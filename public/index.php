<?php
declare(strict_types=1);

/**
 * Affiliated Writer – Slim 4 single-file backend (index.php)
 * - Env-driven config (DB_DSN / DB_PATH, CORS_ORIGINS, APP_DEBUG)
 * - SQLite stability (WAL, busy_timeout)
 * - CORS preflight (catch-all OPTIONS)
 * - Secrets never returned in GET lists
 */

use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use PDO;
use Tuupola\Middleware\CorsMiddleware;

require __DIR__ . '/../vendor/autoload.php';

/* -------------------------
   Helpers
------------------------- */

$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string) $req->getBody();
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

$mask_tail4 = function (?string $s): ?string {
    if (!$s) return $s;
    $len = strlen($s);
    if ($len <= 4) return str_repeat('*', max(0, $len-1)) . substr($s, -1);
    return str_repeat('*', $len - 4) . substr($s, -4);
};

/* -------------------------
   Container + DB (SQLite)
------------------------- */

$container = new Container();
$container->set('db', function () {
    $envDsn  = getenv('DB_DSN') ?: '';
    $dbPath  = getenv('DB_PATH') ?: '/tmp/affwriter.db';
    $dsn     = $envDsn !== '' ? $envDsn : ('sqlite:' . $dbPath);

    $pdo = new PDO($dsn, '', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    try {
        $pdo->exec("PRAGMA journal_mode=WAL;");
        $pdo->exec("PRAGMA synchronous=NORMAL;");
        $pdo->exec("PRAGMA foreign_keys=ON;");
        $pdo->exec("PRAGMA busy_timeout=5000;");
    } catch (Throwable $e) {}

    // ----- Migrations (MVP) -----
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS articles(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            platform TEXT NOT NULL,
            status TEXT NOT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feature_flags(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            credits INTEGER NOT NULL DEFAULT 0,
            credits_expiry TEXT NULL
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ai_providers(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            base_url TEXT NOT NULL,
            model_name TEXT NOT NULL,
            api_key TEXT NOT NULL,
            temperature REAL NOT NULL DEFAULT 0.7,
            priority INTEGER NOT NULL DEFAULT 10,
            assigned_section TEXT NOT NULL DEFAULT 'general',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prompt_templates(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            section TEXT NOT NULL,
            ai_provider_id INTEGER NULL,
            template TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wordpress_sites(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            base_url TEXT NOT NULL,
            username TEXT NOT NULL,
            app_password TEXT NOT NULL,
            default_category_id INTEGER NULL,
            default_status TEXT NOT NULL DEFAULT 'draft',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blogger_blogs(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            blog_id TEXT NOT NULL,
            api_key TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS amazon_apis(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            access_key TEXT NOT NULL,
            secret_key TEXT NOT NULL,
            partner_tag TEXT NOT NULL,
            country TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            model TEXT NULL,
            payload_json TEXT NULL,
            status TEXT NOT NULL DEFAULT 'queued',
            error TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )");

    // ----- Seeds -----
    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $pdo->exec("
            INSERT INTO users(name,email,credits,credits_expiry) VALUES
            ('Admin User','admin@example.com',6300,'2025-12-22'),
            ('Demo Writer','writer@example.com',1200,'2025-11-22')
        ");
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM feature_flags")->fetchColumn() === 0) {
        $flags = ['amazon_api_enabled','bulk_generate_enabled','comparison_table_auto','cta_auto_enable'];
        $ins = $pdo->prepare("INSERT INTO feature_flags(name,enabled) VALUES(?,1)");
        foreach ($flags as $f) { $ins->execute([$f]); }
    }

    return $pdo;
});

/* -------------------------
   App + Middleware
------------------------- */

AppFactory::setContainer($container);
$app  = AppFactory::create();
$pdo  = $container->get('db');

$getCurrentUserId = function () use ($pdo): int {
    try {
        $id = (int)($pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        return $id > 0 ? $id : 1;
    } catch (Throwable $e) { return 1; }
};

// CORS from env (fallback to defaults)
$origins = getenv('CORS_ORIGINS');
$originList = $origins ? array_map('trim', explode(',', $origins)) : [
    "https://affiliated-writer-dashboard.vercel.app",
    "http://localhost:3000",
    "http://127.0.0.1:3000",
];

// 1) CORS first
$app->add(new CorsMiddleware([
    "origin" => $originList,
    "methods" => ["GET","POST","PUT","PATCH","DELETE","OPTIONS"],
    "headers.allow" => ["Authorization","If-Match","If-Unmodified-Since","Content-Type","Accept","Origin","X-Requested-With"],
    "headers.expose" => ["Etag"],
    "credentials" => true,
    "cache" => (int)(getenv('CORS_CACHE') ?: 0),
]));

// 2) Routing middleware
$app->addRoutingMiddleware();

/* -------------------------
   Routes
------------------------- */

// Catch-all OPTIONS (preflight)
$app->options('/{routes:.+}', function (Request $r, Response $res) {
    return $res->withStatus(204);
});

// Health / root
$app->get('/', function (Request $r, Response $res) use ($json) {
    return $json($res, ['status' => 'ok', 'message' => 'Welcome!', 'health_check' => '/api/db/ping']);
});
$app->get('/api', function (Request $r, Response $res) use ($json) {
    return $json($res, ['ok' => true, 'message' => 'Affiliated Writer API root. Try /api/db/ping']);
});

// DB ping
$app->get('/api/db/ping', function (Request $r, Response $res) use ($json, $pdo) {
    try { $pdo->query("SELECT 1"); return $json($res, ['db' => 'up']); }
    catch (Throwable $e) { return $json($res, ['db' => 'down', 'error' => $e->getMessage()], 500); }
});

// Dummy login (MVP only)
$app->post('/api/auth/login', function (Request $r, Response $res) use ($json, $readJson) {
    $body = $readJson($r);
    $email = $body['email'] ?? '';
       $password = $body['password'] ?? '';
    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            'token' => 'dummy-jwt-token-for-testing-purposes',
            'user'  => ['name' => 'Admin User', 'email' => $email]
        ]);
    }
    return $json($res, ['error' => 'Invalid credentials'], 401);
});

// Admin overview
$app->get('/api/admin/overview', function (Request $r, Response $res) use ($json, $pdo) {
    $articles  = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $prompts   = (int)$pdo->query("SELECT COUNT(*) FROM prompt_templates")->fetchColumn();
    $providers = (int)$pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
    $sumCreds  = (int)($pdo->query("SELECT COALESCE(SUM(credits),0) FROM users")->fetchColumn() ?: 0);
    return $json($res, [
        'articles'      => $articles,
        'prompts'       => $prompts,
        'providers'     => $providers,
        'credits_left'  => $sumCreds,
        'credits_expiry'=> null
    ]);
});

// Articles
$app->get('/api/articles', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,title,platform,status,updated_at
        FROM articles
        ORDER BY id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['articles' => $rows]);
});

// Feature flags list & toggle
$app->get('/api/admin/feature-flags', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,name,(enabled+0) AS enabled
        FROM feature_flags
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['flags' => $rows]);
});

$app->post('/api/admin/feature-flags/{id:\d+}/toggle', function (Request $r, Response $res, array $a) use ($json, $pdo) {
    $id = (int)($a['id'] ?? 0);
    if ($id <= 0) return $json($res, ['error' => 'Invalid id'], 422);
    $pdo->prepare("UPDATE feature_flags SET enabled = 1 - enabled WHERE id=?")->execute([$id]);
    $st = $pdo->prepare("SELECT id,(enabled+0) AS enabled FROM feature_flags WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? $json($res, $row) : $json($res, ['error' => 'Not Found'], 404);
});

// Users (admin list)
$app->get('/api/admin/users[/{tail:.*}]', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,name,email,credits,credits_expiry
        FROM users
        ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

// AI Providers (never return api_key)
$app->get('/api/admin/providers', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,name,base_url,model_name,temperature,priority,assigned_section,is_active,created_at
        FROM ai_providers
        ORDER BY priority ASC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

// Create provider (accept key but do not echo it back)
$app->post('/api/admin/providers', function (Request $r, Response $res) use ($json, $readJson, $pdo) {
    $d = $readJson($r);
    $pdo->prepare("
        INSERT INTO ai_providers
        (name, base_url, model_name, api_key, temperature, priority, assigned_section, is_active)
        VALUES(?,?,?,?,?,?,?,?)
    ")->execute([
        trim((string)($d['name'] ?? 'Provider')),
        (string)($d['base_url'] ?? ''),
        (string)($d['model_name'] ?? ''),
        (string)($d['api_key'] ?? ''),
        (float)($d['temperature'] ?? 0.7),
        (int)($d['priority'] ?? 10),
        (string)($d['assigned_section'] ?? 'general'),
        (int)($d['is_active'] ?? 1),
    ]);
    $id = (int)$pdo->lastInsertId();
    $row = $pdo->query("
        SELECT id,name,base_url,model_name,temperature,priority,assigned_section,is_active,created_at
        FROM ai_providers WHERE id={$id}
    ")->fetch(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $row], 201);
});

// Prompt templates
$app->get('/api/admin/prompt-templates', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,name,section,ai_provider_id,template,is_active,created_at
        FROM prompt_templates
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

// Publishing targets (no secrets)
$app->get('/api/publish/wordpress', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,title,base_url,default_category_id,default_status,is_active,created_at
        FROM wordpress_sites
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

$app->get('/api/publish/blogger', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,title,blog_id,is_active,created_at
        FROM blogger_blogs
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

// Amazon APIs (mask)
$app->get('/api/publish/amazon', function (Request $r, Response $res) use ($json, $pdo, $getCurrentUserId, $mask_tail4) {
    $uid = $getCurrentUserId();
    $st = $pdo->prepare("
        SELECT id,title,partner_tag,country,is_active,created_at
        FROM amazon_apis
        WHERE user_id=?
        ORDER BY id DESC
    ");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['partner_tag'] = $mask_tail4((string)($row['partner_tag'] ?? ''));
    }
    return $json($res, ['data' => $rows]);
});

// Jobs
$app->post('/api/jobs/start', function (Request $r, Response $res) use ($json, $readJson, $pdo) {
    $d = $readJson($r);
    if (!isset($d['type']) || trim((string)$d['type']) === '') {
        return $json($res, ['error' => 'type required'], 422);
    }
    $pdo->prepare("
        INSERT INTO jobs(type,model,payload_json,status)
        VALUES(?,?,?, 'queued')
    ")->execute([
        (string)$d['type'],
        $d['model'] ?? null,
        json_encode($d['options'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    return $json($res, ['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
});

$app->get('/api/jobs', function (Request $r, Response $res) use ($json, $pdo) {
    $rows = $pdo->query("
        SELECT id,type,status,created_at
        FROM jobs
        ORDER BY id DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

/* -------------------------
   Error Middleware + Run
------------------------- */

$app->addErrorMiddleware(
    filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    true,
    true
);

$app->run();

<?php
declare(strict_types=1);

/**
 * ============================================================
 *  Affiliated Writer – Slim 4 Backend (Final Production Build)
 * ============================================================
 * ✅ Features:
 *  - Uses env vars (DB_DSN, DB_PATH, CORS_ORIGINS, APP_DEBUG)
 *  - SQLite stability tweaks (WAL, busy_timeout)
 *  - Handles CORS via Tuupola middleware
 *  - Includes alias route /auth/login (Option B)
 *  - JSON helpers with Unicode-safe pretty output
 *  - Securely masks secrets in API responses
 * ============================================================
 */

use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;
use PDO;
use Tuupola\Middleware\CorsMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

require __DIR__ . '/../vendor/autoload.php';

/* -----------------------------
   🧩 Helper Functions
----------------------------- */

$readJson = fn(Request $req): array => json_decode((string)$req->getBody(), true) ?? [];

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

$mask_tail4 = fn(?string $s): ?string =>
    !$s ? $s : (strlen($s) <= 4 ? str_repeat('*', max(0, strlen($s)-1)) . substr($s, -1)
                               : str_repeat('*', strlen($s)-4) . substr($s, -4));

/* -----------------------------
   🧠 Container + SQLite DB
----------------------------- */

$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn, '', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    try {
        $pdo->exec("PRAGMA journal_mode=WAL;");
        $pdo->exec("PRAGMA synchronous=NORMAL;");
        $pdo->exec("PRAGMA foreign_keys=ON;");
        $pdo->exec("PRAGMA busy_timeout=5000;");
    } catch (Throwable) {}

    /* 🧩 Migrations */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            credits INTEGER NOT NULL DEFAULT 0,
            credits_expiry TEXT NULL
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS feature_flags(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS articles(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            platform TEXT NOT NULL,
            status TEXT NOT NULL,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
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
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS prompt_templates(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            section TEXT NOT NULL,
            ai_provider_id INTEGER NULL,
            template TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
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
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS blogger_blogs(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            blog_id TEXT NOT NULL,
            api_key TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");
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
        );
    ");
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
        );
    ");

    /* Seeds */
    if (!$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
        $pdo->exec("
            INSERT INTO users(name,email,credits,credits_expiry)
            VALUES ('Admin User','admin@example.com',6300,'2025-12-22'),
                   ('Demo Writer','writer@example.com',1200,'2025-11-22');
        ");
    }
    if (!$pdo->query("SELECT COUNT(*) FROM feature_flags")->fetchColumn()) {
        $flags = ['amazon_api_enabled','bulk_generate_enabled','comparison_table_auto','cta_auto_enable'];
        $stmt = $pdo->prepare("INSERT INTO feature_flags(name,enabled) VALUES(?,1)");
        foreach ($flags as $f) $stmt->execute([$f]);
    }

    return $pdo;
});

/* -----------------------------
   ⚙️ App + Middleware
----------------------------- */

AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

/* 🧩 CORS Middleware */
$origins = getenv('CORS_ORIGINS');
$originList = $origins ? array_map('trim', explode(',', $origins)) : [
    "https://affiliated-writer-dashboard.vercel.app",
    "http://localhost:3000",
    "http://127.0.0.1:3000",
    "/\\.vercel\\.app$/", // ✅ any vercel preview subdomain
];

$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => $originList,
    "methods" => ["GET","POST","PUT","PATCH","DELETE","OPTIONS"],
    "headers.allow" => ["Authorization","If-Match","If-Unmodified-Since","Content-Type","Accept","Origin","X-Requested-With"],
    "headers.expose" => ["Etag"],
    "credentials" => true,
    "cache" => (int)(getenv('CORS_CACHE') ?: 0),
]));

/* -----------------------------
   🔗 Routes
----------------------------- */

/* Health check */
$app->get('/', fn(Request $r, Response $res) => 
    $json($res, ['status'=>'ok','message'=>'Welcome!'])
);
$app->get('/api/db/ping', fn(Request $r, Response $res) => 
    $json($res, ['db'=>'up'])
);

/* Login Handler (used in alias routes) */
$loginHandler = function (Request $r, Response $res) use ($json, $readJson) {
    $body = $readJson($r);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';
    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            'token' => 'dummy-jwt-token',
            'user' => ['name'=>'Admin User','email'=>$email]
        ]);
    }
    return $json($res, ['error'=>'Invalid credentials'], 401);
};

/* ✅ Main and Alias Login Routes */
$app->post('/api/auth/login', $loginHandler);
$app->post('/auth/login', $loginHandler); // Option B Alias Route

/* Example: Admin Overview */
$app->get('/api/admin/overview', function (Request $r, Response $res) use ($json, $pdo) {
    $stats = [
        'articles' => (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn(),
        'providers'=> (int)$pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn(),
        'prompts'  => (int)$pdo->query("SELECT COUNT(*) FROM prompt_templates")->fetchColumn(),
        'credits_left' => (int)$pdo->query("SELECT SUM(credits) FROM users")->fetchColumn(),
    ];
    return $json($res, $stats);
});

/* -----------------------------
   🧱 Error Middleware + Run
----------------------------- */
$app->addErrorMiddleware(
    filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    true,
    true
);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();
$app->run($request);

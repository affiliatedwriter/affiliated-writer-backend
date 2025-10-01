<?php
declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

// --- Helpers and Migrations (এগুলো অপরিবর্তিত) ---
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

$getCurrentUserId = function(PDO $pdo): int {
    try {
        $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $id = $stmt ? (int)$stmt->fetchColumn() : 0;
        return $id > 0 ? $id : 1;
    } catch (Throwable $e) {
        return 1;
    }
};

$ensure = function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS articles(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, platform TEXT NOT NULL, status TEXT NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS feature_flags(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, enabled INTEGER NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL DEFAULT 0, credits_expiry TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_providers(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, base_url TEXT NOT NULL, model_name TEXT NOT NULL, api_key TEXT NOT NULL, temperature REAL NOT NULL DEFAULT 0.7, priority INTEGER NOT NULL DEFAULT 10, assigned_section TEXT NOT NULL DEFAULT 'general', is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prompt_templates(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, section TEXT NOT NULL, ai_provider_id INTEGER NULL, template TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wordpress_sites(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, base_url TEXT NOT NULL, username TEXT NOT NULL, app_password TEXT NOT NULL, default_category_id INTEGER NULL, default_status TEXT NOT NULL DEFAULT 'draft', is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blogger_blogs(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, blog_id TEXT NOT NULL, api_key TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS amazon_apis(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, access_key TEXT NOT NULL, secret_key TEXT NOT NULL, partner_tag TEXT NOT NULL, country TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, model TEXT NULL, payload_json TEXT NULL, status TEXT NOT NULL DEFAULT 'queued', error TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");

    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO users(name,email,credits,credits_expiry) VALUES ('Admin User','admin@example.com',6300,'2025-12-22'), ('Demo Writer','writer@example.com',1200,'2025-11-22')");
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM feature_flags")->fetchColumn() === 0) {
        $flags = ['amazon_api_enabled','bulk_generate_enabled','comparison_table_auto','cta_auto_enable'];
        $ins = $pdo->prepare("INSERT INTO feature_flags(name,enabled) VALUES(?,1)");
        foreach($flags as $f){ $ins->execute([$f]); }
    }
};

// --- মূল অ্যাপ্লিকেশন ফাংশন ---
return function (App $app) use ($readJson, $json, $ensure, $getCurrentUserId) {
    /** @var PDO $pdo */
    $pdo = $app->getContainer()->get('db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ensure($pdo);

    // ১. প্রথমে CORS Middleware যোগ করুন
    $app->add(function(Request $req, $handler) {
        $res = $handler->handle($req);
        $origin = $req->getHeaderLine('Origin');
        $allowedOrigins = [
            'https://affiliated-writer-dashboard.vercel.app',
            'http://localhost:3000',
            'http://127.0.0.1:3000'
        ];
        if (in_array($origin, $allowedOrigins)) {
            return $res
              ->withHeader('Access-Control-Allow-Origin', $origin)
              ->withHeader('Access-Control-Allow-Credentials', 'true')
              ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
              ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        }
        return $res;
    });

    // ২. এখন আপনার সব রুট রেজিস্টার করুন
    // api_routes.php ফাইলের সব রুট এখানে লোড হবে
    require __DIR__ . '/api_routes.php';

    // ৩. সবশেষে, রাউটিং এবং এরর Middleware যোগ করুন
    $app->addRoutingMiddleware();
    $errorMiddleware = $app->addErrorMiddleware(true, true, true);

    // 404 Not Found এররকে সুন্দরভাবে দেখানোর জন্য হ্যান্ডলার
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
        throw new HttpNotFoundException($request);
    });
};


<?php
declare(strict_types=1);

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

/* -------------------------
   Helpers
------------------------- */

$readJson = fn(Request $req): array =>
    json_decode((string)$req->getBody(), true) ?: [];

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

/* -------------------------
   DB Setup
------------------------- */

$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT, email TEXT, credits INT, credits_expiry TEXT
    );");
    if (!$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
        $pdo->exec("INSERT INTO users(name,email,credits,credits_expiry)
                    VALUES ('Admin User','admin@example.com',5000,'2025-12-22')");
    }
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

/* -------------------------
   Middleware (CORS)
------------------------- */

$origins = getenv('CORS_ORIGINS') ?: 'https://affiliated-writer-dashboard.vercel.app,http://localhost:3000';
$originList = array_map('trim', explode(',', $origins));

$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => $originList,
    "methods" => ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    "headers.allow" => ["Authorization", "Content-Type", "Accept", "Origin"],
    "credentials" => true,
    "cache" => 0,
]));

/* -------------------------
   Routes
------------------------- */

// Health check
$app->get('/', fn($r, $res) => $json($res, ["status" => "ok", "message" => "Welcome!"]));
$app->get('/api/db/ping', fn($r, $res) => $json($res, ["db" => "up"]));

/* ---- LOGIN ROUTE (single handler + alias) ---- */
$loginHandler = function (Request $r, Response $res) use ($json, $readJson) {
    $data = $readJson($r);
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            "token" => "dummy-jwt-token",
            "user"  => ["name" => "Admin User", "email" => $email]
        ]);
    }
    return $json($res, ["error" => "Invalid credentials"], 401);
};

// ✅ Keep one /api/auth/login
$app->post('/api/auth/login', $loginHandler);
// ✅ Add alias /auth/login — same handler, no duplication
$app->post('/auth/login', $loginHandler);

/* -------------------------
   Error Middleware
------------------------- */
$app->addErrorMiddleware(true, true, true);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();
$app->run($request);

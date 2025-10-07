<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tuupola\Middleware\CorsMiddleware;
use Throwable;
use PDO;

require __DIR__ . '/../vendor/autoload.php';

/* ==============================
   🔧 Helpers
============================== */

function readJson(Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function jsonResponse(Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
}

/* ==============================
   🔧 Container + SQLite Database
============================== */

$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");
    return $pdo;
});

/* ==============================
   ⚙️ Slim App + CORS Middleware
============================== */

AppFactory::setContainer($container);
$app = AppFactory::create();

// ✅ Allow front-end origins (Render + Vercel)
$origins = getenv('CORS_ORIGINS') ?: 'https://affiliated-writer-dashboard.vercel.app,http://localhost:3000';
$originList = array_map('trim', explode(',', $origins));

// 🔥 CORS setup
$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => $originList,
    "methods" => explode(',', getenv('CORS_METHODS') ?: 'GET,POST,PUT,PATCH,DELETE,OPTIONS'),
    "headers.allow" => explode(',', getenv('CORS_HEADERS') ?: 'Content-Type,Authorization'),
    "credentials" => filter_var(getenv('CORS_CREDENTIALS') ?: true, FILTER_VALIDATE_BOOLEAN),
    "cache" => (int)(getenv('CORS_CACHE') ?: 0),
]));

/* ==============================
   🧭 Routes
============================== */

// ✅ Health check
$app->get('/', fn($r, $res) => jsonResponse($res, ["status" => "ok", "message" => "Welcome!"]));
$app->get('/api/db/ping', function ($r, $res) use ($container) {
    try {
        $container->get('db')->query("SELECT 1");
        return jsonResponse($res, ["db" => "up"]);
    } catch (Throwable $e) {
        return jsonResponse($res, ["db" => "down", "error" => $e->getMessage()], 500);
    }
});

// ✅ Login endpoint
$app->post('/auth/login', function (Request $r, Response $res) {
    $body = readJson($r);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if ($email === 'admin@example.com' && $password === 'password') {
        return jsonResponse($res, [
            "success" => true,
            "token" => "demo-jwt-token",
            "user" => ["name" => "Admin", "email" => $email]
        ]);
    }
    return jsonResponse($res, ["success" => false, "message" => "Invalid credentials"], 401);
});

/* ==============================
   ⚙️ Error Middleware + Run
============================== */

$app->addErrorMiddleware(false, true, true);
$app->run();

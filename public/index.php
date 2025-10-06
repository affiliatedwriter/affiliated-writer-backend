<?php
declare(strict_types=1);

/**
 * ✅ Affiliated Writer - Production Backend (Slim 4 + SQLite)
 * - Proper CORS for Vercel frontend
 * - Health route fixed
 * - Optimized for Render.com PHP runtime
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

/* ---------------------------------------------
   Helper Functions
----------------------------------------------*/
$readJson = function (Request $req): array {
    $raw = (string)$req->getBody();
    return $raw ? (json_decode($raw, true) ?: []) : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

/* ---------------------------------------------
   DB Setup
----------------------------------------------*/
$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("PRAGMA busy_timeout=5000;");
    return $pdo;
});

/* ---------------------------------------------
   App Setup
----------------------------------------------*/
AppFactory::setContainer($container);
$app = AppFactory::create();

/* ---------------------------------------------
   ✅ Proper CORS Configuration
----------------------------------------------*/
$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => [
        "https://affiliated-writer-dashboard.vercel.app",
        "http://localhost:3000"
    ],
    "methods" => ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    "headers.allow" => [
        "Authorization", "Content-Type", "Accept", "Origin", "X-Requested-With"
    ],
    "headers.expose" => ["Content-Length", "X-Knowledge"],
    "credentials" => true,
    "cache" => 0,
]));

/* ---------------------------------------------
   Routes
----------------------------------------------*/

// Health check (for Render)
$app->get('/', fn($r, $res) => $json($res, [
    "status" => "ok",
    "message" => "Welcome!",
    "health" => "/api/health"
]));
$app->get('/api/health', fn($r, $res) => $json($res, ["ok" => true]));

// ✅ Login endpoint (matches frontend)
$app->post('/auth/login', function (Request $r, Response $res) use ($json, $readJson) {
    $body = $readJson($r);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            "success" => true,
            "message" => "Login successful",
            "token" => "dummy-jwt-token"
        ]);
    }

    return $json($res, ["success" => false, "message" => "Invalid credentials"], 401);
});

/* ---------------------------------------------
   Error Middleware + Run
----------------------------------------------*/
$app->addErrorMiddleware(false, true, true);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();
$app->run($request);

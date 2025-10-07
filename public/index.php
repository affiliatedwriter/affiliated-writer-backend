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

// ---------------------------
// JSON helpers
// ---------------------------
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string) $req->getBody();
    return $raw ? (json_decode($raw, true) ?? []) : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

// ---------------------------
// DB setup
// ---------------------------
$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");
    return $pdo;
});

// ---------------------------
// App setup
// ---------------------------
AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

// ---------------------------
// CORS setup
// ---------------------------
$allowedOrigins = explode(',', getenv('CORS_ORIGINS') ?: 'https://affiliated-writer-dashboard.vercel.app,http://localhost:3000');
$app->add(new CorsMiddleware([
    'origin' => $allowedOrigins,
    'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'headers.allow' => ['Authorization', 'Content-Type', 'Accept', 'Origin', 'X-Requested-With'],
    'headers.expose' => ['Content-Type', 'Authorization'],
    'credentials' => true,
    'cache' => 0,
]));

// ---------------------------
// Routes
// ---------------------------
$app->get('/', fn(Request $r, Response $res) => $json($res, ['status' => 'ok', 'message' => 'Welcome!']));
$app->get('/api/db/ping', fn(Request $r, Response $res) => $json($res, ['db' => 'up']));

$app->post('/api/auth/login', function (Request $r, Response $res) use ($readJson, $json) {
    $body = $readJson($r);
    if (($body['email'] ?? '') === 'admin@example.com' && ($body['password'] ?? '') === 'password') {
        return $json($res, ['success' => true, 'user' => ['name' => 'Admin', 'email' => 'admin@example.com']]);
    }
    return $json($res, ['success' => false, 'message' => 'Invalid credentials'], 401);
});

// ---------------------------
// Error handling
// ---------------------------
$app->addErrorMiddleware(true, true, true);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$request = $creator->fromGlobals();

$app->run($request);

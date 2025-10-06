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

/* ------------------------- Helpers ------------------------- */
$readJson = function (Request $req): array {
    $raw = (string)$req->getBody();
    return $raw ? (json_decode($raw, true) ?? []) : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

/* ------------------------- DB ------------------------- */
$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

/* ------------------------- CORS ------------------------- */
$origins = getenv('CORS_ORIGINS') ?: "https://affiliated-writer-dashboard.vercel.app,http://localhost:3000";
$originList = array_map('trim', explode(',', $origins));

$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => $originList,
    "methods" => ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    "headers.allow" => ["Authorization", "Content-Type", "Accept", "Origin"],
    "credentials" => true,
]));

/* ------------------------- Routes ------------------------- */

// ✅ Health
$app->get('/', fn($r, $res) => $json($res, ["status" => "ok", "message" => "Welcome!"]));
$app->get('/api/db/ping', fn($r, $res) => $json($res, ["db" => "up"]));

// ✅ Login
$app->post('/auth/login', function (Request $req, Response $res) use ($json, $readJson) {
    $body = $readJson($req);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';
    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            "success" => true,
            "user" => ["name" => "Admin User", "email" => $email]
        ]);
    }
    return $json($res, ["success" => false, "message" => "Invalid credentials"], 401);
});

/* ------------------------- Run ------------------------- */
$app->addErrorMiddleware(true, true, true);
$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$app->run($creator->fromGlobals());

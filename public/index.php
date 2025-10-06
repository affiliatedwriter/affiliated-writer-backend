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

/* ----------------- Helpers ----------------- */
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    return $raw ? (json_decode($raw, true) ?: []) : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

/* ----------------- Database ----------------- */
$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("PRAGMA synchronous=NORMAL;");
    $pdo->exec("PRAGMA foreign_keys=ON;");
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

/* ----------------- CORS ----------------- */
$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => [
        "https://affiliated-writer-dashboard.vercel.app",
        "http://localhost:3000",
    ],
    "methods" => ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    "headers.allow" => ["Content-Type", "Authorization", "Accept", "Origin"],
    "credentials" => true,
]));

/* ----------------- Routes ----------------- */
$app->get('/', fn($r, $res) => $json($res, ["status" => "ok", "message" => "Welcome!"]));
$app->get('/api/db/ping', fn($r, $res) => $json($res, ["db" => "up"]));

$app->post('/api/auth/login', function (Request $r, Response $res) use ($json, $readJson) {
    $body = $readJson($r);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            'success' => true,
            'token' => 'dummy-jwt',
            'user' => ['name' => 'Admin', 'email' => $email]
        ]);
    }
    return $json($res, ['success' => false, 'message' => 'Invalid credentials'], 401);
});

/* ----------------- Error Middleware ----------------- */
$app->addErrorMiddleware(false, true, true);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$app->run($creator->fromGlobals());

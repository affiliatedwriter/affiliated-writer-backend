<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Tuupola\Middleware\CorsMiddleware;
use PDO;
use Throwable;

require __DIR__ . '/../vendor/autoload.php';

/* ----------------- Helpers ----------------- */
$readJson = fn(Request $req): array =>
    json_decode((string)$req->getBody(), true) ?? [];

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    return $res->withHeader('Content-Type', 'application/json')
               ->withStatus($code);
};

/* ----------------- Database ----------------- */
$container = new Container();
$container->set('db', function () {
    $pdo = new PDO('sqlite:/tmp/affwriter.db', '', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        password TEXT DEFAULT 'password'
    )");
    if (!$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn()) {
        $pdo->exec("INSERT INTO users(name,email) VALUES('Admin','admin@example.com')");
    }
    return $pdo;
});

/* ----------------- App + Middleware ----------------- */
AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => [
        "https://affiliated-writer-dashboard.vercel.app",
        "http://localhost:3000",
        "/\.vercel\.app$/"
    ],
    "methods" => ["GET", "POST", "OPTIONS"],
    "headers.allow" => ["Content-Type", "Authorization", "Origin", "Accept"],
    "credentials" => true,
    "cache" => 0
]));

/* ----------------- Routes ----------------- */
// Health check
$app->get('/', fn(Request $r, Response $res) =>
    $json($res, ["status" => "ok", "message" => "Welcome!"])
);
$app->get('/api/db/ping', fn(Request $r, Response $res) =>
    $json($res, ["db" => "up"])
);

// ✅ Login endpoint + alias
$loginHandler = function (Request $r, Response $res) use ($readJson, $json, $pdo) {
    $d = $readJson($r);
    $email = $d['email'] ?? '';
    $password = $d['password'] ?? '';

    if ($email === 'admin@example.com' && $password === 'password') {
        return $json($res, [
            "success" => true,
            "user" => ["name" => "Admin", "email" => $email],
            "token" => "demo-token"
        ]);
    }
    return $json($res, ["error" => "Invalid credentials"], 401);
};

// ✅ Login route (main)
$app->post('/api/auth/login', $loginHandler);

// ✅ Alias route (for backward compatibility)
$app->post('/auth/login', $loginHandler);

// both routes valid
$app->post('/api/auth/login', $loginHandler);
$app->post('/auth/login', $loginHandler);

/* ----------------- Error middleware ----------------- */
$app->addErrorMiddleware(true, true, true);
$app->run();

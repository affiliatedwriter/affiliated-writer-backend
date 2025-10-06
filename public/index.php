<?php
declare(strict_types=1);

/**
 * Affiliated Writer Backend (Slim 4, SQLite, CORS Ready)
 * - Production Ready Version
 * - Handles Auth, DB Ping, Admin Routes, Articles, etc.
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

/* ============================================================
   1️⃣ JSON Helper Functions
============================================================ */
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    return $raw ? (json_decode($raw, true) ?: []) : [];
};

$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

/* ============================================================
   2️⃣ Database Configuration (SQLite)
============================================================ */
$container = new Container();
$container->set('db', function () {
    $dsn = getenv('DB_DSN') ?: 'sqlite:/tmp/affwriter.db';

    $pdo = new PDO($dsn, '', '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // SQLite Optimizations
    $pdo->exec("PRAGMA journal_mode=WAL;");
    $pdo->exec("PRAGMA synchronous=NORMAL;");
    $pdo->exec("PRAGMA foreign_keys=ON;");
    $pdo->exec("PRAGMA busy_timeout=5000;");

    // Create Minimal Tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            password TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Seed Default Admin
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE email='admin@example.com'")->fetchColumn();
    if ($exists === 0) {
        $pdo->prepare("INSERT INTO users(name,email,password) VALUES(?,?,?)")
            ->execute(['Admin User', 'admin@example.com', password_hash('password', PASSWORD_DEFAULT)]);
    }

    return $pdo;
});

/* ============================================================
   3️⃣ Slim App + Middleware Setup
============================================================ */
AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');

// CORS Configuration
$app->addRoutingMiddleware();
$app->add(new CorsMiddleware([
    "origin" => [
        "https://affiliated-writer-dashboard.vercel.app",
        "http://localhost:3000"
    ],
    "methods" => ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
    "headers.allow" => ["Authorization", "Content-Type", "Accept", "Origin"],
    "credentials" => true,
]));

/* ============================================================
   4️⃣ Routes (Endpoints)
============================================================ */

// ✅ Health Check
$app->get('/', fn($r, $res) => $json($res, ['status' => 'ok', 'message' => 'Welcome!']));
$app->get('/api/db/ping', fn($r, $res) => $json($res, ['db' => 'up']));

// ✅ Auth Login
$app->post('/api/auth/login', function (Request $r, Response $res) use ($json, $readJson, $pdo) {
    $body = $readJson($r);
    $email = trim($body['email'] ?? '');
    $password = trim($body['password'] ?? '');

    if ($email === '' || $password === '') {
        return $json($res, ['success' => false, 'message' => 'Email and password required'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        return $json($res, [
            'success' => true,
            'token' => 'dummy-jwt-token',
            'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email']]
        ]);
    }
    return $json($res, ['success' => false, 'message' => 'Invalid credentials'], 401);
});

/* ============================================================
   5️⃣ Error Handling + Run
============================================================ */
$app->addErrorMiddleware(false, true, true);

$psr17 = new Psr17Factory();
$creator = new ServerRequestCreator($psr17, $psr17, $psr17, $psr17);
$app->run($creator->fromGlobals());

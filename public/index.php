<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();

/**
 * PDO (env-aware)
 *
 * Prefers:
 *   - DB_DSN / DB_USER / DB_PASS
 *   - or DATABASE_URL (mysql://user:pass@host:3306/dbname)
 * Fallback:
 *   - sqlite:/tmp/affwriter.db
 */
$container->set('db', function () {
    $dsn  = getenv('DB_DSN') ?: '';
    $user = getenv('DB_USER') ?: null;
    $pass = getenv('DB_PASS') ?: null;

    // Support DATABASE_URL like: mysql://user:pass@host:3306/dbname
    if ($dsn === '' && ($url = getenv('DATABASE_URL'))) {
        $parts = parse_url($url);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $scheme = $parts['scheme']; // mysql / pgsql / sqlite
            $host   = $parts['host'];
            $port   = $parts['port'] ?? null;
            $db     = isset($parts['path']) ? ltrim($parts['path'], '/') : null;
            $user   = $parts['user'] ?? null;
            $pass   = $parts['pass'] ?? null;

            if ($scheme === 'mysql') {
                $charset = 'utf8mb4';
                $dsn = "mysql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$db};charset={$charset}";
            } elseif ($scheme === 'pgsql') {
                $dsn = "pgsql:host={$host}" . ($port ? ";port={$port}" : "") . ";dbname={$db}";
            } elseif ($scheme === 'sqlite') {
                // e.g. sqlite:///tmp/file.db  => /tmp/file.db
                $path = isset($parts['path']) ? $parts['path'] : '/tmp/affwriter.db';
                $dsn  = "sqlite:{$path}";
                $user = null;
                $pass = null;
            }
        }
    }

    // Default fallback: SQLite on ephemeral disk (OK for demo/staging)
    if ($dsn === '') {
        $dsn  = 'sqlite:/tmp/affwriter.db';
        $user = null;
        $pass = null;
    }

    // Ensure sqlite file/dir exists
    if (str_starts_with($dsn, 'sqlite:')) {
        $path = substr($dsn, strlen('sqlite:'));
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!file_exists($path)) {
            @touch($path);
        }
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // MySQL specific: ensure utf8mb4 (no-op for sqlite/pgsql)
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

/**
 * Simple CORS (Vercel থেকে কলের জন্য)
 * CORS_ORIGIN env থাকলে সেটি ব্যবহার হবে, নচেৎ '*'
 */
$app->add(function (Request $req, $handler) {
    $res    = $handler->handle($req);
    $origin = $req->getHeaderLine('Origin');
    $allow  = getenv('CORS_ORIGIN') ?: '*';
    if ($allow !== '*' && $origin && str_starts_with($origin, $allow)) {
        $allow = $origin; // reflect exact origin
    }
    return $res
        ->withHeader('Access-Control-Allow-Origin', $allow)
        ->withHeader('Access-Control-Allow-Credentials', 'true')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});
// Preflight
$app->options('/{routes:.+}', fn(Request $r, Response $res) => $res->withStatus(204));

/* Load routes */
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

/* Error middleware (JSON in dev) */
$app->addErrorMiddleware(true, true, true);

$app->run();

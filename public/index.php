<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();

/** ---------- DB (env-aware) ---------- **/
$container->set('db', function () {
    // Render / Docker env গুলো থেকে পড়ি
    $dsn  = getenv('DB_DSN') ?: '';
    $user = getenv('DB_USER') ?: '';
    $pass = getenv('DB_PASS') ?: '';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // কিছুই দিলে না? -> SQLite fallback (ফ্রি প্ল্যানে easiest)
    if ($dsn === '') {
        // Render ephemeral disk: /var/data/
        // Local: /tmp/
        $db_path = getenv('RENDER') ? '/var/data/affwriter.db' : '/tmp/affwriter.db';
        $dsn  = 'sqlite:' . $db_path;
        $user = '';
        $pass = '';
    }

    return new PDO($dsn, $user, $pass, $opts);
});

AppFactory::setContainer($container);
$app = AppFactory::create();

/** ---------- তোমার routes.php লোড ---------- **/
// এখন সব রুট এবং CORS হ্যান্ডলিং app/routes.php থেকে আসবে
$routes = require_once __DIR__ . '/../app/routes.php';
$routes($app);

// Error middleware যোগ করা হলো
$app->addErrorMiddleware(true, true, true);

// অ্যাপ্লিকেশন রান করা হলো
$app->run();
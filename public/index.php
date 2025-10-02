<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();

/** ---------- DB (env-aware) ---------- **/
$container->set('db', function () {
    // Render-এর ফ্রি প্ল্যানের জন্য আমরা /tmp ফোল্ডার ব্যবহার করব, যা সবসময় লেখার যোগ্য
    $db_path = '/tmp/affwriter.db';
    $dsn  = 'sqlite:' . $db_path;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    return new PDO($dsn, '', '', $opts);
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
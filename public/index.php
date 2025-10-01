<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();

$container->set('db', function () {
    $db_path = getenv('RENDER') ? '/var/data/affwriter.db' : '/tmp/affwriter.db';
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

// শুধুমাত্র routes.php লোড হবে
$routes = require_once __DIR__ . '/../app/routes.php';
$routes($app);

$app->addErrorMiddleware(true, true, true);
$app->run();
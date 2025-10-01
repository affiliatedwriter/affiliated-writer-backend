<?php
declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

$container = new Container();

/* PDO bind */
$container->set('db', function() {
    $host = '127.0.0.1';
    $db   = 'affiliated_writer2';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
});

AppFactory::setContainer($container);
$app = AppFactory::create();

/* Load routes */
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

/* Error middleware (JSON) */
$app->addErrorMiddleware(true, true, true);

$app->run();

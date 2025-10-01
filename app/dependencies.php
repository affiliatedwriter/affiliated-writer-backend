<?php
// File: affiliated-writer-new/app/dependencies.php

use DI\ContainerBuilder;

return function (ContainerBuilder $containerBuilder): void {
    // Container definitions
    $containerBuilder->addDefinitions([
        // PDO (no "use PDO"; FQCN ব্যবহার করেছি)
        \PDO::class => function () {
            $host = isset($_ENV['DB_HOST'])     ? $_ENV['DB_HOST']     : '127.0.0.1';
            $port = isset($_ENV['DB_PORT'])     ? $_ENV['DB_PORT']     : '3306';
            $db   = isset($_ENV['DB_DATABASE']) ? $_ENV['DB_DATABASE'] : 'affiliated_writer';
            $user = isset($_ENV['DB_USERNAME']) ? $_ENV['DB_USERNAME'] : 'root';
            $pass = isset($_ENV['DB_PASSWORD']) ? $_ENV['DB_PASSWORD'] : '';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

            $pdo = new \PDO($dsn, $user, $pass, array(
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false
            ));

            // চাইলে হেলথচেক
            // $pdo->query('SELECT 1');

            return $pdo;
        },

        // সাধারণ সেটিংস (যদি দরকার হয়)
        'settings' => array(
            'appName' => 'Affiliated Writer API'
        ),

        // JWT সিক্রেট (arrow function ছাড়া)
        'jwt.secret' => function () {
            return isset($_ENV['JWT_SECRET']) ? $_ENV['JWT_SECRET'] : 'dev-secret';
        }
    ]);
};

<?php
use Slim\App;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;

return function (App $app) {
    // Error middleware + JSON error handler
    $error = $app->addErrorMiddleware(true, true, true);
    $error->setDefaultErrorHandler(function (
        Psr\Http\Message\ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails
    ) use ($app) {
        $status = (int)($exception->getCode() ?: 500);
        if ($status < 400 || $status > 599) { $status = 500; }

        $payload = ['error' => $exception->getMessage()];
        $res = $app->getResponseFactory()->createResponse($status);
        $res->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $res->withHeader('Content-Type', 'application/json');
    });

    // Routing + Body parsing
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();

    // CORS (সবশেষে add করুন যাতে প্রথমে রান হয়)
    $app->add(function (Request $request, RequestHandler $handler) {
        $origin = $request->getHeaderLine('Origin');

        $allowed = array_filter(array_map('trim',
            explode(',', $_ENV['CORS_ORIGINS'] ?? 'http://localhost:3000')
        ));

        $allowOrigin = in_array('*', $allowed, true)
            ? ($origin ?: ($allowed[0] ?? 'http://localhost:3000'))
            : (in_array($origin, $allowed, true) ? $origin : ($allowed[0] ?? 'http://localhost:3000'));

        $allowMethods = $_ENV['CORS_METHODS'] ?? 'GET,POST,PUT,PATCH,DELETE,OPTIONS';
        $allowHeaders = $_ENV['CORS_HEADERS'] ?? 'Content-Type, Authorization';

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $resp = new Response(204);
            return $resp
                ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
                ->withHeader('Access-Control-Allow-Methods', $allowMethods)
                ->withHeader('Access-Control-Allow-Headers', $allowHeaders)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Expose-Headers', 'Content-Type, Authorization')
            ->withHeader('Vary', 'Origin');
    });
};

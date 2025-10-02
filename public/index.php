<?php
declare(strict_types=1);

// Namespace imports
use DI\Container;
use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

// Autoloader
require __DIR__ . '/../vendor/autoload.php';

// --- Helpers (প্রয়োজনীয় ফাংশন) ---
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};
$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};

// --- DI Container এবং Database Setup ---
$container = new Container();
$container->set('db', function () {
    $db_path = '/tmp/affwriter.db';
    $dsn  = 'sqlite:' . $db_path;
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, '', '', $opts);

    // --- Database Migrations (টেবিল তৈরি) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS articles(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, platform TEXT NOT NULL, status TEXT NOT NULL, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS feature_flags(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, enabled INTEGER NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, credits INTEGER NOT NULL DEFAULT 0, credits_expiry TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_providers(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, base_url TEXT NOT NULL, model_name TEXT NOT NULL, api_key TEXT NOT NULL, temperature REAL NOT NULL DEFAULT 0.7, priority INTEGER NOT NULL DEFAULT 10, assigned_section TEXT NOT NULL DEFAULT 'general', is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS prompt_templates(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, section TEXT NOT NULL, ai_provider_id INTEGER NULL, template TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wordpress_sites(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, base_url TEXT NOT NULL, username TEXT NOT NULL, app_password TEXT NOT NULL, default_category_id INTEGER NULL, default_status TEXT NOT NULL DEFAULT 'draft', is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS blogger_blogs(id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, blog_id TEXT NOT NULL, api_key TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS amazon_apis(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL, access_key TEXT NOT NULL, secret_key TEXT NOT NULL, partner_tag TEXT NOT NULL, country TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs(id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, model TEXT NULL, payload_json TEXT NULL, status TEXT NOT NULL DEFAULT 'queued', error TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) { $pdo->exec("INSERT INTO users(name,email,credits,credits_expiry) VALUES ('Admin User','admin@example.com',6300,'2025-12-22'), ('Demo Writer','writer@example.com',1200,'2025-11-22')"); }
    if ((int)$pdo->query("SELECT COUNT(*) FROM feature_flags")->fetchColumn() === 0) { $flags = ['amazon_api_enabled','bulk_generate_enabled','comparison_table_auto','cta_auto_enable']; $ins = $pdo->prepare("INSERT INTO feature_flags(name,enabled) VALUES(?,1)"); foreach($flags as $f){ $ins->execute([$f]); } }

    return $pdo;
});

// --- Slim App ইনিশিয়ালাইজেশন ---
AppFactory::setContainer($container);
$app = AppFactory::create();
$pdo = $container->get('db');
$getCurrentUserId = function() use ($pdo): int {
    try {
        $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $id = $stmt ? (int)$stmt->fetchColumn() : 0;
        return $id > 0 ? $id : 1;
    } catch (Throwable $e) { return 1; }
};

// --- Middleware ---
// CORS preflight রিকোয়েস্ট (`OPTIONS`) হ্যান্ডেল করার জন্য
$app->options('/{routes:.+}', function (Request $request, Response $response) {
    return $response;
});

// মূল CORS Middleware (এটি রাউটিং Middleware-এর আগে থাকতে হবে)
$app->add(function(Request $req, $handler) {
    $response = $handler->handle($req);
    $origin = $req->getHeaderLine('Origin');
    $allowedOrigins = [
        'https://affiliated-writer-dashboard.vercel.app',
        'http://localhost:3000',
        'http://127.0.0.1:3000'
    ];

    if (in_array($origin, $allowedOrigins)) {
        return $response
          ->withHeader('Access-Control-Allow-Origin', $origin)
          ->withHeader('Access-Control-Allow-Credentials', 'true')
          ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
          ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }

    return $response;
});

// রাউটিং Middleware যোগ করা
$app->addRoutingMiddleware();

// --- সব API রুট ---

$app->get('/', function(Request $r, Response $res) use ($json) {
    return $json($res, [
        'status' => 'ok',
        'message' => 'Welcome to the Affiliated Writer API!',
        'health_check' => '/api/db/ping'
    ]);
});

$app->get('/api/db/ping', function(Request $r, Response $res) use($json, $pdo){
    try {
        $pdo->query("SELECT 1");
        return $json($res, ['db' => 'up']);
    } catch(Throwable $e){
        return $json($res, ['db' => 'down', 'error' => $e->getMessage()], 500);
    }
});

$app->post('/api/auth/login', function(Request $r, Response $res) use ($json, $readJson, $pdo) {
    $body = $readJson($r);
    $email = $body['email'] ?? '';
    $password = $body['password'] ?? '';

    // এখানে একটি ডেমো লগইন লজিক দেওয়া হলো।
    if ($email === 'admin@example.com' && $password === 'password') {
        $token = 'dummy-jwt-token-for-testing-purposes';
        return $json($res, ['token' => $token, 'user' => ['name' => 'Admin User', 'email' => $email]]);
    }

    return $json($res, ['error' => 'Invalid credentials'], 401);
});

$app->get('/api/admin/overview', function(Request $r, Response $res) use ($json, $pdo){
    $articles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $prompts  = (int)$pdo->query("SELECT COUNT(*) FROM prompt_templates")->fetchColumn();
    $providers= (int)$pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
    $sumCreds = (int)($pdo->query("SELECT COALESCE(SUM(credits),0) FROM users")->fetchColumn() ?: 0);
    return $json($res, ['articles'=>$articles,'prompts'=>$prompts,'providers'=>$providers, 'credits_left'=>$sumCreds,'credits_expiry'=>null]);
});

$app->get('/api/articles', function(Request $r, Response $res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,title,platform,status,updated_at FROM articles ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['articles'=>$rows]);
});

$app->get('/api/admin/feature-flags', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,name,(enabled+0) AS enabled FROM feature_flags ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res,['flags' => $rows]);
});

$app->post('/api/admin/feature-flags/{id:\d+}/toggle', function($r,$res,$a) use($json,$pdo){
    $id = (int)$a['id'];
    $pdo->prepare("UPDATE feature_flags SET enabled = 1 - enabled WHERE id=?")->execute([$id]);
    $row = $pdo->query("SELECT id,(enabled+0) AS enabled FROM feature_flags WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    return $row ? $json($res,$row) : $json($res,['error'=>'Not Found'],404);
});

$app->get('/api/admin/users[/{tail:.*}]', function($r, $res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,name,email,credits,credits_expiry FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res,['data' => $rows]);
});

$app->get('/api/admin/providers', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT * FROM ai_providers ORDER BY priority ASC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res,['data' => $rows]);
});

$app->post('/api/admin/providers', function($r,$res) use($json,$readJson,$pdo){
    $d=$readJson($r);
    $pdo->prepare("INSERT INTO ai_providers(name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$d['name']??'Provider',$d['base_url']??'',$d['model_name']??'',$d['api_key']??'',(float)($d['temperature']??0.7),(int)($d['priority']??10),$d['assigned_section']??'general',(int)($d['is_active']??1)]);
    $id=(int)$pdo->lastInsertId();
    return $json($res,['data'=>$pdo->query("SELECT * FROM ai_providers WHERE id=$id")->fetch(PDO::FETCH_ASSOC)],201);
});

$app->get('/api/admin/prompt-templates', function($r,$res) use($json, $pdo){
    $rows = $pdo->query("SELECT * FROM prompt_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

$app->get('/api/publish/wordpress', function($r,$res) use($json, $pdo){
    $rows = $pdo->query("SELECT * FROM wordpress_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

$app->get('/api/publish/blogger', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT * FROM blogger_blogs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

$app->get('/api/publish/amazon', function($r,$res) use($json,$pdo,$getCurrentUserId){
    $uid=$getCurrentUserId();
    $st=$pdo->prepare("SELECT id,title,access_key,partner_tag,country,is_active FROM amazon_apis WHERE user_id=? ORDER BY id DESC");
    $st->execute([$uid]);
    return $json($res,['data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
});

$app->post('/api/jobs/start', function($r,$res) use($json,$readJson,$pdo){
    $d=$readJson($r);
    if(!isset($d['type'])) return $json($res,['error'=>'type required'],422);
    $pdo->prepare("INSERT INTO jobs(type,model,payload_json,status) VALUES(?,?,?, 'queued')")
        ->execute([$d['type'],$d['model']??null,json_encode($d['options']??[],JSON_UNESCAPED_UNICODE)]);
    return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
});

$app->get('/api/jobs', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,type,status,created_at FROM jobs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

// --- Final Error Middleware এবং App Run ---
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$app->run();
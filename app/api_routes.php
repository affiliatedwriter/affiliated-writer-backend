<?php
// This file is included by routes.php to keep it clean.
// It assumes $app, $json, $readJson, $pdo, $getCurrentUserId are available in its scope.

/* ===== Root URL (Homepage) ===== */
$app->get('/', function(Request $r, Response $res) use ($json) {
    return $json($res, [
        'status' => 'ok',
        'message' => 'Welcome to the Affiliated Writer API!',
        'service_health' => '/api/db/ping'
    ]);
});
/* ===== Dashboard ===== */
$app->get('/api/admin/overview', function(Request $r, Response $res) use ($json, $pdo){
    $articles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
    $prompts  = (int)$pdo->query("SELECT COUNT(*) FROM prompt_templates")->fetchColumn();
    $providers= (int)$pdo->query("SELECT COUNT(*) FROM ai_providers")->fetchColumn();
    $sumCreds = (int)($pdo->query("SELECT COALESCE(SUM(credits),0) FROM users")->fetchColumn() ?: 0);
    return $json($res, [
      'articles'=>$articles,'prompts'=>$prompts,'providers'=>$providers,
      'credits_left'=>$sumCreds,'credits_expiry'=>null
    ]);
});

/* ===== Articles list ===== */
$app->get('/api/articles', function(Request $r, Response $res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,title,platform,status,updated_at FROM articles ORDER BY id DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['articles'=>$rows]);
});

/* ===== Feature flags ===== */
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

/* ===== Users ===== */
$app->get('/api/admin/users[/{tail:.*}]', function($r, $res) use ($json, $pdo){
    $rows = $pdo->query("SELECT id,name,email,credits,credits_expiry FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res,['data' => $rows]);
});

/* ===== Providers ===== */
$app->get('/api/admin/providers', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT * FROM ai_providers ORDER BY priority ASC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res,['data' => $rows]);
});
$app->post('/api/admin/providers', function($r,$res) use($json,$readJson,$pdo){
    $d=$readJson($r);
    $pdo->prepare("INSERT INTO ai_providers(name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active) VALUES(?,?,?,?,?,?,?,?)")
        ->execute([$d['name']??'Provider',$d['base_url']??'',$d['model_name']??'',$d['api_key']??'',(float)($d['temperature']??0.7),(int)($d['priority']??10),$d['assigned_section']??'general',(int)($d['is_active']??1)]);
    $id=$pdo->lastInsertId();
    return $json($res,['data'=>$pdo->query("SELECT * FROM ai_providers WHERE id=$id")->fetch(PDO::FETCH_ASSOC)],201);
});

/* ===== Prompt templates ===== */
$app->get('/api/admin/prompt-templates', function($r,$res) use($json, $pdo){
    $rows = $pdo->query("SELECT * FROM prompt_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

/* ===== WordPress ===== */
$app->get('/api/publish/wordpress', function($r,$res) use($json, $pdo){
    $rows = $pdo->query("SELECT * FROM wordpress_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

/* ===== Blogger ===== */
$app->get('/api/publish/blogger', function($r,$res) use ($json, $pdo){
    $rows = $pdo->query("SELECT * FROM blogger_blogs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data' => $rows]);
});

/* ===== Amazon ===== */
$app->get('/api/publish/amazon', function($r,$res) use($json,$pdo,$getCurrentUserId){
    $uid=$getCurrentUserId($pdo);
    $st=$pdo->prepare("SELECT id,title,access_key,partner_tag,country,is_active FROM amazon_apis WHERE user_id=? ORDER BY id DESC");
    $st->execute([$uid]);
    return $json($res,['data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
});

/* ===== Jobs ===== */
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

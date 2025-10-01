<?php
declare(strict_types=1);

use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/* -------------------- helpers -------------------- */
$readJson = function (Request $req): array {
    $data = $req->getParsedBody();
    if (is_array($data)) return $data;
    $raw = (string)$req->getBody();
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
};
$json = function (Response $res, $data, int $code = 200): Response {
    $res->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    return $res->withHeader('Content-Type', 'application/json')->withStatus($code);
};
$getCurrentUserId = function(PDO $pdo): int {
    $id = (int)($pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
    return $id > 0 ? $id : 1;
};

/* -------------------- migrations (SQLite version) -------------------- */
$ensure = function (PDO $pdo): void {
    // ARTICLES
    $pdo->exec("CREATE TABLE IF NOT EXISTS articles(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      platform TEXT NOT NULL,
      status TEXT NOT NULL,
      updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // FEATURE FLAGS
    $pdo->exec("CREATE TABLE IF NOT EXISTS feature_flags(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT UNIQUE NOT NULL,
      enabled INTEGER NOT NULL DEFAULT 1
    )");

    // USERS
    $pdo->exec("CREATE TABLE IF NOT EXISTS users(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      email TEXT NOT NULL,
      credits INTEGER NOT NULL DEFAULT 0,
      credits_expiry TEXT NULL
    )");

    // AI PROVIDERS
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_providers(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      base_url TEXT NOT NULL,
      model_name TEXT NOT NULL,
      api_key TEXT NOT NULL,
      temperature REAL NOT NULL DEFAULT 0.7,
      priority INTEGER NOT NULL DEFAULT 10,
      assigned_section TEXT NOT NULL DEFAULT 'general',
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // PROMPT TEMPLATES
    $pdo->exec("CREATE TABLE IF NOT EXISTS prompt_templates(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      section TEXT NOT NULL,
      ai_provider_id INTEGER NULL,
      template TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // WORDPRESS SITES
    $pdo->exec("CREATE TABLE IF NOT EXISTS wordpress_sites(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      base_url TEXT NOT NULL,
      username TEXT NOT NULL,
      app_password TEXT NOT NULL,
      default_category_id INTEGER NULL,
      default_status TEXT NOT NULL DEFAULT 'draft',
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // BLOGGER BLOGS
    $pdo->exec("CREATE TABLE IF NOT EXISTS blogger_blogs(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      blog_id TEXT NOT NULL,
      api_key TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // AMAZON APIs
    $pdo->exec("CREATE TABLE IF NOT EXISTS amazon_apis(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER NOT NULL,
      title TEXT NOT NULL,
      access_key TEXT NOT NULL,
      secret_key TEXT NOT NULL,
      partner_tag TEXT NOT NULL,
      country TEXT NOT NULL,
      is_active INTEGER NOT NULL DEFAULT 1,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // JOBS
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs(
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      type TEXT NOT NULL,
      model TEXT NULL,
      payload_json TEXT NULL,
      status TEXT NOT NULL DEFAULT 'queued',
      error TEXT NULL,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    /* ---- Seed Data ---- */
    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO users(name,email,credits,credits_expiry) VALUES
        ('Admin User','admin@example.com',6300,'2025-12-22'),
        ('Demo Writer','writer@example.com',1200,'2025-11-22')");
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM feature_flags")->fetchColumn() === 0) {
        $flags = ['amazon_api_enabled','bulk_generate_enabled','comparison_table_auto','cta_auto_enable'];
        $ins = $pdo->prepare("INSERT INTO feature_flags(name,enabled) VALUES(?,1)");
        foreach($flags as $f){ $ins->execute([$f]); }
    }
};

/* -------------------- attach routes -------------------- */
return function (App $app) use ($readJson, $json, $ensure, $getCurrentUserId) {

    /** @var PDO $pdo */
    $pdo = $app->getContainer()->get('db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ensure($pdo);

    /* ---- CORS ---- */
    $app->add(function(Request $req, $handler){
        $res = $handler->handle($req);
        $origin = $req->getHeaderLine('Origin') ?: '*';
        $allow = in_array($origin, ['http://localhost:3000','http://127.0.0.1:3000'], true) ? $origin : '*';
        return $res
          ->withHeader('Access-Control-Allow-Origin', $allow)
          ->withHeader('Access-Control-Allow-Credentials', 'true')
          ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
          ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    });
    $app->options('/{routes:.+}', fn(Request $r, Response $res) => $res->withStatus(204));

    /* ---- Health ---- */
    $app->get('/api/db/ping', function(Request $r, Response $res) use($json, $pdo){
        try { $pdo->query("SELECT 1"); return $json($res, ['db'=>'up']); }
        catch(Throwable $e){ return $json($res, ['db'=>'down','error'=>$e->getMessage()], 500); }
    });

    /* ===== Dashboard quick stats ===== */
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

    /* ===== Articles list (optional UI) ===== */
    $app->get('/api/articles', function(Request $r, Response $res) use ($json, $pdo){
        $rows = $pdo->query("SELECT id,title,platform,status,updated_at FROM articles ORDER BY id DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['articles'=>$rows]);
    });

    /* ===== Feature flags (read/toggle) ===== */
    $app->get('/api/admin/feature-flags', function(Request $r, Response $res) use ($json, $pdo){
        $rows = $pdo->query("SELECT id,name,(enabled+0) AS enabled FROM feature_flags ORDER BY name")
                    ->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['flags'=>$rows]);
    });
    $app->post('/api/admin/feature-flags/{id:\d+}/toggle', function(Request $r, Response $res, array $a) use ($json, $pdo){
        $id=(int)$a['id'];
        $pdo->prepare("UPDATE feature_flags SET enabled = 1 - (enabled+0) WHERE id=?")->execute([$id]);
        $row=$pdo->query("SELECT id,(enabled+0) AS enabled FROM feature_flags WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res, ['error'=>'Not Found'],404);
        return $json($res, $row);
    });

    /* ===== ADMIN users (Credits page needs this) ===== */
    $app->get('/api/admin/users[/{tail:.*}]', function(Request $r, Response $res) use ($json, $pdo){
        $rows=$pdo->query("SELECT id,name,email,credits,credits_expiry FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
/* ===== Admin: AI Providers ===== */
$app->get('/api/admin/providers', function(Request $r, Response $res) use ($json,$pdo){
    $rows=$pdo->query("SELECT id,name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active
                       FROM ai_providers ORDER BY priority ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$rows]);
});
$app->post('/api/admin/providers', function(Request $r, Response $res) use ($json,$readJson,$pdo){
    $d=$readJson($r);
    $st=$pdo->prepare("INSERT INTO ai_providers(name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active)
                       VALUES(?,?,?,?,?,?,?,?)");
    $st->execute([
      (string)($d['name']??'Provider'),
      (string)($d['base_url']??''),
      (string)($d['model_name']??''),
      (string)($d['api_key']??''),
      (float)($d['temperature']??0.7),
      (int)($d['priority']??10),
      (string)($d['assigned_section']??'general'),
      (int)($d['is_active']??1),
    ]);
    $id=(int)$pdo->lastInsertId();
    $row=$pdo->query("SELECT * FROM ai_providers WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$row], 201);
});
$app->put('/api/admin/providers/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
    $id=(int)$a['id']; $d=$readJson($r);
    $st=$pdo->prepare("UPDATE ai_providers SET name=?,base_url=?,model_name=?,api_key=?,temperature=?,priority=?,assigned_section=?,is_active=? WHERE id=?");
    $st->execute([
      (string)($d['name']??'Provider'),
      (string)($d['base_url']??''),
      (string)($d['model_name']??''),
      (string)($d['api_key']??''),
      (float)($d['temperature']??0.7),
      (int)($d['priority']??10),
      (string)($d['assigned_section']??'general'),
      (int)($d['is_active']??1),
      $id
    ]);
    $row=$pdo->query("SELECT * FROM ai_providers WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$row]);
});
$app->delete('/api/admin/providers/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
    $pdo->prepare("DELETE FROM ai_providers WHERE id=?")->execute([(int)$a['id']]);
    return $json($res, ['deleted'=>true]);
});
/* ===== Admin: Prompt Templates ===== */
$app->get('/api/admin/prompt-templates', function(Request $r, Response $res) use ($json,$pdo){
    $rows=$pdo->query("SELECT id,name,section,ai_provider_id,template,is_active,created_at
                       FROM prompt_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$rows]);
});
$app->post('/api/admin/prompt-templates', function(Request $r, Response $res) use ($json,$readJson,$pdo){
    $d=$readJson($r);
    $st=$pdo->prepare("INSERT INTO prompt_templates(name,section,ai_provider_id,template,is_active) VALUES(?,?,?,?,?)");
    $st->execute([
      (string)($d['name']??'Template'),
      (string)($d['section']??'info_article'),
      isset($d['ai_provider_id']) && $d['ai_provider_id']!=='' ? (int)$d['ai_provider_id'] : null,
      (string)($d['template']??''),
      (int)($d['is_active']??1),
    ]);
    return $json($res, ['created'=>true,'id'=>(int)$pdo->lastInsertId()], 201);
});
$app->put('/api/admin/prompt-templates/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
    $id=(int)$a['id']; $d=$readJson($r);
    $st=$pdo->prepare("UPDATE prompt_templates SET name=?,section=?,ai_provider_id=?,template=?,is_active=? WHERE id=?");
    $st->execute([
      (string)($d['name']??'Template'),
      (string)($d['section']??'info_article'),
      isset($d['ai_provider_id']) && $d['ai_provider_id']!=='' ? (int)$d['ai_provider_id'] : null,
      (string)($d['template']??''),
      (int)($d['is_active']??1),
      $id
    ]);
    return $json($res, ['updated'=>true]);
});
$app->delete('/api/admin/prompt-templates/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
    $pdo->prepare("DELETE FROM prompt_templates WHERE id=?")->execute([(int)$a['id']]);
    return $json($res, ['deleted'=>true]);
});
    /* ====== PUBLISH: WordPress ====== */
    $app->get('/api/publish/wordpress', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,title,base_url,username,app_password,default_category_id,default_status,is_active
                           FROM wordpress_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
    $app->post('/api/publish/wordpress', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        $st=$pdo->prepare("INSERT INTO wordpress_sites(title,base_url,username,app_password,default_category_id,default_status,is_active)
                           VALUES(?,?,?,?,?,?,?)");
        $st->execute([
          (string)($d['title']??''),
          (string)($d['base_url']??''),
          (string)($d['username']??''),
          (string)($d['app_password']??''),
          isset($d['default_category_id']) && $d['default_category_id']!=='' ? (int)$d['default_category_id'] : null,
          (string)($d['default_status']??'draft'),
          (int)($d['is_active']??1)
        ]);
        return $json($res, ['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/wordpress/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $st=$pdo->prepare("UPDATE wordpress_sites
          SET title=?,base_url=?,username=?,app_password=?,default_category_id=?,default_status=?,is_active=? WHERE id=?");
        $st->execute([
          (string)($d['title']??''),(string)($d['base_url']??''),(string)($d['username']??''),
          (string)($d['app_password']??''),
          isset($d['default_category_id']) && $d['default_category_id']!=='' ? (int)$d['default_category_id'] : null,
          (string)($d['default_status']??'draft'), (int)($d['is_active']??1), $id
        ]);
        return $json($res, ['ok'=>true]);
    });
    $app->delete('/api/publish/wordpress/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM wordpress_sites WHERE id=?")->execute([(int)$a['id']]);
        return $json($res, ['ok'=>true]);
    });
    // test endpoint (simple auth check stub)
    $app->post('/api/publish/wordpress/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT base_url,username,app_password FROM wordpress_sites WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Site not found'],404);
        return $json($res,['ok'=>true,'message'=>'Connection looks OK (stub).']);
    });
    // categories for a WP site (compatible path)
    $app->get('/api/publish/wordpress/{id:\d+}/categories', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT default_category_id FROM wordpress_sites WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        $cats=[];
        if($row && $row['default_category_id']!==null){
            $base=(int)$row['default_category_id'];
            $cats[]=['id'=>$base,'name'=>'Blog'];
            $cats[]=['id'=>$base+1,'name'=>'Deals'];
            $cats[]=['id'=>$base+2,'name'=>'Landing Page'];
        }
        return $json($res,['data'=>$cats]);
    });

    /* ====== PUBLISH: Blogger ====== */
    $app->get('/api/publish/blogger', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,title,blog_id,api_key,is_active FROM blogger_blogs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
    $app->post('/api/publish/blogger', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        $st=$pdo->prepare("INSERT INTO blogger_blogs(title,blog_id,api_key,is_active) VALUES(?,?,?,?)");
        $st->execute([(string)($d['title']??''),(string)($d['blog_id']??''),(string)($d['api_key']??''),(int)($d['is_active']??1)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/blogger/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $pdo->prepare("UPDATE blogger_blogs SET title=?,blog_id=?,api_key=?,is_active=? WHERE id=?")
            ->execute([(string)($d['title']??''),(string)($d['blog_id']??''),(string)($d['api_key']??''),(int)($d['is_active']??1),$id]);
        return $json($res,['ok'=>true]);
    });
    $app->delete('/api/publish/blogger/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM blogger_blogs WHERE id=?")->execute([(int)$a['id']]);
        return $json($res,['ok'=>true]);
    });
    $app->post('/api/publish/blogger/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT api_key FROM blogger_blogs WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Blogger config not found'],404);
        return $json($res,['ok'=>true,'message'=>'API Key looks OK (stub).']);
    });

    /* ====== PUBLISH: Amazon API configs ====== */
    $app->get('/api/publish/amazon', function(Request $r, Response $res) use ($json,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo);
        $st=$pdo->prepare("SELECT id,title,access_key,secret_key,partner_tag AS partnerTag,country,is_active
                           FROM amazon_apis WHERE user_id=? ORDER BY id DESC");
        $st->execute([$uid]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){ $row['secret_key']=''; } // don't expose
        return $json($res,['data'=>$rows]);
    });
    $app->post('/api/publish/amazon', function(Request $r, Response $res) use ($json,$readJson,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo); $d=$readJson($r);
        $partner = $d['partnerTag'] ?? $d['partner_tag'] ?? '';
        $st=$pdo->prepare("INSERT INTO amazon_apis(user_id,title,access_key,secret_key,partner_tag,country,is_active)
                           VALUES(?,?,?,?,?,?,?)");
        $st->execute([$uid,(string)($d['title']??''),(string)($d['access_key']??''),(string)($d['secret_key']??''),(string)$partner,(string)($d['country']??'amazon.com - United States (US)'),
                      (int)($d['is_active']??1)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/amazon/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $partner = $d['partnerTag'] ?? $d['partner_tag'] ?? '';
        if (($d['secret_key'] ?? '') === '') {
            $st=$pdo->prepare("UPDATE amazon_apis SET title=?,access_key=?,partner_tag=?,country=?,is_active=? WHERE id=?");
            $st->execute([(string)($d['title']??''),(string)($d['access_key']??''),(string)$partner,(string)($d['country']??''),(int)($d['is_active']??1),$id]);
        } else {
            $st=$pdo->prepare("UPDATE amazon_apis SET title=?,access_key=?,secret_key=?,partner_tag=?,country=?,is_active=? WHERE id=?");
            $st->execute([(string)($d['title']??''),(string)($d['access_key']??''),(string)$d['secret_key'],(string)$partner,(string)($d['country']??''),(int)($d['is_active']??1),$id]);
        }
        return $json($res,['ok'=>true]);
    });
    $app->delete('/api/publish/amazon/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM amazon_apis WHERE id=?")->execute([(int)$a['id']]);
        return $json($res,['ok'=>true]);
    });
    $app->post('/api/publish/amazon/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT access_key,partner_tag FROM amazon_apis WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Amazon config not found'],404);
        return $json($res,['ok'=>true,'message'=>'Credentials look OK (stub).']);
    });

    /* ===== Publish options (combined) ===== */
    $app->get('/api/publish/options', function(Request $r, Response $res) use ($json,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo);
        $am=$pdo->prepare("SELECT id,title,partner_tag AS partnerTag,country,is_active FROM amazon_apis WHERE user_id=? AND is_active=1 ORDER BY id DESC");
        $am->execute([$uid]);
        $wp=$pdo->query("SELECT id,title,default_category_id,default_status,base_url,username,app_password,is_active FROM wordpress_sites WHERE is_active=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $bg=$pdo->query("SELECT id,title,blog_id,api_key,is_active FROM blogger_blogs WHERE is_active=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res,['amazonApis'=>$am->fetchAll(PDO::FETCH_ASSOC),'wordpressSites'=>$wp,'bloggerBlogs'=>$bg]);
    });

    /* ===== Product search stub (Amazon products) ===== */
    $app->map(['GET','POST'],'/api/amazon/search', function(Request $r, Response $res) use ($json,$readJson){
        $q=$r->getMethod()==='POST'?$readJson($r):$r->getQueryParams();
        $kw=trim((string)($q['q']??'')); $limit=max(3,(int)($q['limit']??10));
        if($kw==='') return $json($res,['items'=>[]]);
        $items=[];
        for($i=1;$i<=$limit;$i++){
            $asin='B0'.substr(strtoupper(md5($kw.$i)),0,8);
            $items[]=[
              'asin'=>$asin,
              'title'=>"$kw — Sample Product $i",
              'image'=>"https://via.placeholder.com/300?text=$asin",
              'rating'=>4.3, 'price'=>49.99+$i
            ];
        }
        return $json($res,['items'=>$items]);
    });

    /* ===== Jobs ===== */
    $app->post('/api/jobs/start', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        if(!isset($d['type'])) return $json($res,['error'=>'type required'],422);
        $st=$pdo->prepare("INSERT INTO jobs(type,model,payload_json,status) VALUES(?,?,?, 'queued')");
        $st->execute([(string)$d['type'], $d['model']??null, json_encode($d['options']??[], JSON_UNESCAPED_UNICODE)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->get('/api/jobs', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,type,status,created_at FROM jobs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res,['data'=>$rows]);
    });
};
/* -------------------- attach routes -------------------- */
return function (App $app) use ($readJson, $json, $ensure, $getCurrentUserId) {

    /** @var PDO $pdo */
    $pdo = $app->getContainer()->get('db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $ensure($pdo);

    /* ---- CORS ---- */
    $app->add(function(Request $req, $handler){
        $res = $handler->handle($req);
        $origin = $req->getHeaderLine('Origin') ?: '*';
        $allow = in_array($origin, ['http://localhost:3000','http://127.0.0.1:3000'], true) ? $origin : '*';
        return $res
          ->withHeader('Access-Control-Allow-Origin', $allow)
          ->withHeader('Access-Control-Allow-Credentials', 'true')
          ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization')
          ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    });
    $app->options('/{routes:.+}', fn(Request $r, Response $res) => $res->withStatus(204));

    /* ---- Health ---- */
    $app->get('/api/db/ping', function(Request $r, Response $res) use($json, $pdo){
        try { $pdo->query("SELECT 1"); return $json($res, ['db'=>'up']); }
        catch(Throwable $e){ return $json($res, ['db'=>'down','error'=>$e->getMessage()], 500); }
    });

    /* ===== Dashboard quick stats ===== */
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

    /* ===== Articles list (optional UI) ===== */
    $app->get('/api/articles', function(Request $r, Response $res) use ($json, $pdo){
        $rows = $pdo->query("SELECT id,title,platform,status,updated_at FROM articles ORDER BY id DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['articles'=>$rows]);
    });

    /* ===== Feature flags (read/toggle) ===== */
    $app->get('/api/admin/feature-flags', function(Request $r, Response $res) use ($json, $pdo){
        $rows = $pdo->query("SELECT id,name,(enabled+0) AS enabled FROM feature_flags ORDER BY name")
                    ->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['flags'=>$rows]);
    });
    $app->post('/api/admin/feature-flags/{id:\d+}/toggle', function(Request $r, Response $res, array $a) use ($json, $pdo){
        $id=(int)$a['id'];
        $pdo->prepare("UPDATE feature_flags SET enabled = 1 - (enabled+0) WHERE id=?")->execute([$id]);
        $row=$pdo->query("SELECT id,(enabled+0) AS enabled FROM feature_flags WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res, ['error'=>'Not Found'],404);
        return $json($res, $row);
    });

    /* ===== ADMIN users (Credits page needs this) ===== */
    $app->get('/api/admin/users[/{tail:.*}]', function(Request $r, Response $res) use ($json, $pdo){
        $rows=$pdo->query("SELECT id,name,email,credits,credits_expiry FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
/* ===== Admin: AI Providers ===== */
$app->get('/api/admin/providers', function(Request $r, Response $res) use ($json,$pdo){
    $rows=$pdo->query("SELECT id,name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active
                       FROM ai_providers ORDER BY priority ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$rows]);
});
$app->post('/api/admin/providers', function(Request $r, Response $res) use ($json,$readJson,$pdo){
    $d=$readJson($r);
    $st=$pdo->prepare("INSERT INTO ai_providers(name,base_url,model_name,api_key,temperature,priority,assigned_section,is_active)
                       VALUES(?,?,?,?,?,?,?,?)");
    $st->execute([
      (string)($d['name']??'Provider'),
      (string)($d['base_url']??''),
      (string)($d['model_name']??''),
      (string)($d['api_key']??''),
      (float)($d['temperature']??0.7),
      (int)($d['priority']??10),
      (string)($d['assigned_section']??'general'),
      (int)($d['is_active']??1),
    ]);
    $id=(int)$pdo->lastInsertId();
    $row=$pdo->query("SELECT * FROM ai_providers WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$row], 201);
});
$app->put('/api/admin/providers/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
    $id=(int)$a['id']; $d=$readJson($r);
    $st=$pdo->prepare("UPDATE ai_providers SET name=?,base_url=?,model_name=?,api_key=?,temperature=?,priority=?,assigned_section=?,is_active=? WHERE id=?");
    $st->execute([
      (string)($d['name']??'Provider'),
      (string)($d['base_url']??''),
      (string)($d['model_name']??''),
      (string)($d['api_key']??''),
      (float)($d['temperature']??0.7),
      (int)($d['priority']??10),
      (string)($d['assigned_section']??'general'),
      (int)($d['is_active']??1),
      $id
    ]);
    $row=$pdo->query("SELECT * FROM ai_providers WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$row]);
});
$app->delete('/api/admin/providers/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
    $pdo->prepare("DELETE FROM ai_providers WHERE id=?")->execute([(int)$a['id']]);
    return $json($res, ['deleted'=>true]);
});
/* ===== Admin: Prompt Templates ===== */
$app->get('/api/admin/prompt-templates', function(Request $r, Response $res) use ($json,$pdo){
    $rows=$pdo->query("SELECT id,name,section,ai_provider_id,template,is_active,created_at
                       FROM prompt_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    return $json($res, ['data'=>$rows]);
});
$app->post('/api/admin/prompt-templates', function(Request $r, Response $res) use ($json,$readJson,$pdo){
    $d=$readJson($r);
    $st=$pdo->prepare("INSERT INTO prompt_templates(name,section,ai_provider_id,template,is_active) VALUES(?,?,?,?,?)");
    $st->execute([
      (string)($d['name']??'Template'),
      (string)($d['section']??'info_article'),
      isset($d['ai_provider_id']) && $d['ai_provider_id']!=='' ? (int)$d['ai_provider_id'] : null,
      (string)($d['template']??''),
      (int)($d['is_active']??1),
    ]);
    return $json($res, ['created'=>true,'id'=>(int)$pdo->lastInsertId()], 201);
});
$app->put('/api/admin/prompt-templates/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
    $id=(int)$a['id']; $d=$readJson($r);
    $st=$pdo->prepare("UPDATE prompt_templates SET name=?,section=?,ai_provider_id=?,template=?,is_active=? WHERE id=?");
    $st->execute([
      (string)($d['name']??'Template'),
      (string)($d['section']??'info_article'),
      isset($d['ai_provider_id']) && $d['ai_provider_id']!=='' ? (int)$d['ai_provider_id'] : null,
      (string)($d['template']??''),
      (int)($d['is_active']??1),
      $id
    ]);
    return $json($res, ['updated'=>true]);
});
$app->delete('/api/admin/prompt-templates/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
    $pdo->prepare("DELETE FROM prompt_templates WHERE id=?")->execute([(int)$a['id']]);
    return $json($res, ['deleted'=>true]);
});
    /* ====== PUBLISH: WordPress ====== */
    $app->get('/api/publish/wordpress', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,title,base_url,username,app_password,default_category_id,default_status,is_active
                           FROM wordpress_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
    $app->post('/api/publish/wordpress', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        $st=$pdo->prepare("INSERT INTO wordpress_sites(title,base_url,username,app_password,default_category_id,default_status,is_active)
                           VALUES(?,?,?,?,?,?,?)");
        $st->execute([
          (string)($d['title']??''),
          (string)($d['base_url']??''),
          (string)($d['username']??''),
          (string)($d['app_password']??''),
          isset($d['default_category_id']) && $d['default_category_id']!=='' ? (int)$d['default_category_id'] : null,
          (string)($d['default_status']??'draft'),
          (int)($d['is_active']??1)
        ]);
        return $json($res, ['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/wordpress/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $st=$pdo->prepare("UPDATE wordpress_sites
          SET title=?,base_url=?,username=?,app_password=?,default_category_id=?,default_status=?,is_active=? WHERE id=?");
        $st->execute([
          (string)($d['title']??''),(string)($d['base_url']??''),(string)($d['username']??''),
          (string)($d['app_password']??''),
          isset($d['default_category_id']) && $d['default_category_id']!=='' ? (int)$d['default_category_id'] : null,
          (string)($d['default_status']??'draft'), (int)($d['is_active']??1), $id
        ]);
        return $json($res, ['ok'=>true]);
    });
    $app->delete('/api/publish/wordpress/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM wordpress_sites WHERE id=?")->execute([(int)$a['id']]);
        return $json($res, ['ok'=>true]);
    });
    // test endpoint (simple auth check stub)
    $app->post('/api/publish/wordpress/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT base_url,username,app_password FROM wordpress_sites WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Site not found'],404);
        return $json($res,['ok'=>true,'message'=>'Connection looks OK (stub).']);
    });
    // categories for a WP site (compatible path)
    $app->get('/api/publish/wordpress/{id:\d+}/categories', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT default_category_id FROM wordpress_sites WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        $cats=[];
        if($row && $row['default_category_id']!==null){
            $base=(int)$row['default_category_id'];
            $cats[]=['id'=>$base,'name'=>'Blog'];
            $cats[]=['id'=>$base+1,'name'=>'Deals'];
            $cats[]=['id'=>$base+2,'name'=>'Landing Page'];
        }
        return $json($res,['data'=>$cats]);
    });

    /* ====== PUBLISH: Blogger ====== */
    $app->get('/api/publish/blogger', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,title,blog_id,api_key,is_active FROM blogger_blogs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res, ['data'=>$rows]);
    });
    $app->post('/api/publish/blogger', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        $st=$pdo->prepare("INSERT INTO blogger_blogs(title,blog_id,api_key,is_active) VALUES(?,?,?,?)");
        $st->execute([(string)($d['title']??''),(string)($d['blog_id']??''),(string)($d['api_key']??''),(int)($d['is_active']??1)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/blogger/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $pdo->prepare("UPDATE blogger_blogs SET title=?,blog_id=?,api_key=?,is_active=? WHERE id=?")
            ->execute([(string)($d['title']??''),(string)($d['blog_id']??''),(string)($d['api_key']??''),(int)($d['is_active']??1),$id]);
        return $json($res,['ok'=>true]);
    });
    $app->delete('/api/publish/blogger/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM blogger_blogs WHERE id=?")->execute([(int)$a['id']]);
        return $json($res,['ok'=>true]);
    });
    $app->post('/api/publish/blogger/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT api_key FROM blogger_blogs WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Blogger config not found'],404);
        return $json($res,['ok'=>true,'message'=>'API Key looks OK (stub).']);
    });

    /* ====== PUBLISH: Amazon API configs ====== */
    $app->get('/api/publish/amazon', function(Request $r, Response $res) use ($json,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo);
        $st=$pdo->prepare("SELECT id,title,access_key,secret_key,partner_tag AS partnerTag,country,is_active
                           FROM amazon_apis WHERE user_id=? ORDER BY id DESC");
        $st->execute([$uid]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row){ $row['secret_key']=''; } // don't expose
        return $json($res,['data'=>$rows]);
    });
    $app->post('/api/publish/amazon', function(Request $r, Response $res) use ($json,$readJson,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo); $d=$readJson($r);
        $partner = $d['partnerTag'] ?? $d['partner_tag'] ?? '';
        $st=$pdo->prepare("INSERT INTO amazon_apis(user_id,title,access_key,secret_key,partner_tag,country,is_active)
                           VALUES(?,?,?,?,?,?,?)");
        $st->execute([$uid,(string)($d['title']??''),(string)($d['access_key']??''),(string)($d['secret_key']??''),(string)$partner,(string)($d['country']??'amazon.com - United States (US)'),
                      (int)($d['is_active']??1)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->put('/api/publish/amazon/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$readJson,$pdo){
        $id=(int)$a['id']; $d=$readJson($r);
        $partner = $d['partnerTag'] ?? $d['partner_tag'] ?? '';
        if (($d['secret_key'] ?? '') === '') {
            $st=$pdo->prepare("UPDATE amazon_apis SET title=?,access_key=?,partner_tag=?,country=?,is_active=? WHERE id=?");
            $st->execute([(string)($d['title']??''),(string)($d['access_key']??''),(string)$partner,(string)($d['country']??''),(int)($d['is_active']??1),$id]);
        } else {
            $st=$pdo->prepare("UPDATE amazon_apis SET title=?,access_key=?,secret_key=?,partner_tag=?,country=?,is_active=? WHERE id=?");
            $st->execute([(string)($d['title']??''),(string)($d['access_key']??''),(string)$d['secret_key'],(string)$partner,(string)($d['country']??''),(int)($d['is_active']??1),$id]);
        }
        return $json($res,['ok'=>true]);
    });
    $app->delete('/api/publish/amazon/{id:\d+}', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $pdo->prepare("DELETE FROM amazon_apis WHERE id=?")->execute([(int)$a['id']]);
        return $json($res,['ok'=>true]);
    });
    $app->post('/api/publish/amazon/{id:\d+}/test', function(Request $r, Response $res, array $a) use ($json,$pdo){
        $id=(int)$a['id'];
        $row=$pdo->query("SELECT access_key,partner_tag FROM amazon_apis WHERE id={$id}")->fetch(PDO::FETCH_ASSOC);
        if(!$row) return $json($res,['ok'=>false,'message'=>'Amazon config not found'],404);
        return $json($res,['ok'=>true,'message'=>'Credentials look OK (stub).']);
    });

    /* ===== Publish options (combined) ===== */
    $app->get('/api/publish/options', function(Request $r, Response $res) use ($json,$pdo,$getCurrentUserId){
        $uid=$getCurrentUserId($pdo);
        $am=$pdo->prepare("SELECT id,title,partner_tag AS partnerTag,country,is_active FROM amazon_apis WHERE user_id=? AND is_active=1 ORDER BY id DESC");
        $am->execute([$uid]);
        $wp=$pdo->query("SELECT id,title,default_category_id,default_status,base_url,username,app_password,is_active FROM wordpress_sites WHERE is_active=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        $bg=$pdo->query("SELECT id,title,blog_id,api_key,is_active FROM blogger_blogs WHERE is_active=1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res,['amazonApis'=>$am->fetchAll(PDO::FETCH_ASSOC),'wordpressSites'=>$wp,'bloggerBlogs'=>$bg]);
    });

    /* ===== Product search stub (Amazon products) ===== */
    $app->map(['GET','POST'],'/api/amazon/search', function(Request $r, Response $res) use ($json,$readJson){
        $q=$r->getMethod()==='POST'?$readJson($r):$r->getQueryParams();
        $kw=trim((string)($q['q']??'')); $limit=max(3,(int)($q['limit']??10));
        if($kw==='') return $json($res,['items'=>[]]);
        $items=[];
        for($i=1;$i<=$limit;$i++){
            $asin='B0'.substr(strtoupper(md5($kw.$i)),0,8);
            $items[]=[
              'asin'=>$asin,
              'title'=>"$kw — Sample Product $i",
              'image'=>"https://via.placeholder.com/300?text=$asin",
              'rating'=>4.3, 'price'=>49.99+$i
            ];
        }
        return $json($res,['items'=>$items]);
    });

    /* ===== Jobs ===== */
    $app->post('/api/jobs/start', function(Request $r, Response $res) use ($json,$readJson,$pdo){
        $d=$readJson($r);
        if(!isset($d['type'])) return $json($res,['error'=>'type required'],422);
        $st=$pdo->prepare("INSERT INTO jobs(type,model,payload_json,status) VALUES(?,?,?, 'queued')");
        $st->execute([(string)$d['type'], $d['model']??null, json_encode($d['options']??[], JSON_UNESCAPED_UNICODE)]);
        return $json($res,['ok'=>true,'id'=>(int)$pdo->lastInsertId()],201);
    });
    $app->get('/api/jobs', function(Request $r, Response $res) use ($json,$pdo){
        $rows=$pdo->query("SELECT id,type,status,created_at FROM jobs ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        return $json($res,['data'=>$rows]);
    });
};

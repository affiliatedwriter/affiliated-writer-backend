<?php
declare(strict_types=1);

/**
 * worker.php — single-job worker (supports both `jobs` and legacy `article_jobs`)
 * DB: affiliated_writer2
 */

$dsn  = 'mysql:host=127.0.0.1;dbname=affiliated_writer2;charset=utf8mb4';
$user = 'root';
$pass = '';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "[worker] running...\n";

/* MySQL strict-mode নরম */
$pdo->exec("SET SESSION sql_mode = ''");

/* ---------- helpers ---------- */
$tableExists = function(PDO $pdo, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
};

$getStatusEnum = function(PDO $pdo, string $table): array {
    $sql = "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'status'";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    $col = (string)($st->fetchColumn() ?: '');
    // parse enum('a','b',...)
    if (stripos($col, "enum(") === false) return [];
    $inside = trim(substr($col, 5, -1));
    $vals = array_map(function($x){ return trim(trim($x), "'"); }, explode(',', $inside));
    return $vals;
};

$ensureJobs = function(PDO $pdo, callable $tableExists): void {
    if (!$tableExists($pdo, 'jobs')) {
        // minimal jobs table (compatible with this worker)
        $pdo->exec("
            CREATE TABLE jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(60) NOT NULL,
                model VARCHAR(60) NULL,
                payload_json JSON NULL,
                status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
                error TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        echo "[worker] created table jobs\n";
    }
};

/* ---------- choose source table ---------- */
$hasJobs        = $tableExists($pdo, 'jobs');
$hasArticleJobs = $tableExists($pdo, 'article_jobs');

if (!$hasJobs && !$hasArticleJobs) {
    // একদম নেই? jobs বানিয়ে দেই
    $ensureJobs($pdo, $tableExists);
    $hasJobs = true;
}

$srcTable = $hasJobs ? 'jobs' : 'article_jobs';
$enums    = $getStatusEnum($pdo, $srcTable);

// status ভ্যালুগুলো টেবিল অনুযায়ী ঠিক করি
$queued   = in_array('queued', $enums, true)     ? 'queued'   : (in_array('pending', $enums, true) ? 'pending' : 'queued');
$running  = in_array('running', $enums, true)    ? 'running'  : (in_array('processing', $enums, true) ? 'processing' : 'running');
$done     = in_array('done', $enums, true)       ? 'done'     : (in_array('completed', $enums, true) ? 'completed' : 'done');
$failed   = in_array('failed', $enums, true)     ? 'failed'   : (in_array('error', $enums, true) ? 'error' : 'failed');

/* ---------- pull single job ---------- */
$sql = "SELECT * FROM {$srcTable} WHERE status = :q ORDER BY id ASC LIMIT 1";
$st  = $pdo->prepare($sql);
$st->execute([':q' => $queued]);
$job = $st->fetch();

if (!$job) {
    echo "[worker] no jobs\n";
    exit;
}

/* ---------- normalize payload ---------- */
$payload = [];
if ($srcTable === 'jobs') {
    $payload = isset($job['payload_json']) ? json_decode((string)$job['payload_json'], true) : [];
} else {
    // legacy article_jobs: options + integrations -> payload_json
    $opt  = isset($job['options'])       ? json_decode((string)$job['options'], true)       : [];
    $inte = isset($job['integrations'])  ? json_decode((string)$job['integrations'], true)  : [];
    if (!is_array($opt))  $opt  = [];
    if (!is_array($inte)) $inte = [];
    $payload = ['options' => $opt, 'integrations' => $inte];
}
if (!is_array($payload)) $payload = [];

/* ---------- mark running ---------- */
$pdo->prepare("UPDATE {$srcTable} SET status = :r, updated_at = NOW() WHERE id = :id")
    ->execute([':r' => $running, ':id' => $job['id']]);

echo "[worker] starting job #{$job['id']} type={$job['type']}\n";

/* ---------- do work (stubs) ---------- */
try {
    $type = (string)$job['type'];

    switch ($type) {
        case 'info_bulk': {
            // both shapes supported:
            // - jobs.payload_json => { keywords:[] }
            // - article_jobs.options => { keywords:[] }
            $kws = $payload['keywords'] ?? ($payload['options']['keywords'] ?? []);
            if (!is_array($kws)) $kws = [];
            echo "   -> would generate ".count($kws)." info articles\n";
            $i = 1;
            foreach ($kws as $kw) {
                $kw = (string)$kw;
                if ($kw === '') continue;
                echo "      [{$i}] {$kw}\n";
                $i++;
            }
            break;
        }

        case 'amazon_bulk': {
            echo "   -> amazon bulk stub\n";
            // e.g., $payload['asinList'] etc...
            break;
        }

        case 'manual': {
            echo "   -> manual stub\n";
            break;
        }

        case 'single_product': {
            echo "   -> single product stub\n";
            break;
        }

        default: {
            echo "   -> unknown type '{$type}'\n";
        }
    }

    $pdo->prepare("UPDATE {$srcTable} SET status = :d, updated_at = NOW() WHERE id = :id")
        ->execute([':d' => $done, ':id' => $job['id']]);
    echo "[worker] job #{$job['id']} marked {$done}\n";

} catch (Throwable $e) {
    $pdo->prepare("UPDATE {$srcTable} SET status=:f, error=:err, updated_at = NOW() WHERE id=:id")
        ->execute([':f' => $failed, ':err' => $e->getMessage(), ':id' => $job['id']]);
    echo "[worker] job #{$job['id']} {$failed}: ".$e->getMessage()."\n";
}

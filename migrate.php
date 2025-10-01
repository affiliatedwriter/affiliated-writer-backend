<?php
declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;

// Autoload সব composer প্যাকেজ
require __DIR__ . '/vendor/autoload.php';

// Settings লোড করা
$settings = require __DIR__ . '/app/settings.php';

// DB connect
$capsule = new Capsule();
$capsule->addConnection($settings['db']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

// Helper
function columnExists(string $table, string $column): bool {
    $res = Capsule::select("
        SELECT COUNT(*) AS c
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ", [$table, $column]);
    return (int)($res[0]->c ?? 0) > 0;
}

/* ---------- USERS: credits & expiry ---------- */
if (!columnExists('users', 'credits')) {
    Capsule::statement("ALTER TABLE users ADD COLUMN credits BIGINT DEFAULT 0 AFTER role");
    echo "✅ Added users.credits\n";
}
if (!columnExists('users', 'credits_expiry')) {
    Capsule::statement("ALTER TABLE users ADD COLUMN credits_expiry DATE NULL AFTER credits");
    echo "✅ Added users.credits_expiry\n";
}

/* ---------- ARTICLES: word_count ---------- */
if (!columnExists('articles', 'word_count')) {
    Capsule::statement("ALTER TABLE articles ADD COLUMN word_count INT DEFAULT 0 AFTER html");
    echo "✅ Added articles.word_count\n";
}

/* ---------- COMPARISON_TEMPLATES ---------- */
Capsule::statement("CREATE TABLE IF NOT EXISTS comparison_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  html LONGTEXT NOT NULL,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "✅ Ensured comparison_templates\n";

/* ---------- FEATURE FLAGS defaults ---------- */
Capsule::statement("INSERT INTO feature_flags (name, enabled, created_at)
SELECT 'amazon_api_enabled', 0, NOW()
WHERE NOT EXISTS (SELECT 1 FROM feature_flags WHERE name='amazon_api_enabled')");
Capsule::statement("INSERT INTO feature_flags (name, enabled, created_at)
SELECT 'bulk_generate_enabled', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM feature_flags WHERE name='bulk_generate_enabled')");
Capsule::statement("INSERT INTO feature_flags (name, enabled, created_at)
SELECT 'image_integration_enabled', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM feature_flags WHERE name='image_integration_enabled')");
echo "✅ Feature flags seeded\n";

/* ---------- SETTINGS defaults ---------- */
$settingsData = [
    ['default_model','gpt-4o-mini',null],
    ['branding_title','Affiliated Writer',null],
    ['cta_button_color','#0ea5e9',null],
    ['cta_text_color','#ffffff',null],
    ['default_schema','Product',null],
    ['slug_mode','keyword',null],
    ['meta_description_mode','auto',null],
    ['image_source','amazon', json_encode(['allow_stock'=>true,'allow_google'=>true])]
];
foreach ($settingsData as [$key,$value,$json]) {
    Capsule::statement("INSERT INTO settings (`key`,`value`,`json`,`updated_at`,`created_at`)
        VALUES (?,?,?,NOW(),NOW())
        ON DUPLICATE KEY UPDATE `key`=`key`", [$key,$value,$json]);
}
echo "✅ Settings defaults ensured\n";

/* ---------- AI PROVIDERS ---------- */
Capsule::statement("INSERT INTO ai_providers (name, base_url, api_key, model_name, temperature, priority, is_active, created_at)
SELECT 'OpenRouter', 'https://openrouter.ai/api/v1', NULL, 'mistralai/mistral-7b-instruct', 0.70, 10, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM ai_providers WHERE name='OpenRouter')");
echo "✅ OpenRouter provider ensured\n";

echo "🎉 Migration complete!\n";

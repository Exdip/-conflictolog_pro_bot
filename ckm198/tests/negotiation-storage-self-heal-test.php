<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$guard = file_get_contents($root . '/includes/negotiation-storage-guard.php');
$api = file_get_contents($root . '/includes/negotiation-test-api.php');
$schema = file_get_contents($root . '/includes/standalone-schema.php');
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$smoke = file_get_contents($root . '/includes/negotiation-core-smoke.php');
$catalog = file_get_contents($root . '/includes/games-catalog.php');
$bridge = file_get_contents($root . '/includes/games-hub-runtime-bridge.php');

$checks = [];
function ck194($ok, $label) {
    global $checks;
    $checks[] = [$ok, $label];
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$ok) exit(1);
}

ck194(str_contains($main, "includes/negotiation-storage-guard.php"), 'plugin loads negotiation storage guard');
ck194(str_contains($guard, 'function ckm_negotiation_storage_health()'), 'guard exposes physical storage health check');
ck194(str_contains($guard, "'sessions' =>") && str_contains($guard, "'turns' =>") && str_contains($guard, "'events' =>") && str_contains($guard, "'requests' =>"), 'guard verifies all four negotiation tables');
ck194(str_contains($guard, "'state_json'") && str_contains($guard, "'message_seq'"), 'guard verifies persistence columns added by 191');
ck194(str_contains($guard, 'SHOW COLUMNS FROM') && str_contains($guard, 'SHOW INDEX FROM'), 'guard checks actual columns and indexes instead of trusting DB version option');
ck194(str_contains($guard, 'ckm_quiz_pro_install_schema()'), 'guard retries canonical dbDelta migration first');
ck194(str_contains($guard, 'ckm_negotiation_storage_create_missing_tables') && str_contains($guard, 'ckm_negotiation_storage_repair_columns') && str_contains($guard, 'ckm_negotiation_storage_repair_indexes'), 'guard has isolated fallback repairs');
ck194(str_contains($api, "ckm_negotiation_storage_ensure()"), 'test API start verifies storage before creating session');
ck194(str_contains($api, "storage_schema_unavailable"), 'unrecoverable storage mismatch returns explicit code');
ck194(str_contains($schema, "const CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16';"), 'DB schema version bumped to force canonical retry');
ck194(str_contains($smoke, "message:data.message || ''"), 'smoke log captures server error message');
ck194(str_contains($smoke, 'code=${codeOf(started)') && str_contains($smoke, 'message=${started.data?.message'), 'first REST failure is visible without opening technical details');
ck194(ckm_test_current_plugin_release($main), 'plugin version remains newer after smoke isolation fix');
ck194(str_contains($catalog, "'product'=>'negotiation_duel_v1'") && str_contains($bridge, "'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"), 'real-game routing remains legacy');
ck194(!str_contains($guard, 'DROP TABLE') && !str_contains($guard, 'TRUNCATE TABLE'), 'self-heal never destroys negotiation data');

echo 'TOTAL ' . count($checks) . '/' . count($checks) . ' PASS' . PHP_EOL;

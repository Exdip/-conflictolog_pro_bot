<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$smoke = file_get_contents($root . '/includes/negotiation-core-smoke.php');
$api = file_get_contents($root . '/includes/negotiation-test-api.php');
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$catalog = file_get_contents($root . '/includes/games-catalog.php');
$bridge = file_get_contents($root . '/includes/games-hub-runtime-bridge.php');

$checks = [];
function ck193($ok, $label) {
    global $checks;
    $checks[] = [$ok, $label];
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . PHP_EOL;
    if (!$ok) exit(1);
}

ck193(str_contains($smoke, "add_submenu_page(\n        null"), 'smoke page is hidden from visible admin menu');
ck193(str_contains($smoke, "'manage_options'"), 'smoke page requires administrator capability');
ck193(str_contains($smoke, "ckm-negotiation-core-smoke"), 'stable hidden smoke page slug');
ck193(str_contains($smoke, "set_transient('ckm_negotiation_smoke_api_'"), 'page opens short-lived per-admin API window');
ck193(str_contains($api, "get_transient('ckm_negotiation_smoke_api_'"), 'test API recognizes short-lived smoke window');
ck193(str_contains($api, 'Routes are always registered') && str_contains($api, "'permission_callback' => 'ckm_negotiation_test_api_permission'"), 'REST routes stay permission-gated even when registered');
ck193(str_contains($smoke, "wp_create_nonce('wp_rest')") && str_contains($smoke, "'X-WP-Nonce'"), 'browser smoke uses authenticated WordPress REST nonce');

foreach ([
    'negotiation-test/start',
    'negotiation-test/state',
    'negotiation-test/voice-finished',
    'negotiation-test/submit',
    'negotiation-test/evaluate',
    'negotiation-test/next',
    'negotiation-test/finish',
] as $route) {
    ck193(str_contains($smoke, $route), 'smoke drives real REST route ' . $route);
}

ck193(str_contains($smoke, "window.location.reload()"), 'smoke performs a real page reload for F5 recovery');
ck193(str_contains($smoke, "sessionStorage.setItem") && str_contains($smoke, "sessionStorage.getItem"), 'checkpoint survives page reload in sessionStorage');
ck193(str_contains($smoke, "F5: state восстанавливается через REST"), 'post-reload state restoration is asserted');
ck193(str_contains($smoke, "Повтор voice-finished идемпотентен"), 'duplicate voice-finished is asserted');
ck193(str_contains($smoke, "Повтор submit с тем же request_id не дублирует ход"), 'duplicate submit is asserted');
ck193(str_contains($smoke, "Устаревший expected_version даёт state_conflict"), 'stale version conflict is asserted');
ck193(str_contains($smoke, "Повтор next не перескакивает кейс"), 'duplicate next is asserted');
ck193(str_contains($smoke, "После finish новые ответы блокируются"), 'post-finish mutation guard is asserted');
ck193(str_contains($smoke, "duration_seconds:120"), 'smoke uses long answer windows to survive browser reload');
ck193(str_contains($smoke, "wp_ajax_ckm_negotiation_core_smoke_cleanup") && str_contains($smoke, "delete_test_session"), 'test persistence is cleaned after smoke');
ck193(str_contains($smoke, "strpos(\$sessionId, 'TEST-API-')"), 'cleanup refuses non-test session ids');
ck193(str_contains($main, "includes/negotiation-core-smoke.php"), 'plugin loads smoke page module');
ck193(ckm_test_current_plugin_release($main), 'plugin version bumped to 195/dev.227');
ck193(str_contains($catalog, "'product'=>'negotiation_duel_v1'") && str_contains($bridge, "'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"), 'real-game routing remains on legacy negotiation_duel_v1');
ck193(!str_contains($smoke, "tenant_id") && !str_contains($smoke, "game_id"), 'smoke page cannot bind test sessions to real tenant/game ids');

echo 'TOTAL ' . count($checks) . '/' . count($checks) . ' PASS' . PHP_EOL;

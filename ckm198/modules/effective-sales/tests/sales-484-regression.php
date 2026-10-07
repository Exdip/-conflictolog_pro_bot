<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s484(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s484('current plugin release header and constant', ckm_test_current_plugin_release($main));
$js = file_get_contents($root.'/effective-sales/assets/sales-session.js');
s484('active sales attempt auto redirects', str_contains($js, "data.code==='ACTIVE_SESSION_EXISTS'") && str_contains($js, "Открываем незавершённую тренировку"));
s484('sales runtime has writer conflict recovery', str_contains($js, "data.code==='WRITER_CONFLICT'") && str_contains($js, "writer/takeover"));
s484('sales runtime tracks active session for takeover', str_contains($js, 'runtimeSessionId=sessionId'));
$controller = file_get_contents($root.'/effective-sales/api/sales-controller.php');
s484('sales controller registers writer takeover', str_contains($controller, "/writer/takeover") && str_contains($controller, 'takeoverWriter'));
s484('sales controller takeover is sales-domain guarded', str_contains($controller, 'SalesDomain::versionForSession($id)'));
echo "{$passed} SALES-484 regression checks passed. No database required.\n";

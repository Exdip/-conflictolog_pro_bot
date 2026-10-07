<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$smoke = file_get_contents($root . '/includes/negotiation-core-smoke.php');
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$catalog = file_get_contents($root . '/includes/games-catalog.php');
$bridge = file_get_contents($root . '/includes/games-hub-runtime-bridge.php');
$checks=[];
function ck195($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }
ck195(ckm_test_current_plugin_release($main),'plugin version bumped to 195/dev.227');
ck195(str_contains($smoke,"'storageKey' => 'ckmNegotiationCoreSmokeV2-' . CKM_QUIZ_PRO_VERSION"),'smoke storage is isolated by plugin build version');
ck195(str_contains($smoke,"run.stage = 'failed';"),'failed run gets explicit failed stage');
ck195(str_contains($smoke,"if (run.stage === 'failed')"),'failed run is not silently re-executed on reload');
ck195(str_contains($smoke,'Нажмите «Запустить заново»'),'failed run asks for explicit restart');
ck195(str_contains($smoke,'clearRun();') && str_contains($smoke,'window.location.reload();'),'explicit restart clears current run before reload');
ck195(str_contains($smoke,"run.stage = 'after-f5';") && str_contains($smoke,'window.location.reload()'),'intended F5 checkpoint still persists across reload');
ck195(str_contains($catalog,"'product'=>'negotiation_duel_v1'") && str_contains($bridge,"'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"),'real games remain on legacy runtime');
echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;

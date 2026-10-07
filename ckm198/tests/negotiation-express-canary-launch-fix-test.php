<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$canary=file_get_contents($root.'/includes/negotiation-express-canary.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$hub=file_get_contents($root.'/includes/games-hub-integration.php');
$failed=0;$passed=0;
function ck197($ok,$label){global $failed,$passed;if($ok){$passed++;echo "PASS: $label\n";}else{$failed++;echo "FAIL: $label\n";}}
ck197(ckm_test_current_plugin_release($main),'plugin version bumped');
ck197(str_contains($canary,'ckm_quiz_pro_express_canary_launch_requested') && str_contains($canary,"current_user_can('manage_options')"),'canary launch marker is admin-only');
ck197(str_contains($canary,"if ((string)(\$quiz['slug'] ?? '') !== 'demo-negotiation-express') return false;") && str_contains($canary,'if ($teamCount !== 1) return false;') && str_contains($canary,"return sanitize_key(\$hostMode) === 'ai';"),'canary launch restricted to built-in express demo, one team, AI host');
ck197(str_contains($org,"'test_mode'=>(\$previewDemoLaunch || \$expressCanaryLaunch) ? 1 : 0"),'explicit canary launch creates test_mode room');
ck197(str_contains($org,'name="ckm_express_canary" value="1"') && str_contains($org,'CANARY:'),'canary marker survives form POST and is visible to admin');
ck197(str_contains($hub,"'express_round_v1' => array(\n            'round_time_seconds'=>60"),'express round default timer is 60 seconds');
ck197(str_contains($org,'Экспресс-раунд: 1 команда, ИИ-ведущий; на каждую переговорную реплику — 60 секунд после окончания озвучивания.'),'express UI note names the current game and its voice-first 60-second timer');
ck197(str_contains($canary,"if (empty(\$game['test_mode'])) return false"),'shadow canary still refuses normal real games');
echo "RESULT: $passed passed, $failed failed\n";
exit($failed?1:0);

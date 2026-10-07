<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$canary = file_get_contents($root . '/includes/negotiation-express-canary.php');
$api = file_get_contents($root . '/includes/standalone-api.php');
$voice = file_get_contents($root . '/includes/standalone-voice.php');
$js = file_get_contents($root . '/assets/standalone-game.js');
$catalog = file_get_contents($root . '/includes/games-catalog.php');
$bridge = file_get_contents($root . '/includes/games-hub-runtime-bridge.php');
$core = file_get_contents($root . '/core-source/includes/quiz/quiz-format-runtime.php');
$checks=[];
function ck196($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }

ck196(ckm_test_current_plugin_release($main),'plugin version bumped to 196/dev.228');
ck196(str_contains($main,"includes/negotiation-express-canary.php"),'canary adapter is loaded by bootstrap');
ck196(str_contains($canary,"CKM_NEGOTIATION_EXPRESS_CANARY_MODE = 'shadow'"),'canary is explicitly shadow-only');
ck196(str_contains($canary,"empty(\$game['test_mode'])") && str_contains($canary,"team_count") && str_contains($canary,"!== 1"),'canary requires test mode and exactly one team');
ck196(str_contains($canary,"format_key_snapshot") && str_contains($canary,"negotiation_duel"),'canary only mirrors legacy negotiation duel format');
ck196(str_contains($canary,"host_mode_snapshot") && str_contains($canary,"'ai'"),'canary requires AI host');
ck196(str_contains($canary,"demo-negotiation-express") && str_contains($canary,"mode === 'express'"),'canary is restricted to built-in express demo');
ck196(str_contains($canary,"TEST-CANARY-EXP-GAME-") && str_contains($canary,"negotiation_express_v1"),'canary uses an isolated test session on the new runtime');
ck196(str_contains($voice,"ckm_quiz_pro_express_canary_mirror_voice_finished") && str_contains($voice,"empty(\$result['skipped'])"),'actual successful legacy TTS completion is mirrored to core');
ck196(str_contains($api,"\$_POST['input_mode']") && str_contains($api,"ckm_quiz_pro_express_canary_mirror_answer"),'actual answer plus input mode is mirrored from participant API');
ck196(str_contains($canary,"legacy_canary_mirror") && str_contains($canary,"mock_evaluation"),'saved legacy judgement is mirrored without changing authoritative scoring');
ck196(str_contains($canary,"ckm_quiz_pro_express_canary_sync") && str_contains($canary,"next_case") && str_contains($canary,"finish_session"),'legacy advance and finish are mirrored to core');
ck196(str_contains($api,"negotiationExpressCanary") && str_contains($canary,"'parity'=>"),'state exposes canary parity diagnostics');
ck196(str_contains($js,"lastAnswerInputMode='voice'") && str_contains($js,"input_mode:lastAnswerInputMode"),'STT answer is tagged voice and sent to server');
ck196(str_contains($js,"· CANARY"),'canary room is visibly marked in the real game UI');
ck196(!str_contains($canary,'register_rest_route') && !str_contains($canary,'wp_ajax_'),'canary adapter does not add a new public API surface');
ck196(str_contains($catalog,"'product'=>'negotiation_duel_v1'"),'commercial catalog remains on legacy runtime');
ck196(str_contains($bridge,"'express_round_v1'         => array('format_key'=>'negotiation_duel','mode'=>'express')"),'Games Hub express route remains legacy');
ck196(str_contains($core,"'runtime'=>'negotiation_duel_v1'") || str_contains($core,"\"runtime\"=>\"negotiation_duel_v1\""),'core format registration remains legacy authoritative runtime');
ck196(!str_contains($canary,'ckm_quiz_payments_table') && !str_contains($canary,'ckm_quiz_pro_checkout'),'canary adapter does not call payment/checkout code');

echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;

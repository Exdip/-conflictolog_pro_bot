<?php
if (PHP_SAPI!=='cli') exit;
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
if (!function_exists('sanitize_key')) {
    function sanitize_key($key){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$key)); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag,$value){ return $value; }
}
$GLOBALS['wpdb']=(object)['prefix'=>'wp_'];

$root=dirname(__DIR__);
require_once $root.'/includes/negotiation-core.php';

$main=file_get_contents($root.'/ckm-quiz-pro.php');
$schema=file_get_contents($root.'/includes/standalone-schema.php');
$adapter=file_get_contents($root.'/includes/standalone-adapter.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
$formatRuntime=file_get_contents($root.'/core-source/includes/quiz/quiz-format-runtime.php');
$core=file_get_contents($root.'/includes/negotiation-core.php');

$checks=[];
function ck189($ok,$label){ global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL').': '.$label.PHP_EOL; if(!$ok) exit(1); }

ck189(defined('CKM_NEGOTIATION_CORE_VERSION') && CKM_NEGOTIATION_CORE_VERSION==='negotiation_core_v1','core version constant');
ck189(interface_exists('CKM_Negotiation_Runtime_Interface'),'runtime interface exists');
ck189(ckm_negotiation_core_runtime_contract() === [
    'create_session','get_state','get_available_actions','submit_player_message','handle_ai_turn','advance_phase','evaluate_turn','check_completion','finish_session','restore_session'
],'runtime contract is stable');
ck189(ckm_negotiation_core_routing_enabled()===false,'new-core routing disabled by default');

$state=ckm_negotiation_core_state_template('negotiation_express_v1','TEST-1');
ck189(($state['runtime']??'')==='negotiation_express_v1' && ($state['state_version']??0)===1,'state template identifies runtime/version');
ck189(($state['phase']??'')==='idle' && ($state['status']??'')==='created','state template starts inert');
ck189(isset($state['voice']['generation'],$state['timer']['starts_after_voice'],$state['available_actions'],$state['runtime_state']),'state template carries shared voice/timer/action/runtime slots');
ck189(ckm_negotiation_core_table('sessions')==='wp_ckm_negotiation_sessions','core table resolver uses isolated namespace');

foreach(['sessions','turns','events','requests'] as $table){
    ck189(str_contains($schema,"CREATE TABLE {\$ns}{$table}"),"schema defines {$table}");
}
ck189(str_contains($schema,"const CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16';"),'schema version bumped');
ck189(str_contains($schema,'UNIQUE KEY session_event_seq (session_id,event_seq)'),'event sequence uniqueness');
ck189(str_contains($schema,'UNIQUE KEY session_request (session_id,client_request_id)'),'request idempotency uniqueness');
ck189(str_contains($adapter,"'negotiation_sessions'=>'ckm_negotiation_sessions'") && str_contains($adapter,"'negotiation_requests'=>'ckm_negotiation_requests'"),'standalone table aliases registered');
ck189(str_contains($main,"includes/negotiation-core.php"),'core file loaded by plugin');

// Regression guard: no existing game is switched to the new core in this build.
ck189(str_contains($catalog,"'product'=>'negotiation_duel_v1'"),'catalog still sells legacy negotiation product');
ck189(str_contains($bridge,"'negotiation_duel_v1'      => array('format_key'=>'negotiation_duel','mode'=>'sales')"),'games hub legacy route unchanged');
ck189(str_contains($formatRuntime,"'runtime'=>'negotiation_duel_v1'"),'format runtime legacy key unchanged');
ck189(!str_contains($core,'register_rest_route(') && !str_contains($core,"add_action('"),'core skeleton has no routing hooks');

echo 'TOTAL '.count($checks).'/'.count($checks).' PASS'.PHP_EOL;

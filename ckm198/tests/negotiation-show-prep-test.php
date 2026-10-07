<?php
// Standalone CLI checks, no WordPress database or network writes.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__.'/');
function add_action(...$args) {}
function add_filter(...$args) {}
function sanitize_key($s) {return preg_replace('/[^a-z0-9_\-]/','',strtolower($s));}
function ckm_quiz_json_decode($s) {return json_decode($s,true) ?: [];}
function ckm_quiz_get_game($id, $lock=false) {return $GLOBALS['fixture'];}
require dirname(__DIR__).'/includes/standalone-negotiation-duel.php';
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/games-hub-integration.php';
require dirname(__DIR__).'/includes/games-catalog.php';
require dirname(__DIR__).'/core-source/includes/quiz/quiz-engine.php';
function check($condition,$label) {if (!$condition) {fwrite(STDERR,"FAIL $label\n");exit(1);}echo "PASS $label\n";}
$fixture=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'ai','format_key_snapshot'=>'negotiation_duel','format_settings_snapshot_json'=>'{"negotiationMode":"communicate"}'];
$teams=[];foreach([1,2,3] as $slot) $teams[]=['id'=>100+$slot,'team_key'=>chr(64+$slot),'team_name'=>'Команда '.chr(64+$slot),'slot_no'=>$slot,'team_token'=>'DO_NOT_EXPOSE'];
$states=[];
foreach($teams as $team) $states[]=ckmqp_show_project($fixture,['game_id'=>7,'role'=>'participant','team_id'=>$team['id']],$teams);
check($states[0]['negotiationShow']['roleTitle']==='Переговорщик','team A role');
check($states[1]['negotiationShow']['roleTitle']==='Оппонент','team B role');
check($states[2]['negotiationShow']['roleTitle']==='Наблюдатель','team C role');
$secret=$states[1]['negotiationShow']['brief'];
foreach([0,2] as $i) check(!str_contains(json_encode($states[$i],JSON_UNESCAPED_UNICODE),$secret),'opponent brief hidden from team '.($i+1));
foreach(['scoreboard','host','unknown'] as $role){
 $s=ckmqp_show_project($fixture,['game_id'=>7,'role'=>$role,'team_id'=>102],$teams);
 check(!isset($s['negotiationShow']['brief']),$role.' receives no private brief');
}
foreach([['game_id'=>8,'role'=>'participant','team_id'=>102],['game_id'=>7,'role'=>'participant','team_id'=>999]] as $auth) check(!isset(ckmqp_show_project($fixture,$auth,$teams)['negotiationShow']['brief']),'wrong game or foreign team receives no brief');
check(!str_contains(json_encode($states),'DO_NOT_EXPOSE'),'team credentials never serialized');
check(ckm_quiz_refresh_deadline($fixture)===$fixture,'polling does not start sequential engine');
check(ckm_quiz_ai_host_classic_autopilot(7)['reason']==='preparation_only','autopilot skips preparation');
check(ckm_quiz_open_next_question(7,'host')['code']==='show_preparation_only','manual sequential start blocked');
check(ckmqp_hub_runtime_to_core_format('persuade_me_v1')==='negotiation_duel','same entitlement family');
check(ckmqp_hub_runtime_negotiation_mode('persuade_me_v1')==='communicate','runtime mode');
$q=[['id'=>1,'format_key'=>'negotiation_duel','format_settings_json'=>'{"negotiationMode":"sales"}']];
check(ckmqp_hub_find_quiz_for_runtime($q,'persuade_me_v1')===0,'missing show never selects sales');
$q[]=['id'=>2,'format_key'=>'negotiation_duel','format_settings_json'=>'{"negotiationMode":"communicate"}'];
check(ckmqp_hub_find_quiz_for_runtime($q,'persuade_me_v1')===2,'correct show template selected');
foreach(['sales','business','express'] as $mode){check(ckm_quiz_pro_negotiation_mode($mode)===$mode,'old mode preserved: '.$mode);$g=$fixture;$g['format_settings_snapshot_json']=json_encode(['negotiationMode'=>$mode]);check(!ckmqp_show_is_game($g),'old game not intercepted: '.$mode);}
check(ckm_quiz_pro_games_catalog_registry()['business']['items']['persuade_me_v1']['product']==='negotiation_duel_v1','existing payment product');
echo "ALL PASS\n";

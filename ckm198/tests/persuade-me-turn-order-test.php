<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$args){}
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;
function t269($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo 'PASS '.(++$n).' '.$name."\n";}
function a269($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>500+$slot];}
function x269($s,$slot,$cmd,$at,$extra=[]){return ckmqp_show_reduce($s,a269($slot),array_merge(['command'=>$cmd,'attempt'=>$s['attempt']],$extra),$at);}
$raw=ckm_quiz_pro_persuade_me_school_grade_content();
$norm=ckm_quiz_pro_persuade_me_normalize_content($raw);
t269(($norm['round1']['cases'][0]['starterRole']??'')==='opponent','round 1 defaults to opponent starter');
t269(($norm['round2']['starterRole']??'')==='speaker','round 2 defaults to active speaker starter');
$game=['id'=>77,'team_count'=>2,'format_settings_snapshot_json'=>json_encode(['negotiationMode'=>'communicate','persuadeMeContent'=>$norm],JSON_UNESCAPED_UNICODE)];
$s=ckmqp_show_initial($game);
$s=x269($s,1,'ready',10)['session'];
$s=x269($s,2,'ready',11)['session'];
t269($s['phase']==='dialogue'&&ckmqp_show_dialogue_turn_slot($s)===2,'school-grade first turn belongs to B opponent');
$r=x269($s,1,'message',12,['text'=>'Лера пытается начать раньше','request_id'=>'turn_order_early_A_01']);
t269(!$r['ok']&&$r['code']==='not_your_turn','A cannot speak before B');
$r=x269($s,2,'message',13,['text'=>'Лера, я контрольные не первый год проверяю.','request_id'=>'turn_order_B_first_01']);
t269(!empty($r['ok']),'B first message accepted');$s=$r['session'];
t269(ckmqp_show_dialogue_turn_slot($s)===1,'turn passes to A after B');
$r=x269($s,2,'message',14,['text'=>'Вторая реплика подряд','request_id'=>'turn_order_B_twice_01']);
t269(!$r['ok']&&$r['code']==='not_your_turn','same team cannot send twice in a row');
$r=x269($s,1,'message',15,['text'=>'Давайте сверим работу с ключом варианта Б.','request_id'=>'turn_order_A_reply_01']);
t269(!empty($r['ok']),'A reply accepted');$s=$r['session'];
t269(ckmqp_show_dialogue_turn_slot($s)===2,'turn returns to B');
$teams=[
 ['id'=>501,'team_key'=>'A','team_name'=>'Команда A','slot_no'=>1],
 ['id'=>502,'team_key'=>'B','team_name'=>'Команда B','slot_no'=>2]
];
$gg=['id'=>77,'game_code'=>'T','title'=>'T','status'=>'waiting','host_mode_snapshot'=>'ai','team_count'=>2,'format_settings_snapshot_json'=>$game['format_settings_snapshot_json']];
$pa=ckmqp_show_dialogue_project($gg,['game_id'=>77,'role'=>'participant','team_id'=>501],$teams,$s);
$pb=ckmqp_show_dialogue_project($gg,['game_id'=>77,'role'=>'participant','team_id'=>502],$teams,$s);
t269(empty($pa['negotiationShow']['canMessage'])&&!empty($pb['negotiationShow']['canMessage']),'only current team gets input after projection');
t269(($pa['negotiationShow']['turnTeamName']??'')==='Команда B'&&($pa['negotiationShow']['turnRoleTitle']??'')==='Оппонент','projection names current speaker and role');
$rdup=x269($s,1,'message',16,['text'=>'Давайте сверим работу с ключом варианта Б.','request_id'=>'turn_order_A_reply_01']);
t269(!empty($rdup['ok'])&&!empty($rdup['duplicate']),'network retry remains idempotent even after turn changes');
$s2=ckmqp_show_initial($game);
$s2['round']=1;$s2['attempt']=0;$s2['phase']='dialogue';
t269(ckmqp_show_dialogue_turn_slot($s2)===1,'hidden-task round starts with active team');
$r=x269($s2,1,'message',20,['text'=>'Предлагаю начать с конкретного дня.','request_id'=>'turn_order_hidden_A1']);
t269(!empty($r['ok']),'hidden active starter accepted');$s2=$r['session'];
t269(ckmqp_show_dialogue_turn_slot($s2)===2,'hidden dialogue alternates to partner');
$legacy=ckmqp_show_initial();$legacy['phase']='dialogue';
t269(ckmqp_show_dialogue_turn_slot($legacy)===0,'legacy three-team room keeps free-dialogue compatibility');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t269(str_contains($js,'Первую реплику делает')&&str_contains($js,'Ожидайте реплику'),'UI explicitly names first/current speaker');
t269(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL $n PASS\n";

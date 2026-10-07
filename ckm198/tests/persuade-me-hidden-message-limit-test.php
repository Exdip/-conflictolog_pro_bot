<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
$n=0;function ck164($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$admin=file_get_contents(dirname(__DIR__).'/includes/standalone-admin.php');
$organizer=file_get_contents(dirname(__DIR__).'/includes/standalone-organizer.php');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
ck164(str_contains($admin,'persuadeHiddenMessageLimit')&&str_contains($admin,'persuade_hidden_message_limit'),'admin saves hidden-task message limit');
ck164(str_contains($organizer,'Максимум реплик')&&str_contains($organizer,'persuade_hidden_message_limit'),'organizer builder exposes message limit');
ck164(str_contains($js,'teamMessageCount')&&str_contains($js,'hiddenMessageLimit'),'game UI exposes total and team quotas');
function a164($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function m164($s,$slot,$text,$id,$now){return ckmqp_show_reduce($s,a164($slot),['command'=>'message','round'=>1,'attempt'=>0,'text'=>$text,'request_id'=>$id],$now);}

ck164(ckmqp_show_hidden_message_limit_from_game(['format_settings_snapshot_json'=>'{}'])===7,'default hidden-task message limit is 7');
ck164(ckmqp_show_hidden_message_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeHiddenMessageLimit":5}'])===5,'saved hidden-task message limit is read');
ck164(ckmqp_show_hidden_message_limit_from_game(['format_settings_snapshot_json'=>'{"persuadeHiddenMessageLimit":99}'])===7,'hidden-task message limit is clamped to 7');

$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=0;$s['phase']='dialogue';$s['dialogueStartedAt']=100;$s['lastDialogueActivityAt']=100;
ck164($s['hiddenMessageLimit']===7,'new session starts with seven-message cap');
foreach([
 [1,'A1','hidden_limit_a_0001'],[1,'A2','hidden_limit_a_0002'],[1,'A3','hidden_limit_a_0003']
] as $x){$r=m164($s,$x[0],$x[1],$x[2],101);ck164($r['ok'],'active team message accepted');$s=$r['session'];}
$r=m164($s,1,'A4','hidden_limit_a_0004',102);ck164(!$r['ok']&&$r['code']==='team_message_limit','active team cannot exceed three messages');

$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'human'];
$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams,$s);
ck164($o['negotiationShow']['messageCount']===3&&$o['negotiationShow']['hiddenMessageLimit']===7,'projection exposes total message counter');
ck164($o['negotiationShow']['teamMessageCount']===3&&$o['negotiationShow']['teamMessageMax']===3&&!$o['negotiationShow']['canMessage'],'active input closes after personal quota');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams,$s);
ck164($o['negotiationShow']['teamMessageMax']===2&&$o['negotiationShow']['canMessage'],'partner keeps two-message quota');

foreach([[2,'B1','hidden_limit_b_0001'],[2,'B2','hidden_limit_b_0002'],[3,'C1','hidden_limit_c_0001']] as $x){$r=m164($s,$x[0],$x[1],$x[2],103);ck164($r['ok']&&$r['session']['phase']==='dialogue','dialogue remains open before total cap');$s=$r['session'];}
$r=m164($s,3,'C2','hidden_limit_c_0002',104);ck164($r['ok'],'seventh message accepted');$s=$r['session'];
ck164($s['phase']==='review'&&$s['dialogueClosedBy']==='message_limit','seventh message automatically starts review');
ck164(count(ckmqp_show_current_dialogue_messages($s))===7,'exactly seven messages stored');

$short=ckmqp_show_initial();$short['round']=1;$short['phase']='dialogue';$short['hiddenMessageLimit']=5;$short['dialogueStartedAt']=200;$short['lastDialogueActivityAt']=200;
foreach([[1,'A1','short_limit_a_0001'],[2,'B1','short_limit_b_0001'],[3,'C1','short_limit_c_0001'],[1,'A2','short_limit_a_0002']] as $x)$short=m164($short,$x[0],$x[1],$x[2],201)['session'];
$r=m164($short,2,'B2','short_limit_b_0002',202);ck164($r['ok']&&$r['session']['phase']==='review','configured lower total cap auto-closes at five');

echo "ALL $n PASS\n";

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
$n=0;function check($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function actor2($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function act2($s,$slot,$cmd,$now,$extra=[]){return ckmqp_show_reduce($s,actor2($slot),array_merge(['command'=>$cmd,'round'=>$s['round'],'attempt'=>$s['attempt']],$extra),$now);}
function pHidden($points,$id,$quote){$criteria=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$criteria[$k]=['points'=>$points,'reason'=>'Наблюдаемое поведение по '.$k,'evidence'=>[['messageId'=>$id,'quote'=>$quote]]];return ['criteria'=>$criteria,'summary'=>'Диалог оценён по единой рубрике.','recommendation'=>'Сохраняйте конкретику.'];}
function rrHidden($sp,$op,$spSlot,$opSlot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$sp,'slot'=>$spSlot],'opponent'=>['total'=>$op,'slot'=>$opSlot]],'total'=>$sp,'rubricVersion'=>3]];}

// Round 2 is an untimed free dialogue. One host action starts it and teams do not reconfirm readiness.
$s=ckmqp_show_initial();$s['attempt']=2;$s['phase']='round_complete';
$s['reviews']=[rrHidden(70,70,1,2),rrHidden(70,70,2,3),rrHidden(70,70,3,1)];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>0,'attempt'=>2],100);$s=$r['session'];check($r['ok']&&$s['round']===1&&$s['attempt']===0&&$s['phase']==='dialogue'&&$s['deadline']===0,'one host action opens hidden-task dialogue without prep timer');
$r=act2($s,1,'ready',101);check(!$r['ok']&&($r['code']??'')==='ready_not_needed','repeated readiness is removed');
check($s['deadline']===0,'hidden round has no mandatory timer');
foreach([1,2,3] as $slot){$r=act2($s,$slot,'message',150+$slot,['text'=>'Реплика команды '.$slot,'request_id'=>'hidden_message_000'.$slot]);check($r['ok'],'all teams can speak '.$slot);$s=$r['session'];}
check(count(ckmqp_show_hidden_review_messages($s,0))===3,'hidden transcript scoped to round 2');
$s=ckmqp_show_tick($s,10000);check($s['phase']==='dialogue','hidden dialogue does not expire');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'finish_dialogue','attempt'=>0],10001);check($r['ok']&&$r['session']['phase']==='review','host closes hidden free dialogue');$s=$r['session'];

// The hidden-task content is context only; scoring uses the same seven-criterion rubric as round 1.
$s['messages'][]=['id'=>4,'teamId'=>101,'slot'=>1,'round'=>1,'attempt'=>0,'text'=>'Давайте встретимся в среду и зафиксируем следующий шаг.','at'=>200,'requestId'=>'hidden_message_0004'];
$s['messages'][]=['id'=>5,'teamId'=>102,'slot'=>2,'round'=>1,'attempt'=>0,'text'=>'Подходит, давайте зафиксируем срок до пятницы.','at'=>201,'requestId'=>'hidden_message_0005'];
$messages=ckmqp_show_hidden_review_messages($s,0);
$valid=['participants'=>['speaker'=>pHidden(10,4,'Давайте встретимся в среду'),'opponent'=>pHidden(10,5,'зафиксируем срок до пятницы')],'summary'=>'Обе стороны продемонстрировали переговорное поведение.'];
$error=null;$v=ckmqp_show_validate_hidden_review($valid,$messages,0,$s,$error);check(is_array($v)&&($v['speakerTotal']??0)===70&&($v['opponentTotal']??0)===70&&($v['rubricVersion']??0)===3,'hidden round uses the same seven-criterion rubric with a 70-point ceiling');
$bad=$valid;$bad['participants']['speaker']['criteria']['request_specificity']['points']=11;check(ckmqp_show_validate_hidden_review($bad,$messages,0,$s,$error)===null,'hidden criterion score above 10 is rejected');
$legacy=['tasks'=>[['points'=>100,'reason'=>'Старая задача','evidence'=>'Давайте встретимся в среду']]];check(ckmqp_show_validate_hidden_review($legacy,$messages,0,$s,$error)===null,'legacy three-task scoring is rejected');

// Store review and advance immediately to the next untimed dialogue.
$sys=['role'=>'system'];$claim=ckmqp_show_hidden_review_reduce($s,$sys,['command'=>'hidden_review_claim','attempt'=>0,'lease'=>'hidden-lease-000001'],231);check($claim['ok']&&$claim['claimed'],'hidden review lease claimed');
$finish=ckmqp_show_hidden_review_reduce($claim['session'],$sys,['command'=>'hidden_review_finish','attempt'=>0,'lease'=>'hidden-lease-000001','review'=>$valid],232);$s=$finish['session'];check(($s['hiddenReviews'][0]['review']['total']??0)===70,'hidden review stores the seven-criterion result');
$legacyConfirm=act2($s,1,'continue',233);check(!$legacyConfirm['ok']&&($legacyConfirm['code']??'')==='confirmation_removed','team transition confirmation is removed');
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>1,'attempt'=>0],234);$s=$r['session'];check($r['ok']&&$s['attempt']===1&&$s['phase']==='dialogue'&&$s['deadline']===0,'host advances to second active team without readiness or prep timer');

// Private cards: only active team sees tasks; host and others do not.
$g=['id'=>7,'game_code'=>'DEMO','title'=>'Переговори другого','status'=>'waiting','host_mode_snapshot'=>'ai'];$teams=[];foreach([1,2,3] as $i)$teams[]=['id'=>100+$i,'team_key'=>chr(64+$i),'team_name'=>'Команда '.chr(64+$i),'slot_no'=>$i];
$state=ckmqp_show_initial();$state['round']=1;$state['attempt']=0;
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams,$state);check(str_contains($o['negotiationShow']['brief'],'три скрытые задачи'),'active team receives hidden tasks');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>102],$teams,$state);check(!str_contains($o['negotiationShow']['brief'],'день недели'),'other team does not receive hidden task');
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'host'],$teams,$state);check(!isset($o['negotiationShow']['brief']),'host does not receive hidden task before reveal');
$state['phase']='review';$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'scoreboard'],$teams,$state);check(count($o['negotiationShow']['revealedTasks'])===3,'tasks reveal only after dialogue');

// Cumulative score after two rounds now uses 70-point ceilings.
$state['reviews']=$s['reviews'];$state['reviews'][0]=rrHidden(70,70,1,2);$state['reviews'][1]=rrHidden(70,70,2,3);$state['reviews'][2]=rrHidden(70,70,3,1);$state['hiddenReviews']=[rrHidden(70,70,1,2),rrHidden(70,70,2,3),rrHidden(70,70,3,1)];
$o=ckmqp_show_dialogue_project($g,['game_id'=>7,'role'=>'participant','team_id'=>101],$teams,$state);check($o['negotiationShow']['scores'][0]['score']===140,'cumulative score adds round 1 and round 2 at 70 each');

$legacy=['version'=>1,'revision'=>2,'attempt'=>0,'phase'=>'waiting','deadline'=>0,'ready'=>[],'next'=>[],'paused'=>false,'remaining'=>0,'messages'=>[['id'=>1,'teamId'=>101,'slot'=>1,'attempt'=>0,'text'=>'old','at'=>1,'requestId'=>'legacy_request_001']],'reviews'=>[]];
$legacy=ckmqp_show_normalize_state($legacy);check($legacy['round']===0&&$legacy['messages'][0]['round']===0&&isset($legacy['hiddenReviews']),'legacy state migrates safely');
echo "ALL $n PASS\n";

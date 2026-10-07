<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
$n=0;function check3($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function actor3($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>100+$slot];}
function act3($s,$slot,$cmd,$now,$extra=[]){return ckmqp_show_reduce($s,actor3($slot),array_merge(['command'=>$cmd,'round'=>$s['round'],'attempt'=>$s['attempt'],'question_index'=>$s['questionIndex']??0],$extra),$now);}
function rr3($sp,$op,$spSlot,$opSlot){return ['status'=>'done','review'=>['participants'=>['speaker'=>['total'=>$sp,'slot'=>$spSlot],'opponent'=>['total'=>$op,'slot'=>$opSlot]],'total'=>$sp,'rubricVersion'=>3]];}

// Start round 3 after round 2. The current contract is untimed: the deadline remains 0.
$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=2;$s['phase']='round2_complete';
$s['reviews']=[rr3(70,70,1,2),rr3(70,70,2,3),rr3(70,70,3,1)];
$s['hiddenReviews']=[rr3(70,70,1,2),rr3(70,70,2,3),rr3(70,70,3,1)];
$r=ckmqp_show_reduce($s,['role'=>'host'],['command'=>'advance','round'=>1,'attempt'=>2],100);$s=$r['session'];
check3($r['ok']&&$s['round']===2&&$s['attempt']===0&&$s['phase']==='hard_answer','one host action starts round 3');
check3($s['questionIndex']===0&&$s['deadline']===0,'round 3 starts without a mandatory timer');
$r=act3($s,2,'hard_answer',101,['text'=>'Чужой ответ','request_id'=>'hard_answer_other_001']);check3(!$r['ok']&&($r['code']??'')==='active_team_required','only active team may answer');

// Three direct answers advance immediately and never create a deadline.
$answers=[
 'Срок был сорван, поэтому сейчас я фиксирую промежуточный контроль и заранее сообщаю о риске.',
 'Я уточню, чем именно недоволен руководитель, и договорюсь о конкретном изменении в работе.',
 'Моя слабая сторона — беру слишком много задач. Поэтому заранее ограничиваю параллельную загрузку.'
];
foreach($answers as $i=>$text){
    $r=act3($s,1,'hard_answer',110+$i,['text'=>$text,'request_id'=>'hard_answer_team1_0'.($i+1)]);$s=$r['session'];
    if($i<2) check3($r['ok']&&$s['questionIndex']===$i+1&&$s['deadline']===0,'answer '.($i+1).' opens the next question without a timer');
    else check3($r['ok']&&$s['phase']==='review'&&$s['deadline']===0,'third answer closes the turn without a timer');
}

// The same seven-criterion rubric is used for the answering team; maximum is 70.
$messages=ckmqp_show_hard_rubric_messages($s,0);
$quote=(string)$messages[1]['text'];$criteria=[];
foreach(array_keys(ckmqp_show_review_criteria()) as $key)$criteria[$key]=['points'=>10,'reason'=>'Наблюдаемое поведение по критерию '.$key,'evidence'=>[['messageId'=>(int)$messages[1]['id'],'quote'=>$quote]]];
$review=['criteria'=>$criteria,'summary'=>'Три прямых ответа.','recommendation'=>'Сохранять конкретику.'];
$v=ckmqp_show_validate_hard_review($review,$s,0,$err);
check3(is_array($v)&&($v['total']??0)===70&&($v['participants']['speaker']['total']??0)===70,'hard round uses the seven-criterion speaker rubric with a 70-point ceiling');
check3(($v['displayRoles']??[])===['speaker']&&($v['suppressWinner']??false)===true&&($v['roundRubric']??'')==='seven_criteria','hard result remains speaker-only');

// Invalid legacy 0/50/100 answer scoring is no longer accepted by the hard-round validator.
$legacy=['answers'=>[
 ['points'=>100,'reason'=>'Старый формат','evidence'=>$quote],
 ['points'=>100,'reason'=>'Старый формат','evidence'=>$quote],
 ['points'=>100,'reason'=>'Старый формат','evidence'=>$quote],
 ]];
check3(ckmqp_show_validate_hard_review($legacy,$s,0,$err2)===null,'legacy three-answer scoring is rejected');

// Provider aliases still normalize into the seven-criterion contract.
$variant=['result'=>['participants'=>['speaker'=>$review],'summary'=>'Три конкретных ответа.','recommendation'=>'Сохраняйте конкретику.']];
$norm=ckmqp_show_normalize_hard_ai_review($variant,$s,0);$nv=ckmqp_show_validate_hard_review($norm,$s,0,$normError);
check3(is_array($nv)&&($nv['total']??0)===70,'hard AI representation aliases normalize without changing the seven-criterion score');

$s['hardReviews'][0]=['status'=>'done','review'=>$v];
check3(ckmqp_show_hard_round_score($s,1)===70,'stored hard review contributes 70 points');

$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
check3(ckm_test_current_plugin_release($main),'current plugin version marker');
echo "ALL $n PASS\n";

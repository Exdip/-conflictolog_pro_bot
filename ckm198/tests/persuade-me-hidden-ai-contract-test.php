<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
$n=0;function ck168($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
function h168($points,$id,$quote){$criteria=[];foreach(array_keys(ckmqp_show_review_criteria()) as $key)$criteria[$key]=['points'=>$points,'reason'=>'Наблюдаемое поведение '.$key,'evidence'=>[['messageId'=>$id,'quote'=>$quote]]];return ['criteria'=>$criteria,'summary'=>'Итог','recommendation'=>'Совет'];}

$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=1;$s['phase']='review';
$s['messages']=[
 ['id'=>31,'teamId'=>102,'slot'=>2,'round'=>1,'attempt'=>1,'text'=>'Давайте согласуем детали встречи и подготовим повестку.','at'=>100,'requestId'=>'x31'],
 ['id'=>32,'teamId'=>101,'slot'=>1,'round'=>1,'attempt'=>1,'text'=>'Мне удобно начать в 14:30. Материалы лучше прислать заранее.','at'=>101,'requestId'=>'x32'],
 ['id'=>33,'teamId'=>102,'slot'=>2,'round'=>1,'attempt'=>1,'text'=>'Фиксируем начало в 14:30 и заранее рассылаем материалы.','at'=>102,'requestId'=>'x33'],
 ['id'=>34,'teamId'=>103,'slot'=>3,'round'=>1,'attempt'=>1,'text'=>'Подходит, давайте зафиксируем срок до пятницы.','at'=>103,'requestId'=>'x34'],
];
$messages=ckmqp_show_hidden_review_messages($s,1);
$valid=['participants'=>['speaker'=>h168(10,33,'Фиксируем начало в 14:30'),'opponent'=>h168(10,34,'Подходит, давайте зафиксируем срок до пятницы')],'summary'=>'Диалог оценён по семи критериям.'];
$error=null;$v=ckmqp_show_validate_hidden_review($valid,$messages,1,$s,$error);
ck168(is_array($v)&&($v['speakerTotal']??0)===70&&($v['opponentTotal']??0)===70,'live-like hidden transcript validates at 70 per role');
ck168(($v['rubricVersion']??0)===3,'hidden review uses rubric version 3');

$raw=['result'=>['participants'=>['speaker'=>h168(10,33,'Фиксируем   начало в 14:30'),'opponent'=>h168(10,34,'Подходит, давайте зафиксируем срок до пятницы')],'analysis'=>'Диалог оценён.','advice'=>'Сохраняйте конкретику.']];
$messages0=[['id'=>33,'teamId'=>101,'slot'=>1,'round'=>1,'attempt'=>0,'text'=>'Фиксируем начало в 14:30 и заранее рассылаем материалы.','at'=>102,'requestId'=>'x33'],['id'=>34,'teamId'=>102,'slot'=>2,'round'=>1,'attempt'=>0,'text'=>'Подходит, давайте зафиксируем срок до пятницы.','at'=>103,'requestId'=>'x34']];
$raw0=['result'=>['participants'=>['speaker'=>h168(10,33,'Фиксируем   начало в 14:30'),'opponent'=>h168(10,34,'Подходит, давайте зафиксируем срок до пятницы')],'analysis'=>'Диалог оценён.','advice'=>'Сохраняйте конкретику.']];
$norm=ckmqp_show_normalize_hidden_ai_review($raw0,$messages0);$error=null;$s0=$s;$s0['attempt']=0;$nv=ckmqp_show_validate_hidden_review($norm,$messages0,0,$s0,$error);
ck168(is_array($nv)&&($nv['total']??0)===70,'provider representation differences normalize without changing the score');
ck168(($nv['participants']['speaker']['total']??0)===70&&($nv['participants']['opponent']['total']??0)===70,'normalized result keeps both role totals');

$bad=$valid;$bad['participants']['speaker']['criteria']['request_specificity']['evidence']=[['messageId'=>999,'quote'=>'Фиксируем начало в 14:30']];
ck168(ckmqp_show_validate_hidden_review($bad,$messages,1,$s,$error)===null,'unknown evidence id is rejected');
$bad=$valid;$bad['participants']['speaker']['criteria']['request_specificity']['evidence']=[['messageId'=>32,'quote'=>'Материалы лучше прислать заранее']];
ck168(ckmqp_show_validate_hidden_review($bad,$messages,1,$s,$error)===null,'foreign-role evidence is rejected for the speaker');

$src=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-hidden.php');
ck168(str_contains($src,'по единой семикритериальной рубрике')&&str_contains($src,'не даёт отдельных очков'),'hidden prompt explicitly removes separate task scoring');
ck168(str_contains($src,'ckmqp_show_validate_review($raw'),'hidden validator delegates to the unified rubric');
ck168(str_contains($src,'ckmqp_show_pairwise_team_score($s,\'hiddenReviews\',$slot)'),'hidden score projection uses pairwise role averaging');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');ck168(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL $n PASS\n";

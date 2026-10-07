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
$n=0;function t299($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$s=ckmqp_show_initial();$s['teamCount']=2;$s['round']=2;$s['attempt']=1;$s['phase']='review';
$s['hardAnswers'][1]=[
 0=>['questionIndex'=>0,'text'=>'Нет, речь только об этой работе; давайте проверим вариант и ключ.','at'=>1,'timedOut'=>false],
 1=>['questionIndex'=>1,'text'=>'На бланке указан вариант Б, это можно сверить с ключом.','at'=>2,'timedOut'=>false],
 2=>['questionIndex'=>2,'text'=>'Предлагаю проверить только спорные задания, чтобы не тратить лишнее время.','at'=>3,'timedOut'=>false],
];
$messages=ckmqp_show_hard_rubric_messages($s,1);
$answerId=30101;$quote='Нет, речь только об этой работе; давайте проверим вариант и ключ.';
$criteria=[];foreach(array_keys(ckmqp_show_review_criteria()) as $key)$criteria[$key]=['points'=>8,'reason'=>'Наблюдаемое поведение '.$key,'evidence'=>[['messageId'=>$answerId,'quote'=>$quote]]];
$speaker=['criteria'=>$criteria,'summary'=>'Ответы прямые и конструктивные.','recommendation'=>'Ещё точнее фиксировать следующий шаг.'];
$v=ckmqp_show_validate_regular_speaker_only_review($speaker,$messages,1,$err,2);
t299(is_array($v)&&($v['speakerTotal']??null)===56,'flat ordinary-rubric speaker assessment is accepted without participants wrapper');
t299(isset($v['participants']['speaker'])&&!isset($v['participants']['opponent']),'single-speaker validator never invents opponent');
$v2=ckmqp_show_validate_regular_speaker_only_review(['speaker'=>$speaker],$messages,1,$err,2);
t299(is_array($v2)&&($v2['total']??null)===56,'top-level speaker wrapper is accepted');
$v3=ckmqp_show_validate_regular_speaker_only_review(['participants'=>['speaker'=>$speaker]],$messages,1,$err,2);
t299(is_array($v3),'participants.speaker wrapper remains accepted');
$hv=ckmqp_show_validate_hard_review($speaker,$s,1,$err);
t299(is_array($hv)&&($hv['total']??null)===56&&($hv['roundRubric']??'')==='seven_criteria','hard round validates only answering team');
$bad=$speaker;$bad['criteria']['respect']['evidence']=[['messageId'=>30100,'quote'=>(string)$messages[0]['text']]];
t299(ckmqp_show_validate_regular_speaker_only_review($bad,$messages,1,$err,2)===null,'foreign system-question evidence is still rejected');
t299(ckmqp_show_validate_review($speaker,$messages,1,$err,2)===null&&str_contains((string)$err,'participants'),'ordinary pairwise validator remains strict');
$hardSrc=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-hard-question.php');
t299(str_contains($hardSrc,"ckmqp_show_ai_review(\$tmp,\$a,true,'regular')"),'hard AI path explicitly requests regular single-speaker contract');
$reviewSrc=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-review.php');
t299(str_contains($reviewSrc,'оцени ТОЛЬКО отвечающую команду speaker'),'hard prompt scopes scoring to answering team');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t299(ckm_test_current_plugin_release($main),'version marker');
echo "ALL $n PASS\n";

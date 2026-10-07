<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (PHP_SAPI!=='cli') exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$args){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function t301($ok,$name){global $n;if(!$ok){fwrite(STDERR,"FAIL $name\n");exit(1);}echo 'PASS '.(++$n).' '.$name."\n";}
function a301($slot){return ['role'=>'participant','slot'=>$slot,'team_id'=>300+$slot];}
function do301($s,$slot,$cmd,$now,$extra=[]){return ckmqp_show_reduce($s,a301($slot),array_merge(['command'=>$cmd,'attempt'=>$s['attempt'],'round'=>$s['round']],$extra),$now);}
$game=['team_count'=>2,'format_settings_snapshot_json'=>'{}'];
$s=ckmqp_show_initial($game);$s['round']=3;$s['attempt']=0;$s['phase']='story_questions';$s['storyStories'][0]=['text'=>'История','at'=>1,'requestId'=>'story_main_request_0001'];
$r=do301($s,2,'story_question',10,['text'=>'Какой вариант?','request_id'=>'story_question_request_0001']);$s=$r['session'];
t301(!empty($r['ok'])&&$s['phase']==='story_answer'&&(int)$s['storyQuestionIndex']===0,'question 1 immediately opens answer 1');
$r=do301($s,1,'story_answer',11,['question_index'=>0,'text'=>'Вариант Б.','request_id'=>'story_answer_request_000001']);$s=$r['session'];
t301(!empty($r['ok'])&&$s['phase']==='story_questions','answer 1 returns to question stage');
$r=do301($s,2,'story_question',12,['text'=>'Почему попросила перепроверку?','request_id'=>'story_question_request_0002']);$s=$r['session'];
t301(!empty($r['ok'])&&$s['phase']==='story_answer'&&(int)$s['storyQuestionIndex']===1,'question 2 immediately opens answer 2');
$r=do301($s,1,'story_answer',13,['question_index'=>1,'text'=>'Чтобы сверить работу с правильным ключом.','request_id'=>'story_answer_request_000002']);$s=$r['session'];
t301(!empty($r['ok'])&&$s['phase']==='story_vote','answer 2 opens vote after exactly two question-answer pairs');
$legacy=ckmqp_show_initial($game);$legacy['round']=3;$legacy['attempt']=0;$legacy['phase']='story_questions';$legacy['storyQuestions'][0][2]=[['text'=>'Уже заданный вопрос','at'=>2,'requestId'=>'legacy_question_request_01']];
$m=ckmqp_show_normalize_state($legacy);
t301($m['phase']==='story_answer'&&(int)$m['storyQuestionIndex']===0,'active old room migrates to first unanswered question');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
t301(str_contains($js,'Проверяющая команда задаёт ровно два вопроса, по одному с ответом рассказчика после каждого.'),'UI explains exact alternating sequence');
t301(str_contains($js,'по отдельной шкале Раунда 4'),'UI names dedicated round-4 rubric');
t301(str_contains($js,"const actionLabel=(idleLabel||'🎙 Продиктовать ответ')"),'dictation instruction follows current control label');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t301(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL $n PASS\n";

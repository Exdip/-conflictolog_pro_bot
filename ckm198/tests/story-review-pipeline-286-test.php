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
require dirname(__DIR__).'/includes/negotiation-show-story.php';
$n=0;function t286($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$messages=[
 ['id'=>40000,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А и прошу сверить ключ проверки.','at'=>1,'requestId'=>'story-main-0'],
 ['id'=>40001,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>0,'text'=>'Какой вариант вы выполняли?','at'=>2,'requestId'=>'story-question-0-0'],
 ['id'=>40002,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Вариант А.','at'=>3,'requestId'=>'story-answer-0-0'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>7,'reason'=>'Есть проявление','evidence'=>[['messageId'=>40002,'quote'=>'Вариант А.']]];
$base=[
 'criteria'=>$criteria,
 'bonuses'=>[
  ['key'=>'bridge','awarded'=>false,'reason'=>'Нет','evidence'=>[]],
  ['name'=>'admit_error','value'=>'false','reason'=>'Нет','evidence'=>[]],
  ['bonus'=>'humor','awarded'=>0,'reason'=>'Нет','evidence'=>[]],
 ],
 'penalties'=>[
  ['code'=>'personal_attack','applied'=>false,'reason'=>'Нет','evidence'=>[]],
  ['type'=>'ultimatum','value'=>'нет','reason'=>'Нет','evidence'=>[]],
  ['penalty'=>'fact_manipulation','applied'=>0,'reason'=>'Игровое искажение разрешено','evidence'=>[]],
 ],
 'summary'=>'Спокойно отвечает на вопросы.',
 'recommendation'=>'Точнее фиксировать следующий шаг.',
];
$r=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>$base]],$messages,0,$err,2);
t286(is_array($r),'list-shaped legacy bonuses and penalties do not break story review');
t286(!isset($r['participants']['speaker']['bonuses'])&&!isset($r['participants']['speaker']['bonusTotal']),'legacy bonuses are absent from normalized result');
t286(!isset($r['participants']['speaker']['penalties'])&&!isset($r['participants']['speaker']['penaltyTotal']),'legacy penalties are absent from normalized result');
t286(($r['participants']['speaker']['total']??-1)===49,'story score remains seven criteria only');
$ru=$base;$ru['bonuses'][0]['key']='Мост';
t286(is_array(ckmqp_show_validate_speaker_only_review(['speaker'=>$ru],$messages,0,$err,2)),'Russian legacy bonus label is ignored without changing score');
$bad=$base;$bad['bonuses'][0]=['key'=>'bridge','awarded'=>true,'reason'=>'Есть мост','evidence'=>[]];
t286(is_array(ckmqp_show_validate_speaker_only_review(['speaker'=>$bad],$messages,0,$err,2)),'true legacy list bonus without evidence is ignored');

$state=ckmqp_show_initial();
$state['teamCount']=2;$state['round']=3;$state['attempt']=0;$state['phase']='review';
$state['storyStories'][0]=['text'=>'Я выполняла вариант А и прошу сверить ключ проверки.','at'=>1];
$state['storyQuestions'][0][2][0]=['text'=>'Какой вариант вы выполняли?','requestId'=>'q1'];
$state['storyAnswers'][0][0]=['text'=>'Вариант А.'];
$state['storyModes'][0]='distortion';
$state['storyResults'][0]=['attempt'=>0,'storytellerSlot'=>1,'mode'=>'distortion','votes'=>[['slot'=>2,'vote'=>'distortion','correct'=>true]]];
$lease='12345678-1234-1234-1234-123456789012';
$state['storyReviews'][0]=['status'=>'running','lease'=>$lease,'expires'=>9999999999,'hash'=>ckmqp_show_story_review_hash($state,0)];
$validated=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>$base]],ckmqp_show_story_rubric_messages($state,0),0,$err,2);
t286(is_array($validated),'speaker-only result validates before persistence');
$finish=ckmqp_show_story_review_reduce($state,['role'=>'system'],['command'=>'story_review_finish','attempt'=>0,'lease'=>$lease,'review'=>$validated,'error'=>''],1000);
t286(!empty($finish['ok']),'story review finish accepts speaker-only review');
t286(($finish['session']['storyReviews'][0]['status']??'')==='done','story review is persisted as done without opponent');
t286(!isset($finish['session']['storyReviews'][0]['review']['participants']['opponent']),'persistence does not invent opponent');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t286(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL $n PASS\n";

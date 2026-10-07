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
$n=0;function t282($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$messages=[
 ['id'=>40000,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А и прошу сверить ключ проверки.','at'=>1,'requestId'=>'story-main-0'],
 ['id'=>40001,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>0,'text'=>'Какой вариант вы выполняли?','at'=>2,'requestId'=>'story-question-0-0'],
 ['id'=>40002,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Вариант А.','at'=>3,'requestId'=>'story-answer-0-0'],
];
$keys=array_keys(ckmqp_show_story_review_criteria());
$criteria=[];foreach($keys as $k)$criteria[$k]=['points'=>8,'reason'=>'Обоснование '.$k,'evidence'=>[['messageId'=>40002,'quote'=>'Вариант А.']]];
$speaker=[
 'criteria'=>$criteria,
 'bonuses'=>[
  'bridge'=>['awarded'=>false,'reason'=>'Не проявлено','evidence'=>[]],
  'admit_error'=>['awarded'=>false,'reason'=>'Не проявлено','evidence'=>[]],
  'humor'=>['awarded'=>false,'reason'=>'Не проявлено','evidence'=>[]],
 ],
 'penalties'=>[
  'personal_attack'=>['applied'=>false,'reason'=>'Не выявлено','evidence'=>[]],
  'ultimatum'=>['applied'=>false,'reason'=>'Не выявлено','evidence'=>[]],
  'fact_manipulation'=>['applied'=>false,'reason'=>'Игровое искажение предусмотрено ролью','evidence'=>[]],
 ],
 'summary'=>'Рассказчик последовательно удерживал версию истории.',
 'recommendation'=>'Точнее объяснять основания сомнений.',
];
$r=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>$speaker]],$messages,0,$err,2);
t282(is_array($r),'participants.speaker accepted without opponent');
t282(($r['participants']['speaker']['total']??null)===56,'speaker score derived server-side');
t282(!isset($r['participants']['opponent']),'opponent result is not invented');
$r2=ckmqp_show_validate_speaker_only_review(['speaker'=>$speaker],$messages,0,$err,2);
t282(is_array($r2),'top-level speaker accepted');
$r3=ckmqp_show_validate_speaker_only_review($speaker,$messages,0,$err,2);
t282(is_array($r3),'flat speaker assessment accepted');
$bad=$speaker;$bad['criteria']['answer_directness']['points']=11;
t282(ckmqp_show_validate_speaker_only_review(['speaker'=>$bad],$messages,0,$err,2)===null,'invalid speaker criterion still rejected');
$normal=['participants'=>['speaker'=>$speaker,'opponent'=>$speaker]];
t282(ckmqp_show_validate_review($normal,$messages,0,$err,2)===null,'ordinary two-party validator still rejects evidence assigned to wrong role');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t282(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
$story=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-story.php');
t282(str_contains($story,'ckmqp_show_ai_review($tmp,$a,true)'),'story round opts into speaker-only AI validation');
function ckm_quiz_pro_solution_price_aipuffer_rest_key(){return 'test-key';}
function ckm_quiz_pro_solution_price_ai_settings(){return ['provider'=>'test','model'=>'test-model'];}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function ckm_quiz_pro_aipuffer_endpoint(){return 'https://example.test/aipuffer';}
function wp_remote_post($url,$args){$GLOBALS['calls282'][]=$args;return ['code'=>200,'body'=>json_encode(['content'=>json_encode($GLOBALS['ai282'])])];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
$state=ckmqp_show_initial();$state['teamCount']=2;$state['phase']='review';$state['messages']=$messages;
$ai282=$speaker;$calls282=[];
$out=ckmqp_show_ai_review($state,0,true);
t282(($out['review']['participants']['speaker']['total']??null)===56,'speaker-only AI transport accepts flat storyteller assessment');
t282(count($calls282)===1,'valid speaker-only assessment needs one AI request');
$ai282=['participants'=>['speaker'=>$speaker]];$calls282=[];
$out=ckmqp_show_ai_review($state,0,true);
t282(($out['review']['total']??null)===56,'speaker-only AI transport accepts participants.speaker without opponent');
t282(!isset($out['review']['participants']['opponent']),'AI transport does not synthesize opponent');
echo "ALL $n PASS\n";

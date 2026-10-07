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
$n=0;function t285($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$messages=[
 ['id'=>40000,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А и прошу сверить ключ проверки.','at'=>1,'requestId'=>'story-main-0'],
 ['id'=>40001,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>0,'text'=>'Какой вариант вы выполняли?','at'=>2,'requestId'=>'story-question-0-0'],
 ['id'=>40002,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Вариант А.','at'=>3,'requestId'=>'story-answer-0-0'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>7,'reason'=>'Есть проявление','evidence'=>[['messageId'=>40002,'quote'=>'Вариант А.']]];
$base=[
 'criteria'=>$criteria,
 'bonuses'=>['bridge'=>false,'admit_error'=>'false','humor'=>0],
 'penalties'=>['personal_attack'=>false,'ultimatum'=>'нет','fact_manipulation'=>0],
 'summary'=>'Спокойно отвечает на вопросы.','recommendation'=>'Точнее фиксировать следующий шаг.',
];
$r=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>$base]],$messages,0,$err,2);
t285(is_array($r),'scalar false bonus and penalty flags accepted in speaker-only story review');
t285(!isset($r['participants']['speaker']['bonuses'])&&!isset($r['participants']['speaker']['penalties']),'legacy auxiliary fields are ignored');
t285(($r['participants']['speaker']['total']??-1)===49,'story total is seven criteria only');
t285(($r['participants']['speaker']['total']??null)===49,'criterion score remains server-derived');
$alias=$base;$alias['bonuses']['bridge']=['value'=>'no'];
$r2=ckmqp_show_validate_speaker_only_review(['speaker'=>$alias],$messages,0,$err,2);
t285(is_array($r2),'harmless value alias false is accepted');
$bad=$base;$bad['bonuses']['bridge']=true;
t285(is_array(ckmqp_show_validate_speaker_only_review(['speaker'=>$bad],$messages,0,$err,2)),'true scalar legacy bonus is ignored');
$bad2=$base;$bad2['penalties']['ultimatum']='yes';
t285(is_array(ckmqp_show_validate_speaker_only_review(['speaker'=>$bad2],$messages,0,$err,2)),'true scalar legacy penalty is ignored');
function ckm_quiz_pro_solution_price_aipuffer_rest_key(){return 'test-key';}
function ckm_quiz_pro_solution_price_ai_settings(){return ['provider'=>'test','model'=>'test-model'];}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function ckm_quiz_pro_aipuffer_endpoint(){return 'https://example.test/aipuffer';}
function wp_remote_post($url,$args){$GLOBALS['calls285'][]=$args;return ['code'=>200,'body'=>json_encode(['content'=>json_encode(['participants'=>['speaker'=>$GLOBALS['ai285']]])])];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
$state=ckmqp_show_initial();$state['teamCount']=2;$state['phase']='review';$state['messages']=$messages;
$ai285=$base;$calls285=[];
$out=ckmqp_show_ai_review($state,0,true);
t285(is_array($out['review']??null),'AI transport accepts harmless false scalar bonus representation');
t285(count($calls285)===1,'normalized speaker-only review succeeds without retry');
$payload=json_decode($calls285[0]['body'],true);$sys=(string)($payload['messages'][0]['content']??'');
t285(str_contains($sys,'Оцени ТОЛЬКО рассказчика speaker'),'speaker-only system prompt is explicit');
t285(!str_contains($sys,'"opponent":'),'speaker-only JSON schema contains no opponent key');
t285(!str_contains($sys,'Каждого участника оцени отдельно'),'speaker-only prompt no longer contains contradictory two-party instruction');
t285(!str_contains($sys,'bridge')&&!str_contains($sys,'bonuses')&&!str_contains($sys,'penalties'),'speaker-only prompt has no bonus or penalty contract');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t285(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL $n PASS\n";

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
$n=0;function t284($ok,$label){global $n;if(!$ok){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
$messages=[
 ['id'=>40000,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А и прошу сверить ключ проверки.','at'=>1,'requestId'=>'story-main-0'],
 ['id'=>40001,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>0,'text'=>'Какой вариант вы выполняли?','at'=>2,'requestId'=>'story-question-0-0'],
 ['id'=>40002,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А.','at'=>3,'requestId'=>'story-answer-0-0'],
];
function p283($evidence){
 $criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>8,'reason'=>'Обоснование '.$k,'evidence'=>$evidence];
 return [
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
}
$err=null;
$r=ckmqp_show_validate_speaker_only_review(['speaker'=>p283(['Я выполняла вариант А.'])],$messages,0,$err,2);
t284(is_array($r),'quote-only string is grounded to a unique real speaker message');
t284(($r['participants']['speaker']['criteria']['answer_directness']['evidence'][0]['messageId']??null)===40002,'quote-only evidence receives the real numeric messageId');
$r=ckmqp_show_validate_speaker_only_review(['speaker'=>p283([['messageId'=>'message 40002','quote'=>'Я выполняла вариант А.']])],$messages,0,$err,2);
t284(is_array($r),'labeled numeric messageId is normalized safely');
$r=ckmqp_show_validate_speaker_only_review(['speaker'=>p283([['messageId'=>40000,'quote'=>'Я выполняла вариант А.']])],$messages,0,$err,2);
t284(is_array($r),'wrong speaker messageId is repaired from a unique real speaker quote');
t284(($r['participants']['speaker']['criteria']['answer_directness']['evidence'][0]['messageId']??null)===40002,'repaired evidence points to the actual speaker message');
$r=ckmqp_show_validate_speaker_only_review(['speaker'=>p283([['quote'=>'Я выполняла вариант А.']])],$messages,0,$err,2);
t284(is_array($r),'missing messageId is grounded from an exact unique quote');
$filteredParticipant=p283([['messageId'=>40001,'quote'=>'Какой вариант вы выполняли?']]);foreach($filteredParticipant['criteria'] as &$row)$row['points']=6;unset($row);
$filtered=ckmqp_show_validate_speaker_only_review(['speaker'=>$filteredParticipant],$messages,0,$err,2);
t284(is_array($filtered),'real opponent evidence no longer invalidates speaker-only story review');
t284(($filtered['participants']['speaker']['criteria']['answer_directness']['evidence']??null)===[],'real opponent evidence is filtered rather than reassigned');
$bad=ckmqp_show_validate_speaker_only_review(['speaker'=>p283(['Выдуманная цитата'])],$messages,0,$err,2);
t284($bad===null,'fabricated quote remains rejected');
$amb=$messages;$amb[]=['id'=>40003,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>0,'text'=>'Я выполняла вариант А.','at'=>4,'requestId'=>'story-answer-0-1'];
$bad=ckmqp_show_validate_speaker_only_review(['speaker'=>p283(['Я выполняла вариант А.'])],$amb,0,$err,2);
t284($bad===null,'ambiguous quote in two speaker messages remains rejected');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t284(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
function ckm_quiz_pro_solution_price_aipuffer_rest_key(){return 'test-key';}
function ckm_quiz_pro_solution_price_ai_settings(){return ['provider'=>'test','model'=>'test-model'];}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function ckm_quiz_pro_aipuffer_endpoint(){return 'https://example.test/aipuffer';}
function wp_remote_post($url,$args){$GLOBALS['calls283'][]=$args;return ['code'=>200,'body'=>json_encode(['content'=>json_encode($GLOBALS['ai283'])])];}
function is_wp_error($r){return false;}
function wp_remote_retrieve_response_code($r){return $r['code'];}
function wp_remote_retrieve_body($r){return $r['body'];}
$state=ckmqp_show_initial();$state['teamCount']=2;$state['phase']='review';$state['messages']=$messages;
$ai283=['participants'=>['speaker'=>p283(['Я выполняла вариант А.'])]];$calls283=[];
$out=ckmqp_show_ai_review($state,0,true);
t284(is_array($out['review']??null),'AI transport accepts uniquely grounded quote-only evidence');
t284(count($calls283)===1,'groundable speaker-only review does not need retry');
$body=json_decode($calls283[0]['body'],true);$sys=(string)($body['messages'][0]['content']??'');
t284(str_contains($sys,'Оцени ТОЛЬКО рассказчика speaker'),'first speaker-only AI request has non-contradictory scope');
t284(str_contains($sys,'messageId должен принадлежать реальной реплике speaker'),'prompt explicitly requests numeric dialogue messageId');
echo "ALL $n PASS\n";

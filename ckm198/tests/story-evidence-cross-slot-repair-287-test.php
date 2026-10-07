<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t287($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
$messages=[
 ['id'=>40000,'slot'=>1,'text'=>'Лера учится в десятом классе. На контрольной она выполняла вариант А.'],
 ['id'=>40001,'slot'=>2,'text'=>'Какой вариант контрольной вы выполняли — А или Б?'],
 ['id'=>40002,'slot'=>1,'text'=>'Я выполняла вариант А. Именно поэтому я и прошу проверить, по какому ключу оценивалась моя работа.'],
 ['id'=>40003,'slot'=>2,'text'=>'Почему вы уверены, что при проверке использовали ключ именно не от вашего варианта?'],
 ['id'=>40004,'slot'=>1,'text'=>'Потому что в проверке есть несоответствие между моими ответами и тем, что отмечено как ошибки.'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>7,'reason'=>'Есть проявление','evidence'=>[['messageId'=>40001,'quote'=>'Я выполняла вариант А.']]];
$raw=['participants'=>['speaker'=>[
 'criteria'=>$criteria,
 'bonuses'=>[
  'bridge'=>['awarded'=>false,'reason'=>'Нет','evidence'=>[]],
  'admit_error'=>['awarded'=>false,'reason'=>'Нет','evidence'=>[]],
  'humor'=>['awarded'=>false,'reason'=>'Нет','evidence'=>[]],
 ],
 'penalties'=>[
  'personal_attack'=>['applied'=>false,'reason'=>'Нет','evidence'=>[]],
  'ultimatum'=>['applied'=>false,'reason'=>'Нет','evidence'=>[]],
  'fact_manipulation'=>['applied'=>false,'reason'=>'Игровое искажение разрешено','evidence'=>[]],
 ],
 'summary'=>'Итог','recommendation'=>'Рекомендация'
]],'summary'=>'Итог'];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,0,$err,2);
t287(is_array($out),'speaker review accepts exact quote despite neighbouring opponent id');
t287(($out['participants']['speaker']['criteria']['answer_directness']['evidence'][0]['messageId']??null)===40002,'evidence id remapped to the real speaker message');
$bad=$raw;foreach($bad['participants']['speaker']['criteria'] as &$r)$r['evidence']=[['messageId'=>40001,'quote'=>'Такой цитаты в рассказе нет']];unset($r);
$err=null;t287(ckmqp_show_validate_speaker_only_review($bad,$messages,0,$err,2)===null,'ungrounded quote remains rejected');
t287(str_contains((string)$err,'цитата не найдена'),'strict rejection reason preserved');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t287(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story evidence cross-slot repair 287 test OK\n";

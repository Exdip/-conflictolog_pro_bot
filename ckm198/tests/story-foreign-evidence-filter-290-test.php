<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t290($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
$messages=[
 ['id'=>40000,'slot'=>1,'text'=>'Лера учится в десятом классе. На контрольной она выполняла вариант А.'],
 ['id'=>40001,'slot'=>2,'text'=>'Какой вариант контрольной вы выполняли — А или Б?'],
 ['id'=>40002,'slot'=>1,'text'=>'Я выполняла вариант А. Именно поэтому я и прошу проверить, по какому ключу оценивалась моя работа.'],
];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>6,'reason'=>'Есть умеренное проявление','evidence'=>[['messageId'=>40001,'quote'=>'Какой вариант контрольной вы выполняли — А или Б?']]];
$raw=['participants'=>['speaker'=>['criteria'=>$criteria,'summary'=>'Итог','recommendation'=>'Рекомендация']],'summary'=>'Итог'];
$err=null;$out=ckmqp_show_validate_speaker_only_review($raw,$messages,0,$err,2);
t290(is_array($out),'speaker-only review survives real foreign checker evidence');
t290(($out['participants']['speaker']['criteria']['answer_directness']['evidence']??null)===[],'foreign checker evidence is dropped, not reassigned');
$bad=$raw;foreach($bad['participants']['speaker']['criteria'] as &$r)$r['evidence']=[['messageId'=>40000,'quote'=>'Выдуманная цитата рассказчика']];unset($r);
$err=null;t290(ckmqp_show_validate_speaker_only_review($bad,$messages,0,$err,2)===null,'fabricated speaker evidence remains rejected');
t290(str_contains((string)$err,'цитата не найдена'),'fabricated evidence keeps strict diagnostic');
$ordinary=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$ordinary[$k]=['points'=>6,'reason'=>'Обычный критерий','evidence'=>[['messageId'=>40001,'quote'=>'Какой вариант контрольной вы выполняли — А или Б?']]];
$two=['participants'=>['speaker'=>['criteria'=>$ordinary,'summary'=>'Итог','recommendation'=>'Совет'],'opponent'=>['criteria'=>$ordinary,'summary'=>'Итог','recommendation'=>'Совет']],'summary'=>'Итог'];
$err=null;t290(ckmqp_show_validate_review($two,$messages,0,$err,2)===null&&str_contains((string)$err,'другому участнику'),'general two-party review remains strict');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t290(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story foreign evidence filter 290 test OK\n";

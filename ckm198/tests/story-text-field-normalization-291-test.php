<?php
// Regression for .291: canonical summary/recommendation may be wrapped objects/arrays.
define('ABSPATH', __DIR__ . '/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string { return $slot===1?'speaker':'opponent'; }
require_once dirname(__DIR__).'/includes/negotiation-show-review.php';
function t291($ok,$msg){ if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);} echo "PASS: $msg\n"; }
$messages=[['id'=>40000,'slot'=>1,'text'=>'Я выполняла вариант А и прошу сверить ключ.']];
$criteria=[];
foreach(array_keys(ckmqp_show_story_review_criteria()) as $k){$criteria[$k]=['points'=>5,'reason'=>'Есть проявление','evidence'=>[]];}
$raw=['participants'=>['speaker'=>[
 'criteria'=>$criteria,
 'summary'=>['text'=>'Спокойно и последовательно отвечает на вопросы.'],
 'recommendation'=>['content'=>'Точнее фиксировать основания своей позиции.'],
]]];
$err=null;$v=ckmqp_show_validate_speaker_only_review($raw,$messages,0,$err,2);
t291(is_array($v),'wrapped canonical text fields validate: '.($err??''));
t291(($v['summary']??'')==='Спокойно и последовательно отвечает на вопросы.','summary unwrapped');
t291(($v['recommendation']??'')==='Точнее фиксировать основания своей позиции.','recommendation unwrapped');
$raw2=['participants'=>['speaker'=>['criteria'=>$criteria,'verdict'=>'Итог поведения','next_steps'=>['Сформулировать конкретный следующий шаг']]]];
$err=null;$v2=ckmqp_show_validate_speaker_only_review($raw2,$messages,0,$err,2);
t291(is_array($v2),'verdict/next_steps aliases validate: '.($err??''));
t291(($v2['summary']??'')==='Итог поведения','verdict mapped to summary');
t291(($v2['recommendation']??'')==='Сформулировать конкретный следующий шаг','next_steps mapped to recommendation');
$raw3=['participants'=>['speaker'=>['criteria'=>$criteria]]];
$err=null;$v3=ckmqp_show_validate_speaker_only_review($raw3,$messages,0,$err,2);
t291(is_array($v3) && ($v3['summary']??null)==='' && ($v3['recommendation']??null)==='','missing story text remains empty when omitted');

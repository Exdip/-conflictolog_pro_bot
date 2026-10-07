<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
// Regression for .292: story-seven scores survive omitted summary/recommendation.
define('ABSPATH', __DIR__ . '/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string { return $slot===1?'speaker':'opponent'; }
require_once dirname(__DIR__).'/includes/negotiation-show-review.php';
function t292($ok,$msg){ if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);} echo "PASS: $msg\n"; }
$messages=[['id'=>40000,'slot'=>1,'text'=>'Я выполняла вариант А и прошу сверить ключ.'],['id'=>40001,'slot'=>2,'text'=>'Почему вы так думаете?']];
$criteria=[];
foreach(array_keys(ckmqp_show_story_review_criteria()) as $k){$criteria[$k]=['points'=>6,'reason'=>'Есть проявление','evidence'=>[]];}
$story=['participants'=>['speaker'=>['criteria'=>$criteria]]];
$err=null;$v=ckmqp_show_validate_speaker_only_review($story,$messages,0,$err,2);
t292(is_array($v),'story-only review validates without summary/recommendation: '.($err??''));
t292(($v['speakerTotal']??null)===42,'seven criteria still total 42');
t292(($v['summary']??null)===''&&($v['recommendation']??null)==='','missing text remains empty and is not synthesized');
$ordinary=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$ordinary[$k]=['points'=>6,'reason'=>'Обычный критерий','evidence'=>[]];
$two=['participants'=>['speaker'=>['criteria'=>$ordinary],'opponent'=>['criteria'=>$ordinary]]];
$err=null;$v2=ckmqp_show_validate_review($two,$messages,0,$err,2);
t292($v2===null && str_contains((string)$err,'итог или рекомендация'),'two-sided reviews remain strict');
$tooLong=['participants'=>['speaker'=>['criteria'=>$criteria,'summary'=>str_repeat('x',4001)]]];
$err=null;$v3=ckmqp_show_validate_speaker_only_review($tooLong,$messages,0,$err,2);
t292($v3===null && str_contains((string)$err,'слишком длинный'),'story text is optional, but oversized text is still rejected');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
t292(str_contains($js,"String(p.summary||'').trim()!==''")&&str_contains($js,"String(p.recommendation||'').trim()!==''"),'UI hides absent optional text fields');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t292(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "ALL PASS\n";

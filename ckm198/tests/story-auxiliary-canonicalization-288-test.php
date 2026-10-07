<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__.'/');
require_once __DIR__.'/../includes/negotiation-show-review.php';
function t288($ok,$msg){if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {return $slot===($attempt+1)?'speaker':'opponent';}
$messages=[['id'=>40000,'slot'=>1,'text'=>'Я предлагаю сверить ключ вместе и после проверки зафиксировать итог.'],['id'=>40001,'slot'=>2,'text'=>'Хорошо, давайте проверим.']];
$criteria=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $k)$criteria[$k]=['points'=>7,'reason'=>'Есть проявление','evidence'=>[['messageId'=>40000,'quote'=>'Я предлагаю сверить ключ вместе']]];
$base=['criteria'=>$criteria,'bonuses'=>['bridge'=>['awarded'=>true]],'penalties'=>['ultimatum'=>['applied'=>true]],'summary'=>'Итог','recommendation'=>'Рекомендация'];
$err=null;$out=ckmqp_show_validate_speaker_only_review(['participants'=>['speaker'=>$base]],$messages,0,$err,2);
t288(is_array($out),'legacy auxiliary fields no longer affect story review');
t288(($out['participants']['speaker']['total']??-1)===49,'score equals seven criteria only');
t288(!isset($out['participants']['speaker']['bonuses'])&&!isset($out['participants']['speaker']['penalties']),'bonuses and penalties are removed from result');
t288(($out['rubricVersion']??0)===4,'story rubric version is 4');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
t288(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
echo "story auxiliary removal 288 test OK\n";

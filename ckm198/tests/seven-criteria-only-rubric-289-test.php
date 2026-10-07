<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
$n=0;function t289($b,$m){global $n;if(!$b){fwrite(STDERR,"FAIL $m\n");exit(1);}echo 'PASS '.(++$n)." $m\n";}
$msg=[['id'=>1,'round'=>0,'attempt'=>0,'slot'=>1,'text'=>'Предлагаю сверить план и зафиксировать срок.'],['id'=>2,'round'=>0,'attempt'=>0,'slot'=>2,'text'=>'Согласен, зафиксируем срок.']];
$mk=function($id,$q){$c=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$c[$k]=['points'=>10,'reason'=>'Проявлено','evidence'=>[['messageId'=>$id,'quote'=>$q]]];return ['criteria'=>$c,'bonuses'=>['bridge'=>['awarded'=>true]],'penalties'=>['ultimatum'=>['applied'=>true]],'summary'=>'Итог','recommendation'=>'Совет'];};
$raw=['participants'=>['speaker'=>$mk(1,'Предлагаю сверить план'),'opponent'=>$mk(2,'Согласен, зафиксируем срок')],'summary'=>'Итог'];
$err=null;$r=ckmqp_show_validate_review($raw,$msg,0,$err,2);
t289(is_array($r),'seven-criterion review accepted');
t289($r['speakerTotal']===70&&$r['opponentTotal']===70,'maximum participant total is 70');
t289(($r['rubricVersion']??0)===3,'rubric version is 3');
t289(!isset($r['participants']['speaker']['bonuses'])&&!isset($r['participants']['speaker']['penalties']),'auxiliary bonus and penalty fields are not stored');
$review=file_get_contents(dirname(__DIR__).'/includes/negotiation-show-review.php');
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
$content=file_get_contents(dirname(__DIR__).'/includes/persuade-me-content.php');
t289(!str_contains($review,'Бонусы каждого участника')&&!str_contains($review,'Штрафы каждого участника'),'AI prompt has no bonus or penalty rubric');
t289(!str_contains($js,'bonusTotal')&&!str_contains($js,'penaltyTotal'),'game UI has no bonus or penalty totals');
t289(str_contains($js,'из 280 баллов')&&str_contains($content,'максимум 280 баллов'),'70 x 4 = 280 copy is published');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t289(ckm_test_current_plugin_release($main),'version marker');
echo "ALL $n PASS\n";

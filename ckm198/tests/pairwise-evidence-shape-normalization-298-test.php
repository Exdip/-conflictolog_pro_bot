<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function ckmqp_show_role(int $slot,int $attempt,int $teamCount=3): string {$speaker=$attempt+1;return $slot===$speaker?'speaker':'opponent';}
require dirname(__DIR__).'/includes/negotiation-show-review.php';
function t298($ok,$m){if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}echo "PASS: $m\n";}
$messages=[
 ['id'=>1,'slot'=>2,'text'=>'Лера, я контрольные не первый год проверяю. Не надо здесь качать права.'],
 ['id'=>2,'slot'=>1,'text'=>'На моём листе указан вариант Б. Прошу сверить задания 2 и 4 с ключом варианта Б.'],
 ['id'=>3,'slot'=>2,'text'=>'Почему ты уверена, что сама не перепутала вариант?'],
 ['id'=>4,'slot'=>1,'text'=>'Давайте откроем ключ Б и сравним эти два задания — так мы проверим факт, а не будем спорить.'],
];
$criteria=[];foreach(array_keys(ckmqp_show_review_criteria()) as $k)$criteria[$k]=['points'=>6,'reason'=>'Есть проявление','evidence'=>[]];
$criteria['request_specificity']['evidence']=['Прошу сверить задания 2 и 4 с ключом варианта Б.'];
$criteria['respect']['evidence']=[['2.0','На моём листе указан вариант Б']];
$criteria['objections']['evidence']=[['message'=>'message 4','excerpt'=>'Давайте откроем ключ Б и сравним эти два задания так мы проверим факт а не будем спорить']];
$raw=['participants'=>[
 'speaker'=>['criteria'=>$criteria,'summary'=>'Конкретно формулирует запрос.','recommendation'=>'Продолжать фиксировать следующий шаг.'],
 'opponent'=>['criteria'=>array_map(fn($x)=>['points'=>5,'reason'=>'Есть проявление','evidence'=>[]],ckmqp_show_review_criteria()),'summary'=>'Задаёт проверочные вопросы.','recommendation'=>'Снизить давление.'],
]];
$n=ckmqp_show_normalize_ai_review($raw,$messages,0,2);
$e1=$n['participants']['speaker']['criteria']['request_specificity']['evidence'][0]??[];
t298(($e1['messageId']??0)===2,'quote-only evidence grounds uniquely to speaker message');
$e2=$n['participants']['speaker']['criteria']['respect']['evidence'][0]??[];
t298(($e2['messageId']??0)===2,'list pair and numeric float-string id normalize');
$e3=$n['participants']['speaker']['criteria']['objections']['evidence'][0]??[];
t298(($e3['messageId']??0)===4,'message alias normalizes');
t298(str_contains((string)($e3['quote']??''),'—'),'punctuation is restored from transcript');
$err=null;$v=ckmqp_show_validate_review($n,$messages,0,$err,2);
t298(is_array($v),'normalized pairwise review passes strict validator: '.($err??''));
$bad=$raw;$bad['participants']['speaker']['criteria']['request_specificity']['evidence']=['выдуманной цитаты здесь нет'];
$nb=ckmqp_show_normalize_ai_review($bad,$messages,0,2);$err=null;$vb=ckmqp_show_validate_review($nb,$messages,0,$err,2);
t298($vb===null,'fabricated quote remains rejected');
$cross=$raw;$cross['participants']['speaker']['criteria']['request_specificity']['evidence']=['Почему ты уверена, что сама не перепутала вариант?'];
$nc=ckmqp_show_normalize_ai_review($cross,$messages,0,2);$err=null;$vc=ckmqp_show_validate_review($nc,$messages,0,$err,2);
t298($vc===null,'opponent quote is not reassigned to speaker');
$main=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
t298(ckm_test_current_plugin_release($main),'version marker');
echo "pairwise evidence shape normalization 298 test OK\n";

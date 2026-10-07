<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s502(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s502('plugin version',ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';
require_once $root.'/negotiation-master/evaluation/evaluation-service.php';
use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\EvaluationService;
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>[],'external_position'=>['statement'=>'Слишком дорого.']];
$fallback=new SalesClientFallback();
$pressure='Тогда скажите, когда сможете подписать договор? Мы можем зафиксировать скидку только сейчас.';
$reply=$fallback->buildProgressReply($ctx,$pressure);
s502('pressured close is not accepted as next step',!str_contains($reply,'мне такой следующий шаг подходит'));
s502('pressured close gets client pushback',str_contains($reply,'не готов подписывать договор')&&str_contains($reply,'экономика решения'));
$good='Предлагаю следующий шаг: подготовлю расчёт на ваших данных и назначим встречу с вами и генеральным директором. Готовы зафиксировать встречу?';
s502('grounded follow-up remains accepted',str_contains($fallback->buildProgressReply($ctx,$good),'мне такой следующий шаг подходит'));
$evalMethod=new ReflectionMethod(EvaluationService::class,'genericAiFallback');$evalMethod->setAccessible(true);
$base=['mechanics'=>['training_domain'=>'sales'],'facts'=>[],'events'=>[],'commitments'=>[],'session'=>['status'=>'completed_sales']];
$base['messages']=[
 ['id'=>1,'actor'=>'player','content'=>'720 тысяч — стандартная цена. Можем дать скидку 10%.'],
 ['id'=>2,'actor'=>'opponent','content'=>'Мне непонятно, чем стоимость окупится.'],
 ['id'=>3,'actor'=>'player','content'=>'Если цена мешает, увеличим скидку до 15%. Лучше не упускать.'],
 ['id'=>4,'actor'=>'opponent','content'=>'Мне всё ещё непонятна экономика.'],
 ['id'=>5,'actor'=>'player','content'=>$pressure],
 ['id'=>6,'actor'=>'opponent','content'=>'Я не готов подписывать договор только ради скидки.'],
];
$next=$evalMethod->invoke(null,['code'=>'next_step'],$base);
s502('pressured signature demand does not count as next step',($next['raw_score']??100)<=35);
$obj=$evalMethod->invoke(null,['code'=>'objection_handling'],$base);
s502('pressure is penalized in objection handling',($obj['raw_score']??100)<30&&str_contains((string)($obj['reason']??''),'давление'));
echo "{$passed} SALES-502 regression checks passed. No database required.\n";

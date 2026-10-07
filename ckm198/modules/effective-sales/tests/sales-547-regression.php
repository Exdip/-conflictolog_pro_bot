<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__.'/fixtures/');
$root=dirname(__DIR__,3);
require $root.'/modules/negotiation-master/evaluation/evaluation-service.php';
$ref=new ReflectionClass(CKM\NegotiationMaster\EvaluationService::class);
$m=$ref->getMethod('salesNextStepAcceptance');
$m->setAccessible(true);
$yes='Я готова выделить 15 минут на диагностический разговор, если продавец покажет связь с контролем качества и не будет сразу продавать подписку.';
$no='Я не готова выделить 15 минут на диагностический разговор.';
if(!$m->invoke(null,$yes)) throw new RuntimeException('Time allocation must confirm next step.');
if($m->invoke(null,$no)) throw new RuntimeException('Negative time allocation must not confirm next step.');
echo "SALES-547 regression passed.\n";

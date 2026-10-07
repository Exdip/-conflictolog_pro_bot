<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s489(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s489('current plugin release header and constant',ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/opponent-response-validator.php';
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';
use CKM\NegotiationMaster\OpponentResponseValidator;
use CKM\NegotiationMaster\SalesClientFallback;
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>[],'external_position'=>['statement'=>'Слишком дорого.']];
$fallback=new SalesClientFallback();
$validator=new OpponentResponseValidator();
$player='Тогда предлагаю конкретный следующий шаг: я подготовлю расчёт на ваших данных, после этого проведём встречу с вами и генеральным директором. Готовы зафиксировать такую встречу?';
$reply=$fallback->buildProgressReply($ctx,$player);
s489('fallback produces explicit client acceptance',str_contains($reply,'мне такой следующий шаг подходит')&&str_contains($reply,'я готов обсудить'));
s489('client acceptance passes opponent validator',$validator->validate($reply,$ctx)===$reply);
$coachRejected=false;
try{$validator->validate('Следующий шаг — вам следует уточнить бюджет и затем назначить встречу.',$ctx);}catch(UnexpectedValueException $e){$coachRejected=true;}
s489('real coaching reply is still rejected',$coachRejected);
$service=file_get_contents($root.'/negotiation-master/ai/opponent/opponent-service.php');
s489('deterministic progress reply still runs before model request',strpos($service,'buildProgressReply')<strpos($service,'$raw = $this->request'));
echo "{$passed} SALES-489 regression checks passed. No database required.\n";

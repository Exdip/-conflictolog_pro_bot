<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s551(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$seller = file_get_contents($root . '/modules/effective-sales/application/sales-ai-seller-service.php');
$scripts = file_get_contents($root . '/modules/effective-sales/application/sales-script-service.php');
$page = file_get_contents($root . '/modules/effective-sales/public/sales-page.php');

s551('plugin version', ckm_test_current_plugin_release($main));
s551('adaptive state exists', str_contains($seller, 'initialAdaptiveState') && str_contains($seller, "'customer_context'") && str_contains($seller, "'demonstration_done'"));
s551('analyzer separated from generator', str_contains($seller, 'analysisPrompt') && str_contains($seller, 'parseAnalysis') && str_contains($seller, 'adaptiveGeneratorPrompt'));
s551('deterministic NBA exists', str_contains($seller, 'selectAdaptiveAction') && str_contains($seller, 'nextDiagnosticAction') && str_contains($seller, 'directAction'));
s551('direct question returns to diagnosis', str_contains($seller, "'return_action'=>self::nextDiagnosticAction(\$state)") && str_contains($seller, 'ВОЗВРАТ ПОСЛЕ ПРЯМОГО ОТВЕТА'));
s551('price grounding explicit', str_contains($seller, 'Называй число только если оно явно есть') && str_contains($seller, 'Не выдумывай цену'));
s551('adaptive demo action', str_contains($seller, 'SHOW_ADAPTIVE_DEMO') && str_contains($seller, 'demonstration_done'));
s551('ready adaptive script template', str_contains($scripts, 'createAdaptiveSalesTemplate') && str_contains($scripts, 'Продажа адаптивных скриптов продаж'));
s551('template commercial guard', str_contains($scripts, 'Первичный аудит текущих диалогов клиента бесплатный') && str_contains($scripts, 'Пилот и полная разработка'));
s551('template UI action', str_contains($page, 'create_adaptive_template') && str_contains($page, 'Готовый скрипт: «Продажа адаптивных скриптов»'));

// Exercise the production NBA selection: direct answers preserve the pending diagnosis.
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require_once $root.'/modules/effective-sales/application/sales-ai-seller-service.php';
$initial=new ReflectionMethod(CKM\EffectiveSales\SalesAiSellerService::class,'initialAdaptiveState');
$select=new ReflectionMethod(CKM\EffectiveSales\SalesAiSellerService::class,'selectAdaptiveAction');
$state=$initial->invoke(null);
$price=$select->invoke(null,$state,['direct_intent'=>'ASK_PRICE']);
s551('price answer preserves unanswered context',($price['action']??'')==='ANSWER_PRICE'&&($price['return_action']??'')==='ASK_CONTEXT');
$state['customer_context']='Отдел продаж';
$product=$select->invoke(null,$state,['direct_intent'=>'ASK_PRODUCT']);
s551('product answer returns to next missing diagnostic field',($product['action']??'')==='ANSWER_PRODUCT'&&($product['return_action']??'')==='ASK_CURRENT_METHOD');
echo "{$passed} SALES-551 adaptive AI seller MVP checks passed. No database required.\n";

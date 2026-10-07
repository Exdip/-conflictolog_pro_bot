<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
$root=dirname(__DIR__,3);
$src=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
if(!ckm_test_declared_version_at_least($src,'VERSION','neg-eval-2.8')) throw new RuntimeException('Evaluator version not bumped.');
if(strpos($src,'if(str_contains($text,\'?\')&&!$valueOutcome)$value=false;')===false) throw new RuntimeException('Pure-question value guard missing.');
// Execute the production guard as well as checking its placement in the evaluator.
require_once $root.'/modules/negotiation-master/evaluation/evaluation-service.php';
$signals=new ReflectionMethod(CKM\NegotiationMaster\EvaluationService::class,'salesPlayerSignals');
$question=$signals->invoke(null,['mechanics'=>['training_domain'=>'sales'],'messages'=>[['id'=>1,'actor'=>'player','content'=>'Предлагаю 15 минут на встречу завтра. Вам подходит?']]]);
if(!empty($question['value'])||!in_array(1,$question['next']??[],true))throw new RuntimeException('Scheduling question must offer a next step without proving value.');
$grounded=$signals->invoke(null,['mechanics'=>['training_domain'=>'sales'],'messages'=>[['id'=>2,'actor'=>'player','content'=>'Вашим менеджерам решение поможет сократить 2 часа ручной работы в день.']]]);
if(!in_array(2,$grounded['value']??[],true))throw new RuntimeException('Grounded outcome must remain value evidence.');
echo "SALES-549 regression passed.
";

<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/modules/negotiation-master/evaluation/evaluation-service.php';
use CKM\NegotiationMaster\EvaluationService;
$checks=0;$failed=0;function e364($ok,$name){global$checks,$failed;$checks++;if(!$ok)$failed++;echo($ok?'PASS':'FAIL').": $name\n";}
$method=new ReflectionMethod(EvaluationService::class,'summaryBuckets');
$rows=[
 'result_quality'=>['code'=>'result_quality','title'=>'Качество достигнутого результата','raw_score'=>100,'explanation'=>'Пакет соответствует целям.','evidence_message_ids'=>[]],
 'interest_discovery'=>['code'=>'interest_discovery','title'=>'Выявление интересов','raw_score'=>0,'explanation'=>'Интересы не выявлены.','evidence_message_ids'=>[]],
 'process'=>['code'=>'process','title'=>'Управление процессом','raw_score'=>72,'explanation'=>'Есть базовая управляемость.','evidence_message_ids'=>[5]],
];
$out=$method->invoke(null,$rows);
e364(count($out['strengths'])===1,'only high-scoring criterion is a strength');
e364(($out['strengths'][0]['criterion_code']??'')==='result_quality','100 score is classified as strength');
e364(!in_array('interest_discovery',array_column($out['strengths'],'criterion_code'),true),'zero criterion never appears in strengths');
e364(count($out['improvements'])===2,'low and medium criteria become improvements');
e364(($out['improvements'][0]['criterion_code']??'')==='interest_discovery','lowest criterion is first improvement');
e364(str_starts_with((string)($out['improvements'][0]['text']??''),'Приоритетная зона развития'),'very low score gets priority wording');
e364(str_starts_with((string)($out['improvements'][1]['text']??''),'Зона развития'),'medium score gets development wording');
e364(!in_array('result_quality',array_column($out['improvements'],'criterion_code'),true),'high criterion never appears in improvements');
$none=$method->invoke(null,['mid'=>['code'=>'mid','title'=>'Средний','raw_score'=>75,'explanation'=>'Средний результат.','evidence_message_ids'=>[]]]);
e364(count($none['strengths'])===0,'no fake strength when nothing reaches threshold');
$svc=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/evaluation-service.php');
e364(preg_match("/VERSION\s*=\s*'neg-eval-([0-9.]+)'/",$svc,$evaluationVersion)&&version_compare($evaluationVersion[1],'1.6','>='),'current evaluator retains summary bucket support');
$js=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/assets/negotiation-session.js');
e364(str_contains($js,'Сильные стороны по заданным критериям пока не подтверждены.'),'empty strengths has honest UI copy');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
e364(ckm_test_current_plugin_release($plugin),'plugin build updated');
echo"TOTAL: $checks checks, $failed failed\n";exit($failed?1:0);

<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
require dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;
$checks=0;$failed=0;function e362($ok,$name){global$checks,$failed;$checks++;if(!$ok)$failed++;echo($ok?'PASS':'FAIL').": $name\n";}
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Фиксируем цену 960000 и срок 40 дней.']], 'facts'=>[], 'events'=>[], 'player_card'=>[], 'opponent_card'=>[], 'final_agreement'=>null,'completion'=>[]];
$r1=['code'=>'result_quality','title'=>'Качество результата','weight'=>60,'rubric'=>['scale'=>['min'=>0,'max'=>10]]];
$r2=['code'=>'interest_discovery','title'=>'Выявление интересов','weight'=>40,'rubric'=>['scale'=>['min'=>0,'max'=>10]]];
$calls=0;
$ev=new AiEvaluator(function($messages)use(&$calls){$calls++;if($calls===1)return'not json';return json_encode(['raw_score'=>8,'level'=>'excellent','confidence'=>92,'evidence_message_ids'=>[1],'reason'=>'Сильный результат.'],JSON_UNESCAPED_UNICODE);});
$thrown=false;try{$out=$ev->evaluate([$r1],$ctx);}catch(Throwable $e){$thrown=true;$out=[];}
e362(!$thrown&&$out===[]&&$calls===1,'malformed single attempt returns unresolved for resumable service fallback without duplicate request');
$out=$ev->evaluate([$r1],$ctx);
e362($calls===2&&isset($out['result_quality']),'subsequent service attempt can normalize a valid single-criterion response');
e362(abs(($out['result_quality']['raw_score']??0)-80)<0.0001,'0-10 single score normalized to 80/100');
e362(($out['result_quality']['level']??'')==='strong','canonical level derived from normalized score');
e362(abs(($out['result_quality']['confidence']??0)-0.92)<0.0001,'confidence 92 normalized to 0.92');

$calls=0;
$ev2=new AiEvaluator(function($messages)use(&$calls){$calls++;if($calls===1)return json_encode(['evaluation'=>['criterion_code'=>'result_quality','raw_score'=>85,'level'=>'weak','confidence'=>.9,'evidence_message_ids'=>[1],'reason'=>'ok']],JSON_UNESCAPED_UNICODE);return json_encode(['evaluation'=>['raw_score'=>7,'level'=>'weak','confidence'=>90,'evidence_message_ids'=>[1],'reason'=>'интересы частично выявлены']],JSON_UNESCAPED_UNICODE);});
$out=$ev2->evaluate([$r1,$r2],$ctx);
e362(isset($out['result_quality'])&&isset($out['interest_discovery'])&&$calls===2,'batch response preserves resolved criterion and bounded repair requests only missing criterion');
e362(($out['result_quality']['level']??'')==='excellent','wrong model level does not invalidate 85/100 score');
e362(abs(($out['interest_discovery']['raw_score']??0)-70)<0.0001,'singular evaluation object accepted and normalized');

$service=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/evaluation-service.php');
e362(preg_match("/VERSION\s*=\s*'neg-eval-([0-9.]+)'/",$service,$evaluationVersion)&&version_compare($evaluationVersion[1],'1.6','>='),'current resumable evaluation version retains 1.6 normalization support');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
e362(ckm_test_current_plugin_release($plugin),'plugin build updated');
e362(str_contains(file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php'),'evaluateSubset'),'per-criterion fallback present');
e362(str_contains(file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php'),'levelForScore'),'server canonical level present');
e362(str_contains(file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php'),'max<=20.0'),'author scale normalization present');
echo"TOTAL: $checks checks, $failed failed\n";exit($failed?1:0);

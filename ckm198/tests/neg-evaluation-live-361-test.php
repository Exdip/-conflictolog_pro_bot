<?php
require_once __DIR__ . '/support/plugin-release.php';
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
require dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;
$checks=0;$failed=0;
function e361($ok,$name){global $checks,$failed;$checks++;if(!$ok)$failed++;echo($ok?'PASS':'FAIL').": $name\n";}
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Фиксируем цену 960000 и срок 40 дней.']], 'facts'=>[], 'events'=>[], 'player_card'=>[], 'opponent_card'=>[], 'final_agreement'=>null,'completion'=>[]];
$rule=['code'=>'result_quality','title'=>'Качество результата','weight'=>60,'rubric'=>['scale'=>['min'=>0,'max'=>10]]];
$rules=[$rule];

$double=json_encode(json_encode(['evaluations'=>[['criterion_code'=>'result_quality','level'=>'strong','raw_score'=>8,'confidence'=>92,'evidence_message_ids'=>[1],'reason'=>'Хороший результат.']]],JSON_UNESCAPED_UNICODE),JSON_UNESCAPED_UNICODE);
$r=(new AiEvaluator(fn()=> $double))->evaluate($rules,$ctx);
e361(isset($r['result_quality']),'double-encoded JSON accepted');
e361(abs(($r['result_quality']['raw_score']??0)-80.0)<0.0001,'0-10 rubric score normalized to 0-100');
e361(abs(($r['result_quality']['confidence']??0)-0.92)<0.0001,'0-100 confidence normalized to 0-1');

$markdown="```json\n".json_encode(['evaluations'=>[['criterion_code'=>'result_quality','level'=>'excellent','raw_score'=>9.2,'confidence'=>0.94,'evidence_message_ids'=>[1],'reason'=>'Очень сильный итог.']]],JSON_UNESCAPED_UNICODE)."\n```";
$r=(new AiEvaluator(fn()=> $markdown))->evaluate($rules,$ctx);
e361(abs(($r['result_quality']['raw_score']??0)-92.0)<0.0001,'markdown JSON and decimal rubric scale accepted');

$nested=json_encode(['evaluations'=>json_encode([['criterion_code'=>'result_quality','level'=>'strong','raw_score'=>76,'confidence'=>91,'evidence_message_ids'=>[1],'reason'=>'Сильный результат.']],JSON_UNESCAPED_UNICODE)],JSON_UNESCAPED_UNICODE);
$r=(new AiEvaluator(fn()=> $nested))->evaluate($rules,$ctx);
e361(abs(($r['result_quality']['confidence']??0)-0.91)<0.0001,'string-encoded evaluations array accepted');

$bad=json_encode(['evaluations'=>[['criterion_code'=>'result_quality','level'=>'strong','raw_score'=>8,'confidence'=>140,'evidence_message_ids'=>[1],'reason'=>'bad confidence']]],JSON_UNESCAPED_UNICODE);
$calls=0;$thrown=false;try{$badOut=(new AiEvaluator(function()use(&$calls,$bad){$calls++;return$bad;}))->evaluate($rules,$ctx);}catch(Throwable){$thrown=true;$badOut=[];}
e361(!$thrown&&abs(($badOut['result_quality']['confidence']??0)-1.0)<0.0001,'out-of-range self-confidence is clamped as metadata');

$service=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/evaluation/evaluation-service.php');
e361(preg_match("/VERSION\s*=\s*'neg-eval-([0-9.]+)'/",$service,$evaluationVersion)&&version_compare($evaluationVersion[1],'1.6','>='),'current evaluator retains normalization support');
e361(str_contains($service,"'evaluation_version'=>self::VERSION")&&str_contains($service,"'failure_stage'=>\$stage"),'retry updates version and stores safe failure stage');

$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
e361(ckm_test_current_plugin_release($plugin),'plugin build updated');

echo "TOTAL: $checks checks, $failed failed\n";exit($failed?1:0);

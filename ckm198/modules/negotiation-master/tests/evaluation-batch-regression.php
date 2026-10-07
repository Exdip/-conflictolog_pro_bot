<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v,$flags=0){ return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
require dirname(__DIR__) . '/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;

$passed=0;
function e477base(string $name,bool $ok):void{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL: '.$name);
    $passed++; echo "PASS: {$name}\n";
}
$rules=[];
for($i=1;$i<=6;$i++)$rules[]=['code'=>'c'.$i,'title'=>'Критерий '.$i,'weight'=>100/6,'rubric'=>[]];
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Тест'],['id'=>2,'actor'=>'opponent','content'=>'Ответ']],
      'facts'=>[],'events'=>[],'player_card'=>[],'opponent_card'=>[],'final_agreement'=>[],'completion'=>[]];

function r477(int $i):array{
    return ['criterion_code'=>'c'.$i,'raw_score'=>70+$i,'confidence'=>0.9,'evidence_message_ids'=>[1,2],'reason'=>'Краткое основание.'];
}
$calls=[];
$ai=new AiEvaluator(function($messages,$timeout)use(&$calls){
    $payload=json_decode($messages[1]['content']??'{}',true);
    $codes=array_map(fn($x)=>(string)($x['code']??''),(array)($payload['criteria']??[]));
    $calls[]=['codes'=>$codes,'timeout'=>$timeout];
    $rows=[];foreach($codes as $code){$i=(int)substr($code,1);$rows[]=r477($i);}
    return json_encode(['evaluations'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
$out=$ai->evaluate($rules,$ctx);
e477base('six criteria returned',count($out)===6);
e477base('six criteria use two chunks',count($calls)===2);
e477base('first chunk c1-c3',$calls[0]['codes']===['c1','c2','c3']);
e477base('second chunk c4-c6',$calls[1]['codes']===['c4','c5','c6']);
e477base('chunk timeout 20 seconds',$calls[0]['timeout']===20&&$calls[1]['timeout']===20);
e477base('criterion c6 validated',isset($out['c6'])&&$out['c6']['raw_score']===76.0);

$svc=file_get_contents(dirname(__DIR__).'/evaluation/evaluation-service.php');
require dirname(__DIR__,3).'/tests/support/plugin-release.php';
e477base('evaluation release retains chunk contract introduced in 2.5', ckm_test_declared_version_at_least($svc,'VERSION','neg-eval-2.5'));
echo "{$passed} evaluation-chunk base regression checks passed.\n";

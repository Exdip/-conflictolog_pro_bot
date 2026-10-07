<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v,$flags=0){ return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
require dirname(__DIR__) . '/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;

$passed=0;
function e477hard(string $name,bool $ok):void{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL: '.$name);
    $passed++; echo "PASS: {$name}\n";
}
$rules=[];
for($i=1;$i<=7;$i++)$rules[]=['code'=>'c'.$i,'title'=>'Критерий '.$i,'weight'=>100/7,'rubric'=>[]];
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Тест'],['id'=>2,'actor'=>'opponent','content'=>'Ответ']],
      'facts'=>[],'events'=>[],'player_card'=>[],'opponent_card'=>[],'final_agreement'=>[],'completion'=>[]];

function rr477(int $i):array{
    return ['criterion_code'=>'c'.$i,'raw_score'=>60+$i,'confidence'=>0.9,'evidence_message_ids'=>[1,2],'reason'=>'Краткое основание.'];
}
$calls=[];
$ai=new AiEvaluator(function($messages,$timeout)use(&$calls){
    $payload=json_decode($messages[1]['content']??'{}',true);
    $codes=array_map(fn($x)=>(string)($x['code']??''),(array)($payload['criteria']??[]));
    $calls[]=['codes'=>$codes,'timeout'=>$timeout,'system'=>$messages[0]['content']??''];
    $n=count($calls);
    if($n===1){ return json_encode(['evaluations'=>[rr477(1),rr477(2)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); } // c3 missing
    if($n===2){ return json_encode(['evaluations'=>[rr477(4),rr477(5),rr477(6)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    if($n===3){ return json_encode(['evaluations'=>[rr477(7)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
    return json_encode(['evaluations'=>[rr477(3)]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
});
$out=$ai->evaluate($rules,$ctx);
e477hard('seven criteria accumulated',count($out)===7);
e477hard('three initial chunks plus one repair',count($calls)===4);
e477hard('chunk 1 c1-c3',$calls[0]['codes']===['c1','c2','c3']);
e477hard('chunk 2 c4-c6',$calls[1]['codes']===['c4','c5','c6']);
e477hard('chunk 3 c7',$calls[2]['codes']===['c7']);
e477hard('repair only missing c3',$calls[3]['codes']===['c3']);
e477hard('repair uses strict prompt',str_contains($calls[3]['system'],'повторная попытка'));
e477hard('all calls use 20 seconds',count(array_filter($calls,fn($c)=>$c['timeout']===20))===4);

$src=file_get_contents(dirname(__DIR__).'/evaluation/ai-evaluator.php');
e477hard('chunk size is three',str_contains($src,'array_chunk($valid,3)'));
e477hard('single repair capped at three',str_contains($src,'array_slice($missing,0,3)'));
e477hard('token cap is 1350',str_contains($src,'min(1350'));
$svc=file_get_contents(dirname(__DIR__).'/evaluation/evaluation-service.php');
require dirname(__DIR__,3).'/tests/support/plugin-release.php';
e477hard('evaluation release retains chunk contract introduced in 2.5', ckm_test_declared_version_at_least($svc,'VERSION','neg-eval-2.5'));
echo "{$passed} evaluation-chunk hardening regression checks passed.\n";

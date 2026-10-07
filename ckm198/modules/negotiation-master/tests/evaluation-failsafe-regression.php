<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($v,$flags=0){ return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
require dirname(__DIR__) . '/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;

$passed=0;
function e479(string $name,bool $ok):void{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL: '.$name);
    $passed++;echo "PASS: {$name}\n";
}

$service=file_get_contents(dirname(__DIR__).'/evaluation/evaluation-service.php');
require dirname(__DIR__,3).'/tests/support/plugin-release.php';
e479('evaluation release retains failsafe contract introduced in 2.5', ckm_test_declared_version_at_least($service,'VERSION','neg-eval-2.5'));
e479('server fallback source exists',str_contains($service,"'server_fallback'"));
e479('generic AI fallback exists',str_contains($service,'genericAiFallback'));
e479('agreement coverage fallback exists',str_contains($service,'agreementCoverage'));
e479('AI degraded flag persisted',str_contains($service,"'ai_degraded'=>".'$aiDegraded'));
e479('subsequent AI calls can be skipped',str_contains($service,'if(!$aiDegraded)'));
e479('missing AI score degrades mode',str_contains($service,'if(!$aiScore)$aiDegraded=true'));

$calls=0;
$ai=new AiEvaluator(function($messages,$timeout)use(&$calls){
    $calls++;
    return 'not-json-at-all';
});
$rules=[['code'=>'communication','title'=>'Коммуникация','weight'=>100,'rubric'=>['scale'=>['min'=>0,'max'=>100]]]];
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Тест']],'facts'=>[],'events'=>[],
      'player_card'=>[],'opponent_card'=>[],'final_agreement'=>[],'completion'=>[]];
$out=$ai->evaluate($rules,$ctx);
e479('single invalid AI response returns empty result',$out===[]);
e479('single criterion uses only one AI transport call',$calls===1);

echo "{$passed} evaluation-failsafe regression checks passed.\n";

<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');

$service=file_get_contents(dirname(__DIR__).'/evaluation/evaluation-service.php');
$js=file_get_contents(dirname(__DIR__).'/assets/negotiation-session.js');

$passed=0;
function e478(string $name,bool $ok):void{
    global $passed;
    if(!$ok)throw new RuntimeException('FAIL: '.$name);
    $passed++;echo "PASS: {$name}\n";
}
require dirname(__DIR__,3).'/tests/support/plugin-release.php';
e478('evaluation release retains resumable contract introduced in 2.5', ckm_test_declared_version_at_least($service,'VERSION','neg-eval-2.5'));
e478('one AI criterion per server step',str_contains($service,'$this->ai->evaluate([$nextAi],$context)'));
e478('whole AI rule set is not evaluated in one service call',!str_contains($service,'$this->ai->evaluate($aiRules,$context)'));
e478('partial scores are persisted',str_contains($service,'storedScores($evalId)'));
e478('version change clears stale partial scores',str_contains($service,'$wpdb->delete($scoreT,[\'evaluation_id\'=>$evalId])'));
e478('processing progress is saved',str_contains($service,'completed\'=>count($saved),\'total\'=>count($rules)'));
e478('finalization waits for all rules',str_contains($service,'if(count($saved)<count($rules))'));
e478('client automatically continues evaluation',str_contains($js,'for(let step=0;step<10;step++)'));
e478('client posts evaluate for each stage',str_contains($js,"const calc=await api('/results/sessions/'+sessionId+'/evaluate'"));
e478('client keeps processing state between steps',str_contains($js,"['processing','pending'].includes"));
e478('client continuation loop is bounded',str_contains($js,'step<10'));

echo "{$passed} evaluation-resumable regression checks passed.\n";

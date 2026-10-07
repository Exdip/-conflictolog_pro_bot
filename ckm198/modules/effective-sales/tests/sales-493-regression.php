<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
$root = dirname(__DIR__, 2);
$passed = 0;
function s493(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s493('current plugin release header and constant',ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/coach/coach-response-validator.php';
require_once $root.'/negotiation-master/ai/coach/coach-service.php';
use CKM\NegotiationMaster\CoachResponseValidator;
use CKM\NegotiationMaster\CoachService;
$validator=new CoachResponseValidator();
$method=new ReflectionMethod(CoachService::class,'safeFallback');$method->setAccessible(true);
$ctx=['training_domain'=>'sales'];
foreach(['attention','direction','example','review_last_move'] as $level){
    $text=(string)$method->invoke(null,$ctx,$level);
    s493('safe fallback passes validator: '.$level,$validator->validate($text,$level)===$text);
}
$service=file_get_contents($root.'/negotiation-master/ai/coach/coach-service.php');
s493('second validation failure falls back instead of surfacing validator error',str_contains($service,'$fallback = self::safeFallback($context, $level);'));
s493('fallback is validated before saving',str_contains($service,'$this->validator->validate($fallback, $level)'));
$prompt=file_get_contents($root.'/negotiation-master/ai/coach/coach-prompt-builder.php');
s493('strict retry explicitly shortens attention',str_contains($prompt,'ОДНО короткое предложение. Только наблюдение'));
s493('strict retry explicitly shortens example',str_contains($prompt,'до 260 символов'));
s493('strict retry no longer only says generic validator rejection',!str_contains($prompt,'Предыдущий черновик был отклонён валидатором. Соблюди формат ещё строже'));
echo "{$passed} SALES-493 regression checks passed. No database required.\n";

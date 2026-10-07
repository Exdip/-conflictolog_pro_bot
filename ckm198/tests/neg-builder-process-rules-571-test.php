<?php
/** Current system seed rules must survive the production builder normalization. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
require $argv[1] ?? dirname(__DIR__) . '/modules/negotiation-master/application/scenario-builder-service.php';
use CKM\NegotiationMaster\ScenarioBuilderService;
use CKM\NegotiationMaster\ScenarioBuilderValidationException;
$root = dirname(__DIR__);
$pack = require $root . '/modules/negotiation-master/content/system-v1.php';
$normalizer = new ReflectionMethod(ScenarioBuilderService::class, 'normalizeRules');
$normalizer->setAccessible(true);
$builder = new ScenarioBuilderService();
$checks = 0;
function process571(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    $checks++;
}
$processRules = 0;
foreach ($pack['scenarios'] as $scenario) {
    $rules = $scenario['components']['rules'];
    try { $actual = $normalizer->invoke($builder, $rules); }
    catch (Throwable $e) { fwrite(STDERR, 'FAIL: current seeded rules rejected: ' . $scenario['scenario']['slug'] . ': ' . $e->getMessage() . "\n"); exit(1); }
    process571(count($actual) === count($rules), 'all seeded rules normalized');
    foreach ($rules as $index => $rule) {
        process571($actual[$index]['rule_type'] === $rule['rule_type'], 'rule type preserved');
        process571(json_decode($actual[$index]['condition_json'], true) === $rule['condition_json'], 'rule condition preserved');
        process571(json_decode($actual[$index]['action_json'], true) === $rule['action_json'], 'rule action preserved');
        process571($actual[$index]['priority'] === (int)$rule['priority'] && $actual[$index]['is_active'] === (int)$rule['is_active'], 'priority and active state preserved');
        if ($rule['rule_type'] === 'process') { $processRules++; }
    }
}
process571($processRules > 0, 'current process rules exercised');
$page = file_get_contents($root . '/modules/negotiation-master/public/builder-page.php');
process571((bool)preg_match('/<option value="process">[^<]*[А-Яа-я]/u', $page), 'process rule has a Russian editor choice');
$rejected = false;
try { $normalizer->invoke($builder, [['code'=>'invalid_rule', 'rule_type'=>'unsupported_type']]); }
catch (ScenarioBuilderValidationException $e) { $rejected = true; }
process571($rejected, 'unsupported rule types remain rejected');
echo "NEG-BUILDER-PROCESS-RULES-571: $checks/$checks PASS; $processRules process rules\n";

<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$pack = require $root . '/negotiation-master/content/system-v1.php';
$passed = 0;
function s481(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }

s481('content pack 1.50.0', ($pack['pack_version'] ?? '') === '1.50.0');
$libs = array_column((array)($pack['libraries'] ?? []), null, 'slug');
s481('effective-sales library exists', isset($libs['effective-sales']));
s481('effective-sales library unlisted', ($libs['effective-sales']['visibility'] ?? '') === 'unlisted');
s481('effective-sales library published', ($libs['effective-sales']['status'] ?? '') === 'published');

$target = null;
foreach ((array)($pack['scenarios'] ?? []) as $entry) {
    if (($entry['scenario']['slug'] ?? '') === 'too-expensive') { $target = $entry; break; }
}
s481('too-expensive scenario exists', is_array($target));
s481('scenario belongs to effective-sales', ($target['scenario']['library_slug'] ?? '') === 'effective-sales');
s481('scenario is published', ($target['scenario']['status'] ?? '') === 'published' && ($target['version']['status'] ?? '') === 'published');
$mechanics = (array)($target['version']['mechanics_json'] ?? []);
s481('sales training domain is data-driven', ($mechanics['training_domain'] ?? '') === 'sales');
s481('sales UI profile', ($mechanics['ui_profile'] ?? '') === 'sales_v1');
s481('sales completion mode', ($mechanics['completion_mode'] ?? '') === 'manual_sales');
s481('no negotiation auto-walkaway', ($mechanics['opponent_can_walkaway'] ?? true) === false);
s481('four sales stages', count((array)($mechanics['stages'] ?? [])) === 4);
$components = (array)($target['components'] ?? []);
s481('no negotiation items', count((array)($components['items'] ?? [])) === 0);
s481('six hidden client facts', count((array)($components['hidden_facts'] ?? [])) === 6);
s481('five sales evaluation rules', count((array)($components['evaluation_rules'] ?? [])) === 5);
$weights = array_sum(array_map(fn($r)=>(float)($r['weight'] ?? 0), (array)($components['evaluation_rules'] ?? [])));
s481('sales evaluation weight is 100', abs($weights - 100.0) < 0.0001);
$codes = array_column((array)($components['evaluation_rules'] ?? []), 'code');
s481('sales criteria are complete', $codes === ['customer_understanding','question_quality','value_proposition','objection_handling','next_step']);

$opp = file_get_contents($root.'/negotiation-master/ai/opponent/opponent-prompt-builder.php');
$arb = file_get_contents($root.'/negotiation-master/ai/arbiter/arbiter-prompt-builder.php');
$eval = file_get_contents($root.'/negotiation-master/evaluation/evaluation-service.php');
$page = file_get_contents(dirname(__DIR__).'/public/sales-page.php');
s481('opponent prompt branches by training_domain', str_contains($opp, "training_domain") && str_contains($opp, 'ИИ-клиент'));
s481('arbiter accepts sales without deal items', str_contains($arb, 'пустой deal_updates — нормален'));
s481('evaluation emits sales outcomes', str_contains($eval, 'sales_advanced') && str_contains($eval, 'sales_value_not_proven'));
s481('dedicated sales page exists', str_contains($page, 'ckm-sales-master') && str_contains($page, 'Эффективный продажник'));

echo "{$passed} SALES-481 regression checks passed. No database required.\n";

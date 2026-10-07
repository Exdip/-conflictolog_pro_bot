<?php
// Standalone CLI regression suite for Negotiation Master scenario rules.
// Does not require WordPress, MySQL or SQLite.
if (PHP_SAPI !== 'cli') { exit(1); }

define('ABSPATH', __DIR__ . '/fixtures/');
define('ARRAY_A', 'ARRAY_A');

require dirname(__DIR__) . '/domain/rule-engine.php';
require dirname(__DIR__) . '/domain/agreement-validator.php';
require dirname(__DIR__) . '/domain/zopa-service.php';

use CKM\NegotiationMaster\RuleEngine;
use CKM\NegotiationMaster\AgreementValidator;
use CKM\NegotiationMaster\ZopaService;

$passed = 0;
function check(string $name, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $passed++;
    echo "PASS: {$name}\n";
}
function invoke_private(string $class, string $method, array $args): mixed {
    $rm = new ReflectionMethod($class, $method);
    return $rm->invoke(null, ...$args);
}

$values = [
    'price' => 100,
    'days' => 20,
    'service' => 12,
    'bonus' => 5,
];

foreach ([RuleEngine::class, AgreementValidator::class, ZopaService::class] as $class) {
    check($class . ' require gte true',
        invoke_private($class, 'requirementSatisfied', [
            ['type'=>'require','gte'=>[['item'=>'price'],100]], $values
        ]) === true
    );
    check($class . ' require gte false',
        invoke_private($class, 'requirementSatisfied', [
            ['type'=>'require','gte'=>[['item'=>'price'],101]], $values
        ]) === false
    );
    check($class . ' require_any',
        invoke_private($class, 'requirementSatisfied', [[
            'type'=>'require_any',
            'conditions'=>[
                ['lte'=>[['item'=>'days'],10]],
                ['gte'=>[['item'=>'service'],12]],
            ],
        ], $values]) === true
    );
    check($class . ' require_all true',
        invoke_private($class, 'requirementSatisfied', [[
            'type'=>'require_all',
            'conditions'=>[
                ['gte'=>[['item'=>'price'],90]],
                ['lte'=>[['item'=>'days'],30]],
            ],
        ], $values]) === true
    );
    check($class . ' require_all false',
        invoke_private($class, 'requirementSatisfied', [[
            'type'=>'require_all',
            'conditions'=>[
                ['gte'=>[['item'=>'price'],90]],
                ['lte'=>[['item'=>'days'],10]],
            ],
        ], $values]) === false
    );
    check($class . ' require_items complete',
        invoke_private($class, 'requirementSatisfied', [[
            'type'=>'require_items','items'=>['price','days','service']
        ], $values]) === true
    );
    check($class . ' require_items missing',
        invoke_private($class, 'requirementSatisfied', [[
            'type'=>'require_items','items'=>['price','missing']
        ], $values]) === false
    );
}

foreach ([RuleEngine::class, AgreementValidator::class, ZopaService::class] as $class) {
    check($class . ' condition all',
        invoke_private($class, 'evalCondition', [[
            'all'=>[
                ['gte'=>[['item'=>'price'],100]],
                ['lte'=>[['item'=>'days'],20]],
            ]
        ], $values]) === true
    );
    check($class . ' condition any',
        invoke_private($class, 'evalCondition', [[
            'any'=>[
                ['lt'=>[['item'=>'price'],50]],
                ['eq'=>[['item'=>'bonus'],5]],
            ]
        ], $values]) === true
    );
}

$seedFile = dirname(__DIR__) . '/content/system-v1.php';
$seed = require $seedFile;
check('seed returns array', is_array($seed));
check('content pack 1.50.0', ($seed['pack_version'] ?? '') === '1.50.0');

$scenarios = (array)($seed['scenarios'] ?? []);
$stubs = (array)($seed['scenario_stubs'] ?? []);
check('65 published scenarios', count($scenarios) === 65);
check('0 scenario stubs', count($stubs) === 0);

$formalServer = [
    'flag_breach'=>true,
    'require'=>true,
    'require_any'=>true,
    'require_all'=>true,
    'require_items'=>true,
    'block'=>true,
    'opponent_walkaway'=>true,
    'require_explicit_confirmation'=>true,
];
$opponentAi = [
    'opponent_pressure_more'=>true,
    'opponent_lock_in'=>true,
    'opponent_hold_price'=>true,
    'opponent_reject_or_counter'=>true,
];

$scenarioSlugs = [];
$serverCount = 0;
$aiCount = 0;

$collectRefs = function(mixed $node, array &$refs) use (&$collectRefs): void {
    if (!is_array($node)) { return; }
    if (isset($node['item']) && is_string($node['item'])) { $refs[$node['item']] = true; }
    if (isset($node['items']) && is_array($node['items'])) {
        foreach ($node['items'] as $code) {
            if (is_string($code) && $code !== '') { $refs[$code] = true; }
        }
    }
    foreach ($node as $child) { $collectRefs($child, $refs); }
};

foreach ($scenarios as $scenario) {
    $card = (array)($scenario['scenario'] ?? []);
    $version = (array)($scenario['version'] ?? []);
    $components = (array)($scenario['components'] ?? []);
    $slug = (string)($card['slug'] ?? '');
    $title = (string)($card['title'] ?? $slug);

    check($title . ' published scenario', ($card['status'] ?? '') === 'published');
    check($title . ' published version', ($version['status'] ?? '') === 'published');
    check($title . ' unique slug', $slug !== '' && !isset($scenarioSlugs[$slug]));
    $scenarioSlugs[$slug] = true;

    $items = (array)($components['items'] ?? []);
    $itemCodes = [];
    foreach ($items as $item) { $itemCodes[(string)($item['code'] ?? '')] = true; }

    $required = (array)(($version['mechanics_json']['required_items'] ?? []));
    foreach ($required as $code) {
        check($title . ' required item ' . $code, isset($itemCodes[$code]));
    }

    $weights = 0;
    foreach ((array)($components['evaluation_rules'] ?? []) as $criterion) {
        $weights += (float)($criterion['weight'] ?? 0);
    }
    check($title . ' evaluation weight 100', abs($weights - 100.0) < 0.0001);

    foreach ((array)($components['rules'] ?? []) as $rule) {
        $code = (string)($rule['code'] ?? '');
        $action = (array)($rule['action_json'] ?? []);
        $type = (string)($action['type'] ?? '');

        if (isset($formalServer[$type])) {
            $serverCount++;
        } elseif (isset($opponentAi[$type])) {
            $aiCount++;
            check($title . '/' . $code . ' AI rule description',
                trim((string)($action['description'] ?? '')) !== ''
            );
        } else {
            throw new RuntimeException("FAIL: {$title}/{$code} action has no executor: {$type}");
        }

        check($title . '/' . $code . ' no player-side pseudo action',
            $type !== 'player_reject_or_counter'
        );

        $refs = [];
        $collectRefs((array)($rule['condition_json'] ?? []), $refs);
        $collectRefs($action, $refs);
        foreach (array_keys($refs) as $ref) {
            check($title . '/' . $code . ' item ref ' . $ref, isset($itemCodes[$ref]));
        }

        if ($type === 'require_all') {
            check($title . '/' . $code . ' require_all conditions',
                !empty($action['conditions']) && is_array($action['conditions'])
            );
        }
        if ($type === 'require_items') {
            check($title . '/' . $code . ' require_items list',
                !empty($action['items']) && is_array($action['items'])
            );
        }
    }
}

check('all 65 slugs unique', count($scenarioSlugs) === 65);
check('formal server rules present', $serverCount > 0);
check('AI opponent rules present', $aiCount > 0);
check('expected server rule count', $serverCount === 313);
check('expected AI rule count', $aiCount === 5);

$prompt = file_get_contents(dirname(__DIR__) . '/ai/opponent/opponent-prompt-builder.php');
check('prompt receives scenario rules', str_contains($prompt, "'scenario_rules' =>"));
check('prompt handles numeric opponent reject/counter', str_contains($prompt, 'opponent_reject_or_counter'));
check('prompt contains no player_reject_or_counter', !str_contains($prompt, 'player_reject_or_counter'));

echo "{$passed} NEG-CATALOG-RUNTIME regression checks passed. No database required.\n";

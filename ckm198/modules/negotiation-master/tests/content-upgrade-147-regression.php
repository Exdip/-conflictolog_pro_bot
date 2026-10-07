<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');

$pack = require dirname(__DIR__) . '/content/system-v1.php';

$passed = 0;
function u147(string $name, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $passed++;
    echo "PASS: {$name}\n";
}

u147('pack includes 1.50.0 sales adapter content', ($pack['pack_version'] ?? '') === '1.50.0');

$target = null;
foreach ((array)($pack['scenarios'] ?? []) as $entry) {
    if (($entry['scenario']['slug'] ?? '') === 'price-increase-renewal') {
        $target = $entry;
        break;
    }
}
u147('price-increase-renewal exists', is_array($target));
u147('Цена выросла is published as version 2', (int)($target['version']['version_number'] ?? 0) === 2);
u147('target version is published', ($target['version']['status'] ?? '') === 'published');

$pr4 = null;
foreach ((array)($target['components']['rules'] ?? []) as $rule) {
    if (($rule['code'] ?? '') === 'PR4') { $pr4 = $rule; break; }
}
u147('PR4 exists', is_array($pr4));
u147('PR4 uses server block', ($pr4['action_json']['type'] ?? '') === 'block');
u147('old player pseudo action absent',
    json_encode($target, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) !== false
    && !str_contains(json_encode($target, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 'player_reject_or_counter')
);

$influence = array_values(array_filter((array)($pack['scenarios'] ?? []), static function(array $entry): bool {
    return ($entry['scenario']['library_slug'] ?? '') === 'share-of-influence';
}));
u147('share-of-influence contains 10 published scenarios', count($influence) === 10);
foreach ($influence as $entry) {
    u147('influence scenario published: ' . ($entry['scenario']['slug'] ?? ''),
        ($entry['scenario']['status'] ?? '') === 'published'
        && ($entry['version']['status'] ?? '') === 'published'
    );
}
u147('no scenario stubs remain', count((array)($pack['scenario_stubs'] ?? [])) === 0);

echo "{$passed} content-upgrade-1.47 regression checks passed.\n";

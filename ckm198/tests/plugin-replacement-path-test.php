<?php
require_once __DIR__ . '/support/plugin-release.php';
$root = dirname(__DIR__);
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$checks = [
    ['package root is self-contained', is_file($root . '/ckm-quiz-pro.php') && is_dir($root . '/includes')],
    ['main plugin file exists', is_file($root . '/ckm-quiz-pro.php')],
    ['current plugin version', ckm_test_current_plugin_release($main)],
];
$pass = 0;
foreach ($checks as [$label,$ok]) { echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $label . PHP_EOL; if ($ok) $pass++; }
echo $pass . '/' . count($checks) . ' PASS' . PHP_EOL;
exit($pass === count($checks) ? 0 : 1);

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s519(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$page = file_get_contents($root . '/modules/effective-sales/public/sales-page.php');
s519('plugin version', ckm_test_current_plugin_release($main));
s519('MAX channel visible', str_contains($page, '<b>MAX</b>'));
s519('MAX connection state honest', str_contains($page, "<b>MAX</b>") && str_contains($page, 'is_array($maxBinding)') && str_contains($page, "'Не назначен'"));
s519('Instagram absent from AI seller page', stripos($page, 'instagram') === false);
s519('existing Telegram channel retained', str_contains($page, '<b>Telegram</b>'));
s519('existing WhatsApp channel retained', str_contains($page, '<b>WhatsApp</b>'));
s519('browser voice retained', str_contains($page, '<b>Голос в браузере</b>'));
echo "{$passed} SALES-519 MAX channel checks passed. No database required.\n";

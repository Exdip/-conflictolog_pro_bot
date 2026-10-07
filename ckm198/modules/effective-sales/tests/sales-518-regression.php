<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s518(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$boot = file_get_contents($root . '/modules/effective-sales/bootstrap.php');
$workspace = file_get_contents($root . '/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$seller = file_get_contents($root . '/modules/effective-sales/application/sales-ai-seller-service.php');
$page = file_get_contents($root . '/modules/effective-sales/public/sales-page.php');
$css = file_get_contents($root . '/modules/effective-sales/assets/sales-app.css');

s518('plugin version', ckm_test_current_plugin_release($main));
s518('workspace service bootstrapped', str_contains($boot, 'sales-ai-seller-workspace-service.php'));
s518('safe URL knowledge import', str_contains($workspace, 'wp_safe_remote_get') && str_contains($workspace, 'wp_http_validate_url'));
s518('manual text knowledge supported', str_contains($workspace, 'addTextKnowledge'));
s518('plain-language corrections supported', str_contains($workspace, 'addCorrection'));
s518('seller prompt receives knowledge and corrections', str_contains($seller, 'SalesAiSellerWorkspaceService::promptContext($script)'));
s518('persistent dialogue logging wired', str_contains($seller, 'recordStart') && str_contains($seller, 'recordTurn') && str_contains($seller, 'recordEnd'));
s518('AI seller workspace actions exist', str_contains($page, "seller_add_url") && str_contains($page, "seller_add_text") && str_contains($page, "seller_add_correction") && str_contains($page, "seller_profile"));
s518('workspace exposes test channel and analytics', str_contains($page, 'Открыть тестовый диалог') && (str_contains($page, 'Последние разговоры') || str_contains($page, 'Лиды и разговоры')) && str_contains($page, 'Передано человеку'));
s518('channel readiness is honest', str_contains($page, 'PSTN / IP-телефония') && str_contains($page, 'Telegram') && str_contains($page, 'WhatsApp') && str_contains($page, 'MAX') && (str_contains($page, 'нужен SIP trunk') || str_contains($page, 'SIP trunk')));
s518('workspace CSS added', str_contains($css, '.ckm-sales-agent-grid') && str_contains($css, '.ckm-sales-channel-grid'));
s518('Pleep brand not exposed in product UI', !str_contains($page, 'PLEEP'));

echo "{$passed} SALES-518 regression checks passed. No database required.\n";

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s521(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
s521('plugin version',ckm_test_current_plugin_release($main));
s521('primary goal stored',str_contains($work,'ai_seller_primary_goal')&&str_contains($page,'seller_primary_goal'));
s521('goal is prompt context',str_contains($work,'ОСНОВНАЯ ЦЕЛЬ ПРОДАЖИ'));
s521('outcome labels',str_contains($work,'OUTCOME_LABELS')&&str_contains($work,"'stalled'=>'Застопорен'"));
s521('stalled timeout',str_contains($work,'STALLED_AFTER=86400'));
s521('conversion metric',str_contains($work,'conversion_rate')&&str_contains($page,'Конверсия в цель'));
s521('AI response metric',str_contains($work,'avg_ai_response_seconds')&&str_contains($page,'Ответ ИИ')&&str_contains($seller,'microtime(true)')&&str_contains($seller,'response_seconds'));
s521('operator response metric',str_contains($work,'avg_operator_response_seconds')&&str_contains($page,'Ответ оператора'));
s521('messages per dialog',str_contains($work,'avg_messages')&&str_contains($page,'Сообщений / диалог'));
s521('CSV export',str_contains($page,'seller_export_analytics')&&str_contains($page,'text/csv')&&str_contains($page,'Экспорт CSV'));
s521('inbox shows goal status',str_contains($page,"goal_status")&&str_contains($page,'outcomeLabels'));
s521('analytics responsive CSS',str_contains($css,'.ckm-sales-analytics-grid')&&str_contains($css,'.ckm-sales-goal-banner'));
echo "{$passed} SALES-521 analytics/goal checks passed. No database required.\n";

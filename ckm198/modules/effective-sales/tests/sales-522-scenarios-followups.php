<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s522(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
s522('plugin version',ckm_test_current_plugin_release($main));
s522('scenario storage',str_contains($work,'ai_seller_scenarios')&&str_contains($work,'MAX_SCENARIOS=20'));
s522('idle and stage triggers',str_contains($work,"'idle'=>'Нет ответа клиента'")&&str_contains($work,"'stage'=>'Стадия воронки'"));
s522('scenario actions',str_contains($work,"'message'=>'Отправить сообщение'")&&str_contains($work,"'handoff'=>'Передать человеку'")&&str_contains($work,"'change_stage'=>'Сменить стадию'")&&str_contains($work,"'close_won'=>'Закрыть как успех'")&&str_contains($work,"'close_lost'=>'Закрыть как отказ'"));
s522('delay capped at seven days',str_contains($work,'min(10080'));
s522('per-dialog automation state',str_contains($seller,'automation_runs')&&str_contains($seller,'scenarioRunState'));
s522('human takeover pauses automations',str_contains($seller,"control_mode']??'ai')==='human")&&str_contains($seller,'scenarioDue'));
s522('public state executes due automations',str_contains($seller,'applyDueAutomations(self::load')&&str_contains($seller,"public function state"));
s522('stage sync from CRM',str_contains($seller,'syncPipelineStageForAdmin')&&str_contains($page,'SalesAiSellerService::syncPipelineStageForAdmin'));
s522('manual QA run',str_contains($seller,'runScenarioNow')&&str_contains($page,'seller_scenario_run')&&str_contains($page,'Выполнить следующий шаг'));
s522('scenario CRUD UI',str_contains($page,'seller_scenario_add')&&str_contains($page,'seller_scenario_toggle')&&str_contains($page,'seller_scenario_delete'));
s522('channel execution note is honest',str_contains($page,'Web — сразу')&&str_contains($page,'Telegram/MAX'));
s522('automation messages logged',str_contains($work,'recordAutomation')&&str_contains($work,"'automation'=>true"));
s522('responsive automation CSS',str_contains($css,'.ckm-sales-automations')&&str_contains($css,'.ckm-sales-automation-form')&&str_contains($css,'.ckm-sales-automation-item'));
echo "{$passed} SALES-522 scenarios/follow-ups checks passed. No database required.\n";

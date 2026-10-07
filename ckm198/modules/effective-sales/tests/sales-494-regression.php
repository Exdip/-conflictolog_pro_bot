<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents(dirname(__DIR__).'/public/sales-page.php');
$api=file_get_contents(dirname(__DIR__).'/api/sales-controller.php');
$service=file_get_contents(dirname(__DIR__).'/application/sales-competition-service.php');
$js=file_get_contents(dirname(__DIR__).'/assets/sales-session.js');
$css=file_get_contents(dirname(__DIR__).'/assets/sales-app.css');
$bootstrap=file_get_contents(dirname(__DIR__).'/bootstrap.php');
$passed=0;
function s494(string $n,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$n);$passed++;echo "PASS: $n\n";}
s494('plugin version',ckm_test_current_plugin_release($main));
s494('home has exactly two primary format concepts',str_contains($page,'ДВА ОСНОВНЫХ ФОРМАТА')&&str_contains($page,'>Тренировка<')&&str_contains($page,'>Соревнование<'));
s494('competition supports two to six teams',str_contains($page,'for($i=2;$i<=6;$i++)')&&str_contains($service,'count($rawTeams) < 2 || count($rawTeams) > 6'));
s494('competition uses shared team assignment and exam mode',str_contains($service,"'assignment_mode'=>'team_shared'")&&str_contains($service,"'mode'=>'exam'"));
s494('coach button is not rendered for competition',str_contains($page,'if(!$competition&&!$assessment): ?><button class="ckm-sales-btn" id="ckm-sales-hint">Нужна подсказка</button>'));
s494('coach API hard blocks non-training mode',str_contains($api,'В соревновании подсказки ИИ-тренера отключены.'));
s494('snapshot forces coach disabled in competition',str_contains($api,'$snapshot[\'can_use_coach\'] = false'));
s494('competition result hidden until all teams complete',str_contains($service,'В соревновании итоговый разбор откроется после завершения всех команд.')&&str_contains($api,'resultGate($id)'));
s494('competition ranking exists',str_contains($page,'Общий рейтинг')&&str_contains($page,"['ranking_ready']"));
s494('participant competition launch stays in sales UI',str_contains($service,"'sales_format'=>'competition'")&&str_contains($service,'SalesPage::url'));
s494('competition routes registered',str_contains($api,"/sales/competitions")&&str_contains($api,'launchCompetition')&&str_contains($api,'closeCompetition'));
s494('competition UI JS creates and launches',str_contains($js,"api('competitions'")&&str_contains($js,"data-sales-launch-competition"));
s494('competition styles present',str_contains($css,'.ckm-sales-competition-banner')&&str_contains($css,'.ckm-sales-ranking'));
s494('competition service loaded',str_contains($bootstrap,"sales-competition-service.php"));
echo "$passed SALES-494 regression checks passed. No database required.\n";

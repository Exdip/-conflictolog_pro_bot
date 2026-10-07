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
$passed=0;
function s496(string $n,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$n);$passed++;echo "PASS: $n\n";}
s496('plugin version',ckm_test_current_plugin_release($main));
s496('competition create offers AI host first and human host second',str_contains($page,'id="ckm-sales-competition-host-mode"')&&strpos($page,'value="ai" selected>ИИ-ведущий')<strpos($page,'value="human">Ведущий-человек'));
s496('host mode is persisted without new database table',str_contains($service,"HOST_OPTION_PREFIX = 'ckm_sales_competition_host_'")&&str_contains($service,'initializeHostState($id,$hostMode)'));
s496('organizer gets central host screen link',str_contains($page,'Открыть экран ведущего')&&str_contains($service,"'sales_view'=>'competition-host'"));
s496('team link marks readiness and opens team waiting room',str_contains($service,"markReady((int)\$auth['assignment_id'],(int)\$auth['user_id'])")&&str_contains($page,'currentTeamAssignmentId'));
s496('AI host auto starts after all teams ready',str_contains($service,"\$state['host_mode']==='ai'")&&str_contains($service,"\$state['phase']='running'"));
s496('human host requires all teams ready',str_contains($service,"Сначала дождитесь готовности всех команд")&&str_contains($service,'startByHost'));
s496('team launch is blocked before host start',str_contains($service,'Соревнование ещё не запущено ведущим.'));
s496('waiting and host REST endpoints registered',str_contains($api,"/waiting")&&str_contains($api,"/host/start")&&str_contains($api,'competitionHost'));
s496('team waiting page auto polls and opens attempt after start',str_contains($js,"competitions/'+id+'/waiting")&&str_contains($js,"if(c.can_launch)")&&str_contains($js,"competitions/'+id+'/launch"));
s496('host screen polls team state',str_contains($js,"competitions/'+id+'/host")&&str_contains($js,'ckm-sales-host-team-list'));
s496('human host start button calls server',str_contains($js,"competitions/'+id+'/host/start")&&str_contains($page,'Начать соревнование'));
s496('competition still hides coach',str_contains($page,'Подсказки ИИ-тренера отключены')&&str_contains($api,'В соревновании подсказки ИИ-тренера отключены.'));
s496('host screen exposes readiness completion and ranking',str_contains($page,'готовы')&&str_contains($page,'завершили')&&str_contains($page,'Общий рейтинг'));
s496('host styling exists',str_contains($css,'.ckm-sales-host-panel')&&str_contains($css,'.ckm-sales-team-waiting')&&str_contains($css,'.ckm-sales-host-team'));
echo "$passed SALES-496 regression checks passed. No database required.\n";

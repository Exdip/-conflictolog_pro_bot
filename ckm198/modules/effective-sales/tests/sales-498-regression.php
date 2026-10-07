<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$service=file_get_contents(dirname(__DIR__).'/application/sales-competition-service.php');
$page=file_get_contents(dirname(__DIR__).'/public/sales-page.php');
$app=file_get_contents(dirname(__DIR__).'/public/app-template.php');
$shell=file_get_contents(dirname(__DIR__).'/public/app-shell.php');
$api=file_get_contents(dirname(__DIR__).'/api/sales-controller.php');
$js=file_get_contents(dirname(__DIR__).'/assets/sales-session.js');
$bootstrap=file_get_contents(dirname(__DIR__).'/bootstrap.php');
$passed=0;
function s498(string $n,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$n);$passed++;echo "PASS: $n\n";}
s498('plugin version',ckm_test_current_plugin_release($main));
s498('team access no longer writes WordPress auth cookie',!str_contains($service,'wp_set_auth_cookie')&&str_contains($service,'authenticateRequest'));
s498('signed team URL authenticates only current request',str_contains($service,'requestedTeamAuth')&&str_contains($service,'wp_set_current_user((int)$auth[\'user_id\'])'));
s498('REST permission can authenticate signed team request',str_contains($api,'SalesCompetitionService::authenticateRequest()'));
s498('team token is propagated to REST and page links',str_contains($shell,"'teamJoin'")&&str_contains($js,'withTeamAuth')&&str_contains($js,"sales_team_join"));
s498('bare team link opens team waiting room',str_contains($page,'currentTeamAssignmentId()')&&str_contains($page,'renderCompetitionWaiting'));
s498('team name is visible in team header and competition banner',str_contains($app,'Команда: ')&&str_contains($page,"\$teamName!==''?\$teamName:'Команда'"));
s498('organizer competition creation uses normal browser POST',str_contains($page,'data-native-submit="1"')&&str_contains($page,'handleRequest(): void')&&str_contains($bootstrap,"template_redirect"));
s498('competition JS does not intercept native create form',str_contains($js,"competitionForm.dataset.nativeSubmit!=='1'"));
s498('network failures are shown in Russian',str_contains($js,'Не удалось связаться с сервером. Повторите попытку через несколько секунд.')&&!str_contains($js,'Failed to fetch'));
echo "$passed SALES-498 regression checks passed. No database required.\n";

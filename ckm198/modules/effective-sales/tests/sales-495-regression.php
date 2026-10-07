<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents(dirname(__DIR__).'/public/sales-page.php');
$service=file_get_contents(dirname(__DIR__).'/application/sales-competition-service.php');
$js=file_get_contents(dirname(__DIR__).'/assets/sales-session.js');
$bootstrap=file_get_contents(dirname(__DIR__).'/bootstrap.php');
$passed=0;
function s495(string $n,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$n);$passed++;echo "PASS: $n\n";}
s495('plugin version',ckm_test_current_plugin_release($main));
s495('competition form does not ask login email or representative',!str_contains($page,'ckm-sales-team-user')&&!str_contains($page,'Логин, email или ID представителя')&&!str_contains($page,'аккаунт-представитель'));
s495('competition create payload only sends team name',str_contains($js,"teams.push({team_name:")&&!str_contains($js,"identifier:(row.querySelector('.ckm-sales-team-user')"));
s495('internal team users are created automatically',str_contains($service,'createTeamUser')&&str_contains($service,"'role'=>'subscriber'")&&str_contains($service,'wp_generate_password'));
s495('team links use signed bearer token',str_contains($service,'teamSignature')&&str_contains($service,'hash_hmac')&&str_contains($service,"'sales_team_sig'"));
s495('team link stateless access is registered after tenant scope',str_contains($bootstrap,"maybeJoinFromLink'], 20")&&str_contains($service,'authenticateRequest')&&!str_contains($service,'wp_set_auth_cookie'));
s495('team link authenticates current request without login cookie and marks readiness',str_contains($service,'wp_set_current_user')&&!str_contains($service,'wp_set_auth_cookie')&&str_contains($service,"markReady((int)\$auth['assignment_id'],(int)\$auth['user_id'])"));
s495('organizer detail shows team links',str_contains($page,'Ссылки команд')&&str_contains($page,'data-sales-copy-link'));
s495('copy link handler exists',str_contains($js,'navigator.clipboard.writeText(link)')&&str_contains($js,"btn.textContent='Скопировано'"));
s495('team link access requires active shared exam assignment',str_contains($service,"a.assignment_mode='team_shared'")&&str_contains($service,"a.mode='exam'")&&str_contains($service,"a.status='active'"));
echo "$passed SALES-495 regression checks passed. No database required.\n";

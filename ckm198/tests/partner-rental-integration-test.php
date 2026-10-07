<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$loader=file_get_contents($root.'/includes/partner/loader.php');
$usage=file_get_contents($root.'/includes/partner/usage.php');
$access=file_get_contents($root.'/includes/partner/access.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$schema=file_get_contents($root.'/includes/partner/schema.php');
$n=0;
function pr270($ok,$label){global $n;$n++;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "OK: $label\n";}
pr270(ckm_test_current_plugin_release($main),'version marker');
pr270(str_contains($main,"includes/partner/loader.php"),'integrated loader');
pr270(str_contains($main,'ckm_quiz_pro_partner_install_schema'),'activation installs partner schema');
pr270(str_contains($schema,"ckmqp_partner_subscriptions") || str_contains($schema,"'subscriptions'"),'subscription schema');
pr270(str_contains($schema,"'usage'") && str_contains($schema,"'grants'"),'usage and grants schema');
pr270(str_contains($loader,'ckmqp_partner_v3_activate') && str_contains($loader,'CKMPR_VERSION'),'legacy/standalone guard');
pr270(str_contains($usage,"base_negotiation_sales") && str_contains($usage,"base_negotiation_business") && str_contains($usage,"base_negotiation_express") && str_contains($usage,"base_persuade_me"),'four negotiation games have distinct usage keys');
pr270(str_contains($usage,"!empty(\$game['test_mode'])"),'test mode excluded');
pr270(str_contains($usage,'$tenantId === 0') && str_contains($usage,"user_can(\$userId, 'manage_options')"),'admin exemption restricted to main platform');
pr270(str_contains($access,'INNER JOIN') && str_contains($access,"m.role='owner'") && str_contains($access,"m.status='active'"),'real owner validation');
pr270(str_contains($access,"ckm_quiz_pro_partner_grant_base_access") && str_contains($access,"status' => 'inactive'"),'tracked grant/revoke');
pr270(str_contains($front,'Общий лимит всех организаторов'),'child organizer sees shared pool');
pr270(!str_contains($loader,'/smoke') && !str_contains($loader,'member-smoke'),'no temporary smoke routes');
echo "ALL $n PASS\n";

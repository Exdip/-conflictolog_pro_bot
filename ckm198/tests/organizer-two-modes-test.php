<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$plans=file_get_contents($root.'/includes/partner/plans.php');
$admin=file_get_contents($root.'/includes/partner/admin.php');
$access=file_get_contents($root.'/includes/partner/access.php');
$c=[];
function tm274(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $name\n";}
tm274($c,ckm_test_current_plugin_release($main),'version marker');
tm274($c,str_contains($org,"\$_GET['view'] ?? 'games'"),'cabinet defaults to base games');
tm274($c,str_contains($org,"if (\$view === 'mode') ckm_quiz_pro_org_mode();"),'explicit mode remains available');
tm274($c,str_contains($org,"ckm_quiz_pro_org_nav_link('mode','Выбор режима'"),'mode nav entry');
tm274($c,str_contains($org,"function ckm_quiz_pro_org_mode(): void"),'mode renderer exists');
tm274($c,str_contains($org,'Покупать игры отдельно') && str_contains($org,'Партнёрский режим'),'two organizer mode cards');
tm274($c,str_contains($org,'15)) . \' реальных запусков') || str_contains($org,"get_option('ckmqp_organizer_monthly_session_limit',15)"),'ordinary mode uses configured per-game limit');
tm274($c,str_contains($org,'все 8 базовых игр') || str_contains($org,'все 8 базовых'),'rental explains eight base games');
tm274($c,str_contains($plans,'function ckm_quiz_pro_partner_save_request'),'partner plan request persistence');
tm274($c,str_contains($front,"action === 'request_plan'"),'front rental request action');
tm274($c,str_contains($front,'Выбрать партнёрский тариф') || str_contains($front,"request_plan"),'inactive rental shows plan chooser');
tm274($c,str_contains($admin,'Запрошен партнёрский режим:') && str_contains($admin,'$selectedPlan'),'admin sees/preselects requested plan');
tm274($c,str_contains($access,'ckm_quiz_pro_partner_clear_request($tenantId)'),'activation clears pending request');
tm274($c,str_contains($org,'Готовые платные сценарии') || str_contains($org,'Готовые платные сценарии и игры под заказ'),'scenario library remains separate');
tm274($c,str_contains($org,'не создаёт новый аккаунт') && str_contains($org,'не меняет поддомен'),'same tenant/account explanation');
$fail=count(array_filter($c,fn($x)=>!$x));
echo 'TOTAL '.count($c).' FAIL '.$fail."\n";
exit($fail?1:0);

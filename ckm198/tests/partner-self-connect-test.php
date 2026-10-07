<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$access=file_get_contents($root.'/includes/partner/access.php');
$plans=file_get_contents($root.'/includes/partner/plans.php');
$schema=file_get_contents($root.'/includes/partner/schema.php');
$checkout=file_get_contents($root.'/includes/partner/plan-checkout.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$c=[];
function psc303(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $name\n";}
psc303($c,ckm_test_current_plugin_release($main),'version marker');
psc303($c,str_contains($access,'function ckm_quiz_pro_partner_user_can_activate') && str_contains($access,"m.role='organizer'"),'connected organizer can activate');
psc303($c,str_contains($plans,'ckm_quiz_pro_partner_user_can_activate') && !str_contains($plans,'может только владелец площадки'),'plan request is not owner-only');
psc303($c,str_contains($schema,"CKM_QUIZ_PRO_PARTNER_SCHEMA_VERSION = '1.5.0'") && str_contains($schema,'payer_user_id'),'payer column added');
psc303($c,str_contains($checkout, "'payer_user_id'=>\$payerUserId") && str_contains($checkout,'ckm_quiz_pro_partner_user_can_activate'),'checkout accepts connected organizer');
psc303($c,str_contains($checkout,"\$payerId = (int)(\$order['payer_user_id'] ?? 0)"),'payment uses payer identity');
psc303($c,str_contains($checkout,'$payerId !== $userId'),'return is bound to payer');
psc303($c,str_contains($front,'$canPlan =') && str_contains($front,'Партнёрский кабинет доступен владельцу площадки и подключённым организаторам.'),'front route accepts connected organizer');
psc303($c,str_contains($front, 'ckm_quiz_pro_partner_tbank_render_owner_section') && !str_contains($front, 'ckm_quiz_pro_partner_yk_render_owner_section'),'T-Bank partner payment settings remain owner-only');
psc303($c,str_contains($front, 'if ($isOwner) {') && str_contains($front,'Добавлять и отключать организаторов может только владелец площадки.'),'member management remains owner-only');
psc303($c,str_contains($org,'$canPartnerPlan') && str_contains($org,'Выбрать партнёрский тариф'),'mode button available to eligible partner');
$fail=count(array_filter($c,fn($x)=>!$x));
echo 'TOTAL '.count($c).' FAIL '.$fail."\n";
exit($fail?1:0);

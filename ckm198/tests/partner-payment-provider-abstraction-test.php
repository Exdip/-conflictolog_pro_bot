<?php
$root=dirname(__DIR__);
$provider=file_get_contents($root.'/includes/partner/payment-provider.php');
$loader=file_get_contents($root.'/includes/partner/loader.php');
$pay=file_get_contents($root.'/includes/partner/payments.php');
$c=[];
function ppa(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $name\n";}
ppa($c,str_contains($provider,'CKM_Quiz_Pro_Partner_Payment_Provider_Interface'),'provider interface exists');
ppa($c,str_contains($provider,"'yookassa' => new CKM_Quiz_Pro_Partner_YooKassa_Provider()"),'YooKassa registered by provider key');
ppa($c,str_contains($provider,'create_order') && str_contains($provider,'create_payment') && str_contains($provider,'sync_order'),'provider contract covers order payment and sync');
ppa($c,str_contains($provider,'ckm_quiz_pro_partner_yk_create_payment') && str_contains($provider,'ckm_quiz_pro_partner_yk_sync_order'),'YooKassa adapter delegates to existing implementation');
ppa($c,str_contains($loader,"require_once __DIR__ . '/payment-provider.php';"),'provider layer loaded before payment module');
ppa($c,!str_contains($pay,'class CKM_Quiz_Pro_Partner_YooKassa_Provider'),'YooKassa implementation remains in legacy payment module, adapter-only change');
$fail=count(array_filter($c,fn($x)=>!$x));
echo 'TOTAL '.count($c).' FAIL '.$fail."\n";
exit($fail?1:0);

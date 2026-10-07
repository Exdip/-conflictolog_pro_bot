<?php
$root=dirname(__DIR__);
$schema=file_get_contents($root.'/includes/partner/schema.php');
$tbank=file_get_contents($root.'/includes/partner/tbank-provider.php');
$pay=file_get_contents($root.'/includes/partner/payments.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$plan=file_get_contents($root.'/includes/partner/plan-checkout.php');
$loader=file_get_contents($root.'/includes/partner/loader.php');
$c=[];
function ptr316(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $name\n";}
ptr316($c,str_contains($schema,"const CKM_QUIZ_PRO_PARTNER_SCHEMA_VERSION = '1.5.0';"),'partner schema bumped for provider fields');
ptr316($c,str_contains($schema,'provider varchar(32) NOT NULL DEFAULT \'yookassa\'') && str_contains($schema,'tbank_payment_id varchar(80)'), 'payment order stores provider and T-Bank payment id');
ptr316($c,str_contains($pay,"'provider' => 'tbank'") && str_contains($pay,"'tbank_payment_id' => ''"),'new partner client orders are T-Bank orders');
ptr316($c,str_contains($pay,'ckm_quiz_pro_partner_tbank_create_payment') && str_contains($pay,'ckm_quiz_pro_partner_tbank_sync_order'),'partner client checkout uses T-Bank create/sync');
ptr316($c,str_contains($pay,"Перейти к оплате в Т‑Банк") && str_contains($pay,"CKM Partner T-Bank"),'public partner payment page is T-Bank');
ptr316($c,str_contains($tbank,'ckm_quiz_pro_partner_tbank_sync_webhook_payload'),'T-Bank webhook reaches order reconciliation');
ptr316($c,str_contains($tbank,"'Language' => 'ru'") && !str_contains($tbank,"'PayType' => 'O'"),'T-Bank Init does not force one-stage PayType');
ptr316($c,str_contains($front,"'create_payment_request', 'save_tbank', 'test_tbank'") && !str_contains($front,'ckm_quiz_pro_partner_yk_render_owner_section'),'partner cabinet exposes T-Bank instead of partner YooKassa');
ptr316($c,str_contains($plan,'ckm_quiz_pro_partner_platform_yk_api') && str_contains($plan,'yookassa_payment_id'),'platform partner-plan payments remain YooKassa');
ptr316($c,str_contains($loader,"ckm_quiz_pro_partner_tbank_public_route") && str_contains($loader,"ckm_quiz_pro_partner_yk_public_route"),'T-Bank public route runs before legacy YooKassa compatibility route');
$fail=count(array_filter($c,fn($x)=>!$x));
echo 'TOTAL '.count($c).' FAIL '.$fail."\n";
exit($fail?1:0);

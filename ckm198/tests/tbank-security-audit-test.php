<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$tbank=file_get_contents($root.'/includes/partner/tbank-provider.php');
$loader=file_get_contents($root.'/includes/partner/loader.php');
$c=[];
function tba315(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $name\n";}
tba315($c,ckm_test_current_plugin_release($main),'version marker');
tba315($c,str_contains($tbank,'aes-256-gcm') && str_contains($tbank,"wp_salt('auth')"),'T-Bank credentials use authenticated encryption');
tba315($c,str_contains($tbank,'hash_equals(') && str_contains($tbank,'$expected') && str_contains($tbank,'$given'),'T-Bank notification token uses constant-time comparison');
tba315($c,str_contains($tbank,'preg_match(') && str_contains($tbank,'/^[a-f0-9]{64}$/D'),'T-Bank webhook bearer token is high-entropy hex');
tba315($c,str_contains($tbank,"'https://securepay.tinkoff.ru/v2/'"),'T-Bank uses official acquiring API endpoint');
tba315($c,!str_contains($tbank,"'Idempotence-Key'"),'T-Bank provider does not claim unsupported idempotency header');
tba315($c,str_contains($tbank,'NotificationURL'),'T-Bank sends notification URL during Init');
tba315($c,str_contains($tbank,"return new WP_REST_Response('OK', 200)"),'T-Bank webhook returns exact OK response after validation');
tba315($c,str_contains($loader,'ckm_quiz_pro_partner_tbank_register_routes'),'T-Bank webhook route is registered');
tba315($c,str_contains(file_get_contents($root.'/includes/partner/payments.php'),'$remoteTerminalKey') && str_contains(file_get_contents($root.'/includes/partner/payments.php'),'amount_mismatch'),'GetState result validates TerminalKey and exact amount');
tba315($c,str_contains(file_get_contents($root.'/includes/partner/payments.php'), '!array_key_exists(\'Success\', $remote) || $remote[\'Success\'] !== true'),'GetState result requires explicit Success=true');
$fail=count(array_filter($c,fn($x)=>!$x));
echo 'TOTAL '.count($c).' FAIL '.$fail."\n";
exit($fail?1:0);

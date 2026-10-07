<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s528(string $name,bool $ok): void { global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$tel=file_get_contents($root.'/modules/effective-sales/application/sales-telephony-service.php');

s528('plugin version',ckm_test_current_plugin_release($main));
s528('telephony service bootstrapped',str_contains($boot,'sales-telephony-service.php'));
s528('provider registry includes mango uis custom',str_contains($tel,"'mango'=>")&&str_contains($tel,"'uis'=>")&&str_contains($tel,"'custom'=>"));
s528('MANGO official API base',str_contains($tel,"https://app.mango-office.ru"));
s528('MANGO signature follows api_key json salt',str_contains($tel,"hash('sha256',\$apiKey.\$json.\$apiSalt)"));
s528('MANGO read-only credential probe',str_contains($tel,"/vpbx/config/users/request")&&str_contains($tel,"['extension'=>\$extension]"));
s528('MANGO callback primitive prepared',str_contains($tel,"/vpbx/commands/callback")&&str_contains($tel,"'to_number'=>\$contact"));
s528('UIS official Call API v4',str_contains($tel,'https://callapi.uiscom.ru/v4.0'));
s528('UIS uses JSON-RPC 2.0',str_contains($tel,"'jsonrpc'=>'2.0'")&&str_contains($tel,"login.user")&&str_contains($tel,"logout.user"));
s528('UIS start simple call primitive prepared',str_contains($tel,"start.simple_call")&&str_contains($tel,"'virtual_phone_number'=>")&&str_contains($tel,"'operator'=>\$operator"));
s528('custom provider requires https',str_contains($tel,"Для внешнего API укажите HTTPS-адрес")&&str_contains($tel,'normalizeHttps'));
s528('telephony secrets remain tenant scoped',str_contains($hub,'createVerifiedTelephony')&&str_contains($hub,"createConfigured('sip'"));
s528('telephony hint is adapter aware',str_contains($hub,"\$adapter==='mango'")&&str_contains($hub,"\$adapter==='uis'"));
s528('MANGO partner form exists',str_contains($page,'MANGO OFFICE продаж')&&str_contains($page,'mango_api_key')&&str_contains($page,'mango_api_salt')&&str_contains($page,'mango_extension'));
s528('UIS partner form exists',str_contains($page,'UIS продаж')&&str_contains($page,'uis_login')&&str_contains($page,'uis_password')&&str_contains($page,'uis_virtual_number'));
s528('custom partner form exists',str_contains($page,'Свой SIP / API')&&str_contains($page,'sip_server')&&str_contains($page,'sip_password'));
s528('telephony connect action uses adapter service',str_contains($page,'integration_telephony_connect')&&str_contains($page,'SalesTelephonyService())->connectPartnerIntegration'));
s528('saved telephony can be tested',str_contains($page,'integration_telephony_test')&&str_contains($page,'testPartnerIntegration'));
s528('saved telephony deletes through service',str_contains($page,"\$provider==='sip'")&&str_contains($page,'SalesTelephonyService())->disconnectPartnerIntegration'));
s528('telephony can be assigned to agent',str_contains($page,'$availableSip')&&str_contains($page,'$sipBinding')&&str_contains($page,'Назначить телефонию агенту'));
s528('agent channel explains Sergey SIP path',str_contains($page,'API подключён · нужен SIP trunk')&&str_contains($page,'CKM Media Gateway')&&str_contains($page,'Сергей'));
s528('UI does not falsely call telephony fully working',!str_contains($page,'IP-ТЕЛЕФОНИЯ · РАБОТАЕТ'));
s528('working messenger channels remain intact',str_contains($page,'TELEGRAM · РАБОТАЕТ')&&str_contains($page,'MAX · РАБОТАЕТ')&&str_contains($page,'WHATSAPP · РАБОТАЕТ'));
s528('legacy integration_save_sip remains accepted',str_contains($page,"integration_save_sip"));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
if(!function_exists('sanitize_key')){function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}}
if(!function_exists('esc_url_raw')){function esc_url_raw($v){return filter_var((string)$v,FILTER_SANITIZE_URL);}}
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}}
require_once $root.'/modules/effective-sales/application/sales-telephony-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesTelephonyService');
$phone=$ref->getMethod('normalizePhone');$phone->setAccessible(true);
s528('runtime phone normalizer',$phone->invoke(null,'+7 (495) 123-45-67',false)==='74951234567');
$bad=false;try{$phone->invoke(null,'12-34',false);}catch(Throwable){$bad=true;}s528('runtime phone validator rejects short number',$bad);
$adapter=$ref->getMethod('normalizeAdapter');$adapter->setAccessible(true);
s528('runtime provider validator',$adapter->invoke(null,'mango')==='mango');
$bad=false;try{$adapter->invoke(null,'unknown');}catch(Throwable){$bad=true;}s528('runtime provider validator rejects unknown',$bad);
$calls=[];$svc=$ref->newInstance(function($provider,$method,$path,$payload)use(&$calls){$calls[]=[$provider,$method,$path,$payload];if($provider==='mango')return ['result'=>1000,'users'=>[]];if(($payload['method']??'')==='login.user')return ['result'=>['data'=>['access_token'=>'abc123','expire_at'=>9999999999]]];return ['result'=>['data'=>['success'=>true]]];});
$mango=$ref->getMethod('mangoApi');$mango->setAccessible(true);$out=$mango->invoke($svc,'mango-key','mango-salt','/vpbx/config/users/request',['extension'=>'101']);
s528('runtime MANGO transport carries signed form',count($calls)===1&&$calls[0][0]==='mango'&&$calls[0][2]==='/vpbx/config/users/request'&&isset($calls[0][3]['sign'],$calls[0][3]['json'])&&($out['result']??0)===1000);
$uis=$ref->getMethod('uisRpc');$uis->setAccessible(true);$out=$uis->invoke($svc,'login.user',['login'=>'demo','password'=>'secret']);
s528('runtime UIS transport carries JSON-RPC',count($calls)===2&&$calls[1][0]==='uis'&&($calls[1][3]['jsonrpc']??'')==='2.0'&&($calls[1][3]['method']??'')==='login.user'&&($out['result']['data']['access_token']??'')==='abc123');

echo "{$passed} SALES-528 telephony adapter core checks passed. No database required.\n";

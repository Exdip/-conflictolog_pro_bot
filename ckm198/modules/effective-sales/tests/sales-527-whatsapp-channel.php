<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s527(string $name,bool $ok): void { global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$controller=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$wa=file_get_contents($root.'/modules/effective-sales/application/sales-whatsapp-service.php');

s527('plugin version',ckm_test_current_plugin_release($main));
s527('WhatsApp service bootstrapped',str_contains($boot,'sales-whatsapp-service.php'));
s527('raw verification responder registered',str_contains($boot,'rest_pre_serve_request')&&str_contains($boot,'serveRawChallenge'));
s527('Graph API latest v26',str_contains($wa,"GRAPH_VERSION='v26.0'")&&str_contains($wa,"https://graph.facebook.com/"));
s527('Bearer authorization used',str_contains($wa,"'Authorization'=>'Bearer '.\$token"));
s527('phone number is verified by API',str_contains($wa,"'GET','/'.\$phone")&&str_contains($wa,"display_phone_number,verified_name,quality_rating"));
s527('WABA baseline subscription',str_contains($wa,"'POST','/'.\$waba.'/subscribed_apps',[],[]"));
s527('WABA callback override',str_contains($wa,"'override_callback_uri'=>\$url")&&str_contains($wa,"'verify_token'=>\$verify"));
s527('local webhook row exists before override verification',strpos($wa,'createVerifiedWhatsApp')<strpos($wa,"'override_callback_uri'=>\$url"));
s527('tenant hub creates verified WhatsApp integration',str_contains($hub,'createVerifiedWhatsApp')&&str_contains($wa,"rowByProviderWebhook('whatsapp'"));
s527('WhatsApp webhook supports GET and POST',str_contains($controller,"/sales/whatsapp/webhook/(?P<hook>")&&str_contains($controller,"['GET','POST']")&&str_contains($controller,'whatsappWebhook'));
s527('GET verification checks hub challenge token',str_contains($wa,'hub.verify_token')&&str_contains($wa,'hub.challenge')&&str_contains($wa,'webhookSecretForRow'));
s527('GET verification served as raw text',str_contains($wa,'X-CKM-WhatsApp-Challenge')&&str_contains($wa,"Content-Type: text/plain"));
s527('POST validates Meta signature',str_contains($wa,'HTTP_X_HUB_SIGNATURE_256')&&str_contains($wa,"hash_hmac('sha256',\$raw,\$secret)")&&str_contains($wa,"'sha256='."));
s527('webhook filters WhatsApp business payload',str_contains($wa,"'whatsapp_business_account'")&&str_contains($wa,"'field']??'')!=='messages'"));
s527('webhook routes only configured phone number',str_contains($wa,"metadata['phone_number_id']")||str_contains($wa,"\$metadata['phone_number_id']"));
s527('incoming message dedupe',str_contains($wa,'ckm_sales_wa_update_')&&str_contains($wa,'get_transient($dedupe)')&&str_contains($wa,'set_transient($dedupe,1,self::UPDATE_TTL)'));
s527('incoming WhatsApp reuses AI seller brain',str_contains($wa,"externalMessage(\$sellerToken,'whatsapp'")&&str_contains($seller,"['telegram','max','whatsapp','phone']"));
s527('WhatsApp session reaches shared inbox',str_contains($work,"['web','telegram','max','whatsapp','phone']")&&str_contains($work,"['telegram','max','whatsapp','phone']"));
s527('operator messages route to WhatsApp',str_contains($seller,"\$channel==='whatsapp'")&&str_contains($seller,'SalesWhatsAppService())->sendForSession($session,$text)'));
s527('automation messages route to WhatsApp',str_contains($seller,'SalesWhatsAppService())->sendForSession($session,$message)')&&str_contains($seller,'executeScenarioStep'));
s527('outbound text uses Phone Number ID messages endpoint',str_contains($wa,"'/'.\$phone.'/messages'")&&str_contains($wa,"'messaging_product'=>'whatsapp'")&&str_contains($wa,"'recipient_type'=>'individual'"));
s527('WhatsApp connector UI is real',str_contains($page,'WHATSAPP · РАБОТАЕТ')&&str_contains($page,'Подключить WhatsApp')&&str_contains($page,'wa_app_secret'));
s527('legacy save action upgraded not removed',str_contains($page,"integration_save_whatsapp")&&str_contains($page,"integration_whatsapp_connect"));
s527('saved WhatsApp can be tested and disconnected',str_contains($page,'integration_whatsapp_test')&&str_contains($page,'disconnectPartnerIntegration($integrationId)'));
s527('WhatsApp can be assigned to agent',str_contains($page,'$availableWhatsApp')&&str_contains($page,'$whatsappBinding')&&str_contains($page,'Подключить WhatsApp к этому агенту'));
s527('WhatsApp shown in inbox channel label',str_contains($page,"\$dialogChannel==='whatsapp'?'WhatsApp'"));
s527('WhatsApp counted as working channel',str_contains($page,'$whatsappCount')&&str_contains($page,"\$channels[]='WhatsApp'"));
s527('automation note includes WhatsApp',str_contains($page,'Telegram/MAX/WhatsApp'));
s527('WhatsApp coexists with telephony adapter layer',str_contains($page,'IP-ТЕЛЕФОНИЯ · АДАПТЕРЫ ГОТОВЫ'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
require_once $root.'/modules/effective-sales/application/sales-whatsapp-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesWhatsAppService');
$id=$ref->getMethod('normalizeId');$id->setAccessible(true);
s527('runtime numeric ID validator',$id->invoke(null,'123456789012345','Phone Number ID')==='123456789012345');
$bad=false;try{$id->invoke(null,'12abc','Phone Number ID');}catch(Throwable){$bad=true;}s527('runtime ID validator rejects invalid ID',$bad);
$tok=$ref->getMethod('normalizeToken');$tok->setAccessible(true);$token='EAAG'.str_repeat('a',80);s527('runtime access token validator',$tok->invoke(null,$token)===$token);
$secret=$ref->getMethod('normalizeAppSecret');$secret->setAccessible(true);$appSecret=str_repeat('a',32);s527('runtime app secret validator',$secret->invoke(null,$appSecret)===$appSecret);
$calls=[];$svc=$ref->newInstance(function($method,$path,$query,$body,$access,$version)use(&$calls){$calls[]=[$method,$path,$query,$body,$access,$version];return ['id'=>'123','display_phone_number'=>'+7 999 000-00-00','verified_name'=>'CKM'];});
$api=$ref->getMethod('api');$api->setAccessible(true);$out=$api->invoke($svc,$token,'GET','/123',['fields'=>'id'],[]);
s527('runtime transport carries graph version and bearer token',count($calls)===1&&$calls[0][0]==='GET'&&$calls[0][1]==='/123'&&$calls[0][4]===$token&&$calls[0][5]==='v26.0'&&($out['id']??'')==='123');
echo "{$passed} SALES-527 WhatsApp channel checks passed. No database required.\n";

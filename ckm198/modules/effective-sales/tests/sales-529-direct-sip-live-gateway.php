<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s529(string $name,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n";}
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$controller=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$tel=file_get_contents($root.'/modules/effective-sales/application/sales-telephony-service.php');
$live=file_get_contents($root.'/modules/effective-sales/application/sales-live-sip-service.php');
$ai=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');

s529('plugin version',ckm_test_current_plugin_release($main));
s529('live SIP service bootstrapped before telephony',strpos($boot,'sales-live-sip-service.php')!==false&&strpos($boot,'sales-live-sip-service.php')<strpos($boot,'sales-telephony-service.php'));
s529('Direct SIP adapter retained and rerouted to Sergey',str_contains($tel,"'direct_sip'=>")&&str_contains($tel,'SIP/PSTN → CKM Media Gateway → Сергей'));
s529('Direct SIP requires TLS URI',str_contains($tel,'^sips:')&&str_contains($tel,'использовать TLS'));
s529('Direct SIP rejects local/private endpoints',str_contains($tel,"\$lower==='localhost'")&&str_contains($tel,'FILTER_FLAG_NO_PRIV_RANGE'));
s529('partner SIP credentials remain tenant scoped',str_contains($tel,"createConfigured('sip'")&&str_contains($hub,'credentials_cipher'));
s529('Direct SIP inbound DID stored for routing',str_contains($tel,"'inbound_did'=>")&&str_contains($tel,"'account_username'=>\$inbound"));
s529('Direct SIP DID globally unambiguous',str_contains($tel,"direct-sip-did:")&&str_contains($hub,'assignedSipByInboundNumber'));
s529('legacy OpenAI platform service retained for rollback',str_contains($live,"current_user_can('manage_options')")&&str_contains($live,'ckm_sales_live_sip_platform_v1'));
s529('platform secrets encrypted AES-256-GCM',str_contains($live,"aes-256-gcm")&&str_contains($live,'secret_cipher'));
s529('active partner UI no longer receives OpenAI platform credentials',!str_contains($page,'name="openai_api_key"')&&!str_contains($page,'name="openai_project_id"')&&!str_contains($page,'name="openai_webhook_secret"'));
s529('active platform UI uses phone gateway secret instead',str_contains($page,'phone_gateway_secret')&&str_contains($page,'Телефонный ИИ-продавец · Сергей'));
s529('global and EU SIP endpoints supported',str_contains($live,'sip.api.openai.com')&&str_contains($live,'sip-eu.api.openai.com'));
s529('inbound webhook route public',str_contains($controller,"/sales/live-sip/webhook")&&str_contains($controller,'liveSipWebhook'));
s529('OpenAI webhook signature headers used',str_contains($live,'webhook-id')&&str_contains($live,'webhook-timestamp')&&str_contains($live,'webhook-signature'));
s529('Standard Webhooks HMAC covers id timestamp raw body',str_contains($live,"\$id.'.'.\$timestamp.'.'.\$raw")&&str_contains($live,"hash_hmac('sha256'"));
s529('webhook timestamp tolerance enforced',str_contains($live,'WEBHOOK_TOLERANCE=300'));
s529('duplicate webhook protection exists',str_contains($live,'ckm_sales_live_sip_wh_')&&str_contains($live,'get_transient($dedupe)'));
s529('new inbound event handled',str_contains($live,"live.transport.incoming")&&str_contains($live,"\$data['session_id']"));
s529('SIP To header routes by DID',str_contains($live,"extractNumber(\$sipHeaders,'To')")&&str_contains($live,'assignedSipByInboundNumber($did)'));
s529('assigned script resolved by owner scope',str_contains($live,'SalesScriptService::findForOwner'));
s529('inbound call accepted through Live sessions endpoint',str_contains($live,"'/live/sessions/'.rawurlencode(\$sessionId).'/accept'"));
s529('unroutable inbound call rejected safely',str_contains($live,"/reject")&&str_contains($live,'status_code'));
s529('outbound call uses Live create session',str_contains($live,"\$this->request('POST','/live/sessions'"));
s529('outbound transport is SIP',str_contains($live,"'transport'=>['type'=>'sip'"));
s529('outbound destination normalized to E164',str_contains($live,"\$destination=self::e164(\$destination)"));
s529('trunk digest auth present',str_contains($live,"'auth'=>['type'=>'digest'"));
s529('caller number included',str_contains($live,"'caller_number'=>\$caller"));
s529('no automatic retry warning exists',str_contains($live,'Повторять запрос автоматически нельзя'));
s529('GPT-Live model fixed to gpt-live-1',str_contains($live,"MODEL='gpt-live-1'"));
s529('voice prompt is natural speech not JSON',str_contains($ai,'voicePromptForScript')&&str_contains($ai,'Говори естественно')&&!str_contains(substr($ai,strpos($ai,'public static function voicePromptForScript'),strpos($ai,'private function request')-strpos($ai,'public static function voicePromptForScript')),'Верни ТОЛЬКО JSON'));
s529('partner SIP trunk fields retained for media gateway',str_contains($page,'direct_sip_provider_url')&&str_contains($page,'direct_sip_username')&&str_contains($page,'direct_sip_password')&&str_contains($page,'CKM Media Gateway'));
s529('legacy direct SIP service retained but active UI no longer exposes GPT-Live outbound',str_contains($live,"MODEL='gpt-live-1'")&&!str_contains($page,'seller_live_sip_call'));
s529('MANGO and UIS adapters preserved',str_contains($page,'MANGO OFFICE продаж')&&str_contains($page,'UIS продаж')&&str_contains($tel,"'mango'=>")&&str_contains($tel,"'uis'=>"));
s529('UI no longer claims RTP bridge is required for Direct SIP',!str_contains($page,'медиашлюз SIP/RTP'));
s529('refer and hangup controls prepared',str_contains($live,"'/refer'")&&str_contains($live,"'/hangup'"));
s529('release note states no sideband claim',is_file($root.'/CKM_0.3.23.497_SALES_DIRECT_SIP_LIVE_GATEWAY.txt')&&str_contains(file_get_contents($root.'/CKM_0.3.23.497_SALES_DIRECT_SIP_LIVE_GATEWAY.txt'),'Sideband transcript/tool worker is intentionally not claimed'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
if(!function_exists('sanitize_key')){function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}}
if(!function_exists('esc_url_raw')){function esc_url_raw($v){return filter_var((string)$v,FILTER_SANITIZE_URL);}}
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}}
$GLOBALS['s529_opts']=[];
if(!function_exists('get_option')){function get_option($k,$d=false){return $GLOBALS['s529_opts'][$k]??$d;}}
if(!function_exists('update_option')){function update_option($k,$v,$autoload=null){$GLOBALS['s529_opts'][$k]=$v;return true;}}
if(!function_exists('current_user_can')){function current_user_can($cap){return true;}}
if(!function_exists('wp_salt')){function wp_salt($scheme='auth'){return 'sales529-test-salt-'.$scheme;}}
if(!function_exists('rest_url')){function rest_url($path=''){return 'https://example.test/wp-json/'.ltrim($path,'/');}}
require_once $root.'/modules/effective-sales/application/sales-telephony-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesTelephonyService');
$m=$ref->getMethod('normalizeDirectSipUrl');$m->setAccessible(true);
s529('runtime accepts secure public SIP trunk',$m->invoke(null,'sips:sip.example.com:5061')==='sips:sip.example.com:5061');
$bad=false;try{$m->invoke(null,'sip:sip.example.com:5060');}catch(Throwable){$bad=true;}s529('runtime rejects plaintext SIP',$bad);
$bad=false;try{$m->invoke(null,'sips:127.0.0.1:5061');}catch(Throwable){$bad=true;}s529('runtime rejects loopback SIP trunk',$bad);
$bad=false;try{$m->invoke(null,'sips:192.168.1.2:5061');}catch(Throwable){$bad=true;}s529('runtime rejects private SIP trunk',$bad);

require_once $root.'/modules/effective-sales/application/sales-live-sip-service.php';
$lref=new ReflectionClass('CKM\\EffectiveSales\\SalesLiveSipService');
$e164=$lref->getMethod('e164');$e164->setAccessible(true);
s529('runtime E164 normalizer',$e164->invoke(null,'+7 (495) 123-45-67')==='+74951234567');
$bad=false;try{$e164->invoke(null,'123');}catch(Throwable){$bad=true;}s529('runtime E164 rejects short number',$bad);

$hdr=$lref->getMethod('header');$hdr->setAccessible(true);
s529('runtime WP header underscore normalization',$hdr->invoke(null,['webhook_id'=>['wh_abc']],'webhook-id')==='wh_abc');

$secretBytes="ckm-sales-529-standard-webhooks-secret";
$whsec='whsec_'.rtrim(strtr(base64_encode($secretBytes),'+/','-_'),'=');
\CKM\EffectiveSales\SalesLiveSipService::savePlatformSettings('sk-test-123456789012345678901234567890','proj_sales529',$whsec,'global','marin');
$raw='{"object":"event","type":"live.transport.incoming","data":{"type":"sip","session_id":"live_test"}}';$wid='wh_sales529';$ts=(string)time();$sig='v1,'.base64_encode(hash_hmac('sha256',$wid.'.'.$ts.'.'.$raw,$secretBytes,true));
s529('runtime Standard Webhooks signature verifies',\CKM\EffectiveSales\SalesLiveSipService::verifyWebhookSignature($raw,['webhook-id'=>$wid,'webhook-timestamp'=>$ts,'webhook-signature'=>$sig],(int)$ts));
$badSig='v1,'.base64_encode(str_repeat('x',32));s529('runtime bad webhook signature rejected',!\CKM\EffectiveSales\SalesLiveSipService::verifyWebhookSignature($raw,['webhook-id'=>$wid,'webhook-timestamp'=>$ts,'webhook-signature'=>$badSig],(int)$ts));

echo "{$passed} SALES-529 Direct SIP Live gateway checks passed. No database or network required.\n";

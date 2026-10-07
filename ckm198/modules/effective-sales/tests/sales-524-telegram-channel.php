<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s524(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$controller=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$tg=file_get_contents($root.'/modules/effective-sales/application/sales-telegram-service.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
s524('plugin version',ckm_test_current_plugin_release($main));
s524('telegram service bootstrapped',str_contains($boot,"sales-telegram-service.php"));
s524('official Bot API methods',str_contains($tg,"'getMe'")&&str_contains($tg,"'setWebhook'")&&str_contains($tg,"'deleteWebhook'")&&str_contains($tg,"'sendMessage'"));
s524('bot token encrypted at rest',str_contains($hub,'aes-256-gcm')&&str_contains($hub,'credentials_cipher')&&str_contains($tg,'connectPartnerIntegration')); 
s524('webhook protected by telegram secret header',str_contains($tg,'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN')&&str_contains($tg,'secret_cipher')&&str_contains($tg,'hash_equals'));
s524('webhook route is public but opaque',str_contains($controller,'/sales/telegram/webhook/(?P<hook>')&&str_contains($controller,"'permission_callback'=>'__return_true'")&&str_contains($controller,'telegramWebhook'));
s524('only private text chats processed',str_contains($tg,'$type!==\'private\'')&&str_contains($tg,"reason'=>'non_text"));
s524('telegram updates deduplicated after success',str_contains($tg,'ckm_sales_tg_update_')&&str_contains($tg,'get_transient($dedupe)')&&str_contains($tg,'set_transient($dedupe,1,self::UPDATE_TTL)'));
s524('external channel session reuses seller brain',str_contains($seller,'externalMessage')&&str_contains($seller,'\'channel\'=>$channel')&&str_contains($seller,'\'external_thread_id\'=>$threadId'));
s524('telegram lead enters shared inbox',str_contains($work,'recordExternalStart')&&str_contains($work,'recordExternalStart')&&str_contains($work,'$log[\'channel\']=$channel'));
s524('operator reply goes back to telegram',str_contains($seller,'SalesTelegramService())->sendForSession($session,$text)')&&str_contains($seller,'operatorMessage'));
s524('automation messages go back to telegram',str_contains($seller,"\$channel==='telegram'")&&str_contains($seller,'SalesTelegramService())->sendForSession($session,$message)')&&str_contains($seller,'executeScenarioStep'));
s524('telegram followups have background cron',str_contains($seller,'cronSchedules')&&str_contains($seller,'ckm_sales_minute')&&str_contains($seller,'cronTick')&&str_contains($boot,'ckm_sales_ai_seller_tick'));
s524('connect and disconnect UI',str_contains($page,'seller_telegram_connect')&&str_contains($page,'seller_telegram_disconnect')&&str_contains($page,'Bot Token от BotFather'));
s524('telegram readiness shown dynamically',str_contains($page,'$telegramBinding')&&str_contains($page,'Открыть бота')&&str_contains($page,'Не назначен'));
s524('MAX and WhatsApp placeholders preserved',str_contains($page,'<b>MAX</b>')&&str_contains($page,'<b>WhatsApp</b>'));
s524('telegram connection CSS added',str_contains($css,'.ckm-sales-telegram-connect')&&str_contains($css,'.ckm-sales-channel-status'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!defined('AUTH_KEY'))define('AUTH_KEY','test-auth-key');
if(!defined('SECURE_AUTH_KEY'))define('SECURE_AUTH_KEY','test-secure-key');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
if(!function_exists('wp_salt')){function wp_salt($scheme='auth'){return 'test-salt-'.$scheme;}}
if(!function_exists('wp_generate_uuid4')){function wp_generate_uuid4(){return '11111111-2222-4333-8444-555555555555';}}
require_once $root.'/modules/effective-sales/application/sales-telegram-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesTelegramService');
$normalize=$ref->getMethod('normalizeToken');$normalize->setAccessible(true);
$valid='123456789:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcd';
s524('runtime token validator accepts BotFather shape',$normalize->invoke(null,$valid)===$valid);
$bad=false;try{$normalize->invoke(null,'bad-token');}catch(Throwable){$bad=true;}s524('runtime token validator rejects malformed token',$bad);
$enc=$ref->getMethod('encrypt');$enc->setAccessible(true);$dec=$ref->getMethod('decrypt');$dec->setAccessible(true);$cipher=$enc->invoke(null,$valid);s524('runtime token encryption roundtrip',is_string($cipher)&&str_starts_with($cipher,'v1:')&&$dec->invoke(null,$cipher)===$valid&&$cipher!==$valid);
$calls=[];$svc=$ref->newInstance(function($method,$params,$token)use(&$calls){$calls[]=[$method,$params,$token];return ['ok'=>true,'result'=>['id'=>1,'username'=>'ckm_test_bot']];});$api=$ref->getMethod('api');$api->setAccessible(true);$api->invoke($svc,$valid,'getMe',[]);s524('runtime transport adapter receives telegram method',count($calls)===1&&$calls[0][0]==='getMe'&&$calls[0][2]===$valid);
echo "{$passed} SALES-524 Telegram channel checks passed. No database required.\n";

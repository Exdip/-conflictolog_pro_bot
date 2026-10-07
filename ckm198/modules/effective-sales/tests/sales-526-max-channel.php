<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s526(string $name,bool $ok): void { global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$controller=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$max=file_get_contents($root.'/modules/effective-sales/application/sales-max-service.php');

s526('plugin version',ckm_test_current_plugin_release($main));
s526('MAX service bootstrapped',str_contains($boot,'sales-max-service.php'));
s526('official MAX API base v2',str_contains($max,'https://platform-api2.max.ru'));
s526('MAX uses Authorization header',str_contains($max,"'Authorization'=>\$token"));
s526('MAX verifies bot via GET me',str_contains($max,"'GET','/me'"));
s526('MAX creates webhook subscription',str_contains($max,"'POST','/subscriptions'")&&str_contains($max,"'update_types'=>['message_created']"));
s526('MAX tests and deletes subscriptions',str_contains($max,"'GET','/subscriptions'")&&str_contains($max,"'DELETE','/subscriptions'"));
s526('MAX sends direct messages',str_contains($max,"'POST','/messages'")&&str_contains($max,"'user_id'=>\$userId"));
s526('MAX webhook route public and opaque',str_contains($controller,'/sales/max/webhook/(?P<hook>')&&str_contains($controller,'maxWebhook')&&str_contains($controller,'SALES_MAX_ERROR'));
s526('MAX webhook secret header verified',str_contains($max,'HTTP_X_MAX_BOT_API_SECRET')&&str_contains($max,'webhookSecretForRow')&&str_contains($max,'hash_equals'));
s526('MAX accepts only private dialogs',str_contains($max,"\$chatType!=='dialog'")&&str_contains($max,"reason'=>'private_dialog_only"));
s526('MAX ignores bot messages',str_contains($max,"reason'=>'bot_message'"));
s526('MAX update dedupe',str_contains($max,'ckm_sales_max_update_')&&str_contains($max,'get_transient($dedupe)')&&str_contains($max,'set_transient($dedupe,1,self::UPDATE_TTL)'));
s526('MAX reuses AI seller brain',str_contains($max,"externalMessage(\$sellerToken,'max'")&&str_contains($seller,"['telegram','max','whatsapp','phone']"));
s526('MAX session reaches shared inbox',str_contains($work,"['web','telegram','max','whatsapp','phone']")&&str_contains($work,"['telegram','max','whatsapp','phone']"));
s526('operator messages route to MAX',str_contains($seller,"\$channel==='max'")&&str_contains($seller,'SalesMaxService())->sendForSession($session,$text)'));
s526('automation messages route to MAX',str_contains($seller,'SalesMaxService())->sendForSession($session,$message)')&&str_contains($seller,'executeScenarioStep'));
s526('tenant hub creates verified MAX integration',str_contains($hub,'createVerifiedMax')&&str_contains($hub,'rowByProviderWebhook')&&str_contains($max,"rowByProviderWebhook('max',\$hook)"));
s526('MAX integration UI is real connector',str_contains($page,'integration_max_connect')&&str_contains($page,'integration_max_test')&&str_contains($page,'MAX · РАБОТАЕТ')&&str_contains($page,'Подключить MAX'));
s526('MAX ID discovered not requested',!str_contains($page,'ID приложения / бота')&&str_contains($page,'определит ID и имя бота'));
s526('MAX can be assigned to concrete agent',str_contains($page,'$availableMax')&&str_contains($page,'$maxBinding')&&str_contains($page,'Подключить MAX к этому агенту'));
s526('MAX shown in inbox channel labels',str_contains($page,"\$dialogChannel==='max'?'MAX'"));
s526('MAX counted in working channels',str_contains($page,'$maxCount')&&str_contains($page,"\$channels[]='MAX'"));
s526('MAX follows background automation note',str_contains($page,'Telegram/MAX'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
require_once $root.'/modules/effective-sales/application/sales-max-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesMaxService');
$normalize=$ref->getMethod('normalizeToken');$normalize->setAccessible(true);
$valid='max_test_token_abcdefghijklmnopqrstuvwxyz123456';
s526('runtime token validator accepts MAX token',$normalize->invoke(null,$valid)===$valid);
$bad=false;try{$normalize->invoke(null,'bad token');}catch(Throwable){$bad=true;}s526('runtime token validator rejects whitespace token',$bad);
$calls=[];$svc=$ref->newInstance(function($method,$path,$query,$body,$token)use(&$calls){$calls[]=[$method,$path,$query,$body,$token];return ['user_id'=>777,'first_name'=>'CKM MAX','username'=>'ckm_max_bot','is_bot'=>true];});
$api=$ref->getMethod('api');$api->setAccessible(true);$out=$api->invoke($svc,$valid,'GET','/me');
s526('runtime transport uses GET me and token',count($calls)===1&&$calls[0][0]==='GET'&&$calls[0][1]==='/me'&&$calls[0][4]===$valid&&($out['user_id']??0)===777);
echo "{$passed} SALES-526 MAX channel checks passed. No database required.\n";

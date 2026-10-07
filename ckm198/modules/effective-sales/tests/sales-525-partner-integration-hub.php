<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s525(string $name,bool $ok): void { global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$tpl=file_get_contents($root.'/modules/effective-sales/public/app-template.php');
$hub=file_get_contents($root.'/modules/effective-sales/application/sales-partner-integration-service.php');
$tg=file_get_contents($root.'/modules/effective-sales/application/sales-telegram-service.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
s525('plugin version',ckm_test_current_plugin_release($main));
s525('integration service bootstrapped',str_contains($boot,'sales-partner-integration-service.php')&&str_contains($boot,"SalesPartnerIntegrationService::class, 'installSchema'"));
s525('tenant scoped integration table',str_contains($hub,'ckm_sales_partner_integrations')&&str_contains($hub,'tenant_id bigint unsigned')&&str_contains($hub,'tenant_provider'));
s525('secrets encrypted generically',str_contains($hub,'aes-256-gcm')&&str_contains($hub,'credentials_cipher')&&str_contains($hub,'webhook_secret_cipher'));
s525('only partner owner can manage secrets',str_contains($hub,'ckm_quiz_pro_partner_user_is_owner')&&str_contains($hub,'canManageSecrets')&&str_contains($hub,'Изменять данные каналов может только владелец'));
s525('organizers get read only UI',str_contains($page,'Режим просмотра')&&str_contains($page,'секреты может добавлять, менять и удалять только владелец'));
s525('integrations reached through Practice navigation',str_contains($tpl,"salesView==='ai-sellers'")&&str_contains($tpl,'>Практика<')&&str_contains($page,"sales_view'=>'integrations'")&&str_contains($page,'>Каналы и интеграции<')&&str_contains($page,"\$view==='integrations'"));
s525('partner enters own telegram credentials',str_contains($page,'integration_telegram_connect')&&str_contains($page,'Bot Token от BotFather')&&str_contains($page,'КАНАЛЫ И ИНТЕГРАЦИИ'));
s525('MAX credentials form is tenant scoped',str_contains($page,'integration_max_connect')&&str_contains($page,'max_api_token')&&str_contains($page,'MAX Bot API'));
s525('WhatsApp credentials form is tenant scoped',str_contains($page,'integration_save_whatsapp')&&str_contains($page,'wa_phone_number_id')&&str_contains($page,'wa_access_token'));
s525('SIP credentials form is tenant scoped',str_contains($page,'integration_save_sip')&&str_contains($page,'sip_server')&&str_contains($page,'sip_login')&&str_contains($page,'sip_password'));
s525('agent receives binding not token',str_contains($page,'seller_integration_bind')&&str_contains($page,'seller_integration_unbind')&&str_contains($page,'Секреты больше не вводятся в карточке ИИ-продавца'));
s525('binding stores user scope and script',str_contains($hub,'assigned_user_id')&&str_contains($hub,'assigned_scope')&&str_contains($hub,'assigned_script_id')&&str_contains($hub,'ai_seller_integrations'));
s525('one provider binding per agent enforced',str_contains($hub,'integration_id<>%s')&&str_contains($hub,'Это подключение уже назначено другому ИИ-продавцу'));
s525('telegram is created in tenant hub',str_contains($tg,'connectPartnerIntegration')&&str_contains($tg,'createVerifiedTelegram')&&str_contains($tg,"assertAccountAvailable('telegram'"));
s525('telegram webhook resolves central integration',str_contains($tg,'rowByWebhook')&&str_contains($tg,"'_integration_row'")&&str_contains($tg,'webhookSecretForRow'));
s525('external sessions remember exact integration',str_contains($seller,'external_integration_id')&&str_contains($seller,'externalMapKey')&&str_contains($tg,'integrationForSession'));
s525('legacy per-agent telegram remains fallback',str_contains($tg,'hookIndex')&&str_contains($tg,'config($script)')&&str_contains($tg,'token_cipher'));
s525('hub has safe UI styles',str_contains($css,'.ckm-sales-integration-grid')&&str_contains($css,'.ckm-sales-integration-list'));
s525('integration hub explains tenant ownership',str_contains($page,'привязаны к текущему <code>tenant_id</code>')&&str_contains($page,'Подключения принадлежат партнёру, а не платформе'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!defined('AUTH_KEY'))define('AUTH_KEY','test-auth-key');
if(!defined('SECURE_AUTH_KEY'))define('SECURE_AUTH_KEY','test-secure-key');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
if(!function_exists('wp_salt')){function wp_salt($scheme='auth'){return 'test-salt-'.$scheme;}}
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}}
require_once $root.'/modules/effective-sales/application/sales-partner-integration-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesPartnerIntegrationService');$enc=$ref->getMethod('encrypt');$enc->setAccessible(true);$dec=$ref->getMethod('decrypt');$dec->setAccessible(true);$plain=['access_token'=>'super-secret','login'=>'partner'];$cipher=$enc->invoke(null,$plain);$round=$dec->invoke(null,$cipher);
s525('runtime generic secret encryption roundtrip',is_string($cipher)&&str_starts_with($cipher,'v1:')&&$cipher!=='super-secret'&&$round===$plain);
echo "{$passed} SALES-525 partner integration hub checks passed. No database required.\n";

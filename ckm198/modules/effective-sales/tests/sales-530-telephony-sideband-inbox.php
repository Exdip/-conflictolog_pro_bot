<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,3);$passed=0;
function s530(string $name,bool $ok): void {global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n";}
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$controller=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$live=file_get_contents($root.'/modules/effective-sales/application/sales-live-sip-service.php');
$side=file_get_contents($root.'/modules/effective-sales/application/sales-live-sideband-service.php');
$workspace=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$tel=file_get_contents($root.'/modules/effective-sales/application/sales-telephony-service.php');
$worker=file_get_contents($root.'/modules/effective-sales/workers/live-sideband/index.mjs');
$pkg=file_get_contents($root.'/modules/effective-sales/workers/live-sideband/package.json');

s530('plugin version',$main!==false&&ckm_test_current_plugin_release($main));
s530('sideband service bootstrapped',$boot!==false&&str_contains($boot,'sales-live-sideband-service.php'));
s530('sideband loaded after live SIP',strpos($boot,'sales-live-sip-service.php')<strpos($boot,'sales-live-sideband-service.php'));
s530('sideband endpoints registered',str_contains($controller,'/sales/live-sip/sideband/claim')&&str_contains($controller,'/sales/live-sip/sideband/event')&&str_contains($controller,'/sales/live-sip/sideband/heartbeat'));
s530('sideband uses dedicated shared secret header',str_contains($controller,'X-CKM-Sideband-Secret')&&str_contains($side,'hash_equals'));
s530('sideband secret encrypted with platform settings',str_contains($live,"'sideband_secret'=>")&&str_contains($live,'secret_cipher'));
s530('sideband secret minimum length enforced',str_contains($live,'Sideband worker secret должен содержать не менее 24 символов'));
s530('worker heartbeat tracked',str_contains($side,'WORKER_ONLINE_TTL=90')&&str_contains($side,'ckm_sales_live_sideband_worker_heartbeat_v1'));
s530('call claim has lease',str_contains($side,'CLAIM_TTL=45')&&str_contains($side,'claimed_until'));
s530('outbound call registered for sideband',str_contains($live,'SalesLiveSidebandService::registerCall')&&str_contains($live,"'direction'=>'outbound'"));
s530('inbound call registered with caller',substr_count($live,'SalesLiveSidebandService::registerCall')>=2&&str_contains($live,"extractNumber(\$sipHeaders,'From')"));
s530('phone channel allowed in workspace',str_contains($workspace,"'phone'")&&str_contains($workspace,'recordLiveTranscriptDelta'));
s530('current Live input transcript event handled',str_contains($side,'session.input_transcript.delta'));
s530('current Live output transcript event handled',str_contains($side,'session.output_transcript.delta'));
s530('transcript timing retained',str_contains($workspace,'live_start_ms')&&str_contains($workspace,'live_end_ms'));
s530('transcript deltas sanitized without trim',str_contains($workspace,'$delta=wp_strip_all_tags($delta)')&&!str_contains($workspace,'$delta=trim(wp_strip_all_tags($delta))'));
s530('session usage stored',str_contains($side,'session.usage.updated')&&str_contains($workspace,'call_usage'));
s530('session close finalizes dialog',str_contains($side,"\$type==='session.closed'")&&str_contains($workspace,"\$log['status']='ended'"));
s530('transport failure finalizes dialog',str_contains($side,"\$type==='transport.failed'")&&str_contains($side,"'failed'"));
s530('worker attaches to current Live sideband URL',str_contains($worker,'/v1/live/sessions/${encodeURIComponent(sessionId)}/attach'));
s530('worker uses Authorization bearer',str_contains($worker,'Authorization:`Bearer ${OPENAI_API_KEY}`'));
s530('worker never handles SIP password',!str_contains($worker,'SIP_PASSWORD')&&!str_contains($worker,'provider_url'));
s530('worker forwards only allowlisted events',str_contains($worker,'const allowed = new Set')&&str_contains($worker,'allowed.has'));
s530('worker sends heartbeat while attached',str_contains($worker,"setInterval(() => post('heartbeat'"));
s530('worker package pins ws dependency',str_contains($pkg,'"ws"'));
s530('platform UI migrated from Live sideband to phone gateway secret',str_contains($page,'phone_gateway_secret')&&!str_contains($page,'openai_sideband_secret'));
s530('platform UI reports phone media worker status',str_contains($page,'Phone media worker:')&&str_contains($page,'worker_online'));
s530('SIP has handoff target field',str_contains($page,'direct_sip_handoff_target')&&str_contains($page,'Перевод живому оператору'));
s530('handoff target stored in tenant credentials',str_contains($tel,"'handoff_target_uri'=>\$handoff"));
s530('phone Inbox label exists',str_contains($page,"\$dialogChannel==='phone'?'Телефон'"));
s530('phone dialog has refer button',str_contains($page,'seller_phone_refer')&&str_contains($page,'Перевести звонок оператору'));
s530('phone dialog has hangup button',str_contains($page,'seller_phone_hangup')&&str_contains($page,'Завершить звонок'));
s530('phone text operator reply disabled',str_contains($page,"if(!\$isPhone&&\$mode==='human'"));
s530('transfer invokes Live refer',str_contains($side,'->refer($sessionId,$target)'));
s530('hangup invokes Live hangup',str_contains($side,'->hangup($sessionId)'));
s530('transfer recorded in Inbox',str_contains($workspace,'recordLiveTransfer')&&str_contains($workspace,'Звонок переведён живому оператору'));
s530('release note documents external long-lived worker',is_file($root.'/CKM_0.3.23.498_SALES_TELEPHONY_SIDEBAND_INBOX.txt')&&str_contains(file_get_contents($root.'/CKM_0.3.23.498_SALES_TELEPHONY_SIDEBAND_INBOX.txt'),'PHP-FPM is intentionally not used'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
if(!function_exists('sanitize_key')){function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}}
if(!function_exists('esc_url_raw')){function esc_url_raw($v){return filter_var((string)$v,FILTER_SANITIZE_URL);}}
require_once $root.'/modules/effective-sales/application/sales-telephony-service.php';
s530('runtime accepts tel transfer',\CKM\EffectiveSales\SalesTelephonyService::normalizeHandoffTarget('tel:+74951234567',false)==='tel:+74951234567');
s530('runtime accepts SIP transfer',\CKM\EffectiveSales\SalesTelephonyService::normalizeHandoffTarget('sip:100@pbx.example.ru',false)==='sip:100@pbx.example.ru');
$bad=false;try{\CKM\EffectiveSales\SalesTelephonyService::normalizeHandoffTarget("tel:+74951234567\r\nX:1",false);}catch(Throwable){$bad=true;}s530('runtime rejects CRLF transfer target',$bad);
$bad=false;try{\CKM\EffectiveSales\SalesTelephonyService::normalizeHandoffTarget('http://example.com',false);}catch(Throwable){$bad=true;}s530('runtime rejects non SIP/TEL transfer target',$bad);

echo "{$passed} SALES-530 telephony sideband Inbox checks passed. No database or network required.\n";

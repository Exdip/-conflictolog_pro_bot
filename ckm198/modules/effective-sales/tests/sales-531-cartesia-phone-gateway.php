<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);$main=file_get_contents($root.'/ckm-quiz-pro.php');$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');$ctrl=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');$svc=file_get_contents($root.'/modules/effective-sales/application/sales-phone-gateway-service.php');$ai=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');$tel=file_get_contents($root.'/modules/effective-sales/application/sales-telephony-service.php');$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');$worker=file_get_contents($root.'/modules/effective-sales/workers/phone-media-gateway/index.mjs');$readme=file_get_contents($root.'/modules/effective-sales/workers/phone-media-gateway/README.md');
$pass=0;$fail=0;function s531($ok,$m){global $pass,$fail;if($ok){$pass++;echo "OK $m\n";}else{$fail++;echo "FAIL $m\n";}}
s531(ckm_test_current_plugin_release($main),'version');
s531(str_contains($boot,'sales-phone-gateway-service.php'),'phone service bootstrapped');
s531(str_contains($ctrl,'/sales/phone-gateway/open')&&str_contains($ctrl,'/sales/phone-gateway/turn')&&str_contains($ctrl,'/sales/phone-gateway/speak')&&str_contains($ctrl,'/sales/phone-gateway/commands'),'worker REST surface');
s531(str_contains($svc,'assignedSipByInboundNumber')&&str_contains($svc,'Deepgram Nova-3 · ru')&&str_contains($svc,'Сергей · Cartesia'),'DID routing and labels');
s531(str_contains($svc,"externalMessage(\$token,'phone'")&&str_contains($ai,"['telegram','max','whatsapp','phone']"),'phone reuses AI seller core');
s531(str_contains($svc,'voiceTicket($token,$dialog)')&&str_contains($svc,'->speak($token,$dialog,$turnIndex)'),'Sergey voice gateway reused');
s531(str_contains($page,'Телефонный ИИ-продавец · Сергей')&&str_contains($page,'Cartesia API key и Voice ID Сергея')&&!str_contains($page,'name="openai_live_voice"'),'Marin field removed from active UI');
s531(!str_contains($page,'Позвонить через ИИ'),'no fake outbound call button');
s531(str_contains($page,'Phone gateway secret')&&str_contains($page,'Проверить Сергея'),'platform UI');
s531(str_contains($tel,'SIP/PSTN → CKM Media Gateway → Сергей'),'telephony adapter relabeled');
s531(str_contains($worker,'api.deepgram.com/v1/listen')&&str_contains($worker,"language:'ru'")&&str_contains($worker,"model:'nova-3'"),'Deepgram Nova-3 ru streaming');
s531(str_contains($worker,'voice_start')&&str_contains($worker,'voice_end')&&str_contains($worker,"role:'scoreboard'")&&str_contains($worker,"post('speak'"),'existing Sergey gateway capture');
s531(str_contains($worker,'0x10')&&str_contains($worker,'mono8k')&&str_contains($worker,'320'),'AudioSocket PCM16 8k bridge');
s531(str_contains($worker,"u.pathname==='/calls/inbound'")&&str_contains($worker,'127.0.0.1'),'local inbound control');
s531(str_contains($readme,'AudioSocket(${CKM_UUID},127.0.0.1:9019)')&&str_contains($readme,'func_uuid'),'Asterisk deployment example');
s531(str_contains($readme,'Outbound PBX originate remains provider/PBX-specific'),'outbound boundary documented');
s531(is_file($root.'/CKM_0.3.23.499_SALES_CARTESIA_PHONE_GATEWAY.txt'),'release marker');
if($fail){fwrite(STDERR,"$fail failures\n");exit(1);}echo "$pass SALES-531 checks passed.\n";

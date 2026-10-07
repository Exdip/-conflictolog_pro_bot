<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!defined('HOUR_IN_SECONDS'))define('HOUR_IN_SECONDS',3600);
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($s){return strip_tags((string)$s);}}
if(!function_exists('sanitize_key')){function sanitize_key($s){$s=strtolower((string)$s);return preg_replace('/[^a-z0-9_\-]/','',$s);}}
require_once dirname(__DIR__).'/application/sales-script-service.php';
require_once dirname(__DIR__).'/application/sales-practice-feedback-service.php';
require_once dirname(__DIR__).'/application/sales-ai-seller-workspace-service.php';
require_once dirname(__DIR__).'/application/sales-ai-seller-service.php';
use CKM\EffectiveSales\SalesAiSellerService;
$root=dirname(__DIR__,3);$main=file_get_contents($root.'/ckm-quiz-pro.php');$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');$tpl=file_get_contents($root.'/modules/effective-sales/public/app-template.php');$ctl=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');$js=file_get_contents($root.'/modules/effective-sales/assets/sales-ai-seller.js');$svc=file_get_contents($root.'/modules/effective-sales/application/sales-script-service.php');$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$checks=[];function s508(&$c,$n,$ok){$c[]=[$n,(bool)$ok];}
s508($checks,'plugin version',ckm_test_current_plugin_release($main));
s508($checks,'ai seller navigation',str_contains($tpl,'ИИ-продавец')&&str_contains($tpl,"sales_view'=>'ai-sellers"));
s508($checks,'connect button',str_contains($page,'Подключить к ИИ-продавцу')&&str_contains($page,'connect_seller'));
s508($checks,'public real-client page',str_contains($page,"sales_view'=>'ai-seller'")&&str_contains($page,'Вы разговариваете с искусственным интеллектом'));
s508($checks,'public link can be revoked',str_contains($svc,'disconnectAiSeller')&&str_contains($svc,"delete_option('ckm_sales_ai_seller_"));
s508($checks,'public seller endpoints',str_contains($ctl,'/sales/ai-seller/start')&&str_contains($ctl,'/sales/ai-seller/message')&&str_contains($ctl,'/sales/ai-seller/voice-ticket')&&str_contains($ctl,'/sales/ai-seller/speak'));
s508($checks,'seller prompt anti-hallucination',str_contains($seller,'Не выдумывай цену, скидку, гарантию')&&str_contains($seller,'не считай факты о текущем собеседнике известными'));
s508($checks,'human handoff',str_contains($seller,"'intent'=>'handoff'")&&str_contains($seller,'запрос на разговор со специалистом'));
s508($checks,'retained text session still has no saved audio',str_contains($page,'Аудио не сохраняется в WordPress')&&str_contains($seller,"'audio_saved'=>false")&&str_contains($seller,'SESSION_TTL = 604800')&&str_contains($seller,'set_transient(self::sessionKey'));
s508($checks,'professional gateway voice',str_contains($seller,"'scoreboard',null")&&str_contains($seller,"'participant',1")&&str_contains($seller,'ckm_quiz_pro_voice_send_payload'));
s508($checks,'browser voice fallback',str_contains($js,'SpeechSynthesisUtterance')&&str_contains($js,'SpeechRecognition||window.webkitSpeechRecognition'));
s508($checks,'gateway stt path',str_contains($js,"u.pathname='/stt'")&&str_contains($js,"role:'participant'"));
$script=['id'=>'s508','title'=>'CRM — цена','product'=>'CRM для отдела продаж, цена 720 000 рублей в год. Есть аналитика лидов и контроль повторных контактов.','client'=>'B2B-коммерческий директор; это ориентир целевого клиента, а не факт о собеседнике.','situation_class'=>'Цена','goal'=>'Понять причину сомнения, объяснить ценность и договориться о следующем шаге','constraints'=>'Не давать скидку без основания; не обещать рост продаж'];
$reply=json_encode(['reply'=>'Понимаю вопрос о цене. Чтобы ответить по существу, уточню: вы сравниваете 720 тысяч с другим решением или пока неясно, какой эффект должна дать CRM?','intent'=>'continue','stage'=>'Разобраться'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$out=SalesAiSellerService::transcriptForTest($script,[['role'=>'user','content'=>'Почему так дорого?']],fn()=> $reply);
s508($checks,'test seller run parses reply',($out['intent']??'')==='continue'&&($out['stage']??'')==='Разобраться'&&str_contains((string)$out['reply'],'720'));
$captured='';SalesAiSellerService::transcriptForTest($script,[['role'=>'user','content'=>'Скажите, у вас есть гарантия окупаемости?']],function($messages) use (&$captured,$reply){$captured=(string)($messages[0]['content']??'');return $reply;});
s508($checks,'prompt grounded in approved script',str_contains($captured,'CRM для отдела продаж')&&str_contains($captured,'Не выдумывай цену, скидку, гарантию'));
$fail=0;foreach($checks as[$n,$ok]){echo($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$fail++;}exit($fail?1:0);

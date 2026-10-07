<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s520(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$ctl=file_get_contents($root.'/modules/effective-sales/api/sales-controller.php');
$js=file_get_contents($root.'/modules/effective-sales/assets/sales-ai-seller.js');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
s520('plugin version',ckm_test_current_plugin_release($main));
s520('pipeline stages',str_contains($work,'PIPELINE_STAGES')&&str_contains($work,"'won'=>'Успех'")&&str_contains($work,"'lost'=>'Отказ'"));
s520('lead card update',str_contains($work,'updateLead')&&str_contains($page,'seller_lead_update'));
s520('human takeover',str_contains($seller,'operatorControl')&&str_contains($page,'seller_takeover')&&str_contains($page,'seller_return_ai'));
s520('human reply',str_contains($seller,'operatorMessage')&&str_contains($page,'seller_operator_message')&&str_contains($page,'Отправить от человека'));
s520('public state endpoint',str_contains($ctl,"/sales/ai-seller/state")&&str_contains($ctl,'aiSellerState'));
s520('client polls operator replies',str_contains($js,'syncState')&&str_contains($js,'startPoll')&&str_contains($js,'queued_for_operator'));
s520('AI stops while human controls',str_contains($seller,"control_mode']??'ai')==='human")&&str_contains($seller,'recordClientOnly')&&strpos($seller,"control_mode']??'ai')==='human")<strpos($seller,"preg_match('/(соедин|перевед|позов)"));
s520('full thread UI',str_contains($page,'ckm-sales-inbox-thread')&&str_contains($page,'Лиды и разговоры'));
s520('MAX retained',str_contains($page,'<b>MAX</b>'));
s520('inbox responsive CSS',str_contains($css,'.ckm-sales-inbox-layout')&&str_contains($css,'.ckm-sales-operator-reply'));
echo "{$passed} SALES-520 inbox/CRM checks passed. No database required.\n";

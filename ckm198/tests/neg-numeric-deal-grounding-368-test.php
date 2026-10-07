<?php
// Item fixtures carry the current typed production context.
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); } }
require dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;
$checks=0;
function n368(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} $checks++;}
$ref=new ReflectionClass(ArbiterService::class);
$values=$ref->getMethod('explicitDealValues');$values->setAccessible(true);
$filter=$ref->getMethod('filterUngroundedDealUpdates');$filter->setAccessible(true);
$baseItems=[
 ['code'=>'price','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB','state_status'=>'not_discussed','current_value'=>[]],
 ['code'=>'delivery_days','value_type'=>'integer','title'=>'Срок поставки','unit'=>'дней','state_status'=>'not_discussed','current_value'=>[]],
];
$ctx=['target_message'=>['id'=>90,'actor'=>'opponent','content'=>"Самым критичным последствием задержки поставки может быть:\n1. Упущенная прибыль.\n2. Штрафы."],'items'=>$baseItems,'recent_dialogue'=>[]];
n368($values->invoke(null,$ctx)===[],'ordered-list numbers are not explicit deal values');
$a=['deal_updates'=>[['item_code'=>'delivery_days','action'=>'proposed','value'=>1,'proposed_by'=>'opponent','confidence'=>0.95]]];
$f=$filter->invoke(null,$a,$ctx);
n368(($f['deal_updates']??[])===[],'hallucinated list number is filtered before item state');
$ctx2=['target_message'=>['id'=>91,'actor'=>'player','content'=>'Предлагаю цену контракта 960 000 рублей и срок поставки 40 дней.'],'items'=>$baseItems,'recent_dialogue'=>[]];
$v=$values->invoke(null,$ctx2);
n368(($v['price']??null)===960000 && ($v['delivery_days']??null)===40,'labeled package values remain grounded');
$a2=['deal_updates'=>[
 ['item_code'=>'price','action'=>'proposed','value'=>960000,'proposed_by'=>'player','confidence'=>0.95],
 ['item_code'=>'delivery_days','action'=>'proposed','value'=>40,'proposed_by'=>'player','confidence'=>0.95],
]];
n368(count(($filter->invoke(null,$a2,$ctx2))['deal_updates']??[])===2,'valid labeled numeric proposals are preserved');
$ctx3=['target_message'=>['id'=>92,'actor'=>'player','content'=>'Предлагаю срок поставки 40 дней.'],'items'=>$baseItems,'recent_dialogue'=>[]];
$v3=$values->invoke(null,$ctx3);
n368(($v3['delivery_days']??null)===40,'delivery value next to label/unit is extracted');
$ctx4=['target_message'=>['id'=>93,'actor'=>'player','content'=>'А если 35?'],'items'=>$baseItems,'recent_dialogue'=>[
 ['id'=>92,'actor'=>'opponent','content'=>'По сроку поставки сейчас обсуждаем 40 дней.'],
 ['id'=>93,'actor'=>'player','content'=>'А если 35?'],
]];
$a4=['deal_updates'=>[['item_code'=>'delivery_days','action'=>'proposed','value'=>35,'proposed_by'=>'player','confidence'=>0.95]]];
n368(count(($filter->invoke(null,$a4,$ctx4))['deal_updates']??[])===1,'short unlabelled numeric continuation is allowed when previous context identifies one item');
$ctx5=['target_message'=>['id'=>94,'actor'=>'opponent','content'=>'Согласен.'],'items'=>[
 ['code'=>'price','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB','state_status'=>'proposed','current_value'=>['offers'=>['player'=>['value'=>960000]]]],
 ['code'=>'delivery_days','value_type'=>'integer','title'=>'Срок поставки','unit'=>'дней','state_status'=>'not_discussed','current_value'=>[]],
],'recent_dialogue'=>[]];
$a5=['deal_updates'=>[['item_code'=>'price','action'=>'acceptance_candidate','value'=>960000,'proposed_by'=>'opponent','confidence'=>0.95]]];
n368(count(($filter->invoke(null,$a5,$ctx5))['deal_updates']??[])===1,'acceptance may confirm a stored offer without repeating the number');
$ctx6=['target_message'=>['id'=>95,'actor'=>'opponent','content'=>'Встреча 02.10.2026. Срок поставки обсудим отдельно.'],'items'=>$baseItems,'recent_dialogue'=>[]];
n368($values->invoke(null,$ctx6)===[],'calendar date is not parsed as delivery value');
$ctx7=['target_message'=>['id'=>96,'actor'=>'opponent','content'=>'Скидка 10%, а срок поставки обсудим отдельно.'],'items'=>$baseItems,'recent_dialogue'=>[]];
$a7=['deal_updates'=>[['item_code'=>'delivery_days','action'=>'proposed','value'=>10,'proposed_by'=>'opponent','confidence'=>0.95]]];
n368(($filter->invoke(null,$a7,$ctx7))['deal_updates']===[],'percentage is not reused as delivery days');
$svc=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
n368(str_contains($svc,"neg-arbiter-1.7"),'arbiter version bumped');
n368(str_contains($svc,'filterUngroundedDealUpdates'),'numeric grounding filter wired');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
n368(ckm_test_current_plugin_release($plugin),'plugin version bumped');
echo "$checks/$checks NEG-NUMERIC-DEAL-GROUNDING-368 PASS\n";

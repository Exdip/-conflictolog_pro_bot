<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/reopen-policy.php';
require_once dirname(__DIR__) . '/modules/negotiation-master/ai/arbiter/arbiter-validator.php';
use CKM\NegotiationMaster\ArbiterValidator;

$checks=0;
function a354(bool $ok,string $label):void{global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
$context=[
 'target_message'=>['id'=>42,'actor'=>'player','content'=>'Предлагаю цену 950000 рублей.'],
 'items'=>[['code'=>'price','state_status'=>'not_discussed','current_value'=>[]]],
 'hidden_facts'=>[],
];
$payload=[
 'message'=>['message_id'=>0,'actor'=>'opponent'], // provider echoed schema example; server context must win
 'semantic_units'=>[['type'=>'offer','text'=>'Цена 950000']],
 'events'=>[['event_type'=>'offer_made','target_type'=>'item','target_code'=>'price','confidence'=>90,'payload'=>[]]],
 'fact_updates'=>[],
 'deal_updates'=>[['item_code'=>'price','action'=>'proposed','value'=>950000,'proposed_by'=>'player','confidence'=>95]],
 'commitment_updates'=>[],
 'rule_signals'=>[],
 'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low'],
];
$v=(new ArbiterValidator())->validate($payload,$context);
a354(($v['message']['message_id']??0)===42,'server target message id authoritative');
a354(($v['message']['actor']??'')==='player','server target actor authoritative');
a354(abs(($v['events'][0]['confidence']??0)-0.9)<0.0001,'event confidence 90 normalized to 0.90');
a354(abs(($v['deal_updates'][0]['confidence']??0)-0.95)<0.0001,'deal confidence 95 normalized to 0.95');
a354(($v['deal_updates'][0]['item_code']??'')==='price','deal item preserved');
$thrown=false;try{(new ArbiterValidator())->validate(array_replace($payload,['events'=>[['event_type'=>'offer_made','target_type'=>'item','target_code'=>'price','confidence'=>101,'payload'=>[]]]]),$context);}catch(UnexpectedValueException){$thrown=true;}
a354($thrown,'confidence above 100 still rejected');
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
a354(ckm_test_current_plugin_release($plugin),'plugin version');
$svc=file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
a354(str_contains($svc,"neg-arbiter-1.7"),'arbiter version bumped');
echo "NEG-ARBITER-VALIDATION-354 {$checks}/{$checks} PASS\n";

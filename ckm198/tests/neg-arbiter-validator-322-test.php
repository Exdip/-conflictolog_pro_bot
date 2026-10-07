<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__.'/fixtures/');
require dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-response-parser.php';
require dirname(__DIR__).'/modules/negotiation-master/ai/arbiter/arbiter-validator.php';
use CKM\NegotiationMaster\{ArbiterResponseParser,ArbiterValidator};
$checks=[];function v322(array &$c,bool $ok,string $label):void{$c[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$context=[
 'target_message'=>['id'=>41,'actor'=>'player','content'=>'Готов рассмотреть 40% предоплаты.'],
 'items'=>[['id'=>1,'code'=>'prepayment','value_type'=>'integer','unit'=>'percent']],
 'hidden_facts'=>[['id'=>2,'code'=>'payment_pressure','reveal_rules'=>['partial'=>'x','revealed'=>'y']]],
];
$payload=[
 'message'=>['message_id'=>41,'actor'=>'player'],
 'semantic_units'=>[['type'=>'willingness','text'=>'Готов рассмотреть 40%']],
 'events'=>[['event_type'=>'willing_to_consider','target_type'=>'item','target_code'=>'prepayment','confidence'=>.95,'payload'=>[]]],
 'fact_updates'=>[],
 'deal_updates'=>[['item_code'=>'prepayment','action'=>'acceptance_candidate','value'=>40,'proposed_by'=>'player','bundle_key'=>'','confidence'=>.9]],
 'rule_signals'=>[],
 'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low']
];
$validator=new ArbiterValidator();$out=$validator->validate($payload,$context);
v322($checks,$out['deal_updates'][0]['action']==='discussed','willingness is not acceptance');
v322($checks,count(array_filter($out['events'],fn($e)=>$e['event_type']==='willing_to_consider'))>=1,'willingness event retained');
$context['target_message']['content']='40% принимаем.';$payload['deal_updates'][0]['action']='acceptance_candidate';$out=$validator->validate($payload,$context);
v322($checks,$out['deal_updates'][0]['action']==='acceptance_candidate','explicit acceptance remains candidate');
$context['target_message']=['id'=>42,'actor'=>'player','content'=>'Предлагаю 20,5 млн при 40% предоплаты.'];
$context['items'][]=['id'=>3,'code'=>'price','value_type'=>'integer','unit'=>'RUB'];
$payload=[
 'message'=>['message_id'=>42,'actor'=>'player'],'semantic_units'=>[],
 'events'=>[['event_type'=>'package_offer_made','target_type'=>'session','target_code'=>'','confidence'=>.95,'payload'=>[]]],
 'fact_updates'=>[],
 'deal_updates'=>[
  ['item_code'=>'price','action'=>'proposed','value'=>20500000,'proposed_by'=>'player','bundle_key'=>'x','confidence'=>.95],
  ['item_code'=>'prepayment','action'=>'proposed','value'=>40,'proposed_by'=>'player','bundle_key'=>'y','confidence'=>.95]
 ],'rule_signals'=>[],'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low']
];
$out=$validator->validate($payload,$context);
v322($checks,$out['deal_updates'][0]['bundle_key']==='msg-42'&&$out['deal_updates'][1]['bundle_key']==='msg-42','package items share server-stable bundle');
$parser=new ArbiterResponseParser();$parsed=$parser->parse("```json\n{\"message\":{\"message_id\":42,\"actor\":\"player\"}}\n```");
v322($checks,(int)$parsed['message']['message_id']===42,'parser accepts fenced JSON');
$bad=$payload;$bad['fact_updates']=[['fact_code'=>'secret_unknown','suggested_level'=>'revealed','confidence'=>.99]];$out=$validator->validate($bad,$context);
v322($checks,count($out['fact_updates'])===0,'unknown hidden fact cannot be updated');

$context['target_message']=['id'=>43,'actor'=>'player','content'=>'А простой оборудования для вас важен?'];
$payload=[
 'message'=>['message_id'=>43,'actor'=>'player'],'semantic_units'=>[],
 'events'=>[['event_type'=>'interest_probe','target_type'=>'hidden_fact','target_code'=>'payment_pressure','confidence'=>.95,'payload'=>[]]],
 'fact_updates'=>[['fact_code'=>'payment_pressure','suggested_level'=>'revealed','confidence'=>.99,'reason'=>'probe','public_summary'=>'secret','discovery_event'=>'interest_discovered']],
 'deal_updates'=>[],'rule_signals'=>[],'dialogue_state'=>['tension'=>'normal','walkaway_risk'=>'low']
];
$out=$validator->validate($payload,$context);
v322($checks,$out['fact_updates'][0]['suggested_level']==='partial','player probe cannot self-confirm hidden fact');

$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks)." checks, ".count($failed)." failed.\n";exit($failed?1:0);

<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/alternative-value-service.php';
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/zopa-service.php';
require_once dirname(__DIR__) . '/modules/negotiation-master/evaluation/no-deal-evaluator.php';
use CKM\NegotiationMaster\AlternativeValueService;
use CKM\NegotiationMaster\ZopaService;
use CKM\NegotiationMaster\NoDealEvaluator;
$checks=0;$fails=0;
function b342(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}

$formal=['description'=>'Проверенная внешняя альтернатива','valuation'=>['model'=>'player_achievement_score','score'=>70]];
$m=AlternativeValueService::model($formal);
b342(($m['alternative_score']??null)===70.0,'formal alternative model is parsed');
b342(AlternativeValueService::model(['valuation'=>['model'=>'player_achievement_score','score'=>101]])===null,'out-of-range alternative score rejected');
b342((AlternativeValueService::compare(85,$formal,true)['relation']??'')==='better_than_alternative','deal above alternative classified correctly');
b342((AlternativeValueService::compare(50,$formal,true)['relation']??'')==='worse_than_alternative','deal below alternative classified correctly');
b342((AlternativeValueService::compare(50,$formal,false)['relation']??'')==='unknown','incomplete search never claims all deals are worse');

$item=[
 'code'=>'mode','title'=>'Режим','value_type'=>'boolean','unit'=>'','required_for_agreement'=>1,
 'player_boundary'=>[],'opponent_boundary'=>['hard_boundary'=>false],
 'player_target'=>['target'=>true],'opponent_target'=>['target'=>false],
 'player_preference_direction'=>'categorical','opponent_preference_direction'=>'categorical','importance_weight'=>1,'config'=>[],
];
$z=(new ZopaService())->analyze(['items'=>[$item],'scenario_rules'=>[],'player_card'=>['alternative'=>$formal]]);
b342(($z['feasibility']??'')==='open','structurally open space remains open');
b342(($z['search']['mutual']['complete']??false)===true,'small boolean search is complete');
b342(($z['alternative_comparison']['best_mutual']['relation']??'')==='worse_than_alternative','best feasible package is formally worse than alternative');
b342(($z['attractiveness']??'')==='worse_than_alternative','ZOPA attractiveness uses formal alternative when available');
b342(str_contains((string)($z['public']['summary']??''),'хуже отказа от сделки'),'post-game summary explains alternative comparison in Russian');

$zFinal=(new ZopaService())->analyze([
 'items'=>[$item],'scenario_rules'=>[],'player_card'=>['alternative'=>$formal],
 'final_agreement'=>['package'=>[['code'=>'mode','title'=>'Режим','value'=>false,'unit'=>'']]],
]);
b342(($zFinal['alternative_comparison']['final_agreement']['relation']??'')==='worse_than_alternative','actual final agreement is compared with alternative');

$ctx=[
 'session'=>['status'=>'completed_no_agreement_player'],
 'messages'=>array_fill(0,8,['actor'=>'player']),
 'events'=>[
  ['actor'=>'player','event_type'=>'interest_probe','payload'=>[]],['actor'=>'player','event_type'=>'constraint_probe','payload'=>[]],
  ['actor'=>'player','event_type'=>'alternative_probe','payload'=>[]],['actor'=>'player','event_type'=>'package_offer_made','payload'=>[]],
  ['actor'=>'player','event_type'=>'item_proposed','payload'=>['item_code'=>'mode']],
 ],
 'facts'=>[['code'=>'f','title'=>'Интерес','reveal_level'=>2]],'items'=>[$item],'zopa'=>$z,
];
$r=(new NoDealEvaluator())->classify($ctx);
b342(($r['result_type']??'')==='rational_walkaway','well-explored no-deal is rational when every checked agreement is worse than alternative');
b342(($r['alternative_relation']??'')==='worse_than_alternative','no-deal result records alternative relation');

$plain=(new ZopaService())->analyze(['items'=>[$item],'scenario_rules'=>[],'player_card'=>['alternative'=>['description'=>'Только описание']]]);
b342(empty($plain['alternative_comparison']['best_mutual']['available']),'legacy text-only alternatives remain supported without invented score');

$root=dirname(__DIR__);$plugin=file_get_contents($root.'/ckm-quiz-pro.php');$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');$eval=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');$builder=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
b342(ckm_test_current_plugin_release($plugin),'plugin version updated');
b342(str_contains($boot,'alternative-value-service.php'),'formal alternative service bootstrapped');
b342(ckm_test_declared_version_at_least($eval,'VERSION','neg-eval-1.3')&&str_contains($eval,"worse_than_alternative"),'evaluation version and agreement guard updated');
b342(str_contains($builder,'числом от 0 до 100'),'builder validates formal alternative score');

fwrite(STDOUT,sprintf("NEG-BATNA: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);

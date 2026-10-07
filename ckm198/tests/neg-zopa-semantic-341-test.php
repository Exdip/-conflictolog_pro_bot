<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/alternative-value-service.php';
require_once dirname(__DIR__) . '/modules/negotiation-master/domain/zopa-service.php';
require_once dirname(__DIR__) . '/modules/negotiation-master/evaluation/no-deal-evaluator.php';
use CKM\NegotiationMaster\ZopaService;
use CKM\NegotiationMaster\NoDealEvaluator;
$checks=0;$fails=0;
function z341(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}
$item=[
 'code'=>'price','title'=>'Цена','value_type'=>'integer','unit'=>'units','required_for_agreement'=>1,
 'player_boundary'=>['min'=>10],'opponent_boundary'=>['max'=>5],
 'player_target'=>['min'=>15],'opponent_target'=>['target'=>4],
 'player_preference_direction'=>'higher','opponent_preference_direction'=>'lower','importance_weight'=>1,'config'=>['opening'=>7],
];
$z=(new ZopaService())->analyze(['items'=>[$item],'scenario_rules'=>[],'player_alternative'=>['description'=>'Иная альтернатива без формальной полезности']]);
z341(($z['attractiveness']??'')==='outside_player_boundary','does not claim worse_than_batna without BATNA model');
z341(($z['attractiveness_basis']??'')==='player_boundary','basis names actual player boundary');
z341(!str_contains(json_encode($z,JSON_UNESCAPED_UNICODE),'worse_than_batna'),'legacy false BATNA label absent');
$ctx=[
 'session'=>['status'=>'completed_no_agreement_player'],
 'messages'=>array_fill(0,8,['actor'=>'player']),
 'events'=>[
  ['actor'=>'player','event_type'=>'interest_probe','payload'=>[]],
  ['actor'=>'player','event_type'=>'constraint_probe','payload'=>[]],
  ['actor'=>'player','event_type'=>'alternative_probe','payload'=>[]],
  ['actor'=>'player','event_type'=>'package_offer_made','payload'=>[]],
  ['actor'=>'player','event_type'=>'item_proposed','payload'=>['item_code'=>'price']],
 ],
 'facts'=>[['code'=>'f1','title'=>'Интерес','reveal_level'=>2]],
 'items'=>[$item+['current_value'=>['offers'=>['opponent'=>['value'=>5]]]]],
 'zopa'=>$z,
];
$r=(new NoDealEvaluator())->classify($ctx);
z341(($r['result_type']??'')==='rational_walkaway','rational walkaway remains available from boundary evidence');
z341(!str_contains((string)($r['reason']??''),'BATNA'),'public reason does not claim BATNA comparison');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
z341(ckm_test_current_plugin_release($plugin),'plugin version updated');
fwrite(STDOUT,sprintf("NEG-ZOPA-SEMANTIC-FIX: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);

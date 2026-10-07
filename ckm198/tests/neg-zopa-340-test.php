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
function z340(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}
function item(string $code,array $pB,array $oB,string $type='integer',array $config=[],array $pT=[],array $oT=[]):array{return[
    'code'=>$code,'title'=>$code,'value_type'=>$type,'unit'=>$type==='integer'?'units':'','required_for_agreement'=>1,
    'player_boundary'=>$pB,'opponent_boundary'=>$oB,'player_target'=>$pT,'opponent_target'=>$oT,
    'player_preference_direction'=>'higher','opponent_preference_direction'=>'lower','importance_weight'=>1,'config'=>$config,
];}

$z=new ZopaService();
$open=$z->analyze(['items'=>[item('price',['min'=>10],['max'=>20],'integer',['opening'=>15],['min'=>15],['target'=>12])],'scenario_rules'=>[]]);
z340(($open['feasibility']??'')==='open','overlapping numeric boundaries create open ZOPA');
z340(($open['public']['summary']??'')!=='','public ZOPA summary exists');
z340(isset($open['sample_package']['price']),'open ZOPA includes internal sample package');

$closed=$z->analyze(['items'=>[item('price',['min'=>10],['max'=>5])],'scenario_rules'=>[]]);
z340(($closed['feasibility']??'')==='closed','disjoint boundaries close ZOPA');
z340(count($closed['blocking_items']??[])===1,'closed ZOPA identifies blocking item');
z340(($closed['attractiveness']??'')==='outside_player_boundary','structurally possible but player-unacceptable space is marked outside player boundary');

$conditional=$z->analyze([
    'items'=>[
        item('price',['min'=>10],['max'=>20],'integer',['opening'=>12],['min'=>12],['target'=>12]),
        item('prepay',['min'=>0],['max'=>100],'integer',['opening'=>20],['min'=>20],['target'=>20]),
    ],
    'scenario_rules'=>[[
        'code'=>'D1','condition'=>['gte'=>[['item'=>'price'],0]],
        'action'=>['type'=>'require','gte'=>[['item'=>'prepay'],40]],
    ]],
]);
z340(($conditional['feasibility']??'')==='conditional','active package dependency creates conditional ZOPA');
z340(count($conditional['compensation_paths']??[])===1,'conditional ZOPA derives compensation path only from scenario rule');
z340(str_contains((string)($conditional['public']['compensation_paths'][0]['description']??''),'prepay'),'public compensation path names linked item');

$blocked=$z->analyze([
    'items'=>[[
        'code'=>'mode','title'=>'Режим','value_type'=>'select','unit'=>'','required_for_agreement'=>1,
        'player_boundary'=>[],'opponent_boundary'=>[],'player_target'=>['target'=>'x'],'opponent_target'=>['target'=>'x'],
        'player_preference_direction'=>'categorical','opponent_preference_direction'=>'categorical','importance_weight'=>1,
        'config'=>['options'=>['x'],'labels'=>['x'=>'X']],
    ]],
    'scenario_rules'=>[['code'=>'B1','condition'=>['eq'=>[['item'=>'mode'],'x']],'action'=>['type'=>'block']]],
]);
z340(($blocked['feasibility']??'')==='closed','hard block can close a complete categorical space');

$noDeal=new NoDealEvaluator();
$base=[
 'session'=>['status'=>'completed_no_agreement_player'],
 'messages'=>[
   ['actor'=>'player'],['actor'=>'opponent'],['actor'=>'player'],['actor'=>'opponent'],['actor'=>'player'],['actor'=>'opponent'],['actor'=>'player'],['actor'=>'opponent'],
 ],
 'events'=>[
   ['actor'=>'player','event_type'=>'interest_probe','payload'=>[]],
   ['actor'=>'player','event_type'=>'constraint_probe','payload'=>[]],
   ['actor'=>'player','event_type'=>'alternative_probe','payload'=>[]],
   ['actor'=>'player','event_type'=>'package_offer_made','payload'=>[]],
   ['actor'=>'player','event_type'=>'item_proposed','payload'=>['item_code'=>'price']],
 ],
 'facts'=>[['code'=>'f1','title'=>'Интерес','reveal_level'=>2]],
 'items'=>[[
   'code'=>'price','title'=>'Цена','required_for_agreement'=>1,'player_boundary'=>['min'=>10],
   'current_value'=>['offers'=>['opponent'=>['value'=>8]]],
 ]],
];
$ctx=$base;$ctx['zopa']=$open;
$r=$noDeal->classify($ctx);
z340(($r['result_type']??'')==='rational_walkaway','explored negotiation with counterpart outside player boundary can be rational walkaway');
z340(($r['counterpart_boundary_pressure']??0)===1,'no-deal evaluator counts counterpart boundary pressure');
z340(in_array(($r['player_contribution']??''),['low','medium','high'],true),'no-deal stores player contribution');

$early=$base;$early['messages']=[['actor'=>'player'],['actor'=>'opponent']];$early['events']=[];$early['facts']=[['code'=>'f1','title'=>'Интерес','reveal_level'=>0]];$early['zopa']=$open;
$er=$noDeal->classify($early);
z340(($er['result_type']??'')==='premature_walkaway','early exit with open ZOPA is premature');
z340(count($er['untested_paths']??[])>0,'premature exit reports untested paths');

$unavoidable=$base;$unavoidable['zopa']=$blocked;$unavoidable['items'][0]['current_value']=[];
$ur=$noDeal->classify($unavoidable);
z340(($ur['result_type']??'')==='unavoidable_no_deal','closed structural ZOPA produces unavoidable no-deal');

$harm=$base;$harm['zopa']=$open;$harm['events']=array_merge($base['events'],[
 ['actor'=>'player','event_type'=>'personal_attack','payload'=>[]],
 ['actor'=>'player','event_type'=>'ultimatum','payload'=>[]],
]);
$hr=$noDeal->classify($harm);
z340(($hr['result_type']??'')==='zopa_destroyed','high player contribution with open ZOPA can classify lost working space');
z340(($hr['player_contribution']??'')==='high','harmful conduct produces high contribution');

$opp=$harm;$opp['session']['status']='completed_no_agreement_opponent';
$or=$noDeal->classify($opp);
z340(($or['result_type']??'')==='opponent_walkaway_caused','opponent exit remains separately classified');

$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$ctxFile=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-context-builder.php');
$eval=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
z340(ckm_test_current_plugin_release($plugin),'plugin build updated');
z340(str_contains($boot,"domain/zopa-service.php"),'ZOPA service bootstrapped');
z340(str_contains($ctxFile, "'scenario_rules'=>\$scenarioRules"),'evaluation context includes formal scenario rules');
z340(ckm_test_declared_version_at_least($eval,'VERSION','neg-eval-1.3')&&str_contains($eval,"\$context['zopa']=self::isSales(\$context)?['public'=>[]]:\$this->zopa->analyze(\$context)"),'evaluation version and ZOPA integration updated');
z340(str_contains($player,'Можно ли было договориться') && str_contains($player,'id="ckm-neg-zopa-result"'),'post-game UI has Russian ZOPA section');
z340(str_contains($js,"summary.zopa")&&str_contains($js,"conditional:'Нужно было связать несколько условий'"),'client renders only post-game ZOPA summary');
z340(!str_contains($js,'sample_package')&&!str_contains($js,'structural_sample_package'),'internal feasible package never reaches live JS logic');

fwrite(STDOUT,sprintf("NEG-ZOPA: %d/%d PASS\n",$checks-$fails,$checks));
exit($fails?1:0);

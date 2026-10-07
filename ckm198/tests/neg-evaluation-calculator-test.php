<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
require dirname(__DIR__).'/modules/negotiation-master/evaluation/criterion-calculator.php';
require dirname(__DIR__).'/modules/negotiation-master/evaluation/no-deal-evaluator.php';
use CKM\NegotiationMaster\CriterionCalculator;use CKM\NegotiationMaster\NoDealEvaluator;
$checks=[];function t325(&$c,$ok,$label){$c[]=!!$ok;echo($ok?'PASS':'FAIL').": $label\n";}
$calc=new CriterionCalculator();
$ctx=['session'=>['status'=>'completed_agreement'],'final_agreement'=>['package'=>[['code'=>'price','value'=>20500000],['code'=>'prepayment','value'=>40],['code'=>'service_months','value'=>12],['code'=>'delivery_days','value'=>60]],'php_validation'=>['final_validation'=>['blocking'=>[]]]],'items'=>[
 ['code'=>'price','player_target'=>['min'=>20500000],'player_boundary'=>['min'=>19700000],'player_preference_direction'=>'higher','config'=>['opening'=>21800000],'opponent_target'=>['target'=>19500000]],
 ['code'=>'prepayment','player_target'=>['min'=>30],'player_boundary'=>['min'=>0],'player_preference_direction'=>'higher','config'=>['opening'=>50],'opponent_target'=>['target'=>20]],
 ['code'=>'service_months','player_target'=>['target'=>12],'player_boundary'=>['min'=>0],'player_preference_direction'=>'lower','config'=>['opening'=>12],'opponent_target'=>['target'=>24]],
 ['code'=>'delivery_days','player_target'=>['target'=>60],'player_boundary'=>['min'=>40],'player_preference_direction'=>'higher','config'=>['opening'=>60],'opponent_target'=>['target'=>45]],
],'facts'=>[['importance'=>1,'reveal_level'=>2,'code'=>'a'],['importance'=>1,'reveal_level'=>1,'code'=>'b']],'events'=>[],'completion'=>['red_line_breached'=>false]];
$econ=$calc->phpScore(['code'=>'economic_result'],$ctx,[]);t325($checks,$econ['raw_score']>=99,'target package receives near-max formal economic score');
$interest=$calc->phpScore(['code'=>'interest_discovery'],$ctx,[]);t325($checks,abs($interest['raw_score']-75)<0.001,'revealed + partial facts normalize to 75');
$ctx['completion']['red_line_breached']=true;$boundary=$calc->phpScore(['code'=>'boundary_protection'],$ctx,[]);t325($checks,$boundary['raw_score']===20.0,'final red-line breach strongly reduces boundary score');
$nd=new NoDealEvaluator();$early=['session'=>['status'=>'completed_no_agreement_player'],'messages'=>[['actor'=>'player']], 'events'=>[]];$r=$nd->classify($early);t325($checks,$r['result_type']==='premature_walkaway','very early no-deal is classified as premature');
$rational=['session'=>['status'=>'completed_no_agreement_player'],'messages'=>array_fill(0,6,['actor'=>'player']), 'events'=>[['actor'=>'player','event_type'=>'red_line_candidate']]];$r=$nd->classify($rational);t325($checks,$r['result_type']==='rational_walkaway'&&$r['economic_raw']>=75,'red-line-driven no-deal can be rational and retain economic value');
$fail=count(array_filter($checks,fn($x)=>!$x));echo count($checks)." checks, $fail failed.\n";exit($fail?1:0);

<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} 
require dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php';
use CKM\NegotiationMaster\AiEvaluator;
$rules=[['code'=>'argumentation','title'=>'Аргументация','weight'=>10,'rubric'=>[]]];
$ctx=['messages'=>[['id'=>1,'actor'=>'player','content'=>'Какие риски для вас критичны?']], 'facts'=>[], 'events'=>[], 'player_card'=>[], 'opponent_card'=>[], 'final_agreement'=>null,'completion'=>[]];
$good=json_encode(['evaluations'=>[['criterion_code'=>'argumentation','level'=>'strong','raw_score'=>74,'confidence'=>0.91,'evidence_message_ids'=>[1],'reason'=>'Аргумент связан с выяснением риска.']]],JSON_UNESCAPED_UNICODE);
$ai=new AiEvaluator(fn()=> $good);$r=$ai->evaluate($rules,$ctx);$ok=isset($r['argumentation'])&&$r['argumentation']['raw_score']===74.0&&$r['argumentation']['confidence']===0.91;echo($ok?'PASS':'FAIL').": valid criterion result accepted\n";
$bad=json_encode(['evaluations'=>[['criterion_code'=>'argumentation','level'=>'strong','raw_score'=>95,'confidence'=>0.95,'evidence_message_ids'=>[1],'reason'=>'bad band']]],JSON_UNESCAPED_UNICODE);
$checks=1;$failed=$ok?0:1;
function aiValidatorCheck(bool $ok,string $label):void{global $checks,$failed;$checks++;if(!$ok)$failed++;echo($ok?'PASS':'FAIL').": $label\n";}
$calls=0;$out=(new AiEvaluator(function()use(&$calls,$bad){$calls++;return$bad;}))->evaluate($rules,$ctx);
aiValidatorCheck(($out['argumentation']['level']??null)==='excellent'&&($out['argumentation']['raw_score']??null)===95.0&&$calls===1,'canonical server level replaces inconsistent model label without changing a valid score');
foreach([['raw_score'=>101,'criterion_code'=>'argumentation'],['raw_score'=>-1,'criterion_code'=>'argumentation'],['raw_score'=>'bad','criterion_code'=>'argumentation'],['raw_score'=>70,'criterion_code'=>'foreign_criterion']] as $row){
    $calls=0;$raw=wp_json_encode(['evaluations'=>[$row+['level'=>'strong','confidence'=>.9]]]);
    $out=(new AiEvaluator(function()use(&$calls,$raw){$calls++;return $raw;}))->evaluate($rules,$ctx);
    aiValidatorCheck($out===[]&&$calls===1,'invalid criterion or numeric score remains unresolved for service fallback: '.wp_json_encode($row));
}
$out=(new AiEvaluator(fn()=>wp_json_encode(['raw_score'=>74,'confidence'=>.15])))->evaluate($rules,$ctx);
aiValidatorCheck(($out['argumentation']['raw_score']??null)===74.0&&($out['argumentation']['confidence']??null)===.15,'self-confidence is metadata and cannot inflate or invalidate the numeric score');
echo "$checks checks, $failed failed\n";exit($failed?1:0);


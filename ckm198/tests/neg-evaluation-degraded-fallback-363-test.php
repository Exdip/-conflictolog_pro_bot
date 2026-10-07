<?php
require_once __DIR__ . '/support/plugin-release.php';
require_once __DIR__ . '/support/evaluation-rule-contract.php';
require_once dirname(__DIR__).'/modules/negotiation-master/evaluation/ai-evaluator.php';
$root=dirname(__DIR__);$checks=[];function e363(&$c,$ok,$m){$c[]=[$ok,$m];}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$ai=file_get_contents($root.'/modules/negotiation-master/evaluation/ai-evaluator.php');
$calc=file_get_contents($root.'/modules/negotiation-master/evaluation/criterion-calculator.php');
$svc=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
e363($checks,ckm_test_current_plugin_release($plugin),'plugin version');
e363($checks,ckm_test_declared_version_at_least($svc,'VERSION','neg-eval-1.6'),'current evaluator retains degraded fallback support');

// Replace only the remote transport; request budgeting and response validation remain production code.
$GLOBALS['e363_requests']=[];
function ckm_quiz_pro_aipuffer_post($body,$timeout){
    $GLOBALS['e363_requests'][]=$body;
    $payload=json_decode($body['messages'][1]['content'],true);
    $rows=[];foreach($payload['criteria'] as $rule)$rows[]=['criterion_code'=>$rule['code'],'raw_score'=>75,'confidence'=>.9,'reason'=>'Оценка по указанному evidence.','evidence_message_ids'=>[1]];
    return ['status'=>200,'body'=>wp_json_encode(['content'=>wp_json_encode(['evaluations'=>$rows])])];
}
function is_wp_error($response){return false;}
function wp_remote_retrieve_response_code($response){return $response['status'];}
function wp_remote_retrieve_body($response){return $response['body'];}
$ctx=['session'=>['status'=>'completed_agreement'],'messages'=>[['id'=>1,'actor'=>'player','content'=>'Предлагаю согласовать пакет.']], 'facts'=>[], 'events'=>[], 'items'=>[], 'player_card'=>[], 'opponent_card'=>[], 'final_agreement'=>null,'completion'=>[]];
$rule=['id'=>1,'code'=>'argumentation','title'=>'Аргументация','weight'=>25,'evaluation_type'=>'ai','rubric'=>[]];
$single=(new \CKM\NegotiationMaster\AiEvaluator())->evaluate([$rule],$ctx);
$rules=[$rule,array_replace($rule,['id'=>2,'code'=>'process_management']),array_replace($rule,['id'=>3,'code'=>'constraint_work'])];
$batch=(new \CKM\NegotiationMaster\AiEvaluator())->evaluate($rules,$ctx);
$budgets=array_column(array_column($GLOBALS['e363_requests'],'ai_params'),'max_completion_tokens');
e363($checks,count($budgets)===2&&min($budgets)>=700&&max($budgets)<=1350&&$budgets[0]<$budgets[1]&&count($single)===1&&count($batch)===3,'compact production AI budget adapts to single criterion and bounded batch size');
e363($checks,count(json_decode($GLOBALS['e363_requests'][0]['messages'][1]['content'],true)['criteria'])===1,'resumable service can request one criterion per attempt');
$low=(new \CKM\NegotiationMaster\AiEvaluator(fn()=>wp_json_encode(['raw_score'=>75,'confidence'=>.1])))->evaluate([$rule],$ctx);
e363($checks,($low['argumentation']['raw_score']??null)===75.0&&($low['argumentation']['confidence']??null)===.1,'self-confidence is metadata rather than a hard gate');
e363($checks,str_contains($calc,"'result_quality','interest_discovery'"),'result quality has formal/hybrid path');
e363($checks,str_contains($calc,'private function resultQuality'),'formal result-quality scorer exists');

$hybridRule=array_replace($rule,['code'=>'boundary_protection','evaluation_type'=>'hybrid']);
$hybrid=ckm_test_calculate_evaluation_rule($hybridRule,$ctx);
$formal=(new \CKM\NegotiationMaster\PhpEvaluator())->score($hybridRule,$ctx,[]);
e363($checks,$hybrid['result']['raw_score']===$formal['raw_score']&&$hybrid['result']['confidence']===.65,'hybrid degraded fallback uses real formal scorer without absent AI share');
e363($checks,$hybrid['result']['source']==='php_fallback'&&$hybrid['writes'][0]['row']['source']==='php_fallback','hybrid fallback source is explicit in public result and storage');
$weakCtx=array_replace($ctx,['session'=>['status'=>'completed_no_agreement']]);
$strongCtx=array_replace($weakCtx,['events'=>[['message_id'=>1,'actor'=>'player','event_type'=>'argument_made','payload'=>[]],['message_id'=>1,'actor'=>'player','event_type'=>'question_asked','payload'=>[]]]]);
$weak=ckm_test_calculate_evaluation_rule($rule,$weakCtx);
$strong=ckm_test_calculate_evaluation_rule($rule,$strongCtx);
e363($checks,$weak['result']['source']==='server_fallback'&&$weak['writes'][0]['row']['source']==='server_fallback'&&$weak['result']['confidence']===.5,'current AI-only fallback is explicitly marked as deterministic server result');
e363($checks,$strong['result']['raw_score']>$weak['result']['raw_score']&&$strong['result']['explanation']!==''&&$strong['result']['evidence_message_ids']===[1],'current AI-only fallback responds to validated events and carries real transcript evidence');
e363($checks,ckm_test_calculate_evaluation_rule($rule,$strongCtx)['result']===$strong['result'],'server fallback is deterministic for unchanged evidence');
$failed=0;foreach($checks as [$ok,$m]){echo ($ok?'PASS':'FAIL')." - $m\n";if(!$ok)$failed++;}echo count($checks)." checks, $failed failed\n";exit($failed?1:0);

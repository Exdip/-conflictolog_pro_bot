<?php
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
$root=dirname(__DIR__);$base=$root.'/modules/negotiation-master';$checks=[];
function c325(array &$checks,bool $ok,string $label):void{$checks[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
$files=['evaluation/evaluation-context-builder.php','evaluation/criterion-calculator.php','evaluation/php-evaluator.php','evaluation/ai-evaluator.php','evaluation/no-deal-evaluator.php','evaluation/result-builder.php','evaluation/evaluation-service.php'];
foreach($files as $f)c325($checks,is_file($base.'/'.$f),'evaluation file '.$f);
$main=file_get_contents($root.'/ckm-quiz-pro.php');$boot=file_get_contents($base.'/bootstrap.php');$controller=file_get_contents($base.'/api/session-controller.php');$service=file_get_contents($base.'/evaluation/evaluation-service.php');$calc=file_get_contents($base.'/evaluation/criterion-calculator.php');$ai=file_get_contents($base.'/evaluation/ai-evaluator.php');$ctx=file_get_contents($base.'/evaluation/evaluation-context-builder.php');$result=file_get_contents($base.'/evaluation/result-builder.php');$js=file_get_contents($base.'/assets/negotiation-session.js');$page=file_get_contents($base.'/public/player-page.php');$schema=json_decode(file_get_contents($base.'/schema.json'),true);
c325($checks,ckm_test_current_plugin_release($main),'plugin version bumped to dev.325');
c325($checks,str_contains($boot,'evaluation/evaluation-service.php')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.3.0'),'evaluation bootstrapped on compatible schema version');
c325($checks,isset($schema['evaluations'],$schema['evaluation_scores'],$schema['assignments'],$schema['assignment_participants'])&&count($schema)===17,'evaluation tables remain in the single current 17-table schema');
c325($checks,str_contains($controller,'/negotiation/results/sessions/(?P<id>\\d+)')&&str_contains($controller,'/evaluate'),'result and evaluate REST endpoints registered');
c325($checks,preg_match("/VERSION\s*=\s*'neg-eval-([0-9.]+)'/",$service,$evaluationVersion)&&version_compare($evaluationVersion[1],'1.3','>=')&&str_contains($service,'Evaluation weights must total 100'),'versioned evaluator checks total weight');
c325($checks,str_contains($service,'weighted_score')&&str_contains($service,'foreach($saved as $score)$total+=(float)$score[\'weighted_score\']'),'global score is calculated in PHP');
c325($checks,str_contains($ai,'Не рассчитывай общий балл /100')&&str_contains($ai,'raw_score'),'AI is criterion-local and forbidden to set global score');
c325($checks,str_contains($ai,'levelForScore')&&str_contains($ai,'if($raw<=20)return\'weak\'')&&str_contains($ai,"return'excellent'"),'server canonical level derives from normalized numeric score');
c325($checks,str_contains($ai,'normalizeConfidence')&&str_contains($service,'genericAiFallback')&&str_contains($service,'ai_degraded'),'resumable evaluator records explicit deterministic fallback instead of confidence-gated synchronous retry');
c325($checks,str_contains($ctx,'opponent_hidden_interests_json')&&str_contains($ctx,'completed_'),'post-game context may use full hidden card only after completion');
c325($checks,str_contains($calc,"'economic_result' => 'php'")&&str_contains($calc,"'argumentation' => 'ai'")&&str_contains($calc,'hybrid'),'legacy six-criterion source mapping present');
c325($checks,str_contains($calc,'final_agreement')&&str_contains($calc,'discovered_facts')===false,'formal calculators use final package and structured context');
c325($checks,str_contains($service,"'failed'")&&str_contains($service,'evaluation_status'),'failed qualitative evaluation does not fabricate a final score');
$nodeal=file_get_contents($base.'/evaluation/no-deal-evaluator.php'); c325($checks,str_contains($service,'agreement_weak')&&str_contains($nodeal,'rational_walkaway'),'deal quality and no-deal result types separated from score');
c325($checks,str_contains($service,'coach_counts')&&!str_contains($service,'-5'),'coach usage reported separately without score penalty');
c325($checks,str_contains($result,'criteria')&&str_contains($result,'display_score'),'player-safe result projection includes criteria and rounded display score');
c325($checks,str_contains($page,'По критериям')&&str_contains($page,'Сильные стороны')&&str_contains($page,'Что улучшить'),'completed UI has evaluation sections');
c325($checks,str_contains($js,'/results/sessions/')&&str_contains($page,'Повторить расчёт'),'browser loads and can retry evaluation');
c325($checks,str_contains($js,'Показать в диалоге')&&str_contains($js,'data-message-id'),'result evidence can navigate to transcript');
require_once __DIR__.'/support/evaluation-rule-contract.php';
$rule=['id'=>1,'code'=>'argumentation','title'=>'Аргументация','weight'=>25,'evaluation_type'=>'ai','sort_order'=>0];
$context=['session'=>['status'=>'completed_no_agreement'],'messages'=>[],'events'=>[],'items'=>[],'completion'=>[]];
$score=ckm_test_calculate_evaluation_rule($rule,$context,['raw_score'=>80.0,'confidence'=>.15,'reason'=>'Evidence-based score.','evidence_message_ids'=>[9],'level'=>'strong']);
c325($checks,$score['result']['raw_score']===80.0&&$score['result']['weighted_score']===20.0&&$score['result']['confidence']===.15,'production PHP calculates weighted contribution without confidence inflation');
c325($checks,count($score['writes'])===1&&$score['writes'][0]['table']==='wp_ckm_neg_evaluation_scores'&&$score['writes'][0]['row']['weighted_score']===20.0,'production persists weighted criterion in existing evaluation table');
$runtime='';foreach($files as $f)$runtime.=file_get_contents($base.'/'.$f);
foreach(['contract-supply','Алексей Петров','ТехноИмпульс'] as $needle)c325($checks,!str_contains($runtime,$needle),'no scenario literal in evaluation runtime: '.$needle);
$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks)." checks, ".count($failed)." failed.\n";exit($failed?1:0);

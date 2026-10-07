<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s523(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');
$work=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-workspace-service.php');
$seller=file_get_contents($root.'/modules/effective-sales/application/sales-ai-seller-service.php');
$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');
s523('plugin version',ckm_test_current_plugin_release($main));
s523('up to eight steps',str_contains($work,'MAX_SCENARIO_STEPS=8')&&str_contains($page,'цепочку до 8 последовательных шагов'));
s523('legacy one-step normalization',str_contains($work,'rawScenarioSteps')&&str_contains($work,"'id'=>'step_1'")&&str_contains($work,'syncLegacyScenarioFields'));
s523('step CRUD service',str_contains($work,'addScenarioStep')&&str_contains($work,'deleteScenarioStep'));
s523('step CRUD handlers',str_contains($page,'seller_scenario_step_add')&&str_contains($page,'seller_scenario_step_delete'));
s523('ordered step UI',str_contains($page,'ckm-sales-automation-steps')&&str_contains($page,'Добавить следующий шаг')&&str_contains($page,'Пауза после предыдущего шага'));
s523('manual run advances one step',str_contains($seller,'nextScenarioStep')&&str_contains($seller,'executeScenarioStep')&&str_contains($page,'Выполнить следующий шаг'));
s523('nested run state',str_contains($seller,"state['steps']")&&str_contains($seller,"state['completed']"));
s523('idle reply cancels remaining chain',str_contains($seller,"role']??''")&&str_contains($seller,'>$prevAt')) ;
s523('step metadata in transcript and logs',str_contains($seller,'scenario_step_id')&&str_contains($work,'scenario_step_number'));
s523('human takeover still pauses',str_contains($seller,"control_mode']??'ai'")&&str_contains($seller,"==='human'")&&str_contains($seller,'scenarioDue'));
s523('responsive chain CSS',str_contains($css,'.ckm-sales-automation-step{')&&str_contains($css,'.ckm-sales-automation-step-form{'));

if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
require_once $root.'/modules/effective-sales/application/sales-ai-seller-service.php';
$ref=new ReflectionClass('CKM\\EffectiveSales\\SalesAiSellerService');
$next=$ref->getMethod('nextScenarioStep');$next->setAccessible(true);
$due=$ref->getMethod('scenarioDue');$due->setAccessible(true);
$now=1700000000;
$scenario=['id'=>'flow_x','enabled'=>true,'trigger'=>'idle','trigger_stage'=>'new','steps'=>[
 ['id'=>'s1','delay_minutes'=>15,'action'=>'message','message'=>'Первое','target_stage'=>'qualified'],
 ['id'=>'s2','delay_minutes'=>1440,'action'=>'message','message'=>'Второе','target_stage'=>'qualified'],
 ['id'=>'s3','delay_minutes'=>0,'action'=>'handoff','message'=>'Подключаю человека','target_stage'=>'qualified'],
]];
$base=['status'=>'active','control_mode'=>'ai','pipeline_stage'=>'new','created_at'=>$now-2000,'pipeline_stage_changed_at'=>$now-2000,'transcript'=>[['role'=>'assistant','at'=>$now-901]],'automation_runs'=>[]];
$n=$next->invoke(null,$base,$scenario);s523('runtime next starts at step 1',is_array($n)&&($n['index']??-1)===0&&($n['step']['id']??'')==='s1');
s523('runtime first delay due',(bool)$due->invoke(null,$base,$scenario,$now));
$after1=$base;$after1['automation_runs']=['flow_x'=>['started_at'=>$now-86500,'steps'=>['s1'=>['at'=>$now-86500,'action'=>'message']]]];$after1['transcript']=[['role'=>'assistant','at'=>$now-86500,'automation'=>true]];
$n2=$next->invoke(null,$after1,$scenario);s523('runtime advances to step 2',is_array($n2)&&($n2['index']??-1)===1&&($n2['step']['id']??'')==='s2');
s523('runtime relative delay due',(bool)$due->invoke(null,$after1,$scenario,$now));
$answered=$after1;$answered['transcript'][]=['role'=>'user','at'=>$now-86000];s523('runtime client reply stops idle chain',!(bool)$due->invoke(null,$answered,$scenario,$now));
$human=$after1;$human['control_mode']='human';s523('runtime human takeover pauses chain',!(bool)$due->invoke(null,$human,$scenario,$now));
$legacy=$base;$legacy['automation_runs']=['flow_x'=>['at'=>$now-100,'action'=>'message']];s523('runtime old .522 run stays complete',$next->invoke(null,$legacy,$scenario)===null);
$stageScenario=$scenario;$stageScenario['trigger']='stage';$stageScenario['trigger_stage']='qualified';$stageScenario['steps'][0]['delay_minutes']=1;$stage=$base;$stage['pipeline_stage']='qualified';$stage['pipeline_stage_changed_at']=$now-61;s523('runtime stage trigger preserved',(bool)$due->invoke(null,$stage,$stageScenario,$now));
echo "{$passed} SALES-523 scenario-chain checks passed. No database required.\n";

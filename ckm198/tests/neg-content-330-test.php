<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$migration=file_get_contents($root.'/modules/negotiation-master/content-migration.php');
$meta=file_get_contents($root.'/modules/negotiation-master/content/public-product-meta.php');
if(!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
$pack=require $root.'/modules/negotiation-master/content/system-v1.php';
$checks=[];
function t330(&$c,$name,$ok){$c[]=$name;if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}
t330($checks,'plugin version',ckm_test_current_plugin_release($plugin));
t330($checks,'schema stable content bumped',ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.5.0')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_CONTENT_VERSION','1.4.0'));
t330($checks,'pack version',version_compare((string)($pack['pack_version']??'0'),'1.4.0','>='));
t330($checks,'no coming soon stubs',isset($pack['scenario_stubs'])&&count($pack['scenario_stubs'])===0);
$conflict=array_values(array_filter($pack['scenarios'],fn($e)=>(($e['scenario']['library_slug']??'')==='conflict-negotiation')));
t330($checks,'ten conflict scenarios',count($conflict)===10);
t330($checks,'all conflict scenarios published',count(array_filter($conflict,fn($e)=>(($e['scenario']['status']??'')==='published'&&($e['version']['status']??'')==='published')))===10);
$expected=['valuable-employee-exit','last-word-mine','decide-for-me','idea-without-voice','toxic-by-team','impossible-by-friday','crm-over-my-dead-body','who-brings-money','grey-cardinal','trust-but-report'];
$by=[];foreach($conflict as $e)$by[$e['scenario']['slug']]=$e;
t330($checks,'all ten slugs present',count(array_diff($expected,array_keys($by)))===0);
foreach(array_slice($expected,1) as $slug){
 $e=$by[$slug]??null;
 t330($checks,$slug.' manifest',is_array($e)&&$e['expected']['items']===6&&$e['expected']['hidden_facts']===4&&$e['expected']['rules']===6&&$e['expected']['evaluation_rules']===7&&$e['expected']['evaluation_weight']===100);
 t330($checks,$slug.' source provenance',isset($e['version']['mechanics_json']['source_section'],$e['version']['mechanics_json']['source_note'])&&str_contains($e['version']['mechanics_json']['source_note'],'учебной конструкцией'));
 t330($checks,$slug.' runtime enabled',($e['version']['mechanics_json']['runtime_enabled']??false)===true);
 t330($checks,$slug.' unique component codes',count(array_unique(array_column($e['components']['items'],'code')))===6&&count(array_unique(array_column($e['components']['hidden_facts'],'code')))===4&&count(array_unique(array_column($e['components']['rules'],'code')))===6&&count(array_unique(array_column($e['components']['evaluation_rules'],'code')))===7);
}
t330($checks,'upgrade promotion path retained',str_contains($migration,'$promotingStub')&&str_contains($migration,"status='published'")&&str_contains($migration,"current_version_id IS NULL"));
t330($checks,'public cards still cover library',str_contains($meta,"'last-word-mine'")&&str_contains($meta,"'trust-but-report'")&&str_contains($meta,"'grey-cardinal'"));
$runtimeFiles=[
 'application/session-service.php','application/message-service.php','application/recovery-service.php','domain/rule-engine.php',
 'ai/opponent/opponent-service.php','ai/arbiter/arbiter-service.php','ai/coach/coach-service.php','evaluation/evaluation-service.php'
];
$runtime='';foreach($runtimeFiles as $f)$runtime.=file_get_contents($root.'/modules/negotiation-master/'.$f);
foreach($expected as $slug)t330($checks,'no runtime slug branch '.$slug,!str_contains($runtime,$slug));
t330($checks,'no new payment provider marker',!str_contains($runtime,'YooKassa')&&!str_contains($runtime,'T-Bank'));
echo 'PASS '.count($checks)."/".count($checks)."\n";

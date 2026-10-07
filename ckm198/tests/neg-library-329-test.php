<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$boot=file_get_contents($root.'/modules/negotiation-master/bootstrap.php');
$schema=json_decode(file_get_contents($root.'/modules/negotiation-master/schema.json'),true);
$access=file_get_contents($root.'/modules/negotiation-master/application/library-access-service.php');
$session=file_get_contents($root.'/modules/negotiation-master/application/session-service.php');
$product=file_get_contents($root.'/modules/negotiation-master/public/product-catalog.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$controller=file_get_contents($root.'/modules/negotiation-master/api/session-controller.php');
$meta=file_get_contents($root.'/modules/negotiation-master/content/public-product-meta.php');
if(!defined('ABSPATH')) define('ABSPATH',__DIR__.'/');
$pack=require $root.'/modules/negotiation-master/content/system-v1.php';
$checks=[];
function t329(&$c,$name,$ok){$c[]=$name;if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}
t329($checks,'version',ckm_test_current_plugin_release($plugin));
t329($checks,'schema versions',ckm_test_declared_version_at_least($boot,'CKM_NEG_DB_VERSION','1.5.0')&&ckm_test_declared_version_at_least($boot,'CKM_NEG_CONTENT_VERSION','1.3.0'));
t329($checks,'library schema retained after later tables',count($schema)>=15&&isset($schema['libraries']));
t329($checks,'scenario library foreign reference field',isset($schema['scenarios']['columns']['library_id'])&&isset($schema['scenarios']['indexes']['library']));
t329($checks,'library fields',isset($schema['libraries']['columns']['access_type'],$schema['libraries']['columns']['product_key'],$schema['libraries']['columns']['visibility']));
t329($checks,'original six system libraries retained',isset($pack['libraries'])&&count($pack['libraries'])>=6);
$libs=array_column($pack['libraries'],null,'slug');
t329($checks,'basic free library',isset($libs['basic'])&&$libs['basic']['access_type']==='free'&&$libs['basic']['status']==='published');
t329($checks,'conflict paid library',isset($libs['conflict-negotiation'])&&$libs['conflict-negotiation']['access_type']==='paid'&&$libs['conflict-negotiation']['product_key']==='neg_library_conflict');
t329($checks,'former future libraries are published',isset($libs['price-question'],$libs['business-talk'],$libs['intersection-of-interests'],$libs['share-of-influence'])&&count(array_filter(array_intersect_key($libs,array_flip(['price-question','business-talk','intersection-of-interests','share-of-influence'])),fn($v)=>$v['status']==='published'))===4);
t329($checks,'coming soon stubs replaced by published scenarios',isset($pack['scenario_stubs'])&&count($pack['scenario_stubs'])===0);
$valuable=null; foreach($pack['scenarios'] as $entry){if(($entry['scenario']['slug']??'')==='valuable-employee-exit')$valuable=$entry;}
t329($checks,'valuable employee published',is_array($valuable)&&$valuable['scenario']['library_slug']==='conflict-negotiation'&&$valuable['scenario']['status']==='published');
t329($checks,'valuable employee manifest',is_array($valuable)&&$valuable['expected']['items']===6&&$valuable['expected']['hidden_facts']===4&&$valuable['expected']['rules']===6&&$valuable['expected']['evaluation_rules']===7&&$valuable['expected']['evaluation_weight']===100);
t329($checks,'source adaptation note',str_contains((string)$valuable['version']['mechanics_json']['source_note'],'адаптирована'));
t329($checks,'base scenarios assigned to basic',count(array_filter(array_slice($pack['scenarios'],0,3),fn($e)=>($e['scenario']['library_slug']??'')==='basic'))===3);
t329($checks,'library access service',str_contains($access,'final class LibraryAccessService')&&str_contains($access,'ckm_neg_library_entitled')&&str_contains($access,'LibraryAccessDeniedException'));
t329($checks,'paid start maps to 403',str_contains($controller,'NEG_LIBRARY_ACCESS_REQUIRED')&&str_contains($controller,'LibraryAccessDeniedException'));
t329($checks,'private library slug is hidden',str_contains($product,"=== 'private' && empty(\$context['admin'])"));
t329($checks,'server start enforcement',str_contains($session,'assertScenarioAccess($scenario)'));
t329($checks,'no payment provider added',!str_contains($access,'YooKassa')&&!str_contains($access,'T-Bank'));
t329($checks,'catalog groups libraries',str_contains($product,'public static function libraries()')&&str_contains($product,'public static function libraryCards'));
t329($checks,'catalog does not select hidden scenario fields',!str_contains($product,'opponent_hidden_interests_json')&&!str_contains($product,'opponent_constraints_json')&&!str_contains($product,'reveal_rules_json'));
t329($checks,'library UI',str_contains($page,'Библиотеки сценариев')&&str_contains($page,'Отдельная лицензия')&&str_contains($page,'Сценарий готовится к публикации'));
t329($checks,'public metadata contains all ten conflict cards',str_contains($meta,"'valuable-employee-exit'")&&str_contains($meta,"'trust-but-report'")&&str_contains($meta,"'grey-cardinal'")&&substr_count($meta,"'category'")>=13);
echo 'PASS '.count($checks)."/".count($checks)."\n";

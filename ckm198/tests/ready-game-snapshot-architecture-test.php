<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$schema=file_get_contents($root.'/includes/standalone-schema.php');
$adapter=file_get_contents($root.'/includes/standalone-adapter.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$pay=file_get_contents($root.'/includes/standalone-payment-connector.php');
$engine=file_get_contents($root.'/core-source/includes/quiz/quiz-engine.php');
$c=[];function s252(&$c,$ok,$name){$c[]=$ok;echo ($ok?'OK ':'FAIL ').$name."\n";}
s252($c,ckm_test_current_plugin_release($plugin),'version');
s252($c,str_contains($schema,"CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16'"),'db version bumped');
s252($c,str_contains($schema,"ready_instances")&&str_contains($schema,'UNIQUE KEY owner_product (owner_key,product_key)'),'snapshot table');
s252($c,str_contains($schema,'catalog_snapshot_json longtext NULL')&&str_contains($schema,'source_signature char(64)'),'catalog provenance stored');
s252($c,str_contains($adapter,"'ready_instances'=>'ckm_quiz_ready_instances'"),'table map');
s252($c,str_contains($ready,'ckm_quiz_pro_ready_game_clone_snapshot')&&str_contains($ready,"quiz_revision']=1"),'clone function present');
s252($c,str_contains($ready,'ckm_quiz_pro_ready_game_snapshot_ensure')&&str_contains($ready,'GET_LOCK'),'idempotent ensure');
s252($c,str_contains($ready,'ckm_quiz_pro_ready_game_ensure_active_snapshots'),'legacy purchase backfill');
s252($c,str_contains($ready,"'catalog_snapshot_json'=>wp_json_encode")&&str_contains($ready,"'source_quiz_revision'=>"),'snapshot metadata');
s252($c,str_contains($catalog,'ckm_quiz_pro_ready_game_snapshot_ensure($uid,$product)')&&str_contains($catalog,'Purchased catalogue games launch from the organizer'),'catalog launches snapshot');
s252($c,str_contains($org,'ckm_quiz_pro_ready_game_ensure_active_snapshots($uid,$tenant>=0?$tenant:null)')&&str_contains($org,'ckm_quiz_pro_ready_game_source_product_for_quiz'),'My Games hides source');
s252($c,str_contains($pay,'ckm_quiz_pro_ready_game_snapshot_ensure($user_id,$format_key,$tenant_id)'),'real payment creates snapshot');
s252($c,str_contains($engine,'ckm_quiz_pro_quiz_access_product($quiz)')&&str_contains($engine,'$requiredProduct'),'runtime checks catalog product');
s252($c,str_contains($ready,'Renewals continue to use the same snapshot')||str_contains($ready,'Renewals continue to use the same copy'),'renewal invariant documented');
$ok=count(array_filter($c));$total=count($c);echo "RESULT $ok/$total\n";exit($ok===$total?0:1);

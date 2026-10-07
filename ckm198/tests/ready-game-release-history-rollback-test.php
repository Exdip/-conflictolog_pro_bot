<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$s=file_get_contents($root.'/includes/standalone-schema.php');
$a=file_get_contents($root.'/includes/standalone-adapter.php');
$checks=[];
function r264(&$c,$ok,$label){$c[]=[$ok,$label];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
r264($checks,ckm_test_current_plugin_release($p),'version');
r264($checks,str_contains($s,"CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16'"),'db schema version bumped');
r264($checks,str_contains($s,"ready_game_releases"),'release history table created');
r264($checks,str_contains($a,"'ready_game_releases'=>'ckm_quiz_ready_game_releases'"),'release table mapped');
r264($checks,str_contains($c,'function ckm_quiz_pro_ready_game_release_rows'),'release history reader');
r264($checks,str_contains($c,'function ckm_quiz_pro_ready_game_append_release_event'),'append-only release logger');
r264($checks,str_contains($c,"'action'=>'rollback'" ) || str_contains($c,"'rollback'"),'rollback action recorded');
r264($checks,str_contains($c,'function ckm_quiz_pro_ready_game_rollback_release'),'rollback function');
r264($checks,str_contains($c,'source_release_id'),'rollback source preserved');
r264($checks,str_contains($c,'function ckm_quiz_pro_ready_game_admin_releases'),'admin release history screen');
r264($checks,str_contains($c,'Вернуть в каталог'),'admin rollback control');
r264($checks,str_contains($c,"action==='releases'"),'release history route');
r264($checks,str_contains($c,'ckm_quiz_pro_ready_game_release_rollback'),'nonce protected rollback endpoint');
r264($checks,str_contains($c,'Уже купленные snapshot-копии организаторов остаются неизменными'),'snapshot preservation explained');
r264($checks,str_contains($c,'release_no'),'live release number stored');
r264($checks,str_contains($c,"ckm_quiz_pro_ready_release_history_seed_v1"),'existing releases migration bridge');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);

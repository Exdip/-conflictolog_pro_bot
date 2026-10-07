<?php
require_once __DIR__ . '/support/plugin-release.php';
$org=file_get_contents(__DIR__.'/../includes/standalone-organizer.php');
$main=file_get_contents(__DIR__.'/../ckm-quiz-pro.php');
$checks=[];
function c255(&$c,$ok,$label){$c[]=$ok;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
c255($checks,ckm_test_current_plugin_release($main),'version');
c255($checks,str_contains($org,'<h2>Базовые игры</h2>') && str_contains($org,'<h2>Готовые игры</h2>') && str_contains($org,'<h2>Собственные игры</h2>'),'three library sections');
c255($checks,str_contains($org,"baseProductKeys=['classic_quiz','chgk_v1','jeopardy_v1','decision_price_v1','negotiation_duel_v1']"),'base product boundary');
c255($checks,str_contains($org,'ckm_quiz_pro_game_access_lifecycle_info'),'active/expired lifecycle');
c255($checks,str_contains($org,'SELECT snapshot_quiz_id,product_key,catalog_revision,catalog_snapshot_json,created_at') && str_contains($org,"ckm_quiz_pro_table('ready_instances')"),'ready snapshots source');
c255($checks,str_contains($org,"scopeType==='private' && !") && str_contains($org,'own[]=$row'),'own games persist independently');
c255($checks,str_contains($org,'Готовые и собственные игры сохраняются даже после окончания доступа.'),'expiry persistence copy');
c255($checks,str_contains($org,'? $cabinetActive : !empty'),'launch gate by type');
c255($checks,str_contains($org,'Возобновить доступ') && str_contains($org,'Продлить доступ'),'renew actions');
c255($checks,str_contains($org,'ckm_quiz_pro_can_edit_owned_quiz($r,$uid)'),'admin-only own edit');
c255($checks,str_contains($org,'Базовые</span><strong>') && str_contains($org,'Готовые</span><strong>') && str_contains($org,'Собственные</span><strong>'),'summary counts');
c255($checks,str_contains($org,'ckm_quiz_pro_org_library_launch_args'),'shared launch args');
echo 'OK '.count($checks).'/'.count($checks)."\n";

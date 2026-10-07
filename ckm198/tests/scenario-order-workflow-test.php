<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$schema=file_get_contents($root.'/includes/standalone-schema.php');
$adapter=file_get_contents($root.'/includes/standalone-adapter.php');
$orders=file_get_contents($root.'/includes/scenario-orders.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$admin=file_get_contents($root.'/includes/standalone-admin.php');
$checks=[];
function c257(&$c,$ok,$name){$c[]=!!$ok; echo ($ok?'PASS ':'FAIL ').count($c).' '.$name."\n";}
c257($checks,ckm_test_current_plugin_release($main),'version');
c257($checks,str_contains($main,"includes/scenario-orders.php"),'module loaded');
c257($checks,str_contains($schema,"scenario_orders") && str_contains($schema,"ready_game_post_id"),'mysql table');
c257($checks,str_contains($adapter,"'scenario_orders'=>'ckm_quiz_scenario_orders'"),'table map');
c257($checks,str_contains($orders,'function ckm_quiz_pro_scenario_order_create') && str_contains($orders,"'status'=>'new'"),'order persisted');
c257($checks,str_contains($orders,"add_submenu_page('ckm-quiz-pro','Заказы сюжетов'") && str_contains($orders,'ckm_quiz_pro_scenario_orders_admin_page'),'admin queue');
c257($checks,str_contains($orders,"'in_work'=>'В работе'") && str_contains($orders,"'ready'=>'Готово'") && str_contains($orders,"'cancelled'=>'Отменён'"),'statuses');
c257($checks,str_contains($orders,'ready_game_post_id') && str_contains($orders,'Комментарий организатору'),'admin link and note');
c257($checks,str_contains($orders,"['action'=>'new','scenario_order'=>"),'create ready game from order');
c257($checks,str_contains($org,'ckm_quiz_pro_scenario_order_create') && str_contains($org,'Заказанные сюжеты'),'organizer create and history');
c257($checks,str_contains($org,'ckm_quiz_pro_scenario_order_status_label') && str_contains($org,'Открыть готовую игру'),'organizer status and ready link');
c257($checks,str_contains($ready,'scenario_order_id') && str_contains($ready,"orderStatus=") && str_contains($ready,"'publish'?'ready':'in_work'"),'ready game auto-links order');
c257($checks,str_contains($admin,'ckm-quiz-pro-scenario-orders') && str_contains($admin,'Заказы сюжетов'),'dashboard card');
c257($checks,str_contains($orders,'wp_mail') && str_contains($orders,'The order itself is already safely stored'),'email is notification only');
$ok=count(array_filter($checks)); echo "RESULT $ok/".count($checks)."\n"; exit($ok===count($checks)?0:1);

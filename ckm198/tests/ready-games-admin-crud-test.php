<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$admin=file_get_contents($root.'/includes/standalone-admin.php');
$checks=[];
function c251(&$c,$ok,$name){$c[]=$ok;echo ($ok?'OK ':'FAIL ').$name."\n";}
c251($checks,ckm_test_current_plugin_release($plugin),'version');
c251($checks,str_contains($ready,"add_submenu_page(\n        'ckm-quiz-pro'") && str_contains($ready,"'ckm-quiz-pro-ready-games'"),'dedicated admin menu');
c251($checks,str_contains($ready,"'show_in_menu'=>false"),'native CPT menu hidden');
c251($checks,str_contains($ready,'Добавить готовую игру') && str_contains($ready,"['action'=>'add']"),'add action visible');
c251($checks,str_contains($ready,'Редактировать карточку') && str_contains($ready,"['action'=>'edit'"),'edit card action visible');
c251($checks,str_contains($ready,'Редактировать игру') && str_contains($ready,'ckm-quiz-pro-edit&quiz='),'edit game-template action visible');
c251($checks,str_contains($ready,'ckm_quiz_pro_ready_game_delete') && str_contains($ready,'wp_delete_post($postId,true)'),'delete action');
c251($checks,str_contains($ready,"admin_post_ckm_quiz_pro_ready_game_save") && str_contains($ready,'wp_insert_post($postData,true)'),'create/update handler');
c251($checks,str_contains($ready,"current_user_can('manage_options')"),'admin-only guard');
c251($checks,str_contains($ready,'Цена, ₽ / 30 дней') && str_contains($ready,'Сюжет / ситуация') && str_contains($ready,'Раунды / этапы'),'catalog fields');
c251($checks,str_contains($ready,'Создать новый шаблон') && str_contains($ready,'Игровой шаблон'),'template selection/create');
c251($checks,str_contains($admin,'Каталог готовых игр') && str_contains($admin,'Добавлять и редактировать'),'dashboard shortcut');
$ok=count(array_filter($checks));$total=count($checks);echo "RESULT $ok/$total\n";exit($ok===$total?0:1);

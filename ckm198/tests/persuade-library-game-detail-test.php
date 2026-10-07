<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$main=file_get_contents($root.'/includes/standalone-organizer.php');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function ok248(&$checks,$cond,$label){$checks[]=[$cond,$label]; if(!$cond){fwrite(STDERR,"FAIL: $label\n");}}
ok248($checks,str_contains($catalog,'ckm_quiz_pro_render_persuade_library_detail'),'detail renderer exists');
ok248($checks,str_contains($ready,'Десятиклассница Лера') && str_contains($ready,'Переговорщик | Лера') && str_contains($ready,'Оппонент | Виктор Палыч'),'situation and roles shown');
ok248($checks,str_contains($ready,'Р1 · Удержи цель') && str_contains($ready,'Р4 · Проверь историю'),'four rounds described');
ok248($checks,str_contains($ready,'Максимум одного раунда — 70') && str_contains($ready,'всей игры — 280'),'280 scoring explained');
ok248($checks,str_contains($catalog,"add_query_arg('game',\$runtime,ckm_quiz_pro_persuade_library_url())") && str_contains($catalog,'>Подробнее</a>'),'details link added');
ok248($checks,str_contains($catalog,'ckm_quiz_pro_persuade_library_action'),'shared card/detail action resolver');
ok248($checks,ckm_test_current_plugin_release($plugin),'current plugin release header and constant are synchronized');
ok248($checks,!str_contains($main,"'persuade_school_grade_v1'=>["),'specific school game still absent from main organizer catalog registry');
$failed=array_filter($checks,fn($x)=>!$x[0]);
echo (count($checks)-count($failed)).'/'.count($checks)." checks passed\n";
exit($failed?1:0);

<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$a=file_get_contents($root.'/includes/standalone-admin.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function t261(&$c,$ok,$label){$c[]=[$ok,$label]; if(!$ok) fwrite(STDERR,"FAIL: $label\n");}
t261($checks,ckm_test_current_plugin_release($p),'version bumped');
t261($checks,str_contains($c,"function ckm_quiz_pro_ready_game_admin_test_launch"),'test launch handler exists');
t261($checks,str_contains($c,"\$action==='test-launch'"),'admin route exists');
t261($checks,str_contains($c,"'test_mode'=>1"),'ready game launch is test_mode');
t261($checks,str_contains($c,"не требует оплаты, не расходует доступ"),'admin explains no access consumption');
t261($checks,str_contains($c,"AND test_mode=0"),'commercial usage excludes tests');
t261($checks,str_contains($c,"AND test_mode=1"),'test usage counted separately');
t261($checks,str_contains($c,"'test_games'=>0"),'separate test counter exists');
t261($checks,substr_count($c,'Тестовый запуск')>=4,'test launch exposed across admin surfaces');
t261($checks,str_contains($a,"'test_mode'=>1"),'legacy admin test launch is true test mode');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);

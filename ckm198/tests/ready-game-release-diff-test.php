<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function r265(&$c,$ok,$label){$c[]=[$ok,$label];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
r265($checks,ckm_test_current_plugin_release($p),'version');
r265($checks,str_contains($c,'function ckm_quiz_pro_ready_game_compare_to_release'),'comparison engine');
r265($checks,str_contains($c,'function ckm_quiz_pro_ready_game_admin_compare'),'comparison screen');
r265($checks,str_contains($c,"action==='compare'"),'comparison route');
r265($checks,str_contains($c,'Сравнить с релизом'),'editor compare button');
r265($checks,str_contains($c,'Сравнить с рабочей версией'),'release-history compare button');
r265($checks,str_contains($c,"'title'=>'Название'"),'title diff');
r265($checks,str_contains($c,"'situation'=>'Сюжет / ситуация'"),'situation diff');
r265($checks,str_contains($c,"'rounds'=>'Раунды / этапы'"),'round diff');
r265($checks,str_contains($c,"'price'=>'Цена'"),'price diff');
r265($checks,str_contains($c,"'key'=>'source_quiz'"),'source quiz revision diff');
r265($checks,str_contains($c,'Они не попадут к новым покупателям'),'stable release explanation');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);

<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function q262(&$c,$ok,$label){$c[]=[$ok,$label];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
q262($checks,ckm_test_current_plugin_release($p),'version');
q262($checks,str_contains($c,'function ckm_quiz_pro_ready_game_qa_fingerprint'),'fingerprint');
q262($checks,str_contains($c,'function ckm_quiz_pro_ready_game_qa_status'),'qa status');
q262($checks,str_contains($c,'function ckm_quiz_pro_ready_game_render_qa'),'qa ui');
q262($checks,str_contains($c,"_ckm_ready_qa_last_test_game_id"),'test link');
q262($checks,str_contains($c,"_ckm_ready_qa_last_test_fingerprint"),'test fingerprint');
q262($checks,str_contains($c,"admin_post_ckm_quiz_pro_ready_game_test_pass"),'pass endpoint');
q262($checks,str_contains($c,"(int)\$game['test_mode']!==1") && str_contains($c,"(string)\$game['status']!=='finished'"),'finished test required');
q262($checks,str_contains($c,"_ckm_ready_qa_passed_fingerprint"),'pass fingerprint stored');
q262($checks,str_contains($c,"_ckm_ready_qa_passed_game_id"),'pass game stored');
q262($checks,str_contains($c,"empty(\$qa['passed'])"),'publish checks qa');
q262($checks,str_contains($c,"'ready_for_publish'=>!empty(\$validation['ready']) && !empty(\$integrity['ok']) && !empty(\$qa['passed'])"),'public registry gate');
q262($checks,str_contains($c,"'orderable'=>\$status==='publish' && !empty(\$validation['ready']) && !empty(\$integrity['ok']) && !empty(\$qa['passed'])"),'purchase gate');
q262($checks,str_contains($c,'После последнего пройденного теста карточка или игровой шаблон были изменены'),'stale explanation');
q262($checks,str_contains($c,'Подтвердить: тест пройден'),'admin confirm button');
q262($checks,str_contains($c,'qa_blocked'),'blocked publish notice');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);

<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$cat=file_get_contents($root.'/includes/games-catalog.php');
$adm=file_get_contents($root.'/includes/standalone-admin.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function oe273(&$c,$ok,$label){$c[]=[$ok,$label]; echo ($ok?'PASS ':'FAIL ').$label."\n";}
oe273($checks,ckm_test_current_plugin_release($main),'version marker');
oe273($checks,str_contains($cat,"['view'=>'education-library']"),'education URL uses separate view');
oe273($checks,str_contains($org,"ckm_quiz_pro_org_nav_link('education-library','Образовательные игры',\$view)"),'education sidebar link');
oe273($checks,!str_contains($org,"in_array(\$view, ['home','education-library'], true)"),'education view is not remapped to games');
oe273($checks,str_contains($org,"function ckm_quiz_pro_can_edit_owned_quiz"),'owned quiz edit guard exists');
oe273($checks,str_contains($org,"created_by_user_id']??0)!==\$uid"),'guard checks creator ownership');
oe273($checks,str_contains($org,"content_scope']??'')!=='private'"),'guard rejects shared content');
oe273($checks,str_contains($org,"ckm_quiz_pro_ready_game_product_for_quiz") && str_contains($org,"ckm_quiz_pro_package_is_readonly_quiz"),'guard rejects ready snapshots and managed packages');
oe273($checks,str_contains($org,"\$kind==='own' && \$cabinetActive") && str_contains($org,"Редактировать</a>"),'own library card exposes edit button only with active constructor access');
oe273($checks,!str_contains($org,'Редактирование игр доступно только администратору сайта. Организатор может создать новую игру с нуля'),'front POST admin-only block removed');
oe273($checks,!str_contains($adm,"if(\$id>0 && !current_user_can('manage_options')) wp_die('Редактирование игр доступно только администратору сайта.'"),'save admin-only block removed');
oe273($checks,str_contains($adm,"ckm_quiz_pro_can_edit_owned_quiz(\$old,\$editorId)"),'save rechecks owned quiz authorization');
oe273($checks,str_contains($org,"Собственную сохранённую игру можно снова открыть из раздела «Мои игры»"),'builder text describes repeat editing');
oe273($checks,str_contains($org,"\$postedQuizId>0") && str_contains($org,"['view'=>'builder','quiz'=>\$id,'saved'=>1]"),'edited organizer quiz returns to builder');
$fail=count(array_filter($checks,fn($x)=>!$x[0]));
echo "RESULT ".(count($checks)-$fail).'/'.count($checks)." PASS\n";
exit($fail?1:0);

<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function c250(&$c,$ok,$label){$c[]=[$label,(bool)$ok];}
c250($checks,ckm_test_current_plugin_release($plugin),'version');
c250($checks,str_contains($org,"\$view = sanitize_key(\$_GET['view'] ?? 'games');"),'base games default');
c250($checks,!str_contains($org,"ckm_quiz_pro_org_nav_link('home','Обзор'"),'overview removed from nav');
c250($checks,str_contains($org,'>ИГРЫ</div>') && str_contains($org,'>СОЗДАНИЕ</div>') && str_contains($org,'>УПРАВЛЕНИЕ</div>'),'navigation groups');
c250($checks,str_contains($org,"ckm_quiz_pro_org_nav_link('games','Базовые игры'") && str_contains($org,"ckm_quiz_pro_org_nav_link('persuade-library','Каталог готовых игр'") && str_contains($org,"ckm_quiz_pro_org_nav_link('scenario-order','Заказать сюжет'") && str_contains($org,"ckm_quiz_pro_org_nav_link('library','Мои игры'"),'required organizer sections');
c250($checks,str_contains($org,"if (\$view === 'home') \$view = 'mode';"),'legacy routes folded into base games');
c250($checks,str_contains($org,'ckm_quiz_pro_can_edit_owned_quiz'),'saved editing admin only');
c250($checks,str_contains($org,"\$isAdmin=current_user_can('manage_options');") && str_contains($org,"if (!\$isAdmin && !ckm_quiz_pro_can_use_front_constructor())"),'admin entitlement bypass');
c250($checks,str_contains($org,'ckm_quiz_pro_can_edit_owned_quiz') && str_contains($org,'ckm_quiz_pro_constructor_enabled_for_current_user'),'admin edit format bypass');
$failed=array_filter($checks,fn($x)=>!$x[1]);
foreach($checks as [$label,$ok]) echo ($ok?'OK ':'FAIL ').$label."\n";
exit($failed?1:0);

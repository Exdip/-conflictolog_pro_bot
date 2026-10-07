<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$tenant=file_get_contents($root.'/includes/tenant-registration.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$ret=file_get_contents($root.'/includes/tenant-payment-return.php');
$checks=[];
function c256(&$c,$ok,$label){$c[]=(bool)$ok;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}}
c256($checks,ckm_test_current_plugin_release($main),'version');
c256($checks,str_contains($tenant,'function ckmqp_tenant_base_games_url'),'canonical tenant base-games helper');
c256($checks,str_contains($tenant,"return add_query_arg(['view'=>'games'],\$base);"),'base-games helper targets games view');
c256($checks,substr_count($tenant,'wp_safe_redirect(ckmqp_tenant_base_games_url(')>=3,'all registration/site creation redirects land on base games');
c256($checks,str_contains($tenant,"return ckmqp_tenant_base_games_url((int)\$site['id']);"),'central login lands on tenant base games');
c256($checks,str_contains($tenant,"return ckm_quiz_pro_organizer_url(['view'=>'games']);"),'tenant login lands on base games');
c256($checks,str_contains($ret,"['view' => 'library', 'paid' => 1]") || str_contains($ret,"['view'=>'library','paid'=>1]"),'successful payment lands in My Games');
c256($checks,str_contains($org,'Оплата подтверждена.') && str_contains($org,'Теперь можно запускать оплаченные игры и создавать собственные игры с нуля.'),'post-payment confirmation');
c256($checks,str_contains($org,"['view'=>'library','created'=>1]") && str_contains($org,'Игра создана.'),'own-game creation returns to My Games');
c256($checks,str_contains($org,'ckm_quiz_pro_ready_game_ensure_active_snapshots'),'ready-game snapshots preserved');
c256($checks,str_contains($org,"if (\$historyAdmin) ckm_quiz_pro_org_nav_link('results','История игр',\$view);"),'history remains administrator-only');
c256($checks,str_contains($org,'После любой активной оплаты') && str_contains($org,'любой активной оплаты на площадке'),'constructor entitlement wording matches any active payment');
c256($checks,str_contains($org,"if (!\$isAdmin && !ckm_quiz_pro_can_use_front_constructor())") && str_contains($org,'ckm_quiz_pro_can_edit_owned_quiz'),'organizer create/admin edit permissions preserved');
echo 'OK '.count($checks).'/'.count($checks)."\n";

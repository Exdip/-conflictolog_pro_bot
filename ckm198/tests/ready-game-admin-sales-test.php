<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$t=file_get_contents($root.'/includes/tenant-registration.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function r266(&$c,$ok,$label){$c[]=[$ok,$label];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
r266($checks,ckm_test_current_plugin_release($p),'version');
r266($checks,str_contains($c,'function ckm_quiz_pro_ready_game_admin_sale_targets'),'organizer/site target list');
r266($checks,str_contains($c,'function ckm_quiz_pro_ready_game_admin_sale_link'),'sale link builder');
r266($checks,str_contains($c,"['buy'=>\$product]"),'sale link carries product only');
r266($checks,str_contains($c,"if(\$renew) \$args['renew']=1"),'renewal sale link');
r266($checks,str_contains($c,'function ckm_quiz_pro_ready_game_admin_grant_free'),'free grant helper');
r266($checks,str_contains($c,'ckm_quiz_pro_payment_create_access'),'free grant uses entitlement path');
r266($checks,str_contains($c,'ckm_quiz_pro_ready_game_snapshot_ensure'),'free grant ensures immutable snapshot');
r266($checks,str_contains($c,'ckmqp_tenant_is_member($tenantId,$userId)'),'tenant membership guard');
r266($checks,str_contains($c,'admin_post_ckm_quiz_pro_ready_game_free_grant'),'nonce-protected grant endpoint');
r266($checks,str_contains($c,"action==='sales'"),'sales admin route');
r266($checks,str_contains($c,'Продать / выдать'),'catalog action button');
r266($checks,str_contains($c,'Ссылка оплаты'),'sale screen');
r266($checks,str_contains($c,'Выдать бесплатно'),'free grant button');
r266($checks,str_contains($t,"if (\$buy!=='' && isset(ckm_quiz_pro_game_access_products()[\$buy]))"),'login preserves sale product on main or tenant');
r266($checks,str_contains($t,"if(!empty(\$_GET['renew'])) \$args['renew']=1"),'login preserves renewal flag');
$failed=count(array_filter($checks,fn($x)=>!$x[0]));
echo (count($checks)-$failed).'/'.count($checks)."\n";
exit($failed?1:0);

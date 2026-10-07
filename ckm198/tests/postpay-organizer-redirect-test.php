<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$ret=file_get_contents($root.'/includes/tenant-payment-return.php');
$checkout=file_get_contents($root.'/includes/checkout-frontend-complete.php');
$js=file_get_contents($root.'/assets/checkout/checkout.js');
$checks=[];
function ck201(&$c,$l,$o){$c[]=[$l,(bool)$o];}
ck201($checks,'Version bumped to 201/dev.233',ckm_test_current_plugin_release($main));
ck201($checks,'Package root is self-contained',is_file($root.'/ckm-quiz-pro.php') && is_dir($root.'/includes'));
ck201($checks,'Preview payment memory writer exists',str_contains($ret,'function ckmqp_after_payment_remember_preview'));
ck201($checks,'Preview memory stores represented organizer',str_contains($ret, "'preview_user_id' => \$preview_uid"));
ck201($checks,'Preview destination validates administrator',str_contains($ret, "user_can(\$browser, 'manage_options')"));
ck201($checks,'Preview success opens library',str_contains($ret,"['view'=>'library','paid'=>1]"));
ck201($checks,'Preview redirect regenerates signed nonce',str_contains($ret, "wp_create_nonce('ckm_qp_preview_organizer_'.\$preview_uid)"));
ck201($checks,'AJAX remembers preview on browser user',str_contains($checkout,'ckmqp_after_payment_remember_preview(get_current_user_id(), $preview_uid'));
ck201($checks,'admin_init checks preview memory',str_contains($ret,'ckmqp_after_payment_preview_destination_from_memory($uid)'));
ck201($checks,'admin_init wires recent-order fallback',str_contains($ret,'ckmqp_after_payment_recent_order_destination($uid)'));
ck201($checks,'Successful recent order is marked consumed',str_contains($ret,'ckmqp_after_payment_mark_order_consumed'));
ck201($checks,'Checkout JS preserves preview user in library URL',str_contains($js,"u.searchParams.set('ckm_preview_user'"));
ck201($checks,'Checkout JS preserves preview nonce in checkpoint',str_contains($js,"u.searchParams.set('ckm_preview_nonce'"));
ck201($checks,'Provider return dashboard redirect remains priority zero',str_contains($ret,"add_action('admin_init', 'ckmqp_after_payment_admin_redirect', 0)"));
$pass=0;foreach($checks as [$l,$o]){echo ($o?'PASS':'FAIL')." - $l\n";if($o)$pass++;}echo "RESULT $pass/".count($checks)."\n";exit($pass===count($checks)?0:1);

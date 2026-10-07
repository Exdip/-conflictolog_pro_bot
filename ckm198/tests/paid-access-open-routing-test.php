<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checkout=file_get_contents($root.'/includes/checkout-frontend-complete.php');
$organizer=file_get_contents($root.'/includes/standalone-organizer.php');
$js=file_get_contents($root.'/assets/checkout/checkout.js');
$checks=[];
function ck199(&$checks,$label,$ok){$checks[]=[$label,(bool)$ok];}
ck199($checks,'Version bumped to 199/dev.231',ckm_test_current_plugin_release($main));
ck199($checks,'Contradictory paid/renew error removed',!str_contains($checkout,'уже оплачена на этой площадке. Для активного доступа используйте кнопку'));
ck199($checks,'Active access returns open redirect',str_contains($checkout,"'already_owned'=>true") && str_contains($checkout,"'view'=>'library'"));
ck199($checks,'Single owned format opens its library directly',str_contains($checkout, "if (count(\$active)===1) \$args['format']=\$active[0];"));
ck199($checks,'Mixed cart removes already active products',str_contains($checkout,'if ($active && $pending)') && str_contains($checkout,'ckm_quiz_pro_payment_cart($pending)'));
ck199($checks,'Preview checkout has signed organizer resolver',str_contains($checkout,'function ckm_quiz_pro_checkout_preview_user_id') && str_contains($checkout,"current_user_can('manage_options')") && str_contains($checkout, "wp_verify_nonce(\$nonce, 'ckm_qp_preview_organizer_'.\$id)"));
ck199($checks,'Payment uses effective organizer user',str_contains($checkout,'ckm_quiz_pro_checkout_effective_user_id()') && str_contains($checkout, "apply_filters('ckm_create_game_payment_url', '', \$cart['format_keys'][0], \$uid)"));
ck199($checks,'Organizer passes preview identity to AJAX',str_contains($organizer, "\$config['preview_user']=\$previewUid") && str_contains($organizer, "\$config['preview_nonce']=wp_create_nonce('ckm_qp_preview_organizer_'.\$previewUid)"));
ck199($checks,'Checkout JS posts preview identity',str_contains($js,'payload.ckm_preview_user') && str_contains($js,'payload.ckm_preview_nonce'));
ck199($checks,'Owned access redirects without post-payment checkpoint',str_contains($js,'if (result.data.already_owned)') && strpos($js,'if (result.data.already_owned)') < strpos($js,"window.localStorage.setItem('ckmqpPostpayReturn'"));
ck199($checks,'Explicit renewal flow retained',str_contains($checkout, "if (\$renew && count(\$cart['format_keys'])!==1)") && str_contains($organizer,'data-ckm-renew="1"'));
$pass=0; foreach($checks as [$label,$ok]){echo ($ok?'PASS':'FAIL')." - $label\n"; if($ok)$pass++;}
echo "RESULT $pass/".count($checks)."\n";
exit($pass===count($checks)?0:1);

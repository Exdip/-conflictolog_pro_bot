<?php
$root=dirname(__DIR__);
$loader=file_get_contents($root.'/includes/partner/loader.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$n=0;
function pfr272($ok,$label){global $n;$n++;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "OK: $label\n";}
pfr272(str_contains($loader,"add_action('template_redirect', 'ckm_quiz_pro_partner_front_route', -40)"),'template_redirect callback registered');
pfr272(str_contains($front,'function ckm_quiz_pro_partner_front_route(): void'),'registered callback exists');
pfr272(!str_contains($front,'function ckm_quiz_pro_partner_partner_front_route(): void'),'duplicated-prefix callback removed');
pfr272(str_contains($front,"if (\$path !== '/ckm-partner') return;"),'partner route guard remains scoped');
echo "ALL $n PASS\n";

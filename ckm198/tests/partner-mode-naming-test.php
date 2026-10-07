<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$front=file_get_contents($root.'/includes/partner/frontend.php');
$admin=file_get_contents($root.'/includes/partner/admin.php');
$pay=file_get_contents($root.'/includes/partner/payments.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$c=0; function pm276(&$c,$ok,$label){$c++; if(!$ok){fwrite(STDERR,"FAIL $label\n"); exit(1);} echo "PASS $label\n";}
pm276($c,ckm_test_current_plugin_release($main),'version marker');
pm276($c,str_contains($org,'Партнёрский режим'),'organizer mode title');
pm276($c,!str_contains($org,'Арендовать платформу'),'old organizer title removed');
pm276($c,str_contains($org,'Выбрать партнёрский тариф'),'partner tariff CTA');
pm276($c,str_contains($front,'ПАРТНЁРСКИЙ РЕЖИМ · 30 ДНЕЙ'),'plan badge naming');
pm276($c,str_contains($front,'Партнёрский режим подходит'),'partner explanation');
pm276($c,str_contains($admin,'Площадки и партнёрский режим'),'admin heading');
pm276($c,str_contains($admin,'Запрошен партнёрский режим:'),'admin request naming');
pm276($c,str_contains($admin,'Отключить партнёрский режим'),'admin deactivate label');
pm276($c,str_contains($pay,'Оплата партнёрского режима ЦКМ'),'YooKassa explanatory copy');
pm276($c,str_contains($pay,'Моя YooKassa'),'YooKassa section retained');
pm276($c,str_contains($pay,'Последние счета'),'payment table retained');
pm276($c,str_contains($pay,"rest_url('ckm-partner/v1/yookassa/'"),'webhook route retained');
pm276($c,str_contains($pay,'payment.succeeded') && str_contains($pay,'payment.canceled'),'webhook events retained');
echo "OK $c/14\n";

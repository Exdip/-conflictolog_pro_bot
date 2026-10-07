<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__).'/modules/negotiation-master/application/agreement-service.php';
use CKM\NegotiationMaster\AgreementService;
$checks=0;
function a357($ok,$msg){global $checks;$checks++;if(!$ok){fwrite(STDERR,"FAIL: $msg\n");exit(1);}echo "PASS: $msg\n";}
$ref=new ReflectionClass(AgreementService::class);
$value=$ref->getMethod('valueText');$value->setAccessible(true);
$proposal=$ref->getMethod('proposalText');$proposal->setAccessible(true);
a357($value->invoke(null,960000,'RUB')==='960 000 руб.','960000 RUB preserved exactly');
a357($value->invoke(null,21800000,'RUB')==='21 800 000 руб.','21800000 RUB preserved exactly');
a357($value->invoke(null,40,'дней')==='40 дн.','Russian days normalized');
a357($value->invoke(null,24,'месяцев')==='24 мес.','Russian months normalized');
$text=$proposal->invoke(null,[
 ['title'=>'Цена контракта','value'=>960000,'unit'=>'RUB'],
 ['title'=>'Срок поставки','value'=>40,'unit'=>'дней'],
]);
a357(str_contains($text,'Цена контракта: 960 000 руб.'),'proposal contains exact price');
a357(str_contains($text,'Срок поставки: 40 дн.'),'proposal contains normalized days');
a357(!str_contains($text,'1,0 млн'),'proposal never rounds 960000 to 1.0 million');
$src=file_get_contents(dirname(__DIR__).'/modules/negotiation-master/application/agreement-service.php');
a357(str_contains($src,'Final agreement text must preserve the exact monetary amount'),'exact-package guard documented');
$plugin=file_get_contents(dirname(__DIR__).'/ckm-quiz-pro.php');
a357(ckm_test_current_plugin_release($plugin),'plugin build updated');
echo "TOTAL: $checks/$checks PASS\n";

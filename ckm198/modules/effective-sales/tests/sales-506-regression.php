<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);$page=file_get_contents($root.'/modules/effective-sales/public/sales-page.php');$tpl=file_get_contents($root.'/modules/effective-sales/public/app-template.php');$boot=file_get_contents($root.'/modules/effective-sales/bootstrap.php');$svc=file_get_contents($root.'/modules/effective-sales/application/sales-script-service.php');$css=file_get_contents($root.'/modules/effective-sales/assets/sales-app.css');$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];function s506(&$c,$n,$ok){$c[]=[$n,(bool)$ok];}
s506($checks,'plugin version',ckm_test_current_plugin_release($main));
s506($checks,'scripts nav',str_contains($tpl,'>Методики<')&&str_contains($tpl,"sales_view'=>'scripts"));
s506($checks,'script service loaded',str_contains($boot,"sales-script-service.php"));
s506($checks,'per tenant storage',str_contains($svc,'ckmqp_scope_id')&&str_contains($svc,"ckm_sales_scripts_v1"));
s506($checks,'12 classes',substr_count($svc,"'=>[")>=12&&str_contains($svc,'Экспертная продажа'));
s506($checks,'builder fields',str_contains($page,'script_product')&&str_contains($page,'script_client')&&str_contains($page,'script_goal')&&str_contains($page,'script_constraints'));
s506($checks,'workflow labels',str_contains($page,'Создать')&&str_contains($page,'Проверить на ИИ-клиенте')&&str_contains($page,'Утвердить'));
s506($checks,'approve action',str_contains($page,"value=\"approve\"")&&str_contains($svc,"['status']='approved'"));
s506($checks,'reference ai client testing',str_contains($page,'referenceScenarioForClass')&&str_contains($page,'Сравнить с типовым ИИ-клиентом'));
s506($checks,'typical client remains comparison path',str_contains($page,'Сравнить с типовым ИИ-клиентом')&&str_contains($page,'Два уровня проверки'));
s506($checks,'scripts css',str_contains($css,'.ckm-sales-script-layout')&&str_contains($css,'.ckm-sales-script-steps'));
$fail=0;foreach($checks as[$n,$ok]){echo($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$fail++;}exit($fail?1:0);

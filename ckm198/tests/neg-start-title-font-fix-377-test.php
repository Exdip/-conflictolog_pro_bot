<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$checks=[];function s377(&$c,$ok,$m){$c[]=$ok;echo ($ok?'PASS':'FAIL')." $m\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
s377($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
s377($checks,str_contains($css,'body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1{max-width:920px'),'scenario title selector outranks global heading rule');
s377($checks,str_contains($css,'font-family:var(--ckm-neg-font-body)!important'),'scenario title explicitly uses body font');
s377($checks,str_contains($css,'body.ckm-neg-app-document h1,')&&str_contains($css,'font-family:var(--ckm-neg-font-heading)!important'),'Caveat branding/heading rule retained elsewhere');
s377($checks,str_contains($css,'body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1{font-size:32px}'),'mobile override targets same strong selector');
s377($checks,str_contains($css,'body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1{font-size:29px}'),'small-mobile override targets same strong selector');
$ok=!in_array(false,$checks,true);echo ($ok?'RESULT PASS ':'RESULT FAIL ').count(array_filter($checks)).'/'.count($checks)."\n";exit($ok?0:1);

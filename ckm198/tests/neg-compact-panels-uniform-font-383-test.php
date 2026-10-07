<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$c=[];function t383(&$c,$ok,$name){$c[]=[$ok,$name];}
t383($c,ckm_test_current_plugin_release($main),'version');
t383($c,str_contains($css,'NEG-COMPACT-PANELS-UNIFORM-FONT 383'),'css marker');
t383($c,str_contains($css,'body.ckm-neg-app-document *{')&&str_contains($css,'font-size:13px!important'),'uniform 13px rule');
t383($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-task,')&&str_contains($css,'padding:14px!important'),'compact task/state padding');
t383($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-state-list{')&&str_contains($css,'gap:5px!important'),'compact state list');
t383($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-state-item{')&&str_contains($css,'padding:7px 8px!important'),'compact state item');
t383($c,str_contains($page,'id="ckm-neg-mobile-task"')&&str_contains($page,'id="ckm-neg-mobile-state"'),'panel controls preserved');
t383($c,str_contains($page,'id="ckm-neg-send"')&&str_contains($page,'id="ckm-neg-agreement"'),'game actions preserved');
$fail=array_filter($c,fn($x)=>!$x[0]);foreach($c as [$ok,$name])echo ($ok?'PASS ':'FAIL ').$name."\n";printf("NEG-COMPACT-PANELS-UNIFORM-FONT 383: %d/%d PASS\n",count($c)-count($fail),count($c));exit($fail?1:0);

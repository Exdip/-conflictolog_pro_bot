<?php
require_once __DIR__ . '/support/plugin-release.php';
$c=[]; function t381(&$c,$ok,$m){$c[]=[$ok,$m]; if(!$ok) fwrite(STDERR,"FAIL: $m\n");}
$r=dirname(__DIR__); $m=file_get_contents($r.'/ckm-quiz-pro.php'); $css=file_get_contents($r.'/modules/negotiation-master/assets/negotiation-app.css'); $p=file_get_contents($r.'/modules/negotiation-master/public/player-page.php');
t381($c,ckm_test_current_plugin_release($m),'version');
t381($c,str_contains($css,'NEG-COMPOSE-ACTION-BAR 381'),'marker');
t381($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-compose-actions .ckm-neg-btn'),'action bar buttons normalized together');
t381($c,str_contains($css,'#ckm-neg-send{')&&str_contains($css,'margin-left:auto!important'),'send primary aligned right');
t381($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-compose .ckm-neg-coach')&&str_contains($css,'background:transparent!important'),'closed coach chrome compact');
t381($c,str_contains($css,'grid-template-columns:repeat(2,minmax(0,1fr))'),'responsive two-column actions');
foreach(['ckm-neg-pause','ckm-neg-agreement','ckm-neg-no-deal','ckm-neg-send','ckm-neg-coach-toggle'] as $id)t381($c,str_contains($p,'id="'.$id.'"'),'keeps '.$id);
foreach($c as [$ok,$m]) if(!$ok) exit(1); echo 'PASS '.count($c).'/'.count($c)."\n";

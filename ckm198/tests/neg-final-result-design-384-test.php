<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$page=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$c=[];function t384(&$c,$ok,$name){$c[]=[$ok,$name];}
t384($c,ckm_test_current_plugin_release($main),'version');
t384($c,str_contains($css,'NEG-FINAL-RESULT-DESIGN 384'),'css marker');
t384($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-completed{')&&str_contains($css,'width:min(1120px,100%)'),'completion surface constrained');
t384($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-score-line{')&&str_contains($css,'font-size:13px!important'),'score remains uniform type size');
t384($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-criteria{')&&str_contains($css,'grid-template-columns:repeat(2,minmax(0,1fr))'),'criteria compact grid');
t384($c,str_contains($css,'.ckm-neg-app-main .ckm-neg-result-columns>div{'),'strength/improvement cards');
t384($c,str_contains($page,'id="ckm-neg-completed"')&&str_contains($page,'id="ckm-neg-result-ready"'),'completion ids preserved');
t384($c,str_contains($page,'id="ckm-neg-replay"')&&str_contains($page,'id="ckm-neg-result-retry"'),'result controls preserved');
t384($c,str_contains($js,'function renderCompletion(s)')&&str_contains($js,'function renderEvaluation(r)'),'completion logic preserved');
$fail=array_filter($c,fn($x)=>!$x[0]);foreach($c as [$ok,$name])echo ($ok?'PASS ':'FAIL ').$name."\n";printf("NEG-FINAL-RESULT-DESIGN 384: %d/%d PASS\n",count($c)-count($fail),count($c));exit($fail?1:0);

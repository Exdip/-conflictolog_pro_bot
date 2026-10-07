<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$c=[]; function t382(&$c,$ok,$label){$c[]=[$ok,$label];}
t382($c,ckm_test_current_plugin_release($main),'version');
t382($c,str_contains($css,'NEG-DIALOGUE-MESSAGE-DESIGN 382'),'css marker');
t382($c,str_contains($js,"who.className='ckm-neg-message-speaker'"),'speaker class');
t382($c,str_contains($js,"body.className='ckm-neg-message-body'"),'body class');
t382($c,str_contains($css,'.ckm-neg-message.is-player'),'player style');
t382($c,str_contains($css,'.ckm-neg-message.is-opponent'),'opponent style');
t382($c,str_contains($css,'.ckm-neg-empty'),'system empty style');
t382($c,str_contains($css,'.ckm-neg-message.is-agreement'),'agreement style');
t382($c,str_contains($css,'white-space:pre-wrap'),'long text formatting');
$fail=array_values(array_filter($c,fn($x)=>!$x[0]));
printf("NEG-DIALOGUE-MESSAGE-DESIGN 382: %d/%d PASS\n",count($c)-count($fail),count($c));
foreach($fail as $x)fwrite(STDERR,"FAIL: {$x[1]}\n");
exit($fail?1:0);

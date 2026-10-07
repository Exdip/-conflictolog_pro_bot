<?php
require_once __DIR__ . '/support/plugin-release.php';
$checks=[]; function t380(&$c,$ok,$m){$c[]=[$ok,$m]; if(!$ok){fwrite(STDERR,"FAIL: $m\n");}}
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
t380($checks,ckm_test_current_plugin_release($main),'plugin version bumped');
t380($checks,str_contains($css,'NEG-SEND-BUTTON-EXACT-MATCH 380'),'css marker');
t380($checks,str_contains($css,'height:auto!important'),'no forced fixed send height');
t380($checks,str_contains($css,'padding:10px 15px!important'),'send padding matches base game buttons');
t380($checks,str_contains($css,'min-height:42px!important'),'send minimum matches base buttons');
t380($checks,str_contains($css,'font-weight:400!important'),'send text is not bold');
foreach($checks as [$ok,$m]) if(!$ok) exit(1); echo 'PASS '.count($checks)."/".count($checks)."\n";

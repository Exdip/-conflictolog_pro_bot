<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function t379(&$c,$ok,$m){$c[]=[$ok,$m];}
t379($checks,ckm_test_current_plugin_release($main),'plugin version bumped to .379');
t379($checks,str_contains($css,'NEG-SEND-BUTTON-EXACT-MATCH 380'),'current send box model supersedes fixed-height 379' );
t379($checks,str_contains($css,'.ckm-neg-app-main .ckm-neg-compose-actions #ckm-neg-send'),'send button selector is scoped to negotiation compose controls');
t379($checks,str_contains($css,'font-weight:400!important'),'send button uses normal font weight');
t379($checks,preg_match('/#ckm-neg-send\{[^}]*min-height:42px!important;[^}]*height:auto!important;/s',$css)===1,'send button standard minimum and natural height' );
t379($checks,str_contains($css,'min-height:42px!important'),'send button minimum height matches base buttons');
t379($checks,str_contains($css,'align-items:center!important')&&str_contains($css,'justify-content:center!important'),'send label remains vertically/horizontally centered');
t379($checks,preg_match('/@media\(max-width:720px\)\{[^@]*\.ckm-neg-app-main \.ckm-neg-compose-actions\{\s*display:grid!important;\s*grid-template-columns:repeat\(2,minmax\(0,1fr\)\);/s',$css)===1,'current mobile action-bar rule retained');
$bad=array_filter($checks,fn($x)=>!$x[0]);
foreach($checks as [$ok,$m]) echo ($ok?'PASS':'FAIL')." - $m\n";
if($bad){exit(1);} echo count($checks)."/".count($checks)." PASS\n";

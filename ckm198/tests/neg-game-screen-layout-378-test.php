<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function g378(&$c,$ok,$name){$c[]=[$ok,$name]; if(!$ok) fwrite(STDERR,"FAIL: $name\n");}
g378($checks,ckm_test_current_plugin_release($main),'378 version');
g378($checks,str_contains($css,'/* NEG-GAME-SCREEN-LAYOUT 378:'),'378 css block');
g378($checks,str_contains($css,'.ckm-neg-app-main .ckm-neg-game{grid-template-columns:minmax(220px,250px) minmax(0,1fr) minmax(240px,280px);'),'desktop dialogue-first grid');
g378($checks,str_contains($css,'@media(max-width:1080px){')&&str_contains($css,'.ckm-neg-app-main .ckm-neg-game{grid-template-columns:minmax(0,1fr)!important'),'compact grid truly one column');
g378($checks,str_contains($css,'.ckm-neg-app-main .ckm-neg-mobile-tools{display:flex!important'),'mobile tools compact flex row');
g378($checks,str_contains($css,'width:auto!important')&&str_contains($css,'font-weight:400!important'),'mobile controls auto width normal weight');
g378($checks,str_contains($css,'body.ckm-neg-app-document{overflow-x:hidden!important}'),'horizontal page overflow blocked');
g378($checks,str_contains($css,'.ckm-neg-appnav a{display:inline-flex!important')&&str_contains($css,'height:38px!important')&&str_contains($css,'font-weight:400!important'),'nav buttons equal height and normal weight');
g378($checks,str_contains($css,'.ckm-neg-app-main .ckm-neg-task.is-mobile-open,.ckm-neg-app-main .ckm-neg-state.is-mobile-open{display:block!important}'),'existing mobile panel toggles preserved');
$fail=array_filter($checks,fn($x)=>!$x[0]);
printf("NEG-GAME-SCREEN-LAYOUT 378: %d/%d PASS\n",count($checks)-count($fail),count($checks));
exit($fail?1:0);

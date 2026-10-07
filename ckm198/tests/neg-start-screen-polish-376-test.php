<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=[];
function s376(&$c,$ok,$label){$c[]=[$ok,$label];echo($ok?'PASS ':'FAIL ').$label."\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
s376($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
s376($checks,str_contains($css,'body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1{max-width:920px;margin:8px 0 10px;font-family:var(--ckm-neg-font-body)!important'),'scenario title uses body font');
s376($checks,str_contains($css,'font-size:clamp(30px,2.65vw,42px)'),'scenario title size restrained');
s376($checks,str_contains($css,'font-weight:700!important')&&str_contains($css,'letter-spacing:-.025em!important'),'scenario title readable weight and tracking');
s376($checks,str_contains($css,'.ckm-neg-launch-fieldset>.ckm-neg-muted{margin:-1px 0 10px!important'),'difficulty help has scoped compact rule');
s376($checks,str_contains($css,'font-size:12px!important')&&str_contains($css,'font-family:var(--ckm-neg-font-body)!important'),'difficulty help uses ordinary body typography');
s376($checks,str_contains($css,'.ckm-neg-start-hero{position:relative;overflow:hidden;padding:28px 32px!important'),'hero vertical size reduced');
s376($checks,str_contains($css,'.ckm-neg-start-people{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:18px'),'people block pulled upward');
s376($checks,str_contains($css,'.ckm-neg-start-mission{padding:21px 24px!important'),'mission block compacted');
s376($checks,str_contains($css,'@media(max-width:760px){.ckm-neg-start-hero{padding:22px 20px!important}body.ckm-neg-app-document .ckm-neg-app-main .ckm-neg-start-hero h1{font-size:32px}'),'mobile title remains compact');
s376($checks,str_contains($player,'ckm-neg-start-layout')&&str_contains($player,'ckm-neg-launch-panel'),'375 layout preserved');
s376($checks,str_contains($player,'id="ckm-neg-start"')&&str_contains($player,'id="ckm-neg-continue"')&&str_contains($player,'id="ckm-neg-restart"'),'runtime ids preserved');
$fails=array_filter($checks,fn($x)=>!$x[0]);echo count($checks).'/'.count($checks).' checks, '.count($fails).' failed'."\n";exit($fails?1:0);

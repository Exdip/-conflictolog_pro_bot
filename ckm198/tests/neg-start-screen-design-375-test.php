<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=[];
function s375(&$c,$ok,$label){$c[]=[$ok,$label];echo($ok?'PASS ':'FAIL ').$label."\n";}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$player=file_get_contents($root.'/modules/negotiation-master/public/player-page.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
s375($checks,ckm_test_current_plugin_release($plugin),'plugin version bumped');
s375($checks,str_contains($player,'ckm-neg-start-layout')&&str_contains($player,'ckm-neg-start-content')&&str_contains($player,'ckm-neg-launch-panel'),'focused two-area start layout present');
s375($checks,str_contains($player,'Переговорная задача')&&str_contains($player,'Что нужно сделать'),'task is promoted into briefing');
s375($checks,str_contains($player,'ckm-neg-start-people')&&str_contains($player,'Ваша роль')&&str_contains($player,'Оппонент'),'role and opponent grouped in hero');
s375($checks,str_contains($player,'renderKnownFactsStart')&&str_contains($player,'ckm-neg-brief-fact'),'known facts have dedicated human renderer');
s375($checks,str_contains($player,'renderThresholdsStart')&&str_contains($player,'ckm-neg-brief-row'),'targets and red lines have compact renderer');
s375($checks,str_contains($player,'$itemUnits[$code]')&&str_contains($player,"'RUB'=>'руб.'"),'units shown in start briefing');
s375($checks,str_contains($player,'name="ckm-neg-mode"')&&str_contains($player,'name="ckm-neg-difficulty"')&&str_contains($player,'id="ckm-neg-voice"'),'existing runtime controls preserved');
s375($checks,str_contains($player,'id="ckm-neg-start"')&&str_contains($player,'id="ckm-neg-continue"')&&str_contains($player,'id="ckm-neg-restart"'),'start/continue/restart ids preserved');
s375($checks,str_contains($css,'.ckm-neg-start-layout{display:grid;grid-template-columns:')&&str_contains($css,'.ckm-neg-launch-panel{position:sticky'),'desktop briefing and sticky launch controls styled');
s375($checks,str_contains($css,'@media(max-width:1100px){.ckm-neg-start-layout{grid-template-columns:1fr}'),'responsive start layout collapses to one column');
s375($checks,!str_contains(substr($player,strpos($player,'ckm-neg-start-screen'),strpos($player,'ckm-neg-game')-strpos($player,'ckm-neg-start-screen')),'ckm-neg-pregrid'),'old dashboard-like pregrid removed from prestart');
$fails=array_filter($checks,fn($x)=>!$x[0]);echo count($checks).'/'.count($checks).' checks, '.count($fails).' failed'."\n";exit($fails?1:0);

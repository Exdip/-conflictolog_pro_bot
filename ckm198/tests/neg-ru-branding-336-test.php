<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$app=file_get_contents($root.'/modules/negotiation-master/public/app-template.php');
$shell=file_get_contents($root.'/modules/negotiation-master/public/app-shell.php');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-app.css');
$builder=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$assign=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-assignments.js');
$session=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$checks=[];
function c336(&$c,$ok,$name){$c[]=$ok;echo ($ok?'PASS ':'FAIL ').$name."\n";}
c336($checks,ckm_test_current_plugin_release($plugin),'plugin build');
c336($checks,str_contains($shell,'family=Caveat')&&str_contains($shell,'family=Ubuntu'),'site fonts enqueued');
c336($checks,str_contains($css,'"Ubuntu"')&&str_contains($css,'"Caveat"'),'site font stacks');
c336($checks,str_contains($shell,'CKKM-logo-icon.webp'),'official logo');
c336($checks,str_contains($app,'ИИ-тренажёр переговоров')&&!str_contains($app,'AI-тренажёр'),'Russian AI label');
c336($checks,str_contains($builder,'Жёсткое ограничение')&&!str_contains($builder,'>hard_constraint<'),'rule labels Russian');
c336($checks,str_contains($builder,'<option value="hybrid">Формальные правила + ИИ</option>')&&!str_contains($builder,'>hybrid<'),'evaluation labels Russian');
c336($checks,str_contains($builder,'Деньги')&&!str_contains($builder,'<option>money</option>'),'item type labels Russian');
c336($checks,!str_contains($builder,'hard constraints'),'no English hard constraints');
c336($checks,!str_contains($builder,'BATNA'),'no BATNA label');
c336($checks,!str_contains($assign,"'User ")&&!str_contains($assign,'email или ID'),'assignment UI Russian');
c336($checks,str_contains($session,'item.value_labels'),'categorical labels supported');
$fail=count(array_filter($checks,fn($x)=>!$x));echo "TOTAL ".count($checks)." FAIL $fail\n";exit($fail?1:0);

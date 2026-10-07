<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function g344($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
g344(ckm_test_current_plugin_release($plugin),'plugin version updated');
g344(!str_contains($page,'Цель участника <small>структурированные данные'),'raw player target textarea removed');
g344(!str_contains($page,'Граница участника <small>структурированные данные'),'raw player boundary textarea removed');
g344(str_contains($page,'Красная линия участника'),'player red line has normal UI');
g344(str_contains($page,'Минимально допустимо')&&str_contains($page,'Максимально допустимо'),'min/max controls exist');
g344(str_contains($page,'Разрешённые варианты')&&str_contains($page,'Обязательное значение'),'categorical/hard controls exist');
g344(str_contains($page,'data-f="player_target_json"')&&str_contains($page,'type="hidden"'),'player target contract retained hidden');
g344(str_contains($page,'data-f="opponent_boundary_json"')&&str_contains($page,'type="hidden"'),'opponent boundary contract retained hidden');
g344(str_contains($js,'syncItemSide')&&str_contains($js,'fillItemSide'),'builder roundtrip helpers exist');
g344(str_contains($js,"value==='higher'?'higher_better'"),'legacy higher canonicalized');
g344(str_contains($js,"value==='lower'?'lower_better'"),'legacy lower canonicalized');
g344(str_contains($js,'delete target[previous]'),'target mode changes do not leave stale primary key');
g344(str_contains($js,"delete boundary[key]"),'boundary known keys rebuilt cleanly');
g344(str_contains($js,'targetField.value=jsonText(target)')&&str_contains($js,'boundaryField.value=jsonText(boundary)'),'normal fields serialize to existing JSON contract');
g344(str_contains($css,'.ckm-neg-item-sides'),'new item layout styled');
fwrite(STDOUT,sprintf("NEG-ITEM-GOALS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

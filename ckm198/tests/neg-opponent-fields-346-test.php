<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function o346($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
o346(ckm_test_current_plugin_release($plugin),'plugin version updated');
foreach(['nb-persona','nb-external','nb-hidden-interests','nb-constraints','nb-opponent-alt','nb-concessions','nb-walkaway-json'] as $id)o346(!str_contains($page,'id="'.$id.'"'),'raw opponent JSON removed: '.$id);
o346(str_contains($page,'id="nb-opponent-style"')&&str_contains($page,'id="nb-opponent-traits"'),'persona has normal fields');
o346(str_contains($page,'id="nb-opponent-priorities"'),'hidden priorities normal field exists');
o346(str_contains($page,'id="nb-opponent-alt-description"'),'opponent alternative normal field exists');
o346(str_contains($page,'id="nb-opponent-walkaway-condition"')&&str_contains($page,'id="nb-opponent-walkaway-repeat"'),'walkaway normal fields exist');
o346(str_contains($page,'data-ui="opponent_opening_value"')&&str_contains($page,'data-ui="opponent_concession_allowed"'),'opening and concessions moved to item card');
o346(str_contains($js,'prepareOpponent')&&str_contains($js,'opponentFieldsFromForm'),'opponent roundtrip helpers exist');
o346(str_contains($js,'externalExtras')&&str_contains($js,'constraintExtras')&&str_contains($js,'concessionExtras'),'legacy extra fields retained');
o346(str_contains($js,'factCodesAuto'),'hidden fact linkage compatibility retained');
o346(str_contains($js,'fillOpponentItemFields'),'per-item opponent fields populated');
o346(str_contains($js,'...opponent,mechanics_json'),'payload uses structured opponent serializer');
o346(!str_contains($js,"getJSON('#nb-persona'")&&!str_contains($js,"getJSON('#nb-concessions'")&&!str_contains($js,"getJSON('#nb-walkaway-json'"),'removed opponent JSON controls are not read');
o346(str_contains($css,'.ckm-neg-item-opponent-opening'),'opponent item UI styled');
fwrite(STDOUT,sprintf("NEG-OPPONENT-FIELDS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

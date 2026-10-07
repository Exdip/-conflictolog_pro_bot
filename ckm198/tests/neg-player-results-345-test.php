<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function r345($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
r345(ckm_test_current_plugin_release($plugin),'plugin version updated');
r345(!str_contains($page,'id="nb-ideal"'),'general ideal JSON field removed');
r345(!str_contains($page,'id="nb-target"'),'general target JSON field removed');
r345(!str_contains($page,'id="nb-redlines"'),'general red-lines JSON field removed');
r345(str_contains($page,'Идеальный результат, цели и красные линии задаются по каждому предмету переговоров.'),'player card explains single editing source');
r345(str_contains($page,'data-ui="player_ideal_value"'),'per-item ideal result input exists');
r345(str_contains($page,'data-ui="player_redline_enabled"'),'per-item red-line switch exists');
r345(str_contains($js,'preparePlayerResults'),'legacy player-result split helper exists');
r345(str_contains($js,'playerResultsFromItems'),'player-result serializer exists');
r345(str_contains($js,'idealExtras')&&str_contains($js,'targetExtras')&&str_contains($js,'redLineExtras'),'legacy extra data is retained');
r345(str_contains($js,'player_ideal_result_json:results.ideal'),'ideal result serialized from normal fields');
r345(str_contains($js,'player_target_result_json:results.target'),'target result serialized from item goals');
r345(str_contains($js,'player_red_lines_json:results.redLines'),'red lines serialized from item controls');
r345(!str_contains($js,"getJSON('#nb-ideal'")&&!str_contains($js,"getJSON('#nb-target'")&&!str_contains($js,"getJSON('#nb-redlines'"),'removed JSON controls are not read');
r345(str_contains($css,'.ckm-neg-builder-result-note')&&str_contains($css,'.ckm-neg-item-subtitle-row'),'new result UI is styled');
fwrite(STDOUT,sprintf("NEG-PLAYER-RESULTS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

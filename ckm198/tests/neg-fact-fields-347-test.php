<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function f347($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
$seed=file_get_contents($root.'/modules/negotiation-master/content/system-v1.php');
f347(ckm_test_current_plugin_release($plugin),'plugin version updated');
f347(!str_contains($page,'<textarea id="nb-known"'),'visible known-facts JSON textarea removed');
f347(str_contains($page,'id="nb-known-facts"')&&str_contains($page,'id="nb-known-template"'),'known facts use repeater rows');
f347(str_contains($page,'data-ui="known_label"')&&str_contains($page,'data-ui="known_value"'),'known fact has normal title and content fields');
f347(str_contains($page,'id="nb-known-legacy-note"'),'complex legacy known facts get preservation note');
f347(str_contains($page,'data-f="reveal_rules_json"')&&str_contains($page,'type="hidden"'),'reveal JSON contract retained only as hidden field');
f347(str_contains($page,'data-ui="fact_partial"')&&str_contains($page,'data-ui="fact_revealed"'),'partial and full reveal conditions have normal fields');
f347(str_contains($page,'data-ui="fact_automatic"'),'automatic reveal has checkbox');
f347(str_contains($page,'data-f="initial_level"')&&str_contains($page,'Частично известен')&&str_contains($page,'Полностью известен'),'initial reveal level is editable');
f347(str_contains($js,'prepareKnownFacts')&&str_contains($js,'knownFactsFromForm'),'known facts roundtrip helpers exist');
f347(str_contains($js,'knownFactsState.extras')&&str_contains($js,'arrayOpaque'),'complex object/list legacy data is preserved');
f347(str_contains($js,'prepareFactRow')&&str_contains($js,'syncFactRow'),'hidden fact reveal helpers exist');
f347(str_contains($js,'delete rules.partial')&&str_contains($js,'delete rules.revealed')&&str_contains($js,'delete rules.automatic_reveal'),'known reveal keys rebuilt without deleting unknown keys');
f347(str_contains($js,'player_known_facts_json:knownFactsFromForm()'),'payload uses normal known fact editor');
f347(str_contains($js,'prepareKnownFacts(v.player_known_facts_json)'),'existing known facts populate new editor');
f347(str_contains($js,"row.dataset.kind==='fact')syncFactRow(row)"),'fact reveal fields serialized before payload collection');
f347(str_contains($css,'.ckm-neg-known-head')&&str_contains($css,'.ckm-neg-fact-reveal-grid'),'fact editor UI styled');
if(preg_match("/CKM_NEG_SEED_JSON'\\n(.*)\\nCKM_NEG_SEED_JSON/s",$seed,$m)){
    $data=json_decode($m[1],true);$scenarioCount=0;$hiddenCount=0;$standardReveal=0;$nestedKnown=0;$expectedHidden=0;
    foreach((array)($data['scenarios']??[]) as $scenario){$scenarioCount++;$expectedHidden+=(int)$scenario['expected']['hidden_facts'];$known=$scenario['version']['player_known_facts_json']??[];if(is_array($known)){foreach($known as $v){if(is_array($v))$nestedKnown++;}}foreach((array)($scenario['components']['hidden_facts']??[]) as $fact){$hiddenCount++;$r=$fact['reveal_rules_json']??[];if(is_array($r)&&array_key_exists('partial',$r)&&array_key_exists('revealed',$r)&&array_key_exists('automatic_reveal',$r))$standardReveal++;}}
    f347($scenarioCount===count($data['scenarios'])&&$scenarioCount>=13,'all current scenarios inspected and original pack retained');
    f347($hiddenCount===$expectedHidden&&$standardReveal===$hiddenCount,'all current hidden facts match manifests and supported reveal fields');
    f347($nestedKnown>=2,'nested known-fact blocks from original and expanded packs remain supported');
}else{f347(false,'seed JSON parsed');}
fwrite(STDOUT,sprintf("NEG-FACT-FIELDS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

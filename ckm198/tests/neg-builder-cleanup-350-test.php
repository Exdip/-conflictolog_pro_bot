<?php
require_once __DIR__ . '/support/plugin-release.php';
define('ABSPATH', __DIR__);
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_unslash($value){return $value;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return strip_tags((string)$value);}
$root=dirname(__DIR__);$checks=0;$fails=0;
function c350($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
$data=require $root.'/modules/negotiation-master/content/system-v1.php';
require_once $root.'/modules/negotiation-master/application/scenario-builder-service.php';
$encodeConfig=new ReflectionMethod(CKM\NegotiationMaster\ScenarioBuilderService::class,'json');
$normalizeItems=new ReflectionMethod(CKM\NegotiationMaster\ScenarioBuilderService::class,'normalizeItems');
$builder=new CKM\NegotiationMaster\ScenarioBuilderService();
c350(ckm_test_current_plugin_release($plugin),'plugin version updated');
foreach(['Системный код','Дополнительная конфигурация','Код причины','структурированные данные'] as $term)c350(!str_contains($page,$term),'technical visible label removed: '.$term);
c350(!preg_match('/<textarea[^>]+class="[^"]*is-json/s',$page),'no visible JSON textarea remains in builder');
c350(substr_count($page,'type="hidden" data-f="code"')===4,'component codes are hidden for facts/items/rules/evaluation');
foreach(['item_config_opening','item_config_step','item_config_options'] as $field)c350(str_contains($page,'data-ui="'.$field.'"'),'normal item config field: '.$field);
c350(str_contains($js,'data-ui="item_option_label"'),'normal select option label field generated');
c350(str_contains($page,'Участник предложил соглашение')&&str_contains($page,'Запрошено финальное подтверждение соглашения'),'rule event labels localized');
c350(str_contains($js,'nextTechnicalCode')&&str_contains($js,'ensureTechnicalCode'),'technical codes generated automatically');
c350(str_contains($js,'prepareItemConfig')&&str_contains($js,'syncItemConfig'),'item config roundtrip helpers exist');
c350(str_contains($js,"for(const k of ['opening','step','options','labels'])delete out[k]"),'known config keys rebuilt from normal fields');
c350(str_contains($js,'itemOptionCode')&&str_contains($js,'itemOptionLabel'),'select values translated between hidden codes and labels');
c350(str_contains($js,'Сохранённое событие'),'unknown legacy rule event preserved without exposing raw code by default');
c350(str_contains($css,'.ckm-neg-item-config-fieldset')&&str_contains($css,'.ckm-neg-item-option-row'),'cleanup UI styled');
// The pack grew after .350. Its per-scenario manifests, not the old aggregate
// of 75 items, describe which current installation data must be inspected.
if(is_array($data)&&!empty($data['scenarios'])){
  $items=[];$covered=0;$selects=0;$manifestsOk=true;$storedOk=true;
  // Sales conversation scenarios may declare zero package items; their five
  // evaluation criteria carry the scoring contract instead.
  foreach($data['scenarios'] as $scenario){$expected=$scenario['expected']['items']??null;if(!is_int($expected)||$expected<0||count($scenario['components']['items']??[])!==$expected)$manifestsOk=false;}
  foreach((array)($data['scenarios']??[]) as $scenario)foreach((array)($scenario['components']['items']??[]) as $item){$items[]=$item;$cfg=$item['config_json']??[];$ok=is_array($cfg);foreach(array_keys((array)$cfg) as $k)if(!in_array($k,['opening','step','options','labels'],true))$ok=false;if($ok)$covered++;if(($item['value_type']??'')==='select')$selects++;}
  foreach($items as $item){$cfg=$item['config_json']??[];if(json_decode($encodeConfig->invoke(null,$cfg),true)!==$cfg)$storedOk=false;}
  c350($manifestsOk&&count($items)>0&&count($items)===array_sum(array_map(static fn(array $s): int=>(int)($s['expected']['items']??0),(array)($data['scenarios']??[]))),'all current system negotiation items inspected against manifests');
  c350($covered===count($items),'all current system item configs covered by normal fields');
  c350($selects>0,'select items included in compatibility audit');
  c350($storedOk,'production JSON storage preserves every current item config');
  $normalizedOk=true;
  // normalizeItems accepts canonical editor payloads, not raw installation aliases.
  // The separate Node direction-roundtrip suite executes the actual UI mapper on
  // every seed preference before calling this same PHP backend. Here a canonical
  // preference fixture keeps the all-current-config storage check independent.
  foreach($data['scenarios'] as $scenario){$entries=$scenario['components']['items']??[];foreach($entries as &$entry){$entry['player_preference_direction']='custom_rule';$entry['opponent_preference_direction']='custom_rule';}unset($entry);$normalized=$normalizeItems->invoke($builder,$entries);if(count($normalized)!==count($entries))$normalizedOk=false;foreach($entries as $index=>$item){$row=$normalized[$index]??[];if(json_decode($row['config_json']??'null',true)!==($item['config_json']??[])||($row['player_preference_direction']??null)!==($item['player_preference_direction']??null)||($row['opponent_preference_direction']??null)!==($item['opponent_preference_direction']??null))$normalizedOk=false;}}
  c350($normalizedOk,'production item normalizer preserves all current configs and canonical preference payloads');
  $legacy=['opening'=>7,'custom_extension'=>['retained'=>true]];
  c350(json_decode($encodeConfig->invoke(null,$legacy),true)===$legacy&&str_contains($page,'data-ui="item_config_preserved"')&&str_contains($js,'const out={...original};for(const k of'),'legacy config extensions retained in production storage and builder preservation path');
}else c350(false,'seed JSON parsed');
fwrite(STDOUT,sprintf("NEG-BUILDER-CLEANUP: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

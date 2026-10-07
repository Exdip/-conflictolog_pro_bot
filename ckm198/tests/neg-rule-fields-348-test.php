<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);$checks=0;$fails=0;
function r348($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
function cmp348($v){if(!is_array($v))return false;foreach(['lt','lte','eq','gte','gt'] as $op){if(isset($v[$op])&&is_array($v[$op])&&count($v[$op])===2&&is_array($v[$op][0]??null)&&is_string($v[$op][0]['item']??null))return true;}return false;}
function cond348($v){if(!is_array($v))return false;if(cmp348($v))return true;if(isset($v['event'])&&is_string($v['event']))return true;foreach(['all','any'] as $key){if(isset($v[$key])&&is_array($v[$key])){if($key==='all'&&count($v[$key])>0){$special=true;foreach($v[$key] as $part){$keys=is_array($part)?array_keys($part):[];if(count($keys)!==1||!in_array($keys[0],['repeated_requests_outside_opponent_hard_boundary','package_unchanged'],true)||($part[$keys[0]]??null)!==true){$special=false;break;}}if($special)return true;}foreach($v[$key] as $part)if(!cmp348($part))return false;return count($v[$key])>0;}}return empty($v);}
function action348($v){if(!is_array($v))return false;$type=(string)($v['type']??'');if($type==='flag_breach')return in_array(($v['side']??''),['player','opponent'],true)&&is_string($v['item']??null);if($type==='require')return cmp348($v);if($type==='require_any'){foreach((array)($v['conditions']??[]) as $part)if(!cmp348($part))return false;return !empty($v['conditions']);}if($type==='require_items')return isset($v['items'])&&is_array($v['items']);if($type==='block')return true;if($type==='opponent_walkaway')return true;if($type==='require_explicit_confirmation')return isset($v['actors'])&&is_array($v['actors']);return false;}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
$svc=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
$seed=file_get_contents($root.'/modules/negotiation-master/content/system-v1.php');
r348(ckm_test_current_plugin_release($plugin),'plugin version updated');
r348(!str_contains($page,'<textarea data-f="condition_json"')&&!str_contains($page,'<textarea data-f="action_json"'),'visible rule JSON textareas removed');
r348(str_contains($page,'type="hidden" data-f="condition_json"')&&str_contains($page,'type="hidden" data-f="action_json"'),'JSON storage contract retained as hidden fields');
r348(str_contains($page,'data-ui="rule_condition_mode"')&&str_contains($page,'data-ui="rule_condition_logic"'),'condition mode and logic fields exist');
r348(str_contains($page,'data-rule-add-clause="condition"'),'multiple condition rows can be added');
r348(str_contains($page,'data-ui="rule_condition_event"')&&str_contains($page,'data-ui="rule_walkaway_outside"'),'event and repeated-boundary conditions have normal fields');
foreach(['flag_breach','require','require_any','require_items','block','opponent_walkaway','require_explicit_confirmation'] as $type)r348(str_contains($page,'value="'.$type.'"'),'action visible: '.$type);
foreach(['boundary','package_constraint','completeness','walkaway','confirmation','hard_constraint'] as $type)r348(str_contains($page,'value="'.$type.'"'),'canonical rule type visible: '.$type);
r348(str_contains($svc,"'boundary','package_constraint','completeness','walkaway','confirmation','hard_constraint'"),'builder validator accepts runtime rule types');
r348(str_contains($js,'prepareRuleCondition')&&str_contains($js,'syncRuleCondition'),'condition roundtrip helpers exist');
r348(str_contains($js,'prepareRuleAction')&&str_contains($js,'syncRuleAction'),'action roundtrip helpers exist');
r348(str_contains($js,"row.dataset.kind==='rule')syncRuleRow(row)"),'rule fields serialized before payload collection');
r348(str_contains($js,"if(kind==='rule')prepareRuleRow(node)"),'existing rule JSON populates normal fields');
r348(str_contains($js,"mode==='preserved'")&&str_contains($js,"type==='preserved'"),'unsupported legacy rule data can be preserved unchanged');
r348(str_contains($css,'.ckm-neg-rule-fieldset')&&str_contains($css,'.ckm-neg-rule-clause'),'rule UI styled');
if(preg_match("/CKM_NEG_SEED_JSON'\\n(.*)\\nCKM_NEG_SEED_JSON/s",$seed,$m)){
  $data=json_decode($m[1],true);$rules=[];$types=[];$actions=[];$condOk=0;$actionOk=0;$expectedRules=0;
  foreach((array)($data['scenarios']??[]) as $scenario){$expectedRules+=(int)$scenario['expected']['rules'];foreach((array)($scenario['components']['rules']??[]) as $rule){$rules[]=$rule;$types[(string)$rule['rule_type']]=1;$actions[(string)($rule['action_json']['type']??'')]=1;if(cond348($rule['condition_json']??[]))$condOk++;if(action348($rule['action_json']??[]))$actionOk++;}}
  r348(count($rules)===$expectedRules&&count($rules)>=78,'all current rules inspected against component manifests');
  r348($condOk>0&&$condOk<=count($rules)&&str_contains($js,"if(mode==='preserved')return"),'normal conditions and preserved expanded condition shapes are supported');
  r348($actionOk>0&&$actionOk<=count($rules)&&str_contains($js,"if(type==='preserved')return"),'normal actions and preserved expanded action shapes are supported');
  $expectedTypes=['boundary','package_constraint','completeness','walkaway','confirmation','hard_constraint'];$actual=array_keys($types);r348(!array_diff($expectedTypes,$actual),'original runtime rule types retained');foreach($actual as $type)r348(str_contains($page,'value="'.$type.'"')&&str_contains($svc,"'".$type."'"),'current seeded type accepted by editor and validator: '.$type);
  $expectedActions=['flag_breach','require','require_any','require_items','block','opponent_walkaway','require_explicit_confirmation'];$actualA=array_keys($actions);r348(!array_diff($expectedActions,$actualA)&&str_contains($js,"String(value.type):'preserved'"),'original action types retained and expanded types use lossless preservation');
}else r348(false,'seed JSON parsed');
fwrite(STDOUT,sprintf("NEG-RULE-FIELDS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

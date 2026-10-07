<?php
require_once __DIR__ . '/support/plugin-release.php';
define('ABSPATH', __DIR__);
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
function wp_unslash($value){return $value;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$value));}
function sanitize_text_field($value){return strip_tags((string)$value);}
$root=dirname(__DIR__);$checks=0;$fails=0;
function e349($ok,$name){global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $name\n");}}
function rubric349($r){if(!is_array($r))return false;$allowed=['description','scale','anchors'];foreach(array_keys($r) as $k)if(!in_array($k,$allowed,true))return false;$scale=$r['scale']??[];$anchors=$r['anchors']??[];if(!is_array($scale)||!isset($scale['min'],$scale['max'])||!is_numeric($scale['min'])||!is_numeric($scale['max'])||(float)$scale['min']>=(float)$scale['max']||!is_array($anchors))return false;$keys=array_keys($anchors);sort($keys);$five=['adequate','excellent','limited','strong','weak'];sort($five);if($keys===$five)return true;$min=(float)$scale['min'];$max=(float)$scale['max'];$mid=$min+($max-$min)/2;$expected=[($min==(int)$min?(int)$min:(string)$min),($mid==(int)$mid?(int)$mid:(string)$mid),($max==(int)$max?(int)$max:(string)$max)];sort($expected);return $keys===$expected;}
function config349($c){if(!is_array($c))return false;foreach(array_keys($c) as $k)if(!in_array($k,['evidence_required','runtime_enabled','php_share'],true))return false;if(array_key_exists('evidence_required',$c)&&!is_bool($c['evidence_required']))return false;if(array_key_exists('runtime_enabled',$c)&&!is_bool($c['runtime_enabled']))return false;if(array_key_exists('php_share',$c)&&(!is_numeric($c['php_share'])||(float)$c['php_share']<0||(float)$c['php_share']>1))return false;return true;}
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
$css=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.css');
$svc=file_get_contents($root.'/modules/negotiation-master/application/scenario-builder-service.php');
$data=require $root.'/modules/negotiation-master/content/system-v1.php';
require_once $root.'/modules/negotiation-master/application/scenario-builder-service.php';
$normalizer=new ReflectionMethod(CKM\NegotiationMaster\ScenarioBuilderService::class,'normalizeEvaluation');
$builder=new CKM\NegotiationMaster\ScenarioBuilderService();
e349(ckm_test_current_plugin_release($plugin),'plugin version updated');
$evalTemplate='';if(preg_match('/<template id="nb-evaluation-template">(.*?)<\/template>/s',$page,$tm))$evalTemplate=$tm[1];e349($evalTemplate!==''&&!str_contains($evalTemplate,'<textarea data-f="rubric_json"')&&!str_contains($evalTemplate,'<textarea data-f="config_json"'),'visible evaluation JSON textareas removed');
e349(str_contains($page,'type="hidden" data-f="rubric_json"')&&str_contains($page,'type="hidden" data-f="config_json"'),'evaluation JSON storage retained as hidden fields');
foreach(['evaluation_description','evaluation_rubric_mode','evaluation_scale_min','evaluation_scale_max','evaluation_anchor_weak','evaluation_anchor_limited','evaluation_anchor_adequate','evaluation_anchor_strong','evaluation_anchor_excellent','evaluation_anchor_min','evaluation_anchor_mid','evaluation_anchor_max','evaluation_evidence_required','evaluation_runtime_enabled','evaluation_php_share'] as $field)e349(str_contains($page,'data-ui="'.$field.'"'),'normal evaluation field: '.$field);
foreach(['hybrid','php','ai','rubric'] as $type)e349(str_contains($page,'value="'.$type.'"'),'evaluation type visible: '.$type);
e349(str_contains($svc,"private const EVAL_TYPES = ['php','ai','hybrid','rubric'];"),'builder validator accepts system rubric type');
e349(str_contains($js,'prepareEvaluationRow')&&str_contains($js,'syncEvaluationRow'),'evaluation roundtrip helpers exist');
e349(str_contains($js,"row.dataset.kind==='evaluation')syncEvaluationRow(row)"),'evaluation serialized before payload');
e349(str_contains($js,"if(kind==='evaluation')prepareEvaluationRow(node)"),'stored evaluation data populates normal fields');
e349(str_contains($js,"mode==='preserved'")||str_contains($js,"mode!=='preserved'"),'legacy complex rubric preservation path exists');
e349(str_contains($js,'evidence_required')&&str_contains($js,'runtime_enabled')&&str_contains($js,'php_share'),'known evaluation config fields roundtrip');
e349(str_contains($css,'.ckm-neg-evaluation-fieldset')&&str_contains($css,'.ckm-neg-evaluation-anchors'),'evaluation UI styled');
// .349's 88 criteria belonged to an older pack. Inspect every current scenario
// against its authoritative manifest and exercise the production normalizer.
if(is_array($data)&&!empty($data['scenarios'])){
  $rules=[];$rubricOk=0;$configOk=0;$types=[];$manifestsOk=true;$storedOk=true;
  foreach($data['scenarios'] as $scenario){$entries=$scenario['components']['evaluation_rules']??[];$expected=$scenario['expected']['evaluation_rules']??null;if(!is_int($expected)||$expected<1||count($entries)!==$expected)$manifestsOk=false;$normalized=$normalizer->invoke($builder,$entries);if(count($normalized)!==count($entries))$storedOk=false;foreach($entries as $index=>$rule){$row=$normalized[$index]??[];if(($row['evaluation_type']??null)!==($rule['evaluation_type']??null)||json_decode($row['rubric_json']??'null',true)!==($rule['rubric_json']??[])||json_decode($row['config_json']??'null',true)!==($rule['config_json']??[]))$storedOk=false;}}
  foreach((array)($data['scenarios']??[]) as $scenario)foreach((array)($scenario['components']['evaluation_rules']??[]) as $rule){$rules[]=$rule;$types[(string)($rule['evaluation_type']??'')]=1;if(rubric349($rule['rubric_json']??[]))$rubricOk++;if(config349($rule['config_json']??[]))$configOk++;}
  e349($manifestsOk&&count($rules)>0&&count($rules)===array_sum(array_map(static fn(array $s): int=>(int)($s['expected']['evaluation_rules']??0),(array)($data['scenarios']??[]))),'all current system evaluation criteria inspected against manifests');
  e349($rubricOk===count($rules),'all current system rubrics covered by normal editor');
  e349($configOk===count($rules),'all current system evaluation configs covered by normal editor');
  e349(isset($types['rubric'])&&isset($types['hybrid'])&&isset($types['ai']),'real system evaluation types represented');
  $weightsOk=true;foreach((array)($data['scenarios']??[]) as $scenario){$sum=0;foreach((array)($scenario['components']['evaluation_rules']??[]) as $rule)$sum+=(float)($rule['weight']??0);if(abs($sum-100)>0.001){$weightsOk=false;break;}}e349($weightsOk,'every system scenario evaluation weight remains 100');
  e349($storedOk,'production evaluator normalizer preserves every current rubric and config');
  $legacy=['code'=>'legacy_criterion','title'=>'Legacy criterion','evaluation_type'=>'hybrid','weight'=>100,'rubric_json'=>['custom_rubric'=>['retained'=>true]],'config_json'=>['custom_extension'=>true]];
  $stored=$normalizer->invoke($builder,[$legacy])[0];
  e349(json_decode($stored['rubric_json'],true)===$legacy['rubric_json']&&json_decode($stored['config_json'],true)===$legacy['config_json']&&str_contains($page,'data-ui="evaluation_config_preserved"'),'legacy complex evaluation data retained by production normalizer and visible preservation path');
}else e349(false,'seed JSON parsed');
fwrite(STDOUT,sprintf("NEG-EVALUATION-FIELDS: %d/%d PASS\n",$checks-$fails,$checks));exit($fails?1:0);

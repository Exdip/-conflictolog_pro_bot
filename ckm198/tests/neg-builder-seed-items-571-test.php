<?php
/** Current seed items must survive the production builder, including preference aliases. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return $v;}
function sanitize_textarea_field($v){return $v;}
function wp_json_encode($v,$f=0){return json_encode($v,$f);}
require $argv[1] ?? dirname(__DIR__) . '/modules/negotiation-master/application/scenario-builder-service.php';
use CKM\NegotiationMaster\ScenarioBuilderService;
use CKM\NegotiationMaster\ScenarioBuilderValidationException;
$root=dirname(__DIR__);$pack=require $root.'/modules/negotiation-master/content/system-v1.php';
if(isset($argv[2])){$pack=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);}
else{
    // Native PHP regression uses valid backend fixtures; the Node suite passes every
    // seeded item through the actual editor mapper and then invokes this entrypoint.
    $example=$pack['scenarios'][0]['components']['items'][0];$cases=[];
    foreach(['higher_better','lower_better','custom_rule'] as $direction){$item=$example;$item['player_preference_direction']=$direction;$item['opponent_preference_direction']=$direction;$cases[]=['scenario'=>['slug'=>'canonical-preference-fixture'],'components'=>['items'=>[$item]]];}
    $pack['scenarios']=$cases;
}
$method=new ReflectionMethod(ScenarioBuilderService::class,'normalizeItems');$method->setAccessible(true);$builder=new ScenarioBuilderService();$checks=0;$fails=0;$directions=[];
function items571(bool $ok,string $label):void{global $checks,$fails;$checks++;if(!$ok){$fails++;fwrite(STDERR,"FAIL: $label\n");}}
foreach($pack['scenarios'] as $scenario){
    $source=$scenario['components']['items'];
    try{$actual=$method->invoke($builder,$source);}
    catch(Throwable $e){items571(false,'seed items rejected: '.$scenario['scenario']['slug'].': '.$e->getMessage());continue;}
    items571(count($actual)===count($source),'all seed items retained');
    foreach($source as $i=>$item){
        foreach(['code','title','value_type','unit','player_preference_direction','opponent_preference_direction','reopen_policy'] as $field){items571($actual[$i][$field]===$item[$field],'item field preserved: '.$field);}
        foreach(['player_target_json','player_boundary_json','opponent_target_json','opponent_boundary_json','config_json'] as $field){items571(json_decode($actual[$i][$field],true)===$item[$field],'item data preserved: '.$field);}
        foreach(['player_preference_direction','opponent_preference_direction'] as $field)$directions[$item[$field]]=true;
    }
}
$page=file_get_contents($root.'/modules/negotiation-master/public/builder-page.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-builder.js');
foreach(array_keys($directions) as $direction){
    $uiDirection=$direction;
    items571(substr_count($page,'value="'.$uiDirection.'"')>=2,'both player and opponent can retain seeded preference: '.$direction);
}
items571(str_contains($js,"value==='higher'?'higher_better':value==='lower'?'lower_better':value==='custom'?'custom_rule':value"),'seed preference aliases retain the current editor normalization');
$invalid=$pack['scenarios'][0]['components']['items'][0];$invalid['player_preference_direction']='unsupported_direction';$rejected=false;
try{$method->invoke($builder,[$invalid]);}catch(ScenarioBuilderValidationException $e){$rejected=true;}
items571($rejected,'unknown preference directions remain rejected');
echo sprintf("NEG-BUILDER-SEED-ITEMS-571: %d/%d PASS, %d FAIL\n",$checks-$fails,$checks,$fails);exit($fails?1:0);

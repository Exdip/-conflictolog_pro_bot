<?php
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__.'/fixtures/');
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v,$flags=0){ return json_encode($v,$flags); } }
require dirname(__DIR__).'/modules/negotiation-master/ai/coach/coach-response-validator.php';
require dirname(__DIR__).'/modules/negotiation-master/ai/coach/coach-prompt-builder.php';
use CKM\NegotiationMaster\{CoachResponseValidator,CoachPromptBuilder};
$checks=[];function v323(array &$c,bool $ok,string $label):void{$c[]=[$ok,$label];echo($ok?'PASS':'FAIL').": $label\n";}
function rejects323(callable $fn):bool{try{$fn();return false;}catch(UnexpectedValueException){return true;}}
$v=new CoachResponseValidator();
v323($checks,$v->validate('Вы уже уступили по цене до того, как выяснили другие критерии.','attention')!=='','attention accepts concise observation');
v323($checks,$v->validate('Попробуйте выяснить, какие критерии кроме цены важны клиенту.','direction')!=='','direction accepts one action direction');
v323($checks,$v->validate('Кроме стоимости, какие параметры предложения для вас наиболее важны?','example')!=='','example accepts one ready utterance');
v323($checks,$v->validate('Вы обозначили конкретную позицию. Но уступка не связана со встречным условием. В дальнейшем сохраняйте обменность.','review_last_move')!=='','review accepts three short sentences');
v323($checks,rejects323(fn()=>$v->validate("• Вариант один\n• Вариант два",'example')),'example rejects list of alternatives');
v323($checks,rejects323(fn()=>$v->validate('opponent_hidden_interests: secret','attention')),'technical secret key rejected');
v323($checks,rejects323(fn()=>$v->validate('Первое. Второе. Третье.','attention')),'attention rejects overlong sentence count');
v323($checks,rejects323(fn()=>$v->validate('Спросите клиента о других критериях.','attention')),'attention rejects prescribed next move');
v323($checks,rejects323(fn()=>$v->validate('Скажите: «Какие критерии кроме цены важны?»','direction')),'direction rejects ready-made utterance');
v323($checks,rejects323(fn()=>$v->validate('Например: Какие условия для вас важны?','example')),'example rejects meta prefix');
$p=new CoachPromptBuilder();
$messages=$p->messages(['help_level'=>'direction','player_card'=>['task'=>'T'],'visible_items'=>[],'visible_facts'=>[['content'=>'Открытый факт']],'recent_dialogue'=>[],'previous_hints'=>[],'last_player_message'=>null,'coach_counts'=>[]]);
$wire=json_encode($messages,JSON_UNESCAPED_UNICODE);
v323($checks,str_contains($wire,'Открытый факт') && !str_contains($wire,'opponent_hidden_interests'),'prompt contains only supplied safe context');
v323($checks,str_contains($wire,'гипотез') || str_contains($wire,'возможно'),'prompt marks unknowns as hypotheses');
$failed=array_filter($checks,fn($x)=>!$x[0]);echo count($checks)." checks, ".count($failed)." failed.\n";exit($failed?1:0);

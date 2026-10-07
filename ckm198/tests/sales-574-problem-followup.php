<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require dirname(__DIR__) . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
require dirname(__DIR__) . '/modules/negotiation-master/ai/arbiter/arbiter-service.php';

use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\ArbiterService;

$passed=0;
function s574(string $label,bool $ok): void {
    global $passed;
    if(!$ok){fwrite(STDERR,"FAIL: {$label}\n");exit(1);}
    $passed++;
}

$question='Понял. А что именно в текущем способе работы вас не устраивает или создаёт проблемы для менеджеров?';
$facts=[
    ['code'=>'generated_fact_1','title'=>'Текущая ситуация','content'=>'Клиент уже решает задачу некоторым способом и не станет менять его без понятной причины.','current_reveal_level'=>1,'reveal_rules'=>['partial'=>'Вопрос о Текущая ситуация','revealed'=>'Прямое уточнение факта: Текущая ситуация']],
    ['code'=>'generated_fact_2','title'=>'Проблема','content'=>'У клиента есть практическое неудобство или потеря, которую нужно выявить вопросами, а не предполагать.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о Проблема','revealed'=>'Прямое уточнение факта: Проблема']],
];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'ИИ-клиент'],'hidden_facts'=>$facts,'recent_dialogue'=>[],'sales_reply_history'=>[],'validated_state'=>['discovered_facts'=>[['code'=>'generated_fact_1','reveal_level'=>1]]]];

$fallback=new SalesClientFallback();
s574('follow-up question is not swallowed by progress reply',$fallback->buildProgressReply($ctx,$question)==='');
$reply=$fallback->buildFactReply($ctx,$question);
s574('follow-up question does not fall back to opening',$reply!==''&&$reply!=='Расскажите, чем ваше предложение может быть полезно именно в нашей ситуации.');
s574('follow-up question is grounded in problem fact',str_contains($reply,'практическое неудобство')||str_contains($reply,'потер'));

$method=new ReflectionMethod(ArbiterService::class,'deterministicFactUpdates');
$updates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'player','content'=>$question],'hidden_facts'=>$facts]);
s574('arbiter records problem follow-up as partial',count($updates)===1&&($updates[0]['fact_code']??'')==='generated_fact_2'&&($updates[0]['suggested_level']??'')==='partial');

echo "{$passed} SALES-574 problem follow-up regression checks passed.\n";

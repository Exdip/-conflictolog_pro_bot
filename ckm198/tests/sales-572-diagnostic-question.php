<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require dirname(__DIR__) . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
require dirname(__DIR__) . '/modules/negotiation-master/ai/arbiter/arbiter-service.php';

use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\ArbiterService;

$passed=0;
function s572(string $label,bool $ok): void {
    global $passed;
    if(!$ok){fwrite(STDERR,"FAIL: {$label}\n");exit(1);}
    $passed++;
}

$question='Спасибо. Чтобы понять, будет ли наше решение полезно именно вам: в каких ситуациях ваш текущий скрипт или работа менеджеров чаще всего перестают давать нужный результат?';
$facts=[
    ['code'=>'generated_fact_1','title'=>'Текущая ситуация','content'=>'Клиент уже решает задачу некоторым способом и не станет менять его без понятной причины.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о Текущая ситуация','revealed'=>'Прямое уточнение факта: Текущая ситуация']],
    ['code'=>'generated_fact_2','title'=>'Проблема','content'=>'У клиента есть практическое неудобство или потеря, которую нужно выявить вопросами, а не предполагать.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о Проблема','revealed'=>'Прямое уточнение факта: Проблема']],
];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'ИИ-клиент'],'hidden_facts'=>$facts,'recent_dialogue'=>[],'sales_reply_history'=>[],'validated_state'=>[]];

$fallback=new SalesClientFallback();
$progress=$fallback->buildProgressReply($ctx,$question);
s572('diagnostic question is not swallowed by value progress acknowledgement',$progress==='');
$reply=$fallback->buildFactReply($ctx,$question);
s572('diagnostic question gets grounded client reply',$reply!=='');
s572('grounded reply uses client perspective',str_contains($reply,'Мы ')||str_contains($reply,'У нас '));

$method=new ReflectionMethod(ArbiterService::class,'deterministicFactUpdates');
$updates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'player','content'=>$question],'hidden_facts'=>$facts]);
s572('arbiter records diagnostic process question as partial',count($updates)>=1&&($updates[0]['suggested_level']??'')==='partial');

echo "{$passed} SALES-572 diagnostic regression checks passed.\n";

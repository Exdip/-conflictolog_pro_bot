<?php
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require dirname(__DIR__) . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';

use CKM\NegotiationMaster\SalesClientFallback;

$passed=0;
function s575(string $label,bool $ok): void {
    global $passed;
    if(!$ok){fwrite(STDERR,"FAIL: {$label}\n");exit(1);}
    $passed++;
}

$question='Понял. А что именно в текущем способе работы вас не устраивает или создаёт проблемы для менеджеров?';
$facts=[
    ['code'=>'generated_fact_1','title'=>'Текущая ситуация','content'=>'Клиент уже решает задачу некоторым способом и не станет менять его без понятной причины.','current_reveal_level'=>1,'reveal_rules'=>['partial'=>'Вопрос о Текущая ситуация','revealed'=>'Прямое уточнение факта: Текущая ситуация']],
    ['code'=>'generated_fact_2','title'=>'Проблема','content'=>'У клиента есть практическое неудобство или потеря, которую нужно выявить вопросами, а не предполагать.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о Проблема','revealed'=>'Прямое уточнение факта: Проблема']],
];
$ctx=[
    'mechanics'=>['training_domain'=>'sales'],
    'identity'=>['name'=>'ИИ-клиент'],
    'hidden_facts'=>$facts,
    'validated_state'=>['discovered_facts'=>[['code'=>'generated_fact_1','reveal_level'=>1]]],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'Мы уже решаем задачу некоторым способом и не станем менять этот способ без понятной причины.'],
        ['actor'=>'player','content'=>$question],
    ],
    'sales_reply_history'=>[
        ['actor'=>'opponent','content'=>'Мы уже решаем задачу некоторым способом и не станем менять этот способ без понятной причины.'],
    ],
];

$fallback=new SalesClientFallback();
s575('problem follow-up is not swallowed by progress reply',$fallback->buildProgressReply($ctx,$question)==='');
$reply=$fallback->buildFactReply($ctx,$question);
s575('problem follow-up answers from the undiscovered problem fact',$reply==='У нас есть практическое неудобство или потеря.');
s575('problem reply is not rejected as duplicate',$fallback->recentDuplicate($ctx,$reply)==='');
s575('problem reply does not expose authoring instruction',!str_contains($reply,'выявить вопросами')&&!str_contains($reply,'предполагать'));

echo "{$passed} SALES-575 problem reply regression checks passed.\n";

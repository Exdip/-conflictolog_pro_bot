<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s517(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$builder = file_get_contents($root . '/modules/negotiation-master/ai/opponent/opponent-context-builder.php');
$service = file_get_contents($root . '/modules/negotiation-master/ai/opponent/opponent-service.php');
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();

s517('plugin version', ckm_test_current_plugin_release($main));
s517('sales history built only for sales context', str_contains($builder, "training_domain'] ?? '') === 'sales'"));
s517('sales history is separate from recent prompt context', str_contains($builder, "'sales_reply_history' => \$salesReplyHistory"));
s517('fallback rotation uses short-window method', str_contains($service, 'recentFallbackDuplicate($context, $loopReply)'));

$old = 'У нас в отделе продаж 18 менеджеров.';
$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'Первый новый ответ.'], ['actor'=>'player','content'=>'Продолжим?'],
        ['actor'=>'opponent','content'=>'Второй новый ответ.'], ['actor'=>'player','content'=>'Что дальше?'],
        ['actor'=>'opponent','content'=>'Третий новый ответ.'], ['actor'=>'player','content'=>'И ещё?'],
        ['actor'=>'opponent','content'=>'Четвёртый новый ответ.'], ['actor'=>'player','content'=>'Расскажите подробнее.'],
    ],
    'sales_reply_history'=>[
        ['content'=>$old],
        ['content'=>'Первый новый ответ.'],
        ['content'=>'Второй новый ответ.'],
        ['content'=>'Третий новый ответ.'],
        ['content'=>'Четвёртый новый ответ.'],
    ],
    'hidden_facts'=>[],
];
s517('old reply outside last-four window is still blocked', $fallback->recentDuplicate($ctx,$old)!=='');

$factCtx=$ctx;
$factCtx['recent_dialogue'][]=['actor'=>'player','content'=>'Сколько менеджеров у вас в отделе продаж?'];
$factCtx['hidden_facts']=[[
    'title'=>'Размер команды',
    'content'=>$old,
    'reveal_rules'=>['partial'=>'размер отдела продаж','revealed'=>'сколько менеджеров в отделе продаж'],
]];
s517('direct re-question still permits scenario fact', $fallback->recentDuplicate($factCtx,$old)==='');

$phrase='Пока мне не хватает новой конкретики, чтобы двигаться дальше. Что именно вы предлагаете сделать на следующем шаге?';
$fbCtx=[
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[['actor'=>'opponent','content'=>$phrase],['actor'=>'player','content'=>'И всё же?']],
    'sales_reply_history'=>[['content'=>$phrase]],
    'hidden_facts'=>[],
];
s517('fallback short-window still detects immediate repeat', $fallback->recentFallbackDuplicate($fbCtx,$phrase)!=='');

$neg=$ctx; $neg['mechanics']['training_domain']='negotiation';
s517('non-sales still bypasses session-history dedup', $fallback->recentDuplicate($neg,$old)==='');

echo "{$passed} SALES-517 regression checks passed. No database required.\n";

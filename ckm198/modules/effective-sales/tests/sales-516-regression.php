<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s516(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$service = file_get_contents($root . '/modules/negotiation-master/ai/opponent/opponent-service.php');
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();

$phrases = [
    'Мне пока не хватает новой конкретики. Что именно вы предлагаете сделать дальше?',
    'Мы это уже обсудили. Что нового вы можете предложить по нашей ситуации?',
    'Для меня важен конкретный результат. Что изменится после вашего предложения?',
    'Мне нужен новый предмет обсуждения: что конкретно вы хотите проверить или показать?',
    'Хорошо. Что именно вы предлагаете сделать дальше?',
];

s516('plugin version', ckm_test_current_plugin_release($main));
s516('five fallback phrases present', count(array_filter($phrases, fn($p)=>str_contains($service,$p))) === 5);
s516('fallback selection uses short-window recentFallbackDuplicate', str_contains($service, "recentFallbackDuplicate(\$context, \$loopReply)"));

$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>$phrases[0]], ['actor'=>'player','content'=>'Повторите?'],
        ['actor'=>'opponent','content'=>$phrases[1]], ['actor'=>'player','content'=>'И всё же?'],
        ['actor'=>'opponent','content'=>$phrases[2]], ['actor'=>'player','content'=>'Ещё раз?'],
        ['actor'=>'opponent','content'=>$phrases[3]], ['actor'=>'player','content'=>'Что дальше?'],
    ],
    'hidden_facts'=>[],
];
s516('first four fallbacks are blocked', $fallback->recentFallbackDuplicate($ctx,$phrases[0])!=='' && $fallback->recentFallbackDuplicate($ctx,$phrases[1])!=='' && $fallback->recentFallbackDuplicate($ctx,$phrases[2])!=='' && $fallback->recentFallbackDuplicate($ctx,$phrases[3])!=='');
s516('fifth fallback remains available', $fallback->recentFallbackDuplicate($ctx,$phrases[4])==='');

$neg=$ctx; $neg['mechanics']['training_domain']='negotiation';
s516('non-sales still bypasses fallback dedup', $fallback->recentFallbackDuplicate($neg,$phrases[0])==='');

echo "{$passed} SALES-516 regression checks passed. No database required.\n";

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s513(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();

$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'У нас в отделе продаж 18 менеджеров.'],
        ['actor'=>'player','content'=>'Предлагаю проверить выборку звонков.'],
        ['actor'=>'opponent','content'=>'Я успеваю вручную прослушивать только 5–7 звонков в неделю.'],
        ['actor'=>'player','content'=>'Что вы считаете проблемным звонком?'],
        ['actor'=>'opponent','content'=>'Смотрим жалобы и потерянные сделки.'],
        ['actor'=>'player','content'=>'Кто участвует в контроле качества?'],
        ['actor'=>'opponent','content'=>'Пока в основном я сама.'],
        ['actor'=>'player','content'=>'Как вы выбираете звонки для проверки?'],
    ],
    'hidden_facts'=>[],
];
s513('plugin version', ckm_test_current_plugin_release($main));
s513('fourth previous exact duplicate detected', $fallback->recentDuplicate($ctx, 'У нас в отделе продаж 18 менеджеров.') !== '');
s513('fourth previous near duplicate detected', $fallback->recentDuplicate($ctx, 'У нас в отделе продаж работают 18 менеджеров.') !== '');
s513('new reply accepted', $fallback->recentDuplicate($ctx, 'Для приоритизации берём жалобы и потерянные сделки.') === '');

$five = $ctx;
array_unshift($five['recent_dialogue'], ['actor'=>'opponent','content'=>'Пятый старый ответ, который уже вне окна.'], ['actor'=>'player','content'=>'Старый вопрос.']);
s513('full-session client duplicates remain blocked', $fallback->recentDuplicate($five, 'Пятый старый ответ, который уже вне окна.') !== '');
s513('fallback rotation ignores outside four-opponent window', $fallback->recentFallbackDuplicate($five, 'Пятый старый ответ, который уже вне окна.') === '');

$fact = [
    'code'=>'manual_control',
    'title'=>'Ручной контроль',
    'content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.',
    'reveal_rules'=>[
        'partial'=>'Вопрос о контроле качества разговоров',
        'revealed'=>'Уточнение, сколько звонков реально проверяется вручную',
    ],
];
$direct = [
    'mechanics'=>['training_domain'=>'sales'],
    'hidden_facts'=>[$fact],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.'],
        ['actor'=>'player','content'=>'Сколько звонков вы успеваете прослушивать вручную?'],
    ],
];
s513('direct re-question may repeat exact fact', $fallback->recentDuplicate($direct, 'Елена успевает вручную прослушивать только 5–7 звонков в неделю.') === '');

$notDirect = $direct;
$notDirect['recent_dialogue'][1]['content'] = 'Предлагаю провести бесплатный аудит.';
s513('unrelated turn still blocks repeated fact', $fallback->recentDuplicate($notDirect, 'Елена успевает вручную прослушивать только 5–7 звонков в неделю.') !== '');

$neg = $ctx; $neg['mechanics']['training_domain']='negotiation';
s513('non-sales bypasses dedup', $fallback->recentDuplicate($neg, 'У нас в отделе продаж 18 менеджеров.') === '');

echo "{$passed} SALES-513 regression checks passed. No database required.\n";

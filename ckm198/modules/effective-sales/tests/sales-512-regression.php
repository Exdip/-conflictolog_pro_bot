<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s512(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
$service = file_get_contents($root . '/modules/negotiation-master/ai/opponent/opponent-service.php');
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();
$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'player','content'=>'Сколько звонков вы успеваете прослушивать?'],
        ['actor'=>'opponent','content'=>'Я успеваю вручную прослушивать только 5–7 звонков в неделю.'],
        ['actor'=>'player','content'=>'Как вы выбираете звонки для проверки?'],
        ['actor'=>'opponent','content'=>'У нас в отделе продаж 18 менеджеров.'],
        ['actor'=>'player','content'=>'Предлагаю проверить выборку звонков.'],
    ],
];
s512('plugin version', ckm_test_current_plugin_release($main));
s512('exact duplicate detected', $fallback->recentDuplicate($ctx, 'У нас в отделе продаж 18 менеджеров.') !== '');
s512('near duplicate detected', $fallback->recentDuplicate($ctx, 'У нас в отделе продаж работают 18 менеджеров.') !== '');
s512('new client reply accepted', $fallback->recentDuplicate($ctx, 'Хорошо, а что конкретно вы покажете по итогам аудита?') === '');
$neg = $ctx; $neg['mechanics']['training_domain']='negotiation';
s512('non-sales bypasses dedup', $fallback->recentDuplicate($neg, 'У нас в отделе продаж 18 менеджеров.') === '');
$fact = ['code'=>'manual_control','title'=>'Ручной контроль','content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.','reveal_rules'=>['partial'=>'Вопрос о контроле качества разговоров','revealed'=>'Уточнение, сколько звонков реально проверяется вручную']];
$revealed = ['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Елена Соколова'],'hidden_facts'=>[$fact],'validated_state'=>['discovered_facts'=>[['code'=>'manual_control','title'=>'Ручной контроль','reveal_level'=>2]]],'external_position'=>['statement'=>'Спасибо, но нам ничего не нужно.']];
s512('511 revealed-fact guard preserved', $fallback->buildFactReply($revealed, 'А как вы сейчас понимаете, какие звонки менеджеров стоит проверить в первую очередь?') === '');
s512('511 direct re-question preserved', str_contains($fallback->buildFactReply($revealed, 'Сколько звонков вы успеваете прослушивать вручную?'), '5–7 звонков'));
s512('service retries with explicit avoid instruction', str_contains($service, 'АНТИДУБЛЬ: не повторяй и не перефразируй почти дословно последние ответы клиента'));
s512('service checks deterministic reply before model', strpos($service, 'recentDuplicate($context, $validated)') < strpos($service, '$promptMessages = $this->prompts->messages'));
s512('final sales fallback also blocks duplicate', str_contains($service, 'recentDuplicate($context, $fallback)'));
echo "{$passed} SALES-512 regression checks passed. No database required.\n";

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$root = dirname(__DIR__, 3);
$passed = 0;
function s515(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main = file_get_contents($root . '/ckm-quiz-pro.php');
require $root . '/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback = new CKM\NegotiationMaster\SalesClientFallback();

$first = "Мы определяем, какие звонки стоит проверить, основываясь на нескольких факторах:\n"
    . "- Показатели эффективности (анализируем конверсию звонков в сделки, чтобы выявить менеджеров с низкими показателями).\n"
    . "- Клиентские жалобы (обращаем внимание на звонки, по которым поступали негативные отзывы от клиентов).\n"
    . "- Сложные ситуации (проверяем звонки, связанные с проблемными сделками или конфликтами).\n"
    . "- Случайная выборка (периодически выбираем звонки для общего контроля качества, чтобы получить полное представление о работе команды).\n\n"
    . "Эти подходы помогают нам сосредоточиться на наиболее критичных звонках и улучшать качество обслуживания клиентов.";

$paraphrase = "Мы определяем приоритетные звонки для проверки по следующим критериям:\n"
    . "- Показатели эффективности (анализируем конверсию звонков в сделки, чтобы выявить менеджеров с низкими результатами).\n"
    . "- Клиентские жалобы (обращаем внимание на звонки, по которым поступали негативные отзывы или жалобы от клиентов).\n"
    . "- Проблемные сделки (проверяем звонки, связанные с трудными или потерянными сделками, чтобы понять, где возникают сложности).\n"
    . "- Случайная выборка (периодически выбираем звонки для общего контроля качества, чтобы получить полное представление о работе команды).\n\n"
    . "Эти методы помогают сосредоточиться на наиболее важной информации и улучшать качество обслуживания.";

$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'У нас в отделе продаж 18 менеджеров.'],
        ['actor'=>'player','content'=>'Что ещё важно?'],
        ['actor'=>'opponent','content'=>$first],
        ['actor'=>'player','content'=>'А как вы выбираете звонки для проверки?'],
    ],
    'hidden_facts'=>[],
];

s515('plugin version', ckm_test_current_plugin_release($main));
s515('semantic paraphrase detected', $fallback->recentDuplicate($ctx, $paraphrase) !== '');

$distinct = "Сейчас основная проблема не в выборе звонков, а в том, что после прослушивания у нас нет единого процесса обратной связи менеджерам. Разборы проводятся нерегулярно, и выводы часто остаются у руководителя.";
s515('different long sales reply accepted', $fallback->recentDuplicate($ctx, $distinct) === '');

$shortCtx = [
    'mechanics'=>['training_domain'=>'sales'],
    'recent_dialogue'=>[
        ['actor'=>'opponent','content'=>'Нам важно качество звонков и обратная связь.'],
        ['actor'=>'player','content'=>'Что ещё?'],
    ],
    'hidden_facts'=>[],
];
s515('short generic similarity is not overblocked', $fallback->recentDuplicate($shortCtx, 'Нам важно качество разговоров и обратная связь менеджерам.') === '');

$neg = $ctx;
$neg['mechanics']['training_domain']='negotiation';
s515('non-sales bypasses semantic dedup', $fallback->recentDuplicate($neg, $paraphrase) === '');

echo "{$passed} SALES-515 regression checks passed. No database required.\n";

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s483(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }

$main = file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s483('current plugin release header and constant', ckm_test_current_plugin_release($main));

require_once $root.'/negotiation-master/ai/opponent/opponent-response-validator.php';
require_once $root.'/negotiation-master/ai/arbiter/arbiter-service.php';

use CKM\NegotiationMaster\OpponentResponseValidator;
use CKM\NegotiationMaster\ArbiterService;

$ctx = [
    'mechanics'=>['training_domain'=>'sales'],
    'identity'=>['name'=>'Андрей Морозов','role'=>'Коммерческий директор клиента'],
    'constraints'=>[], 'concession_space'=>[],
];
$validator = new OpponentResponseValidator();
$badWithLateI = "Существует несколько факторов, которые могут заставить рассматривать стоимость как слишком высокую.\n- Ограничение бюджета может быть основным фактором.\n- Неясные результаты могут вызывать сомнения.\n- Альтернативные решения также влияют на решение.\nЕсли вы хотите, я могу помочь рассмотреть эти аспекты подробнее.";
$rejected = false;
try { $validator->validate($badWithLateI, $ctx); } catch (UnexpectedValueException) { $rejected = true; }
s483('consultant list with late first-person escape is rejected', $rejected);
$good = 'Бюджет у нас в принципе есть. Я пока не понимаю, как эти 720 тысяч окупятся и что именно мы получим за эти деньги.';
s483('concrete first-person client answer is accepted', $validator->validate($good, $ctx) === $good);
$shortGood = 'Для нас это пока слишком дорого: я не вижу понятной окупаемости.';
s483('short concrete client answer is accepted', $validator->validate($shortGood, $ctx) === $shortGood);

$facts = [
    [
        'code'=>'fragmented_process','title'=>'Разрозненный процесс',
        'content'=>'Менеджеры работают одновременно в CRM, таблицах и мессенджерах; руководитель плохо видит, что происходит со сделками.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Вопрос о текущем процессе работы с лидами и сделками','revealed'=>'Уточнение, где именно передаются заявки, где ведутся данные и что руководитель не видит в текущем процессе'],
    ],
    [
        'code'=>'lost_leads_rate','title'=>'Потерянные лиды',
        'content'=>'Около 8–10% из примерно 600 входящих лидов в месяц не получают своевременного повторного контакта.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Вопрос о потерях, зависших лидах или качестве повторного контакта','revealed'=>'Уточнение масштаба потерь и количества входящих лидов в месяц'],
    ],
    [
        'code'=>'deal_margin','title'=>'Экономика одной сделки',
        'content'=>'Средняя валовая прибыль от одной дополнительной сделки составляет около 45 000 рублей.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Вопрос о ценности дополнительной сделки или экономических последствиях потерянных лидов','revealed'=>'Прямое уточнение средней валовой прибыли или экономического эффекта одной сделки'],
    ],
    [
        'code'=>'budget_exists','title'=>'Бюджет существует',
        'content'=>'Проблема не в абсолютном отсутствии денег: компания может потратить 720 000 рублей, если будет понятна экономика решения.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Уточнение, что именно означает «дорого» и с чем клиент сравнивает цену','revealed'=>'Выяснение, что ключевая проблема — непонятная окупаемость, а не отсутствие бюджета'],
    ],
    [
        'code'=>'decision_process','title'=>'Процесс согласования',
        'content'=>'Андрей должен обосновать покупку генеральному директору; без понятного расчёта он не понесёт предложение на согласование.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Вопрос о том, как принимается решение и кого ещё нужно подключить','revealed'=>'Уточнение роли генерального директора и того, что ему потребуется экономическое обоснование'],
    ],
    [
        'code'=>'previous_crm_failure','title'=>'Неудачный прошлый опыт',
        'content'=>'Генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.',
        'current_reveal_level'=>0,
        'reveal_rules'=>['partial'=>'Вопрос о прошлом опыте внедрения CRM или рисках перехода','revealed'=>'Прямое выяснение того, что предыдущая CRM не прижилась и это влияет на текущее решение'],
    ],
];

$method = new ReflectionMethod(ArbiterService::class, 'deterministicFactUpdates');
$method->setAccessible(true);
$probe = 'Что именно заставляет вас считать стоимость слишком высокой: ограничение бюджета или пока неясно, какой результат система даст бизнесу?';
$playerUpdates = $method->invoke(null, [
    'mechanics'=>['training_domain'=>'sales'],
    'target_message'=>['actor'=>'player','content'=>$probe],
    'hidden_facts'=>$facts,
]);
s483('price-value probe unlocks exactly one fact', count($playerUpdates)===1);
s483('price-value probe unlocks only budget fact partial', ($playerUpdates[0]['fact_code']??'')==='budget_exists' && ($playerUpdates[0]['suggested_level']??'')==='partial');

foreach ($facts as &$f) { if ($f['code']==='budget_exists') { $f['current_reveal_level']=1; } }
unset($f);
$opponentUpdates = $method->invoke(null, [
    'mechanics'=>['training_domain'=>'sales'],
    'target_message'=>['actor'=>'opponent','content'=>$good],
    'hidden_facts'=>$facts,
]);
s483('budget client answer reveals exactly one fact', count($opponentUpdates)===1);
s483('budget client answer reveals only budget fact', ($opponentUpdates[0]['fact_code']??'')==='budget_exists' && ($opponentUpdates[0]['suggested_level']??'')==='revealed');

foreach ($facts as &$f) { if ($f['code']==='budget_exists') { $f['current_reveal_level']=0; } }
unset($f);
$canon = new ReflectionMethod(ArbiterService::class, 'canonicalizeFactUpdates');
$canon->setAccessible(true);
$analysis = ['fact_updates'=>[
    ['fact_code'=>'decision_process','suggested_level'=>'revealed','confidence'=>0.99],
    ['fact_code'=>'previous_crm_failure','suggested_level'=>'revealed','confidence'=>0.99],
]];
$filtered = $canon->invoke(null, $analysis, [
    'mechanics'=>['training_domain'=>'sales'],
    'target_message'=>['actor'=>'player','content'=>$probe],
    'hidden_facts'=>$facts,
]);
s483('sales arbiter hallucinated fact updates are evidence-gated', count($filtered['fact_updates']??[])===1 && ($filtered['fact_updates'][0]['fact_code']??'')==='budget_exists');

$prompt = file_get_contents($root.'/negotiation-master/ai/opponent/opponent-prompt-builder.php');
s483('strict retry forces first-sentence client position', str_contains($prompt, 'В ПЕРВОЙ фразе прямо ответь про себя или свою компанию'));

$arb = file_get_contents($root.'/negotiation-master/ai/arbiter/arbiter-service.php');
s483('sales fact updates use evidence gate', str_contains($arb, 'data-driven reveal_rules as an evidence gate'));

echo "{$passed} SALES-483 regression checks passed. No database required.\n";

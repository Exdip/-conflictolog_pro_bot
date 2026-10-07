<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s482(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }

$main = file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s482('current plugin release header and constant', ckm_test_current_plugin_release($main));

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
$bad = 'Обе причины могут влиять на восприятие стоимости. Ограничение бюджета часто является важным фактором, особенно для малых и средних предприятий. С другой стороны, неопределенность в отношении ожидаемых результатов также может вызывать сомнения. Важно рассмотреть, какие конкретные преимущества система может предоставить, и оценить, насколько они оправдывают затраты.';
$rejected = false;
try { $validator->validate($bad, $ctx); } catch (UnexpectedValueException) { $rejected = true; }
s482('live detached consultant reply is rejected', $rejected);
$good = 'Бюджет у нас в принципе есть. Я пока не понимаю, как эти 720 тысяч окупятся и что именно мы получим за эти деньги.';
s482('in-role client reply is accepted', $validator->validate($good, $ctx) === $good);

$fact = [
    'code'=>'budget_exists',
    'title'=>'Бюджет существует',
    'content'=>'Проблема не в абсолютном отсутствии денег: компания может потратить 720 000 рублей, если будет понятна экономика решения.',
    'current_reveal_level'=>0,
    'reveal_rules'=>[
        'partial'=>'Уточнение, что именно означает «дорого» и с чем клиент сравнивает цену',
        'revealed'=>'Выяснение, что ключевая проблема — непонятная окупаемость, а не отсутствие бюджета',
    ],
];
$method = new ReflectionMethod(ArbiterService::class, 'deterministicFactUpdates');
$method->setAccessible(true);
$playerContext = [
    'mechanics'=>['training_domain'=>'sales'],
    'target_message'=>['actor'=>'player','content'=>'Что именно заставляет вас считать стоимость слишком высокой: ограничение бюджета или пока неясно, какой результат система даст бизнесу?'],
    'hidden_facts'=>[$fact],
];
$playerUpdates = $method->invoke(null, $playerContext);
s482('sales semantic probe marks budget fact partial', count($playerUpdates)===1 && ($playerUpdates[0]['fact_code']??'')==='budget_exists' && ($playerUpdates[0]['suggested_level']??'')==='partial');

$fact['current_reveal_level']=1;
$opponentContext = [
    'mechanics'=>['training_domain'=>'sales'],
    'target_message'=>['actor'=>'opponent','content'=>$good],
    'hidden_facts'=>[$fact],
];
$opponentUpdates = $method->invoke(null, $opponentContext);
s482('sales client answer reveals budget fact', count($opponentUpdates)===1 && ($opponentUpdates[0]['suggested_level']??'')==='revealed');

$prompt = file_get_contents($root.'/negotiation-master/ai/opponent/opponent-prompt-builder.php');
s482('sales prompt forbids theoretical both-sides answer', str_contains($prompt, 'Не рассуждай о том, что оба варианта теоретически возможны'));
$arb = file_get_contents($root.'/negotiation-master/ai/arbiter/arbiter-prompt-builder.php');
s482('arbiter requires semantic reveal-rule matching', str_contains($arb, 'сопоставляй смысл целевого сообщения'));

echo "{$passed} SALES-482 regression checks passed. No database required.\n";

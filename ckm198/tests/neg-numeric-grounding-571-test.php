<?php
/** Execute production deterministic grounding; optional source path supports a baseline negative control. */
if (PHP_SAPI !== 'cli') { exit; }
define('ABSPATH', __DIR__ . '/');
require $argv[1] ?? dirname(__DIR__) . '/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;
$method = new ReflectionMethod(ArbiterService::class, 'explicitDealValues');
$method->setAccessible(true);
$items = [
    ['code'=>'amount','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB'],
    ['code'=>'lead_time','value_type'=>'integer','title'=>'Срок поставки','unit'=>'дней'],
];
$cases = [
    ['Предлагаю цену контракта 960 000 рублей и срок поставки 40 дней.', ['amount'=>960000,'lead_time'=>40]],
    ['Предлагаю срок поставки 40 дней и цену контракта 960 000 рублей.', ['amount'=>960000,'lead_time'=>40]],
    ['Цена контракта 20,5 млн рублей; срок поставки 40 дней.', ['amount'=>20500000,'lead_time'=>40]],
    ['Предлагаю срок поставки три дня.', ['lead_time'=>3]],
    ['Цена — три рубля.', ['amount'=>3]],
    ['Срок поставки — за три дня.', ['lead_time'=>3]],
    ['Вот три основных последствия задержки поставки для проекта:', []],
    ["Вот три основных последствия задержки поставки:\n1. Срыв графика.\n2. Потеря доверия.\n3. Увеличение затрат.", []],
    ['Рассмотрим три важных причины, влияющих на цену контракта.', []],
    ["1. Обсудить цену контракта.\n2. Обсудить срок поставки.", []],
];
$checks=0;$fails=0;
foreach ($cases as [$text,$expected]) {
    $actual=$method->invoke(null,['target_message'=>['content'=>$text],'items'=>$items]);
    ksort($actual);ksort($expected);$checks++;
    if($actual!==$expected){$fails++;fwrite(STDERR,'FAIL: '.$text.' expected='.json_encode($expected,JSON_UNESCAPED_UNICODE).' actual='.json_encode($actual,JSON_UNESCAPED_UNICODE)."\n");}
}
echo sprintf("NEG-NUMERIC-GROUNDING-571: %d/%d PASS, %d FAIL\n",$checks-$fails,$checks,$fails);
exit($fails?1:0);

<?php
// Numeric fixtures include the production item type; list guards coexist with word numbers.
require_once __DIR__ . '/support/plugin-release.php';
$root = dirname(__DIR__);
define('ABSPATH', '/tmp/');
require_once $root.'/modules/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\ArbiterService;
$checks = 0;
function n369(bool $ok, string $label): void { global $checks; if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); } $checks++; }
$invoke = static function(string $method, ...$args) {
    $r = new ReflectionMethod(ArbiterService::class, $method);
    $r->setAccessible(true);
    return $r->invoke(null, ...$args);
};
$list = "Вот три основных последствия задержки поставки для проекта:\n\n1. **Срыв сроков выполнения**:\n - Задержка поставки может привести к несоблюдению графика.\n\n2. **Увеличение затрат**:\n - Потребность в экстренных мерах увеличит бюджет.\n\n3. **Потеря доверия клиентов**:\n - Задержка может негативно сказаться на репутации.";
$items = [
    ['code'=>'price','value_type'=>'integer','title'=>'Цена контракта','unit'=>'RUB','state_status'=>'not_discussed','current_value'=>null],
    ['code'=>'delivery_days','value_type'=>'integer','title'=>'Срок поставки','unit'=>'дней','state_status'=>'not_discussed','current_value'=>null],
];
$ctx = ['target_message'=>['id'=>34,'actor'=>'opponent','content'=>$list],'items'=>$items,'recent_dialogue'=>[]];
$mentions = $invoke('numericMentions', $list);
n369(count($mentions) === 1 && substr($list, $mentions[0]['pos'], $mentions[0]['end'] - $mentions[0]['pos']) === 'три', 'ordered list markers are excluded while Russian number words remain recognized');
n369($invoke('numericMentions', "1. Первый пункт\n2. Второй пункт\n3. Третий пункт") === [], 'bare ordered list markers cannot become numeric values');
n369($invoke('explicitDealValues', $ctx) === [], 'ordered list has no explicit deal values');
$analysis = ['deal_updates'=>[['item_code'=>'delivery_days','action'=>'proposed','value'=>1,'proposed_by'=>'opponent','bundle_key'=>'','confidence'=>0.95]]];
$filtered = $invoke('filterUngroundedDealUpdates', $analysis, $ctx);
n369(($filtered['deal_updates'] ?? []) === [], 'model proposal sourced only from list number is rejected');
$normal = 'Предлагаю срок поставки 40 дней и цену контракта 960 000 рублей.';
$normalCtx = ['target_message'=>['id'=>35,'actor'=>'player','content'=>$normal],'items'=>$items,'recent_dialogue'=>[]];
$values = $invoke('explicitDealValues', $normalCtx);
n369(($values['delivery_days'] ?? null) === 40, 'grounded delivery value preserved');
n369(($values['price'] ?? null) === 960000, 'grounded price value preserved');
$date = 'Встреча 02.10.2026. Срок поставки пока не обсуждаем.';
$dateCtx = ['target_message'=>['id'=>36,'actor'=>'player','content'=>$date],'items'=>$items,'recent_dialogue'=>[]];
n369($invoke('explicitDealValues', $dateCtx) === [], 'calendar date is not a deal value');
$percent = 'Риск вырос на 10%, срок поставки пока не называю.';
$percentCtx = ['target_message'=>['id'=>37,'actor'=>'player','content'=>$percent],'items'=>$items,'recent_dialogue'=>[]];
n369($invoke('explicitDealValues', $percentCtx) === [], 'percent is not converted into days');
$svc = file_get_contents($root.'/modules/negotiation-master/ai/arbiter/arbiter-service.php');
$plugin = file_get_contents($root.'/ckm-quiz-pro.php');
n369(str_contains($svc, "neg-arbiter-1.7"), 'arbiter version bumped');
n369(str_contains($svc, 'PREG_OFFSET_CAPTURE') && str_contains($svc, '$prefix = substr($text, 0, $pos);') && str_contains($svc, '$tail = substr($text, $pos);'), 'hard list-marker guard present');
n369(ckm_test_current_plugin_release($plugin), 'plugin version bumped');
echo "PASS $checks/$checks\n";

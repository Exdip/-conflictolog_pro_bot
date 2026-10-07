<?php
define('ABSPATH', __DIR__.'/');
require dirname(__DIR__).'/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
require dirname(__DIR__).'/modules/negotiation-master/ai/opponent/opponent-response-validator.php';
use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\OpponentResponseValidator;
$checks = 0;
function check510(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($label); }
    $checks++;
}
$ctx = ['mechanics'=>['training_domain'=>'sales'], 'identity'=>['name'=>'Елена Соколова'],
    'hidden_facts'=>[['title'=>'Ручной контроль', 'content'=>'Елена успевает вручную прослушивать только 5–7 звонков в неделю.',
        'reveal_rules'=>['partial'=>'Вопрос о контроле качества разговоров', 'revealed'=>'Уточнение, сколько звонков реально проверяется вручную']]]];
$fallback = new SalesClientFallback();
$reply = $fallback->buildFactReply($ctx, 'Сколько звонков вы успеваете прослушивать вручную за неделю?');
check510($reply === 'Я успеваю вручную прослушивать только 5–7 звонков в неделю.', 'First-person agreement and facts preserved');
$method = new ReflectionMethod(SalesClientFallback::class, 'firstPersonFact');
foreach ([
    ['Елена Соколова не может проверить всё.', 'Я не могу проверить всё.'],
    ['Елена хочет сначала увидеть результаты.', 'Я хочу сначала увидеть результаты.'],
    ['Елена готова обсудить результаты.', 'Я готова обсудить результаты.'],
    ['Елена предпочитает другой формат.', ''],
    ['Директор поручил это Елена.', ''],
] as [$fact, $expected]) {
    check510($method->invoke(null, ['content'=>$fact], $ctx) === $expected, $fact);
}
$audit = 'А может, проверим, всё ли у вас нормально? То есть проведём бесплатный аудит?';
foreach ([$audit, 'Предлагаю провести аудит отдела продаж.', 'Давайте бесплатно разберём несколько звонков.'] as $offer) {
    $reply = $fallback->build($ctx, $offer);
    check510(str_contains($reply, 'Что именно') && !str_contains($reply, '5–7'), 'Audit asks scope without fact dump');
    check510($fallback->buildFactReply($ctx, $offer) === '', 'Audit does not trigger discovery reply');
    check510((new OpponentResponseValidator())->validate($reply, $ctx) === $reply, 'Audit reply passes role validation');
}
foreach (['Вы уже проводили аудит?', 'Не предлагаю проводить аудит.', 'Мы не будем проводить бесплатный аудит.'] as $message) {
    check510(!str_contains($fallback->buildProgressReply($ctx, $message), 'в рамках аудита'), 'Past audit/refusal is not a new offer');
}
check510($fallback->buildProgressReply(['mechanics'=>['training_domain'=>'negotiation']], $audit) === '', 'Other domains unchanged');
echo "PASS: $checks sales reply checks\n";

<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');

require dirname(__DIR__) . '/ai/arbiter/arbiter-service.php';
require dirname(__DIR__) . '/ai/opponent/opponent-response-validator.php';

use CKM\NegotiationMaster\ArbiterService;
use CKM\NegotiationMaster\OpponentResponseValidator;

$passed = 0;
function live473(string $name, bool $ok): void {
    global $passed;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $name); }
    $passed++;
    echo "PASS: {$name}\n";
}

$items = [
    ['code'=>'task_scope','title'=>'Объём новой ответственности','value_type'=>'select','unit'=>'',
        'config'=>['labels'=>['none'=>'Не берёт новую задачу','limited'=>'Берёт ограниченный блок','full'=>'Берёт полный блок']]],
    ['code'=>'current_tasks_removed','title'=>'Снятие текущих задач','value_type'=>'integer','unit'=>'tasks','config'=>[]],
    ['code'=>'duration_weeks','title'=>'Срок дополнительной ответственности','value_type'=>'integer','unit'=>'weeks','config'=>[]],
    ['code'=>'decision_autonomy','title'=>'Самостоятельность в новой задаче','value_type'=>'select','unit'=>'',
        'config'=>['labels'=>['current'=>'Текущая','expanded'=>'Расширенная','full'=>'Полная в рамках блока']]],
    ['code'=>'support_resource','title'=>'Поддержка для новой задачи','value_type'=>'select','unit'=>'',
        'config'=>['labels'=>['none'=>'Без дополнительной поддержки','peer'=>'Помощь коллеги','assistant'=>'Выделенный помощник']]],
    ['code'=>'review_after_weeks','title'=>'Пересмотр договорённости','value_type'=>'integer','unit'=>'weeks','config'=>[]],
];

$message = 'Антон, хочу понять, что именно мешает вам взять новый блок. Предлагаю снять с вас две текущие задачи, передать вам ограниченный блок на 6 недель, дать расширенную самостоятельность и помощь коллеги, а через 3 недели отдельно пересмотреть договорённость. Готовы на таких условиях?';

$context = [
    'target_message'=>['id'=>999,'actor'=>'player','content'=>$message],
    'items'=>$items,
];

$method = new ReflectionMethod(ArbiterService::class, 'explicitDealValues');
$values = $method->invoke(null, $context);

live473('word-number tasks parsed', ($values['current_tasks_removed'] ?? null) === 2);
live473('duration 6 weeks parsed', ($values['duration_weeks'] ?? null) === 6);
live473('review 3 weeks parsed', ($values['review_after_weeks'] ?? null) === 3);
live473('limited scope parsed', ($values['task_scope'] ?? null) === 'limited');
live473('expanded autonomy parsed', ($values['decision_autonomy'] ?? null) === 'expanded');
live473('peer support parsed', ($values['support_resource'] ?? null) === 'peer');
live473('exact six-item package recovered', count($values) === 6);

$validator = new OpponentResponseValidator();
$opponentContext = [
    'identity'=>['name'=>'Антон Смирнов','role'=>'Ведущий специалист'],
    'constraints'=>[],
    'concession_space'=>[],
];

$bad = 'Похоже, вы предлагаете Антону возможность взять новый блок. Возможно, стоит уточнить несколько моментов. Попробуйте понять причины затруднений и убедитесь, что всё ясно. Такой подход поможет улучшить коммуникацию.';
$rejected = false;
try { $validator->validate($bad, $opponentContext); }
catch (UnexpectedValueException) { $rejected = true; }
live473('coach-style opponent reply rejected', $rejected);

$good = 'Да, предложение выглядит разумно. Снятие двух текущих задач поможет мне сосредоточиться на новом блоке. Готов обсудить детали.';
live473('first-person opponent reply accepted', $validator->validate($good, $opponentContext) === $good);


$liveBad = 'Антон, я понимаю, что у вас может быть много задач, и это может вызывать стресс. Предложенные вами условия звучат разумно. Снятие двух текущих задач и ограниченный блок на 6 недель с расширенной самостоятельностью и поддержкой коллеги могут помочь вам сосредоточиться на приоритетах. Давайте обсудим детали и убедимся, что все стороны понимают свои обязанности. Как вы к этому относитесь?';
$liveRejected = false;
try { $validator->validate($liveBad, $opponentContext); }
catch (UnexpectedValueException) { $liveRejected = true; }
live473('live .473 self-addressing reply rejected', $liveRejected);

$selfNameBad = 'Антон: согласен обсудить этот вариант.';
$selfNameRejected = false;
try { $validator->validate($selfNameBad, $opponentContext); }
catch (UnexpectedValueException) { $selfNameRejected = true; }
live473('self-name prefix rejected', $selfNameRejected);

$roleGood = 'Я готов взять ограниченный блок на 6 недель, если вы действительно снимете с меня две текущие задачи и дадите поддержку коллеги.';
live473('first-person role reply accepted', $validator->validate($roleGood, $opponentContext) === $roleGood);


$live479Bad = 'Ваше предложение выглядит разумно и конструктивно. Оно учитывает потребности Антона и предлагает поддержку, что может помочь ему справиться с текущими задачами. Возможно, стоит уточнить, какие именно задачи вы хотите снять, чтобы он понимал, на что может рассчитывать. Также полезно обсудить, как будет организована помощь со стороны коллеги. Если он согласен, можно установить четкие критерии для пересмотра договорённости через 3 недели.';
$live479Rejected = false;
try { $validator->validate($live479Bad, $opponentContext); }
catch (UnexpectedValueException) { $live479Rejected = true; }
live473('live .479 third-person coach reply rejected', $live479Rejected);

$inflectedNameBad = 'Условия для Антона выглядят приемлемо, но стоит уточнить детали.';
$inflectedRejected = false;
try { $validator->validate($inflectedNameBad, $opponentContext); }
catch (UnexpectedValueException) { $inflectedRejected = true; }
live473('inflected own first name rejected', $inflectedRejected);

$coachWithoutName = 'Предложение выглядит разумно. Полезно обсудить, какие задачи стоит снять, а потом можно установить критерии пересмотра.';
$coachWithoutNameRejected = false;
try { $validator->validate($coachWithoutName, $opponentContext); }
catch (UnexpectedValueException) { $coachWithoutNameRejected = true; }
live473('coach advice without own name rejected', $coachWithoutNameRejected);

$cleanRole = 'Да, если вы снимете с меня две текущие задачи и дадите помощь коллеги, я готов взять ограниченный блок на 6 недель. Через 3 недели можем пересмотреть договорённость.';
live473('clean first-person role reply still accepted', $validator->validate($cleanRole, $opponentContext) === $cleanRole);

echo "{$passed} live-package-role regression checks passed.\n";

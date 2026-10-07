<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s486(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }

$main = file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s486('current plugin release header and constant', ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/opponent-response-validator.php';
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';
require_once $root.'/negotiation-master/ai/arbiter/arbiter-service.php';

use CKM\NegotiationMaster\OpponentResponseValidator;
use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\ArbiterService;

$facts = [
 ['code'=>'fragmented_process','title'=>'Разрозненный процесс','content'=>'Менеджеры работают одновременно в CRM, таблицах и мессенджерах; руководитель плохо видит, что происходит со сделками.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о текущем процессе работы с лидами и сделками','revealed'=>'Уточнение, где именно передаются заявки, где ведутся данные и что руководитель не видит в текущем процессе']],
 ['code'=>'lost_leads_rate','title'=>'Потерянные лиды','content'=>'Около 8–10% из примерно 600 входящих лидов в месяц не получают своевременного повторного контакта.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о потерях, зависших лидах или качестве повторного контакта','revealed'=>'Уточнение масштаба потерь и количества входящих лидов в месяц']],
 ['code'=>'deal_margin','title'=>'Экономика одной сделки','content'=>'Средняя валовая прибыль от одной дополнительной сделки составляет около 45 000 рублей.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о ценности дополнительной сделки или экономических последствиях потерянных лидов','revealed'=>'Прямое уточнение средней валовой прибыли или экономического эффекта одной сделки']],
 ['code'=>'budget_exists','title'=>'Бюджет существует','content'=>'Проблема не в абсолютном отсутствии денег: компания может потратить 720 000 рублей, если будет понятна экономика решения.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Уточнение, что именно означает «дорого» и с чем клиент сравнивает цену','revealed'=>'Выяснение, что ключевая проблема — непонятная окупаемость, а не отсутствие бюджета']],
 ['code'=>'decision_process','title'=>'Процесс согласования','content'=>'Андрей должен обосновать покупку генеральному директору; без понятного расчёта он не понесёт предложение на согласование.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о том, как принимается решение и кого ещё нужно подключить','revealed'=>'Уточнение роли генерального директора и того, что ему потребуется экономическое обоснование']],
 ['code'=>'previous_crm_failure','title'=>'Неудачный прошлый опыт','content'=>'Генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о прошлом опыте внедрения CRM или рисках перехода','revealed'=>'Прямое выяснение того, что предыдущая CRM не прижилась и это влияет на текущее решение']],
];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>$facts,'external_position'=>['statement'=>'Система выглядит нормально, но 720 тысяч в год — это слишком дорого. За CRM мы столько платить не готовы.'],'constraints'=>[],'concession_space'=>[]];
$fallback=new SalesClientFallback();
$validator=new OpponentResponseValidator();

$budget=$fallback->buildFactReply($ctx,'Что именно заставляет вас считать эту стоимость слишком высокой: ограничение бюджета или пока неясно, какой результат система даст бизнесу?');
s486('direct budget probe gets concrete grounded fact', str_contains($budget,'720 000') && str_contains($budget,'экономика решения'));

$leadBroad=$fallback->buildFactReply($ctx,'Как сейчас у вас ведутся лиды и где чаще всего возникают потери или задержки?');
s486('broad lead probe returns partial grounded reply', str_contains($leadBroad,'лидами') && str_contains($leadBroad,'потери'));
s486('broad lead probe does not leak hidden numbers', !str_contains($leadBroad,'8–10') && !str_contains($leadBroad,'600'));
s486('partial grounded reply passes role validator', $validator->validate($leadBroad,$ctx)===$leadBroad);

$leadConcrete=$fallback->buildFactReply($ctx,'Какой масштаб потерь: сколько лидов в месяц и какой процент не получает повторного контакта?');
s486('concrete lead probe reveals scenario numbers', str_contains($leadConcrete,'8–10%') && str_contains($leadConcrete,'600'));

$method = new ReflectionMethod(ArbiterService::class, 'deterministicFactUpdates');
$method->setAccessible(true);
$playerUpdates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'player','content'=>'Как сейчас у вас ведутся лиды и где чаще всего возникают потери или задержки?'],'hidden_facts'=>$facts]);
s486('broad player lead probe stays partial', count($playerUpdates)===1 && ($playerUpdates[0]['fact_code']??'')==='lost_leads_rate' && ($playerUpdates[0]['suggested_level']??'')==='partial');

foreach ($facts as &$f) { if ($f['code']==='lost_leads_rate') { $f['current_reveal_level']=1; } }
unset($f);
$partialOpponent=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'opponent','content'=>$leadBroad],'hidden_facts'=>$facts]);
s486('partial client line does not promote lead fact to revealed', count($partialOpponent)===0);
$fullOpponent=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'opponent','content'=>$leadConcrete],'hidden_facts'=>$facts]);
s486('concrete client line promotes lead fact to revealed', count($fullOpponent)===1 && ($fullOpponent[0]['fact_code']??'')==='lost_leads_rate' && ($fullOpponent[0]['suggested_level']??'')==='revealed');

$service=file_get_contents($root.'/negotiation-master/ai/opponent/opponent-service.php');
$factPos=strpos($service,'buildFactReply');
$requestPos=strpos($service,'$raw = $this->request', $factPos===false?0:$factPos);
s486('grounded fact reply is attempted before model request', $factPos!==false && $requestPos!==false && $factPos<$requestPos);
s486('service documents no invented operational details', str_contains($service,'cannot invent operational details'));

$arb=file_get_contents($root.'/negotiation-master/ai/arbiter/arbiter-service.php');
s486('sales reveal requires concrete evidence', str_contains($arb,'topic match is not enough for full disclosure') && str_contains($arb,'salesNumericEvidenceMatches'));

echo "{$passed} SALES-486 regression checks passed. No database required.\n";

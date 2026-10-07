<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s485(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }

$main = file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s485('current plugin release header and constant', ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/opponent-response-validator.php';
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';

use CKM\NegotiationMaster\OpponentResponseValidator;
use CKM\NegotiationMaster\SalesClientFallback;

$facts = [
 ['code'=>'fragmented_process','title'=>'Разрозненный процесс','content'=>'Менеджеры работают одновременно в CRM, таблицах и мессенджерах; руководитель плохо видит, что происходит со сделками.','reveal_rules'=>['partial'=>'Вопрос о текущем процессе работы с лидами и сделками']],
 ['code'=>'lost_leads_rate','title'=>'Потерянные лиды','content'=>'Около 8–10% из примерно 600 входящих лидов в месяц не получают своевременного повторного контакта.','reveal_rules'=>['partial'=>'Вопрос о потерях, зависших лидах или качестве повторного контакта']],
 ['code'=>'deal_margin','title'=>'Экономика одной сделки','content'=>'Средняя валовая прибыль от одной дополнительной сделки составляет около 45 000 рублей.','reveal_rules'=>['partial'=>'Вопрос о ценности дополнительной сделки или экономических последствиях потерянных лидов']],
 ['code'=>'budget_exists','title'=>'Бюджет существует','content'=>'Проблема не в абсолютном отсутствии денег: компания может потратить 720 000 рублей, если будет понятна экономика решения.','reveal_rules'=>['partial'=>'Уточнение, что именно означает «дорого» и с чем клиент сравнивает цену']],
 ['code'=>'decision_process','title'=>'Процесс согласования','content'=>'Андрей должен обосновать покупку генеральному директору; без понятного расчёта он не понесёт предложение на согласование.','reveal_rules'=>['partial'=>'Вопрос о том, как принимается решение и кого ещё нужно подключить']],
 ['code'=>'previous_crm_failure','title'=>'Неудачный прошлый опыт','content'=>'Генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.','reveal_rules'=>['partial'=>'Вопрос о прошлом опыте внедрения CRM или рисках перехода']],
];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>$facts,'external_position'=>['statement'=>'Система выглядит нормально, но 720 тысяч в год — это слишком дорого. За CRM мы столько платить не готовы.'],'constraints'=>[],'concession_space'=>[]];
$fallback=new SalesClientFallback();
$validator=new OpponentResponseValidator();
$reply=$fallback->build($ctx,'Что именно заставляет вас считать стоимость слишком высокой: ограничение бюджета или пока неясно, какой результат система даст бизнесу?');
s485('price probe fallback stays first-person', str_contains($reply,'У нас проблема не в') && str_contains($reply,'мы можем потратить 720 000'));
s485('price probe fallback contains only budget economics', !str_contains($reply,'генеральн') && !str_contains($reply,'мессендж') && !str_contains($reply,'45 000'));
s485('price probe fallback passes role validator', $validator->validate($reply,$ctx)===$reply);
$decision=$fallback->build($ctx,'Кто кроме вас принимает окончательное решение о покупке?');
s485('decision probe selects decision fact', str_contains($decision,'генеральному директору'));
s485('decision fallback speaks as client', str_starts_with($decision,'Я '));
$service=file_get_contents($root.'/negotiation-master/ai/opponent/opponent-service.php');
s485('sales service has deterministic role fallback', str_contains($service,'SalesClientFallback') && str_contains($service,'must never dead-end'));
$bootstrap=file_get_contents($root.'/negotiation-master/bootstrap.php');
s485('fallback class is loaded before opponent service', strpos($bootstrap,'sales-client-fallback.php') < strpos($bootstrap,'opponent-service.php'));
echo "{$passed} SALES-485 regression checks passed. No database required.\n";

<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s488(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s488('current plugin release header and constant',ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/opponent-response-validator.php';
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';
require_once $root.'/negotiation-master/ai/arbiter/arbiter-service.php';
require_once $root.'/negotiation-master/evaluation/evaluation-service.php';
use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\ArbiterService;
use CKM\NegotiationMaster\EvaluationService;
$facts=[
 ['code'=>'fragmented_process','title'=>'Разрозненный процесс','content'=>'Менеджеры работают одновременно в CRM, таблицах и мессенджерах; руководитель плохо видит, что происходит со сделками.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о текущем процессе работы с лидами и сделками','revealed'=>'Уточнение, где именно передаются заявки, где ведутся данные и что руководитель не видит в текущем процессе']],
 ['code'=>'lost_leads_rate','title'=>'Потерянные лиды','content'=>'Около 8–10% из примерно 600 входящих лидов в месяц не получают своевременного повторного контакта.','current_reveal_level'=>2,'reveal_rules'=>['partial'=>'Вопрос о потерях, зависших лидах или качестве повторного контакта','revealed'=>'Уточнение масштаба потерь и количества входящих лидов в месяц']],
 ['code'=>'deal_margin','title'=>'Экономика одной сделки','content'=>'Средняя валовая прибыль от одной дополнительной сделки составляет около 45 000 рублей.','current_reveal_level'=>2,'reveal_rules'=>['partial'=>'Вопрос о ценности дополнительной сделки или экономических последствиях потерянных лидов','revealed'=>'Прямое уточнение средней валовой прибыли или экономического эффекта одной сделки']],
 ['code'=>'budget_exists','title'=>'Бюджет существует','content'=>'Проблема не в абсолютном отсутствии денег: компания может потратить 720 000 рублей, если будет понятна экономика решения.','current_reveal_level'=>2,'reveal_rules'=>['partial'=>'Уточнение, что именно означает «дорого» и с чем клиент сравнивает цену','revealed'=>'Выяснение, что ключевая проблема — непонятная окупаемость, а не отсутствие бюджета']],
 ['code'=>'decision_process','title'=>'Процесс согласования','content'=>'Андрей должен обосновать покупку генеральному директору; без понятного расчёта он не понесёт предложение на согласование.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о том, как принимается решение и кого ещё нужно подключить','revealed'=>'Уточнение роли генерального директора и того, что ему потребуется экономическое обоснование']],
 ['code'=>'previous_crm_failure','title'=>'Неудачный прошлый опыт','content'=>'Генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о прошлом опыте внедрения CRM или рисках перехода','revealed'=>'Прямое выяснение того, что предыдущая CRM не прижилась и это влияет на текущее решение']],
];
$method=new ReflectionMethod(ArbiterService::class,'deterministicFactUpdates');$method->setAccessible(true);
$playerUpdates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'player','content'=>'Кто должен окончательно утвердить покупку этой CRM и что нужно показать для согласования?'],'hidden_facts'=>$facts]);
$playerCodes=array_values(array_map(fn($x)=>$x['fact_code']??'',(array)$playerUpdates));
s488('decision probe gates out past CRM fact',in_array('decision_process',$playerCodes,true)&&!in_array('previous_crm_failure',$playerCodes,true));
$factsAfter=$facts;
foreach($factsAfter as &$f){if(($f['code']??'')==='decision_process')$f['current_reveal_level']=1;}unset($f);
$oppUpdates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'opponent','content'=>'Я должен обосновать покупку генеральному директору; без понятного расчёта он не понесёт предложение на согласование.'],'hidden_facts'=>$factsAfter]);
$oppCodes=array_values(array_map(fn($x)=>$x['fact_code']??'',(array)$oppUpdates));
s488('decision answer cannot reveal past CRM fact through CEO wording',in_array('decision_process',$oppCodes,true)&&!in_array('previous_crm_failure',$oppCodes,true));

$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>$facts,'external_position'=>['statement'=>'Слишком дорого.']];
$fallback=new SalesClientFallback();
$progressCtx=$ctx;
$progressCtx['validated_state']['discovered_facts']=array_map(fn($fact)=>['code'=>$fact['code'],'reveal_level'=>2],$facts);
$nextText='Если такой подход вам подходит, предлагаю следующий шаг: я подготовлю расчёт окупаемости, а мы назначим встречу с вами и генеральным директором, чтобы обсудить риски внедрения. Готовы зафиксировать встречу?';
s488('next-step reaction outranks fact discovery even with CEO and risk cues',str_contains($fallback->build($progressCtx,$nextText),'мне такой следующий шаг подходит'));
$valueText='При прибыли 45 000 рублей годовая стоимость 720 000 рублей окупается примерно 16 дополнительными сделками. Этот расчёт показывает эффект для вашей компании.';
s488('value reaction outranks money fact replay',str_contains($fallback->build($progressCtx,$valueText),'расчёт уже выглядит предметно'));
$service=file_get_contents($root.'/negotiation-master/ai/opponent/opponent-service.php');
s488('progress path runs before fact path',strpos($service,'$progressReply =')<strpos($service,'$factReply ='));

$eval=file_get_contents($root.'/negotiation-master/evaluation/evaluation-service.php');
s488('evaluation version bumped to 2.4',ckm_test_declared_version_at_least($eval,'VERSION','neg-eval-2.5'));
s488('result type requires confirmed next step',str_contains($eval,"!empty(\$nextOutcome['confirmed'])"));
s488('AI next-step score has confirmation cap',str_contains($eval,"\$raw=min(\$raw,!empty(\$salesNextOutcome['offered'])?58.0:35.0)"));

$evalMethod=new ReflectionMethod(EvaluationService::class,'genericAiFallback');$evalMethod->setAccessible(true);
$base=[
 'mechanics'=>['training_domain'=>'sales'],
 'facts'=>array_map(fn($f)=>['reveal_level'=>2],$facts),
 'events'=>[],'commitments'=>[],'session'=>['status'=>'completed_sales']
];
$unconfirmed=$base;
$unconfirmed['messages']=[
 ['id'=>1,'actor'=>'player','content'=>'Что стоит за возражением по цене?'],
 ['id'=>2,'actor'=>'opponent','content'=>'Нужна понятная экономика.'],
 ['id'=>9,'actor'=>'player','content'=>'При прибыли 45 000 рублей стоимость 720 000 окупается примерно 16 дополнительными сделками.'],
 ['id'=>10,'actor'=>'opponent','content'=>'Расчёт понятен.'],
 ['id'=>11,'actor'=>'player','content'=>'Предлагаю следующий шаг: подготовлю расчёт и назначим встречу с вами и генеральным директором. Готовы зафиксировать встречу?'],
 ['id'=>12,'actor'=>'opponent','content'=>'Сначала нужно всё проверить.'],
];
$unconfirmedScore=$evalMethod->invoke(null,['code'=>'next_step'],$unconfirmed);
s488('unconfirmed offer cannot score as advanced next step',($unconfirmedScore['raw_score']??100)<=58);
$confirmed=$unconfirmed;
$confirmed['messages'][5]=['id'=>12,'actor'=>'opponent','content'=>'Да, такой следующий шаг мне подходит. Подготовьте расчёт, и назначим встречу.'];
$confirmedScore=$evalMethod->invoke(null,['code'=>'next_step'],$confirmed);
s488('explicit client acceptance earns high next-step score',($confirmedScore['raw_score']??0)>=90&&in_array(12,$confirmedScore['evidence_message_ids']??[],true));
$resultMethod=new ReflectionMethod(EvaluationService::class,'resultType');$resultMethod->setAccessible(true);
$svc=(new ReflectionClass(EvaluationService::class))->newInstanceWithoutConstructor();
$scores=['next_step'=>['raw_score'=>95]];
$unconfirmedType=$resultMethod->invoke($svc,$unconfirmed,$scores,[],false,95.0);
$confirmedType=$resultMethod->invoke($svc,$confirmed,$scores,[],false,95.0);
s488('high total without client acceptance is interest, not advanced',$unconfirmedType==='sales_interest');
s488('high total with explicit acceptance can be advanced',$confirmedType==='sales_advanced');

echo "{$passed} SALES-488 regression checks passed. No database required.\n";

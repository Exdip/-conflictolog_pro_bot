<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s487(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s487('current plugin release header and constant',ckm_test_current_plugin_release($main));
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
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>$facts,'external_position'=>['statement'=>'Слишком дорого.']];
$fallback=new SalesClientFallback();
$process=$fallback->buildFactReply($ctx,'Менеджеры ведут работу в одной CRM или параллельно используют таблицы и мессенджеры? Хватает ли прозрачности по сделкам?');
s487('specific workflow probe maps to process fact',str_contains($process,'CRM, таблицах и мессенджерах'));
$experience=$fallback->buildFactReply($ctx,'По этой CRM: был ли у вас раньше опыт внедрения похожей системы и что тогда пошло не так?');
s487('past-experience probe returns exact stored fact',str_contains($experience,'сотрудники почти не использовали')&&!str_contains($experience,'сложной в использовании'));
$decision=$fallback->buildFactReply($ctx,'Кто должен окончательно утвердить покупку и что ему нужно для решения?');
s487('decision probe stays decision-specific',str_contains($decision,'генеральному директору')&&!str_contains($decision,'сотрудники почти не использовали'));
// Progress fixtures represent discovery already completed; discovery assertions above retain unrevealed facts.
$progressCtx=$ctx;
$progressCtx['validated_state']['discovered_facts']=array_map(fn($fact)=>['code'=>$fact['code'],'reveal_level'=>2],$facts);
$value=$fallback->buildProgressReply($progressCtx,'При прибыли 45 000 рублей годовая стоимость 720 000 окупается примерно 16 дополнительными сделками в год. Этот расчёт показывает эффект для вашей компании.');
s487('value argument gets forward-moving client reaction',str_contains($value,'расчёт уже выглядит предметно'));
$next=$fallback->buildProgressReply($progressCtx,'Предлагаю следующий шаг: подготовлю расчёт, а мы назначим встречу с вами и генеральным директором. Готовы зафиксировать встречу?');
s487('concrete next step gets acceptance',str_contains($next,'мне такой следующий шаг подходит'));
$method=new ReflectionMethod(ArbiterService::class,'deterministicFactUpdates');$method->setAccessible(true);
$updates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'player','content'=>'Кто должен окончательно утвердить покупку и что ему понадобится для решения?'],'hidden_facts'=>$facts]);
$codes=array_values(array_map(fn($x)=>$x['fact_code']??'',(array)$updates));
s487('decision question cannot unlock past-experience fact',in_array('decision_process',$codes,true)&&!in_array('previous_crm_failure',$codes,true));
$eval=file_get_contents($root.'/negotiation-master/evaluation/evaluation-service.php');
s487('evaluation version bumped for full-cycle recalculation',ckm_test_declared_version_at_least($eval,'VERSION','neg-eval-2.5'));
s487('sales fallback scans full transcript',str_contains($eval,'salesPlayerSignals')&&str_contains($eval,'salesEvidenceFor'));
s487('next-step fallback has criterion-specific evidence',str_contains($eval,"'next_step'=>array_values")&&str_contains($eval,'salesNextStepOutcome'));

$evalMethod=new ReflectionMethod(EvaluationService::class,'genericAiFallback');$evalMethod->setAccessible(true);
$evalContext=[
 'mechanics'=>['training_domain'=>'sales'],
 'facts'=>array_map(fn($f)=>['reveal_level'=>2],$facts),
 'messages'=>[
  ['id'=>1,'actor'=>'player','content'=>'Что стоит за возражением по цене?'],
  ['id'=>2,'actor'=>'opponent','content'=>'Нужна понятная экономика.'],
  ['id'=>9,'actor'=>'player','content'=>'При прибыли 45 000 рублей стоимость 720 000 окупается примерно 16 дополнительными сделками в год.'],
  ['id'=>10,'actor'=>'opponent','content'=>'Такой расчёт можно обсуждать.'],
  ['id'=>11,'actor'=>'player','content'=>'Предлагаю следующий шаг: подготовлю расчёт на наших данных и назначим встречу с вами и генеральным директором. Готовы зафиксировать встречу?'],
  ['id'=>12,'actor'=>'opponent','content'=>'Да, такой следующий шаг мне подходит. Подготовьте расчёт, и назначим встречу.'],
 ],'events'=>[],'commitments'=>[],'session'=>['status'=>'completed_sales']
];
$nextScore=$evalMethod->invoke(null,['code'=>'next_step'],$evalContext);
s487('full-transcript fallback recognizes confirmed late next step',($nextScore['raw_score']??0)>=80 && in_array(11,$nextScore['evidence_message_ids']??[],true) && in_array(12,$nextScore['evidence_message_ids']??[],true));
$valueScore=$evalMethod->invoke(null,['code'=>'value_proposition'],$evalContext);
s487('full-transcript fallback recognizes late ROI argument',($valueScore['raw_score']??0)>=80 && in_array(9,$valueScore['evidence_message_ids']??[],true));

$service=file_get_contents($root.'/negotiation-master/ai/opponent/opponent-service.php');
s487('progress reply is attempted before language model',strpos($service,'buildProgressReply')<strpos($service,'$raw = $this->request'));
echo "{$passed} SALES-487 regression checks passed. No database required.\n";

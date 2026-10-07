<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');
if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($s){return strip_tags((string)$s);}} 
if(!function_exists('sanitize_key')){function sanitize_key($s){$s=strtolower((string)$s);return preg_replace('/[^a-z0-9_\-]/','',$s);}} 
if(!function_exists('wp_json_encode')){function wp_json_encode($v,$f=0){return json_encode($v,$f);}} 
require_once dirname(__DIR__).'/application/sales-script-service.php';
require_once dirname(__DIR__).'/application/sales-script-client-service.php';
require_once dirname(__DIR__,2).'/negotiation-master/ai/opponent/sales-client-fallback.php';
use CKM\EffectiveSales\SalesScriptClientService;
use CKM\NegotiationMaster\SalesClientFallback;
$checks=[];function s507(&$c,$n,$ok){$c[]=[$n,(bool)$ok];}
$main=file_get_contents(dirname(__DIR__,3).'/ckm-quiz-pro.php');
s507($checks,'plugin version',ckm_test_current_plugin_release($main));
$script=['id'=>'script-test-507','title'=>'CRM для отдела продаж','product'=>'CRM для B2B-отдела продаж, 720 000 рублей в год','client'=>'Коммерческий директор компании с 25 менеджерами','situation_class'=>'Цена','goal'=>'Выяснить причину возражения по цене, показать экономику и договориться о встрече с руководителем','constraints'=>'Не давать скидку сразу; не давить; не обещать автоматический рост продаж'];
$fallback=SalesScriptClientService::fallbackBlueprint($script);
s507($checks,'fallback has client',($fallback['client_name']??'')!==''&&($fallback['opening_message']??'')!=='');
s507($checks,'fallback has 4-7 hidden facts',count($fallback['hidden_facts']??[])>=4&&count($fallback['hidden_facts']??[])<=7);
$ai=[
 'client_name'=>'Игорь Лебедев','client_role'=>'Коммерческий директор клиента','persona'=>['style'=>'Рациональный и осторожный','traits'=>['просит расчёты','не любит давление']],
 'opening_message'=>'720 тысяч в год выглядят слишком дорого. Покажите, за счёт чего это окупится.',
 'hidden_facts'=>[
  ['code'=>'roi_gap','title'=>'Непонятная окупаемость','content'=>'Компания готова рассматривать бюджет, если увидит окупаемость в пределах года.','importance'=>1.6,'reveal_partial'=>'Вопрос о причине ценового сомнения','reveal_full'=>'Прямой вопрос об окупаемости и допустимом сроке'],
  ['code'=>'lead_loss','title'=>'Потери лидов','content'=>'Около 40 лидов в месяц теряются из-за несвоевременного повторного контакта.','importance'=>1.5,'reveal_partial'=>'Вопрос о потерях лидов','reveal_full'=>'Уточнение количества потерянных лидов в месяц'],
  ['code'=>'decision','title'=>'Согласование','content'=>'Финальное решение принимает генеральный директор после короткого экономического расчёта.','importance'=>1.4,'reveal_partial'=>'Вопрос об участниках решения','reveal_full'=>'Прямой вопрос о том, кто утверждает покупку'],
  ['code'=>'adoption_risk','title'=>'Риск внедрения','content'=>'Ранее сотрудники плохо использовали новую систему, поэтому клиент опасается повторения.','importance'=>1.5,'reveal_partial'=>'Вопрос о рисках внедрения','reveal_full'=>'Уточнение прошлого неудачного опыта'],
 ],
 'priorities'=>['понять окупаемость','снизить риск внедрения'],'constraints'=>['не подписывать договор до расчёта'],'alternative'=>'Оставить текущую систему','walkaway'=>'Уйти при давлении или неподтверждённых обещаниях',
 'value_ack'=>'Если расчёт основан на наших данных, это уже можно обсуждать.','next_step_accept'=>'Да, подготовьте расчёт и назначим встречу с генеральным директором.','pressure_reject'=>'Под давлением подписывать не буду.','fit_accept'=>'Если решение не подходит, лучше скажите прямо.'
];
$svc=new SalesScriptClientService(fn()=>json_encode($ai,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$bp=$svc->blueprint($script);
s507($checks,'ai blueprint used',($bp['generation_source']??'')==='ai'&&($bp['client_name']??'')==='Игорь Лебедев');
s507($checks,'ai hidden facts preserved',count($bp['hidden_facts']??[])===4&&($bp['hidden_facts'][0]['code']??'')==='roi_gap');
$payload=SalesScriptClientService::scenarioPayload($script,$bp);$mech=$payload['version']['mechanics_json']??[];
s507($checks,'custom scenario sales domain',($mech['training_domain']??'')==='sales'&&!empty($mech['script_generated'])&&($mech['script_id']??'')==='script-test-507');
s507($checks,'script data carried into scenario',str_contains((string)$payload['version']['player_situation'],'720 000')&&str_contains((string)$payload['version']['player_task'],'встрече'));
s507($checks,'generic evaluation totals 100',array_sum(array_column($payload['evaluation_rules'],'weight'))===100);
s507($checks,'custom facts become scenario facts',count($payload['hidden_facts'])===4&&($payload['hidden_facts'][1]['code']??'')==='lead_loss');
// Mini test run of the generated client contract: discovery -> value -> next step.
$ctx=['mechanics'=>$mech,'identity'=>['name'=>$bp['client_name']],'hidden_facts'=>$bp['hidden_facts'],'external_position'=>['statement'=>$bp['opening_message']]];
$f=new SalesClientFallback();
$r1=$f->buildFactReply($ctx,'Что именно делает цену слишком высокой и какой срок окупаемости вы считаете приемлемым?');
$r2=$f->buildFactReply($ctx,'Сколько лидов в месяц вы теряете из-за несвоевременного повторного контакта?');
$r3=$f->buildProgressReply($ctx,'Если вернуть хотя бы часть этих лидов, эффект можно посчитать на ваших данных и сопоставить с 720 000 рублей в год.');
$r4=$f->buildProgressReply($ctx,'Предлагаю следующий шаг: я подготовлю расчёт на ваших данных и после этого проведём встречу с вами и генеральным директором. Готовы зафиксировать встречу?');
s507($checks,'test run discovery 1',$r1!==''&&str_contains($r1,'окупаем'));s507($checks,'test run discovery 2',$r2!==''&&str_contains($r2,'40'));s507($checks,'test run value acknowledged',$r3==='Если расчёт основан на наших данных, это уже можно обсуждать.');s507($checks,'test run next step confirmed',$r4==='Мне подходит такой вариант. Да, подготовьте расчёт и назначим встречу с генеральным директором.');
$fail=0;foreach($checks as[$n,$ok]){echo($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$fail++;}exit($fail?1:0);

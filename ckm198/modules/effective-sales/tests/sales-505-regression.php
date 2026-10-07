<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__.'/fixtures/');
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$pack=require $root.'/modules/negotiation-master/content/system-v1.php';
$meta=require $root.'/modules/negotiation-master/content/public-product-meta.php';
$page=file_get_contents(dirname(__DIR__).'/public/sales-page.php');
$domain=file_get_contents(dirname(__DIR__).'/sales-domain.php');
$js=file_get_contents(dirname(__DIR__).'/assets/sales-session.js');
$eval=file_get_contents($root.'/modules/negotiation-master/evaluation/evaluation-service.php');
$passed=0;
function s505(string $name,bool $ok):void{global $passed;if(!$ok)throw new RuntimeException('FAIL: '.$name);$passed++;echo "PASS: {$name}\n";}
s505('plugin version',ckm_test_current_plugin_release($main));
s505('content pack 1.50.0',($pack['pack_version']??'')==='1.50.0');
$sales=array_values(array_filter((array)$pack['scenarios'],fn($e)=>(string)($e['scenario']['library_slug']??'')==='effective-sales'));
s505('12 sales scenarios',count($sales)===12);
$expected=['Первичный контакт','Диагностика','Ценность','Цена','Отсрочка','Конкурент','Сложное решение','Риск и недоверие','Особые условия','Продвижение сделки','Сложный клиент','Экспертная продажа'];
$cats=[];
foreach($sales as $entry){
    $slug=(string)$entry['scenario']['slug'];$m=(array)($entry['version']['mechanics_json']??[]);
    s505($slug.' domain',($m['training_domain']??'')==='sales');
    s505($slug.' mechanics taxonomy',trim((string)($m['situation_class']??''))!==''&&trim((string)($m['deal_stage']??''))!==''&&trim((string)($m['primary_skill']??''))!=='');
    s505($slug.' evaluation count',count((array)$entry['components']['evaluation_rules'])===5);
    s505($slug.' evaluation weight',array_sum(array_column((array)$entry['components']['evaluation_rules'],'weight'))===100);
    s505($slug.' public meta',isset($meta[$slug])&&trim((string)($meta[$slug]['stage']??''))!==''&&trim((string)($meta[$slug]['skill']??''))!=='');
    $cats[]=(string)($meta[$slug]['category']??'');
}
sort($cats);$expectedSorted=$expected;sort($expectedSorted);s505('all 12 classes represented',$cats===$expectedSorted);
s505('taxonomy shown on home',str_contains($page,'12 типов реальных продаж')&&str_contains($page,'classificationOrder'));
s505('taxonomy classes are filter buttons',str_contains($page,'data-sales-class-filter')&&str_contains($page,'data-sales-class'));
s505('taxonomy filter wired in javascript',str_contains($js,"const taxonomy=qs('#ckm-sales-taxonomy')")&&str_contains($js,"card.hidden=!!selected&&card.dataset.salesClass!==selected"));
s505('card exposes stage and skill',str_contains($page,'Главный навык')&&str_contains($page,"['stage']"));
s505('public mechanics expose taxonomy',str_contains($domain,"'situation_class'")&&str_contains($domain,"'primary_skill'")&&str_contains($domain,"'success_mode'"));
s505('anti-sale result type',str_contains($eval,'sales_fit_correct')&&str_contains($js,"sales_fit_correct:'Продажа не нужна — решение верное'"));
s505('evaluation 2.5',ckm_test_declared_version_at_least($eval,'VERSION','neg-eval-2.5'));
require $root.'/modules/negotiation-master/ai/opponent/sales-client-fallback.php';
$fallback=new CKM\NegotiationMaster\SalesClientFallback();
$ctx=['mechanics'=>['training_domain'=>'sales','progress_replies'=>['next_step_accept'=>'Согласен на проверочный следующий шаг.']], 'identity'=>['name'=>'Клиент'], 'hidden_facts'=>[['code'=>'integration_need','title'=>'Интеграция обязательна','content'=>'Новая система должна получать заявки из действующей CRM.','reveal_rules'=>['partial'=>'Вопрос об интеграции или ограничениях внедрения','revealed'=>'Уточнение обязательной интеграции с действующей CRM']]],'external_position'=>['statement'=>'Расскажите о продукте.']];
$reply=$fallback->buildFactReply($ctx,'Как у вас сейчас устроена интеграция с действующей CRM и что обязательно сохранить?');
s505('generic fact fallback handles new sales topics',str_contains($reply,'CRM'));
$progress=$fallback->buildProgressReply($ctx,'Предлагаю следующий шаг: проведём короткое демо интеграции на ваших данных завтра. Готовы?');
s505('scenario progress reply is data driven',$progress==='Мне подходит такой вариант. Согласен на проверочный следующий шаг.');
echo "{$passed} SALES-505 regression checks passed. No database required.\n";

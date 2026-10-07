<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
if (PHP_SAPI !== 'cli') { exit(1); }
define('ABSPATH', __DIR__ . '/fixtures/');
$root = dirname(__DIR__, 2);
$passed = 0;
function s491(string $name, bool $ok): void { global $passed; if (!$ok) { throw new RuntimeException('FAIL: '.$name); } $passed++; echo "PASS: {$name}\n"; }
$main=file_get_contents(dirname($root).'/ckm-quiz-pro.php');
s491('current plugin release header and constant',ckm_test_current_plugin_release($main));
require_once $root.'/negotiation-master/ai/opponent/sales-client-fallback.php';
require_once $root.'/negotiation-master/ai/arbiter/arbiter-service.php';
use CKM\NegotiationMaster\SalesClientFallback;
use CKM\NegotiationMaster\ArbiterService;
$fact=['code'=>'previous_crm_failure','title'=>'Неудачный прошлый опыт','content'=>'Генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.','current_reveal_level'=>0,'reveal_rules'=>['partial'=>'Вопрос о прошлом опыте внедрения CRM или рисках перехода','revealed'=>'Прямое выяснение того, что предыдущая CRM не прижилась и это влияет на текущее решение']];
$ctx=['mechanics'=>['training_domain'=>'sales'],'identity'=>['name'=>'Андрей Морозов'],'hidden_facts'=>[$fact],'external_position'=>['statement'=>'Слишком дорого.']];
$fallback=new SalesClientFallback();
$question='Был ли у вас раньше опыт внедрения CRM, который влияет на нынешнее решение? Что именно тогда произошло?';
$reply=$fallback->buildFactReply($ctx,$question);
s491('explicit what exactly happened question gets concrete stored fact',str_contains($reply,'сотрудники почти не использовали')&&str_contains($reply,'опасается повторить ошибку'));
$method=new ReflectionMethod(ArbiterService::class,'deterministicFactUpdates');$method->setAccessible(true);
$partialFact=$fact;$partialFact['current_reveal_level']=1;
$partial='У нас уже был опыт внедрения CRM, и он влияет на осторожность сейчас. Могу пояснить, что именно пошло не так.';
$partialUpdates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'opponent','content'=>$partial],'hidden_facts'=>[$partialFact]]);
$partialLevels=array_map(fn($x)=>$x['suggested_level']??'',(array)$partialUpdates);
s491('partial client reply cannot promote fact to revealed',!in_array('revealed',$partialLevels,true));
$full='У нас генеральный директор уже однажды купил CRM, которую сотрудники почти не использовали, поэтому опасается повторить ошибку.';
$fullUpdates=$method->invoke(null,['mechanics'=>['training_domain'=>'sales'],'target_message'=>['actor'=>'opponent','content'=>$full],'hidden_facts'=>[$partialFact]]);
$fullLevels=array_map(fn($x)=>$x['suggested_level']??'',(array)$fullUpdates);
s491('concrete client reply promotes fact to revealed',in_array('revealed',$fullLevels,true));
$arbiter=file_get_contents($root.'/negotiation-master/ai/arbiter/arbiter-service.php');
s491('sales reveal gate no longer relaxes to two tokens after partial',!str_contains($arbiter,"(\$current >= 1 && \$coreOverlap >= 2)"));
echo "{$passed} SALES-491 regression checks passed. No database required.\n";

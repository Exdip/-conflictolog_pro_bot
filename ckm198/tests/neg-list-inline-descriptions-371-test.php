<?php
// Historical feature tests follow the current synchronized plugin release.
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
define('ABSPATH','/tmp/');
require_once $root.'/modules/negotiation-master/ai/opponent/opponent-service.php';
use CKM\NegotiationMaster\OpponentService;
$checks=0;
function n371(bool $ok,string $label):void{global $checks;if(!$ok){fwrite(STDERR,"FAIL: $label\n");exit(1);} $checks++;}
$call=static function(string $method,...$args){$r=new ReflectionMethod(OpponentService::class,$method);$r->setAccessible(true);return $r->invoke(null,...$args);};
$input="Конечно! Вот последствия:\n- Увеличение затрат:\n - Задержка может привести к дополнительным расходам на хранение, переработку или даже штрафам за несвоевременное выполнение обязательств.\n- Срыв сроков выполнения:\n - Задержка поставки может привести к сдвигу графика выполнения проекта.";
$out=$call('cleanUserFacingText',$input);
n371(str_contains($out,'- Увеличение затрат (задержка может привести к дополнительным расходам на хранение, переработку или даже штрафам за несвоевременное выполнение обязательств).'),'first nested explanation becomes one-line parenthetical');
n371(str_contains($out,'- Срыв сроков выполнения (задержка поставки может привести к сдвигу графика выполнения проекта).'),'second nested explanation becomes one-line parenthetical');
n371(!str_contains($out,"\n - "),'no nested bullet remains');
$numbered="1. **Увеличение затрат**:\n - Дополнительные расходы.\n2. **Срыв сроков**:\n - Сдвиг графика.";
$numberedOut=$call('cleanUserFacingText',$numbered);
n371(str_contains($numberedOut,'- Увеличение затрат (дополнительные расходы).'),'numbered markdown list is normalized and collapsed');
n371(!str_contains($numberedOut,'**'),'markdown stars removed');
$flat="- Цена: 960 000 руб.\n- Срок: 40 дней.";
n371($call('cleanUserFacingText',$flat)===$flat,'ordinary flat list stays flat');
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$js=file_get_contents($root.'/modules/negotiation-master/assets/negotiation-session.js');
$prompt=file_get_contents($root.'/modules/negotiation-master/ai/opponent/opponent-prompt-builder.php');
n371(ckm_test_current_plugin_release($plugin),'plugin version bumped');
n371(str_contains($js,'function inlineListDescriptions(text)'),'client cleans historical nested lists');
n371(str_contains($prompt,'каждый пункт пиши строго в одну строку')&&str_contains($prompt,'Не создавай вложенные пункты'),'prompt requests one-line list items');
n371(basename($root)==='ckm198','canonical plugin directory preserved');
echo "PASS $checks/10\n";

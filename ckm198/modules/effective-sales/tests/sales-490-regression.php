<?php
require_once dirname(__DIR__, 3) . '/tests/support/plugin-release.php';
$root=dirname(__DIR__,3);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$page=file_get_contents(dirname(__DIR__).'/public/sales-page.php');
$js=file_get_contents(dirname(__DIR__).'/assets/sales-session.js');
$api=file_get_contents(dirname(__DIR__).'/api/sales-controller.php');
$checks=[];
function s490(&$c,$n,$ok){$c[]=$ok;echo ($ok?'PASS':'FAIL')." sales-490 $n\n";}
s490($checks,'plugin version',ckm_test_current_plugin_release($main));
s490($checks,'abandoned attempt renders restart brief',str_contains($page,"==='abandoned'")&&str_contains($page,'Эта попытка была закрыта или заменена новой. Начните тренировку заново.'));
s490($checks,'restart button is explicit',str_contains($page,"\$restart?'Начать заново':'Начать разговор'")&&str_contains($page,'data-restart'));
s490($checks,'start sends restart flag',str_contains($js,"const restart=start.dataset.restart==='1'")&&str_contains($js,'difficulty,restart,client_id'));
s490($checks,'english resume error translated',str_contains($api,"SESSION_NOT_RESUMABLE")&&str_contains($api,'Эту попытку уже нельзя продолжить. Начните новую тренировку.'));
exit(in_array(false,$checks,true)?1:0);

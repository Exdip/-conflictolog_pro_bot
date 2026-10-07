<?php
$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/standalone-game.js');
$checks=[];
function ck129($ok,$name){global $checks;$checks[]=[$ok,$name];}
ck129(str_contains($js,'id="showActionHint"'),'prominent action hint exists');
ck129(str_contains($js,'Сейчас: нажмите «Команда готова»'),'waiting guidance exists');
ck129(str_contains($js,'Сейчас отвечает ваша команда'),'hard-question active guidance exists');
ck129(str_contains($js,"'Сейчас отвечает '+activeName"), 'hard-question observer gets active team');
ck129(str_contains($js,'задайте уточняющий вопрос '),'story question ordinal guidance exists');
ck129(str_contains($js,'Ваши вопросы приняты. Ждём перехода к ответам рассказчика.'),'story question completion guidance exists');
ck129(str_contains($js,'Ваш голос зафиксирован'),'secret vote completion guidance exists');
ck129(str_contains($js,"$('showReady').textContent=s.canReady?'Команда готова':'Готовность подтверждена'"),'ready button has confirmed state');
ck129(!str_contains($js,"Переход подтверждён")&&str_contains($js,"showAction('advance')"),'review transition uses one host action');
ck129(str_contains($js,"$('showNextRound').hidden=true")&&str_contains($js,"Повторная готовность команд не требуется"),'separate round confirmation is removed');
$fail=array_filter($checks,fn($x)=>!$x[0]);
foreach($checks as [$ok,$name]) echo ($ok?'PASS':'FAIL')."\t$name\n";
exit($fail?1:0);

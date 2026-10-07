<?php
$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/standalone-game.js');
$reg=file_get_contents($root.'/core-source/includes/quiz/quiz-format-registry.php');
$checks=[];
function ck128($ok,$name){global $checks;$checks[]=[$ok,$name];}
ck128(str_contains($js,'Игра состоит из 4 раундов'),'waiting screen explains 4 rounds');
ck128(str_contains($js,'Максимум каждого раунда — 70 баллов, всей игры — 280.'),'waiting screen explains 1152 maximum');
ck128(str_contains($js,"'Раунд '+roundNo+' из 4 — '+currentRoundName"),'dynamic round N/4 title exists');
ck128(str_contains($js,'Раунд 1 из 4 — «Удержи цель»'),'round 1 rules numbered');
ck128(str_contains($js,'Раунд 2 из 4 — «Скрытая задача»'),'round 2 rules numbered');
ck128(str_contains($js,'Раунд 3 из 4 — «Неудобный вопрос»'),'round 3 rules numbered');
ck128(str_contains($js,'Раунд 4 из 4 — «Проверь историю»'),'round 4 rules numbered');
ck128(str_contains($reg,'полноценная четырёхраундовая игра «Переговори другого»'),'registry no longer calls it preparatory');
$fail=array_filter($checks,fn($x)=>!$x[0]);
foreach($checks as [$ok,$name]) echo ($ok?'PASS':'FAIL')."	$name
";
exit($fail?1:0);

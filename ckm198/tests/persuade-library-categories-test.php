<?php
$catalog=file_get_contents(__DIR__.'/../includes/games-catalog.php');
$ready=file_get_contents(__DIR__.'/../includes/ready-games-catalog.php');
$checks=[];
function ok247(&$checks,$cond,$msg){$checks[]=$cond; echo ($cond?'OK ':'FAIL ').$msg."\n";}
ok247($checks,str_contains($catalog,'БАЗОВЫЕ ИГРЫ') && str_contains($catalog,'Каталог готовых игр'),'base games area');
ok247($checks,substr_count($ready,"'category'=>'Школьные ситуации'")>=2,'school category contains generic and grade game');
ok247($checks,str_contains($ready,"'category'=>'Для студентов'")&&str_contains($ready,"'category'=>'Для руководителей'")&&str_contains($ready,"'category'=>'Семейные ситуации'"),'other categories');
ok247($checks,str_contains($catalog,'ckm-library-category-nav')&&str_contains($catalog,'foreach($groups as $category=>$items)'),'grouped scalable renderer');
ok247($checks,str_contains($ready,"'title'=>'Двойка, которой не было'")&&str_contains($ready,"'product'=>'persuade_school_grade_v1'"),'grade game remains in library');
ok247($checks,str_contains($ready,'всей игры — 280'),'280 message retained');
exit(in_array(false,$checks,true)?1:0);

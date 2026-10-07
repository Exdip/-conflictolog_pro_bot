<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/includes/ready-games-catalog.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$c=[];function c260(&$c,$ok,$name){$c[]=$ok;echo ($ok?'OK ':'FAIL ').$name."\n";}
c260($c,ckm_test_current_plugin_release($main),'version');
c260($c,str_contains($catalog,'ckm_quiz_pro_ready_game_integrity_check'),'integrity function');
c260($c,str_contains($catalog,'Проверить игру'),'admin check action');
c260($c,str_contains($catalog,'Проверка целостности игры'),'integrity report');
c260($c,str_contains($catalog,"'quiz_exists','Связанный шаблон существует'"),'source quiz exists');
c260($c,str_contains($catalog,"'quiz_published','Шаблон опубликован'"),'source quiz published');
c260($c,str_contains($catalog,"'format','Формат поддерживается текущим движком'"),'runtime format supported');
c260($c,str_contains($catalog,"'content','В текущей редакции есть игровое содержание'"),'current revision content');
c260($c,str_contains($catalog,"'question_text','У активных вопросов заполнен текст'"),'question text integrity');
c260($c,str_contains($catalog,"'round_refs','Ссылки вопросов на раунды целы'"),'round reference integrity');
c260($c,str_contains($catalog,"'persuade_teams','«Переговори другого» настроена на 2 команды'"),'persuade team contract');
c260($c,str_contains($catalog,"'persuade_rounds','В содержании «Переговори другого» есть все 4 раунда'"),'persuade four rounds');
c260($c,str_contains($catalog,'integrity_blocked'),'publish integrity gate');
c260($c,str_contains($catalog,"'integrity_ok'=>!empty(\$integrity['ok'])"),'registry exposes integrity');
c260($c,str_contains($catalog,"'orderable'=>\$status==='publish' && !empty(\$validation['ready']) && !empty(\$integrity['ok'])"),'broken game not orderable');
c260($c,str_contains($catalog,'ckm_quiz_pro_ready_game_integrity_invalidate'),'health cache invalidation');
c260($c,str_contains($catalog,"ckm_quiz_pro_ready_game_integrity_invalidate((int)\$id)"),'template save invalidates health');
$ok=count(array_filter($c));$total=count($c);echo "RESULT $ok/$total\n";exit($ok===$total?0:1);

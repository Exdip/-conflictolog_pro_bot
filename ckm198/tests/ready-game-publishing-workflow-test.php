<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$plugin=file_get_contents($root.'/ckm-quiz-pro.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$access=file_get_contents($root.'/includes/standalone-game-access.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$c=[];function p253(&$c,$ok,$name){$c[]=$ok;echo ($ok?'OK ':'FAIL ').$name."\n";}
p253($c,ckm_test_current_plugin_release($plugin),'version');
p253($c,str_contains($ready,'value="draft"')&&str_contains($ready,'Черновик'),'draft status');
p253($c,str_contains($ready,'value="publish"')&&str_contains($ready,'Опубликована'),'published status');
p253($c,str_contains($ready,'value="private"')&&str_contains($ready,'Скрыта'),'hidden status');
p253($c,str_contains($ready,"\$status=\$post ? (string)\$post->post_status : 'draft';"),'new item defaults draft');
p253($c,str_contains($ready,"'post_status'=>['publish','draft','private']") && str_contains($ready,'ckm_quiz_pro_ready_game_release_snapshot'),'public registry only published');
p253($c,str_contains($ready,'ckm_quiz_pro_ready_games_registry(true)'),'internal registry supports existing owners');
p253($c,str_contains($ready,"'orderable'=>\$status==='publish'")&&str_contains($ready,"'hidden'=>\$status==='private'"),'orderability metadata');
p253($c,str_contains($ready,"['action'=>'preview','id'=>\$postId]")&&str_contains($ready,'ckm_quiz_pro_ready_game_admin_preview'),'admin preview');
p253($c,str_contains($ready,'Предварительный просмотр готовой игры'),'preview screen label');
p253($c,str_contains($access,'game_not_for_sale')&&str_contains($access,"!\$product['orderable']"),'direct checkout blocked');
p253($c,!str_contains($access,"'persuade_school_grade_v1'=>['title'=>'Двойка, которой не было'"),'ready game removed from hardcoded product list');
p253($c,str_contains($org,"\$orderable=!array_key_exists('orderable',\$product) || !empty(\$product['orderable']);")&&str_contains($org,'elseif($orderable) $unpaid[$key]=$product;'),'nonpublic hidden from new buyers');
p253($c,str_contains($org,"if(array_key_exists('orderable',\$product) && empty(\$product['orderable'])) continue;"),'add-other list excludes nonpublic');
$ok=count(array_filter($c));$total=count($c);echo "RESULT $ok/$total\n";exit($ok===$total?0:1);

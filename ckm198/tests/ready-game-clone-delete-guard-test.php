<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$c=file_get_contents($root.'/includes/ready-games-catalog.php');
$p=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[
 'version'=>ckm_test_current_plugin_release($p),
 'usage helper'=>str_contains($c,'function ckm_quiz_pro_ready_game_usage'),
 'deep template clone'=>str_contains($c,'function ckm_quiz_pro_ready_game_clone_source_quiz'),
 'clone admin action'=>str_contains($c,"admin_post_ckm_quiz_pro_ready_game_clone"),
 'clone is draft'=>str_contains($c,"'post_status'=>'draft'"),
 'new product key'=>str_contains($c,"'_ckm_ready_product_key','ready_game_'."),
 'usage in table'=>str_contains($c,"Покупок: "),
 'session usage'=>str_contains($c,"Сессий: "),
 'delete guard'=>str_contains($c,"if((int)\$usage['instances']>0 || (int)\$usage['games']>0)"),
 'retire private'=>str_contains($c,"'post_status'=>'private'"),
 'retired meta'=>str_contains($c,"_ckm_ready_retired"),
 'clone button'=>str_contains($c,'Клонировать'),
 'archive wording'=>str_contains($c,'Скрыть / архивировать'),
];
$ok=0;
foreach($checks as $name=>$v){ echo ($v?'OK ':'FAIL ').$name.PHP_EOL; if($v)$ok++; }
echo $ok.'/'.count($checks).PHP_EOL;
exit($ok===count($checks)?0:1);

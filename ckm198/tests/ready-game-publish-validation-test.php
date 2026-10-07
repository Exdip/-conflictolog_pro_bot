<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/includes/ready-games-catalog.php');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$c=[];function c259(&$c,$ok,$name){$c[]=$ok;echo ($ok?'OK ':'FAIL ').$name."\n";}
c259($c,ckm_test_current_plugin_release($main),'version');
c259($c,str_contains($catalog,'ckm_quiz_pro_ready_game_validate_values'),'validation function');
c259($c,str_contains($catalog,'ckm_quiz_pro_ready_game_validate_post'),'post validation function');
c259($c,str_contains($catalog,'Готовность к публикации'),'admin checklist');
c259($c,str_contains($catalog,'Публикация заблокирована.'),'blocked publish notice');
c259($c,str_contains($catalog,"'excerpt','Краткое описание для карточки'"),'description required');
c259($c,str_contains($catalog,"'content','Сюжет / ситуация'"),'plot required');
c259($c,str_contains($catalog,"'audience','Аудитория'"),'audience required');
c259($c,str_contains($catalog,"'price','Цена',\$price>0"),'paid price required');
c259($c,str_contains($catalog,"'product_unique','Уникальный ключ оплаты'"),'payment key uniqueness');
c259($c,str_contains($catalog,"'quiz','Опубликованный игровой шаблон'"),'published template required');
c259($c,str_contains($catalog,"'rounds','Раунды / этапы'"),'rounds required');
c259($c,str_contains($catalog,"'rules','Правила определения результата'"),'result rules required');
c259($c,str_contains($catalog,'$publishBlocked=$requestedStatus===\'publish\''),'publish gate');
c259($c,str_contains($catalog,"'post_status'=>'draft'")&&str_contains($catalog,"'publish_blocked'=>1"),'failed publish stays draft');
c259($c,str_contains($catalog,"if(!\$includeNonPublic && \$status==='private') continue;") && str_contains($catalog,"if(\$status!=='publish' || empty(\$item['ready_for_publish'])) continue;"),'public registry fail-safe');
c259($c,str_contains($catalog,'native-publish-blocked'),'native editor guard');
c259($c,str_contains($catalog,'✕ карточка не готова'),'catalog readiness label');
$ok=count(array_filter($c));$total=count($c);echo "RESULT $ok/$total\n";exit($ok===$total?0:1);

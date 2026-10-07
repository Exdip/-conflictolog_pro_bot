<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$content=file_get_contents($root.'/includes/persuade-me-content.php');
$show=file_get_contents($root.'/includes/negotiation-show.php');
$access=file_get_contents($root.'/includes/standalone-game-access.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$hub=file_get_contents($root.'/includes/games-hub-integration.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
$labels=file_get_contents($root.'/includes/display-labels.php');
$neg=file_get_contents($root.'/includes/standalone-negotiation-duel.php');
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$checks=[];
function ck208(&$c,$l,$ok){$c[]=[$l,(bool)$ok];if(!$ok)fwrite(STDERR,"FAIL: $l\n");}
ck208($checks,'current plugin release header and constant are synchronized',ckm_test_current_plugin_release($main));
foreach([
 'school'=>'ckm_quiz_pro_persuade_me_school_content',
 'student'=>'ckm_quiz_pro_persuade_me_student_content',
 'leader'=>'ckm_quiz_pro_persuade_me_leader_content',
 'family'=>'ckm_quiz_pro_persuade_me_family_content',
] as $variant=>$fn){
 ck208($checks,"content $variant",str_contains($content,"function {$fn}(): array"));
 ck208($checks,"seed $variant",str_contains($show,"'variant'=>'{$variant}'"));
}
ck208($checks,'student plot',str_contains($content,'Участник учебной группы не присылает свою часть совместного проекта'));
ck208($checks,'leader plot',str_contains($content,'Сильный сотрудник отказывается брать новую задачу'));
ck208($checks,'family plot',str_contains($content,'Родитель и подросток спорят о времени возвращения домой'));
ck208($checks,'same four-round contract',str_contains($content,"'round1'=>[")&&str_contains($content,"'round2'=>[")&&str_contains($content,"'round3'=>[")&&str_contains($content,"'round4'=>["));
foreach(['persuade_school_v1','persuade_student_v1','persuade_leader_v1','persuade_family_v1'] as $product){
 ck208($checks,"paid product $product",str_contains($access,"'{$product}'=>["));
 ck208($checks,"runtime mapping $product",str_contains($hub,"'{$product}'")&&str_contains($bridge,"'{$product}'"));
}
ck208($checks,'separate access helper',str_contains($access,'function ckm_quiz_pro_quiz_access_product(array $row): string'));
ck208($checks,'school access separated',str_contains($access,"'school'=>'persuade_school_v1'"));
ck208($checks,'student access separated',str_contains($access,"'student'=>'persuade_student_v1'"));
ck208($checks,'leader access separated',str_contains($access,"'leader'=>'persuade_leader_v1'"));
ck208($checks,'family access separated',str_contains($access,"'family'=>'persuade_family_v1'"));
ck208($checks,'scenario library registry',str_contains($catalog,'function ckm_quiz_pro_persuade_scenario_registry(): array'));
ck208($checks,'scenario library renderer',str_contains($catalog,'function ckm_quiz_pro_render_persuade_library(): void')&&str_contains($catalog,'ckm_quiz_pro_ready_games_registry'));
ck208($checks,'ready catalogue seeds four scenario audiences',str_contains($ready,"'product'=>'persuade_school_v1'")&&str_contains($ready,"'product'=>'persuade_student_v1'")&&str_contains($ready,"'product'=>'persuade_leader_v1'")&&str_contains($ready,"'product'=>'persuade_family_v1'"));
ck208($checks,'main persuade links to separate ready-games catalogue',str_contains($catalog,'Каталог готовых игр')&&str_contains($org,"'persuade-library'"));
ck208($checks,'custom scenario button',str_contains($catalog,'Сценарий под заказ'));
ck208($checks,'custom scenario form',str_contains($org,'function ckm_quiz_pro_org_scenario_order(): void')&&str_contains($org,'ckm_qp_scenario_order'));
ck208($checks,'education section',str_contains($catalog,"'title' => 'Образовательные игры'")&&str_contains($catalog,'Тематические игры по школьным предметам'));
ck208($checks,'education is separate direction',str_contains($org,"'education-library'")&&str_contains($org,'Образовательные игры'));
ck208($checks,'extra scenario templates hidden from generic launch',str_contains($org,"in_array(\$variant,['school','school_grade','student','leader','family'],true)"));
ck208($checks,'explicit product guard on launch',str_contains($org,'ckm_quiz_pro_quiz_access_product($quiz)')&&str_contains($org,'Нет активного доступа к этой игре. Откройте базовые игры или каталог готовых игр.'));
ck208($checks,'history variant labels',str_contains($org,"'student'=>'Переговори другого — для студентов'")&&str_contains($org,"'family'=>'Переговори другого — Семейные ситуации'"));
ck208($checks,'quiz variant labels',str_contains($neg,"'leader'=>'Переговори другого — для руководителей'"));
ck208($checks,'canonical titles variants',str_contains($labels,"'demo-negotiation-communicate-family'=>'Переговори другого — Семейные ситуации'"));
$ok=array_reduce($checks,fn($c,$x)=>$c&&$x[1],true);
echo count(array_filter($checks,fn($x)=>$x[1])).'/'.count($checks)." PASS\n";
exit($ok?0:1);

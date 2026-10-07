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
$ready=file_get_contents($root.'/includes/ready-games-catalog.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$hub=file_get_contents($root.'/includes/games-hub-integration.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
$n=0;function ok246($b,$label){global $n;if(!$b){fwrite(STDERR,"FAIL $label\n");exit(1);}echo 'PASS '.(++$n)." $label\n";}
ok246(ckm_test_current_plugin_release($main),'current plugin release header and constant are synchronized');
ok246(str_contains($content,'function ckm_quiz_pro_persuade_me_school_grade_content(): array'),'dedicated content pack exists');
ok246(str_contains($content,'«Двойка, которой не было»')&&str_contains($content,'Вы Лера.')&&str_contains($content,'Вы Виктор Палыч'),'requested situation and roles embedded');
ok246(str_contains($content,"'round1'=>[")&&str_contains($content,"'round2'=>[")&&str_contains($content,"'round3'=>[")&&str_contains($content,"'round4'=>["),'all four rounds present');
ok246(str_contains($show,"'slug'=>'demo-negotiation-communicate-school-grade'")&&str_contains($show,"'variant'=>'school_grade'")&&str_contains($show,"'fn'=>'ckm_quiz_pro_persuade_me_school_grade_content'"),'dedicated seeded template');
ok246(str_contains($access,"'school_grade'=>'persuade_school_grade_v1'")&&str_contains($ready,"'product'=>'persuade_school_grade_v1'")&&str_contains($ready,'ckm_quiz_pro_game_access_products'),'separate paid entitlement');
ok246(str_contains($hub,"'persuade_school_grade_v1'")&&str_contains($bridge,"'persuade_school_grade_v1'"),'runtime integrated');
ok246(str_contains($org,"'persuade_school_grade_v1'=>'school_grade'")&&str_contains($org,"'demo-negotiation-communicate-school-grade'"),'organizer can launch exact template');
ok246(str_contains($ready,'Каталог готовых игр')&&str_contains($ready,"'category'=>'Школьные ситуации'"),'administrator ready-games catalog and school category');
$mainCatalog=substr($catalog,0,strpos($catalog,'function ckm_quiz_pro_persuade_scenario_registry'));
ok246(!str_contains($mainCatalog,"'persuade_school_grade_v1'=>["),'new game absent from main catalog');
ok246(str_contains($ready,"'product'=>'persuade_school_grade_v1'")&&str_contains($ready,"'title'=>'Двойка, которой не было'"),'new game appears in administrator ready-games catalog');
ok246(str_contains($show,'Максимум — 280 баллов.')&&str_contains(file_get_contents($root.'/assets/standalone-game.js'),'из 280 баллов'),'280-point model preserved');

define('ABSPATH',__DIR__.'/');
function add_action(...$x){}
require $root.'/includes/persuade-me-content.php';
$c=ckm_quiz_pro_persuade_me_school_grade_content();
ok246(count($c['round1']['cases'])===3&&count($c['round2']['tasks'])===3&&count($c['round3']['questions'])===3&&count($c['round4']['dossiers'])===3,'three-team content shape valid');
ok246(count($c['round4']['dossiers'][0]['facts'])===5&&count($c['round4']['dossiers'][1]['facts'])===5&&count($c['round4']['dossiers'][2]['facts'])===5,'story dossiers have five facts each');
echo "ALL $n PASS\n";

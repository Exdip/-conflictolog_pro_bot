<?php
require_once __DIR__ . '/support/catalog-titles.php';
$root=dirname(__DIR__);
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$access=file_get_contents($root.'/includes/standalone-game-access.php');
$solution=file_get_contents($root.'/includes/standalone-solution-price.php');
$format=file_get_contents($root.'/core-source/includes/quiz/quiz-format-registry.php');
$checks=[];
function ck188($ok,$label){ global $checks; $checks[]=[$ok,$label]; if(!$ok){fwrite(STDERR,"FAIL: $label\n"); exit(1);} echo "PASS: $label\n"; }
ck188((ckm_quiz_pro_games_catalog_registry()['business']['items']['decision_price_v1']['title']??null)==='Управленческая игра "Ваш выбор"','catalog title renamed');
// dev.328 introduced the session-based master and restored this public group name.
ck188(str_contains($catalog,"'title'=>'Деловые переговоры'"),'negotiation skill group');
ck188(str_contains($catalog,'Игра для развития навыка принятия управленческих решений'),'management decision skill group');
ck188(str_contains($catalog,"'keys'=>['express_round_v1','sales_v1','persuade_me_v1','negotiation_master_v1']"),'four negotiation games grouped with the session-based master');
ck188(str_contains($catalog,"'keys'=>['decision_price_v1']"),'Управленческая игра "Ваш выбор" isolated in management group');
ck188(str_contains($access,"'decision_price_v1'=>['title'=>'Управленческая игра \"Ваш выбор\"'"),'access product renamed');
ck188(str_contains($solution,"'title'=>'Управленческая игра \"Ваш выбор\"'"),'game renamed without Demo prefix');
ck188(str_contains($solution,'управленческой игры «Управленческая игра "Ваш выбор"»'),'AI prompt renamed');
ck188(str_contains($format,"'title'=>'Управленческая игра \"Ваш выбор\"'"),'format registry renamed');
ck188(str_contains($org,"'solution_price'=>'Управленческая игра \"Ваш выбор\"'"),'organizer format label renamed');
ck188(!str_contains($catalog,'Цена решения'),'old name absent from catalog');
ck188(!str_contains($org,'Цена решения'),'old name absent from organizer UI');
echo 'TOTAL '.count($checks)."/".count($checks)." PASS\n";

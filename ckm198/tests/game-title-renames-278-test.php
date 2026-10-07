<?php
require_once __DIR__ . '/support/plugin-release.php';
require_once __DIR__ . '/support/catalog-titles.php';
$root=dirname(__DIR__);
$checks=[];
function t278(&$c,$name,$ok){$c[]=[$name,(bool)$ok];}
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$catalog=file_get_contents($root.'/includes/games-catalog.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$labels=file_get_contents($root.'/includes/display-labels.php');
$partner=file_get_contents($root.'/includes/partner/plans.php');
$migration=file_get_contents($root.'/includes/title-renames-278.php');
$registry=ckm_quiz_pro_games_catalog_registry();
$business=$registry['business']['items'];
$storedTitles=ckm_quiz_pro_builtin_game_titles();
$partnerTitles=ckm_quiz_pro_partner_base_game_titles();

t278($checks,'version',ckm_test_current_plugin_release($main));
t278($checks,'management title',($business['decision_price_v1']['title']??null)==='Управленческая игра "Ваш выбор"');
t278($checks,'sales title',ckm_quiz_pro_quiz_display_title(['slug'=>'demo-negotiation-sales','title'=>'Продажи'])==='Эффективный продажник');
t278($checks,'business title',ckm_quiz_pro_quiz_display_title(['slug'=>'demo-negotiation-business','title'=>'Деловые переговоры'])==='Мастер переговоров');
// dev.328 restored the storefront title; dev.278's stored-data migration remains.
t278($checks,'express title',($business['express_round_v1']['title']??null)==='Экспресс-раунд' && ckm_quiz_pro_quiz_display_title(['slug'=>'demo-negotiation-express','title'=>'Переговорный раунд'])==='Экспресс-раунд');
t278($checks,'persuade title',($storedTitles['demo-negotiation-communicate']??null)==='Переговори другого');
t278($checks,'partner list uses current titles',$partnerTitles===['Классический квиз','Битва знатоков','Интеллектуальный батл','Управленческая игра "Ваш выбор"','Эффективный продажник','Мастер переговоров','Экспресс-раунд','Переговори другого']);
t278($checks,'migration loaded',str_contains($main,"includes/title-renames-278.php"));
t278($checks,'migration is one-time',str_contains($migration,'ckm_quiz_pro_title_renames_278_done'));
t278($checks,'technical keys preserved',str_contains($org,"'solution_price'") && str_contains($org,"'persuade_me_v1'"));
// The public group heading may say "Деловые переговоры"; individual cards may not.
$bad=['Продажи','Деловые переговоры','Переговори меня'];
foreach($bad as $old){
    $publicTitles=array_merge(array_column($business,'title'),array_values($storedTitles),$partnerTitles);
    t278($checks,'old label removed: '.$old,!in_array($old,$publicTitles,true));
}
t278($checks,'historic express migration preserves compatibility',ckm_quiz_pro_title_renames_278_string('Экспресс-раунд')==='Переговорный раунд' && ($storedTitles['demo-negotiation-express']??null)==='Переговорный раунд');
$fail=0;
foreach($checks as [$n,$ok]){echo ($ok?'PASS':'FAIL')."\t{$n}\n"; if(!$ok)$fail++;}
echo count($checks).'/'.count($checks).' checks, failures='.$fail."\n";
exit($fail?1:0);

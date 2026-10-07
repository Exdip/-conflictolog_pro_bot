<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$duel=file_get_contents($root.'/includes/standalone-negotiation-duel.php');
$checks=[];
function ck204(&$checks,$label,$ok){$checks[]=[$label,(bool)$ok];}
ck204($checks,'current plugin version',ckm_test_current_plugin_release($main));
ck204($checks,'express seed uses Opponent speaks',substr_count($duel,'Оппонент говорит: «')>=5);
ck204($checks,'old express seed phrasing removed',strpos($duel,'"Оппонент: «')===false);
ck204($checks,'existing built-in negotiation rows are migrated',strpos($duel,'ckm_quiz_pro_upgrade_builtin_speaker_phrasing')!==false);
ck204($checks,'migration includes built-in express slug',strpos($duel,"'demo-negotiation-express'")!==false);
ck204($checks,'migration maps old opponent prefix',strpos($duel,"'Оппонент: «'   => 'Оппонент говорит: «'")!==false);
$bad=array_filter($checks,fn($x)=>!$x[1]);
foreach($checks as [$label,$ok]) echo ($ok?'PASS':'FAIL')."\t".$label."\n";
exit($bad?1:0);

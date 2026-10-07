<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$labels=file_get_contents($root.'/includes/display-labels.php');
$neg=file_get_contents($root.'/includes/standalone-negotiation-duel.php');
$checks=[];
function ck202(&$checks,$label,$ok){$checks[]=[$label,(bool)$ok]; if(!$ok) fwrite(STDERR,"FAIL: $label\n");}
ck202($checks,'current plugin version',ckm_test_current_plugin_release($main));
ck202($checks,'launch selector uses clean title',str_contains($org,'esc_html($displayTitle).\'</option>\''));
ck202($checks,'launch selector no title+format concatenation',!str_contains($org,"\$q[\'title\'].\' · \'.\$displayFormat"));
foreach(['Классический квиз','Битва знатоков','Интеллектуальный батл','Управленческая игра "Ваш выбор"','Эффективный продажник','Мастер переговоров','Переговорный раунд','Переговори другого'] as $title){ ck202($checks,'canonical '.$title,str_contains($labels,"=>'{$title}'")); }
ck202($checks,'normalizes existing games',str_contains($labels,'UPDATE {$games} SET title=%s WHERE quiz_id=%d'));
ck202($checks,'normalizes history audit',str_contains($labels,'UPDATE {$audit} a INNER JOIN {$games} g'));
ck202($checks,'negotiation badges use mode title',str_contains($neg,'return ckm_quiz_pro_negotiation_mode_title($mode);'));
$uiFiles=['standalone-schema.php','standalone-chgk.php','standalone-jeopardy.php','standalone-solution-price.php','standalone-negotiation-duel.php','negotiation-show.php','negotiation-show-dialogue.php','standalone-entitlements.php'];
foreach($uiFiles as $f){$txt=file_get_contents($root.'/includes/'.$f); ck202($checks,'no Demo prefix in '.$f,!str_contains($txt,'Демо:'));}
$ok=array_reduce($checks,fn($c,$x)=>$c&&$x[1],true);
echo count(array_filter($checks,fn($x)=>$x[1])).'/'.count($checks)." PASS\n";
exit($ok?0:1);

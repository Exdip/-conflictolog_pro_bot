<?php
require_once __DIR__.'/support/plugin-release.php';
// This feature survives later releases; validate the current header/constant contract.
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$content=file_get_contents($root.'/includes/persuade-me-content.php');
$show=file_get_contents($root.'/includes/negotiation-show.php');
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$labels=file_get_contents($root.'/includes/display-labels.php');
$checks=[];
function ck207(&$checks,$label,$ok){$checks[]=[$label,(bool)$ok];if(!$ok)fwrite(STDERR,"FAIL: $label\n");}
ck207($checks,'current plugin release header and constant are synchronized',ckm_test_current_plugin_release($main));
ck207($checks,'school content function exists',str_contains($content,'function ckm_quiz_pro_persuade_me_school_content(): array'));
ck207($checks,'round1 school project plot',str_contains($content,'Одноклассник задерживает свою часть общей презентации'));
ck207($checks,'round1 anti-cheating plot',str_contains($content,'просит дать списать готовую домашнюю работу'));
ck207($checks,'round2 school rehearsal plot',str_contains($content,'договариваются о репетиции школьного выступления'));
ck207($checks,'round3 school questions',str_contains($content,'Почему класс должен выбрать твою идею'));
ck207($checks,'round4 school fair dossier',str_contains($content,"'title'=>'Подготовка школьной ярмарки'"));
ck207($checks,'round4 olympiad dossier',str_contains($content,"'title'=>'Поездка на городскую олимпиаду'"));
ck207($checks,'round4 school play dossier',str_contains($content,"'title'=>'Репетиция школьного спектакля'"));
ck207($checks,'same communicate mechanics',str_contains($show,"'demo-negotiation-communicate-school'")&&str_contains($show,"'communicate',"));
ck207($checks,'exactly two teams',str_contains($show,"'min_teams'=>2")&&str_contains($show,"'max_teams'=>2"));
ck207($checks,'school content stored in persuadeMeContent',str_contains($show,"persuadeMeContent']=\$content"));
ck207($checks,'school variant marker',str_contains($show,"'variant'=>'school'")&&str_contains($show,"persuadeMeVariant']=\$variant"));
ck207($checks,'launch title mapping',str_contains($org,"'demo-negotiation-communicate-school'=>'Переговори другого — для школьников'"));
ck207($checks,'canonical title mapping',str_contains($labels,"'demo-negotiation-communicate-school'=>'Переговори другого — для школьников'"));
ck207($checks,'business default content preserved',str_contains($content,'Клиент требует дополнительную работу бесплатно.'));
ck207($checks,'mechanics names unchanged',str_contains($content,"'round1'=>[")&&str_contains($content,"'round4'=>["));
$ok=array_reduce($checks,fn($c,$x)=>$c&&$x[1],true);
echo count(array_filter($checks,fn($x)=>$x[1])).'/'.count($checks)." PASS\n";
exit($ok?0:1);

<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$duel=file_get_contents($root.'/includes/standalone-negotiation-duel.php');
$auto=file_get_contents($root.'/includes/standalone-auto-host.php');
$js=file_get_contents($root.'/assets/standalone-game.js');
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$checks=[];
function ck205(&$c,$name,$ok){$c[]=[$name,(bool)$ok];}
ck205($checks,'current plugin version',ckm_test_current_plugin_release($main));
ck205($checks,'sales client says',substr_count($duel,'Клиент говорит: «')>=5);
ck205($checks,'business buyer says',substr_count($duel,'Покупатель говорит: «')>=4);
ck205($checks,'express opponent says',substr_count($duel,'Оппонент говорит: «')>=5);
ck205($checks,'migration reads correct question_text column',str_contains($duel,'SELECT id,question_text'));
ck205($checks,'migration is all builtin negotiation modes',str_contains($duel,"'demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express'"));
ck205($checks,'generic PHP speech normalizer exists',str_contains($auto,'function ckm_quiz_pro_spoken_label_text'));
ck205($checks,'TTS question uses normalizer',str_contains($auto,'ckm_quiz_pro_spoken_label_text((string)$q[\'question_text\'])'));
ck205($checks,'client display normalizer exists',str_contains($js,'function speechLabelText'));
ck205($checks,'question display uses normalizer',str_contains($js,"qel.textContent=speechLabelText"));
ck205($checks,'team dialogue says speaks',str_contains($js,"+' говорит:</strong>"));
$fail=0;foreach($checks as [$n,$ok]){echo ($ok?'PASS':'FAIL')." - $n\n";if(!$ok)$fail++;}echo (count($checks)-$fail).'/'.count($checks)." PASS\n";exit($fail?1:0);

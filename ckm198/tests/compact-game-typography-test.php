<?php
require_once __DIR__ . '/support/plugin-release.php';
$root=dirname(__DIR__);
$main=file_get_contents($root.'/ckm-quiz-pro.php');
$pub=file_get_contents($root.'/includes/standalone-public.php');
$checks=[];
function ck203(&$checks,$name,$ok){$checks[]=[$name,(bool)$ok];}
ck203($checks,'current plugin version',ckm_test_current_plugin_release($main));
ck203($checks,'question text compact',str_contains($pub,'.question{font-size:clamp(18px,1.8vw,24px);line-height:1.45;font-weight:400}'));
ck203($checks,'timer compact regular',str_contains($pub,'.timer{font-size:22px;font-weight:400;line-height:1.35;margin:6px 0 10px}'));
ck203($checks,'old oversized question removed',!str_contains($pub,'.question{font-size:clamp(22px,3vw,34px);line-height:1.3}'));
ck203($checks,'old bold timer removed',!str_contains($pub,'.timer{font-size:42px;font-weight:800}'));
ck203($checks,'participant uses timer class',str_contains($pub,'class="timer" id="timer"'));
ck203($checks,'participant uses question class',str_contains($pub,'class="question" id="question"'));
$pass=0;
foreach($checks as [$name,$ok]){echo ($ok?'PASS':'FAIL')." - $name\n"; if($ok)$pass++;}
echo "$pass/".count($checks)." PASS\n";
exit($pass===count($checks)?0:1);

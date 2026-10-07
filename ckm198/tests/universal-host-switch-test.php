<?php
$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/standalone-game.js');
$api=file_get_contents($root.'/includes/standalone-api.php');
$pub=file_get_contents($root.'/includes/standalone-public.php');
$show=file_get_contents($root.'/includes/negotiation-show-dialogue.php');
$checks=[];
function chk(&$c,$name,$ok){$c[]=[$name,(bool)$ok];}
chk($checks,'Universal button exists',strpos($pub,'id="hostModeSwitch"')!==false);
chk($checks,'Button is outside generic actions',preg_match('/id="hostModeActions"[^>]*>.*id="hostModeSwitch".*<\/div><div class="host-actions" id="genericHostActions"/s',$pub)===1);
chk($checks,'No CHGK-only client visibility rule',strpos($js,"modeSwitch.hidden=formatKey!=='chgk'")===false);
chk($checks,'Universal client renderer exists',strpos($js,'function renderHostModeSwitch(st)')!==false);
chk($checks,'Jeopardy renders switch',strpos($js,'renderJeopardyHost(st){')!==false && strpos($js,'renderHostModeSwitch(st);hide(\'genericHostActions\',true)')!==false);
chk($checks,'AI label = human host',strpos($js,"'Переключить на ведущего-человека'")!==false);
chk($checks,'Universal server function exists',strpos($api,'function ckm_quiz_pro_switch_host_mode_universal')!==false);
chk($checks,'Universal command route exists',strpos($api,"if(in_array(\$command,['switch_human','switch_ai'],true))")!==false);
chk($checks,'Sequential AI autopilot resumes',strpos($api,"['classic_quiz','solution_price','negotiation_duel']")!==false && strpos($api,'ckm_quiz_ai_host_classic_autopilot')!==false);
chk($checks,'Jeopardy AI autopilot resumes',strpos($api,"\$formatKey==='jeopardy'")!==false && strpos($api,'ckm_quiz_pro_jeopardy_local_autopilot')!==false);
chk($checks,'CHGK specialized switch retained',strpos($api,"\$formatKey==='chgk' && function_exists('ckm_quiz_chgk_switch_host_mode')")!==false);
chk($checks,'Persuade Me dedicated switch retained',strpos($show,'ckmqp_show_switch_host_mode')!==false);
chk($checks,'Manual takeover guard retained',strpos($js,'manualTakeoverPending=true')!==false && strpos($js,"stopAiClientAutomation('manual_switch_requested')")!==false);
chk($checks,'Server stale AI guard retained',strpos(file_get_contents($root.'/core-source/includes/quiz/quiz-engine.php'),'ai_host_manual_takeover')!==false);
$pass=0;foreach($checks as [$n,$ok]){echo ($ok?'PASS':'FAIL')." - $n\n";if($ok)$pass++;}
echo "RESULT $pass/".count($checks)."\n";exit($pass===count($checks)?0:1);

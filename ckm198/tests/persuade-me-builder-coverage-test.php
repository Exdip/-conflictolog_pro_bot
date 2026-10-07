<?php
$root = dirname(__DIR__);
function ck133($ok,$label){ if(!$ok){fwrite(STDERR,"FAIL\t$label\n"); exit(1);} echo "PASS\t$label\n"; }
$org=file_get_contents($root.'/includes/standalone-organizer.php');
$access=file_get_contents($root.'/includes/standalone-game-access.php');
$bridge=file_get_contents($root.'/includes/games-hub-runtime-bridge.php');
$neg=file_get_contents($root.'/includes/standalone-negotiation-duel.php');
$admin=file_get_contents($root.'/includes/standalone-admin.php');
ck133(str_contains($org,"'persuade_me_v1'=>'Переговори другого'"),'frontend builder lists Переговори другого');
ck133(str_contains($org,'$requestedChoice===\'persuade_me_v1\'?\'negotiation_duel\''),'frontend builder maps to negotiation core');
ck133(str_contains($org,'name="negotiation_mode" value="communicate"'),'frontend builder saves communicate mode');
ck133(str_contains($org,'ckm_quiz_pro_persuade_me_front_editor'),'builder exposes dedicated four-round content editor');
ck133(str_contains($access,"'persuade_me_v1'=>'negotiation_duel_v1'"),'same paid entitlement is reused');
ck133(str_contains($access,"'title'=>'Переговорные поединки'"),'payment family renamed in plural');
ck133(str_contains($bridge,"'persuade_me_v1'           => array('format_key'=>'negotiation_duel','mode'=>'communicate')"),'compatibility bridge knows persuade runtime');
ck133(str_contains($neg,'if ($mode === \'communicate\') return 30;'),'communicate compatibility base time is 30 sec');
ck133(str_contains($admin,'if($postedFormat===\'persuade_me_v1\') $postedFormat=\'negotiation_duel\';'),'technical save maps virtual format safely');
ck133(str_contains($admin,'$isPersuadeMe?2:'),'saved persuade game is locked to 2 teams');
ck133(substr_count($org,'Переговори другого') >= 8,'organizer/public surfaces mention game');
echo "ALL PASS\n";

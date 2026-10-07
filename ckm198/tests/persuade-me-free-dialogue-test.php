<?php
if(PHP_SAPI!=='cli')exit;
$root=dirname(__DIR__);
$js=file_get_contents($root.'/assets/standalone-game.js');
$dialogue=file_get_contents($root.'/includes/negotiation-show-dialogue.php');
$organizer=file_get_contents($root.'/includes/standalone-organizer.php');
$admin=file_get_contents($root.'/includes/standalone-admin.php');
$checks=[];
function ck162($ok,$name){global $checks;$checks[]=[$ok,$name];}
ck162(!str_contains($js,'Без таймера')&&!str_contains($js,'Диалог · без таймера'),'untimed UI does not show the no-timer badge');
ck162(str_contains($js,'id="showFinishDialogue"')&&str_contains($js,"showAction('finish_dialogue')"),'human host has finish-dialogue control');
ck162(str_contains($js,'const showTimedPhase=')&&!str_contains($js,"const showTimedPhase=s.stage==='hard_answer'")&&str_contains($js,"s.stage==='story_answer'&&Number(s.deadline||0)>0")&&str_contains($js,"s.stage==='story_tell'&&Number(s.deadline||0)>0"),'pause excludes untimed hard-question stage');
ck162(str_contains($dialogue,"\$s['phase']='dialogue';")&&str_contains($dialogue,"\$s['deadline']=\$limit>0"),'dialogue deadline is optional');
ck162(str_contains($dialogue,"\$command==='finish_dialogue'")&&str_contains($dialogue,"\$s['dialogueClosedBy']='host'"),'server supports explicit dialogue finish');
ck162(str_contains($dialogue,'ckmqp_show_ai_dialogue_should_finish'),'AI-host automatic dialogue completion exists');
ck162(str_contains($organizer,'persuade_dialogue_limit_enabled')&&str_contains($admin,"persuadeDialogueLimitEnabled"),'organizer can opt into a dialogue limit');
$fail=array_filter($checks,fn($x)=>!$x[0]);foreach($checks as [$ok,$name])echo ($ok?'PASS':'FAIL')."\t$name\n";exit($fail?1:0);

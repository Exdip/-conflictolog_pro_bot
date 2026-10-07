<?php
if(PHP_SAPI!=='cli')exit;
$js=file_get_contents(dirname(__DIR__).'/assets/standalone-game.js');
$n=0;function ck($b,$m){global $n;if(!$b){fwrite(STDERR,"FAIL $m\n");exit(1);}echo 'PASS '.(++$n)." $m\n";}
ck(str_contains($js,"function renderShowFinalSurface(st)"),'dedicated final surface exists');
ck(str_contains($js,"if(s.stage==='game_complete'){renderShowFinalSurface(st);return;}"),'game complete switches to final-only surface');
ck(str_contains($js,"$('showLog').hidden=true")&&str_contains($js,"$('showCompose').hidden=true")&&str_contains($js,"$('showVote').hidden=true"),'conversation and voting controls are hidden');
ck(str_contains($js,"$('showReady').hidden=true")&&str_contains($js,"$('showContinue').hidden=true")&&str_contains($js,"$('showNextRound').hidden=true")&&str_contains($js,"$('showPause').hidden=true"),'all game-control buttons are hidden');
ck(str_contains($js,"title.textContent='Итоговый рейтинг'"),'role list becomes final ranking');
ck(str_contains($js,"MODE==='scoreboard'?'Финальный результат'"),'scoreboard gets public final heading');
ck(str_contains($js,"if(MODE==='play'&&s.teamName)")&&str_contains($js,"'Ваша команда: '"),'participant gets own rank and total');
ck(str_contains($js,"if(s.stage==='game_complete'){panel.innerHTML='<div class=\"answer-row\">'+showFinalHeadline(s)+showFinalTableHtml(s)+'</div>';return;"),'final panel omits old review feed');
ck(str_contains($js,"hide('timer',true);hide('question',true);"),'stale timer and situation are removed');
echo "ALL $n PASS\n";

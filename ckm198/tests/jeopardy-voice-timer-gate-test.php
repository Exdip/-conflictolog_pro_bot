<?php
if (PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__);
$engine=file_get_contents($root.'/core-source/includes/quiz/quiz-engine.php');
$runtime=file_get_contents($root.'/core-source/includes/quiz/quiz-jeopardy-runtime.php');
$final=file_get_contents($root.'/core-source/includes/quiz/quiz-jeopardy-final.php');
$voice=file_get_contents($root.'/includes/standalone-voice.php');
$game=file_get_contents($root.'/assets/standalone-game.js');
$checks=[
 'ordinary jeopardy AI question defers deadline'=>str_contains($engine, '$deferJeopardyAiTimer = $formatForTimer === \'jeopardy\' && $isAiHost') && str_contains($engine, '$deferAiTimerUntilVoiceEnd = $deferChgkAiTimer || $deferJeopardyAiTimer || $deferSequentialAiTimer'),
 'ordinary jeopardy publish failure starts fallback timer'=>str_contains($engine, '!empty($deferJeopardyAiTimer)') && str_contains($engine, "'voice_publish_failed'"),
 'server blocks jeopardy answers before voice end'=>str_contains($engine, "array('classic_quiz','solution_price','negotiation_duel','jeopardy')") && str_contains($engine, "'timer_waiting_for_voice'"),
 'buzzer remains closed while AI voice is speaking'=>substr_count($runtime, '$voiceTimerArmed')>=2 && str_contains($runtime, '$open = $isLive && $phase === \'question_open\' && $questionId > 0 && $voiceTimerArmed'),
 'cat answer remains closed while AI voice is speaking'=>str_contains($runtime, '&& $voiceTimerArmed') && str_contains($runtime, '$phase === \'question_closed\' || $voiceTimerArmed'),
 'final starts with deferred deadline in AI mode'=>str_contains($final, '$deferUntilVoiceEnd = (string)($game[\'host_mode_snapshot\'] ?? \'\') === \'ai\'') && str_contains($final, "'question_deadline_at'=>\$deferUntilVoiceEnd ? null : \$deadline"),
 'final state exposes awaiting voice'=>str_contains($final, "'awaitingVoice'=>\$awaitingVoice"),
 'final voice completion arms authoritative timer'=>str_contains($final,'function ckm_quiz_jeopardy_start_final_timer_after_voice') && str_contains($final,"'jeopardy_final_timer_started'"),
 'final autopilot waits for narration'=>str_contains($final,"'awaiting_final_voice'"),
 'voice ack routes jeopardy ordinary and final'=>str_contains($voice,"'solution_price','negotiation_duel','jeopardy'") && str_contains($voice,'ckm_quiz_jeopardy_start_final_timer_after_voice'),
 'participant hides answer controls while narration plays'=>str_contains($game,'Кнопка ответа и таймер откроются после окончания вопроса') && str_contains($game,'Слушайте финальный вопрос. Таймер ответа начнётся после окончания реплики ведущего.'),
 'timer UI recognizes jeopardy voice wait'=>str_contains($game,"'classic_quiz','solution_price','negotiation_duel','jeopardy'") && str_contains($game,'Слушайте ведущего · таймер после реплики'),
 '185 STT single-owner fix remains present'=>str_contains($game,'preserveStt') && str_contains($game,'negotiationShow'),
 '180 AI manual takeover guard remains present'=>str_contains($game,'stopAiClientAutomation') && str_contains($game,'manual_mode_confirmed'),
];
$i=0;$failed=false;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.(++$i).' '.$name.PHP_EOL;if(!$ok)$failed=true;}exit($failed?1:0);

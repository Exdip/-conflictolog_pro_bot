<?php
if (PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__);
$engine=file_get_contents($root.'/core-source/includes/quiz/quiz-engine.php');
$voice=file_get_contents($root.'/includes/standalone-voice.php');
$game=file_get_contents($root.'/assets/standalone-game.js');
$checks=[
 'sequential AI formats defer deadline'=>str_contains($engine,"array('classic_quiz','solution_price','negotiation_duel')") && str_contains($engine,'$deferSequentialAiTimer') && str_contains($engine,'$deferJeopardyAiTimer'),
 'deferred question stores null deadline'=>str_contains($engine,"'question_deadline_at'=>".'$deferAiTimerUntilVoiceEnd'." ? null : ".'$deadline'),
 'authoritative timer helper is idempotent'=>str_contains($engine,'ckm_quiz_start_deferred_question_timer') && str_contains($engine,'question_deadline_at IS NULL') && str_contains($engine,"'question-timer-started-'"),
 'timer starts from playback completion'=>str_contains($voice,'ckm_quiz_pro_sequential_start_timer_after_voice') && str_contains($voice,"'voice_playback_complete'"),
 'voice ack validates exact question_started event'=>str_contains($voice,"".'$hostEvent'."!=='question_started'") && str_contains($voice,'timerDeferredUntilVoiceEnd'),
 'CHGK dedicated bridge remains routed'=>str_contains($voice,"".'$formatKey'."==='chgk'") && str_contains($voice,'ckm_quiz_chgk_start_ai_early_answer_after_voice'),
 'server blocks answers before voice end'=>str_contains($engine,"'timer_waiting_for_voice'"),
 'participant UI disables answers before timer'=>str_contains($game,'sequentialVoiceTimerWaiting') && str_contains($game,'selected||voiceTimerWaiting'),
 'participant UI explains voice-first wait'=>str_contains($game,'Слушайте ведущего. Таймер начнётся после окончания реплики.'),
 'timer UI explains deferred countdown'=>str_contains($game,'Слушайте ведущего · таймер после реплики'),
 'publish failure has immediate safe fallback'=>str_contains($engine,"'voice_publish_failed'"),
 '185 STT single-owner fix remains present'=>str_contains($game,'preserveStt') && str_contains($game,'negotiationShow'),
];
$i=0;$failed=false;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL').' '.(++$i).' '.$name.PHP_EOL;if(!$ok)$failed=true;}exit($failed?1:0);

<?php
$root=dirname(__DIR__);
$files=[
 'core-source/includes/quiz/quiz-engine.php',
 'core-source/includes/quiz/quiz-format-runtime.php',
 'core-source/includes/quiz/quiz-chgk-question-flow.php',
 'includes/standalone-chgk.php',
 'includes/standalone-auto-host.php',
 'includes/standalone-voice.php',
 'assets/standalone-game.js',
 'assets/standalone-voice.js',
 'assets/standalone-ai-host.js',
];
$all='';foreach($files as $f){$all.=file_get_contents($root.'/'.$f)."\n";}
$checks=[
 'AI CHGK timer deferred while question is spoken'=>strpos($all,'timerDeferredUntilVoiceEnd')!==false && strpos($all,'question_narration')!==false,
 'Actual playback completion bridge exists'=>strpos($all,'voice_playback_complete')!==false && strpos($all,'ckmqp:voice-playback-ended')!==false,
 'Voice completion starts early-answer window'=>strpos($all,'ckm_quiz_chgk_start_ai_early_answer_after_voice')!==false && strpos($all,"'chgk_early_answer_window_started'")!==false,
 'Early-answer window uses configured 5-second default'=>strpos($all,"'earlyAnswerSeconds'=>max(5")!==false || strpos($all,"'earlyAnswerSeconds'=>5")!==false,
 'No early answer transitions to 60-second discussion'=>strpos($all,'$seconds=60;')!==false && strpos($all,'ckm_quiz_chgk_start_discussion')!==false,
 'Final-answer window remains 20 seconds'=>strpos($all,"'finalAnswerSeconds'=>20")!==false && strpos($all,"finalAnswerSeconds'=>\$seconds")!==false,
 'Participant early-answer button remains in early window'=>strpos($all,"chgkPhase==='early_answer_offer'")!==false && strpos($all,'id="earlyAnswerBtn"')!==false,
 'Correct-answer speech deduplicates case-only variants'=>strpos($all,'ckm_quiz_pro_host_unique_answer_values')!==false,
];
$failed=false;foreach($checks as $name=>$ok){echo ($ok?'PASS':'FAIL')." - $name\n";if(!$ok)$failed=true;}exit($failed?1:0);

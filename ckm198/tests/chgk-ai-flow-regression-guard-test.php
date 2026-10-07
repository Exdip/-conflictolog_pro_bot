<?php
$root=dirname(__DIR__);
$files=[
 'core-source/includes/quiz/quiz-chgk-question-flow.php',
 'includes/standalone-chgk.php',
 'includes/standalone-auto-host.php',
 'includes/games-hub-smoke.php',
 'assets/standalone-game.js',
];
$all=''; foreach($files as $f){ $all.=file_get_contents($root.'/'.$f)."\n"; }
$checks=[
 '5-second early-answer contract'=>strpos($all,"'earlyAnswerSeconds'=>max(5")!==false && strpos($all,"Окно досрочного ответа")!==false,
 '60-second discussion contract'=>strpos($all,"'discussion_time_seconds'=>60")!==false || strpos($all,"$seconds=60;")!==false,
 '20-second final-answer contract'=>strpos($all,"'finalAnswerSeconds'=>20")!==false && strpos($all,"Окно окончательного ответа")!==false,
 'Question playback starts early window'=>strpos($all,'ckm_quiz_chgk_start_ai_early_answer_after_voice')!==false && strpos($all,'chgk_question_narration_completed')!==false,
 'Arbitration playback gates reveal'=>strpos($all,'chgk_ai_arbitration_voice_completed')!==false && strpos($all,'arbitration_voice_hold')!==false,
 'Reveal playback gates next question'=>strpos($all,'chgk_answer_reveal_voice_completed')!==false && strpos($all,'answer_reveal_voice_hold')!==false,
 'Participant hint says 5 seconds after narration'=>strpos($all,'После окончания озвучивания начнутся 5 секунд на досрочный ответ.')!==false,
 'Correct answer variants are deduplicated'=>strpos($all,'ckm_quiz_pro_host_unique_answer_values')!==false,
];
$failed=false; foreach($checks as $name=>$ok){ echo ($ok?'PASS':'FAIL')." - $name\n"; if(!$ok)$failed=true; }
exit($failed?1:0);

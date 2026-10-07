<?php
if (PHP_SAPI!=='cli') exit;
define('ABSPATH',__DIR__.'/');

function ckm_quiz_pro_chgk_local_autopilot(int $gameId): array {
    return array('ok'=>true,'gameId'=>$gameId);
}

require dirname(__DIR__).'/core-source/includes/quiz/quiz-chgk-question-flow.php';

$checks=array(
    'standalone autopilot makes AI available'=>ckm_quiz_chgk_ai_host_available(),
    'waiting room allows takeover'=>ckm_quiz_chgk_ai_takeover_is_safe(array('quiz_phase'=>'waiting'),array('phase'=>'waiting')),
    'discussion allows takeover'=>ckm_quiz_chgk_ai_takeover_is_safe(array('quiz_phase'=>'question_open'),array('phase'=>'discussion')),
    'closed pending answer allows takeover'=>ckm_quiz_chgk_ai_takeover_is_safe(array('quiz_phase'=>'question_closed'),array('phase'=>'arbitration','answerRevealed'=>false,'pendingArbitration'=>1)),
    'closed answer before reveal allows takeover'=>ckm_quiz_chgk_ai_takeover_is_safe(array('quiz_phase'=>'question_closed'),array('phase'=>'answers_closed','answerRevealed'=>false,'pendingArbitration'=>0)),
    'final answer input remains protected'=>!ckm_quiz_chgk_ai_takeover_is_safe(array('quiz_phase'=>'question_open'),array('phase'=>'final_answer_open')),
);

$passed=0;
foreach($checks as $name=>$ok){
    if(!$ok){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}
    echo 'PASS '.(++$passed).' '.$name.PHP_EOL;
}
echo 'ALL '.$passed.' PASS'.PHP_EOL;

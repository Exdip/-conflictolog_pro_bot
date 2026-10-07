<?php
if (PHP_SAPI!=='cli') exit;
$root=dirname(__DIR__);
$files=[
    'game'=>file_get_contents($root.'/assets/standalone-game.js'),
    'ai'=>file_get_contents($root.'/assets/standalone-ai-host.js'),
    'api'=>file_get_contents($root.'/includes/standalone-api.php'),
    'chgk'=>file_get_contents($root.'/includes/standalone-chgk.php'),
    'engine'=>file_get_contents($root.'/core-source/includes/quiz/quiz-engine.php'),
    'flow'=>file_get_contents($root.'/core-source/includes/quiz/quiz-chgk-question-flow.php'),
    'lobby'=>file_get_contents($root.'/core-source/includes/quiz/quiz-chgk-lobby.php'),
    'show'=>file_get_contents($root.'/includes/negotiation-show-dialogue.php'),
];
$checks=[
    'manual switch cancels pending client AI automation'=>str_contains($files['game'],"stopAiClientAutomation('manual_switch_requested')"),
    'delayed AI autostart rechecks authoritative client mode'=>str_contains($files['game'],"String(current.hostMode||'')!=='ai'"),
    'manual switch requires server confirmation'=>str_contains($files['game'],"Сервер не подтвердил переход к ручному управлению."),
    'manual mode clears client AI autostart timer'=>str_contains($files['game'],'clearTimeout(aiAutoStartTimer)'),
    'browser AI speech queue is cancelled'=>str_contains($files['ai'],'window.speechSynthesis.cancel()') && str_contains($files['ai'],"ckmqp:ai-host-stop"),
    'CHGK phase tick refreshes game before acting'=>str_contains($files['chgk'],'$fresh=ckm_quiz_get_game($gameId);if($fresh)$game=$fresh;'),
    'CHGK autopilot stops after a manual takeover during judging'=>str_contains($files['chgk'],"reason'=>'manual_takeover_after_judging'"),
    'AI open-next is rejected in manual mode'=>str_contains($files['engine'],"'ai_host_manual_takeover'") && str_contains($files['engine'],'$actorType === \'ai_host\''),
    'AI reveal is rejected in manual mode'=>str_contains($files['flow'],'$role===\'ai_host\'') && str_contains($files['flow'],"'ai_host_manual_takeover'"),
    'AI finish is rejected in manual mode'=>substr_count($files['engine'],"'ai_host_manual_takeover'")>=2,
    'slow AI compose rechecks mode before publish'=>str_contains($files['engine'],"reason'=>'manual_takeover_during_compose'"),
    'lobby AI autostart rechecks mode before open'=>str_contains($files['lobby'],"reason'=>'manual_takeover_before_autostart'"),
    'negotiation show rechecks current mode before AI advance'=>str_contains($files['show'],'$modeGame=ckm_quiz_get_game($gid) ?: $game;'),
    '179 team dictation recorder fallback remains present'=>str_contains($files['game'],'MediaRecorder') && str_contains($files['game'],'sttRecorder'),
];
$i=0;
foreach($checks as $name=>$ok){
    if(!$ok){fwrite(STDERR,'FAIL '.$name.PHP_EOL);exit(1);}
    echo 'PASS '.(++$i).' '.$name.PHP_EOL;
}
echo 'ALL '.$i.' PASS'.PHP_EOL;

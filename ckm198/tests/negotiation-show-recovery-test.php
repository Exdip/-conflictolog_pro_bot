<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');
function add_action(...$args){}
function ckm_quiz_json_decode($s){return json_decode($s,true)?:[];}
require dirname(__DIR__).'/includes/persuade-me-content.php';
require dirname(__DIR__).'/includes/negotiation-show.php';
require dirname(__DIR__).'/includes/negotiation-show-dialogue.php';
require dirname(__DIR__).'/includes/negotiation-show-review.php';
require dirname(__DIR__).'/includes/negotiation-show-hidden.php';
require dirname(__DIR__).'/includes/negotiation-show-hard-question.php';
require dirname(__DIR__).'/includes/negotiation-show-story.php';
require dirname(__DIR__).'/includes/negotiation-show-manual-review.php';
$n=0;
function verify($ok,$label){global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.(++$n).' '.$label."\n";}

function manual_participant(array $criteria,string $summary='Ручная оценка.'): array {
    $rows=[];foreach($criteria as $key)$rows[$key]=['points'=>5,'reason'=>'Есть достаточное проявление критерия.','evidence'=>[]];
    return ['criteria'=>$rows,'summary'=>$summary,'recommendation'=>'Уточнить следующий шаг.'];
}
function regular_manual_raw(): array {
    $keys=array_keys(ckmqp_show_review_criteria());
    return ['participants'=>[
        'speaker'=>manual_participant($keys,'Итог переговорщика.'),
        'opponent'=>manual_participant($keys,'Итог оппонента.'),
    ],'summary'=>'Общий итог ручной оценки.'];
}
function speaker_manual_raw(bool $story=false): array {
    $keys=array_keys($story?ckmqp_show_story_review_criteria():ckmqp_show_review_criteria());
    return ['participants'=>['speaker'=>manual_participant($keys,$story?'Итог рассказчика.':'Итог отвечающей команды.')],'summary'=>'Итог ручной оценки.'];
}

$host=['role'=>'host'];$team=['role'=>'participant','slot'=>1,'team_id'=>101];

// Round 1: ordinary seven-criterion review.
$s=ckmqp_show_initial();
$s['phase']='review';
$s['messages']=[
 ['id'=>1,'round'=>0,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Предлагаю зафиксировать срок сегодня.','at'=>1,'requestId'=>'r1-speaker-1'],
 ['id'=>2,'round'=>0,'attempt'=>0,'slot'=>2,'teamId'=>102,'text'=>'Согласен, фиксируем срок.','at'=>2,'requestId'=>'r1-opponent-1'],
];
$s['reviews'][0]=['status'=>'failed','error'=>'AI failure','retryAfter'=>115];
verify(ckmqp_show_manual_review_available($s,100),'manual recovery available after AI failure in round 1');
verify(ckmqp_show_manual_review_available($s,120),'manual recovery remains available after retry window');
$r=ckmqp_show_reduce($s,$team,['command'=>'manual_review','round'=>0,'attempt'=>0,'review'=>regular_manual_raw()],120);
verify(!$r['ok']&&$r['code']==='host_required','participant cannot submit manual review');
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>0,'attempt'=>0,'review'=>regular_manual_raw()],120);
verify($r['ok'],'manual seven-criterion review saves in round 1');
verify(($r['session']['reviews'][0]['review']['speakerTotal']??0)===35&&($r['session']['reviews'][0]['review']['opponentTotal']??0)===35,'round 1 totals are 35 and 35');
verify(($r['session']['reviews'][0]['review']['source']??'')==='manual'&&($r['session']['reviews'][0]['source']??'')==='manual','manual source is persisted');
verify(!ckmqp_show_manual_review_available($r['session'],121),'completed review cannot be manually overwritten');
$legacy=ckmqp_show_initial();$legacy['phase']='review';$legacy['reviews'][0]=['status'=>'failed'];
$bad=ckmqp_show_reduce($legacy,$host,['command'=>'manual_review','round'=>0,'attempt'=>0,'points'=>[100,50,100],'text'=>'legacy'],100);
verify(!$bad['ok']&&$bad['code']==='legacy_review_rejected','legacy three-score payload is rejected');

// Round 1 retry lease expiry is also a valid manual trigger.
$expired=ckmqp_show_initial();$expired['phase']='review';$expired['reviews'][0]=['status'=>'running','expires'=>90,'lease'=>'expired-lease-00000001'];
verify(ckmqp_show_manual_review_available($expired,100),'expired AI lease exposes manual recovery');
$running=ckmqp_show_initial();$running['phase']='review';$running['reviews'][0]=['status'=>'running','expires'=>200,'lease'=>'live-lease-00000001'];
verify(!ckmqp_show_manual_review_available($running,100),'active AI lease blocks manual recovery');

// Round 2: hidden-task review uses the same seven-criterion contract.
$s=ckmqp_show_initial();$s['round']=1;$s['attempt']=0;$s['phase']='review';$s['hiddenReviews'][0]=['status'=>'failed','error'=>'AI failure'];
verify(ckmqp_show_manual_review_available($s,100),'manual recovery available in round 2');
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>1,'attempt'=>0,'review'=>regular_manual_raw()],100);
verify($r['ok'],'manual seven-criterion review saves in round 2');
verify(($r['session']['hiddenReviews'][0]['review']['rubricVersion']??0)===3,'round 2 uses unified rubric version 3');

// Round 3: speaker-only review.
$s=ckmqp_show_initial();$s['round']=2;$s['attempt']=0;$s['phase']='review';$s['hardReviews'][0]=['status'=>'failed','error'=>'AI failure'];
verify(ckmqp_show_manual_review_available($s,100),'manual recovery available in round 3');
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>2,'attempt'=>0,'review'=>speaker_manual_raw(false)],100);
verify($r['ok'],'manual speaker-only review saves in round 3');
verify(($r['session']['hardReviews'][0]['review']['speakerTotal']??0)===35&&($r['session']['hardReviews'][0]['review']['suppressWinner']??false)===true,'round 3 stores speaker-only 35-point projection');

// Round 4: dedicated story rubric.
$s=ckmqp_show_initial();$s['round']=3;$s['attempt']=0;$s['phase']='review';$s['storyResults'][0]=['vote'=>'truth'];$s['storyReviews'][0]=['status'=>'failed','error'=>'AI failure'];
verify(ckmqp_show_manual_review_available($s,100),'manual recovery available in round 4');
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>3,'attempt'=>0,'review'=>speaker_manual_raw(true)],100);
verify($r['ok'],'manual story review saves in round 4');
verify(($r['session']['storyReviews'][0]['review']['speakerTotal']??0)===35&&($r['session']['storyReviews'][0]['review']['rubricVersion']??0)===4,'round 4 uses dedicated story rubric version 4');

// Stale round/attempt and malformed review remain protected.
$s=ckmqp_show_initial();$s['phase']='review';$s['reviews'][0]=['status'=>'failed'];
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>1,'attempt'=>0,'review'=>regular_manual_raw()],100);
verify(!$r['ok']&&$r['code']==='stale_attempt','wrong round is rejected');
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>0,'attempt'=>0,'review'=>['participants'=>[]]],100);
verify(!$r['ok']&&$r['code']==='review_invalid','malformed seven-criterion review is rejected');

// Existing AI result is never overwritten.
$s=ckmqp_show_initial();$s['phase']='review';$s['reviews'][0]=['status'=>'done','review'=>['speakerTotal'=>70,'opponentTotal'=>60]];
verify(!ckmqp_show_manual_review_available($s,100),'successful AI result blocks manual overwrite');

$messages=[
 ['id'=>7,'round'=>0,'attempt'=>0,'slot'=>1,'text'=>'Передайте данные сегодня.'],
 ['id'=>8,'round'=>0,'attempt'=>0,'slot'=>2,'text'=>'Согласен.'],
];
$keys=array_keys(ckmqp_show_review_criteria());
$make=function($id,$quote) use($keys){$criteria=[];foreach($keys as $k)$criteria[$k]=['points'=>5,'reason'=>'Есть проявление.','evidence'=>[['messageId'=>$id,'quote'=>$quote]]];return ['criteria'=>$criteria,'summary'=>'Итог.','recommendation'=>'Уточнить следующий шаг.'];};
$raw=['participants'=>['speaker'=>$make(7,'Передайте данные сегодня.'),'opponent'=>$make(8,'Согласен.')],'summary'=>'Итог диалога.'];
$error=null;$review=ckmqp_show_validate_review($raw,$messages,0,$error);
verify(is_array($review)&&$review['speakerTotal']===35&&$review['opponentTotal']===35,'manual-compatible seven-criterion validator accepts grounded evidence');

$r=$s;$r['round']=1;verify(!ckmqp_show_review_eligible($r,0),'round one cannot claim ordinary round one review');

// Manual story UI contract: a human host may award 7-10 without AI-style evidence.
$s=ckmqp_show_initial();$s['round']=3;$s['attempt']=0;$s['phase']='review';$s['storyResults'][0]=['vote'=>'truth'];$s['storyReviews'][0]=['status'=>'failed'];
$s['messages']=[
 ['id'=>1,'round'=>3,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Основная история.','at'=>1,'requestId'=>'story-main-1'],
 ['id'=>2,'round'=>3,'attempt'=>0,'slot'=>2,'teamId'=>102,'text'=>'Почему так?','at'=>2,'requestId'=>'story-question-1'],
 ['id'=>3,'round'=>3,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Потому что так было выгоднее.','at'=>3,'requestId'=>'story-answer-1'],
 ['id'=>4,'round'=>3,'attempt'=>0,'slot'=>2,'teamId'=>102,'text'=>'А потом?','at'=>4,'requestId'=>'story-question-2'],
 ['id'=>5,'round'=>3,'attempt'=>0,'slot'=>1,'teamId'=>101,'text'=>'Потом мы договорились.','at'=>5,'requestId'=>'story-answer-2'],
];
$storyRows=[];foreach(array_keys(ckmqp_show_story_review_criteria()) as $key)$storyRows[$key]=['points'=>10,'reason'=>'Ведущий непосредственно наблюдал и считает критерий полностью выполненным.','evidence'=>[]];
$manualStory=['participants'=>['speaker'=>['criteria'=>$storyRows,'summary'=>'Ручная оценка ведущего.','recommendation'=>'Продолжать.']],'summary'=>'Общий итог.'];
$r=ckmqp_show_reduce($s,$host,['command'=>'manual_review','round'=>3,'attempt'=>0,'review'=>$manualStory],100);
verify($r['ok'],'manual story UI payload accepts high scores without AI evidence');
verify(($r['session']['storyReviews'][0]['review']['speakerTotal']??0)===70,'manual story UI payload preserves 70-point total');

echo "ALL $n PASS\n";

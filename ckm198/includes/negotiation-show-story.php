<?php
if (!defined('ABSPATH')) exit;

/** Final round: a closed dossier, a short story, cross-examination and blind truth/distortion votes. */
function ckmqp_show_story_dossiers(array $s=[]): array {
    return ckmqp_show_content($s)['round4']['dossiers'];
}

function ckmqp_show_story_mode_pattern(int $now,int $revision=0): array {
    $patterns=[
        ['truth','distortion','truth'],
        ['distortion','truth','distortion'],
        ['truth','distortion','distortion'],
        ['distortion','truth','truth'],
    ];
    $raw=sprintf('%u',crc32($now.'|'.$revision.'|ckmqp-story-final'));
    return $patterns[((int)$raw)%count($patterns)];
}

function ckmqp_show_story_mode_label(string $mode): string {
    return $mode==='distortion'?'Есть существенное искажение':'Соответствует досье';
}

function ckmqp_show_story_opponent_slots(int $attempt,int $teamCount=3): array {
    $teamCount=$teamCount===2?2:3;$active=$attempt+1;return array_values(array_filter(range(1,$teamCount),static fn($slot)=>$slot!==$active));
}

function ckmqp_show_story_question_order(array $s,int $attempt): array {
    $out=[];$opp=ckmqp_show_story_opponent_slots($attempt,ckmqp_show_team_count($s));
    // Interleave opponents so one team cannot ask both of its questions before the other gets a turn.
    for($i=0;$i<2;$i++) foreach($opp as $slot){
        $q=$s['storyQuestions'][$attempt][$slot][$i]??null;
        if(is_array($q))$out[]=['slot'=>$slot,'localIndex'=>$i,'text'=>(string)($q['text']??''),'requestId'=>(string)($q['requestId']??'')];
    }
    return $out;
}

function ckmqp_show_story_rubric_messages(array $s,int $attempt): array {
    $speaker=$attempt+1;$story=$s['storyStories'][$attempt]??[];$out=[];$id=40000+$attempt*200;
    $out[]=['id'=>$id++,'round'=>0,'attempt'=>$attempt,'slot'=>$speaker,'teamId'=>0,'text'=>(string)($story['text']??''),'at'=>(int)($story['at']??0),'requestId'=>'story-main-'.$attempt];
    $ordered=ckmqp_show_story_question_order($s,$attempt);
    foreach($ordered as $idx=>$q){
        $slot=(int)($q['slot']??0);$teamCount=ckmqp_show_team_count($s);if($slot<1||$slot>$teamCount)$slot=($speaker%$teamCount)+1;
        $out[]=['id'=>$id++,'round'=>0,'attempt'=>$attempt,'slot'=>$slot,'teamId'=>0,'text'=>(string)($q['text']??''),'at'=>$idx*2+1,'requestId'=>'story-question-'.$attempt.'-'.$idx];
        $answer=$s['storyAnswers'][$attempt][$idx]??[];
        $out[]=['id'=>$id++,'round'=>0,'attempt'=>$attempt,'slot'=>$speaker,'teamId'=>0,'text'=>(string)($answer['text']??''),'at'=>$idx*2+2,'requestId'=>'story-answer-'.$attempt.'-'.$idx];
    }
    return $out;
}
function ckmqp_show_story_review_hash(array $s,int $attempt): string {
    return hash('sha256',json_encode([ckmqp_show_story_rubric_messages($s,$attempt),$s['storyModes'][$attempt]??'',ckmqp_show_story_dossiers($s)[$attempt]??[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function ckmqp_show_story_review_eligible(array $s,int $a): bool {
    return (int)($s['round']??0)===3 && $a>=0 && $a<=ckmqp_show_last_attempt($s) && is_array($s['storyResults'][$a]??null) && ($a<(int)$s['attempt'] || ($a===(int)$s['attempt'] && in_array((string)($s['phase']??''),['review','game_complete'],true)));
}
function ckmqp_show_story_review_status(array $s,int $a,int $now): string {
    $r=$s['storyReviews'][$a]??[];$status=$r['status']??'pending';return $status==='running' && (int)($r['expires']??0)<=$now?'pending':$status;
}
function ckmqp_show_story_review_reduce(array $s,array $actor,array $input,int $now): array {
    $fail=static fn($code,$error)=>['ok'=>false,'session'=>$s,'code'=>$code,'error'=>$error];
    if(($actor['role']??'')!=='system')return $fail('system_required','Недопустимое действие.');
    $a=(int)($input['attempt']??-1);if(!ckmqp_show_story_review_eligible($s,$a))return $fail('review_not_ready','Испытание ещё не завершено.');
    $r=$s['storyReviews'][$a]??[];
    if(($input['command']??'')==='story_review_claim'){
        if(($r['status']??'')==='done')return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if(ckmqp_show_story_review_status($s,$a,$now)==='running')return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if(($r['status']??'')==='failed'&&$now<(int)($r['retryAfter']??0))return $fail('retry_wait','Повторите оценку через несколько секунд.');
        $lease=(string)($input['lease']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$lease))return $fail('lease_invalid','Не удалось начать оценку.');
        $s['storyReviews'][$a]=['status'=>'running','lease'=>$lease,'expires'=>$now+75,'hash'=>ckmqp_show_story_review_hash($s,$a)];return ['ok'=>true,'session'=>$s,'claimed'=>true];
    }
    if(($r['status']??'')!=='running'||!hash_equals((string)$r['lease'],(string)($input['lease']??''))||$r['hash']!==ckmqp_show_story_review_hash($s,$a))return $fail('stale_review','Результат устарел. Повторите оценку.');
    $review=$input['review']??null;
    if(is_array($review)){
        $error=null;$validated=ckmqp_show_validate_speaker_only_review($review,ckmqp_show_story_rubric_messages($s,$a),$a,$error,ckmqp_show_team_count($s));
        if($validated!==null){$validated['displayRoles']=['speaker'];$validated['suppressWinner']=true;$validated['roundRubric']='story_seven_v2';$s['storyReviews'][$a]=['status'=>'done','review'=>$validated,'completedAt'=>$now];return ['ok'=>true,'session'=>$s];}
    }
    $s['storyReviews'][$a]=['status'=>'failed','error'=>(string)($input['error']??'Не удалось получить корректную оценку ИИ.'),'retryAfter'=>$now+15];return ['ok'=>true,'session'=>$s];
}
function ckmqp_show_ai_story_review(array $s,int $a): array {
    $tmp=$s;$tmp['messages']=ckmqp_show_story_rubric_messages($s,$a);$tmp['content']=ckmqp_show_content($s);$d=ckmqp_show_story_dossiers($s)[$a]??[];
    $mode=(string)($s['storyModes'][$a]??'truth');
    $modeInstruction=$mode==='distortion'
        ?('Режим distortion: рассказчик обязан выполнить ровно предписанное искажение: '.(string)($d['distortion']??''). ' Остальные существенные факты досье не следует произвольно менять.')
        :'Режим truth: рассказ должен соответствовать досье без существенных искажений.';
    $tmp['content']['round1']['cases'][$a]=[
        'situation'=>'Раунд «Проверь историю»: участник рассказывает историю и отвечает на перекрёстные вопросы. Закрытое досье: '.implode(' | ',array_map('strval',$d['facts']??[])).'. '.$modeInstruction.' Оценивай рассказчика только по специальной семикритериальной шкале этого раунда.',
        'speaker'=>'Рассказчик излагает назначенную версию и отвечает на проверочные вопросы.',
        'opponent'=>'Проверяющая команда задаёт вопросы и голосует; отдельные баллы за это испытание ей не начисляются.',
    ];
    $result=ckmqp_show_ai_review($tmp,$a,true);if(is_array($result['review']??null)){$result['review']['displayRoles']=['speaker'];$result['review']['suppressWinner']=true;$result['review']['roundRubric']='story_seven_v2';}return $result;
}
function ckmqp_show_run_story_review(array $game,int $a): array {
    $lease=wp_generate_uuid4();$claim=ckmqp_show_session($game,['role'=>'system'],['command'=>'story_review_claim','attempt'=>$a,'lease'=>$lease]);if(empty($claim['ok'])||empty($claim['claimed']))return $claim;
    try{$result=ckmqp_show_ai_story_review($claim['session'],$a);}catch(Throwable $e){$result=['error'=>'Не удалось завершить обращение к ИИ. Повторите оценку.'];}
    return ckmqp_show_session($game,['role'=>'system'],['command'=>'story_review_finish','attempt'=>$a,'lease'=>$lease,'review'=>$result['review']??null,'error'=>$result['error']??'Оценка не получена.']);
}

function ckmqp_show_story_result(array $s,int $attempt): ?array {
    $votes=$s['storyVotes'][$attempt]??[];$mode=(string)($s['storyModes'][$attempt]??'');
    $opp=ckmqp_show_story_opponent_slots($attempt,ckmqp_show_team_count($s));
    if(!in_array($mode,['truth','distortion'],true) || count($votes)<count($opp)) return null;
    foreach($opp as $slot) if(!isset($votes[$slot])) return null;
    $storyteller=$attempt+1;$voteRows=[];
    foreach($opp as $slot){
        $vote=(string)($votes[$slot]['vote']??'');$correct=$vote===$mode;
        $voteRows[]=['slot'=>$slot,'vote'=>$vote,'correct'=>$correct];
    }
    return ['attempt'=>$attempt,'storytellerSlot'=>$storyteller,'mode'=>$mode,'modeLabel'=>ckmqp_show_story_mode_label($mode),'votes'=>$voteRows];
}

function ckmqp_show_story_round_score(array $s,int $slot): ?int {
    if($slot<1||$slot>ckmqp_show_team_count($s))return null;$r=$s['storyReviews'][$slot-1]??[];if(($r['status']??'')!=='done')return null;$review=$r['review']??[];
    if(is_array($review['participants']??null))return (int)($review['participants']['speaker']['total']??0);
    return isset($review['total'])?(int)$review['total']:null;
}

function ckmqp_show_story_all_results_done(array $s): bool {
    foreach(ckmqp_show_attempt_indexes($s) as $a) if(!is_array($s['storyResults'][$a]??null)) return false;
    return true;
}

function ckmqp_show_story_all_reviews_done(array $s): bool {
    foreach(ckmqp_show_attempt_indexes($s) as $a)if(($s['storyReviews'][$a]['status']??'')!=='done')return false;return true;
}

function ckmqp_show_story_cumulative_score(array $s,int $slot): int {
    return (int)(ckmqp_show_first_round_score($s,$slot)??0)+(int)(ckmqp_show_hidden_round_score($s,$slot)??0)+(int)(ckmqp_show_hard_round_score($s,$slot)??0)+(int)(ckmqp_show_story_round_score($s,$slot)??0);
}

function ckmqp_show_story_project(array $out,array $s,array $teams,array $auth): array {
    $show=&$out['negotiationShow'];$a=(int)$s['attempt'];$active=$a+1;$dossiers=ckmqp_show_story_dossiers($s);$dossier=$dossiers[$a]??[];$now=time();
    $namesBySlot=[];foreach($teams as $t)$namesBySlot[(int)$t['slot_no']]=(string)$t['team_name'];
    $mineSlot=0;if(($auth['role']??'')==='participant')foreach($teams as $t)if((int)$t['id']===(int)($auth['team_id']??0)){$mineSlot=(int)$t['slot_no'];break;}
    $result=$s['storyResults'][$a]??null;$mode=(string)($s['storyModes'][$a]??'truth');
    $show['storyQuestionIndex']=(int)($s['storyQuestionIndex']??0);$show['storyQuestionsTotal']=2*count(ckmqp_show_story_opponent_slots($a,ckmqp_show_team_count($s)));$show['storyVoteCount']=count($s['storyVotes'][$a]??[]);
    $story=$s['storyStories'][$a]??null;$show['storyText']=is_array($story)?(string)($story['text']??''):'';$show['storyTimedOut']=is_array($story)&&!empty($story['timedOut']);
    $ordered=ckmqp_show_story_question_order($s,$a);if(in_array((string)($s['phase']??''),['story_vote','review','game_complete'],true))$show['storyQuestionsTotal']=count($ordered);$show['storyQuestions']=[];
    foreach($ordered as $idx=>$q){$ans=$s['storyAnswers'][$a][$idx]??null;$show['storyQuestions'][]=['index'=>$idx,'fromSlot'=>(int)$q['slot'],'fromName'=>$namesBySlot[(int)$q['slot']]??('Команда '.$q['slot']),'text'=>(string)$q['text'],'answer'=>is_array($ans)?(string)($ans['text']??''):'','timedOut'=>is_array($ans)&&!empty($ans['timedOut']),'endedEarly'=>is_array($ans)&&!empty($ans['endedEarly'])];}
    $myAsked=$mineSlot?(int)count($s['storyQuestions'][$a][$mineSlot]??[]):0;$show['storyQuestionsAskedByMe']=$myAsked;$show['canVote']=$mineSlot>0&&$mineSlot!==$active&&$s['phase']==='story_vote'&&!isset($s['storyVotes'][$a][$mineSlot]);$show['hasVoted']=$mineSlot>0&&isset($s['storyVotes'][$a][$mineSlot]);$show['storyResult']=is_array($result)?$result:null;
    if(is_array($result)){
        $show['storyReveal']=['title'=>(string)($dossier['title']??''),'facts'=>$dossier['facts']??[],'mode'=>$mode,'modeLabel'=>ckmqp_show_story_mode_label($mode),'distortion'=>(string)($dossier['distortion']??'')];
        $show['storyRevealedVotes']=[];foreach($result['votes'] as $v)$show['storyRevealedVotes'][]=['name'=>$namesBySlot[(int)$v['slot']]??('Команда '.$v['slot']),'voteLabel'=>ckmqp_show_story_mode_label((string)$v['vote']),'correct'=>!empty($v['correct'])];
    }
    $show['reviews']=[];$show['reviewAttempt']=null;$show['reviewStatus']='none';$allDone=true;
    foreach(ckmqp_show_attempt_indexes($s) as $i){
        $status=ckmqp_show_story_review_eligible($s,$i)?ckmqp_show_story_review_status($s,$i,$now):'not_started';$rr=$s['storyReviews'][$i]??[];$item=['attempt'=>$i,'status'=>$status,'kind'=>'story-seven'];
        if($status==='done')$item['result']=$rr['review'];if($status==='failed')$item['error']=$rr['error'];$show['reviews'][]=$item;if($status!=='done')$allDone=false;
        if($show['reviewAttempt']===null&&in_array($status,['pending','running','failed'],true)){$show['reviewAttempt']=$i;$show['reviewStatus']=$status;$show['canRequestReview']=$status==='pending'||($status==='failed'&&$now>=(int)($rr['retryAfter']??0));}
    }
    $authorized=(int)($auth['game_id']??0)===(int)$out['game']['id'] && (($auth['role']??'')==='host'||(($auth['role']??'')==='participant'&&in_array((int)($auth['team_id']??0),array_map(static fn($t)=>(int)$t['id'],$teams),true)));
    $show['canRequestReview']=$authorized&&!empty($show['canRequestReview']);$show['autoRequestReview']=$show['canRequestReview']&&$show['reviewStatus']==='pending';$show['allReviewsDone']=$allDone;
    $show['scores']=[];foreach($teams as $t){$slot=(int)$t['slot_no'];$r1=ckmqp_show_first_round_score($s,$slot);$r2=ckmqp_show_hidden_round_score($s,$slot);$r3=ckmqp_show_hard_round_score($s,$slot);$r4=ckmqp_show_story_round_score($s,$slot);$pending=in_array(null,[$r1,$r2,$r3,$r4],true);$show['scores'][]=['teamId'=>(int)$t['id'],'name'=>$t['team_name'],'score'=>($r1??0)+($r2??0)+($r3??0)+($r4??0),'round1'=>$r1,'round2'=>$r2,'round3'=>$r3,'round4'=>$r4,'pending'=>$pending];}
    foreach($out['teams'] as &$teamState)foreach($show['scores'] as $row)if((int)$teamState['id']===$row['teamId']){$teamState['score']=$row['score'];$teamState['scorePending']=!empty($row['pending']);break;}unset($teamState);
    $show['winners']=[];
    if($s['phase']==='game_complete'&&$allDone&&$show['scores']){$max=max(array_column($show['scores'],'score'));foreach($show['scores'] as $row)if((int)$row['score']===$max)$show['winners'][]=$row['name'];$show['winningScore']=$max;$show['winnerMarginRule']=0;}
    return $out;
}

function ckmqp_show_finalize_game(array $game,array $s): bool {
    if(($s['phase']??'')!=='game_complete' || !ckmqp_show_story_all_results_done($s) || !ckmqp_show_story_all_reviews_done($s)) return false;
    global $wpdb;$gid=(int)($game['id']??0);if($gid<1)return false;
    $teamTable=ckm_quiz_teams_table();$gameTable=ckm_quiz_games_table();$now=current_time('mysql');
    $scoreRows=[];$winnerNames=[];$winningScore=0;$shouldAnnounce=false;
    try{
        if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('begin');
        $teams=$wpdb->get_results($wpdb->prepare("SELECT id,slot_no,team_name FROM {$teamTable} WHERE game_id=%d ORDER BY slot_no ASC FOR UPDATE",$gid),ARRAY_A)?:[];
        if(count($teams)!==ckmqp_show_team_count($s))throw new RuntimeException('teams');
        $scores=[];
        foreach($teams as $t){
            $slot=(int)$t['slot_no'];$score=ckmqp_show_story_cumulative_score($s,$slot);$scores[$slot]=$score;
            $scoreRows[]=['slot'=>$slot,'name'=>(string)$t['team_name'],'score'=>$score];
            $summary='Переговори другого: итог '.$score.' из 280 баллов.';
            $ok=$wpdb->update($teamTable,['score'=>$score,'final_score'=>$score,'final_summary'=>$summary,'updated_at'=>$now],['id'=>(int)$t['id']]);
            if($ok===false)throw new RuntimeException('team_update');
        }
        $winningScore=max($scores);$winnerSlots=[];
        foreach($scores as $slot=>$score)if($score===$winningScore)$winnerSlots[]=$slot;
        foreach($scoreRows as $row)if(in_array((int)$row['slot'],$winnerSlots,true))$winnerNames[]=(string)$row['name'];
        $existing=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$gameTable} WHERE id=%d FOR UPDATE",$gid));
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$gameTable} SET status='finished',quiz_phase='finished',round_phase='finished',question_deadline_at=NULL,is_paused=0,finished_at=COALESCE(finished_at,%s),state_version=state_version+1,updated_at=%s WHERE id=%d",$now,$now,$gid));
        if($ok===false)throw new RuntimeException('game_update');
        if($wpdb->query('COMMIT')===false)throw new RuntimeException('commit');
        $shouldAnnounce=$existing!=='finished';
        if($shouldAnnounce&&function_exists('ckm_quiz_append_event'))@ckm_quiz_append_event(
            $gid,'negotiation_show_finished','system',0,0,0,'game',$gid,
            ['scores'=>$scores,'winnerSlots'=>$winnerSlots,'winners'=>$winnerNames,'winningScore'=>$winningScore],
            'negotiation-show-finished-'.$gid
        );
    }catch(Throwable $e){
        $wpdb->query('ROLLBACK');error_log('CKM negotiation show finalize: '.$e->getMessage());return false;
    }
    if($shouldAnnounce&&function_exists('ckm_quiz_publish_ai_host_event')){
        @ckm_quiz_publish_ai_host_event(
            $gid,'negotiation_show_finished',null,
            ['showScores'=>$scoreRows,'winners'=>$winnerNames,'winningScore'=>$winningScore],
            'ai-negotiation-show-finished-'.$gid
        );
    }
    return true;
}

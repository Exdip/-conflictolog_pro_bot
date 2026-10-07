<?php
if (!defined('ABSPATH')) exit;

function ckmqp_show_hard_review_hash(array $s,int $attempt): string {
    return hash('sha256',json_encode([ckmqp_show_hard_questions($s)[$attempt]??[], $s['hardAnswers'][$attempt]??[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function ckmqp_show_hard_review_eligible(array $s,int $a): bool {
    return (int)($s['round']??0)===2 && $a>=0 && $a<=ckmqp_show_last_attempt($s) && ($a<(int)$s['attempt'] || ($a===(int)$s['attempt'] && in_array($s['phase'],['review','round3_complete'],true)));
}
function ckmqp_show_hard_review_status(array $s,int $a,int $now): string {
    $r=$s['hardReviews'][$a]??[];$status=$r['status']??'pending';
    return $status==='running' && (int)($r['expires']??0)<=$now ? 'pending' : $status;
}
function ckmqp_show_hard_review_reduce(array $s,array $actor,array $input,int $now): array {
    $fail=static fn($code,$error)=>['ok'=>false,'session'=>$s,'code'=>$code,'error'=>$error];
    if (($actor['role']??'')!=='system') return $fail('system_required','Недопустимое действие.');
    $a=(int)($input['attempt']??-1);
    if (!ckmqp_show_hard_review_eligible($s,$a)) return $fail('review_not_ready','Ответы ещё не завершены.');
    $r=$s['hardReviews'][$a]??[];
    if (($input['command']??'')==='hard_review_claim') {
        if (($r['status']??'')==='done') return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if (ckmqp_show_hard_review_status($s,$a,$now)==='running') return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if (($r['status']??'')==='failed' && $now<(int)($r['retryAfter']??0)) return $fail('retry_wait','Повторите оценку через несколько секунд.');
        $lease=(string)($input['lease']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$lease)) return $fail('lease_invalid','Не удалось начать оценку.');
        $s['hardReviews'][$a]=['status'=>'running','lease'=>$lease,'expires'=>$now+75,'hash'=>ckmqp_show_hard_review_hash($s,$a)];
        return ['ok'=>true,'session'=>$s,'claimed'=>true];
    }
    if (($r['status']??'')!=='running' || !hash_equals((string)$r['lease'],(string)($input['lease']??'')) || $r['hash']!==ckmqp_show_hard_review_hash($s,$a)) return $fail('stale_review','Результат устарел. Повторите оценку.');
    $review=$input['review']??null;
    if (is_array($review)) {
        $validated=ckmqp_show_validate_hard_review($review,$s,$a);
        if ($validated!==null) {$s['hardReviews'][$a]=['status'=>'done','review'=>$validated,'completedAt'=>$now];return ['ok'=>true,'session'=>$s];}
    }
    $s['hardReviews'][$a]=['status'=>'failed','error'=>(string)($input['error']??'Не удалось получить корректную оценку ИИ.'),'retryAfter'=>$now+15];
    return ['ok'=>true,'session'=>$s];
}

function ckmqp_show_hard_rubric_messages(array $s,int $attempt): array {
    $speaker=$attempt+1;$teamCount=ckmqp_show_team_count($s);$opponent=($speaker%$teamCount)+1;$questions=ckmqp_show_hard_questions($s)[$attempt]??[];$answers=$s['hardAnswers'][$attempt]??[];$out=[];$id=30000+$attempt*100;
    for($i=0;$i<3;$i++){
        $out[]=['id'=>$id++,'round'=>0,'attempt'=>$attempt,'slot'=>$opponent,'teamId'=>0,'text'=>(string)($questions[$i]??''),'at'=>$i*2,'requestId'=>'hard-question-'.$attempt.'-'.$i];
        $out[]=['id'=>$id++,'round'=>0,'attempt'=>$attempt,'slot'=>$speaker,'teamId'=>0,'text'=>(string)($answers[$i]['text']??''),'at'=>$i*2+1,'requestId'=>'hard-answer-'.$attempt.'-'.$i];
    }
    return $out;
}

function ckmqp_show_validate_hard_review(array $raw,array $s,int $attempt,?string &$error=null): ?array {
    $review=ckmqp_show_validate_regular_speaker_only_review($raw,ckmqp_show_hard_rubric_messages($s,$attempt),$attempt,$error,ckmqp_show_team_count($s));
    if($review!==null){$review['displayRoles']=['speaker'];$review['suppressWinner']=true;$review['roundRubric']='seven_criteria';}
    return $review;
}

/** Normalize harmless representation differences in the hard-question AI contract.
 * Never invent evidence and never change the semantic score.
 */
function ckmqp_show_normalize_hard_ai_review(array $raw,array $s,int $attempt): array {
    return ckmqp_show_normalize_ai_review($raw,ckmqp_show_hard_rubric_messages($s,$attempt));
}


function ckmqp_show_hard_timeout_zero_review(array $s,int $attempt): ?array {
    return null; // The same seven-criterion arbiter handles empty/timed-out answers and assigns 0 where behavior is absent.
}

function ckmqp_show_ai_hard_review(array $s,int $a): array {
    $tmp=$s;$tmp['messages']=ckmqp_show_hard_rubric_messages($s,$a);$tmp['content']=ckmqp_show_content($s);
    $questions=ckmqp_show_hard_questions($s)[$a]??[];
    $tmp['content']['round1']['cases'][$a]=[
        'situation'=>'Раунд «Неудобный вопрос». Оппонент последовательно задаёт три сложных вопроса: '.implode(' | ',array_map('strval',$questions)).'. Оцени только переговорное поведение отвечающей команды по тем же 7 критериям. Если для критерия нет проявления в ответах, ставь 0; не компенсируй отсутствие поведения красноречием.',
        'speaker'=>'Команда отвечает на три неудобных вопроса.',
        'opponent'=>'Системный собеседник задаёт вопросы; его результат в игровой счёт не входит.',
    ];
    $result=ckmqp_show_ai_review($tmp,$a,true,'regular');
    if(is_array($result['review']??null)){$result['review']['displayRoles']=['speaker'];$result['review']['suppressWinner']=true;$result['review']['roundRubric']='seven_criteria';}
    return $result;
}
function ckmqp_show_run_hard_review(array $game,int $a): array {
    $lease=wp_generate_uuid4();$claim=ckmqp_show_session($game,['role'=>'system'],['command'=>'hard_review_claim','attempt'=>$a,'lease'=>$lease]);if(empty($claim['ok'])||empty($claim['claimed']))return $claim;
    try{$result=ckmqp_show_ai_hard_review($claim['session'],$a);}catch(Throwable $e){$result=['error'=>'Не удалось завершить обращение к ИИ. Повторите оценку.'];}
    return ckmqp_show_session($game,['role'=>'system'],['command'=>'hard_review_finish','attempt'=>$a,'lease'=>$lease,'review'=>$result['review']??null,'error'=>$result['error']??'Оценка не получена.']);
}
function ckmqp_show_hard_round_score(array $s,int $slot): ?int {
    $r=$s['hardReviews'][$slot-1]??[];if(($r['status']??'')!=='done')return null;$review=$r['review']??[];
    if(is_array($review['participants']??null))return (int)($review['participants']['speaker']['total']??0);
    return isset($review['total'])?(int)$review['total']:null;
}
function ckmqp_show_all_hard_reviews_done(array $s): bool { foreach(ckmqp_show_attempt_indexes($s) as $a)if(($s['hardReviews'][$a]['status']??'')!=='done')return false;return true; }
function ckmqp_show_project_hard_reviews(array $out,array $s,array $teams,array $auth): array {
    $now=time();$show=&$out['negotiationShow'];$show['reviews']=[];$show['scores']=[];$show['reviewAttempt']=null;$show['reviewStatus']='none';$allDone=true;
    foreach(ckmqp_show_attempt_indexes($s) as $a){$status=ckmqp_show_hard_review_eligible($s,$a)?ckmqp_show_hard_review_status($s,$a,$now):'not_started';$r=$s['hardReviews'][$a]??[];$item=['attempt'=>$a,'status'=>$status,'kind'=>'hard'];if($status==='done')$item['result']=$r['review'];if($status==='failed')$item['error']=$r['error'];$show['reviews'][]=$item;if($status!=='done')$allDone=false;if($show['reviewAttempt']===null&&in_array($status,['pending','running','failed'],true)){$show['reviewAttempt']=$a;$show['reviewStatus']=$status;$show['canRequestReview']=$status==='pending'||($status==='failed'&&$now>=(int)($r['retryAfter']??0));}}
    $authorized=(int)($auth['game_id']??0)===(int)$out['game']['id'] && (($auth['role']??'')==='host'||(($auth['role']??'')==='participant'&&in_array((int)($auth['team_id']??0),array_map(static fn($t)=>(int)$t['id'],$teams),true)));$show['canRequestReview']=$authorized&&!empty($show['canRequestReview']);$show['autoRequestReview']=$show['canRequestReview']&&$show['reviewStatus']==='pending';
    foreach($teams as $t){$slot=(int)$t['slot_no'];$r1=ckmqp_show_first_round_score($s,$slot);$r2=ckmqp_show_hidden_round_score($s,$slot);$r3=ckmqp_show_hard_round_score($s,$slot);$show['scores'][]=['teamId'=>(int)$t['id'],'name'=>$t['team_name'],'score'=>($r1??0)+($r2??0)+($r3??0),'round1'=>$r1,'round2'=>$r2,'round3'=>$r3];}
    foreach($out['teams'] as &$teamState)foreach($show['scores'] as $row)if((int)$teamState['id']===$row['teamId']){$teamState['score']=$row['score'];$teamState['scorePending']=$row['round3']===null;break;}unset($teamState);
    $show['canContinue']=!empty($show['canContinue'])&&ckmqp_show_current_review_done($s);$show['allReviewsDone']=$allDone;$show['winners']=[];
    return $out;
}

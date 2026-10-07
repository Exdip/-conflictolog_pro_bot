<?php
if (!defined('ABSPATH')) exit;

function ckmqp_show_hidden_review_messages(array $s,int $attempt): array {
    return array_values(array_filter($s['messages'],static fn($m)=>(int)($m['round']??0)===1 && (int)$m['attempt']===$attempt));
}
function ckmqp_show_hidden_review_hash(array $s,int $attempt): string {
    return hash('sha256',json_encode([ckmqp_show_hidden_tasks($s)[$attempt],ckmqp_show_hidden_review_messages($s,$attempt)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}
function ckmqp_show_hidden_review_eligible(array $s,int $a): bool {
    return (int)($s['round']??0)===1 && $a>=0 && $a<=ckmqp_show_last_attempt($s) && ($a<(int)$s['attempt'] || ($a===(int)$s['attempt'] && in_array($s['phase'],['review','round2_complete'],true)));
}
function ckmqp_show_hidden_review_status(array $s,int $a,int $now): string {
    $r=$s['hiddenReviews'][$a]??[];$status=$r['status']??'pending';
    return $status==='running' && (int)($r['expires']??0)<=$now ? 'pending' : $status;
}
function ckmqp_show_hidden_review_reduce(array $s,array $actor,array $input,int $now): array {
    $fail=static fn($code,$error)=>['ok'=>false,'session'=>$s,'code'=>$code,'error'=>$error];
    if (($actor['role']??'')!=='system') return $fail('system_required','Недопустимое действие.');
    $a=(int)($input['attempt']??-1);
    if (!ckmqp_show_hidden_review_eligible($s,$a)) return $fail('review_not_ready','Диалог ещё не завершён.');
    $r=$s['hiddenReviews'][$a]??[];
    if ($input['command']==='hidden_review_claim') {
        if (($r['status']??'')==='done') return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if (ckmqp_show_hidden_review_status($s,$a,$now)==='running') return ['ok'=>true,'session'=>$s,'claimed'=>false];
        if (($r['status']??'')==='failed' && $now<(int)($r['retryAfter']??0)) return $fail('retry_wait','Повторите оценку через несколько секунд.');
        $lease=(string)($input['lease']??'');
        if (!preg_match('/^[a-zA-Z0-9-]{16,80}$/D',$lease)) return $fail('lease_invalid','Не удалось начать оценку.');
        $s['hiddenReviews'][$a]=['status'=>'running','lease'=>$lease,'expires'=>$now+75,'hash'=>ckmqp_show_hidden_review_hash($s,$a)];
        return ['ok'=>true,'session'=>$s,'claimed'=>true];
    }
    if (($r['status']??'')!=='running' || !hash_equals((string)$r['lease'],(string)($input['lease']??'')) || $r['hash']!==ckmqp_show_hidden_review_hash($s,$a)) return $fail('stale_review','Результат устарел. Повторите оценку.');
    $review=$input['review']??null;
    if (is_array($review)) {
        $validated=ckmqp_show_validate_hidden_review($review,ckmqp_show_hidden_review_messages($s,$a),$a,$s);
        if ($validated!==null) {
            $s['hiddenReviews'][$a]=['status'=>'done','review'=>$validated,'completedAt'=>$now];
            return ['ok'=>true,'session'=>$s];
        }
    }
    $s['hiddenReviews'][$a]=['status'=>'failed','error'=>(string)($input['error']??'Не удалось получить корректную оценку ИИ.'),'retryAfter'=>$now+15];
    return ['ok'=>true,'session'=>$s];
}

function ckmqp_show_validate_hidden_review(array $raw,array $messages,int $attempt,array $s=[],?string &$error=null): ?array {
    return ckmqp_show_validate_review($raw,$messages,$attempt,$error,ckmqp_show_team_count($s));
}

/** Normalize harmless representation differences in the hidden-task AI contract.
 * Never invent evidence and never change the semantic score.
 */
function ckmqp_show_normalize_hidden_ai_review(array $raw,array $messages): array {
    return ckmqp_show_normalize_ai_review($raw,$messages);
}

function ckmqp_show_ai_hidden_review(array $s,int $a): array {
    $messages=ckmqp_show_hidden_review_messages($s,$a);
    $mapped=[];
    foreach($messages as $m){$m['round']=0;$mapped[]=$m;}
    $tmp=$s;$tmp['messages']=$mapped;$tmp['content']=ckmqp_show_content($s);
    $tasks=ckmqp_show_hidden_tasks($s)[$a]??[];
    $tmp['content']['round1']['cases'][$a]=[
        'situation'=>ckmqp_show_hidden_situation($s).' Скрытые игровые задачи активной стороны: '.implode(' | ',array_map('strval',$tasks)).'. Оцени переговорное поведение по единой семикритериальной рубрике; выполнение скрытой задачи само по себе не даёт отдельных очков.',
        'speaker'=>'Активная сторона ведёт разговор и старается выполнить закрытые задачи, не называя их напрямую.',
        'opponent'=>(string)(ckmqp_show_content($s)['round2']['partnerBrief']??'Собеседник реагирует естественно на предложения активной стороны.'),
    ];
    return ckmqp_show_ai_review($tmp,$a);
}

function ckmqp_show_run_hidden_review(array $game,int $a): array {
    $lease=wp_generate_uuid4();
    $claim=ckmqp_show_session($game,['role'=>'system'],['command'=>'hidden_review_claim','attempt'=>$a,'lease'=>$lease]);
    if (empty($claim['ok'])||empty($claim['claimed'])) return $claim;
    try{$result=ckmqp_show_ai_hidden_review($claim['session'],$a);}catch(Throwable $e){$result=['error'=>'Не удалось завершить обращение к ИИ. Повторите оценку.'];}
    return ckmqp_show_session($game,['role'=>'system'],['command'=>'hidden_review_finish','attempt'=>$a,'lease'=>$lease,'review'=>$result['review']??null,'error'=>$result['error']??'Оценка не получена.']);
}

function ckmqp_show_first_round_score(array $s,int $slot): ?int {
    return function_exists('ckmqp_show_round1_team_score')?ckmqp_show_round1_team_score($s,$slot):null;
}
function ckmqp_show_hidden_round_score(array $s,int $slot): ?int {
    return ckmqp_show_pairwise_team_score($s,'hiddenReviews',$slot);
}
function ckmqp_show_all_first_reviews_done(array $s): bool {
    foreach(ckmqp_show_attempt_indexes($s) as $a)if(($s['reviews'][$a]['status']??'')!=='done')return false;return true;
}
function ckmqp_show_project_hidden_reviews(array $out,array $s,array $teams,array $auth): array {
    $now=time();$show=&$out['negotiationShow'];$show['reviews']=[];$show['scores']=[];$show['reviewAttempt']=null;$show['reviewStatus']='none';
    $allDone=true;
    foreach(ckmqp_show_attempt_indexes($s) as $a){
        $status=ckmqp_show_hidden_review_eligible($s,$a)?ckmqp_show_hidden_review_status($s,$a,$now):'not_started';$r=$s['hiddenReviews'][$a]??[];
        $item=['attempt'=>$a,'status'=>$status,'kind'=>'hidden'];if($status==='done')$item['result']=$r['review'];if($status==='failed')$item['error']=$r['error'];$show['reviews'][]=$item;
        if($status!=='done')$allDone=false;
        if($show['reviewAttempt']===null&&in_array($status,['pending','running','failed'],true)){$show['reviewAttempt']=$a;$show['reviewStatus']=$status;$show['canRequestReview']=$status==='pending'||($status==='failed'&&$now>=(int)($r['retryAfter']??0));}
    }
    $authorized=(int)($auth['game_id']??0)===(int)$out['game']['id'] && (($auth['role']??'')==='host'||(($auth['role']??'')==='participant'&&in_array((int)($auth['team_id']??0),array_map(static fn($t)=>(int)$t['id'],$teams),true)));
    $show['canRequestReview']=$authorized&&!empty($show['canRequestReview']);$show['autoRequestReview']=$show['canRequestReview']&&$show['reviewStatus']==='pending';
    foreach($teams as $t){$slot=(int)$t['slot_no'];$r1=ckmqp_show_first_round_score($s,$slot);$r2=ckmqp_show_hidden_round_score($s,$slot);$total=($r1??0)+($r2??0);$show['scores'][]=['teamId'=>(int)$t['id'],'name'=>$t['team_name'],'score'=>$total,'round1'=>$r1,'round2'=>$r2];}
    foreach($out['teams'] as &$teamState)foreach($show['scores'] as $row)if((int)$teamState['id']===$row['teamId']){$teamState['score']=$row['score'];$teamState['scorePending']=$row['round2']===null;break;}unset($teamState);
    $show['canContinue']=!empty($show['canContinue'])&&ckmqp_show_current_review_done($s);$show['allReviewsDone']=$allDone;$show['winners']=[];
    if($s['phase']==='round2_complete'&&$allDone){$max=max(array_column($show['scores'],'score'));foreach($show['scores'] as $row)if($row['score']===$max)$show['winners'][]=$row['name'];}
    return $out;
}

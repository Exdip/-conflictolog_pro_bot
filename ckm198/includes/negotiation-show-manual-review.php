<?php
if (!defined('ABSPATH')) exit;

/**
 * Manual recovery is available only after the AI review failed or its lease
 * expired. It never competes with a successful AI result and never overwrites
 * a completed review.
 */
function ckmqp_show_manual_review_available(array $s,int $now): bool {
    $round=(int)($s['round']??-1);
    $attempt=(int)($s['attempt']??-1);
    $bucket=null;$eligible=false;$status='';$entry=[];

    if($round===0 && function_exists('ckmqp_show_review_eligible') && ckmqp_show_review_eligible($s,$attempt)){
        $bucket='reviews';
        $eligible=true;
        $status=function_exists('ckmqp_show_review_status')?ckmqp_show_review_status($s,$attempt,$now):'';
    }elseif($round===1 && function_exists('ckmqp_show_hidden_review_eligible') && ckmqp_show_hidden_review_eligible($s,$attempt)){
        $bucket='hiddenReviews';
        $eligible=true;
        $status=function_exists('ckmqp_show_hidden_review_status')?ckmqp_show_hidden_review_status($s,$attempt,$now):'';
    }elseif($round===2 && function_exists('ckmqp_show_hard_review_eligible') && ckmqp_show_hard_review_eligible($s,$attempt)){
        $bucket='hardReviews';
        $eligible=true;
        $status=function_exists('ckmqp_show_hard_review_status')?ckmqp_show_hard_review_status($s,$attempt,$now):'';
    }elseif($round===3 && function_exists('ckmqp_show_story_review_eligible') && ckmqp_show_story_review_eligible($s,$attempt)){
        $bucket='storyReviews';
        $eligible=true;
        $status=function_exists('ckmqp_show_story_review_status')?ckmqp_show_story_review_status($s,$attempt,$now):'';
    }

    if(!$eligible || !$bucket)return false;
    $entry=is_array($s[$bucket][$attempt]??null)?$s[$bucket][$attempt]:[];
    if(($entry['status']??'')==='done')return false;

    // A failed AI review is immediately recoverable. A running review becomes
    // recoverable only when its server-side lease has expired.
    if($status==='failed')return true;
    if(($entry['status']??'')==='running' && (int)($entry['expires']??0)>0 && (int)$entry['expires']<=$now)return true;
    return false;
}

/** Build and validate one manual review using the exact server contracts used by AI review. */
function ckmqp_show_manual_review_validate(array $s,array $raw,int $round,int $attempt,?string &$error=null): ?array {
    $error=null;
    $teamCount=ckmqp_show_team_count($s);

    if($round===0){
        $messages=ckmqp_show_review_messages($s,$attempt);
        $validated=ckmqp_show_validate_review($raw,$messages,$attempt,$error,$teamCount);
        if($validated!==null){
            $validated['source']='manual';
            return $validated;
        }
        return null;
    }

    if($round===1){
        $messages=ckmqp_show_hidden_review_messages($s,$attempt);
        $validated=ckmqp_show_validate_hidden_review($raw,$messages,$attempt,$s,$error);
        if($validated!==null){
            $validated['source']='manual';
            return $validated;
        }
        return null;
    }

    if($round===2){
        $validated=ckmqp_show_validate_hard_review($raw,$s,$attempt,$error);
        if($validated!==null){
            $validated['source']='manual';
            return $validated;
        }
        return null;
    }

    if($round===3){
        $validated=ckmqp_show_validate_speaker_only_review($raw,ckmqp_show_story_rubric_messages($s,$attempt),$attempt,$error,$teamCount,true);
        if($validated!==null){
            $validated['displayRoles']=['speaker'];
            $validated['suppressWinner']=true;
            $validated['roundRubric']='story_seven_v2';
            $validated['source']='manual';
            return $validated;
        }
        return null;
    }

    $error='Неизвестный раунд ручной оценки.';
    return null;
}

/** Called inside the session row lock. A completed result is never overwritten. */
function ckmqp_show_manual_review_reduce(array $s,array $actor,array $input,int $now): array {
    $fail=static fn($code,$error)=>['ok'=>false,'session'=>$s,'code'=>$code,'error'=>$error];
    if(($actor['role']??'')!=='host')return $fail('host_required','Ручная оценка доступна только ведущему.');

    $round=(int)($s['round']??-1);
    $attempt=(int)($s['attempt']??-1);
    if(!isset($input['round'],$input['attempt']) || (int)$input['round']!==$round || (int)$input['attempt']!==$attempt)return $fail('stale_attempt','Этап изменился. Обновите комнату.');
    if(!ckmqp_show_manual_review_available($s,$now))return $fail('manual_unavailable','Ручная оценка доступна после ошибки или истечения времени ИИ-оценки.');

    if(isset($input['points']) || isset($input['score0']) || isset($input['score1']) || isset($input['score2']) || isset($input['text']))return $fail('legacy_review_rejected','Старая трёхбалльная форма ручной оценки больше не поддерживается.');
    $raw=$input['review']??null;
    if(!is_array($raw))return $fail('review_invalid','Не передана корректная ручная оценка.');
    if(isset($raw['points']) || isset($raw['score0']) || isset($raw['score1']) || isset($raw['score2']))return $fail('legacy_review_rejected','Старая трёхбалльная форма ручной оценки больше не поддерживается.');

    $error=null;
    $validated=ckmqp_show_manual_review_validate($s,$raw,$round,$attempt,$error);
    if($validated===null)return $fail('review_invalid',$error?:'Не удалось проверить ручную оценку.');

    $bucket=[0=>'reviews',1=>'hiddenReviews',2=>'hardReviews',3=>'storyReviews'][$round]??null;
    if($bucket===null)return $fail('round_invalid','Неизвестный раунд.');
    $entry=is_array($s[$bucket][$attempt]??null)?$s[$bucket][$attempt]:[];
    if(($entry['status']??'')==='done')return $fail('review_already_done','Оценка уже сохранена.');

    $s[$bucket][$attempt]=[
        'status'=>'done',
        'review'=>$validated,
        'completedAt'=>$now,
        'source'=>'manual',
        'actorRole'=>'host',
    ];
    return ['ok'=>true,'session'=>$s];
}

<?php
if (!defined('ABSPATH')) exit;

function ckmqp_show_table(): string { global $wpdb; return $wpdb->prefix.'ckm_quiz_show_sessions'; }
function ckmqp_show_install_dialogue(): void {
    if (get_option('ckmqp_show_dialogue_schema') === '1') return;
    if (!function_exists('ckmqp_content_ready') || !ckmqp_content_ready()) return;
    global $wpdb;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $table=ckmqp_show_table(); $collate=$wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        game_id bigint unsigned NOT NULL,
        tenant_id bigint unsigned NOT NULL DEFAULT 0,
        state_json longtext NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (game_id),
        KEY tenant_id (tenant_id)
    ) ENGINE=InnoDB {$collate};");
    $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table),ARRAY_A);
    if ($engine && strtolower((string)$engine['Engine'])==='innodb') update_option('ckmqp_show_dialogue_schema','1',false);
}
add_action('init','ckmqp_show_install_dialogue',21);

function ckmqp_show_dialogue_limit_from_game(array $game): int {
    $raw=(string)($game['format_settings_snapshot_json']??($game['format_settings_json']??''));
    $settings=$raw!==''?json_decode($raw,true):[];
    if(!is_array($settings)||empty($settings['persuadeDialogueLimitEnabled'])) return 0;
    $seconds=(int)($settings['persuadeDialogueLimitSeconds']??180);
    return max(60,min(600,$seconds));
}
function ckmqp_show_hidden_message_limit_from_game(array $game): int {
    $raw=(string)($game['format_settings_snapshot_json']??($game['format_settings_json']??''));
    $settings=$raw!==''?json_decode($raw,true):[];
    if(!is_array($settings)) $settings=[];
    $limit=(int)($settings['persuadeHiddenMessageLimit']??7);
    return max(3,min(7,$limit));
}
function ckmqp_show_story_limit_from_game(array $game): int {
    $raw=(string)($game['format_settings_snapshot_json']??($game['format_settings_json']??''));
    $settings=$raw!==''?json_decode($raw,true):[];
    if(!is_array($settings)||empty($settings['persuadeStoryLimitEnabled'])) return 0;
    $seconds=(int)($settings['persuadeStoryLimitSeconds']??90);
    return max(60,min(120,$seconds));
}
function ckmqp_show_hard_answer_limit_from_game(array $game): int {
    // «Неудобный вопрос» is intentionally untimed. Keep the helper for backward compatibility.
    return 0;
}
function ckmqp_show_story_answer_limit_from_game(array $game): int {
    $raw=(string)($game['format_settings_snapshot_json']??($game['format_settings_json']??''));
    $settings=$raw!==''?json_decode($raw,true):[];
    if(!is_array($settings) || empty($settings['persuadeStoryAnswerLimitEnabled'])) return 0;
    $seconds=(int)($settings['persuadeStoryAnswerSeconds']??30);
    return in_array($seconds,[20,30,60],true)?$seconds:30;
}
function ckmqp_show_apply_story_answer_policy(array $s,array $game): array {
    $desired=ckmqp_show_story_answer_limit_from_game($game);
    $needsMigration=!isset($s['storyAnswerPolicyVersion']) || (int)$s['storyAnswerPolicyVersion']<2;
    if($needsMigration || (int)($s['storyAnswerLimit']??0)!==$desired){
        $s['storyAnswerLimit']=$desired;
        $s['storyAnswerLimitConfigured']=true;
        $s['storyAnswerPolicyConfigured']=true;
        $s['storyAnswerPolicyVersion']=2;
        if((int)($s['round']??0)===3 && ($s['phase']??'')==='story_answer' && $desired<=0){
            $s['deadline']=0;$s['paused']=false;$s['remaining']=0;
        }
    }
    return $s;
}
function ckmqp_show_hidden_message_cap_for_slot(array $s,int $slot): int {
    return $slot===ckmqp_show_active_slot($s)?3:2;
}
function ckmqp_show_hidden_message_count_for_slot(array $s,int $slot): int {
    $count=0;$round=(int)($s['round']??0);$attempt=(int)($s['attempt']??0);
    foreach($s['messages']??[] as $m) if((int)($m['round']??0)===$round && (int)($m['attempt']??-1)===$attempt && (int)($m['slot']??0)===$slot)$count++;
    return $count;
}
/** New «Переговори другого» rooms use two teams. Legacy rooms keep three. */
function ckmqp_show_team_count(array $s): int {
    return (int)($s['teamCount']??3)===2 ? 2 : 3;
}
function ckmqp_show_attempt_indexes(array $s): array { return range(0,ckmqp_show_team_count($s)-1); }
function ckmqp_show_slot_indexes(array $s): array { return range(1,ckmqp_show_team_count($s)); }
function ckmqp_show_last_attempt(array $s): int { return ckmqp_show_team_count($s)-1; }
function ckmqp_show_initial(array $game=[]): array {
    $content=$game ? ckm_quiz_pro_persuade_me_content_from_game($game) : ckm_quiz_pro_persuade_me_default_content();
    $dialogueLimit=$game ? ckmqp_show_dialogue_limit_from_game($game) : 0;
    $hiddenMessageLimit=$game ? ckmqp_show_hidden_message_limit_from_game($game) : 7;
    $storyLimit=$game ? ckmqp_show_story_limit_from_game($game) : 0;
    $hardAnswerLimit=0;
    $storyAnswerLimit=$game ? ckmqp_show_story_answer_limit_from_game($game) : 0;
    // Existing sessions created before dev.267 normalize to three teams. New rooms
    // take the immutable team_count snapshot from the game row.
    $teamCount=$game ? (int)($game['team_count']??3) : 3;
    $teamCount=$teamCount===2?2:3;
    return ['version'=>1,'revision'=>0,'teamCount'=>$teamCount,'round'=>0,'attempt'=>0,'phase'=>'waiting','deadline'=>0,'ready'=>[], 'next'=>[], 'roundNext'=>[],
        'paused'=>false,'remaining'=>0,'dialogueLimit'=>$dialogueLimit,'storyLimit'=>$storyLimit,'storyLimitConfigured'=>true,'hardAnswerLimit'=>$hardAnswerLimit,'hardAnswerLimitConfigured'=>true,'storyAnswerLimit'=>$storyAnswerLimit,'storyAnswerLimitConfigured'=>true,'storyAnswerPolicyConfigured'=>true,'storyAnswerPolicyVersion'=>2,'dialogueStartedAt'=>0,'lastDialogueActivityAt'=>0,'dialogueClosedBy'=>'',
        'aiDialogueNaturalAt'=>0,'aiDialogueIdlePromptedAt'=>0,'aiDialogueIdlePromptSeq'=>0,'autoAdvanceMarkedAt'=>0,
        'hiddenMessageLimit'=>$hiddenMessageLimit,'hiddenMessageLimitConfigured'=>true,
        'messages'=>[],'reviews'=>[],'hiddenReviews'=>[],'hardAnswers'=>[],'hardReviews'=>[],'questionIndex'=>0,
        'storyModes'=>[],'storyStories'=>[],'storyQuestions'=>[],'storyQuestionDone'=>[],'storyAnswers'=>[],'storyVotes'=>[],'storyResults'=>[],'storyReviews'=>[],'storyQuestionIndex'=>0,'content'=>$content];
}

/** Backward-compatible state upgrade for rooms created by .109-.121. */
function ckmqp_show_normalize_state(array $s): array {
    // Rooms created before the two-team migration had no marker and therefore
    // remain on their original three-team contract until they finish.
    if (!isset($s['teamCount'])) $s['teamCount']=3;
    $s['teamCount']=(int)$s['teamCount']===2?2:3;
    if (!isset($s['round'])) $s['round']=0;
    if (!isset($s['roundNext']) || !is_array($s['roundNext'])) $s['roundNext']=[];
    if (!isset($s['hiddenReviews']) || !is_array($s['hiddenReviews'])) $s['hiddenReviews']=[];
    if (!isset($s['hardAnswers']) || !is_array($s['hardAnswers'])) $s['hardAnswers']=[];
    if (!isset($s['hardReviews']) || !is_array($s['hardReviews'])) $s['hardReviews']=[];
    if (!isset($s['questionIndex'])) $s['questionIndex']=0;
    foreach(['storyModes','storyStories','storyQuestions','storyQuestionDone','storyAnswers','storyVotes','storyResults','storyReviews'] as $key) if (!isset($s[$key]) || !is_array($s[$key])) $s[$key]=[];
    if (!isset($s['storyQuestionIndex'])) $s['storyQuestionIndex']=0;
    if (!isset($s['ready']) || !is_array($s['ready'])) $s['ready']=[];
    if (!isset($s['next']) || !is_array($s['next'])) $s['next']=[];
    if (!isset($s['messages']) || !is_array($s['messages'])) $s['messages']=[];
    if (!isset($s['dialogueLimit'])) $s['dialogueLimit']=0;
    if (!isset($s['dialogueStartedAt'])) $s['dialogueStartedAt']=0;
    if (!isset($s['lastDialogueActivityAt'])) $s['lastDialogueActivityAt']=0;
    if (!isset($s['dialogueClosedBy'])) $s['dialogueClosedBy']='';
    if (!isset($s['aiDialogueNaturalAt'])) $s['aiDialogueNaturalAt']=0;
    if (!isset($s['aiDialogueIdlePromptedAt'])) $s['aiDialogueIdlePromptedAt']=0;
    if (!isset($s['aiDialogueIdlePromptSeq'])) $s['aiDialogueIdlePromptSeq']=0;
    if (!isset($s['autoAdvanceMarkedAt'])) $s['autoAdvanceMarkedAt']=0;
    if (!isset($s['hiddenMessageLimit'])) $s['hiddenMessageLimit']=7;
    $s['hiddenMessageLimit']=max(3,min(7,(int)$s['hiddenMessageLimit']));
    if (!isset($s['storyLimit'])) $s['storyLimit']=0;
    $s['storyLimit']=max(0,min(120,(int)$s['storyLimit']));
    $s['hardAnswerLimit']=0;
    if ((int)$s['round']===2 && $s['phase']==='hard_answer') { $s['deadline']=0;$s['paused']=false;$s['remaining']=0; }
    if (!isset($s['storyAnswerLimit']) || !in_array((int)$s['storyAnswerLimit'],[0,20,30,60],true)) $s['storyAnswerLimit']=0;
    if ($s['phase']==='dialogue' && (int)$s['dialogueLimit']<=0) {
        $s['deadline']=0;$s['paused']=false;$s['remaining']=0;
    }
    if ((int)$s['round']===3 && $s['phase']==='preparation') {
        $s['deadline']=0;$s['paused']=false;$s['remaining']=0;
    }
    if ((int)$s['round']===3 && $s['phase']==='story_tell' && (int)$s['storyLimit']<=0) {
        $s['deadline']=0;$s['paused']=false;$s['remaining']=0;
    }
    if ((int)$s['round']===3 && $s['phase']==='story_answer' && (int)$s['storyAnswerLimit']<=0) {
        $s['deadline']=0;$s['paused']=false;$s['remaining']=0;
    }
    // Two-team cross-examination is strictly alternating: question 1 -> answer 1 ->
    // question 2 -> answer 2. Migrate active rooms created by older builds that
    // could collect the next question before the storyteller answered the first.
    if ((int)$s['round']===3 && ckmqp_show_team_count($s)===2 && $s['phase']==='story_questions') {
        $a=(int)($s['attempt']??0);
        $ordered=function_exists('ckmqp_show_story_question_order')?ckmqp_show_story_question_order($s,$a):[];
        foreach($ordered as $idx=>$q){
            if(!isset($s['storyAnswers'][$a][$idx])){
                $s['phase']='story_answer';$s['storyQuestionIndex']=(int)$idx;$s['deadline']=0;$s['paused']=false;$s['remaining']=0;
                break;
            }
        }
    }
    $s['content']=ckm_quiz_pro_persuade_me_normalize_content($s['content']??null);
    foreach ($s['messages'] as &$m) if (!isset($m['round'])) $m['round']=0;
    unset($m);
    return $s;
}

function ckmqp_show_tick(array $s, int $now): array {
    $s=ckmqp_show_normalize_state($s);
    if ($s['paused']) return $s;
    if (($s['phase']??'')==='waiting' && !ckmqp_show_is_initial_waiting($s)) $s=ckmqp_show_begin_attempt($s,(int)$s['round'],(int)$s['attempt'],$now);
    // Раунд 3 «Неудобный вопрос» не имеет тайм-аута: вопрос остаётся открыт до ответа
    // или явного завершения блока активной командой.
    if ((int)$s['round']===2 && $s['phase']==='review') return $s;
    if ((int)$s['round']===3) {
        if ($s['phase']==='story_tell' && (int)$s['deadline']>0 && $now >= $s['deadline']) {
            $a=(int)$s['attempt'];if(!isset($s['storyStories'][$a]))$s['storyStories'][$a]=['text'=>'','at'=>(int)$s['deadline'],'timedOut'=>true];
            $s['phase']='story_questions';$s['deadline']=0;
        }
        while ($s['phase']==='story_answer' && (int)$s['deadline']>0 && $now >= $s['deadline']) {
            $a=(int)$s['attempt'];$q=(int)$s['storyQuestionIndex'];$expiredAt=(int)$s['deadline'];
            if(!isset($s['storyAnswers'][$a])||!is_array($s['storyAnswers'][$a]))$s['storyAnswers'][$a]=[];
            if(!isset($s['storyAnswers'][$a][$q]))$s['storyAnswers'][$a][$q]=['questionIndex'=>$q,'text'=>'','at'=>$expiredAt,'timedOut'=>true];
            $ordered=ckmqp_show_story_question_order($s,$a);$last=max(0,count($ordered)-1);if($q<$last){$s['storyQuestionIndex']=$q+1;$limit=(int)($s['storyAnswerLimit']??0);$s['deadline']=$limit>0?$expiredAt+$limit:0;}else{$s['phase']='story_vote';$s['deadline']=0;}
        }
        return $s;
    }
    // Rounds 1–2 no longer use a preparation countdown. Legacy rooms that were
    // parked on the old preparation phase open the dialogue on the next state tick.
    if (in_array((int)($s['round']??0),[0,1],true) && $s['phase']==='preparation') {
        $limit=max(0,(int)($s['dialogueLimit']??0));
        $s['phase']='dialogue';
        $s['dialogueStartedAt']=$limit>0?$now:0;
        $s['lastDialogueActivityAt']=0;
        $s['dialogueClosedBy']='';$s['aiDialogueNaturalAt']=0;$s['aiDialogueIdlePromptedAt']=0;
        $s['deadline']=$limit>0?$now+$limit:0;
        $s['paused']=false;$s['remaining']=0;
    }
    if ($s['phase']==='dialogue' && (int)($s['dialogueLimit']??0)>0 && (int)$s['deadline']>0 && $now >= (int)$s['deadline']) {
        $s['phase']='review'; $s['deadline']=0;$s['dialogueClosedBy']='timer';
    }
    if ((int)($s['round']??0)===1 && $s['phase']==='dialogue') {
        $limit=max(3,min(7,(int)($s['hiddenMessageLimit']??7)));
        if(count(ckmqp_show_current_dialogue_messages($s)) >= $limit){
            $s['phase']='review';$s['deadline']=0;$s['paused']=false;$s['remaining']=0;$s['dialogueClosedBy']='message_limit';
        }
    }
    return $s;
}
function ckmqp_show_role(int $slot, int $attempt, int $teamCount=3): string {
    $teamCount=$teamCount===2?2:3;
    $active=$attempt+1; $opponent=($active % $teamCount)+1;
    return $slot===$active ? 'speaker' : ($slot===$opponent ? 'opponent' : 'observer');
}
function ckmqp_show_active_slot(array $s): int { return ((int)$s['attempt'])+1; }
function ckmqp_show_dialogue_starter_role(array $s): string {
    $round=(int)($s['round']??0);$content=ckmqp_show_content($s);
    if($round===0){$case=$content['round1']['cases'][(int)($s['attempt']??0)]??[];$role=ckm_quiz_pro_persuade_me_starter_role($case['starterRole']??'opponent','opponent');}
    elseif($round===1){$role=ckm_quiz_pro_persuade_me_starter_role($content['round2']['starterRole']??'speaker','speaker');}
    else return '';
    return in_array($role,['speaker','opponent'],true)?$role:($round===0?'opponent':'speaker');
}
function ckmqp_show_dialogue_starter_slot(array $s): int {
    if(ckmqp_show_team_count($s)!==2 || !in_array((int)($s['round']??0),[0,1],true)) return 0;
    $active=ckmqp_show_active_slot($s);$opponent=($active%2)+1;
    return ckmqp_show_dialogue_starter_role($s)==='opponent'?$opponent:$active;
}
function ckmqp_show_dialogue_turn_slot(array $s): int {
    if(($s['phase']??'')!=='dialogue') return 0;
    $starter=ckmqp_show_dialogue_starter_slot($s);if($starter<1)return 0;
    $messages=ckmqp_show_current_dialogue_messages($s);if(count($messages)===0)return $starter;
    $last=(int)($messages[count($messages)-1]['slot']??0);
    if(in_array($last,[1,2],true))return $last===1?2:1;
    return count($messages)%2===0?$starter:($starter===1?2:1);
}
function ckmqp_show_dialogue_role_label(array $s,int $slot): string {
    if((int)($s['round']??0)===1)return $slot===ckmqp_show_active_slot($s)?'Исполнитель скрытых задач':'Собеседник';
    return ckmqp_show_role($slot,(int)($s['attempt']??0),ckmqp_show_team_count($s))==='opponent'?'Оппонент':'Переговорщик';
}
function ckmqp_show_content(array $s=[]): array {
    return ckm_quiz_pro_persuade_me_normalize_content($s['content']??null);
}
function ckmqp_show_cases(array $s=[]): array { return ckmqp_show_content($s)['round1']['cases']; }
function ckmqp_show_hidden_tasks(array $s=[]): array { return ckmqp_show_content($s)['round2']['tasks']; }
function ckmqp_show_hidden_situation(array $s=[]): string { return (string)ckmqp_show_content($s)['round2']['situation']; }
function ckmqp_show_hard_questions(array $s=[]): array { return ckmqp_show_content($s)['round3']['questions']; }
function ckmqp_show_all_hidden_reviews_done(array $s): bool {
    foreach(ckmqp_show_attempt_indexes($s) as $a) if (($s['hiddenReviews'][$a]['status']??'')!=='done') return false;
    return true;
}
function ckmqp_show_current_review_done(array $s): bool {
    $a=(int)$s['attempt'];
    if ((int)$s['round']===3) return (($s['storyReviews'][$a]['status']??'')==='done');
    if ((int)$s['round']===2) return (($s['hardReviews'][$a]['status']??'')==='done');
    if ((int)$s['round']===1) return (($s['hiddenReviews'][$a]['status']??'')==='done');
    return (($s['reviews'][$a]['status']??'')==='done');
}

function ckmqp_show_is_initial_waiting(array $s): bool {
    return (int)($s['round']??0)===0
        && (int)($s['attempt']??0)===0
        && (string)($s['phase']??'waiting')==='waiting'
        && empty($s['messages']) && empty($s['reviews']) && empty($s['hiddenReviews'])
        && empty($s['hardReviews']) && empty($s['storyResults']) && empty($s['storyReviews']);
}

function ckmqp_show_begin_attempt(array $s,int $round,int $attempt,int $now): array {
    $s['round']=$round;$s['attempt']=$attempt;$s['ready']=[];$s['next']=[];$s['roundNext']=[];
    $s['paused']=false;$s['remaining']=0;$s['questionIndex']=0;$s['storyQuestionIndex']=0;
    $s['dialogueStartedAt']=0;$s['lastDialogueActivityAt']=0;$s['dialogueClosedBy']='';
    $s['aiDialogueNaturalAt']=0;$s['aiDialogueIdlePromptedAt']=0;$s['autoAdvanceMarkedAt']=0;
    if($round===2){$s['phase']='hard_answer';$s['deadline']=0;}
    elseif($round===3){$s['phase']='preparation';$s['deadline']=0;}
    else {
        $limit=max(0,(int)($s['dialogueLimit']??0));
        $s['phase']='dialogue';
        $s['dialogueStartedAt']=$limit>0?$now:0;
        $s['lastDialogueActivityAt']=0;
        $s['deadline']=$limit>0?$now+$limit:0;
    }
    if($round===3){
        if(empty($s['storyModes']) && function_exists('ckmqp_show_story_mode_pattern')) $s['storyModes']=array_slice(ckmqp_show_story_mode_pattern($now,(int)($s['revision']??0)),0,ckmqp_show_team_count($s));
        $s['storyQuestionDone'][$attempt]=[];
    }
    return $s;
}

function ckmqp_show_story_questions_finished(array $s,int $attempt): bool {
    if(!function_exists('ckmqp_show_story_opponent_slots')) return false;
    foreach(ckmqp_show_story_opponent_slots($attempt,ckmqp_show_team_count($s)) as $opp){
        $asked=count($s['storyQuestions'][$attempt][$opp]??[]);
        if($asked<2 && empty($s['storyQuestionDone'][$attempt][$opp])) return false;
    }
    return true;
}
function ckmqp_show_story_open_answers_or_vote(array $s,int $attempt,int $now): array {
    $ordered=function_exists('ckmqp_show_story_question_order')?ckmqp_show_story_question_order($s,$attempt):[];
    if(count($ordered)>0){
        $s['phase']='story_answer';$s['storyQuestionIndex']=0;
        $limit=(int)($s['storyAnswerLimit']??0);$s['deadline']=$limit>0?$now+$limit:0;
    } else {
        $s['phase']='story_vote';$s['storyQuestionIndex']=0;$s['deadline']=0;
    }
    $s['paused']=false;$s['remaining']=0;
    return $s;
}

function ckmqp_show_review_completed_at(array $s): int {
    $a=(int)($s['attempt']??0);$r=(int)($s['round']??0);
    if($r===0)return (int)($s['reviews'][$a]['completedAt']??0);
    if($r===1)return (int)($s['hiddenReviews'][$a]['completedAt']??0);
    if($r===2)return (int)($s['hardReviews'][$a]['completedAt']??0);
    if($r===3){
        if(($s['storyReviews'][$a]['status']??'')==='done')return (int)($s['storyReviews'][$a]['completedAt']??0);
        $result=$s['storyResults'][$a]??null;if(is_array($result)&&!empty($result['completedAt']))return (int)$result['completedAt'];
        $latest=0;foreach(($s['storyVotes'][$a]??[]) as $vote)$latest=max($latest,(int)($vote['at']??0));return $latest;
    }
    return 0;
}

/** One controlled transition replaces three team confirmations plus another readiness step. */
function ckmqp_show_advance_from_review(array $s,int $now): array {
    $phase=(string)($s['phase']??'');$round=(int)($s['round']??0);$attempt=(int)($s['attempt']??0);
    if($phase==='review'){
        if(!ckmqp_show_current_review_done($s))return $s;
        if($attempt<ckmqp_show_last_attempt($s))return ckmqp_show_begin_attempt($s,$round,$attempt+1,$now);
        if($round<3)return ckmqp_show_begin_attempt($s,$round+1,0,$now);
        $s['phase']='game_complete';$s['deadline']=0;$s['ready']=[];$s['next']=[];$s['roundNext']=[];$s['paused']=false;$s['remaining']=0;
        return $s;
    }
    // Backward compatibility for rooms stopped on the former round-confirmation screens.
    if(in_array($phase,['round_complete','round2_complete','round3_complete'],true) && $round<3)return ckmqp_show_begin_attempt($s,$round+1,0,$now);
    return $s;
}

function ckmqp_show_current_dialogue_messages(array $s): array {
    $round=(int)($s['round']??0);$attempt=(int)($s['attempt']??0);
    return array_values(array_filter($s['messages']??[],static fn($m)=>(int)($m['round']??0)===$round && (int)($m['attempt']??-1)===$attempt));
}

/** Recovery is offered only for an obviously unusable failed attempt. */
function ckmqp_show_can_restart_empty_failed_attempt(array $s): bool {
    $round=(int)($s['round']??0);$attempt=(int)($s['attempt']??0);$phase=(string)($s['phase']??'');
    if ($attempt<0 || $attempt>ckmqp_show_last_attempt($s)) return false;
    if (in_array($round,[0,1],true)) {
        if ($phase!=='review' || count(ckmqp_show_current_dialogue_messages($s))>0) return false;
        $bucket=$round===1?'hiddenReviews':'reviews';
        return (($s[$bucket][$attempt]['status']??'')==='failed');
    }
    if ($round===2) {
        if ($phase!=='review' || ($s['hardReviews'][$attempt]['status']??'')!=='failed') return false;
        $answers=$s['hardAnswers'][$attempt]??[];
        if (!is_array($answers) || count($answers)!==3) return false;
        foreach ([0,1,2] as $i) {
            $row=$answers[$i]??null;
            if (!is_array($row) || empty($row['timedOut']) || trim((string)($row['text']??''))!=='') return false;
        }
        return true;
    }
    if ($round===3) {
        // If the storyteller's 60-second window expired without any story, cross-examination
        // has no meaningful subject. Allow the host to restart the same storyteller only
        // before any downstream question/answer/vote/result has been recorded.
        if ($phase!=='story_questions') return false;
        $story=$s['storyStories'][$attempt]??null;
        if (!is_array($story) || empty($story['timedOut']) || trim((string)($story['text']??''))!=='') return false;
        if (!empty($s['storyQuestions'][$attempt]??[])) return false;
        if (!empty($s['storyAnswers'][$attempt]??[])) return false;
        if (!empty($s['storyVotes'][$attempt]??[])) return false;
        if (!empty($s['storyResults'][$attempt]??null)) return false;
        return true;
    }
    return false;
}

function ckmqp_show_restart_empty_failed_attempt(array $s,int $now): array {
    if (!ckmqp_show_can_restart_empty_failed_attempt($s)) return $s;
    $round=(int)$s['round'];$attempt=(int)$s['attempt'];
    if ($round===2) {
        unset($s['hardReviews'][$attempt],$s['hardAnswers'][$attempt]);
    } elseif ($round===3) {
        unset($s['storyStories'][$attempt],$s['storyQuestions'][$attempt],$s['storyQuestionDone'][$attempt],$s['storyAnswers'][$attempt],$s['storyVotes'][$attempt],$s['storyResults'][$attempt],$s['storyReviews'][$attempt]);
    } else {
        $bucket=$round===1?'hiddenReviews':'reviews';
        unset($s[$bucket][$attempt]);
    }
    $s=ckmqp_show_begin_attempt($s,$round,$attempt,$now);
    if ($round===3) $s['recoveredTimedOutStory']=true;
    else $s['recoveredEmptyReview']=true;
    return $s;
}
function ckmqp_show_text_lower(string $text): string { return function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text); }
function ckmqp_show_ai_natural_grace_seconds(): int { return 10; }
function ckmqp_show_ai_idle_prompt_seconds(): int { return 25; }
function ckmqp_show_ai_idle_close_seconds(): int { return 15; }

/**
 * Content-only completion hint for the AI-host controller.
 * It deliberately never looks at hidden tasks, scores or review state, so
 * an early close cannot reveal to the active team that its secret goals are done.
 */
function ckmqp_show_ai_dialogue_should_finish(array $s): bool {
    if (($s['phase']??'')!=='dialogue' || !in_array((int)($s['round']??0),[0,1],true)) return false;
    $messages=ckmqp_show_current_dialogue_messages($s);$count=count($messages);$round=(int)$s['round'];
    if($count<3)return false;
    $teams=[];foreach($messages as $m)$teams[(int)($m['teamId']??0)]=true;if(count($teams)<2)return false;
    // Round 1 may end after enough substantive turns. Round 2 has its own hard
    // message cap and never uses hidden-task completion as an end signal.
    if($round===0 && $count>=8)return true;
    $tail=array_slice($messages,-3);$text=ckmqp_show_text_lower(implode(' ',array_map(static fn($m)=>(string)($m['text']??''),$tail)));
    $agreement=['договорились','согласен','согласна','согласны','подходит','устраивает','фиксируем','зафиксируем','подтверждаю','подтверждаем','да, правильно','да правильно','решено','итого','принимаю этот вариант','так и сделаем','по рукам'];
    foreach($agreement as $marker)if(str_contains($text,$marker))return true;
    if($count>=4){$deadlock=['не договоримся','не можем согласовать','договориться не получится','это окончательно','отказываюсь','не готов продолжать','не готова продолжать','дальше обсуждать нечего'];foreach($deadlock as $marker)if(str_contains($text,$marker))return true;}
    return false;
}

/**
 * AI-host end-of-dialogue controller. Natural completion gets a 10-second
 * grace window. Otherwise 25 seconds of silence triggers a spoken prompt;
 * 15 more silent seconds close the dialogue. The message cap still closes
 * immediately inside the reducer.
 */
function ckmqp_show_ai_dialogue_advance(array $s,int $now,string $command='poll'): array {
    $s=ckmqp_show_normalize_state($s);
    if (($s['phase']??'')==='review' && ckmqp_show_current_review_done($s)) {
        $doneAt=ckmqp_show_review_completed_at($s);$mark=(int)($s['autoAdvanceMarkedAt']??0);
        if($doneAt<=0){if($mark<=0){$s['autoAdvanceMarkedAt']=$now;return $s;}$doneAt=$mark;}
        elseif($mark>0)$doneAt=$mark;
        if($now-$doneAt>=5)return ckmqp_show_advance_from_review($s,$now);
        return $s;
    }
    if (($s['phase']??'')!=='dialogue' || !in_array((int)($s['round']??0),[0,1],true)) return $s;
    $activity=max((int)($s['lastDialogueActivityAt']??0),(int)($s['dialogueStartedAt']??0));
    if($command==='message'){
        $s['aiDialogueIdlePromptedAt']=0;
        $s['aiDialogueNaturalAt']=ckmqp_show_ai_dialogue_should_finish($s)?$now:0;
        return $s;
    }
    $naturalAt=(int)($s['aiDialogueNaturalAt']??0);
    if($naturalAt>0 && $activity<=$naturalAt && $now-$naturalAt>=ckmqp_show_ai_natural_grace_seconds()){
        $s['phase']='review';$s['deadline']=0;$s['paused']=false;$s['remaining']=0;$s['dialogueClosedBy']='ai_natural';
        $s['aiDialogueNaturalAt']=0;$s['aiDialogueIdlePromptedAt']=0;
        return $s;
    }
    $promptedAt=(int)($s['aiDialogueIdlePromptedAt']??0);
    if($activity<=0)return $s;
    if($promptedAt>0 && $activity>$promptedAt){
        $s['aiDialogueIdlePromptedAt']=0;
        return $s;
    }
    if($promptedAt<=0 && $now-$activity>=ckmqp_show_ai_idle_prompt_seconds()){
        $s['aiDialogueIdlePromptedAt']=$now;
        $s['aiDialogueIdlePromptSeq']=max(0,(int)($s['aiDialogueIdlePromptSeq']??0))+1;
        return $s;
    }
    if($promptedAt>0 && $now-$promptedAt>=ckmqp_show_ai_idle_close_seconds()){
        $s['phase']='review';$s['deadline']=0;$s['paused']=false;$s['remaining']=0;$s['dialogueClosedBy']='ai_idle';
        $s['aiDialogueNaturalAt']=0;$s['aiDialogueIdlePromptedAt']=0;
    }
    return $s;
}

/** Pure state transition; clock and authenticated team slot are server supplied. */
function ckmqp_show_reduce(array $s, array $actor, array $input, int $now): array {
    $s=ckmqp_show_tick($s,$now);
    $fail=static fn($code,$error)=>['ok'=>false,'session'=>$s,'code'=>$code,'error'=>$error];
    $command=(string)($input['command']??'');
    if (in_array($command,['review_claim','review_finish'],true)) return ckmqp_show_review_reduce($s,$actor,$input,$now);
    if (in_array($command,['hidden_review_claim','hidden_review_finish'],true)) return ckmqp_show_hidden_review_reduce($s,$actor,$input,$now);
    if (in_array($command,['hard_review_claim','hard_review_finish'],true)) return ckmqp_show_hard_review_reduce($s,$actor,$input,$now);
    if (in_array($command,['story_review_claim','story_review_finish'],true)) return ckmqp_show_story_review_reduce($s,$actor,$input,$now);
    if ($command==='manual_review') return ckmqp_show_manual_review_reduce($s,$actor,$input,$now);
    if ($command==='poll') return ['ok'=>true,'session'=>$s];
    $host=($actor['role']??'')==='host'; $slot=(int)($actor['slot']??0); $tid=(int)($actor['team_id']??0);
    if (isset($input['round']) && (int)$input['round']!==(int)$s['round']) return $fail('stale_round','Раунд уже изменился. Обновите состояние комнаты.');
    if (in_array($command,['pause','resume'],true)) {
        if ((int)($input['attempt']??-1)!==$s['attempt']) return $fail('stale_attempt','Роли уже изменились.');
        if (!$host) return $fail('host_required','Это действие доступно организатору.');
        $timedPhase=$s['phase']==='hard_answer'
            || ($s['phase']==='story_answer' && (int)$s['deadline']>0)
            || ($s['phase']==='story_tell' && (int)$s['deadline']>0);
        if (!$timedPhase) return $fail('phase_closed','На этом этапе таймер не запущен.');
        if ($command==='pause' && !$s['paused']) {$s['remaining']=max(0,$s['deadline']-$now);$s['paused']=true;}
        if ($command==='resume' && $s['paused']) {$s['deadline']=$now+$s['remaining'];$s['paused']=false;$s['remaining']=0;}
        return ['ok'=>true,'session'=>$s];
    }
    if ($command==='finish_dialogue') {
        $round=(int)($s['round']??0);$phase=(string)($s['phase']??'');$attempt=(int)($s['attempt']??0);
        if(isset($input['attempt']) && (int)$input['attempt']!==$attempt)return $fail('stale_attempt','Роли уже изменились. Обновите состояние комнаты.');
        if($s['paused'])return $fail('paused','Игра на паузе.');
        if($host){
            if(!in_array($round,[0,1],true)||$phase!=='dialogue')return $fail('phase_closed','Сейчас свободный диалог не идёт.');
            $s['phase']='review';$s['deadline']=0;$s['paused']=false;$s['remaining']=0;$s['dialogueClosedBy']='host';
            return ['ok'=>true,'session'=>$s];
        }
        if(($actor['role']??'')!=='participant'||$tid<1||!in_array($slot,ckmqp_show_slot_indexes($s),true))return $fail('participant_required','Нужна действующая сессия команды.');
        if($round===0 && $phase==='dialogue'){
            if(ckmqp_show_role($slot,$attempt,ckmqp_show_team_count($s))==='observer')return $fail('observer_read_only','Наблюдатель не завершает диалог участников.');
            $s['phase']='review';$s['deadline']=0;$s['remaining']=0;$s['dialogueClosedBy']='participant';
            return ['ok'=>true,'session'=>$s];
        }
        if($round===1 && $phase==='dialogue'){
            if($slot!==ckmqp_show_active_slot($s))return $fail('active_team_required','Завершить этот диалог может активная команда.');
            $s['phase']='review';$s['deadline']=0;$s['remaining']=0;$s['dialogueClosedBy']='participant';
            return ['ok'=>true,'session'=>$s];
        }
        if($round===2 && $phase==='hard_answer'){
            if($slot!==ckmqp_show_active_slot($s))return $fail('active_team_required','Завершить ответы может активная команда.');
            $a=$attempt;$q=(int)($s['questionIndex']??0);
            if(!isset($s['hardAnswers'][$a])||!is_array($s['hardAnswers'][$a]))$s['hardAnswers'][$a]=[];
            for($i=$q;$i<3;$i++)if(!isset($s['hardAnswers'][$a][$i]))$s['hardAnswers'][$a][$i]=['questionIndex'=>$i,'text'=>'','at'=>$now,'timedOut'=>false,'endedEarly'=>true];
            $s['phase']='review';$s['deadline']=0;$s['remaining']=0;$s['dialogueClosedBy']='participant';
            return ['ok'=>true,'session'=>$s];
        }
        if($round===3 && $phase==='story_questions'){
            $active=ckmqp_show_active_slot($s);if($slot===$active)return $fail('opponent_required','Рассказчик сейчас отвечает только после вопросов соперников.');
            if(!isset($s['storyQuestionDone'][$attempt])||!is_array($s['storyQuestionDone'][$attempt]))$s['storyQuestionDone'][$attempt]=[];
            $s['storyQuestionDone'][$attempt][$slot]=true;
            if(ckmqp_show_story_questions_finished($s,$attempt))$s=ckmqp_show_story_open_answers_or_vote($s,$attempt,$now);
            return ['ok'=>true,'session'=>$s];
        }
        if($round===3 && $phase==='story_answer'){
            if($slot!==ckmqp_show_active_slot($s))return $fail('active_team_required','Завершить ответы может рассказчик.');
            $ordered=ckmqp_show_story_question_order($s,$attempt);$q=(int)($s['storyQuestionIndex']??0);
            if(!isset($s['storyAnswers'][$attempt])||!is_array($s['storyAnswers'][$attempt]))$s['storyAnswers'][$attempt]=[];
            for($i=$q;$i<count($ordered);$i++)if(!isset($s['storyAnswers'][$attempt][$i]))$s['storyAnswers'][$attempt][$i]=['questionIndex'=>$i,'text'=>'','at'=>$now,'timedOut'=>false,'endedEarly'=>true];
            $s['phase']='story_vote';$s['deadline']=0;$s['remaining']=0;$s['dialogueClosedBy']='participant';
            return ['ok'=>true,'session'=>$s];
        }
        return $fail('phase_closed','На этом этапе завершать диалог не требуется.');
    }
    if ($command==='advance') {
        if(!$host)return $fail('host_required','Продолжить игру может ведущий.');
        $advanced=ckmqp_show_advance_from_review($s,$now);
        if($advanced===$s)return $fail('advance_unavailable','Сначала дождитесь завершения оценки.');
        return ['ok'=>true,'session'=>$advanced];
    }
    if ($command==='restart_attempt') {
        if(!$host)return $fail('host_required','Повторить испытание может только ведущий.');
        $restarted=ckmqp_show_restart_empty_failed_attempt($s,$now);
        if($restarted===$s)return $fail('restart_unavailable','Повтор испытания доступен только для пустого ошибочного диалога, полностью пропущенного блока ответов или рассказа, который истёк до начала перекрёстных вопросов.');
        return ['ok'=>true,'session'=>$restarted];
    }
    if ($host || ($actor['role']??'')!=='participant' || $tid<1 || !in_array($slot,ckmqp_show_slot_indexes($s),true)) return $fail('participant_required','Нужна действующая сессия команды.');
    if ($command==='message') {
        $rid=(string)($input['request_id']??''); $body=(string)($input['text']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$rid)) return $fail('request_id_invalid','Обновите страницу и повторите отправку.');
        foreach ($s['messages'] as $m) if ($m['teamId']===$tid && $m['requestId']===$rid) {
            $mr=(int)($m['round']??0);
            if ($m['text']!==$body || $m['attempt']!==(int)($input['attempt']??-1) || $mr!==(int)$s['round']) return $fail('request_conflict','Этот запрос уже использован для другой реплики.');
            return ['ok'=>true,'session'=>$s,'duplicate'=>true];
        }
    }
    if ((int)($input['attempt']??-1)!==$s['attempt'] && $command!=='next_round') return $fail('stale_attempt','Роли уже изменились. Обновите состояние комнаты.');
    if ($s['paused']) return $fail('paused','Игра на паузе.');
    if ($command==='ready') {
        if(!ckmqp_show_is_initial_waiting($s)) return $fail('ready_not_needed','Повторная готовность между испытаниями больше не требуется.');
        if (in_array($slot,$s['ready'],true) && in_array($s['phase'],['waiting','preparation'],true)) return ['ok'=>true,'session'=>$s];
        if ($s['phase']!=='waiting') return $fail('phase_closed','Подготовка уже началась.');
        $s['ready'][]=$slot;
        if (count($s['ready'])===ckmqp_show_team_count($s)) $s=ckmqp_show_begin_attempt($s,(int)$s['round'],(int)$s['attempt'],$now);
    } elseif ($command==='story_ready') {
        if ((int)$s['round']!==3 || $s['phase']!=='preparation') return $fail('story_ready_closed','Сейчас подготовка рассказчика не идёт.');
        $active=ckmqp_show_active_slot($s);if($slot!==$active)return $fail('active_team_required','К рассказу готовится другая команда.');
        $s['phase']='story_tell';$limit=max(0,(int)($s['storyLimit']??0));$s['deadline']=$limit>0?$now+$limit:0;$s['paused']=false;$s['remaining']=0;
    } elseif ($command==='story_submit') {
        if ((int)$s['round']!==3 || $s['phase']!=='story_tell') return $fail('story_closed','Сейчас рассказ не принимается.');
        $active=ckmqp_show_active_slot($s);if($slot!==$active)return $fail('active_team_required','Сейчас историю рассказывает другая команда.');
        $a=(int)$s['attempt'];$body=(string)($input['text']??'');$rid=(string)($input['request_id']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$rid))return $fail('request_id_invalid','Обновите страницу и повторите отправку.');
        if(trim($body)===''||strlen($body)>8000)return $fail('message_invalid','Введите рассказ длиной до 2000 символов.');
        if(isset($s['storyStories'][$a])){if(($s['storyStories'][$a]['requestId']??'')===$rid&&(string)($s['storyStories'][$a]['text']??'')===$body)return ['ok'=>true,'session'=>$s,'duplicate'=>true];return $fail('story_locked','Рассказ уже зафиксирован.');}
        $s['storyStories'][$a]=['text'=>$body,'at'=>$now,'requestId'=>$rid,'timedOut'=>false];$s['phase']='story_questions';$s['deadline']=0;
    } elseif ($command==='story_question') {
        if ((int)$s['round']!==3 || $s['phase']!=='story_questions') return $fail('questions_closed','Сейчас вопросы не принимаются.');
        $active=ckmqp_show_active_slot($s);if($slot===$active)return $fail('opponent_required','Вопросы задаёт соперничающая команда.');
        $a=(int)$s['attempt'];$body=(string)($input['text']??'');$rid=(string)($input['request_id']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$rid))return $fail('request_id_invalid','Обновите страницу и повторите отправку.');
        if(trim($body)===''||strlen($body)>2000)return $fail('message_invalid','Введите один уточняющий вопрос длиной до 500 символов.');
        if(!isset($s['storyQuestions'][$a])||!is_array($s['storyQuestions'][$a]))$s['storyQuestions'][$a]=[];if(!isset($s['storyQuestions'][$a][$slot])||!is_array($s['storyQuestions'][$a][$slot]))$s['storyQuestions'][$a][$slot]=[];
        foreach($s['storyQuestions'][$a][$slot] as $old){if(($old['requestId']??'')===$rid){if((string)($old['text']??'')===$body)return ['ok'=>true,'session'=>$s,'duplicate'=>true];return $fail('request_conflict','Этот запрос уже использован для другого вопроса.');}}
        if(count($s['storyQuestions'][$a][$slot])>=2)return $fail('question_limit','Ваша команда уже задала два вопроса.');
        if(!empty($s['storyQuestionDone'][$a][$slot]))return $fail('questions_finished','Ваша команда уже завершила вопросы в этом испытании.');
        $s['storyQuestions'][$a][$slot][]=['text'=>$body,'at'=>$now,'requestId'=>$rid];
        if(count($s['storyQuestions'][$a][$slot])>=2){if(!isset($s['storyQuestionDone'][$a])||!is_array($s['storyQuestionDone'][$a]))$s['storyQuestionDone'][$a]=[];$s['storyQuestionDone'][$a][$slot]=true;}
        if(ckmqp_show_team_count($s)===2){
            // In two-team mode the storyteller answers each question immediately.
            $ordered=ckmqp_show_story_question_order($s,$a);$s['phase']='story_answer';$s['storyQuestionIndex']=max(0,count($ordered)-1);
            $limit=(int)($s['storyAnswerLimit']??0);$s['deadline']=$limit>0?$now+$limit:0;$s['paused']=false;$s['remaining']=0;
        } elseif(ckmqp_show_story_questions_finished($s,$a))$s=ckmqp_show_story_open_answers_or_vote($s,$a,$now);
    } elseif ($command==='story_answer') {
        if ((int)$s['round']!==3 || $s['phase']!=='story_answer') return $fail('answer_closed','Сейчас ответ не принимается.');
        $active=ckmqp_show_active_slot($s);if($slot!==$active)return $fail('active_team_required','На вопросы отвечает рассказчик.');
        $a=(int)$s['attempt'];$q=(int)$s['storyQuestionIndex'];$sentQ=(int)($input['question_index']??-1);$body=(string)($input['text']??'');$rid=(string)($input['request_id']??'');
        if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$rid))return $fail('request_id_invalid','Обновите страницу и повторите отправку.');
        if(trim($body)===''||strlen($body)>8000)return $fail('message_invalid','Введите ответ длиной до 2000 символов.');
        if(!isset($s['storyAnswers'][$a])||!is_array($s['storyAnswers'][$a]))$s['storyAnswers'][$a]=[];
        foreach($s['storyAnswers'][$a] as $idx=>$old){if(($old['requestId']??'')===$rid){if((int)$idx===$sentQ&&(string)($old['text']??'')===$body)return ['ok'=>true,'session'=>$s,'duplicate'=>true];return $fail('request_conflict','Этот запрос уже использован для другого ответа.');}}
        if($sentQ!==$q)return $fail('stale_question','Этот вопрос уже закрыт. Отвечайте на текущий вопрос.');
        if(isset($s['storyAnswers'][$a][$q]))return $fail('answer_locked','Ответ на этот вопрос уже закрыт.');
        $s['storyAnswers'][$a][$q]=['questionIndex'=>$q,'text'=>$body,'at'=>$now,'requestId'=>$rid,'timedOut'=>false];
        $ordered=ckmqp_show_story_question_order($s,$a);
        if(ckmqp_show_team_count($s)===2){
            $next=null;foreach($ordered as $idx=>$row)if(!isset($s['storyAnswers'][$a][$idx])){$next=(int)$idx;break;}
            if($next!==null){$s['phase']='story_answer';$s['storyQuestionIndex']=$next;$limit=(int)($s['storyAnswerLimit']??0);$s['deadline']=$limit>0?$now+$limit:0;}
            elseif(ckmqp_show_story_questions_finished($s,$a)){$s['phase']='story_vote';$s['deadline']=0;}
            else{$s['phase']='story_questions';$s['storyQuestionIndex']=count($ordered);$s['deadline']=0;}
        } else {
            $last=max(0,count($ordered)-1);
            if($q<$last){$s['storyQuestionIndex']=$q+1;$limit=(int)($s['storyAnswerLimit']??0);$s['deadline']=$limit>0?$now+$limit:0;}else{$s['phase']='story_vote';$s['deadline']=0;}
        }
    } elseif ($command==='story_vote') {
        if ((int)$s['round']!==3 || $s['phase']!=='story_vote') return $fail('vote_closed','Сейчас голосование недоступно.');
        $active=ckmqp_show_active_slot($s);if($slot===$active)return $fail('opponent_required','Рассказчик не голосует за собственную историю.');
        $a=(int)$s['attempt'];$vote=(string)($input['vote']??'');if(!in_array($vote,['truth','distortion'],true))return $fail('vote_invalid','Выберите один из двух вариантов.');
        if(!isset($s['storyVotes'][$a])||!is_array($s['storyVotes'][$a]))$s['storyVotes'][$a]=[];
        if(isset($s['storyVotes'][$a][$slot])){if((string)$s['storyVotes'][$a][$slot]['vote']===$vote)return ['ok'=>true,'session'=>$s,'duplicate'=>true];return $fail('vote_locked','Голос вашей команды уже зафиксирован.');}
        $s['storyVotes'][$a][$slot]=['vote'=>$vote,'at'=>$now];
        if(count($s['storyVotes'][$a])===ckmqp_show_team_count($s)-1){$result=ckmqp_show_story_result($s,$a);if(!is_array($result))return $fail('vote_result_failed','Не удалось посчитать результат голосования.');$result['completedAt']=$now;$s['storyResults'][$a]=$result;$s['phase']='review';$s['deadline']=0;}
    } elseif ($command==='hard_answer') {
        if ((int)$s['round']!==2 || $s['phase']!=='hard_answer') return $fail('answer_closed','Сейчас ответ не принимается.');
        $active=ckmqp_show_active_slot($s);
        if ($slot!==$active) return $fail('active_team_required','На этот вопрос отвечает другая команда.');
        $q=(int)$s['questionIndex'];$sentQ=(int)($input['question_index']??-1);$body=(string)($input['text']??'');$rid=(string)($input['request_id']??'');
        if (!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$rid)) return $fail('request_id_invalid','Обновите страницу и повторите отправку.');
        if (trim($body)==='' || strlen($body)>8000) return $fail('message_invalid','Введите ответ длиной до 2000 символов.');
        $a=(int)$s['attempt'];if (!isset($s['hardAnswers'][$a])||!is_array($s['hardAnswers'][$a]))$s['hardAnswers'][$a]=[];
        foreach($s['hardAnswers'][$a] as $idx=>$old){if(($old['requestId']??'')===$rid){if((int)$idx===$sentQ&&(string)($old['text']??'')===$body)return ['ok'=>true,'session'=>$s,'duplicate'=>true];return $fail('request_conflict','Этот запрос уже использован для другого ответа.');}}
        if ($sentQ!==$q) return $fail('stale_question','Этот вопрос уже закрыт. Отвечайте на текущий вопрос.');
        if (isset($s['hardAnswers'][$a][$q])) return $fail('answer_locked','Ответ на этот вопрос уже закрыт.');
        $s['hardAnswers'][$a][$q]=['questionIndex'=>$q,'text'=>$body,'at'=>$now,'requestId'=>$rid,'timedOut'=>false];
        if ($q<2) {$s['questionIndex']=$q+1;$s['deadline']=0;} else {$s['phase']='review';$s['deadline']=0;}
    } elseif ($command==='message') {
        if ($s['phase']!=='dialogue') return $fail('dialogue_closed','Приём реплик закрыт.');
        if ((int)$s['round']===0 && ckmqp_show_role($slot,$s['attempt'],ckmqp_show_team_count($s))==='observer') return $fail('observer_read_only','Наблюдатель не участвует в диалоге.');
        $turnSlot=ckmqp_show_dialogue_turn_slot($s);
        if($turnSlot>0 && $slot!==$turnSlot)return $fail('not_your_turn','Сейчас реплику делает другая команда. Дождитесь своего хода.');
        if (trim($body)==='' || strlen($body)>8000) return $fail('message_invalid','Введите реплику длиной до 2000 символов.');
        $count=0;foreach($s['messages'] as $m) if((int)($m['round']??0)===(int)$s['round'] && $m['attempt']===$s['attempt'])$count++;
        if ((int)$s['round']===1) {
            $hiddenLimit=max(3,min(7,(int)($s['hiddenMessageLimit']??7)));
            if ($count >= $hiddenLimit) return $fail('message_limit','Достигнут общий лимит реплик в испытании.');
            $teamCount=ckmqp_show_hidden_message_count_for_slot($s,$slot);$teamCap=ckmqp_show_hidden_message_cap_for_slot($s,$slot);
            if ($teamCount >= $teamCap) return $fail('team_message_limit',$slot===ckmqp_show_active_slot($s)?'Активная команда уже использовала три реплики.':'Ваша команда уже использовала две реплики в этом испытании.');
        } elseif ($count>=100) return $fail('message_limit','Достигнут лимит 100 реплик на испытание.');
        $s['messages'][]=['id'=>count($s['messages'])+1,'teamId'=>$tid,'slot'=>$slot,'round'=>(int)$s['round'],'attempt'=>$s['attempt'],'text'=>$body,'at'=>$now,'requestId'=>$rid];
        $s['lastDialogueActivityAt']=$now;
        if ((int)$s['round']===1 && count(ckmqp_show_current_dialogue_messages($s)) >= max(3,min(7,(int)($s['hiddenMessageLimit']??7)))) {
            $s['phase']='review';$s['deadline']=0;$s['paused']=false;$s['remaining']=0;$s['dialogueClosedBy']='message_limit';
        }
    } elseif (in_array($command,['continue','next_round'],true)) {
        return $fail('confirmation_removed','Подтверждение перехода командами больше не требуется. Переход выполняет ведущий одной кнопкой.');
    } else return $fail('unknown_command','Неизвестное действие.');
    return ['ok'=>true,'session'=>$s];
}


function ckmqp_show_host_team_names(int $gameId): array {
    global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT slot_no,team_name FROM '.ckm_quiz_teams_table().' WHERE game_id=%d ORDER BY slot_no ASC',$gameId),ARRAY_A)?:[];$out=[];
    foreach($rows as $row)$out[(int)$row['slot_no']]=(string)$row['team_name'];
    return $out;
}
function ckmqp_show_host_round_name(int $round): string {
    return [0=>'Удержи цель',1=>'Скрытая задача',2=>'Неудобный вопрос',3=>'Проверь историю'][$round]??'Переговори другого';
}
function ckmqp_show_host_score_text(array $s,int $round,int $attempt,array $names): string {
    $review=null;
    if($round===0)$review=$s['reviews'][$attempt]['review']??null;
    elseif($round===1)$review=$s['hiddenReviews'][$attempt]['review']??null;
    elseif($round===2)$review=$s['hardReviews'][$attempt]['review']??null;
    elseif($round===3)$review=$s['storyReviews'][$attempt]['review']??null;
    if(!is_array($review))return '';
    $name=$names[$attempt+1]??('Команда '.($attempt+1));$total=(int)($review['total']??0);
    if(in_array($round,[0,1],true) && is_array($review['participants']??null)){
        $sp=$review['participants']['speaker']??[];$op=$review['participants']['opponent']??[];
        $teamCount=ckmqp_show_team_count($s);$spName=$names[(int)($sp['slot']??($attempt+1))]??$name;$opName=$names[(int)($op['slot']??((($attempt+1)%$teamCount)+1))]??'Оппонент';
        $winner=$review['winner']??[];$winnerText=(string)($winner['label']??'');
        if(($winner['type']??'')==='speaker')$winnerText='Победитель диалога — '.$spName;
        elseif(($winner['type']??'')==='opponent')$winnerText='Победитель диалога — '.$opName;
        $text=$spName.' — '.(int)($sp['total']??0).' баллов; '.$opName.' — '.(int)($op['total']??0).' баллов. '.$winnerText.'.';
    }else{$text=$name.'. Результат — '.$total.' баллов.';}
    $summary=trim((string)($review['summary']??''));$rec=trim((string)($review['recommendation']??''));
    if($summary!=='')$text.=' '.$summary;
    if($rec!=='')$text.=' Рекомендация: '.$rec;
    return $text;
}
function ckmqp_show_host_round_scores(array $s,int $round,array $names): string {
    $parts=[];
    foreach(ckmqp_show_slot_indexes($s) as $slot){
        $score=null;
        if($round===0 && function_exists('ckmqp_show_first_round_score'))$score=ckmqp_show_first_round_score($s,$slot);
        elseif($round===1 && function_exists('ckmqp_show_hidden_round_score'))$score=ckmqp_show_hidden_round_score($s,$slot);
        elseif($round===2 && function_exists('ckmqp_show_hard_round_score'))$score=ckmqp_show_hard_round_score($s,$slot);
        elseif($round===3 && function_exists('ckmqp_show_story_round_score'))$score=ckmqp_show_story_round_score($s,$slot);
        if($score!==null)$parts[]=($names[$slot]??('Команда '.$slot)).' — '.(int)$score;
    }
    return $parts?(' Счёт раунда: '.implode('; ',$parts).'.'):'';
}
function ckmqp_show_publish_ai_host_transition(array $game,array $before,array $after): void {
    if((string)($game['host_mode_snapshot']??'')!=='ai' || !function_exists('ckm_quiz_publish_ai_host_event'))return;
    if($before===$after)return;
    $gid=(int)($game['id']??0);if($gid<1)return;
    $round=(int)($after['round']??0);$attempt=(int)($after['attempt']??0);$phase=(string)($after['phase']??'');
    $oldRound=(int)($before['round']??0);$oldAttempt=(int)($before['attempt']??0);$oldPhase=(string)($before['phase']??'');
    $names=ckmqp_show_host_team_names($gid);$active=$names[$attempt+1]??('Команда '.($attempt+1));$text='';$event='negotiation_show_stage';$suffix='';

    if($oldPhase==='dialogue' && $phase==='dialogue' && (int)($before['aiDialogueIdlePromptedAt']??0)===0 && (int)($after['aiDialogueIdlePromptedAt']??0)>0){
        $seq=max(1,(int)($after['aiDialogueIdlePromptSeq']??1));
        $event='negotiation_show_dialogue_idle_prompt';$suffix="r{$round}-a{$attempt}-idle-prompt-{$seq}";
        $text='Есть ли что добавить? Если новых реплик не будет, через 15 секунд я завершу диалог и перейду к оценке.';
    } elseif($oldPhase==='waiting' && $phase==='dialogue' && in_array($round,[0,1],true)){
        $event='negotiation_show_dialogue_started';$suffix="r{$round}-a{$attempt}-dialogue";
        $limit=max(0,(int)($after['dialogueLimit']??0));$messageNote=$round===1?' Максимум реплик в испытании — '.max(3,min(7,(int)($after['hiddenMessageLimit']??7))).'.':'';
        $starterSlot=ckmqp_show_dialogue_starter_slot($after);$starterName=$names[$starterSlot]??('Команда '.$starterSlot);$starterRole=$starterSlot>0?ckmqp_show_dialogue_role_label($after,$starterSlot):'';$starterNote=$starterSlot>0?' Первую реплику делает '.$starterName.($starterRole!==''?' — '.$starterRole:'').'. Далее команды отвечают строго по очереди.':'';
        $text='Раунд '.($round+1).' из 4 — «'.ckmqp_show_host_round_name($round).'». Активная команда — '.$active.'. Подготовка без отсчёта: диалог открыт сразу. '.($limit>0?'Лимит диалога — '.$limit.' секунд.':'Обязательного таймера нет.').$starterNote.$messageNote;
    } elseif($oldPhase==='waiting' && $phase==='preparation'){
        $event='negotiation_show_preparation_started';$suffix="r{$round}-a{$attempt}-prep";
        if($round===3)$text='Раунд 4 из 4 — «Проверь историю». Рассказчик — '.$active.'. Нажмите «Готов рассказать», когда будете готовы.';
    } elseif($oldPhase==='waiting' && $phase==='hard_answer' && $round===2){
        $questions=ckmqp_show_hard_questions($after)[$attempt]??[];$q=(int)($after['questionIndex']??0);
        $event='negotiation_show_hard_question';$suffix="r2-a{$attempt}-q{$q}";
        $text='Раунд 3 из 4 — «Неудобный вопрос». Отвечает '.$active.'. Первый вопрос: '.($questions[$q]??'').'.';
    } elseif($oldPhase==='preparation' && $phase==='dialogue' && in_array($round,[0,1],true)){
        $event='negotiation_show_dialogue_started';$suffix="r{$round}-a{$attempt}-dialogue";
        $limit=max(0,(int)($after['dialogueLimit']??0));$messageNote=$round===1?' Максимум реплик в испытании — '.max(3,min(7,(int)($after['hiddenMessageLimit']??7))).'.':'';
        $starterSlot=ckmqp_show_dialogue_starter_slot($after);$starterName=$names[$starterSlot]??('Команда '.$starterSlot);$starterRole=$starterSlot>0?ckmqp_show_dialogue_role_label($after,$starterSlot):'';$starterNote=$starterSlot>0?' Первую реплику делает '.$starterName.($starterRole!==''?' — '.$starterRole:'').'. Далее — строгая очередность.':'';
        $text='Начинаем '.($round===0?'переговорный диалог':'разговор со скрытыми задачами').'. '.($limit>0?'Лимит — '.$limit.' секунд.':'Обязательного таймера нет; я завершу диалог после содержательного результата или достаточного числа реплик.').$starterNote.$messageNote;
    } elseif($oldPhase==='dialogue' && $phase==='review' && in_array($round,[0,1],true)){
        $event='negotiation_show_review_started';$suffix="r{$round}-a{$attempt}-review";
        $closedBy=(string)($after['dialogueClosedBy']??'');$text=$closedBy==='timer'?'Лимит диалога завершён. Реплики зафиксированы. ИИ-арбитр оценивает результат.':($closedBy==='host'?'Ведущий завершил диалог. Реплики зафиксированы. ИИ-арбитр оценивает результат.':($closedBy==='message_limit'?'Достигнут лимит реплик. Диалог автоматически завершён. ИИ-арбитр оценивает результат.':($closedBy==='ai_natural'?'Диалог естественно завершён. Реплики зафиксированы. Перехожу к оценке.':($closedBy==='ai_idle'?'Новых реплик нет. Завершаю диалог и перехожу к оценке.':'Диалог завершён. Реплики зафиксированы. ИИ-арбитр оценивает результат.'))));
    } elseif($oldPhase==='preparation' && $phase==='story_tell' && $round===3){
        $event='negotiation_show_story_started';$suffix="r3-a{$attempt}-tell";
        $limit=max(0,(int)($after['storyLimit']??0));$text=$active.', начинайте историю. '.($limit>0?'На рассказ — до '.$limit.' секунд.':'Зафиксируйте рассказ, когда закончите.');
    } elseif($oldPhase==='story_tell' && $phase==='story_questions' && $round===3){
        $event='negotiation_show_story_questions';$suffix="r3-a{$attempt}-questions";
        $checkerCount=max(1,ckmqp_show_team_count($after)-1);
        $text=$checkerCount===1
            ? 'История зафиксирована. Проверяющая команда, задайте два уточняющих вопроса.'
            : 'История зафиксирована. Две проверяющие команды, задайте по два уточняющих вопроса.';
    } elseif($oldPhase==='story_questions' && $phase==='story_answer' && $round===3){
        $ordered=function_exists('ckmqp_show_story_question_order')?ckmqp_show_story_question_order($after,$attempt):[];$q=(int)($after['storyQuestionIndex']??0);
        $event='negotiation_show_story_answer';$suffix="r3-a{$attempt}-q{$q}";
        $limit=max(0,(int)($after['storyAnswerLimit']??0));$text='Вопрос первый: '.(string)($ordered[$q]['text']??'').($limit>0?' На ответ — до '.$limit.' секунд.':' Ответьте на вопрос.');
    } elseif($round===3 && $oldPhase==='story_answer' && $phase==='story_answer' && (int)($after['storyQuestionIndex']??0)!==(int)($before['storyQuestionIndex']??0)){
        $ordered=function_exists('ckmqp_show_story_question_order')?ckmqp_show_story_question_order($after,$attempt):[];$q=(int)($after['storyQuestionIndex']??0);
        $event='negotiation_show_story_answer';$suffix="r3-a{$attempt}-q{$q}";
        $limit=max(0,(int)($after['storyAnswerLimit']??0));$text='Следующий вопрос: '.(string)($ordered[$q]['text']??'').($limit>0?' На ответ — до '.$limit.' секунд.':' Ответьте на вопрос.');
    } elseif($round===3 && $oldPhase==='story_answer' && $phase==='story_vote'){
        $event='negotiation_show_story_vote';$suffix="r3-a{$attempt}-vote";
        $checkerCount=max(1,ckmqp_show_team_count($after)-1);
        $text=$checkerCount===1
            ? 'Ответы завершены. Проверяющая команда, проголосуйте: «Соответствует досье» или «Есть существенное искажение».'
            : 'Ответы завершены. Проверяющие команды, независимо проголосуйте: «Соответствует досье» или «Есть существенное искажение».';
    } elseif($round===3 && $oldPhase==='story_vote' && $phase==='review'){
        $result=$after['storyResults'][$attempt]??[];$mode=(string)($result['modeLabel']??'');
        $event='negotiation_show_story_result';$suffix="r3-a{$attempt}-result";
        $text='Голосование завершено. '.($mode!==''?'Правильное определение: '.$mode.'. ':'').'Отдельные очки за голосование не начисляются. Запускаю оценку рассказчика по специальной шкале Раунда 4.';
    } elseif($round===2 && $phase==='hard_answer' && (int)($after['questionIndex']??0)!==(int)($before['questionIndex']??0)){
        $questions=ckmqp_show_hard_questions($after)[$attempt]??[];$q=(int)($after['questionIndex']??0);
        $event='negotiation_show_hard_question';$suffix="r2-a{$attempt}-q{$q}";
        $text='Следующий неудобный вопрос: '.($questions[$q]??'').'.';
    } elseif($round===2 && $oldPhase==='hard_answer' && $phase==='review'){
        $event='negotiation_show_review_started';$suffix="r2-a{$attempt}-review";
        $text='Три ответа завершены. ИИ-арбитр оценивает раунд «Неудобный вопрос».';
    } else {
        $reviewDone=false;
        if($round===0)$reviewDone=(($before['reviews'][$attempt]['status']??'')!=='done' && ($after['reviews'][$attempt]['status']??'')==='done');
        elseif($round===1)$reviewDone=(($before['hiddenReviews'][$attempt]['status']??'')!=='done' && ($after['hiddenReviews'][$attempt]['status']??'')==='done');
        elseif($round===2)$reviewDone=(($before['hardReviews'][$attempt]['status']??'')!=='done' && ($after['hardReviews'][$attempt]['status']??'')==='done');
        elseif($round===3)$reviewDone=(($before['storyReviews'][$attempt]['status']??'')!=='done' && ($after['storyReviews'][$attempt]['status']??'')==='done');
        if($reviewDone){$event='negotiation_show_review_finished';$suffix="r{$round}-a{$attempt}-review-done";$text=ckmqp_show_host_score_text($after,$round,$attempt,$names);}
        elseif($oldRound!==$round && in_array($phase,['dialogue','preparation','hard_answer'],true)){
            $event='negotiation_show_round_started';$suffix="r{$round}-start";
            $prepText=$round===3?'Рассказчик знакомится с досье и нажимает «Готов рассказать».':'Свободный диалог открыт сразу.';$text='Начинается раунд '.($round+1).' из 4 — «'.ckmqp_show_host_round_name($round).'». '.($phase==='preparation'?$prepText:($phase==='dialogue'?$prepText:'Первый вопрос уже открыт.'));
        } elseif($oldAttempt!==$attempt && in_array($phase,['dialogue','preparation','hard_answer'],true)){
            $event='negotiation_show_next_attempt';$suffix="r{$round}-a{$attempt}-start";
            $prepText=$round===3?'После подготовки рассказчика нажмите «Готов рассказать».':'Диалог открыт сразу.';$text='Следующее испытание раунда «'.ckmqp_show_host_round_name($round).'». Активная команда — '.$active.'. '.($phase==='preparation'?$prepText:($phase==='dialogue'?$prepText:'Первый вопрос уже открыт.'));
        }
    }
    if(trim($text)==='' || $suffix==='')return;
    @ckm_quiz_publish_ai_host_event($gid,$event,null,['showHostText'=>$text,'showRound'=>$round,'showAttempt'=>$attempt],'ai-negotiation-show-'.$suffix);
}

/** Serialize concurrent polls, readiness, pause and messages on one InnoDB row. */
function ckmqp_show_session(array $game,array $actor,array $input): array {
    global $wpdb;
    if (get_option('ckmqp_show_dialogue_schema')!=='1') return ['ok'=>false,'error'=>'Не удалось подготовить таблицу диалога. Обратитесь к организатору.'];
    $table=ckmqp_show_table();$gid=(int)$game['id'];$tenant=(int)($game['tenant_id']??0);
    try {
        if ($wpdb->query('START TRANSACTION')===false) throw new RuntimeException('begin');
        $initial=wp_json_encode(ckmqp_show_initial($game),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$table} (game_id,tenant_id,state_json,updated_at) VALUES (%d,%d,%s,%s)",$gid,$tenant,$initial,current_time('mysql')))===false) throw new RuntimeException('insert');
        $row=$wpdb->get_row($wpdb->prepare("SELECT state_json FROM {$table} WHERE game_id=%d AND tenant_id=%d FOR UPDATE",$gid,$tenant),ARRAY_A);
        if (!$row) throw new RuntimeException('row');
        $s=json_decode($row['state_json'],true);
        if (!is_array($s) || ($s['version']??0)!==1) throw new RuntimeException('version');
        if(!isset($s['content']) || !is_array($s['content'])) $s['content']=ckm_quiz_pro_persuade_me_content_from_game($game);
        $s=ckmqp_show_normalize_state($s);
        if(!isset($s['dialogueLimitConfigured'])){
            $s['dialogueLimit']=ckmqp_show_dialogue_limit_from_game($game);
            $s['dialogueLimitConfigured']=true;
            if($s['phase']==='dialogue' && (int)$s['dialogueLimit']<=0){$s['deadline']=0;$s['paused']=false;$s['remaining']=0;}
        }
        if(!isset($s['hiddenMessageLimitConfigured'])){
            $s['hiddenMessageLimit']=ckmqp_show_hidden_message_limit_from_game($game);
            $s['hiddenMessageLimitConfigured']=true;
        }
        if(!isset($s['storyLimitConfigured'])){
            $s['storyLimit']=ckmqp_show_story_limit_from_game($game);
            $s['storyLimitConfigured']=true;
            if((int)$s['round']===3 && $s['phase']==='preparation'){$s['deadline']=0;$s['paused']=false;$s['remaining']=0;}
            if((int)$s['round']===3 && $s['phase']==='story_tell' && (int)$s['storyLimit']<=0){$s['deadline']=0;$s['paused']=false;$s['remaining']=0;}
        }
        if(!isset($s['hardAnswerLimitConfigured'])){
            $s['hardAnswerLimit']=ckmqp_show_hard_answer_limit_from_game($game);
            $s['hardAnswerLimitConfigured']=true;
        }
        $s=ckmqp_show_apply_story_answer_policy($s,$game);
        $before=$s;$now=time();
        $result=ckmqp_show_reduce($s,$actor,$input,$now);
        // Do not trust the game snapshot supplied by a poll that may have started
        // before AI → manual takeover. Re-read the authoritative host mode right
        // before any automatic AI state transition.
        $modeGame=ckm_quiz_get_game($gid) ?: $game;
        if(!empty($result['ok']) && (string)($modeGame['host_mode_snapshot']??'')==='ai'){
            $result['session']=ckmqp_show_ai_dialogue_advance($result['session'],$now,(string)($input['command']??'poll'));
        }
        if ($result['session']!==$before) $result['session']['revision']=(int)($before['revision']??0)+1;
        if ($result['session']!==$before && $wpdb->update($table,['state_json'=>wp_json_encode($result['session'],JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')],['game_id'=>$gid,'tenant_id'=>$tenant])===false) throw new RuntimeException('update');
        if ($wpdb->query('COMMIT')===false) throw new RuntimeException('commit');
        if (!empty($result['ok']) && $result['session']!==$before) {
            $publishGame=ckm_quiz_get_game($gid) ?: $modeGame;
            ckmqp_show_publish_ai_host_transition($publishGame,$before,$result['session']);
        }
        if (!empty($result['ok']) && ($result['session']['phase']??'')==='game_complete' && function_exists('ckmqp_show_finalize_game')) @ckmqp_show_finalize_game($game,$result['session']);
        return $result;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM show dialogue persistence: '.$e->getMessage());
        return ['ok'=>false,'error'=>'Не удалось сохранить состояние. Повторите действие.'];
    }
}

function ckmqp_show_dialogue_project(array $game,array $auth,array $teams,array $s): array {
    $s=ckmqp_show_normalize_state($s);
    $out=ckmqp_show_project($game,$auth,$teams);
    $mine=null;
    if ((int)($auth['game_id']??0)===(int)$game['id'] && ($auth['role']??'')==='participant') foreach($teams as $t) if((int)$t['id']===(int)($auth['team_id']??0)){$mine=$t;break;}
    $round=(int)$s['round'];$slot=$mine?(int)$mine['slot_no']:0;$role=$mine?ckmqp_show_role($slot,$s['attempt'],ckmqp_show_team_count($s)):'';
    $show=&$out['negotiationShow'];unset($show['brief']);
    if (in_array(($auth['role']??''),['host','admin'],true)) $show['roleTitle']='Организатор';
    $show['revision']=(int)($s['revision']??0);$show['teamCount']=ckmqp_show_team_count($s);$show['round']=$round;$show['stage']=$s['phase'];$show['attempt']=$s['attempt'];
    if ($round===0) {
        $case=ckmqp_show_cases($s)[$s['attempt']];
        $show['roundTitle']='Удержи цель';
        $show['situation']='Удержи цель · испытание '.($s['attempt']+1).' из '.ckmqp_show_team_count($s).'. '.$case['situation'];
        if ($mine) {
            $show['roleTitle']=['speaker'=>'Переговорщик','opponent'=>'Оппонент','observer'=>'Наблюдатель'][$role];
            $show['brief']=$role==='observer'?(string)ckmqp_show_content($s)['round1']['observerBrief']:$case[$role];
        }
    } elseif ($round===1) {
        $active=ckmqp_show_active_slot($s);$tasks=ckmqp_show_hidden_tasks($s)[$s['attempt']];
        $show['roundTitle']='Скрытая задача';
        $show['situation']='Скрытая задача · испытание '.($s['attempt']+1).' из '.ckmqp_show_team_count($s).'. '.ckmqp_show_hidden_situation($s);
        if ($mine) {
            if ($slot===$active) {
                $show['roleTitle']='Исполнитель скрытых задач';
                $show['brief']="Ваши три скрытые задачи:\n1. {$tasks[0]}\n2. {$tasks[1]}\n3. {$tasks[2]}\n\nНе называйте задания напрямую. Скрытые задачи задают цель поведения, но отдельных очков за них нет: после диалога применяется единая шкала 7 критериев по 0–10.";
            } else {
                $show['roleTitle']='Собеседник';
                $show['brief']=(string)ckmqp_show_content($s)['round2']['partnerBrief'];
            }
        }
        $show['revealedTasks']=in_array($s['phase'],['review','round2_complete'],true)?$tasks:[];
    } elseif ($round===2) {
        $active=ckmqp_show_active_slot($s);$q=(int)$s['questionIndex'];$questions=ckmqp_show_hard_questions($s)[$s['attempt']]??[];
        $show['roundTitle']='Неудобный вопрос';
        $show['hardQuestionIndex']=$q;$show['hardQuestionsTotal']=3;$show['activeSlot']=$active;
        $show['situation']='Неудобный вопрос · команда '.($s['attempt']+1).' из '.ckmqp_show_team_count($s).' · вопрос '.min(3,$q+1).' из 3. '.($questions[$q]??'Ответы завершены.');
        if ($mine) {
            if ($slot===$active) {$show['roleTitle']='Отвечающая команда';$show['brief']=(string)ckmqp_show_content($s)['round3']['answerBrief'];}
            else {$show['roleTitle']='Оппонент';$show['brief']='Следите за ответами другой стороны и готовьтесь к своему блоку неудобных вопросов.';}
        }
        $show['hardAnswers']=[];foreach(($s['hardAnswers'][$s['attempt']]??[]) as $idx=>$ans)$show['hardAnswers'][]=['questionIndex'=>(int)$idx,'question'=>$questions[$idx]??'','text'=>(string)($ans['text']??''),'timedOut'=>!empty($ans['timedOut']),'endedEarly'=>!empty($ans['endedEarly'])];
    } else {
        $active=ckmqp_show_active_slot($s);$dossier=ckmqp_show_story_dossiers($s)[$s['attempt']]??[];$mode=(string)($s['storyModes'][$s['attempt']]??'truth');$q=(int)($s['storyQuestionIndex']??0);$ordered=ckmqp_show_story_question_order($s,(int)$s['attempt']);
        $show['roundTitle']='Проверь историю';$show['activeSlot']=$active;
        $phase=$s['phase'];
        if($phase==='story_tell')$show['situation']='Проверь историю · рассказ команды '.($s['attempt']+1).' из '.ckmqp_show_team_count($s).'. '.((int)($s['storyLimit']??0)>0?'На историю — до '.(int)$s['storyLimit'].' секунд.':'Рассказ.');
        elseif($phase==='story_questions')$show['situation']=ckmqp_show_team_count($s)===2
            ? 'Проверь историю · проверяющая команда задаёт два уточняющих вопроса.'
            : 'Проверь историю · две проверяющие команды задают по два уточняющих вопроса.';
        elseif($phase==='story_answer')$show['situation']='Проверь историю · вопрос '.min(max(1,count($ordered)),$q+1).' из '.max(1,count($ordered)).'. '.($ordered[$q]['text']??'Ответы завершены.');
        elseif($phase==='story_vote')$show['situation']='Проверь историю · тайное голосование: соответствует ли рассказ закрытому досье?';
        elseif(in_array($phase,['review','game_complete'],true))$show['situation']='Проверь историю · результат раскрыт.';
        else $show['situation']='Проверь историю · команда '.($s['attempt']+1).' из '.ckmqp_show_team_count($s).' готовится к рассказу.';
        if($mine){
            if($slot===$active){
                $facts=implode("\n",array_map(static fn($x)=>'• '.$x,$dossier['facts']??[]));$secret=$mode==='distortion'?('СЕКРЕТНЫЙ РЕЖИМ: есть существенное искажение. '.($dossier['distortion']??'')):'СЕКРЕТНЫЙ РЕЖИМ: рассказ должен полностью соответствовать досье. Не изменяйте факты.';
                $storyRule=(int)($s['storyLimit']??0)>0?'На рассказ — до '.(int)$s['storyLimit'].' секунд.':'Рассказ.';$show['roleTitle']='Рассказчик';$show['brief']='Тема: '.($dossier['title']??'')."\n\nОпорные факты:\n".$facts."\n\n".$secret."\n\nКогда будете готовы, нажмите «Готов рассказать». ".$storyRule." Затем ответьте на уточняющие вопросы соперника ".((int)($s['storyAnswerLimit']??0)>0?'— до '.(int)$s['storyAnswerLimit'].' секунд на каждый.':'без ограничения времени.');
            } else {$show['roleTitle']='Проверяющая команда';$show['brief']=(string)ckmqp_show_content($s)['round4']['checkerBrief'];}
        }
    }
    $names=[];foreach($teams as $t)$names[(int)$t['id']]=(string)$t['team_name'];
    $show['messages']=[];
    foreach($s['messages'] as $m) $show['messages'][]=['id'=>$m['id'],'teamName'=>$names[$m['teamId']]??'Команда','round'=>(int)($m['round']??0),'attempt'=>$m['attempt'],'text'=>$m['text'],'at'=>$m['at']];
    $show['roles']=[];
    foreach($teams as $t){
        if($round===0)$label=['speaker'=>'Переговорщик','opponent'=>'Оппонент','observer'=>'Наблюдатель'][ckmqp_show_role((int)$t['slot_no'],$s['attempt'],ckmqp_show_team_count($s))];
        elseif($round===1)$label=((int)$t['slot_no']===ckmqp_show_active_slot($s))?'Исполнитель':'Собеседник';
        elseif($round===2)$label=((int)$t['slot_no']===ckmqp_show_active_slot($s))?'Отвечает':'Оппонент';
        else $label=((int)$t['slot_no']===ckmqp_show_active_slot($s))?'Рассказчик':'Проверяет историю';
        $show['roles'][]=['name'=>$t['team_name'],'role'=>$label];
    }
    $show['readyCount']=count($s['ready']);$show['nextCount']=0;$show['roundNextCount']=0;
    $show['initialReady']=ckmqp_show_is_initial_waiting($s);
    $show['canReady']=$mine && $show['initialReady'] && !in_array($slot,$s['ready'],true);
    $show['canStoryReady']=$mine && $round===3 && $s['phase']==='preparation' && $slot===ckmqp_show_active_slot($s);
    $show['storyLimit']=max(0,(int)($s['storyLimit']??0));
    $show['hardAnswerLimit']=0;
    $show['storyAnswerLimit']=max(0,(int)($s['storyAnswerLimit']??0));
    $show['hasContinued']=false;$show['hasNextRoundConfirmed']=false;
    $show['currentReviewDone']=ckmqp_show_current_review_done($s);
    $show['canManualReview']=($auth['role']??'')==='host' && (int)($auth['game_id']??0)===(int)$game['id'] && function_exists('ckmqp_show_manual_review_available') && ckmqp_show_manual_review_available($s,time());
    $show['canRestartAttempt']=in_array(($auth['role']??''),['host','admin'],true) && (int)($auth['game_id']??0)===(int)$game['id'] && ckmqp_show_can_restart_empty_failed_attempt($s);
    $show['canContinue']=false;$show['canNextRound']=false;
    $show['canAdvance']=in_array(($auth['role']??''),['host','admin'],true) && (($s['phase']==='review' && $show['currentReviewDone']) || in_array($s['phase'],['round_complete','round2_complete','round3_complete'],true));
    $currentDialogueMessages=ckmqp_show_current_dialogue_messages($s);
    $show['messageCount']=count($currentDialogueMessages);
    $show['hiddenMessageLimit']=max(3,min(7,(int)($s['hiddenMessageLimit']??7)));
    $show['teamMessageCount']=$mine && $round===1?ckmqp_show_hidden_message_count_for_slot($s,$slot):0;
    $show['teamMessageMax']=$mine && $round===1?ckmqp_show_hidden_message_cap_for_slot($s,$slot):0;
    $hiddenQuotaOpen=$round!==1 || ($show['messageCount']<$show['hiddenMessageLimit'] && (!$mine || $show['teamMessageCount']<$show['teamMessageMax']));
    $turnSlot=ckmqp_show_dialogue_turn_slot($s);$turnTeamName='';foreach($teams as $t)if((int)$t['slot_no']===$turnSlot){$turnTeamName=(string)$t['team_name'];break;}
    $show['turnSlot']=$turnSlot;$show['turnTeamName']=$turnTeamName;$show['starterRole']=ckmqp_show_dialogue_starter_role($s);$show['turnRoleTitle']=$turnSlot>0?ckmqp_show_dialogue_role_label($s,$turnSlot):'';$show['isMyTurn']=$mine&&$turnSlot>0&&$slot===$turnSlot;$show['strictTurnOrder']=ckmqp_show_team_count($s)===2&&in_array($round,[0,1],true);
    $dialogueTurnOpen=$turnSlot<1 || ($mine && $slot===$turnSlot);
    $storyQuestionOpen=$round!==3 || empty($s['storyQuestionDone'][$s['attempt']][$slot]);
    $show['canMessage']=$mine && (($s['phase']==='dialogue' && ($round===1 || $role!=='observer') && $hiddenQuotaOpen && $dialogueTurnOpen) || ($round===2 && $s['phase']==='hard_answer' && $slot===ckmqp_show_active_slot($s)) || ($round===3 && (($s['phase']==='story_tell' && $slot===ckmqp_show_active_slot($s)) || ($s['phase']==='story_questions' && $slot!==ckmqp_show_active_slot($s) && count($s['storyQuestions'][$s['attempt']][$slot]??[])<2 && $storyQuestionOpen) || ($s['phase']==='story_answer' && $slot===ckmqp_show_active_slot($s))))) && !$s['paused'];
    $participantCanFinish=false;
    if($mine&&!$s['paused']){
        if($round===0&&$s['phase']==='dialogue'&&$role!=='observer')$participantCanFinish=true;
        elseif($round===1&&$s['phase']==='dialogue'&&$slot===ckmqp_show_active_slot($s))$participantCanFinish=true;
        elseif($round===2&&$s['phase']==='hard_answer'&&$slot===ckmqp_show_active_slot($s))$participantCanFinish=true;
        elseif($round===3&&$s['phase']==='story_questions'&&$slot!==ckmqp_show_active_slot($s)&&$storyQuestionOpen)$participantCanFinish=true;
        elseif($round===3&&$s['phase']==='story_answer'&&$slot===ckmqp_show_active_slot($s))$participantCanFinish=true;
    }
    $hostCanFinish=($auth['role']??'')==='host'&&in_array($round,[0,1],true)&&$s['phase']==='dialogue';
    $show['canFinishDialogue']=$participantCanFinish||$hostCanFinish;
    $show['finishDialogueConfirm']=$round===2?'Завершить ответы? Оставшиеся вопросы будут отмечены как оставшиеся без ответа, после чего начнётся оценка.':($round===3&&$s['phase']==='story_questions'?'Подтвердить, что у вашей команды больше нет вопросов? После этого игра продолжится.':($round===3&&$s['phase']==='story_answer'?'Завершить ответы рассказчика? Оставшиеся ответы будут отмечены как оставшиеся без ответа, затем начнётся голосование.':'Завершить диалог и передать его на оценку?'));
    $show['paused']=$s['paused'];$show['remaining']=$s['remaining'];$show['deadline']=$s['paused']?0:$s['deadline'];
    $show['dialogueLimit']=max(0,(int)($s['dialogueLimit']??0));
    $show['dialogueClosedBy']=(string)($s['dialogueClosedBy']??'');
    $activity=max((int)($s['lastDialogueActivityAt']??0),(int)($s['dialogueStartedAt']??0));
    $show['dialogueIdleSeconds']=$s['phase']==='dialogue'&&$activity>0?max(0,time()-$activity):0;
    $show['aiDialogueNaturalAt']=(int)($s['aiDialogueNaturalAt']??0);
    $show['aiDialogueIdlePromptedAt']=(int)($s['aiDialogueIdlePromptedAt']??0);
    $autoCloseAt=0;if($show['aiDialogueNaturalAt']>0)$autoCloseAt=$show['aiDialogueNaturalAt']+ckmqp_show_ai_natural_grace_seconds();elseif($show['aiDialogueIdlePromptedAt']>0)$autoCloseAt=$show['aiDialogueIdlePromptedAt']+ckmqp_show_ai_idle_close_seconds();
    $show['aiDialogueAutoCloseAt']=$autoCloseAt;
    $out['game']['questionDeadlineUnix']=$show['deadline'];
    if($round===3)return ckmqp_show_story_project($out,$s,$teams,$auth);
    if($round===2)return ckmqp_show_project_hard_reviews($out,$s,$teams,$auth);
    return $round===1 ? ckmqp_show_project_hidden_reviews($out,$s,$teams,$auth) : ckmqp_show_project_reviews($out,$s,$teams,$auth);
}


/** Switch «Переговори другого» between AI host and human host without resetting the round. */
function ckmqp_show_switch_host_mode(array $game, string $targetMode, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''), ['host','admin'], true)) return ckm_quiz_error(403,'Сменить ведущего может только организатор.','host_required');
    $targetMode=sanitize_key($targetMode);
    if (!in_array($targetMode,['ai','human'],true)) return ckm_quiz_error(422,'Неизвестный режим ведущего.','host_mode_invalid');
    if (!ckmqp_show_is_game($game)) return ckm_quiz_error(409,'Переключение доступно только в игре «Переговори другого».','negotiation_show_required');
    if ((string)($game['status'] ?? '')==='finished') return ckm_quiz_error(409,'Игра уже завершена.','game_finished');
    $current=(string)($game['host_mode_snapshot'] ?? $game['host_mode'] ?? 'ai');
    if ($current===$targetMode) return ckm_quiz_result(true,200,['action'=>'host_mode_unchanged','hostMode'=>$current,'game'=>$game]);
    if ($targetMode==='ai' && function_exists('ckm_quiz_ai_host_is_configured') && !ckm_quiz_ai_host_is_configured()) return ckm_quiz_error(409,'ИИ-ведущий сейчас не настроен.','ai_host_unavailable');

    global $wpdb;
    $now=current_time('mysql');
    $table=ckm_quiz_games_table();
    $ok=$wpdb->query($wpdb->prepare("UPDATE {$table} SET host_mode_snapshot=%s,host_mode=%s,state_version=state_version+1,updated_at=%s WHERE id=%d",$targetMode,$targetMode,$now,(int)$game['id']));
    if ($ok===false) return ckm_quiz_error(500,'Не удалось переключить ведущего.','host_switch_failed');
    $fresh=ckm_quiz_get_game((int)$game['id']);
    if (!$fresh) return ckm_quiz_error(500,'Не удалось прочитать состояние после переключения.','host_switch_reload_failed');
    $action=$targetMode==='ai'?'ai_host_takeover':'human_host_takeover';
    ckm_quiz_append_event((int)$game['id'],$action,'host',(int)($auth['user_id']??0),0,0,'game',(int)$game['id'],['from'=>$current,'to'=>$targetMode,'format'=>'negotiation_duel'],'negotiation-host-switch-'.(int)$game['id'].'-'.$targetMode.'-'.(int)($fresh['state_version']??0));
    if ($targetMode==='ai' && function_exists('ckm_quiz_publish_ai_host_event')) {
        $text='Управление передано ИИ-ведущему. Продолжаем «Переговори другого» с текущего состояния без сброса раунда.';
        @ckm_quiz_publish_ai_host_event((int)$game['id'],'negotiation_show_round_started',null,['showHostText'=>$text],'ai-negotiation-show-takeover-'.(int)$game['id'].'-'.(int)($fresh['state_version']??0));
    }
    return ckm_quiz_result(true,200,['action'=>$action,'hostMode'=>$targetMode,'game'=>$fresh]);
}

function ckmqp_show_ajax_action(): void {
    $game=ckm_quiz_pro_find_game((string)($_POST['game']??''));
    if (!$game || !ckmqp_show_is_game($game)) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    $command=sanitize_key((string)($_POST['command']??''));
    if (($game['status']??'')==='finished') wp_send_json(['ok'=>false,'error'=>'Игра завершена.'],409);
    if (in_array($command,['pause','resume','advance','restart_attempt','switch_ai','switch_human','manual_review'],true) || ($command==='finish_dialogue' && ($_POST['role']??'')==='host') || ($command==='review' && ($_POST['role']??'')==='host')) {
        if (!ckm_quiz_secret_equal((string)($_POST['token']??''),(string)$game['host_token']) || !ckm_quiz_secret_equal((string)($_POST['nonce']??''),(string)$game['host_nonce'])) wp_send_json(['ok'=>false,'error'=>'Неверная ссылка ведущего.'],403);
        $actor=['role'=>'host'];$auth=['role'=>'host','game_id'=>(int)$game['id']];
    } else {
        $v=ckm_quiz_pro_validate_team_request($game,(string)($_POST['team']??''),(string)($_POST['token']??''),(string)($_POST['nonce']??''));
        if (empty($v['ok'])) ckm_quiz_pro_json_error($v);
        $uid=absint($_POST['user_id']??0);$member=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null;
        if (!$member || (int)$member['team_id']!==(int)$v['team']['id'] || ($member['member_status']??'active')!=='active') wp_send_json(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
        $actor=['role'=>'participant','team_id'=>(int)$v['team']['id'],'slot'=>(int)$v['team']['slot_no']];
        $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>$actor['team_id'],'user_id'=>$uid];
    }
    if (in_array($command,['switch_ai','switch_human'],true)) {
        $switch=ckmqp_show_switch_host_mode($game,$command==='switch_ai'?'ai':'human',$auth);
        if (empty($switch['ok'])) wp_send_json(['ok'=>false,'error'=>$switch['error']??'Не удалось переключить ведущего.','code'=>$switch['code']??'host_switch_failed'],(int)($switch['status']??409));
        $fresh=ckm_quiz_pro_find_game((string)$game['game_code']) ?: ($switch['game']??$game);
        wp_send_json(['ok'=>true,'state'=>ckmqp_show_state($fresh,$auth)]);
    }
    if ($command==='review') {
        $round=(int)($_POST['round']??0);$attempt=(int)($_POST['attempt']??-1);
        $result=$round===3?ckmqp_show_run_story_review($game,$attempt):($round===2?ckmqp_show_run_hard_review($game,$attempt):($round===1?ckmqp_show_run_hidden_review($game,$attempt):ckmqp_show_run_review($game,$attempt)));
        if (empty($result['ok'])) wp_send_json(['ok'=>false,'error'=>$result['error']??'Не удалось запустить оценку.'],409);
        wp_send_json(['ok'=>true,'state'=>ckmqp_show_state($game,$auth)]);
    }
    if($command==='manual_review') {
        /*
         * Manual review now uses the same seven-criterion review contract as the
         * AI arbiter. The old score0/score1/score2 transport is intentionally
         * rejected here so a stale client can never write a legacy-shaped result.
         */
        $rawReview=$_POST['review']??null;
        if(is_string($rawReview)){
            $rawReview=wp_unslash($rawReview);
            if(function_exists('mb_strlen') && mb_strlen($rawReview)>200000)wp_send_json(['ok'=>false,'error'=>'Ручная оценка слишком большая.'],422);
            $review=json_decode($rawReview,true);
            if(!is_array($review) || json_last_error()!==JSON_ERROR_NONE)wp_send_json(['ok'=>false,'error'=>'Неверный формат ручной оценки.'],422);
        }elseif(is_array($rawReview)){
            $review=wp_unslash($rawReview);
        }else{
            wp_send_json(['ok'=>false,'error'=>'Не передана ручная оценка.'],422);
        }
        $result=ckmqp_show_session($game,$actor,[
            'command'=>'manual_review',
            'round'=>(int)($_POST['round']??-1),
            'attempt'=>(int)($_POST['attempt']??-1),
            'review'=>$review,
        ]);
        if(empty($result['ok']))wp_send_json(['ok'=>false,'error'=>$result['error']??'Не удалось сохранить оценку.','code'=>$result['code']??'storage_error'],409);
        wp_send_json(['ok'=>true,'state'=>ckmqp_show_state($game,$auth)]);
    }
    $text=sanitize_textarea_field(wp_unslash((string)($_POST['text']??'')));
    if (function_exists('mb_strlen') && mb_strlen($text)>2000) wp_send_json(['ok'=>false,'error'=>'Максимум 2000 символов.'],422);
    $result=ckmqp_show_session($game,$actor,['command'=>$command,'round'=>(int)($_POST['round']??0),'attempt'=>(int)($_POST['attempt']??-1),'request_id'=>(string)($_POST['request_id']??''),'question_index'=>(int)($_POST['question_index']??-1),'vote'=>sanitize_key((string)($_POST['vote']??'')),'text'=>$text]);
    if (empty($result['ok'])) wp_send_json(['ok'=>false,'error'=>$result['error'],'code'=>$result['code']??'storage_error'],409);
    if (($result['session']['phase']??'')==='game_complete' && function_exists('ckmqp_show_finalize_game')) ckmqp_show_finalize_game($game,$result['session']);
    $fresh=ckm_quiz_pro_find_game((string)$game['game_code']) ?: $game;
    wp_send_json(['ok'=>true,'state'=>ckmqp_show_state($fresh,$auth)]);
}
add_action('wp_ajax_ckm_qp_show_action','ckmqp_show_ajax_action');
add_action('wp_ajax_nopriv_ckm_qp_show_action','ckmqp_show_ajax_action');

/**
 * Narrow REST transport for round-4 storyteller readiness.
 *
 * The reducer and authorization are exactly the same as the AJAX action, but
 * this route avoids admin-ajax contention with the participant room's 1.8s
 * state poll. It is intentionally limited to story_ready only.
 */
function ckmqp_show_story_ready_rest(WP_REST_Request $request): WP_REST_Response {
    $p=$request->get_json_params();
    if(!is_array($p))$p=$request->get_params();
    if(!is_array($p))$p=[];

    $game=ckm_quiz_pro_find_game((string)($p['game']??''));
    if(!$game || !ckmqp_show_is_game($game))return new WP_REST_Response(['ok'=>false,'error'=>'Игра не найдена.'],404);
    if(($game['status']??'')==='finished')return new WP_REST_Response(['ok'=>false,'error'=>'Игра завершена.'],409);

    $v=ckm_quiz_pro_validate_team_request($game,(string)($p['team']??''),(string)($p['token']??''),(string)($p['nonce']??''));
    if(empty($v['ok']))return new WP_REST_Response(['ok'=>false,'error'=>$v['error']??'Неверная ссылка команды.','code'=>$v['code']??'join_auth_failed'],(int)($v['status']??403));

    $uid=absint($p['user_id']??0);
    $member=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null;
    if(!$member || (int)$member['team_id']!==(int)$v['team']['id'] || ($member['member_status']??'active')!=='active'){
        return new WP_REST_Response(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
    }

    $actor=['role'=>'participant','team_id'=>(int)$v['team']['id'],'slot'=>(int)$v['team']['slot_no']];
    $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>$actor['team_id'],'user_id'=>$uid];
    $result=ckmqp_show_session($game,$actor,[
        'command'=>'story_ready',
        'round'=>(int)($p['round']??3),
        'attempt'=>(int)($p['attempt']??-1),
    ]);
    if(empty($result['ok']))return new WP_REST_Response(['ok'=>false,'error'=>$result['error']??'Не удалось начать рассказ.','code'=>$result['code']??'storage_error'],409);

    $fresh=ckm_quiz_pro_find_game((string)$game['game_code']) ?: $game;
    global $wpdb;
    $teams=$wpdb->get_results($wpdb->prepare(
        'SELECT id,team_key,team_name,slot_no FROM '.ckm_quiz_teams_table().' WHERE game_id=%d ORDER BY slot_no ASC,id ASC',
        (int)$fresh['id']
    ),ARRAY_A) ?: [];
    $state=ckmqp_show_dialogue_project($fresh,$auth,$teams,$result['session']);
    $events=ckmqp_show_ai_host_events((int)$fresh['id']);
    $state['events']=$events;
    $state['lastEventId']=$events?(int)$events[count($events)-1]['id']:0;
    $state['hasMoreEvents']=false;
    return new WP_REST_Response(['ok'=>true,'state'=>$state],200);
}

function ckmqp_show_register_story_ready_rest(): void {
    register_rest_route('ckm-quiz-pro/v1','/show-story-ready',[
        'methods'=>'POST',
        'callback'=>'ckmqp_show_story_ready_rest',
        'permission_callback'=>'__return_true',
    ]);
}

add_action('rest_api_init','ckmqp_show_register_story_ready_rest');

/**
 * Narrow REST transport for round-4 secret voting.
 *
 * This mirrors the story_ready transport fix: authorization and reducer logic
 * stay unchanged, while the vote no longer competes with the participant
 * room's frequent admin-ajax polling request.
 */
function ckmqp_show_story_vote_rest(WP_REST_Request $request): WP_REST_Response {
    $p=$request->get_json_params();
    if(!is_array($p))$p=$request->get_params();
    if(!is_array($p))$p=[];

    $game=ckm_quiz_pro_find_game((string)($p['game']??''));
    if(!$game || !ckmqp_show_is_game($game))return new WP_REST_Response(['ok'=>false,'error'=>'Игра не найдена.'],404);
    if(($game['status']??'')==='finished')return new WP_REST_Response(['ok'=>false,'error'=>'Игра завершена.'],409);

    $v=ckm_quiz_pro_validate_team_request($game,(string)($p['team']??''),(string)($p['token']??''),(string)($p['nonce']??''));
    if(empty($v['ok']))return new WP_REST_Response(['ok'=>false,'error'=>$v['error']??'Неверная ссылка команды.','code'=>$v['code']??'join_auth_failed'],(int)($v['status']??403));

    $uid=absint($p['user_id']??0);
    $member=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null;
    if(!$member || (int)$member['team_id']!==(int)$v['team']['id'] || ($member['member_status']??'active')!=='active'){
        return new WP_REST_Response(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
    }

    $actor=['role'=>'participant','team_id'=>(int)$v['team']['id'],'slot'=>(int)$v['team']['slot_no']];
    $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>$actor['team_id'],'user_id'=>$uid];
    $vote=sanitize_key((string)($p['vote']??''));
    if(!in_array($vote,['truth','distortion'],true))return new WP_REST_Response(['ok'=>false,'error'=>'Выберите один из двух вариантов.','code'=>'vote_invalid'],422);
    $result=ckmqp_show_session($game,$actor,[
        'command'=>'story_vote',
        'round'=>(int)($p['round']??3),
        'attempt'=>(int)($p['attempt']??-1),
        'vote'=>$vote,
    ]);
    if(empty($result['ok']))return new WP_REST_Response(['ok'=>false,'error'=>$result['error']??'Не удалось зафиксировать голос.','code'=>$result['code']??'storage_error'],409);
    if(($result['session']['phase']??'')==='game_complete' && function_exists('ckmqp_show_finalize_game'))ckmqp_show_finalize_game($game,$result['session']);

    $fresh=ckm_quiz_pro_find_game((string)$game['game_code']) ?: $game;
    global $wpdb;
    $teams=$wpdb->get_results($wpdb->prepare(
        'SELECT id,team_key,team_name,slot_no FROM '.ckm_quiz_teams_table().' WHERE game_id=%d ORDER BY slot_no ASC,id ASC',
        (int)$fresh['id']
    ),ARRAY_A) ?: [];
    $state=ckmqp_show_dialogue_project($fresh,$auth,$teams,$result['session']);
    $events=ckmqp_show_ai_host_events((int)$fresh['id']);
    $state['events']=$events;
    $state['lastEventId']=$events?(int)$events[count($events)-1]['id']:0;
    $state['hasMoreEvents']=false;
    return new WP_REST_Response(['ok'=>true,'state'=>$state],200);
}

function ckmqp_show_register_story_vote_rest(): void {
    register_rest_route('ckm-quiz-pro/v1','/show-story-vote',[
        'methods'=>'POST',
        'callback'=>'ckmqp_show_story_vote_rest',
        'permission_callback'=>'__return_true',
    ]);
}
add_action('rest_api_init','ckmqp_show_register_story_vote_rest');

function ckmqp_show_dialogue_demo_metadata(): void {
    if (get_option('ckmqp_show_round4_metadata_v177')==='1' || get_option('ckmqp_show_dialogue_schema')!=='1') return;
    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug=%s AND tenant_id=0 AND content_scope='shared'",'demo-negotiation-communicate'));
    if (!$id) return;
    $ok=$wpdb->update($table,['title'=>'Переговори другого','min_teams'=>2,'max_teams'=>2,
        'short_description'=>'Две команды. Четыре раунда: «Удержи цель», «Скрытая задача», «Неудобный вопрос» и финал «Проверь историю» с закрытым досье, перекрёстными вопросами и тайным голосованием.',
        'instructions'=>'Раунды 1–2 идут в свободном диалоге. Раунд 3 — «Неудобный вопрос»: три вопроса без отсчёта времени; следующий вопрос открывается после ответа или явного завершения блока. Раунд 4 — «Проверь историю»: подготовка, рассказ, уточняющие вопросы и голосование идут по этапам; ограничение времени для рассказа и ответов может отдельно включаться организатором. Максимум за игру — 280 баллов.'],['id'=>$id]);
    if ($ok!==false) update_option('ckmqp_show_round4_metadata_v177','1',false);
}
add_action('init','ckmqp_show_dialogue_demo_metadata',22);

<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_json_error(array $r): void {
    $status=(int)($r['status']??400); wp_send_json(['ok'=>false,'code'=>$r['code']??'error','error'=>$r['error']??'Ошибка'],$status);
}
function ckm_quiz_pro_find_game(string $code): ?array { return ckm_quiz_get_game_by_code(strtoupper(sanitize_text_field($code))); }


/**
 * Standalone local judgement used when a human host controls pacing.
 * The protected Cloud arbiter is not involved yet: Classic Quiz is judged by
 * its objective answer key and «Битва знатоков» by the existing strict
 * normalized reference/accepted-variant matcher.
 */
function ckm_quiz_pro_local_judge_closed_question(int $gameId): array {
    $game=ckm_quiz_get_game($gameId);
    if(!$game || (string)($game['quiz_phase']??'')!=='question_closed') return ['ok'=>true,'skipped'=>true,'reason'=>'not_closed'];
    $format=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : (string)($game['format_key_snapshot']??'classic_quiz');
    if($format==='chgk' && function_exists('ckm_quiz_pro_chgk_local_judge_closed_question')) return ckm_quiz_pro_chgk_local_judge_closed_question($gameId);
    global $wpdb;
    $question=ckm_quiz_get_question((int)($game['current_question_id']??0));
    if(!$question) return ['ok'=>false,'code'=>'question_missing','error'=>'Текущий вопрос не найден.'];
    $answers=$wpdb->get_results($wpdb->prepare(
        "SELECT * FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d AND verdict='pending' ORDER BY id ASC",
        $gameId,(int)$question['id']
    ),ARRAY_A) ?: [];
    $scored=0;
    foreach($answers as $answer){
        $evaluation=ckm_quiz_evaluate_answer($question,$answer,'ai');
        if(empty($evaluation['judged'])) continue;
        $auth=['role'=>'host','game_id'=>$gameId,'team_id'=>0,'user_id'=>0];
        $score=ckm_quiz_score_answer($gameId,(int)$answer['id'],(int)$evaluation['points'],(string)$evaluation['verdict'],(string)$evaluation['reason'],$auth,'standalone-local-'.(int)$answer['id'].'-'.(int)($answer['attempt_no']??1));
        if(empty($score['ok'])) return $score;
        $scored++;
    }
    return ['ok'=>true,'scored'=>$scored,'mode'=>'objective_local'];
}
function ckm_quiz_pro_validate_team_request(array $game,string $teamKey,string $token,string $nonce): array {
    $team=ckm_quiz_get_team_by_key((int)$game['id'],strtoupper(sanitize_text_field($teamKey)));
    if(!$team) return ['ok'=>false,'status'=>404,'error'=>'Команда не найдена.','code'=>'team_not_found'];
    $v=ckm_quiz_validate_join_link($game,$team,$token,$nonce); if(empty($v['ok'])) return $v;
    return ['ok'=>true,'team'=>$team];
}

function ckm_quiz_pro_ajax_join(): void {
    $game=ckm_quiz_pro_find_game($_POST['game']??''); if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    $v=ckm_quiz_pro_validate_team_request($game,$_POST['team']??'',$_POST['token']??'',$_POST['nonce']??''); if(empty($v['ok'])) ckm_quiz_pro_json_error($v);
    $team=$v['team']; $existingId=absint($_POST['user_id']??0);
    if($existingId>0){
        $m=ckm_quiz_get_membership((int)$game['id'],$existingId);
        if($m && (int)$m['team_id']===(int)$team['id']){
            if(function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='chgk' && function_exists('ckm_quiz_chgk_set_team_ready')){
                $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>(int)$team['id'],'user_id'=>$existingId,'member_role'=>$m['member_role']??'player'];
                ckm_quiz_chgk_set_team_ready($auth,['ready'=>true]);
                if(function_exists('ckm_quiz_pro_chgk_local_autopilot')) ckm_quiz_pro_chgk_local_autopilot((int)$game['id']);
            }
            // Reopening an already joined team room must still kick the AI-host
            // sequential autostart. Otherwise a returning one-team mini-game can
            // remain forever in waiting even though the only team is present.
            $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
            ckm_quiz_refresh_deadline($fresh);
            wp_send_json(['ok'=>true,'userId'=>$existingId,'teamId'=>(int)$team['id']]);
        }
    }
    $r=ckm_quiz_join_guest_team($game,$team,sanitize_text_field(wp_unslash($_POST['name']??''))); if(empty($r['ok'])) ckm_quiz_pro_json_error($r);
    $uid=(int)$r['member']['user_id'];
    if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='chgk' && function_exists('ckm_quiz_chgk_set_team_ready')) {
        $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>(int)$team['id'],'user_id'=>$uid,'member_role'=>$r['member']['member_role']??'player'];
        ckm_quiz_chgk_set_team_ready($auth,['ready'=>true]);
    }
    $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
    ckm_quiz_refresh_deadline($fresh);
    if(function_exists('ckm_quiz_pro_chgk_local_autopilot')) ckm_quiz_pro_chgk_local_autopilot((int)$game['id']);
    wp_send_json(['ok'=>true,'userId'=>$uid,'teamId'=>(int)$team['id']]);
}

function ckm_quiz_pro_ajax_state(): void {
    $game=ckm_quiz_pro_find_game($_REQUEST['game']??''); if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    $game=ckm_quiz_refresh_deadline($game);
    $stateFormat=function_exists('ckm_quiz_runtime_format_key')?ckm_quiz_runtime_format_key($game):(string)($game['format_key_snapshot']??'classic_quiz');
    if((string)($game['host_mode_snapshot']??'')==='human' && !in_array($stateFormat,['jeopardy','chgk'],true) && (string)($game['quiz_phase']??'')==='question_closed') { ckm_quiz_pro_local_judge_closed_question((int)$game['id']); $game=ckm_quiz_get_game((int)$game['id']) ?: $game; }
    if(function_exists('ckm_quiz_pro_chgk_local_autopilot')) { ckm_quiz_pro_chgk_local_autopilot((int)$game['id']); $game=ckm_quiz_get_game((int)$game['id']) ?: $game; }
    if($stateFormat==='jeopardy' && function_exists('ckm_quiz_pro_jeopardy_local_autopilot')) { ckm_quiz_pro_jeopardy_local_autopilot((int)$game['id']); $game=ckm_quiz_get_game((int)$game['id']) ?: $game; }
    $role=sanitize_key($_REQUEST['role']??'participant');
    if($role==='participant'){
        $v=ckm_quiz_pro_validate_team_request($game,$_REQUEST['team']??'',$_REQUEST['token']??'',$_REQUEST['nonce']??''); if(empty($v['ok'])) ckm_quiz_pro_json_error($v);
        $uid=absint($_REQUEST['user_id']??0); $m=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null; if(!$m || (int)$m['team_id']!==(int)$v['team']['id']) $uid=0;
        $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>(int)$v['team']['id'],'user_id'=>$uid,'member_role'=>$m['member_role']??'player'];
    } elseif($role==='scoreboard'){
        if(!ckm_quiz_secret_equal((string)($_REQUEST['token']??''),(string)$game['scoreboard_token']) || !ckm_quiz_secret_equal((string)($_REQUEST['nonce']??''),(string)$game['scoreboard_nonce'])) wp_send_json(['ok'=>false,'error'=>'Неверная ссылка табло.'],403);
        $auth=['role'=>'scoreboard','game_id'=>(int)$game['id'],'team_id'=>0,'user_id'=>0];
    } else {
        if(!ckm_quiz_secret_equal((string)($_REQUEST['token']??''),(string)$game['host_token']) || !ckm_quiz_secret_equal((string)($_REQUEST['nonce']??''),(string)$game['host_nonce'])) wp_send_json(['ok'=>false,'error'=>'Неверная ссылка ведущего.'],403);
        $auth=['role'=>'host','game_id'=>(int)$game['id'],'team_id'=>0,'user_id'=>get_current_user_id()];
    }
    $state=ckm_quiz_build_state($game,$auth,0);
    if (function_exists('ckm_quiz_pro_express_canary_public_state')) {
        $state['negotiationExpressCanary']=ckm_quiz_pro_express_canary_public_state($game);
    }
    wp_send_json(['ok'=>true,'state'=>$state]);
}

function ckm_quiz_pro_ajax_answer(): void {
    $game=ckm_quiz_pro_find_game($_POST['game']??''); if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    $v=ckm_quiz_pro_validate_team_request($game,$_POST['team']??'',$_POST['token']??'',$_POST['nonce']??''); if(empty($v['ok'])) ckm_quiz_pro_json_error($v);
    $uid=absint($_POST['user_id']??0); $m=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null; if(!$m || (int)$m['team_id']!==(int)$v['team']['id']) wp_send_json(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
    $answer=sanitize_textarea_field(wp_unslash($_POST['answer']??''));
    $answerInputMode=sanitize_key((string)($_POST['input_mode']??'text'));
    if(!in_array($answerInputMode,['text','voice'],true)) $answerInputMode='text';
    $submittedQuestionId=(int)($game['current_question_id']??0);
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : (string)($game['format_key_snapshot']??'classic_quiz');
    $isChgk=$formatKey==='chgk'; $isJeopardy=$formatKey==='jeopardy';
    if($isJeopardy){
        $existing=ckm_quiz_get_answer((int)$game['id'],(int)$v['team']['id'],(int)($game['current_question_id']??0));
        if($existing) wp_send_json(['ok'=>false,'code'=>'answer_locked','error'=>'Ответ команды уже зафиксирован для этого вопроса.'],409);
    }
    $payload=$isChgk ? ['argumentation'=>''] : ['answer'=>$answer];
    $r=ckm_quiz_submit_answer(['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>(int)$v['team']['id'],'user_id'=>$uid,'member_role'=>$m['member_role']],[ 'answer_text'=>$answer,'answer_payload'=>$payload ]);
    if(empty($r['ok'])) ckm_quiz_pro_json_error($r);
    if($isChgk && function_exists('ckm_quiz_chgk_maybe_auto_close_question')) ckm_quiz_chgk_maybe_auto_close_question((int)$game['id']);
    if($isChgk && function_exists('ckm_quiz_pro_chgk_local_autopilot')) ckm_quiz_pro_chgk_local_autopilot((int)$game['id']);
    if($formatKey==='solution_price' && function_exists('ckm_quiz_pro_solution_price_maybe_auto_close')) {
        // Mini-game feedback is useful only if it arrives right after the
        // submitted decision. Close the stage once all active teams answered;
        // the close handler performs review, publishes host feedback and opens
        // the next stage through the existing AI-host autopilot.
        ckm_quiz_pro_solution_price_maybe_auto_close((int)$game['id']);
    }
    if($formatKey==='negotiation_duel' && function_exists('ckm_quiz_pro_negotiation_maybe_auto_close')) {
        // Negotiation rounds are short by design. Once all active teams have
        // fixed a reply, close the round immediately, score the move and move
        // to the next opponent line through the sequential AI-host autopilot.
        ckm_quiz_pro_negotiation_maybe_auto_close((int)$game['id']);
    }
    if($isJeopardy && function_exists('ckm_quiz_pro_jeopardy_local_resolve_after_answer')) {
        $jr=ckm_quiz_pro_jeopardy_local_resolve_after_answer((int)$game['id'],(int)$v['team']['id']);
        if(empty($jr['ok'])) ckm_quiz_pro_json_error($jr);
        if(function_exists('ckm_quiz_pro_jeopardy_local_autopilot')) ckm_quiz_pro_jeopardy_local_autopilot((int)$game['id']);
    }
    $canary=null;
    if(function_exists('ckm_quiz_pro_express_canary_is_game') && ckm_quiz_pro_express_canary_is_game($game)) {
        $answerId=(int)($r['answer']['id']??0);
        $canary=ckm_quiz_pro_express_canary_mirror_answer($game,(int)$v['team']['id'],$submittedQuestionId,$answer,$answerInputMode,$answerId);
        if(function_exists('ckm_quiz_pro_express_canary_log_result')) ckm_quiz_pro_express_canary_log_result('answer',$canary,(int)$game['id']);
        $freshCanaryGame=ckm_quiz_get_game((int)$game['id']) ?: $game;
        $canarySync=ckm_quiz_pro_express_canary_sync($freshCanaryGame);
        if(function_exists('ckm_quiz_pro_express_canary_log_result')) ckm_quiz_pro_express_canary_log_result('sync_after_answer',$canarySync,(int)$game['id']);
        if(!isset($canary['ok']) || $canary['ok']!==false) $canary=$canarySync;
    }
    wp_send_json(['ok'=>true,'result'=>$r,'canary'=>$canary]);
}



function ckm_quiz_pro_ai_start_from_host(array $game,int $uid,bool $voiceReady): array {
    $gameId=(int)($game['id']??0);
    if($gameId<=0) return ckm_quiz_error(404,'Игра не найдена.','game_not_found');
    if((string)($game['host_mode_snapshot']??'')!=='ai') return ckm_quiz_error(409,'Эта игра работает с ведущим-человеком.','host_mode_mismatch');
    if((string)($game['status']??'')==='finished') return ckm_quiz_error(409,'Игра уже завершена.','game_finished');
    if((string)($game['quiz_phase']??'waiting')!=='waiting') return ckm_quiz_error(409,'Игра уже запущена.','game_already_started');
    if(function_exists('ckm_quiz_pro_ai_voice_provider') && ckm_quiz_pro_ai_voice_provider()==='gateway' && !$voiceReady) {
        return ckm_quiz_error(409,'Сначала включите Сергея и дождитесь готовности голосового соединения.','voice_not_ready');
    }
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : (string)($game['format_key_snapshot']??'classic_quiz');
    if(in_array($formatKey,['classic_quiz','solution_price','negotiation_duel'],true)) {
        if(function_exists('ckm_quiz_ai_host_classic_all_teams_joined') && !ckm_quiz_ai_host_classic_all_teams_joined($game)) {
            return ckm_quiz_error(409,'Сначала подключите все команды, затем запускайте игру.','teams_not_joined');
        }
    } elseif($formatKey==='chgk') {
        $preflight=function_exists('ckm_quiz_chgk_preflight') ? ckm_quiz_chgk_preflight($game) : ['canStart'=>true];
        if(empty($preflight['canStart'])) {
            $first=(array)($preflight['blockers'][0]??[]);
            return ckm_quiz_error(409,(string)($first['message']??'Комната ещё не готова к старту.'),(string)($first['code']??'preflight_blocked'));
        }
    } elseif($formatKey==='jeopardy') {
        if(function_exists('ckm_quiz_pro_jeopardy_all_teams_joined') && !ckm_quiz_pro_jeopardy_all_teams_joined($game)) {
            return ckm_quiz_error(409,'Сначала подключите все команды, затем запускайте игру.','teams_not_joined');
        }
    }
    global $wpdb;
    $now=ckm_quiz_now_mysql();
    $updated=$wpdb->query($wpdb->prepare(
        "UPDATE ".ckm_quiz_games_table()." SET auto_start=1,state_version=state_version+1,updated_at=%s WHERE id=%d AND auto_start=0",
        $now,$gameId
    ));
    if($updated===false) return ckm_quiz_error(500,'Не удалось разрешить старт игры.','start_authorize_failed');
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    $result=['ok'=>true,'action'=>'start_authorized'];
    if(in_array($formatKey,['classic_quiz','solution_price','negotiation_duel'],true) && function_exists('ckm_quiz_ai_host_classic_autopilot')) {
        $result=ckm_quiz_ai_host_classic_autopilot($gameId);
    } elseif($formatKey==='chgk' && function_exists('ckm_quiz_chgk_ai_host_autostart')) {
        $result=ckm_quiz_chgk_ai_host_autostart($gameId);
    }
    if(empty($result['ok'])) return $result;
    return ['ok'=>true,'result'=>$result,'game'=>ckm_quiz_get_game($gameId) ?: $fresh];
}


function ckm_quiz_pro_chgk_early_answer_seconds_value($raw): int {
    $seconds = absint($raw);
    if ($seconds < 5) $seconds = 5;
    return max(5, min(60, $seconds));
}

function ckm_quiz_pro_set_chgk_early_answer_seconds(array $game, int $seconds, int $userId): array {
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') {
        return ckm_quiz_error(409, 'Это действие доступно только для «Битвы знатоков».', 'chgk_required');
    }
    $seconds = ckm_quiz_pro_chgk_early_answer_seconds_value($seconds);
    $settings = function_exists('ckm_quiz_json_decode') ? ckm_quiz_json_decode((string)($game['format_settings_snapshot_json'] ?? '')) : array();
    if (!is_array($settings)) $settings = array();
    $settings['discussionSeconds'] = 60;
    $settings['finalAnswerSeconds'] = 20;
    $settings['earlyAnswerSeconds'] = $seconds;
    $settings['questionSelectionMode'] = 'sequential';
    $settings['appealsEnabled'] = false;
    $settings['appealWindowSeconds'] = 0;
    $settings['tieBreakMode'] = 'disabled';
    $json = wp_json_encode($settings, JSON_UNESCAPED_UNICODE);
    if (!$json) return ckm_quiz_error(500, 'Не удалось сохранить настройку времени.', 'early_answer_settings_encode_failed');
    global $wpdb;
    $gameId = (int)($game['id'] ?? 0);
    $now = function_exists('ckm_quiz_now_mysql') ? ckm_quiz_now_mysql() : current_time('mysql');
    $deadline = null;
    $flow = function_exists('ckm_quiz_chgk_question_flow_state') ? ckm_quiz_chgk_question_flow_state($game) : array();
    $isActiveEarly = (string)($game['quiz_phase'] ?? '') === 'question_open'
        && (string)($flow['phase'] ?? '') === 'early_answer_offer'
        && empty($game['is_paused']);
    if ($isActiveEarly && function_exists('ckm_quiz_deadline_mysql')) {
        $deadline = ckm_quiz_deadline_mysql($seconds);
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . ckm_quiz_games_table() . ' SET format_settings_snapshot_json=%s, question_deadline_at=%s, state_version=state_version+1, updated_at=%s WHERE id=%d',
            $json, $deadline, $now, $gameId
        ));
    } else {
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . ckm_quiz_games_table() . ' SET format_settings_snapshot_json=%s, state_version=state_version+1, updated_at=%s WHERE id=%d',
            $json, $now, $gameId
        ));
    }
    if ($updated === false) return ckm_quiz_error(500, 'Не удалось сохранить время досрочного ответа.', 'early_answer_seconds_save_failed');
    if (function_exists('ckm_quiz_append_event')) {
        ckm_quiz_append_event($gameId, 'chgk_early_answer_seconds_changed', 'host', $userId, 0, (int)($game['current_question_id'] ?? 0), 'game', $gameId, array('earlyAnswerSeconds'=>$seconds, 'deadlineAt'=>$deadline), 'chgk-early-answer-seconds-' . $gameId . '-' . time());
    }
    $fresh = function_exists('ckm_quiz_get_game') ? (ckm_quiz_get_game($gameId) ?: $game) : $game;
    return ckm_quiz_result(true, 200, array('action'=>'early_answer_seconds_saved', 'earlyAnswerSeconds'=>$seconds, 'game'=>$fresh));
}

function ckm_quiz_pro_switch_host_mode_universal(array $game, string $targetMode, array $auth): array {
    $role=(string)($auth['role'] ?? '');
    if(!in_array($role,['host','admin'],true)) return ckm_quiz_error(403,'Сменить ведущего может только организатор.','host_required');
    $targetMode=sanitize_key($targetMode);
    if(!in_array($targetMode,['ai','human'],true)) return ckm_quiz_error(422,'Неизвестный режим ведущего.','host_mode_invalid');
    $gameId=(int)($game['id'] ?? 0);
    if($gameId<=0) return ckm_quiz_error(404,'Игра не найдена.','game_not_found');
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : (string)($game['format_key_snapshot'] ?? 'classic_quiz');

    // CHGK keeps its stricter phase-safety contract.
    if($formatKey==='chgk' && function_exists('ckm_quiz_chgk_switch_host_mode')) {
        return ckm_quiz_chgk_switch_host_mode($gameId,$targetMode,$auth);
    }
    if($targetMode==='ai' && function_exists('ckm_quiz_ai_host_is_configured') && !ckm_quiz_ai_host_is_configured()) {
        return ckm_quiz_error(409,'ИИ-ведущий сейчас не настроен.','ai_host_unavailable');
    }

    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $fresh=ckm_quiz_get_game($gameId,true);
        if(!$fresh){$wpdb->query('ROLLBACK');return ckm_quiz_error(404,'Игра не найдена.','game_not_found');}
        if((string)($fresh['status'] ?? '')==='finished'){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Игра уже завершена.','game_finished');}
        $current=(string)($fresh['host_mode_snapshot'] ?? 'human');
        if($current===$targetMode){$wpdb->query('COMMIT');return ckm_quiz_result(true,200,['action'=>'host_mode_unchanged','hostMode'=>$current,'game'=>$fresh]);}
        $now=ckm_quiz_now_mysql();
        // A human-started game handed to AI must be authorized to continue from waiting.
        if($targetMode==='ai') {
            $ok=$wpdb->query($wpdb->prepare(
                'UPDATE '.ckm_quiz_games_table().' SET host_mode_snapshot=%s,host_mode=%s,auto_start=1,state_version=state_version+1,updated_at=%s WHERE id=%d',
                $targetMode,$targetMode,$now,$gameId
            ));
        } else {
            $ok=$wpdb->query($wpdb->prepare(
                'UPDATE '.ckm_quiz_games_table().' SET host_mode_snapshot=%s,host_mode=%s,state_version=state_version+1,updated_at=%s WHERE id=%d',
                $targetMode,$targetMode,$now,$gameId
            ));
        }
        if($ok===false) throw new RuntimeException($wpdb->last_error ?: 'host_switch_update_failed');
        $action=$targetMode==='human'?'human_host_takeover':'ai_host_takeover';
        $eventId=ckm_quiz_append_event(
            $gameId,$action,'host',(int)($auth['user_id'] ?? 0),0,(int)($fresh['current_question_id'] ?? 0),'game',$gameId,
            ['from'=>$current,'to'=>$targetMode,'phase'=>(string)($fresh['quiz_phase'] ?? ''),'formatKey'=>$formatKey],
            'universal-host-switch-'.$gameId.'-'.$targetMode.'-'.(int)($fresh['state_version'] ?? 0)
        );
        if($eventId<=0) throw new RuntimeException('host_switch_event_failed');
        $wpdb->query('COMMIT');
    } catch(Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM universal host switch failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось переключить ведущего.','host_switch_failed');
    }

    // Resume the appropriate AI autopilot from the exact current state.
    $autoPilot=null;
    if($targetMode==='ai') {
        if(in_array($formatKey,['classic_quiz','solution_price','negotiation_duel'],true) && function_exists('ckm_quiz_ai_host_classic_autopilot')) {
            $autoPilot=ckm_quiz_ai_host_classic_autopilot($gameId);
        } elseif($formatKey==='jeopardy' && function_exists('ckm_quiz_pro_jeopardy_local_autopilot')) {
            $autoPilot=ckm_quiz_pro_jeopardy_local_autopilot($gameId);
        }
    }
    $out=ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true,200,['action'=>$targetMode==='human'?'human_host_takeover':'ai_host_takeover','hostMode'=>$targetMode,'game'=>$out,'autoPilot'=>$autoPilot]);
}

function ckm_quiz_pro_ajax_host_action(): void {
    $game=ckm_quiz_pro_find_game($_POST['game']??'');
    if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    if(!ckm_quiz_secret_equal((string)($_POST['token']??''),(string)$game['host_token']) || !ckm_quiz_secret_equal((string)($_POST['nonce']??''),(string)$game['host_nonce'])) wp_send_json(['ok'=>false,'error'=>'Неверная ссылка ведущего.'],403);
    if (ckmqp_show_is_game($game)) wp_send_json(['ok'=>false,'code'=>'show_preparation_only','error'=>'Используйте отдельные кнопки управления раундом «Переговори другого».'],409);
    $command=sanitize_key((string)(($_POST['command']??'') ?: ($_POST['action']??'')));
    $uid=get_current_user_id();
    $hostMode=(string)($game['host_mode_snapshot']??'ai');
    $formatKey=function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : (string)($game['format_key_snapshot']??'classic_quiz');
    $auth=['role'=>'host','game_id'=>(int)$game['id'],'team_id'=>0,'user_id'=>$uid];

    if($formatKey==='chgk' && $command==='set_early_answer_seconds') {
        $result=ckm_quiz_pro_set_chgk_early_answer_seconds($game,(int)($_POST['early_answer_seconds']??5),$uid);
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if($formatKey==='chgk' && !empty($game['is_paused']) && in_array($command,['close','next','start','reveal'],true)) {
        wp_send_json(['ok'=>false,'error'=>'Игра на паузе. Сначала нажмите «Продолжить».','code'=>'game_paused'],409);
    }

    if($formatKey==='chgk' && in_array($command,['pause','resume'],true)) {
        if($command==='pause') $result=function_exists('ckm_quiz_chgk_pause_game') ? ckm_quiz_chgk_pause_game((int)$game['id'],$auth) : ['ok'=>false,'status'=>500,'code'=>'pause_unavailable','error'=>'Пауза недоступна.'];
        else $result=function_exists('ckm_quiz_chgk_resume_game') ? ckm_quiz_chgk_resume_game((int)$game['id'],$auth) : ['ok'=>false,'status'=>500,'code'=>'resume_unavailable','error'=>'Продолжение недоступно.'];
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if(in_array($command,['switch_human','switch_ai'],true)) {
        $result=ckm_quiz_pro_switch_host_mode_universal($game,$command==='switch_ai'?'ai':'human',$auth);
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        if($command==='switch_ai' && $formatKey==='chgk' && function_exists('ckm_quiz_pro_chgk_local_autopilot')) {
            $autoPilot=ckm_quiz_pro_chgk_local_autopilot((int)$game['id']);
            if(is_array($result)) $result['autoPilot']=$autoPilot;
        }
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if($command==='publish_message') {
        $result=function_exists('ckm_quiz_publish_human_message')
            ? ckm_quiz_publish_human_message($game,(string)wp_unslash($_POST['text']??''),$auth)
            : ['ok'=>false,'status'=>500,'code'=>'host_message_unavailable','error'=>'Текстовая реплика ведущего недоступна.'];
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if($command==='score_answer') {
        $answerId=absint($_POST['answer_id']??0);
        $verdict=sanitize_key((string)($_POST['verdict']??''));
        if(!in_array($verdict,['accepted','correct','rejected','incorrect'],true)) $verdict='accepted';
        $points=in_array($verdict,['accepted','correct'],true) ? 1 : 0;
        $comment=sanitize_textarea_field(wp_unslash($_POST['comment']??''));
        if($comment==='') $comment=in_array($verdict,['accepted','correct'],true) ? 'Решение арбитра: ответ засчитан.' : 'Решение арбитра: ответ не засчитан.';
        $requestId=sanitize_text_field((string)($_POST['request_id']??('manual-'.$answerId.'-'.time())));
        $result=ckm_quiz_score_answer((int)$game['id'],$answerId,$points,$verdict,$comment,$auth,$requestId);
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if($formatKey==='chgk' && in_array($command,['ai_arbitrate','ai_rearbitrate'],true)) {
        $answerId=absint($_POST['answer_id']??0);
        $result=function_exists('ckm_quiz_pro_chgk_local_judge_answer')
            ? ckm_quiz_pro_chgk_local_judge_answer((int)$game['id'],$answerId,$command==='ai_rearbitrate')
            : ['ok'=>false,'status'=>500,'code'=>'ai_arbitration_unavailable','error'=>'ИИ-оценка недоступна.'];
        if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        wp_send_json(['ok'=>true,'result'=>$result,'aiArbitration'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    if($hostMode==='ai') {
        if(!in_array($command,['start','next'],true) || (string)($game['quiz_phase']??'waiting')!=='waiting') {
            wp_send_json(['ok'=>false,'error'=>'После запуска ходом игры управляет ИИ-ведущий.'],409);
        }
        $voiceReady=!empty($_POST['voice_ready']);
        $started=ckm_quiz_pro_ai_start_from_host($game,$uid,$voiceReady);
        if(empty($started['ok'])) ckm_quiz_pro_json_error($started);
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        $auth=['role'=>'host','game_id'=>(int)$fresh['id'],'team_id'=>0,'user_id'=>$uid];
        wp_send_json(['ok'=>true,'result'=>$started['result']??$started,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
    }

    $result=null;
    if($formatKey==='jeopardy' && str_starts_with($command,'jeopardy_')){
        $result=ckm_quiz_pro_jeopardy_host_action($game,$command,$_POST,$uid);
    } elseif($formatKey==='jeopardy'){
        wp_send_json(['ok'=>false,'error'=>'Для «Интеллектуального батла» используйте управление игровым полем и финалом.'],409);
    } elseif($command==='close'){
        if($formatKey==='chgk' && (string)($game['quiz_phase']??'')==='question_open'
            && function_exists('ckm_quiz_chgk_final_answer_is_open')
            && !ckm_quiz_chgk_final_answer_is_open($game)){
            if(function_exists('ckm_quiz_chgk_discussion_is_started') && !ckm_quiz_chgk_discussion_is_started($game) && function_exists('ckm_quiz_chgk_start_discussion')){
                $result=ckm_quiz_chgk_start_discussion((int)$game['id'],'host',$uid);
            } elseif(function_exists('ckm_quiz_chgk_open_final_answer_window')){
                $result=ckm_quiz_chgk_open_final_answer_window((int)$game['id'],'host',$uid);
            } else {
                $result=ckm_quiz_error(500,'Управление фазой недоступно.','chgk_phase_unavailable');
            }
        } else {
            if($formatKey==='chgk' && (string)($game['quiz_phase']??'')==='question_open'
                && function_exists('ckm_quiz_chgk_final_answer_is_open') && ckm_quiz_chgk_final_answer_is_open($game)
                && function_exists('ckm_quiz_chgk_final_answer_remaining_grace')){
                $grace=ckm_quiz_chgk_final_answer_remaining_grace($game,10);
                $qid=(int)($game['current_question_id']??0);
                global $wpdb;
                $answers=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d",(int)$game['id'],$qid));
                if($answers<=0 && $grace>0) {
                    $result=ckm_quiz_error(409,'Окно окончательного ответа только что открылось. Дайте команде увидеть поле ответа и зафиксировать ответ.','final_answer_window_grace');
                } else {
                    $result=ckm_quiz_close_current_question((int)$game['id'],'host',$uid);
                    if(!empty($result['ok']) && $formatKey!=='chgk') ckm_quiz_pro_local_judge_closed_question((int)$game['id']);
                }
            } else {
                $result=ckm_quiz_close_current_question((int)$game['id'],'host',$uid);
                if(!empty($result['ok']) && $formatKey!=='chgk') ckm_quiz_pro_local_judge_closed_question((int)$game['id']);
            }
        }
    } elseif($command==='reveal' && $formatKey==='chgk'){
        $result=function_exists('ckm_quiz_chgk_reveal_answer')
            ? ckm_quiz_chgk_reveal_answer((int)$game['id'],['role'=>'host','game_id'=>(int)$game['id'],'team_id'=>0,'user_id'=>$uid])
            : ['ok'=>false,'status'=>500,'code'=>'answer_reveal_unavailable','error'=>'Раскрытие ответа недоступно.'];
    } elseif($command==='next' || $command==='start'){
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        if((string)($fresh['quiz_phase']??'')==='question_closed' && $formatKey!=='chgk') ckm_quiz_pro_local_judge_closed_question((int)$fresh['id']);
        $result=ckm_quiz_open_next_question((int)$fresh['id'],'host',$uid);
        if(empty($result['ok']) && (string)($result['code']??'')==='questions_complete') $result=ckm_quiz_finish_game((int)$fresh['id'],'host',$uid,true);
    } elseif($command==='finish'){
        $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
        if((string)($fresh['quiz_phase']??'')==='question_open') ckm_quiz_close_current_question((int)$fresh['id'],'host',$uid);
        if($formatKey!=='chgk') ckm_quiz_pro_local_judge_closed_question((int)$fresh['id']);
        $result=ckm_quiz_finish_game((int)$fresh['id'],'host',$uid,true);
    } else {
        wp_send_json(['ok'=>false,'error'=>'Неизвестная команда ведущего.'],400);
    }
    if(empty($result['ok'])) ckm_quiz_pro_json_error($result);
    $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
    $auth=['role'=>'host','game_id'=>(int)$fresh['id'],'team_id'=>0,'user_id'=>$uid];
    wp_send_json(['ok'=>true,'result'=>$result,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
}


function ckm_quiz_pro_ajax_chgk_action(): void {
    $game=ckm_quiz_pro_find_game($_POST['game']??''); if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    if(!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') wp_send_json(['ok'=>false,'error'=>'Это действие доступно только для «Битвы знатоков».'],409);
    $v=ckm_quiz_pro_validate_team_request($game,$_POST['team']??'',$_POST['token']??'',$_POST['nonce']??''); if(empty($v['ok'])) ckm_quiz_pro_json_error($v);
    $uid=absint($_POST['user_id']??0); $m=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null; if(!$m || (int)$m['team_id']!==(int)$v['team']['id']) wp_send_json(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
    $command=sanitize_key((string)($_POST['command']??''));
    $auth=['role'=>'participant','game_id'=>(int)$game['id'],'team_id'=>(int)$v['team']['id'],'user_id'=>$uid,'member_role'=>$m['member_role']??'player'];
    if($command==='early_answer'){
        $r=function_exists('ckm_quiz_chgk_open_final_answer_window') ? ckm_quiz_chgk_open_final_answer_window((int)$game['id'],'participant',$uid) : ['ok'=>false,'status'=>500,'code'=>'early_answer_unavailable','error'=>'Досрочный ответ недоступен.'];
    } elseif($command==='bonus_minute'){
        $r=function_exists('ckm_quiz_chgk_use_bonus_minute') ? ckm_quiz_chgk_use_bonus_minute($auth) : ['ok'=>false,'status'=>500,'code'=>'bonus_minute_unavailable','error'=>'Дополнительная минута недоступна.'];
    } else {
        wp_send_json(['ok'=>false,'error'=>'Неизвестное действие команды.'],400);
    }
    if(empty($r['ok'])) ckm_quiz_pro_json_error($r);
    $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
    wp_send_json(['ok'=>true,'result'=>$r,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
}

function ckm_quiz_pro_ajax_jeopardy_action(): void {
    $game=ckm_quiz_pro_find_game($_POST['game']??''); if(!$game) wp_send_json(['ok'=>false,'error'=>'Игра не найдена.'],404);
    if(!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='jeopardy') wp_send_json(['ok'=>false,'error'=>'Это действие доступно только в «Интеллектуальном батле».'],409);
    if((string)($game['host_mode_snapshot']??'')==='ai' && empty($game['auto_start'])) wp_send_json(['ok'=>false,'code'=>'waiting_for_host_start','error'=>'Ведущий ещё не запустил игру.'],409);
    $v=ckm_quiz_pro_validate_team_request($game,$_POST['team']??'',$_POST['token']??'',$_POST['nonce']??''); if(empty($v['ok'])) ckm_quiz_pro_json_error($v);
    $uid=absint($_POST['user_id']??0); $m=$uid?ckm_quiz_get_membership((int)$game['id'],$uid):null;
    if(!$m || (int)$m['team_id']!==(int)$v['team']['id']) wp_send_json(['ok'=>false,'error'=>'Сначала подключитесь к команде.'],403);
    $command=sanitize_key((string)($_POST['command']??''));
    $r=ckm_quiz_pro_jeopardy_team_action($game,$v['team'],$m,$command,$_POST);
    if(empty($r['ok'])) ckm_quiz_pro_json_error($r);
    // Return the authoritative post-action state immediately.  Waiting for the
    // next polling tick made a successful cell selection look like a dead
    // button and also allowed an older in-flight poll to repaint stale state.
    $fresh=ckm_quiz_get_game((int)$game['id']) ?: $game;
    $auth=['role'=>'participant','game_id'=>(int)$fresh['id'],'team_id'=>(int)$v['team']['id'],'user_id'=>$uid,'member_role'=>$m['member_role']??'player'];
    wp_send_json(['ok'=>true,'result'=>$r,'state'=>ckm_quiz_build_state($fresh,$auth,0)]);
}

foreach(['join'=>'ckm_quiz_pro_ajax_join','state'=>'ckm_quiz_pro_ajax_state','answer'=>'ckm_quiz_pro_ajax_answer','host_action'=>'ckm_quiz_pro_ajax_host_action','jeopardy_action'=>'ckm_quiz_pro_ajax_jeopardy_action','chgk_action'=>'ckm_quiz_pro_ajax_chgk_action'] as $action=>$fn){
    add_action('wp_ajax_ckm_qp_'.$action,$fn); add_action('wp_ajax_nopriv_ckm_qp_'.$action,$fn);
}

<?php
/**
 * CHGK question-flow enhancement (alpha.69.15).
 *
 * Adds a format-specific sub-state on top of the shared quiz_phase without
 * forking the core engine. The sub-state is derived from immutable game events:
 * open -> closed/arbitrating -> review -> completed.
 */
if (!defined('ABSPATH')) exit;

function ckm_quiz_chgk_question_flow_settings(array $game): array {
    $settings = function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : array();
    return array(
        'closeWhenAllAnswered'=>!empty($settings['closeWhenAllAnswered']),
        'reviewEnabled'=>!array_key_exists('questionReviewEnabled', $settings) || !empty($settings['questionReviewEnabled']),
        'reviewRequired'=>!empty($settings['requireQuestionReview']),
        'finalAnswerSeconds'=>20,
        'earlyAnswerSeconds'=>max(5, min(60, (int)($settings['earlyAnswerSeconds'] ?? 5))),
    );
}

function ckm_quiz_chgk_ai_host_available(): bool {
    $cloudAvailable=function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured();
    $standaloneAvailable=function_exists('ckm_quiz_pro_chgk_local_autopilot');
    return $cloudAvailable || $standaloneAvailable;
}

function ckm_quiz_chgk_ai_takeover_is_safe(array $game, array $flow): bool {
    $core=(string)($game['quiz_phase'] ?? 'waiting');
    if ($core==='waiting') return true;
    if ($core==='question_closed') {
        return empty($flow['answerRevealed']) || (int)($flow['pendingArbitration'] ?? 0)===0;
    }
    return $core==='question_open'
        && in_array((string)($flow['phase'] ?? ''),array('question_narration','early_answer_offer','discussion'),true);
}


function ckm_quiz_chgk_pause_state(array $game): array {
    $paused=!empty($game['is_paused']);
    return array(
        'enabled'=>true,
        'isPaused'=>$paused,
        'pausedAt'=>(string)($game['paused_at'] ?? ''),
        'pausedQuizPhase'=>(string)($game['paused_quiz_phase'] ?? ''),
        'pausedFormatPhase'=>(string)($game['paused_format_phase'] ?? ''),
        'remainingSeconds'=>$paused ? max(0,(int)($game['paused_remaining_seconds'] ?? 0)) : 0,
    );
}

function ckm_quiz_chgk_pause_game(int $gameId, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''),array('host','admin'),true)) return ckm_quiz_error(403,'Поставить игру на паузу может только ведущий.','host_required');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Пауза этого типа доступна только в «Битве знатоков».','chgk_required'); }
        if ((string)($game['status'] ?? '')!=='live' || (string)($game['quiz_phase'] ?? '')!=='question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Пауза доступна только во время активного раунда.','pause_phase_unavailable'); }
        if (!empty($game['is_paused'])) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'already_paused','pause'=>ckm_quiz_chgk_pause_state($game))); }
        $deadlineTs=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
        $remaining=$deadlineTs>0 ? max(0,$deadlineTs-ckm_quiz_now_timestamp()) : 0;
        if ($remaining<=0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Текущая фаза уже завершилась и не может быть поставлена на паузу.','pause_deadline_passed'); }
        $formatPhase=ckm_quiz_chgk_final_answer_is_open($game) ? 'final_answer_open' : (ckm_quiz_chgk_discussion_is_started($game) ? 'discussion' : 'early_answer_offer');
        $now=ckm_quiz_now_mysql();
        $ok=$wpdb->update(ckm_quiz_games_table(),array(
            'is_paused'=>1,'paused_at'=>$now,'paused_quiz_phase'=>'question_open','paused_format_phase'=>$formatPhase,
            'paused_remaining_seconds'=>$remaining,'question_deadline_at'=>null,'updated_at'=>$now,
        ),array('id'=>$gameId));
        if ($ok===false) throw new RuntimeException($wpdb->last_error ?: 'pause_update_failed');
        $eventId=ckm_quiz_append_event($gameId,'game_paused','host',(int)($auth['user_id'] ?? 0),0,(int)($game['current_question_id'] ?? 0),'game',$gameId,array('formatPhase'=>$formatPhase,'remainingSeconds'=>$remaining),'chgk-pause-'.$gameId.'-'.(int)($game['state_version'] ?? 0));
        if ($eventId<=0) throw new RuntimeException('pause_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK pause failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось поставить игру на паузу.','pause_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'paused','game'=>$fresh,'pause'=>$fresh?ckm_quiz_chgk_pause_state($fresh):array()));
}

function ckm_quiz_chgk_resume_game(int $gameId, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''),array('host','admin'),true)) return ckm_quiz_error(403,'Продолжить игру может только ведущий.','host_required');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Продолжение этой паузы доступно только в «Битве знатоков».','chgk_required'); }
        if (empty($game['is_paused'])) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'not_paused','pause'=>ckm_quiz_chgk_pause_state($game))); }
        if ((string)($game['status'] ?? '')!=='live' || (string)($game['quiz_phase'] ?? '')!=='question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Текущий раунд уже нельзя продолжить.','resume_phase_unavailable'); }
        $remaining=max(1,(int)($game['paused_remaining_seconds'] ?? 0));
        $deadline=ckm_quiz_deadline_mysql($remaining);
        $now=ckm_quiz_now_mysql();
        $formatPhase=(string)($game['paused_format_phase'] ?? 'discussion');
        $ok=$wpdb->update(ckm_quiz_games_table(),array(
            'is_paused'=>0,'paused_at'=>null,'paused_quiz_phase'=>'','paused_format_phase'=>'','paused_remaining_seconds'=>0,
            'question_deadline_at'=>$deadline,'updated_at'=>$now,
        ),array('id'=>$gameId));
        if ($ok===false) throw new RuntimeException($wpdb->last_error ?: 'resume_update_failed');
        $eventId=ckm_quiz_append_event($gameId,'game_resumed','host',(int)($auth['user_id'] ?? 0),0,(int)($game['current_question_id'] ?? 0),'game',$gameId,array('formatPhase'=>$formatPhase,'remainingSeconds'=>$remaining,'deadlineAt'=>$deadline),'chgk-resume-'.$gameId.'-'.(int)($game['state_version'] ?? 0));
        if ($eventId<=0) throw new RuntimeException('resume_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK resume failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось продолжить игру.','resume_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'resumed','game'=>$fresh,'pause'=>$fresh?ckm_quiz_chgk_pause_state($fresh):array()));
}

function ckm_quiz_chgk_switch_host_mode(int $gameId, string $targetMode, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''),array('host','admin'),true)) return ckm_quiz_error(403,'Сменить ведущего может только организатор.','host_required');
    $targetMode=sanitize_key($targetMode);
    if (!in_array($targetMode,array('ai','human'),true)) return ckm_quiz_error(422,'Неизвестный режим ведущего.','host_mode_invalid');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Перехват ведущего в этой версии доступен только в «Битве знатоков».','chgk_required'); }
        if ((string)($game['status'] ?? '')==='finished') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Игра уже завершена.','game_finished'); }
        $current=(string)($game['host_mode_snapshot'] ?? 'human');
        if ($current===$targetMode) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'host_mode_unchanged','hostMode'=>$current)); }
        if ($targetMode==='ai') {
            if (!ckm_quiz_chgk_ai_host_available()) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'ИИ-ведущий сейчас не настроен.','ai_host_unavailable'); }
            $flow=ckm_quiz_chgk_question_flow_state($game);
            if (!ckm_quiz_chgk_ai_takeover_is_safe($game,$flow)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Передать управление ИИ можно в ожидании, во время решения о досрочном ответе, во время обсуждения или после закрытия ответов.','host_switch_unsafe_phase'); }
        }
        $now=ckm_quiz_now_mysql();
        $ok=$wpdb->update(ckm_quiz_games_table(),array('host_mode_snapshot'=>$targetMode,'host_mode'=>$targetMode,'updated_at'=>$now),array('id'=>$gameId));
        if ($ok===false) throw new RuntimeException($wpdb->last_error ?: 'host_switch_update_failed');
        $action=$targetMode==='human' ? 'human_host_takeover' : 'ai_host_takeover';
        $eventId=ckm_quiz_append_event($gameId,$action,'host',(int)($auth['user_id'] ?? 0),0,(int)($game['current_question_id'] ?? 0),'game',$gameId,array('from'=>$current,'to'=>$targetMode,'phase'=>(string)($game['quiz_phase'] ?? '')),'chgk-host-switch-'.$gameId.'-'.$targetMode.'-'.(int)($game['state_version'] ?? 0));
        if ($eventId<=0) throw new RuntimeException('host_switch_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK host switch failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось переключить ведущего.','host_switch_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>$targetMode==='human'?'human_host_takeover':'ai_host_takeover','game'=>$fresh,'hostMode'=>$targetMode));
}

function ckm_quiz_chgk_final_answer_is_open(array $game): bool {
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') return false;
    $gameId=(int)($game['id'] ?? 0);
    $questionId=(int)($game['current_question_id'] ?? 0);
    if ($gameId<=0 || $questionId<=0 || (string)($game['quiz_phase'] ?? '') !== 'question_open') return false;
    return ckm_quiz_chgk_question_event_exists($gameId,$questionId,'chgk_final_answer_opened');
}


function ckm_quiz_chgk_final_answer_opened_at(int $gameId, int $questionId): string {
    if ($gameId <= 0 || $questionId <= 0) return '';
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare(
        "SELECT created_at FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND question_id=%d AND action='chgk_final_answer_opened' ORDER BY id DESC LIMIT 1",
        $gameId,
        $questionId
    ));
}

function ckm_quiz_chgk_final_answer_remaining_grace(array $game, int $minimumSeconds = 10): int {
    $gameId = (int)($game['id'] ?? 0);
    $qid = (int)($game['current_question_id'] ?? 0);
    if ($gameId <= 0 || $qid <= 0) return 0;
    $openedAt = ckm_quiz_chgk_final_answer_opened_at($gameId, $qid);
    $openedTs = $openedAt !== '' ? ckm_quiz_mysql_timestamp($openedAt) : 0;
    if ($openedTs <= 0) return 0;
    $elapsed = max(0, ckm_quiz_now_timestamp() - $openedTs);
    return max(0, $minimumSeconds - $elapsed);
}

function ckm_quiz_chgk_open_final_answer_window(int $gameId, string $actorType='system', int $actorUserId=0): array {
    if ($gameId<=0) return ckm_quiz_error(404,'Игра не найдена.','game_not_found');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Окно финального ответа доступно только для «Битвы знатоков».','chgk_required');
        }
        if ((string)($game['status'] ?? '')!=='live' || (string)($game['quiz_phase'] ?? '')!=='question_open' || (int)($game['current_question_id'] ?? 0)<=0) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Сейчас нет открытого обсуждения.','question_not_open');
        }
        $qid=(int)$game['current_question_id'];
        if (ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_final_answer_opened')) {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true,200,array('action'=>'final_answer_already_open','game'=>$game));
        }
        $discussionStarted=ckm_quiz_chgk_discussion_is_started($game);
        $earlyAnswer=!$discussionStarted;
        if($earlyAnswer){
            $earlyDeadline=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
            if($earlyDeadline>0 && ckm_quiz_now_timestamp()>=$earlyDeadline){
                $wpdb->query('ROLLBACK');
                if(function_exists('ckm_quiz_chgk_start_discussion')) ckm_quiz_chgk_start_discussion($gameId,$actorType,$actorUserId);
                return ckm_quiz_error(409,'Время досрочного ответа прошло. Началось обсуждение команды.','early_answer_window_closed');
            }
            if(!ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_early_answer_claimed')){
                $claimEvent=ckm_quiz_append_event($gameId,'chgk_early_answer_claimed',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,array('claimedAt'=>ckm_quiz_now_mysql()),'chgk-early-answer-claimed-'.$qid);
                if($claimEvent<=0) throw new RuntimeException('chgk_early_answer_claim_event_failed');
            }
            if(!ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_early_answer_window_closed')){
                $earlyClosed=ckm_quiz_append_event($gameId,'chgk_early_answer_window_closed',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,array('closedAt'=>ckm_quiz_now_mysql(),'reason'=>'early_answer_claimed'),'chgk-early-answer-closed-'.$qid);
                if($earlyClosed<=0) throw new RuntimeException('chgk_early_answer_close_event_failed');
            }
        } else {
            $closedEvent=ckm_quiz_append_event(
                $gameId,'chgk_discussion_closed',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,
                array('closedAt'=>ckm_quiz_now_mysql()),
                'chgk-discussion-closed-'.$qid
            );
            if ($closedEvent<=0) throw new RuntimeException('chgk_discussion_close_event_failed');
        }
        $settings=ckm_quiz_chgk_question_flow_settings($game);
        $seconds=(int)$settings['finalAnswerSeconds'];
        $deadline=ckm_quiz_deadline_mysql($seconds);
        $now=ckm_quiz_now_mysql();
        $updated=$wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$deadline,'updated_at'=>$now),array('id'=>$gameId));
        if ($updated===false) throw new RuntimeException($wpdb->last_error ?: 'chgk_final_answer_deadline_update_failed');
        $openedEvent=ckm_quiz_append_event(
            $gameId,'chgk_final_answer_opened',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,
            array('finalAnswerSeconds'=>$seconds,'deadlineAt'=>$deadline,'earlyAnswer'=>$earlyAnswer),
            'chgk-final-answer-opened-'.$qid
        );
        if ($openedEvent<=0) throw new RuntimeException('chgk_final_answer_open_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK final-answer window failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось открыть окно окончательного ответа.','final_answer_open_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'final_answer_opened','game'=>$fresh,'finalAnswerSeconds'=>$seconds,'deadlineAt'=>$deadline));
}

function ckm_quiz_chgk_question_event_exists(int $gameId, int $questionId, string $action): bool {
    if ($gameId <= 0 || $questionId <= 0 || $action === '') return false;
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND question_id=%d AND action=%s",
        $gameId, $questionId, sanitize_key($action)
    )) > 0;
}

function ckm_quiz_chgk_question_event_created_at(int $gameId, int $questionId, string $action): string {
    if ($gameId <= 0 || $questionId <= 0 || $action === '') return '';
    global $wpdb;
    return (string)$wpdb->get_var($wpdb->prepare(
        "SELECT created_at FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND question_id=%d AND action=%s ORDER BY id DESC LIMIT 1",
        $gameId, $questionId, sanitize_key($action)
    ));
}

function ckm_quiz_chgk_discussion_is_started(array $game): bool {
    $gameId=(int)($game['id'] ?? 0); $qid=(int)($game['current_question_id'] ?? 0);
    return $gameId>0 && $qid>0 && ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_discussion_started');
}

function ckm_quiz_chgk_early_answer_is_claimed(int $gameId, int $questionId): bool {
    return ckm_quiz_chgk_question_event_exists($gameId,$questionId,'chgk_early_answer_claimed');
}

function ckm_quiz_chgk_start_ai_early_answer_after_voice(int $gameId, int $messageEventId): array {
    if ($gameId<=0 || $messageEventId<=0) return ckm_quiz_error(422,'Некорректное подтверждение озвучивания.','voice_playback_invalid');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Действие доступно только для «Битвы знатоков».','chgk_required'); }
        if ((string)($game['host_mode_snapshot'] ?? '')!=='ai') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'host_mode_human')); }

        $event=$wpdb->get_row($wpdb->prepare(
            "SELECT id,question_id,payload_json FROM ".ckm_quiz_events_table()." WHERE id=%d AND game_id=%d AND action='ai_host_message' LIMIT 1",
            $messageEventId,$gameId
        ),ARRAY_A);
        if (!$event) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Сообщение ИИ-ведущего не найдено.','voice_playback_stale'); }
        $payload=ckm_quiz_json_decode((string)($event['payload_json'] ?? ''));
        $hostEvent=sanitize_key((string)($payload['event'] ?? ''));
        $qid=(int)($event['question_id'] ?? 0);
        $currentQid=(int)($game['current_question_id'] ?? 0);

        if ($hostEvent==='question_closed' || $hostEvent==='answer_revealed') {
            if ($qid<=0 || $qid!==$currentQid) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Подтверждение относится не к текущему вопросу.','voice_playback_stale'); }
            $markerAction=$hostEvent==='question_closed' ? 'chgk_ai_arbitration_voice_completed' : 'chgk_answer_reveal_voice_completed';
            $markerKey=($hostEvent==='question_closed' ? 'chgk-ai-arbitration-voice-completed-' : 'chgk-answer-reveal-voice-completed-').$qid;
            if (!ckm_quiz_chgk_question_event_exists($gameId,$qid,$markerAction)) {
                $markerId=ckm_quiz_append_event(
                    $gameId,$markerAction,'ai_host',0,0,$qid,'host_message',$messageEventId,
                    array('messageEventId'=>$messageEventId,'hostEvent'=>$hostEvent,'completedAt'=>ckm_quiz_now_mysql()),
                    $markerKey
                );
                if ($markerId<=0) throw new RuntimeException('voice_stage_completion_event_failed');
            }
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true,200,array('action'=>$markerAction,'messageEventId'=>$messageEventId));
        }

        if ($hostEvent!=='question_started') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'voice_event_no_timer_bridge','hostEvent'=>$hostEvent)); }
        if ((string)($game['status'] ?? '')!=='live' || (string)($game['quiz_phase'] ?? '')!=='question_open') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'question_not_open')); }
        if ($qid<=0 || $qid!==$currentQid) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Подтверждение относится не к текущему вопросу.','voice_playback_stale'); }
        if (!ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_question_narration_started')) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'narration_not_deferred')); }
        if (ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_early_answer_window_started')) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'early_answer_window_already_started','game'=>$game)); }

        if (!ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_question_narration_completed')) {
            $narrationEvent=ckm_quiz_append_event(
                $gameId,'chgk_question_narration_completed','ai_host',0,0,$qid,'host_message',$messageEventId,
                array('messageEventId'=>$messageEventId,'completedAt'=>ckm_quiz_now_mysql()),
                'chgk-question-narration-completed-'.$qid
            );
            if ($narrationEvent<=0) throw new RuntimeException('voice_playback_event_failed');
        }

        $settings=ckm_quiz_chgk_question_flow_settings($game);
        $seconds=(int)$settings['earlyAnswerSeconds'];
        $deadline=ckm_quiz_deadline_mysql($seconds);
        $now=ckm_quiz_now_mysql();
        $updated=$wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$deadline,'updated_at'=>$now),array('id'=>$gameId));
        if ($updated===false) throw new RuntimeException($wpdb->last_error ?: 'chgk_early_answer_deadline_update_failed');
        $eventId=ckm_quiz_append_event(
            $gameId,'chgk_early_answer_window_started','ai_host',0,0,$qid,'format_runtime',$qid,
            array('earlyAnswerSeconds'=>$seconds,'deadlineAt'=>$deadline,'singleFinalAnswer'=>true,'afterNarration'=>true),
            'chgk-early-answer-started-'.$qid
        );
        if ($eventId<=0) throw new RuntimeException('chgk_early_answer_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK voice-stage completion failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось подтвердить завершение реплики ИИ-ведущего.','voice_stage_completion_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'early_answer_window_started','game'=>$fresh,'earlyAnswerSeconds'=>$seconds,'deadlineAt'=>$deadline));
}

function ckm_quiz_chgk_start_discussion(int $gameId, string $actorType='system', int $actorUserId=0): array {
    if ($gameId<=0) return ckm_quiz_error(404,'Игра не найдена.','game_not_found');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Действие доступно только для «Битвы знатоков».','chgk_required'); }
        if ((string)($game['status'] ?? '')!=='live' || (string)($game['quiz_phase'] ?? '')!=='question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Сейчас нет открытого вопроса.','question_not_open'); }
        $qid=(int)($game['current_question_id'] ?? 0);
        if ($qid<=0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Текущий вопрос не найден.','question_missing'); }
        if (ckm_quiz_chgk_final_answer_is_open($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Окно окончательного ответа уже открыто.','final_answer_open'); }
        if (ckm_quiz_chgk_discussion_is_started($game)) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'discussion_already_started','game'=>$game)); }
        $hadEarlyWindow=ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_early_answer_window_started');
        if ($hadEarlyWindow && !ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_early_answer_window_closed')) {
            $earlyClosed=ckm_quiz_append_event($gameId,'chgk_early_answer_window_closed',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,array('closedAt'=>ckm_quiz_now_mysql()),'chgk-early-answer-closed-'.$qid);
            if ($earlyClosed<=0) throw new RuntimeException('chgk_early_answer_close_event_failed');
        }
        $settings=ckm_quiz_chgk_question_flow_settings($game);
        $seconds=60;
        $deadline=ckm_quiz_deadline_mysql($seconds); $now=ckm_quiz_now_mysql();
        $updated=$wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$deadline,'updated_at'=>$now),array('id'=>$gameId));
        if ($updated===false) throw new RuntimeException($wpdb->last_error ?: 'chgk_discussion_deadline_update_failed');
        $eventId=ckm_quiz_append_event($gameId,'chgk_discussion_started',$actorType,$actorUserId,0,$qid,'format_runtime',$qid,array('discussionSeconds'=>$seconds,'deadlineAt'=>$deadline,'singleFinalAnswer'=>true),'chgk-discussion-started-'.$qid);
        if ($eventId<=0) throw new RuntimeException('chgk_discussion_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK start discussion failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось начать обсуждение.','discussion_start_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'discussion_started','game'=>$fresh,'discussionSeconds'=>$seconds,'deadlineAt'=>$deadline));
}

function ckm_quiz_chgk_bonus_minutes_state(array $game): array {
    $gameId=(int)($game['id'] ?? 0);
    if ($gameId<=0 || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') return array('earned'=>0,'used'=>0,'available'=>0);
    global $wpdb;
    $answers=$wpdb->get_results($wpdb->prepare(
        "SELECT answer_payload_json,verdict,awarded_points FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d",
        $gameId
    ),ARRAY_A) ?: array();
    $earned=0;
    foreach($answers as $row){
        $payload=ckm_quiz_json_decode($row['answer_payload_json'] ?? '');
        $early=!empty($payload['earlyAnswer']);
        $verdict=sanitize_key((string)($row['verdict'] ?? ''));
        $points=(int)($row['awarded_points'] ?? 0);
        if($early && in_array($verdict,array('accepted','correct'),true)) $earned++;
    }
    $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND action='chgk_bonus_minute_used'",$gameId));
    return array('earned'=>$earned,'used'=>$used,'available'=>max(0,$earned-$used));
}

function ckm_quiz_chgk_use_bonus_minute(array $auth): array {
    if ((string)($auth['role'] ?? '') !== 'participant') return ckm_quiz_error(403,'Дополнительную минуту может использовать только команда.','participant_required');
    $gameId=(int)($auth['game_id'] ?? 0); $teamId=(int)($auth['team_id'] ?? 0); $userId=(int)($auth['user_id'] ?? 0);
    if ($gameId<=0 || $teamId<=0 || $userId<=0) return ckm_quiz_error(401,'Сессия участника недействительна.','participant_session_invalid');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try{
        $game=ckm_quiz_get_game($gameId,true);
        if(!$game){$wpdb->query('ROLLBACK');return ckm_quiz_error(404,'Игра не найдена.','game_not_found');}
        if(!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk'){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Дополнительная минута доступна только в «Битве знатоков».','chgk_required');}
        if(!empty($game['is_paused'])){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Игра на паузе.','game_paused');}
        if((string)($game['status']??'')!=='live' || (string)($game['quiz_phase']??'')!=='question_open'){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Сейчас нет обсуждения.','question_not_open');}
        $member=ckm_quiz_get_membership($gameId,$userId,true);
        if(!$member || (int)$member['team_id']!==$teamId){$wpdb->query('ROLLBACK');return ckm_quiz_error(403,'Вы не состоите в этой команде.','foreign_game_or_team');}
        $flow=ckm_quiz_chgk_question_flow_state($game);
        if((string)($flow['phase'] ?? '')!=='discussion'){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Дополнительную минуту можно использовать только во время обсуждения.','bonus_minute_wrong_phase');}
        $bonus=ckm_quiz_chgk_bonus_minutes_state($game);
        if((int)($bonus['available'] ?? 0)<=0){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'У команды нет заработанных дополнительных минут.','bonus_minute_unavailable');}
        $deadlineTs=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
        if($deadlineTs<=0 || ckm_quiz_now_timestamp()>=$deadlineTs){$wpdb->query('ROLLBACK');return ckm_quiz_error(409,'Время обсуждения уже истекло.','deadline_passed');}
        $newDeadline=date('Y-m-d H:i:s',$deadlineTs+60);
        $now=ckm_quiz_now_mysql();
        $ok=$wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$newDeadline,'updated_at'=>$now),array('id'=>$gameId));
        if($ok===false) throw new RuntimeException($wpdb->last_error ?: 'bonus_deadline_update_failed');
        $qid=(int)($game['current_question_id'] ?? 0);
        $eventId=ckm_quiz_append_event($gameId,'chgk_bonus_minute_used','participant',$userId,$teamId,$qid,'game',$gameId,array('addedSeconds'=>60,'deadlineAt'=>$newDeadline),'chgk-bonus-minute-used-'.$gameId.'-'.$qid.'-'.(int)($bonus['used'] ?? 0));
        if($eventId<=0) throw new RuntimeException('bonus_minute_event_failed');
        $wpdb->query('COMMIT');
    }catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK bonus minute failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось использовать дополнительную минуту.','bonus_minute_failed');
    }
    $fresh=ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true,200,array('action'=>'bonus_minute_used','game'=>$fresh,'bonusMinutes'=>$fresh?ckm_quiz_chgk_bonus_minutes_state($fresh):array()));
}

function ckm_quiz_chgk_question_flow_state(array $game): array {
    $isChgk = function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'chgk';
    if (!$isChgk) return array('enabled'=>false,'phase'=>(string)($game['quiz_phase'] ?? 'waiting'));
    $settings = ckm_quiz_chgk_question_flow_settings($game);
    $gameId = (int)($game['id'] ?? 0);
    $questionId = (int)($game['current_question_id'] ?? 0);
    $core = (string)($game['quiz_phase'] ?? 'waiting');
    if ($questionId <= 0 || $core === 'waiting') return array_merge($settings, array('enabled'=>true,'phase'=>'waiting','questionId'=>$questionId,'canStartReview'=>false,'canFinishReview'=>false,'canRevealAnswer'=>false,'answerRevealed'=>false,'publicReveal'=>false));
    if ((string)($game['status'] ?? '') === 'finished' || $core === 'finished') return array_merge($settings, array('enabled'=>true,'phase'=>'finished','questionId'=>$questionId,'canStartReview'=>false,'canFinishReview'=>false,'canRevealAnswer'=>false,'answerRevealed'=>true,'publicReveal'=>true));
    if ($core === 'question_open') {
        if (!empty($game['is_paused']) && (string)($game['paused_format_phase'] ?? '') !== '') {
            $phase = (string)$game['paused_format_phase'];
        } elseif (ckm_quiz_chgk_final_answer_is_open($game)) $phase='final_answer_open';
        elseif (ckm_quiz_chgk_discussion_is_started($game)) $phase='discussion';
        elseif (ckm_quiz_chgk_question_event_exists($gameId,$questionId,'chgk_early_answer_window_started')) $phase='early_answer_offer';
        elseif ((string)($game['host_mode_snapshot'] ?? '')==='ai' && ckm_quiz_chgk_question_event_exists($gameId,$questionId,'chgk_question_narration_started')) $phase='question_narration';
        else $phase='early_answer_offer';
        $bonus=ckm_quiz_chgk_bonus_minutes_state($game);
        return array_merge($settings, array('enabled'=>true,'phase'=>$phase,'questionId'=>$questionId,'canStartReview'=>false,'canFinishReview'=>false,'canRevealAnswer'=>false,'answerRevealed'=>false,'publicReveal'=>false,'bonusMinutes'=>$bonus));
    }

    global $wpdb;
    $pending = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending'",
        $gameId, $questionId
    ));
    $answersClosed = ckm_quiz_chgk_question_event_exists($gameId, $questionId, 'chgk_answers_closed');
    $arbitrationRequested = ckm_quiz_chgk_question_event_exists($gameId, $questionId, 'chgk_arbitration_requested');
    $revealed = ckm_quiz_chgk_question_event_exists($gameId, $questionId, 'chgk_answer_revealed');
    $started = ckm_quiz_chgk_question_event_exists($gameId, $questionId, 'question_review_started');
    $finished = ckm_quiz_chgk_question_event_exists($gameId, $questionId, 'question_review_finished');
    if ($finished) $phase='completed';
    elseif ($started) $phase='review';
    elseif ($revealed) $phase='answer_reveal';
    elseif ($arbitrationRequested || $pending > 0) $phase='arbitration';
    else $phase='answers_closed';
    $canReveal = $core === 'question_closed' && $pending === 0 && !$revealed;
    return array_merge($settings, array(
        'enabled'=>true,
        'phase'=>$phase,
        'questionId'=>$questionId,
        'answersClosed'=>$answersClosed,
        'arbitrationRequested'=>$arbitrationRequested,
        'pendingArbitration'=>$pending,
        'answerRevealed'=>$revealed,
        'finalAnswerOpenedAt'=>ckm_quiz_chgk_final_answer_opened_at($gameId, $questionId),
        'reviewStarted'=>$started,
        'reviewFinished'=>$finished,
        'canRevealAnswer'=>$canReveal,
        'canStartReview'=>$settings['reviewEnabled'] && $revealed && !$started && !$finished && $pending === 0 && $core === 'question_closed',
        'canFinishReview'=>$settings['reviewEnabled'] && $started && !$finished,
        'canGoNext'=>$core === 'question_closed' && $revealed && $pending === 0 && (!$settings['reviewRequired'] || $finished) && (!$started || $finished),
        'publicReveal'=>$revealed,
    ));
}

function ckm_quiz_chgk_single_team_duel_state(array $game): array {
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') {
        return array('enabled'=>false,'teamCount'=>0);
    }
    $gameId=(int)($game['id'] ?? 0);
    if ($gameId<=0) return array('enabled'=>false,'teamCount'=>0);
    global $wpdb;
    $teams=$wpdb->get_results($wpdb->prepare(
        "SELECT id,team_name,score FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active' ORDER BY slot_no ASC,id ASC LIMIT 2",
        $gameId
    ),ARRAY_A) ?: array();
    if (count($teams)!==1) return array('enabled'=>false,'teamCount'=>count($teams));
    $team=$teams[0];
    $teamId=(int)$team['id'];
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT r.question_id,a.id answer_id,a.verdict,a.awarded_points
         FROM (
           SELECT DISTINCT question_id
           FROM " . ckm_quiz_events_table() . "
           WHERE game_id=%d AND action='chgk_answer_revealed' AND question_id>0
         ) r
         LEFT JOIN " . ckm_quiz_answers_table() . " a
           ON a.game_id=%d AND a.team_id=%d AND a.question_id=r.question_id
         ORDER BY r.question_id ASC",
        $gameId,$gameId,$teamId
    ),ARRAY_A) ?: array();
    $gameScore=0;
    $outcomes=array();
    $currentQuestionId=(int)($game['current_question_id'] ?? 0);
    $currentOutcome='';
    $currentVerdict='pending';
    foreach($rows as $row){
        $hasAnswer=(int)($row['answer_id'] ?? 0)>0;
        $verdict=$hasAnswer ? sanitize_key((string)($row['verdict'] ?? 'pending')) : 'no_answer';
        if ($verdict==='') $verdict='pending';
        $points=$hasAnswer ? (int)($row['awarded_points'] ?? 0) : 0;
        if (in_array($verdict,array('accepted','correct'),true) || $points>0) {
            $outcome='experts';
        } elseif (in_array($verdict,array('rejected','incorrect','no_answer'),true) || ($verdict!=='pending' && $points<=0)) {
            $outcome='game';
            $gameScore++;
        } else {
            $outcome='pending';
        }
        $qid=(int)$row['question_id'];
        $outcomes[]=array('questionId'=>$qid,'verdict'=>$verdict,'outcome'=>$outcome,'points'=>$points);
        if ($qid===$currentQuestionId) { $currentOutcome=$outcome; $currentVerdict=$verdict; }
    }
    $settings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : array();
    $targetScore=max(1,(int)($settings['winScore'] ?? 6));
    $expertsScore=(int)$team['score'];
    $winner=$expertsScore >= $targetScore ? 'experts' : ($gameScore >= $targetScore ? 'game' : '');
    return array(
        'enabled'=>true,
        'teamCount'=>1,
        'teamId'=>$teamId,
        'teamName'=>(string)$team['team_name'],
        'expertsLabel'=>'Знатоки',
        'gameLabel'=>'Игра',
        'expertsScore'=>$expertsScore,
        'gameScore'=>$gameScore,
        'targetScore'=>$targetScore,
        'winner'=>$winner,
        'matchFinished'=>$winner!=='',
        'playedQuestions'=>count($rows),
        'currentQuestionId'=>$currentQuestionId,
        'currentOutcome'=>$currentOutcome,
        'currentVerdict'=>$currentVerdict,
        'outcomes'=>$outcomes,
    );
}

function ckm_quiz_chgk_reveal_answer(int $gameId, array $auth): array {
    $role=(string)($auth['role'] ?? '');
    if (!in_array($role, array('host','admin','ai_host','system'), true)) return ckm_quiz_error(403,'Показать правильный ответ может только ведущий.','host_required');
    $game=ckm_quiz_get_game($gameId);
    if (!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') return ckm_quiz_error(409,'Раскрытие ответа доступно только для «Битвы знатоков».','chgk_required');
    if ($role==='ai_host' && (string)($game['host_mode_snapshot'] ?? '')!=='ai') return ckm_quiz_error(409,'Автоматическое действие ИИ отменено: включено ручное управление.','ai_host_manual_takeover');
    if ((string)($game['quiz_phase'] ?? '')!=='question_closed' || (int)($game['current_question_id'] ?? 0)<=0) return ckm_quiz_error(409,'Сначала закройте приём окончательных ответов.','answers_not_closed');
    $flow=ckm_quiz_chgk_question_flow_state($game);
    if (!empty($flow['answerRevealed'])) return ckm_quiz_result(true,200,array('action'=>'answer_already_revealed','questionFlow'=>$flow));
    if ((int)($flow['pendingArbitration'] ?? 0)>0) return ckm_quiz_error(409,'Сначала завершите арбитраж всех ответов.','arbitration_pending');
    $qid=(int)$game['current_question_id'];
    $actorType=$role==='ai_host' ? 'ai_host' : ($role==='system' ? 'system' : 'host');
    $actorUserId=(int)($auth['user_id'] ?? 0);
    $eventId=ckm_quiz_append_event(
        $gameId,'chgk_answer_revealed',$actorType,$actorUserId,0,$qid,'question',$qid,
        array('questionId'=>$qid,'revealedAt'=>ckm_quiz_now_mysql()),
        'chgk-answer-revealed-'.$qid
    );
    if ($eventId<=0) return ckm_quiz_error(500,'Не удалось раскрыть правильный ответ.','answer_reveal_event_failed');
    $question=ckm_quiz_get_question($qid);
    $aiHost=array();
    if ($question && (string)($game['host_mode_snapshot'] ?? '')==='ai' && function_exists('ckm_quiz_publish_ai_host_event')) {
        $aiHost=ckm_quiz_publish_ai_host_event($gameId,'answer_revealed',$question,array('answerReveal'=>true),'ai-answer-revealed-'.$qid);
    }
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    $duel=ckm_quiz_chgk_single_team_duel_state($fresh);
    $matchFinish=array();
    if (!empty($duel['enabled']) && !empty($duel['matchFinished']) && (string)($fresh['status'] ?? '')!=='finished') {
        $finishActor=$role==='ai_host' ? 'ai_host' : ($role==='system' ? 'system' : 'host');
        $matchFinish=ckm_quiz_finish_game($gameId,$finishActor,$actorUserId,true);
        $fresh=ckm_quiz_get_game($gameId) ?: $fresh;
        $duel=ckm_quiz_chgk_single_team_duel_state($fresh);
    }
    return ckm_quiz_result(true,200,array('action'=>'answer_revealed','eventId'=>$eventId,'aiHost'=>$aiHost,'questionFlow'=>ckm_quiz_chgk_question_flow_state($fresh),'singleTeamDuel'=>$duel,'matchFinish'=>$matchFinish));
}

function ckm_quiz_chgk_start_review(int $gameId, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''), array('host','admin'), true)) return ckm_quiz_error(403, 'Открыть разбор может только ведущий.', 'host_required');
    $game = ckm_quiz_get_game($gameId);
    if (!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') return ckm_quiz_error(409, 'Разбор вопроса доступен только для «Битвы знатоков».', 'chgk_required');
    $flow = ckm_quiz_chgk_question_flow_state($game);
    if (!$flow['reviewEnabled']) return ckm_quiz_error(409, 'Разбор вопроса отключён в настройках формата.', 'review_disabled');
    if (!empty($flow['reviewFinished'])) return ckm_quiz_error(409, 'Разбор этого вопроса уже завершён.', 'review_already_finished');
    if (!empty($flow['reviewStarted'])) return ckm_quiz_error(409, 'Разбор этого вопроса уже открыт.', 'review_already_started');
    if ((int)($flow['pendingArbitration'] ?? 0) > 0) return ckm_quiz_error(409, 'Сначала завершите арбитраж всех ответов.', 'arbitration_pending');
    if (empty($flow['answerRevealed'])) return ckm_quiz_error(409, 'Сначала покажите правильный ответ.', 'answer_not_revealed');
    if ((string)($game['quiz_phase'] ?? '') !== 'question_closed') return ckm_quiz_error(409, 'Сначала завершите обсуждение.', 'question_not_closed');
    $qid=(int)$game['current_question_id'];
    $eventId=ckm_quiz_append_event($gameId,'question_review_started','host',(int)($auth['user_id'] ?? 0),0,$qid,'question',$qid,array('questionId'=>$qid),'question-review-started-'.$qid);
    if ($eventId<=0) return ckm_quiz_error(500,'Не удалось открыть разбор вопроса.','review_event_failed');
    $aiHost=array();
    $question=ckm_quiz_get_question($qid);
    if ($question && (string)($game['host_mode_snapshot'] ?? '')==='ai' && function_exists('ckm_quiz_publish_ai_host_event')) {
        $aiHost=ckm_quiz_publish_ai_host_event($gameId,'question_review_started',$question,array('review'=>true),'ai-question-review-started-'.$qid);
        if (!empty($aiHost['ok'])) ckm_quiz_append_event($gameId,'ai_host_comment_generated','ai_host',0,0,$qid,'question',$qid,array('phase'=>'review'),'ai-host-review-comment-'.$qid);
    }
    return ckm_quiz_result(true,200,array('action'=>'review_started','eventId'=>$eventId,'aiHost'=>$aiHost,'questionFlow'=>ckm_quiz_chgk_question_flow_state(ckm_quiz_get_game($gameId) ?: $game)));
}

function ckm_quiz_chgk_finish_review(int $gameId, array $auth): array {
    if (!in_array((string)($auth['role'] ?? ''), array('host','admin'), true)) return ckm_quiz_error(403, 'Завершить разбор может только ведущий.', 'host_required');
    $game=ckm_quiz_get_game($gameId);
    if (!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') return ckm_quiz_error(409,'Разбор вопроса доступен только для «Битвы знатоков».','chgk_required');
    $flow=ckm_quiz_chgk_question_flow_state($game);
    if (!empty($flow['reviewFinished'])) return ckm_quiz_error(409,'Разбор этого вопроса уже завершён.','review_already_finished');
    if (empty($flow['reviewStarted'])) return ckm_quiz_error(409,'Сначала откройте разбор вопроса.','review_not_started');
    if ((int)($flow['pendingArbitration'] ?? 0)>0) return ckm_quiz_error(409,'Нельзя завершить разбор, пока есть ответы без решения.','arbitration_pending');
    $qid=(int)$game['current_question_id'];
    $eventId=ckm_quiz_append_event($gameId,'question_review_finished','host',(int)($auth['user_id'] ?? 0),0,$qid,'question',$qid,array('questionId'=>$qid),'question-review-finished-'.$qid);
    if ($eventId<=0) return ckm_quiz_error(500,'Не удалось завершить разбор вопроса.','review_finish_event_failed');
    $comparative=array('scheduled'=>false,'reason'=>'unavailable');
    if (function_exists('ckm_quiz_chgk_schedule_comparative_analysis')) {
        $comparative=ckm_quiz_chgk_schedule_comparative_analysis($gameId,$qid);
    }
    $question=ckm_quiz_get_question($qid);
    $aiHost=array();
    if ($question && (string)($game['host_mode_snapshot'] ?? '')==='ai' && function_exists('ckm_quiz_publish_ai_host_event')) {
        $aiHost=ckm_quiz_publish_ai_host_event($gameId,'question_review_finished',$question,array('reviewFinished'=>true,'comparativeAnalysis'=>$comparative),'ai-question-review-finished-'.$qid);
    }
    return ckm_quiz_result(true,200,array('action'=>'review_finished','eventId'=>$eventId,'comparativeAnalysis'=>$comparative,'aiHost'=>$aiHost,'questionFlow'=>ckm_quiz_chgk_question_flow_state(ckm_quiz_get_game($gameId) ?: $game)));
}

function ckm_quiz_chgk_maybe_auto_close_question(int $gameId): array {
    $game=ckm_quiz_get_game($gameId);
    if (!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') return array('closed'=>false,'reason'=>'not_chgk');
    $settings=ckm_quiz_chgk_question_flow_settings($game);
    if (empty($settings['closeWhenAllAnswered']) || (string)($game['quiz_phase'] ?? '')!=='question_open') return array('closed'=>false,'reason'=>'disabled_or_not_open');
    global $wpdb;
    $teamCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_teams_table()." WHERE game_id=%d AND team_status='active'",$gameId));
    $answerCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT team_id) FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d",$gameId,(int)$game['current_question_id']));
    if ($teamCount<=0 || $answerCount<$teamCount) return array('closed'=>false,'reason'=>'waiting','answered'=>$answerCount,'teams'=>$teamCount);
    $qid=(int)$game['current_question_id'];
    $closed=ckm_quiz_close_current_question($gameId,'system',0);
    if (!empty($closed['ok'])) ckm_quiz_append_event($gameId,'question_auto_closed','system',0,0,$qid,'question',$qid,array('answeredTeams'=>$answerCount,'teamCount'=>$teamCount),'question-auto-closed-'.$qid);
    return array('closed'=>!empty($closed['ok']),'answered'=>$answerCount,'teams'=>$teamCount,'result'=>$closed);
}

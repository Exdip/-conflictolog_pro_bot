<?php
/**
 * alpha.85.4: simplified final round for «Интеллектуальный батл».
 *
 * Hardening: reveal is forbidden before the server deadline; missing answers
 * become no_answer only after the real submission window has ended.
 *
 * The final is one fixed-value question. There are no wagers. Every team may
 * submit one private answer while the final is open. The host reveals all
 * answers together and resolves submitted answers. A correct final answer adds
 * a fixed 500 points; an incorrect or missing answer adds 0. score_events
 * remains the authoritative ledger.
 */

if (!defined('ABSPATH')) exit;

function ckm_quiz_jeopardy_final_question(array $game): ?array {
    if (!function_exists('ckm_quiz_jeopardy_is_active') || !ckm_quiz_jeopardy_is_active($game)) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND question_stage='final' ORDER BY position ASC,id ASC LIMIT 1",
        (int)$game['quiz_id'], (int)$game['quiz_revision']
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_jeopardy_final_is_current(array $game): bool {
    if (!function_exists('ckm_quiz_jeopardy_is_active') || !ckm_quiz_jeopardy_is_active($game)) return false;
    $qid = (int)($game['current_question_id'] ?? 0);
    if ($qid <= 0) return false;
    $q = ckm_quiz_get_question($qid);
    return is_array($q) && (string)($q['question_stage'] ?? 'main') === 'final';
}

function ckm_quiz_jeopardy_final_points(array $game = array()): int {
    // Deliberately fixed for the simplified ruleset. A later editor option can
    // expose this without reintroducing a wager mechanic.
    return 500;
}

function ckm_quiz_jeopardy_final_control_row(int $gameId, int $questionId, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $questionId <= 0) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='jeopardy_final_control' ORDER BY id DESC LIMIT 1{$lock}",
        $gameId, $questionId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** Kept only so older callers fail safely instead of fatalling. */
function ckm_quiz_jeopardy_final_wager_row(int $gameId, int $questionId, int $teamId, bool $forUpdate = false): ?array {
    return null;
}

function ckm_quiz_jeopardy_final_board_complete(array $game): bool {
    if (!function_exists('ckm_quiz_jeopardy_board_rows') || !function_exists('ckm_quiz_jeopardy_cell_status_map')) return false;
    $rows = ckm_quiz_jeopardy_board_rows($game);
    if (!$rows) return false;
    $states = ckm_quiz_jeopardy_cell_status_map((int)$game['id']);
    foreach ($rows as $row) {
        $state = (string)($states[(int)$row['id']]['status'] ?? 'available');
        if ($state !== 'played') return false;
    }
    return true;
}

function ckm_quiz_jeopardy_final_team_rows(int $gameId): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id,team_name,slot_no,score,team_status FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active' ORDER BY slot_no ASC,id ASC",
        $gameId
    ), ARRAY_A) ?: array();
}

function ckm_quiz_jeopardy_final_state(array $game, string $role = 'participant'): array {
    if (!function_exists('ckm_quiz_jeopardy_is_active') || !ckm_quiz_jeopardy_is_active($game)) return array('enabled'=>false);
    $question = ckm_quiz_jeopardy_final_question($game);
    if (!$question) return array('enabled'=>false,'configured'=>false);

    $gameId = (int)$game['id'];
    $questionId = (int)$question['id'];
    $control = ckm_quiz_jeopardy_final_control_row($gameId, $questionId, false);
    $status = $control ? (string)($control['status'] ?? 'open') : 'not_started';
    $revealed = $status === 'revealed' || $status === 'resolved';
    $started = $control !== null;
    $deadlineTs = $started ? ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? '')) : 0;
    if ($deadlineTs <= 0 && $control) {
        $controlPayload = ckm_quiz_json_decode($control['payload_json'] ?? '');
        $deadlineTs = ckm_quiz_mysql_timestamp((string)($controlPayload['deadlineAt'] ?? ''));
    }
    $secondsRemaining = $deadlineTs > 0 ? max(0, $deadlineTs - ckm_quiz_now_timestamp()) : 0;
    $awaitingVoice = $started && $status === 'open' && (string)($game['quiz_phase'] ?? '') === 'question_open'
        && (string)($game['host_mode_snapshot'] ?? '') === 'ai' && $deadlineTs <= 0;
    $submissionOpen = $started && $status === 'open' && (string)($game['quiz_phase'] ?? '') === 'question_open' && $secondsRemaining > 0;
    $submissionClosed = $started && in_array($status, array('closed','revealed','resolved'), true);
    $teams = ckm_quiz_jeopardy_final_team_rows($gameId);
    $participantTeamId = 0;
    if ($role === 'participant' && function_exists('ckm_quiz_current_state_auth')) {
        $auth = ckm_quiz_current_state_auth();
        $participantTeamId = (int)($auth['team_id'] ?? 0);
    }

    $rows = array();
    $submittedCount = 0;
    $resolvedCount = 0;
    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        $answer = ckm_quiz_get_answer($gameId, $teamId, $questionId, false);
        if ($answer) $submittedCount++;
        $verdict = $answer ? (string)($answer['verdict'] ?? 'pending') : 'no_answer';
        // Once answers are revealed, a missing answer is already a resolved
        // zero-point result. Submitted answers still require a verdict.
        $resolved = $revealed && (!$answer || $verdict !== 'pending');
        if ($resolved) $resolvedCount++;
        $item = array(
            'teamId'=>$teamId,
            'teamName'=>(string)$team['team_name'],
            'score'=>(int)$team['score'],
            'answerSubmitted'=>(bool)$answer,
            'resolved'=>$resolved,
            'verdict'=>$verdict,
            'finalPoints'=>ckm_quiz_jeopardy_final_points($game),
        );
        $canSeePrivate = $revealed || ($role === 'participant' && $participantTeamId === $teamId);
        if ($canSeePrivate) $item['answerText']=$answer ? (string)$answer['answer_text'] : '';
        $rows[] = $item;
    }

    return array(
        'enabled'=>true,
        'configured'=>true,
        'started'=>$started,
        'status'=>$status,
        'revealed'=>$revealed,
        'questionId'=>$questionId,
        'fixedPoints'=>ckm_quiz_jeopardy_final_points($game),
        'canStart'=>!$started && ckm_quiz_jeopardy_final_board_complete($game) && (string)($game['quiz_phase'] ?? '') !== 'question_open' && (string)($game['status'] ?? '') !== 'finished',
        'open'=>$submissionOpen,
        'awaitingVoice'=>$awaitingVoice,
        'submissionClosed'=>$submissionClosed,
        'secondsRemaining'=>$secondsRemaining,
        'canReveal'=>$started && !$revealed && $submissionClosed && (string)($game['quiz_phase'] ?? '') === 'question_closed',
        'submittedCount'=>$submittedCount,
        'resolvedCount'=>$resolvedCount,
        'teamCount'=>count($teams),
        'allResolved'=>$revealed && count($teams) > 0 && $resolvedCount >= count($teams),
        'rows'=>$rows,
        'label'=>'Финальный раунд',
    );
}

/** Request-local auth slot used by state privacy. */
function ckm_quiz_set_current_state_auth(array $auth): void { $GLOBALS['ckm_quiz_state_auth'] = $auth; }
function ckm_quiz_current_state_auth(): array { return isset($GLOBALS['ckm_quiz_state_auth']) && is_array($GLOBALS['ckm_quiz_state_auth']) ? $GLOBALS['ckm_quiz_state_auth'] : array(); }

function ckm_quiz_jeopardy_start_final(int $gameId, array $auth): array {
    $actorType = sanitize_key((string)($auth['actor_type'] ?? ($auth['role'] ?? 'host')));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финал доступен только в «Интеллектуальном батле».','jeopardy_required'); }
        if ((string)($game['status'] ?? '') === 'finished') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Игра уже завершена.','game_finished'); }
        if ((string)($game['quiz_phase'] ?? '') === 'question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Сначала завершите текущий вопрос.','question_already_open'); }
        if (!ckm_quiz_jeopardy_final_board_complete($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финал можно открыть только после завершения всех ячеек игрового поля.','jeopardy_final_board_incomplete'); }
        $question = ckm_quiz_jeopardy_final_question($game);
        if (!$question) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финальный вопрос не настроен.','jeopardy_final_missing'); }
        if (ckm_quiz_jeopardy_final_control_row($gameId,(int)$question['id'],true)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финальный раунд уже запускался.','jeopardy_final_already_started'); }
        $now = ckm_quiz_now_mysql();
        $seconds = max(15,min(3600,(int)($question['time_limit_seconds'] ?? 60)));
        $deferUntilVoiceEnd = (string)($game['host_mode_snapshot'] ?? '') === 'ai';
        $deadline = $deferUntilVoiceEnd ? '' : ckm_quiz_deadline_mysql($seconds);
        $ok = $wpdb->insert(ckm_quiz_mechanics_table(), array(
            'game_id'=>$gameId,'team_id'=>0,'round_id'=>0,'question_id'=>(int)$question['id'],
            'mechanic_type'=>'jeopardy_final_control','mechanic_key'=>'jeopardy_final_control','status'=>'open','value_int'=>ckm_quiz_jeopardy_final_points($game),'result_int'=>0,
            'actor_type'=>$actorType,'actor_user_id'=>$actorUserId,'idempotency_key'=>'jeopardy-final-control','score_event_id'=>0,
            'payload_json'=>ckm_quiz_json_encode(array('startedAt'=>$now,'deadlineAt'=>$deadline,'seconds'=>$seconds,'timerDeferredUntilVoiceEnd'=>$deferUntilVoiceEnd,'fixedPoints'=>ckm_quiz_jeopardy_final_points($game))),'created_at'=>$now,'resolved_at'=>null,'updated_at'=>$now,
        ));
        if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'final_control_insert_failed');
        if ((string)($game['round_phase'] ?? '') === 'open') {
            $roundClose=ckm_quiz_close_round_locked($game,$actorType,$actorUserId,'final_round',0);
            if (empty($roundClose['ok'])) throw new RuntimeException((string)($roundClose['code'] ?? 'final_round_close_failed'));
        }
        $update=$wpdb->update(ckm_quiz_games_table(),array(
            'status'=>'live','quiz_phase'=>'question_open','current_question_id'=>(int)$question['id'],
            'current_question_position'=>(int)$question['position'],
            'question_started_at'=>$now,'question_deadline_at'=>$deferUntilVoiceEnd ? null : $deadline,'current_round_id'=>0,'current_round_position'=>0,'round_phase'=>'closed','updated_at'=>$now,
        ),array('id'=>$gameId));
        if ($update===false) throw new RuntimeException('final_game_update_failed');
        $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_started',$actorType,$actorUserId,0,(int)$question['id'],'question',(int)$question['id'],array('deadlineAt'=>$deadline,'seconds'=>$seconds,'timerDeferredUntilVoiceEnd'=>$deferUntilVoiceEnd,'fixedPoints'=>ckm_quiz_jeopardy_final_points($game)),'jeopardy-final-started');
        if ($eventId<=0) throw new RuntimeException('final_start_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final start failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось открыть финальный раунд.','jeopardy_final_start_failed');
    }
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    $aiHost=array();
    if ((string)($fresh['host_mode_snapshot']??'')==='ai') {
        if (function_exists('ckm_quiz_publish_ai_host_event')) {
            $aiHost=ckm_quiz_publish_ai_host_event($gameId,'question_started',$question,array('finalRound'=>true),'ai-jeopardy-final-started');
            if (empty($aiHost['ok']) || empty($aiHost['eventId'])) {
                $aiHost['timerFallback']=ckm_quiz_jeopardy_start_final_timer_after_voice($gameId,0);
            }
        } else {
            $aiHost=array('ok'=>false,'code'=>'ai_host_publish_unavailable','eventId'=>0,'timerFallback'=>ckm_quiz_jeopardy_start_final_timer_after_voice($gameId,0));
        }
        $fresh=ckm_quiz_get_game($gameId) ?: $fresh;
    }
    return ckm_quiz_result(true,200,array('final'=>ckm_quiz_jeopardy_final_state($fresh,'host'),'game'=>$fresh,'aiHost'=>$aiHost));
}

/** Arm the final answer window only after the AI-host question finished speaking. */
function ckm_quiz_jeopardy_start_final_timer_after_voice(int $gameId, int $messageEventId=0): array {
    if ($gameId<=0) return ckm_quiz_error(422,'Некорректная игра для запуска финального таймера.','jeopardy_final_timer_invalid');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game || !ckm_quiz_jeopardy_final_is_current($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финальный раунд не открыт.','jeopardy_final_not_current'); }
        if ((string)($game['host_mode_snapshot']??'')!=='ai') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'host_mode_human')); }
        $questionId=(int)($game['current_question_id']??0);
        $control=ckm_quiz_jeopardy_final_control_row($gameId,$questionId,true);
        if (!$control || (string)($control['status']??'')!=='open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Окно финального ответа недоступно.','jeopardy_final_control_missing'); }
        $existing=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at']??''));
        if ($existing>0) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'jeopardy_final_timer_already_started','deadlineAt'=>(string)$game['question_deadline_at'])); }
        $payload=ckm_quiz_json_decode((string)($control['payload_json']??''));
        if (empty($payload['timerDeferredUntilVoiceEnd'])) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'timer_not_deferred')); }
        $question=ckm_quiz_get_question($questionId);
        $seconds=max(15,min(3600,(int)($payload['seconds']??($question['time_limit_seconds']??60))));
        $now=ckm_quiz_now_mysql();
        $deadline=ckm_quiz_deadline_mysql($seconds);
        $payload['deadlineAt']=$deadline;
        $payload['timerStartedAt']=$now;
        $payload['timerStartedAfterVoice']=true;
        $payload['messageEventId']=$messageEventId;
        if ($wpdb->update(ckm_quiz_mechanics_table(),array('payload_json'=>ckm_quiz_json_encode($payload),'updated_at'=>$now),array('id'=>(int)$control['id']))===false) throw new RuntimeException('jeopardy_final_control_timer_update_failed');
        $updated=$wpdb->query($wpdb->prepare(
            "UPDATE ".ckm_quiz_games_table()." SET question_started_at=%s,question_deadline_at=%s,state_version=state_version+1,updated_at=%s WHERE id=%d AND current_question_id=%d AND question_deadline_at IS NULL",
            $now,$deadline,$now,$gameId,$questionId
        ));
        if ($updated===false) throw new RuntimeException($wpdb->last_error ?: 'jeopardy_final_timer_update_failed');
        if ($updated===0) { $fresh=ckm_quiz_get_game($gameId,true) ?: $game; $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('action'=>'jeopardy_final_timer_already_started','deadlineAt'=>(string)($fresh['question_deadline_at']??''))); }
        $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_timer_started','system',0,0,$questionId,'game_mechanic',(int)$control['id'],array('seconds'=>$seconds,'deadlineAt'=>$deadline,'messageEventId'=>$messageEventId,'startedAfterVoice'=>true),'jeopardy-final-timer-started');
        if ($eventId<=0) throw new RuntimeException('jeopardy_final_timer_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final timer start failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось запустить таймер финального ответа.','jeopardy_final_timer_start_failed');
    }
    return ckm_quiz_result(true,200,array('action'=>'jeopardy_final_timer_started','deadlineAt'=>$deadline,'seconds'=>$seconds,'messageEventId'=>$messageEventId));
}

/** Backward-compatible endpoint: wagers were deliberately removed in alpha.83.6. */
function ckm_quiz_jeopardy_place_final_wager(array $auth, int $amount): array {
    return ckm_quiz_error(410,'Финальные ставки убраны. Финальный вопрос имеет фиксированную стоимость 500 баллов.','jeopardy_final_wager_removed');
}

function ckm_quiz_jeopardy_close_final_submission(int $gameId, string $actorType='host', int $actorUserId=0): array {
    global $wpdb; $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game || !ckm_quiz_jeopardy_final_is_current($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финальный раунд не открыт.','jeopardy_final_not_current'); }
        $questionId=(int)$game['current_question_id'];
        $control=ckm_quiz_jeopardy_final_control_row($gameId,$questionId,true);
        if (!$control) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Состояние финального раунда не найдено.','jeopardy_final_control_missing'); }
        if ((string)($control['status'] ?? '')!=='open') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('alreadyClosed'=>true)); }
        $deadlineTs=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
        $nowTs=ckm_quiz_now_timestamp();
        if ($actorType !== 'system' && (string)($game['host_mode_snapshot']??'')==='ai' && $deadlineTs<=0) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Сначала дождитесь окончания озвучивания финального вопроса.','timer_waiting_for_voice');
        }
        if ($actorType !== 'system' && $deadlineTs > 0 && $nowTs < $deadlineTs) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Приём финальных ответов ещё идёт. Раскрытие станет доступно после окончания серверного таймера.','jeopardy_final_submission_open');
        }
        $now=ckm_quiz_now_mysql();
        if ($wpdb->update(ckm_quiz_mechanics_table(),array('status'=>'closed','updated_at'=>$now),array('id'=>(int)$control['id']))===false) throw new RuntimeException('final_control_close_failed');
        if ($wpdb->update(ckm_quiz_games_table(),array('quiz_phase'=>'question_closed','question_deadline_at'=>null,'updated_at'=>$now),array('id'=>$gameId))===false) throw new RuntimeException('final_game_close_failed');
        $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_submissions_closed',$actorType,$actorUserId,0,$questionId,'game_mechanic',(int)$control['id'],array(),'jeopardy-final-submissions-closed');
        if ($eventId<=0) throw new RuntimeException('final_close_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final close failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось закрыть приём финальных ответов.','jeopardy_final_close_failed');
    }
    return ckm_quiz_result(true,200,array('game'=>ckm_quiz_get_game($gameId)));
}

function ckm_quiz_jeopardy_reveal_final(int $gameId, array $auth): array {
    $actorType = sanitize_key((string)($auth['actor_type'] ?? ($auth['role'] ?? 'host')));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    $game=ckm_quiz_get_game($gameId);
    if (!$game || !ckm_quiz_jeopardy_final_is_current($game)) return ckm_quiz_error(409,'Финальный раунд не открыт.','jeopardy_final_not_current');
    if ((string)($game['quiz_phase'] ?? '')==='question_open') {
        $deadlineTs=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
        $nowTs=ckm_quiz_now_timestamp();
        if ($deadlineTs <= 0 || $nowTs < $deadlineTs) {
            return ckm_quiz_error(409,'Приём финальных ответов ещё идёт. Дождитесь окончания серверного таймера.','jeopardy_final_submission_open');
        }
        $closed=ckm_quiz_jeopardy_close_final_submission($gameId,'system',0);
        if (empty($closed['ok'])) return $closed;
    }
    global $wpdb; $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true); $questionId=(int)$game['current_question_id'];
        $control=ckm_quiz_jeopardy_final_control_row($gameId,$questionId,true);
        if (!$control) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Состояние финала не найдено.','jeopardy_final_control_missing'); }
        if (in_array((string)($control['status'] ?? ''),array('revealed','resolved'),true)) { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('duplicate'=>true)); }
        if ((string)($control['status'] ?? '') !== 'closed' || (string)($game['quiz_phase'] ?? '') !== 'question_closed') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Сначала дождитесь окончания времени приёма финальных ответов.','jeopardy_final_submission_open');
        }
        $now=ckm_quiz_now_mysql();
        if ($wpdb->update(ckm_quiz_mechanics_table(),array('status'=>'revealed','updated_at'=>$now),array('id'=>(int)$control['id']))===false) throw new RuntimeException('final_reveal_update_failed');
        $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_revealed',$actorType,$actorUserId,0,$questionId,'game_mechanic',(int)$control['id'],array('fixedPoints'=>ckm_quiz_jeopardy_final_points($game)),'jeopardy-final-revealed');
        if ($eventId<=0) throw new RuntimeException('final_reveal_event_failed');
        foreach (ckm_quiz_jeopardy_final_team_rows($gameId) as $finalTeam) {
            $finalTeamId=(int)($finalTeam['id'] ?? 0);
            if ($finalTeamId<=0 || ckm_quiz_get_answer($gameId,$finalTeamId,$questionId,false)) continue;
            $noAnswerEvent=ckm_quiz_append_event($gameId,'jeopardy_final_no_answer','system',0,$finalTeamId,$questionId,'team',$finalTeamId,array('fixedPoints'=>0),'jeopardy-final-no-answer-'.$finalTeamId);
            if ($noAnswerEvent<=0) throw new RuntimeException('final_no_answer_event_failed');
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final reveal failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось раскрыть финальные ответы.','jeopardy_final_reveal_failed');
    }
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true,200,array('final'=>ckm_quiz_jeopardy_final_state($fresh,'host')));
}

function ckm_quiz_jeopardy_resolve_final_team(int $gameId, int $teamId, string $decision, array $auth): array {
    $decision=sanitize_key($decision);
    if (!in_array($decision,array('accepted','rejected'),true)) return ckm_quiz_error(422,'Укажите результат ответа.','jeopardy_final_decision_invalid');
    $actorType = sanitize_key((string)($auth['actor_type'] ?? 'host'));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb; $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game || !ckm_quiz_jeopardy_final_is_current($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Финальный раунд не активен.','jeopardy_final_not_current'); }
        $questionId=(int)$game['current_question_id'];
        $control=ckm_quiz_jeopardy_final_control_row($gameId,$questionId,true);
        if (!$control || (string)($control['status'] ?? '')!=='revealed') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Сначала раскройте финальные ответы.','jeopardy_final_not_revealed'); }
        $team=ckm_quiz_get_team($gameId,$teamId,true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Команда не найдена.','team_not_found'); }
        $answer=ckm_quiz_get_answer($gameId,$teamId,$questionId,true);
        if (!$answer) {
            if ($decision==='accepted') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'У команды нет финального ответа.','jeopardy_final_answer_missing'); }
            $wpdb->query('COMMIT');
            $fresh=ckm_quiz_get_game($gameId) ?: $game;
            return ckm_quiz_result(true,200,array('noAnswer'=>true,'final'=>ckm_quiz_jeopardy_final_state($fresh,'host')));
        }
        if ((string)($answer['verdict'] ?? 'pending') !== 'pending') { $wpdb->query('COMMIT'); return ckm_quiz_result(true,200,array('duplicate'=>true)); }
        $question=ckm_quiz_get_question($questionId);
        if (!$question) throw new RuntimeException('final_question_missing');
        $points=$decision==='accepted' ? ckm_quiz_jeopardy_final_points($game) : 0;
        $reason=$decision==='accepted' ? 'Интеллектуальный батл: верный финальный ответ' : 'Интеллектуальный батл: неверный финальный ответ';
        $scored=ckm_quiz_apply_score_locked($game,$question,$answer,$points,$decision,$reason,$actorType,$actorUserId,'jeopardy-final-fixed-'.$teamId);
        if (empty($scored['ok'])) { $wpdb->query('ROLLBACK'); return $scored; }
        $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_team_resolved',$actorType,$actorUserId,$teamId,$questionId,'answer',(int)$answer['id'],array('decision'=>$decision,'fixedPoints'=>ckm_quiz_jeopardy_final_points($game),'delta'=>$points),'jeopardy-final-resolve-'.$teamId);
        if ($eventId<=0) throw new RuntimeException('final_resolve_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final resolve failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось обработать финальный ответ команды.','jeopardy_final_resolve_failed');
    }
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true,200,array('final'=>ckm_quiz_jeopardy_final_state($fresh,'host')));
}

function ckm_quiz_jeopardy_final_finish_guard(array $game): array {
    if (!function_exists('ckm_quiz_jeopardy_is_active') || !ckm_quiz_jeopardy_is_active($game)) return ckm_quiz_result(true,200,array('skipped'=>true));
    $question=ckm_quiz_jeopardy_final_question($game);
    if (!$question) return ckm_quiz_result(true,200,array('skipped'=>true));
    $control=ckm_quiz_jeopardy_final_control_row((int)$game['id'],(int)$question['id'],false);
    if (!$control) return ckm_quiz_error(409,'В квизе настроен финальный раунд. Сначала сыграйте его.','jeopardy_final_required');
    if ((string)($control['status'] ?? '')!=='resolved') return ckm_quiz_error(409,'Сначала завершите финальный раунд и вынесите решение по всем отправленным ответам.','jeopardy_final_unresolved');
    return ckm_quiz_result(true,200,array('ready'=>true));
}

function ckm_quiz_jeopardy_finish_final(int $gameId, array $auth): array {
    $actorType = sanitize_key((string)($auth['actor_type'] ?? ($auth['role'] ?? 'host')));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    $game=ckm_quiz_get_game($gameId);
    if (!$game || !ckm_quiz_jeopardy_final_is_current($game)) return ckm_quiz_error(409,'Финальный раунд не активен.','jeopardy_final_not_current');
    $state=ckm_quiz_jeopardy_final_state($game,'host');
    if (empty($state['allResolved'])) return ckm_quiz_error(409,'Сначала вынесите решение по всем отправленным финальным ответам.','jeopardy_final_unresolved');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $locked=ckm_quiz_get_game($gameId,true);
        $questionId=(int)($locked['current_question_id'] ?? 0);
        $control=ckm_quiz_jeopardy_final_control_row($gameId,$questionId,true);
        if (!$control) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409,'Состояние финального раунда не найдено.','jeopardy_final_control_missing'); }
        if ((string)($control['status'] ?? '')!=='resolved') {
            $now=ckm_quiz_now_mysql();
            if ($wpdb->update(ckm_quiz_mechanics_table(),array('status'=>'resolved','resolved_at'=>$now,'updated_at'=>$now),array('id'=>(int)$control['id']))===false) throw new RuntimeException('final_control_resolve_failed');
            $eventId=ckm_quiz_append_event($gameId,'jeopardy_final_completed',$actorType,$actorUserId,0,$questionId,'game_mechanic',(int)$control['id'],array('resolvedTeams'=>(int)($state['resolvedCount'] ?? 0),'fixedPoints'=>ckm_quiz_jeopardy_final_points($locked)),'jeopardy-final-completed');
            if ($eventId<=0) throw new RuntimeException('final_complete_event_failed');
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK'); error_log('CKM Jeopardy final completion failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось зафиксировать завершение финального раунда.','jeopardy_final_complete_failed');
    }
    return ckm_quiz_finish_game($gameId,$actorType,$actorUserId);
}


/**
 * alpha.86.4 — server-side AI Host autopilot for the Jeopardy final.
 *
 * It never chooses ordinary board cells for a team. Team-owned decisions stay
 * participant-owned. The autopilot only replaces actions that belonged to the
 * human host: starting the configured final after the board is complete,
 * revealing it after the server deadline, asking ИИ to arbitrate in AI
 * judge mode, and finishing the game once every team is resolved.
 */
function ckm_quiz_jeopardy_ai_autopilot(int $gameId): array {
    if ($gameId <= 0) return array('ok'=>true,'skipped'=>true,'reason'=>'game_missing');
    $game = ckm_quiz_get_game($gameId);
    if (!$game || !function_exists('ckm_quiz_jeopardy_is_active') || !ckm_quiz_jeopardy_is_active($game)) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'format_not_jeopardy');
    }
    if ((string)($game['host_mode_snapshot'] ?? '') !== 'ai') {
        return array('ok'=>true,'skipped'=>true,'reason'=>'host_mode_human');
    }
    if ((string)($game['status'] ?? '') === 'finished') {
        return array('ok'=>true,'skipped'=>true,'reason'=>'game_finished');
    }

    $question = ckm_quiz_jeopardy_final_question($game);
    if (!$question) return array('ok'=>true,'skipped'=>true,'reason'=>'final_not_configured');
    $auth = array('role'=>'ai_host','actor_type'=>'ai_host','user_id'=>0,'game_id'=>$gameId,'team_id'=>0);
    $control = ckm_quiz_jeopardy_final_control_row($gameId,(int)$question['id'],false);

    // Start final only after every normal cell has been played and no answer is
    // still waiting for a human/AI verdict.
    if (!$control) {
        if (!ckm_quiz_jeopardy_final_board_complete($game)) return array('ok'=>true,'skipped'=>true,'reason'=>'board_incomplete');
        if ((string)($game['quiz_phase'] ?? '') === 'question_open') return array('ok'=>true,'skipped'=>true,'reason'=>'question_open');
        global $wpdb;
        $currentQuestionId = (int)($game['current_question_id'] ?? 0);
        if ($currentQuestionId > 0) {
            $pending = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending'",
                $gameId,$currentQuestionId
            ));
            if ($pending > 0) return array('ok'=>true,'skipped'=>true,'reason'=>'pending_arbitration','pending'=>$pending);
        }
        $started = ckm_quiz_jeopardy_start_final($gameId,$auth);
        if (empty($started['ok'])) return $started;
        $fresh = ckm_quiz_get_game($gameId) ?: $game;
        return array('ok'=>true,'action'=>'final_started','game'=>ckm_quiz_get_game($gameId) ?: $fresh,'aiHost'=>$started['aiHost']??array());
    }

    $state = ckm_quiz_jeopardy_final_state($game,'host');
    $status = (string)($state['status'] ?? '');
    if (!empty($state['awaitingVoice'])) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'awaiting_final_voice');
    }

    // Server deadline is authoritative. reveal_final() closes submissions itself
    // if the timer has expired, so no browser button is needed.
    if (($status === 'open' && (int)($state['secondsRemaining'] ?? 0) <= 0) || $status === 'closed') {
        $revealed = ckm_quiz_jeopardy_reveal_final($gameId,$auth);
        if (empty($revealed['ok'])) return $revealed;
        if (function_exists('ckm_quiz_publish_ai_host_event')) {
            ckm_quiz_publish_ai_host_event($gameId,'final_revealed',$question,array('jeopardyFinal'=>true),'ai-jeopardy-final-revealed');
        }
        $ai = function_exists('ckm_quiz_jeopardy_ai_arbitrate_final_all')
            ? ckm_quiz_jeopardy_ai_arbitrate_final_all($gameId)
            : array('ok'=>true,'skipped'=>true,'reason'=>'ai_arbitration_unavailable');
        $fresh = ckm_quiz_get_game($gameId) ?: $game;
        $freshState = ckm_quiz_jeopardy_final_state($fresh,'host');
        if (!empty($freshState['allResolved'])) {
            $finish = ckm_quiz_jeopardy_finish_final($gameId,$auth);
            return array('ok'=>!empty($finish['ok']),'action'=>'final_finished','aiArbitration'=>$ai,'finish'=>$finish);
        }
        return array('ok'=>true,'action'=>'final_revealed','aiArbitration'=>$ai,'final'=>$freshState);
    }

    // Crash/reload recovery: if reveal already happened but AI judging did not
    // finish, apply only missing/pending evaluations idempotently.
    if (!empty($state['revealed']) && empty($state['allResolved'])
        && function_exists('ckm_quiz_jeopardy_ai_mode')
        && ckm_quiz_jeopardy_ai_mode($game) === 'ai'
        && function_exists('ckm_quiz_jeopardy_ai_arbitrate_answer')) {
        global $wpdb;
        $answers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending' ORDER BY id ASC",
            $gameId,(int)$question['id']
        ),ARRAY_A) ?: array();
        foreach ($answers as $answer) {
            ckm_quiz_jeopardy_ai_arbitrate_answer($gameId,(int)$answer['id'],array(),true,'');
        }
        $game = ckm_quiz_get_game($gameId) ?: $game;
        $state = ckm_quiz_jeopardy_final_state($game,'host');
    }

    // Human/hybrid fallback may finish adjudication later. As soon as all rows
    // are resolved, AI host completes the final/game without a separate button.
    if (!empty($state['revealed']) && !empty($state['allResolved'])) {
        $finish = ckm_quiz_jeopardy_finish_final($gameId,$auth);
        return array('ok'=>!empty($finish['ok']),'action'=>'final_finished','finish'=>$finish);
    }

    return array('ok'=>true,'skipped'=>true,'reason'=>'waiting','final'=>$state);
}

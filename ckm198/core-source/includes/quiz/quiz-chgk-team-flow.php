<?php
/**
 * CHGK single-team shared-device final-answer flow.
 *
 * «Битва знатоков» uses exactly one team and one shared team screen. There is
 * no captain role and no editable server-side draft in this runtime. The single
 * final answer is submitted directly and remains immutable in the answers ledger.
 */
if (!defined('ABSPATH')) exit;

function ckm_quiz_chgk_flow_is_active(array $game): bool {
    return function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'chgk';
}

function ckm_quiz_chgk_flow_settings(array $game): array {
    // dev.98: normalize every old/new CHGK room to the current product rule.
    // Stored legacy captain_only/individual_members snapshots are ignored.
    return array(
        'participationMode'=>'team_device',
        'finalizationMode'=>'team_device',
        'singleTeamSession'=>true,
        'captainSupported'=>false,
        'legacySnapshot'=>false,
    );
}

function ckm_quiz_chgk_get_draft(int $gameId, int $teamId, int $questionId, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $teamId <= 0 || $questionId <= 0 || ckm_quiz_drafts_table() === '') return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_drafts_table() . " WHERE game_id=%d AND team_id=%d AND question_id=%d LIMIT 1{$lock}",
        $gameId, $teamId, $questionId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_chgk_draft_public(?array $draft): ?array {
    if (!$draft) return null;
    return array(
        'id'=>(int)$draft['id'],
        'gameId'=>(int)$draft['game_id'],
        'teamId'=>(int)$draft['team_id'],
        'questionId'=>(int)$draft['question_id'],
        'answerText'=>(string)$draft['draft_answer'],
        'argumentation'=>(string)$draft['draft_argumentation'],
        'version'=>(int)$draft['version'],
        'updatedByUserId'=>(int)$draft['updated_by_user_id'],
        'updatedAt'=>(string)$draft['updated_at'],
    );
}

function ckm_quiz_chgk_member_can_edit(array $game, array $team, array $auth): bool {
    if (!ckm_quiz_chgk_flow_is_active($game)) return false;
    if ((string)($auth['role'] ?? '') !== 'participant') return false;
    return (int)($auth['team_id'] ?? 0) > 0
        && (int)($auth['team_id'] ?? 0) === (int)($team['id'] ?? 0);
}

function ckm_quiz_chgk_member_can_finalize(array $game, array $team, array $auth): bool {
    return ckm_quiz_chgk_member_can_edit($game, $team, $auth);
}

function ckm_quiz_chgk_validate_open_question(array $game): array {
    if (!ckm_quiz_chgk_flow_is_active($game)) return ckm_quiz_error(409, 'Окончательный ответ доступен только в формате Битва знатоков.', 'chgk_required');
    if (!empty($game['is_paused'])) {
        return ckm_quiz_error(409, 'Игра на паузе. Дождитесь продолжения ведущим.', 'game_paused');
    }
    if ((string)($game['status'] ?? '') !== 'live' || (string)($game['quiz_phase'] ?? '') !== 'question_open' || (int)($game['current_question_id'] ?? 0) <= 0) {
        return ckm_quiz_error(409, 'Сейчас нет открытого вопроса.', 'question_not_open');
    }
    if (function_exists('ckm_quiz_chgk_final_answer_is_open') && !ckm_quiz_chgk_final_answer_is_open($game)) {
        return ckm_quiz_error(409, 'Сначала завершите обсуждение. Окончательный ответ ещё не открыт.', 'final_answer_not_open');
    }
    $deadline = ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? ''));
    if ($deadline <= 0 || ckm_quiz_now_timestamp() >= $deadline) return ckm_quiz_error(409, 'Время ответа истекло.', 'deadline_passed');
    return ckm_quiz_result(true, 200);
}

function ckm_quiz_chgk_save_draft(array $auth, array $input): array {
    return ckm_quiz_error(409, 'В «Битве знатоков» черновики отключены. Ответ вводится только после окончания обсуждения и фиксируется один раз.', 'chgk_drafts_disabled');
}

function ckm_quiz_chgk_finalize_answer(array $auth, array $input): array {
    $gameId = (int)($auth['game_id'] ?? 0);
    $teamId = (int)($auth['team_id'] ?? 0);
    $userId = (int)($auth['user_id'] ?? 0);
    if ($gameId <= 0 || $teamId <= 0 || $userId <= 0) return ckm_quiz_error(401, 'Сессия участника недействительна.', 'participant_session_invalid');
    $answerText = sanitize_textarea_field((string)($input['answer_text'] ?? ''));
    $argumentation = sanitize_textarea_field((string)($input['argumentation'] ?? ''));
    if (trim($answerText) === '') return ckm_quiz_error(422, 'Введите окончательный ответ команды.', 'final_answer_empty');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found'); }
        $currentQuestionId = (int)($game['current_question_id'] ?? 0);
        // dev.130: the first successful final answer may immediately auto-close
        // the question in single-team CHGK. A repeated submit after that close
        // must still be reported as a locked final answer, not as a generic
        // «question_not_open» state. This keeps the user-facing rule stable:
        // one final answer can be fixed only once.
        if ($currentQuestionId > 0 && ckm_quiz_get_answer($gameId, $teamId, $currentQuestionId, true)) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Окончательный ответ команды уже зафиксирован и не может быть изменён.', 'final_answer_locked');
        }
        $open = ckm_quiz_chgk_validate_open_question($game);
        if (empty($open['ok'])) { $wpdb->query('ROLLBACK'); return $open; }
        $member = ckm_quiz_get_membership($gameId, $userId, true);
        if (!$member || (int)$member['team_id'] !== $teamId) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(403, 'Вы не состоите в этой команде.', 'foreign_game_or_team'); }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team || !ckm_quiz_chgk_member_can_finalize($game, $team, $auth)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(403, 'Эта сессия не может зафиксировать окончательный ответ команды.', 'finalize_not_allowed'); }
        $questionId = (int)$game['current_question_id'];
        $question = ckm_quiz_get_question($questionId);
        if (!$question) throw new RuntimeException('question_missing');
        if (ckm_quiz_get_answer($gameId, $teamId, $questionId, true)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Окончательный ответ команды уже зафиксирован и не может быть изменён.', 'final_answer_locked'); }
        $earlyAnswer = function_exists('ckm_quiz_chgk_early_answer_is_claimed') ? ckm_quiz_chgk_early_answer_is_claimed($gameId, $questionId) : false;
        $answerPayload = array('argumentation'=>$argumentation, 'finalized'=>true, 'earlyAnswer'=>$earlyAnswer);
        if (function_exists('ckm_quiz_runtime_prepare_answer')) {
            $runtime = ckm_quiz_runtime_prepare_answer($game, $question, $answerText, $answerPayload);
            if (empty($runtime['ok'])) { $wpdb->query('ROLLBACK'); return $runtime; }
            $answerText = (string)($runtime['answerText'] ?? $answerText);
            $answerPayload = (array)($runtime['answerPayload'] ?? $answerPayload);
        }
        $now = ckm_quiz_now_mysql();
        $startedTs = ckm_quiz_mysql_timestamp((string)$game['question_started_at']);
        $responseMs = $startedTs > 0 ? max(0, (int)round((microtime(true) - $startedTs) * 1000)) : 0;
        $normalized = ckm_quiz_normalize_scalar($answerText);
        $ok = $wpdb->insert(ckm_quiz_answers_table(), array(
            'game_id'=>$gameId,'team_id'=>$teamId,'question_id'=>$questionId,'quiz_revision'=>(int)$game['quiz_revision'],
            'attempt_no'=>1,'answer_text'=>$answerText,'answer_payload_json'=>ckm_quiz_json_encode($answerPayload),
            'normalized_answer'=>$normalized,'submitted_by_user_id'=>$userId,'response_time_ms'=>$responseMs,
            'auto_points'=>0,'awarded_points'=>0,'verdict'=>'pending','judge_mode'=>(string)$game['judge_mode_snapshot'],
            'judged_by_user_id'=>0,'submitted_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
        ));
        if ($ok === false) {
            if (ckm_quiz_get_answer($gameId, $teamId, $questionId, true)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Окончательный ответ команды уже зафиксирован.', 'final_answer_locked'); }
            throw new RuntimeException($wpdb->last_error ?: 'answer_insert_failed');
        }
        $answerId = (int)$wpdb->insert_id;
        $eventId = ckm_quiz_append_event($gameId, 'chgk_final_answer_submitted', 'participant', $userId, $teamId, $questionId, 'answer', $answerId, array(
            'answerId'=>$answerId,'attempt'=>1,'responseTimeMs'=>$responseMs,'finalizedByUserId'=>$userId,
            'participationMode'=>'team_device','finalizationMode'=>'team_device',
        ), 'answer-submitted-' . $answerId);
        if ($eventId <= 0) throw new RuntimeException('answer_submit_event_failed');
        $updatedAnswer = ckm_quiz_get_answer($gameId, $teamId, $questionId);
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK finalize failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось зафиксировать окончательный ответ.', 'answer_finalize_failed');
    }
    $autoClose = function_exists('ckm_quiz_chgk_maybe_auto_close_question') ? ckm_quiz_chgk_maybe_auto_close_question($gameId) : array('closed'=>false);
    return ckm_quiz_result(true, 200, array('action'=>'finalized','answer'=>$updatedAnswer ? ckm_quiz_answer_public($updatedAnswer, true) : null,'autoClose'=>$autoClose));
}

function ckm_quiz_chgk_assign_captain(array $auth, array $input): array {
    if (!in_array((string)($auth['role'] ?? ''), array('host','admin'), true)) {
        return ckm_quiz_error(403, 'Изменять настройки командной сессии может только ведущий или администратор.', 'host_required');
    }
    return ckm_quiz_error(409, 'В «Битве знатоков» используется одна команда и общий командный экран. Отдельная роль капитана не используется.', 'captain_not_supported');
}

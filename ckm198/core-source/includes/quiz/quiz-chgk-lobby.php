<?php
/**
 * CHGK lobby / team readiness / preflight layer.
 *
 * Reuses shared game_teams.ready_at and team membership.
 * No second team store is created. The first question is opened only after a
 * server-side preflight succeeds for rooms that enable the lobby requirement.
 */
if (!defined('ABSPATH')) exit;

function ckm_quiz_chgk_lobby_settings(array $game): array {
    $settings = function_exists('ckm_quiz_runtime_format_settings')
        ? ckm_quiz_runtime_format_settings($game)
        : ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    $hasReadyRule = array_key_exists('requireAllTeamsReady', $settings);
    return array(
        'requireAllTeamsReady'=>$hasReadyRule ? !empty($settings['requireAllTeamsReady']) : false,
        'legacySnapshot'=>!$hasReadyRule,
        'participationMode'=>'team_device',
        'finalizationMode'=>'team_device',
        'captainRequired'=>false,
    );
}

function ckm_quiz_chgk_can_toggle_ready(array $game, array $team, array $auth): bool {
    if (!function_exists('ckm_quiz_chgk_flow_is_active') || !ckm_quiz_chgk_flow_is_active($game)) return false;
    if ((string)($auth['role'] ?? '') !== 'participant') return false;
    if ((int)($auth['team_id'] ?? 0) !== (int)($team['id'] ?? 0)) return false;
    if (function_exists('ckm_quiz_chgk_member_can_finalize')) return ckm_quiz_chgk_member_can_finalize($game, $team, $auth);
    return false;
}

function ckm_quiz_chgk_set_team_ready(array $auth, array $input): array {
    $gameId = (int)($auth['game_id'] ?? 0);
    $teamId = (int)($auth['team_id'] ?? 0);
    $userId = (int)($auth['user_id'] ?? 0);
    if ($gameId <= 0 || $teamId <= 0 || $userId <= 0) return ckm_quiz_error(401, 'Сессия участника недействительна.', 'participant_session_invalid');
    $ready = !empty($input['ready']);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !function_exists('ckm_quiz_chgk_flow_is_active') || !ckm_quiz_chgk_flow_is_active($game)) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Готовность команды используется только в «Битве знатоков».', 'chgk_required');
        }
        if ((string)($game['status'] ?? '') !== 'waiting' || (string)($game['quiz_phase'] ?? '') !== 'waiting' || (int)($game['current_question_position'] ?? 0) > 0) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Готовность команды можно менять только до начала игры.', 'lobby_closed');
        }
        $member = ckm_quiz_get_membership($gameId, $userId, true);
        if (!$member || (int)$member['team_id'] !== $teamId || (string)($member['member_status'] ?? '') !== 'active') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(403, 'Вы не состоите в этой команде.', 'foreign_game_or_team');
        }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team || (string)($team['team_status'] ?? '') !== 'active') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Команда недоступна.', 'team_inactive');
        }
        if (!ckm_quiz_chgk_can_toggle_ready($game, $team, $auth)) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(403, 'Подтвердить готовность можно только с командного экрана.', 'captain_required');
        }
        $wasReady = !empty($team['ready_at']);
        if ($wasReady === $ready) {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true, 200, array('action'=>'unchanged','ready'=>$ready,'team'=>$team));
        }
        $now = ckm_quiz_now_mysql();
        $updated = $wpdb->update(ckm_quiz_teams_table(), array(
            'ready_at'=>$ready ? $now : null,
            'updated_at'=>$now,
        ), array('id'=>$teamId,'game_id'=>$gameId));
        if ($updated === false) throw new RuntimeException($wpdb->last_error ?: 'team_ready_update_failed');
        $eventId = ckm_quiz_append_event(
            $gameId,
            $ready ? 'team_ready' : 'team_not_ready',
            'participant',
            $userId,
            $teamId,
            0,
            'team',
            $teamId,
            array('ready'=>$ready)
        );
        if ($eventId <= 0) throw new RuntimeException('team_ready_event_failed');
        $fresh = ckm_quiz_get_team($gameId, $teamId);
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM CHGK ready toggle failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось изменить готовность команды.', 'team_ready_failed');
    }
    $autoStart = array();
    if ($ready && function_exists('ckm_quiz_chgk_ai_host_autostart')) {
        $autoStart = ckm_quiz_chgk_ai_host_autostart($gameId);
    }
    return ckm_quiz_result(true, 200, array('action'=>$ready ? 'ready' : 'not_ready','ready'=>$ready,'team'=>$fresh ?: $team,'autoStart'=>$autoStart));
}


/**
 * Server-side AI host autostart for CHGK lobby.
 *
 * Human-host games remain manual. In AI-host CHGK games the first question is
 * opened as soon as the existing CHGK preflight reports that the room can
 * start. This function is idempotent and safe to call from participant polling
 * as well as immediately after a team changes readiness.
 */
function ckm_quiz_chgk_ai_host_autostart(int $gameId): array {
    if ($gameId <= 0) return array('ok'=>true,'skipped'=>true,'reason'=>'game_missing');
    $game = ckm_quiz_get_game($gameId);
    if (!$game) return array('ok'=>true,'skipped'=>true,'reason'=>'game_missing');
    if ((string)($game['host_mode_snapshot'] ?? '') !== 'ai') return array('ok'=>true,'skipped'=>true,'reason'=>'host_mode_human');
    if (!function_exists('ckm_quiz_chgk_flow_is_active') || !ckm_quiz_chgk_flow_is_active($game)) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'format_not_chgk');
    }
    if ((string)($game['status'] ?? '') !== 'waiting' || (string)($game['quiz_phase'] ?? '') !== 'waiting' || (int)($game['current_question_position'] ?? 0) > 0) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'lobby_already_closed');
    }
    if (empty($game['auto_start'])) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'waiting_for_host_start');
    }
    $preflight = function_exists('ckm_quiz_chgk_preflight') ? ckm_quiz_chgk_preflight($game) : array('canStart'=>false);
    if (empty($preflight['canStart'])) {
        $first = (array)($preflight['blockers'][0] ?? array());
        return array(
            'ok'=>true,
            'skipped'=>true,
            'reason'=>(string)($first['code'] ?? 'preflight_blocked'),
            'preflight'=>$preflight,
        );
    }
    $freshBeforeOpen = ckm_quiz_get_game($gameId);
    if (!$freshBeforeOpen || (string)($freshBeforeOpen['host_mode_snapshot'] ?? '') !== 'ai') {
        return array('ok'=>true,'skipped'=>true,'reason'=>'manual_takeover_before_autostart','preflight'=>$preflight);
    }
    $opened = ckm_quiz_open_next_question($gameId, 'ai_host', 0);
    if (!empty($opened['ok'])) {
        return array('ok'=>true,'action'=>'first_question_started','opened'=>$opened,'preflight'=>$preflight);
    }
    // Concurrent polling can race after all teams become ready. If another
    // request has already started the question, converge successfully.
    $code = (string)($opened['code'] ?? '');
    if (in_array($code, array('question_already_open','game_finished'), true)) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'already_started','preflight'=>$preflight);
    }
    $fresh = ckm_quiz_get_game($gameId);
    if ($fresh && ((string)($fresh['status'] ?? '') === 'live' || (string)($fresh['quiz_phase'] ?? '') === 'question_open')) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'already_started','preflight'=>$preflight);
    }
    return $opened;
}

function ckm_quiz_chgk_preflight_access(array $game): array {
    return ckm_quiz_core_preflight_access($game);
}

function ckm_quiz_chgk_preflight(array $game): array {
    if (!function_exists('ckm_quiz_chgk_flow_is_active') || !ckm_quiz_chgk_flow_is_active($game)) {
        return array('applicable'=>false,'canStart'=>true,'blockers'=>array(),'warnings'=>array(),'checks'=>array());
    }
    global $wpdb;
    $gameId = (int)($game['id'] ?? 0);
    $settings = ckm_quiz_chgk_lobby_settings($game);
    $blockers = array();
    $warnings = array();
    $checks = array();

    $waiting = (string)($game['status'] ?? '') === 'waiting' && (string)($game['quiz_phase'] ?? '') === 'waiting' && (int)($game['current_question_position'] ?? 0) === 0;
    $checks['roomWaiting'] = array('ok'=>$waiting,'label'=>'Комната ожидает старта');
    if (!$waiting) $blockers[] = array('code'=>'room_not_waiting','message'=>'Игра уже запущена или завершена.');

    $quiz = ckm_quiz_get_quiz((int)($game['quiz_id'] ?? 0), false);
    $minTeams = 1;
    $maxTeams = 1;
    $teams = $wpdb->get_results($wpdb->prepare(
        "SELECT id,team_key,team_name,captain_user_id,team_status,ready_at FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active' ORDER BY slot_no ASC,id ASC",
        $gameId
    ), ARRAY_A) ?: array();
    $teamCount = count($teams);
    $teamsOk = $teamCount >= $minTeams && $teamCount <= $maxTeams;
    $checks['teams'] = array('ok'=>$teamsOk,'label'=>'Команды','detail'=>$teamCount . ' (нужно ' . $minTeams . '–' . $maxTeams . ')');
    if (!$teamsOk) $blockers[] = array('code'=>'team_count_invalid','message'=>'Для «Битвы знатоков» должна быть ровно одна активная команда.');

    $readyCount = 0;
    $teamSummary = array();
    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        $ready = !empty($team['ready_at']);
        if ($ready) $readyCount++;
        $memberCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . ckm_quiz_members_table() . " WHERE game_id=%d AND team_id=%d AND member_status='active'",
            $gameId,$teamId
        ));
        $teamSummary[] = array(
            'id'=>$teamId,'key'=>(string)$team['team_key'],'name'=>(string)$team['team_name'],
            'ready'=>$ready,'memberCount'=>$memberCount,'teamSessionValid'=>true,
        );
    }
    $allReady = $teamCount > 0 && $readyCount === $teamCount;
    $readyOk = empty($settings['requireAllTeamsReady']) || $allReady;
    $checks['ready'] = array('ok'=>$readyOk,'label'=>'Готовность команд','detail'=>$readyCount . '/' . $teamCount);
    if (!$readyOk) $blockers[] = array('code'=>'teams_not_ready','message'=>'Не все команды подтвердили готовность: ' . $readyCount . ' из ' . $teamCount . '.');

    $checks['teamSession'] = array('ok'=>true,'label'=>'Командный экран','detail'=>'готов; отдельные роли внутри команды не используются');

    $questionRows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,position,question_type,question_text,correct_answers_json,round_id FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' ORDER BY position ASC,id ASC",
        (int)$game['quiz_id'],(int)$game['quiz_revision']
    ), ARRAY_A) ?: array();
    $invalidQuestions = array();
    foreach ($questionRows as $question) {
        $answers = ckm_quiz_json_decode($question['correct_answers_json'] ?? '');
        if ((string)($question['question_type'] ?? '') !== 'text'
            || trim((string)($question['question_text'] ?? '')) === ''
            || !is_array($answers) || count(array_filter(array_map('strval',$answers), static function($x){ return trim($x) !== ''; })) === 0
            || (int)($question['round_id'] ?? 0) <= 0) {
            $invalidQuestions[] = (int)($question['position'] ?? 0);
        }
    }
    $questionsOk = count($questionRows) > 0 && !$invalidQuestions;
    $checks['questions'] = array('ok'=>$questionsOk,'label'=>'Вопросы Битва знатоков','detail'=>count($questionRows) . ' вопросов' . ($invalidQuestions ? '; проблемы: ' . implode(',',$invalidQuestions) : ''));
    if (!$questionsOk) $blockers[] = array('code'=>'questions_invalid','message'=>!$questionRows ? 'В игре нет активных вопросов.' : 'Проверьте вопросы № ' . implode(', ', $invalidQuestions) . ': Битва знатоков требует текст вопроса, эталон, тип «Текстовый» и раунд.');

    // Do not use ckm_quiz_runtime_question_seconds() here. In the current
    // «Битва знатоков» flow it intentionally returns 5 seconds for the
    // pre-question early-answer offer deadline. The lobby preflight checks the
    // real discussion time, which is normalized separately to 60 seconds.
    $formatSettings = function_exists('ckm_quiz_runtime_format_settings')
        ? ckm_quiz_runtime_format_settings($game)
        : ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    $discussion = (int)($formatSettings['discussionSeconds'] ?? 60);
    if ($discussion < 60 || $discussion > 300) $discussion = 60;
    $timerOk = $discussion >= 60 && $discussion <= 300;
    $checks['timer'] = array('ok'=>$timerOk,'label'=>'Время обсуждения','detail'=>$discussion . ' сек.');
    if (!$timerOk) $blockers[] = array('code'=>'discussion_seconds_invalid','message'=>'Время обсуждения «Битвы знатоков» должно быть от 60 до 300 секунд.');

    $access = ckm_quiz_chgk_preflight_access($game);
    $checks['access'] = array('ok'=>!empty($access['ok']),'label'=>'Оплаченный доступ','detail'=>(string)($access['detail'] ?? ''));
    if (empty($access['ok'])) $blockers[] = array('code'=>(string)($access['code'] ?? 'access_invalid'),'message'=>(string)($access['detail'] ?? 'Оплаченный доступ недоступен.'));

    $judgeMode = ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid');
    if (in_array($judgeMode, array('ai','hybrid'), true) && (!function_exists('ckm_quiz_ai_arbitration_is_configured') || !ckm_quiz_ai_arbitration_is_configured())) {
        $warnings[] = array('code'=>'ai_arbiter_unavailable','message'=>'ИИ-арбитр сейчас недоступен; при необходимости используйте ручную оценку.');
    }
    if ((string)($game['host_mode_snapshot'] ?? '') === 'ai' && (!function_exists('ckm_quiz_ai_host_is_configured') || !ckm_quiz_ai_host_is_configured())) {
        $warnings[] = array('code'=>'ai_host_unavailable','message'=>'ИИ-ведущий сейчас недоступен; будет использован безопасный fallback-текст.');
    }

    return array(
        'applicable'=>true,
        'canStart'=>empty($blockers),
        'requireAllTeamsReady'=>!empty($settings['requireAllTeamsReady']),
        'readyCount'=>$readyCount,
        'teamCount'=>$teamCount,
        'allReady'=>$allReady,
        'captainRequired'=>false,
        'checks'=>$checks,
        'blockers'=>$blockers,
        'warnings'=>$warnings,
        'teams'=>$teamSummary,
    );
}

function ckm_quiz_chgk_preflight_error(array $preflight): array {
    $first = (array)($preflight['blockers'][0] ?? array());
    $message = (string)($first['message'] ?? 'Игра «Битва знатоков» пока не готова к старту.');
    return ckm_quiz_result(false, 409, array(
        'error'=>$message,
        'code'=>'chgk_preflight_failed',
        'preflight'=>$preflight,
    ));
}

function ckm_quiz_chgk_lobby_state(array $game, array $auth, array $teams = array()): array {
    $settings = ckm_quiz_chgk_lobby_settings($game);
    if (!$teams) {
        global $wpdb;
        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id,team_key,team_name,captain_user_id,team_status,ready_at FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active' ORDER BY slot_no ASC,id ASC",
            (int)$game['id']
        ), ARRAY_A) ?: array();
    }
    $readyCount = 0;
    foreach ($teams as $team) if (!empty($team['ready_at'])) $readyCount++;
    $role = (string)($auth['role'] ?? 'participant');
    $out = array(
        'active'=>(string)($game['status'] ?? '') === 'waiting' && (string)($game['quiz_phase'] ?? '') === 'waiting',
        'requireAllTeamsReady'=>!empty($settings['requireAllTeamsReady']),
        'readyCount'=>$readyCount,
        'teamCount'=>count($teams),
        'allReady'=>count($teams) > 0 && $readyCount === count($teams),
        'captainRequired'=>false,
    );
    if ($role === 'participant') {
        $teamId = (int)($auth['team_id'] ?? 0);
        $own = null;
        foreach ($teams as $team) if ((int)($team['id'] ?? 0) === $teamId) { $own = $team; break; }
        $out['yourReady'] = $own ? !empty($own['ready_at']) : false;
        $out['canToggleReady'] = $own ? ckm_quiz_chgk_can_toggle_ready($game, $own, $auth) : false;
    }
    if (in_array($role, array('host','admin'), true) && $out['active']) {
        $out['preflight'] = ckm_quiz_chgk_preflight($game);
    }
    return $out;
}

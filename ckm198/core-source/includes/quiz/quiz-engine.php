<?php
/** Server-authoritative game engine for CKM Quizzes. */

if (!defined('ABSPATH')) {
    exit;
}

function ckm_quiz_result(bool $ok, int $status, array $data = array()): array {
    return array_merge(array('ok'=>$ok, 'status'=>$status), $data);
}

function ckm_quiz_error(int $status, string $message, string $code = ''): array {
    $data = array('error'=>$message);
    if ($code !== '') $data['code'] = $code;
    return ckm_quiz_result(false, $status, $data);
}

function ckm_quiz_mode(string $value, string $fallback = 'human'): string {
    $value = sanitize_key($value);
    return in_array($value, array('ai','human'), true) ? $value : $fallback;
}

function ckm_quiz_judge_mode(string $value, string $fallback = 'hybrid'): string {
    $value = sanitize_key($value);
    return in_array($value, array('ai','human','hybrid'), true) ? $value : $fallback;
}

function ckm_quiz_settings(array $game): array {
    return ckm_quiz_json_decode($game['settings_snapshot_json'] ?? '');
}

function ckm_quiz_create_room(array $input, int $creatorUserId): array {
    global $wpdb;
    $scopeLock='ckmqp_pay_'.md5(ckmqp_scope_id().'|'.$creatorUserId);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$scopeLock))!==1) return ckm_quiz_error(409,'Площадка обновляется. Повторите запуск.','tenant_busy');
    try {
    // Authentication is performed centrally by ckm_quiz_require_admin_request().
    // A purchase is always bound to an authenticated WordPress organizer.
    if ($creatorUserId<=0 || (!user_can($creatorUserId,'manage_options') && !user_can($creatorUserId,'ckm_quiz_organize'))) return ckm_quiz_error(403,'Нет доступа к запуску игры.','organizer_required');
    if (!empty($input['test_mode']) && !user_can($creatorUserId,'manage_options')) return ckm_quiz_error(403,'Нет доступа к тестовым комнатам.','admin_required');
    if (!ckmqp_scope_user_allowed($creatorUserId)) return ckm_quiz_error(403,'Нет доступа к площадке.','tenant_required');
    $quizId = absint($input['quiz_id'] ?? 0);
    $testMode = !empty($input['test_mode']);
    $quiz = ckm_quiz_get_quiz($quizId, !$testMode);
    if (!$quiz) return ckm_quiz_error(404, 'Опубликованный квиз не найден.', 'quiz_not_found');
    if (!ckmqp_content_can_use($quiz,$creatorUserId)) return ckm_quiz_error(403,'Игра принадлежит другой площадке или организатору.','content_denied');
    $requiredProduct = function_exists('ckm_quiz_pro_quiz_access_product')
        ? ckm_quiz_pro_quiz_access_product($quiz)
        : (string)$quiz['format_key'];
    if (!$testMode && !ckm_quiz_pro_can_access_format($creatorUserId,$requiredProduct)) return ckm_quiz_error(403,'Доступ к этой игре не оплачен.','payment_required');
    $revision = max(1, (int)$quiz['current_revision']);
    $formatKey = function_exists('ckm_quiz_format_normalize_key')
        ? ckm_quiz_format_normalize_key((string)($quiz['format_key'] ?? CKM_EA_DEFAULT_GAME_FORMAT))
        : 'classic_quiz';
    $format = function_exists('ckm_quiz_format_get') ? ckm_quiz_format_get($formatKey) : null;
    if (!$format) return ckm_quiz_error(409, 'Формат квиза не зарегистрирован.', 'format_not_registered');
    if (!ckm_quiz_format_is_runnable($formatKey)) {
        return ckm_quiz_error(409, 'Этот формат уже зарегистрирован, но его игровая механика ещё не подключена.', 'format_not_implemented');
    }
    $formatVersion = max(1, (int)($format['format_version'] ?? 1));
    $formatContract = (array)($format['contract'] ?? array());
    $formatContractCheck = function_exists('ckm_quiz_format_validate_contract') ? ckm_quiz_format_validate_contract($formatContract) : array('ok'=>true);
    if (empty($formatContractCheck['ok'])) return ckm_quiz_error(409, 'Контракт формата повреждён или неполон.', 'format_contract_invalid');
    $formatOverrides = ckm_quiz_json_decode($quiz['format_settings_json'] ?? '');
    $formatSettings = function_exists('ckm_quiz_format_resolve_settings')
        ? ckm_quiz_format_resolve_settings($formatKey, $formatOverrides)
        : array_replace_recursive((array)($format['default_settings'] ?? array()), $formatOverrides);
    if (function_exists('ckmqp_hub_merge_format_settings')) {
        $formatSettings = ckmqp_hub_merge_format_settings($formatKey, $formatSettings, $input);
    }
    if (($formatOverrides['negotiationMode'] ?? '') === 'communicate') $formatSettings['negotiationMode']='communicate';
    if (function_exists('ckm_quiz_format_sync_rounds') && !ckm_quiz_format_sync_rounds($quizId, $revision)) {
        return ckm_quiz_error(500, 'Не удалось синхронизировать раунды квиза.', 'round_sync_failed');
    }
    if (ckm_quiz_question_count($quizId, $revision) <= 0) {
        return ckm_quiz_error(409, 'В выбранной редакции квиза нет активных вопросов.', 'questions_missing');
    }
    global $wpdb;
    $unlinkedRounds = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND round_id=0",
        $quizId,
        $revision
    ));
    if ($unlinkedRounds > 0) {
        return ckm_quiz_error(409, 'Не все вопросы привязаны к раундам.', 'round_links_missing');
    }
    if (function_exists('ckm_quiz_format_round_type_allowed')) {
        $roundTypes = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT round_type FROM " . ckm_quiz_rounds_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",
            $quizId,
            $revision
        )) ?: array();
        foreach ($roundTypes as $roundType) {
            if (!ckm_quiz_format_round_type_allowed($formatKey, (string)$roundType)) {
                return ckm_quiz_error(409, 'Тип раунда не разрешён контрактом выбранного формата.', 'round_type_not_allowed');
            }
        }
    }
    if (function_exists('ckm_quiz_runtime_validate_quiz')) {
        $runtimeValidation = ckm_quiz_runtime_validate_quiz($formatKey, $quizId, $revision, $formatSettings);
        if (empty($runtimeValidation['ok'])) return $runtimeValidation;
    }

    $rawTeams = isset($input['teams']) && is_array($input['teams']) ? array_values($input['teams']) : array();
    $requestedCount = (int)($input['team_count'] ?? count($rawTeams));
    if ($formatKey === 'chgk' && ($requestedCount > 1 || count($rawTeams) > 1)) {
        return ckm_quiz_error(409, '«Битва знатоков» проводится только одной командой против Игры.', 'chgk_single_team_only');
    }
    $teamPolicy = ckm_quiz_core_team_policy($quiz);
    $policyMin = $formatKey === 'chgk' ? 1 : max(1, (int)($teamPolicy['min'] ?? 2));
    $policyMax = $formatKey === 'chgk' ? 1 : max($policyMin, (int)($teamPolicy['max'] ?? 10));
    $quizMinTeams = $formatKey === 'chgk' ? 1 : ckm_quiz_core_normalize_team_count((int)($quiz['min_teams'] ?? $policyMin), $policyMin, $policyMax);
    $quizMaxTeams = $formatKey === 'chgk' ? 1 : ckm_quiz_core_normalize_team_count((int)($quiz['max_teams'] ?? $policyMax), $quizMinTeams, $policyMax);
    if ($formatKey === 'chgk') $requestedCount = 1;
    $teamCount = ckm_quiz_core_normalize_team_count($requestedCount > 0 ? $requestedCount : $quizMinTeams, $quizMinTeams, $quizMaxTeams);
    // Respect the quiz/team contract for communicate mode. New «Переговори другого»
    // templates are exactly two teams; legacy three-team rooms remain unchanged because
    // an already-created room keeps its immutable team_count snapshot.
    while (count($rawTeams) < $teamCount) $rawTeams[] = array();
    $rawTeams = array_slice($rawTeams, 0, $teamCount);

    $teams = array();
    foreach ($rawTeams as $index => $rawTeam) {
        if (!is_array($rawTeam)) $rawTeam = array();
        $slot = $index + 1;
        $defaultName = ckm_quiz_core_team_default_name($slot);
        $name = sanitize_text_field((string)($rawTeam['name'] ?? $defaultName));
        $teams[] = array('slot'=>$slot, 'name'=>$name !== '' ? $name : $defaultName);
    }

    $saleId = absint($input['sale_id'] ?? 0);
    $accessId = absint($input['access_id'] ?? 0);
    $accessCheck = ckm_quiz_core_validate_room_access($saleId, $accessId, $testMode, $quiz);
    if (empty($accessCheck['ok'])) {
        return ckm_quiz_error(
            (int)($accessCheck['status'] ?? 403),
            (string)($accessCheck['error'] ?? 'Не удалось проверить доступ к квизу.'),
            (string)($accessCheck['code'] ?? 'access_denied')
        );
    }
    $saleId = absint($accessCheck['sale_id'] ?? $saleId);
    $accessId = absint($accessCheck['access_id'] ?? $accessId);

    $hostMode = ckm_quiz_mode((string)($input['host_mode'] ?? $quiz['host_mode'] ?? 'human'));
    $judgeMode = ckm_quiz_judge_mode((string)($input['judge_mode'] ?? $formatSettings['judgeMode'] ?? $quiz['judge_mode'] ?? 'hybrid'));
    $title = sanitize_text_field((string)($input['title'] ?? $quiz['title']));
    if ($title === '') $title = (string)$quiz['title'];
    $now = ckm_quiz_now_mysql();
    $gameCode = ckm_quiz_unique_game_code();
    $hostToken = ckm_quiz_random_token();
    $hostNonce = ckm_quiz_random_token();
    $scoreboardToken = ckm_quiz_random_token();
    $scoreboardNonce = ckm_quiz_random_token();
    $settings = array(
        'testMode'=>$testMode,
        'secondsPerQuestion'=>max(5, min(3600, (int)($input['seconds_per_question'] ?? $quiz['seconds_per_question'] ?? 60))),
        'speedBonusEnabled'=>function_exists('ckm_quiz_runtime_speed_bonus_enabled') ? ckm_quiz_runtime_speed_bonus_enabled($formatKey) : true,
        'speedBonusPoints'=>1,
        'createdByApi'=>'sprint-2',
    );
    if (function_exists('ckmqp_hub_merge_room_settings')) {
        $settings = ckmqp_hub_merge_room_settings($formatKey, $settings, $input);
    }

    $wpdb->query('START TRANSACTION');
    try {
        $inserted = $wpdb->insert(ckm_quiz_games_table(), array(
            'tenant_id'=>ckmqp_scope_id(),
            'game_code'=>$gameCode,
            'game_type'=>'quiz',
            'sale_id'=>$saleId,
            'access_id'=>$accessId,
            'training_id'=>0,
            'quiz_id'=>$quizId,
            'quiz_revision'=>$revision,
            'format_key_snapshot'=>$formatKey,
            'format_version_snapshot'=>$formatVersion,
            'format_settings_snapshot_json'=>ckm_quiz_json_encode($formatSettings),
            'format_contract_snapshot_json'=>ckm_quiz_json_encode($formatContract),
            'created_by_user_id'=>$creatorUserId,
            'host_mode_snapshot'=>$hostMode,
            'judge_mode_snapshot'=>$judgeMode,
            'settings_snapshot_json'=>ckm_quiz_json_encode($settings),
            'quiz_phase'=>'waiting',
            'round_phase'=>'waiting',
            'current_round_id'=>0,
            'current_round_position'=>0,
            'round_started_at'=>null,
            'round_closed_at'=>null,
            'title'=>$title,
            'team_count'=>$teamCount,
            'status'=>'waiting',
            'auto_start'=>0,
            'countdown_seconds'=>0,
            'scoreboard_token'=>$scoreboardToken,
            'scoreboard_nonce'=>$scoreboardNonce,
            'judge_token'=>'',
            'host_token'=>$hostToken,
            'host_nonce'=>$hostNonce,
            'current_question_id'=>0,
            'current_question_position'=>0,
            'state_version'=>0,
            'test_mode'=>$testMode ? 1 : 0,
            'created_at'=>$now,
            'updated_at'=>$now,
        ));
        if ($inserted === false) throw new RuntimeException($wpdb->last_error ?: 'game_insert_failed');
        $gameId = (int)$wpdb->insert_id;
        $teamCredentials = array();
        foreach ($teams as $teamSpec) {
            $key = ckm_quiz_core_team_key_from_slot((int)$teamSpec['slot']);
            $joinToken = ckm_quiz_random_token(20);
            $joinNonce = ckm_quiz_random_token(20);
            $ok = $wpdb->insert(ckm_quiz_teams_table(), array(
                'game_id'=>$gameId,
                'team_key'=>$key,
                'slot_no'=>(int)$teamSpec['slot'],
                'team_name'=>(string)$teamSpec['name'],
                'captain_user_id'=>0,
                'team_status'=>'active',
                'join_token'=>$joinToken,
                'join_nonce'=>$joinNonce,
                'score'=>0,
                'turns_count'=>0,
                'facts_count'=>0,
                'final_score'=>0,
                'ready_at'=>null,
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'team_insert_failed');
            $teamId = (int)$wpdb->insert_id;
            $teamCredentials[] = array(
                'teamId'=>$teamId,
                'teamKey'=>$key,
                'teamName'=>(string)$teamSpec['name'],
                'teamToken'=>$joinToken,
                'nonce'=>$joinNonce,
            );
        }
        if ($formatKey === 'jeopardy' && !empty($teamCredentials)) {
            $wpdb->update(ckm_quiz_games_table(), array('jeopardy_selector_team_id'=>(int)$teamCredentials[0]['teamId'],'updated_at'=>$now), array('id'=>$gameId));
        }
        $createdEventId = ckm_quiz_append_event(
            $gameId,
            'game_created',
            'admin',
            $creatorUserId,
            0,
            0,
            'game',
            $gameId,
            array('teamCount'=>$teamCount, 'hostMode'=>$hostMode, 'judgeMode'=>$judgeMode, 'testMode'=>$testMode, 'formatKey'=>$formatKey, 'formatVersion'=>$formatVersion),
            'game-created'
        );
        if ($createdEventId <= 0) throw new RuntimeException('game_event_insert_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz room creation failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось создать комнату квиза.', 'room_create_failed');
    }

    $fresh = ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true, 201, array(
        'game'=>$fresh,
        'credentials'=>array(
            'host'=>array('gameCode'=>$gameCode, 'hostToken'=>$hostToken, 'nonce'=>$hostNonce),
            'scoreboard'=>array('gameCode'=>$gameCode, 'screenToken'=>$scoreboardToken, 'nonce'=>$scoreboardNonce),
            'teams'=>$teamCredentials,
        ),
    ));
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$scopeLock)); }
}

function ckm_quiz_join_team(array $game, array $team, int $userId): array {
    if ($userId <= 0 || !get_userdata($userId)) {
        return ckm_quiz_error(401, 'Для участия войдите в общую учётную запись платформы.', 'user_login_required');
    }
    if ((int)$team['game_id'] !== (int)$game['id']) return ckm_quiz_error(403, 'Команда относится к другой игре.', 'foreign_team');
    if (!in_array((string)$game['status'], array('waiting','live'), true)) return ckm_quiz_error(409, 'Подключение к завершённой игре закрыто.', 'game_closed');
    global $wpdb;
    $now = ckm_quiz_now_mysql();
    $wpdb->query('START TRANSACTION');
    try {
        $lockedTeam = ckm_quiz_get_team((int)$game['id'], (int)$team['id'], true);
        if (!$lockedTeam) throw new RuntimeException('team_missing');
        $existing = ckm_quiz_get_membership((int)$game['id'], $userId, true);
        if ($existing && (int)$existing['team_id'] !== (int)$lockedTeam['id']) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Вы уже состоите в другой команде этой игры.', 'already_in_other_team');
        }
        if (!$existing) {
            $role = 'player';
            $user = get_userdata($userId);
            $ok = $wpdb->insert(ckm_quiz_members_table(), array(
                'game_id'=>(int)$game['id'],
                'team_id'=>(int)$lockedTeam['id'],
                'user_id'=>$userId,
                'member_role'=>$role,
                'display_name_snapshot'=>$user ? (string)$user->display_name : '',
                'member_status'=>'active',
                'joined_at'=>$now,
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'member_insert_failed');
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM " . ckm_quiz_members_table() . " WHERE id=%d LIMIT 1",
                (int)$wpdb->insert_id
            ), ARRAY_A);
            $joinedEventId = ckm_quiz_append_event(
                (int)$game['id'],
                'team_member_joined',
                'participant',
                $userId,
                (int)$lockedTeam['id'],
                0,
                'membership',
                (int)($existing['id'] ?? 0),
                array('memberRole'=>$role),
                'member-joined-' . $userId
            );
            if ($joinedEventId <= 0) throw new RuntimeException('member_event_insert_failed');
            if ((string)($game['status'] ?? '') === 'waiting' && (string)($game['quiz_phase'] ?? '') === 'waiting' && function_exists('ckm_quiz_chgk_flow_is_active') && ckm_quiz_chgk_flow_is_active($game)) {
                $wpdb->update(ckm_quiz_teams_table(), array('ready_at'=>null,'updated_at'=>$now), array('id'=>(int)$lockedTeam['id']));
            }
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz join failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось присоединить участника к команде.', 'join_failed');
    }
    $freshTeam = ckm_quiz_get_team((int)$game['id'], (int)$team['id']);
    return ckm_quiz_result(true, 200, array('game'=>$game, 'team'=>$freshTeam ?: $team, 'member'=>$existing));
}

function ckm_quiz_consume_access_locked(array $game): array {
    $result = ckm_quiz_core_consume_access_locked($game);
    if (is_array($result) && array_key_exists('ok', $result)) return $result;
    return ckm_quiz_error(503, 'Адаптер списания доступа вернул некорректный ответ.', 'access_adapter_invalid');
}

function ckm_quiz_start_round_locked(array $game, array $question, string $actorType, int $actorUserId = 0): array {
    $roundId = (int)($question['round_id'] ?? 0);
    if ($roundId <= 0) return ckm_quiz_error(409, 'Вопрос не привязан к раунду.', 'question_round_missing');
    $round = ckm_quiz_get_round($roundId);
    if (!$round) return ckm_quiz_error(409, 'Раунд вопроса не найден.', 'round_missing');

    $currentRoundId = (int)($game['current_round_id'] ?? 0);
    $roundPhase = (string)($game['round_phase'] ?? 'waiting');
    if ($currentRoundId === $roundId && $roundPhase === 'open') {
        return ckm_quiz_result(true, 200, array('started'=>false, 'round'=>$round));
    }

    global $wpdb;
    $now = ckm_quiz_now_mysql();
    $ok = $wpdb->update(ckm_quiz_games_table(), array(
        'round_phase'=>'open',
        'current_round_id'=>$roundId,
        'current_round_position'=>(int)$round['position'],
        'round_started_at'=>$now,
        'round_closed_at'=>null,
        'updated_at'=>$now,
    ), array('id'=>(int)$game['id']));
    if ($ok === false) return ckm_quiz_error(500, 'Не удалось открыть раунд.', 'round_start_update_failed');

    $eventId = ckm_quiz_append_event(
        (int)$game['id'],
        'round_started',
        $actorType,
        $actorUserId,
        0,
        (int)$question['id'],
        'round',
        $roundId,
        array(
            'roundId'=>$roundId,
            'roundKey'=>(string)$round['round_key'],
            'position'=>(int)$round['position'],
            'title'=>(string)$round['title'],
            'startedAt'=>$now,
            'firstQuestionPosition'=>(int)$question['position'],
        ),
        'round-started-' . $roundId
    );
    if ($eventId <= 0) return ckm_quiz_error(500, 'Не удалось записать начало раунда.', 'round_start_event_failed');
    return ckm_quiz_result(true, 200, array('started'=>true, 'round'=>$round, 'eventId'=>$eventId));
}

function ckm_quiz_close_round_locked(array $game, string $actorType, int $actorUserId = 0, string $reason = 'round_complete', int $questionId = 0): array {
    $roundId = (int)($game['current_round_id'] ?? 0);
    if ($roundId <= 0 || (string)($game['round_phase'] ?? 'waiting') !== 'open') {
        return ckm_quiz_result(true, 200, array('closed'=>false));
    }
    $round = ckm_quiz_get_round($roundId);
    if (!$round) return ckm_quiz_error(409, 'Текущий раунд не найден.', 'round_missing');

    global $wpdb;
    $now = ckm_quiz_now_mysql();
    $ok = $wpdb->update(ckm_quiz_games_table(), array(
        'round_phase'=>'closed',
        'round_closed_at'=>$now,
        'updated_at'=>$now,
    ), array('id'=>(int)$game['id']));
    if ($ok === false) return ckm_quiz_error(500, 'Не удалось закрыть раунд.', 'round_close_update_failed');

    $eventId = ckm_quiz_append_event(
        (int)$game['id'],
        'round_closed',
        $actorType,
        $actorUserId,
        0,
        max(0, $questionId),
        'round',
        $roundId,
        array(
            'roundId'=>$roundId,
            'roundKey'=>(string)$round['round_key'],
            'position'=>(int)$round['position'],
            'title'=>(string)$round['title'],
            'closedAt'=>$now,
            'reason'=>sanitize_key($reason),
        ),
        'round-closed-' . $roundId
    );
    if ($eventId <= 0) return ckm_quiz_error(500, 'Не удалось записать завершение раунда.', 'round_close_event_failed');
    return ckm_quiz_result(true, 200, array('closed'=>true, 'round'=>$round, 'eventId'=>$eventId));
}

/** Return the authoritative question duration after format adapters. */
function ckm_quiz_effective_question_seconds(array $game, array $question): int {
    $settings = ckm_quiz_settings($game);
    $seconds = (int)($question['time_limit_seconds'] ?? 0);
    if ($seconds <= 0) $seconds = (int)($settings['secondsPerQuestion'] ?? 60);
    $seconds = max(5, min(3600, $seconds));
    if (function_exists('ckm_quiz_runtime_question_seconds')) {
        $seconds = ckm_quiz_runtime_question_seconds($game, $question, $seconds);
    }
    return max(5, min(3600, (int)$seconds));
}

/** Arm a question whose timer was deferred until the host voice ended. */
function ckm_quiz_start_deferred_question_timer(int $gameId, int $questionId, int $seconds, string $reason='voice_playback_complete', int $messageEventId=0): array {
    if ($gameId<=0 || $questionId<=0) return ckm_quiz_error(422,'Некорректный вопрос для запуска таймера.','deferred_timer_invalid');
    $seconds=max(5,min(3600,$seconds));
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404,'Игра не найдена.','game_not_found'); }
        if ((string)($game['status']??'')!=='live' || (string)($game['quiz_phase']??'')!=='question_open') {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'question_not_open'));
        }
        if ((int)($game['current_question_id']??0)!==$questionId) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Подтверждение относится не к текущему вопросу.','voice_playback_stale');
        }
        $existing=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at']??''));
        if ($existing>0) {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true,200,array('action'=>'question_timer_already_started','deadlineAt'=>(string)$game['question_deadline_at'],'seconds'=>$seconds));
        }
        $now=ckm_quiz_now_mysql();
        $deadline=ckm_quiz_deadline_mysql($seconds);
        $updated=$wpdb->query($wpdb->prepare(
            "UPDATE ".ckm_quiz_games_table()." SET question_started_at=%s,question_deadline_at=%s,state_version=state_version+1,updated_at=%s WHERE id=%d AND current_question_id=%d AND question_deadline_at IS NULL",
            $now,$deadline,$now,$gameId,$questionId
        ));
        if ($updated===false) throw new RuntimeException($wpdb->last_error ?: 'deferred_timer_update_failed');
        if ($updated===0) {
            $fresh=ckm_quiz_get_game($gameId,true) ?: $game;
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true,200,array('action'=>'question_timer_already_started','deadlineAt'=>(string)($fresh['question_deadline_at']??''),'seconds'=>$seconds));
        }
        $eventId=ckm_quiz_append_event(
            $gameId,'question_timer_started','system',0,0,$questionId,'question',$questionId,
            array('seconds'=>$seconds,'deadlineAt'=>$deadline,'reason'=>sanitize_key($reason),'messageEventId'=>$messageEventId,'startedAfterVoice'=>$reason==='voice_playback_complete'),
            'question-timer-started-'.$questionId
        );
        if ($eventId<=0) throw new RuntimeException('deferred_timer_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM deferred timer start failed: '.$e->getMessage());
        return ckm_quiz_error(500,'Не удалось запустить таймер вопроса.','deferred_timer_start_failed');
    }
    return ckm_quiz_result(true,200,array('action'=>'question_timer_started','deadlineAt'=>$deadline,'seconds'=>$seconds,'messageEventId'=>$messageEventId));
}

function ckm_quiz_open_next_question(int $gameId, string $actorType, int $actorUserId = 0): array {
    if (ckmqp_show_is_game(ckm_quiz_get_game($gameId) ?: [])) return ckm_quiz_error(409,'Используйте управление раундом «Переговори другого» в комнате команды.','show_preparation_only');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found');
        }
        if ((string)$game['status'] === 'finished') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Игра уже завершена.', 'game_finished');
        }
        if ($actorType === 'ai_host' && (string)($game['host_mode_snapshot'] ?? '') !== 'ai') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Автоматическое действие ИИ отменено: включено ручное управление.', 'ai_host_manual_takeover');
        }
        $firstStart = (int)($game['current_question_position'] ?? 0) === 0 && (string)($game['quiz_phase'] ?? '') === 'waiting';
        if ($firstStart && function_exists('ckm_quiz_chgk_flow_is_active') && ckm_quiz_chgk_flow_is_active($game) && function_exists('ckm_quiz_chgk_preflight')) {
            $preflight = ckm_quiz_chgk_preflight($game);
            if (empty($preflight['canStart'])) {
                $wpdb->query('ROLLBACK');
                return function_exists('ckm_quiz_chgk_preflight_error') ? ckm_quiz_chgk_preflight_error($preflight) : ckm_quiz_error(409, 'Игра «Битва знатоков» пока не готова к старту.', 'chgk_preflight_failed');
            }
        }
        if ((string)$game['quiz_phase'] === 'question_open') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Сначала закройте текущий вопрос.', 'question_already_open');
        }
        if ((string)$game['quiz_phase'] === 'question_closed' && (int)($game['current_question_id'] ?? 0) > 0
            && function_exists('ckm_quiz_chgk_question_flow_state')
            && function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'chgk') {
            $questionFlow = ckm_quiz_chgk_question_flow_state($game);
            if (empty($questionFlow['answerRevealed'])) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Сначала завершите арбитраж и покажите правильный ответ.', 'answer_not_revealed');
            }
            if (!empty($questionFlow['reviewStarted']) && empty($questionFlow['reviewFinished'])) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Сначала завершите разбор текущего вопроса.', 'review_not_finished');
            }
            if (!empty($questionFlow['reviewRequired']) && empty($questionFlow['reviewFinished'])) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Перед следующим вопросом требуется разбор текущего вопроса.', 'review_required');
            }
        }
        if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'chgk'
            && function_exists('ckm_quiz_chgk_single_team_duel_state')) {
            $duel = ckm_quiz_chgk_single_team_duel_state($game);
            if (!empty($duel['enabled']) && !empty($duel['matchFinished'])) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Матч уже завершён: одна из сторон набрала 6 очков.', 'chgk_match_complete');
            }
        }
        $selection = array('handled'=>false,'question'=>null);
        if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='jeopardy') {
            $selectedQuestion = function_exists('ckm_quiz_jeopardy_selected_question') ? ckm_quiz_jeopardy_selected_question($game) : null;
            if (!$selectedQuestion) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Сначала выберите доступную ячейку игрового поля «Интеллектуального батла».', 'jeopardy_cell_selection_required');
            }
            $playedCount = 0;
            if (function_exists('ckm_quiz_jeopardy_cell_status_map')) {
                foreach (ckm_quiz_jeopardy_cell_status_map($gameId) as $cellState) if ((string)($cellState['status'] ?? '') === 'played') $playedCount++;
            }
            $selection = array('handled'=>true,'question'=>$selectedQuestion,'sequencePosition'=>$playedCount + 1,'mode'=>'jeopardy_board');
        } elseif (function_exists('ckm_quiz_chgk_select_next_question') && function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='chgk') {
            $selection = ckm_quiz_chgk_select_next_question($game, $actorType, $actorUserId);
        }
        $question = !empty($selection['handled']) ? ($selection['question'] ?? null) : ckm_quiz_get_question_at((int)$game['quiz_id'], (int)$game['quiz_revision'], (int)$game['current_question_position']);
        if (!$question) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Активных вопросов больше нет. Завершите игру.', 'questions_complete');
        }
        $nextRoundId = (int)($question['round_id'] ?? 0);
        if ((string)($game['round_phase'] ?? 'waiting') === 'open'
            && (int)($game['current_round_id'] ?? 0) > 0
            && (int)$game['current_round_id'] !== $nextRoundId) {
            $roundClose = ckm_quiz_close_round_locked($game, $actorType, $actorUserId, 'next_round', (int)($game['current_question_id'] ?? 0));
            if (empty($roundClose['ok'])) {
                $wpdb->query('ROLLBACK');
                return $roundClose;
            }
            $game = ckm_quiz_get_game($gameId, true) ?: $game;
        }
        $roundStart = ckm_quiz_start_round_locked($game, $question, $actorType, $actorUserId);
        if (empty($roundStart['ok'])) {
            $wpdb->query('ROLLBACK');
            return $roundStart;
        }
        $game = ckm_quiz_get_game($gameId, true) ?: $game;
        $first = (int)$game['current_question_position'] === 0;
        if ($first) {
            $consumption = ckm_quiz_consume_access_locked($game);
            if (empty($consumption['ok'])) {
                $wpdb->query('ROLLBACK');
                return $consumption;
            }
        }
        $seconds = ckm_quiz_effective_question_seconds($game, $question);
        $formatForTimer = function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : '';
        $isAiHost = (string)($game['host_mode_snapshot'] ?? '') === 'ai';
        $deferChgkAiTimer = $formatForTimer === 'chgk' && $isAiHost;
        $deferJeopardyAiTimer = $formatForTimer === 'jeopardy' && $isAiHost;
        $deferSequentialAiTimer = $isAiHost && in_array($formatForTimer, array('classic_quiz','solution_price','negotiation_duel'), true);
        $deferAiTimerUntilVoiceEnd = $deferChgkAiTimer || $deferJeopardyAiTimer || $deferSequentialAiTimer;
        $now = ckm_quiz_now_mysql();
        // Voice-first timer contract: the question is visible while the host speaks,
        // but the authoritative countdown is armed only after playback completes.
        // CHGK keeps its dedicated early-answer phase bridge. Human-host games
        // remain immediate until their live-microphone gate is added separately.
        $deadline = $deferAiTimerUntilVoiceEnd ? '' : ckm_quiz_deadline_mysql($seconds);
        $data = array(
            'status'=>'live',
            'quiz_phase'=>'question_open',
            'current_question_id'=>(int)$question['id'],
            'current_question_position'=>!empty($selection['handled']) ? max(1,(int)($selection['sequencePosition'] ?? 1)) : (int)$question['position'],
            'question_started_at'=>$now,
            'question_deadline_at'=>$deferAiTimerUntilVoiceEnd ? null : $deadline,
            'started_at'=>$first ? $now : ($game['started_at'] ?: $now),
            'updated_at'=>$now,
        );
        if ($first) $data['first_question_started_at'] = $now;
        $ok = $wpdb->update(ckm_quiz_games_table(), $data, array('id'=>$gameId));
        if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'question_update_failed');
        $questionEventId = ckm_quiz_append_event(
            $gameId,
            'question_started',
            $actorType,
            $actorUserId,
            0,
            (int)$question['id'],
            'question',
            (int)$question['id'],
            array('position'=>!empty($selection['handled']) ? max(1,(int)($selection['sequencePosition'] ?? 1)) : (int)$question['position'], 'sourcePosition'=>(int)$question['position'], 'selectionMode'=>(string)($selection['mode'] ?? 'sequential'), 'deadlineAt'=>$deadline, 'timeLimitSeconds'=>$seconds, 'timerDeferredUntilVoiceEnd'=>$deferAiTimerUntilVoiceEnd, 'timerGateFormat'=>$deferChgkAiTimer ? 'chgk' : ($deferJeopardyAiTimer ? 'jeopardy' : ($deferSequentialAiTimer ? 'sequential' : 'immediate'))),
            'question-started-' . (int)$question['id']
        );
        if ($questionEventId <= 0) throw new RuntimeException('question_start_event_failed');
        if (function_exists('ckm_quiz_runtime_after_question_started')) {
            ckm_quiz_runtime_after_question_started($game, $question, $actorType, $actorUserId, $deadline, $seconds);
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz question start failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось запустить вопрос.', 'question_start_failed');
    }
    $freshGame = ckm_quiz_get_game($gameId);
    $aiHost = array();
    if ($freshGame && (string)($freshGame['host_mode_snapshot'] ?? '') === 'ai') {
        // ИИ calls must never keep a core game transaction open. The
        // question is already started even if the provider is slow/unavailable;
        // the adapter will fall back to deterministic text when necessary.
        if (!empty($first)) {
            $aiHost['gameStarted'] = ckm_quiz_publish_ai_host_event($gameId, 'game_started', $question, array(), 'ai-game-started');
        }
        $aiHost['questionStarted'] = ckm_quiz_publish_ai_host_event(
            $gameId,
            'question_started',
            $question,
            array(),
            'ai-question-started-' . (int)$question['id']
        );
        // If AI text/voice publication fails, do not strand the room with NULL deadline.
        // Successful publication remains voice-gated; only the failure path starts now.
        if ((!empty($deferSequentialAiTimer) || !empty($deferJeopardyAiTimer)) && (empty($aiHost['questionStarted']['ok']) || empty($aiHost['questionStarted']['eventId']))) {
            $aiHost['timerFallback'] = ckm_quiz_start_deferred_question_timer($gameId,(int)$question['id'],$seconds,'voice_publish_failed',0);
        }
        $freshGame = ckm_quiz_get_game($gameId) ?: $freshGame;
    }
    return ckm_quiz_result(true, 200, array('game'=>$freshGame ?: ckm_quiz_get_game($gameId), 'question'=>$question, 'aiHost'=>$aiHost));
}

function ckm_quiz_normalize_scalar($value): string {
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_float($value) || is_int($value)) return (string)$value;
    $text = trim(wp_strip_all_tags((string)$value));
    $text = preg_replace('/\s+/u', ' ', $text);
    return function_exists('mb_strtolower') ? mb_strtolower((string)$text, 'UTF-8') : strtolower((string)$text);
}

function ckm_quiz_correct_values(array $question): array {
    $raw = ckm_quiz_json_decode($question['correct_answers_json'] ?? '');
    if (isset($raw['answers']) && is_array($raw['answers'])) $raw = $raw['answers'];
    if (isset($raw['answer'])) $raw = array($raw['answer']);
    if ($raw && array_keys($raw) !== range(0, count($raw) - 1)) $raw = array_values($raw);
    return array_values(array_filter(array_map('ckm_quiz_normalize_scalar', $raw), function (string $value): bool { return $value !== ''; }));
}

function ckm_quiz_submitted_values(array $answer): array {
    $payload = ckm_quiz_json_decode($answer['answer_payload_json'] ?? '');
    $values = array();
    foreach (array('answers','options','selected') as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) { $values = $payload[$key]; break; }
    }
    if (!$values && array_key_exists('answer', $payload)) $values = array($payload['answer']);
    if (!$values && array_key_exists('option', $payload)) $values = array($payload['option']);
    if (!$values) $values = array((string)($answer['answer_text'] ?? ''));
    return array_values(array_filter(array_map('ckm_quiz_normalize_scalar', $values), function (string $value): bool { return $value !== ''; }));
}

function ckm_quiz_question_is_objective(array $question): bool {
    $type = sanitize_key((string)($question['question_type'] ?? ''));
    return in_array($type, array('single_choice','multiple_choice','true_false','number','numeric'), true);
}

function ckm_quiz_text_fuzzy_normalize(string $value): string {
    $value = trim(wp_strip_all_tags($value));
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = str_replace(array('ё','й'), array('е','и'), $value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', (string)$value);
    return trim((string)$value);
}

function ckm_quiz_utf8_chars(string $value): array {
    if ($value === '') return array();
    if (preg_match_all('/./us', $value, $matches)) return $matches[0];
    return str_split($value);
}

function ckm_quiz_levenshtein_utf8(string $a, string $b): int {
    if ($a === $b) return 0;
    $aa = ckm_quiz_utf8_chars($a);
    $bb = ckm_quiz_utf8_chars($b);
    $n = count($aa); $m = count($bb);
    if ($n === 0) return $m;
    if ($m === 0) return $n;
    $prev = range(0, $m);
    for ($i = 1; $i <= $n; $i++) {
        $curr = array($i);
        for ($j = 1; $j <= $m; $j++) {
            $cost = ($aa[$i - 1] === $bb[$j - 1]) ? 0 : 1;
            $curr[$j] = min($curr[$j - 1] + 1, $prev[$j] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $curr;
    }
    return (int)$prev[$m];
}

function ckm_quiz_text_matches_reference(string $submitted, array $references): array {
    $submittedNorm = ckm_quiz_text_fuzzy_normalize($submitted);
    if ($submittedNorm === '') return array('ok'=>false, 'reason'=>'Ответ пустой.');
    foreach ($references as $reference) {
        $referenceNorm = ckm_quiz_text_fuzzy_normalize((string)$reference);
        if ($referenceNorm === '') continue;
        if ($submittedNorm === $referenceNorm) {
            return array('ok'=>true, 'reason'=>'Ответ совпал с эталоном.');
        }
        $maxLen = max(count(ckm_quiz_utf8_chars($submittedNorm)), count(ckm_quiz_utf8_chars($referenceNorm)));
        $minLen = min(count(ckm_quiz_utf8_chars($submittedNorm)), count(ckm_quiz_utf8_chars($referenceNorm)));
        if ($maxLen < 5 || $minLen < 4) continue;
        $distance = ckm_quiz_levenshtein_utf8($submittedNorm, $referenceNorm);
        $limit = $maxLen <= 6 ? 2 : ($maxLen <= 14 ? 3 : max(3, (int)floor($maxLen * 0.22)));
        if ($distance <= $limit) {
            return array('ok'=>true, 'reason'=>'Ответ принят: орфографическая ошибка или опечатка не меняет смысл ответа.');
        }
        $submittedWords = preg_split('/\s+/u', $submittedNorm, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        $referenceWords = preg_split('/\s+/u', $referenceNorm, -1, PREG_SPLIT_NO_EMPTY) ?: array();
        if (count($submittedWords) > 1 || count($referenceWords) > 1) {
            $shared = 0;
            foreach ($referenceWords as $rw) {
                foreach ($submittedWords as $sw) {
                    $wl = max(count(ckm_quiz_utf8_chars($rw)), count(ckm_quiz_utf8_chars($sw)));
                    if ($rw === $sw || ($wl >= 5 && ckm_quiz_levenshtein_utf8($rw, $sw) <= ($wl <= 6 ? 2 : 3))) { $shared++; break; }
                }
            }
            if ($referenceWords && $shared >= max(1, count($referenceWords) - 1)) {
                return array('ok'=>true, 'reason'=>'Ответ принят: ключевые слова совпали, опечатки несущественны.');
            }
        }
    }
    return array('ok'=>false, 'reason'=>'Ответ не совпал с эталоном даже с учётом возможных опечаток.');
}

/**
 * Resolve the actual judging policy for a running game.
 *
 * Buyer-facing mode is authoritative:
 * - human host => manual judging, including objective questions;
 * - AI host => objective questions are always auto-checked against their stored answer key.
 *
 * The stored judge_mode_snapshot is retained for subjective/text questions and backward
 * compatibility. This also repairs already-created AI games that were accidentally
 * snapshotted with judge_mode=human.
 */
function ckm_quiz_effective_judge_mode(array $game, array $question): string {
    $hostMode = ckm_quiz_mode((string)($game['host_mode_snapshot'] ?? $game['host_mode'] ?? 'ai'), 'ai');
    if ($hostMode === 'human') return 'human';
    if ($hostMode === 'ai' && ckm_quiz_question_is_objective($question)) return 'ai';
    return ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid');
}

function ckm_quiz_evaluate_answer(array $question, array $answer, string $judgeMode): array {
    $type = sanitize_key((string)$question['question_type']);
    $objective = ckm_quiz_question_is_objective($question);
    if ($judgeMode === 'human' || ($judgeMode === 'hybrid' && !$objective)) {
        return array('judged'=>false, 'verdict'=>'pending', 'points'=>0, 'reason'=>'Ожидается решение ведущего.');
    }
    $correct = ckm_quiz_correct_values($question);
    $submitted = ckm_quiz_submitted_values($answer);
    if (!$correct || !$submitted) return array('judged'=>false, 'verdict'=>'pending', 'points'=>0, 'reason'=>'Нет эталона для автоматической проверки.');
    $isCorrect = false;
    if (in_array($type, array('number','numeric'), true)) {
        $expected = (float)$correct[0];
        $actual = (float)$submitted[0];
        $tolerance = max(0.0, (float)$question['numeric_tolerance']);
        $isCorrect = abs($actual - $expected) <= $tolerance;
    } elseif ($type === 'multiple_choice') {
        sort($correct);
        sort($submitted);
        $isCorrect = $correct === $submitted;
    } else {
        $textMatch = function_exists('ckm_quiz_text_matches_reference') ? ckm_quiz_text_matches_reference((string)$submitted[0], $correct) : array('ok'=>in_array($submitted[0], $correct, true), 'reason'=>'');
        $isCorrect = !empty($textMatch['ok']);
    }
    return array(
        'judged'=>true,
        'verdict'=>$isCorrect ? 'correct' : 'incorrect',
        'points'=>$isCorrect ? max(0, (int)$question['points']) : 0,
        'reason'=>isset($textMatch) && !empty($textMatch['reason']) ? (string)$textMatch['reason'] : ($isCorrect ? 'Ответ совпал с эталоном.' : 'Ответ не совпал с эталоном.'),
    );
}

function ckm_quiz_score_key64(string $key, string $prefix = 'score'): string {
    $key = preg_replace('/[^A-Za-z0-9_-]/', '', $key);
    if ($key === '') $key = $prefix . '-' . ckm_quiz_random_token(18);
    if (strlen($key) <= 64) return $key;
    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix);
    if ($safePrefix === '') $safePrefix = 'score';
    $head = substr($safePrefix . '-', 0, 16);
    $hashLen = max(16, 64 - strlen($head));
    return $head . substr(hash('sha256', $key), 0, $hashLen);
}

function ckm_quiz_score_event_key64(string $idempotencyKey): string {
    $raw = 'game-event-' . $idempotencyKey;
    if (strlen($raw) <= 64) return $raw;
    return 'game-event-' . substr(hash('sha256', $idempotencyKey), 0, 53);
}

function ckm_quiz_apply_score_locked(array $game, array $question, array $answer, int $points, string $verdict, string $reason, string $actorType, int $actorUserId, string $idempotencyKey): array {
    global $wpdb;
    $scoreTable = ckm_quiz_score_events_table();
    // alpha.69.13.2: both DB keys are varchar(64). Normalize centrally so a
    // perfectly valid score idempotency key cannot overflow after the
    // additional `game-event-` prefix is applied. Hashing keeps long caller
    // request IDs deterministic without collision-prone truncation.
    $idempotencyKey = ckm_quiz_score_key64($idempotencyKey, 'score');
    $gameEventKey = ckm_quiz_score_event_key64($idempotencyKey);
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$scoreTable} WHERE game_id=%d AND idempotency_key=%s LIMIT 1",
        (int)$game['id'],
        $idempotencyKey
    ), ARRAY_A);
    if ($existing) return ckm_quiz_result(true, 200, array('duplicate'=>true, 'scoreEvent'=>$existing));
    $team = ckm_quiz_get_team((int)$game['id'], (int)$answer['team_id'], true);
    if (!$team) return ckm_quiz_error(409, 'Команда ответа больше не существует.', 'team_missing');
    $before = (int)$team['score'];

    // alpha.69.13.1: score_events is the authoritative audit ledger. A resolved
    // answer can be re-scored by AI or a human after another transaction has
    // already updated the ledger. Deriving the correction only from the answer
    // row can leave the row and ledger out of sync (for example 0 points in the
    // answer but +1 still present in score_events). Serialize on the answer row
    // in callers and calculate the answer's current contribution from its
    // answer_score / appeal_adjustment events. Legacy rows without ledger entries fall back to the
    // stored awarded_points value.
    $ledgerRow = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) event_count,COALESCE(SUM(points_delta),0) contribution
         FROM {$scoreTable}
         WHERE game_id=%d AND answer_id=%d AND event_type IN ('answer_score','appeal_adjustment')",
        (int)$game['id'],
        (int)$answer['id']
    ), ARRAY_A);
    $ledgerEventCount = (int)($ledgerRow['event_count'] ?? 0);
    $oldPoints = $ledgerEventCount > 0
        ? (int)($ledgerRow['contribution'] ?? 0)
        : (int)$answer['awarded_points'];

    if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='chgk') {
        $binaryVerdict=sanitize_key($verdict);
        if (in_array($binaryVerdict,array('accepted','correct'),true)) $points=1;
        elseif (in_array($binaryVerdict,array('rejected','incorrect'),true)) $points=0;
        else return ckm_quiz_error(409,'В «Битве знатоков» решение арбитра только бинарное: ответ принят (+1 Знатокам) или не принят (+1 Игре).','chgk_binary_verdict_required');
    }
    $points = max(-10000, min(10000, $points));
    $delta = $points - $oldPoints;
    $after = $before + $delta;
    $now = ckm_quiz_now_mysql();
    $ok = $wpdb->insert($scoreTable, array(
        'game_id'=>(int)$game['id'],
        'team_id'=>(int)$answer['team_id'],
        'question_id'=>(int)$question['id'],
        'answer_id'=>(int)$answer['id'],
        'event_type'=>'answer_score',
        'points_delta'=>$delta,
        'score_before'=>$before,
        'score_after'=>$after,
        'reason'=>$reason,
        'actor_type'=>$actorType,
        'actor_user_id'=>$actorUserId,
        'idempotency_key'=>$idempotencyKey,
        'metadata_json'=>ckm_quiz_json_encode(array(
            'verdict'=>$verdict,
            'attempt'=>(int)$answer['attempt_no'],
            'previousContribution'=>$oldPoints,
            'previousAwardedPoints'=>(int)$answer['awarded_points'],
            'ledgerEventCount'=>$ledgerEventCount,
        )),
        'created_at'=>$now,
    ));
    if ($ok === false) return ckm_quiz_error(500, 'Не удалось записать изменение счёта.', 'score_event_failed');
    $scoreEventId = (int)$wpdb->insert_id;
    $wpdb->update(ckm_quiz_teams_table(), array('score'=>$after, 'updated_at'=>$now), array('id'=>(int)$team['id']));
    $wpdb->update(ckm_quiz_answers_table(), array(
        'auto_points'=>$actorType === 'system' || $actorType === 'ai_host' ? $points : (int)$answer['auto_points'],
        'awarded_points'=>$points,
        'verdict'=>$verdict,
        'judge_mode'=>$actorType === 'host' ? 'human' : ($actorType === 'ai_host' ? 'ai' : 'automatic'),
        'judged_by_user_id'=>$actorUserId,
        'judge_comment'=>$reason,
        'reviewed_at'=>$now,
        'updated_at'=>$now,
    ), array('id'=>(int)$answer['id']));
    $gameEventId = ckm_quiz_append_event(
        (int)$game['id'],
        'answer_scored',
        $actorType,
        $actorUserId,
        (int)$answer['team_id'],
        (int)$question['id'],
        'score_event',
        $scoreEventId,
        array('answerId'=>(int)$answer['id'], 'points'=>$points, 'delta'=>$delta, 'verdict'=>$verdict),
        $gameEventKey
    );
    if ($gameEventId <= 0) return ckm_quiz_error(500, 'Не удалось записать оценку в журнал игры.', 'score_game_event_failed');
    return ckm_quiz_result(true, 200, array('scoreEventId'=>$scoreEventId, 'scoreAfter'=>$after));
}

function ckm_quiz_auto_score_pending_answers_locked(array $game, array $question): int {
    if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='jeopardy' && (string)($question['question_stage'] ?? 'main')==='final') {
        return 0;
    }
    if (function_exists('ckm_quiz_runtime_requires_arbitration') && ckm_quiz_runtime_requires_arbitration($game, $question)) {
        return 0;
    }
    global $wpdb;
    $answers = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending' ORDER BY id ASC FOR UPDATE",
        (int)$game['id'],
        (int)$question['id']
    ), ARRAY_A) ?: array();
    $scored = 0;
    $judgeMode = ckm_quiz_effective_judge_mode($game, $question);
    foreach ($answers as $answer) {
        $evaluation = ckm_quiz_evaluate_answer($question, $answer, $judgeMode);
        if (empty($evaluation['judged'])) continue;
        $score = ckm_quiz_apply_score_locked(
            $game,
            $question,
            $answer,
            (int)$evaluation['points'],
            (string)$evaluation['verdict'],
            (string)$evaluation['reason'],
            'system',
            0,
            'auto-answer-' . (int)$answer['id'] . '-attempt-' . (int)$answer['attempt_no']
        );
        if (empty($score['ok'])) throw new RuntimeException((string)$score['error']);
        $scored++;
    }
    return $scored;
}

function ckm_quiz_reconcile_closed_ai_question(int $gameId): void {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || (string)($game['quiz_phase'] ?? '') !== 'question_closed' || (string)($game['host_mode_snapshot'] ?? '') !== 'ai') {
            $wpdb->query('ROLLBACK');
            return;
        }
        $question = ckm_quiz_get_question((int)($game['current_question_id'] ?? 0));
        if (!$question) {
            $wpdb->query('ROLLBACK');
            return;
        }
        ckm_quiz_auto_score_pending_answers_locked($game, $question);
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz closed-question scoring reconciliation failed: ' . $e->getMessage());
    }
}

function ckm_quiz_close_current_question(int $gameId, string $actorType = 'system', int $actorUserId = 0): array {
    if (function_exists('ckm_quiz_jeopardy_close_final_submission')) {
        $probe = ckm_quiz_get_game($gameId);
        if ($probe && function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($probe)) {
            return ckm_quiz_jeopardy_close_final_submission($gameId, $actorType, $actorUserId);
        }
    }
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found');
        }
        if ((string)$game['quiz_phase'] !== 'question_open') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_result(true, 200, array('alreadyClosed'=>true, 'game'=>$game));
        }
        $question = ckm_quiz_get_question((int)$game['current_question_id']);
        if (!$question) throw new RuntimeException('current_question_missing');
        ckm_quiz_auto_score_pending_answers_locked($game, $question);
        $now = ckm_quiz_now_mysql();
        $wpdb->update(ckm_quiz_games_table(), array('quiz_phase'=>'question_closed', 'updated_at'=>$now), array('id'=>$gameId));
        $closeEventId = ckm_quiz_append_event(
            $gameId,
            'question_closed',
            $actorType,
            $actorUserId,
            0,
            (int)$question['id'],
            'question',
            (int)$question['id'],
            array('position'=>(int)$question['position'], 'closedAt'=>$now),
            'question-closed-' . (int)$question['id']
        );
        if ($closeEventId <= 0) throw new RuntimeException('question_close_event_failed');
        if (function_exists('ckm_quiz_runtime_after_question_closed')) {
            ckm_quiz_runtime_after_question_closed($game, $question, $actorType, $actorUserId);
        }
        $currentRoundId = (int)($question['round_id'] ?? 0);
        $isJeopardy = function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'jeopardy';
        $randomizedChgk = function_exists('ckm_quiz_chgk_random_selection_enabled') && ckm_quiz_chgk_random_selection_enabled($game);
        if ($isJeopardy) {
            $roundGame = ckm_quiz_get_game($gameId, true) ?: $game;
            $hasMoreInRound = $currentRoundId > 0 && function_exists('ckm_quiz_jeopardy_round_has_available') && ckm_quiz_jeopardy_round_has_available($roundGame, $currentRoundId);
            if ($currentRoundId > 0 && !$hasMoreInRound) {
                $nextRoundId = function_exists('ckm_quiz_jeopardy_selectable_round_id') ? ckm_quiz_jeopardy_selectable_round_id($roundGame) : 0;
                $roundClose = ckm_quiz_close_round_locked($roundGame, $actorType, $actorUserId, $nextRoundId > 0 ? 'round_complete' : 'quiz_questions_complete', (int)$question['id']);
                if (empty($roundClose['ok'])) throw new RuntimeException((string)($roundClose['code'] ?? 'round_close_failed'));
            }
        } elseif ($randomizedChgk) {
            $hasMoreInRound = $currentRoundId > 0 && function_exists('ckm_quiz_chgk_round_has_unplayed_main') && ckm_quiz_chgk_round_has_unplayed_main($game, $currentRoundId);
            $remainingAll = function_exists('ckm_quiz_chgk_unplayed_main_rows') ? count(ckm_quiz_chgk_unplayed_main_rows($game)) : 0;
            if ($currentRoundId > 0 && !$hasMoreInRound) {
                $roundGame = ckm_quiz_get_game($gameId, true) ?: $game;
                $roundClose = ckm_quiz_close_round_locked($roundGame, $actorType, $actorUserId, $remainingAll > 0 ? 'round_complete' : 'quiz_questions_complete', (int)$question['id']);
                if (empty($roundClose['ok'])) throw new RuntimeException((string)($roundClose['code'] ?? 'round_close_failed'));
            }
        } else {
            $nextQuestion = ckm_quiz_get_question_at((int)$game['quiz_id'], (int)$game['quiz_revision'], (int)$question['position']);
            $nextRoundId = $nextQuestion ? (int)($nextQuestion['round_id'] ?? 0) : 0;
            if ($currentRoundId > 0 && (!$nextQuestion || $nextRoundId !== $currentRoundId)) {
                $roundGame = ckm_quiz_get_game($gameId, true) ?: $game;
                $roundClose = ckm_quiz_close_round_locked($roundGame, $actorType, $actorUserId, $nextQuestion ? 'round_complete' : 'quiz_questions_complete', (int)$question['id']);
                if (empty($roundClose['ok'])) throw new RuntimeException((string)($roundClose['code'] ?? 'round_close_failed'));
            }
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz question close failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось закрыть вопрос.', 'question_close_failed');
    }

    // External AI arbitration must never run while the core game transaction is
    // open. A slow or unavailable provider therefore cannot roll back the fact
    // that the question was closed. Failures leave answers pending for the human
    // fallback and are recorded as separate CHGK arbitration events.
    $aiArbitration = null;
    if (isset($question) && is_array($question) && function_exists('ckm_quiz_ai_arbitration_run_for_question')) {
        $aiArbitration = ckm_quiz_ai_arbitration_run_for_question($gameId, (int)$question['id']);
    }
    $freshGame = ckm_quiz_get_game($gameId);
    $solutionReview = null;
    if ($freshGame && isset($question) && is_array($question)
        && function_exists('ckm_quiz_runtime_format_key')
        && ckm_quiz_runtime_format_key($freshGame) === 'solution_price'
        && function_exists('ckm_quiz_pro_solution_price_review_closed_question')) {
        // «Управленческая игра "Ваш выбор"» contains subjective text answers. The generic quiz
        // evaluator cannot judge those without a reference answer, so run the
        // format-specific AI/rubric review before the host composes feedback.
        $solutionReview = ckm_quiz_pro_solution_price_review_closed_question($gameId, (int)$question['id']);
        $freshGame = ckm_quiz_get_game($gameId) ?: $freshGame;
    }
    $negotiationReview = null;
    if ($freshGame && isset($question) && is_array($question)
        && function_exists('ckm_quiz_runtime_format_key')
        && ckm_quiz_runtime_format_key($freshGame) === 'negotiation_duel'
        && function_exists('ckm_quiz_pro_negotiation_review_closed_question')) {
        $negotiationReview = ckm_quiz_pro_negotiation_review_closed_question($gameId, (int)$question['id']);
        $freshGame = ckm_quiz_get_game($gameId) ?: $freshGame;
    }
    $aiHost = null;
    if ($freshGame && (string)($freshGame['host_mode_snapshot'] ?? '') === 'ai' && isset($question) && is_array($question)) {
        // For CHGK this runs after AI arbitration; for «Управленческая игра "Ваш выбор"» it runs
        // after the subjective review, so the host can explain the saved result.
        $aiHost = ckm_quiz_publish_ai_host_event(
            $gameId,
            'question_closed',
            $question,
            array('aiArbitration'=>$aiArbitration, 'solutionReview'=>$solutionReview, 'negotiationReview'=>$negotiationReview),
            'ai-question-closed-' . (int)$question['id']
        );
        $freshGame = ckm_quiz_get_game($gameId) ?: $freshGame;
    }
    return ckm_quiz_result(true, 200, array('game'=>$freshGame ?: ckm_quiz_get_game($gameId), 'aiArbitration'=>$aiArbitration, 'solutionReview'=>$solutionReview, 'negotiationReview'=>$negotiationReview, 'aiHost'=>$aiHost));
}

/**
 * Automatic game-flow driver for sequential AI-host formats (Classic Quiz and «Управленческая игра "Ваш выбор"»).
 *
 * Human-host games remain fully manual. For AI-host classic games the first
 * question starts only after every active team has at least one active member,
 * so opening one team link cannot start the timer for everybody else. After a
 * question closes, the server opens the next question idempotently. When there
 * are no questions left, it finishes the game.
 *
 * This is intentionally limited to sequential formats classic_quiz, solution_price and negotiation_duel. CHGK has its own lobby/review
 * contract and Jeopardy has its own selector/final autopilot.
 */
function ckm_quiz_ai_host_classic_all_teams_joined(array $game): bool {
    if ((string)($game['host_mode_snapshot'] ?? '') !== 'ai') return false;
    if (!function_exists('ckm_quiz_runtime_format_key') || !in_array(ckm_quiz_runtime_format_key($game), array('classic_quiz','solution_price','negotiation_duel'), true)) return false;
    $gameId = (int)($game['id'] ?? 0);
    if ($gameId <= 0) return false;
    global $wpdb;
    $activeTeams = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_status='active'",
        $gameId
    ));
    if ($activeTeams <= 0) return false;
    $joinedTeams = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT team_id) FROM " . ckm_quiz_members_table() . " WHERE game_id=%d AND member_status='active'",
        $gameId
    ));
    return $joinedTeams >= $activeTeams;
}

function ckm_quiz_ai_host_classic_autopilot(int $gameId): array {
    if ($gameId <= 0) return array('ok'=>true,'skipped'=>true,'reason'=>'game_missing');
    $game = ckm_quiz_get_game($gameId);
    if (!$game) return array('ok'=>true,'skipped'=>true,'reason'=>'game_missing');
    if (ckmqp_show_is_game($game)) return ['ok'=>true,'skipped'=>true,'reason'=>'preparation_only'];
    if ((string)($game['host_mode_snapshot'] ?? '') !== 'ai') return array('ok'=>true,'skipped'=>true,'reason'=>'host_mode_human');
    if (!function_exists('ckm_quiz_runtime_format_key') || !in_array(ckm_quiz_runtime_format_key($game), array('classic_quiz','solution_price','negotiation_duel'), true)) {
        return array('ok'=>true,'skipped'=>true,'reason'=>'format_not_sequential');
    }
    if ((string)($game['status'] ?? '') === 'finished') return array('ok'=>true,'skipped'=>true,'reason'=>'game_finished');

    $phase = (string)($game['quiz_phase'] ?? 'waiting');
    if ($phase === 'waiting') {
        if (empty($game['auto_start'])) {
            return array('ok'=>true,'skipped'=>true,'reason'=>'waiting_for_host_start');
        }
        if (!ckm_quiz_ai_host_classic_all_teams_joined($game)) {
            return array('ok'=>true,'skipped'=>true,'reason'=>'waiting_for_teams');
        }
        $opened = ckm_quiz_open_next_question($gameId, 'ai_host', 0);
        if (!empty($opened['ok'])) return array('ok'=>true,'action'=>'first_question_started','opened'=>$opened);
        return $opened;
    }

    if ($phase === 'question_closed') {
        // Keep old/partial AI games self-healing before moving forward.
        ckm_quiz_reconcile_closed_ai_question($gameId);
        $opened = ckm_quiz_open_next_question($gameId, 'ai_host', 0);
        if (!empty($opened['ok'])) return array('ok'=>true,'action'=>'next_question_started','opened'=>$opened);
        if ((string)($opened['code'] ?? '') === 'questions_complete') {
            $finished = ckm_quiz_finish_game($gameId, 'ai_host', 0);
            return array('ok'=>!empty($finished['ok']),'action'=>'game_finished','finish'=>$finished);
        }
        // A concurrent poll may have already advanced the game. Treat that as
        // successful idempotent convergence rather than a runtime failure.
        if ((string)($opened['code'] ?? '') === 'question_already_open') {
            return array('ok'=>true,'skipped'=>true,'reason'=>'already_advanced');
        }
        return $opened;
    }

    return array('ok'=>true,'skipped'=>true,'reason'=>'phase_' . sanitize_key($phase));
}

function ckm_quiz_refresh_deadline(array $game): array {
    if (ckmqp_show_is_game($game)) return $game;
    if (!empty($game['is_paused'])) return $game;
    $phase = (string)($game['quiz_phase'] ?? '');
    $isAi = (string)($game['host_mode_snapshot'] ?? '') === 'ai';
    $formatKey = function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : '';
    $aiJeopardy = $isAi
        && $formatKey === 'jeopardy'
        && function_exists('ckm_quiz_jeopardy_ai_autopilot');
    $aiClassic = $isAi
        && in_array($formatKey, array('classic_quiz','solution_price','negotiation_duel'), true)
        && function_exists('ckm_quiz_ai_host_classic_autopilot');
    $aiChgkLobby = $isAi
        && $formatKey === 'chgk'
        && function_exists('ckm_quiz_chgk_ai_host_autostart');

    if ($phase === 'waiting' && $aiChgkLobby) {
        ckm_quiz_chgk_ai_host_autostart((int)$game['id']);
        return ckm_quiz_get_game((int)$game['id']) ?: $game;
    }

    if ($phase === 'question_closed' && $isAi) {
        // Runtime self-heal for AI games closed by older builds before automatic
        // objective scoring was enforced. Pending objective answers are idempotently scored.
        ckm_quiz_reconcile_closed_ai_question((int)$game['id']);
        $fresh = ckm_quiz_get_game((int)$game['id']) ?: $game;
        if ($aiJeopardy) {
            ckm_quiz_jeopardy_ai_autopilot((int)$game['id']);
            $fresh = ckm_quiz_get_game((int)$game['id']) ?: $fresh;
        } elseif ($aiClassic) {
            ckm_quiz_ai_host_classic_autopilot((int)$game['id']);
            $fresh = ckm_quiz_get_game((int)$game['id']) ?: $fresh;
        }
        return $fresh;
    }
    if ($phase !== 'question_open') {
        if ($aiJeopardy) {
            ckm_quiz_jeopardy_ai_autopilot((int)$game['id']);
            return ckm_quiz_get_game((int)$game['id']) ?: $game;
        }
        if ($aiClassic) {
            ckm_quiz_ai_host_classic_autopilot((int)$game['id']);
            return ckm_quiz_get_game((int)$game['id']) ?: $game;
        }
        return $game;
    }
    $deadline = ckm_quiz_mysql_timestamp((string)$game['question_deadline_at']);
    if ($deadline <= 0 || ckm_quiz_now_timestamp() < $deadline) return $game;
    if ($formatKey === 'chgk' && function_exists('ckm_quiz_chgk_final_answer_is_open') && !ckm_quiz_chgk_final_answer_is_open($game)) {
        if (function_exists('ckm_quiz_chgk_discussion_is_started') && !ckm_quiz_chgk_discussion_is_started($game) && function_exists('ckm_quiz_chgk_start_discussion')) {
            ckm_quiz_chgk_start_discussion((int)$game['id'], 'system', 0);
            return ckm_quiz_get_game((int)$game['id']) ?: $game;
        }
        if (function_exists('ckm_quiz_chgk_open_final_answer_window')) {
            ckm_quiz_chgk_open_final_answer_window((int)$game['id'], 'system', 0);
            return ckm_quiz_get_game((int)$game['id']) ?: $game;
        }
    }
    ckm_quiz_close_current_question((int)$game['id'], 'system', 0);
    if ($aiJeopardy) ckm_quiz_jeopardy_ai_autopilot((int)$game['id']);
    elseif ($aiClassic) ckm_quiz_ai_host_classic_autopilot((int)$game['id']);
    return ckm_quiz_get_game((int)$game['id']) ?: $game;
}

function ckm_quiz_submit_answer(array $auth, array $input): array {
    $gameId = (int)($auth['game_id'] ?? 0);
    $teamId = (int)($auth['team_id'] ?? 0);
    $userId = (int)($auth['user_id'] ?? 0);
    if ($gameId <= 0 || $teamId <= 0 || $userId <= 0) return ckm_quiz_error(401, 'Сессия участника недействительна.', 'participant_session_invalid');
    $answerText = sanitize_textarea_field((string)($input['answer_text'] ?? ''));
    $answerPayload = isset($input['answer_payload']) && is_array($input['answer_payload']) ? $input['answer_payload'] : array();
    if ($answerText === '' && !$answerPayload) return ckm_quiz_error(422, 'Передайте ответ команды.', 'answer_empty');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || (string)$game['status'] !== 'live' || (string)$game['quiz_phase'] !== 'question_open') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Сейчас нет открытого вопроса.', 'question_not_open');
        }
        $formatKey = function_exists('ckm_quiz_runtime_format_key') ? ckm_quiz_runtime_format_key($game) : '';
        if ($formatKey === 'chgk' && !empty($game['is_paused'])) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Игра на паузе. Дождитесь продолжения ведущим.', 'game_paused');
        }
        if ($formatKey === 'chgk' && function_exists('ckm_quiz_chgk_final_answer_is_open') && !ckm_quiz_chgk_final_answer_is_open($game)) {
            $phaseDeadline = ckm_quiz_mysql_timestamp((string)$game['question_deadline_at']);
            if ($phaseDeadline > 0 && ckm_quiz_now_timestamp() >= $phaseDeadline) {
                $wpdb->query('ROLLBACK');
                if (function_exists('ckm_quiz_chgk_discussion_is_started') && !ckm_quiz_chgk_discussion_is_started($game) && function_exists('ckm_quiz_chgk_start_discussion')) {
                    ckm_quiz_chgk_start_discussion($gameId, 'system', 0);
                    return ckm_quiz_error(409, 'Время досрочного ответа прошло. Началось обсуждение команды.', 'early_answer_window_closed');
                }
                if (function_exists('ckm_quiz_chgk_open_final_answer_window')) {
                    ckm_quiz_chgk_open_final_answer_window($gameId, 'system', 0);
                    return ckm_quiz_error(409, 'Обсуждение завершено. Окно окончательного ответа только что открыто — отправьте ответ ещё раз.', 'final_answer_not_open');
                }
            }
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Окно окончательного ответа ещё не открыто.', 'final_answer_not_open');
        }
        $deadline = ckm_quiz_mysql_timestamp((string)$game['question_deadline_at']);
        if ($deadline <= 0) {
            $voiceDeferred = (string)($game['host_mode_snapshot'] ?? '')==='ai'
                && in_array($formatKey,array('classic_quiz','solution_price','negotiation_duel','jeopardy'),true);
            $wpdb->query('ROLLBACK');
            if ($voiceDeferred) return ckm_quiz_error(409,'Сначала дослушайте ведущего. Таймер ответа ещё не запущен.','timer_waiting_for_voice');
            ckm_quiz_close_current_question($gameId, 'system', 0);
            return ckm_quiz_error(409, 'Время ответа истекло; сервер отклонил ответ.', 'deadline_passed');
        }
        if (ckm_quiz_now_timestamp() >= $deadline) {
            $wpdb->query('ROLLBACK');
            ckm_quiz_close_current_question($gameId, 'system', 0);
            return ckm_quiz_error(409, 'Время ответа истекло; сервер отклонил ответ.', 'deadline_passed');
        }
        $member = ckm_quiz_get_membership($gameId, $userId, true);
        if (!$member || (int)$member['team_id'] !== $teamId) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(403, 'Вы не состоите в этой команде.', 'foreign_game_or_team');
        }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team) throw new RuntimeException('team_missing');
        $questionId = (int)$game['current_question_id'];
        $question = ckm_quiz_get_question($questionId);
        if (!$question) throw new RuntimeException('question_missing');
        if (function_exists('ckm_quiz_jeopardy_authorize_answer')) {
            $jeopardyAuth = ckm_quiz_jeopardy_authorize_answer($game, $teamId);
            if (empty($jeopardyAuth['ok'])) { $wpdb->query('ROLLBACK'); return $jeopardyAuth; }
        }
        if (function_exists('ckm_quiz_runtime_prepare_answer')) {
            $runtimeAnswer = ckm_quiz_runtime_prepare_answer($game, $question, $answerText, $answerPayload);
            if (empty($runtimeAnswer['ok'])) {
                $wpdb->query('ROLLBACK');
                return $runtimeAnswer;
            }
            $answerText = (string)($runtimeAnswer['answerText'] ?? $answerText);
            $answerPayload = isset($runtimeAnswer['answerPayload']) && is_array($runtimeAnswer['answerPayload']) ? $runtimeAnswer['answerPayload'] : $answerPayload;
        }
        $existing = ckm_quiz_get_answer($gameId, $teamId, $questionId, true);
        if (function_exists('ckm_quiz_runtime_existing_answer_locked') && ckm_quiz_runtime_existing_answer_locked($game, $existing)) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Финальный ответ команды уже зафиксирован и не может быть изменён.', 'final_answer_locked');
        }
        $now = ckm_quiz_now_mysql();
        $startedTs = ckm_quiz_mysql_timestamp((string)$game['question_started_at']);
        $responseMs = $startedTs > 0 ? max(0, (int)round((microtime(true) - $startedTs) * 1000)) : 0;
        $normalized = ckm_quiz_normalize_scalar($answerText !== '' ? $answerText : ckm_quiz_json_encode($answerPayload));
        if (!$existing) {
            $ok = $wpdb->insert(ckm_quiz_answers_table(), array(
                'game_id'=>$gameId,
                'team_id'=>$teamId,
                'question_id'=>$questionId,
                'quiz_revision'=>(int)$game['quiz_revision'],
                'attempt_no'=>1,
                'answer_text'=>$answerText,
                'answer_payload_json'=>ckm_quiz_json_encode($answerPayload),
                'normalized_answer'=>$normalized,
                'submitted_by_user_id'=>$userId,
                'response_time_ms'=>$responseMs,
                'auto_points'=>0,
                'awarded_points'=>0,
                'verdict'=>'pending',
                'judge_mode'=>(string)$game['judge_mode_snapshot'],
                'judged_by_user_id'=>0,
                'submitted_at'=>$now,
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
            if ($ok === false) {
                $race = ckm_quiz_get_answer($gameId, $teamId, $questionId, true);
                if ($race) {
                    $wpdb->query('ROLLBACK');
                    if (function_exists('ckm_quiz_runtime_existing_answer_locked') && ckm_quiz_runtime_existing_answer_locked($game, $race)) {
                        return ckm_quiz_error(409, 'Финальный ответ команды уже зафиксирован и не может быть изменён.', 'final_answer_locked');
                    }
                    return ckm_quiz_error(409, 'Ответ команды одновременно изменён другим участником. Повторите сохранение до окончания времени.', 'answer_conflict');
                }
                throw new RuntimeException($wpdb->last_error ?: 'answer_insert_failed');
            }
            $answerId = (int)$wpdb->insert_id;
            $answerEventId = ckm_quiz_append_event(
                $gameId,
                function_exists('ckm_quiz_runtime_answer_event_name') ? ckm_quiz_runtime_answer_event_name($game, false) : 'answer_submitted',
                'participant',
                $userId,
                $teamId,
                $questionId,
                'answer',
                $answerId,
                array('answerId'=>$answerId, 'attempt'=>1, 'responseTimeMs'=>$responseMs),
                'answer-submitted-' . $answerId
            );
            if ($answerEventId <= 0) throw new RuntimeException('answer_submit_event_failed');
            $updatedAnswer = ckm_quiz_get_answer($gameId, $teamId, $questionId);
            $action = ckm_quiz_runtime_format_key($game) === 'chgk' ? 'finalized' : 'created';
        } else {
            $attempt = (int)$existing['attempt_no'] + 1;
            $updated = $wpdb->update(ckm_quiz_answers_table(), array(
                'attempt_no'=>$attempt,
                'answer_text'=>$answerText,
                'answer_payload_json'=>ckm_quiz_json_encode($answerPayload),
                'normalized_answer'=>$normalized,
                'submitted_by_user_id'=>$userId,
                'response_time_ms'=>$responseMs,
                'auto_points'=>0,
                'awarded_points'=>0,
                'verdict'=>'pending',
                'judge_mode'=>(string)$game['judge_mode_snapshot'],
                'judged_by_user_id'=>0,
                'judge_comment'=>'',
                'reviewed_at'=>null,
                'submitted_at'=>$now,
                'updated_at'=>$now,
            ), array('id'=>(int)$existing['id']));
            if ($updated === false) throw new RuntimeException($wpdb->last_error ?: 'answer_update_failed');
            $updateEventId = ckm_quiz_append_event(
                $gameId,
                function_exists('ckm_quiz_runtime_answer_event_name') ? ckm_quiz_runtime_answer_event_name($game, true) : 'answer_updated',
                'participant',
                $userId,
                $teamId,
                $questionId,
                'answer',
                (int)$existing['id'],
                array('answerId'=>(int)$existing['id'], 'attempt'=>$attempt, 'responseTimeMs'=>$responseMs),
                'answer-update-' . (int)$existing['id'] . '-' . $attempt
            );
            if ($updateEventId <= 0) throw new RuntimeException('answer_update_event_failed');
            $updatedAnswer = ckm_quiz_get_answer($gameId, $teamId, $questionId);
            $action = 'updated';
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz answer failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось сохранить ответ команды.', 'answer_save_failed');
    }
    return ckm_quiz_result(true, 200, array('action'=>$action, 'answer'=>$updatedAnswer ? ckm_quiz_answer_public($updatedAnswer, true) : null));
}

function ckm_quiz_maybe_publish_chgk_final_arbitration_comment(int $gameId, int $questionId): array {
    if ($gameId <= 0 || $questionId <= 0) return array('ok'=>true,'skipped'=>true,'reason'=>'target_missing');
    $game = ckm_quiz_get_game($gameId);
    if (!$game || (string)($game['host_mode_snapshot'] ?? '') !== 'ai') return array('ok'=>true,'skipped'=>true,'reason'=>'host_mode_human');
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') return array('ok'=>true,'skipped'=>true,'reason'=>'format_not_chgk');
    global $wpdb;
    $eventKey = 'ai-chgk-arbitration-final-' . $questionId;
    $existing = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND event_key=%s LIMIT 1",
        $gameId,
        $eventKey
    ));
    if ($existing > 0) return array('ok'=>true,'skipped'=>true,'reason'=>'already_published','eventId'=>$existing);
    $pending = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending'",
        $gameId,
        $questionId
    ));
    if ($pending > 0) return array('ok'=>true,'skipped'=>true,'reason'=>'pending_answers','pending'=>$pending);
    $question = ckm_quiz_get_question($questionId);
    if (!$question) return array('ok'=>true,'skipped'=>true,'reason'=>'question_missing');
    return ckm_quiz_publish_ai_host_event($gameId, 'question_closed', $question, array('arbitrationFinal'=>true), $eventKey);
}

function ckm_quiz_score_answer(int $gameId, int $answerId, int $points, string $verdict, string $comment, array $auth, string $requestId): array {
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found');
        }
        $answer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE id=%d AND game_id=%d LIMIT 1 FOR UPDATE",
            $answerId,
            $gameId
        ), ARRAY_A);
        if (!$answer) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(404, 'Ответ в этой игре не найден.', 'answer_not_found');
        }
        if ((string)$game['quiz_phase'] === 'question_open' && (int)$game['current_question_id'] === (int)$answer['question_id']) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Оценивать ответ можно после закрытия вопроса.', 'question_still_open');
        }
        $question = ckm_quiz_get_question((int)$answer['question_id']);
        if (!$question) throw new RuntimeException('question_missing');
        if (function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game)==='jeopardy' && (string)($question['question_stage'] ?? 'main')==='final') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409,'Финальные ответы оцениваются только через блок финального раунда.','jeopardy_final_score_via_final_required');
        }
        $verdict = sanitize_key($verdict);
        if (!in_array($verdict, array('correct','incorrect','partial','accepted','rejected'), true)) $verdict = 'accepted';
        $comment = sanitize_textarea_field($comment);
        $requestId = preg_replace('/[^A-Za-z0-9_-]/', '', $requestId);
        if ($requestId === '') $requestId = substr(hash('sha256', $answerId . '|' . $points . '|' . $verdict . '|' . $comment), 0, 32);
        $result = ckm_quiz_apply_score_locked(
            $game,
            $question,
            $answer,
            $points,
            $verdict,
            $comment,
            'host',
            (int)($auth['user_id'] ?? 0),
            'manual-' . $answerId . '-' . $requestId
        );
        if (empty($result['ok'])) {
            $wpdb->query('ROLLBACK');
            return $result;
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz manual score failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось сохранить решение арбитра.', 'manual_score_failed');
    }
    $aiHost = null;
    if (isset($answer) && is_array($answer) && (string)($answer['verdict'] ?? 'pending') === 'pending') {
        $aiHost = ckm_quiz_maybe_publish_chgk_final_arbitration_comment($gameId, (int)($answer['question_id'] ?? 0));
    }
    return ckm_quiz_result(true, 200, array('score'=>$result, 'game'=>ckm_quiz_get_game($gameId), 'aiHost'=>$aiHost));
}

function ckm_quiz_publish_human_message(array $game, string $text, array $auth): array {
    if ((string)$game['host_mode_snapshot'] !== 'human') return ckm_quiz_error(409, 'Эта комната работает в режиме ИИ-ведущего.', 'host_mode_mismatch');
    $text = trim(sanitize_textarea_field($text));
    if ($text === '') return ckm_quiz_error(422, 'Введите сообщение ведущего.', 'host_message_empty');
    if (function_exists('mb_substr')) $text = mb_substr($text, 0, 3000, 'UTF-8'); else $text = substr($text, 0, 3000);
    $output = ckm_quiz_compose_host_output('human', 'host_message', array(
        'text'=>$text,
        'game'=>$game,
        'actor_user_id'=>(int)($auth['user_id'] ?? 0),
    ));
    $eventId = ckm_quiz_append_event((int)$game['id'], 'human_host_message', 'host', (int)($auth['user_id'] ?? 0), 0, (int)$game['current_question_id'], 'host_message', 0, $output);
    if ($eventId <= 0) return ckm_quiz_error(500, 'Не удалось опубликовать сообщение ведущего.', 'host_message_failed');
    return ckm_quiz_result(true, 200, array('eventId'=>$eventId, 'output'=>$output));
}

/**
 * Compose and publish an AI-host message outside core state transactions.
 * Provider failures are handled inside CKM_Quiz_AI_Host by a safe text fallback.
 */
function ckm_quiz_publish_ai_host_event(int $gameId, string $event, $question = null, array $extraContext = array(), string $eventKey = ''): array {
    $game = ckm_quiz_get_game($gameId);
    if (!$game) return array('ok'=>false,'code'=>'game_not_found','eventId'=>0);
    if ((string)($game['host_mode_snapshot'] ?? '') !== 'ai') return array('ok'=>true,'skipped'=>true,'reason'=>'host_mode_human','eventId'=>0);
    $context = array_merge($extraContext, array(
        'game'=>$game,
        'question'=>is_array($question) ? $question : array(),
    ));
    try {
        $output = ckm_quiz_compose_host_output('ai', $event, $context);
    } catch (Throwable $e) {
        error_log('CKM Quiz AI host compose failed: ' . $e->getMessage());
        return array('ok'=>false,'code'=>'ai_host_compose_failed','eventId'=>0);
    }
    // AI composition may take long enough for the organizer to switch to manual
    // control. Re-read the authoritative mode immediately before publishing so
    // a stale in-flight AI request cannot speak after manual takeover.
    $freshModeGame = ckm_quiz_get_game($gameId);
    if (!$freshModeGame || (string)($freshModeGame['host_mode_snapshot'] ?? '') !== 'ai') {
        return array('ok'=>true,'skipped'=>true,'reason'=>'manual_takeover_during_compose','eventId'=>0);
    }
    $game = $freshModeGame;
    $questionId = is_array($question) ? (int)($question['id'] ?? 0) : 0;
    if ($eventKey === '') $eventKey = 'ai-host-' . sanitize_key($event) . '-' . ($questionId > 0 ? $questionId : $gameId);
    $eventId = ckm_quiz_append_event($gameId, 'ai_host_message', 'ai_host', 0, 0, $questionId, 'host_message', 0, $output, $eventKey);
    if ($eventId <= 0) {
        error_log('CKM Quiz AI host event write failed: game=' . $gameId . '; event=' . $event);
        return array('ok'=>false,'code'=>'ai_host_event_failed','eventId'=>0,'output'=>$output);
    }
    return array('ok'=>true,'eventId'=>$eventId,'output'=>$output);
}

function ckm_quiz_compose_ai_message(array $game, string $event, bool $publish): array {
    if ((string)$game['host_mode_snapshot'] !== 'ai') return ckm_quiz_error(409, 'Эта комната работает в режиме голосового ведущего.', 'host_mode_mismatch');
    $allowed = array('game_created','game_started','question_started','question_closed','question_review_started','question_review_finished','comparative_analysis_ready','methodology_analysis_ready','answer_received','game_finished');
    if (!in_array($event, $allowed, true)) return ckm_quiz_error(422, 'Неизвестное событие ИИ-ведущего.', 'ai_event_invalid');
    $question = (int)$game['current_question_id'] > 0 ? ckm_quiz_get_question((int)$game['current_question_id']) : null;
    $output = ckm_quiz_compose_host_output('ai', $event, array('game'=>$game, 'question'=>$question ?: array()));
    $eventId = 0;
    if ($publish) {
        $eventId = ckm_quiz_append_event((int)$game['id'], 'ai_host_message', 'ai_host', 0, 0, (int)$game['current_question_id'], 'host_message', 0, $output);
        if ($eventId <= 0) return ckm_quiz_error(500, 'Не удалось опубликовать сообщение ИИ-ведущего.', 'ai_message_failed');
    }
    return ckm_quiz_result(true, 200, array('published'=>$publish, 'eventId'=>$eventId, 'output'=>$output));
}

/**
 * Speed bonus is deliberately simple: at finish, every question awards the
 * configured bonus to the fastest team(s) among answers that earned full
 * credit. response_time_ms belongs to the last saved version of the answer,
 * so changing an answer before the deadline also changes its speed result.
 */
function ckm_quiz_speed_bonus_points(array $game): int {
    $settings = ckm_quiz_settings($game);
    if (array_key_exists('speedBonusEnabled', $settings) && empty($settings['speedBonusEnabled'])) return 0;
    return max(0, min(10, (int)($settings['speedBonusPoints'] ?? 1)));
}

function ckm_quiz_apply_speed_bonuses_locked(array $game): array {
    global $wpdb;
    $gameId = (int)($game['id'] ?? 0);
    $bonus = ckm_quiz_speed_bonus_points($game);
    if ($gameId <= 0 || $bonus <= 0) return array('awarded'=>0, 'questions'=>0);

    $answers = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND awarded_points>0 AND verdict IN ('correct','accepted') ORDER BY question_id ASC,response_time_ms ASC,id ASC FOR UPDATE",
        $gameId
    ), ARRAY_A) ?: array();

    $byQuestion = array();
    foreach ($answers as $answer) {
        $questionId = (int)$answer['question_id'];
        if ($questionId <= 0) continue;
        $byQuestion[$questionId][] = $answer;
    }

    $awarded = 0;
    $questions = 0;
    foreach ($byQuestion as $questionId => $rows) {
        if (!$rows) continue;
        $fastest = null;
        foreach ($rows as $row) {
            $ms = max(0, (int)$row['response_time_ms']);
            if ($fastest === null || $ms < $fastest) $fastest = $ms;
        }
        if ($fastest === null) continue;
        $winners = array_values(array_filter($rows, function(array $row) use ($fastest): bool {
            return max(0, (int)$row['response_time_ms']) === $fastest;
        }));
        if (!$winners) continue;
        $questions++;

        foreach ($winners as $answer) {
            $teamId = (int)$answer['team_id'];
            $key = 'speed-bonus-q' . (int)$questionId . '-team' . $teamId;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . ckm_quiz_score_events_table() . " WHERE game_id=%d AND idempotency_key=%s LIMIT 1",
                $gameId,
                $key
            ));
            if ($existing) continue;

            $team = ckm_quiz_get_team($gameId, $teamId, true);
            if (!$team) throw new RuntimeException('speed_bonus_team_missing');
            $before = (int)$team['score'];
            $after = $before + $bonus;
            $now = ckm_quiz_now_mysql();
            $ok = $wpdb->insert(ckm_quiz_score_events_table(), array(
                'game_id'=>$gameId,
                'team_id'=>$teamId,
                'question_id'=>(int)$questionId,
                'answer_id'=>(int)$answer['id'],
                'event_type'=>'speed_bonus',
                'points_delta'=>$bonus,
                'score_before'=>$before,
                'score_after'=>$after,
                'reason'=>'Бонус за самый быстрый правильный ответ.',
                'actor_type'=>'system',
                'actor_user_id'=>0,
                'idempotency_key'=>$key,
                'metadata_json'=>ckm_quiz_json_encode(array(
                    'responseTimeMs'=>$fastest,
                    'tieCount'=>count($winners),
                    'bonusPoints'=>$bonus,
                )),
                'created_at'=>$now,
            ));
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'speed_bonus_score_event_failed');
            $scoreEventId = (int)$wpdb->insert_id;
            $updated = $wpdb->update(ckm_quiz_teams_table(), array('score'=>$after, 'updated_at'=>$now), array('id'=>$teamId));
            if ($updated === false) throw new RuntimeException($wpdb->last_error ?: 'speed_bonus_team_update_failed');
            $eventId = ckm_quiz_append_event(
                $gameId,
                'speed_bonus_awarded',
                'system',
                0,
                $teamId,
                (int)$questionId,
                'score_event',
                $scoreEventId,
                array('answerId'=>(int)$answer['id'], 'points'=>$bonus, 'responseTimeMs'=>$fastest, 'tieCount'=>count($winners)),
                'game-event-' . $key
            );
            if ($eventId <= 0) throw new RuntimeException('speed_bonus_game_event_failed');
            $awarded++;
        }
    }
    return array('awarded'=>$awarded, 'questions'=>$questions);
}

function ckm_quiz_finish_game(int $gameId, string $actorType, int $actorUserId = 0, bool $forceSharedPlace = false): array {
    $current = ckm_quiz_get_game($gameId);
    if (!$current) return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found');
    if ($actorType === 'ai_host' && (string)($current['host_mode_snapshot'] ?? '') !== 'ai') {
        return ckm_quiz_error(409, 'Автоматическое действие ИИ отменено: включено ручное управление.', 'ai_host_manual_takeover');
    }
    // A configured «Интеллектуальный батл» final is an explicit part of the
    // game, so the generic finish action must not silently close a live board
    // question and bypass it.
    if (function_exists('ckm_quiz_jeopardy_final_finish_guard')) {
        $preGuard = ckm_quiz_jeopardy_final_finish_guard($current);
        if (empty($preGuard['ok'])) return $preGuard;
    }
    if ((string)$current['quiz_phase'] === 'question_open') {
        $closed = ckm_quiz_close_current_question($gameId, $actorType, $actorUserId);
        if (empty($closed['ok'])) return $closed;
    }
    $current = ckm_quiz_get_game($gameId) ?: $current;
    if (function_exists('ckm_quiz_chgk_finish_guard')) {
        $guard = ckm_quiz_chgk_finish_guard($current, $forceSharedPlace);
        if (empty($guard['ok'])) return $guard;
    }
    if (function_exists('ckm_quiz_jeopardy_final_finish_guard')) {
        $guard = ckm_quiz_jeopardy_final_finish_guard($current);
        if (empty($guard['ok'])) return $guard;
    }
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) throw new RuntimeException('game_missing');
        if ((string)$game['status'] === 'finished') {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true, 200, array('alreadyFinished'=>true, 'game'=>$game));
        }
        if ((string)($game['round_phase'] ?? 'waiting') === 'open') {
            $roundClose = ckm_quiz_close_round_locked($game, $actorType, $actorUserId, 'game_finished', (int)($game['current_question_id'] ?? 0));
            if (empty($roundClose['ok'])) throw new RuntimeException((string)($roundClose['code'] ?? 'round_close_failed'));
            $game = ckm_quiz_get_game($gameId, true) ?: $game;
        }
        $speedBonus = ckm_quiz_apply_speed_bonuses_locked($game);
        $now = ckm_quiz_now_mysql();
        $wpdb->update(ckm_quiz_games_table(), array(
            'status'=>'finished',
            'quiz_phase'=>'finished',
            'round_phase'=>'finished',
            'question_deadline_at'=>null,
            'is_paused'=>0,'paused_at'=>null,'paused_quiz_phase'=>'','paused_format_phase'=>'','paused_remaining_seconds'=>0,
            'finished_at'=>$now,
            'updated_at'=>$now,
        ), array('id'=>$gameId));
        if (ckm_quiz_append_event($gameId, 'game_finished', $actorType, $actorUserId, 0, 0, 'game', $gameId, array('finishedAt'=>$now, 'speedBonus'=>$speedBonus), 'game-finished') <= 0) throw new RuntimeException('game_finish_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz finish failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось завершить игру.', 'game_finish_failed');
    }
    $freshGame = ckm_quiz_get_game($gameId);
    $aiHost = null;
    if ($freshGame && (string)($freshGame['host_mode_snapshot'] ?? '') === 'ai') {
        $aiHost = ckm_quiz_publish_ai_host_event($gameId, 'game_finished', null, array(), 'ai-game-finished');
        $freshGame = ckm_quiz_get_game($gameId) ?: $freshGame;
    }
    return ckm_quiz_result(true, 200, array('game'=>$freshGame ?: ckm_quiz_get_game($gameId), 'aiHost'=>$aiHost));
}

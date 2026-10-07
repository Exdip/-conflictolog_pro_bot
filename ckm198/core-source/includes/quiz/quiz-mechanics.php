<?php
/**
 * Universal runtime layer for bonuses, penalties and wagers.
 *
 * The layer is intentionally format-agnostic. Format runtimes decide when an
 * action is legal and what limits apply; this service only provides safe,
 * idempotent persistence and score-ledger integration.
 */

if (!defined('ABSPATH')) exit;

function ckm_quiz_mechanic_normalize_type(string $type): string {
    $type = sanitize_key($type);
    return in_array($type, array('bonus','penalty','wager','jeopardy_cell','jeopardy_buzz','jeopardy_cat_in_bag','jeopardy_auction','jeopardy_final_wager','jeopardy_final_control'), true) ? $type : '';
}

function ckm_quiz_mechanic_normalize_idempotency(string $requestId): string {
    $requestId = preg_replace('/[^A-Za-z0-9_-]/', '', $requestId);
    if ($requestId === '') $requestId = 'mech-' . ckm_quiz_random_token(18);
    return substr($requestId, 0, 64);
}

function ckm_quiz_mechanic_get(int $mechanicId, bool $forUpdate = false): ?array {
    if ($mechanicId <= 0) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE id=%d LIMIT 1{$lock}",
        $mechanicId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_mechanic_rows_for_game(int $gameId, int $teamId = 0, int $limit = 200): array {
    if ($gameId <= 0) return array();
    global $wpdb;
    $limit = max(1, min(500, $limit));
    if ($teamId > 0) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND team_id=%d ORDER BY id DESC LIMIT %d",
            $gameId,
            $teamId,
            $limit
        ), ARRAY_A) ?: array();
        return array_reverse($rows);
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d ORDER BY id DESC LIMIT %d",
        $gameId,
        $limit
    ), ARRAY_A) ?: array();
    return array_reverse($rows);
}

function ckm_quiz_mechanic_public(array $row): array {
    return array(
        'id'=>(int)($row['id'] ?? 0),
        'teamId'=>(int)($row['team_id'] ?? 0),
        'roundId'=>(int)($row['round_id'] ?? 0),
        'questionId'=>(int)($row['question_id'] ?? 0),
        'type'=>(string)($row['mechanic_type'] ?? ''),
        'key'=>(string)($row['mechanic_key'] ?? ''),
        'status'=>(string)($row['status'] ?? ''),
        'value'=>(int)($row['value_int'] ?? 0),
        'result'=>(int)($row['result_int'] ?? 0),
        'scoreEventId'=>(int)($row['score_event_id'] ?? 0),
        'payload'=>ckm_quiz_json_decode($row['payload_json'] ?? ''),
        'createdAt'=>(string)($row['created_at'] ?? ''),
        'resolvedAt'=>(string)($row['resolved_at'] ?? ''),
    );
}

function ckm_quiz_mechanic_validate_scope(array $game, array $scope): array {
    $roundId = max(0, (int)($scope['round_id'] ?? 0));
    $questionId = max(0, (int)($scope['question_id'] ?? 0));

    if ($roundId > 0) {
        $round = ckm_quiz_get_round($roundId);
        if (!$round || (int)$round['quiz_id'] !== (int)$game['quiz_id'] || (int)$round['quiz_revision'] !== (int)$game['quiz_revision']) {
            return ckm_quiz_error(409, 'Раунд не относится к текущей редакции игры.', 'mechanic_round_mismatch');
        }
    }
    if ($questionId > 0) {
        $question = ckm_quiz_get_question($questionId);
        if (!$question || (int)$question['quiz_id'] !== (int)$game['quiz_id'] || (int)$question['quiz_revision'] !== (int)$game['quiz_revision']) {
            return ckm_quiz_error(409, 'Вопрос не относится к текущей редакции игры.', 'mechanic_question_mismatch');
        }
        if ($roundId > 0 && (int)($question['round_id'] ?? 0) > 0 && (int)$question['round_id'] !== $roundId) {
            return ckm_quiz_error(409, 'Вопрос относится к другому раунду.', 'mechanic_scope_mismatch');
        }
    }
    return ckm_quiz_result(true, 200, array('roundId'=>$roundId, 'questionId'=>$questionId));
}

function ckm_quiz_mechanic_existing_by_key(int $gameId, string $idempotencyKey, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $idempotencyKey === '') return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND idempotency_key=%s LIMIT 1{$lock}",
        $gameId,
        $idempotencyKey
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_mechanic_create_locked(array $game, array $team, string $type, int $value, string $actorType, int $actorUserId, string $requestId, array $scope = array(), array $payload = array()): array {
    global $wpdb;
    $type = ckm_quiz_mechanic_normalize_type($type);
    if ($type === '') return ckm_quiz_error(422, 'Неизвестный тип игровой механики.', 'mechanic_type_invalid');
    if ((int)$team['game_id'] !== (int)$game['id']) return ckm_quiz_error(409, 'Команда относится к другой игре.', 'mechanic_team_mismatch');

    $scopeCheck = ckm_quiz_mechanic_validate_scope($game, $scope);
    if (empty($scopeCheck['ok'])) return $scopeCheck;
    $roundId = (int)$scopeCheck['roundId'];
    $questionId = (int)$scopeCheck['questionId'];
    $requestId = ckm_quiz_mechanic_normalize_idempotency($requestId);
    $existing = ckm_quiz_mechanic_existing_by_key((int)$game['id'], $requestId, true);
    if ($existing) return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>$existing));

    $now = ckm_quiz_now_mysql();
    $mechanicKey = sanitize_key((string)($payload['mechanicKey'] ?? $type));
    if ($mechanicKey === '') $mechanicKey = $type;
    $ok = $wpdb->insert(ckm_quiz_mechanics_table(), array(
        'game_id'=>(int)$game['id'],
        'team_id'=>(int)$team['id'],
        'round_id'=>$roundId,
        'question_id'=>$questionId,
        'mechanic_type'=>$type,
        'mechanic_key'=>$mechanicKey,
        'status'=>'pending',
        'value_int'=>$value,
        'result_int'=>0,
        'actor_type'=>sanitize_key($actorType ?: 'system'),
        'actor_user_id'=>max(0, $actorUserId),
        'idempotency_key'=>$requestId,
        'score_event_id'=>0,
        'payload_json'=>ckm_quiz_json_encode($payload),
        'created_at'=>$now,
        'resolved_at'=>null,
        'updated_at'=>$now,
    ));
    if ($ok === false) {
        $race = ckm_quiz_mechanic_existing_by_key((int)$game['id'], $requestId, true);
        if ($race) return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>$race));
        return ckm_quiz_error(500, 'Не удалось записать игровую механику.', 'mechanic_insert_failed');
    }
    $row = ckm_quiz_mechanic_get((int)$wpdb->insert_id, true);
    if (!$row) return ckm_quiz_error(500, 'Не удалось перечитать игровую механику.', 'mechanic_reload_failed');
    return ckm_quiz_result(true, 201, array('duplicate'=>false, 'mechanic'=>$row));
}

function ckm_quiz_mechanic_apply_score_locked(array $game, array $team, array $mechanic, int $delta, string $reason, string $actorType, int $actorUserId, string $finalStatus): array {
    global $wpdb;
    $mechanicId = (int)$mechanic['id'];
    if ($mechanicId <= 0) return ckm_quiz_error(500, 'Некорректная игровая механика.', 'mechanic_missing');
    if ((int)($mechanic['score_event_id'] ?? 0) > 0 || in_array((string)($mechanic['status'] ?? ''), array('applied','settled','void'), true)) {
        return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>$mechanic, 'scoreAfter'=>(int)$team['score']));
    }

    $before = (int)$team['score'];
    $delta = max(-1000000, min(1000000, $delta));
    $after = $before + $delta;
    $now = ckm_quiz_now_mysql();
    $scoreKey = 'mechanic-' . $mechanicId;
    $scoreTable = ckm_quiz_score_events_table();
    $existingScore = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$scoreTable} WHERE game_id=%d AND idempotency_key=%s LIMIT 1",
        (int)$game['id'],
        $scoreKey
    ), ARRAY_A);
    if ($existingScore) {
        $wpdb->update(ckm_quiz_mechanics_table(), array(
            'status'=>$finalStatus,
            'result_int'=>(int)$existingScore['points_delta'],
            'score_event_id'=>(int)$existingScore['id'],
            'resolved_at'=>$now,
            'updated_at'=>$now,
        ), array('id'=>$mechanicId));
        return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>ckm_quiz_mechanic_get($mechanicId, true), 'scoreAfter'=>(int)$existingScore['score_after']));
    }

    $eventType = 'mechanic_' . (string)$mechanic['mechanic_type'];
    $ok = $wpdb->insert($scoreTable, array(
        'game_id'=>(int)$game['id'],
        'team_id'=>(int)$team['id'],
        'question_id'=>(int)($mechanic['question_id'] ?? 0),
        'answer_id'=>0,
        'event_type'=>$eventType,
        'points_delta'=>$delta,
        'score_before'=>$before,
        'score_after'=>$after,
        'reason'=>sanitize_textarea_field($reason),
        'actor_type'=>sanitize_key($actorType ?: 'system'),
        'actor_user_id'=>max(0, $actorUserId),
        'idempotency_key'=>$scoreKey,
        'metadata_json'=>ckm_quiz_json_encode(array(
            'mechanicId'=>$mechanicId,
            'mechanicType'=>(string)$mechanic['mechanic_type'],
            'mechanicKey'=>(string)$mechanic['mechanic_key'],
            'roundId'=>(int)($mechanic['round_id'] ?? 0),
            'value'=>(int)$mechanic['value_int'],
            'status'=>$finalStatus,
        )),
        'created_at'=>$now,
    ));
    if ($ok === false) return ckm_quiz_error(500, 'Не удалось записать изменение счёта игровой механики.', 'mechanic_score_event_failed');
    $scoreEventId = (int)$wpdb->insert_id;
    $updatedTeam = $wpdb->update(ckm_quiz_teams_table(), array('score'=>$after, 'updated_at'=>$now), array('id'=>(int)$team['id']));
    if ($updatedTeam === false) return ckm_quiz_error(500, 'Не удалось обновить счёт команды.', 'mechanic_team_score_failed');
    $updatedMechanic = $wpdb->update(ckm_quiz_mechanics_table(), array(
        'status'=>$finalStatus,
        'result_int'=>$delta,
        'score_event_id'=>$scoreEventId,
        'resolved_at'=>$now,
        'updated_at'=>$now,
    ), array('id'=>$mechanicId));
    if ($updatedMechanic === false) return ckm_quiz_error(500, 'Не удалось завершить игровую механику.', 'mechanic_resolve_failed');

    $gameEventId = ckm_quiz_append_event(
        (int)$game['id'],
        'mechanic_' . $finalStatus,
        $actorType ?: 'system',
        max(0, $actorUserId),
        (int)$team['id'],
        (int)($mechanic['question_id'] ?? 0),
        'game_mechanic',
        $mechanicId,
        array(
            'mechanicType'=>(string)$mechanic['mechanic_type'],
            'mechanicKey'=>(string)$mechanic['mechanic_key'],
            'roundId'=>(int)($mechanic['round_id'] ?? 0),
            'value'=>(int)$mechanic['value_int'],
            'delta'=>$delta,
            'scoreAfter'=>$after,
            'status'=>$finalStatus,
        ),
        'mechanic-event-' . $mechanicId . '-' . $finalStatus
    );
    if ($gameEventId <= 0) return ckm_quiz_error(500, 'Не удалось записать механику в журнал игры.', 'mechanic_game_event_failed');

    return ckm_quiz_result(true, 200, array(
        'duplicate'=>false,
        'mechanic'=>ckm_quiz_mechanic_get($mechanicId, true),
        'scoreEventId'=>$scoreEventId,
        'scoreAfter'=>$after,
    ));
}

function ckm_quiz_apply_bonus(int $gameId, int $teamId, int $points, string $reason, string $actorType = 'host', int $actorUserId = 0, string $requestId = '', array $scope = array()): array {
    return ckm_quiz_apply_adjustment($gameId, $teamId, 'bonus', abs($points), $reason, $actorType, $actorUserId, $requestId, $scope);
}

function ckm_quiz_apply_penalty(int $gameId, int $teamId, int $points, string $reason, string $actorType = 'host', int $actorUserId = 0, string $requestId = '', array $scope = array()): array {
    return ckm_quiz_apply_adjustment($gameId, $teamId, 'penalty', abs($points), $reason, $actorType, $actorUserId, $requestId, $scope);
}

function ckm_quiz_apply_adjustment(int $gameId, int $teamId, string $type, int $points, string $reason, string $actorType, int $actorUserId, string $requestId, array $scope = array()): array {
    global $wpdb;
    $type = ckm_quiz_mechanic_normalize_type($type);
    if (!in_array($type, array('bonus','penalty'), true)) return ckm_quiz_error(422, 'Допустим только бонус или штраф.', 'adjustment_type_invalid');
    $points = abs($points);
    if ($points <= 0 || $points > 1000000) return ckm_quiz_error(422, 'Количество баллов должно быть больше нуля.', 'mechanic_points_invalid');
    $reason = sanitize_textarea_field($reason);
    $requestId = ckm_quiz_mechanic_normalize_idempotency($requestId);

    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found'); }
        if ((string)$game['status'] === 'finished') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Игра уже завершена.', 'game_finished'); }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда не найдена.', 'team_not_found'); }
        $created = ckm_quiz_mechanic_create_locked($game, $team, $type, $points, $actorType, $actorUserId, $requestId, $scope, array('reason'=>$reason));
        if (empty($created['ok'])) { $wpdb->query('ROLLBACK'); return $created; }
        $mechanic = $created['mechanic'];
        if (!empty($created['duplicate']) && in_array((string)($mechanic['status'] ?? ''), array('applied','settled','void'), true)) {
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>ckm_quiz_mechanic_public($mechanic), 'game'=>ckm_quiz_get_game($gameId)));
        }
        $delta = $type === 'bonus' ? $points : -$points;
        $applied = ckm_quiz_mechanic_apply_score_locked($game, $team, $mechanic, $delta, $reason, $actorType, $actorUserId, 'applied');
        if (empty($applied['ok'])) throw new RuntimeException((string)($applied['code'] ?? 'mechanic_apply_failed'));
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz mechanic adjustment failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось применить игровую механику.', 'mechanic_adjustment_failed');
    }
    return ckm_quiz_result(true, 200, array('duplicate'=>!empty($applied['duplicate']), 'mechanic'=>ckm_quiz_mechanic_public($applied['mechanic']), 'scoreAfter'=>(int)$applied['scoreAfter'], 'game'=>ckm_quiz_get_game($gameId)));
}

function ckm_quiz_place_wager(int $gameId, int $teamId, int $amount, string $actorType = 'participant', int $actorUserId = 0, string $requestId = '', array $scope = array(), array $payload = array()): array {
    global $wpdb;
    $amount = abs($amount);
    if ($amount <= 0 || $amount > 1000000) return ckm_quiz_error(422, 'Ставка должна быть больше нуля.', 'wager_amount_invalid');
    $requestId = ckm_quiz_mechanic_normalize_idempotency($requestId);
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found'); }
        if ((string)$game['status'] === 'finished') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Игра уже завершена.', 'game_finished'); }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда не найдена.', 'team_not_found'); }
        $payload['amount'] = $amount;
        $created = ckm_quiz_mechanic_create_locked($game, $team, 'wager', $amount, $actorType, $actorUserId, $requestId, $scope, $payload);
        if (empty($created['ok'])) { $wpdb->query('ROLLBACK'); return $created; }
        $mechanic = $created['mechanic'];
        if (empty($created['duplicate'])) {
            $eventId = ckm_quiz_append_event(
                $gameId,
                'wager_placed',
                $actorType ?: 'participant',
                max(0, $actorUserId),
                $teamId,
                (int)($mechanic['question_id'] ?? 0),
                'game_mechanic',
                (int)$mechanic['id'],
                array('amount'=>$amount, 'roundId'=>(int)($mechanic['round_id'] ?? 0)),
                'wager-placed-' . (int)$mechanic['id']
            );
            if ($eventId <= 0) throw new RuntimeException('wager_event_failed');
        }
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz wager create failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось сохранить ставку.', 'wager_create_failed');
    }
    return ckm_quiz_result(true, !empty($created['duplicate']) ? 200 : 201, array('duplicate'=>!empty($created['duplicate']), 'mechanic'=>ckm_quiz_mechanic_public($mechanic), 'game'=>ckm_quiz_get_game($gameId)));
}

function ckm_quiz_settle_wager(int $gameId, int $mechanicId, string $outcome, string $actorType = 'system', int $actorUserId = 0): array {
    global $wpdb;
    $outcome = sanitize_key($outcome);
    if (!in_array($outcome, array('won','lost','void'), true)) return ckm_quiz_error(422, 'Результат ставки должен быть won, lost или void.', 'wager_outcome_invalid');
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Игра не найдена.', 'game_not_found'); }
        $mechanic = ckm_quiz_mechanic_get($mechanicId, true);
        if (!$mechanic || (int)$mechanic['game_id'] !== $gameId || (string)$mechanic['mechanic_type'] !== 'wager') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(404, 'Ставка в этой игре не найдена.', 'wager_not_found');
        }
        $team = ckm_quiz_get_team($gameId, (int)$mechanic['team_id'], true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда ставки не найдена.', 'team_not_found'); }
        if (in_array((string)$mechanic['status'], array('settled','void'), true)) {
            $storedPayload = ckm_quiz_json_decode($mechanic['payload_json'] ?? '');
            $storedOutcome = sanitize_key((string)($storedPayload['outcome'] ?? ''));
            if ($storedOutcome !== '' && $storedOutcome !== $outcome) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Ставка уже рассчитана с другим результатом.', 'wager_outcome_conflict');
            }
            $wpdb->query('COMMIT');
            return ckm_quiz_result(true, 200, array('duplicate'=>true, 'mechanic'=>ckm_quiz_mechanic_public($mechanic), 'scoreAfter'=>(int)$team['score']));
        }
        if ((string)$mechanic['status'] !== 'pending') {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(409, 'Ставка уже не находится в ожидании.', 'wager_not_pending');
        }
        $amount = abs((int)$mechanic['value_int']);
        $delta = $outcome === 'won' ? $amount : ($outcome === 'lost' ? -$amount : 0);
        $finalStatus = $outcome === 'void' ? 'void' : 'settled';
        $reason = $outcome === 'won' ? 'Ставка выиграна' : ($outcome === 'lost' ? 'Ставка проиграна' : 'Ставка отменена');
        $settled = ckm_quiz_mechanic_apply_score_locked($game, $team, $mechanic, $delta, $reason, $actorType, $actorUserId, $finalStatus);
        if (empty($settled['ok'])) throw new RuntimeException((string)($settled['code'] ?? 'wager_settle_failed'));
        $fresh = $settled['mechanic'];
        $payload = ckm_quiz_json_decode($fresh['payload_json'] ?? '');
        $payload['outcome'] = $outcome;
        $wpdb->update(ckm_quiz_mechanics_table(), array('payload_json'=>ckm_quiz_json_encode($payload), 'updated_at'=>ckm_quiz_now_mysql()), array('id'=>$mechanicId));
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz wager settle failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось рассчитать ставку.', 'wager_settle_failed');
    }
    $final = ckm_quiz_mechanic_get($mechanicId) ?: $fresh;
    return ckm_quiz_result(true, 200, array('duplicate'=>!empty($settled['duplicate']), 'mechanic'=>ckm_quiz_mechanic_public($final), 'scoreAfter'=>(int)$settled['scoreAfter'], 'game'=>ckm_quiz_get_game($gameId)));
}

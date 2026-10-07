<?php
/**
 * Persistence and read models for CKM Quizzes.
 *
 * The module deliberately reuses the platform game/team tables. Relations are
 * validated by the service layer because платформа/dbDelta does not manage
 * foreign-key constraints portably.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ckm_quiz_games_table(): string { return ckm_quiz_core_storage_table('games'); }
function ckm_quiz_teams_table(): string { return ckm_quiz_core_storage_table('teams'); }
function ckm_quiz_members_table(): string { return ckm_quiz_core_storage_table('members'); }
function ckm_quiz_quizzes_table(): string { return ckm_quiz_core_storage_table('quizzes'); }
function ckm_quiz_questions_table(): string { return ckm_quiz_core_storage_table('questions'); }
function ckm_quiz_answers_table(): string { return ckm_quiz_core_storage_table('answers'); }
function ckm_quiz_score_events_table(): string { return ckm_quiz_core_storage_table('score_events'); }
function ckm_quiz_events_table(): string { return ckm_quiz_core_storage_table('events'); }
function ckm_quiz_formats_table(): string { return ckm_quiz_core_storage_table('formats'); }
function ckm_quiz_rounds_table(): string { return ckm_quiz_core_storage_table('rounds'); }
function ckm_quiz_mechanics_table(): string { return ckm_quiz_core_storage_table('mechanics'); }
function ckm_quiz_drafts_table(): string { return ckm_quiz_core_storage_table('drafts'); }
function ckm_quiz_appeals_table(): string { return ckm_quiz_core_storage_table('appeals'); }
function ckm_quiz_access_table(): string { return ckm_quiz_core_storage_table('access'); }
function ckm_quiz_sales_table(): string { return ckm_quiz_core_storage_table('sales'); }
function ckm_quiz_methodology_analysis_table(): string { return ckm_quiz_core_storage_table_optional('methodology_analysis'); }
function ckm_quiz_report_jobs_table(): string { return ckm_quiz_core_storage_table_optional('report_jobs'); }

function ckm_quiz_now_mysql(): string {
    return current_time('mysql');
}

function ckm_quiz_now_timestamp(): int {
    return time();
}

function ckm_quiz_mysql_timestamp(?string $value): int {
    $value = trim((string)$value);
    if ($value === '') return 0;
    try {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
        return $date ? $date->getTimestamp() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function ckm_quiz_deadline_mysql(int $seconds): string {
    $seconds = max(5, min(3600, $seconds));
    try {
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(date_default_timezone_get());
        return (new DateTimeImmutable('now', $timezone))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s', time() + $seconds);
    }
}

function ckm_quiz_json_decode($value, array $fallback = array()): array {
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function ckm_quiz_json_encode(array $value): string {
    $json = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function ckm_quiz_random_token(int $bytes = 24): string {
    return ckm_quiz_core_random_token($bytes);
}

function ckm_quiz_unique_game_code(): string {
    global $wpdb;
    $table = ckm_quiz_games_table();
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $code = 'QUIZ-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', ckm_quiz_random_token(9)), 0, 8));
        if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE game_code=%s", $code)) === 0) {
            return $code;
        }
    }
    return 'QUIZ-' . strtoupper(wp_generate_password(12, false, false));
}


function ckm_quiz_secret_equal(string $provided, string $expected): bool {
    return $provided !== '' && $expected !== '' && strlen($provided) === strlen($expected) && hash_equals($expected, $provided);
}

function ckm_quiz_guest_user_id(int $gameId): int {
    if ($gameId <= 0) return 0;
    global $wpdb;
    for ($i = 0; $i < 24; $i++) {
        try {
            $candidate = random_int(1000000000000, 8999999999999);
        } catch (Throwable $e) {
            $candidate = 1000000000000 + (int)(hexdec(substr(hash('sha256', uniqid((string)$gameId, true)), 0, 10)) % 7000000000000);
        }
        if ($candidate <= 0) continue;
        if (function_exists('get_userdata') && get_userdata($candidate)) continue;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . ckm_quiz_members_table() . " WHERE game_id=%d AND user_id=%d LIMIT 1",
            $gameId,
            $candidate
        ));
        if (!$exists) return $candidate;
    }
    return 0;
}

function ckm_quiz_join_guest_team(array $game, array $team, string $displayName = ''): array {
    if ((int)($team['game_id'] ?? 0) !== (int)($game['id'] ?? 0)) return ckm_quiz_error(403, 'Команда относится к другой игре.', 'foreign_team');
    if (!in_array((string)($game['status'] ?? ''), array('waiting','live'), true)) return ckm_quiz_error(409, 'Подключение к завершённой игре закрыто.', 'game_closed');
    global $wpdb;
    $now = ckm_quiz_now_mysql();
    $wpdb->query('START TRANSACTION');
    try {
        $lockedTeam = ckm_quiz_get_team((int)$game['id'], (int)$team['id'], true);
        if (!$lockedTeam) throw new RuntimeException('team_missing');
        $guestUserId = ckm_quiz_guest_user_id((int)$game['id']);
        if ($guestUserId <= 0) throw new RuntimeException('guest_identity_failed');
        $role = 'player';
        $name = sanitize_text_field($displayName);
        if ($name === '') $name = 'Гость · ' . ((string)($lockedTeam['team_name'] ?? '') !== '' ? (string)$lockedTeam['team_name'] : ('Команда ' . (string)($lockedTeam['team_key'] ?? '')));
        $ok = $wpdb->insert(ckm_quiz_members_table(), array(
            'game_id'=>(int)$game['id'],
            'team_id'=>(int)$lockedTeam['id'],
            'user_id'=>$guestUserId,
            'member_role'=>$role,
            'display_name_snapshot'=>$name,
            'member_status'=>'active',
            'joined_at'=>$now,
            'created_at'=>$now,
            'updated_at'=>$now,
        ));
        if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'guest_member_insert_failed');
        $memberId = (int)$wpdb->insert_id;
        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_members_table() . " WHERE id=%d LIMIT 1",
            $memberId
        ), ARRAY_A);
        if (!$member) throw new RuntimeException('guest_member_reload_failed');
        if ((string)($game['status'] ?? '') === 'waiting' && (string)($game['quiz_phase'] ?? '') === 'waiting' && function_exists('ckm_quiz_chgk_flow_is_active') && ckm_quiz_chgk_flow_is_active($game)) {
            $wpdb->update(ckm_quiz_teams_table(), array('ready_at'=>null,'updated_at'=>$now), array('id'=>(int)$lockedTeam['id']));
        }
        $eventId = ckm_quiz_append_event(
            (int)$game['id'],
            'team_member_joined',
            'participant_guest',
            0,
            (int)$lockedTeam['id'],
            0,
            'membership',
            $memberId,
            array('memberRole'=>$role, 'guest'=>true),
            'guest-member-joined-' . $memberId
        );
        if ($eventId <= 0) throw new RuntimeException('guest_member_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Quiz guest join failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось создать гостевую сессию команды.', 'guest_join_failed');
    }
    $freshTeam = ckm_quiz_get_team((int)$game['id'], (int)$team['id']);
    return ckm_quiz_result(true, 200, array('game'=>$game, 'team'=>$freshTeam ?: $team, 'member'=>$member, 'guest'=>true));
}

function ckm_quiz_validate_join_link(array $game, array $team, string $teamToken, string $nonce): array {
    if ((int)($team['game_id'] ?? 0) !== (int)($game['id'] ?? 0)) {
        return array('ok'=>false, 'status'=>403, 'error'=>'Команда относится к другой игре.', 'code'=>'foreign_team');
    }
    if (!ckm_quiz_secret_equal(trim($teamToken), (string)($team['join_token'] ?? ''))
        || !ckm_quiz_secret_equal(trim($nonce), (string)($team['join_nonce'] ?? ''))) {
        return array('ok'=>false, 'status'=>403, 'error'=>'Токен команды или nonce недействителен.', 'code'=>'join_auth_failed');
    }
    return array('ok'=>true, 'status'=>200, 'role'=>'join', 'game_id'=>(int)$game['id'], 'team_id'=>(int)$team['id']);
}

function ckm_quiz_get_quiz(int $quizId, bool $publishedOnly = false): ?array {
    if ($quizId <= 0) return null;
    global $wpdb;
    $status = $publishedOnly ? " AND status='published'" : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_quizzes_table() . " WHERE id=%d{$status} LIMIT 1",
        $quizId
    ), ARRAY_A);
    return is_array($row) && ckmqp_content_in_scope($row) ? $row : null;
}

function ckm_quiz_get_question(int $questionId): ?array {
    if ($questionId <= 0) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_questions_table() . " WHERE id=%d LIMIT 1",
        $questionId
    ), ARRAY_A);
    return is_array($row) && ckm_quiz_get_quiz((int)$row['quiz_id']) ? $row : null;
}

function ckm_quiz_get_round(int $roundId): ?array {
    if ($roundId <= 0) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_rounds_table() . " WHERE id=%d LIMIT 1",
        $roundId
    ), ARRAY_A);
    return is_array($row) && ckm_quiz_get_quiz((int)$row['quiz_id']) ? $row : null;
}

function ckm_quiz_round_public(array $round): array {
    return array(
        'id'=>(int)($round['id'] ?? 0),
        'key'=>(string)($round['round_key'] ?? ''),
        'position'=>(int)($round['position'] ?? 0),
        'title'=>(string)($round['title'] ?? ''),
        'type'=>(string)($round['round_type'] ?? 'questions'),
        'rules'=>ckm_quiz_json_decode($round['rules_json'] ?? ''),
        'settings'=>ckm_quiz_json_decode($round['settings_json'] ?? ''),
        'status'=>(string)($round['status'] ?? 'active'),
    );
}

function ckm_quiz_get_question_at(int $quizId, int $revision, int $afterPosition = 0): ?array {
    if (!ckm_quiz_get_quiz($quizId)) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')='main' AND position>%d ORDER BY position ASC,id ASC LIMIT 1",
        $quizId,
        $revision,
        max(0, $afterPosition)
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_question_count(int $quizId, int $revision): int {
    if (!ckm_quiz_get_quiz($quizId)) return 0;
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')='main'",
        $quizId,
        $revision
    ));
}


function ckm_quiz_tiebreak_question_count(int $quizId, int $revision): int {
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND question_stage='tiebreak'",
        $quizId,
        $revision
    ));
}

function ckm_quiz_get_tiebreak_question_at(int $quizId, int $revision, int $afterPosition = 0): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND question_stage='tiebreak' AND position>%d ORDER BY position ASC,id ASC LIMIT 1",
        $quizId,
        $revision,
        max(0, $afterPosition)
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_game_by_code(string $code, bool $forUpdate = false): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    global $wpdb;
    if (!ckmqp_scope_ready() || ckmqp_scope_id()<0) return null;
    $tenant=ckmqp_scope_id();
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_games_table() . " WHERE game_code=%s AND tenant_id={$tenant} AND game_type='quiz' LIMIT 1{$lock}",
        $code
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_game(int $gameId, bool $forUpdate = false): ?array {
    if ($gameId <= 0) return null;
    global $wpdb;
    if (!ckmqp_scope_ready() || ckmqp_scope_id()<0) return null;
    $tenant=ckmqp_scope_id();
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_games_table() . " WHERE id=%d AND tenant_id={$tenant} AND game_type='quiz' LIMIT 1{$lock}",
        $gameId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_team(int $gameId, int $teamId, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $teamId <= 0) return null;
    if (!ckm_quiz_get_game($gameId)) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_teams_table() . " WHERE id=%d AND game_id=%d LIMIT 1{$lock}",
        $teamId,
        $gameId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_team_by_key(int $gameId, string $teamKey, bool $forUpdate = false): ?array {
    if ($gameId <= 0) return null;
    if (!ckm_quiz_get_game($gameId)) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d AND team_key=%s LIMIT 1{$lock}",
        $gameId,
        strtoupper(trim($teamKey))
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_membership(int $gameId, int $userId, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $userId <= 0) return null;
    if (!ckm_quiz_get_game($gameId)) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_members_table() . " WHERE game_id=%d AND user_id=%d AND member_status='active' LIMIT 1{$lock}",
        $gameId,
        $userId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_get_answer(int $gameId, int $teamId, int $questionId, bool $forUpdate = false): ?array {
    if (!ckm_quiz_get_game($gameId)) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND team_id=%d AND question_id=%d LIMIT 1{$lock}",
        $gameId,
        $teamId,
        $questionId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_append_event(
    int $gameId,
    string $action,
    string $actorType,
    int $actorUserId = 0,
    int $teamId = 0,
    int $questionId = 0,
    string $entityType = '',
    int $entityId = 0,
    array $payload = array(),
    string $eventKey = ''
): int {
    global $wpdb;
    $events = ckm_quiz_events_table();
    $eventKey = trim($eventKey);
    if ($eventKey === '') $eventKey = 'evt-' . ckm_quiz_random_token(18);
    $ok = $wpdb->insert($events, array(
        'game_id'=>$gameId,
        'event_key'=>$eventKey,
        'action'=>sanitize_key($action),
        'actor_type'=>sanitize_key($actorType),
        'actor_user_id'=>max(0, $actorUserId),
        'team_id'=>max(0, $teamId),
        'question_id'=>max(0, $questionId),
        'entity_type'=>sanitize_key($entityType),
        'entity_id'=>max(0, $entityId),
        'payload_json'=>ckm_quiz_json_encode($payload),
        'created_at'=>ckm_quiz_now_mysql(),
    ));
    if ($ok === false) {
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$events} WHERE game_id=%d AND event_key=%s LIMIT 1",
            $gameId,
            $eventKey
        ));
        return $existing ? (int)$existing : 0;
    }
    $eventId = (int)$wpdb->insert_id;
    $wpdb->query($wpdb->prepare(
        "UPDATE " . ckm_quiz_games_table() . " SET state_version=state_version+1,updated_at=%s WHERE id=%d",
        ckm_quiz_now_mysql(),
        $gameId
    ));

    /**
     * Integration hook for non-critical delivery channels (voice broadcast,
     * analytics, external mirrors). Consumers MUST NOT mutate quiz state.
     * The voice bridge uses a non-blocking HTTP request so game transactions
     * are not held open by the external gateway.
     */
    do_action('ckm_quiz_event_appended', array(
        'id'=>$eventId,
        'game_id'=>$gameId,
        'event_key'=>$eventKey,
        'action'=>sanitize_key($action),
        'actor_type'=>sanitize_key($actorType),
        'actor_user_id'=>max(0, $actorUserId),
        'team_id'=>max(0, $teamId),
        'question_id'=>max(0, $questionId),
        'entity_type'=>sanitize_key($entityType),
        'entity_id'=>max(0, $entityId),
        'payload'=>$payload,
    ));
    return $eventId;
}

function ckm_quiz_events_after(int $gameId, int $lastEventId, int $limit = 250): array {
    if (!ckm_quiz_get_game($gameId)) return [];
    global $wpdb;
    $limit = max(1, min(500, $limit));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,action,actor_type,actor_user_id,team_id,question_id,entity_type,entity_id,payload_json,created_at FROM " . ckm_quiz_events_table() . " WHERE game_id=%d AND id>%d ORDER BY id ASC LIMIT %d",
        $gameId,
        max(0, $lastEventId),
        $limit
    ), ARRAY_A) ?: array();
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['actor_user_id'] = (int)$row['actor_user_id'];
        $row['team_id'] = (int)$row['team_id'];
        $row['question_id'] = (int)$row['question_id'];
        $row['entity_id'] = (int)$row['entity_id'];
        $row['payload'] = ckm_quiz_json_decode($row['payload_json']);
        unset($row['payload_json']);
    }
    unset($row);
    return $rows;
}

function ckm_quiz_question_public(array $question, bool $reveal = false, bool $hostDetails = false): array {
    $out = array(
        'id'=>(int)$question['id'],
        'key'=>(string)$question['question_key'],
        'position'=>(int)$question['position'],
        'roundNo'=>(int)$question['round_no'],
        'roundId'=>(int)($question['round_id'] ?? 0),
        'roundTitle'=>(string)$question['round_title'],
        'stage'=>(string)($question['question_stage'] ?? 'main'),
        'jeopardyCategoryKey'=>(string)($question['jeopardy_category_key'] ?? ''),
        'jeopardyCategoryTitle'=>(string)($question['jeopardy_category_title'] ?? ''),
        'jeopardyValue'=>(int)($question['jeopardy_value'] ?? 0),
        'type'=>(string)$question['question_type'],
        'text'=>(string)$question['question_text'],
        'options'=>ckm_quiz_json_decode($question['options_json']),
        'points'=>(int)$question['points'],
        'timeLimitSeconds'=>(int)$question['time_limit_seconds'],
        'mediaUrl'=>(string)$question['media_url'],
        'mediaType'=>(string)($question['media_type'] ?? (((string)($question['media_url'] ?? '')) !== '' ? 'image' : '')),
        'mediaStartSeconds'=>(int)($question['media_start_seconds'] ?? 0),
        'mediaEndSeconds'=>(int)($question['media_end_seconds'] ?? 0),
    );
    if ($reveal) {
        $out['correctAnswers'] = ckm_quiz_json_decode($question['correct_answers_json']);
        $out['explanation'] = (string)$question['explanation'];
    }
    if ($hostDetails) {
        $out['hostScript'] = (string)$question['host_script'];
        $out['scoringRule'] = ckm_quiz_json_decode($question['scoring_rule_json']);
    }
    return $out;
}

/**
 * Public CHGK arbitration summary for the projector/scoreboard.
 * Never exposes answer_text or argumentation. During an open discussion the
 * scoreboard only receives answeredCurrentQuestion on the team row.
 */
function ckm_quiz_scoreboard_arbitration_summary(array $game): array {
    if (!function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game) !== 'chgk') return array();
    if ((string)($game['quiz_phase'] ?? '') === 'question_open') return array();
    $gameId = (int)($game['id'] ?? 0);
    $questionId = (int)($game['current_question_id'] ?? 0);
    if ($gameId <= 0 || $questionId <= 0) return array();
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tm.id team_id,tm.team_name,a.id answer_id,a.verdict,a.awarded_points,a.judge_mode,a.judge_comment,a.reviewed_at
         FROM " . ckm_quiz_teams_table() . " tm
         LEFT JOIN " . ckm_quiz_answers_table() . " a ON a.game_id=tm.game_id AND a.team_id=tm.id AND a.question_id=%d
         WHERE tm.game_id=%d ORDER BY tm.slot_no ASC,tm.id ASC",
        $questionId,
        $gameId
    ), ARRAY_A) ?: array();
    $out = array();
    foreach ($rows as $row) {
        $hasAnswer = (int)($row['answer_id'] ?? 0) > 0;
        $verdict = $hasAnswer ? sanitize_key((string)($row['verdict'] ?? 'pending')) : 'no_answer';
        if ($verdict === '') $verdict = 'pending';
        $out[] = array(
            'teamId'=>(int)$row['team_id'],
            'teamName'=>(string)$row['team_name'],
            'hasAnswer'=>$hasAnswer,
            'status'=>$verdict === 'pending' ? 'pending' : ($verdict === 'no_answer' ? 'no_answer' : 'resolved'),
            'verdict'=>$verdict,
            'points'=>$hasAnswer ? (int)($row['awarded_points'] ?? 0) : 0,
            'judgeMode'=>$hasAnswer ? sanitize_key((string)($row['judge_mode'] ?? '')) : '',
            'judgeComment'=>$hasAnswer && $verdict !== 'pending' ? sanitize_textarea_field((string)($row['judge_comment'] ?? '')) : '',
            'reviewedAt'=>$hasAnswer ? (string)($row['reviewed_at'] ?? '') : '',
        );
    }
    return $out;
}

function ckm_quiz_answer_public(array $answer, bool $includeText = true): array {
    $out = array(
        'id'=>(int)$answer['id'],
        'teamId'=>(int)$answer['team_id'],
        'questionId'=>(int)$answer['question_id'],
        'attempt'=>(int)$answer['attempt_no'],
        'submittedByUserId'=>(int)$answer['submitted_by_user_id'],
        'responseTimeMs'=>(int)$answer['response_time_ms'],
        'verdict'=>(string)$answer['verdict'],
        'awardedPoints'=>(int)$answer['awarded_points'],
        'judgeMode'=>(string)($answer['judge_mode'] ?? ''),
        'reviewedAt'=>(string)($answer['reviewed_at'] ?? ''),
        'submittedAt'=>(string)$answer['submitted_at'],
        'updatedAt'=>(string)$answer['updated_at'],
    );
    if ($includeText) {
        $out['answerText'] = (string)$answer['answer_text'];
        $out['answerPayload'] = ckm_quiz_json_decode($answer['answer_payload_json']);
        $out['judgeComment'] = (string)$answer['judge_comment'];
    }
    return $out;
}


/**
 * Test rooms are operational diagnostics only. They are excluded from every
 * public aggregate/read model and may only appear in direct game state.
 */
function ckm_quiz_game_is_test(array $game): bool {
    if (array_key_exists('test_mode', $game)) return (int)$game['test_mode'] === 1;
    $settings = ckm_quiz_settings($game);
    return !empty($settings['testMode']);
}

function ckm_quiz_public_history_games(int $limit = 100): array {
    $tenant=ckmqp_scope_ready()?ckmqp_scope_id():-1;
    global $wpdb;
    $limit = max(1, min(500, $limit));
    return $wpdb->get_results($wpdb->prepare(
        "SELECT id,game_code,title,status,quiz_id,quiz_revision,started_at,finished_at,created_at FROM " . ckm_quiz_games_table() . " WHERE tenant_id={$tenant} AND game_type='quiz' AND test_mode=0 ORDER BY id DESC LIMIT %d",
        $limit
    ), ARRAY_A) ?: array();
}

function ckm_quiz_public_history_contains_game(int $gameId): bool {
    if ($gameId <= 0) return false;
    $tenant=ckmqp_scope_ready()?ckmqp_scope_id():-1;
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_games_table() . " WHERE tenant_id={$tenant} AND id=%d AND game_type='quiz' AND test_mode=0",
        $gameId
    )) > 0;
}

function ckm_quiz_public_statistics(): array {
    $tenant=ckmqp_scope_ready()?ckmqp_scope_id():-1;
    global $wpdb;
    $games = ckm_quiz_games_table();
    $teams = ckm_quiz_teams_table();
    return array(
        'games'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$games} WHERE tenant_id={$tenant} AND game_type='quiz' AND test_mode=0"),
        'finishedGames'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$games} WHERE tenant_id={$tenant} AND game_type='quiz' AND test_mode=0 AND status='finished'"),
        'teams'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$teams} tm INNER JOIN {$games} g ON g.id=tm.game_id WHERE g.tenant_id={$tenant} AND g.game_type='quiz' AND g.test_mode=0"),
    );
}

function ckm_quiz_public_rating_rows(int $limit = 100): array {
    $tenant=ckmqp_scope_ready()?ckmqp_scope_id():-1;
    global $wpdb;
    $limit = max(1, min(500, $limit));
    $games = ckm_quiz_games_table();
    $teams = ckm_quiz_teams_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT tm.id team_id,tm.team_name,tm.score,g.id game_id,g.game_code,g.finished_at FROM {$teams} tm INNER JOIN {$games} g ON g.id=tm.game_id WHERE g.tenant_id={$tenant} AND g.game_type='quiz' AND g.test_mode=0 AND g.status='finished' ORDER BY tm.score DESC,tm.id ASC LIMIT %d",
        $limit
    ), ARRAY_A) ?: array();
}

function ckm_quiz_build_state(array $game, array $auth, int $lastEventId = 0): array {
    if (ckmqp_show_is_game($game)) return ckmqp_show_state($game, $auth);
    global $wpdb;
    if (function_exists('ckm_quiz_set_current_state_auth')) ckm_quiz_set_current_state_auth($auth);
    $gameId = (int)$game['id'];
    $role = (string)($auth['role'] ?? 'participant');
    $teams = $wpdb->get_results($wpdb->prepare(
        "SELECT id,team_key,team_name,slot_no,captain_user_id,team_status,score,ready_at FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d ORDER BY slot_no ASC,id ASC",
        $gameId
    ), ARRAY_A) ?: array();
    $isChgk = function_exists('ckm_quiz_chgk_flow_is_active') && ckm_quiz_chgk_flow_is_active($game);
    $memberRows = array();
    $membersByTeam = array();
    $memberNameByUser = array();
    if ($isChgk && in_array($role, array('host','admin','participant'), true)) {
        $memberRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,game_id,team_id,user_id,member_role,display_name_snapshot,member_status,joined_at FROM " . ckm_quiz_members_table() . " WHERE game_id=%d AND member_status='active' ORDER BY team_id,id ASC",
            $gameId
        ), ARRAY_A) ?: array();
        foreach ($memberRows as $memberRow) {
            $tid = (int)$memberRow['team_id'];
            $uid = (int)$memberRow['user_id'];
            $publicMember = array(
                'id'=>(int)$memberRow['id'],
                'userId'=>$uid,
                'role'=>(string)$memberRow['member_role'],
                'displayName'=>(string)$memberRow['display_name_snapshot'],
                'joinedAt'=>(string)$memberRow['joined_at'],
            );
            $membersByTeam[$tid][] = $publicMember;
            $memberNameByUser[$uid] = (string)$memberRow['display_name_snapshot'];
        }
    }
    $speedRows = $wpdb->get_results($wpdb->prepare(
        "SELECT team_id,COALESCE(SUM(points_delta),0) speed_bonus FROM " . ckm_quiz_score_events_table() . " WHERE game_id=%d AND event_type='speed_bonus' GROUP BY team_id",
        $gameId
    ), ARRAY_A) ?: array();
    $speedByTeam = array();
    foreach ($speedRows as $row) $speedByTeam[(int)$row['team_id']] = (int)$row['speed_bonus'];
    $teamState = array();
    foreach ($teams as $team) {
        $teamId = (int)$team['id'];
        $submitted = 0;
        if ((int)$game['current_question_id'] > 0) {
            $submitted = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND team_id=%d AND question_id=%d",
                $gameId,
                $teamId,
                (int)$game['current_question_id']
            ));
        }
        $teamItem = array(
            'id'=>$teamId,
            'key'=>(string)$team['team_key'],
            'name'=>(string)$team['team_name'],
            'slot'=>(int)$team['slot_no'],
            'status'=>(string)$team['team_status'],
            'score'=>(int)$team['score'],
            'baseScore'=>(int)$team['score'] - (int)($speedByTeam[$teamId] ?? 0),
            'speedBonus'=>(int)($speedByTeam[$teamId] ?? 0),
            'ready'=>!empty($team['ready_at']),
            'answeredCurrentQuestion'=>$submitted > 0,
        );
        if ($isChgk && ($role === 'host' || $role === 'admin' || ($role === 'participant' && (int)($auth['team_id'] ?? 0) === $teamId))) {
            $teamItem['teamDeviceMode'] = true;
            if ($role === 'host' || $role === 'admin') $teamItem['members'] = array_values($membersByTeam[$teamId] ?? array());
        }
        $teamState[] = $teamItem;
    }

    $deadlineTs = ckm_quiz_mysql_timestamp((string)$game['question_deadline_at']);
    $round = null;
    if ((int)($game['current_round_id'] ?? 0) > 0) {
        $roundRow = ckm_quiz_get_round((int)$game['current_round_id']);
        if ($roundRow) $round = ckm_quiz_round_public($roundRow);
    }
    $question = null;
    $questionRow = null;
    if ((int)$game['current_question_id'] > 0) {
        $questionRow = ckm_quiz_get_question((int)$game['current_question_id']);
        if ($questionRow) {
            $privileged = $role === 'host' || $role === 'admin';
            $questionFlowForReveal = function_exists('ckm_quiz_chgk_question_flow_state') ? ckm_quiz_chgk_question_flow_state($game) : array();
            $isChgkReveal = !empty($questionFlowForReveal['enabled']);
            $isJeopardyFinal = function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($game);
            $jeopardyFinalRevealed = false;
            if ($isJeopardyFinal && function_exists('ckm_quiz_jeopardy_final_control_row')) {
                $finalControl = ckm_quiz_jeopardy_final_control_row($gameId, (int)$game['current_question_id'], false);
                $jeopardyFinalRevealed = $finalControl && in_array((string)($finalControl['status'] ?? ''), array('revealed','resolved'), true);
            }
            $reveal = $privileged || ($isJeopardyFinal ? $jeopardyFinalRevealed : ($isChgkReveal ? !empty($questionFlowForReveal['publicReveal']) : (string)$game['quiz_phase'] !== 'question_open'));
            $question = ckm_quiz_question_public($questionRow, $reveal, $privileged);
        }
    }
    $out = array(
        'serverTime'=>ckm_quiz_now_timestamp(),
        'game'=>array(
            'id'=>$gameId,
            'code'=>(string)$game['game_code'],
            'title'=>(string)$game['title'],
            'status'=>(string)$game['status'],
            'phase'=>(string)$game['quiz_phase'],
            'hostMode'=>(string)$game['host_mode_snapshot'],
            'judgeMode'=>(string)$game['judge_mode_snapshot'],
            'quizId'=>(int)$game['quiz_id'],
            'quizRevision'=>(int)$game['quiz_revision'],
            'formatKey'=>(string)($game['format_key_snapshot'] ?? CKM_EA_DEFAULT_GAME_FORMAT),
            'formatVersion'=>(int)($game['format_version_snapshot'] ?? 1),
            'formatContract'=>ckm_quiz_json_decode($game['format_contract_snapshot_json'] ?? ''),
            'roundPhase'=>(string)($game['round_phase'] ?? 'waiting'),
            'currentRoundId'=>(int)($game['current_round_id'] ?? 0),
            'currentRoundPosition'=>(int)($game['current_round_position'] ?? 0),
            'roundStartedAt'=>(string)($game['round_started_at'] ?? ''),
            'roundClosedAt'=>(string)($game['round_closed_at'] ?? ''),
            'questionPosition'=>(int)$game['current_question_position'],
            'questionCount'=>ckm_quiz_question_count((int)$game['quiz_id'], (int)$game['quiz_revision']),
            'questionStartedAt'=>(string)$game['question_started_at'],
            'questionDeadlineAt'=>(string)$game['question_deadline_at'],
            'questionDeadlineUnix'=>!empty($game['is_paused']) ? 0 : $deadlineTs,
            'secondsRemaining'=>!empty($game['is_paused']) ? max(0,(int)($game['paused_remaining_seconds'] ?? 0)) : ($deadlineTs > 0 ? max(0, $deadlineTs - ckm_quiz_now_timestamp()) : 0),
            'isPaused'=>!empty($game['is_paused']),
            'pausedAt'=>(string)($game['paused_at'] ?? ''),
            'pausedPhase'=>(string)($game['paused_format_phase'] ?? ''),
            'pausedRemainingSeconds'=>max(0,(int)($game['paused_remaining_seconds'] ?? 0)),
            'stateVersion'=>(int)$game['state_version'],
            'accessConsumed'=>!empty($game['access_consumed_at']),
            'testMode'=>ckm_quiz_game_is_test($game),
            'startAuthorized'=>!empty($game['auto_start']) || (string)($game['host_mode_snapshot'] ?? '') !== 'ai',
        ),
        'teams'=>$teamState,
        'round'=>$round,
        'question'=>$question,
        'formatRuntime'=>function_exists('ckm_quiz_runtime_state') ? ckm_quiz_runtime_state($game, $role, $questionRow) : array(),
        'questionFlow'=>function_exists('ckm_quiz_chgk_question_flow_state') ? ckm_quiz_chgk_question_flow_state($game) : array('enabled'=>false,'phase'=>(string)$game['quiz_phase']),
        'singleTeamDuel'=>function_exists('ckm_quiz_chgk_single_team_duel_state') ? ckm_quiz_chgk_single_team_duel_state($game) : array('enabled'=>false),
        'questionSelection'=>function_exists('ckm_quiz_chgk_question_selection_state') ? ckm_quiz_chgk_question_selection_state($game) : array('enabled'=>false,'mode'=>'sequential'),
        'jeopardyBoard'=>function_exists('ckm_quiz_jeopardy_board_state') ? ckm_quiz_jeopardy_board_state($game) : array('enabled'=>false),
    );
    if ((string)($game['format_key_snapshot'] ?? '') === 'jeopardy' && function_exists('ckm_quiz_jeopardy_result_summary')) {
        $out['resultSummary'] = ckm_quiz_jeopardy_result_summary($game, $teamState);
    }
    if ($isChgk && function_exists('ckm_quiz_chgk_lobby_state')) {
        $out['lobby'] = ckm_quiz_chgk_lobby_state($game, $auth, $teams);
    }
    if ($isChgk && function_exists('ckm_quiz_chgk_state_extension')) {
        $out = array_merge($out, ckm_quiz_chgk_state_extension($game, $auth));
    }
    if ($isChgk && (int)($game['current_question_id'] ?? 0) > 0 && function_exists('ckm_quiz_chgk_comparative_public')) {
        $out['comparativeAnalysis'] = ckm_quiz_chgk_comparative_public($game, (int)$game['current_question_id'], $auth);
    }
    if ($isChgk && (int)($game['current_question_id'] ?? 0) > 0 && function_exists('ckm_quiz_methodology_public')) {
        $out['methodologyAnalysis'] = ckm_quiz_methodology_public($game, (int)$game['current_question_id'], $auth);
    }
    if ($role === 'scoreboard' && function_exists('ckm_quiz_scoreboard_arbitration_summary')) {
        $out['publicArbitration'] = ckm_quiz_scoreboard_arbitration_summary($game);
    }
    if (function_exists('ckm_quiz_mechanic_rows_for_game') && function_exists('ckm_quiz_mechanic_public')) {
        if ($role === 'host' || $role === 'admin') {
            $out['mechanics'] = array_map('ckm_quiz_mechanic_public', ckm_quiz_mechanic_rows_for_game($gameId));
            $finalHidden = isset($out['formatRuntime']['finalRound']) && !empty($out['formatRuntime']['finalRound']['started']) && empty($out['formatRuntime']['finalRound']['revealed']);
            if ($finalHidden) {
                foreach ($out['mechanics'] as &$mechanicPublic) {
                    if ((string)($mechanicPublic['type'] ?? '') === 'jeopardy_final_wager') {
                        $mechanicPublic['value'] = 0;
                        $mechanicPublic['result'] = 0;
                        $mechanicPublic['payload'] = array('private'=>true,'submitted'=>true);
                    }
                }
                unset($mechanicPublic);
            }
        } elseif ($role === 'participant' && (int)($auth['team_id'] ?? 0) > 0) {
            $out['yourMechanics'] = array_map('ckm_quiz_mechanic_public', ckm_quiz_mechanic_rows_for_game($gameId, (int)$auth['team_id']));
        }
    }

    if ((string)($game['host_mode_snapshot'] ?? '') === 'human') {
        $liveUrl = ckm_quiz_core_live_room_url((string)($game['live_room'] ?? ''));
        if ($liveUrl !== '') $out['liveHost'] = array('provider'=>'jitsi','url'=>$liveUrl);
    }

    if ($role === 'participant') {
        $participantTeamId = (int)($auth['team_id'] ?? 0);
        $participantUserId = (int)($auth['user_id'] ?? 0);
        $participantTeam = $participantTeamId > 0 ? ckm_quiz_get_team($gameId, $participantTeamId) : null;
        $isCaptain = !$isChgk && $participantTeam && (int)($participantTeam['captain_user_id'] ?? 0) > 0 && (int)$participantTeam['captain_user_id'] === $participantUserId;
        $flow = $isChgk && function_exists('ckm_quiz_chgk_flow_settings') ? ckm_quiz_chgk_flow_settings($game) : array();
        $out['you'] = array(
            'userId'=>$participantUserId,
            'teamId'=>$participantTeamId,
            'memberRole'=>$isChgk ? 'team_device' : ($isCaptain ? 'captain' : (string)($auth['member_role'] ?? 'player')),
            'isCaptain'=>false,
            'canEditDraft'=>false,
            'canFinalize'=>$isChgk && $participantTeam && function_exists('ckm_quiz_chgk_member_can_finalize') ? ckm_quiz_chgk_member_can_finalize($game, $participantTeam, $auth) : false,
            'participationMode'=>(string)($flow['participationMode'] ?? ''),
            'finalizationMode'=>(string)($flow['finalizationMode'] ?? ''),
            'testPreview'=>!empty($auth['test_preview']),
            'guest'=>!empty($auth['guest']),
        );
        if ((int)$game['current_question_id'] > 0) {
            $own = ckm_quiz_get_answer($gameId, $participantTeamId, (int)$game['current_question_id']);
            $out['yourAnswer'] = $own ? ckm_quiz_answer_public($own, true) : null;
        }
    }

    if ($role === 'host' || $role === 'admin') {
        $answerRows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*,tm.team_name,tm.team_key FROM " . ckm_quiz_answers_table() . " a INNER JOIN " . ckm_quiz_teams_table() . " tm ON tm.id=a.team_id WHERE a.game_id=%d AND a.question_id=%d ORDER BY tm.slot_no ASC,a.id ASC",
            $gameId,
            (int)$game['current_question_id']
        ), ARRAY_A) ?: array();
        $finalHiddenForHost = isset($out['formatRuntime']['finalRound']) && !empty($out['formatRuntime']['finalRound']['started']) && empty($out['formatRuntime']['finalRound']['revealed']);
        $out['answers'] = array_map(function (array $row) use ($game, $questionRow, $finalHiddenForHost): array {
            $item = ckm_quiz_answer_public($row, !$finalHiddenForHost);
            $item['teamName'] = (string)$row['team_name'];
            $item['teamKey'] = (string)$row['team_key'];
            if ($finalHiddenForHost) $item['answerHidden'] = true;
            if ($questionRow && function_exists('ckm_quiz_runtime_arbitration_context')) {
                $item['arbitration'] = ckm_quiz_runtime_arbitration_context($game, $questionRow, $row);
            }
            return $item;
        }, $answerRows);
    }

    $events = ckm_quiz_events_after($gameId, $lastEventId);
    if ($role !== 'host' && $role !== 'admin') {
        foreach ($events as &$event) {
            $event['actor_user_id'] = 0;
            if ((string)$event['action'] === 'access_consumed') {
                $event['entity_id'] = 0;
                $event['payload'] = array('consumed'=>true);
            }
            if ((string)$event['entity_type'] === 'membership') $event['entity_id'] = 0;
            if (in_array((string)$event['action'], array('captain_assigned','captain_changed'), true)) $event['payload'] = array('changed'=>true);
            if (strpos((string)$event['action'], 'chgk_comparative_analysis_') === 0) $event['payload'] = array('status'=>str_replace('chgk_comparative_analysis_','',(string)$event['action']));
            if ((string)$event['action'] === 'jeopardy_ai_arbitration_completed' && empty($event['payload']['applied'])) {
                $event['payload'] = array('status'=>'pending_review','applied'=>false);
            }
            if ((string)$event['action'] === 'jeopardy_ai_arbitration_failed') {
                $event['payload'] = array('status'=>'failed');
            }
        }
        unset($event);
    }
    $out['events'] = $events;
    $out['lastEventId'] = $events ? (int)$events[count($events) - 1]['id'] : max(0, $lastEventId);
    $out['hasMoreEvents'] = count($events) >= 250;
    return $out;
}

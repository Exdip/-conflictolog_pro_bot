<?php
/**
 * Server runtime for the CKM format "Интеллектуальный батл".
 *
 * alpha.83.6 keeps the board + buzzer core, one hidden special mechanic
 * «Секретная передача» (cat_in_bag), AI/human arbitration and a fixed-value
 * final question. Risk wagers and «Торги за вопрос» were deliberately removed
 * from this format to keep the live ruleset simple.
 */

if (!defined('ABSPATH')) exit;

function ckm_quiz_jeopardy_is_active(array $game): bool {
    return function_exists('ckm_quiz_runtime_format_key') && ckm_quiz_runtime_format_key($game) === 'jeopardy';
}

function ckm_quiz_jeopardy_question_value(array $question): int {
    $value = (int)($question['jeopardy_value'] ?? 0);
    if ($value <= 0) $value = (int)($question['points'] ?? 0);
    return max(0, $value);
}

function ckm_quiz_jeopardy_special_type(array $question): string {
    $type = sanitize_key((string)($question['jeopardy_special_type'] ?? ''));
    return $type === 'cat_in_bag' ? $type : '';
}

function ckm_quiz_jeopardy_is_cat_question(array $question): bool {
    return ckm_quiz_jeopardy_special_type($question) === 'cat_in_bag';
}

function ckm_quiz_jeopardy_is_auction_question(array $question): bool {
    return ckm_quiz_jeopardy_special_type($question) === 'auction';
}

function ckm_quiz_jeopardy_normalize_category_key(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    // sanitize_key() intentionally accepts only ASCII [a-z0-9_-]. For Russian
    // category titles (for example "История" or "Наука") it returns an
    // empty string, which made a perfectly valid Jeopardy board fail runtime
    // validation. Preserve readable ASCII keys where possible and derive a
    // deterministic short key for any Unicode title otherwise.
    $ascii = sanitize_key($raw);
    if ($ascii !== '') return $ascii;

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    return 'cat_' . substr(hash('sha256', $normalized), 0, 20);
}

function ckm_quiz_jeopardy_category_key(array $question): string {
    $stored = ckm_quiz_jeopardy_normalize_category_key((string)($question['jeopardy_category_key'] ?? ''));
    if ($stored !== '') return $stored;
    return ckm_quiz_jeopardy_normalize_category_key((string)($question['jeopardy_category_title'] ?? ''));
}

function ckm_quiz_jeopardy_validate_quiz(int $quizId, int $revision, array $settings): array {
    global $wpdb;
    $values = array_values(array_unique(array_map('intval', (array)($settings['questionValues'] ?? array(100,200,300,400,500)))));
    $values = array_values(array_filter($values, static function(int $v): bool { return $v > 0; }));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,position,round_id,jeopardy_category_key,jeopardy_category_title,jeopardy_value,points,question_stage FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')='main' ORDER BY position ASC,id ASC",
        $quizId,
        $revision
    ), ARRAY_A) ?: array();
    if (!$rows) return ckm_quiz_error(409, 'Для «Интеллектуального батла» добавьте вопросы игрового поля.', 'jeopardy_questions_missing');
    $finalRows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,position,question_type FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND question_stage='final' ORDER BY position ASC,id ASC",
        $quizId, $revision
    ), ARRAY_A) ?: array();
    if (count($finalRows) > 1) return ckm_quiz_error(409, 'Для «Интеллектуального батла» допускается только один финальный вопрос.', 'jeopardy_final_multiple');
    if ($finalRows && sanitize_key((string)($finalRows[0]['question_type'] ?? 'text')) !== 'text') return ckm_quiz_error(409, 'Финальный вопрос «Интеллектуального батла» должен иметь текстовый тип ответа.', 'jeopardy_final_text_required');
    $seen = array();
    $categoriesByRound = array();
    foreach ($rows as $row) {
        $categoryKey = ckm_quiz_jeopardy_category_key($row);
        $categoryTitle = trim((string)($row['jeopardy_category_title'] ?? ''));
        $value = ckm_quiz_jeopardy_question_value($row);
        if ($categoryKey === '' || $categoryTitle === '') {
            return ckm_quiz_error(409, 'У вопроса №' . (int)$row['position'] . ' не заполнена категория «Интеллектуального батла».', 'jeopardy_category_missing');
        }
        if ($value <= 0 || ($values && !in_array($value, $values, true))) {
            return ckm_quiz_error(409, 'У вопроса №' . (int)$row['position'] . ' некорректный номинал. Используйте значения игрового поля.', 'jeopardy_value_invalid');
        }
        $roundId = (int)($row['round_id'] ?? 0);
        $cellKey = $roundId . ':' . $categoryKey . ':' . $value;
        if (isset($seen[$cellKey])) {
            return ckm_quiz_error(409, 'В одном раунде нельзя иметь две одинаковые ячейки «категория + номинал».', 'jeopardy_cell_duplicate');
        }
        $seen[$cellKey] = true;
        $categoriesByRound[$roundId][$categoryKey] = true;
    }
    $maxCategories = max(1, min(12, (int)($settings['categoryCount'] ?? 5)));
    foreach ($categoriesByRound as $roundId=>$cats) {
        if (count($cats) > $maxCategories) {
            return ckm_quiz_error(409, 'В раунде «Интеллектуального батла» больше категорий, чем разрешено настройками.', 'jeopardy_category_count_exceeded');
        }
    }
    return ckm_quiz_result(true, 200);
}

function ckm_quiz_jeopardy_board_rows(array $game): array {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT q.*,r.position round_position,r.title round_name,r.round_type FROM " . ckm_quiz_questions_table() . " q LEFT JOIN " . ckm_quiz_rounds_table() . " r ON r.id=q.round_id WHERE q.quiz_id=%d AND q.quiz_revision=%d AND q.status='active' AND COALESCE(q.question_stage,'main')='main' ORDER BY COALESCE(r.position,q.round_no),q.jeopardy_category_title,q.jeopardy_value,q.position,q.id",
        (int)$game['quiz_id'],
        (int)$game['quiz_revision']
    ), ARRAY_A) ?: array();
}

function ckm_quiz_jeopardy_cell_status_map(int $gameId): array {
    if ($gameId <= 0) return array();
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT question_id,status,team_id,id FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND mechanic_type='jeopardy_cell' ORDER BY id ASC",
        $gameId
    ), ARRAY_A) ?: array();
    $out = array();
    foreach ($rows as $row) $out[(int)$row['question_id']] = $row;
    return $out;
}

function ckm_quiz_jeopardy_round_has_available(array $game, int $roundId): bool {
    if ($roundId <= 0) return false;
    $states = ckm_quiz_jeopardy_cell_status_map((int)$game['id']);
    foreach (ckm_quiz_jeopardy_board_rows($game) as $row) {
        if ((int)($row['round_id'] ?? 0) !== $roundId) continue;
        $state = (string)($states[(int)$row['id']]['status'] ?? 'available');
        if (!in_array($state, array('selected','played'), true)) return true;
    }
    return false;
}

function ckm_quiz_jeopardy_selectable_round_id(array $game): int {
    $current = (int)($game['current_round_id'] ?? 0);
    if ((string)($game['round_phase'] ?? '') === 'open' && $current > 0 && ckm_quiz_jeopardy_round_has_available($game, $current)) return $current;
    $states = ckm_quiz_jeopardy_cell_status_map((int)$game['id']);
    foreach (ckm_quiz_jeopardy_board_rows($game) as $row) {
        $state = (string)($states[(int)$row['id']]['status'] ?? 'available');
        if (!in_array($state, array('selected','played'), true)) return (int)($row['round_id'] ?? 0);
    }
    return 0;
}

function ckm_quiz_jeopardy_board_state(array $game): array {
    if (!ckm_quiz_jeopardy_is_active($game)) return array('enabled'=>false);
    $states = ckm_quiz_jeopardy_cell_status_map((int)$game['id']);
    $rounds = array();
    foreach (ckm_quiz_jeopardy_board_rows($game) as $row) {
        $roundId = (int)($row['round_id'] ?? 0);
        $roundKey = (string)($row['round_key'] ?? ('round-' . (int)($row['round_no'] ?? 1)));
        if (!isset($rounds[$roundId])) {
            $rounds[$roundId] = array(
                'id'=>$roundId,
                'position'=>(int)($row['round_position'] ?? $row['round_no'] ?? 1),
                'title'=>(string)($row['round_name'] ?? $row['round_title'] ?? ''),
                'type'=>(string)($row['round_type'] ?? 'board'),
                'categories'=>array(),
            );
        }
        $catKey = ckm_quiz_jeopardy_category_key($row);
        if (!isset($rounds[$roundId]['categories'][$catKey])) {
            $rounds[$roundId]['categories'][$catKey] = array(
                'key'=>$catKey,
                'title'=>(string)($row['jeopardy_category_title'] ?? ''),
                'cells'=>array(),
            );
        }
        $mechanic = $states[(int)$row['id']] ?? null;
        $state = is_array($mechanic) ? (string)($mechanic['status'] ?? 'available') : 'available';
        if (!in_array($state, array('selected','played'), true)) $state = 'available';
        $rounds[$roundId]['categories'][$catKey]['cells'][] = array(
            'questionId'=>(int)$row['id'],
            'value'=>ckm_quiz_jeopardy_question_value($row),
            'state'=>$state,
            'selectedByTeamId'=>is_array($mechanic) ? (int)($mechanic['team_id'] ?? 0) : 0,
        );
    }
    foreach ($rounds as &$round) $round['categories'] = array_values($round['categories']);
    unset($round);
    return array(
        'enabled'=>true,
        'selectorTeamId'=>(int)($game['jeopardy_selector_team_id'] ?? 0),
        'selectedQuestionId'=>(int)($game['jeopardy_selected_question_id'] ?? 0),
        'selectableRoundId'=>ckm_quiz_jeopardy_selectable_round_id($game),
        'rounds'=>array_values($rounds),
    );
}

function ckm_quiz_jeopardy_cat_row(int $gameId, int $questionId, bool $forUpdate = false): ?array {
    if ($gameId <= 0 || $questionId <= 0) return null;
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='jeopardy_cat_in_bag' ORDER BY id DESC LIMIT 1{$lock}",
        $gameId, $questionId
    ), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_jeopardy_cat_state(array $game): array {
    if (!ckm_quiz_jeopardy_is_active($game)) return array('enabled'=>false);
    $questionId = (int)($game['jeopardy_selected_question_id'] ?? 0);
    if ($questionId <= 0 && (int)($game['current_question_id'] ?? 0) > 0) $questionId = (int)$game['current_question_id'];
    if ($questionId <= 0) return array('enabled'=>false);
    $question = ckm_quiz_get_question($questionId);
    if (!$question || !ckm_quiz_jeopardy_is_cat_question($question)) return array('enabled'=>false);
    $row = ckm_quiz_jeopardy_cat_row((int)$game['id'], $questionId, false);
    $payload = $row ? ckm_quiz_json_decode($row['payload_json'] ?? '') : array();
    $sourceTeamId = (int)($payload['sourceTeamId'] ?? ($game['jeopardy_selector_team_id'] ?? 0));
    $targetTeamId = $row ? (int)($row['team_id'] ?? 0) : 0;
    $status = $row ? (string)($row['status'] ?? 'assigned') : 'pending_target';
    if ($status === 'pending') $status = 'assigned';
    $phase = (string)($game['quiz_phase'] ?? '');
    $isCurrent = (int)($game['current_question_id'] ?? 0) === $questionId;
    $voiceTimerArmed = (string)($game['host_mode_snapshot'] ?? '') !== 'ai'
        || ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? '')) > 0;
    $open = (string)($game['status'] ?? '') === 'live'
        && $phase === 'question_open'
        && $isCurrent
        && $voiceTimerArmed
        && $targetTeamId > 0
        && $status === 'assigned';
    // The answering window may already be closed when AI/human arbitration
    // happens. Resolution must remain available until the assigned special
    // mechanic receives its final verdict.
    $resolvable = (string)($game['status'] ?? '') === 'live'
        && in_array($phase, array('question_open','question_closed'), true)
        && $isCurrent
        && ($phase === 'question_closed' || $voiceTimerArmed)
        && $targetTeamId > 0
        && $status === 'assigned';
    return array(
        'enabled'=>true,
        'questionId'=>$questionId,
        'status'=>$status,
        'sourceTeamId'=>$sourceTeamId,
        'targetTeamId'=>$targetTeamId,
        'open'=>$open,
        'resolvable'=>$resolvable,
        'value'=>ckm_quiz_jeopardy_question_value($question),
        'label'=>'Секретная передача',
    );
}

/** Legacy compatibility only: auctions are not part of the current ruleset. */
function ckm_quiz_jeopardy_auction_row(int $gameId, int $questionId, bool $forUpdate = false): ?array {
    return null;
}

function ckm_quiz_jeopardy_auction_state(array $game): array {
    return array('enabled'=>false,'removed'=>true);
}

function ckm_quiz_jeopardy_assign_auction(int $gameId, int $winnerTeamId, int $bid, array $auth): array {
    return ckm_quiz_error(410, 'Эта спецмеханика удалена из «Интеллектуального батла».', 'jeopardy_auction_removed');
}

function ckm_quiz_jeopardy_resolve_auction(int $gameId, string $decision, array $auth): array {
    return ckm_quiz_error(410, 'Эта спецмеханика удалена из «Интеллектуального батла».', 'jeopardy_auction_removed');
}

function ckm_quiz_jeopardy_runtime_state(array $game, string $role): array {
    return array(
        'key'=>'jeopardy',
        'runtime'=>'jeopardy_v1',
        'phase'=>(string)($game['quiz_phase'] ?? 'waiting'),
        'speedBonusEnabled'=>false,
        'negativeScoreEnabled'=>ckm_quiz_jeopardy_negative_score_enabled($game),
        'selectorTeamId'=>(int)($game['jeopardy_selector_team_id'] ?? 0),
        'selectedQuestionId'=>(int)($game['jeopardy_selected_question_id'] ?? 0),
        'board'=>ckm_quiz_jeopardy_board_state($game),
        'catInBag'=>ckm_quiz_jeopardy_cat_state($game),
        'auction'=>array('enabled'=>false,'removed'=>true),
        'finalRound'=>function_exists('ckm_quiz_jeopardy_final_state') ? ckm_quiz_jeopardy_final_state($game, $role) : array('enabled'=>false),
        'buzzer'=>ckm_quiz_jeopardy_buzzer_state($game),
        'wager'=>array('enabled'=>false,'open'=>false,'rows'=>array(),'removed'=>true),
        'aiArbitration'=>function_exists('ckm_quiz_jeopardy_ai_state') ? ckm_quiz_jeopardy_ai_state($game, $role) : array('enabled'=>false,'mode'=>'human','configured'=>false,'humanFallback'=>true),
        'host'=>array(
            'mode'=>(string)($game['host_mode_snapshot'] ?? 'human'),
            'aiConnector'=>function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured() ? 'ai_puffer' : 'unavailable',
            'liveAI'=>(string)($game['host_mode_snapshot'] ?? '') === 'ai' && function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured(),
        ),
        'specialMechanics'=>array('wagersRegistered'=>false,'wagersRuntime'=>'removed','catInBagRegistered'=>true,'catInBagRuntime'=>'active','auctionRegistered'=>false,'auctionRuntime'=>'removed','finalRoundRegistered'=>true,'finalRoundRuntime'=>'fixed_500'),
    );
}


function ckm_quiz_jeopardy_wager_limits(array $game): array {
    return array('min'=>0,'max'=>0);
}

/** Historical rows are readable so an already-started old room can settle cleanly. */
function ckm_quiz_jeopardy_wager_rows(int $gameId, int $questionId, int $teamId = 0): array {
    if ($gameId <= 0 || $questionId <= 0) return array();
    global $wpdb;
    if ($teamId > 0) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND team_id=%d AND mechanic_type='wager' ORDER BY id ASC",
            $gameId,$questionId,$teamId
        ),ARRAY_A) ?: array();
    }
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='wager' ORDER BY id ASC",
        $gameId,$questionId
    ),ARRAY_A) ?: array();
}

function ckm_quiz_jeopardy_wager_state(array $game): array {
    return array('enabled'=>false,'open'=>false,'questionId'=>(int)($game['current_question_id'] ?? 0),'min'=>0,'max'=>0,'rows'=>array(),'removed'=>true);
}

function ckm_quiz_jeopardy_place_wager(array $auth, int $amount): array {
    return ckm_quiz_error(410, 'Рискованные ставки убраны из «Интеллектуального батла».', 'jeopardy_wager_removed');
}

function ckm_quiz_jeopardy_settle_team_wager(int $gameId, int $questionId, int $teamId, string $outcome, string $actorType = 'host', int $actorUserId = 0): array {
    $rows=ckm_quiz_jeopardy_wager_rows($gameId,$questionId,$teamId);
    if (!$rows) return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'no_wager'));
    foreach ($rows as $row) {
        if ((string)($row['status'] ?? '')==='pending') return ckm_quiz_settle_wager($gameId,(int)$row['id'],$outcome,$actorType,$actorUserId);
    }
    return ckm_quiz_result(true,200,array('skipped'=>true,'reason'=>'already_settled'));
}

function ckm_quiz_jeopardy_negative_score_enabled(array $game): bool {
    if (!ckm_quiz_jeopardy_is_active($game)) return false;
    $settings = ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    if (array_key_exists('negativeScoreEnabled', $settings)) return !empty($settings['negativeScoreEnabled']);
    return false;
}

function ckm_quiz_jeopardy_wrong_answer_penalty(int $gameId, array $game, array $question, int $teamId, string $actorType, int $actorUserId, string $scopeKey): array {
    if (!ckm_quiz_jeopardy_negative_score_enabled($game)) return ckm_quiz_result(true, 200, array('skipped'=>true,'reason'=>'negative_score_disabled'));
    $value = ckm_quiz_jeopardy_question_value($question);
    if ($value <= 0 || !function_exists('ckm_quiz_apply_penalty')) return ckm_quiz_result(true, 200, array('skipped'=>true,'reason'=>'question_value_missing'));
    return ckm_quiz_apply_penalty(
        $gameId,
        $teamId,
        $value,
        'Интеллектуальный батл: штраф за неверный ответ',
        $actorType,
        $actorUserId,
        'jeopardy-wrong-' . sanitize_key($scopeKey) . '-' . (int)$question['id'] . '-' . $teamId,
        array('round_id'=>(int)($game['current_round_id'] ?? 0),'question_id'=>(int)$question['id'])
    );
}

function ckm_quiz_jeopardy_buzzer_seconds(array $game): int {
    // alpha.81: buzzer timing is a per-quiz format setting and is frozen into
    // the game snapshot. Keep the old contract fallback for rooms created by
    // earlier builds.
    $settings = ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    $seconds = (int)($settings['buzzerSeconds'] ?? 0);
    if ($seconds <= 0) {
        $contract = ckm_quiz_json_decode($game['format_contract_snapshot_json'] ?? '');
        $seconds = (int)($contract['rules']['buzzerSeconds'] ?? 15);
    }
    return max(5, min(60, $seconds > 0 ? $seconds : 15));
}

function ckm_quiz_jeopardy_buzzer_rows(int $gameId, int $questionId, bool $forUpdate = false): array {
    if ($gameId <= 0 || $questionId <= 0) return array();
    global $wpdb;
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='jeopardy_buzz' ORDER BY id ASC{$lock}",
        $gameId, $questionId
    ), ARRAY_A) ?: array();
}

function ckm_quiz_jeopardy_buzzer_state(array $game): array {
    if (!ckm_quiz_jeopardy_is_active($game)) return array('enabled'=>false);
    if (function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($game)) return array('enabled'=>false,'questionId'=>(int)($game['current_question_id'] ?? 0),'seconds'=>0,'active'=>null,'blockedTeamIds'=>array(),'open'=>false,'resolvable'=>false,'specialMode'=>'final');
    $questionId = (int)($game['current_question_id'] ?? 0);
    $isLive = (string)($game['status'] ?? '') === 'live';
    $phase = (string)($game['quiz_phase'] ?? '');
    $voiceTimerArmed = (string)($game['host_mode_snapshot'] ?? '') !== 'ai'
        || ckm_quiz_mysql_timestamp((string)($game['question_deadline_at'] ?? '')) > 0;
    $open = $isLive && $phase === 'question_open' && $questionId > 0 && $voiceTimerArmed;
    $closed = $isLive && $phase === 'question_closed' && $questionId > 0;
    $auction = ckm_quiz_jeopardy_auction_state($game);
    if (($open || $closed) && !empty($auction['enabled']) && (string)($auction['status'] ?? '') === 'assigned' && (int)($auction['questionId'] ?? 0) === $questionId) {
        return array('enabled'=>false,'questionId'=>$questionId,'seconds'=>0,'active'=>null,'blockedTeamIds'=>array(),'open'=>false,'resolvable'=>false,'specialMode'=>'auction');
    }
    $cat = ckm_quiz_jeopardy_cat_state($game);
    if (($open || $closed) && !empty($cat['enabled']) && (string)($cat['status'] ?? '') === 'assigned' && (int)($cat['questionId'] ?? 0) === $questionId) {
        return array('enabled'=>false,'questionId'=>$questionId,'seconds'=>0,'active'=>null,'blockedTeamIds'=>array(),'open'=>false,'resolvable'=>false,'specialMode'=>'cat_in_bag');
    }
    $seconds = ckm_quiz_jeopardy_buzzer_seconds($game);
    $rows = $questionId > 0 ? ckm_quiz_jeopardy_buzzer_rows((int)$game['id'], $questionId, false) : array();
    $now = ckm_quiz_now_timestamp();
    $active = null; $blocked = array(); $resolvable = false;
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? '');
        $payload = ckm_quiz_json_decode($row['payload_json'] ?? '');
        $expiresAt = (int)($payload['expiresAt'] ?? 0);
        $teamId = (int)($row['team_id'] ?? 0);
        $answer = ($status === 'claimed' && $teamId > 0 && $questionId > 0)
            ? ckm_quiz_get_answer((int)$game['id'], $teamId, $questionId, false)
            : null;
        $pendingAnswer = $answer && (string)($answer['verdict'] ?? 'pending') === 'pending';

        // alpha.83.5: once a team submitted its answer while it owned the buzzer,
        // expiry of the short answer window must not erase the host's ability to
        // resolve that already-saved answer. This remains true after the main
        // question timer closes in hybrid AI/human arbitration.
        if ($status === 'claimed' && $pendingAnswer) {
            $active = array(
                'id'=>(int)$row['id'],
                'teamId'=>$teamId,
                'status'=>'claimed',
                'claimedAt'=>(string)$row['created_at'],
                'expiresAt'=>$expiresAt,
                'secondsRemaining'=>$open && $expiresAt > 0 ? max(0, $expiresAt - $now) : 0,
                'hasAnswer'=>true,
                'answerId'=>(int)($answer['id'] ?? 0),
                'postClose'=>$closed,
            );
            $resolvable = true;
            continue;
        }

        if ($status === 'claimed' && $expiresAt > 0 && $expiresAt <= $now) $status = 'expired';
        if (in_array($status, array('rejected','expired'), true)) $blocked[] = $teamId;
        if ($status === 'claimed' && !$active && $open) {
            $active = array(
                'id'=>(int)$row['id'],
                'teamId'=>$teamId,
                'status'=>'claimed',
                'claimedAt'=>(string)$row['created_at'],
                'expiresAt'=>$expiresAt,
                'secondsRemaining'=>$expiresAt > 0 ? max(0, $expiresAt - $now) : $seconds,
                'hasAnswer'=>false,
                'answerId'=>0,
                'postClose'=>false,
            );
            $resolvable = true; // host may reject/reopen even before an answer arrives.
        }
    }
    $enabled = $open || ($closed && $resolvable);
    return array(
        'enabled'=>$enabled,
        'questionId'=>$questionId,
        'seconds'=>$seconds,
        'active'=>$active,
        'blockedTeamIds'=>array_values(array_unique(array_filter($blocked))),
        'open'=>$open && !$active,
        'resolvable'=>$resolvable,
        'postClose'=>$closed && $resolvable,
    );
}

function ckm_quiz_jeopardy_claim_buzzer(array $auth): array {
    $gameId = (int)($auth['game_id'] ?? 0);
    $teamId = (int)($auth['team_id'] ?? 0);
    $userId = (int)($auth['user_id'] ?? 0);
    if ($gameId <= 0 || $teamId <= 0 || $userId <= 0) return ckm_quiz_error(401, 'Сессия участника недействительна.', 'participant_session_invalid');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Кнопка доступна только в «Интеллектуальном батле».', 'jeopardy_required'); }
        if ((string)$game['status'] !== 'live' || (string)$game['quiz_phase'] !== 'question_open' || (int)$game['current_question_id'] <= 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сейчас нет открытого вопроса.', 'question_not_open'); }
        $auction = ckm_quiz_jeopardy_auction_state($game);
        if (!empty($auction['enabled']) && (string)($auction['status'] ?? '') === 'assigned' && (int)($auction['questionId'] ?? 0) === (int)$game['current_question_id']) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Для «Торгов за вопрос» кнопка ответа отключена: отвечает победитель торгов.', 'jeopardy_auction_buzzer_disabled'); }
        $cat = ckm_quiz_jeopardy_cat_state($game);
        if (!empty($cat['enabled']) && (string)($cat['status'] ?? '') === 'assigned' && (int)($cat['questionId'] ?? 0) === (int)$game['current_question_id']) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Для «Секретной передачи» кнопка ответа отключена: отвечает назначенная команда.', 'jeopardy_cat_buzzer_disabled'); }
        $member = ckm_quiz_get_membership($gameId, $userId, true);
        if (!$member || (int)$member['team_id'] !== $teamId) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(403, 'Вы не состоите в этой команде.', 'foreign_game_or_team'); }
        $questionId = (int)$game['current_question_id'];
        $rows = ckm_quiz_jeopardy_buzzer_rows($gameId, $questionId, true);
        $nowTs = ckm_quiz_now_timestamp(); $now = ckm_quiz_now_mysql();
        foreach ($rows as $row) {
            $payload = ckm_quiz_json_decode($row['payload_json'] ?? '');
            $expiresAt = (int)($payload['expiresAt'] ?? 0);
            if ((string)$row['status'] === 'claimed' && $expiresAt > 0 && $expiresAt <= $nowTs) {
                $wpdb->update(ckm_quiz_mechanics_table(), array('status'=>'expired','resolved_at'=>$now,'updated_at'=>$now), array('id'=>(int)$row['id']));
                ckm_quiz_append_event($gameId,'jeopardy_buzz_expired','system',0,(int)$row['team_id'],$questionId,'game_mechanic',(int)$row['id'],array('teamId'=>(int)$row['team_id']),'jeopardy-buzz-expired-' . (int)$row['id']);
                $row['status']='expired';
            }
            if ((int)$row['team_id'] === $teamId && in_array((string)$row['status'], array('rejected','expired'), true)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Эта команда уже потеряла право ответа на текущий вопрос.', 'jeopardy_buzz_team_blocked'); }
            if ((string)$row['status'] === 'claimed') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Кнопку уже нажала другая команда.', 'jeopardy_buzz_locked'); }
        }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда не найдена.', 'team_not_found'); }
        $expiresAt = $nowTs + ckm_quiz_jeopardy_buzzer_seconds($game);
        $created = ckm_quiz_mechanic_create_locked($game,$team,'jeopardy_buzz',0,'participant',$userId,'jeopardy-buzz-' . $questionId . '-' . $teamId . '-' . ckm_quiz_random_token(8),array('round_id'=>(int)$game['current_round_id'],'question_id'=>$questionId),array('mechanicKey'=>'jeopardy_buzz','expiresAt'=>$expiresAt));
        if (empty($created['ok'])) { $wpdb->query('ROLLBACK'); return $created; }
        $mechanic = $created['mechanic'];
        $wpdb->update(ckm_quiz_mechanics_table(), array('status'=>'claimed','updated_at'=>$now), array('id'=>(int)$mechanic['id']));
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_buzz_claimed','participant',$userId,$teamId,$questionId,'game_mechanic',(int)$mechanic['id'],array('teamId'=>$teamId,'expiresAt'=>$expiresAt),'jeopardy-buzz-claimed-' . (int)$mechanic['id']);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_buzz_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Jeopardy buzzer failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось зафиксировать нажатие кнопки.', 'jeopardy_buzz_failed');
    }
    $fresh = ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true, 200, array('buzzer'=>ckm_quiz_jeopardy_buzzer_state($fresh)));
}

function ckm_quiz_jeopardy_resolve_buzzer(int $gameId, string $decision, array $auth): array {
    $decision = sanitize_key($decision);
    if (!in_array($decision, array('accepted','rejected'), true)) return ckm_quiz_error(422, 'Укажите результат ответа.', 'jeopardy_buzz_decision_invalid');
    $actorType = sanitize_key((string)($auth['actor_type'] ?? 'host'));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Действие доступно только в «Интеллектуальном батле».', 'jeopardy_required'); }
        $questionId = (int)($game['current_question_id'] ?? 0);
        $rows = ckm_quiz_jeopardy_buzzer_rows($gameId, $questionId, true);
        $active = null;
        foreach ($rows as $row) if ((string)$row['status'] === 'claimed') { $active=$row; break; }
        if (!$active) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Нет команды, зафиксировавшей право ответа.', 'jeopardy_buzz_missing'); }
        $now = ckm_quiz_now_mysql();
        $wpdb->update(ckm_quiz_mechanics_table(), array('status'=>$decision,'resolved_at'=>$now,'updated_at'=>$now), array('id'=>(int)$active['id']));
        $teamId=(int)$active['team_id'];
        if ($decision === 'accepted') $wpdb->update(ckm_quiz_games_table(), array('jeopardy_selector_team_id'=>$teamId,'updated_at'=>$now), array('id'=>$gameId));

        // The buzzer host decision is authoritative for the board-format answer.
        // Mark a submitted answer as resolved with 0 answer-points so the generic
        // close-question auto scorer cannot award the nominal a second time. The
        // nominal itself is recorded below as the dedicated Jeopardy bonus event.
        $submittedAnswer = ckm_quiz_get_answer($gameId, $teamId, $questionId, true);
        if ($submittedAnswer && (string)($submittedAnswer['verdict'] ?? 'pending') === 'pending') {
            $questionForVerdict = ckm_quiz_get_question($questionId);
            if (!$questionForVerdict) throw new RuntimeException('jeopardy_question_missing');
            $answerDecision = ckm_quiz_apply_score_locked(
                $game, $questionForVerdict, $submittedAnswer, 0,
                $decision === 'accepted' ? 'accepted' : 'rejected',
                $decision === 'accepted' ? 'Интеллектуальный батл: ответ принят ведущим' : 'Интеллектуальный батл: ответ отклонён ведущим',
                $actorType, $actorUserId,
                'jeopardy-buzz-answer-' . (int)$submittedAnswer['id'] . '-' . $decision
            );
            if (empty($answerDecision['ok'])) throw new RuntimeException((string)($answerDecision['code'] ?? 'jeopardy_answer_resolve_failed'));
        }

        $eventId = ckm_quiz_append_event($gameId,'jeopardy_buzz_' . $decision,$actorType,$actorUserId,$teamId,$questionId,'game_mechanic',(int)$active['id'],array('teamId'=>$teamId,'decision'=>$decision),'jeopardy-buzz-resolve-' . (int)$active['id']);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_buzz_resolve_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return ckm_quiz_error(500, 'Не удалось обработать ответ после кнопки.', 'jeopardy_buzz_resolve_failed');
    }
    $wagerSettlement = ckm_quiz_jeopardy_settle_team_wager(
        $gameId, $questionId, $teamId, $decision === 'accepted' ? 'won' : 'lost', $actorType, $actorUserId
    );
    if (empty($wagerSettlement['ok'])) return $wagerSettlement;
    $wrongPenalty = null;
    if ($decision === 'rejected') {
        $question = ckm_quiz_get_question($questionId);
        if ($question) {
            $wrongPenalty = ckm_quiz_jeopardy_wrong_answer_penalty($gameId, $game, $question, $teamId, $actorType, $actorUserId, 'buzz');
            if (empty($wrongPenalty['ok'])) return $wrongPenalty;
        }
    }

    if ($decision === 'accepted') {
        $question = ckm_quiz_get_question($questionId);
        $value = $question ? ckm_quiz_jeopardy_question_value($question) : 0;
        if ($value > 0 && function_exists('ckm_quiz_apply_bonus')) {
            $score = ckm_quiz_apply_bonus($gameId,$teamId,$value,'Интеллектуальный батл: правильный ответ после кнопки',$actorType,$actorUserId,'jeopardy-buzz-score-' . $questionId . '-' . $teamId,array('round_id'=>(int)($game['current_round_id'] ?? 0),'question_id'=>$questionId));
            if (empty($score['ok'])) return $score;
        }
        $closed = ckm_quiz_close_current_question($gameId,$actorType,$actorUserId);
        if (empty($closed['ok'])) return $closed;
    }
    $fresh = ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true, 200, array('decision'=>$decision,'buzzer'=>ckm_quiz_jeopardy_buzzer_state($fresh),'wager'=>ckm_quiz_jeopardy_wager_state($fresh),'wagerSettlement'=>$wagerSettlement,'wrongAnswerPenalty'=>$wrongPenalty));
}

function ckm_quiz_jeopardy_authorize_answer(array $game, int $teamId): array {
    if (!ckm_quiz_jeopardy_is_active($game)) return ckm_quiz_result(true, 200);
    if (function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($game)) {
        $qid=(int)($game['current_question_id'] ?? 0);
        $control=function_exists('ckm_quiz_jeopardy_final_control_row') ? ckm_quiz_jeopardy_final_control_row((int)$game['id'],$qid,false) : null;
        if (!$control || (string)($control['status'] ?? '') !== 'open') return ckm_quiz_error(409, 'Приём финальных ответов завершён.', 'jeopardy_final_answers_closed');
        return ckm_quiz_result(true, 200);
    }
    $auction = ckm_quiz_jeopardy_auction_state($game);
    if (!empty($auction['enabled']) && (string)($auction['status'] ?? '') === 'assigned' && (int)($auction['questionId'] ?? 0) === (int)($game['current_question_id'] ?? 0)) {
        if ((int)($auction['winnerTeamId'] ?? 0) !== $teamId) return ckm_quiz_error(403, 'Право ответа принадлежит победителю торгов.', 'jeopardy_auction_owned_by_other_team');
        return ckm_quiz_result(true, 200);
    }
    $cat = ckm_quiz_jeopardy_cat_state($game);
    if (!empty($cat['enabled']) && (string)($cat['status'] ?? '') === 'assigned' && (int)($cat['questionId'] ?? 0) === (int)($game['current_question_id'] ?? 0)) {
        if ((int)($cat['targetTeamId'] ?? 0) !== $teamId) return ckm_quiz_error(403, 'Этот спецвопрос передан другой команде.', 'jeopardy_cat_owned_by_other_team');
        return ckm_quiz_result(true, 200);
    }
    $buzzer = ckm_quiz_jeopardy_buzzer_state($game);
    if (empty($buzzer['enabled'])) return ckm_quiz_result(true, 200);
    $active = isset($buzzer['active']) && is_array($buzzer['active']) ? $buzzer['active'] : null;
    if (!$active) return ckm_quiz_error(409, 'Сначала нажмите кнопку ответа.', 'jeopardy_buzz_required');
    if ((int)($active['teamId'] ?? 0) !== $teamId) return ckm_quiz_error(403, 'Право ответа сейчас у другой команды.', 'jeopardy_buzz_owned_by_other_team');
    if ((int)($active['secondsRemaining'] ?? 0) <= 0) return ckm_quiz_error(409, 'Время ответа после кнопки истекло.', 'jeopardy_buzz_expired');
    return ckm_quiz_result(true, 200);
}

function ckm_quiz_jeopardy_selected_question(array $game): ?array {
    if (!ckm_quiz_jeopardy_is_active($game)) return null;
    $questionId = (int)($game['jeopardy_selected_question_id'] ?? 0);
    if ($questionId <= 0) return null;
    $question = ckm_quiz_get_question($questionId);
    if (!$question || (int)$question['quiz_id'] !== (int)$game['quiz_id'] || (int)$question['quiz_revision'] !== (int)$game['quiz_revision']) return null;
    return $question;
}

function ckm_quiz_jeopardy_select_cell(int $gameId, int $teamId, int $questionId, array $auth): array {
    if ($gameId <= 0 || $teamId <= 0 || $questionId <= 0) return ckm_quiz_error(422, 'Укажите команду и ячейку игрового поля.', 'jeopardy_selection_missing');
    $authRole = sanitize_key((string)($auth['role'] ?? ''));
    $actorType = $authRole === 'participant' ? 'participant' : sanitize_key((string)($auth['actor_type'] ?? 'host'));
    if (!in_array($actorType, array('participant','host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Это действие доступно только в «Интеллектуальном батле».', 'jeopardy_required'); }
        if ((string)$game['status'] === 'finished') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Игра завершена.', 'game_finished'); }
        if (function_exists('ckm_quiz_jeopardy_final_question') && function_exists('ckm_quiz_jeopardy_final_control_row')) {
            $finalQuestion = ckm_quiz_jeopardy_final_question($game);
            if ($finalQuestion && ckm_quiz_jeopardy_final_control_row($gameId, (int)$finalQuestion['id'], true)) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Финальный раунд уже начат: игровое поле заблокировано.', 'jeopardy_final_active');
            }
        }
        if ((string)$game['quiz_phase'] === 'question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала завершите текущий вопрос.', 'question_already_open'); }
        if ((string)$game['quiz_phase'] === 'question_closed' && (int)($game['current_question_id'] ?? 0) > 0) {
            $pending = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d AND verdict='pending'", $gameId, (int)$game['current_question_id']));
            if ($pending > 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала завершите оценку ответов текущего вопроса.', 'jeopardy_scoring_pending'); }
        }
        if ((int)($game['jeopardy_selected_question_id'] ?? 0) > 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Ячейка уже выбрана и ожидает запуска.', 'jeopardy_cell_already_selected'); }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team || (string)($team['team_status'] ?? '') !== 'active') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда не найдена.', 'team_not_found'); }
        $selector = (int)($game['jeopardy_selector_team_id'] ?? 0);
        if ($selector > 0 && $selector !== $teamId) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(403, 'Сейчас вопрос выбирает другая команда.', 'jeopardy_not_selector'); }
        $question = ckm_quiz_get_question($questionId);
        if (!$question || (int)$question['quiz_id'] !== (int)$game['quiz_id'] || (int)$question['quiz_revision'] !== (int)$game['quiz_revision'] || (string)($question['status'] ?? '') !== 'active' || (string)($question['question_stage'] ?? 'main') !== 'main') {
            $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Ячейка игрового поля не найдена.', 'jeopardy_cell_not_found');
        }
        if (ckm_quiz_jeopardy_category_key($question) === '' || ckm_quiz_jeopardy_question_value($question) <= 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'У вопроса не заполнены категория или номинал.', 'jeopardy_cell_invalid'); }
        $expectedRoundId = ckm_quiz_jeopardy_selectable_round_id($game);
        if ($expectedRoundId > 0 && (int)($question['round_id'] ?? 0) !== $expectedRoundId) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала сыграйте оставшиеся ячейки текущего раунда.', 'jeopardy_wrong_round'); }
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='jeopardy_cell' LIMIT 1 FOR UPDATE",
            $gameId, $questionId
        ), ARRAY_A);
        if ($existing && in_array((string)($existing['status'] ?? ''), array('selected','played'), true)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Эта ячейка уже выбрана или сыграна.', 'jeopardy_cell_unavailable'); }
        $requestId = 'jeopardy-cell-' . $questionId;
        $created = ckm_quiz_mechanic_create_locked($game, $team, 'jeopardy_cell', ckm_quiz_jeopardy_question_value($question), $actorType, $actorUserId, $requestId, array('round_id'=>(int)($question['round_id'] ?? 0),'question_id'=>$questionId), array(
            'mechanicKey'=>'jeopardy_cell_' . $questionId,
            'categoryKey'=>ckm_quiz_jeopardy_category_key($question),
            'categoryTitle'=>(string)($question['jeopardy_category_title'] ?? ''),
            'value'=>ckm_quiz_jeopardy_question_value($question),
            'specialType'=>ckm_quiz_jeopardy_special_type($question),
        ));
        if (empty($created['ok'])) { $wpdb->query('ROLLBACK'); return $created; }
        $mechanic = $created['mechanic'];
        $now = ckm_quiz_now_mysql();
        $wpdb->update(ckm_quiz_mechanics_table(), array('status'=>'selected','updated_at'=>$now), array('id'=>(int)$mechanic['id']));
        $gameUpdate = array('jeopardy_selected_question_id'=>$questionId,'updated_at'=>$now);
        if ($selector <= 0) $gameUpdate['jeopardy_selector_team_id'] = $teamId;
        if ($wpdb->update(ckm_quiz_games_table(), $gameUpdate, array('id'=>$gameId)) === false) throw new RuntimeException('jeopardy_game_selection_update_failed');
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_cell_selected',$actorType,$actorUserId,$teamId,$questionId,'game_mechanic',(int)$mechanic['id'],array(
            'teamId'=>$teamId,
            'categoryKey'=>ckm_quiz_jeopardy_category_key($question),
            'categoryTitle'=>(string)($question['jeopardy_category_title'] ?? ''),
            'value'=>ckm_quiz_jeopardy_question_value($question),
            'roundId'=>(int)($question['round_id'] ?? 0),
            'specialType'=>ckm_quiz_jeopardy_special_type($question),
        ),'jeopardy-cell-selected-' . $questionId);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_cell_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Jeopardy select failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось выбрать ячейку «Интеллектуального батла».', 'jeopardy_select_failed');
    }
    $fresh = ckm_quiz_get_game($gameId);
    return ckm_quiz_result(true, 200, array('game'=>$fresh,'question'=>$question,'board'=>$fresh ? ckm_quiz_jeopardy_board_state($fresh) : array()));
}

function ckm_quiz_jeopardy_set_selector(int $gameId, int $teamId, array $auth): array {
    if ($gameId <= 0 || $teamId <= 0) return ckm_quiz_error(422, 'Укажите команду.', 'jeopardy_selector_missing');
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Это действие доступно только в «Интеллектуальном батле».', 'jeopardy_required'); }
        if (function_exists('ckm_quiz_jeopardy_final_question') && function_exists('ckm_quiz_jeopardy_final_control_row')) {
            $finalQuestion = ckm_quiz_jeopardy_final_question($game);
            if ($finalQuestion && ckm_quiz_jeopardy_final_control_row($gameId, (int)$finalQuestion['id'], true)) {
                $wpdb->query('ROLLBACK');
                return ckm_quiz_error(409, 'Во время финального раунда право выбора игрового поля зафиксировано.', 'jeopardy_final_selector_locked');
            }
        }
        if ((string)($game['quiz_phase'] ?? '') === 'question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Нельзя передавать право выбора во время открытого вопроса.', 'jeopardy_selector_locked'); }
        $auctionPending = ckm_quiz_jeopardy_auction_state($game);
        if (!empty($auctionPending['enabled']) && (string)($auctionPending['status'] ?? '') === 'pending_bid') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала завершите «Торги за вопрос».', 'jeopardy_auction_required'); }
        $catPending = ckm_quiz_jeopardy_cat_state($game);
        if (!empty($catPending['enabled']) && (string)($catPending['status'] ?? '') === 'pending_target') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала назначьте команду для спецвопроса «Секретная передача».', 'jeopardy_cat_target_required'); }
        $team = ckm_quiz_get_team($gameId, $teamId, true);
        if (!$team) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда не найдена.', 'team_not_found'); }
        $now = ckm_quiz_now_mysql();
        if ($wpdb->update(ckm_quiz_games_table(), array('jeopardy_selector_team_id'=>$teamId,'updated_at'=>$now), array('id'=>$gameId)) === false) throw new RuntimeException('selector_update_failed');
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_selector_changed','host',(int)($auth['user_id'] ?? 0),$teamId,0,'team',$teamId,array('teamId'=>$teamId),'jeopardy-selector-' . $teamId . '-' . ckm_quiz_now_timestamp());
        if ($eventId <= 0) throw new RuntimeException('selector_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return ckm_quiz_error(500, 'Не удалось передать право выбора.', 'jeopardy_selector_update_failed');
    }
    return ckm_quiz_result(true,200,array('game'=>ckm_quiz_get_game($gameId)));
}

function ckm_quiz_jeopardy_assign_cat_target(int $gameId, int $targetTeamId, array $auth): array {
    if ($gameId <= 0 || $targetTeamId <= 0) return ckm_quiz_error(422, 'Укажите команду, которой передаётся спецвопрос.', 'jeopardy_cat_target_missing');
    $authRole = sanitize_key((string)($auth['role'] ?? ''));
    $actorType = $authRole === 'participant' ? 'participant' : sanitize_key((string)($auth['actor_type'] ?? 'host'));
    if (!in_array($actorType, array('participant','host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Действие доступно только в «Интеллектуальном батле».', 'jeopardy_required'); }
        if ((string)($game['quiz_phase'] ?? '') === 'question_open') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Спецвопрос уже открыт.', 'jeopardy_cat_already_open'); }
        $questionId = (int)($game['jeopardy_selected_question_id'] ?? 0);
        $question = $questionId > 0 ? ckm_quiz_get_question($questionId) : null;
        if (!$question || !ckm_quiz_jeopardy_is_cat_question($question)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Выбранная ячейка не является спецвопросом «Секретная передача».', 'jeopardy_cat_not_selected'); }
        $sourceTeamId = (int)($game['jeopardy_selector_team_id'] ?? 0);
        if ($sourceTeamId <= 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Не определена команда, выбравшая спецвопрос.', 'jeopardy_cat_source_missing'); }
        if ($authRole === 'participant' && (int)($auth['team_id'] ?? 0) !== $sourceTeamId) {
            $wpdb->query('ROLLBACK');
            return ckm_quiz_error(403, 'Только команда, выбравшая «Секретную передачу», может назначить получателя.', 'jeopardy_cat_not_source_team');
        }
        if ($targetTeamId === $sourceTeamId) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(422, 'Спецвопрос нужно передать другой команде.', 'jeopardy_cat_same_team'); }
        $target = ckm_quiz_get_team($gameId, $targetTeamId, true);
        if (!$target || (string)($target['team_status'] ?? '') !== 'active') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(404, 'Команда-получатель не найдена.', 'team_not_found'); }
        $existing = ckm_quiz_jeopardy_cat_row($gameId, $questionId, true);
        if ($existing) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Команда для этого спецвопроса уже назначена.', 'jeopardy_cat_target_locked'); }
        $created = ckm_quiz_mechanic_create_locked(
            $game, $target, 'jeopardy_cat_in_bag', ckm_quiz_jeopardy_question_value($question),
            $actorType, $actorUserId, 'jeopardy-cat-' . $questionId,
            array('round_id'=>(int)($question['round_id'] ?? 0),'question_id'=>$questionId),
            array('mechanicKey'=>'jeopardy_cat_in_bag','sourceTeamId'=>$sourceTeamId,'targetTeamId'=>$targetTeamId,'specialType'=>'cat_in_bag')
        );
        if (empty($created['ok'])) { $wpdb->query('ROLLBACK'); return $created; }
        $mechanic = $created['mechanic'];
        $now = ckm_quiz_now_mysql();
        if ($wpdb->update(ckm_quiz_mechanics_table(), array('status'=>'assigned','updated_at'=>$now), array('id'=>(int)$mechanic['id'])) === false) throw new RuntimeException('jeopardy_cat_status_failed');
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_cat_assigned',$actorType,$actorUserId,$targetTeamId,$questionId,'game_mechanic',(int)$mechanic['id'],array(
            'sourceTeamId'=>$sourceTeamId,'targetTeamId'=>$targetTeamId,'value'=>ckm_quiz_jeopardy_question_value($question)
        ),'jeopardy-cat-assigned-' . $questionId);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_cat_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Jeopardy cat assign failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось передать спецвопрос другой команде.', 'jeopardy_cat_assign_failed');
    }
    $fresh = ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true, 200, array('catInBag'=>ckm_quiz_jeopardy_cat_state($fresh)));
}

function ckm_quiz_jeopardy_resolve_cat(int $gameId, string $decision, array $auth): array {
    $decision = sanitize_key($decision);
    if (!in_array($decision, array('accepted','rejected'), true)) return ckm_quiz_error(422, 'Укажите результат ответа.', 'jeopardy_cat_decision_invalid');
    $actorType = sanitize_key((string)($auth['actor_type'] ?? 'host'));
    if (!in_array($actorType, array('host','ai_host'), true)) $actorType = 'host';
    $actorUserId = $actorType === 'ai_host' ? 0 : (int)($auth['user_id'] ?? 0);
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || !ckm_quiz_jeopardy_is_active($game)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Действие доступно только в «Интеллектуальном батле».', 'jeopardy_required'); }
        $questionId = (int)($game['current_question_id'] ?? 0);
        $phase = (string)($game['quiz_phase'] ?? '');
        if (!in_array($phase, array('question_open','question_closed'), true) || $questionId <= 0) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Текущий спецвопрос уже нельзя оценить.', 'jeopardy_cat_not_resolvable'); }
        $question = ckm_quiz_get_question($questionId);
        if (!$question || !ckm_quiz_jeopardy_is_cat_question($question)) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Текущий вопрос не является «Секретной передачей».', 'jeopardy_cat_not_current'); }
        $mechanic = ckm_quiz_jeopardy_cat_row($gameId, $questionId, true);
        if (!$mechanic || (string)($mechanic['status'] ?? '') !== 'assigned') { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Для спецвопроса не назначена команда.', 'jeopardy_cat_target_missing'); }
        $payload = ckm_quiz_json_decode($mechanic['payload_json'] ?? '');
        $targetTeamId = (int)($mechanic['team_id'] ?? 0);
        $sourceTeamId = (int)($payload['sourceTeamId'] ?? 0);
        $answer = ckm_quiz_get_answer($gameId, $targetTeamId, $questionId, true);
        if ($decision === 'accepted' && !$answer) { $wpdb->query('ROLLBACK'); return ckm_quiz_error(409, 'Сначала команда должна отправить ответ.', 'jeopardy_cat_answer_missing'); }
        if ($answer && (string)($answer['verdict'] ?? 'pending') === 'pending') {
            $answerDecision = ckm_quiz_apply_score_locked(
                $game, $question, $answer, 0, $decision,
                $decision === 'accepted' ? 'Интеллектуальный батл: спецвопрос принят ведущим' : 'Интеллектуальный батл: спецвопрос отклонён ведущим',
                $actorType, $actorUserId, 'jeopardy-cat-answer-' . (int)$answer['id'] . '-' . $decision
            );
            if (empty($answerDecision['ok'])) throw new RuntimeException((string)($answerDecision['code'] ?? 'jeopardy_cat_answer_resolve_failed'));
        }
        $now = ckm_quiz_now_mysql();
        if ($wpdb->update(ckm_quiz_mechanics_table(), array('status'=>$decision,'resolved_at'=>$now,'updated_at'=>$now), array('id'=>(int)$mechanic['id'])) === false) throw new RuntimeException('jeopardy_cat_resolve_update_failed');
        $nextSelector = $decision === 'accepted' ? $targetTeamId : $sourceTeamId;
        if ($nextSelector <= 0) $nextSelector = $targetTeamId;
        if ($wpdb->update(ckm_quiz_games_table(), array('jeopardy_selector_team_id'=>$nextSelector,'updated_at'=>$now), array('id'=>$gameId)) === false) throw new RuntimeException('jeopardy_cat_selector_update_failed');
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_cat_' . $decision,$actorType,$actorUserId,$targetTeamId,$questionId,'game_mechanic',(int)$mechanic['id'],array(
            'sourceTeamId'=>$sourceTeamId,'targetTeamId'=>$targetTeamId,'nextSelectorTeamId'=>$nextSelector,'decision'=>$decision
        ),'jeopardy-cat-resolve-' . (int)$mechanic['id']);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_cat_resolve_event_failed');
        $wpdb->query('COMMIT');
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Jeopardy cat resolve failed: ' . $e->getMessage());
        return ckm_quiz_error(500, 'Не удалось обработать ответ на спецвопрос.', 'jeopardy_cat_resolve_failed');
    }

    $wagerSettlement = ckm_quiz_jeopardy_settle_team_wager(
        $gameId, $questionId, $targetTeamId, $decision === 'accepted' ? 'won' : 'lost', $actorType, $actorUserId
    );
    if (empty($wagerSettlement['ok'])) return $wagerSettlement;
    $wrongPenalty = null;
    if ($decision === 'rejected') {
        $wrongPenalty = ckm_quiz_jeopardy_wrong_answer_penalty($gameId, $game, $question, $targetTeamId, $actorType, $actorUserId, 'cat');
        if (empty($wrongPenalty['ok'])) return $wrongPenalty;
    }
    if ($decision === 'accepted') {
        $value = ckm_quiz_jeopardy_question_value($question);
        if ($value > 0 && function_exists('ckm_quiz_apply_bonus')) {
            $score = ckm_quiz_apply_bonus($gameId,$targetTeamId,$value,'Интеллектуальный батл: правильный ответ на «Секретную передачу»',$actorType,$actorUserId,'jeopardy-cat-score-' . $questionId . '-' . $targetTeamId,array('round_id'=>(int)($game['current_round_id'] ?? 0),'question_id'=>$questionId));
            if (empty($score['ok'])) return $score;
        }
    }
    $closed = ckm_quiz_close_current_question($gameId,$actorType,$actorUserId);
    if (empty($closed['ok'])) return $closed;
    $fresh = ckm_quiz_get_game($gameId) ?: $game;
    return ckm_quiz_result(true, 200, array('decision'=>$decision,'wagerSettlement'=>$wagerSettlement,'wrongAnswerPenalty'=>$wrongPenalty,'game'=>$fresh));
}

function ckm_quiz_jeopardy_mark_question_played(array $game, array $question, string $actorType, int $actorUserId): void {
    if (!ckm_quiz_jeopardy_is_active($game)) return;
    if ((string)($question['question_stage'] ?? 'main') !== 'main') return;
    global $wpdb;
    $gameId = (int)$game['id'];
    $questionId = (int)$question['id'];
    $mechanic = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . ckm_quiz_mechanics_table() . " WHERE game_id=%d AND question_id=%d AND mechanic_type='jeopardy_cell' LIMIT 1 FOR UPDATE",
        $gameId, $questionId
    ), ARRAY_A);
    if (!$mechanic) throw new RuntimeException('jeopardy_selected_cell_missing');
    $now = ckm_quiz_now_mysql();
    if ((string)($mechanic['status'] ?? '') !== 'played') {
        $ok = $wpdb->update(ckm_quiz_mechanics_table(), array('status'=>'played','resolved_at'=>$now,'updated_at'=>$now), array('id'=>(int)$mechanic['id']));
        if ($ok === false) throw new RuntimeException('jeopardy_cell_played_update_failed');
        $eventId = ckm_quiz_append_event($gameId,'jeopardy_cell_played',$actorType,$actorUserId,(int)($mechanic['team_id'] ?? 0),$questionId,'game_mechanic',(int)$mechanic['id'],array(
            'categoryKey'=>ckm_quiz_jeopardy_category_key($question),
            'value'=>ckm_quiz_jeopardy_question_value($question),
        ),'jeopardy-cell-played-' . $questionId);
        if ($eventId <= 0) throw new RuntimeException('jeopardy_cell_played_event_failed');
    }
    $wpdb->update(ckm_quiz_games_table(), array('jeopardy_selected_question_id'=>0,'updated_at'=>$now), array('id'=>$gameId));
}

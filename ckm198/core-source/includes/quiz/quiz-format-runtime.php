<?php
/**
 * Runtime adapters for Game Format Layer.
 *
 * alpha.69.10 keeps CHGK runtime active and connects both arbitration and the text AI host to ИИ.
 * The shared quiz phases remain unchanged; format-specific behaviour is exposed
 * through this adapter layer so later formats do not fork the core engine.
 */

if (!defined('ABSPATH')) exit;

function ckm_quiz_format_runtime_is_supported(string $formatKey, ?array $format = null): bool {
    $formatKey = sanitize_key($formatKey);
    if ($formatKey === 'classic_quiz') return true;
    if (!in_array($formatKey, array('chgk','jeopardy','solution_price','negotiation_duel'), true)) return false;
    if ($format === null && function_exists('ckm_quiz_format_get')) $format = ckm_quiz_format_get($formatKey);
    return is_array($format)
        && (string)($format['status'] ?? '') === 'active'
        && (string)($format['engine_contract'] ?? '') === 'format_layer_v1';
}

function ckm_quiz_runtime_format_key(array $game): string {
    $key = sanitize_key((string)($game['format_key_snapshot'] ?? CKM_EA_DEFAULT_GAME_FORMAT));
    return $key !== '' ? $key : CKM_EA_DEFAULT_GAME_FORMAT;
}

function ckm_quiz_runtime_format_settings(array $game): array {
    $settings = ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    $formatKey = ckm_quiz_runtime_format_key($game);
    if (!$settings && function_exists('ckm_quiz_format_resolve_settings')) {
        $settings = ckm_quiz_format_resolve_settings($formatKey);
    }
    if ($formatKey === 'chgk') {
        // The current «Битва знатоков» contract is intentionally strict:
        // one team, sequential questions, no appeals, no tie-break, fixed phase timers.
        // Normalize old snapshots here so legacy settings cannot block start or revive removed mechanics.
        $settings['questionSelectionMode'] = 'sequential';
        $settings['appealsEnabled'] = false;
        $settings['appealWindowSeconds'] = 0;
        $settings['tieBreakMode'] = 'disabled';
        $settings['discussionSeconds'] = 60;
        $settings['finalAnswerSeconds'] = 20;
        $earlyRaw = (int)($settings['earlyAnswerSeconds'] ?? 5);
        $settings['earlyAnswerSeconds'] = $earlyRaw < 5 ? 5 : max(5, min(60, $earlyRaw));
    }
    if ($formatKey === 'negotiation_duel') {
        // «Переговорный раунд» has a fixed 60-second response window. Older game
        // snapshots could keep a stale Games Hub value (for example 240 sec),
        // while the task text correctly said «У вас 60 секунд». Normalize the
        // runtime snapshot so UI/state and the authoritative timer agree.
        $mode = sanitize_key((string)($settings['negotiationMode'] ?? ''));
        if ($mode === 'express') $settings['secondsPerTurn'] = 60;
    }
    return $settings;
}

function ckm_quiz_runtime_validate_quiz(string $formatKey, int $quizId, int $revision, array $formatSettings): array {
    $formatKey = sanitize_key($formatKey);
    if ($formatKey === 'jeopardy' && function_exists('ckm_quiz_jeopardy_validate_quiz')) {
        return ckm_quiz_jeopardy_validate_quiz($quizId, $revision, $formatSettings);
    }
    if (in_array($formatKey, array('solution_price','negotiation_duel'), true)) {
        return ckm_quiz_result(true, 200);
    }
    if ($formatKey !== 'chgk') return ckm_quiz_result(true, 200);
    global $wpdb;
    $invalidTypes = $wpdb->get_results($wpdb->prepare(
        "SELECT id,position,question_type FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND question_type<>'text' ORDER BY position ASC",
        $quizId,
        $revision
    ), ARRAY_A) ?: array();
    $questionCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')='main'",
        $quizId,
        $revision
    ));
    $legacyStageCount = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')<>'main'",
        $quizId,
        $revision
    ));
    if ($legacyStageCount > 0) {
        return ckm_quiz_error(409, 'В «Битве знатоков» больше нет резервных тай-брейк/финальных вопросов. Все активные вопросы должны идти в обычной последовательности.', 'chgk_legacy_question_stage_not_supported');
    }
    if ($questionCount < 11) {
        return ckm_quiz_error(409, 'Для «Битвы знатоков» нужно минимум 11 обычных вопросов: матч идёт до 6 очков и при счёте 5:5 требуется одиннадцатый вопрос.', 'chgk_min_questions_11');
    }
    if ($invalidTypes) {
        $positions = array_map(static function(array $row): int { return (int)$row['position']; }, $invalidTypes);
        return ckm_quiz_error(409, 'Для формата «Битва знатоков» все игровые ответы должны быть текстовыми. Исправьте тип вопроса: ' . implode(', ', $positions) . '.', 'chgk_text_answer_required');
    }
    // Older games may still store previous timer settings in the snapshot.
    // They must not block the host button: runtime normalizes CHGK to 5 + 60 + 20 seconds.
    $seconds = 60;
    $finalSeconds = 20;
    return ckm_quiz_result(true, 200);
}

function ckm_quiz_runtime_question_seconds(array $game, array $question, int $fallbackSeconds): int {
    $formatKey = ckm_quiz_runtime_format_key($game);
    if ($formatKey !== 'chgk') {
        if ($formatKey === 'negotiation_duel') {
            $formatSettings = ckm_quiz_runtime_format_settings($game);
            $mode = sanitize_key((string)($formatSettings['negotiationMode'] ?? ''));
            if ($mode === 'express') return 60;
        }
        $settings = function_exists('ckm_quiz_settings') ? ckm_quiz_settings($game) : array();
        if (!empty($settings['hubAnswerTimeOverride'])) {
            return max(5, min(3600, (int)($settings['secondsPerQuestion'] ?? $fallbackSeconds)));
        }
        return max(5, min(3600, $fallbackSeconds));
    }
    $settings = ckm_quiz_runtime_format_settings($game);
    return max(5, min(60, (int)($settings['earlyAnswerSeconds'] ?? 5)));
}

function ckm_quiz_runtime_speed_bonus_enabled(string $formatKey): bool {
    return !in_array(sanitize_key($formatKey), array('chgk','jeopardy','negotiation_duel'), true);
}

function ckm_quiz_runtime_requires_arbitration(array $game, array $question): bool {
    return ckm_quiz_runtime_format_key($game) === 'chgk';
}

function ckm_quiz_runtime_answer_policy(array $game): array {
    if (ckm_quiz_runtime_format_key($game) === 'chgk') {
        $settings = ckm_quiz_runtime_format_settings($game);
        return array(
            'mode'=>'team_final',
            'answerType'=>'text',
            'singleFinalAnswer'=>true,
            'allowReplace'=>false,
            'argumentationEnabled'=>true,
            'questionReviewEnabled'=>!array_key_exists('questionReviewEnabled',$settings) || !empty($settings['questionReviewEnabled']),
            'requireQuestionReview'=>!empty($settings['requireQuestionReview']),
            'closeWhenAllAnswered'=>!empty($settings['closeWhenAllAnswered']),
        );
    }
    return array(
        'mode'=>'team_editable',
        'answerType'=>'question',
        'singleFinalAnswer'=>false,
        'allowReplace'=>true,
        'argumentationEnabled'=>false,
    );
}

function ckm_quiz_runtime_prepare_answer(array $game, array $question, string $answerText, array $answerPayload): array {
    if (ckm_quiz_runtime_format_key($game) !== 'chgk') {
        return ckm_quiz_result(true, 200, array('answerText'=>$answerText, 'answerPayload'=>$answerPayload, 'finalized'=>false));
    }
    if (function_exists('ckm_quiz_chgk_final_answer_is_open') && !ckm_quiz_chgk_final_answer_is_open($game)) {
        return ckm_quiz_error(409, 'Сначала завершите обсуждение. Окончательный ответ ещё не открыт.', 'final_answer_not_open');
    }
    if (sanitize_key((string)($question['question_type'] ?? '')) !== 'text') {
        return ckm_quiz_error(409, 'Для «Битвы знатоков» текущий вопрос должен иметь текстовый тип ответа.', 'chgk_text_answer_required');
    }
    $answerText = trim(sanitize_textarea_field($answerText));
    if ($answerText === '') return ckm_quiz_error(422, 'Введите финальный ответ команды.', 'chgk_final_answer_empty');
    $argumentation = trim(sanitize_textarea_field((string)($answerPayload['argumentation'] ?? '')));
    $payload = array(
        'finalized'=>true,
        'argumentation'=>$argumentation,
        'format'=>'chgk',
    );
    return ckm_quiz_result(true, 200, array('answerText'=>$answerText, 'answerPayload'=>$payload, 'finalized'=>true));
}

function ckm_quiz_runtime_existing_answer_locked(array $game, ?array $existing): bool {
    if (!$existing) return false;
    if (ckm_quiz_runtime_format_key($game) === 'chgk') return true;
    if (ckm_quiz_runtime_format_key($game) === 'jeopardy' && function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($game)) return true;
    return false;
}

function ckm_quiz_runtime_answer_event_name(array $game, bool $updated): string {
    if (ckm_quiz_runtime_format_key($game) === 'chgk') return 'chgk_final_answer_submitted';
    return $updated ? 'answer_updated' : 'answer_submitted';
}

function ckm_quiz_runtime_arbitration_context(array $game, array $question, array $answer): array {
    $formatKey = ckm_quiz_runtime_format_key($game);
    $payload = ckm_quiz_json_decode($answer['answer_payload_json'] ?? '');
    $mode = ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid');
    $base = array(
        'required'=>false,
        'formatKey'=>$formatKey,
        'mode'=>$mode,
        'provider'=>'none',
        'criteria'=>array(),
        'status'=>(string)($answer['verdict'] ?? 'pending') === 'pending' ? 'pending' : 'resolved',
        'resolvedBy'=>(string)($answer['judge_mode'] ?? ''),
    );
    if ($formatKey !== 'chgk') return $base;
    $base['required'] = true;
    $base['provider'] = in_array($mode, array('ai','hybrid'), true) ? 'ai_puffer' : 'human';
    $base['criteria'] = array('answer_accuracy','argumentation_quality');
    $base['answerText'] = (string)($answer['answer_text'] ?? '');
    $base['argumentation'] = (string)($payload['argumentation'] ?? '');
    $base['referenceAnswers'] = ckm_quiz_json_decode($question['correct_answers_json'] ?? '');
    $base['referenceExplanation'] = (string)($question['explanation'] ?? '');
    $base['questionText'] = (string)($question['question_text'] ?? '');
    $rule = ckm_quiz_json_decode($question['scoring_rule_json'] ?? '');
    $base['acceptedVariants'] = array_values((array)($rule['acceptedVariants'] ?? array()));
    $base['rejectedVariants'] = array_values((array)($rule['rejectedVariants'] ?? array()));
    $base['keyClues'] = array_values((array)($rule['keyClues'] ?? array()));
    $base['arbitrationRules'] = (string)($rule['arbitrationRules'] ?? '');
    $base['argumentationCriteria'] = (string)($rule['argumentationCriteria'] ?? '');
    return $base;
}

function ckm_quiz_runtime_state(array $game, string $role, ?array $question = null): array {
    $formatKey = ckm_quiz_runtime_format_key($game);
    if ($formatKey === 'jeopardy' && function_exists('ckm_quiz_jeopardy_runtime_state')) {
        return ckm_quiz_jeopardy_runtime_state($game, $role);
    }
    if ($formatKey === 'negotiation_duel') {
        $settings = ckm_quiz_runtime_format_settings($game);
        $mode = sanitize_key((string)($settings['negotiationMode'] ?? 'sales'));
        if (!in_array($mode, array('sales','business','express'), true)) $mode = 'sales';
        return array(
            'key'=>'negotiation_duel',
            'runtime'=>'negotiation_duel_v1',
            'phase'=>(string)($game['quiz_phase'] ?? 'waiting'),
            'negotiationMode'=>$mode,
            'answerType'=>'text',
            'secondsPerTurn'=>max(30, min(600, (int)($settings['secondsPerTurn'] ?? 120))),
            'assessmentProfile'=>array_values((array)($settings['assessmentProfile'] ?? array())),
            'judgeMode'=>sanitize_key((string)($settings['judgeMode'] ?? 'ai')) ?: 'ai',
        );
    }
    if ($formatKey !== 'chgk') {
        return array('key'=>$formatKey, 'runtime'=>'classic', 'phase'=>(string)($game['quiz_phase'] ?? 'waiting'));
    }
    $settings = ckm_quiz_runtime_format_settings($game);
    $mode = ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid');
    return array(
        'key'=>'chgk',
        'runtime'=>'chgk_v1',
        'phase'=>(string)($game['quiz_phase'] ?? 'waiting') === 'question_open'
            ? (function_exists('ckm_quiz_chgk_question_flow_state')
                ? (string)((ckm_quiz_chgk_question_flow_state($game)['phase'] ?? 'question_narration'))
                : ((function_exists('ckm_quiz_chgk_final_answer_is_open') && ckm_quiz_chgk_final_answer_is_open($game)) ? 'final_answer_open' : ((function_exists('ckm_quiz_chgk_discussion_is_started') && ckm_quiz_chgk_discussion_is_started($game)) ? 'discussion' : 'early_answer_offer')))
            : (string)($game['quiz_phase'] ?? 'waiting'),
        'discussionSeconds'=>60,
        'finalAnswerSeconds'=>20,
        'earlyAnswerSeconds'=>max(5, min(60, (int)($settings['earlyAnswerSeconds'] ?? 5))),
        'bonusMinutes'=>function_exists('ckm_quiz_chgk_bonus_minutes_state') ? ckm_quiz_chgk_bonus_minutes_state($game) : array('earned'=>0,'used'=>0,'available'=>0),
        'singleFinalAnswer'=>true,
        'answerType'=>'text',
        'argumentationEnabled'=>true,
        'teamFlow'=>function_exists('ckm_quiz_chgk_flow_settings') ? ckm_quiz_chgk_flow_settings($game) : array(
            'participationMode'=>'team_device',
            'finalizationMode'=>'team_device',
            'singleTeamSession'=>true,
            'captainSupported'=>false,
            'legacySnapshot'=>false,
        ),
        'arbitration'=>array(
            'required'=>true,
            'mode'=>$mode,
            'aiConnector'=>function_exists('ckm_quiz_ai_arbitration_is_configured') && ckm_quiz_ai_arbitration_is_configured() ? 'ai_puffer' : 'unavailable',
            'autoEnabled'=>in_array($mode, array('ai','hybrid'), true),
            'humanAvailable'=>true,
        ),
        'pause'=>function_exists('ckm_quiz_chgk_pause_state') ? ckm_quiz_chgk_pause_state($game) : array('enabled'=>true,'isPaused'=>false,'remainingSeconds'=>0),
        'host'=>array(
            'mode'=>(string)($game['host_mode_snapshot'] ?? 'human'),
            'aiConnector'=>function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured() ? 'ai_puffer' : 'unavailable',
            'liveAI'=>(string)($game['host_mode_snapshot'] ?? '') === 'ai' && function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured(),
            'fallbackAvailable'=>true,
        ),
    );
}

function ckm_quiz_runtime_after_question_started(array $game, array $question, string $actorType, int $actorUserId, string $deadline, int $seconds): void {
    if (ckm_quiz_runtime_format_key($game) !== 'chgk') return;
    $settings = function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : array();
    if ((string)($game['host_mode_snapshot'] ?? '') === 'ai') {
        $eventId = ckm_quiz_append_event(
            (int)$game['id'],
            'chgk_question_narration_started',
            $actorType,
            $actorUserId,
            0,
            (int)$question['id'],
            'format_runtime',
            (int)$question['id'],
            array('timerDeferred'=>true, 'earlyAnswerSeconds'=>max(5, min(60, (int)($settings['earlyAnswerSeconds'] ?? 5))), 'discussionSeconds'=>60, 'finalAnswerSeconds'=>20, 'singleFinalAnswer'=>true),
            'chgk-question-narration-started-' . (int)$question['id']
        );
        if ($eventId <= 0) throw new RuntimeException('chgk_question_narration_event_failed');
        return;
    }
    $eventId = ckm_quiz_append_event(
        (int)$game['id'],
        'chgk_early_answer_window_started',
        $actorType,
        $actorUserId,
        0,
        (int)$question['id'],
        'format_runtime',
        (int)$question['id'],
        array('earlyAnswerSeconds'=>max(5, min(60, (int)($settings['earlyAnswerSeconds'] ?? 5))), 'deadlineAt'=>$deadline, 'singleFinalAnswer'=>true),
        'chgk-early-answer-started-' . (int)$question['id']
    );
    if ($eventId <= 0) throw new RuntimeException('chgk_early_answer_event_failed');
}

function ckm_quiz_runtime_after_question_closed(array $game, array $question, string $actorType, int $actorUserId): void {
    if (ckm_quiz_runtime_format_key($game) === 'jeopardy') {
        if (function_exists('ckm_quiz_jeopardy_mark_question_played')) ckm_quiz_jeopardy_mark_question_played($game, $question, $actorType, $actorUserId);
        return;
    }
    if (ckm_quiz_runtime_format_key($game) !== 'chgk') return;
    global $wpdb;
    $answers = $wpdb->get_results($wpdb->prepare(
        "SELECT id,team_id,attempt_no FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND question_id=%d ORDER BY id ASC",
        (int)$game['id'],
        (int)$question['id']
    ), ARRAY_A) ?: array();
    if (!function_exists('ckm_quiz_chgk_question_event_exists') || !ckm_quiz_chgk_question_event_exists((int)$game['id'], (int)$question['id'], 'chgk_discussion_closed')) {
        $eventId = ckm_quiz_append_event(
            (int)$game['id'],
            'chgk_discussion_closed',
            $actorType,
            $actorUserId,
            0,
            (int)$question['id'],
            'format_runtime',
            (int)$question['id'],
            array('answersCount'=>count($answers)),
            'chgk-discussion-closed-' . (int)$question['id']
        );
        if ($eventId <= 0) throw new RuntimeException('chgk_discussion_close_event_failed');
    }
    if (!function_exists('ckm_quiz_chgk_question_event_exists') || !ckm_quiz_chgk_question_event_exists((int)$game['id'], (int)$question['id'], 'chgk_answers_closed')) {
        $answersClosedEvent = ckm_quiz_append_event(
            (int)$game['id'],
            'chgk_answers_closed',
            $actorType,
            $actorUserId,
            0,
            (int)$question['id'],
            'format_runtime',
            (int)$question['id'],
            array('answersCount'=>count($answers), 'closedAt'=>ckm_quiz_now_mysql()),
            'chgk-answers-closed-' . (int)$question['id']
        );
        if ($answersClosedEvent <= 0) throw new RuntimeException('chgk_answers_closed_event_failed');
    }
    foreach ($answers as $answer) {
        $requestId = 'chgk-arbitration-' . (int)$answer['id'] . '-attempt-' . (int)$answer['attempt_no'];
        $arbEvent = ckm_quiz_append_event(
            (int)$game['id'],
            'chgk_arbitration_requested',
            'system',
            0,
            (int)$answer['team_id'],
            (int)$question['id'],
            'answer',
            (int)$answer['id'],
            array(
                'answerId'=>(int)$answer['id'],
                'teamId'=>(int)$answer['team_id'],
                'judgeMode'=>ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid'),
                'criteria'=>array('answer_accuracy','argumentation_quality'),
                'provider'=>in_array(ckm_quiz_judge_mode((string)($game['judge_mode_snapshot'] ?? 'hybrid'), 'hybrid'), array('ai','hybrid'), true) ? 'ai_puffer' : 'human',
            ),
            $requestId
        );
        if ($arbEvent <= 0) throw new RuntimeException('chgk_arbitration_event_failed');
    }
}

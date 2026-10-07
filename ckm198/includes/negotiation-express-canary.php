<?php
if (!defined('ABSPATH')) exit;

/**
 * negotiation_express_v1 canary adapter.
 *
 * Safety boundary: this adapter is intentionally SHADOW-ONLY. The proven
 * negotiation_duel_v1 remains authoritative for gameplay, scoring, payment and
 * history. Only a one-team, AI-host, test_mode room created from the built-in
 * demo-negotiation-express quiz is mirrored into Negotiation Core v1.
 *
 * Real browser events are mirrored:
 * - actual AI-host TTS playback completion -> voice_finished;
 * - actual typed/STT participant reply -> submit_answer with input_mode;
 * - saved legacy judgement -> evaluate_turn;
 * - legacy question advance/finish -> next_case/finish_session.
 */
const CKM_NEGOTIATION_EXPRESS_CANARY_VERSION = 'negotiation_express_canary_v1';
const CKM_NEGOTIATION_EXPRESS_CANARY_MODE = 'shadow';

/**
 * Explicit admin-only launch marker for a real-browser canary room.
 * This never enables the new runtime for normal games.
 */
function ckm_quiz_pro_express_canary_launch_requested(): bool {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return false;
    $raw = isset($_REQUEST['ckm_express_canary'])
        ? strtolower(trim((string)wp_unslash($_REQUEST['ckm_express_canary'])))
        : '';
    return in_array($raw, ['1','true','yes','on'], true);
}

function ckm_quiz_pro_express_canary_launch_allowed(array $quiz, int $teamCount, string $hostMode): bool {
    if (!ckm_quiz_pro_express_canary_launch_requested()) return false;
    if ((string)($quiz['slug'] ?? '') !== 'demo-negotiation-express') return false;
    if ($teamCount !== 1) return false;
    return sanitize_key($hostMode) === 'ai';
}

function ckm_quiz_pro_express_canary_qualifies(array $game, array $quiz): bool {
    if (empty($game['test_mode'])) return false;
    if ((int)($game['team_count'] ?? 0) !== 1) return false;
    if (sanitize_key((string)($game['format_key_snapshot'] ?? '')) !== 'negotiation_duel') return false;
    if (sanitize_key((string)($game['host_mode_snapshot'] ?? '')) !== 'ai') return false;
    if ((string)($quiz['slug'] ?? '') !== 'demo-negotiation-express') return false;

    $settings = function_exists('ckm_quiz_json_decode')
        ? ckm_quiz_json_decode((string)($game['format_settings_snapshot_json'] ?? ''))
        : json_decode((string)($game['format_settings_snapshot_json'] ?? ''), true);
    if (!is_array($settings)) $settings = [];
    $mode = function_exists('ckm_quiz_pro_negotiation_mode')
        ? ckm_quiz_pro_negotiation_mode((string)($settings['negotiationMode'] ?? ''))
        : sanitize_key((string)($settings['negotiationMode'] ?? ''));
    return $mode === 'express';
}

function ckm_quiz_pro_express_canary_quiz(array $game): ?array {
    static $cache = [];
    $quizId = (int)($game['quiz_id'] ?? 0);
    if ($quizId <= 0) return null;
    if (array_key_exists($quizId, $cache)) return $cache[$quizId];
    $quiz = function_exists('ckm_quiz_get_quiz') ? ckm_quiz_get_quiz($quizId, false) : null;
    $cache[$quizId] = is_array($quiz) ? $quiz : null;
    return $cache[$quizId];
}

function ckm_quiz_pro_express_canary_is_game(array $game): bool {
    $quiz = ckm_quiz_pro_express_canary_quiz($game);
    return is_array($quiz) && ckm_quiz_pro_express_canary_qualifies($game, $quiz);
}

function ckm_quiz_pro_express_canary_session_id(array $game): string {
    return 'TEST-CANARY-EXP-GAME-' . max(0, (int)($game['id'] ?? 0));
}

function ckm_quiz_pro_express_canary_cases(array $game): array {
    global $wpdb;
    $quizId = (int)($game['quiz_id'] ?? 0);
    $revision = max(1, (int)($game['quiz_revision'] ?? 1));
    if ($quizId <= 0 || !function_exists('ckm_quiz_questions_table')) return [];
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id,position,round_title,question_text,explanation,points,time_limit_seconds FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND COALESCE(question_stage,'main')='main' ORDER BY position ASC,id ASC",
        $quizId,
        $revision
    ), ARRAY_A) ?: [];
    $cases = [];
    foreach ($rows as $row) {
        $position = max(1, (int)($row['position'] ?? (count($cases) + 1)));
        $text = trim((string)($row['question_text'] ?? ''));
        if ($text === '') continue;
        $cases[] = [
            'id' => 'q-' . (int)($row['id'] ?? $position),
            'title' => 'Экспресс-ситуация ' . $position,
            'situation' => '',
            'opponent_message' => $text,
            'task' => trim((string)($row['explanation'] ?? '')) ?: 'Ответьте коротко и по делу, сохраняя инициативу.',
            'duration_seconds' => max(5, min(120, (int)($row['time_limit_seconds'] ?? 60) ?: 60)),
            'max_score' => max(1, min(100, (int)($row['points'] ?? 20) ?: 20)),
            'legacy_question_id' => (int)($row['id'] ?? 0),
            'legacy_position' => $position,
        ];
    }
    return $cases;
}

function ckm_quiz_pro_express_canary_service(): CKM_Negotiation_Persisted_Runtime_Service {
    static $service = null;
    if (!$service instanceof CKM_Negotiation_Persisted_Runtime_Service) {
        $service = new CKM_Negotiation_Persisted_Runtime_Service();
    }
    return $service;
}

function ckm_quiz_pro_express_canary_ensure(array $game): array {
    if (!ckm_quiz_pro_express_canary_is_game($game)) {
        return ['ok'=>false,'code'=>'canary_not_enabled','message'=>'Canary для этой комнаты выключен.'];
    }
    if (function_exists('ckm_negotiation_storage_ensure')) {
        $health = ckm_negotiation_storage_ensure();
        if (empty($health['ok'])) {
            return ['ok'=>false,'code'=>'canary_storage_unhealthy','message'=>'Хранилище Negotiation Core недоступно.','health'=>$health];
        }
    }
    $sessionId = ckm_quiz_pro_express_canary_session_id($game);
    $service = ckm_quiz_pro_express_canary_service();
    $state = $service->state($sessionId);
    if (!empty($state['ok']) || (($state['code'] ?? '') !== 'session_not_found' && !isset($state['code']))) return $state;
    if (($state['code'] ?? '') !== 'session_not_found') return $state;

    $cases = ckm_quiz_pro_express_canary_cases($game);
    if (!$cases) return ['ok'=>false,'code'=>'canary_cases_missing','message'=>'Не удалось собрать демо-кейсы Переговорный раунда.'];
    return $service->create_session('negotiation_express_v1', [
        'session_id' => $sessionId,
        'cases' => $cases,
        'player_role' => 'Команда',
        'opponent_role' => 'Оппонент',
        'now' => gmdate('c'),
    ], [
        'tenant_id' => max(0, (int)($game['tenant_id'] ?? 0)),
        'game_id' => max(0, (int)($game['id'] ?? 0)),
        'host_mode' => 'ai',
        'created_at' => gmdate('c'),
    ]);
}

function ckm_quiz_pro_express_canary_apply(array $game, string $action, array $command = [], array $context = []): array {
    $state = ckm_quiz_pro_express_canary_ensure($game);
    if (isset($state['ok']) && $state['ok'] === false) return $state;
    $command['expected_version'] = (int)($state['state_version'] ?? 0);
    if (empty($command['client_request_id'])) {
        $command['client_request_id'] = 'canary-' . sanitize_key($action) . '-' . (int)($game['id'] ?? 0) . '-' . (int)($state['state_version'] ?? 0);
    }
    $command['now'] = (string)($command['now'] ?? gmdate('c'));
    return ckm_quiz_pro_express_canary_service()->apply(
        ckm_quiz_pro_express_canary_session_id($game),
        $action,
        $command,
        $context
    );
}

function ckm_quiz_pro_express_canary_mirror_voice_finished(array $game, int $legacyMessageEventId): array {
    if ($legacyMessageEventId <= 0 || !ckm_quiz_pro_express_canary_is_game($game)) return ['ok'=>true,'skipped'=>true,'reason'=>'not_canary'];
    $state = ckm_quiz_pro_express_canary_ensure($game);
    if (isset($state['ok']) && $state['ok'] === false) return $state;
    if ((string)($state['phase'] ?? '') === 'answer_window') return ['ok'=>true,'skipped'=>true,'reason'=>'already_open','state'=>$state];
    if ((string)($state['phase'] ?? '') !== 'host_speaking') return ['ok'=>true,'skipped'=>true,'reason'=>'phase_'.sanitize_key((string)($state['phase'] ?? '')),'state'=>$state];
    return ckm_quiz_pro_express_canary_apply($game, 'voice_finished', [
        'client_request_id' => 'canary-voice-event-' . $legacyMessageEventId,
        'message_id' => (string)($state['voice']['message_id'] ?? ''),
        'voice_generation' => (int)($state['voice']['generation'] ?? 0),
        'legacy_message_event_id' => $legacyMessageEventId,
    ]);
}

function ckm_quiz_pro_express_canary_legacy_evaluation(int $gameId, int $teamId, int $questionId): ?array {
    if ($gameId <= 0 || $teamId <= 0 || $questionId <= 0) return null;
    $answer = function_exists('ckm_quiz_get_answer') ? ckm_quiz_get_answer($gameId, $teamId, $questionId) : null;
    if (!is_array($answer) || (string)($answer['verdict'] ?? 'pending') === 'pending') return null;
    $score = (int)($answer['awarded_points'] ?? 0);
    $comment = trim((string)($answer['judge_comment'] ?? ''));
    return [
        'total_score' => $score,
        'score' => $score,
        'criteria' => [],
        'strengths' => [],
        'improvements' => [],
        'feedback' => $comment,
        'comment' => $comment,
        'provider' => 'legacy_canary_mirror',
        'legacy_verdict' => (string)($answer['verdict'] ?? ''),
    ];
}

function ckm_quiz_pro_express_canary_mirror_answer(array $game, int $teamId, int $questionId, string $text, string $inputMode, int $answerId = 0): array {
    if (!ckm_quiz_pro_express_canary_is_game($game)) return ['ok'=>true,'skipped'=>true,'reason'=>'not_canary'];
    $state = ckm_quiz_pro_express_canary_ensure($game);
    if (isset($state['ok']) && $state['ok'] === false) return $state;
    if ((string)($state['phase'] ?? '') !== 'answer_window') {
        return ['ok'=>false,'code'=>'canary_phase_mismatch','message'=>'Canary не находится в окне ответа.','state'=>$state];
    }
    $requestSuffix = $answerId > 0 ? (string)$answerId : ((string)$questionId . '-' . (string)$teamId);
    $submitted = ckm_quiz_pro_express_canary_apply($game, 'submit_answer', [
        'client_request_id' => 'canary-answer-' . $requestSuffix,
        'text' => $text,
        'input_mode' => in_array($inputMode, ['voice','text'], true) ? $inputMode : 'text',
    ]);
    if (isset($submitted['ok']) && $submitted['ok'] === false) return $submitted;

    $evaluation = ckm_quiz_pro_express_canary_legacy_evaluation((int)$game['id'], $teamId, $questionId);
    if (!$evaluation) return ['ok'=>true,'submitted'=>true,'evaluation_pending'=>true,'state'=>$submitted];
    $evaluated = ckm_quiz_pro_express_canary_service()->apply(
        ckm_quiz_pro_express_canary_session_id($game),
        'evaluate_turn',
        [
            'expected_version' => (int)($submitted['state_version'] ?? 0),
            'client_request_id' => 'canary-evaluate-' . $requestSuffix,
            'now' => gmdate('c'),
        ],
        ['mock_evaluation'=>$evaluation]
    );
    return $evaluated;
}

function ckm_quiz_pro_express_canary_sync(array $game): array {
    if (!ckm_quiz_pro_express_canary_is_game($game)) return ['ok'=>true,'skipped'=>true,'reason'=>'not_canary'];
    $state = ckm_quiz_pro_express_canary_ensure($game);
    if (isset($state['ok']) && $state['ok'] === false) return $state;

    $legacyPosition = max(0, (int)($game['current_question_position'] ?? 0));
    for ($guard = 0; $guard < 10; $guard++) {
        $coreIndex = max(0, (int)($state['case']['index'] ?? 0));
        if ((string)($game['status'] ?? '') === 'finished') {
            if ((string)($state['status'] ?? '') !== 'finished') {
                $state = ckm_quiz_pro_express_canary_service()->apply(
                    ckm_quiz_pro_express_canary_session_id($game),
                    'finish_session',
                    [
                        'expected_version'=>(int)($state['state_version'] ?? 0),
                        'client_request_id'=>'canary-finish-game-'.(int)$game['id'],
                        'force'=>true,
                        'now'=>gmdate('c'),
                    ]
                );
            }
            break;
        }
        if ($legacyPosition > $coreIndex && (string)($state['phase'] ?? '') === 'feedback') {
            $state = ckm_quiz_pro_express_canary_service()->apply(
                ckm_quiz_pro_express_canary_session_id($game),
                'next_case',
                [
                    'expected_version'=>(int)($state['state_version'] ?? 0),
                    'client_request_id'=>'canary-next-to-'.$legacyPosition.'-v'.(int)($state['state_version'] ?? 0),
                    'now'=>gmdate('c'),
                ]
            );
            if (isset($state['ok']) && $state['ok'] === false) break;
            continue;
        }
        break;
    }
    return $state;
}

function ckm_quiz_pro_express_canary_public_state(array $game): array {
    if (!ckm_quiz_pro_express_canary_is_game($game)) return ['enabled'=>false];
    $state = ckm_quiz_pro_express_canary_sync($game);
    if (isset($state['ok']) && $state['ok'] === false) {
        return [
            'enabled'=>true,
            'mode'=>CKM_NEGOTIATION_EXPRESS_CANARY_MODE,
            'runtime'=>'negotiation_express_v1',
            'ok'=>false,
            'code'=>(string)($state['code'] ?? 'canary_error'),
        ];
    }
    $legacyPosition = max(0, (int)($game['current_question_position'] ?? 0));
    $coreIndex = max(0, (int)($state['case']['index'] ?? 0));
    $legacyDeadline = trim((string)($game['question_deadline_at'] ?? ''));
    $coreDeadline = trim((string)($state['timer']['deadline_at'] ?? ''));
    return [
        'enabled'=>true,
        'mode'=>CKM_NEGOTIATION_EXPRESS_CANARY_MODE,
        'runtime'=>(string)($state['runtime'] ?? 'negotiation_express_v1'),
        'sessionId'=>(string)($state['session_id'] ?? ''),
        'phase'=>(string)($state['phase'] ?? ''),
        'status'=>(string)($state['status'] ?? ''),
        'stateVersion'=>(int)($state['state_version'] ?? 0),
        'caseIndex'=>$coreIndex,
        'legacyQuestionPosition'=>$legacyPosition,
        'score'=>(int)($state['score']['total'] ?? 0),
        'lastInputMode'=>(string)($state['answer']['input_mode'] ?? ''),
        'parity'=>[
            'position'=>($legacyPosition === 0 || $legacyPosition === $coreIndex),
            'timerGate'=>($legacyDeadline === '' ? $coreDeadline === '' : $coreDeadline !== ''),
        ],
    ];
}

function ckm_quiz_pro_express_canary_log_result(string $stage, array $result, int $gameId): void {
    if (!empty($result['ok']) || !empty($result['skipped'])) return;
    error_log('CKM Express Canary ['.$stage.'] game '.$gameId.': '.(string)($result['code'] ?? 'error').' '.(string)($result['message'] ?? $result['error'] ?? ''));
}

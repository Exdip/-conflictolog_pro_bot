<?php
if (!defined('ABSPATH')) exit;

/**
 * Negotiation Express Runtime v1
 *
 * First opt-in runtime built on Negotiation Core v1. This build deliberately
 * has no REST/AJAX routing and is not assigned to existing games. It can be
 * instantiated/registered and exercised by smoke tests or an explicit future
 * adapter only.
 */

const CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION = 'negotiation_express_v1';

final class CKM_Negotiation_Express_Runtime implements CKM_Negotiation_Runtime_Interface {
    public function runtime_key(): string {
        return CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION;
    }

    public function create_session(array $context): array {
        $sessionId = trim((string)($context['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = 'NEG-EXP-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 12));
        }
        $cases = $this->normalize_cases((array)($context['cases'] ?? []));
        if (!$cases) {
            return $this->error([], 'cases_required', 'Для Переговорный раунда нужен хотя бы один кейс.');
        }

        $state = ckm_negotiation_core_state_template($this->runtime_key(), $sessionId);
        $state['status'] = 'active';
        $state['phase'] = 'host_speaking';
        $state['turn_number'] = 1;
        $state['player_role'] = $this->clean_text((string)($context['player_role'] ?? 'Команда'));
        $state['opponent_role'] = $this->clean_text((string)($context['opponent_role'] ?? 'Оппонент'));
        $state['runtime_state'] = [
            'cases' => $cases,
            'current_case_index' => 0,
            'total_cases' => count($cases),
            'completed_cases' => 0,
            'event_seq' => 0,
            'events' => [],
            'applied_request_ids' => [],
        ];
        $state['score'] = [
            'current_case' => null,
            'total' => 0,
            'maximum' => array_sum(array_map(static fn(array $case): int => (int)$case['max_score'], $cases)),
        ];
        $state = $this->load_case($state, 0, $this->now_iso($context));
        $state = $this->append_event($state, 'session_created', 'system', ['runtime' => $this->runtime_key()]);
        $state = $this->append_event($state, 'express_case_started', 'system', ['case_id' => (string)$state['case']['id']]);
        return $this->refresh_actions($state);
    }

    public function get_state(array $session): array {
        return $this->refresh_actions($session);
    }

    public function get_available_actions(array $state): array {
        if (($state['status'] ?? '') === 'finished' || !empty($state['is_finished'])) return [];
        $phase = (string)($state['phase'] ?? '');
        if ($phase === 'host_speaking') return ['voice_finished'];
        if ($phase === 'answer_window') return ['submit_answer'];
        if ($phase === 'feedback') {
            return !empty($this->check_completion($state)['complete']) ? ['finish_session'] : ['next_case'];
        }
        return [];
    }

    public function submit_player_message(array $session, array $command): array {
        $requestId = trim((string)($command['client_request_id'] ?? ''));
        if ($requestId !== '' && $this->request_was_applied($session, $requestId)) return $session;
        if (($session['status'] ?? '') === 'finished' || !empty($session['is_finished'])) {
            return $this->error($session, 'session_finished', 'Сессия уже завершена.');
        }
        if (($session['phase'] ?? '') !== 'answer_window') {
            return $this->error($session, 'wrong_phase', 'Ответ сейчас недоступен.');
        }
        if (empty($session['timer']['enabled']) || empty($session['timer']['started_at'])) {
            return $this->error($session, 'timer_not_started', 'Окно ответа ещё не открыто.');
        }
        if (!empty($session['answer']['locked'])) {
            return $this->error($session, 'answer_locked', 'Ответ уже принят.');
        }
        $now = $this->now_iso($command);
        if ($this->is_after_deadline($now, (string)($session['timer']['deadline_at'] ?? ''))) {
            return $this->error($session, 'timer_expired', 'Время ответа истекло.');
        }
        $text = trim($this->clean_text((string)($command['text'] ?? '')));
        if ($text === '') return $this->error($session, 'empty_answer', 'Ответ не должен быть пустым.');

        $session['answer'] = [
            'allowed' => false,
            'locked' => true,
            'text' => $text,
            'input_mode' => sanitize_key((string)($command['input_mode'] ?? 'text')) ?: 'text',
            'submitted_at' => $now,
        ];
        $session['phase'] = 'evaluating';
        $session['message_history'][] = [
            'actor' => 'team',
            'turn_number' => (int)($session['turn_number'] ?? 0),
            'text' => $text,
            'input_mode' => $session['answer']['input_mode'],
            'created_at' => $now,
        ];
        if ($requestId !== '') $session = $this->remember_request($session, $requestId);
        $session = $this->append_event($session, 'player_message_submitted', 'team', [
            'input_mode' => $session['answer']['input_mode'],
        ]);
        return $this->mutated($session);
    }

    public function handle_ai_turn(array $session, array $context = []): array {
        // Express v1 has no conversational opponent turn after the player's
        // answer. The AI role in this runtime is evaluation, handled below.
        return $this->evaluate_turn($session, $context);
    }

    public function advance_phase(array $session, array $command = []): array {
        $action = sanitize_key((string)($command['action'] ?? ''));
        if ($action === 'voice_finished') return $this->on_voice_finished($session, $command);
        if ($action === 'next_case') return $this->on_next_case($session, $command);
        if ($action === 'finish_session') return $this->finish_session($session, $command);
        return $this->error($session, 'action_not_allowed', 'Неизвестное действие runtime.');
    }

    public function evaluate_turn(array $session, array $context = []): array {
        if (($session['status'] ?? '') === 'finished' || !empty($session['is_finished'])) {
            return $this->error($session, 'session_finished', 'Сессия уже завершена.');
        }
        if (($session['phase'] ?? '') !== 'evaluating') {
            return $this->error($session, 'wrong_phase', 'Сейчас нечего оценивать.');
        }
        $case = (array)($session['case'] ?? []);
        $maxScore = max(1, (int)($case['max_score'] ?? 10));
        $answerText = (string)($session['answer']['text'] ?? '');

        $session = $this->append_event($session, 'ai_turn_started', 'system', ['purpose' => 'evaluation']);
        $evaluation = null;
        if (isset($context['mock_evaluation']) && is_array($context['mock_evaluation'])) {
            $evaluation = $context['mock_evaluation'];
            $evaluation['provider'] = (string)($evaluation['provider'] ?? 'mock');
        } elseif (isset($context['evaluator']) && is_callable($context['evaluator'])) {
            $evaluation = call_user_func($context['evaluator'], $case, $answerText, $session);
            if (!is_array($evaluation)) $evaluation = [];
            $evaluation['provider'] = (string)($evaluation['provider'] ?? 'callback');
        } else {
            $evaluation = $this->local_evaluation($answerText, $maxScore);
        }
        $score = max(0, min($maxScore, (int)($evaluation['total_score'] ?? $evaluation['score'] ?? 0)));
        $evaluation['total_score'] = $score;
        $evaluation['max_score'] = $maxScore;
        $evaluation['criteria'] = is_array($evaluation['criteria'] ?? null) ? $evaluation['criteria'] : [];
        $evaluation['strengths'] = array_values(array_filter((array)($evaluation['strengths'] ?? []), 'is_string'));
        $evaluation['improvements'] = array_values(array_filter((array)($evaluation['improvements'] ?? $evaluation['mistakes'] ?? []), 'is_string'));
        $evaluation['feedback'] = $this->clean_text((string)($evaluation['feedback'] ?? $evaluation['comment'] ?? ''));

        $session['evaluation'] = $evaluation;
        $session['score']['current_case'] = $score;
        $session['score']['total'] = (int)($session['score']['total'] ?? 0) + $score;
        $session['runtime_state']['completed_cases'] = min(
            (int)($session['runtime_state']['total_cases'] ?? 0),
            (int)($session['runtime_state']['completed_cases'] ?? 0) + 1
        );
        $session['phase'] = 'feedback';
        $session = $this->append_event($session, 'turn_evaluated', 'system', [
            'score' => $score,
            'max_score' => $maxScore,
            'provider' => (string)($evaluation['provider'] ?? ''),
        ]);
        $session = $this->append_event($session, 'express_case_completed', 'system', [
            'case_id' => (string)($case['id'] ?? ''),
        ]);
        return $this->mutated($session);
    }

    public function check_completion(array $session): array {
        $total = (int)($session['runtime_state']['total_cases'] ?? 0);
        $completed = (int)($session['runtime_state']['completed_cases'] ?? 0);
        return [
            'complete' => $total > 0 && $completed >= $total,
            'completed_cases' => $completed,
            'total_cases' => $total,
            'reason' => ($total > 0 && $completed >= $total) ? 'all_cases_completed' : '',
        ];
    }

    public function finish_session(array $session, array $context = []): array {
        if (($session['status'] ?? '') === 'finished' || !empty($session['is_finished'])) return $session;
        $completion = $this->check_completion($session);
        if (empty($completion['complete']) && empty($context['force'])) {
            return $this->error($session, 'session_not_complete', 'Не все кейсы завершены.');
        }
        $session['status'] = 'finished';
        $session['phase'] = 'finished';
        $session['is_finished'] = true;
        $session['result'] = [
            'total_score' => (int)($session['score']['total'] ?? 0),
            'maximum' => (int)($session['score']['maximum'] ?? 0),
            'completed_cases' => (int)($session['runtime_state']['completed_cases'] ?? 0),
            'total_cases' => (int)($session['runtime_state']['total_cases'] ?? 0),
        ];
        $session['finished_at'] = $this->now_iso($context);
        $session = $this->append_event($session, 'session_completed', 'system', $session['result']);
        return $this->mutated($session);
    }

    public function restore_session(array $session): array {
        if (($session['runtime'] ?? '') !== $this->runtime_key()) {
            return $this->error($session, 'runtime_mismatch', 'Сессия принадлежит другому runtime.');
        }
        return $this->refresh_actions($session);
    }

    private function on_voice_finished(array $session, array $command): array {
        if (($session['status'] ?? '') === 'finished' || !empty($session['is_finished'])) {
            return $this->error($session, 'session_finished', 'Сессия уже завершена.');
        }
        $messageId = trim((string)($command['message_id'] ?? ''));
        $generation = (int)($command['voice_generation'] ?? -1);
        $expectedMessage = (string)($session['voice']['message_id'] ?? '');
        $expectedGeneration = (int)($session['voice']['generation'] ?? 0);

        if (($session['phase'] ?? '') === 'answer_window'
            && ($session['voice']['status'] ?? '') === 'finished'
            && $messageId === $expectedMessage
            && $generation === $expectedGeneration) {
            return $session; // idempotent replay
        }
        if ($generation !== $expectedGeneration) {
            return $this->error($session, 'voice_generation_mismatch', 'Получено устаревшее событие озвучивания.');
        }
        if ($messageId === '' || $messageId !== $expectedMessage) {
            return $this->error($session, 'voice_message_mismatch', 'Событие относится не к текущей реплике.');
        }
        if (($session['phase'] ?? '') !== 'host_speaking') {
            return $this->error($session, 'wrong_phase', 'Окончание голоса сейчас не ожидается.');
        }

        $now = $this->now_iso($command);
        $duration = max(1, (int)($session['timer']['duration_seconds'] ?? 15));
        $session['voice']['status'] = 'finished';
        $session['voice']['finished_at'] = $now;
        $session['timer']['enabled'] = true;
        $session['timer']['started_at'] = $now;
        $session['timer']['deadline_at'] = $this->add_seconds($now, $duration);
        $session['answer']['allowed'] = true;
        $session['phase'] = 'answer_window';
        $session = $this->append_event($session, 'host_voice_finished', 'ai_host', [
            'message_id' => $messageId,
            'voice_generation' => $generation,
        ]);
        $session = $this->append_event($session, 'timer_started', 'system', [
            'duration_seconds' => $duration,
            'deadline_at' => $session['timer']['deadline_at'],
            'trigger' => 'host_voice_finished',
        ]);
        return $this->mutated($session);
    }

    private function on_next_case(array $session, array $command): array {
        if (($session['phase'] ?? '') !== 'feedback') {
            return $this->error($session, 'wrong_phase', 'Следующий кейс сейчас недоступен.');
        }
        if (!empty($this->check_completion($session)['complete'])) {
            return $this->finish_session($session, $command);
        }
        $next = (int)($session['runtime_state']['current_case_index'] ?? 0) + 1;
        $session['turn_number'] = (int)($session['turn_number'] ?? 0) + 1;
        $session = $this->load_case($session, $next, $this->now_iso($command));
        $session = $this->append_event($session, 'express_case_started', 'system', [
            'case_id' => (string)($session['case']['id'] ?? ''),
        ]);
        return $this->mutated($session);
    }

    private function load_case(array $state, int $index, string $now): array {
        $cases = (array)($state['runtime_state']['cases'] ?? []);
        if (!isset($cases[$index])) return $state;
        $case = $cases[$index];
        $state['runtime_state']['current_case_index'] = $index;
        $state['case'] = [
            'id' => (string)$case['id'],
            'index' => $index + 1,
            'total' => count($cases),
            'title' => (string)$case['title'],
            'situation' => (string)$case['situation'],
            'opponent_message' => (string)$case['opponent_message'],
            'max_score' => (int)$case['max_score'],
        ];
        $state['current_prompt'] = (string)$case['opponent_message'];
        $state['current_task'] = (string)$case['task'];
        $generation = (int)($state['voice']['generation'] ?? 0) + 1;
        $state['voice'] = [
            'generation' => $generation,
            'message_id' => $this->message_id((string)($state['session_id'] ?? ''), (int)($state['turn_number'] ?? 1), $generation),
            'status' => 'waiting',
            'started_at' => null,
            'finished_at' => null,
        ];
        $state['timer'] = [
            'enabled' => false,
            'starts_after_voice' => true,
            'duration_seconds' => (int)$case['duration_seconds'],
            'started_at' => null,
            'deadline_at' => null,
        ];
        $state['answer'] = [
            'allowed' => false,
            'locked' => false,
            'text' => null,
            'input_mode' => null,
            'submitted_at' => null,
        ];
        $state['evaluation'] = null;
        $state['score']['current_case'] = null;
        $state['phase'] = 'host_speaking';
        $state['case_started_at'] = $now;
        return $state;
    }

    private function normalize_cases(array $cases): array {
        $out = [];
        foreach (array_values($cases) as $i => $case) {
            if (!is_array($case)) continue;
            $situation = trim($this->clean_text((string)($case['situation'] ?? '')));
            $message = trim($this->clean_text((string)($case['opponent_message'] ?? $case['message'] ?? '')));
            if ($situation === '' && $message === '') continue;
            $out[] = [
                'id' => sanitize_key((string)($case['id'] ?? 'case-' . ($i + 1))) ?: 'case-' . ($i + 1),
                'title' => $this->clean_text((string)($case['title'] ?? 'Ситуация ' . ($i + 1))),
                'situation' => $situation,
                'opponent_message' => $message,
                'task' => $this->clean_text((string)($case['task'] ?? 'Ответьте коротко и по делу.')),
                'duration_seconds' => max(5, min(120, (int)($case['duration_seconds'] ?? 15))),
                'max_score' => max(1, min(100, (int)($case['max_score'] ?? 10))),
            ];
        }
        return $out;
    }

    private function local_evaluation(string $text, int $maxScore): array {
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $score = $length < 12 ? 2 : ($length < 40 ? 5 : 6);
        $strengths = [];
        $improvements = [];
        if (strpos($text, '?') !== false) {
            $score += 1;
            $strengths[] = 'Есть уточняющий вопрос.';
        } else {
            $improvements[] = 'Можно точнее выяснить позицию оппонента вопросом.';
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach (['предлагаю', 'давайте', 'если', 'уточн', 'следующ'] as $signal) {
            if (strpos($lower, $signal) !== false) {
                $score += 2;
                $strengths[] = 'Есть управляемый следующий ход.';
                break;
            }
        }
        if ($length > 260) {
            $score -= 1;
            $improvements[] = 'Для экспресс-раунда реплика перегружена.';
        }
        $score = max(0, min($maxScore, $score));
        return [
            'total_score' => $score,
            'max_score' => $maxScore,
            'criteria' => [],
            'strengths' => $strengths,
            'improvements' => $improvements,
            'feedback' => $improvements ? implode(' ', $improvements) : 'Реплика соответствует формату экспресс-раунда.',
            'provider' => 'runtime_local_fallback',
        ];
    }

    private function refresh_actions(array $state): array {
        if (!isset($state['error'])) unset($state['ok']);
        $state['available_actions'] = $this->get_available_actions($state);
        return $state;
    }

    private function mutated(array $state): array {
        $state['state_version'] = max(1, (int)($state['state_version'] ?? 1) + 1);
        return $this->refresh_actions($state);
    }

    private function append_event(array $state, string $type, string $actor, array $payload = []): array {
        $seq = (int)($state['runtime_state']['event_seq'] ?? 0) + 1;
        $state['runtime_state']['event_seq'] = $seq;
        if (!isset($state['runtime_state']['events']) || !is_array($state['runtime_state']['events'])) {
            $state['runtime_state']['events'] = [];
        }
        $state['runtime_state']['events'][] = [
            'event_seq' => $seq,
            'event_type' => $type,
            'phase' => (string)($state['phase'] ?? ''),
            'turn_number' => (int)($state['turn_number'] ?? 0),
            'actor' => $actor,
            'payload' => $payload,
        ];
        return $state;
    }

    private function remember_request(array $state, string $requestId): array {
        $ids = (array)($state['runtime_state']['applied_request_ids'] ?? []);
        if (!in_array($requestId, $ids, true)) $ids[] = $requestId;
        if (count($ids) > 50) $ids = array_slice($ids, -50);
        $state['runtime_state']['applied_request_ids'] = $ids;
        return $state;
    }

    private function request_was_applied(array $state, string $requestId): bool {
        return in_array($requestId, (array)($state['runtime_state']['applied_request_ids'] ?? []), true);
    }

    private function error(array $state, string $code, string $message): array {
        return [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'state_version' => (int)($state['state_version'] ?? 0),
            'state' => $state,
        ];
    }

    private function clean_text(string $text): string {
        if (function_exists('sanitize_textarea_field')) return sanitize_textarea_field($text);
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text);
    }

    private function now_iso(array $context = []): string {
        $provided = trim((string)($context['now'] ?? ''));
        if ($provided !== '' && strtotime($provided) !== false) return date('c', strtotime($provided));
        return gmdate('c');
    }

    private function add_seconds(string $iso, int $seconds): string {
        $ts = strtotime($iso);
        if ($ts === false) $ts = time();
        return date('c', $ts + $seconds);
    }

    private function is_after_deadline(string $now, string $deadline): bool {
        if ($deadline === '') return false;
        $n = strtotime($now);
        $d = strtotime($deadline);
        return $n !== false && $d !== false && $n > $d;
    }

    private function message_id(string $sessionId, int $turn, int $generation): string {
        return 'exp-' . substr(hash('sha256', $sessionId . '|' . $turn . '|' . $generation), 0, 20);
    }
}

CKM_Negotiation_Runtime_Registry::register(new CKM_Negotiation_Express_Runtime());

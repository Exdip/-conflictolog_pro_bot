<?php
if (!defined('ABSPATH')) exit;

/**
 * Negotiation Core v1 persistence.
 *
 * Stores an authoritative full-state snapshot plus normalized turns/events and
 * idempotency responses. It is deliberately not exposed through REST/AJAX in
 * this build and therefore cannot reroute legacy negotiation_duel_v1 games.
 */

const CKM_NEGOTIATION_REPOSITORY_VERSION = 'negotiation_repository_v1';

final class CKM_Negotiation_Repository {
    private object $db;

    public function __construct(?object $db = null) {
        if ($db === null) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    public function create(array $state, array $meta = []): array {
        if ($this->is_error_envelope($state)) return $state;
        $sessionId = trim((string)($state['session_id'] ?? ''));
        if ($sessionId === '') return $this->error($state, 'session_id_required', 'Не указан session_id.');
        if ($this->session_row($sessionId)) return $this->error($state, 'session_exists', 'Сессия уже существует.');

        $now = $this->mysql_datetime((string)($meta['created_at'] ?? $meta['now'] ?? '')) ?: $this->mysql_now();
        $row = $this->session_row_from_state($state, $meta, $now, true);
        $this->begin();
        if ($this->db->insert(ckm_negotiation_core_table('sessions'), $row) === false) {
            $this->rollback();
            return $this->error($state, 'storage_session_insert_failed', $this->last_error());
        }
        if (!$this->persist_new_turns($state, 0, (int)($meta['tenant_id'] ?? 0))) {
            $this->rollback();
            return $this->error($state, 'storage_turn_insert_failed', $this->last_error());
        }
        if (!$this->persist_new_events($state, 0, (int)($meta['tenant_id'] ?? 0))) {
            $this->rollback();
            return $this->error($state, 'storage_event_insert_failed', $this->last_error());
        }
        $this->commit();
        return $state;
    }

    public function save_transition(array $before, array $after, array $request = []): array {
        if ($this->is_error_envelope($after)) return $after;
        $sessionId = trim((string)($after['session_id'] ?? ''));
        if ($sessionId === '') return $this->error($before, 'session_id_required', 'Не указан session_id.');
        $existing = $this->session_row($sessionId);
        if (!$existing) return $this->error($before, 'session_not_found', 'Сессия не найдена в хранилище.');

        $beforeVersion = (int)($before['state_version'] ?? 0);
        $afterVersion = (int)($after['state_version'] ?? 0);
        $storedVersion = (int)($existing['state_version'] ?? 0);
        if ($storedVersion !== $beforeVersion) {
            return $this->error($before, 'state_conflict', 'Состояние сессии уже изменилось.', [
                'current_version' => $storedVersion,
            ]);
        }

        $now = $this->mysql_datetime((string)($request['now'] ?? '')) ?: $this->mysql_now();
        if ($afterVersion === $beforeVersion && $this->encode($after) === $this->encode($before)) {
            $requestId = trim((string)($request['client_request_id'] ?? ''));
            if ($requestId !== '') {
                $action = sanitize_key((string)($request['action'] ?? ''));
                if (!$this->store_request($sessionId, $requestId, $action, $beforeVersion, $afterVersion, $after, $now)) {
                    return $this->error($after, 'storage_request_insert_failed', $this->last_error());
                }
            }
            return $after;
        }
        if ($afterVersion <= $beforeVersion) {
            return $this->error($before, 'state_version_not_advanced', 'Изменённое состояние должно увеличить state_version.');
        }
        $update = $this->session_row_from_state($after, [], $now, false);
        $this->begin();
        $updated = $this->db->update(ckm_negotiation_core_table('sessions'), $update, ['session_id' => $sessionId, 'state_version' => $beforeVersion]);
        if ($updated === false) {
            $this->rollback();
            return $this->error($before, 'storage_session_update_failed', $this->last_error());
        }
        if ((int)$updated === 0) {
            $this->rollback();
            $fresh = $this->session_row($sessionId);
            return $this->error($before, 'state_conflict', 'Состояние сессии уже изменилось.', [
                'current_version' => (int)($fresh['state_version'] ?? $storedVersion),
            ]);
        }

        $tenantId = (int)($existing['tenant_id'] ?? 0);
        $beforeMessageSeq = count((array)($before['message_history'] ?? []));
        $beforeEventSeq = (int)($before['runtime_state']['event_seq'] ?? 0);
        if (!$this->persist_new_turns($after, $beforeMessageSeq, $tenantId)) {
            $this->rollback();
            return $this->error($before, 'storage_turn_insert_failed', $this->last_error());
        }
        if (!$this->persist_new_events($after, $beforeEventSeq, $tenantId)) {
            $this->rollback();
            return $this->error($before, 'storage_event_insert_failed', $this->last_error());
        }

        $requestId = trim((string)($request['client_request_id'] ?? ''));
        if ($requestId !== '') {
            $action = sanitize_key((string)($request['action'] ?? ''));
            if (!$this->store_request($sessionId, $requestId, $action, $beforeVersion, (int)($after['state_version'] ?? $beforeVersion), $after, $now)) {
                $this->rollback();
                return $this->error($after, 'storage_request_insert_failed', $this->last_error());
            }
        }
        $this->commit();
        return $after;
    }

    public function load(string $sessionId): ?array {
        $row = $this->session_row($sessionId);
        if (!$row) return null;
        $state = $this->decode((string)($row['state_json'] ?? ''));
        if (!is_array($state) || !$state) return null;
        return $state;
    }

    public function request_response(string $sessionId, string $requestId): ?array {
        $requestId = trim($requestId);
        if ($requestId === '') return null;
        $table = ckm_negotiation_core_table('requests');
        $sql = $this->db->prepare("SELECT response_json FROM {$table} WHERE session_id=%s AND client_request_id=%s LIMIT 1", $sessionId, $requestId);
        $raw = $this->db->get_var($sql);
        if (!is_string($raw) || $raw === '') return null;
        $decoded = $this->decode($raw);
        return is_array($decoded) ? $decoded : null;
    }

    public function delete_test_session(string $sessionId): bool {
        if (strpos($sessionId, 'test_') !== 0 && strpos($sessionId, 'TEST-') !== 0) return false;
        foreach (['requests','events','turns','sessions'] as $logical) {
            $this->db->delete(ckm_negotiation_core_table($logical), ['session_id' => $sessionId]);
        }
        return true;
    }

    private function session_row_from_state(array $state, array $meta, string $now, bool $creating): array {
        $runtimeState = is_array($state['runtime_state'] ?? null) ? $state['runtime_state'] : [];
        $row = [
            'runtime' => sanitize_key((string)($state['runtime'] ?? '')),
            'status' => sanitize_key((string)($state['status'] ?? 'created')) ?: 'created',
            'phase' => sanitize_key((string)($state['phase'] ?? 'idle')) ?: 'idle',
            'turn_number' => max(0, (int)($state['turn_number'] ?? 0)),
            'state_version' => max(1, (int)($state['state_version'] ?? 1)),
            'host_mode' => sanitize_key((string)($state['host_mode'] ?? $meta['host_mode'] ?? 'ai')) ?: 'ai',
            'ai_generation' => max(0, (int)($state['ai_generation'] ?? 0)),
            'voice_generation' => max(0, (int)($state['voice']['generation'] ?? 0)),
            'deadline_at' => $this->mysql_datetime((string)($state['timer']['deadline_at'] ?? '')),
            'runtime_state_json' => $this->encode($runtimeState),
            'state_json' => $this->encode($state),
            'finished_at' => $this->mysql_datetime((string)($state['finished_at'] ?? '')),
            'updated_at' => $now,
        ];
        if ($creating) {
            $row = [
                'tenant_id' => max(0, (int)($meta['tenant_id'] ?? 0)),
                'game_id' => max(0, (int)($meta['game_id'] ?? 0)),
                'session_id' => (string)$state['session_id'],
                'started_at' => $this->mysql_datetime((string)($state['started_at'] ?? $meta['started_at'] ?? $now)) ?: $now,
                'created_at' => $now,
            ] + $row;
        }
        return $row;
    }

    private function persist_new_turns(array $state, int $alreadyPersisted, int $tenantId): bool {
        $history = array_values((array)($state['message_history'] ?? []));
        $table = ckm_negotiation_core_table('turns');
        foreach ($history as $index => $message) {
            $messageSeq = $index + 1;
            if ($messageSeq <= $alreadyPersisted || !is_array($message)) continue;
            $row = [
                'tenant_id' => $tenantId,
                'session_id' => (string)($state['session_id'] ?? ''),
                'message_seq' => $messageSeq,
                'turn_number' => max(0, (int)($message['turn_number'] ?? $state['turn_number'] ?? 0)),
                'actor' => sanitize_key((string)($message['actor'] ?? 'system')) ?: 'system',
                'message_type' => sanitize_key((string)($message['message_type'] ?? 'message')) ?: 'message',
                'message_text' => (string)($message['text'] ?? $message['message_text'] ?? ''),
                'input_mode' => sanitize_key((string)($message['input_mode'] ?? 'system')) ?: 'system',
                'phase' => sanitize_key((string)($message['phase'] ?? $state['phase'] ?? '')),
                'runtime' => sanitize_key((string)($state['runtime'] ?? '')),
                'analysis_json' => $this->encode((array)($message['analysis'] ?? [])),
                'evaluation_json' => $this->encode((array)($message['evaluation'] ?? [])),
                'created_at' => $this->mysql_datetime((string)($message['created_at'] ?? '')) ?: $this->mysql_now(),
            ];
            if ($this->db->insert($table, $row) === false) return false;
        }
        return true;
    }

    private function persist_new_events(array $state, int $afterSeq, int $tenantId): bool {
        $events = (array)($state['runtime_state']['events'] ?? []);
        $table = ckm_negotiation_core_table('events');
        foreach ($events as $event) {
            if (!is_array($event)) continue;
            $seq = (int)($event['event_seq'] ?? 0);
            if ($seq <= $afterSeq) continue;
            $row = [
                'tenant_id' => $tenantId,
                'session_id' => (string)($state['session_id'] ?? ''),
                'event_seq' => $seq,
                'event_type' => sanitize_key((string)($event['event_type'] ?? '')),
                'phase' => sanitize_key((string)($event['phase'] ?? $state['phase'] ?? '')),
                'turn_number' => max(0, (int)($event['turn_number'] ?? $state['turn_number'] ?? 0)),
                'actor' => sanitize_key((string)($event['actor'] ?? 'system')) ?: 'system',
                'payload_json' => $this->encode((array)($event['payload'] ?? [])),
                'created_at' => $this->mysql_now(),
            ];
            if ($this->db->insert($table, $row) === false) return false;
        }
        return true;
    }

    private function store_request(string $sessionId, string $requestId, string $action, int $before, int $after, array $response, string $now): bool {
        $table = ckm_negotiation_core_table('requests');
        $existing = $this->request_response($sessionId, $requestId);
        if (is_array($existing)) return true;
        return $this->db->insert($table, [
            'session_id' => $sessionId,
            'client_request_id' => $requestId,
            'action' => $action,
            'state_version_before' => $before,
            'state_version_after' => $after,
            'response_json' => $this->encode($response),
            'created_at' => $now,
        ]) !== false;
    }

    private function session_row(string $sessionId): ?array {
        $table = ckm_negotiation_core_table('sessions');
        $sql = $this->db->prepare("SELECT * FROM {$table} WHERE session_id=%s LIMIT 1", $sessionId);
        $row = $this->db->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        return is_array($row) ? $row : null;
    }

    private function encode($value): string {
        if (function_exists('wp_json_encode')) {
            $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return is_string($encoded) ? $encoded : '{}';
    }

    private function decode(string $json) {
        if ($json === '') return null;
        return json_decode($json, true);
    }

    private function mysql_datetime(string $value): ?string {
        $value = trim($value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private function mysql_now(): string {
        if (function_exists('current_time')) return (string)current_time('mysql', true);
        return gmdate('Y-m-d H:i:s');
    }


    private function begin(): void {
        if (method_exists($this->db, 'query')) $this->db->query('START TRANSACTION');
    }

    private function commit(): void {
        if (method_exists($this->db, 'query')) $this->db->query('COMMIT');
    }

    private function rollback(): void {
        if (method_exists($this->db, 'query')) $this->db->query('ROLLBACK');
    }

    private function last_error(): string {
        $error = trim((string)($this->db->last_error ?? ''));
        return $error !== '' ? $error : 'Ошибка хранилища переговорной сессии.';
    }

    private function is_error_envelope(array $value): bool {
        return array_key_exists('ok', $value) && $value['ok'] === false && isset($value['code']);
    }

    private function error(array $state, string $code, string $message, array $extra = []): array {
        return $extra + [
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'state_version' => (int)($state['state_version'] ?? 0),
            'state' => $state,
        ];
    }
}

/**
 * Small orchestration layer used by smoke tests now and by a future endpoint
 * adapter later. Nothing registers a public route in this build.
 */
final class CKM_Negotiation_Persisted_Runtime_Service {
    private CKM_Negotiation_Repository $repository;

    public function __construct(?CKM_Negotiation_Repository $repository = null) {
        $this->repository = $repository ?: new CKM_Negotiation_Repository();
    }

    public function create_session(string $runtimeKey, array $context, array $meta = []): array {
        $runtime = CKM_Negotiation_Runtime_Registry::get($runtimeKey);
        if (!$runtime) return $this->error([], 'runtime_not_found', 'Runtime не найден.');
        $state = $runtime->create_session($context);
        if ($this->is_error($state)) return $state;
        return $this->repository->create($state, $meta + ['now' => (string)($context['now'] ?? '')]);
    }

    public function state(string $sessionId): array {
        $stored = $this->repository->load($sessionId);
        if (!$stored) return $this->error([], 'session_not_found', 'Сессия не найдена.');
        $runtime = CKM_Negotiation_Runtime_Registry::get((string)($stored['runtime'] ?? ''));
        if (!$runtime) return $this->error($stored, 'runtime_not_found', 'Runtime не найден.');
        return $runtime->restore_session($stored);
    }

    public function apply(string $sessionId, string $action, array $command = [], array $context = []): array {
        $before = $this->repository->load($sessionId);
        if (!$before) return $this->error([], 'session_not_found', 'Сессия не найдена.');

        $requestId = trim((string)($command['client_request_id'] ?? ''));
        if ($requestId !== '') {
            $previous = $this->repository->request_response($sessionId, $requestId);
            if (is_array($previous)) return $previous;
        }

        if (array_key_exists('expected_version', $command)) {
            $expected = (int)$command['expected_version'];
            $current = (int)($before['state_version'] ?? 0);
            if ($expected !== $current) {
                return $this->error($before, 'state_conflict', 'Состояние сессии уже изменилось.', ['current_version' => $current]);
            }
        }

        $runtime = CKM_Negotiation_Runtime_Registry::get((string)($before['runtime'] ?? ''));
        if (!$runtime) return $this->error($before, 'runtime_not_found', 'Runtime не найден.');

        $command['action'] = $action;
        if ($action === 'submit_answer') {
            $after = $runtime->submit_player_message($before, $command);
        } elseif ($action === 'evaluate_turn') {
            $after = $runtime->evaluate_turn($before, $context + $command);
        } elseif (in_array($action, ['voice_finished','next_case','finish_session'], true)) {
            $after = $runtime->advance_phase($before, $command);
        } else {
            return $this->error($before, 'action_not_allowed', 'Неизвестное действие persistence service.');
        }
        if ($this->is_error($after)) return $after;

        return $this->repository->save_transition($before, $after, [
            'client_request_id' => $requestId,
            'action' => $action,
            'now' => (string)($command['now'] ?? ''),
        ]);
    }

    private function is_error(array $value): bool {
        return array_key_exists('ok', $value) && $value['ok'] === false && isset($value['code']);
    }

    private function error(array $state, string $code, string $message, array $extra = []): array {
        return $extra + ['ok'=>false,'code'=>$code,'message'=>$message,'state_version'=>(int)($state['state_version']??0),'state'=>$state];
    }
}

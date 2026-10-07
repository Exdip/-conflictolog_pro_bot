<?php
if (!defined('ABSPATH')) exit;

/**
 * CKM Negotiation Core v1
 *
 * This file intentionally does not route any existing game to the new core.
 * It only defines the common runtime contract, registry and state helpers that
 * future negotiation runtimes can opt into explicitly.
 */

const CKM_NEGOTIATION_CORE_VERSION = 'negotiation_core_v1';

interface CKM_Negotiation_Runtime_Interface {
    public function runtime_key(): string;
    public function create_session(array $context): array;
    public function get_state(array $session): array;
    public function get_available_actions(array $state): array;
    public function submit_player_message(array $session, array $command): array;
    public function handle_ai_turn(array $session, array $context = []): array;
    public function advance_phase(array $session, array $command = []): array;
    public function evaluate_turn(array $session, array $context = []): array;
    public function check_completion(array $session): array;
    public function finish_session(array $session, array $context = []): array;
    public function restore_session(array $session): array;
}

final class CKM_Negotiation_Runtime_Registry {
    /** @var array<string,CKM_Negotiation_Runtime_Interface> */
    private static array $runtimes = [];

    public static function register(CKM_Negotiation_Runtime_Interface $runtime): void {
        $key = sanitize_key($runtime->runtime_key());
        if ($key === '') {
            throw new InvalidArgumentException('Negotiation runtime key must not be empty.');
        }
        self::$runtimes[$key] = $runtime;
    }

    public static function get(string $runtimeKey): ?CKM_Negotiation_Runtime_Interface {
        $key = sanitize_key($runtimeKey);
        return self::$runtimes[$key] ?? null;
    }

    /** @return string[] */
    public static function keys(): array {
        return array_keys(self::$runtimes);
    }

    public static function has(string $runtimeKey): bool {
        return self::get($runtimeKey) instanceof CKM_Negotiation_Runtime_Interface;
    }
}

function ckm_negotiation_core_table(string $logical): string {
    global $wpdb;
    $map = [
        'sessions' => 'ckm_negotiation_sessions',
        'turns' => 'ckm_negotiation_turns',
        'events' => 'ckm_negotiation_events',
        'requests' => 'ckm_negotiation_requests',
    ];
    return isset($map[$logical]) ? $wpdb->prefix . $map[$logical] : '';
}

/**
 * New-core routing is disabled by default. No legacy game changes behaviour
 * merely because this file is present or because the schema is installed.
 */
function ckm_negotiation_core_routing_enabled(): bool {
    return (bool) apply_filters('ckm_negotiation_core_routing_enabled', false);
}

function ckm_negotiation_core_state_template(string $runtimeKey, string $sessionId = ''): array {
    return [
        'session_id' => $sessionId,
        'runtime' => sanitize_key($runtimeKey),
        'status' => 'created',
        'phase' => 'idle',
        'turn_number' => 0,
        'state_version' => 1,
        'player_role' => '',
        'opponent_role' => '',
        'message_history' => [],
        'current_prompt' => null,
        'current_task' => null,
        'voice' => [
            'generation' => 0,
            'message_id' => '',
            'status' => 'idle',
        ],
        'timer' => [
            'enabled' => false,
            'starts_after_voice' => false,
            'duration_seconds' => 0,
            'started_at' => null,
            'deadline_at' => null,
        ],
        'available_actions' => [],
        'score' => null,
        'runtime_state' => [],
        'is_finished' => false,
        'result' => null,
    ];
}

function ckm_negotiation_core_runtime_contract(): array {
    return [
        'create_session',
        'get_state',
        'get_available_actions',
        'submit_player_message',
        'handle_ai_turn',
        'advance_phase',
        'evaluate_turn',
        'check_completion',
        'finish_session',
        'restore_session',
    ];
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Closed test-only REST adapter for Negotiation Core v1.
 *
 * Safety properties:
 * - disabled by default;
 * - can be enabled explicitly with CKM_NEGOTIATION_TEST_API_ENABLED, a filter,
 *   or a short-lived per-admin smoke window opened by the hidden smoke page;
 * - WordPress administrator capability is required;
 * - only TEST-API-* sessions are accepted;
 * - only negotiation_express_v1 can be started;
 * - tenant_id/game_id are forced to zero, so no paid/real game is attached;
 * - every mutating command after start requires expected_version and client_request_id.
 *
 * This is intentionally NOT a production participant/host API.
 */

const CKM_NEGOTIATION_TEST_API_VERSION = 'negotiation_test_api_v1';
const CKM_NEGOTIATION_TEST_API_NAMESPACE = 'ckm-quiz-pro/v1';

function ckm_negotiation_test_api_enabled(): bool {
    $enabled = defined('CKM_NEGOTIATION_TEST_API_ENABLED')
        ? (bool) CKM_NEGOTIATION_TEST_API_ENABLED
        : false;
    $enabled = (bool) apply_filters('ckm_negotiation_test_api_enabled', $enabled);
    if ($enabled) return true;

    // The hidden admin smoke page may open a short-lived window for the
    // current administrator. This never enables the API for another user.
    if (function_exists('get_current_user_id') && function_exists('get_transient')
        && function_exists('current_user_can') && current_user_can('manage_options')) {
        $uid = (int) get_current_user_id();
        if ($uid > 0 && get_transient('ckm_negotiation_smoke_api_' . $uid)) return true;
    }
    return false;
}

function ckm_negotiation_test_api_is_test_session(string $sessionId): bool {
    return strpos($sessionId, 'TEST-API-') === 0;
}

function ckm_negotiation_test_api_permission($request = null) {
    if (!ckm_negotiation_test_api_enabled()) {
        return class_exists('WP_Error')
            ? new WP_Error('test_api_disabled', 'Тестовый API отключён.', ['status' => 404])
            : false;
    }
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        return class_exists('WP_Error')
            ? new WP_Error('forbidden', 'Недостаточно прав.', ['status' => 403])
            : false;
    }
    return true;
}

final class CKM_Negotiation_Test_API {
    private CKM_Negotiation_Persisted_Runtime_Service $service;

    public function __construct(?CKM_Negotiation_Persisted_Runtime_Service $service = null) {
        $this->service = $service ?: new CKM_Negotiation_Persisted_Runtime_Service();
    }

    public function start(array $params): array {
        if (function_exists('ckm_negotiation_storage_ensure')) {
            $storage = ckm_negotiation_storage_ensure();
            if (empty($storage['ok'])) {
                $details = wp_json_encode($storage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return $this->error([], 'storage_schema_unavailable', 'Хранилище Negotiation Core не готово: ' . (is_string($details) ? $details : 'unknown'));
            }
        }
        $runtime = sanitize_key((string)($params['runtime'] ?? CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION));
        if ($runtime !== CKM_NEGOTIATION_EXPRESS_RUNTIME_VERSION) {
            return $this->error([], 'runtime_not_allowed', 'Test API разрешает запуск только negotiation_express_v1.');
        }

        $sessionId = $this->clean_session_id((string)($params['session_id'] ?? ''));
        if ($sessionId === '') $sessionId = $this->new_test_session_id();
        if (!ckm_negotiation_test_api_is_test_session($sessionId)) {
            return $this->error([], 'test_session_required', 'Test API работает только с сессиями TEST-API-*.');
        }

        $cases = is_array($params['cases'] ?? null) ? array_values($params['cases']) : [];
        if (!$cases) return $this->error([], 'cases_required', 'Для тестовой сессии нужен хотя бы один кейс.');
        if (count($cases) > 50) return $this->error([], 'too_many_cases', 'В test API допускается не более 50 кейсов.');

        $context = [
            'session_id' => $sessionId,
            'cases' => $cases,
            'player_role' => $this->clean_text((string)($params['player_role'] ?? 'Команда')),
            'opponent_role' => $this->clean_text((string)($params['opponent_role'] ?? 'Оппонент')),
        ];
        if ($this->valid_time((string)($params['now'] ?? ''))) $context['now'] = (string)$params['now'];

        $state = $this->service->create_session($runtime, $context, [
            'tenant_id' => 0,
            'game_id' => 0,
            'host_mode' => 'ai',
            'now' => (string)($context['now'] ?? ''),
        ]);
        return $this->success_or_error($state);
    }

    public function state(array $params): array {
        $sessionId = $this->required_test_session($params);
        if (is_array($sessionId)) return $sessionId;
        return $this->success_or_error($this->service->state($sessionId));
    }

    public function voice_finished(array $params): array {
        return $this->mutate($params, 'voice_finished', [
            'message_id' => $this->clean_text((string)($params['message_id'] ?? '')),
            'voice_generation' => (int)($params['voice_generation'] ?? -1),
        ]);
    }

    public function submit(array $params): array {
        return $this->mutate($params, 'submit_answer', [
            'text' => $this->clean_text((string)($params['text'] ?? '')),
            'input_mode' => sanitize_key((string)($params['input_mode'] ?? 'text')) ?: 'text',
        ]);
    }

    public function evaluate(array $params): array {
        $context = [];
        if (isset($params['mock_evaluation']) && is_array($params['mock_evaluation'])) {
            // Explicitly test-only. Production runtime must use its real evaluator.
            $context['mock_evaluation'] = $params['mock_evaluation'];
        }
        return $this->mutate($params, 'evaluate_turn', [], $context);
    }

    public function next(array $params): array {
        return $this->mutate($params, 'next_case');
    }

    public function finish(array $params): array {
        return $this->mutate($params, 'finish_session', [
            'force' => !empty($params['force']),
        ]);
    }

    private function mutate(array $params, string $action, array $extraCommand = [], array $context = []): array {
        $sessionId = $this->required_test_session($params);
        if (is_array($sessionId)) return $sessionId;

        if (!array_key_exists('expected_version', $params)) {
            return $this->error([], 'expected_version_required', 'Для изменяющего запроса нужен expected_version.');
        }
        $expectedVersion = (int)$params['expected_version'];
        if ($expectedVersion < 1) {
            return $this->error([], 'expected_version_invalid', 'expected_version должен быть положительным числом.');
        }

        $requestId = $this->clean_request_id((string)($params['client_request_id'] ?? ''));
        if ($requestId === '') {
            return $this->error([], 'client_request_id_required', 'Для изменяющего запроса нужен client_request_id.');
        }

        $command = $extraCommand + [
            'expected_version' => $expectedVersion,
            'client_request_id' => $requestId,
        ];
        if ($this->valid_time((string)($params['now'] ?? ''))) $command['now'] = (string)$params['now'];

        $result = $this->service->apply($sessionId, $action, $command, $context);
        return $this->success_or_error($result);
    }

    /** @return string|array */
    private function required_test_session(array $params) {
        $sessionId = $this->clean_session_id((string)($params['session_id'] ?? ''));
        if ($sessionId === '') return $this->error([], 'session_id_required', 'Не указан session_id.');
        if (!ckm_negotiation_test_api_is_test_session($sessionId)) {
            return $this->error([], 'test_session_required', 'Test API работает только с сессиями TEST-API-*.');
        }
        return $sessionId;
    }

    private function success_or_error(array $result): array {
        if (array_key_exists('ok', $result) && $result['ok'] === false && isset($result['code'])) return $result;
        return [
            'ok' => true,
            'state_version' => (int)($result['state_version'] ?? 0),
            'state' => $result,
        ];
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

    private function clean_session_id(string $value): string {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9_-]/', '', $value) ?? '';
        return substr($value, 0, 64);
    }

    private function clean_request_id(string $value): string {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9._:-]/', '', $value) ?? '';
        return substr($value, 0, 96);
    }

    private function clean_text(string $value): string {
        if (function_exists('sanitize_textarea_field')) return sanitize_textarea_field($value);
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value);
    }

    private function valid_time(string $value): bool {
        return trim($value) !== '' && strtotime($value) !== false;
    }

    private function new_test_session_id(): string {
        $suffix = function_exists('wp_generate_uuid4')
            ? str_replace('-', '', (string)wp_generate_uuid4())
            : hash('sha256', uniqid('', true));
        return 'TEST-API-' . strtoupper(substr($suffix, 0, 16));
    }
}

function ckm_negotiation_test_api_status_for(array $payload): int {
    if (!empty($payload['ok'])) return 200;
    $code = (string)($payload['code'] ?? 'error');
    if (in_array($code, ['session_not_found','runtime_not_found','test_api_disabled'], true)) return 404;
    if (in_array($code, ['state_conflict','session_exists','answer_locked','session_finished','voice_generation_mismatch','voice_message_mismatch'], true)) return 409;
    if (in_array($code, ['wrong_phase','action_not_allowed','timer_not_started','timer_expired','session_not_complete'], true)) return 422;
    if (strpos($code, 'storage_') === 0) return 500;
    return 400;
}

function ckm_negotiation_test_api_request_params($request): array {
    if (is_array($request)) return $request;
    $params = [];
    if (is_object($request) && method_exists($request, 'get_params')) {
        $all = $request->get_params();
        if (is_array($all)) $params = $all;
    }
    if (is_object($request) && method_exists($request, 'get_json_params')) {
        $json = $request->get_json_params();
        if (is_array($json)) $params = array_replace($params, $json);
    }
    return $params;
}

function ckm_negotiation_test_api_rest_response(array $payload) {
    $status = ckm_negotiation_test_api_status_for($payload);
    if (class_exists('WP_REST_Response')) {
        $response = new WP_REST_Response($payload, $status);
        if (method_exists($response, 'header')) $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
    return $payload;
}

function ckm_negotiation_test_api_register_routes(): void {
    // Routes are always registered so a hidden administrator smoke page can
    // open its short-lived test window after normal plugin bootstrap. Access
    // is still closed by ckm_negotiation_test_api_permission().
    if (!function_exists('register_rest_route')) return;

    $api = new CKM_Negotiation_Test_API();
    $routes = [
        '/negotiation-test/start' => ['POST', 'start'],
        '/negotiation-test/state' => ['GET', 'state'],
        '/negotiation-test/voice-finished' => ['POST', 'voice_finished'],
        '/negotiation-test/submit' => ['POST', 'submit'],
        '/negotiation-test/evaluate' => ['POST', 'evaluate'],
        '/negotiation-test/next' => ['POST', 'next'],
        '/negotiation-test/finish' => ['POST', 'finish'],
    ];

    foreach ($routes as $route => [$method, $handler]) {
        register_rest_route(CKM_NEGOTIATION_TEST_API_NAMESPACE, $route, [
            'methods' => $method,
            'callback' => static function($request) use ($api, $handler) {
                $payload = $api->{$handler}(ckm_negotiation_test_api_request_params($request));
                return ckm_negotiation_test_api_rest_response($payload);
            },
            'permission_callback' => 'ckm_negotiation_test_api_permission',
        ]);
    }
}
add_action('rest_api_init', 'ckm_negotiation_test_api_register_routes');

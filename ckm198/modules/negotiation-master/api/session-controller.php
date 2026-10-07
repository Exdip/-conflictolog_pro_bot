<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class SessionController {
    public static function register(): void {
        register_rest_route('ckm/v1', '/negotiation/sessions/start', [
            'methods' => 'POST', 'callback' => [self::class, 'start'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/messages', [
            'methods' => 'POST', 'callback' => [self::class, 'message'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/resume', [
            'methods' => 'GET', 'callback' => [self::class, 'resume'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/pause', [
            'methods' => 'POST', 'callback' => [self::class, 'pause'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/retry-opponent', [
            'methods' => 'POST', 'callback' => [self::class, 'retryOpponent'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/coach', [
            'methods' => 'POST', 'callback' => [self::class, 'coach'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/agreements/draft', [
            'methods' => 'POST', 'callback' => [self::class, 'agreementDraft'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/agreements/propose', [
            'methods' => 'POST', 'callback' => [self::class, 'agreementPropose'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/finish-without-agreement/preview', [
            'methods' => 'POST', 'callback' => [self::class, 'noDealPreview'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/finish-without-agreement/confirm', [
            'methods' => 'POST', 'callback' => [self::class, 'noDealConfirm'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/results/sessions/(?P<id>\d+)', [
            'methods' => 'GET', 'callback' => [self::class, 'result'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/results/sessions/(?P<id>\d+)/evaluate', [
            'methods' => 'POST', 'callback' => [self::class, 'evaluate'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/writer/takeover', [
            'methods' => 'POST', 'callback' => [self::class, 'takeoverWriter'], 'permission_callback' => [self::class, 'permission'],
        ]);
        register_rest_route('ckm/v1', '/negotiation/sessions/(?P<id>\d+)/recovery-diagnostics', [
            'methods' => 'GET', 'callback' => [self::class, 'recoveryDiagnostics'], 'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function permission() {
        if (!is_user_logged_in()) { return new \WP_Error('neg_auth_required', 'Требуется вход.', ['status'=>401]); }
        try { Access::context(); return true; }
        catch (\Throwable) { return new \WP_Error('neg_access_denied', 'Доступ запрещён.', ['status'=>403]); }
    }

    public static function adminPermission() {
        if (!is_user_logged_in()) { return new \WP_Error('neg_auth_required', 'Требуется вход.', ['status'=>401]); }
        try { Access::admin(); return true; }
        catch (\Throwable) { return new \WP_Error('neg_access_denied', 'Доступ запрещён.', ['status'=>403]); }
    }

    private static function clientId(array $data): string {
        return RecoveryService::normalizeClientId((string)($data['client_id'] ?? ''));
    }

    private static function assertWriter(int $sessionId, array $data): RecoveryService {
        (new AssignmentService())->assertSessionWritable($sessionId);
        $recovery = new RecoveryService();
        $recovery->assertWriter($sessionId, self::clientId($data));
        return $recovery;
    }

    private static function body($request): array {
        $data = method_exists($request, 'get_json_params') ? $request->get_json_params() : null;
        if (!is_array($data) || !$data) { $data = method_exists($request, 'get_params') ? $request->get_params() : []; }
        return is_array($data) ? $data : [];
    }

    private static function flag(mixed $value): bool {
        if (is_bool($value)) { return $value; }
        return in_array(strtolower((string) $value), ['1','true','yes','on'], true);
    }

    private static function failure(\Throwable $error, int $fallback = 422): \WP_REST_Response {
        if ($error instanceof OffTopicMessageException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_OFF_TOPIC','message'=>$error->getMessage()], 422, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof WriterConflictException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'WRITER_CONFLICT','message'=>$error->getMessage()], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof RecoveryBusyException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'RECOVERY_BUSY','message'=>'Сессия уже обрабатывается. Повторите запрос после обновления состояния.'], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof StateConflictException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'STATE_CONFLICT','message'=>$error->getMessage()], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof SessionCompletedException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'SESSION_COMPLETED','message'=>$error->getMessage()], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof AgreementValidationException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'AGREEMENT_VALIDATION_FAILED','message'=>$error->getMessage(),'details'=>$error->details], 422, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof AgreementResponseUnavailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_AGREEMENT_OPPONENT_UNAVAILABLE','message'=>'Не удалось получить решение оппонента по итоговому пакету.'], 503, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof OpponentBusyException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_OPPONENT_BUSY','message'=>'Ответ оппонента уже формируется.'], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof OpponentUnavailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_OPPONENT_UNAVAILABLE','message'=>'Не удалось получить ответ оппонента.'], 503, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof AssignmentAccessDeniedException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_FORBIDDEN','message'=>$error->getMessage()], 403, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof AssignmentAttemptLimitException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_ATTEMPT_LIMIT','message'=>$error->getMessage()], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof AssignmentUnavailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_ASSIGNMENT_UNAVAILABLE','message'=>$error->getMessage()], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof LibraryAccessDeniedException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_LIBRARY_ACCESS_REQUIRED','message'=>$error->getMessage()], 403, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof CoachNotAvailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'COACH_NOT_AVAILABLE_IN_EXAM','message'=>'ИИ-тренер недоступен в режиме «Экзамен».'], 403, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof CoachBusyException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_COACH_BUSY','message'=>'Подождите завершения текущего хода.'], 409, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof CoachUnavailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_COACH_UNAVAILABLE','message'=>'Не удалось получить подсказку тренера.'], 503, ['Cache-Control'=>'no-store, private']);
        }
        if ($error instanceof EvaluationUnavailableException) {
            return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_EVALUATION_UNAVAILABLE','message'=>'Итоговый разбор временно недоступен. Можно повторить расчёт позже.'], 503, ['Cache-Control'=>'no-store, private']);
        }
        $status = $error instanceof \InvalidArgumentException ? 422 : $fallback;
        $message = $status === 403 ? 'Доступ запрещён.' : $error->getMessage();
        return new \WP_REST_Response(['ok'=>false,'code'=>'NEG_SESSION_ERROR','message'=>$message], $status, ['Cache-Control'=>'no-store, private']);
    }

    public static function start($request): \WP_REST_Response {
        try {
            $data = self::body($request);
            $clientId = self::clientId($data);
            $service = new SessionService();
            $scenarioId = (int) ($data['scenario_id'] ?? 0);
            $restart = self::flag($data['restart'] ?? false);
            if ($restart) {
                $active = $service->activeForScenario($scenarioId);
                if ($active && ($active['status'] ?? '') === 'in_progress') { (new RecoveryService())->assertWriter((int)$active['id'], $clientId); }
            }
            $result = $service->start(
                $scenarioId,
                (string) ($data['mode'] ?? 'training'),
                self::flag($data['voice_enabled'] ?? false),
                $restart,
                (string) ($data['difficulty'] ?? 'medium')
            );
            if (!empty($result['active_exists'])) {
                return new \WP_REST_Response(['ok'=>false,'code'=>'ACTIVE_SESSION_EXISTS','session_id'=>$result['session_id'],'status'=>$result['status']], 409, ['Cache-Control'=>'no-store, private']);
            }
            (new RecoveryService())->assertWriter((int)$result['session_id'], $clientId);
            $result['snapshot'] = (new PlayerSessionSnapshotBuilder())->build((int)$result['session_id']);
            return new \WP_REST_Response(['ok'=>true] + $result, 201, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error); }
    }

    public static function resume($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            (new SessionService())->resume($id);
            $recovery = (new RecoveryService())->recover($id);
            return new \WP_REST_Response(['ok'=>true,'snapshot'=>$recovery['snapshot'],'recovery'=>['recovered'=>!empty($recovery['recovered']),'busy'=>!empty($recovery['busy'])]], 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function pause($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            $recovery = self::assertWriter($id, $data);
            $snapshot = (new SessionService())->pause($id);
            $recovery->releaseWriter($id, self::clientId($data));
            return new \WP_REST_Response(['ok'=>true,'snapshot'=>$snapshot], 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function message($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $result = (new MessageService())->send(
                $id,
                (string) ($data['client_message_id'] ?? ''),
                (string) ($data['content'] ?? ''),
                (string) ($data['input_type'] ?? 'text')
            );
            $status = !empty($result['opponent_failed']) || !empty($result['opponent_pending']) ? 202 : (!empty($result['idempotent']) ? 200 : 201);
            return new \WP_REST_Response(['ok'=>true] + $result, $status, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function retryOpponent($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $playerMessageId = (int) ($data['player_message_id'] ?? 0);
            if ($playerMessageId <= 0) { throw new \InvalidArgumentException('Player message is required.'); }
            $result = (new MessageService())->retryOpponent($id, $playerMessageId);
            return new \WP_REST_Response(['ok'=>true] + $result, 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function coach($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $level = (string)($data['help_level'] ?? '');
            $clientRequestId = (string)($data['client_request_id'] ?? '');
            $result = (new CoachService())->help($id, $level, $clientRequestId);
            return new \WP_REST_Response(['ok'=>true] + $result, !empty($result['idempotent']) ? 200 : 201, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function agreementDraft($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $revision = isset($data['expected_state_revision']) ? (int)$data['expected_state_revision'] : null;
            $result = (new AgreementService())->draft($id, $revision);
            return new \WP_REST_Response(['ok'=>true] + $result, !empty($result['idempotent']) ? 200 : 201, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function agreementPropose($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $agreementId = (int)($data['agreement_id'] ?? 0);
            if ($agreementId <= 0) { throw new \InvalidArgumentException('Agreement is required.'); }
            $revision = isset($data['expected_state_revision']) ? (int)$data['expected_state_revision'] : null;
            $result = (new AgreementService())->propose($id, $agreementId, $revision);
            return new \WP_REST_Response(['ok'=>true] + $result, 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function noDealPreview($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $result = (new NoDealService())->preview($id);
            return new \WP_REST_Response(['ok'=>true] + $result, 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function noDealConfirm($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            self::assertWriter($id, $data);
            $token = (string)($data['confirmation_token'] ?? '');
            if ($token === '') { throw new \InvalidArgumentException('Confirmation token is required.'); }
            $result = (new NoDealService())->confirm($id, $token, (string)($data['comment'] ?? ''));
            return new \WP_REST_Response(['ok'=>true] + $result + ['snapshot'=>(new PlayerSessionSnapshotBuilder())->build($id)], 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function result($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $result = (new EvaluationService())->result($id);
            $gate = (new AssignmentService())->resultVisibleForSession($id);
            if (empty($gate['visible'])) {
                $result = ['ready'=>false,'hidden'=>true,'status'=>(string)($result['status'] ?? 'pending'),'message'=>(string)$gate['message']];
            }
            return new \WP_REST_Response(['ok'=>true,'result'=>$result], 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function evaluate($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $result = (new EvaluationService())->evaluate($id);
            $gate = (new AssignmentService())->resultVisibleForSession($id);
            if (empty($gate['visible'])) {
                $result = ['ready'=>false,'hidden'=>true,'status'=>(string)($result['status'] ?? 'processing'),'message'=>(string)$gate['message']];
            }
            return new \WP_REST_Response(['ok'=>true,'result'=>$result], ($result['ready'] ?? false) ? 200 : 202, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function takeoverWriter($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            $data = self::body($request);
            (new AssignmentService())->assertSessionWritable($id);
            $result = (new RecoveryService())->takeover($id, self::clientId($data));
            return new \WP_REST_Response(['ok'=>true] + $result, 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }

    public static function recoveryDiagnostics($request): \WP_REST_Response {
        try {
            $id = (int) (method_exists($request, 'get_param') ? $request->get_param('id') : 0);
            return new \WP_REST_Response(['ok'=>true,'diagnostics'=>(new RecoveryService())->diagnostics($id)], 200, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) { return self::failure($error, 403); }
    }


}

<?php
if (!defined('ABSPATH')) { exit; }

/**
 * CKM completion sound signal.
 *
 * Lets an administrator keep a WordPress admin/console page open and receive
 * an audible signal when an authenticated automation posts a completion event.
 * Front-end game/host/team pages intentionally render no sound controls and do
 * not poll this endpoint.
 */
final class CKM_Completion_Signal {
    private const OPTION = 'ckm_completion_signal';
    private const ROUTE  = '/completion-signal';

    public static function boot(): void {
        add_action('rest_api_init', [self::class, 'register_rest']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin'], 99);
        add_action('admin_footer', [self::class, 'render_controls'], 99);
    }

    public static function register_rest(): void {
        register_rest_route('ckm/v1', self::ROUTE, [
            [
                'methods' => 'GET',
                'callback' => [self::class, 'read'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods' => 'POST',
                'callback' => [self::class, 'trigger'],
                'permission_callback' => [self::class, 'can_trigger'],
            ],
        ]);
    }

    public static function can_trigger(): bool {
        return current_user_can('manage_options');
    }

    public static function read(): \WP_REST_Response {
        $signal = get_option(self::OPTION, []);
        if (!is_array($signal)) { $signal = []; }
        return new \WP_REST_Response([
            'ok' => true,
            'signal' => [
                'id' => (string)($signal['id'] ?? ''),
                'message' => (string)($signal['message'] ?? ''),
                'voice' => !empty($signal['voice']),
                'created_at' => (string)($signal['created_at'] ?? ''),
            ],
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    public static function trigger(\WP_REST_Request $request): \WP_REST_Response {
        $data = $request->get_json_params();
        if (!is_array($data)) { $data = []; }
        $message = sanitize_text_field((string)($data['message'] ?? 'Готово. Задание выполнено.'));
        if ($message === '') { $message = 'Готово. Задание выполнено.'; }
        if (function_exists('mb_substr')) { $message = mb_substr($message, 0, 160); }
        else { $message = substr($message, 0, 160); }
        $voice = !array_key_exists('voice', $data) || filter_var($data['voice'], FILTER_VALIDATE_BOOLEAN);
        $signal = [
            'id' => wp_generate_uuid4(),
            'message' => $message,
            'voice' => $voice,
            'created_at' => gmdate('c'),
        ];
        update_option(self::OPTION, $signal, false);
        return new \WP_REST_Response(['ok' => true, 'signal' => $signal], 200, ['Cache-Control' => 'no-store, private']);
    }

    private static function should_render(): bool {
        if (!is_user_logged_in() || !current_user_can('manage_options')) { return false; }
        if (wp_doing_ajax()) { return false; }
        return true;
    }

    public static function enqueue_admin(): void {
        if (!self::should_render()) { return; }
        self::enqueue_assets();
    }

    private static function enqueue_assets(): void {
        $base = CKM_QUIZ_PRO_URL . 'assets/completion-signal/';
        wp_enqueue_style('ckm-completion-signal', $base . 'completion-signal.css', [], CKM_QUIZ_PRO_VERSION);
        wp_enqueue_script('ckm-completion-signal', $base . 'completion-signal.js', [], CKM_QUIZ_PRO_VERSION, true);
        wp_add_inline_script('ckm-completion-signal', 'window.CKMCompletionSignal=' . wp_json_encode([
            'url' => rest_url('ckm/v1/completion-signal'),
            'pollMs' => 10000,
            'defaultMessage' => 'Готово. Задание выполнено.',
        ]) . ';', 'before');
    }

    public static function render_controls(): void {
        if (!self::should_render()) { return; }
        static $rendered = false;
        if ($rendered) { return; }
        $rendered = true;
        ?>
        <div class="ckm-completion-sound" id="ckm-completion-sound" aria-live="polite">
            <button type="button" class="ckm-completion-sound-toggle" id="ckm-completion-sound-toggle" aria-pressed="false">🔇 Звук завершения: ВЫКЛ</button>
            <button type="button" class="ckm-completion-sound-test" id="ckm-completion-sound-test">Проверить звук</button>
            <span class="ckm-completion-sound-note" id="ckm-completion-sound-note">Нажмите «Проверить звук» один раз и разрешите уведомления — тогда сигнал сохранится после перезагрузки страницы.</span>
        </div>
        <?php
    }
}

CKM_Completion_Signal::boot();

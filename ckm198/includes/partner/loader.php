<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/schema.php';

function ckm_quiz_pro_partner_legacy_module_active(): bool {
    return defined('CKMPR_VERSION')
        || function_exists('ckmpr_boot')
        || function_exists('ckmqp_partner_admin_page')
        || function_exists('ckmqp_partner_v2_quota_state')
        || function_exists('ckmqp_partner_v3_activate');
}

function ckm_quiz_pro_partner_dependencies_ready(): bool {
    $required = [
        'ckmqp_tenant_table',
        'ckmqp_scope_id',
        'ckmqp_tenant_link',
        'ckm_quiz_pro_table',
        'ckm_quiz_pro_has_game_access',
        'ckm_quiz_pro_find_game',
    ];
    foreach ($required as $fn) {
        if (!function_exists($fn)) return false;
    }
    return true;
}

function ckm_quiz_pro_partner_admin_dependency_notice(): void {
    if (!current_user_can('manage_options')) return;
    if (ckm_quiz_pro_partner_legacy_module_active()) {
        echo '<div class="notice notice-warning"><p><strong>CKM Quiz Pro — партнёрский режим:</strong> обнаружен отдельный партнёрский плагин или старые WPCode-сниппеты №258/259/261. Встроенный модуль временно не запускается. Отключите отдельный <code>ckm-partner-rental</code> и старые сниппеты; №260 также больше не нужен.</p></div>';
    } elseif (!ckm_quiz_pro_partner_dependencies_ready()) {
        echo '<div class="notice notice-error"><p><strong>CKM Quiz Pro — партнёрский режим:</strong> внутренние tenant/access API не готовы. Партнёрский модуль временно не запущен.</p></div>';
    }
}
add_action('admin_notices', 'ckm_quiz_pro_partner_admin_dependency_notice');

add_action('plugins_loaded', static function (): void {
    if (ckm_quiz_pro_partner_legacy_module_active() || !ckm_quiz_pro_partner_dependencies_ready()) return;

    require_once __DIR__ . '/plans.php';
    require_once __DIR__ . '/access.php';
    require_once __DIR__ . '/usage.php';
    require_once __DIR__ . '/members.php';
    require_once __DIR__ . '/payment-provider.php';
    require_once __DIR__ . '/tbank-provider.php';
    require_once __DIR__ . '/payments.php';
    require_once __DIR__ . '/plan-checkout.php';
    require_once __DIR__ . '/admin.php';
    require_once __DIR__ . '/frontend.php';

    ckm_quiz_pro_partner_boot();
}, 99);

function ckm_quiz_pro_partner_boot(): void {
    add_action('init', 'ckm_quiz_pro_partner_install_schema', 3);
    add_action('admin_init', 'ckm_quiz_pro_partner_install_schema', 3);

    add_action('wp_ajax_ckm_qp_host_action', 'ckm_quiz_pro_partner_preflight_host_start', -100);
    add_action('wp_ajax_nopriv_ckm_qp_host_action', 'ckm_quiz_pro_partner_preflight_host_start', -100);
    add_action('wp_ajax_ckm_qp_show_action', 'ckm_quiz_pro_partner_preflight_show_action', -100);
    add_action('wp_ajax_nopriv_ckm_qp_show_action', 'ckm_quiz_pro_partner_preflight_show_action', -100);
    add_action('ckm_quiz_event_appended', 'ckm_quiz_pro_partner_on_event_appended', 5, 1);

    add_action('admin_menu', 'ckm_quiz_pro_partner_register_admin_menu', 35);
    add_action('rest_api_init', 'ckm_quiz_pro_partner_yk_register_routes');
    add_action('rest_api_init', 'ckm_quiz_pro_partner_tbank_register_routes');
    add_action('rest_api_init', 'ckm_quiz_pro_partner_plan_register_routes');
    add_action('template_redirect', 'ckm_quiz_pro_partner_tbank_public_route', -46);
    add_action('template_redirect', 'ckm_quiz_pro_partner_yk_public_route', -45);
    add_action('template_redirect', 'ckm_quiz_pro_partner_front_route', -40);
    add_action('wp_footer', 'ckm_quiz_pro_partner_render_usage_badge', 30);
}

<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class Health {
    public static function permission() {
        return current_user_can('manage_options') ? true : new \WP_Error('ckm_neg_forbidden', 'Administrator access required.', ['status' => is_user_logged_in() ? 403 : 401]);
    }
    public static function response() {
        global $wpdb;
        $permission = self::permission();
        if (is_wp_error($permission)) { return $permission; }
        $previous = $wpdb->suppress_errors(true);
        try {
            $tables = Schema::inspect();
            $version = (string) get_option('ckm_neg_db_version', '');
            $ok = $version === CKM_NEG_DB_VERSION && Schema::healthy($tables);
            $result = ['module' => 'negotiation-master', 'status' => $ok ? 'ok' : 'degraded', 'db_version' => $version, 'required_db_version' => CKM_NEG_DB_VERSION, 'content_version' => (string) get_option('ckm_neg_content_version', ''), 'tables' => $tables, 'migration_status' => (string) get_option('ckm_neg_migration_status', 'not_started')];
            return new \WP_REST_Response($result, $ok ? 200 : 503, ['Cache-Control' => 'no-store, private']);
        } catch (\Throwable $error) {
            return new \WP_REST_Response(['module' => 'negotiation-master', 'status' => 'degraded', 'db_version' => (string) get_option('ckm_neg_db_version', ''), 'required_db_version' => CKM_NEG_DB_VERSION, 'tables' => [], 'migration_status' => 'failed'], 503, ['Cache-Control' => 'no-store, private']);
        } finally { $wpdb->suppress_errors($previous); }
    }
    public static function register(): void {
        register_rest_route('ckm/v1', '/negotiation/health', ['methods' => 'GET', 'callback' => [self::class, 'response'], 'permission_callback' => [self::class, 'permission']]);
    }
}

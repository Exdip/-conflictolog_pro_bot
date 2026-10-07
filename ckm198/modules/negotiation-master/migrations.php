<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** All DDL and options belong exclusively to this module. Never drop user data. */
final class Migrations {
    private const LOCK = 'ckm_neg_migration_lock';
    private const TTL = 300;
    private static function forgetLockCache(): void {
        wp_cache_delete(self::LOCK, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
    public static function acquire(): ?string {
        global $wpdb;
        $token = (time() + self::TTL) . ':' . wp_generate_uuid4();
        if (add_option(self::LOCK, $token, '', false)) { return $token; }
        // Read directly: object caches are not authoritative for a CAS lease.
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::LOCK));
        if ($old === null || (int) $old > time()) { return null; }
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $token, self::LOCK, $old));
        self::forgetLockCache();
        return $changed === 1 ? $token : null;
    }
    private static function renew(string &$token): void {
        global $wpdb;
        $next = (time() + self::TTL) . ':' . wp_generate_uuid4();
        $changed = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s", $next, self::LOCK, $token));
        self::forgetLockCache();
        if ($changed !== 1) { throw new \RuntimeException('Migration lease lost.'); }
        $token = $next;
    }
    public static function release(string $token): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::LOCK, $token));
        self::forgetLockCache();
    }
    public static function run(): bool {
        global $wpdb;
        if (get_option('ckm_neg_db_version', '') === CKM_NEG_DB_VERSION) { return true; }
        // Never downgrade storage created by a later release.
        if (version_compare((string) get_option('ckm_neg_db_version', '0'), CKM_NEG_DB_VERSION, '>')) { return false; }
        $previousErrors = $wpdb->suppress_errors(true);
        $token = null;
        try {
            $token = self::acquire();
            if ($token === null) { return false; }
            if (get_option('ckm_neg_db_version', '') === CKM_NEG_DB_VERSION) { return true; }
            update_option('ckm_neg_migration_status', 'running', false);
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            foreach (array_keys(Schema::spec()) as $key) {
                self::renew($token);
                dbDelta(Schema::sql($key));
                if ($wpdb->last_error !== '') { throw new \RuntimeException('Migration query failed.'); }
            }
            self::renew($token);
            if (!Schema::healthy(Schema::inspect())) { throw new \RuntimeException('Schema verification failed.'); }
            update_option('ckm_neg_db_version', CKM_NEG_DB_VERSION, false);
            // Schema-only baseline. Content migration advances its marker after commit.
            add_option('ckm_neg_content_version', '1.0.0', '', false);
            update_option('ckm_neg_migration_status', 'complete', false);
            delete_option('ckm_neg_migration_error');
            return get_option('ckm_neg_db_version', '') === CKM_NEG_DB_VERSION;
        } catch (\Throwable $error) {
            update_option('ckm_neg_migration_status', 'failed', false);
            update_option('ckm_neg_migration_error', 'schema_install_failed', false);
            return false;
        } finally {
            if ($token !== null) { self::release($token); }
            $wpdb->suppress_errors($previousErrors);
        }
    }
}

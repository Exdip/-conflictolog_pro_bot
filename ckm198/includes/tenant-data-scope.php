<?php
if (!defined('ABSPATH')) exit;
function ckmqp_scope_migration_error(string $step,string $table,string $message): bool {
    update_option('ckmqp_scope_migration_error',['step'=>$step,'table'=>$table,'message'=>$message],false);
    return false;
}
/** Additive migration: old rows keep tenant_id=0 and their original user owner. */
function ckmqp_scope_install(): bool {
    if (get_option('ckmqp_scope_schema')==='1') return true;
    global $wpdb;
    $tables=[ckm_quiz_pro_table('games'),ckm_quiz_pro_table('game_access'),ckmqp_test_table('orders'),ckmqp_test_table('access')];
    foreach ($tables as $table) {
        if ($table==='') return ckmqp_scope_migration_error('table_mapping',$table,'Не определено имя таблицы.');
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` WHERE Field=%s",'tenant_id'))) {
            if ($wpdb->query("ALTER TABLE `$table` ADD COLUMN tenant_id bigint unsigned NOT NULL DEFAULT 0")===false) return ckmqp_scope_migration_error('add_tenant_id',$table,(string)$wpdb->last_error);
        }
        if (!$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name=%s",'ckmqp_tenant'))) {
            if ($wpdb->query("ALTER TABLE `$table` ADD INDEX ckmqp_tenant (tenant_id)")===false) return ckmqp_scope_migration_error('add_index',$table,(string)$wpdb->last_error);
        }
    }
    delete_option('ckmqp_scope_migration_error');
    update_option('ckmqp_scope_schema','1',false);
    return true;
}
add_action('init','ckmqp_scope_install',3);
function ckmqp_scope_ready(): bool { return get_option('ckmqp_scope_schema')==='1'; }
/** Only server-resolved context is accepted. POST/GET tenant_id are never consulted. */
function ckmqp_scope_id(): int {
    $ctx=$GLOBALS['ckmqp_tenant_context']??null;
    if ($ctx===null) {
        if (PHP_SAPI==='cli' || (defined('WP_CLI') && WP_CLI)) return 0;
        $ctx=ckmqp_tenant_resolve((string)($_SERVER['HTTP_HOST']??''));
    }
    if (($ctx['kind']??'')==='platform') return 0;
    // staged remains usable only by internal tests/migration code; HTTP guard rejects it.
    if (in_array($ctx['kind']??'', ['staged','tenant'],true) && (int)($ctx['tenant_id']??0)>0) return (int)$ctx['tenant_id'];
    return -1;
}
function ckmqp_scope_user_allowed(int $uid): bool {
    $id=ckmqp_scope_id();
    return ckmqp_scope_ready() && $uid>0 && $id>=0 && ($id===0 || ckmqp_tenant_is_member($id,$uid));
}
/** Access belongs to a tenant, legacy rows remain private to the buyer. */
function ckmqp_scope_access_clause(int $uid): string {
    global $wpdb;
    if (!ckmqp_scope_user_allowed($uid)) return '1=0';
    $tenant=ckmqp_scope_id();
    return $tenant>0 ? $wpdb->prepare('tenant_id=%d',$tenant) : $wpdb->prepare('tenant_id=0 AND user_id=%d',$uid);
}
add_action('admin_notices',static function () {
    if (current_user_can('manage_options') && !ckmqp_scope_ready()) {
        $error=(array)get_option('ckmqp_scope_migration_error',[]);
        echo '<div class="notice notice-error"><p>Не завершено обновление таблиц площадок. Новая оплата и запуск временно недоступны.</p><p>'.esc_html(implode(' · ',array_filter([(string)($error['step']??''),(string)($error['table']??''),(string)($error['message']??'Причина пока не записана.')]))).'</p></div>';
    }
});

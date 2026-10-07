<?php
if (!defined('ABSPATH')) exit;
function ckmqp_content_ready(): bool { return get_option('ckmqp_content_schema')==='1'; }
function ckmqp_content_install(): bool {
    if (ckmqp_content_ready()) return true;
    if (!ckmqp_scope_ready()) return false;
    global $wpdb;$table=ckm_quiz_pro_table('quizzes');
    foreach (['tenant_id'=>'bigint unsigned NOT NULL DEFAULT 0','content_scope'=>"varchar(20) NOT NULL DEFAULT 'legacy'"] as $column=>$definition) {
        if (!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` WHERE Field=%s",$column)) && $wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition")===false) return ckmqp_scope_migration_error('content_columns',$table,(string)$wpdb->last_error);
    }
    if (!$wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name=%s",'ckmqp_content')) && $wpdb->query("ALTER TABLE `$table` ADD INDEX ckmqp_content (tenant_id,content_scope)")===false) return ckmqp_scope_migration_error('content_index',$table,(string)$wpdb->last_error);
    // Only installed demo fixtures and managed packages are automatically shared.
    $packages=ckm_quiz_pro_table('packages');
    if ($wpdb->query("UPDATE `$table` q SET q.content_scope='shared' WHERE q.content_scope='legacy' AND q.tenant_id=0 AND (q.slug IN ('demo-classic-quiz','demo-battle-experts','ckm-demo-intellectual-battle','demo-solution-price','demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express') OR EXISTS (SELECT 1 FROM `$packages` p WHERE p.quiz_id=q.id))")===false) return ckmqp_scope_migration_error('content_classification',$table,(string)$wpdb->last_error);
    delete_option('ckmqp_scope_migration_error');
    update_option('ckmqp_content_schema','1',false);return true;
}
add_action('init','ckmqp_content_install',4);
/** Repository boundary: participant requests inherit the room's host, not an organizer login. */
function ckmqp_content_in_scope(array $quiz): bool {
    if (!ckmqp_content_ready() || ckmqp_scope_id()<0 || !isset($quiz['tenant_id'],$quiz['content_scope'])) return false;
    return ((int)$quiz['tenant_id']===0 && $quiz['content_scope']==='shared') || (int)$quiz['tenant_id']===ckmqp_scope_id();
}
function ckmqp_content_can_use(array $quiz,int $uid): bool {
    if (!ckmqp_content_in_scope($quiz) || !ckmqp_scope_user_allowed($uid)) return false;
    if ($quiz['content_scope']==='shared') return true;
    if (ckmqp_scope_id()>0) return $quiz['content_scope']==='private';
    return (int)$quiz['created_by_user_id']===$uid || user_can($uid,'manage_options');
}
function ckmqp_content_can_edit(array $quiz,int $uid): bool {
    if (!ckmqp_content_can_use($quiz,$uid)) return false;
    return $quiz['content_scope']!=='shared';
}
function ckmqp_content_list_clause(string $alias=''): string {
    if (!in_array($alias,['','q'],true)) throw new InvalidArgumentException('Invalid content alias');
    if (!ckmqp_content_ready()) return '1=0';
    $uid=ckm_quiz_pro_effective_organizer_user_id();$tenant=ckmqp_scope_id();
    if (!ckmqp_scope_user_allowed($uid)) return '1=0';
    $p=$alias===''?'':$alias.'.';
    $shared="({$p}tenant_id=0 AND {$p}content_scope='shared')";
    if ($tenant>0) return "($shared OR ({$p}tenant_id=$tenant AND {$p}content_scope='private'))";
    $owner=user_can($uid,'manage_options')?'1=1':"{$p}created_by_user_id=".(int)$uid;
    return "($shared OR ({$p}tenant_id=0 AND {$p}content_scope IN ('legacy','private') AND $owner))";
}

/**
 * 0.3.23.39 repair: earlier tenant-content migration had already completed
 * before Solution Price / Negotiation Duel demo slugs existed in the shared
 * allow-list. Repair those rows once so paid tenant libraries can see them.
 */
function ckmqp_content_share_new_builtin_demos(): void {
    if (get_option('ckmqp_content_builtin_demos_v2')==='1') return;
    if (!ckmqp_content_ready()) return;
    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $slugs=['demo-solution-price','demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express'];
    $placeholders=implode(',',array_fill(0,count($slugs),'%s'));
    $sql=$wpdb->prepare("UPDATE `$table` SET tenant_id=0, content_scope='shared' WHERE slug IN ($placeholders)",...$slugs);
    if ($wpdb->query($sql)===false) {
        ckmqp_scope_migration_error('share_new_builtin_demos',$table,(string)$wpdb->last_error);
        return;
    }
    update_option('ckmqp_content_builtin_demos_v2','1',false);
}
add_action('init','ckmqp_content_share_new_builtin_demos',12);

add_action('admin_notices',static function () {
    if (current_user_can('manage_options') && !ckmqp_content_ready()) {
        $error=(array)get_option('ckmqp_scope_migration_error',[]);
        echo '<div class="notice notice-error"><p>'.(!ckmqp_scope_ready()?'Обновление игровых шаблонов ожидает завершения обновления таблиц площадок.':'Не завершено обновление игровых шаблонов: '.esc_html(implode(' · ',array_map('strval',$error)))) .'</p></div>';
    }
});

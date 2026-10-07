<?php
if (!defined('ABSPATH')) exit;
function ckmqp_transfer_tables(): array {
    return [ckm_quiz_pro_table('quizzes')=>'created_by_user_id',ckm_quiz_pro_table('games')=>'created_by_user_id',ckm_quiz_pro_table('game_access')=>'user_id',ckmqp_test_table('orders')=>'user_id',ckmqp_test_table('access')=>'user_id'];
}
/** Explicit administrative transfer. Never infer a tenant from login or webhook host. */
function ckmqp_transfer_owner(int $uid,int $tenant,bool $execute=false) {
    if (!current_user_can('manage_options') || !ckmqp_scope_ready() || !ckmqp_content_ready() || $uid<=0 || $tenant<=0) return new WP_Error('forbidden','Нет доступа к переносу.');
    global $wpdb;
    $target=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.ckmqp_tenant_table('tenants')." WHERE id=%d AND status='staged'",$tenant));
    $owner=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.ckmqp_tenant_table('members')." WHERE tenant_id=%d AND user_id=%d AND role='owner' AND status='active'",$tenant,$uid));
    if (!$target || !$owner) return new WP_Error('owner','Выберите подготовленную площадку, владельцем которой является указанный пользователь.');
    $tables=ckmqp_transfer_tables();$counts=[];
    foreach ($tables as $table=>$field) {
        $engine=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table),ARRAY_A);
        if (!$engine || strcasecmp((string)$engine['Engine'],'InnoDB')!==0) return new WP_Error('engine','Для переноса все таблицы должны использовать InnoDB.');
    }
    $lock='ckmqp_pay_'.md5('0|'.$uid);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,2)',$lock))!==1) return new WP_Error('busy','Сейчас создаётся заказ. Повторите позже.');
    try {
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('database','Не удалось начать перенос.');
        // Lock orders before access, matching payment confirmation order.
        $ordered=[ckm_quiz_pro_table('quizzes')=>'created_by_user_id',ckmqp_test_table('orders')=>'user_id',ckmqp_test_table('access')=>'user_id',ckm_quiz_pro_table('games')=>'created_by_user_id',ckm_quiz_pro_table('game_access')=>'user_id'];
        foreach ($ordered as $table=>$field) {
            $extra=$table===ckm_quiz_pro_table('quizzes')?" AND content_scope IN ('legacy','private')":'';
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id=0 AND `$field`=%d{$extra} FOR UPDATE",$uid),ARRAY_A);
            if ($wpdb->last_error) { $wpdb->query('ROLLBACK'); return new WP_Error('database','Ошибка чтения записей. Перенос отменён.'); }
            $counts[$table]=count($rows?:[]);
            foreach ($rows?:[] as $row) {
                if ($table===ckmqp_test_table('orders') && !in_array($row['status'],['succeeded','canceled'],true)) { $wpdb->query('ROLLBACK'); return new WP_Error('pending','Сначала завершите или отмените все ожидающие тестовые платежи владельца.'); }
                if ($table===ckm_quiz_pro_table('games') && $row['status']!=='finished') { $wpdb->query('ROLLBACK'); return new WP_Error('active','Сначала завершите все игры владельца.'); }
            }
        }
        if (!$execute) { $wpdb->query('ROLLBACK'); return $counts; }
        foreach ($ordered as $table=>$field) {
            $extra=$table===ckm_quiz_pro_table('quizzes')?" AND content_scope IN ('legacy','private')":'';
            $set=$table===ckm_quiz_pro_table('quizzes')?", content_scope='private'":'';
            if ($wpdb->query($wpdb->prepare("UPDATE `$table` SET tenant_id=%d{$set} WHERE tenant_id=0 AND `$field`=%d{$extra}",$tenant,$uid))===false) { $wpdb->query('ROLLBACK'); return new WP_Error('database','Не удалось перенести данные. Изменения отменены.'); }
        }
        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('database','Не удалось подтвердить перенос.'); }
        return $counts;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
}
add_action('admin_menu',static function () { add_submenu_page('ckm-quiz-pro','Перенос на площадку','Перенос на площадку','manage_options','ckmqp-tenant-transfer','ckmqp_transfer_admin'); });
function ckmqp_transfer_admin(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Перенос на площадку</h1><p>Переносятся частные шаблоны владельца, запуски, личные доступы и тестовые заказы выбранного владельца. Общая библиотека игр остаётся общей.</p><p><strong>Поддомены пока закрыты. После переноса эти данные исчезнут из основного кабинета и станут доступны после открытия площадки. Старые ссылки комнат на основном домене перестанут работать.</strong></p>';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_admin_referer('ckmqp_transfer');
        $uid=absint($_POST['owner_id']??0);$tenant=absint($_POST['tenant_id']??0);
        $execute=isset($_POST['execute']) && !empty($_POST['ack']);
        $result=ckmqp_transfer_owner($uid,$tenant,$execute);
        if (is_wp_error($result)) echo '<p>'.esc_html($result->get_error_message()).'</p>';
        else {
            echo '<h2>'.($execute?'Перенос завершён':'Предварительный расчёт').'</h2><ul>';
            foreach ($result as $table=>$count) echo '<li>'.esc_html($table).': '.(int)$count.'</li>';
            echo '</ul>';
            if (!$execute) {
                echo '<form method="post">';wp_nonce_field('ckmqp_transfer');
                echo '<input type="hidden" name="owner_id" value="'.$uid.'"><input type="hidden" name="tenant_id" value="'.$tenant.'"><p>Владелец: '.$uid.'. Площадка: '.$tenant.'.</p><p><label><input type="checkbox" name="ack" required> Подтверждаю перенос и временную недоступность этих данных до открытия площадки.</label></p><button class="button" name="execute" value="1">Перенести данные</button></form>';
            }
        }
    }
    echo '<h2>Проверить перенос</h2><form method="post">';wp_nonce_field('ckmqp_transfer');
    echo '<p><label>ID владельца <input type="number" name="owner_id" min="1" required></label></p><p><label>ID площадки <input type="number" name="tenant_id" min="1" required></label></p><button class="button button-primary">Показать предварительный расчёт</button></form></div>';
}

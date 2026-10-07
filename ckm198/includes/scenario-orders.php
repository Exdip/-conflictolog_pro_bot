<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_scenario_order_statuses(): array {
    return [
        'new'=>'Новая',
        'in_work'=>'В работе',
        'ready'=>'Готово',
        'closed'=>'Завершён',
        'cancelled'=>'Отменён',
    ];
}

function ckm_quiz_pro_scenario_order_status_label(string $status): string {
    $all=ckm_quiz_pro_scenario_order_statuses();
    return $all[$status] ?? $status;
}

function ckm_quiz_pro_scenario_orders_table_ready(): bool {
    static $ready=null;
    if($ready!==null) return $ready;
    global $wpdb;
    $table=ckm_quiz_pro_table('scenario_orders');
    if($table==='') return $ready=false;
    $pattern=method_exists($wpdb,'esc_like')?$wpdb->esc_like($table):$table;
    return $ready=((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$pattern))===$table);
}

function ckm_quiz_pro_scenario_order_create(int $userId,array $data) {
    if($userId<=0) return new WP_Error('scenario_user','Не определён организатор.');
    if(!ckm_quiz_pro_scenario_orders_table_ready()) return new WP_Error('scenario_storage','Хранилище заказов ещё не обновлено.');
    $tenant=function_exists('ckm_quiz_pro_access_tenant_id')?ckm_quiz_pro_access_tenant_id($userId,null):(function_exists('ckmqp_scope_id')?ckmqp_scope_id():0);
    if($tenant<0) return new WP_Error('scenario_tenant','Не определена площадка организатора.');
    $audience=sanitize_text_field((string)($data['audience']??''));
    $format=sanitize_text_field((string)($data['game_format']??''));
    $theme=sanitize_text_field((string)($data['theme']??''));
    $details=sanitize_textarea_field((string)($data['details']??''));
    if($audience==='' || $details==='') return new WP_Error('scenario_required','Укажите аудиторию и опишите, какой сценарий нужен.');
    global $wpdb;
    $now=current_time('mysql');
    $ok=$wpdb->insert(ckm_quiz_pro_table('scenario_orders'),[
        'tenant_id'=>$tenant,'user_id'=>$userId,'audience'=>$audience,'game_format'=>$format,'theme'=>$theme,'details'=>$details,
        'status'=>'new','admin_note'=>'','ready_game_post_id'=>0,'created_at'=>$now,'updated_at'=>$now,
    ],['%d','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s']);
    if($ok===false) return new WP_Error('scenario_insert','Не удалось сохранить заявку.');
    $id=(int)$wpdb->insert_id;

    // Email is only a notification. The order itself is already safely stored.
    $admin=(string)get_option('admin_email');
    if($admin!==''){
        $user=get_userdata($userId);
        $body="Новый заказ сюжета #{$id}\n";
        $body.='Организатор: '.($user?$user->display_name:'').' (#'.$userId.")\n";
        $body.='Email: '.($user?$user->user_email:'')."\n";
        $body.='Аудитория: '.$audience."\nФормат: ".$format."\nТема: ".$theme."\n\nОписание:\n".$details."\n";
        wp_mail($admin,'ЦКМ: новый заказ сюжета #'.$id,$body);
    }
    return $id;
}

function ckm_quiz_pro_scenario_orders_for_user(int $userId): array {
    if($userId<=0 || !ckm_quiz_pro_scenario_orders_table_ready()) return [];
    $tenant=function_exists('ckm_quiz_pro_access_tenant_id')?ckm_quiz_pro_access_tenant_id($userId,null):(function_exists('ckmqp_scope_id')?ckmqp_scope_id():0);
    if($tenant<0) return [];
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('scenario_orders').' WHERE user_id=%d AND tenant_id=%d ORDER BY id DESC',
        $userId,$tenant
    ),ARRAY_A) ?: [];
}

function ckm_quiz_pro_scenario_order_get(int $id): array {
    if($id<=0 || !ckm_quiz_pro_scenario_orders_table_ready()) return [];
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('scenario_orders').' WHERE id=%d',$id),ARRAY_A);
    return is_array($row)?$row:[];
}

function ckm_quiz_pro_scenario_order_ready_game_url(array $row): string {
    $postId=(int)($row['ready_game_post_id']??0);
    if($postId<=0) return '';
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type() || $post->post_status!=='publish') return '';
    $item=ckm_quiz_pro_ready_game_item_from_post($post);
    $product=sanitize_key((string)($item['product']??''));
    return $product!=='' ? add_query_arg('game',$product,ckm_quiz_pro_persuade_library_url()) : '';
}

add_action('admin_menu',static function(): void {
    add_submenu_page('ckm-quiz-pro','Заказы сюжетов','Заказы сюжетов','manage_options','ckm-quiz-pro-scenario-orders','ckm_quiz_pro_scenario_orders_admin_page',8);
},19);

function ckm_quiz_pro_scenario_orders_admin_url(array $args=[]): string {
    return add_query_arg($args,admin_url('admin.php?page=ckm-quiz-pro-scenario-orders'));
}

function ckm_quiz_pro_scenario_orders_admin_page(): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    if(!ckm_quiz_pro_scenario_orders_table_ready()){
        echo '<div class="wrap"><h1>Заказы сюжетов</h1><div class="notice notice-warning"><p>Таблица заказов ещё не создана. Перезагрузите страницу после обновления плагина.</p></div></div>';
        return;
    }
    $action=sanitize_key((string)($_GET['action']??''));
    $id=max(0,(int)($_GET['id']??0));
    if($action==='edit' && $id>0){ ckm_quiz_pro_scenario_order_admin_form($id); return; }
    global $wpdb;
    $rows=$wpdb->get_results('SELECT * FROM '.ckm_quiz_pro_table('scenario_orders').' ORDER BY id DESC',ARRAY_A) ?: [];
    echo '<div class="wrap"><h1>Заказы сюжетов</h1><p>Заявки организаторов на разработку готового сюжета. Здесь можно вести статус работы и привязать готовую игру из каталога.</p>';
    if(isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Заказ обновлён.</p></div>';
    echo '<table class="widefat striped"><thead><tr><th>ID / дата</th><th>Организатор</th><th>Запрос</th><th>Статус</th><th>Готовая игра</th><th>Действия</th></tr></thead><tbody>';
    foreach($rows as $r){
        $u=get_userdata((int)$r['user_id']);
        $game='—';
        if((int)$r['ready_game_post_id']>0){ $gp=get_post((int)$r['ready_game_post_id']); if($gp) $game=(string)$gp->post_title; }
        echo '<tr><td><strong>#'.(int)$r['id'].'</strong><br>'.esc_html((string)$r['created_at']).'</td><td>'.esc_html($u?$u->display_name:'Пользователь #'.(int)$r['user_id']).'<br><code>tenant '.(int)$r['tenant_id'].'</code></td><td><strong>'.esc_html((string)$r['theme']).'</strong><br>'.esc_html((string)$r['game_format']).'<br><span class="description">'.esc_html(wp_trim_words((string)$r['details'],18,'…')).'</span></td><td>'.esc_html(ckm_quiz_pro_scenario_order_status_label((string)$r['status'])).'</td><td>'.esc_html($game).'</td><td><a class="button button-small" href="'.esc_url(ckm_quiz_pro_scenario_orders_admin_url(['action'=>'edit','id'=>(int)$r['id']])).'">Открыть</a></td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="6">Заказов пока нет.</td></tr>';
    echo '</tbody></table></div>';
}

function ckm_quiz_pro_scenario_order_admin_form(int $id): void {
    $row=ckm_quiz_pro_scenario_order_get($id);
    if(!$row) wp_die('Заказ не найден.');
    $u=get_userdata((int)$row['user_id']);
    $posts=get_posts(['post_type'=>ckm_quiz_pro_ready_game_post_type(),'post_status'=>['publish','draft','private'],'posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
    echo '<div class="wrap"><h1>Заказ сюжета #'.(int)$id.'</h1><p><a href="'.esc_url(ckm_quiz_pro_scenario_orders_admin_url()).'">← Все заказы</a></p>';
    if(isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Заказ обновлён.</p></div>';
    echo '<div class="card" style="max-width:1000px"><p><strong>Организатор:</strong> '.esc_html($u?$u->display_name:'Пользователь #'.(int)$row['user_id']).'</p><p><strong>Аудитория:</strong> '.esc_html((string)$row['audience']).'</p><p><strong>Формат:</strong> '.esc_html((string)$row['game_format']).'</p><p><strong>Тема:</strong> '.esc_html((string)$row['theme']).'</p><p><strong>Описание:</strong><br>'.nl2br(esc_html((string)$row['details'])).'</p></div>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="ckm_quiz_pro_scenario_order_save"><input type="hidden" name="id" value="'.(int)$id.'">';
    wp_nonce_field('ckm_quiz_pro_scenario_order_save_'.$id,'ckm_scenario_order_nonce');
    echo '<table class="form-table"><tbody><tr><th>Статус</th><td><select name="status">';
    foreach(ckm_quiz_pro_scenario_order_statuses() as $key=>$label) echo '<option value="'.esc_attr($key).'" '.selected((string)$row['status'],$key,false).'>'.esc_html($label).'</option>';
    echo '</select></td></tr><tr><th>Готовая игра</th><td><select name="ready_game_post_id"><option value="0">— ещё не привязана —</option>';
    foreach($posts as $post) echo '<option value="'.(int)$post->ID.'" '.selected((int)$row['ready_game_post_id'],(int)$post->ID,false).'>'.esc_html((string)$post->post_title).' · '.esc_html((string)$post->post_status).'</option>';
    echo '</select> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'new','scenario_order'=>$id])).'">Создать готовую игру по заказу</a></td></tr>';
    echo '<tr><th>Комментарий организатору</th><td><textarea class="large-text" rows="5" name="admin_note">'.esc_textarea((string)$row['admin_note']).'</textarea></td></tr></tbody></table>';
    submit_button('Сохранить заказ'); echo '</form></div>';
}

add_action('admin_post_ckm_quiz_pro_scenario_order_save',static function(): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $id=max(0,(int)($_POST['id']??0));
    check_admin_referer('ckm_quiz_pro_scenario_order_save_'.$id,'ckm_scenario_order_nonce');
    $row=ckm_quiz_pro_scenario_order_get($id); if(!$row) wp_die('Заказ не найден.');
    $status=sanitize_key((string)($_POST['status']??'new'));
    if(!array_key_exists($status,ckm_quiz_pro_scenario_order_statuses())) $status='new';
    $ready=max(0,(int)($_POST['ready_game_post_id']??0));
    if($ready>0){ $p=get_post($ready); if(!$p || $p->post_type!==ckm_quiz_pro_ready_game_post_type()) $ready=0; }
    $note=sanitize_textarea_field(wp_unslash($_POST['admin_note']??''));
    global $wpdb;
    $wpdb->update(ckm_quiz_pro_table('scenario_orders'),['status'=>$status,'ready_game_post_id'=>$ready,'admin_note'=>$note,'updated_at'=>current_time('mysql')],['id'=>$id],['%s','%d','%s','%s'],['%d']);
    wp_safe_redirect(ckm_quiz_pro_scenario_orders_admin_url(['action'=>'edit','id'=>$id,'saved'=>1])); exit;
});

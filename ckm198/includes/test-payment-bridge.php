<?php
if (!defined('ABSPATH')) exit;
function ckmqp_test_enabled(): bool { return get_option('ckmqp_test_pay_enabled','0')==='1'; }
function ckmqp_test_table(string $kind): string { global $wpdb; return $wpdb->prefix.'ckmqp_test_'.$kind; }
function ckmqp_test_install(): void {
    if (get_option('ckmqp_test_pay_schema')==='1') return;
    global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
    $orders=ckmqp_test_table('orders'); $access=ckmqp_test_table('access');
    dbDelta("CREATE TABLE $orders (
        order_id varchar(36) NOT NULL,
        user_id bigint unsigned NOT NULL,
        tenant_id bigint unsigned NOT NULL DEFAULT 0,
        user_ref varchar(64) NOT NULL,
        cart_json text NOT NULL,
        total_minor bigint unsigned NOT NULL,
        status varchar(24) NOT NULL DEFAULT 'created',
        confirmation_url text NULL,
        created_at datetime NOT NULL,
        paid_at datetime NULL,
        PRIMARY KEY  (order_id),
        KEY user_status (user_id,status),
        KEY tenant_status (tenant_id,status)
    ) ENGINE=InnoDB $c;");
    dbDelta("CREATE TABLE $access (
        order_id varchar(36) NOT NULL,
        user_id bigint unsigned NOT NULL,
        tenant_id bigint unsigned NOT NULL DEFAULT 0,
        format_key varchar(64) NOT NULL,
        expires_at datetime NOT NULL,
        PRIMARY KEY  (order_id,format_key),
        KEY user_format (user_id,format_key),
        KEY tenant_format (tenant_id,format_key)
    ) ENGINE=InnoDB $c;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($orders)))===$orders && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($access)))===$access) update_option('ckmqp_test_pay_schema','1',false);
}
add_action('init','ckmqp_test_install',2);
function ckmqp_test_has_access(int $uid,string $format, ?int $tenant_id = null): bool {
    if (!ckmqp_test_enabled()) return false;
    if (function_exists('ckm_quiz_pro_access_scope_parts')) {
        $scope=ckm_quiz_pro_access_scope_parts($uid,$tenant_id);
        if (!$scope) return false;
        global $wpdb;
        $args=array_merge((array)$scope['args'],[$format]);
        return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckmqp_test_table('access').' WHERE '.(string)$scope['sql'].' AND format_key=%s AND expires_at>UTC_TIMESTAMP()',...$args))>0;
    }
    if (!ckmqp_scope_user_allowed($uid)) return false;
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckmqp_test_table('access').' WHERE '.ckmqp_scope_access_clause($uid).' AND format_key=%s AND expires_at>UTC_TIMESTAMP()',$format))>0;
}
function ckmqp_test_call(string $action,array $data) {
    $key=(string)get_option('ckmqp_test_pair_key','');
    if (!preg_match('/^[a-f0-9]{64}$/D',$key)) return new WP_Error('config','Не задан ключ связи с сервером тестовой оплаты.');
    $route='/ckm-test-pay/v1/'.$action; $body=wp_json_encode($data); $time=(string)time(); $nonce=bin2hex(random_bytes(16));
    $sig=hash_hmac('sha256',$route."\n".$time."\n".$nonce."\n".$body,$key);
    $url=add_query_arg('rest_route',$route,'https://centr-razvitia-uma.ru/');
    $r=wp_remote_post($url,['headers'=>['Content-Type'=>'application/json','X-CKMTS-Time'=>$time,'X-CKMTS-Nonce'=>$nonce,'X-CKMTS-Signature'=>$sig],'body'=>$body,'timeout'=>25,'redirection'=>0]);
    if (is_wp_error($r)) return new WP_Error('network','Сервер оплаты не ответил. Повторите попытку.');
    $code=(int)wp_remote_retrieve_response_code($r); $env=json_decode(wp_remote_retrieve_body($r),true);
    if ($code!==200 || !is_array($env)) return new WP_Error('remote','Сервер тестовой оплаты отклонил запрос (HTTP '.$code.'). Проверьте настройки подключения.');
    if (!is_string($env['payload']??null) || !is_string($env['signature']??null) || !hash_equals(hash_hmac('sha256',$nonce."\n".$env['payload'],$key),$env['signature'])) return new WP_Error('signature','Не удалось проверить ответ сервера оплаты.');
    $result=json_decode($env['payload'],true);
    return is_array($result)?$result:new WP_Error('response','Некорректный ответ сервера.');
}

/**
 * Backward-compatible transport mapping for the legacy test-payment server.
 * Server 0.1.0 knows only the original product catalogue. The four paid
 * Persuade Me scenario packs all cost 990 RUB, so a single scenario purchase
 * can be verified remotely using the legacy 990-RUB classic_quiz SKU while
 * the local signed order continues to grant only the requested scenario key.
 * This bridge is intentionally test-payment-only and is never used by live
 * payment adapters.
 */
function ckmqp_test_transport_map(): array {
    $map=[
        'persuade_school_v1'  => 'classic_quiz',
        'persuade_school_grade_v1' => 'classic_quiz',
        'persuade_student_v1' => 'classic_quiz',
        'persuade_leader_v1'  => 'classic_quiz',
        'persuade_family_v1'  => 'classic_quiz',
    ];
    // The legacy test-payment server knows only the original SKUs. Finished
    // catalogue games can still use it when their price matches one of those
    // transport SKUs; the local signed order grants the actual catalogue key.
    if(function_exists('ckm_quiz_pro_ready_games_registry')){
        $priceSku=[990=>'classic_quiz',1490=>'negotiation_duel_v1',1990=>'chgk_v1',2490=>'jeopardy_v1'];
        foreach(ckm_quiz_pro_ready_games_registry() as $key=>$item){
            $price=(int)($item['price'] ?? 0);
            if(isset($priceSku[$price])) $map[sanitize_key((string)$key)]=$priceSku[$price];
        }
    }
    return $map;
}
function ckmqp_test_transport_keys(array $desired): array {
    $map=ckmqp_test_transport_map();
    $out=[];
    foreach($desired as $key){
        $key=sanitize_key((string)$key);
        if($key==='') continue;
        $out[]=$map[$key] ?? $key;
    }
    return array_values(array_unique($out));
}
function ckmqp_test_is_scenario_key(string $key): bool {
    return isset(ckmqp_test_transport_map()[$key]);
}
function ckmqp_test_validate_transport_prices(array $desired): bool {
    $products=ckm_quiz_pro_game_access_products();
    $map=ckmqp_test_transport_map();
    foreach($desired as $key){
        $key=sanitize_key((string)$key);
        if(!isset($map[$key])) continue;
        $transport=$map[$key];
        if(!isset($products[$key],$products[$transport])) return false;
        if((int)$products[$key]['price'] !== (int)$products[$transport]['price']) return false;
    }
    return true;
}

function ckmqp_test_get(string $id,int $uid) { if (!ckmqp_scope_user_allowed($uid)) return null; global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckmqp_test_table('orders').' WHERE order_id=%s AND user_id=%d AND tenant_id=%d',$id,$uid,ckmqp_scope_id()),ARRAY_A); }
function ckmqp_test_accept(array $order,array $remote) {
    global $wpdb;
    $desired_keys=json_decode($order['cart_json'],true);
    $desired_keys=is_array($desired_keys)?array_values($desired_keys):[];
    $transport_keys=ckmqp_test_transport_keys($desired_keys);
    if (($remote['test']??null)!==true || ($remote['shop_id']??'')!=='1441400' || ($remote['currency']??'')!=='RUB' || ($remote['order_id']??'')!==$order['order_id'] || ($remote['user_ref']??'')!==$order['user_ref'] || ($remote['total_minor']??null)!==(int)$order['total_minor'] || ($remote['format_keys']??null)!==$transport_keys) return new WP_Error('order','Ответ не соответствует заказу.');
    if (!ckmqp_scope_ready() || !isset($order['tenant_id']) || (int)$order['tenant_id']<0) return new WP_Error('scope','Не определена площадка сохранённого заказа.');
    $status=$remote['status']??'';
    if (!in_array($status,['created','pending','waiting_for_capture','succeeded','canceled'],true)) return new WP_Error('status','Неизвестный статус.');
    $url=$remote['confirmation_url']??'';
    if (!is_string($url) || ($url!=='' && wp_parse_url($url,PHP_URL_SCHEME)!=='https')) return new WP_Error('url','Некорректная ссылка оплаты.');
    $update=['status'=>$status,'confirmation_url'=>$url];
    if ($status==='succeeded') {
        $paid=$remote['paid_at']??''; $ts=is_string($paid)?strtotime($paid.' UTC'):false;
        if (!$ts || $ts>time()+300 || $ts<strtotime($order['created_at'].' UTC')-300) return new WP_Error('time','Некорректное время подтверждения.');
        $update['paid_at']=$paid;
        // All purchased formats are granted together, once per order+format.
        if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('storage','Не удалось открыть транзакцию.');
        $stored=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckmqp_test_table('orders').' WHERE order_id=%s FOR UPDATE',$order['order_id']),ARRAY_A);
        if (!$stored || (string)$stored['user_ref']!==(string)$order['user_ref'] || (int)$stored['user_id']!==(int)$order['user_id'] || (int)$stored['total_minor']!==(int)$order['total_minor'] || $stored['cart_json']!==$order['cart_json']) {
            $wpdb->query('ROLLBACK'); return new WP_Error('storage','Сохранённый заказ изменился. Повторите проверку.');
        }
        $order=$stored;
        foreach ($desired_keys as $format) {
            $expires=function_exists('ckm_quiz_pro_access_extension_expires')
                ? ckm_quiz_pro_access_extension_expires((int)$order['user_id'],(string)$format,30,(int)$order['tenant_id'],$ts)
                : gmdate('Y-m-d H:i:s',$ts+30*DAY_IN_SECONDS);
            $ok=$wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.ckmqp_test_table('access').' (order_id,user_id,format_key,expires_at,tenant_id) VALUES (%s,%d,%s,%s,%d)',$order['order_id'],(int)$order['user_id'],$format,$expires,(int)$order['tenant_id']));
            if ($ok===false) { $wpdb->query('ROLLBACK'); return new WP_Error('storage','Не удалось сохранить доступ. Повторите проверку.'); }
        }
        if ($wpdb->update(ckmqp_test_table('orders'),$update,['order_id'=>$order['order_id']])===false) { $wpdb->query('ROLLBACK'); return new WP_Error('storage','Не удалось сохранить заказ.'); }
        if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('storage','Не удалось подтвердить сохранение доступа.'); }
        if (function_exists('ckmqp_after_payment_remember_cart')) {
            ckmqp_after_payment_remember_cart((int)$order['user_id'], $desired_keys, (int)$order['tenant_id']);
        }
    } elseif ($order['status']!=='succeeded') {
        if ($wpdb->query($wpdb->prepare("UPDATE ".ckmqp_test_table('orders')." SET status=%s, confirmation_url=%s WHERE order_id=%s AND status<>'succeeded'",$status,$url,$order['order_id']))===false) return new WP_Error('storage','Не удалось сохранить заказ.');
    }
    $remote['transport_format_keys']=$remote['format_keys'] ?? [];
    $remote['format_keys']=$desired_keys;
    return $remote;
}
function ckmqp_test_checkout($existing,array $cart,int $uid) {
    if (!ckmqp_test_enabled()) return $existing;
    if ($uid<=0 || !ckm_quiz_pro_can_organize() || ckm_quiz_pro_preview_organizer_user_id()>0) return new WP_Error('user','Оплачивайте игры из собственной учётной записи организатора.');
    if (!ckmqp_scope_user_allowed($uid)) return new WP_Error('scope','Нет доступа к площадке или обновление базы ещё не завершено.');
    $tenant=function_exists('ckm_quiz_pro_access_tenant_id') ? ckm_quiz_pro_access_tenant_id($uid, null) : ckmqp_scope_id();
    if ($tenant < 0) return new WP_Error('scope','Нет доступа к площадке или обновление базы ещё не завершено.');
    if (function_exists('ckmqp_after_payment_remember_cart')) {
        ckmqp_after_payment_remember_cart($uid, (array)($cart['format_keys'] ?? []), $tenant);
    }
    // Rebuild even for calls from another PHP extension.
    $checked=ckm_quiz_pro_payment_cart($cart['format_keys']??[]);
    if (is_wp_error($checked) || !$checked['items']) return new WP_Error('cart','Некорректный заказ.');
    $cart=$checked;
    $scenario_keys=array_values(array_filter((array)$cart['format_keys'],'ckmqp_test_is_scenario_key'));
    if ($scenario_keys && count((array)$cart['format_keys'])!==1) return new WP_Error('scenario_separate','Дополнительные сценарии «Переговори другого» в тестовом контуре оплачиваются по одному. Оформите выбранный сценарий отдельным заказом.');
    if (!ckmqp_test_validate_transport_prices((array)$cart['format_keys'])) return new WP_Error('scenario_price','Цена сценария не совпадает с проверочным SKU тестового сервера.');
    $transport_keys=ckmqp_test_transport_keys((array)$cart['format_keys']);
    global $wpdb; $table=ckmqp_test_table('orders');
    $lock='ckmqp_pay_'.md5($tenant.'|'.$uid);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)',$lock))!==1) return new WP_Error('busy','Заказ уже создаётся. Повторите попытку.');
    try {
        $json=wp_json_encode($cart['format_keys']);
        $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE user_id=%d AND tenant_id=%d AND cart_json=%s AND status IN ('created','pending','waiting_for_capture') AND created_at>UTC_TIMESTAMP()-INTERVAL 22 HOUR ORDER BY created_at DESC LIMIT 1",$uid,$tenant,$json),ARRAY_A);
        if (!$order) {
            $order=['order_id'=>wp_generate_uuid4(),'user_id'=>$uid,'tenant_id'=>$tenant,'user_ref'=>hash('sha256',home_url('/').'|'.$tenant.'|'.$uid),'cart_json'=>$json,'total_minor'=>$cart['total_minor'],'status'=>'created','created_at'=>gmdate('Y-m-d H:i:s')];
            if (!$wpdb->insert($table,$order)) return new WP_Error('storage','Не удалось сохранить заказ.');
        }
        $remote=ckmqp_test_call('create',['order_id'=>$order['order_id'],'user_ref'=>$order['user_ref'],'format_keys'=>$transport_keys]);
        if (is_wp_error($remote)) return $remote;
        $result=ckmqp_test_accept($order,$remote);
        if (is_wp_error($result)) return $result;
        if (in_array($result['status'],['succeeded','canceled'],true)) return function_exists('ckmqp_return_check_url') ? ckmqp_return_check_url($order) : ckm_quiz_pro_organizer_url(['view'=>'payment','test_order'=>$order['order_id']]);
        return $result['confirmation_url']?:new WP_Error('pending','Платёж ещё создаётся. Повторите попытку.');
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock)); }
}
add_filter('ckm_create_cart_payment_url','ckmqp_test_checkout',100,3);
add_filter('ckm_create_game_payment_url',function ($url,$game,$uid) {
    if (!ckmqp_test_enabled()) return $url;
    $cart=ckm_quiz_pro_payment_cart([$game]);
    return is_wp_error($cart)?$cart:ckmqp_test_checkout($url,$cart,(int)$uid);
},100,3);
function ckmqp_test_sync(string $id,int $uid) {
    if (!ckmqp_test_enabled()) return new WP_Error('disabled','Тестовая оплата отключена.');
    if (!ckmqp_scope_user_allowed($uid)) return new WP_Error('scope','Нет доступа к площадке.');
    $order=ckmqp_return_order($id,$uid);
    if (!$order) return new WP_Error('missing','Заказ не найден в вашей учётной записи.');
    $remote=ckmqp_test_call('status',['order_id'=>$id,'user_ref'=>$order['user_ref']]);
    return is_wp_error($remote)?$remote:ckmqp_test_accept($order,$remote);
}
add_action('wp_ajax_ckmqp_test_status',function () {
    if (!check_ajax_referer('ckm_create_payment','nonce',false)) wp_send_json_error(['message'=>'Обновите страницу.'],403);
    $id=is_string($_POST['order_id']??null)?sanitize_text_field(wp_unslash($_POST['order_id'])):'';
    $result=ckmqp_test_sync($id,get_current_user_id());
    if (is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()],400);
    $order=ckmqp_return_order($id,get_current_user_id());
    wp_send_json_success(['status'=>$result['status'],'url'=>$order?ckmqp_return_destination($order):ckm_quiz_pro_organizer_url()]);
});
function ckmqp_test_return_screen(): bool {
    if (!ckmqp_test_enabled() || !isset($_GET['test_order'])) return false;
    $id=is_string($_GET['test_order'])?sanitize_text_field(wp_unslash($_GET['test_order'])):'';
    $order=ckmqp_return_order($id,get_current_user_id());
    ckm_quiz_pro_org_shell_start('Проверка тестовой оплаты');
    echo '<main class="ckm-main"><section class="ckm-card"><h1>Проверка тестовой оплаты</h1><p id="test-pay-status" role="status">Проверяем подтверждение платежа…</p>';
    if (!$order) {
        echo '<p>Заказ не найден в вашей учётной записи.</p>';
    } else {
        $config=['order_id'=>$id,'ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('ckm_create_payment'),'cabinet_url'=>ckmqp_return_destination($order)];
        echo '<button type="button" class="ckm-btn" id="test-pay-retry">Проверить ещё раз</button>';
        echo '<script>window.CKM_TEST_RETURN='.wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';</script><script src="'.esc_url(CKM_QUIZ_PRO_URL.'assets/checkout/test-return.js?ver='.CKM_QUIZ_PRO_VERSION).'" defer></script>';
    }
    echo '<p><a href="'.esc_url($order ? ckmqp_return_destination($order) : ckm_quiz_pro_organizer_url()).'">Перейти к играм</a></p></section></main>';
    ckm_quiz_pro_org_shell_end(); return true;
}
// A later visit also recovers payment if the browser was closed on YooKassa.
function ckmqp_test_recover_recent(): void {
    if (!ckmqp_test_enabled() || !ckmqp_scope_user_allowed(get_current_user_id()) || !is_user_logged_in() || ckm_quiz_pro_preview_organizer_user_id()>0) return;
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare("SELECT order_id FROM ".ckmqp_test_table('orders')." WHERE user_id=%d AND tenant_id=%d AND status IN ('created','pending','waiting_for_capture') ORDER BY created_at DESC LIMIT 1",get_current_user_id(),ckmqp_scope_id()),ARRAY_A);
    if ($row) ckmqp_test_sync($row['order_id'],get_current_user_id());
}
add_action('admin_menu',function () { add_submenu_page('ckm-quiz-pro','Тестовая оплата','Тестовая оплата','manage_options','ckmqp-test-pay','ckmqp_test_admin'); });
function ckmqp_test_admin(): void {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['test_pay_save'])) {
        check_admin_referer('ckmqp_test_settings');
        $key=trim((string)wp_unslash($_POST['pair_key']??''));
        if ($key!=='' && !preg_match('/^[a-f0-9]{64}$/D',$key)) echo '<div class="notice notice-error"><p>Ключ связи должен содержать 64 символа. Скопируйте его с сервера оплаты.</p></div>';
        else {
            if ($key!=='') update_option('ckmqp_test_pair_key',$key,false);
            update_option('ckmqp_test_pay_enabled',isset($_POST['enabled'])?'1':'0',false);
            echo '<div class="notice notice-success"><p>Настройки сохранены.</p></div>';
        }
    }
    echo '<div class="wrap"><h1>Тестовая оплата</h1><p>Shop ID: 1441400. Сервер оплаты: centr-razvitia-uma.ru.</p><form method="post">'; wp_nonce_field('ckmqp_test_settings');
    echo '<p><label><input type="checkbox" name="enabled" value="1" '.checked(ckmqp_test_enabled(),true,false).'> Включить тестовую оплату и тестовый доступ</label></p><p><label>Ключ связи сайтов<br><input type="password" class="regular-text" name="pair_key" autocomplete="new-password" value=""></label></p><p>'.(get_option('ckmqp_test_pair_key')?'Ключ сохранён. Пустое поле оставляет его без изменений.':'Скопируйте ключ из настроек серверного модуля.').'</p><p>Секретный ключ ЮKassa вводится только на сервере оплаты. При выключении тестового режима тестовые доступы перестают учитываться.</p><button class="button button-primary" name="test_pay_save" value="1">Сохранить</button></form></div>';
}

function ckmqp_test_can_launch(string $format,int $uid): bool {
    return ckm_quiz_pro_can_access_format($uid,$format);
}

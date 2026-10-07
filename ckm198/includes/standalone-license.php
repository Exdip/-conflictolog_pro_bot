<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_ensure_installation_id(): string {
    $id=(string)get_option('ckm_quiz_pro_installation_id','');
    if ($id==='') {
        $id=wp_generate_uuid4();
        update_option('ckm_quiz_pro_installation_id',$id,false);
    }
    return $id;
}

function ckm_quiz_pro_site_domain(): string {
    $host=(string)wp_parse_url(home_url('/'),PHP_URL_HOST);
    return strtolower($host);
}

function ckm_quiz_pro_cloud_base_url(): string {
    return untrailingslashit((string)apply_filters('ckm_quiz_pro_cloud_base_url','https://api.centr-razvitia-uma.ru/v1'));
}


function ckm_quiz_pro_managed_sites_base_domain(): string {
    return strtolower((string)apply_filters('ckm_quiz_pro_managed_sites_base_domain','ckkm.ru'));
}

function ckm_quiz_pro_gateway_base_url(): string {
    return untrailingslashit((string)apply_filters('ckm_quiz_pro_gateway_base_url','https://gateway.video-training.ru'));
}

function ckm_quiz_pro_primary_site_url(): string {
    return untrailingslashit((string)apply_filters('ckm_quiz_pro_primary_site_url','https://centr-razvitia-uma.ru'));
}

function ckm_quiz_pro_license_state(): array {
    $state=get_option('ckm_quiz_pro_license_state',[]);
    return is_array($state)?$state:[];
}

function ckm_quiz_pro_license_key(): string {
    return trim((string)get_option('ckm_quiz_pro_license_key',''));
}

function ckm_quiz_pro_remote_license_check(): array|WP_Error {
    $licenseKey=ckm_quiz_pro_license_key();
    if ($licenseKey==='') return new WP_Error('license_key_missing','Сначала введите лицензионный ключ.');
    $nonce=bin2hex(random_bytes(16));
    $request=[
        'license_key'=>$licenseKey,
        'plugin_slug'=>'ckm-quiz-pro',
        'plugin_version'=>CKM_QUIZ_PRO_VERSION,
        'domain'=>ckm_quiz_pro_site_domain(),
        'installation_id'=>ckm_quiz_pro_ensure_installation_id(),
        'request_nonce'=>$nonce,
    ];
    $response=wp_remote_post(ckm_quiz_pro_cloud_base_url().'/license/check',[
        'timeout'=>12,
        'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],
        'body'=>wp_json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        'data_format'=>'body',
    ]);
    if (is_wp_error($response)) return $response;
    $code=(int)wp_remote_retrieve_response_code($response);
    $body=json_decode((string)wp_remote_retrieve_body($response),true);
    if ($code<200 || $code>=300 || !is_array($body)) {
        return new WP_Error('cloud_license_http','Облачный сервис вернул некорректный ответ лицензии (HTTP '.$code.').');
    }
    $payload=ckm_quiz_pro_verify_license_response($body,[
        'domain'=>$request['domain'],
        'installation_id'=>$request['installation_id'],
        'request_nonce'=>$nonce,
    ]);
    if (is_wp_error($payload)) return $payload;
    update_option('ckm_quiz_pro_license_state',[
        'payload'=>$payload,
        'envelope'=>$body,
        'checked_at'=>gmdate('c'),
    ],false);
    return $payload;
}

function ckm_quiz_pro_license_admin_menu(): void {
    add_submenu_page('ckm-quiz-pro','Лицензия','Лицензия','manage_options','ckm-quiz-pro-license','ckm_quiz_pro_license_page');
}
add_action('admin_menu','ckm_quiz_pro_license_admin_menu',30);

function ckm_quiz_pro_license_page(): void {
    if(!current_user_can('manage_options')) return;
    $notice='';
    $noticeClass='info';
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_license_save'])){
        check_admin_referer('ckm_quiz_pro_license');
        update_option('ckm_quiz_pro_license_key',sanitize_text_field(wp_unslash($_POST['license_key']??'')),false);
        $notice='Лицензионный ключ сохранён.';
        $noticeClass='success';
    }
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_license_check'])){
        check_admin_referer('ckm_quiz_pro_license');
        $r=ckm_quiz_pro_remote_license_check();
        if(is_wp_error($r)){ $notice=$r->get_error_message(); $noticeClass='error'; }
        else { $notice='Подпись Облачный сервис проверена. Статус лицензии: '.(string)($r['status']??'unknown').'.'; $noticeClass='success'; }
    }
    $state=ckm_quiz_pro_license_state();
    $payload=(array)($state['payload']??[]);
    $crypto=ckm_quiz_pro_crypto_self_test();
    echo '<div class="wrap"><h1>Лицензия</h1>';
    if($notice!=='') echo '<div class="notice notice-'.esc_attr($noticeClass).'"><p>'.esc_html($notice).'</p></div>';
    echo '<p>Каждый ответ лицензии и каждый update-manifest принимается только после успешной проверки <strong>Ed25519</strong>. Закрытый ключ в плагине отсутствует.</p>';
    echo '<table class="form-table"><tr><th>Installation ID</th><td><code>'.esc_html(ckm_quiz_pro_ensure_installation_id()).'</code></td></tr><tr><th>Текущий домен</th><td><code>'.esc_html(ckm_quiz_pro_site_domain()).'</code></td></tr><tr><th>Зона управляемых сайтов</th><td><code>*.'.esc_html(ckm_quiz_pro_managed_sites_base_domain()).'</code></td></tr><tr><th>Основной сайт</th><td><code>'.esc_html(ckm_quiz_pro_primary_site_url()).'</code></td></tr><tr><th>Облачный сервис API</th><td><code>'.esc_html(ckm_quiz_pro_cloud_base_url()).'</code></td></tr><tr><th>Realtime / голос</th><td><code>'.esc_html(ckm_quiz_pro_gateway_base_url()).'</code></td></tr><tr><th>Signing key ID</th><td><code>'.esc_html(CKM_QUIZ_PRO_ED25519_KEY_ID).'</code> <small>(DEV preview)</small></td></tr></table>';
    echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_license');
    echo '<table class="form-table"><tr><th>Лицензионный ключ</th><td><input type="text" class="regular-text" name="license_key" value="'.esc_attr(ckm_quiz_pro_license_key()).'" autocomplete="off"></td></tr></table>';
    echo '<p><button class="button button-primary" name="ckm_qp_license_save" value="1">Сохранить ключ</button> <button class="button" name="ckm_qp_license_check" value="1">Проверить в Облачный сервис</button></p></form>';
    echo '<h2>Последний проверенный статус</h2>';
    if(!$payload) echo '<p>Подписанный ответ Облачный сервис ещё не получен.</p>';
    else echo '<table class="widefat striped" style="max-width:900px"><tbody><tr><th>Статус</th><td>'.esc_html((string)($payload['status']??'')).'</td></tr><tr><th>Тариф</th><td>'.esc_html((string)($payload['plan']??'')).'</td></tr><tr><th>Действует до</th><td>'.esc_html((string)($payload['expires_at']??'')).'</td></tr><tr><th>Проверено</th><td>'.esc_html((string)($state['checked_at']??'')).'</td></tr></tbody></table>';
    echo '<h2>Криптографический self-test</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Проверка</th><th>Результат</th><th>Детали</th></tr></thead><tbody>';
    foreach($crypto as $r) echo '<tr><td>'.esc_html($r[0]).'</td><td><strong style="color:'.($r[1]?'#008a20':'#b32d2e').'">'.($r[1]?'PASS':'FAIL').'</strong></td><td><code>'.esc_html($r[2]).'</code></td></tr>';
    echo '</tbody></table><p><small>В этой dev-сборке используется отдельный тестовый public key. В коммерческом релизе он будет заменён production-ключом Облачный сервис, созданным и хранимым только на сервере.</small></p></div>';
}

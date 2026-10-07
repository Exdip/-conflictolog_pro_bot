<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_ENTITLEMENTS_SCHEMA = 'ckm.entitlements.v1';

function ckm_quiz_pro_verify_entitlements_response(array $envelope, array $expected=[]): array|WP_Error {
    $payload = ckm_quiz_pro_verify_signed_envelope($envelope, CKM_QUIZ_PRO_ENTITLEMENTS_SCHEMA);
    if (is_wp_error($payload)) return $payload;
    foreach (['license_id','domain','installation_id','issued_at','valid_until','packages'] as $required) {
        if (!array_key_exists($required,$payload)) return new WP_Error('entitlements_payload_invalid','В signed entitlements-response отсутствует поле '.$required.'.');
    }
    if (!is_array($payload['packages'])) return new WP_Error('entitlements_packages_invalid','Поле packages должно быть массивом.');
    if (!empty($expected['domain']) && strtolower((string)$payload['domain']) !== strtolower((string)$expected['domain'])) return new WP_Error('entitlements_domain_mismatch','Каталог покупок выдан для другого домена.');
    if (!empty($expected['installation_id']) && !hash_equals((string)$expected['installation_id'],(string)$payload['installation_id'])) return new WP_Error('entitlements_installation_mismatch','Каталог покупок выдан для другой установки.');
    if (array_key_exists('request_nonce',$expected) && (string)($payload['request_nonce']??'') !== (string)$expected['request_nonce']) return new WP_Error('entitlements_nonce_mismatch','Каталог покупок не соответствует текущему запросу.');
    $issued=ckm_quiz_pro_parse_utc((string)$payload['issued_at']);
    $valid=ckm_quiz_pro_parse_utc((string)$payload['valid_until']);
    $now=time();
    if ($issued===false || $valid===false || $issued>$now+300 || $valid<$now) return new WP_Error('entitlements_response_stale','Подписанный каталог покупок просрочен или имеет неверное время.');
    foreach ($payload['packages'] as $p) {
        if (!is_array($p) || empty($p['package_id']) || empty($p['title']) || empty($p['format_key']) || empty($p['entitlement'])) return new WP_Error('entitlement_item_invalid','В каталоге есть неполная запись игры.');
    }
    return $payload;
}

function ckm_quiz_pro_entitlements_state(): array {
    $v=get_option('ckm_quiz_pro_entitlements_state',[]);
    return is_array($v)?$v:[];
}

function ckm_quiz_pro_store_entitlements(array $payload, array $envelope=[]): void {
    global $wpdb;
    $table=ckm_quiz_pro_table('entitlements');
    $now=current_time('mysql');
    foreach ((array)$payload['packages'] as $p) {
        $packageId=sanitize_key((string)$p['package_id']);
        if ($packageId==='') continue;
        $row=[
            'package_id'=>$packageId,
            'title'=>sanitize_text_field((string)$p['title']),
            'format_key'=>sanitize_key((string)$p['format_key']),
            'package_version'=>sanitize_text_field((string)($p['package_version']??'1')),
            'entitlement'=>sanitize_key((string)$p['entitlement']),
            'delivery_mode'=>sanitize_key((string)($p['delivery_mode']??'cloud_package')),
            'package_url'=>esc_url_raw((string)($p['package_url']??'')),
            'expires_at'=>sanitize_text_field((string)($p['expires_at']??'')),
            'metadata_json'=>wp_json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>$now,
        ];
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE package_id=%s LIMIT 1",$packageId));
        if ($id>0) $wpdb->update($table,$row,['id'=>$id]);
        else { $row['created_at']=$now; $wpdb->insert($table,$row); }
    }
    update_option('ckm_quiz_pro_entitlements_state',['payload'=>$payload,'envelope'=>$envelope,'synced_at'=>gmdate('c')],false);
}

function ckm_quiz_pro_sync_entitlements_from_fixture(): array|WP_Error {
    $file=CKM_QUIZ_PRO_DIR.'fixtures/signed-entitlements-response.json';
    if (!is_readable($file)) return new WP_Error('entitlements_fixture_missing','Не найден DEV signed-entitlements fixture.');
    $env=json_decode((string)file_get_contents($file),true);
    if (!is_array($env)) return new WP_Error('entitlements_fixture_invalid','Некорректный DEV signed-entitlements fixture.');
    $payload=ckm_quiz_pro_verify_entitlements_response($env);
    if (is_wp_error($payload)) return $payload;
    // DEV fixture is intentionally portable so that it can be tested on ckkm.ru.
    ckm_quiz_pro_store_entitlements($payload,$env);
    return $payload;
}

function ckm_quiz_pro_remote_entitlements_sync(): array|WP_Error {
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
    $response=wp_remote_post(ckm_quiz_pro_cloud_base_url().'/catalog/entitlements',[
        'timeout'=>15,
        'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],
        'body'=>wp_json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'data_format'=>'body',
    ]);
    if (is_wp_error($response)) return $response;
    $code=(int)wp_remote_retrieve_response_code($response);
    $env=json_decode((string)wp_remote_retrieve_body($response),true);
    if ($code<200 || $code>=300 || !is_array($env)) return new WP_Error('entitlements_http','Облачный сервис вернул некорректный каталог покупок (HTTP '.$code.').');
    $payload=ckm_quiz_pro_verify_entitlements_response($env,['domain'=>$request['domain'],'installation_id'=>$request['installation_id'],'request_nonce'=>$nonce]);
    if (is_wp_error($payload)) return $payload;
    ckm_quiz_pro_store_entitlements($payload,$env);
    return $payload;
}

function ckm_quiz_pro_entitlement_install_dev(string $packageId): array|WP_Error {
    if ($packageId!=='ckm-cloud-demo-classic') return new WP_Error('dev_package_unknown','Для этой покупки DEV-доставка не определена.');
    ckm_quiz_pro_seed_demo_quiz();
    global $wpdb;
    $quiz=$wpdb->get_row("SELECT * FROM ".ckm_quiz_pro_table('quizzes')." WHERE slug='demo-classic-quiz' LIMIT 1",ARRAY_A);
    if (!$quiz) return new WP_Error('demo_missing','Не удалось создать локальную демо-игру.');
    $table=ckm_quiz_pro_table('packages');
    $now=current_time('mysql');
    $row=[
        'package_id'=>$packageId,'package_version'=>'1.0.0','title'=>'Классический квиз','format_key'=>'classic_quiz',
        'quiz_id'=>(int)$quiz['id'],'quiz_revision'=>(int)$quiz['current_revision'],'source_type'=>'cloud_entitlement_dev','trust_level'=>'entitlement_signed_dev','signing_key_id'=>CKM_QUIZ_PRO_ED25519_KEY_ID,
        'editable'=>0,'content_sha256'=>'','manifest_json'=>wp_json_encode(['schema'=>'ckm.dev-entitled-package.v1','package_id'=>$packageId]),'installed_at'=>$now,'updated_at'=>$now,
    ];
    $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE package_id=%s LIMIT 1",$packageId));
    if ($existing>0) $wpdb->update($table,$row,['id'=>$existing]); else $wpdb->insert($table,$row);
    return ['package_id'=>$packageId,'title'=>$row['title'],'quiz_id'=>$row['quiz_id']];
}

function ckm_quiz_pro_entitlements_admin_actions(): array {
    $out=['notice'=>'','error'=>''];
    if ($_SERVER['REQUEST_METHOD']!=='POST') return $out;
    if (isset($_POST['ckm_qp_sync_entitlements_dev'])) {
        check_admin_referer('ckm_quiz_pro_entitlements');
        $r=ckm_quiz_pro_sync_entitlements_from_fixture();
        if (is_wp_error($r)) $out['error']=$r->get_error_message(); else $out['notice']='DEV-каталог покупок синхронизирован. Ed25519-подпись проверена.';
    }
    if (isset($_POST['ckm_qp_sync_entitlements_cloud'])) {
        check_admin_referer('ckm_quiz_pro_entitlements');
        $r=ckm_quiz_pro_remote_entitlements_sync();
        if (is_wp_error($r)) $out['error']=$r->get_error_message(); else $out['notice']='Каталог купленных игр синхронизирован с Облачный сервис.';
    }
    if (isset($_POST['ckm_qp_install_entitled_dev'])) {
        check_admin_referer('ckm_quiz_pro_entitlements');
        $r=ckm_quiz_pro_entitlement_install_dev(sanitize_key(wp_unslash($_POST['package_id']??'')));
        if (is_wp_error($r)) $out['error']=$r->get_error_message(); else $out['notice']='Купленная DEV-игра установлена: '.$r['title'].'.';
    }
    return $out;
}

function ckm_quiz_pro_render_entitlements_catalog(): void {
    global $wpdb;
    $table=ckm_quiz_pro_table('entitlements');
    $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC",ARRAY_A) ?: [];
    $state=ckm_quiz_pro_entitlements_state();
    echo '<div class="card" style="max-width:1100px"><h2>Купленные в Облачный сервис</h2><p>Сервер возвращает только игры, разрешённые текущей лицензии. Каталог принимается только после проверки Ed25519.</p>';
    echo '<form method="post" style="display:inline-block;margin-right:8px">'; wp_nonce_field('ckm_quiz_pro_entitlements'); echo '<button class="button button-primary" name="ckm_qp_sync_entitlements_cloud" value="1">Синхронизировать покупки</button></form>';
    echo '<form method="post" style="display:inline-block">'; wp_nonce_field('ckm_quiz_pro_entitlements'); echo '<button class="button" name="ckm_qp_sync_entitlements_dev" value="1">DEV: загрузить подписанный каталог</button></form>';
    if(!empty($state['synced_at'])) echo '<p><small>Последняя синхронизация: '.esc_html((string)$state['synced_at']).'</small></p>';
    echo '<table class="widefat striped"><thead><tr><th>Игра</th><th>Формат</th><th>Версия</th><th>Право</th><th>Действие</th></tr></thead><tbody>';
    foreach($rows as $r){
        $installed=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('packages').' WHERE package_id=%s',(string)$r['package_id']))>0;
        $action=$installed?'<strong style="color:#008a20">Установлена</strong>':'';
        if(!$installed && (string)$r['delivery_mode']==='local_fixture'){
            $action='<form method="post">'; ob_start(); wp_nonce_field('ckm_quiz_pro_entitlements'); $nonce=ob_get_clean(); $action.=$nonce.'<input type="hidden" name="package_id" value="'.esc_attr($r['package_id']).'"><button class="button button-primary" name="ckm_qp_install_entitled_dev" value="1">Установить DEV</button></form>';
        } elseif(!$installed && (string)$r['package_url']!=='') $action='<span>Готова к Cloud-доставке</span>';
        echo '<tr><td><strong>'.esc_html($r['title']).'</strong><br><code>'.esc_html($r['package_id']).'</code></td><td>'.esc_html($r['format_key']).'</td><td>'.esc_html($r['package_version']).'</td><td>'.esc_html($r['entitlement']).'</td><td>'.$action.'</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="5">Каталог ещё не синхронизирован.</td></tr>';
    echo '</tbody></table><p><small>DEV-кнопка проверяет полный путь signed entitlement → license mapping → «Мои игры». Реальный endpoint: <code>POST /v1/catalog/entitlements</code>.</small></p></div>';
}

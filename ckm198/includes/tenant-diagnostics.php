<?php
if (!defined('ABSPATH')) exit;
/** Covers responses that actually reach WordPress; static/CDN cache rules are separate. */
function ckmqp_dynamic_request(): bool {
    $ctx=$GLOBALS['ckmqp_tenant_context']??[];
    if (isset($ctx['kind']) && $ctx['kind']!=='platform') return true;
    $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    $base=rtrim((string)wp_parse_url((string)get_option('home'),PHP_URL_PATH),'/');
    if ($base!=='' && str_starts_with($path,$base.'/')) $path=substr($path,strlen($base));
    foreach (['/ckm/','/ckm-organizer/','/ckm-login/','/ckm-register/','/ckm-sites/'] as $prefix) if ($path===rtrim($prefix,'/') || str_starts_with($path,$prefix)) return true;
    $action=$_REQUEST['action']??'';
    return str_ends_with($path,'/admin-ajax.php') && is_string($action) && (str_starts_with($action,'ckm_') || str_starts_with($action,'ckmqp_'));
}
function ckmqp_dynamic_nocache(): void {
    if (!ckmqp_dynamic_request()) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
    nocache_headers();
    if (!headers_sent()) { header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');header('Vary: Cookie',false); }
}
add_action('send_headers','ckmqp_dynamic_nocache',1000);
function ckmqp_probe_matches($token,array $context,$reservation): bool {
    return is_string($token) && (bool)preg_match('/^[a-f0-9]{64}$/D',$token) && is_array($reservation)
        && (int)($reservation['expires']??0)>=time()
        && (int)($reservation['tenant_id']??0)>0
        && (int)($context['tenant_id']??0)===(int)$reservation['tenant_id']
        && ($context['kind']??'')==='staged';
}
function ckmqp_probe_respond(array $context): void {
    $token=$_GET['ckmqp_probe']??null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) return;
    $reservation=get_transient('ckmqp_probe_'.$token);
    if (!ckmqp_probe_matches($token,$context,$reservation)) return;
    nocache_headers();header('Cache-Control: private, no-store, max-age=0');
    wp_send_json(['probe'=>$token,'tenant_id'=>(int)$context['tenant_id'],'hostname'=>ckmqp_tenant_hostname((string)($_SERVER['HTTP_HOST']??'')),'version'=>CKM_QUIZ_PRO_VERSION],200);
}
function ckmqp_probe_result_valid($data,int $tenant,string $host,string $token): bool {
    return is_array($data) && is_string($data['probe']??null) && hash_equals($token,$data['probe'])
        && ($data['tenant_id']??null)===$tenant && ($data['hostname']??'')===$host && ($data['version']??'')===CKM_QUIZ_PRO_VERSION;
}
function ckmqp_probe_run(int $tenant): array {
    if (!current_user_can('manage_options') || $tenant<=0) return ['ok'=>false,'message'=>'Недостаточно прав или неверная площадка.'];
    $url=ckmqp_tenant_link(home_url('/'),$tenant);
    if ($url==='') return ['ok'=>false,'message'=>'Нет допустимого зарегистрированного поддомена.'];
    $host=(string)wp_parse_url($url,PHP_URL_HOST);
    $token=bin2hex(random_bytes(32));$key='ckmqp_probe_'.$token;
    if (!set_transient($key,['tenant_id'=>$tenant,'expires'=>time()+60],60)) return ['ok'=>false,'message'=>'Не удалось подготовить проверку.'];
    try {
        // Reject private IP destinations; TLS verification and same-host response required.
        $response=wp_safe_remote_get(add_query_arg('ckmqp_probe',$token,$url),['timeout'=>12,'redirection'=>0,'sslverify'=>true,'headers'=>['Cache-Control'=>'no-cache'],'limit_response_size'=>4096]);
        if (is_wp_error($response)) return ['ok'=>false,'message'=>'HTTPS-проверка не выполнена: '.$response->get_error_message()];
        $status=(int)wp_remote_retrieve_response_code($response);
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if ($status!==200 || !ckmqp_probe_result_valid($data,$tenant,$host,$token)) return ['ok'=>false,'message'=>'Ответ не соответствует площадке. HTTP '.$status.'. Проверьте DNS, SSL, перенаправления и кэш.'];
        return ['ok'=>true,'message'=>'HTTPS-запрос дошёл до этой установки; поддомен и площадка определены верно. Устаревший ответ кэша не получен.'];
    } finally { delete_transient($key); }
}
add_action('admin_menu',static function () { add_submenu_page('ckm-quiz-pro','Проверка поддоменов','Проверка поддоменов','manage_options','ckmqp-diagnostics','ckmqp_diagnostics_admin'); });
function ckmqp_diagnostics_admin(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Проверка поддоменов</h1><p>Проверка не открывает площадку посетителям и не меняет DNS или сертификаты.</p>';
    if (isset($_POST['probe'])) {
        check_admin_referer('ckmqp_diagnostics');$tenant=absint($_POST['tenant_id']??0);$result=ckmqp_probe_run($tenant);
        echo '<div class="notice '.($result['ok']?'notice-success':'notice-error').'"><p>'.esc_html($result['message']).'</p></div>';
        if ($tenant>0 && ckmqp_content_ready()) {
            global $wpdb;$q=ckm_quiz_pro_table('quizzes');$questions=ckm_quiz_pro_table('questions');
            $media=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$questions` x INNER JOIN `$q` q ON q.id=x.quiz_id WHERE q.tenant_id=%d AND x.media_url IS NOT NULL AND x.media_url<>''",$tenant));
            $covers=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$q` WHERE tenant_id=%d AND cover_url IS NOT NULL AND cover_url<>''",$tenant));
            echo '<p>Медиа в вопросах площадки: '.$media.'. Обложки: '.$covers.'.</p><p>Это ссылки на медиа, а не подтверждение их приватности. Прямые файлы хостинга и внешние видео требуют отдельной проверки доступа.</p>';
        }
    }
    echo '<form method="post">';wp_nonce_field('ckmqp_diagnostics');
    echo '<p><label>ID площадки <input type="number" name="tenant_id" min="1" required></label></p><button class="button button-primary" name="probe" value="1">Проверить HTTPS и маршрутизацию</button></form><h2>Что остаётся проверить</h2><ul><li>DNS wildcard и сертификат основного домена и поддоменов.</li><li>Исключения кэша для кабинета, входа, регистрации, комнат и AJAX.</li><li>Ключ кэша должен учитывать hostname. Не объединять ответы разных поддоменов.</li><li>Отдельно проверить вход, cookies и оплату в браузере.</li><li>Приватные медиа нельзя защищать только скрытием ссылки в интерфейсе.</li></ul><p>Плагин выставляет no-store для динамических ответов, дошедших до WordPress. Кэш CDN, веб-сервера и advanced-cache.php может сработать раньше: его исключения настраиваются отдельно.</p></div>';
}

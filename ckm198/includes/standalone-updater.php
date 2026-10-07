<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_fetch_signed_update_manifest(bool $force=false): array|WP_Error {
    $cached=get_transient('ckm_quiz_pro_update_manifest');
    if(!$force && is_array($cached)) return $cached;

    $state=ckm_quiz_pro_license_state();
    $licensePayload=(array)($state['payload']??[]);
    if((string)($licensePayload['status']??'')!=='active') return new WP_Error('license_inactive','Активная подписанная лицензия отсутствует.');
    $features=(array)($licensePayload['features']??[]);
    if(array_key_exists('updates',$features) && !$features['updates']) return new WP_Error('updates_not_allowed','Обновления не входят в текущую лицензию.');

    $nonce=bin2hex(random_bytes(16));
    $request=[
        'license_key'=>ckm_quiz_pro_license_key(),
        'plugin_slug'=>'ckm-quiz-pro',
        'current_version'=>CKM_QUIZ_PRO_VERSION,
        'domain'=>ckm_quiz_pro_site_domain(),
        'installation_id'=>ckm_quiz_pro_ensure_installation_id(),
        'request_nonce'=>$nonce,
    ];
    $response=wp_remote_post(ckm_quiz_pro_cloud_base_url().'/plugin/update',[
        'timeout'=>12,
        'headers'=>['Content-Type'=>'application/json','Accept'=>'application/json'],
        'body'=>wp_json_encode($request,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        'data_format'=>'body',
    ]);
    if(is_wp_error($response)) return $response;
    $code=(int)wp_remote_retrieve_response_code($response);
    $body=json_decode((string)wp_remote_retrieve_body($response),true);
    if($code<200 || $code>=300 || !is_array($body)) return new WP_Error('update_manifest_http','Облачный сервис вернул некорректный update-manifest (HTTP '.$code.').');
    $payload=ckm_quiz_pro_verify_update_manifest($body,['request_nonce'=>$nonce]);
    if(is_wp_error($payload)) return $payload;
    set_transient('ckm_quiz_pro_update_manifest',$payload,6*HOUR_IN_SECONDS);
    return $payload;
}

function ckm_quiz_pro_inject_signed_update($transient) {
    if(!is_object($transient) || empty($transient->checked)) return $transient;
    if(!isset($transient->checked[plugin_basename(CKM_QUIZ_PRO_FILE)])) return $transient;
    $manifest=ckm_quiz_pro_fetch_signed_update_manifest(false);
    if(is_wp_error($manifest)) return $transient;
    if(version_compare((string)$manifest['version'],CKM_QUIZ_PRO_VERSION,'<=')) return $transient;
    $obj=(object)[
        'slug'=>'ckm-quiz-pro',
        'plugin'=>plugin_basename(CKM_QUIZ_PRO_FILE),
        'new_version'=>(string)$manifest['version'],
        'url'=>(string)($manifest['release_notes_url']??''),
        'package'=>(string)$manifest['package_url'],
        'requires'=>(string)($manifest['requires_wp']??''),
        'tested'=>(string)($manifest['tested_wp']??''),
        'requires_php'=>(string)($manifest['min_php']??'8.3'),
    ];
    $transient->response[plugin_basename(CKM_QUIZ_PRO_FILE)]=$obj;
    return $transient;
}
add_filter('pre_set_site_transient_update_plugins','ckm_quiz_pro_inject_signed_update');

function ckm_quiz_pro_verify_update_download($reply, string $package, $upgrader, array $hookExtra) {
    if($reply!==false) return $reply;
    if(($hookExtra['plugin']??'')!==plugin_basename(CKM_QUIZ_PRO_FILE)) return false;
    $manifest=get_transient('ckm_quiz_pro_update_manifest');
    if(!is_array($manifest) || empty($manifest['package_url']) || !hash_equals((string)$manifest['package_url'],$package)) return false;
    require_once ABSPATH.'wp-admin/includes/file.php';
    $tmp=download_url($package,300);
    if(is_wp_error($tmp)) return $tmp;
    $actual=hash_file('sha256',$tmp);
    if(!is_string($actual) || !hash_equals(strtolower((string)$manifest['sha256']),strtolower($actual))){
        @unlink($tmp);
        return new WP_Error('update_package_hash_mismatch','Игровая платформа: SHA-256 скачанного обновления не совпадает с подписанным update-manifest.');
    }
    return $tmp;
}
add_filter('upgrader_pre_download','ckm_quiz_pro_verify_update_download',10,4);

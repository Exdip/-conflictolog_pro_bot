<?php
if (!defined('ABSPATH')) exit;

/**
 * Payment connector foundation.
 * Payment unlocks game formats inside the current/recorded tenant.
 * Creating a tenant never grants a game entitlement.
 */
function ckm_quiz_pro_payment_create_access(int $user_id, string $format_key, int $days = 30, ?int $tenant_id = null, string $grant_ref = ''): bool {
    global $wpdb;
    if (!ckmqp_scope_ready()) return false;
    $format_key=ckm_quiz_pro_access_format_key($format_key);
    if (!$user_id || !isset(ckm_quiz_pro_game_access_products()[$format_key])) return false;

    if ($tenant_id===null) $tenant_id=function_exists('ckm_quiz_pro_access_tenant_id') ? ckm_quiz_pro_access_tenant_id($user_id, null) : ckmqp_scope_id();
    if ($tenant_id<0) return false;
    if ($tenant_id>0 && !ckmqp_tenant_is_member($tenant_id,$user_id)) return false;

    $table = ckm_quiz_pro_table('game_access');

    // Real gateways may deliver the same success callback more than once.
    // A stable grant reference prevents a duplicate callback from extending
    // access repeatedly. No new DB table is needed.
    $grant_ref=trim($grant_ref);
    $grant_hash=$grant_ref!=='' ? hash('sha256',$tenant_id.'|'.$user_id.'|'.$format_key.'|'.$grant_ref) : '';
    $lock='ckm_qp_grant_'.substr($grant_hash!==''?$grant_hash:md5($tenant_id.'|'.$user_id.'|'.$format_key),0,32);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)',$lock))!==1) return false;
    try {
        if ($grant_hash!=='') {
            $seen=get_option('ckm_quiz_pro_processed_payment_grants',[]);
            if (!is_array($seen)) $seen=[];
            $cut=time()-90*DAY_IN_SECONDS;
            foreach ($seen as $hash=>$ts) if ((int)$ts<$cut) unset($seen[$hash]);
            if (isset($seen[$grant_hash])) return true;
        }

        $expires=function_exists('ckm_quiz_pro_access_extension_expires')
            ? ckm_quiz_pro_access_extension_expires($user_id,$format_key,$days,$tenant_id,time())
            : gmdate('Y-m-d H:i:s', time() + max(1,$days) * DAY_IN_SECONDS);
        $ok=(bool)$wpdb->insert($table,[
        'tenant_id'=>$tenant_id,
        'user_id'=>$user_id,
        'format_key'=>$format_key,
        'started_at'=>gmdate('Y-m-d H:i:s'),
        'expires_at'=>$expires,
        'status'=>'active'
        ]);
        if ($ok && function_exists('ckm_quiz_pro_ready_game_snapshot_ensure')) {
            // Catalogue purchases receive their own frozen quiz copy. Renewal
            // keeps that same copy; edits of the source catalogue template do
            // not rewrite an already purchased game.
            ckm_quiz_pro_ready_game_snapshot_ensure($user_id,$format_key,$tenant_id);
        }
        if ($ok && $grant_hash!=='') {
            $seen[$grant_hash]=time();
            if (count($seen)>500) { asort($seen,SORT_NUMERIC); $seen=array_slice($seen,-500,null,true); }
            update_option('ckm_quiz_pro_processed_payment_grants',$seen,false);
        }
        return $ok;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
}

function ckm_quiz_pro_payment_grant_ref(array $payload): string {
    foreach (['payment_id','order_id','transaction_id','yookassa_id'] as $field) {
        if (isset($payload[$field]) && is_scalar($payload[$field]) && trim((string)$payload[$field])!=='') {
            return $field.':'.sanitize_text_field((string)$payload[$field]);
        }
    }
    if (isset($payload['object']) && is_array($payload['object']) && isset($payload['object']['id']) && is_scalar($payload['object']['id'])) {
        return 'object_id:'.sanitize_text_field((string)$payload['object']['id']);
    }
    // Fallback still deduplicates identical webhook payloads.
    return 'payload:'.hash('sha256',wp_json_encode($payload));
}

function ckm_quiz_pro_payment_success(array $payload): bool {
    $tenant=array_key_exists('tenant_id',$payload) ? (int)$payload['tenant_id'] : null;
    return ckm_quiz_pro_payment_create_access(
        (int)($payload['user_id'] ?? 0),
        sanitize_key($payload['format_key'] ?? ''),
        30,
        $tenant,
        ckm_quiz_pro_payment_grant_ref($payload)
    );
}
add_action('ckm_payment_success','ckm_quiz_pro_payment_success');

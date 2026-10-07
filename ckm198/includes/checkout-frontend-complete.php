<?php
if (!defined('ABSPATH')) exit;
add_action('wp_enqueue_scripts', function () {
    $post = get_post();
    if (!is_singular() || !$post || !has_shortcode($post->post_content, 'ckm_quiz_checkout')) return;
    wp_enqueue_style('ckm-checkout-css', CKM_QUIZ_PRO_URL.'assets/checkout/checkout.css', array(), CKM_QUIZ_PRO_VERSION);
    wp_enqueue_script('ckm-checkout-js', CKM_QUIZ_PRO_URL.'assets/checkout/checkout.js', array(), CKM_QUIZ_PRO_VERSION, true);
    wp_localize_script('ckm-checkout-js', 'CKM_CHECKOUT', array('ajax_url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('ckm_create_payment')));
});
add_action('wp_ajax_ckm_create_payment', 'ckm_create_payment');
add_action('wp_ajax_nopriv_ckm_create_payment', 'ckm_create_payment');

/**
 * Resolve the organizer represented by an administrator preview during checkout.
 * The regular customer path still uses the authenticated user. Preview context
 * must be explicitly signed and can never be supplied by a normal organizer.
 */
function ckm_quiz_pro_checkout_preview_user_id(): int {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return 0;
    $id = absint($_POST['ckm_preview_user'] ?? 0);
    if ($id <= 0) return 0;
    $nonce = sanitize_text_field(wp_unslash($_POST['ckm_preview_nonce'] ?? ''));
    if ($nonce === '' || !wp_verify_nonce($nonce, 'ckm_qp_preview_organizer_'.$id)) return 0;
    $u = get_user_by('id', $id);
    if (!$u || !defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE, (array)$u->roles, true)) return 0;
    return $id;
}

function ckm_quiz_pro_checkout_effective_user_id(): int {
    $preview = ckm_quiz_pro_checkout_preview_user_id();
    return $preview > 0 ? $preview : get_current_user_id();
}

/** Keep a verified admin preview when AJAX redirects back to the organizer UI. */
function ckm_quiz_pro_checkout_organizer_url(array $args, int $preview_user_id = 0): string {
    $url = ckm_quiz_pro_organizer_url($args);
    if ($preview_user_id > 0 && is_user_logged_in() && current_user_can('manage_options')) {
        $url = add_query_arg([
            'ckm_preview_user' => $preview_user_id,
            'ckm_preview_nonce' => wp_create_nonce('ckm_qp_preview_organizer_'.$preview_user_id),
        ], $url);
    }
    return $url;
}
function ckm_create_payment() {
    if (!check_ajax_referer('ckm_create_payment', 'nonce', false)) wp_send_json_error(array('message'=>'Обновите страницу и повторите попытку.'), 403);
    $selection = isset($_POST['games']) ? wp_unslash($_POST['games']) : (isset($_POST['game']) ? wp_unslash($_POST['game']) : '');
    $renew=!empty($_POST['renew']);
    $cart = ckm_quiz_pro_payment_cart($selection);
    if (is_wp_error($cart)) wp_send_json_error(['message'=>$cart->get_error_message()], 400);
    if (!$cart['items']) wp_send_json_error(['message'=>'Выберите хотя бы одну игру.'], 400);
    if ($renew && count($cart['format_keys'])!==1) wp_send_json_error(['message'=>'Продление выполняется по одной игре за раз.'],400);

    $uid = is_user_logged_in() ? ckm_quiz_pro_checkout_effective_user_id() : 0;
    $preview_uid = is_user_logged_in() ? ckm_quiz_pro_checkout_preview_user_id() : 0;

    // Active access is not a payment error. Open it immediately. If a mixed
    // cart contains both active and unpaid products, silently remove the
    // active products and charge only the products that still need access.
    if ($uid > 0 && !$renew) {
        $active=[];$pending=[];
        foreach ($cart['format_keys'] as $key) {
            if (ckm_quiz_pro_can_access_format($uid,$key)) $active[]=$key;
            else $pending[]=$key;
        }
        if ($active && !$pending) {
            $args=['view'=>'library'];
            if (count($active)===1) $args['format']=$active[0];
            wp_send_json_success([
                'url'=>ckm_quiz_pro_checkout_organizer_url($args,$preview_uid),
                'already_owned'=>true,
                'message'=>'Доступ уже активен. Открываем игру.',
                'format_keys'=>$active,
            ]);
        }
        if ($active && $pending) {
            $cart=ckm_quiz_pro_payment_cart($pending);
            if (is_wp_error($cart) || !$cart['items']) wp_send_json_error(['message'=>'Не удалось обновить состав заказа.'],400);
        }
    }

    if (!is_user_logged_in()) {
        $args=['view'=>'payment','games'=>implode(',', $cart['format_keys'])];
        if ($renew) $args['renew']=1;
        $return_url = ckm_quiz_pro_organizer_url($args);
        wp_send_json_success(['url'=>wp_login_url($return_url)]);
    }
    if (function_exists('ckmqp_after_payment_remember_cart')) {
        ckmqp_after_payment_remember_cart($uid, (array)$cart['format_keys']);
    }
    // When an administrator buys while previewing an organizer, remember the
    // represented organizer on the browser user's account as well. A payment
    // provider may return to /wp-admin/ under the administrator session, so a
    // marker stored only on the represented organizer cannot redirect that
    // browser out of the WordPress console.
    if ($preview_uid > 0 && function_exists('ckmqp_after_payment_remember_preview')) {
        ckmqp_after_payment_remember_preview(get_current_user_id(), $preview_uid, (array)$cart['format_keys']);
    }
    // A bundle requires a gateway capable of charging the entire server-priced cart.
    // Never fall back to paying only the first game in a multi-game order.
    if (count($cart['format_keys']) === 1) {
        $url = apply_filters('ckm_create_game_payment_url', '', $cart['format_keys'][0], $uid);
    } else {
        $url = apply_filters('ckm_create_cart_payment_url', '', $cart, $uid);
        if (!$url) wp_send_json_error(['message'=>'Оплата нескольких игр пока не подключена. Обратитесь к администратору сайта.'], 503);
    }
    if (is_wp_error($url)) wp_send_json_error(['message'=>$url->get_error_message()], 503);
    if (!is_string($url) || !$url) wp_send_json_error(array('message'=>'Платёжный шлюз не подключён. Обратитесь к администратору сайта.'), 503);
    $url = esc_url_raw($url, array('https', 'http'));
    if (!$url || !wp_parse_url($url, PHP_URL_HOST)) wp_send_json_error(array('message'=>'Не удалось получить ссылку оплаты.'), 502);
    wp_send_json_success(array('url'=>$url));
}

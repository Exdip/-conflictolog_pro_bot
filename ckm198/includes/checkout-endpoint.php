<?php
if (!defined('ABSPATH')) exit;

// Compatibility redirects only: /ckm-payment/ is a real theme-rendered page.
add_action('template_redirect', function () {
    $path = untrailingslashit((string) wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $legacy = array(
        untrailingslashit((string) wp_parse_url(home_url('/ckm-checkout/'), PHP_URL_PATH)),
        untrailingslashit((string) wp_parse_url(home_url('/ckm/checkout/'), PHP_URL_PATH))
    );
    if (!in_array($path, $legacy, true) && empty($_GET['ckm_checkout'])) return;
    $url = ckm_quiz_pro_checkout_url();
    if (isset($_GET['game']) && is_string($_GET['game'])) $url = add_query_arg('game', sanitize_key(wp_unslash($_GET['game'])), $url);
    wp_safe_redirect($url, 302);
    exit;
}, 1);

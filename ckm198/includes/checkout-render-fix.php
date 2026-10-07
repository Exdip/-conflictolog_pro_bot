<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_checkout_url() {
    $id = (int) get_option('ckm_quiz_pro_checkout_page_id', 0);
    return $id && get_post_status($id) === 'publish' ? get_permalink($id) : home_url('/ckm-payment/');
}

function ckm_quiz_checkout_render_fix() {
    $game = isset($_GET['game']) && is_string($_GET['game']) ? sanitize_key(wp_unslash($_GET['game'])) : '';
    $products = ckm_quiz_pro_game_access_products();
    $icons = array('classic_quiz'=>'🧠', 'chgk_v1'=>'⚡', 'jeopardy_v1'=>'🏆');
    ob_start();
    echo '<div class="ckm-checkout">';
    if (!isset($products[$game])) {
        echo '<div class="ckm-checkout__catalog">';
        foreach ($products as $key => $product) {
            echo '<section class="ckm-checkout__card">';
            echo '<h2 class="ckm-checkout__title"><span aria-hidden="true">'.esc_html($icons[$key] ?? '').'</span> '.esc_html($product['title']).'</h2>';
            echo '<p class="ckm-checkout__price">'.esc_html($product['price']).' ₽ / 30 дней</p>';
            echo '<form class="ckm-checkout__select" method="get" action="'.esc_url(ckm_quiz_pro_checkout_url()).'">';
            // Keep plain WordPress permalinks working as well as pretty URLs.
            if (!get_option('permalink_structure')) echo '<input type="hidden" name="page_id" value="'.esc_attr(get_option('ckm_quiz_pro_checkout_page_id')).'">';
            echo '<input type="hidden" name="game" value="'.esc_attr($key).'">';
            echo '<button class="ckm-checkout__button" type="submit" aria-label="'.esc_attr('Выбрать: '.$product['title']).'">Выбрать</button></form></section>';
        }
        echo '</div>';
    } else {
        $product = $products[$game];
        echo '<section class="ckm-checkout__card">';
        echo '<h2 class="ckm-checkout__title"><span aria-hidden="true">'.esc_html($icons[$game] ?? '').'</span> '.esc_html($product['title']).'</h2>';
        echo '<p class="ckm-checkout__details">Доступ:<br>30 дней</p>';
        echo '<p class="ckm-checkout__price">Стоимость:<br>'.esc_html($product['price']).' ₽</p>';
        echo '<button type="button" class="ckm-checkout__button" data-ckm-payment-game="'.esc_attr($game).'">Перейти к оплате</button>';
        echo '<p class="ckm-checkout__status" role="status" aria-live="polite" hidden></p>';
        echo '<noscript><p>Для перехода к оплате включите JavaScript в браузере.</p></noscript></section>';
    }
    echo '</div>';
    return ob_get_clean();
}
add_action('init', function () {
    add_shortcode('ckm_quiz_checkout', 'ckm_quiz_checkout_render_fix');
}, 20);

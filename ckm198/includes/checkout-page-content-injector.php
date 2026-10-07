<?php
if (!defined('ABSPATH')) exit;

// Runs once on upgrade, without rewriting arbitrary pages on every request.
function ckm_quiz_pro_ensure_checkout_content() {
    if (get_option('ckm_quiz_pro_native_checkout_version') === '0.3.23.10') return;
    $page = get_page_by_path('ckm-payment', OBJECT, 'page');
    $data = array('post_title'=>'Оплата игр', 'post_content'=>'[ckm_quiz_checkout]', 'post_status'=>'publish', 'post_type'=>'page');
    if ($page) {
        if (!metadata_exists('post', $page->ID, '_ckm_checkout_before_native')) {
            update_post_meta($page->ID, '_ckm_checkout_before_native', array('content'=>$page->post_content, 'title'=>$page->post_title, 'template'=>get_post_meta($page->ID, '_wp_page_template', true)));
        }
        $data['ID'] = $page->ID;
        $id = wp_update_post($data, true);
    } else {
        $data['post_name'] = 'ckm-payment';
        $id = wp_insert_post($data, true);
    }
    if (is_wp_error($id) || !$id) return;
    update_post_meta($id, '_wp_page_template', 'default');
    update_post_meta($id, '_ckm_checkout_page', '1');
    update_option('ckm_quiz_pro_checkout_page_id', $id, false);
    update_option('ckm_quiz_pro_native_checkout_version', '0.3.23.10', false);
    // Discard the old custom endpoint rule after all plugins register their rules.
    add_action('wp_loaded', function () { flush_rewrite_rules(false); });
}
add_action('init', 'ckm_quiz_pro_ensure_checkout_content', 30);

<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_game_store_page(){
    ob_start();
    echo '<style>
:root{color-scheme:dark;--bg:#07101d;--panel:#0c1829;--card:#10213a;--line:#243b5c;--text:#eef5ff;--muted:#9eb0cb;--accent:#7cb4ff}
.ckm-qp-shell{max-width:1280px;margin:32px auto;padding:24px;color:var(--text);font-family:Arial,sans-serif}
.ckm-qp-shell *{box-sizing:border-box}.ckm-qp-shell h1,.ckm-qp-shell h2{color:var(--text)}
.ckm-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:24px}.ckm-head h1{font-size:clamp(32px,4vw,48px);margin:6px 0 8px}.ckm-kicker{font-size:12px;letter-spacing:.14em;color:var(--muted);font-weight:800}.ckm-muted{color:var(--muted);line-height:1.55}.ckm-card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px}.ckm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:16px}.ckm-badge{display:inline-block;padding:5px 9px;border:1px solid var(--line);border-radius:999px;font-size:12px;color:var(--muted)}.ckm-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border:1px solid #355273;border-radius:10px;background:#12243d;color:#fff;text-decoration:none}.ckm-btn-primary{background:#dceaff;color:#07101d;border-color:#dceaff;font-weight:800}.ckm-card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
'.ckm_quiz_pro_games_catalog_inline_css().'
</style>';
    echo '<div class="ckm-qp-shell">';
    ckm_quiz_pro_render_games_catalog(false);
    echo '</div>';
    return (string)ob_get_clean();
}
function ckm_quiz_pro_game_checkout_page(){
    return ckm_quiz_checkout_render_fix();
}

function ckm_quiz_pro_checkout_handler(){
    if(empty($_POST['ckm_start_payment'])) return;
    if(empty($_POST['ckm_game_checkout_nonce']) || !wp_verify_nonce($_POST['ckm_game_checkout_nonce'],'ckm_game_checkout')) return;
    $game=sanitize_key($_POST['ckm_game']??'');
    do_action('ckm_game_payment_start',$game,get_current_user_id());
}
add_action('init','ckm_quiz_pro_checkout_handler',5);

function ckm_quiz_pro_register_game_store_shortcode(){
    add_shortcode('ckm_quiz_store','ckm_quiz_pro_game_store_page');
    add_shortcode('ckm_quiz_checkout','ckm_quiz_pro_game_checkout_page');
}
add_action('init','ckm_quiz_pro_register_game_store_shortcode');

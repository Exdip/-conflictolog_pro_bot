<?php
if (!defined('ABSPATH')) exit;
function ckmqp_return_order_id($value): string {
    return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$value) ? strtolower($value):'';
}

/**
 * Resolve only the tenant needed to route a payment return back to its subdomain.
 * The order UUID is not used to grant access or expose order details here.
 */
function ckmqp_return_order_tenant_id(string $id): int {
    $id=ckmqp_return_order_id($id);
    if ($id==='' || !function_exists('ckmqp_test_table')) return 0;
    global $wpdb;
    $table=ckmqp_test_table('orders');
    if ($table==='' || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) return 0;
    return max(0,(int)$wpdb->get_var($wpdb->prepare("SELECT tenant_id FROM `$table` WHERE order_id=%s LIMIT 1",$id)));
}

/**
 * YooKassa/test-server may return to the primary host. Move the browser to the
 * tenant host before authentication checks, so the tenant's login cookie and
 * tenant scope are used for the rest of the payment confirmation.
 */
function ckmqp_payment_return_route_to_tenant(): void {
    if (is_admin() || ckmqp_scope_id()!==0) return;
    $id=ckmqp_return_order_id($_GET['test_order']??null);
    if ($id==='') return;
    $tenant=ckmqp_return_order_tenant_id($id);
    if ($tenant<=0) return;
    $base=ckmqp_tenant_link(ckm_quiz_pro_organizer_base_url(),$tenant);
    if ($base==='') return;
    $target=add_query_arg(['view'=>'payment','test_order'=>$id],$base);
    // ckmqp_tenant_link already validates that this is a registered first-level
    // subdomain of the platform, so a normal redirect is intentional here.
    wp_redirect($target,302,'CKM Quiz Pro');
    exit;
}
add_action('template_redirect','ckmqp_payment_return_route_to_tenant',1);

/** The only cross-scope read of order data: authenticated buyer's own order. */
function ckmqp_return_order(string $id,int $uid): ?array {
    $id=ckmqp_return_order_id($id);
    if ($id==='' || $uid<=0 || !ckmqp_scope_ready() || ckmqp_scope_id()<0) return null;
    global $wpdb;
    $order=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckmqp_test_table('orders').' WHERE order_id=%s AND user_id=%d',$id,$uid),ARRAY_A);
    if (!$order) return null;
    $tenant=(int)$order['tenant_id'];$current=ckmqp_scope_id();
    if ($tenant<0 || ($current>0 && $tenant!==$current)) return null;
    if ($tenant>0 && !ckmqp_tenant_is_member($tenant,$uid)) return null;
    return $order;
}

function ckmqp_return_order_format_keys(array $order): array {
    $raw=json_decode((string)($order['cart_json']??''),true);
    if (!is_array($raw)) return [];
    $products=ckm_quiz_pro_game_access_products();$keys=[];
    foreach ($raw as $value) {
        if (!is_string($value)) continue;
        $key=ckm_quiz_pro_access_format_key($value);
        if ($key!=='' && isset($products[$key])) $keys[$key]=true;
    }
    return array_keys($keys);
}

/** URL used while the payment still needs confirmation/synchronisation. */
function ckmqp_return_check_url(array $order): string {
    $tenant=(int)($order['tenant_id']??-1);
    $id=ckmqp_return_order_id($order['order_id']??null);
    $base=$tenant===0 ? ckm_quiz_pro_organizer_base_url() : ckmqp_tenant_link(ckm_quiz_pro_organizer_base_url(),$tenant);
    if ($base==='') return home_url('/');
    $args=['view'=>'payment'];
    if ($id!=='') $args['test_order']=$id;
    return add_query_arg($args,$base);
}

/**
 * Successful payment returns to the purchased game on the buyer's tenant.
 * One purchased format opens its game library directly; a multi-format cart
 * opens the tenant library where all newly unlocked formats are visible.
 */
function ckmqp_return_destination(array $order): string {
    $tenant=(int)($order['tenant_id']??-1);
    $base=$tenant===0 ? ckm_quiz_pro_organizer_base_url() : ckmqp_tenant_link(ckm_quiz_pro_organizer_base_url(),$tenant);
    if ($base==='') return home_url('/ckm-sites/');
    $keys=ckmqp_return_order_format_keys($order);
    $args=['view'=>'library'];
    if (count($keys)===1) $args['format']=$keys[0];
    return add_query_arg($args,$base);
}


/**
 * Remember where the buyer must land after returning from a payment provider.
 * Some gateways are configured outside this plugin and may send the browser to
 * /wp-admin/. In that case we still route the organizer back to the tenant
 * cabinet and to the purchased games, not to the WordPress dashboard.
 */
function ckmqp_after_payment_remember_cart(int $uid, array $format_keys, ?int $tenant_id = null): void {
    if ($uid <= 0) return;
    $products = ckm_quiz_pro_game_access_products();
    $keys = [];
    foreach ($format_keys as $key) {
        if (!is_string($key)) continue;
        $key = ckm_quiz_pro_access_format_key($key);
        if ($key !== '' && isset($products[$key])) $keys[$key] = true;
    }
    if (!$keys) return;
    if ($tenant_id === null) {
        $tenant_id = function_exists('ckm_quiz_pro_access_tenant_id') ? ckm_quiz_pro_access_tenant_id($uid, null) : ckmqp_scope_id();
    }
    $tenant_id = (int)$tenant_id;
    if ($tenant_id < 0) return;
    if ($tenant_id > 0 && (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenant_id, $uid))) return;
    update_user_meta($uid, 'ckmqp_after_payment_redirect', [
        'tenant_id' => $tenant_id,
        'format_keys' => array_values(array_keys($keys)),
        'expires' => time() + DAY_IN_SECONDS,
    ]);
}

/**
 * Remember a payment started by a WordPress administrator while previewing a
 * customer organizer. The provider returns under the administrator session,
 * whereas the purchased entitlement belongs to the represented organizer.
 */
function ckmqp_after_payment_remember_preview(int $browser_uid, int $preview_uid, array $format_keys, ?int $tenant_id = null): void {
    if ($browser_uid <= 0 || $preview_uid <= 0 || $browser_uid === $preview_uid) return;
    $browser = get_user_by('id', $browser_uid);
    $preview = get_user_by('id', $preview_uid);
    if (!$browser || !$preview || !user_can($browser, 'manage_options')) return;
    if (!defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE, (array)$preview->roles, true)) return;
    $products = ckm_quiz_pro_game_access_products();
    $keys = [];
    foreach ($format_keys as $key) {
        if (!is_string($key)) continue;
        $key = ckm_quiz_pro_access_format_key($key);
        if ($key !== '' && isset($products[$key])) $keys[$key] = true;
    }
    if (!$keys) return;
    if ($tenant_id === null) {
        $tenant_id = function_exists('ckm_quiz_pro_access_tenant_id') ? ckm_quiz_pro_access_tenant_id($preview_uid, null) : ckmqp_scope_id();
    }
    $tenant_id = (int)$tenant_id;
    if ($tenant_id < 0) return;
    update_user_meta($browser_uid, 'ckmqp_after_payment_preview_redirect', [
        'preview_user_id' => $preview_uid,
        'tenant_id' => $tenant_id,
        'format_keys' => array_values(array_keys($keys)),
        'expires' => time() + DAY_IN_SECONDS,
    ]);
}

function ckmqp_after_payment_preview_destination_from_memory(int $browser_uid): string {
    $memory = get_user_meta($browser_uid, 'ckmqp_after_payment_preview_redirect', true);
    if (!is_array($memory)) return '';
    if ((int)($memory['expires'] ?? 0) < time()) {
        delete_user_meta($browser_uid, 'ckmqp_after_payment_preview_redirect');
        return '';
    }
    $preview_uid = (int)($memory['preview_user_id'] ?? 0);
    $tenant_id = (int)($memory['tenant_id'] ?? -1);
    $browser = get_user_by('id', $browser_uid);
    $preview = get_user_by('id', $preview_uid);
    if (!$browser || !$preview || !user_can($browser, 'manage_options') || $tenant_id < 0) return '';
    if (!defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE, (array)$preview->roles, true)) return '';
    $keys = is_array($memory['format_keys'] ?? null) ? $memory['format_keys'] : [];
    $products = ckm_quiz_pro_game_access_products();
    $valid = [];
    foreach ($keys as $key) {
        if (!is_string($key)) continue;
        $key = ckm_quiz_pro_access_format_key($key);
        if ($key !== '' && isset($products[$key])) $valid[$key] = true;
    }
    $keys = array_keys($valid);
    if (!$keys) return '';
    $base = ckmqp_after_payment_base_for_tenant($tenant_id);
    if ($base === '') return '';
    $active = [];
    foreach ($keys as $key) if (ckm_quiz_pro_can_access_format($preview_uid, $key, $tenant_id)) $active[] = $key;
    $args = $active ? ['view'=>'library','paid'=>1] : ['view'=>'payment','payment_return'=>1,'games'=>implode(',', $keys)];
    if (count($active) === 1) $args['format'] = $active[0];
    $args['ckm_preview_user'] = $preview_uid;
    $args['ckm_preview_nonce'] = wp_create_nonce('ckm_qp_preview_organizer_'.$preview_uid);
    return add_query_arg($args, $base);
}

function ckmqp_after_payment_base_for_tenant(int $tenant_id): string {
    if ($tenant_id > 0 && function_exists('ckmqp_tenant_link')) {
        $base = ckmqp_tenant_link(ckm_quiz_pro_organizer_base_url(), $tenant_id);
        if ($base !== '') return $base;
    }
    return ckm_quiz_pro_organizer_base_url();
}

function ckmqp_after_payment_destination_from_keys(int $uid, array $format_keys, int $tenant_id): string {
    $products = ckm_quiz_pro_game_access_products();
    $keys = [];
    foreach ($format_keys as $key) {
        if (!is_string($key)) continue;
        $key = ckm_quiz_pro_access_format_key($key);
        if ($key !== '' && isset($products[$key])) $keys[$key] = true;
    }
    $keys = array_values(array_keys($keys));
    $base = ckmqp_after_payment_base_for_tenant($tenant_id);
    if ($base === '') return home_url('/ckm-organizer/');

    $active = [];
    foreach ($keys as $key) {
        if (ckm_quiz_pro_can_access_format($uid, $key, $tenant_id)) $active[] = $key;
    }
    if ($active) {
        $args = ['view' => 'library', 'paid' => 1];
        if (count($active) === 1) $args['format'] = $active[0];
        return add_query_arg($args, $base);
    }

    // If the provider returned before the webhook/access write finished, keep
    // the organizer in the payment flow instead of dropping them into wp-admin.
    $args = ['view' => 'payment', 'payment_return' => 1];
    if ($keys) $args['games'] = implode(',', $keys);
    return add_query_arg($args, $base);
}

function ckmqp_after_payment_destination_from_memory(int $uid): string {
    $memory = get_user_meta($uid, 'ckmqp_after_payment_redirect', true);
    if (!is_array($memory)) return '';
    if ((int)($memory['expires'] ?? 0) < time()) {
        delete_user_meta($uid, 'ckmqp_after_payment_redirect');
        return '';
    }
    $tenant_id = (int)($memory['tenant_id'] ?? -1);
    if ($tenant_id < 0) return '';
    if ($tenant_id > 0 && (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenant_id, $uid))) return '';
    $keys = is_array($memory['format_keys'] ?? null) ? $memory['format_keys'] : [];
    return ckmqp_after_payment_destination_from_keys($uid, $keys, $tenant_id);
}

function ckmqp_after_payment_admin_redirect(): void {
    if (!is_admin() || !is_user_logged_in()) return;
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) return;
    if (function_exists('ckmqp_after_payment_admin_is_dashboard') && !ckmqp_after_payment_admin_is_dashboard()) return;
    $uid = get_current_user_id();

    // 0.3.23.104: do not infer a redirect from any recent order on every
    // /wp-admin/ visit. That fallback created redirect loops when the payment
    // provider repeatedly returned to the WordPress dashboard. Only an explicit
    // one-shot server memory may redirect here; the ordinary checkout path uses
    // the browser localStorage fallback below.
    $target = ckmqp_after_payment_destination_from_memory($uid);
    $source = 'memory';

    // Administrator preview payment: the order/access belongs to the represented
    // organizer, but the provider returns under the administrator's WP session.
    if ($target === '') {
        $target = ckmqp_after_payment_preview_destination_from_memory($uid);
        $source = 'preview_memory';
    }

    // Final server-side recovery for the buyer's own test order. This function
    // already existed but was not wired into admin_init, so a successful return
    // with a missing one-shot memory could remain on /wp-admin/.
    if ($target === '') {
        $target = ckmqp_after_payment_recent_order_destination($uid);
        $source = 'recent_order';
    }
    if ($target === '') return;

    if ($source === 'memory') delete_user_meta($uid, 'ckmqp_after_payment_redirect');
    if ($source === 'preview_memory') delete_user_meta($uid, 'ckmqp_after_payment_preview_redirect');
    if ($source === 'recent_order' && (($GLOBALS['ckmqp_after_payment_recent_order_status'] ?? '') === 'succeeded')) {
        ckmqp_after_payment_mark_order_consumed($uid, (string)($GLOBALS['ckmqp_after_payment_recent_order_id'] ?? ''));
    }
    update_user_meta($uid, 'ckmqp_after_payment_redirect_used_at', time());
    wp_redirect($target, 302, 'CKM Quiz Pro');
    exit;
}
add_action('admin_init', 'ckmqp_after_payment_admin_redirect', 0);

/**
 * Browser-side safety net for payment providers configured to return to /wp-admin/.
 * The checkout button stores the desired organizer return URL in localStorage
 * before leaving ckkm.ru. If PHP has no usable order/memory context on return,
 * this script still moves the organizer out of the WordPress console.
 */
function ckmqp_after_payment_admin_localstorage_fallback(): void {
    if (!is_admin() || !is_user_logged_in()) return;
    if (!function_exists('ckmqp_after_payment_admin_is_dashboard') || !ckmqp_after_payment_admin_is_dashboard()) return;
    $organizer = esc_url_raw(ckm_quiz_pro_organizer_base_url());
    ?>
    <script>
    (function(){
        try {
            var key = 'ckmqpPostpayReturn';
            var lockKey = 'ckmqpPostpayReturnLock';
            var raw = window.localStorage && window.localStorage.getItem(key);
            if (!raw) return;

            var now = Date.now();
            var lock = 0;
            try { lock = Number(window.sessionStorage && window.sessionStorage.getItem(lockKey) || 0); } catch (e) {}
            if (lock && now - lock < 15000) {
                // We already tried to leave wp-admin during this payment return.
                // Stop instead of producing ERR_TOO_MANY_REDIRECTS.
                window.localStorage.removeItem(key);
                return;
            }

            var data = JSON.parse(raw);
            var expires = Number(data && data.expires || 0);
            if (!expires || now > expires) { window.localStorage.removeItem(key); return; }

            // Safe landing first: the payment screen can show the newly paid games
            // and never requires a second automatic redirect.
            var target = String(data.fallbackUrl || data.url || '');
            if (!target) return;
            var url = new URL(target, window.location.origin);
            if (url.origin !== window.location.origin) { window.localStorage.removeItem(key); return; }
            if (url.pathname.indexOf('/wp-admin') === 0) { window.localStorage.removeItem(key); return; }
            if (url.pathname.indexOf('/ckm-organizer') !== 0) {
                url = new URL(<?php echo wp_json_encode($organizer); ?>, window.location.origin);
                url.searchParams.set('view', 'payment');
                url.searchParams.set('payment_return', '1');
            }
            url.searchParams.set('ckm_postpay_once', '1');
            try { window.sessionStorage && window.sessionStorage.setItem(lockKey, String(now)); } catch (e) {}
            window.localStorage.removeItem(key);
            window.location.replace(url.href);
        } catch (e) {
            try { window.localStorage && window.localStorage.removeItem('ckmqpPostpayReturn'); } catch (ignore) {}
        }
    })();
    </script>
    <?php
}
add_action('admin_footer-index.php', 'ckmqp_after_payment_admin_localstorage_fallback', 1);


function ckmqp_after_payment_admin_is_dashboard(): bool {
    if (!is_admin()) return false;
    $page = isset($_GET['page']) ? (string)$_GET['page'] : '';
    if ($page !== '') return false;
    $pagenow = isset($GLOBALS['pagenow']) ? (string)$GLOBALS['pagenow'] : '';
    if ($pagenow === 'index.php') return true;
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    $self = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    foreach ([$script, $self, parse_url($uri, PHP_URL_PATH) ?: ''] as $path) {
        if ($path === '' || preg_match('~/wp-admin/(index\.php)?$~', $path) === 1) return true;
    }
    return false;
}

function ckmqp_after_payment_consumed_order_ids(int $uid): array {
    $raw = get_user_meta($uid, 'ckmqp_after_payment_consumed_orders', true);
    if (!is_array($raw)) return [];
    $ids = [];
    foreach ($raw as $id) {
        $id = ckmqp_return_order_id($id);
        if ($id !== '') $ids[$id] = true;
    }
    return array_keys($ids);
}

function ckmqp_after_payment_mark_order_consumed(int $uid, string $order_id): void {
    $order_id = ckmqp_return_order_id($order_id);
    if ($uid <= 0 || $order_id === '') return;
    $ids = ckmqp_after_payment_consumed_order_ids($uid);
    array_unshift($ids, $order_id);
    $ids = array_slice(array_values(array_unique($ids)), 0, 20);
    update_user_meta($uid, 'ckmqp_after_payment_consumed_orders', $ids);
}

/**
 * Fallback for gateways that return the browser to /wp-admin/ without our
 * original return parameters. Use the buyer's own recent order as the source of
 * truth: pending orders go to the synchronisation screen; paid orders go to the
 * purchased game library.
 */
function ckmqp_after_payment_recent_order_destination(int $uid): string {
    if ($uid <= 0 || !function_exists('ckmqp_test_enabled') || !ckmqp_test_enabled() || !function_exists('ckmqp_test_table')) return '';
    global $wpdb;
    $table = ckmqp_test_table('orders');
    if ($table === '' || $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return '';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM `$table`
         WHERE user_id=%d
           AND status IN ('created','pending','waiting_for_capture','succeeded')
           AND created_at > UTC_TIMESTAMP() - INTERVAL 24 HOUR
         ORDER BY COALESCE(paid_at, created_at) DESC
         LIMIT 8",
        $uid
    ), ARRAY_A) ?: [];
    if (!$rows) return '';

    $consumed = array_flip(ckmqp_after_payment_consumed_order_ids($uid));
    foreach ($rows as $order) {
        $id = ckmqp_return_order_id($order['order_id'] ?? null);
        if ($id === '') continue;
        $tenant = (int)($order['tenant_id'] ?? -1);
        if ($tenant < 0) continue;
        if ($tenant > 0 && (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenant, $uid))) continue;
        $status = (string)($order['status'] ?? '');
        if ($status === 'succeeded' && isset($consumed[$id])) continue;
        $GLOBALS['ckmqp_after_payment_recent_order_id'] = $id;
        $GLOBALS['ckmqp_after_payment_recent_order_status'] = $status;
        if ($status === 'succeeded') return ckmqp_return_destination($order);
        return ckmqp_return_check_url($order);
    }
    return '';
}


function ckmqp_return_login_args(): array {
    $id=ckmqp_return_order_id($_GET['test_order']??null);
    return $id!==''?['test_order'=>$id]:[];
}
/** Read-only purchase summary; does not switch the current tenant or grant access. */
function ckmqp_return_paid_titles(int $tenant,int $uid): array {
    if ($tenant<=0 || !ckmqp_scope_ready() || !ckmqp_tenant_is_member($tenant,$uid)) return [];
    global $wpdb;
    $keys=$wpdb->get_col($wpdb->prepare('SELECT DISTINCT format_key FROM '.ckm_quiz_pro_table('game_access')." WHERE tenant_id=%d AND status='active' AND expires_at>UTC_TIMESTAMP()",$tenant))?:[];
    if (ckmqp_test_enabled()) $keys=array_merge($keys,$wpdb->get_col($wpdb->prepare('SELECT DISTINCT format_key FROM '.ckmqp_test_table('access').' WHERE tenant_id=%d AND expires_at>UTC_TIMESTAMP()',$tenant))?:[]);
    $products=ckm_quiz_pro_game_access_products();$titles=[];
    foreach (array_unique($keys) as $key) if (isset($products[$key])) $titles[]=$products[$key]['title'];
    return $titles;
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Platform-owner YooKassa checkout for CKM partner plans.
 * This is deliberately separate from tenant-owned YooKassa in payments.php.
 */

function ckm_quiz_pro_partner_plan_orders_table(): string {
    return ckm_quiz_pro_partner_table('plan_orders');
}

function ckm_quiz_pro_partner_platform_yk_option_name(): string {
    return 'ckmqp_platform_yookassa_settings';
}

function ckm_quiz_pro_partner_platform_yk_crypto_key(): string {
    return hash('sha256', wp_salt('auth') . '|ckm-platform-yookassa-v1', true);
}

function ckm_quiz_pro_partner_platform_yk_encrypt(string $plain): string|WP_Error {
    if ($plain === '' || !function_exists('openssl_encrypt')) return new WP_Error('crypto', 'На сервере недоступно безопасное шифрование Secret key.');
    try { $iv = random_bytes(12); }
    catch (Throwable $e) { return new WP_Error('crypto', 'Не удалось создать ключ шифрования.'); }
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ckm_quiz_pro_partner_platform_yk_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-platform-yookassa', 16);
    if (!is_string($cipher) || strlen($tag) !== 16) return new WP_Error('crypto', 'Не удалось зашифровать Secret key.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function ckm_quiz_pro_partner_platform_yk_decrypt(string $stored): string|WP_Error {
    if ($stored === '') return '';
    if (!str_starts_with($stored, 'v1:') || !function_exists('openssl_decrypt')) return new WP_Error('crypto', 'Сохранённый Secret key имеет неподдерживаемый формат.');
    $raw = base64_decode(substr($stored, 3), true);
    if (!is_string($raw) || strlen($raw) <= 28) return new WP_Error('crypto', 'Не удалось прочитать сохранённый Secret key.');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', ckm_quiz_pro_partner_platform_yk_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-platform-yookassa');
    if (!is_string($plain) || $plain === '') return new WP_Error('crypto', 'Не удалось расшифровать Secret key. Введите его заново.');
    return $plain;
}

function ckm_quiz_pro_partner_platform_yk_settings(): array {
    $saved = get_option(ckm_quiz_pro_partner_platform_yk_option_name(), []);
    if (!is_array($saved)) $saved = [];
    return array_merge([
        'shop_id' => '',
        'secret_cipher' => '',
        'secret_last4' => '',
        'mode' => 'test',
        'vat_code' => 1,
        'enabled' => 0,
        'webhook_token' => '',
        'account_test' => 0,
        'account_status' => '',
        'fiscalization_enabled' => 0,
        'last_checked_at' => '',
    ], $saved);
}

function ckm_quiz_pro_partner_platform_yk_ready(): bool {
    $s = ckm_quiz_pro_partner_platform_yk_settings();
    return (int)$s['enabled'] === 1 && trim((string)$s['shop_id']) !== '' && trim((string)$s['secret_cipher']) !== '';
}

function ckm_quiz_pro_partner_platform_yk_webhook_url(?array $settings = null): string {
    $settings = $settings ?? ckm_quiz_pro_partner_platform_yk_settings();
    $token = sanitize_text_field((string)($settings['webhook_token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return '';
    return rest_url('ckm-partner/v1/platform-yookassa/' . $token);
}

function ckm_quiz_pro_partner_platform_yk_save_settings(array $input): array|WP_Error {
    if (!current_user_can('manage_options')) return new WP_Error('forbidden', 'Недостаточно прав для настройки YooKassa владельца ЦКМ.');
    $old = ckm_quiz_pro_partner_platform_yk_settings();
    $shopId = preg_replace('/\D+/', '', (string)($input['shop_id'] ?? ''));
    if ($shopId === '' || strlen($shopId) < 4 || strlen($shopId) > 32) return new WP_Error('shop_id', 'Укажите корректный Shop ID YooKassa.');
    $mode = sanitize_key((string)($input['mode'] ?? 'test'));
    if (!in_array($mode, ['test', 'live'], true)) $mode = 'test';
    $vat = (int)($input['vat_code'] ?? 1);
    $vatLabels = function_exists('ckm_quiz_pro_partner_yk_vat_labels') ? ckm_quiz_pro_partner_yk_vat_labels() : [1=>'Без НДС'];
    if (!isset($vatLabels[$vat])) $vat = 1;
    $enabled = !empty($input['enabled']) ? 1 : 0;
    $secretCipher = (string)$old['secret_cipher'];
    $secretLast4 = (string)$old['secret_last4'];
    $secret = trim((string)($input['secret_key'] ?? ''));
    if (!empty($input['remove_secret'])) {
        $secretCipher = '';
        $secretLast4 = '';
        $enabled = 0;
    } elseif ($secret !== '') {
        if (strlen($secret) < 8 || strlen($secret) > 255) return new WP_Error('secret', 'Secret key выглядит некорректно.');
        $enc = ckm_quiz_pro_partner_platform_yk_encrypt($secret);
        if (is_wp_error($enc)) return $enc;
        $secretCipher = $enc;
        $secretLast4 = substr($secret, -4);
    }
    if ($secretCipher === '') $enabled = 0;
    $token = (string)$old['webhook_token'];
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        try { $token = bin2hex(random_bytes(32)); }
        catch (Throwable $e) { return new WP_Error('random', 'Не удалось создать защищённый webhook URL.'); }
    }
    $new = $old;
    $new['shop_id'] = $shopId;
    $new['secret_cipher'] = $secretCipher;
    $new['secret_last4'] = $secretLast4;
    $new['mode'] = $mode;
    $new['vat_code'] = $vat;
    $new['enabled'] = $enabled;
    $new['webhook_token'] = $token;
    $new['updated_at'] = gmdate('Y-m-d H:i:s');
    update_option(ckm_quiz_pro_partner_platform_yk_option_name(), $new, false);
    return ckm_quiz_pro_partner_platform_yk_settings();
}

function ckm_quiz_pro_partner_platform_yk_api(string $method, string $path, ?array $body = null, string $idempotenceKey = ''): array|WP_Error {
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    $secret = ckm_quiz_pro_partner_platform_yk_decrypt((string)$settings['secret_cipher']);
    if (is_wp_error($secret)) return $secret;
    $shopId = trim((string)$settings['shop_id']);
    if ($shopId === '' || $secret === '') return new WP_Error('settings', 'Сначала укажите Shop ID и Secret key YooKassa владельца ЦКМ.');
    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'], true)) return new WP_Error('method', 'Неподдерживаемый метод YooKassa API.');
    $path = ltrim($path, '/');
    if ($path === '' || preg_match('#[^a-zA-Z0-9_/?=&.\-]#', $path)) return new WP_Error('path', 'Некорректный путь YooKassa API.');
    $headers = [
        'Authorization' => 'Basic ' . base64_encode($shopId . ':' . $secret),
        'Accept' => 'application/json',
    ];
    $args = ['method'=>$method, 'headers'=>$headers, 'timeout'=>25, 'redirection'=>0];
    if ($method === 'POST') {
        if ($idempotenceKey === '') $idempotenceKey = wp_generate_uuid4();
        $headers['Idempotence-Key'] = substr($idempotenceKey, 0, 64);
        $headers['Content-Type'] = 'application/json';
        $args['headers'] = $headers;
        $args['body'] = wp_json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $response = wp_remote_request('https://api.yookassa.ru/v3/' . $path, $args);
    if (is_wp_error($response)) return new WP_Error('network', 'YooKassa не ответила. Повторите попытку позже.');
    $code = (int)wp_remote_retrieve_response_code($response);
    $json = json_decode((string)wp_remote_retrieve_body($response), true);
    if ($code < 200 || $code >= 300 || !is_array($json)) {
        $message = is_array($json) ? sanitize_text_field((string)($json['description'] ?? $json['code'] ?? '')) : '';
        return new WP_Error('yookassa', 'YooKassa отклонила запрос (HTTP ' . $code . ')' . ($message !== '' ? ': ' . $message : '.'));
    }
    return $json;
}

function ckm_quiz_pro_partner_platform_yk_test_connection(): array|WP_Error {
    $me = ckm_quiz_pro_partner_platform_yk_api('GET', 'me');
    if (is_wp_error($me)) return $me;
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    $expectedTest = (string)$settings['mode'] === 'test';
    $actualTest = !empty($me['test']);
    if ($expectedTest !== $actualTest) {
        return new WP_Error('mode_mismatch', $expectedTest
            ? 'Выбран тестовый режим, но YooKassa вернула боевой магазин.'
            : 'Выбран боевой режим, но YooKassa вернула тестовый магазин.');
    }
    $settings['account_test'] = $actualTest ? 1 : 0;
    $settings['account_status'] = sanitize_key((string)($me['status'] ?? ''));
    $settings['fiscalization_enabled'] = !empty($me['fiscalization_enabled']) ? 1 : 0;
    $settings['last_checked_at'] = gmdate('Y-m-d H:i:s');
    $settings['updated_at'] = gmdate('Y-m-d H:i:s');
    update_option(ckm_quiz_pro_partner_platform_yk_option_name(), $settings, false);
    return $me;
}

function ckm_quiz_pro_partner_plan_order(string $orderId): ?array {
    if ($orderId === '') return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ckm_quiz_pro_partner_plan_orders_table() . ' WHERE order_id=%s LIMIT 1', $orderId), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_pro_partner_plan_order_by_payment(string $paymentId): ?array {
    if ($paymentId === '') return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . ckm_quiz_pro_partner_plan_orders_table() . ' WHERE yookassa_payment_id=%s LIMIT 1', $paymentId), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_pro_partner_plan_create_order(int $tenantId, int $payerUserId, string $planKey): array|WP_Error {
    ckm_quiz_pro_partner_install_schema();
    if ($tenantId <= 0 || $payerUserId <= 0 || !ckm_quiz_pro_partner_user_can_activate($tenantId, $payerUserId)) return new WP_Error('forbidden', 'Партнёрский тариф может оплатить владелец площадки или подключённый организатор.');
    $ownerUserId = ckm_quiz_pro_partner_valid_owner_id($tenantId);
    if ($ownerUserId <= 0) return new WP_Error('owner', 'У площадки нет действующего владельца.');
    $plans = ckm_quiz_pro_partner_plan_settings();
    $planKey = sanitize_key($planKey);
    if (!isset($plans[$planKey])) return new WP_Error('plan', 'Неизвестный партнёрский тариф.');
    $price = (int)$plans[$planKey]['price_rub'];
    if ($price <= 0) return new WP_Error('price', 'Цена партнёрского тарифа пока не задана.');
    if (!ckm_quiz_pro_partner_platform_yk_ready()) return new WP_Error('gateway', 'Оплата партнёрского режима через YooKassa владельца ЦКМ пока не подключена.');
    $orderId = wp_generate_uuid4();
    $now = gmdate('Y-m-d H:i:s');
    $data = [
        'order_id'=>$orderId,
        'tenant_id'=>$tenantId,
        'owner_user_id'=>$ownerUserId,
        'payer_user_id'=>$payerUserId,
        'plan_key'=>$planKey,
        'session_limit'=>(int)$plans[$planKey]['limit'],
        'amount_minor'=>$price * 100,
        'currency'=>'RUB',
        'status'=>'created',
        'yookassa_payment_id'=>'',
        'confirmation_url'=>'',
        'last_error'=>'',
        'created_at'=>$now,
        'updated_at'=>$now,
    ];
    global $wpdb;
    if ($wpdb->insert(ckm_quiz_pro_partner_plan_orders_table(), $data) === false) return new WP_Error('database', 'Не удалось создать заказ партнёрского тарифа.');
    return ckm_quiz_pro_partner_plan_order($orderId) ?: $data;
}

function ckm_quiz_pro_partner_plan_return_url(array $order): string {
    $url = ckm_quiz_pro_partner_route_url((int)$order['tenant_id']);
    return add_query_arg('plan_payment_return', rawurlencode((string)$order['order_id']), $url);
}

function ckm_quiz_pro_partner_plan_create_payment(array $order): array|WP_Error {
    $me = ckm_quiz_pro_partner_platform_yk_test_connection();
    if (is_wp_error($me)) return $me;
    if (sanitize_key((string)($me['status'] ?? '')) !== 'enabled') return new WP_Error('shop_disabled', 'Магазин YooKassa владельца ЦКМ не находится в статусе enabled.');
    $payerId = (int)($order['payer_user_id'] ?? 0);
    if ($payerId <= 0) $payerId = (int)$order['owner_user_id'];
    $user = get_userdata($payerId);
    if (!$user || !is_email((string)$user->user_email)) return new WP_Error('email', 'У плательщика отсутствует корректный email для оплаты.');
    $plans = ckm_quiz_pro_partner_plan_settings();
    $label = (string)($plans[$order['plan_key']]['label'] ?? $order['plan_key']);
    $value = number_format(((int)$order['amount_minor']) / 100, 2, '.', '');
    $body = [
        'amount'=>['value'=>$value, 'currency'=>'RUB'],
        'capture'=>true,
        'confirmation'=>['type'=>'redirect', 'return_url'=>ckm_quiz_pro_partner_plan_return_url($order)],
        'description'=>mb_substr('Партнёрский режим ЦКМ ' . $label . ' · 30 дней', 0, 128),
        'metadata'=>[
            'ckm_type'=>'partner_plan',
            'order_id'=>(string)$order['order_id'],
            'tenant_id'=>(string)(int)$order['tenant_id'],
            'plan_key'=>(string)$order['plan_key'],
        ],
    ];
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    if (!empty($settings['fiscalization_enabled'])) {
        $body['receipt'] = [
            'customer'=>['email'=>(string)$user->user_email],
            'items'=>[[
                'description'=>mb_substr('Партнёрский режим ЦКМ ' . $label . ' · 30 дней', 0, 128),
                'quantity'=>'1.00',
                'amount'=>['value'=>$value, 'currency'=>'RUB'],
                'vat_code'=>(int)$settings['vat_code'],
                'payment_mode'=>'full_payment',
                'payment_subject'=>'service',
            ]],
        ];
    }
    $payment = ckm_quiz_pro_partner_platform_yk_api('POST', 'payments', $body, 'ckm-plan-' . (string)$order['order_id']);
    if (is_wp_error($payment)) return $payment;
    $paymentId = sanitize_text_field((string)($payment['id'] ?? ''));
    $confirmation = esc_url_raw((string)($payment['confirmation']['confirmation_url'] ?? ''));
    $status = sanitize_key((string)($payment['status'] ?? 'pending'));
    if ($paymentId === '') return new WP_Error('response', 'YooKassa не вернула идентификатор платежа.');
    if ($confirmation === '' && $status !== 'succeeded') return new WP_Error('response', 'YooKassa не вернула ссылку оплаты.');
    global $wpdb;
    $wpdb->update(ckm_quiz_pro_partner_plan_orders_table(), [
        'status'=>$status,
        'yookassa_payment_id'=>$paymentId,
        'confirmation_url'=>$confirmation,
        'updated_at'=>gmdate('Y-m-d H:i:s'),
    ], ['id'=>(int)$order['id']]);
    return ckm_quiz_pro_partner_plan_order((string)$order['order_id']) ?: $order;
}

function ckm_quiz_pro_partner_plan_start_checkout(int $tenantId, int $payerUserId, string $planKey): array|WP_Error {
    $order = ckm_quiz_pro_partner_plan_create_order($tenantId, $payerUserId, $planKey);
    if (is_wp_error($order)) return $order;
    $order = ckm_quiz_pro_partner_plan_create_payment($order);
    if (is_wp_error($order)) return $order;
    $request = ckm_quiz_pro_partner_save_request($tenantId, $payerUserId, $planKey);
    if (!is_wp_error($request)) {
        $request['status'] = 'awaiting_payment';
        $request['order_id'] = (string)$order['order_id'];
        update_option(ckm_quiz_pro_partner_request_option_name($tenantId), $request, false);
    }
    return $order;
}

function ckm_quiz_pro_partner_plan_validate_payment(array $order, array $payment): true|WP_Error {
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    // Partner-plan activation is a security boundary: require an explicit
    // merchant account and explicit test/live flag in the authoritative API
    // response instead of accepting a partially populated response.
    $recipient = is_array($payment['recipient'] ?? null) ? $payment['recipient'] : [];
    $recipientAccount = trim((string)($recipient['account_id'] ?? ''));
    if ($recipientAccount === '' || $recipientAccount !== (string)$settings['shop_id']) return new WP_Error('recipient', 'Платёж относится к другому или неопределённому магазину YooKassa.');
    if (!array_key_exists('test', $payment)) return new WP_Error('mode_missing', 'YooKassa не указала режим платежа. Платёж не подтверждён.');
    $expectedTest = (string)$settings['mode'] === 'test';
    if ((bool)$payment['test'] !== $expectedTest) return new WP_Error('mode', 'Режим платежа не совпадает с режимом YooKassa.');
    $amount = $payment['amount'] ?? [];
    $minor = isset($amount['value']) ? (int)round(((float)$amount['value']) * 100) : -1;
    if ($minor !== (int)$order['amount_minor'] || (string)($amount['currency'] ?? '') !== 'RUB') return new WP_Error('amount', 'Сумма или валюта платежа не совпадает с заказом.');
    $meta = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
    if ((string)($meta['ckm_type'] ?? '') !== 'partner_plan'
        || (string)($meta['order_id'] ?? '') !== (string)$order['order_id']
        || (int)($meta['tenant_id'] ?? 0) !== (int)$order['tenant_id']
        || sanitize_key((string)($meta['plan_key'] ?? '')) !== (string)$order['plan_key']) {
        return new WP_Error('metadata', 'Метаданные платежа не соответствуют заказу партнёрского тарифа.');
    }
    return true;
}

function ckm_quiz_pro_partner_plan_sync_order(string $orderId): array|WP_Error {
    $order = ckm_quiz_pro_partner_plan_order($orderId);
    if (!$order) return new WP_Error('order', 'Заказ партнёрского тарифа не найден.');
    if (!empty($order['activated_at'])) return $order;
    $paymentId = sanitize_text_field((string)$order['yookassa_payment_id']);
    if ($paymentId === '') return new WP_Error('payment', 'Платёж YooKassa ещё не создан.');
    global $wpdb;
    $lock = 'ckm_plan_' . substr(hash('sha256', $orderId), 0, 40);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) return new WP_Error('busy', 'Платёж уже обрабатывается. Повторите попытку через несколько секунд.');
    try {
        $order = ckm_quiz_pro_partner_plan_order($orderId);
        if (!$order || !empty($order['activated_at'])) return $order ?: new WP_Error('order', 'Заказ не найден.');
        $payment = ckm_quiz_pro_partner_platform_yk_api('GET', 'payments/' . rawurlencode($paymentId));
        if (is_wp_error($payment)) return $payment;
        $valid = ckm_quiz_pro_partner_plan_validate_payment($order, $payment);
        if (is_wp_error($valid)) return $valid;
        $status = sanitize_key((string)($payment['status'] ?? ''));
        $update = ['status'=>$status, 'updated_at'=>gmdate('Y-m-d H:i:s'), 'last_error'=>''];
        if ($status === 'succeeded') {
            $ref = 'partner-plan:' . (string)$order['order_id'];
            $activated = ckm_quiz_pro_partner_activate((int)$order['tenant_id'], (string)$order['plan_key'], $ref, (int)$order['amount_minor'], (int)$order['session_limit']);
            if (is_wp_error($activated)) {
                $wpdb->update(ckm_quiz_pro_partner_plan_orders_table(), ['last_error'=>$activated->get_error_message(), 'updated_at'=>gmdate('Y-m-d H:i:s')], ['id'=>(int)$order['id']]);
                return $activated;
            }
            $update['paid_at'] = gmdate('Y-m-d H:i:s');
            $update['activated_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($status === 'canceled') {
            $update['last_error'] = 'Платёж отменён.';
        }
        $wpdb->update(ckm_quiz_pro_partner_plan_orders_table(), $update, ['id'=>(int)$order['id']]);
        return ckm_quiz_pro_partner_plan_order($orderId) ?: $order;
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

function ckm_quiz_pro_partner_plan_sync_return(int $tenantId, int $userId, string $orderId): array|WP_Error {
    $order = ckm_quiz_pro_partner_plan_order($orderId);
    $payerId = $order ? (int)($order['payer_user_id'] ?? 0) : 0;
    if ($payerId <= 0 && $order) $payerId = (int)$order['owner_user_id'];
    if (!$order || (int)$order['tenant_id'] !== $tenantId || $payerId !== $userId) return new WP_Error('order', 'Этот заказ не относится к вашей площадке или вашему платежу.');
    return ckm_quiz_pro_partner_plan_sync_order($orderId);
}

function ckm_quiz_pro_partner_plan_register_routes(): void {
    register_rest_route('ckm-partner/v1', '/platform-yookassa/(?P<token>[a-f0-9]{64})', [
        'methods'=>'POST',
        'callback'=>'ckm_quiz_pro_partner_plan_webhook',
        'permission_callback'=>'__return_true',
    ]);
}

function ckm_quiz_pro_partner_plan_webhook(WP_REST_Request $request): WP_REST_Response {
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    $token = (string)$request->get_param('token');
    if (!preg_match('/^[a-f0-9]{64}$/D', (string)$settings['webhook_token']) || !hash_equals((string)$settings['webhook_token'], $token)) return new WP_REST_Response(['ok'=>false], 404);
    $payload = $request->get_json_params();
    if (!is_array($payload)) return new WP_REST_Response(['ok'=>false], 400);
    $event = sanitize_key(str_replace('.', '_', (string)($payload['event'] ?? '')));
    if (!in_array($event, ['payment_succeeded', 'payment_canceled', 'payment_waiting_for_capture'], true)) return new WP_REST_Response(['ok'=>true], 200);
    $paymentId = sanitize_text_field((string)($payload['object']['id'] ?? ''));
    if ($paymentId === '') return new WP_REST_Response(['ok'=>false], 400);
    // Never activate from webhook payload alone: re-fetch payment from YooKassa.
    $order = ckm_quiz_pro_partner_plan_order_by_payment($paymentId);
    if (!$order) return new WP_REST_Response(['ok'=>true], 200);
    $result = ckm_quiz_pro_partner_plan_sync_order((string)$order['order_id']);
    return new WP_REST_Response(['ok'=>!is_wp_error($result)], is_wp_error($result) ? 500 : 200);
}

function ckm_quiz_pro_partner_plan_recent_orders(int $tenantId, int $limit = 10): array {
    global $wpdb;
    $limit = max(1, min(50, $limit));
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . ckm_quiz_pro_partner_plan_orders_table() . ' WHERE tenant_id=%d ORDER BY id DESC LIMIT %d', $tenantId, $limit), ARRAY_A) ?: [];
}

function ckm_quiz_pro_partner_plan_render_admin_yookassa_section(): void {
    $settings = ckm_quiz_pro_partner_platform_yk_settings();
    $vatLabels = function_exists('ckm_quiz_pro_partner_yk_vat_labels') ? ckm_quiz_pro_partner_yk_vat_labels() : [1=>'Без НДС'];
    echo '<hr><h2>Оплата партнёрского режима через YooKassa владельца ЦКМ</h2>';
    echo '<p>Эта касса используется только для оплаты Start / Business / Pro владельцами площадок. YooKassa самих партнёров для расчётов с их клиентами настраивается отдельно в партнёрском кабинете.</p>';
    echo '<form method="post">';
    wp_nonce_field('ckm_quiz_pro_partner_admin');
    echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="save_platform_yookassa">';
    echo '<table class="form-table"><tr><th>Shop ID</th><td><input name="platform_shop_id" inputmode="numeric" maxlength="32" value="' . esc_attr((string)$settings['shop_id']) . '" required></td></tr>';
    echo '<tr><th>Secret key</th><td><input type="password" name="platform_secret_key" maxlength="255" autocomplete="new-password" placeholder="' . (!empty($settings['secret_cipher']) ? 'Сохранён ····' . esc_attr((string)$settings['secret_last4']) . ' — оставьте пустым, чтобы не менять' : 'Введите Secret key YooKassa') . '"><label style="margin-left:12px"><input type="checkbox" name="platform_remove_secret" value="1"> удалить сохранённый ключ</label></td></tr>';
    echo '<tr><th>Режим</th><td><select name="platform_mode"><option value="test"' . selected((string)$settings['mode'], 'test', false) . '>Тестовый</option><option value="live"' . selected((string)$settings['mode'], 'live', false) . '>Боевой</option></select></td></tr>';
    echo '<tr><th>НДС</th><td><select name="platform_vat_code">';
    foreach ($vatLabels as $code=>$label) echo '<option value="' . (int)$code . '"' . selected((int)$settings['vat_code'], (int)$code, false) . '>' . esc_html($label) . '</option>';
    echo '</select></td></tr>';
    echo '<tr><th>Приём оплаты</th><td><label><input type="checkbox" name="platform_enabled" value="1"' . checked((int)$settings['enabled'], 1, false) . '> включить автоматическую оплату партнёрского режима</label></td></tr></table>';
    submit_button('Сохранить YooKassa партнёрских тарифов');
    echo '</form>';
    if (!empty($settings['secret_cipher'])) {
        echo '<form method="post" style="margin-top:-54px;margin-left:360px">';
        wp_nonce_field('ckm_quiz_pro_partner_admin');
        echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="test_platform_yookassa">';
        submit_button('Проверить подключение', 'secondary', 'submit', false);
        echo '</form>';
    }
    $webhook = ckm_quiz_pro_partner_platform_yk_webhook_url($settings);
    if ($webhook !== '') echo '<p><strong>Webhook YooKassa:</strong><br><code style="word-break:break-all">' . esc_html($webhook) . '</code><br><span class="description">Для HTTP Basic Auth добавьте этот HTTPS-адрес в YooKassa и включите события payment.succeeded и payment.canceled.</span></p>';
    if (!empty($settings['last_checked_at'])) echo '<p class="description">Последняя проверка магазина: ' . esc_html((string)$settings['last_checked_at']) . ' UTC · статус ' . esc_html((string)$settings['account_status']) . ' · ' . (!empty($settings['account_test']) ? 'тестовый' : 'боевой') . ' магазин.</p>';
}

function ckm_quiz_pro_partner_plan_render_admin_orders(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $orders = $wpdb->get_results('SELECT * FROM ' . ckm_quiz_pro_partner_plan_orders_table() . ' ORDER BY id DESC LIMIT 30', ARRAY_A) ?: [];
    echo '<h2 style="margin-top:32px">Последние оплаты партнёрского режима</h2>';
    echo '<table class="widefat striped"><thead><tr><th>Дата</th><th>Площадка</th><th>Тариф</th><th>Сумма</th><th>Статус</th><th>YooKassa</th></tr></thead><tbody>';
    foreach ($orders as $order) {
        echo '<tr><td>' . esc_html((string)$order['created_at']) . '</td><td>#' . (int)$order['tenant_id'] . '</td><td>' . esc_html((string)$order['plan_key']) . '</td><td>' . esc_html(number_format_i18n(((int)$order['amount_minor']) / 100, 2)) . ' ₽</td><td>' . esc_html((string)$order['status']) . (!empty($order['activated_at']) ? '<br><small>активирован</small>' : '') . '</td><td><code>' . esc_html((string)$order['yookassa_payment_id']) . '</code>' . (!empty($order['last_error']) ? '<br><small style="color:#b32d2e">' . esc_html((string)$order['last_error']) . '</small>' : '') . '</td></tr>';
    }
    if (!$orders) echo '<tr><td colspan="6">Оплат партнёрского режима пока нет.</td></tr>';
    echo '</tbody></table>';
}

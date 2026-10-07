<?php
if (!defined('ABSPATH')) exit;

/**
 * Tenant-scoped YooKassa for partner-owned client payments.
 *
 * Important boundary: this module does NOT hijack CKM's own game/scenario
 * checkout. A partner uses these credentials only for invoices issued to the
 * partner's own client-organizers. Money therefore goes directly to the
 * partner's YooKassa account, while CKM's own products keep their existing
 * payment path.
 */

function ckm_quiz_pro_partner_yk_settings_table(): string {
    return ckm_quiz_pro_partner_table('payment_settings');
}

function ckm_quiz_pro_partner_yk_orders_table(): string {
    return ckm_quiz_pro_partner_table('payment_orders');
}

function ckm_quiz_pro_partner_yk_crypto_key(): string {
    return hash('sha256', wp_salt('auth') . '|ckm-partner-yookassa-v1', true);
}

function ckm_quiz_pro_partner_yk_encrypt(string $plain): string|WP_Error {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        return new WP_Error('crypto_unavailable', 'На сервере недоступно безопасное шифрование OpenSSL. Secret key не сохранён.');
    }
    try {
        $iv = random_bytes(12);
    } catch (Throwable $e) {
        return new WP_Error('crypto_random', 'Не удалось подготовить безопасное шифрование ключа.');
    }
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', ckm_quiz_pro_partner_yk_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-yookassa', 16);
    if (!is_string($cipher) || $cipher === '' || strlen($tag) !== 16) {
        return new WP_Error('crypto_failed', 'Не удалось зашифровать Secret key.');
    }
    return 'v1:' . base64_encode($iv . $tag . $cipher);
}

function ckm_quiz_pro_partner_yk_decrypt(string $stored): string|WP_Error {
    if ($stored === '') return '';
    if (!str_starts_with($stored, 'v1:') || !function_exists('openssl_decrypt')) {
        return new WP_Error('crypto_format', 'Сохранённый Secret key имеет неподдерживаемый формат. Введите ключ заново.');
    }
    $raw = base64_decode(substr($stored, 3), true);
    if (!is_string($raw) || strlen($raw) <= 28) return new WP_Error('crypto_format', 'Не удалось прочитать сохранённый Secret key. Введите ключ заново.');
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', ckm_quiz_pro_partner_yk_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-yookassa');
    if (!is_string($plain) || $plain === '') return new WP_Error('crypto_failed', 'Не удалось расшифровать Secret key. Введите ключ заново.');
    return $plain;
}

function ckm_quiz_pro_partner_yk_settings(int $tenantId): array {
    if ($tenantId <= 0) return [];
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_yk_settings_table() . ' WHERE tenant_id=%d LIMIT 1',
        $tenantId
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function ckm_quiz_pro_partner_yk_ready(int $tenantId): bool {
    $s = ckm_quiz_pro_partner_yk_settings($tenantId);
    return !empty($s)
        && (int)($s['enabled'] ?? 0) === 1
        && trim((string)($s['shop_id'] ?? '')) !== ''
        && trim((string)($s['secret_cipher'] ?? '')) !== '';
}

function ckm_quiz_pro_partner_yk_vat_labels(): array {
    return [
        1 => 'Без НДС',
        2 => 'НДС 0%',
        3 => 'НДС 10%',
        4 => 'НДС 20%',
        5 => 'НДС 10/110',
        6 => 'НДС 20/120',
        7 => 'НДС 5%',
        8 => 'НДС 7%',
        9 => 'НДС 5/105',
        10 => 'НДС 7/107',
        11 => 'НДС 22%',
        12 => 'НДС 22/122',
    ];
}

function ckm_quiz_pro_partner_yk_webhook_url(int $tenantId, ?array $settings = null): string {
    $settings = $settings ?? ckm_quiz_pro_partner_yk_settings($tenantId);
    $token = sanitize_text_field((string)($settings['webhook_token'] ?? ''));
    if ($tenantId <= 0 || !preg_match('/^[a-f0-9]{64}$/D', $token)) return '';
    $url = rest_url('ckm-partner/v1/yookassa/' . $tenantId . '/' . $token);
    if (function_exists('ckmqp_tenant_link')) {
        $tenantUrl = ckmqp_tenant_link($url, $tenantId);
        if (is_string($tenantUrl) && $tenantUrl !== '') $url = $tenantUrl;
    }
    return $url;
}

function ckm_quiz_pro_partner_yk_save_settings(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
    if ($tenantId <= 0 || $ownerUserId <= 0 || !ckm_quiz_pro_partner_user_is_owner($tenantId, $ownerUserId)) {
        return new WP_Error('forbidden', 'Настройки YooKassa доступны только владельцу площадки.');
    }
    if (!ckm_quiz_pro_partner_partner_active($tenantId)) return new WP_Error('subscription', 'Сначала активируйте партнёрский режим.');

    $shopId = preg_replace('/\D+/', '', (string)($input['shop_id'] ?? ''));
    if ($shopId === '' || strlen($shopId) < 4 || strlen($shopId) > 32) return new WP_Error('shop_id', 'Укажите корректный Shop ID YooKassa.');
    $mode = sanitize_key((string)($input['mode'] ?? 'test'));
    if (!in_array($mode, ['test', 'live'], true)) $mode = 'test';
    $vat = (int)($input['vat_code'] ?? 1);
    if (!isset(ckm_quiz_pro_partner_yk_vat_labels()[$vat])) $vat = 1;
    $enabled = !empty($input['enabled']) ? 1 : 0;

    $old = ckm_quiz_pro_partner_yk_settings($tenantId);
    $secretCipher = (string)($old['secret_cipher'] ?? '');
    $secretLast4 = (string)($old['secret_last4'] ?? '');
    $secret = trim((string)($input['secret_key'] ?? ''));
    if (!empty($input['remove_secret'])) {
        $secretCipher = '';
        $secretLast4 = '';
        $enabled = 0;
    } elseif ($secret !== '') {
        if (strlen($secret) < 8 || strlen($secret) > 255) return new WP_Error('secret', 'Secret key выглядит некорректно.');
        $enc = ckm_quiz_pro_partner_yk_encrypt($secret);
        if (is_wp_error($enc)) return $enc;
        $secretCipher = $enc;
        $secretLast4 = substr($secret, -4);
    }
    if ($secretCipher === '') $enabled = 0;

    $webhookToken = (string)($old['webhook_token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $webhookToken)) {
        try { $webhookToken = bin2hex(random_bytes(32)); }
        catch (Throwable $e) { return new WP_Error('random', 'Не удалось создать защищённый адрес webhook.'); }
    }

    $now = gmdate('Y-m-d H:i:s');
    $data = [
        'tenant_id' => $tenantId,
        'owner_user_id' => $ownerUserId,
        'shop_id' => $shopId,
        'secret_cipher' => $secretCipher,
        'secret_last4' => $secretLast4,
        'mode' => $mode,
        'vat_code' => $vat,
        'enabled' => $enabled,
        'webhook_token' => $webhookToken,
        'updated_at' => $now,
    ];
    global $wpdb;
    if ($old) {
        $ok = $wpdb->update(ckm_quiz_pro_partner_yk_settings_table(), $data, ['tenant_id' => $tenantId]);
    } else {
        $data['created_at'] = $now;
        $ok = $wpdb->insert(ckm_quiz_pro_partner_yk_settings_table(), $data);
    }
    if ($ok === false) return new WP_Error('database', 'Не удалось сохранить настройки YooKassa.');
    return ckm_quiz_pro_partner_yk_settings($tenantId);
}

function ckm_quiz_pro_partner_yk_api(int $tenantId, string $method, string $path, ?array $body = null, string $idempotenceKey = ''): array|WP_Error {
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);
    if (!$settings) return new WP_Error('settings', 'YooKassa для этой площадки не настроена.');
    $secret = ckm_quiz_pro_partner_yk_decrypt((string)($settings['secret_cipher'] ?? ''));
    if (is_wp_error($secret)) return $secret;
    $shopId = trim((string)($settings['shop_id'] ?? ''));
    if ($shopId === '' || $secret === '') return new WP_Error('settings', 'Укажите Shop ID и Secret key YooKassa.');

    $method = strtoupper($method);
    if (!in_array($method, ['GET', 'POST'], true)) return new WP_Error('method', 'Неподдерживаемый метод YooKassa API.');
    $path = ltrim($path, '/');
    if ($path === '' || preg_match('#[^a-zA-Z0-9_/?=&.\-]#', $path)) return new WP_Error('path', 'Некорректный путь YooKassa API.');

    $headers = [
        'Authorization' => 'Basic ' . base64_encode($shopId . ':' . $secret),
        'Accept' => 'application/json',
    ];
    $args = ['method' => $method, 'headers' => $headers, 'timeout' => 25, 'redirection' => 0];
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

function ckm_quiz_pro_partner_yk_test_connection(int $tenantId): array|WP_Error {
    $me = ckm_quiz_pro_partner_yk_api($tenantId, 'GET', 'me');
    if (is_wp_error($me)) return $me;
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);
    $expectedTest = (string)($settings['mode'] ?? 'test') === 'test';
    $actualTest = !empty($me['test']);
    if ($expectedTest !== $actualTest) {
        return new WP_Error('mode_mismatch', $expectedTest
            ? 'Выбран тестовый режим, но YooKassa вернула боевой магазин. Переключите режим на «Боевой».'
            : 'Выбран боевой режим, но YooKassa вернула тестовый магазин. Переключите режим на «Тестовый».');
    }
    global $wpdb;
    $wpdb->update(ckm_quiz_pro_partner_yk_settings_table(), [
        'account_test' => $actualTest ? 1 : 0,
        'account_status' => sanitize_key((string)($me['status'] ?? '')),
        'fiscalization_enabled' => !empty($me['fiscalization_enabled']) ? 1 : 0,
        'last_checked_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['tenant_id' => $tenantId]);
    return $me;
}

function ckm_quiz_pro_partner_yk_validate_customer_identity(string $login, string $email): true|WP_Error {
    if ($login === '' || strlen($login) > 60 || !validate_username($login)) return new WP_Error('login', 'Укажите корректный логин организатора.');
    if (!is_email($email)) return new WP_Error('email', 'Укажите корректный email организатора.');
    $emailUid = (int)email_exists($email);
    $loginUid = (int)username_exists($login);
    if ($emailUid > 0 && $loginUid > 0 && $emailUid !== $loginUid) return new WP_Error('account_mismatch', 'Логин и email принадлежат разным существующим аккаунтам.');
    $uid = $emailUid ?: $loginUid;
    if ($uid > 0) {
        $user = get_userdata($uid);
        if (!$user || strcasecmp((string)$user->user_email, $email) !== 0 || (string)$user->user_login !== $login) {
            return new WP_Error('account_mismatch', 'Для существующего аккаунта укажите его точные логин и email.');
        }
        $cap = defined('CKM_QUIZ_PRO_ORGANIZER_CAP') ? CKM_QUIZ_PRO_ORGANIZER_CAP : 'ckm_quiz_organize';
        if (!user_can($uid, $cap) && !user_can($uid, 'manage_options')) return new WP_Error('role', 'Этот аккаунт не имеет прав организатора.');
    }
    return true;
}

function ckm_quiz_pro_partner_yk_amount_minor(string $rub): int|WP_Error {
    $rub = trim(str_replace(',', '.', $rub));
    if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $rub)) return new WP_Error('amount', 'Укажите сумму в рублях, например 1500 или 1500.00.');
    [$whole, $frac] = array_pad(explode('.', $rub, 2), 2, '');
    $frac = str_pad(substr($frac, 0, 2), 2, '0');
    $minor = ((int)$whole * 100) + (int)$frac;
    if ($minor < 100 || $minor > 1000000000) return new WP_Error('amount', 'Сумма должна быть от 1 ₽ до 10 000 000 ₽.');
    return $minor;
}

function ckm_quiz_pro_partner_yk_create_order(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
    if (!ckm_quiz_pro_partner_partner_active($tenantId)) return new WP_Error('subscription', 'Партнёрский режим не активна.');
    if (!ckm_quiz_pro_partner_user_is_owner($tenantId, $ownerUserId)) return new WP_Error('forbidden', 'Только владелец площадки может выставлять счета.');
    if (!ckm_quiz_pro_partner_yk_ready($tenantId)) return new WP_Error('settings', 'Сначала подключите и включите свою YooKassa.');

    $name = sanitize_text_field((string)($input['client_name'] ?? ''));
    $login = sanitize_user((string)($input['client_login'] ?? ''), true);
    $email = sanitize_email((string)($input['client_email'] ?? ''));
    if ($name === '') $name = $login;
    $valid = ckm_quiz_pro_partner_yk_validate_customer_identity($login, $email);
    if (is_wp_error($valid)) return $valid;
    $minor = ckm_quiz_pro_partner_yk_amount_minor((string)($input['amount_rub'] ?? ''));
    if (is_wp_error($minor)) return $minor;
    $description = sanitize_text_field((string)($input['description'] ?? ''));
    if ($description === '') $description = 'Доступ организатора к игровой площадке';
    if (function_exists('mb_substr')) $description = mb_substr($description, 0, 128, 'UTF-8');
    else $description = substr($description, 0, 128);

    try { $publicToken = bin2hex(random_bytes(32)); }
    catch (Throwable $e) { return new WP_Error('random', 'Не удалось создать защищённую ссылку оплаты.'); }
    $orderId = wp_generate_uuid4();
    $now = gmdate('Y-m-d H:i:s');
    global $wpdb;
    $ok = $wpdb->insert(ckm_quiz_pro_partner_yk_orders_table(), [
        'order_id' => $orderId,
        'public_token' => $publicToken,
        'tenant_id' => $tenantId,
        'owner_user_id' => $ownerUserId,
        'customer_user_id' => 0,
        'customer_name' => $name,
        'customer_login' => $login,
        'customer_email' => $email,
        'amount_minor' => $minor,
        'currency' => 'RUB',
        'description' => $description,
        'status' => 'created',
        'access_status' => 'pending',
        'yookassa_payment_id' => '',
        'confirmation_url' => '',
        'last_error' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if (!$ok) return new WP_Error('database', 'Не удалось сохранить счёт.');
    $order = ckm_quiz_pro_partner_yk_order($tenantId, $orderId);
    if (!$order) return new WP_Error('database', 'Счёт сохранён, но не найден повторно.');

    $link = ckm_quiz_pro_partner_yk_order_url($order);
    if ($link !== '') {
        wp_mail($email, 'Ссылка на оплату доступа к площадке', "Здравствуйте, {$name}!\n\nОплатить доступ организатора: {$link}\n\nПосле успешной оплаты учётная запись будет подключена к площадке автоматически.");
    }
    return $order;
}

function ckm_quiz_pro_partner_yk_order(int $tenantId, string $orderId): array {
    if ($tenantId <= 0 || !preg_match('/^[a-f0-9-]{36}$/iD', $orderId)) return [];
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_yk_orders_table() . ' WHERE tenant_id=%d AND order_id=%s LIMIT 1',
        $tenantId, $orderId
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function ckm_quiz_pro_partner_yk_order_public(string $orderId, string $token): array {
    if (!preg_match('/^[a-f0-9-]{36}$/iD', $orderId) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return [];
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_yk_orders_table() . ' WHERE order_id=%s AND public_token=%s LIMIT 1',
        $orderId, $token
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function ckm_quiz_pro_partner_yk_order_url(array $order): string {
    $tenantId = (int)($order['tenant_id'] ?? 0);
    $orderId = (string)($order['order_id'] ?? '');
    $token = (string)($order['public_token'] ?? '');
    if ($tenantId <= 0 || $orderId === '' || $token === '') return '';
    $url = home_url('/ckm-partner-pay/');
    if (function_exists('ckmqp_tenant_link')) {
        $tenantUrl = ckmqp_tenant_link($url, $tenantId);
        if (is_string($tenantUrl) && $tenantUrl !== '') $url = $tenantUrl;
    }
    return add_query_arg(['order' => $orderId, 'token' => $token], $url);
}

function ckm_quiz_pro_partner_yk_format_minor(int $minor): string {
    return number_format($minor / 100, 2, '.', '');
}

function ckm_quiz_pro_partner_yk_payment_body(array $order, array $settings): array {
    $returnUrl = add_query_arg('return', '1', ckm_quiz_pro_partner_yk_order_url($order));
    $amount = ['value' => ckm_quiz_pro_partner_yk_format_minor((int)$order['amount_minor']), 'currency' => 'RUB'];
    $body = [
        'amount' => $amount,
        'capture' => true,
        'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
        'description' => (string)$order['description'],
        'metadata' => [
            'ckm_order_id' => (string)$order['order_id'],
            'ckm_tenant_id' => (string)$order['tenant_id'],
            'ckm_kind' => 'partner_organizer_access',
        ],
    ];
    if (!empty($settings['fiscalization_enabled'])) {
        $body['receipt'] = [
            'customer' => ['email' => (string)$order['customer_email']],
            'items' => [[
                'description' => (string)$order['description'],
                'quantity' => 1,
                'amount' => $amount,
                'vat_code' => max(1, min(12, (int)($settings['vat_code'] ?? 1))),
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ]],
        ];
    }
    return $body;
}

function ckm_quiz_pro_partner_yk_create_payment(array $order): array|WP_Error {
    $tenantId = (int)($order['tenant_id'] ?? 0);
    if ($tenantId <= 0 || !ckm_quiz_pro_partner_partner_active($tenantId)) return new WP_Error('subscription', 'Партнёрский режим площадки сейчас не активен. Оплата временно недоступна.');
    if (!ckm_quiz_pro_partner_yk_ready($tenantId)) return new WP_Error('settings', 'YooKassa площадки временно отключена.');
    if ((string)($order['status'] ?? '') === 'succeeded') return $order;
    if (!empty($order['confirmation_url']) && in_array((string)$order['status'], ['pending', 'waiting_for_capture'], true)) return $order;

    // Refresh shop metadata before the first real payment. This also verifies
    // credentials and tells us whether receipt data is required.
    $me = ckm_quiz_pro_partner_yk_test_connection($tenantId);
    if (is_wp_error($me)) return $me;
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);
    if ((string)($settings['account_status'] ?? '') !== '' && (string)$settings['account_status'] !== 'enabled') {
        return new WP_Error('shop_disabled', 'Магазин YooKassa не находится в статусе enabled.');
    }

    $remote = ckm_quiz_pro_partner_yk_api(
        $tenantId,
        'POST',
        'payments',
        ckm_quiz_pro_partner_yk_payment_body($order, $settings),
        'ckm-' . (string)$order['order_id']
    );
    if (is_wp_error($remote)) return $remote;
    $paymentId = sanitize_text_field((string)($remote['id'] ?? ''));
    $status = sanitize_key((string)($remote['status'] ?? 'pending'));
    $confirmation = '';
    if (isset($remote['confirmation']) && is_array($remote['confirmation'])) {
        $confirmation = esc_url_raw((string)($remote['confirmation']['confirmation_url'] ?? ''), ['https']);
    }
    if ($paymentId === '') return new WP_Error('response', 'YooKassa не вернула идентификатор платежа.');
    if ($confirmation === '' && $status !== 'succeeded') return new WP_Error('response', 'YooKassa не вернула ссылку для подтверждения платежа.');

    global $wpdb;
    $wpdb->update(ckm_quiz_pro_partner_yk_orders_table(), [
        'status' => $status,
        'yookassa_payment_id' => $paymentId,
        'confirmation_url' => $confirmation,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => (int)$order['id'], 'tenant_id' => $tenantId]);
    $fresh = ckm_quiz_pro_partner_yk_order($tenantId, (string)$order['order_id']);
    if ($status === 'succeeded') {
        $reconciled = ckm_quiz_pro_partner_yk_reconcile_payment($tenantId, $remote);
        if (is_wp_error($reconciled)) return $reconciled;
        $fresh = ckm_quiz_pro_partner_yk_order($tenantId, (string)$order['order_id']);
    }
    return $fresh ?: $order;
}

function ckm_quiz_pro_partner_yk_fetch_payment(int $tenantId, string $paymentId): array|WP_Error {
    if ($paymentId === '' || strlen($paymentId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/D', $paymentId)) return new WP_Error('payment', 'Некорректный идентификатор платежа.');
    return ckm_quiz_pro_partner_yk_api($tenantId, 'GET', 'payments/' . $paymentId);
}

function ckm_quiz_pro_partner_yk_reconcile_payment(int $tenantId, array $payment): array|WP_Error {
    $paymentId = sanitize_text_field((string)($payment['id'] ?? ''));
    $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
    $orderId = sanitize_text_field((string)($metadata['ckm_order_id'] ?? ''));
    if ($paymentId === '' || $orderId === '' || (int)($metadata['ckm_tenant_id'] ?? 0) !== $tenantId || (string)($metadata['ckm_kind'] ?? '') !== 'partner_organizer_access') {
        return new WP_Error('metadata', 'Платёж не относится к счёту этой площадки.');
    }
    $order = ckm_quiz_pro_partner_yk_order($tenantId, $orderId);
    if (!$order) return new WP_Error('order', 'Счёт для платежа не найден.');
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);

    // Payment confirmation is a security boundary: do not accept a partially
    // populated provider response. The authoritative YooKassa response must
    // explicitly identify this shop and the configured test/live environment.
    $recipient = is_array($payment['recipient'] ?? null) ? $payment['recipient'] : [];
    $recipientAccount = trim((string)($recipient['account_id'] ?? ''));
    if ($recipientAccount === '' || $recipientAccount !== (string)($settings['shop_id'] ?? '')) {
        return new WP_Error('recipient', 'Платёж относится к другому или неопределённому магазину YooKassa.');
    }
    if (!array_key_exists('test', $payment)) {
        return new WP_Error('mode_missing', 'YooKassa не указала режим платежа. Платёж не подтверждён.');
    }
    $expectedTest = (string)($settings['mode'] ?? 'test') === 'test';
    if ((bool)$payment['test'] !== $expectedTest) {
        return new WP_Error('mode_mismatch', 'Режим платежа не совпадает с режимом YooKassa площадки.');
    }

    $amount = is_array($payment['amount'] ?? null) ? $payment['amount'] : [];
    $remoteMinor = ckm_quiz_pro_partner_yk_amount_minor((string)($amount['value'] ?? ''));
    if (is_wp_error($remoteMinor) || (int)$remoteMinor !== (int)$order['amount_minor'] || (string)($amount['currency'] ?? '') !== 'RUB') {
        return new WP_Error('amount_mismatch', 'Сумма или валюта платежа не совпадает со счётом.');
    }

    $status = sanitize_key((string)($payment['status'] ?? ''));
    if (!in_array($status, ['pending', 'waiting_for_capture', 'succeeded', 'canceled'], true)) return new WP_Error('status', 'YooKassa вернула неизвестный статус платежа.');
    global $wpdb;
    $update = [
        'status' => $status,
        'yookassa_payment_id' => $paymentId,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ];
    if ($status === 'canceled') $update['last_error'] = sanitize_text_field((string)($payment['cancellation_details']['reason'] ?? 'Платёж отменён.'));
    if ($status === 'succeeded') $update['paid_at'] = gmdate('Y-m-d H:i:s');
    $wpdb->update(ckm_quiz_pro_partner_yk_orders_table(), $update, ['id' => (int)$order['id'], 'tenant_id' => $tenantId]);

    if ($status === 'succeeded' && (string)($order['access_status'] ?? '') !== 'active') {
        $member = ckm_quiz_pro_partner_member_create($tenantId, [
            'login' => (string)$order['customer_login'],
            'email' => (string)$order['customer_email'],
            'name' => (string)$order['customer_name'],
        ]);
        if (is_wp_error($member)) {
            $wpdb->update(ckm_quiz_pro_partner_yk_orders_table(), [
                'access_status' => 'error',
                'last_error' => sanitize_text_field($member->get_error_message()),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int)$order['id'], 'tenant_id' => $tenantId]);
            return new WP_Error('access', 'Оплата подтверждена, но организатора не удалось подключить автоматически: ' . $member->get_error_message());
        }
        $wpdb->update(ckm_quiz_pro_partner_yk_orders_table(), [
            'customer_user_id' => (int)$member['user_id'],
            'access_status' => 'active',
            'last_error' => '',
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => (int)$order['id'], 'tenant_id' => $tenantId]);
    }
    return ckm_quiz_pro_partner_yk_order($tenantId, $orderId);
}

function ckm_quiz_pro_partner_yk_sync_order(array $order): array|WP_Error {
    $paymentId = (string)($order['yookassa_payment_id'] ?? '');
    if ($paymentId === '') return $order;
    $payment = ckm_quiz_pro_partner_yk_fetch_payment((int)$order['tenant_id'], $paymentId);
    if (is_wp_error($payment)) return $payment;
    return ckm_quiz_pro_partner_yk_reconcile_payment((int)$order['tenant_id'], $payment);
}

function ckm_quiz_pro_partner_yk_register_routes(): void {
    register_rest_route('ckm-partner/v1', '/yookassa/(?P<tenant>\d+)/(?P<token>[a-f0-9]{64})', [
        'methods' => 'POST',
        'callback' => 'ckm_quiz_pro_partner_yk_webhook',
        'permission_callback' => '__return_true',
    ]);
}

function ckm_quiz_pro_partner_yk_webhook(WP_REST_Request $request): WP_REST_Response {
    $tenantId = (int)$request['tenant'];
    $token = (string)$request['token'];
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);
    if (!$settings || !hash_equals((string)($settings['webhook_token'] ?? ''), $token)) {
        return new WP_REST_Response(['ok' => false], 404);
    }
    $body = $request->get_json_params();
    if (!is_array($body) || (string)($body['type'] ?? '') !== 'notification') return new WP_REST_Response(['ok' => false], 400);
    $event = sanitize_key((string)($body['event'] ?? ''));
    if (!in_array($event, ['payment.succeeded', 'payment.canceled', 'payment.waiting_for_capture'], true)) {
        return new WP_REST_Response(['ok' => true, 'ignored' => true], 200);
    }
    $paymentId = sanitize_text_field((string)($body['object']['id'] ?? ''));
    if ($paymentId === '') return new WP_REST_Response(['ok' => false], 400);

    // Never grant access from the webhook body alone. Re-read the payment from
    // YooKassa with this tenant's own credentials, then compare shop, amount,
    // currency, metadata and test/live mode before activating membership.
    $payment = ckm_quiz_pro_partner_yk_fetch_payment($tenantId, $paymentId);
    if (is_wp_error($payment)) return new WP_REST_Response(['ok' => false], 502);
    $result = ckm_quiz_pro_partner_yk_reconcile_payment($tenantId, $payment);
    if (is_wp_error($result)) return new WP_REST_Response(['ok' => false], 409);
    return new WP_REST_Response(['ok' => true], 200);
}

function ckm_quiz_pro_partner_yk_public_route(): void {
    $path = rtrim((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== '/ckm-partner-pay') return;
    $orderId = sanitize_text_field((string)($_GET['order'] ?? $_POST['order'] ?? ''));
    $token = sanitize_text_field((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    $order = ckm_quiz_pro_partner_yk_order_public($orderId, $token);
    if (!$order || (int)$order['tenant_id'] !== (int)ckmqp_scope_id()) wp_die('Ссылка оплаты недействительна.', '', ['response' => 404]);

    $error = '';
    if (!empty($_GET['return']) && !empty($order['yookassa_payment_id'])) {
        $sync = ckm_quiz_pro_partner_yk_sync_order($order);
        if (is_wp_error($sync)) $error = $sync->get_error_message();
        else $order = $sync;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['start_payment'])) {
        if (!hash_equals((string)$order['public_token'], $token)) $error = 'Ссылка оплаты недействительна.';
        else {
            $created = ckm_quiz_pro_partner_yk_create_payment($order);
            if (is_wp_error($created)) $error = $created->get_error_message();
            else {
                $order = $created;
                if ((string)$order['status'] === 'succeeded') {
                    wp_safe_redirect(add_query_arg('return', '1', ckm_quiz_pro_partner_yk_order_url($order)));
                    exit;
                }
                $confirmation = esc_url_raw((string)($order['confirmation_url'] ?? ''), ['https']);
                if ($confirmation !== '') {
                    wp_redirect($confirmation, 302, 'CKM Partner YooKassa');
                    exit;
                }
                $error = 'Не удалось получить ссылку YooKassa.';
            }
        }
    }

    $tenantName = 'Партнёрская площадка';
    global $wpdb;
    $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . ckmqp_tenant_table('tenants') . ' WHERE id=%d LIMIT 1', (int)$order['tenant_id']));
    if (is_string($name) && trim($name) !== '') $tenantName = $name;

    nocache_headers(); status_header(200);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Оплата — ' . esc_html($tenantName) . '</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#152033;margin:0}.wrap{max-width:720px;margin:8vh auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ee;border-radius:18px;padding:26px;box-shadow:0 12px 40px rgba(28,44,70,.08)}h1{margin-top:0}.amount{font-size:28px;font-weight:800}.btn{display:inline-block;border:0;border-radius:12px;background:#111d2f;color:#fff;padding:13px 18px;font-weight:700;cursor:pointer}.ok{padding:12px;border-radius:10px;background:#eaf8ef;color:#195b31}.err{padding:12px;border-radius:10px;background:#fff0f0;color:#8b1d1d}.muted{color:#687386;font-size:14px}</style></head><body><div class="wrap"><div class="card">';
    echo '<p class="muted">' . esc_html($tenantName) . '</p><h1>Оплата доступа организатора</h1>';
    echo '<p>' . esc_html((string)$order['description']) . '</p><p class="amount">' . esc_html(number_format_i18n((int)$order['amount_minor'] / 100, 2)) . ' ₽</p>';
    echo '<p>Организатор: <strong>' . esc_html((string)$order['customer_name']) . '</strong><br>' . esc_html((string)$order['customer_email']) . '</p>';
    if ($error !== '') echo '<div class="err">' . esc_html($error) . '</div>';
    if ((string)$order['status'] === 'succeeded' && (string)$order['access_status'] === 'active') {
        echo '<div class="ok"><strong>Оплата подтверждена.</strong> Учётная запись организатора подключена к площадке. Данные для входа отправлены на email.</div>';
    } elseif ((string)$order['status'] === 'succeeded') {
        echo '<div class="err"><strong>Оплата подтверждена.</strong> Автоматическое подключение требует проверки владельца площадки.</div>';
    } elseif ((string)$order['status'] === 'canceled') {
        echo '<div class="err">Платёж отменён. Владелец площадки может выставить новый счёт.</div>';
    } else {
        echo '<form method="post"><input type="hidden" name="order" value="' . esc_attr($orderId) . '"><input type="hidden" name="token" value="' . esc_attr($token) . '"><button class="btn" name="start_payment" value="1">Перейти к оплате в YooKassa</button></form>';
        if (!empty($order['yookassa_payment_id'])) echo '<p class="muted">Если вы уже оплатили, обновите эту страницу через несколько секунд.</p>';
    }
    echo '<p class="muted">Платёж принимает владелец этой площадки через собственный магазин YooKassa. ЦКМ не получает реквизиты банковской карты покупателя.</p>';
    echo '</div></div></body></html>'; exit;
}

function ckm_quiz_pro_partner_yk_recent_orders(int $tenantId, int $limit = 20): array {
    if ($tenantId <= 0) return [];
    global $wpdb;
    $limit = max(1, min(100, $limit));
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_yk_orders_table() . ' WHERE tenant_id=%d ORDER BY id DESC LIMIT %d',
        $tenantId, $limit
    ), ARRAY_A) ?: [];
}

function ckm_quiz_pro_partner_yk_handle_owner_action(int $tenantId, int $ownerUserId, string $action): array {
    $result = ['notice' => '', 'error' => ''];
    if ($action === 'save_yookassa') {
        $saved = ckm_quiz_pro_partner_yk_save_settings($tenantId, $ownerUserId, [
            'shop_id' => wp_unslash($_POST['shop_id'] ?? ''),
            'secret_key' => wp_unslash($_POST['secret_key'] ?? ''),
            'mode' => wp_unslash($_POST['yk_mode'] ?? 'test'),
            'vat_code' => wp_unslash($_POST['vat_code'] ?? '1'),
            'enabled' => !empty($_POST['yk_enabled']),
            'remove_secret' => !empty($_POST['remove_secret']),
        ]);
        if (is_wp_error($saved)) $result['error'] = $saved->get_error_message();
        else $result['notice'] = 'Настройки YooKassa сохранены. Secret key хранится в зашифрованном виде.';
    } elseif ($action === 'test_yookassa') {
        $test = ckm_quiz_pro_partner_yk_test_connection($tenantId);
        if (is_wp_error($test)) $result['error'] = $test->get_error_message();
        else $result['notice'] = 'Соединение с YooKassa подтверждено. Магазин: ' . (!empty($test['test']) ? 'тестовый' : 'боевой') . ', статус: ' . sanitize_text_field((string)($test['status'] ?? 'unknown')) . '.';
    } elseif ($action === 'create_payment_request') {
        $order = ckm_quiz_pro_partner_yk_create_order($tenantId, $ownerUserId, [
            'client_name' => wp_unslash($_POST['client_name'] ?? ''),
            'client_login' => wp_unslash($_POST['client_login'] ?? ''),
            'client_email' => wp_unslash($_POST['client_email'] ?? ''),
            'amount_rub' => wp_unslash($_POST['amount_rub'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
        ]);
        if (is_wp_error($order)) $result['error'] = $order->get_error_message();
        else {
            $url = ckm_quiz_pro_partner_yk_order_url($order);
            $result['notice'] = 'Счёт создан. Ссылка отправлена клиенту на email.' . ($url !== '' ? ' Ссылка: ' . $url : '');
        }
    }
    return $result;
}

function ckm_quiz_pro_partner_yk_render_owner_section(int $tenantId): void {
    $settings = ckm_quiz_pro_partner_yk_settings($tenantId);
    $ready = ckm_quiz_pro_partner_yk_ready($tenantId);
    $mode = (string)($settings['mode'] ?? 'test');
    $vat = (int)($settings['vat_code'] ?? 1);
    $webhook = ckm_quiz_pro_partner_yk_webhook_url($tenantId, $settings);

    echo '<hr style="margin:30px 0"><div class="ckm-kicker">ПЛАТЕЖИ ПАРТНЁРА</div><h2>Моя YooKassa</h2>';
    echo '<p>Подключите собственный магазин YooKassa. Деньги от ваших клиентов поступают напрямую вам. Оплата партнёрского режима ЦКМ и покупки у владельца платформы остаются отдельными и через эту кассу не проходят.</p>';
    echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
    echo '<input type="hidden" name="partner_action" value="save_yookassa">';
    echo '<label class="ckm-label">Shop ID<input class="ckm-input" name="shop_id" inputmode="numeric" maxlength="32" value="' . esc_attr((string)($settings['shop_id'] ?? '')) . '" required></label>';
    echo '<label class="ckm-label">Secret key<input class="ckm-input" type="password" name="secret_key" autocomplete="new-password" maxlength="255" placeholder="' . (!empty($settings['secret_cipher']) ? 'Сохранён ····' . esc_attr((string)$settings['secret_last4']) . ' — оставьте пустым, чтобы не менять' : 'Введите Secret key YooKassa') . '"></label>';
    echo '<div class="ckm-form-grid"><label class="ckm-label">Режим<select class="ckm-input" name="yk_mode"><option value="test"' . selected($mode, 'test', false) . '>Тестовый магазин</option><option value="live"' . selected($mode, 'live', false) . '>Боевой магазин</option></select></label>';
    echo '<label class="ckm-label">НДС для услуги<select class="ckm-input" name="vat_code">';
    foreach (ckm_quiz_pro_partner_yk_vat_labels() as $code => $label) echo '<option value="' . (int)$code . '"' . selected($vat, $code, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label></div>';
    echo '<label><input type="checkbox" name="yk_enabled" value="1"' . checked((int)($settings['enabled'] ?? 0), 1, false) . '> Принимать платежи через мою YooKassa</label>';
    if (!empty($settings['secret_cipher'])) echo '<br><label><input type="checkbox" name="remove_secret" value="1"> Удалить сохранённый Secret key и отключить приём платежей</label>';
    echo '<p><button class="ckm-btn ckm-btn-primary">Сохранить YooKassa</button></p></form>';

    if ($settings) {
        echo '<form method="post" style="display:inline-block;margin-right:8px">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
        echo '<input type="hidden" name="partner_action" value="test_yookassa"><button class="ckm-btn">Проверить подключение</button></form>';
    }
    echo '<p class="ckm-muted">Статус: <strong>' . ($ready ? 'включена' : 'не готова к приёму платежей') . '</strong>';
    if (!empty($settings['last_checked_at'])) echo ' · API проверен ' . esc_html(wp_date('d.m.Y H:i', strtotime((string)$settings['last_checked_at'] . ' UTC')));
    echo '.</p>';
    if ($webhook !== '') {
        echo '<div class="ckm-alert"><strong>Webhook YooKassa</strong><br><code style="word-break:break-all">' . esc_html($webhook) . '</code><br><span class="ckm-muted">Укажите этот HTTPS-адрес в личном кабинете YooKassa → Интеграция → HTTP-уведомления. Включите события payment.succeeded и payment.canceled.</span></div>';
    }

    echo '<h2>Выставить счёт организатору</h2>';
    echo '<p>После успешной оплаты система создаст или подключит аккаунт организатора именно к этой площадке. До оплаты доступ не выдаётся.</p>';
    if (!$ready) {
        echo '<p class="ckm-alert ckm-alert-error">Сначала сохраните Shop ID и Secret key и включите приём платежей.</p>';
    } else {
        echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
        echo '<input type="hidden" name="partner_action" value="create_payment_request">';
        echo '<div class="ckm-form-grid"><label class="ckm-label">Имя<input class="ckm-input" name="client_name" maxlength="190" required></label><label class="ckm-label">Логин<input class="ckm-input" name="client_login" maxlength="60" required></label></div>';
        echo '<div class="ckm-form-grid"><label class="ckm-label">Email<input class="ckm-input" type="email" name="client_email" maxlength="190" required></label><label class="ckm-label">Сумма, ₽<input class="ckm-input" name="amount_rub" inputmode="decimal" placeholder="1500.00" required></label></div>';
        echo '<label class="ckm-label">Назначение платежа<input class="ckm-input" name="description" maxlength="128" value="Доступ организатора к игровой площадке"></label>';
        echo '<p><button class="ckm-btn ckm-btn-primary">Создать ссылку на оплату</button></p></form>';
    }

    $orders = ckm_quiz_pro_partner_yk_recent_orders($tenantId, 20);
    echo '<h2>Последние счета</h2><table class="widefat"><thead><tr><th>Клиент</th><th>Сумма</th><th>Платёж</th><th>Доступ</th><th>Ссылка</th></tr></thead><tbody>';
    foreach ($orders as $order) {
        $url = ckm_quiz_pro_partner_yk_order_url($order);
        echo '<tr><td><strong>' . esc_html((string)$order['customer_name']) . '</strong><br>' . esc_html((string)$order['customer_email']) . '</td><td>' . esc_html(number_format_i18n((int)$order['amount_minor'] / 100, 2)) . ' ₽</td><td>' . esc_html((string)$order['status']) . '</td><td>' . esc_html((string)$order['access_status']) . '</td><td>' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Открыть</a>' : '—') . '</td></tr>';
        if (!empty($order['last_error'])) echo '<tr><td colspan="5" class="ckm-muted">' . esc_html((string)$order['last_error']) . '</td></tr>';
    }
    if (!$orders) echo '<tr><td colspan="5">Счетов пока нет.</td></tr>';
    echo '</tbody></table>';
}

/**
 * Partner-owned client payments are handled by T-Bank.
 * Platform-owned partner plan payments remain in plan-checkout.php and use
 * the CKM administrator's YooKassa account.
 */
function ckm_quiz_pro_partner_tbank_order(int $tenantId, string $orderId): array {
    if ($tenantId <= 0 || !preg_match('/^[a-f0-9-]{36}$/iD', $orderId)) return [];
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_table('payment_orders') . ' WHERE tenant_id=%d AND order_id=%s LIMIT 1',
        $tenantId,
        $orderId
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function ckm_quiz_pro_partner_tbank_order_public(string $orderId, string $token): array {
    if (!preg_match('/^[a-f0-9-]{36}$/iD', $orderId) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return [];
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_table('payment_orders') . ' WHERE order_id=%s AND public_token=%s LIMIT 1',
        $orderId,
        $token
    ), ARRAY_A);
    return is_array($row) ? $row : [];
}

function ckm_quiz_pro_partner_tbank_order_url(array $order): string {
    $tenantId = (int)($order['tenant_id'] ?? 0);
    $orderId = (string)($order['order_id'] ?? '');
    $token = (string)($order['public_token'] ?? '');
    if ($tenantId <= 0 || $orderId === '' || $token === '') return '';
    $url = home_url('/ckm-partner-pay/');
    if (function_exists('ckmqp_tenant_link')) {
        $tenantUrl = ckmqp_tenant_link($url, $tenantId);
        if (is_string($tenantUrl) && $tenantUrl !== '') $url = $tenantUrl;
    }
    return add_query_arg(['order' => $orderId, 'token' => $token], $url);
}

function ckm_quiz_pro_partner_tbank_map_status(string $status): string {
    $status = strtoupper(trim($status));
    if ($status === 'CONFIRMED') return 'succeeded';
    if (in_array($status, ['REJECTED', 'DEADLINE_EXPIRED', 'REVERSED', 'REFUNDED'], true)) return 'canceled';
    return 'pending';
}

function ckm_quiz_pro_partner_tbank_activate_order(array $order): array|WP_Error {
    if ((string)($order['access_status'] ?? '') === 'active') return $order;
    $member = ckm_quiz_pro_partner_member_create((int)$order['tenant_id'], [
        'login' => (string)$order['customer_login'],
        'email' => (string)$order['customer_email'],
        'name' => (string)$order['customer_name'],
    ]);
    if (is_wp_error($member)) return $member;

    global $wpdb;
    $ok = $wpdb->update(
        ckm_quiz_pro_partner_table('payment_orders'),
        [
            'customer_user_id' => (int)$member['user_id'],
            'access_status' => 'active',
            'last_error' => '',
            'paid_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ],
        ['id' => (int)$order['id'], 'tenant_id' => (int)$order['tenant_id']]
    );
    if ($ok === false) return new WP_Error('database', 'Оплата подтверждена, но не удалось активировать организатора.');
    return ckm_quiz_pro_partner_tbank_order((int)$order['tenant_id'], (string)$order['order_id']);
}

function ckm_quiz_pro_partner_tbank_create_order(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) {
        return new WP_Error('provider', 'Провайдер Т‑Банка не загружен.');
    }
    $draft = $provider->create_order($tenantId, $ownerUserId, $input);
    if (is_wp_error($draft)) return $draft;

    try {
        $publicToken = bin2hex(random_bytes(32));
        $orderId = wp_generate_uuid4();
    } catch (Throwable $e) {
        return new WP_Error('random', 'Не удалось создать защищённый счёт.');
    }

    $now = gmdate('Y-m-d H:i:s');
    global $wpdb;
    $ok = $wpdb->insert(ckm_quiz_pro_partner_table('payment_orders'), [
        'order_id' => $orderId,
        'public_token' => $publicToken,
        'tenant_id' => $tenantId,
        'owner_user_id' => $ownerUserId,
        'customer_user_id' => 0,
        'customer_name' => (string)$draft['customer_name'],
        'customer_login' => (string)$draft['customer_login'],
        'customer_email' => (string)$draft['customer_email'],
        'amount_minor' => (int)$draft['amount_minor'],
        'currency' => 'RUB',
        'description' => (string)$draft['description'],
        'status' => 'created',
        'access_status' => 'pending',
        'provider' => 'tbank',
        'yookassa_payment_id' => '',
        'tbank_payment_id' => '',
        'confirmation_url' => '',
        'last_error' => '',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    if ($ok === false) return new WP_Error('database', 'Не удалось сохранить счёт Т‑Банка.');

    $order = ckm_quiz_pro_partner_tbank_order($tenantId, $orderId);
    if (!$order) return new WP_Error('database', 'Счёт сохранён, но не найден повторно.');
    $link = ckm_quiz_pro_partner_tbank_order_url($order);
    if ($link !== '') {
        wp_mail(
            (string)$order['customer_email'],
            'Ссылка на оплату доступа к площадке',
            "Здравствуйте, {$order['customer_name']}!\n\nОплатить доступ организатора: {$link}\n\nПосле успешной оплаты учётная запись будет подключена к площадке автоматически."
        );
    }
    return $order;
}

function ckm_quiz_pro_partner_tbank_sync_order(array $order): array|WP_Error {
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) return new WP_Error('provider', 'Провайдер Т‑Банка не загружен.');
    if ((string)($order['provider'] ?? '') !== 'tbank') return $order;
    if ((string)($order['access_status'] ?? '') === 'active') return $order;

    $synced = $provider->sync_order($order);
    if (is_wp_error($synced)) return $synced;
    $remote = is_array($synced['provider_response'] ?? null) ? $synced['provider_response'] : [];
    $remoteOrderId = sanitize_text_field((string)($remote['OrderId'] ?? ''));
    $remotePaymentId = sanitize_text_field((string)($remote['PaymentId'] ?? ''));
    $remoteTerminalKey = sanitize_text_field((string)($remote['TerminalKey'] ?? ''));
    $remoteAmount = isset($remote['Amount']) && is_numeric($remote['Amount']) ? (int)$remote['Amount'] : -1;
    if (!array_key_exists('Success', $remote) || $remote['Success'] !== true) return new WP_Error('provider_status', 'Т‑Банк не подтвердил запрос статуса.');
    if ($remoteTerminalKey === '') return new WP_Error('terminal_mismatch', 'Т‑Банк не вернул TerminalKey.');
    $settings = $provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider ? $provider->settings_for_ui((int)$order['tenant_id']) : [];
    if ($settings === [] || (string)($settings['provider'] ?? '') !== 'tbank') return new WP_Error('settings', 'Настройки Т‑Банка для площадки не найдены.');
    $configuredTerminalKey = $provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider ? $provider->configured_terminal_key((int)$order['tenant_id']) : '';
    if ($configuredTerminalKey === '' || !hash_equals($configuredTerminalKey, $remoteTerminalKey)) return new WP_Error('terminal_mismatch', 'Т‑Банк вернул другой TerminalKey.');
    if ($remoteOrderId === '' || !hash_equals((string)$order['order_id'], $remoteOrderId)) return new WP_Error('order_mismatch', 'Т‑Банк вернул другой OrderId.');
    if ($remotePaymentId === '' || !hash_equals((string)($order['tbank_payment_id'] ?? ''), $remotePaymentId)) return new WP_Error('payment_mismatch', 'Т‑Банк вернул другой PaymentId.');
    if ($remoteAmount < 0 || $remoteAmount !== (int)$order['amount_minor']) return new WP_Error('amount_mismatch', 'Сумма платежа Т‑Банка не совпадает со счётом.');

    $providerStatus = strtoupper((string)($remote['Status'] ?? ''));
    $status = ckm_quiz_pro_partner_tbank_map_status($providerStatus);
    global $wpdb;
    $update = [
        'status' => $status,
        'tbank_payment_id' => $remotePaymentId,
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'last_error' => '',
    ];
    if ($status === 'canceled') $update['last_error'] = 'Платёж Т‑Банк завершён со статусом ' . sanitize_text_field($providerStatus) . '.';
    if ($status === 'succeeded') $update['paid_at'] = gmdate('Y-m-d H:i:s');
    $ok = $wpdb->update(ckm_quiz_pro_partner_table('payment_orders'), $update, ['id' => (int)$order['id'], 'tenant_id' => (int)$order['tenant_id']]);
    if ($ok === false) return new WP_Error('database', 'Не удалось сохранить статус платежа Т‑Банка.');

    $fresh = ckm_quiz_pro_partner_tbank_order((int)$order['tenant_id'], (string)$order['order_id']);
    if (!$fresh) return new WP_Error('database', 'Счёт Т‑Банка не найден после обновления.');
    if ($status === 'succeeded') {
        $activated = ckm_quiz_pro_partner_tbank_activate_order($fresh);
        if (is_wp_error($activated)) {
            $wpdb->update(ckm_quiz_pro_partner_table('payment_orders'), [
                'access_status' => 'error',
                'last_error' => sanitize_text_field($activated->get_error_message()),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => (int)$order['id'], 'tenant_id' => (int)$order['tenant_id']]);
            return new WP_Error('access', 'Оплата подтверждена, но организатора не удалось подключить автоматически: ' . $activated->get_error_message());
        }
        return $activated;
    }
    return $fresh;
}

function ckm_quiz_pro_partner_tbank_sync_webhook_payload(int $tenantId, array $payload): array|WP_Error {
    $paymentId = sanitize_text_field((string)($payload['PaymentId'] ?? ''));
    $orderId = sanitize_text_field((string)($payload['OrderId'] ?? ''));
    if ($paymentId === '' && $orderId === '') return new WP_Error('payment', 'Т‑Банк не передал PaymentId или OrderId.');
    global $wpdb;
    $table = ckm_quiz_pro_partner_table('payment_orders');
    if ($paymentId !== '') {
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id=%d AND provider=%s AND tbank_payment_id=%s LIMIT 1', $tenantId, 'tbank', $paymentId), ARRAY_A);
    } else {
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id=%d AND provider=%s AND order_id=%s LIMIT 1', $tenantId, 'tbank', $orderId), ARRAY_A);
    }
    if (!is_array($order)) return new WP_Error('order', 'Счёт Т‑Банка для уведомления не найден.');
    if ($orderId !== '' && !hash_equals((string)$order['order_id'], $orderId)) return new WP_Error('order_mismatch', 'OrderId уведомления не совпадает со счётом.');
    return ckm_quiz_pro_partner_tbank_sync_order($order);
}

function ckm_quiz_pro_partner_tbank_create_payment(array $order): array|WP_Error {
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) return new WP_Error('provider', 'Провайдер Т‑Банка не загружен.');
    if ((string)($order['provider'] ?? '') !== 'tbank') return new WP_Error('provider', 'Этот счёт создан другим платёжным провайдером.');
    if ((string)($order['status'] ?? '') === 'succeeded' && (string)($order['access_status'] ?? '') === 'active') return $order;
    if (!empty($order['confirmation_url']) && !empty($order['tbank_payment_id']) && (string)$order['status'] === 'pending') return $order;

    $created = $provider->create_payment($order);
    if (is_wp_error($created)) return $created;
    $paymentId = sanitize_text_field((string)($created['payment_id'] ?? ''));
    $confirmation = esc_url_raw((string)($created['confirmation_url'] ?? ''), ['https']);
    if ($paymentId === '' || $confirmation === '') return new WP_Error('response', 'Т‑Банк не вернул идентификатор или ссылку на оплату.');
    $status = ckm_quiz_pro_partner_tbank_map_status((string)($created['status'] ?? 'NEW'));

    global $wpdb;
    $ok = $wpdb->update(ckm_quiz_pro_partner_table('payment_orders'), [
        'status' => $status,
        'tbank_payment_id' => $paymentId,
        'confirmation_url' => $confirmation,
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'last_error' => '',
    ], ['id' => (int)$order['id'], 'tenant_id' => (int)$order['tenant_id'], 'provider' => 'tbank']);
    if ($ok === false) return new WP_Error('database', 'Не удалось сохранить платёж Т‑Банка.');
    $fresh = ckm_quiz_pro_partner_tbank_order((int)$order['tenant_id'], (string)$order['order_id']);
    return $fresh ?: $order;
}

function ckm_quiz_pro_partner_tbank_public_route(): void {
    $path = rtrim((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== '/ckm-partner-pay') return;
    $orderId = sanitize_text_field((string)($_GET['order'] ?? $_POST['order'] ?? ''));
    $token = sanitize_text_field((string)($_GET['token'] ?? $_POST['token'] ?? ''));
    $order = ckm_quiz_pro_partner_tbank_order_public($orderId, $token);
    if (!$order || (int)$order['tenant_id'] !== (int)ckmqp_scope_id()) return;
    if ((string)($order['provider'] ?? '') !== 'tbank') return;

    $error = '';
    if (!empty($_GET['return']) && !empty($order['tbank_payment_id'])) {
        $sync = ckm_quiz_pro_partner_tbank_sync_order($order);
        if (is_wp_error($sync)) $error = $sync->get_error_message();
        else $order = $sync;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['start_payment'])) {
        if (!hash_equals((string)$order['public_token'], $token)) $error = 'Ссылка оплаты недействительна.';
        else {
            $created = ckm_quiz_pro_partner_tbank_create_payment($order);
            if (is_wp_error($created)) $error = $created->get_error_message();
            else {
                $order = $created;
                $confirmation = esc_url_raw((string)($order['confirmation_url'] ?? ''), ['https']);
                if ($confirmation !== '') {
                    wp_safe_redirect($confirmation, 302, 'CKM Partner T-Bank');
                    exit;
                }
                $error = 'Не удалось получить ссылку Т‑Банка.';
            }
        }
    }

    $tenantName = 'Партнёрская площадка';
    global $wpdb;
    $name = $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . ckmqp_tenant_table('tenants') . ' WHERE id=%d LIMIT 1', (int)$order['tenant_id']));
    if (is_string($name) && trim($name) !== '') $tenantName = $name;

    nocache_headers(); status_header(200);
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Оплата — ' . esc_html($tenantName) . '</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:#f5f7fb;color:#152033;margin:0}.wrap{max-width:720px;margin:8vh auto;padding:20px}.card{background:#fff;border:1px solid #dfe5ee;border-radius:18px;padding:26px;box-shadow:0 12px 40px rgba(28,44,70,.08)}h1{margin-top:0}.amount{font-size:28px;font-weight:800}.btn{display:inline-block;border:0;border-radius:12px;background:#111d2f;color:#fff;padding:13px 18px;font-weight:700;cursor:pointer}.ok{padding:12px;border-radius:10px;background:#eaf8ef;color:#195b31}.err{padding:12px;border-radius:10px;background:#fff0f0;color:#8b1d1d}.muted{color:#687386;font-size:14px}</style></head><body><div class="wrap"><div class="card">';
    echo '<p class="muted">' . esc_html($tenantName) . '</p><h1>Оплата доступа организатора</h1>';
    echo '<p>' . esc_html((string)$order['description']) . '</p><p class="amount">' . esc_html(number_format_i18n((int)$order['amount_minor'] / 100, 2)) . ' ₽</p>';
    echo '<p>Организатор: <strong>' . esc_html((string)$order['customer_name']) . '</strong><br>' . esc_html((string)$order['customer_email']) . '</p>';
    if ($error !== '') echo '<div class="err">' . esc_html($error) . '</div>';
    if ((string)$order['status'] === 'succeeded' && (string)$order['access_status'] === 'active') {
        echo '<div class="ok"><strong>Оплата подтверждена.</strong> Учётная запись организатора подключена к площадке. Данные для входа отправлены на email.</div>';
    } elseif ((string)$order['status'] === 'succeeded') {
        echo '<div class="err"><strong>Оплата подтверждена.</strong> Автоматическое подключение требует проверки владельца площадки.</div>';
    } elseif ((string)$order['status'] === 'canceled') {
        echo '<div class="err">Платёж отменён. Владелец площадки может выставить новый счёт.</div>';
    } else {
        echo '<form method="post"><input type="hidden" name="order" value="' . esc_attr($orderId) . '"><input type="hidden" name="token" value="' . esc_attr($token) . '"><button class="btn" name="start_payment" value="1">Перейти к оплате в Т‑Банк</button></form>';
        if (!empty($order['tbank_payment_id'])) echo '<p class="muted">Если вы уже оплатили, обновите эту страницу через несколько секунд.</p>';
    }
    echo '<p class="muted">Платёж принимает владелец этой площадки через собственный интернет‑эквайринг Т‑Банка. ЦКМ не получает реквизиты банковской карты покупателя.</p>';
    echo '</div></div></body></html>'; exit;
}

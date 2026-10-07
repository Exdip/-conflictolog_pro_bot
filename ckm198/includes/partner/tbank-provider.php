<?php
if (!defined('ABSPATH')) exit;

/**
 * T-Bank internet-acquiring provider for partner-owned client payments.
 *
 * This provider is used for partner-owned client payments.
 * Payments from a partner to the CKM platform itself remain on the platform
 * YooKassa flow in plan-checkout.php.
 */
final class CKM_Quiz_Pro_Partner_TBank_Provider implements CKM_Quiz_Pro_Partner_Payment_Provider_Interface {
    public function key(): string { return 'tbank'; }
    public function label(): string { return 'Т‑Банк'; }

    private function table(): string {
        return ckm_quiz_pro_partner_table('payment_methods');
    }

    private function crypto_key(): string {
        return hash('sha256', wp_salt('auth') . '|ckm-partner-payment-provider-v1', true);
    }

    private function encrypt_credentials(array $credentials): string|WP_Error {
        $plain = wp_json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($plain) || $plain === '') {
            return new WP_Error('crypto_encode', 'Не удалось подготовить реквизиты Т‑Банка.');
        }
        if (!function_exists('openssl_encrypt')) {
            return new WP_Error('crypto_unavailable', 'На сервере недоступно безопасное шифрование OpenSSL.');
        }
        try { $iv = random_bytes(12); }
        catch (Throwable $e) { return new WP_Error('crypto_random', 'Не удалось подготовить безопасное шифрование.'); }
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-tbank', 16);
        if (!is_string($cipher) || $cipher === '' || strlen($tag) !== 16) {
            return new WP_Error('crypto_failed', 'Не удалось зашифровать реквизиты Т‑Банка.');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    private function decrypt_credentials(string $stored): array|WP_Error {
        if ($stored === '' || !str_starts_with($stored, 'v1:') || !function_exists('openssl_decrypt')) {
            return new WP_Error('crypto_format', 'Реквизиты Т‑Банка сохранены в неподдерживаемом формате.');
        }
        $raw = base64_decode(substr($stored, 3), true);
        if (!is_string($raw) || strlen($raw) <= 28) {
            return new WP_Error('crypto_format', 'Не удалось прочитать реквизиты Т‑Банка.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, 'ckm-tbank');
        if (!is_string($plain) || $plain === '') {
            return new WP_Error('crypto_failed', 'Не удалось расшифровать реквизиты Т‑Банка.');
        }
        $credentials = json_decode($plain, true);
        if (!is_array($credentials)) return new WP_Error('crypto_json', 'Сохранённые реквизиты Т‑Банка повреждены.');
        return $credentials;
    }

    private function row(int $tenantId): array {
        if ($tenantId <= 0) return [];
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $this->table() . ' WHERE tenant_id=%d AND provider=%s LIMIT 1',
            $tenantId,
            $this->key()
        ), ARRAY_A);
        return is_array($row) ? $row : [];
    }

    private function settings(int $tenantId): array|WP_Error {
        $row = $this->row($tenantId);
        if (!$row) return [];
        $credentials = $this->decrypt_credentials((string)($row['credentials_cipher'] ?? ''));
        if (is_wp_error($credentials)) return $credentials;
        $row['_credentials'] = $credentials;
        return $row;
    }

    public function configured_terminal_key(int $tenantId): string {
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings) || !$settings) return '';
        $credentials = is_array($settings['_credentials'] ?? null) ? $settings['_credentials'] : [];
        return sanitize_text_field((string)($credentials['terminal_key'] ?? ''));
    }

    public function ready(int $tenantId): bool {
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings) || !$settings) return false;
        $c = is_array($settings['_credentials'] ?? null) ? $settings['_credentials'] : [];
        return (int)($settings['enabled'] ?? 0) === 1
            && trim((string)($c['terminal_key'] ?? '')) !== ''
            && trim((string)($c['password'] ?? '')) !== '';
    }

    public function save_settings(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
        if ($tenantId <= 0 || $ownerUserId <= 0 || !ckm_quiz_pro_partner_user_is_owner($tenantId, $ownerUserId)) {
            return new WP_Error('forbidden', 'Настройки Т‑Банка доступны только владельцу площадки.');
        }
        if (!ckm_quiz_pro_partner_partner_active($tenantId)) {
            return new WP_Error('subscription', 'Сначала активируйте партнёрский режим.');
        }

        $old = $this->row($tenantId);
        $oldCredentials = [];
        if ($old && !empty($old['credentials_cipher'])) {
            $decoded = $this->decrypt_credentials((string)$old['credentials_cipher']);
            if (is_wp_error($decoded)) return $decoded;
            $oldCredentials = $decoded;
        }

        $removeCredentials = !empty($input['remove_credentials']);
        $terminalKey = trim(sanitize_text_field((string)($input['terminal_key'] ?? '')));
        if (!$removeCredentials && $terminalKey === '') $terminalKey = (string)($oldCredentials['terminal_key'] ?? '');
        $password = trim((string)($input['password'] ?? ''));
        if (!$removeCredentials && $password === '') $password = (string)($oldCredentials['password'] ?? '');

        $mode = sanitize_key((string)($input['mode'] ?? 'test'));
        if (!in_array($mode, ['test', 'live'], true)) $mode = 'test';
        $enabled = !empty($input['enabled']) ? 1 : 0;

        if ($removeCredentials) {
            $enabled = 0;
            $password = '';
            $terminalKey = '';
        } else {
            if ($terminalKey === '' || strlen($terminalKey) < 4 || strlen($terminalKey) > 64) {
                return new WP_Error('terminal_key', 'Укажите корректный TerminalKey Т‑Банка.');
            }
            if ($password === '' || strlen($password) > 255) {
                return new WP_Error('password', 'Укажите пароль терминала Т‑Банка.');
            }
        }

        if ($terminalKey === '' || $password === '') {
            $cipher = '';
            $hint = '';
        } else {
            $enc = $this->encrypt_credentials([
                'terminal_key' => $terminalKey,
                'password' => $password,
            ]);
            if (is_wp_error($enc)) return $enc;
            $cipher = $enc;
            $hint = substr($terminalKey, -4);
        }

        $webhookSecret = (string)($old['webhook_secret_cipher'] ?? '');
        if ($webhookSecret === '') {
            try {
                $webhookSecret = $this->encrypt_credentials(['token' => bin2hex(random_bytes(32))]);
            } catch (Throwable $e) {
                return new WP_Error('random', 'Не удалось создать защищённый адрес уведомлений Т‑Банка.');
            }
            if (is_wp_error($webhookSecret)) return $webhookSecret;
        }

        $now = gmdate('Y-m-d H:i:s');
        $data = [
            'tenant_id' => $tenantId,
            'owner_user_id' => $ownerUserId,
            'provider' => $this->key(),
            'enabled' => $enabled,
            'is_default' => !empty($input['is_default']) ? 1 : 0,
            'credentials_cipher' => $cipher,
            'credentials_hint' => $hint,
            'mode' => $mode,
            'account_status' => '',
            'webhook_secret_cipher' => $webhookSecret,
            'updated_at' => $now,
        ];

        global $wpdb;
        if ($old) {
            $ok = $wpdb->update($this->table(), $data, ['id' => (int)$old['id']]);
        } else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($this->table(), $data);
        }
        if ($ok === false) return new WP_Error('database', 'Не удалось сохранить настройки Т‑Банка.');
        return $this->row($tenantId);
    }

    private function token(array $params, string $password): string {
        $scalar = [];
        foreach ($params as $key => $value) {
            if ($key === 'Token' || is_array($value) || is_object($value) || $value === null) continue;
            if (is_bool($value)) $value = $value ? 'true' : 'false';
            $scalar[(string)$key] = (string)$value;
        }
        $scalar['Password'] = $password;
        ksort($scalar, SORT_STRING);
        return hash('sha256', implode('', array_values($scalar)));
    }

    private function api(string $path, array $body, string $password): array|WP_Error {
        $body['Token'] = $this->token($body, $password);
        $response = wp_remote_post('https://securepay.tinkoff.ru/v2/' . ltrim($path, '/'), [
            'timeout' => 25,
            'redirection' => 0,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($response)) return new WP_Error('network', 'Т‑Банк не ответил. Повторите попытку позже.');
        $code = (int)wp_remote_retrieve_response_code($response);
        $json = json_decode((string)wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            return new WP_Error('tbank', 'Т‑Банк отклонил запрос (HTTP ' . $code . ').');
        }
        return $json;
    }

    public function test_connection(int $tenantId): array|WP_Error {
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings)) return $settings;
        if (!$settings) return new WP_Error('settings', 'Т‑Банк для этой площадки не настроен.');
        $credentials = $settings['_credentials'] ?? [];
        $terminalKey = trim((string)($credentials['terminal_key'] ?? ''));
        $password = (string)($credentials['password'] ?? '');
        if ($terminalKey === '' || $password === '') return new WP_Error('settings', 'Укажите TerminalKey и пароль терминала Т‑Банка.');

        try { $probeOrder = 'CKMTEST-' . strtoupper(bin2hex(random_bytes(8))); }
        catch (Throwable $e) { return new WP_Error('random', 'Не удалось сформировать безопасную проверочную операцию.'); }

        // CheckOrder does not create a payment. A valid terminal/password pair
        // should produce an API response even when the synthetic order is absent.
        $result = $this->api('CheckOrder', [
            'TerminalKey' => $terminalKey,
            'OrderId' => $probeOrder,
        ], $password);
        if (is_wp_error($result)) return $result;

        $errorCode = (string)($result['ErrorCode'] ?? '');
        if (in_array($errorCode, ['204', '205'], true)) {
            return new WP_Error('credentials', 'Т‑Банк отклонил подпись. Проверьте TerminalKey и пароль терминала.');
        }

        global $wpdb;
        $wpdb->update($this->table(), [
            'account_status' => 'reachable',
            'last_checked_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['tenant_id' => $tenantId, 'provider' => $this->key()]);

        return [
            'reachable' => true,
            'authenticated' => true,
            'probe_order' => $probeOrder,
            'error_code' => $errorCode,
            'message' => sanitize_text_field((string)($result['Message'] ?? '')),
            'details' => sanitize_text_field((string)($result['Details'] ?? '')),
        ];
    }

    public function webhook_url(int $tenantId): string {
        $settings = $this->row($tenantId);
        if (!$settings || empty($settings['webhook_secret_cipher'])) return '';
        $secret = $this->decrypt_credentials((string)$settings['webhook_secret_cipher']);
        if (is_wp_error($secret)) return '';
        $token = sanitize_text_field((string)($secret['token'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return '';
        return rest_url('ckm-partner/v1/tbank/' . $tenantId . '/' . $token);
    }

    public function create_order(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
        if (!ckm_quiz_pro_partner_partner_active($tenantId)) return new WP_Error('subscription', 'Партнёрский режим площадки сейчас не активен.');
        if (!ckm_quiz_pro_partner_user_is_owner($tenantId, $ownerUserId)) return new WP_Error('forbidden', 'Только владелец площадки может выставлять счета.');
        if (!$this->ready($tenantId)) return new WP_Error('settings', 'Сначала подключите и включите Т‑Банк.');

        $login = sanitize_user((string)($input['client_login'] ?? ''), true);
        $email = sanitize_email((string)($input['client_email'] ?? ''));
        $name = sanitize_text_field((string)($input['client_name'] ?? ''));
        if ($name === '') $name = $login;
        if ($login === '' || strlen($login) > 60 || !validate_username($login)) return new WP_Error('login', 'Укажите корректный логин организатора.');
        if (!is_email($email)) return new WP_Error('email', 'Укажите корректный email организатора.');
        if (function_exists('ckm_quiz_pro_partner_yk_amount_minor')) {
            $minor = ckm_quiz_pro_partner_yk_amount_minor((string)($input['amount_rub'] ?? ''));
            if (is_wp_error($minor)) return $minor;
        } else {
            return new WP_Error('amount', 'Модуль расчёта суммы не загружен.');
        }
        try { $orderId = wp_generate_uuid4(); }
        catch (Throwable $e) { return new WP_Error('random', 'Не удалось создать идентификатор заказа.'); }
        $description = sanitize_text_field((string)($input['description'] ?? 'Доступ организатора к игровой площадке'));
        if (function_exists('mb_substr')) $description = mb_substr($description, 0, 140, 'UTF-8'); else $description = substr($description, 0, 140);

        return [
            'provider' => $this->key(),
            'tenant_id' => $tenantId,
            'owner_user_id' => $ownerUserId,
            'order_id' => $orderId,
            'customer_name' => $name,
            'customer_login' => $login,
            'customer_email' => $email,
            'amount_minor' => $minor,
            'currency' => 'RUB',
            'description' => $description,
            'status' => 'created',
        ];
    }

    public function create_payment(array $order): array|WP_Error {
        $tenantId = (int)($order['tenant_id'] ?? 0);
        if ($tenantId <= 0 || !$this->ready($tenantId)) return new WP_Error('settings', 'Т‑Банк площадки временно отключён.');
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings)) return $settings;
        $credentials = $settings['_credentials'] ?? [];
        $terminalKey = (string)($credentials['terminal_key'] ?? '');
        $password = (string)($credentials['password'] ?? '');
        $amount = (int)($order['amount_minor'] ?? 0);
        if ($amount < 100) return new WP_Error('amount', 'Сумма платежа должна быть не меньше 1 ₽.');
        $orderId = sanitize_text_field((string)($order['order_id'] ?? ''));
        if ($orderId === '' || strlen($orderId) > 50) return new WP_Error('order', 'Некорректный OrderId Т‑Банка.');

        $body = [
            'TerminalKey' => $terminalKey,
            'Amount' => $amount,
            'OrderId' => $orderId,
            'Description' => function_exists('mb_substr') ? mb_substr((string)($order['description'] ?? 'Оплата'), 0, 140, 'UTF-8') : substr((string)($order['description'] ?? 'Оплата'), 0, 140),
            'Language' => 'ru',
        ];
        $webhook = $this->webhook_url($tenantId);
        if ($webhook !== '') $body['NotificationURL'] = $webhook;
        $email = sanitize_email((string)($order['customer_email'] ?? ''));
        if ($email !== '') $body['DATA'] = ['Email' => $email];

        $remote = $this->api('Init', $body, $password);
        if (is_wp_error($remote)) return $remote;
        if (empty($remote['Success'])) {
            $code = sanitize_text_field((string)($remote['ErrorCode'] ?? ''));
            $message = sanitize_text_field((string)($remote['Message'] ?? ''));
            return new WP_Error('tbank_payment', 'Т‑Банк не создал платёж' . ($code !== '' ? ' (код ' . $code . ')' : '') . ($message !== '' ? ': ' . $message : '.'));
        }
        $paymentId = sanitize_text_field((string)($remote['PaymentId'] ?? ''));
        $paymentUrl = esc_url_raw((string)($remote['PaymentURL'] ?? ''), ['https']);
        if ($paymentId === '' || $paymentUrl === '') return new WP_Error('response', 'Т‑Банк не вернул идентификатор или ссылку на оплату.');

        return [
            'provider' => $this->key(),
            'payment_id' => $paymentId,
            'confirmation_url' => $paymentUrl,
            'status' => sanitize_key((string)($remote['Status'] ?? 'NEW')),
            'amount_minor' => $amount,
            'raw' => $remote,
        ];
    }

    public function settings_for_ui(int $tenantId): array {
        $row = $this->row($tenantId);
        if (!$row) return [];
        unset($row['credentials_cipher']);
        return $row;
    }

    public function webhook_token(int $tenantId): string {
        $row = $this->row($tenantId);
        if (!$row || empty($row['webhook_secret_cipher'])) return '';
        $secret = $this->decrypt_credentials((string)$row['webhook_secret_cipher']);
        if (is_wp_error($secret)) return '';
        $token = sanitize_text_field((string)($secret['token'] ?? ''));
        return preg_match('/^[a-f0-9]{64}$/D', $token) ? $token : '';
    }

    public function verify_webhook(int $tenantId, array $payload): bool {
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings) || !$settings) return false;
        $credentials = $settings['_credentials'] ?? [];
        $password = (string)($credentials['password'] ?? '');
        $given = sanitize_text_field((string)($payload['Token'] ?? ''));
        if ($password === '' || $given === '') return false;
        $expected = $this->token($payload, $password);
        return hash_equals($expected, $given);
    }

    public function sync_order(array $order): array|WP_Error {
        $tenantId = (int)($order['tenant_id'] ?? 0);
        $paymentId = sanitize_text_field((string)($order['tbank_payment_id'] ?? $order['payment_id'] ?? ''));
        if ($tenantId <= 0 || $paymentId === '') return $order;
        $settings = $this->settings($tenantId);
        if (is_wp_error($settings)) return $settings;
        $credentials = $settings['_credentials'] ?? [];
        $terminalKey = (string)($credentials['terminal_key'] ?? '');
        $password = (string)($credentials['password'] ?? '');
        if ($terminalKey === '' || $password === '') return new WP_Error('settings', 'Т‑Банк для этой площадки не настроен.');

        $remote = $this->api('GetState', [
            'TerminalKey' => $terminalKey,
            'PaymentId' => $paymentId,
        ], $password);
        if (is_wp_error($remote)) return $remote;
        $order['tbank_payment_id'] = $paymentId;
        $order['status'] = sanitize_key((string)($remote['Status'] ?? ($order['status'] ?? '')));
        $order['provider_response'] = $remote;
        return $order;
    }
}

function ckm_quiz_pro_partner_tbank_handle_owner_action(int $tenantId, int $ownerUserId, string $action): array {
    $result = ['notice' => '', 'error' => ''];
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) {
        $result['error'] = 'Провайдер Т‑Банка не загружен.';
        return $result;
    }
    if ($action === 'save_tbank') {
        $saved = $provider->save_settings($tenantId, $ownerUserId, [
            'terminal_key' => wp_unslash($_POST['tbank_terminal_key'] ?? ''),
            'password' => wp_unslash($_POST['tbank_password'] ?? ''),
            'mode' => wp_unslash($_POST['tbank_mode'] ?? 'test'),
            'enabled' => !empty($_POST['tbank_enabled']),
            'remove_credentials' => !empty($_POST['tbank_remove_credentials']),
        ]);
        if (is_wp_error($saved)) $result['error'] = $saved->get_error_message();
        else $result['notice'] = 'Настройки Т‑Банка сохранены. Пароль терминала хранится в зашифрованном виде.';
    } elseif ($action === 'test_tbank') {
        $test = $provider->test_connection($tenantId);
        if (is_wp_error($test)) $result['error'] = $test->get_error_message();
        else $result['notice'] = 'Соединение с API Т‑Банка подтверждено. Проверочный заказ не создавал платёж.';
    } elseif ($action === 'create_payment_request') {
        $order = ckm_quiz_pro_partner_tbank_create_order($tenantId, $ownerUserId, [
            'client_name' => wp_unslash($_POST['client_name'] ?? ''),
            'client_login' => wp_unslash($_POST['client_login'] ?? ''),
            'client_email' => wp_unslash($_POST['client_email'] ?? ''),
            'amount_rub' => wp_unslash($_POST['amount_rub'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
        ]);
        if (is_wp_error($order)) $result['error'] = $order->get_error_message();
        else {
            $url = ckm_quiz_pro_partner_tbank_order_url($order);
            $result['notice'] = 'Счёт создан через Т‑Банк. Ссылка отправлена клиенту на email.' . ($url !== '' ? ' Ссылка: ' . $url : '');
        }
    }
    return $result;
}

function ckm_quiz_pro_partner_tbank_render_owner_section(int $tenantId): void {
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) return;
    $row = $provider->settings_for_ui($tenantId);
    $ready = $provider->ready($tenantId);
    $mode = (string)($row['mode'] ?? 'test');
    $hint = (string)($row['credentials_hint'] ?? '');
    $webhook = $provider->webhook_url($tenantId);

    echo '<hr style="margin:30px 0"><div class="ckm-kicker">ДОПОЛНИТЕЛЬНЫЙ ЭКВАЙРИНГ</div><h2>Т‑Банк</h2>';
    echo '<p>Подключите свой интернет‑эквайринг Т‑Банка для расчётов с вашими организаторами. Именно через этот эквайринг партнёр выставляет счета своим клиентам и получает оплату напрямую на свой счёт.</p>';
    echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
    echo '<input type="hidden" name="partner_action" value="save_tbank">';
    echo '<label class="ckm-label">TerminalKey<input class="ckm-input" name="tbank_terminal_key" maxlength="64" value="" placeholder="' . esc_attr($hint !== '' ? 'Сохранён ····' . $hint . ' — оставьте пустым, чтобы не менять' : 'Например, TinkoffBankTest') . '"></label>';
    echo '<label class="ckm-label">Пароль терминала<input class="ckm-input" type="password" name="tbank_password" autocomplete="new-password" maxlength="255" placeholder="' . ($hint !== '' ? 'Сохранён — оставьте пустым, чтобы не менять' : 'Введите пароль терминала') . '"></label>';
    echo '<label class="ckm-label">Режим<select class="ckm-input" name="tbank_mode"><option value="test"' . selected($mode, 'test', false) . '>Тестовый терминал</option><option value="live"' . selected($mode, 'live', false) . '>Боевой терминал</option></select></label>';
    echo '<label><input type="checkbox" name="tbank_enabled" value="1"' . checked((int)($row['enabled'] ?? 0), 1, false) . '> Разрешить использование Т‑Банка</label>';
    if ($hint !== '') echo '<br><label><input type="checkbox" name="tbank_remove_credentials" value="1"> Удалить сохранённые реквизиты и отключить Т‑Банк</label>';
    echo '<p><button class="ckm-btn ckm-btn-primary">Сохранить Т‑Банк</button></p></form>';
    if ($row) {
        echo '<form method="post" style="display:inline-block;margin-right:8px">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
        echo '<input type="hidden" name="partner_action" value="test_tbank"><button class="ckm-btn">Проверить подключение</button></form>';
    }
    echo '<p class="ckm-muted">Статус: <strong>' . ($ready ? 'подключён' : 'не готов к приёму платежей') . '</strong>';
    if (!empty($row['last_checked_at'])) echo ' · API проверен ' . esc_html(wp_date('d.m.Y H:i', strtotime((string)$row['last_checked_at'] . ' UTC')));
    echo '.</p>';
    if ($webhook !== '') echo '<div class="ckm-alert"><strong>Webhook Т‑Банка</strong><br><code style="word-break:break-all">' . esc_html($webhook) . '</code><br><span class="ckm-muted">Этот адрес можно указать в настройках уведомлений интернет‑эквайринга Т‑Банка.</span></div>';

    echo '<h2>Выставить счёт организатору</h2>';
    echo '<p>После успешной оплаты через Т‑Банк система автоматически создаст или подключит аккаунт организатора к этой площадке.</p>';
    if (!$ready) {
        echo '<p class="ckm-alert ckm-alert-error">Сначала сохраните TerminalKey и пароль Т‑Банка и включите приём платежей.</p>';
    } else {
        echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_partner_partner_front');
        echo '<input type="hidden" name="partner_action" value="create_payment_request">';
        echo '<div class="ckm-form-grid"><label class="ckm-label">Имя<input class="ckm-input" name="client_name" maxlength="190" required></label><label class="ckm-label">Логин<input class="ckm-input" name="client_login" maxlength="60" required></label></div>';
        echo '<div class="ckm-form-grid"><label class="ckm-label">Email<input class="ckm-input" type="email" name="client_email" maxlength="190" required></label><label class="ckm-label">Сумма, ₽<input class="ckm-input" name="amount_rub" inputmode="decimal" placeholder="1500.00" required></label></div>';
        echo '<label class="ckm-label">Назначение платежа<input class="ckm-input" name="description" maxlength="140" value="Доступ организатора к игровой площадке"></label>';
        echo '<p><button class="ckm-btn ckm-btn-primary">Создать ссылку на оплату через Т‑Банк</button></p></form>';
    }

    global $wpdb;
    $orders = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . ckm_quiz_pro_partner_table('payment_orders') . ' WHERE tenant_id=%d AND provider=%s ORDER BY id DESC LIMIT %d',
        $tenantId, 'tbank', 20
    ), ARRAY_A) ?: [];
    echo '<h2>Последние счета Т‑Банка</h2><table class="widefat"><thead><tr><th>Клиент</th><th>Сумма</th><th>Платёж</th><th>Доступ</th><th>Ссылка</th></tr></thead><tbody>';
    foreach ($orders as $order) {
        $url = function_exists('ckm_quiz_pro_partner_tbank_order_url') ? ckm_quiz_pro_partner_tbank_order_url($order) : '';
        echo '<tr><td><strong>' . esc_html((string)$order['customer_name']) . '</strong><br>' . esc_html((string)$order['customer_email']) . '</td><td>' . esc_html(number_format_i18n((int)$order['amount_minor'] / 100, 2)) . ' ₽</td><td>' . esc_html((string)$order['status']) . '</td><td>' . esc_html((string)$order['access_status']) . '</td><td>' . ($url !== '' ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Открыть</a>' : '—') . '</td></tr>';
        if (!empty($order['last_error'])) echo '<tr><td colspan="5" class="ckm-muted">' . esc_html((string)$order['last_error']) . '</td></tr>';
    }
    if (!$orders) echo '<tr><td colspan="5">Счетов пока нет.</td></tr>';
    echo '</tbody></table>';
}

function ckm_quiz_pro_partner_tbank_register_routes(): void {
    register_rest_route('ckm-partner/v1', '/tbank/(?P<tenant>\d+)/(?P<token>[a-f0-9]{64})', [
        'methods' => 'POST',
        'callback' => 'ckm_quiz_pro_partner_tbank_webhook',
        'permission_callback' => '__return_true',
    ]);
}

function ckm_quiz_pro_partner_tbank_webhook(WP_REST_Request $request): WP_REST_Response {
    $tenantId = (int)$request['tenant'];
    $token = (string)$request['token'];
    $provider = ckm_quiz_pro_partner_payment_provider('tbank');
    if (!$provider || !($provider instanceof CKM_Quiz_Pro_Partner_TBank_Provider)) return new WP_REST_Response('NOT_FOUND', 404);
    $row = $provider->settings_for_ui($tenantId);
    $secret = $provider->webhook_token($tenantId);
    if ($secret === '' || !hash_equals($secret, $token)) return new WP_REST_Response('NOT_FOUND', 404);
    $body = $request->get_json_params();
    if (!is_array($body) || !$provider->verify_webhook($tenantId, $body)) return new WP_REST_Response('INVALID', 400);
    if (function_exists('ckm_quiz_pro_partner_tbank_sync_webhook_payload')) {
        $result = ckm_quiz_pro_partner_tbank_sync_webhook_payload($tenantId, $body);
        if (is_wp_error($result)) return new WP_REST_Response(['ok'=>false,'error'=>$result->get_error_code()], 409);
    }
    return new WP_REST_Response('OK', 200);
}


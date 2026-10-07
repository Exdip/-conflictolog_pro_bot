<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_valid_owner_id(int $tenantId): int {
    if ($tenantId <= 0) return 0;
    global $wpdb;
    $members = ckmqp_tenant_table('members');
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT m.user_id
         FROM $members m
         INNER JOIN {$wpdb->users} u ON u.ID=m.user_id
         WHERE m.tenant_id=%d AND m.role='owner' AND m.status='active'
         ORDER BY m.id LIMIT 1",
        $tenantId
    ));
}

function ckm_quiz_pro_partner_user_is_owner(int $tenantId, int $userId): bool {
    if ($tenantId <= 0 || $userId <= 0) return false;
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT m.id
         FROM " . ckmqp_tenant_table('members') . " m
         INNER JOIN {$wpdb->users} u ON u.ID=m.user_id
         WHERE m.tenant_id=%d AND m.user_id=%d AND m.role='owner' AND m.status='active'
         LIMIT 1",
        $tenantId,
        $userId
    ));
}

function ckm_quiz_pro_partner_user_can_activate(int $tenantId, int $userId): bool {
    if ($tenantId <= 0 || $userId <= 0) return false;
    if (current_user_can('manage_options')) return true;
    if (ckm_quiz_pro_partner_user_is_owner($tenantId, $userId)) return true;
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT m.id FROM " . ckmqp_tenant_table('members') . " m
         WHERE m.tenant_id=%d AND m.user_id=%d AND m.role='organizer' AND m.status='active'
         LIMIT 1",
        $tenantId, $userId
    ));
}

function ckm_quiz_pro_partner_subscription(int $tenantId, bool $activeOnly = false): ?array {
    if ($tenantId <= 0) return null;
    ckm_quiz_pro_partner_install_schema();
    global $wpdb;
    $table = ckm_quiz_pro_partner_table('subscriptions');
    $sql = "SELECT * FROM $table WHERE tenant_id=%d";
    if ($activeOnly) $sql .= " AND status='active' AND expires_at>UTC_TIMESTAMP()";
    $sql .= ' LIMIT 1';
    $row = $wpdb->get_row($wpdb->prepare($sql, $tenantId), ARRAY_A);
    return is_array($row) ? $row : null;
}

function ckm_quiz_pro_partner_partner_active(int $tenantId): bool {
    return ckm_quiz_pro_partner_subscription($tenantId, true) !== null;
}

function ckm_quiz_pro_partner_period(array $sub, ?int $now = null): array {
    $now = $now ?: time();
    $start = strtotime((string)($sub['started_at'] ?? '') . ' UTC');
    if ($start === false || $start <= 0) $start = $now;

    $bucket = max(0, (int)floor(max(0, $now - $start) / (30 * DAY_IN_SECONDS)));
    $from = $start + $bucket * 30 * DAY_IN_SECONDS;
    $to = $from + 30 * DAY_IN_SECONDS;
    $expiry = strtotime((string)($sub['expires_at'] ?? '') . ' UTC');
    if ($expiry !== false && $expiry > 0) $to = min($to, $expiry);

    return [
        'key' => 'partner:' . (int)($sub['id'] ?? 0) . ':' . $bucket,
        'from' => $from,
        'to' => $to,
        'from_mysql' => gmdate('Y-m-d H:i:s', $from),
        'to_mysql' => gmdate('Y-m-d H:i:s', $to),
    ];
}

function ckm_quiz_pro_partner_grant_base_access(array $sub): array|WP_Error {
    global $wpdb;
    $access = ckm_quiz_pro_table('game_access');
    $grants = ckm_quiz_pro_partner_table('grants');
    $tenantId = (int)$sub['tenant_id'];
    $ownerId = (int)$sub['owner_user_id'];
    $subscriptionId = (int)$sub['id'];
    $started = (string)$sub['started_at'];
    $expires = (string)$sub['expires_at'];
    $now = gmdate('Y-m-d H:i:s');

    foreach (ckm_quiz_pro_partner_base_product_keys() as $key) {
        $grant = $wpdb->get_row($wpdb->prepare(
            "SELECT id,access_id FROM $grants WHERE subscription_id=%d AND format_key=%s LIMIT 1",
            $subscriptionId,
            $key
        ), ARRAY_A);

        if ($grant) {
            $accessExists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $access WHERE id=%d LIMIT 1",
                (int)$grant['access_id']
            ));
            if ($accessExists > 0) {
                $ok = $wpdb->update($access, [
                    'status' => 'active',
                    'expires_at' => $expires,
                    'updated_at' => $now,
                ], ['id' => (int)$grant['access_id']]);
                if ($ok === false) return new WP_Error('database', 'Не удалось продлить партнёрский доступ к базовым играм.');
                $wpdb->update($grants, ['updated_at' => $now], ['id' => (int)$grant['id']]);
                continue;
            }
            $wpdb->delete($grants, ['id' => (int)$grant['id']]);
        }

        $ok = $wpdb->insert($access, [
            'tenant_id' => $tenantId,
            'user_id' => $ownerId,
            'format_key' => $key,
            'started_at' => $started,
            'expires_at' => $expires,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) return new WP_Error('database', 'Не удалось выдать базовые игры партнёру.');

        $accessId = (int)$wpdb->insert_id;
        $ok = $wpdb->insert($grants, [
            'subscription_id' => $subscriptionId,
            'tenant_id' => $tenantId,
            'access_id' => $accessId,
            'format_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($ok === false) return new WP_Error('database', 'Не удалось зафиксировать партнёрское право доступа.');
    }

    return ['ok' => true];
}

function ckm_quiz_pro_partner_activate(int $tenantId, string $planKey, string $paymentRef = '', ?int $priceMinor = null, ?int $sessionLimit = null): array|WP_Error {
    if ($tenantId <= 0) return new WP_Error('tenant', 'Площадка не найдена.');
    $plans = ckm_quiz_pro_partner_plan_settings();
    if (!isset($plans[$planKey])) return new WP_Error('plan', 'Неизвестный партнёрский тариф.');

    $ownerId = ckm_quiz_pro_partner_valid_owner_id($tenantId);
    if ($ownerId <= 0) {
        return new WP_Error('owner', 'У площадки нет действующего владельца WordPress. Сначала восстановите или назначьте владельца.');
    }

    global $wpdb;
    $table = ckm_quiz_pro_partner_table('subscriptions');
    $existing = ckm_quiz_pro_partner_subscription($tenantId, false);
    $paymentRef = sanitize_text_field($paymentRef);
    if ($paymentRef !== '' && $existing && hash_equals((string)($existing['last_payment_ref'] ?? ''), $paymentRef)) {
        return $existing;
    }
    $nowTs = time();
    $started = gmdate('Y-m-d H:i:s', $nowTs);
    $expires = gmdate('Y-m-d H:i:s', $nowTs + 30 * DAY_IN_SECONDS);
    $created = $started;
    $extra = 0;
    $extraKey = '';

    if ($existing) {
        $created = (string)$existing['created_at'];
        $oldExpiry = strtotime((string)$existing['expires_at'] . ' UTC');
        if ((string)$existing['status'] === 'active' && $oldExpiry !== false && $oldExpiry > $nowTs) {
            $started = (string)$existing['started_at'];
            $expires = gmdate('Y-m-d H:i:s', $oldExpiry + 30 * DAY_IN_SECONDS);
            $extra = (int)$existing['extra_sessions'];
            $extraKey = (string)$existing['extra_period_key'];
        }
    }

    $row = [
        'tenant_id' => $tenantId,
        'owner_user_id' => $ownerId,
        'plan_key' => $planKey,
        'session_limit' => $sessionLimit !== null ? max(1, $sessionLimit) : (int)$plans[$planKey]['limit'],
        'extra_sessions' => $extra,
        'extra_period_key' => $extraKey,
        'price_minor' => $priceMinor !== null ? max(0, $priceMinor) : (int)$plans[$planKey]['price_rub'] * 100,
        'last_payment_ref' => $paymentRef !== '' ? $paymentRef : (string)($existing['last_payment_ref'] ?? ''),
        'status' => 'active',
        'started_at' => $started,
        'expires_at' => $expires,
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ];

    $wpdb->query('START TRANSACTION');
    try {
        if ($existing) {
            $ok = $wpdb->update($table, $row, ['id' => (int)$existing['id']]);
        } else {
            $row['created_at'] = $created;
            $ok = $wpdb->insert($table, $row);
        }
        if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'subscription_update_failed');

        $sub = ckm_quiz_pro_partner_subscription($tenantId, false);
        if (!$sub) throw new RuntimeException('subscription_read_failed');
        $grant = ckm_quiz_pro_partner_grant_base_access($sub);
        if (is_wp_error($grant)) throw new RuntimeException($grant->get_error_message());

        if ($wpdb->query('COMMIT') === false) throw new RuntimeException('commit_failed');
        if (function_exists('ckm_quiz_pro_partner_clear_request')) ckm_quiz_pro_partner_clear_request($tenantId);
        return $sub;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('database', 'Не удалось активировать партнёрский режим: ' . $e->getMessage());
    }
}

function ckm_quiz_pro_partner_add_extra(int $tenantId, int $amount): array|WP_Error {
    $amount = max(1, min(1000, $amount));
    $sub = ckm_quiz_pro_partner_subscription($tenantId, true);
    if (!$sub) return new WP_Error('subscription', 'Активный партнёрский режим не найден.');

    $period = ckm_quiz_pro_partner_period($sub);
    $current = (string)$sub['extra_period_key'] === $period['key'] ? (int)$sub['extra_sessions'] : 0;
    global $wpdb;
    $ok = $wpdb->update(ckm_quiz_pro_partner_table('subscriptions'), [
        'extra_sessions' => $current + $amount,
        'extra_period_key' => $period['key'],
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ], ['id' => (int)$sub['id']]);
    if ($ok === false) return new WP_Error('database', 'Не удалось добавить дополнительные запуски.');
    return ckm_quiz_pro_partner_subscription($tenantId, false) ?: $sub;
}

function ckm_quiz_pro_partner_deactivate(int $tenantId): bool {
    $sub = ckm_quiz_pro_partner_subscription($tenantId, false);
    if (!$sub) return true;

    global $wpdb;
    $subscriptions = ckm_quiz_pro_partner_table('subscriptions');
    $grants = ckm_quiz_pro_partner_table('grants');
    $access = ckm_quiz_pro_table('game_access');
    $now = gmdate('Y-m-d H:i:s');

    $wpdb->query('START TRANSACTION');
    try {
        $ok = $wpdb->update($subscriptions, [
            'status' => 'inactive',
            'updated_at' => $now,
        ], ['id' => (int)$sub['id']]);
        if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'subscription_deactivate_failed');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT access_id FROM $grants WHERE subscription_id=%d",
            (int)$sub['id']
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $ok = $wpdb->update($access, [
                'status' => 'inactive',
                'expires_at' => $now,
                'updated_at' => $now,
            ], ['id' => (int)$row['access_id']]);
            if ($ok === false) throw new RuntimeException($wpdb->last_error ?: 'grant_deactivate_failed');
        }

        if ($wpdb->query('COMMIT') === false) throw new RuntimeException('commit_failed');
        return true;
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('CKM Partner Rental deactivate failed: ' . $e->getMessage());
        return false;
    }
}

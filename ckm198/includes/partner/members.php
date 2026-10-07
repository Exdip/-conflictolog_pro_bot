<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_member_create(int $tenantId, array $data): array|WP_Error {
    if (!ckm_quiz_pro_partner_partner_active($tenantId)) return new WP_Error('subscription', 'Партнёрский режим не активна.');

    $login = sanitize_user((string)($data['login'] ?? ''), true);
    $email = sanitize_email((string)($data['email'] ?? ''));
    $name = sanitize_text_field((string)($data['name'] ?? ''));
    if ($login === '' || strlen($login) > 60 || !validate_username($login)) return new WP_Error('login', 'Укажите корректный логин организатора.');
    if (!is_email($email)) return new WP_Error('email', 'Укажите корректный email.');
    if ($name === '') $name = $login;

    $emailUid = (int)email_exists($email);
    $loginUid = (int)username_exists($login);
    if ($emailUid > 0 && $loginUid > 0 && $emailUid !== $loginUid) {
        return new WP_Error('account_mismatch', 'Указанные логин и email принадлежат разным существующим аккаунтам.');
    }

    $uid = $emailUid ?: $loginUid;
    $created = false;
    if ($uid > 0) {
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('account', 'Существующий аккаунт не найден.');
        if (strcasecmp((string)$user->user_email, $email) !== 0 || (string)$user->user_login !== $login) {
            return new WP_Error('account_mismatch', 'Для существующего аккаунта укажите его точные логин и email.');
        }
        $cap = defined('CKM_QUIZ_PRO_ORGANIZER_CAP') ? CKM_QUIZ_PRO_ORGANIZER_CAP : 'ckm_quiz_organize';
        if (!user_can($uid, $cap) && !user_can($uid, 'manage_options')) {
            return new WP_Error('role', 'Этот аккаунт не имеет прав организатора.');
        }
    } else {
        $password = wp_generate_password(24, true, true);
        $role = defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') ? CKM_QUIZ_PRO_ORGANIZER_ROLE : 'subscriber';
        $new = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => $name,
            'user_pass' => $password,
            'role' => $role,
        ]);
        if (is_wp_error($new)) return $new;
        $uid = (int)$new;
        $created = true;
        if (function_exists('wp_new_user_notification')) wp_new_user_notification($uid, null, 'user');
    }

    global $wpdb;
    $members = ckmqp_tenant_table('members');
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id,status,role FROM $members WHERE tenant_id=%d AND user_id=%d LIMIT 1",
        $tenantId,
        $uid
    ), ARRAY_A);
    $now = gmdate('Y-m-d H:i:s');

    if ($existing && (string)$existing['role'] === 'owner') {
        return new WP_Error('owner', 'Владелец площадки не может быть преобразован в дочернего организатора.');
    }

    if ($existing) {
        $ok = $wpdb->update($members, ['role' => 'organizer', 'status' => 'active'], ['id' => (int)$existing['id']]);
    } else {
        $ok = $wpdb->insert($members, [
            'tenant_id' => $tenantId,
            'user_id' => $uid,
            'role' => 'organizer',
            'status' => 'active',
            'created_at' => $now,
        ]);
    }

    if ($ok === false) {
        if ($created) {
            if (!function_exists('wp_delete_user')) require_once ABSPATH . 'wp-admin/includes/user.php';
            if (function_exists('wp_delete_user')) wp_delete_user($uid);
        }
        return new WP_Error('database', 'Не удалось добавить организатора на партнёрскую площадку.');
    }

    return ['user_id' => $uid, 'created' => $created];
}

function ckm_quiz_pro_partner_member_deactivate(int $tenantId, int $memberId): bool {
    if ($tenantId <= 0 || $memberId <= 0) return false;
    global $wpdb;
    return $wpdb->query($wpdb->prepare(
        "UPDATE " . ckmqp_tenant_table('members') . " SET status='inactive' WHERE id=%d AND tenant_id=%d AND role='organizer'",
        $memberId,
        $tenantId
    )) !== false;
}

function ckm_quiz_pro_partner_members(int $tenantId): array {
    if ($tenantId <= 0) return [];
    global $wpdb;
    $members = ckmqp_tenant_table('members');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT m.id,m.user_id,m.status,u.user_login,u.display_name,u.user_email
         FROM $members m
         INNER JOIN {$wpdb->users} u ON u.ID=m.user_id
         WHERE m.tenant_id=%d AND m.role='organizer'
         ORDER BY m.id DESC",
        $tenantId
    ), ARRAY_A) ?: [];
}

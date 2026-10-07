<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_game_usage_key(array $game): string {
    if (!empty($game['quiz_id'])) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id,slug,format_key,format_settings_json FROM " . ckm_quiz_pro_table('quizzes') . " WHERE id=%d LIMIT 1",
            (int)$game['quiz_id']
        ), ARRAY_A);

        if ($row) {
            $format = sanitize_key((string)($row['format_key'] ?? ''));
            $slug = sanitize_key((string)($row['slug'] ?? ''));
            if ($format === 'classic_quiz') return 'base_classic_quiz';
            if ($format === 'chgk') return 'base_battle_experts';
            if ($format === 'jeopardy') return 'base_intellectual_battle';
            if ($format === 'solution_price') return 'base_decision_price';
            if ($format === 'negotiation_duel') {
                $map = [
                    'demo-negotiation-sales' => 'base_negotiation_sales',
                    'demo-negotiation-business' => 'base_negotiation_business',
                    'demo-negotiation-express' => 'base_negotiation_express',
                    'demo-negotiation-communicate' => 'base_persuade_me',
                ];
                if (isset($map[$slug])) return $map[$slug];
            }
            if (function_exists('ckm_quiz_pro_quiz_access_product')) {
                return sanitize_key((string)ckm_quiz_pro_quiz_access_product($row));
            }
        }
    }

    $raw = (string)($game['format_key_snapshot'] ?? '');
    return function_exists('ckm_quiz_pro_access_format_key')
        ? sanitize_key((string)ckm_quiz_pro_access_format_key($raw))
        : sanitize_key($raw);
}

function ckm_quiz_pro_partner_usage_exists(int $gameId): bool {
    if ($gameId <= 0) return false;
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM " . ckm_quiz_pro_partner_table('usage') . " WHERE game_id=%d LIMIT 1",
        $gameId
    ));
}

function ckm_quiz_pro_partner_quota_state(array $game): array {
    ckm_quiz_pro_partner_install_schema();
    $gameId = (int)($game['id'] ?? 0);
    $tenantId = (int)($game['tenant_id'] ?? 0);
    $userId = (int)($game['created_by_user_id'] ?? 0);
    $productKey = ckm_quiz_pro_partner_game_usage_key($game);
    $alreadyCounted = $gameId > 0 && ckm_quiz_pro_partner_usage_exists($gameId);

    if (!empty($game['test_mode']) || ($tenantId === 0 && $userId > 0 && user_can($userId, 'manage_options'))) {
        return [
            'unlimited' => true,
            'allowed' => true,
            'already_counted' => $alreadyCounted,
            'used' => 0,
            'limit' => 0,
            'extra' => 0,
            'remaining' => PHP_INT_MAX,
            'scope' => 'test',
            'period_key' => '',
            'product_key' => $productKey,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reset_at' => 0,
        ];
    }

    global $wpdb;
    $usage = ckm_quiz_pro_partner_table('usage');
    $sub = $tenantId > 0 ? ckm_quiz_pro_partner_subscription($tenantId, true) : null;

    if ($sub) {
        $period = ckm_quiz_pro_partner_period($sub);
        $extra = (string)$sub['extra_period_key'] === $period['key'] ? (int)$sub['extra_sessions'] : 0;
        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $usage WHERE tenant_id=%d AND usage_scope='partner' AND period_key=%s",
            $tenantId,
            $period['key']
        ));
        $limit = (int)$sub['session_limit'];
        $total = $limit + $extra;
        return [
            'unlimited' => false,
            'allowed' => $alreadyCounted || $used < $total,
            'already_counted' => $alreadyCounted,
            'used' => $used,
            'limit' => $limit,
            'extra' => $extra,
            'remaining' => max(0, $total - $used),
            'scope' => 'partner',
            'period_key' => $period['key'],
            'product_key' => $productKey,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'reset_at' => (int)$period['to'],
            'plan_key' => (string)$sub['plan_key'],
        ];
    }

    $limit = max(1, (int)get_option('ckmqp_organizer_monthly_session_limit', 15));
    $periodKey = 'organizer:' . gmdate('Y-m');
    $nextMonth = strtotime(gmdate('Y-m-01 00:00:00', strtotime('+1 month')) . ' UTC');

    if ($tenantId > 0) {
        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $usage WHERE tenant_id=%d AND usage_scope='organizer' AND product_key=%s AND period_key=%s",
            $tenantId,
            $productKey,
            $periodKey
        ));
    } else {
        $used = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $usage WHERE user_id=%d AND usage_scope='organizer' AND product_key=%s AND period_key=%s",
            $userId,
            $productKey,
            $periodKey
        ));
    }

    return [
        'unlimited' => false,
        'allowed' => $alreadyCounted || $used < $limit,
        'already_counted' => $alreadyCounted,
        'used' => $used,
        'limit' => $limit,
        'extra' => 0,
        'remaining' => max(0, $limit - $used),
        'scope' => 'organizer',
        'period_key' => $periodKey,
        'product_key' => $productKey,
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'reset_at' => $nextMonth ?: 0,
    ];
}

function ckm_quiz_pro_partner_quota_message(array $q): string {
    $used = (int)($q['used'] ?? 0);
    $total = (int)($q['limit'] ?? 0) + (int)($q['extra'] ?? 0);
    $date = !empty($q['reset_at']) ? wp_date('d.m.Y', (int)$q['reset_at']) : '';

    if (($q['scope'] ?? '') === 'partner') {
        return 'Месячный лимит партнёрской площадки исчерпан: ' . $used . ' из ' . $total . ' игровых сессий. '
            . ($date !== '' ? 'Новый период — ' . $date . '. ' : '')
            . 'Можно добавить пакет запусков или изменить тариф.';
    }
    return 'Месячный лимит этой оплаченной игры исчерпан: ' . $used . ' из ' . $total . ' запусков. '
        . ($date !== '' ? 'Лимит обновится ' . $date . '. ' : '')
        . 'Обратитесь к администратору для увеличения лимита.';
}

function ckm_quiz_pro_partner_preflight_game(array $game): void {
    $q = ckm_quiz_pro_partner_quota_state($game);
    if (!empty($q['unlimited']) || !empty($q['already_counted']) || !empty($q['allowed'])) return;

    wp_send_json([
        'ok' => false,
        'code' => 'monthly_session_limit_reached',
        'error' => ckm_quiz_pro_partner_quota_message($q),
        'usage' => [
            'used' => (int)$q['used'],
            'limit' => (int)$q['limit'],
            'extra' => (int)$q['extra'],
            'remaining' => 0,
            'resetAt' => (int)$q['reset_at'],
        ],
    ], 429);
}

function ckm_quiz_pro_partner_game_from_request(): ?array {
    $code = (string)($_REQUEST['game'] ?? '');
    if ($code === '') return null;
    $game = ckm_quiz_pro_find_game($code);
    return is_array($game) ? $game : null;
}

function ckm_quiz_pro_partner_preflight_host_start(): void {
    $command = sanitize_key((string)(($_POST['command'] ?? '') ?: ($_POST['action'] ?? '')));
    if (!in_array($command, ['start', 'next'], true)) return;
    $game = ckm_quiz_pro_partner_game_from_request();
    if (!$game) return;
    if ((string)($game['quiz_phase'] ?? 'waiting') !== 'waiting' || !empty($game['auto_start'])) return;
    ckm_quiz_pro_partner_preflight_game($game);
}

function ckm_quiz_pro_partner_show_session_started(int $gameId): bool {
    if ($gameId <= 0) return false;
    global $wpdb;
    $table = ckm_quiz_pro_table('show_sessions');
    $json = $wpdb->get_var($wpdb->prepare("SELECT state_json FROM $table WHERE game_id=%d LIMIT 1", $gameId));
    if (!is_string($json) || $json === '') return false;
    $state = json_decode($json, true);
    if (!is_array($state)) return false;
    return (int)($state['revision'] ?? 0) > 1
        || !in_array((string)($state['phase'] ?? 'waiting'), ['waiting', 'preparation'], true);
}

function ckm_quiz_pro_partner_preflight_show_action(): void {
    $command = sanitize_key((string)($_POST['command'] ?? ''));
    if ($command === '' || $command === 'poll') return;
    $game = ckm_quiz_pro_partner_game_from_request();
    if (!$game || !function_exists('ckmqp_show_is_game') || !ckmqp_show_is_game($game)) return;
    if (ckm_quiz_pro_partner_usage_exists((int)$game['id'])) return;

    if (ckm_quiz_pro_partner_show_session_started((int)$game['id'])) {
        ckm_quiz_pro_partner_record_usage($game);
        return;
    }
    ckm_quiz_pro_partner_preflight_game($game);
}

function ckm_quiz_pro_partner_record_usage(array $game): void {
    $gameId = (int)($game['id'] ?? 0);
    if ($gameId <= 0 || ckm_quiz_pro_partner_usage_exists($gameId)) return;

    $q = ckm_quiz_pro_partner_quota_state($game);
    if (!empty($q['unlimited']) || !empty($q['already_counted'])) return;
    if (empty($q['allowed'])) return;

    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(ckm_quiz_pro_partner_table('usage'), [
        'tenant_id' => (int)$q['tenant_id'],
        'user_id' => (int)$q['user_id'],
        'game_id' => $gameId,
        'product_key' => (string)$q['product_key'],
        'usage_scope' => (string)$q['scope'],
        'period_key' => (string)$q['period_key'],
        'counted_at' => $now,
        'created_at' => $now,
    ]);
}

function ckm_quiz_pro_partner_event_is_start(array $event): bool {
    $action = (string)($event['action'] ?? '');
    if (in_array($action, ['question_started', 'round_started'], true)) return true;
    if ($action !== 'ai_host_message') return false;

    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    if (!$payload && is_string($event['payload_json'] ?? null)) {
        $decoded = json_decode($event['payload_json'], true);
        if (is_array($decoded)) $payload = $decoded;
    }
    $hostEvent = sanitize_key((string)($payload['event'] ?? ''));
    return in_array($hostEvent, ['negotiation_show_dialogue_started', 'negotiation_show_round_started'], true);
}

function ckm_quiz_pro_partner_on_event_appended(array $event): void {
    if (!ckm_quiz_pro_partner_event_is_start($event) || !function_exists('ckm_quiz_get_game')) return;
    $game = ckm_quiz_get_game((int)($event['game_id'] ?? 0));
    if (is_array($game)) ckm_quiz_pro_partner_record_usage($game);
}

function ckm_quiz_pro_partner_usage_for_subscription(array $sub): array {
    $period = ckm_quiz_pro_partner_period($sub);
    global $wpdb;
    $used = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . ckm_quiz_pro_partner_table('usage') . " WHERE tenant_id=%d AND usage_scope='partner' AND period_key=%s",
        (int)$sub['tenant_id'],
        $period['key']
    ));
    $extra = (string)$sub['extra_period_key'] === $period['key'] ? (int)$sub['extra_sessions'] : 0;
    $total = (int)$sub['session_limit'] + $extra;
    return [
        'used' => $used,
        'limit' => (int)$sub['session_limit'],
        'extra' => $extra,
        'total' => $total,
        'remaining' => max(0, $total - $used),
        'period' => $period,
    ];
}

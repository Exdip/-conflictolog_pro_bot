<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_plan_settings(): array {
    $defaults = [
        'start' => ['label' => 'Start', 'limit' => 30, 'price_rub' => 0],
        'business' => ['label' => 'Business', 'limit' => 75, 'price_rub' => 0],
        'pro' => ['label' => 'Pro', 'limit' => 150, 'price_rub' => 0],
    ];
    $saved = get_option('ckmqp_partner_plan_settings', []);
    if (!is_array($saved)) $saved = [];

    foreach ($defaults as $key => $default) {
        if (!isset($saved[$key]) || !is_array($saved[$key])) $saved[$key] = $default;
        $saved[$key]['label'] = $default['label'];
        $saved[$key]['limit'] = max(1, (int)($saved[$key]['limit'] ?? $default['limit']));
        $saved[$key]['price_rub'] = max(0, (int)($saved[$key]['price_rub'] ?? 0));
    }
    return $saved;
}

function ckm_quiz_pro_partner_base_product_keys(): array {
    return ['classic_quiz', 'chgk_v1', 'jeopardy_v1', 'decision_price_v1', 'negotiation_duel_v1'];
}

function ckm_quiz_pro_partner_base_game_titles(): array {
    return [
        'Классический квиз',
        'Битва знатоков',
        'Интеллектуальный батл',
        'Управленческая игра "Ваш выбор"',
        'Эффективный продажник',
        'Мастер переговоров',
        'Экспресс-раунд',
        'Переговори другого',
    ];
}


function ckm_quiz_pro_partner_request_option_name(int $tenantId): string {
    return 'ckmqp_partner_request_' . max(0, $tenantId);
}

function ckm_quiz_pro_partner_request(int $tenantId): ?array {
    if ($tenantId <= 0) return null;
    $row = get_option(ckm_quiz_pro_partner_request_option_name($tenantId), null);
    if (!is_array($row) || empty($row['plan_key'])) return null;
    $plans = ckm_quiz_pro_partner_plan_settings();
    $key = sanitize_key((string)$row['plan_key']);
    if (!isset($plans[$key])) return null;
    $row['plan_key'] = $key;
    return $row;
}

function ckm_quiz_pro_partner_save_request(int $tenantId, int $userId, string $planKey): array|WP_Error {
    if ($tenantId <= 0 || $userId <= 0) return new WP_Error('tenant', 'Площадка не найдена.');
    $plans = ckm_quiz_pro_partner_plan_settings();
    $planKey = sanitize_key($planKey);
    if (!isset($plans[$planKey])) return new WP_Error('plan', 'Неизвестный партнёрский тариф.');
    if (!function_exists('ckm_quiz_pro_partner_user_can_activate') || !ckm_quiz_pro_partner_user_can_activate($tenantId, $userId)) {
        return new WP_Error('forbidden', 'Подключить партнёрский режим может владелец площадки или подключённый организатор.');
    }
    $row = [
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'plan_key' => $planKey,
        'status' => 'pending',
        'requested_at' => gmdate('Y-m-d H:i:s'),
    ];
    update_option(ckm_quiz_pro_partner_request_option_name($tenantId), $row, false);
    return $row;
}

function ckm_quiz_pro_partner_clear_request(int $tenantId): void {
    if ($tenantId > 0) delete_option(ckm_quiz_pro_partner_request_option_name($tenantId));
}

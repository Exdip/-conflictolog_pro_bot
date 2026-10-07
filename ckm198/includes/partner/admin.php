<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_register_admin_menu(): void {
    add_submenu_page(
        'ckm-quiz-pro',
        'Партнёры и лимиты',
        'Партнёры и лимиты',
        'manage_options',
        'ckm-quiz-pro-partners',
        'ckm_quiz_pro_partner_render_admin_page'
    );
}

function ckm_quiz_pro_partner_admin_actions(): array {
    $out = ['notice' => '', 'error' => ''];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user_can('manage_options')) return $out;
    if (!isset($_POST['ckm_quiz_pro_partner_admin_action'])) return $out;

    check_admin_referer('ckm_quiz_pro_partner_admin');
    $action = sanitize_key((string)$_POST['ckm_quiz_pro_partner_admin_action']);

    if ($action === 'save_limits') {
        update_option('ckmqp_organizer_monthly_session_limit', max(1, min(1000, absint($_POST['organizer_limit'] ?? 15))), false);
        $plans = ckm_quiz_pro_partner_plan_settings();
        foreach (['start', 'business', 'pro'] as $key) {
            $plans[$key]['limit'] = max(1, min(5000, absint($_POST[$key . '_limit'] ?? $plans[$key]['limit'])));
            $plans[$key]['price_rub'] = max(0, min(10000000, absint($_POST[$key . '_price'] ?? $plans[$key]['price_rub'])));
        }
        update_option('ckmqp_partner_plan_settings', $plans, false);
        $out['notice'] = 'Лимиты и партнёрские тарифы сохранены.';
    } elseif ($action === 'save_platform_yookassa') {
        if (!function_exists('ckm_quiz_pro_partner_platform_yk_save_settings')) {
            $out['error'] = 'Модуль оплаты партнёрских тарифов не загружен.';
        } else {
            $r = ckm_quiz_pro_partner_platform_yk_save_settings([
                'shop_id' => wp_unslash($_POST['platform_shop_id'] ?? ''),
                'secret_key' => wp_unslash($_POST['platform_secret_key'] ?? ''),
                'remove_secret' => !empty($_POST['platform_remove_secret']),
                'mode' => wp_unslash($_POST['platform_mode'] ?? 'test'),
                'vat_code' => absint($_POST['platform_vat_code'] ?? 1),
                'enabled' => !empty($_POST['platform_enabled']),
            ]);
            if (is_wp_error($r)) $out['error'] = $r->get_error_message();
            else $out['notice'] = 'YooKassa для оплаты партнёрских тарифов сохранена.';
        }
    } elseif ($action === 'test_platform_yookassa') {
        if (!function_exists('ckm_quiz_pro_partner_platform_yk_test_connection')) {
            $out['error'] = 'Модуль оплаты партнёрских тарифов не загружен.';
        } else {
            $r = ckm_quiz_pro_partner_platform_yk_test_connection();
            if (is_wp_error($r)) $out['error'] = $r->get_error_message();
            else $out['notice'] = 'Соединение с YooKassa подтверждено. Магазин: ' . (!empty($r['test']) ? 'тестовый' : 'боевой') . ', статус: ' . sanitize_text_field((string)($r['status'] ?? 'unknown')) . '.';
        }
    } elseif ($action === 'activate') {
        $r = ckm_quiz_pro_partner_activate(absint($_POST['tenant_id'] ?? 0), sanitize_key((string)($_POST['plan_key'] ?? 'business')));
        if (is_wp_error($r)) $out['error'] = $r->get_error_message();
        else $out['notice'] = 'Партнёрский режим активирован/продлён на 30 дней. Базовые 8 игр открыты.';
    } elseif ($action === 'extra') {
        $r = ckm_quiz_pro_partner_add_extra(absint($_POST['tenant_id'] ?? 0), absint($_POST['extra_amount'] ?? 10));
        if (is_wp_error($r)) $out['error'] = $r->get_error_message();
        else $out['notice'] = 'Дополнительные запуски добавлены.';
    } elseif ($action === 'deactivate') {
        if (ckm_quiz_pro_partner_deactivate(absint($_POST['tenant_id'] ?? 0))) $out['notice'] = 'Партнёрский режим отключён. Партнёрские права на базовые игры закрыты.';
        else $out['error'] = 'Не удалось отключить партнёрский режим.';
    }
    return $out;
}

function ckm_quiz_pro_partner_render_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    ckm_quiz_pro_partner_install_schema();
    $msg = ckm_quiz_pro_partner_admin_actions();
    $plans = ckm_quiz_pro_partner_plan_settings();
    $organizerLimit = max(1, (int)get_option('ckmqp_organizer_monthly_session_limit', 15));

    echo '<div class="wrap"><h1>Партнёры и месячные лимиты</h1>';
    if ($msg['notice'] !== '') echo '<div class="notice notice-success"><p>' . esc_html($msg['notice']) . '</p></div>';
    if ($msg['error'] !== '') echo '<div class="notice notice-error"><p>' . esc_html($msg['error']) . '</p></div>';

    echo '<h2>Политика лимитов</h2><form method="post">';
    wp_nonce_field('ckm_quiz_pro_partner_admin');
    echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="save_limits">';
    echo '<table class="form-table"><tr><th>Обычный организатор</th><td><input type="number" min="1" max="1000" name="organizer_limit" value="' . (int)$organizerLimit . '"> запусков в месяц <p class="description">Лимит считается отдельно по каждой игре. Для базовых переговоров «Эффективный продажник», «Мастер переговоров», «Переговорный раунд» и «Переговори другого» используются четыре отдельных счётчика.</p></td></tr>';
    foreach ($plans as $key => $plan) {
        echo '<tr><th>Партнёр ' . esc_html($plan['label']) . '</th><td><input type="number" min="1" max="5000" name="' . esc_attr($key) . '_limit" value="' . (int)$plan['limit'] . '"> запусков / 30 дней &nbsp; Стоимость: <input type="number" min="0" name="' . esc_attr($key) . '_price" value="' . (int)$plan['price_rub'] . '"> ₽/30 дней <span class="description">(0 = цена пока не задана)</span></td></tr>';
    }
    echo '</table>';
    submit_button('Сохранить тарифы и лимиты');
    echo '</form>';

    if (function_exists('ckm_quiz_pro_partner_plan_render_admin_yookassa_section')) {
        ckm_quiz_pro_partner_plan_render_admin_yookassa_section();
    }

    global $wpdb;
    $tenants = ckmqp_tenant_table('tenants');
    $members = ckmqp_tenant_table('members');
    $domains = ckmqp_tenant_table('domains');
    $rows = $wpdb->get_results(
        "SELECT t.id,t.name,t.slug,d.hostname,m.user_id,u.user_login,u.user_email
         FROM $tenants t
         LEFT JOIN $domains d ON d.tenant_id=t.id AND d.status IN ('active','reserved')
         LEFT JOIN $members m ON m.tenant_id=t.id AND m.role='owner' AND m.status='active'
         LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id
         ORDER BY t.id DESC",
        ARRAY_A
    ) ?: [];

    echo '<h2>Площадки и партнёрский режим</h2><table class="widefat striped"><thead><tr><th>Площадка</th><th>Владелец</th><th>Статус</th><th>Использование</th><th>Управление</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $tenantId = (int)$row['id'];
        $sub = ckm_quiz_pro_partner_subscription($tenantId, false);
        $request = function_exists('ckm_quiz_pro_partner_request') ? ckm_quiz_pro_partner_request($tenantId) : null;
        $active = $sub && (string)$sub['status'] === 'active' && strtotime((string)$sub['expires_at'] . ' UTC') > time();
        $usage = $active ? ckm_quiz_pro_partner_usage_for_subscription($sub) : null;
        $ownerValid = ckm_quiz_pro_partner_valid_owner_id($tenantId) > 0;
        $status = $active
            ? 'Партнёр ' . esc_html((string)$sub['plan_key']) . ' · до ' . esc_html(wp_date('d.m.Y', strtotime((string)$sub['expires_at'] . ' UTC')))
            : ($request
                ? 'Запрошен партнёрский режим: ' . esc_html((string)($plans[$request['plan_key']]['label'] ?? $request['plan_key']))
                : ($sub ? 'Партнёрский режим не активен' : 'Обычная площадка'));
        $use = $usage ? ((int)$usage['used'] . ' из ' . (int)$usage['total'] . ' · осталось ' . (int)$usage['remaining']) : '—';

        echo '<tr><td><strong>' . esc_html((string)$row['name']) . '</strong><br><code>' . esc_html((string)$row['hostname']) . '</code></td>';
        echo '<td>' . ($ownerValid ? esc_html((string)$row['user_login']) . '<br><small>' . esc_html((string)$row['user_email']) . '</small>' : '<strong style="color:#b32d2e">Владелец отсутствует</strong>') . '</td>';
        echo '<td>' . $status . '</td><td>' . esc_html($use) . '</td><td>';
        if ($request && !$active) {
            $requestUser = get_user_by('id', (int)($request['user_id'] ?? 0));
            $requestStatus = (string)($request['status'] ?? 'pending');
            $statusText = $requestStatus === 'awaiting_payment' ? ' · ожидается оплата' : '';
            echo '<p><strong>Заявка организатора:</strong> ' . esc_html((string)($plans[$request['plan_key']]['label'] ?? $request['plan_key'])) . esc_html($statusText) . '<br><small>' . esc_html($requestUser ? $requestUser->user_login : ('user #' . (int)($request['user_id'] ?? 0))) . ' · ' . esc_html((string)($request['requested_at'] ?? '')) . '</small></p>';
        }

        if ($ownerValid) {
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">';
            wp_nonce_field('ckm_quiz_pro_partner_admin');
            $selectedPlan = $request['plan_key'] ?? ($sub['plan_key'] ?? 'business');
            echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="activate"><input type="hidden" name="tenant_id" value="' . $tenantId . '"><select name="plan_key">';
            foreach ($plans as $key => $plan) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($selectedPlan, $key, false) . '>' . esc_html($plan['label']) . ' — ' . (int)$plan['limit'] . '</option>';
            }
            echo '</select><button class="button button-primary">' . ($active ? 'Продлить 30 дней' : 'Активировать') . '</button></form>';
        } else {
            echo '<p><small>Сначала назначьте действующего владельца площадки.</small></p>';
        }

        if ($active) {
            echo '<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px">';
            wp_nonce_field('ckm_quiz_pro_partner_admin');
            echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="extra"><input type="hidden" name="tenant_id" value="' . $tenantId . '"><select name="extra_amount"><option value="10">+10</option><option value="25">+25</option><option value="50">+50</option><option value="100">+100</option></select><button class="button">Добавить запуски</button></form>';
            echo '<form method="post">';
            wp_nonce_field('ckm_quiz_pro_partner_admin');
            echo '<input type="hidden" name="ckm_quiz_pro_partner_admin_action" value="deactivate"><input type="hidden" name="tenant_id" value="' . $tenantId . '"><button class="button">Отключить партнёрский режим</button></form>';
        }
        echo '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5">Площадки не найдены.</td></tr>';
    echo '</tbody></table>';
    echo '<p><strong>Базовая восьмёрка партнёра:</strong> ' . esc_html(implode(' · ', ckm_quiz_pro_partner_base_game_titles())) . '. Сценарные библиотеки и игры под заказ в партнёрский режим автоматически не входят.</p>';
    if (function_exists('ckm_quiz_pro_partner_plan_render_admin_orders')) ckm_quiz_pro_partner_plan_render_admin_orders();
    echo '</div>';
}

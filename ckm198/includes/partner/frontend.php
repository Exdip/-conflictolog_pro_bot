<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_partner_route_url(int $tenantId = 0): string {
    $url = home_url('/ckm-partner/');
    if ($tenantId > 0) {
        $tenantUrl = ckmqp_tenant_link($url, $tenantId);
        if ($tenantUrl !== '') return $tenantUrl;
    }
    return $url;
}

function ckm_quiz_pro_partner_front_route(): void {
    $path = rtrim((string)wp_parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
    if ($path !== '/ckm-partner') return;

    if (!is_user_logged_in()) {
        wp_safe_redirect(function_exists('ckm_quiz_pro_login_url') ? ckm_quiz_pro_login_url() : wp_login_url());
        exit;
    }

    $tenantId = (int)ckmqp_scope_id();
    $uid = get_current_user_id();
    $isOwner = $tenantId > 0 && function_exists('ckm_quiz_pro_partner_user_is_owner') && ckm_quiz_pro_partner_user_is_owner($tenantId, $uid);
    $canPlan = $tenantId > 0 && function_exists('ckm_quiz_pro_partner_user_can_activate') && ckm_quiz_pro_partner_user_can_activate($tenantId, $uid);
    if ($tenantId <= 0 || !$canPlan) {
        wp_die('Партнёрский кабинет доступен владельцу площадки и подключённым организаторам.', '', ['response' => 403]);
    }

    $sub = ckm_quiz_pro_partner_subscription($tenantId, false);
    $active = $sub && (string)$sub['status'] === 'active' && strtotime((string)$sub['expires_at'] . ' UTC') > time();
    $request = function_exists('ckm_quiz_pro_partner_request') ? ckm_quiz_pro_partner_request($tenantId) : null;
    $notice = '';
    $error = '';

    if (!empty($_GET['plan_payment_return']) && function_exists('ckm_quiz_pro_partner_plan_sync_return')) {
        $orderId = sanitize_text_field(wp_unslash((string)$_GET['plan_payment_return']));
        $sync = ckm_quiz_pro_partner_plan_sync_return($tenantId, $uid, $orderId);
        if (is_wp_error($sync)) {
            $error = $sync->get_error_message();
        } else {
            $status = sanitize_key((string)($sync['status'] ?? ''));
            if (!empty($sync['activated_at'])) $notice = 'Оплата подтверждена. Партнёрский режим активирован автоматически на 30 дней.';
            elseif ($status === 'canceled') $error = 'Платёж отменён. Партнёрский режим не активирован.';
            else $notice = 'Платёж ещё обрабатывается YooKassa. Обновите страницу через несколько секунд.';
        }
        $sub = ckm_quiz_pro_partner_subscription($tenantId, false);
        $active = $sub && (string)$sub['status'] === 'active' && strtotime((string)$sub['expires_at'] . ' UTC') > time();
        $request = function_exists('ckm_quiz_pro_partner_request') ? ckm_quiz_pro_partner_request($tenantId) : null;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!wp_verify_nonce((string)($_POST['_wpnonce'] ?? ''), 'ckm_quiz_pro_partner_partner_front')) {
            $error = 'Сессия формы истекла. Обновите страницу.';
        } else {
            $action = sanitize_key((string)($_POST['partner_action'] ?? ''));
            if ($action === 'request_plan') {
                if ($active) {
                    $error = 'Партнёрский режим уже активен.';
                } else {
                    $planKey = sanitize_key((string)($_POST['plan_key'] ?? ''));
                    $plans = ckm_quiz_pro_partner_plan_settings();
                    $price = isset($plans[$planKey]) ? (int)$plans[$planKey]['price_rub'] : 0;
                    if ($price > 0 && function_exists('ckm_quiz_pro_partner_plan_start_checkout')) {
                        $order = ckm_quiz_pro_partner_plan_start_checkout($tenantId, $uid, $planKey);
                        if (is_wp_error($order)) {
                            $error = $order->get_error_message();
                        } else {
                            $payUrl = esc_url_raw((string)($order['confirmation_url'] ?? ''));
                            if ($payUrl === '' || wp_parse_url($payUrl, PHP_URL_SCHEME) !== 'https') {
                                $error = 'YooKassa не вернула безопасную ссылку оплаты.';
                            } else {
                                wp_redirect($payUrl, 302, 'CKM Partner Plan YooKassa');
                                exit;
                            }
                        }
                    } else {
                        $r = ckm_quiz_pro_partner_save_request($tenantId, $uid, $planKey);
                        if (is_wp_error($r)) $error = $r->get_error_message();
                        else {
                            $request = $r;
                            $notice = 'Выбран тариф ' . (string)($plans[$r['plan_key']]['label'] ?? $r['plan_key']) . '. Цена или автоматическая оплата пока не настроены — заявка отправлена администратору.';
                        }
                    }
                }
            } elseif (in_array($action, ['create_payment_request', 'save_tbank', 'test_tbank'], true)) {
                if (!$isOwner) {
                    $error = 'Настройки платёжных систем и расчёты с вашими организаторами доступны владельцу площадки.';
                } elseif (!$active) {
                    $error = 'Сначала активируйте партнёрский режим.';
                } elseif (function_exists('ckm_quiz_pro_partner_tbank_handle_owner_action')) {
                    $paymentResult = ckm_quiz_pro_partner_tbank_handle_owner_action($tenantId, $uid, $action);
                    $notice = (string)($paymentResult['notice'] ?? '');
                    $error = (string)($paymentResult['error'] ?? '');
                } else {
                    $error = 'Платёжный модуль партнёра не загружен.';
                }
            } elseif ($action === 'renew_plan') {
                if (!$canPlan) {
                    $error = 'У вас нет права продлевать партнёрский режим этой площадки.';
                } elseif (!$active || !$sub) {
                    $error = 'Активный партнёрский режим не найден.';
                } elseif (!function_exists('ckm_quiz_pro_partner_plan_start_checkout') || !function_exists('ckm_quiz_pro_partner_platform_yk_ready') || !ckm_quiz_pro_partner_platform_yk_ready()) {
                    $error = 'Автоматическая оплата партнёрского режима пока не подключена.';
                } else {
                    $order = ckm_quiz_pro_partner_plan_start_checkout($tenantId, $uid, (string)$sub['plan_key']);
                    if (is_wp_error($order)) {
                        $error = $order->get_error_message();
                    } else {
                        $payUrl = esc_url_raw((string)($order['confirmation_url'] ?? ''));
                        if ($payUrl === '' || wp_parse_url($payUrl, PHP_URL_SCHEME) !== 'https') $error = 'YooKassa не вернула безопасную ссылку оплаты.';
                        else { wp_redirect($payUrl, 302, 'CKM Partner Plan YooKassa'); exit; }
                    }
                }
            } elseif (!$active) {
                $error = 'Сначала выберите партнёрский тариф.';
            } elseif ($action === 'add_member') {
                if (!$isOwner) {
                    $error = 'Добавлять и отключать организаторов может только владелец площадки.';
                } else {
                $r = ckm_quiz_pro_partner_member_create($tenantId, [
                    'login' => wp_unslash($_POST['login'] ?? ''),
                    'email' => wp_unslash($_POST['email'] ?? ''),
                    'name' => wp_unslash($_POST['name'] ?? ''),
                ]);
                if (is_wp_error($r)) $error = $r->get_error_message();
                else $notice = !empty($r['created']) ? 'Организатор создан. На email отправлено письмо для входа.' : 'Существующий организатор добавлен на площадку.';
                }
            } elseif ($action === 'deactivate_member') {
                if (!$isOwner) {
                    $error = 'Добавлять и отключать организаторов может только владелец площадки.';
                } else {
                    $ok = ckm_quiz_pro_partner_member_deactivate($tenantId, absint($_POST['member_id'] ?? 0));
                    if ($ok) $notice = 'Организатор отключён от площадки.';
                    else $error = 'Не удалось отключить организатора.';
                }
            }
        }
    }

    nocache_headers();
    status_header(200);
    if (function_exists('ckm_quiz_pro_org_shell_start')) {
        ckm_quiz_pro_org_shell_start('Партнёрский кабинет');
    } else {
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Партнёрский кабинет</title><body style="font-family:Arial;max-width:1100px;margin:30px auto">';
    }

    echo '<main class="ckm-main"><section class="ckm-card"><h1>Партнёрский кабинет</h1>';
    if ($notice !== '') echo '<div class="ckm-alert"><strong>' . esc_html($notice) . '</strong></div>';
    if ($error !== '') echo '<div class="ckm-alert ckm-alert-error" role="alert">' . esc_html($error) . '</div>';

    if (!$active) {
        $plans = ckm_quiz_pro_partner_plan_settings();
        echo '<div class="ckm-kicker">РЕЖИМ 2</div><h2>Партнёрский режим</h2>';
        echo '<p>Партнёрский режим подходит тем, кто хочет использовать ЦКМ как собственную игровую площадку: получить все 8 базовых игр и подключать своих организаторов. Платные сценарии и игры под заказ приобретаются отдельно.</p>';
        echo '<p><a class="ckm-btn" href="' . esc_url(function_exists('ckm_quiz_pro_organizer_url') ? ckm_quiz_pro_organizer_url(['view'=>'mode']) : home_url('/ckm-organizer/?view=mode')) . '">← К выбору режима</a></p>';
        if ($request) {
            $requestedLabel = $plans[$request['plan_key']]['label'] ?? $request['plan_key'];
            echo '<div class="ckm-alert"><strong>Заявка отправлена:</strong> тариф ' . esc_html((string)$requestedLabel) . '. Можно выбрать другой тариф — заявка будет обновлена.</div>';
        }
        echo '<div class="ckm-grid">';
        foreach ($plans as $key => $plan) {
            $price = (int)$plan['price_rub'];
            echo '<article class="ckm-card ckm-game-card"><div class="ckm-kicker">ПАРТНЁРСКИЙ РЕЖИМ · 30 ДНЕЙ</div><h2>' . esc_html((string)$plan['label']) . '</h2>';
            echo '<p><strong>' . (int)$plan['limit'] . ' реальных игровых сессий</strong> на всю площадку за 30 дней.</p>';
            echo '<p class="ckm-muted">Все 8 базовых игр · общий пул для владельца и подключённых организаторов.</p>';
            echo '<p class="ckm-format-price">' . ($price > 0 ? esc_html(number_format_i18n($price, 0) . ' ₽ / 30 дней') : 'Цена пока не задана') . '</p>';
            echo '<form method="post">';
            wp_nonce_field('ckm_quiz_pro_partner_partner_front');
            echo '<input type="hidden" name="partner_action" value="request_plan"><input type="hidden" name="plan_key" value="' . esc_attr($key) . '">';
            $button = $price > 0 && function_exists('ckm_quiz_pro_partner_platform_yk_ready') && ckm_quiz_pro_partner_platform_yk_ready()
                ? 'Оплатить ' . esc_html((string)$plan['label'])
                : ($request && $request['plan_key'] === $key ? 'Тариф выбран' : 'Выбрать ' . esc_html((string)$plan['label']));
            echo '<button class="ckm-btn ckm-btn-primary">' . $button . '</button></form></article>';
        }
        echo '</div>';
        if (function_exists('ckm_quiz_pro_partner_platform_yk_ready') && ckm_quiz_pro_partner_platform_yk_ready()) {
            echo '<p class="ckm-muted">После успешной оплаты YooKassa партнёрский режим активируется автоматически на 30 дней. Аккаунт, поддомен и ваши отдельно купленные игры сохраняются.</p>';
        } else {
            echo '<p class="ckm-muted">Пока автоматическая оплата не подключена, выбранный тариф отправляется администратору для ручной активации. Аккаунт, поддомен и ваши отдельно купленные игры сохраняются.</p>';
        }
    } else {
        $usage = ckm_quiz_pro_partner_usage_for_subscription($sub);
        $plans = ckm_quiz_pro_partner_plan_settings();
        $label = $plans[$sub['plan_key']]['label'] ?? $sub['plan_key'];

        echo '<p><strong>Тариф:</strong> ' . esc_html((string)$label) . ' · <strong>Действует до:</strong> ' . esc_html(wp_date('d.m.Y', strtotime((string)$sub['expires_at'] . ' UTC'))) . '</p>';
        echo '<div class="ckm-alert"><strong>Использовано ' . (int)$usage['used'] . ' из ' . (int)$usage['total'] . ' игровых сессий.</strong> Осталось: ' . (int)$usage['remaining'] . '. Новый период: ' . esc_html(wp_date('d.m.Y', (int)$usage['period']['to'])) . '.</div>';
        if (function_exists('ckm_quiz_pro_partner_platform_yk_ready') && ckm_quiz_pro_partner_platform_yk_ready() && (int)($plans[$sub['plan_key']]['price_rub'] ?? 0) > 0) {
            echo '<form method="post" style="margin:14px 0 20px">';
            wp_nonce_field('ckm_quiz_pro_partner_partner_front');
            echo '<input type="hidden" name="partner_action" value="renew_plan"><button class="ckm-btn ckm-btn-primary">Продлить ' . esc_html((string)$label) . ' на 30 дней через YooKassa</button></form>';
        }
        echo '<h2>8 базовых игр</h2><p>' . esc_html(implode(' · ', ckm_quiz_pro_partner_base_game_titles())) . '</p>';
        echo '<p class="ckm-muted">Платные сценарные библиотеки и игры под заказ приобретаются отдельно. Расчёты со своими клиентами партнёр принимает самостоятельно; ЦКМ не удерживает платежи партнёра от его организаторов.</p>';

        if ($isOwner && function_exists('ckm_quiz_pro_partner_tbank_render_owner_section')) {
            ckm_quiz_pro_partner_tbank_render_owner_section($tenantId);
        } elseif (!$isOwner) {
            echo '<div class="ckm-alert">Партнёрский режим активен. Вы можете пользоваться его общим лимитом и продлевать тариф. Настройки площадки и управление организаторами доступны владельцу.</div>';
        }

        if ($isOwner) {
            echo '<h2>Добавить организатора вручную</h2><p>Если клиент оплатил другим способом, создайте ему организатора здесь. При оплате через ваш Т‑Банк аккаунт создаётся автоматически после подтверждения платежа. Все организаторы используют общий месячный пул вашей площадки.</p>';
            echo '<form method="post">';
            wp_nonce_field('ckm_quiz_pro_partner_partner_front');
            echo '<input type="hidden" name="partner_action" value="add_member">';
            echo '<label class="ckm-label">Имя<input class="ckm-input" name="name" maxlength="190" required></label>';
            echo '<label class="ckm-label">Логин<input class="ckm-input" name="login" maxlength="60" autocomplete="off" required></label>';
            echo '<label class="ckm-label">Email<input class="ckm-input" type="email" name="email" maxlength="190" required></label>';
            echo '<p><button class="ckm-btn ckm-btn-primary">Создать организатора</button></p></form>';

            $members = ckm_quiz_pro_partner_members($tenantId);
            $periodKey = $usage['period']['key'];
            global $wpdb;
            $usageTable = ckm_quiz_pro_partner_table('usage');

            echo '<h2>Организаторы партнёра</h2><table class="widefat"><thead><tr><th>Организатор</th><th>Email</th><th>Сессий в текущем периоде</th><th>Статус</th><th></th></tr></thead><tbody>';
            foreach ($members as $member) {
                $count = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $usageTable WHERE tenant_id=%d AND user_id=%d AND usage_scope='partner' AND period_key=%s",
                    $tenantId,
                    (int)$member['user_id'],
                    $periodKey
                ));
                echo '<tr><td><strong>' . esc_html((string)$member['display_name']) . '</strong><br><code>' . esc_html((string)$member['user_login']) . '</code></td><td>' . esc_html((string)$member['user_email']) . '</td><td>' . $count . '</td><td>' . esc_html((string)$member['status']) . '</td><td>';
                if ((string)$member['status'] === 'active') {
                    echo '<form method="post">';
                    wp_nonce_field('ckm_quiz_pro_partner_partner_front');
                    echo '<input type="hidden" name="partner_action" value="deactivate_member"><input type="hidden" name="member_id" value="' . (int)$member['id'] . '"><button class="ckm-btn">Отключить</button></form>';
                }
                echo '</td></tr>';
            }
            if (!$members) echo '<tr><td colspan="5">Организаторов пока нет.</td></tr>';
            echo '</tbody></table>';
        }
    }

    echo '</section></main>';
    if (function_exists('ckm_quiz_pro_org_shell_end')) ckm_quiz_pro_org_shell_end();
    else echo '</body></html>';
    exit;
}

function ckm_quiz_pro_partner_render_usage_badge(): void {
    if (!is_user_logged_in()) return;
    $tenantId = (int)ckmqp_scope_id();
    if ($tenantId <= 0) return;
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (strpos($uri, 'ckm-organizer') === false) return;

    $uid = get_current_user_id();
    $sub = ckm_quiz_pro_partner_subscription($tenantId, true);
    if ($sub) {
        $usage = ckm_quiz_pro_partner_usage_for_subscription($sub);
        $owner = ckm_quiz_pro_partner_user_is_owner($tenantId, $uid);
        echo '<div id="ckmqp-partner-usage" style="position:fixed;right:18px;bottom:18px;z-index:99999;background:#0d1a2d;color:#fff;border:1px solid #355273;border-radius:14px;padding:12px 14px;box-shadow:0 12px 30px rgba(0,0,0,.3);font:14px/1.4 Arial,sans-serif"><strong>Партнёрская площадка: ' . (int)$usage['used'] . ' / ' . (int)$usage['total'] . '</strong><br><span style="opacity:.78">Общий лимит всех организаторов</span>';
        if ($owner) echo '<br><a style="color:#dceaff" href="' . esc_url(ckm_quiz_pro_partner_route_url($tenantId)) . '">Партнёрский кабинет</a>';
        echo '</div>';
    } else {
        $limit = max(1, (int)get_option('ckmqp_organizer_monthly_session_limit', 15));
        echo '<div id="ckmqp-organizer-limit" style="position:fixed;right:18px;bottom:18px;z-index:99999;background:#0d1a2d;color:#fff;border:1px solid #355273;border-radius:14px;padding:10px 12px;box-shadow:0 12px 30px rgba(0,0,0,.3);font:13px/1.4 Arial,sans-serif">Лимит: <strong>' . $limit . ' запусков / месяц</strong><br><span style="opacity:.75">на каждую оплаченную игру</span></div>';
    }
}

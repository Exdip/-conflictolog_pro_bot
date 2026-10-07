<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_ORGANIZER_ROLE = 'ckm_quiz_organizer';
const CKM_QUIZ_PRO_ORGANIZER_CAP = 'ckm_quiz_organize';
const CKM_QUIZ_PRO_CONSTRUCTOR_META = 'ckm_qp_constructor_enabled';

function ckm_quiz_pro_organizer_install(): void {
    $role = get_role(CKM_QUIZ_PRO_ORGANIZER_ROLE);
    if (!$role) {
        $role = add_role(CKM_QUIZ_PRO_ORGANIZER_ROLE, 'Организатор', [
            'read' => true,
            CKM_QUIZ_PRO_ORGANIZER_CAP => true,
        ]);
    } elseif (!$role->has_cap(CKM_QUIZ_PRO_ORGANIZER_CAP)) {
        $role->add_cap(CKM_QUIZ_PRO_ORGANIZER_CAP, true);
    }

    $admin = get_role('administrator');
    if ($admin && !$admin->has_cap(CKM_QUIZ_PRO_ORGANIZER_CAP)) {
        $admin->add_cap(CKM_QUIZ_PRO_ORGANIZER_CAP, true);
    }

    $defs = [
        'organizer' => ['Кабинет организатора', 'ckm-organizer'],
        'login'     => ['Вход организатора', 'ckm-login'],
    ];
    foreach ($defs as $key => $def) {
        $id = (int)get_option('ckm_quiz_pro_page_'.$key, 0);
        if ($id > 0 && get_post($id)) continue;
        $existing = get_page_by_path($def[1]);
        if ($existing) {
            update_option('ckm_quiz_pro_page_'.$key, (int)$existing->ID, false);
            continue;
        }
        $id = wp_insert_post([
            'post_title' => $def[0],
            'post_name' => $def[1],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '',
        ]);
        if (!is_wp_error($id)) update_option('ckm_quiz_pro_page_'.$key, (int)$id, false);
    }
    update_option('ckm_quiz_pro_organizer_version', '1', false);
}

function ckm_quiz_pro_organizer_maybe_install(): void {
    if (get_option('ckm_quiz_pro_organizer_version', '') !== '1') ckm_quiz_pro_organizer_install();
}
add_action('init', 'ckm_quiz_pro_organizer_maybe_install', 5);

function ckm_quiz_pro_preview_organizer_user_id(): int {
    if (!is_user_logged_in() || !current_user_can('manage_options')) return 0;
    $id = absint($_GET['ckm_preview_user'] ?? 0);
    if ($id <= 0) return 0;
    $nonce = sanitize_text_field(wp_unslash($_GET['ckm_preview_nonce'] ?? ''));
    if ($nonce === '' || !wp_verify_nonce($nonce, 'ckm_qp_preview_organizer_'.$id)) return 0;
    $u = get_user_by('id', $id);
    if (!$u || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE, (array)$u->roles, true)) return 0;
    return $id;
}

function ckm_quiz_pro_effective_organizer_user_id(): int {
    $preview = ckm_quiz_pro_preview_organizer_user_id();
    return $preview > 0 ? $preview : get_current_user_id();
}

function ckm_quiz_pro_organizer_base_url(): string {
    $id = (int)get_option('ckm_quiz_pro_page_organizer', 0);
    return $id && get_post($id) ? get_permalink($id) : home_url('/ckm-organizer/');
}

function ckm_quiz_pro_organizer_url(array $args = []): string {
    $base = ckmqp_tenant_link(ckm_quiz_pro_organizer_base_url(),ckmqp_scope_id());
    $preview = ckm_quiz_pro_preview_organizer_user_id();
    if ($preview > 0) {
        $args['ckm_preview_user'] = $preview;
        $args['ckm_preview_nonce'] = wp_create_nonce('ckm_qp_preview_organizer_'.$preview);
    }
    return $args ? add_query_arg($args, $base) : $base;
}

function ckm_quiz_pro_login_url(array $args = []): string {
    $id = (int)get_option('ckm_quiz_pro_page_login', 0);
    $base = $id && get_post($id) ? get_permalink($id) : home_url('/ckm-login/');
    $base=ckmqp_tenant_link($base,ckmqp_scope_id());
    return $args ? add_query_arg($args, $base) : $base;
}

function ckm_quiz_pro_can_organize(): bool {
    if (ckmqp_scope_id()<0 || (ckmqp_scope_id()>0 && !ckmqp_tenant_is_member(ckmqp_scope_id(),get_current_user_id()))) return false;
    return is_user_logged_in() && (current_user_can('manage_options') || current_user_can(CKM_QUIZ_PRO_ORGANIZER_CAP));
}

/** History is visible only to a WordPress administrator (manage_options). */
function ckm_quiz_pro_history_admin_access(?int $userId=null, ?int $tenantId=null): bool {
    if (!is_user_logged_in()) return false;
    // Deliberately ignore tenant owner/admin roles and preview users.
    // Only the currently authenticated WordPress administrator may access history.
    return current_user_can('manage_options');
}

function ckm_quiz_pro_constructor_enabled_for_user(int $userId): bool {
    // Use the same active, tenant-scoped entitlements as game launch.
    // The legacy manual flag no longer grants or blocks constructor access.
    return $userId > 0 && ckm_quiz_pro_has_paid_cabinet_access($userId);
}

function ckm_quiz_pro_constructor_enabled_for_current_user(): bool {
    return ckm_quiz_pro_constructor_enabled_for_user(ckm_quiz_pro_effective_organizer_user_id());
}

function ckm_quiz_pro_can_use_front_constructor(): bool {
    return ckm_quiz_pro_can_organize() && ckm_quiz_pro_constructor_enabled_for_current_user();
}

function ckm_quiz_pro_can_edit_quizzes(): bool {
    if (!is_user_logged_in()) return false;
    if (current_user_can('manage_options')) return true;
    return ckm_quiz_pro_can_use_front_constructor();
}

/**
 * Organizers may edit only games they created themselves inside the current
 * tenant. Shared demos, managed packages and purchased ready-game snapshots
 * stay immutable. Administrators retain full technical editing access.
 */
function ckm_quiz_pro_can_edit_owned_quiz(array $quiz, ?int $userId=null): bool {
    if (current_user_can('manage_options')) return true;
    $uid=$userId ?? ckm_quiz_pro_effective_organizer_user_id();
    if ($uid<=0 || !ckm_quiz_pro_can_organize()) return false;
    if ((string)($quiz['content_scope']??'')!=='private') return false;
    if ((int)($quiz['tenant_id']??-1)!==ckmqp_scope_id()) return false;
    if ((int)($quiz['created_by_user_id']??0)!==$uid) return false;
    $quizId=(int)($quiz['id']??0);
    if ($quizId<=0) return false;
    if (function_exists('ckm_quiz_pro_package_is_readonly_quiz') && ckm_quiz_pro_package_is_readonly_quiz($quizId)) return false;
    if (function_exists('ckm_quiz_pro_ready_game_product_for_quiz') && ckm_quiz_pro_ready_game_product_for_quiz($quizId)!=='') return false;
    return function_exists('ckmqp_content_can_edit') && ckmqp_content_can_edit($quiz,$uid);
}

function ckm_quiz_pro_is_organizer_only_user(): bool {
    return is_user_logged_in() && current_user_can(CKM_QUIZ_PRO_ORGANIZER_CAP) && !current_user_can('manage_options');
}

add_filter('show_admin_bar', static function($show){
    return ckm_quiz_pro_is_organizer_only_user() ? false : $show;
});

add_action('admin_init', static function(): void {
    if (!ckm_quiz_pro_is_organizer_only_user()) return;
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    wp_safe_redirect(ckm_quiz_pro_organizer_url());
    exit;
}, 1);

function ckm_quiz_pro_organizer_admin_menu(): void {
    add_submenu_page('ckm-quiz-pro', 'Организаторы', 'Организаторы', 'manage_options', 'ckm-quiz-pro-organizers', 'ckm_quiz_pro_organizers_admin_page');
}
add_action('admin_menu', 'ckm_quiz_pro_organizer_admin_menu', 35);

function ckm_quiz_pro_organizers_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $notice = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_create_organizer'])) {
        check_admin_referer('ckm_qp_create_organizer');
        $login = sanitize_user(wp_unslash($_POST['user_login'] ?? ''), true);
        $email = sanitize_email(wp_unslash($_POST['user_email'] ?? ''));
        $password = (string)wp_unslash($_POST['user_password'] ?? '');
        if ($login === '' || !is_email($email)) {
            $error = 'Укажите логин и корректный email.';
        } elseif (username_exists($login) || email_exists($email)) {
            $error = 'Пользователь с таким логином или email уже существует.';
        } else {
            if ($password === '') $password = wp_generate_password(18, true, true);
            $userId = wp_insert_user([
                'user_login' => $login,
                'user_email' => $email,
                'user_pass' => $password,
                'role' => CKM_QUIZ_PRO_ORGANIZER_ROLE,
                'display_name' => $login,
            ]);
            if (is_wp_error($userId)) {
                $error = $userId->get_error_message();
            } else {
                $notice = 'Организатор создан. Логин: '.$login.' · временный пароль: '.$password;
            }
        }
    }

    $users = get_users(['role' => CKM_QUIZ_PRO_ORGANIZER_ROLE, 'orderby' => 'ID', 'order' => 'DESC']);
    echo '<div class="wrap"><h1>Организаторы</h1>';
    if ($notice !== '') echo '<div class="notice notice-success"><p>'.esc_html($notice).'</p></div>';
    if ($error !== '') echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';
    echo '<p>Клиенты с ролью «Организатор» работают только через frontend-кабинет и не получают доступ к WordPress-админке.</p>';
    echo '<h2>Добавить организатора</h2><form method="post">'; wp_nonce_field('ckm_qp_create_organizer');
    echo '<table class="form-table"><tr><th>Логин</th><td><input class="regular-text" name="user_login" required></td></tr><tr><th>Email</th><td><input class="regular-text" type="email" name="user_email" required></td></tr><tr><th>Пароль</th><td><input class="regular-text" name="user_password" autocomplete="new-password"><p class="description">Оставьте пустым — будет создан случайный пароль.</p></td></tr><tr><th>Конструктор</th><td>Автоматически после любой активной оплаты на площадке. После этого можно создать игру с нуля в любом поддерживаемом формате.</td></tr></table><p><button class="button button-primary" name="ckm_qp_create_organizer" value="1">Создать организатора</button></p></form>';

    echo '<h2>Существующие организаторы</h2><p><strong>Важно:</strong> конструктор доступен автоматически после любой активной оплаты на площадке. Ручное включение не требуется. Статус показан для текущей площадки.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Пользователь</th><th>Email</th><th>Конструктор</th><th>Frontend</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $enabled = ckm_quiz_pro_constructor_enabled_for_user((int)$u->ID);
        $previewArgs = [
            'ckm_preview_user' => (int)$u->ID,
            'ckm_preview_nonce' => wp_create_nonce('ckm_qp_preview_organizer_'.(int)$u->ID),
        ];
        $previewUrl = add_query_arg($previewArgs, ckm_quiz_pro_organizer_base_url());
        $builderUrl = add_query_arg(array_merge($previewArgs, ['view' => 'builder']), ckm_quiz_pro_organizer_base_url());
        $frontendActions = '<a class="button" href="'.esc_url($previewUrl).'" target="_blank">Кабинет</a>';
        if ($enabled) {
            $frontendActions .= ' <a class="button button-primary" href="'.esc_url($builderUrl).'" target="_blank">Создать свою игру</a>';
        }
        echo '<tr><td><strong>'.esc_html($u->display_name).'</strong><br><code>'.esc_html($u->user_login).'</code></td><td>'.esc_html($u->user_email).'</td><td>'.esc_html($enabled ? 'Доступен после активной оплаты' : 'Нет активной оплаты на этой площадке').'</td><td>'.$frontendActions.'</td></tr>';
    }
    if (!$users) echo '<tr><td colspan="4">Организаторов пока нет.</td></tr>';
    echo '</tbody></table></div>';
}

function ckm_quiz_pro_organizer_template_redirect(): void {
    if (is_admin()) return;
    $loginId = (int)get_option('ckm_quiz_pro_page_login', 0);
    $orgId = (int)get_option('ckm_quiz_pro_page_organizer', 0);
    if ($loginId > 0 && is_page($loginId)) {
        ckm_quiz_pro_render_organizer_login();
        exit;
    }
    if ($orgId > 0 && is_page($orgId)) {
        ckm_quiz_pro_render_organizer_cabinet();
        exit;
    }
}
add_action('template_redirect', 'ckm_quiz_pro_organizer_template_redirect', 5);

function ckm_quiz_pro_render_organizer_login(): void {
    $scopeId = ckmqp_scope_id();
    $loginUrl = ckm_quiz_pro_login_url();
    if ($scopeId > 0 && function_exists('ckmqp_tenant_link')) {
        $tenantLoginUrl = ckmqp_tenant_link($loginUrl, $scopeId);
        if ($tenantLoginUrl !== '') $loginUrl = $tenantLoginUrl;
    }

    // A tenant has no public generic login entrance. The tenant root is the game
    // storefront; login is shown only as part of a concrete purchase/return flow.
    if ($scopeId > 0) {
        $buyRaw = is_string($_GET['buy'] ?? null) ? sanitize_key(wp_unslash($_GET['buy'])) : '';
        $hasBuy = $buyRaw !== '' && isset(ckm_quiz_pro_game_access_products()[ckm_quiz_pro_access_format_key($buyRaw)]);
        $hasReturn = function_exists('ckmqp_return_order_id') && ckmqp_return_order_id($_GET['test_order'] ?? null) !== '';
        if (!$hasBuy && !$hasReturn && ($_GET['create_game'] ?? '') !== '1') {
            $site = function_exists('ckmqp_tenant_site_row') ? ckmqp_tenant_site_row($scopeId) : null;
            $root = is_array($site) && !empty($site['hostname']) ? 'https://'.$site['hostname'].'/' : home_url('/');
            wp_safe_redirect($root);
            exit;
        }
    }

    if (ckm_quiz_pro_can_organize()) {
        wp_safe_redirect(ckmqp_login_destination(get_current_user_id()));
        exit;
    }
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_front_login'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'ckm_qp_front_login')) {
            $error = 'Сессия формы истекла. Обновите страницу.';
        } else {
            $identity = trim((string)wp_unslash($_POST['ckm_identity'] ?? ($_POST['user_login'] ?? '')));
            $password = (string)wp_unslash($_POST['ckm_password'] ?? ($_POST['user_password'] ?? ''));
            $creds = [
                'user_login' => sanitize_text_field($identity),
                'user_password' => $password,
                'remember' => !empty($_POST['remember']),
            ];
            $u = wp_signon($creds, is_ssl());
            if (is_wp_error($u)) {
                $error = 'Неверный логин или пароль.';
            } elseif (!user_can($u, CKM_QUIZ_PRO_ORGANIZER_CAP) && !user_can($u, 'manage_options')) {
                wp_logout();
                $error = 'Для этой учётной записи кабинет организатора не разрешён.';
            } else {
                wp_safe_redirect(ckmqp_login_destination((int)$u->ID));
                exit;
            }
        }
    }
    $loginTitle = $scopeId > 0 ? 'Вход на площадку' : 'Вход в кабинет';
    $loginLead = $scopeId > 0
        ? 'Войдите под учётной записью организатора этой площадки.'
        : 'После входа вы перейдёте на свою площадку.';
    $siteRow = $scopeId > 0 && function_exists('ckmqp_tenant_site_row') ? ckmqp_tenant_site_row($scopeId) : null;
    ckm_quiz_pro_org_shell_start($loginTitle);
    echo '<div class="ckm-auth"><div class="ckm-card ckm-auth-card"><h1>'.esc_html($loginTitle).'</h1>';
    if ($siteRow) echo '<p><strong>'.esc_html($siteRow['name'] ?: ($siteRow['hostname'] ?? '')).'</strong></p>';
    echo '<p class="ckm-muted">'.esc_html($loginLead).'</p>';
    if (get_option('ckmqp_tenant_registration','0')==='1' && $scopeId===0) {
        echo '<div class="ckm-auth-register"><div><strong>Новая площадка?</strong><span>Создайте аккаунт организатора и свой адрес вида name.ckkm.ru.</span></div><a class="ckm-btn ckm-btn-primary" href="'.esc_url(home_url('/ckm-register/')).'">Зарегистрировать площадку</a></div>';
        echo '<div class="ckm-auth-divider"><span>или войдите в существующий кабинет</span></div>';
    }
    if ($error !== '') echo '<div class="ckm-alert ckm-alert-error">'.esc_html($error).'</div>';
    echo '<form method="post" autocomplete="off">'; wp_nonce_field('ckm_qp_front_login');
    // Use CKM-specific field names and opt out of browser credential autofill so a saved
    // owner account (for example leogorn) is not presented as the organizer of every tenant.
    echo '<label class="ckm-label">Логин или email<input class="ckm-input" name="ckm_identity" autocomplete="off" autocapitalize="none" spellcheck="false" value="" required></label><label class="ckm-label">Пароль<input class="ckm-input" type="password" name="ckm_password" autocomplete="new-password" value="" required></label><label class="ckm-check"><input type="checkbox" name="remember" value="1"> Запомнить меня</label><button class="ckm-btn ckm-btn-primary" name="ckm_qp_front_login" value="1">Войти</button></form>';
    if ($scopeId === 0) echo '<p><a href="'.esc_url(wp_lostpassword_url(ckm_quiz_pro_login_url())).'">Забыли пароль?</a></p>';
    echo '</div></div>';
    ckm_quiz_pro_org_shell_end();
}

function ckm_quiz_pro_preview_allows_demo_launch(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (ckm_quiz_pro_preview_organizer_user_id() <= 0) return false;
    if (!current_user_can('manage_options')) return false;
    if (empty($_POST['ckm_qp_front_create_game'])) return false;

    $quizId = absint($_POST['quiz_id'] ?? 0);
    if ($quizId <= 0) return false;

    global $wpdb;
    $slug = (string)$wpdb->get_var($wpdb->prepare(
        "SELECT slug FROM ".ckm_quiz_pro_table('quizzes')." WHERE id=%d AND status='published' LIMIT 1",
        $quizId
    ));
    if ($slug === '') return false;

    $builtInDemoSlugs = [
        'demo-classic-quiz',
        'demo-battle-experts',
        'ckm-demo-intellectual-battle',
        'demo-solution-price',
        'demo-negotiation-sales',
        'demo-negotiation-business',
        'demo-negotiation-express',
        'demo-negotiation-communicate',
    ];
    return in_array($slug, $builtInDemoSlugs, true);
}

function ckm_quiz_pro_render_organizer_cabinet(): void {
    if (!is_user_logged_in()) {
        $loginArgs = ckmqp_return_login_args();
        if (($_GET['view'] ?? '') === 'builder') $loginArgs['create_game'] = '1';
        wp_safe_redirect(ckm_quiz_pro_login_url($loginArgs));
        exit;
    }
    if (!ckm_quiz_pro_can_organize()) {
        status_header(403);
        ckm_quiz_pro_org_shell_start('Доступ запрещён');
        echo '<div class="ckm-card"><h1>Нет доступа</h1><p>Эта учётная запись не является организатором.</p></div>';
        ckm_quiz_pro_org_shell_end();
        return;
    }

    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && ckm_quiz_pro_preview_organizer_user_id()>0 && !ckm_quiz_pro_preview_allows_demo_launch()) {
        wp_die('Режим просмотра не позволяет изменять данные.', '', ['response'=>403]);
    }
    if (function_exists('ckmqp_test_return_screen') && ckmqp_test_return_screen()) return;
    if (function_exists('ckmqp_test_recover_recent')) ckmqp_test_recover_recent();

    // The organizer cabinet now starts with the fixed base/demo games.
    // Legacy links to the old Overview and Education Library remain valid,
    // but resolve into the Base Games area instead of creating duplicate
    // top-level sections.
    $view = sanitize_key($_GET['view'] ?? 'games');
    if (!in_array($view, ['home','mode','games','payment','library','new-game','results','builder','persuade-library','scenario-order','education-library'], true)) $view = 'games';
    if ($view === 'home') $view = 'mode';
    $historyAdmin = ckm_quiz_pro_history_admin_access(ckm_quiz_pro_effective_organizer_user_id(), ckmqp_scope_id());
    if ($view === 'results' && !$historyAdmin) $view = 'games';
    if (!ckm_quiz_pro_has_paid_cabinet_access(ckm_quiz_pro_effective_organizer_user_id()) && !in_array($view, ['mode','games','payment','builder','results','persuade-library','scenario-order','education-library'], true)) {
        // New organizers land on the catalog first. They can compare all seven
        // formats before choosing a paid product; gameplay/library views still
        // remain protected by the existing entitlement checks.
        $view = 'games';
    }

    if ($view === 'results' && $historyAdmin && isset($_GET['history_export'])) {
        $export=sanitize_key((string)$_GET['history_export']);
        $nonce=sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? ''));
        if($export==='list' && wp_verify_nonce($nonce,'ckm_qp_history_export_list')){
            ckm_quiz_pro_history_export_list(ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id());
            exit;
        }
        if($export==='game'){
            $gameId=absint($_GET['history_game'] ?? 0);
            if($gameId>0 && wp_verify_nonce($nonce,'ckm_qp_history_export_game_'.$gameId)){
                ckm_quiz_pro_history_export_game($gameId,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id());
                exit;
            }
        }
        status_header(403);
        wp_die('Ссылка экспорта недействительна или устарела.','', ['response'=>403]);
    }

    $notice = '';
    $gameResult = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_front_create_game'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'ckm_qp_front_create_game')) {
            $gameResult = ['ok'=>false,'error'=>'Сессия формы истекла.'];
        } else {
            $quizId = absint($_POST['quiz_id'] ?? 0);
            global $wpdb;
            $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".ckm_quiz_pro_table('quizzes')." WHERE id=%d AND status='published'", $quizId), ARRAY_A);
            if (!$quiz) {
                $gameResult = ['ok'=>false,'error'=>'Игра не найдена или не опубликована.'];
            } else {
                $previewDemoLaunch = ckm_quiz_pro_preview_organizer_user_id()>0 && ckm_quiz_pro_preview_allows_demo_launch();
                $requiredProduct=function_exists('ckm_quiz_pro_quiz_access_product') ? ckm_quiz_pro_quiz_access_product($quiz) : ckm_quiz_pro_access_format_key((string)($quiz['format_key'] ?? ''));
                $effectiveUid=ckm_quiz_pro_effective_organizer_user_id();
                $ownedCustom=((int)($quiz['created_by_user_id'] ?? 0)===$effectiveUid) && empty($quiz['package_id']);
                $customAccess=$ownedCustom && ckm_quiz_pro_has_paid_cabinet_access($effectiveUid);
                if (!$previewDemoLaunch && !$customAccess && !ckm_quiz_pro_can_access_format($effectiveUid,$requiredProduct)) {
                    $gameResult=['ok'=>false,'error'=>'Нет активного доступа к этой игре. Откройте базовые игры или каталог готовых игр.'];
                } else {
                    $isChgk=(string)($quiz['format_key'] ?? '') === 'chgk';
                    $quizMinTeams = $isChgk ? 1 : max(1, (int)($quiz['min_teams'] ?? 2));
                    $quizMaxTeams = $isChgk ? 1 : max($quizMinTeams, min(10, (int)($quiz['max_teams'] ?? 10)));
                    $requestedCount = (int)($_POST['team_count'] ?? $quizMinTeams);
                    $count = $isChgk ? 1 : max($quizMinTeams, min($quizMaxTeams, $requestedCount > 0 ? $requestedCount : $quizMinTeams));
                    $teams=[];
                    for($i=1;$i<=$count;$i++) {
                        $default = 'Команда '.ckm_quiz_core_team_key_from_slot($i);
                        $name = sanitize_text_field(wp_unslash($_POST['team_name_'.$i] ?? $default));
                        $teams[]=['name'=>$name !== '' ? $name : $default];
                    }
                    $hostMode=in_array((string)($quiz['host_mode']??'ai'),['ai','human'],true)?(string)$quiz['host_mode']:'ai';
                    $expressCanaryLaunch = function_exists('ckm_quiz_pro_express_canary_launch_allowed')
                        && ckm_quiz_pro_express_canary_launch_allowed($quiz, $count, $hostMode);
                    $gameResult = ckm_quiz_create_room([
                        'quiz_id'=>$quizId,
                        'team_count'=>$count,
                        'teams'=>$teams,
                        'host_mode'=>$hostMode,
                        'judge_mode'=>'ai',
                        'test_mode'=>($previewDemoLaunch || $expressCanaryLaunch) ? 1 : 0,
                        'ckm_runtime_config_json'=>isset($_POST['ckm_runtime_config_json']) ? wp_unslash($_POST['ckm_runtime_config_json']) : '',
                    ],get_current_user_id());
                }
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_save_quiz'])) {
        if (!ckm_quiz_pro_can_use_front_constructor()) {
            $view = 'builder';
        } else {
            $postedQuizId=absint($_POST['quiz_id'] ?? 0);
            $id = ckm_quiz_pro_save_quiz_from_post();
            if(current_user_can('manage_options') || $postedQuizId>0){
                wp_safe_redirect(ckm_quiz_pro_organizer_url(['view'=>'builder','quiz'=>$id,'saved'=>1]));
            } else {
                wp_safe_redirect(ckm_quiz_pro_organizer_url(['view'=>'library','created'=>1]));
            }
            exit;
        }
    }

    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_delete_history_game'])) {
        $gameId=absint($_POST['history_game_id'] ?? 0);
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $result=['ok'=>false,'error'=>'Некорректный запрос удаления.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0){
            $result=['ok'=>false,'error'=>'Удаление недоступно в режиме просмотра кабинета.'];
        } elseif($gameId>0 && wp_verify_nonce($nonce,'ckm_qp_delete_history_game_'.$gameId)){
            $result=ckm_quiz_pro_history_trash_game($gameId,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id(),sanitize_text_field(wp_unslash($_POST['history_confirm_code'] ?? '')));
        }
        $args=['view'=>'results'];
        foreach(['history_q','history_status','history_format','history_date_from','history_date_to','history_sort','history_page'] as $key){
            $value=trim(sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
            if($value!=='') $args[$key]=$value;
        }
        if(!empty($result['ok'])) $args['history_trashed']='1';
        else $args['history_delete_error']=(string)($result['error'] ?? 'Не удалось переместить запуск в корзину.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));
        exit;
    }


    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_bulk_delete_history_games'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $rawIds=isset($_POST['history_game_ids']) && is_array($_POST['history_game_ids']) ? wp_unslash($_POST['history_game_ids']) : [];
        $gameIds=[];
        foreach($rawIds as $rawId) if(is_scalar($rawId)) $gameIds[]=absint($rawId);
        $gameIds=array_values(array_unique(array_filter($gameIds)));
        $confirm=sanitize_text_field(wp_unslash($_POST['history_bulk_confirm'] ?? ''));
        $result=['ok'=>false,'error'=>'Некорректный запрос массового удаления.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0){
            $result=['ok'=>false,'error'=>'Удаление недоступно в режиме просмотра кабинета.'];
        } elseif(wp_verify_nonce($nonce,'ckm_qp_bulk_delete_history_games')){
            $result=ckm_quiz_pro_history_bulk_trash_games($gameIds,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id(),$confirm);
        }
        $args=['view'=>'results'];
        foreach(['history_q','history_status','history_format','history_date_from','history_date_to','history_sort','history_page'] as $key){
            $value=trim(sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
            if($value!=='') $args[$key]=$value;
        }
        if(!empty($result['ok'])) $args['history_bulk_trashed']=(string)max(1,(int)($result['count'] ?? 0));
        else $args['history_bulk_delete_error']=(string)($result['error'] ?? 'Не удалось переместить выбранные игры в корзину.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));
        exit;
    }

    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_restore_history_games'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $rawIds=isset($_POST['history_game_ids']) && is_array($_POST['history_game_ids']) ? wp_unslash($_POST['history_game_ids']) : [];
        $gameIds=[];foreach($rawIds as $rawId) if(is_scalar($rawId)) $gameIds[]=absint($rawId);
        $result=['ok'=>false,'error'=>'Некорректный запрос восстановления.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0) $result=['ok'=>false,'error'=>'Изменение истории недоступно в режиме просмотра кабинета.'];
        elseif(wp_verify_nonce($nonce,'ckm_qp_history_trash_actions')) $result=ckm_quiz_pro_history_restore_games($gameIds,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id());
        $args=['view'=>'results','history_trash'=>1];
        $period=ckm_quiz_pro_history_trash_period((string)($_POST['trash_period'] ?? 'all'));if($period!=='all')$args['trash_period']=$period;
        if(!empty($result['ok'])) $args['history_restored']=(string)max(1,(int)($result['count'] ?? 0)); else $args['history_trash_error']=(string)($result['error'] ?? 'Не удалось восстановить выбранные игры.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));exit;
    }

    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_permanent_delete_history_games'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $rawIds=isset($_POST['history_game_ids']) && is_array($_POST['history_game_ids']) ? wp_unslash($_POST['history_game_ids']) : [];
        $gameIds=[];foreach($rawIds as $rawId) if(is_scalar($rawId)) $gameIds[]=absint($rawId);
        $confirm=sanitize_text_field(wp_unslash($_POST['history_permanent_confirm'] ?? ''));
        $result=['ok'=>false,'error'=>'Некорректный запрос окончательного удаления.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0) $result=['ok'=>false,'error'=>'Изменение истории недоступно в режиме просмотра кабинета.'];
        elseif(wp_verify_nonce($nonce,'ckm_qp_history_trash_actions')) $result=ckm_quiz_pro_history_permanent_delete_games($gameIds,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id(),$confirm);
        $args=['view'=>'results','history_trash'=>1];
        $period=ckm_quiz_pro_history_trash_period((string)($_POST['trash_period'] ?? 'all'));if($period!=='all')$args['trash_period']=$period;
        if(!empty($result['ok'])) $args['history_permanent_deleted']=(string)max(1,(int)($result['count'] ?? 0)); else $args['history_trash_error']=(string)($result['error'] ?? 'Не удалось окончательно удалить выбранные игры.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));exit;
    }


    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_clear_entire_history_trash'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $result=['ok'=>false,'error'=>'Некорректный запрос очистки всей корзины.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0) $result=['ok'=>false,'error'=>'Изменение истории недоступно в режиме просмотра кабинета.'];
        elseif(wp_verify_nonce($nonce,'ckm_qp_history_clear_entire_trash')) $result=ckm_quiz_pro_history_clear_entire_trash(ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id());
        $args=['view'=>'results','history_trash'=>1];
        if(!empty($result['ok'])){
            $args['history_clear_entire_done']=(string)max(0,(int)($result['count'] ?? 0));
            if(!empty($result['failed'])) $args['history_clear_entire_failed']=(string)(int)$result['failed'];
        } else $args['history_trash_error']=(string)($result['error'] ?? 'Не удалось очистить всю корзину.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));exit;
    }


    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_purge_history_trash_all'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $confirm=sanitize_text_field(wp_unslash($_POST['trash_purge_confirm'] ?? ''));
        $period=ckm_quiz_pro_history_trash_period((string)($_POST['trash_period'] ?? 'all'));
        $result=['ok'=>false,'error'=>'Некорректный запрос полной очистки корзины.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0) $result=['ok'=>false,'error'=>'Изменение истории недоступно в режиме просмотра кабинета.'];
        elseif(wp_verify_nonce($nonce,'ckm_qp_history_trash_actions')) $result=ckm_quiz_pro_history_purge_all_trash(ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id(),$confirm);
        $args=['view'=>'results','history_trash'=>1];
        if($period!=='all') $args['trash_period']=$period;
        if(!empty($result['ok'])){
            $count=(int)($result['count'] ?? 0);
            if($count>0) $args['history_purged_all']=(string)$count; else $args['history_purge_all_none']='1';
            $remaining=(int)($result['remaining'] ?? 0);
            if($remaining>0) $args['history_purge_all_remaining']=(string)$remaining;
        } else $args['history_trash_error']=(string)($result['error'] ?? 'Не удалось полностью очистить корзину.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));exit;
    }

    if ($historyAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_purge_history_trash_by_age'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        $days=absint($_POST['trash_purge_days'] ?? 30);
        $confirm=sanitize_text_field(wp_unslash($_POST['trash_purge_confirm'] ?? ''));
        $period=ckm_quiz_pro_history_trash_period((string)($_POST['trash_period'] ?? 'all'));
        $result=['ok'=>false,'error'=>'Некорректный запрос очистки корзины.'];
        if(ckm_quiz_pro_preview_organizer_user_id()>0) $result=['ok'=>false,'error'=>'Изменение истории недоступно в режиме просмотра кабинета.'];
        elseif(wp_verify_nonce($nonce,'ckm_qp_history_trash_actions')) $result=ckm_quiz_pro_history_purge_trash_by_age($days,ckm_quiz_pro_effective_organizer_user_id(),ckmqp_scope_id(),$confirm);
        $args=['view'=>'results','history_trash'=>1];
        if($period!=='all') $args['trash_period']=$period;
        if(!empty($result['ok'])){
            $count=(int)($result['count'] ?? 0);
            if($count>0) $args['history_purged']=(string)$count; else $args['history_purge_none']='1';
            $remaining=(int)($result['remaining'] ?? 0);
            if($remaining>0) $args['history_purge_remaining']=(string)$remaining;
        } else $args['history_trash_error']=(string)($result['error'] ?? 'Не удалось очистить корзину.');
        wp_safe_redirect(ckm_quiz_pro_organizer_url($args));exit;
    }

    $previewUserId = ckm_quiz_pro_preview_organizer_user_id();
    $effectiveUser = get_user_by('id', ckm_quiz_pro_effective_organizer_user_id());
    ckm_quiz_pro_org_shell_start('Кабинет организатора', true, $view);
    echo '<div class="ckm-layout"><aside class="ckm-sidebar"><div class="ckm-org-name">'.esc_html(ckm_quiz_pro_display_label(get_bloginfo('name'))).'</div><nav>';
    echo '<div class="ckm-nav-section">РЕЖИМ РАБОТЫ</div>';
    ckm_quiz_pro_org_nav_link('mode','Выбор режима',$view);
    echo '<div class="ckm-nav-section">ИГРЫ</div>';
    ckm_quiz_pro_org_nav_link('games','Базовые игры',$view);
    ckm_quiz_pro_org_nav_link('education-library','Образовательные игры',$view);
    ckm_quiz_pro_org_nav_link('persuade-library','Каталог готовых игр',$view);
    echo '<div class="ckm-nav-section">СОЗДАНИЕ</div>';
    $customUrl=ckm_quiz_pro_create_own_game_url();
    echo '<a class="ckm-nav '.($view==='builder'?'active':'').'" href="'.esc_url($customUrl).'">Создать свою игру</a>';
    ckm_quiz_pro_org_nav_link('scenario-order','Заказать сюжет',$view);
    echo '<div class="ckm-nav-section">УПРАВЛЕНИЕ</div>';
    ckm_quiz_pro_org_nav_link('library','Мои игры',$view);
    if ($historyAdmin) ckm_quiz_pro_org_nav_link('results','История игр',$view);
    $effectiveName = $effectiveUser ? $effectiveUser->display_name : wp_get_current_user()->display_name;
    echo '</nav><div class="ckm-sidebar-foot"><div class="ckm-user">Аккаунт: '.esc_html($effectiveName).'</div><a href="'.esc_url(wp_logout_url(ckm_quiz_pro_login_url())).'">Выйти / сменить аккаунт</a></div></aside><main class="ckm-main">';

    if ($previewUserId > 0 && current_user_can('manage_options')) {
        echo '<div class="ckm-alert"><strong>Режим просмотра:</strong> вы видите кабинет так, как его видит организатор <strong>'.esc_html($effectiveName).'</strong>. <a href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-organizers')).'">Вернуться к организаторам</a></div>';
    }

    if ($view === 'mode') ckm_quiz_pro_org_mode();
    elseif ($view === 'games') ckm_quiz_pro_render_games_catalog(true);
    elseif ($view === 'payment') ckm_quiz_pro_org_payment();
    elseif ($view === 'library') ckm_quiz_pro_org_library();
    elseif ($view === 'persuade-library') ckm_quiz_pro_render_persuade_library();
    elseif ($view === 'education-library') ckm_quiz_pro_render_education_library();
    elseif ($view === 'scenario-order') ckm_quiz_pro_org_scenario_order();
    elseif ($view === 'new-game') ckm_quiz_pro_org_new_game($gameResult);
    elseif ($view === 'results') ckm_quiz_pro_org_results();
    elseif ($view === 'builder') ckm_quiz_pro_org_builder();
    else ckm_quiz_pro_org_home();

    echo '</main></div>';
    ckm_quiz_pro_org_shell_end();
}

function ckm_quiz_pro_org_nav_link(string $key,string $label,string $current): void {
    $url = ckm_quiz_pro_organizer_url($key==='home'?[]:['view'=>$key]);
    echo '<a class="ckm-nav '.($current===$key?'active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
}


function ckm_quiz_pro_org_mode(): void {
    $uid = ckm_quiz_pro_effective_organizer_user_id();
    $tenantId = (int)ckmqp_scope_id();
    $sub = ($tenantId > 0 && function_exists('ckm_quiz_pro_partner_subscription')) ? ckm_quiz_pro_partner_subscription($tenantId, true) : null;
    $partnerActive = is_array($sub);
    $isOwner = $tenantId > 0 && function_exists('ckm_quiz_pro_partner_user_is_owner') && ckm_quiz_pro_partner_user_is_owner($tenantId, $uid);
    $canPartnerPlan = $tenantId > 0 && function_exists('ckm_quiz_pro_partner_user_can_activate') && ckm_quiz_pro_partner_user_can_activate($tenantId, $uid);
    $request = ($tenantId > 0 && function_exists('ckm_quiz_pro_partner_request')) ? ckm_quiz_pro_partner_request($tenantId) : null;
    $plans = function_exists('ckm_quiz_pro_partner_plan_settings') ? ckm_quiz_pro_partner_plan_settings() : [];
    $gamesUrl = ckm_quiz_pro_organizer_url(['view'=>'games']);
    $partnerUrl = ($tenantId > 0 && function_exists('ckm_quiz_pro_partner_route_url')) ? ckm_quiz_pro_partner_route_url($tenantId) : '';

    echo '<div class="ckm-head"><div><div class="ckm-kicker">РЕЖИМ РАБОТЫ</div><h1>Как вы хотите работать с ЦКМ?</h1><p class="ckm-muted">Это один аккаунт и одна площадка. Можно покупать отдельные игры для собственных мероприятий или подключить партнёрский режим для работы со своими организаторами и клиентами.</p></div></div>';
    if ($partnerActive) {
        $label = $plans[$sub['plan_key']]['label'] ?? $sub['plan_key'];
        echo '<div class="ckm-alert"><strong>Сейчас активен «Партнёрский режим».</strong> Тариф ' . esc_html((string)$label) . ' · до ' . esc_html(wp_date('d.m.Y', strtotime((string)$sub['expires_at'] . ' UTC'))) . '.</div>';
    } elseif ($request) {
        $label = $plans[$request['plan_key']]['label'] ?? $request['plan_key'];
        echo '<div class="ckm-alert"><strong>Заявка на партнёрский режим отправлена.</strong> Выбран тариф ' . esc_html((string)$label) . '. До активации действует обычный режим покупки отдельных игр.</div>';
    }

    echo '<div class="ckm-grid ckm-direction-grid">';
    echo '<section class="ckm-card ckm-direction-card">';
    echo '<div class="ckm-kicker">РЕЖИМ 1' . (!$partnerActive ? ' · ТЕКУЩИЙ' : '') . '</div><h2>Покупать игры отдельно</h2>';
    echo '<p>Для организатора, который сам проводит игры и хочет оплачивать только нужные форматы.</p>';
    echo '<p class="ckm-muted">Каждая выбранная игра оплачивается отдельно на 30 дней. Лимит — ' . max(1,(int)get_option('ckmqp_organizer_monthly_session_limit',15)) . ' реальных запусков в месяц на каждую оплаченную игру. Тестовые запуски лимит не расходуют.</p>';
    echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="' . esc_url($gamesUrl) . '">Выбрать игры</a></div></section>';

    echo '<section class="ckm-card ckm-direction-card">';
    echo '<div class="ckm-kicker">РЕЖИМ 2' . ($partnerActive ? ' · ТЕКУЩИЙ' : '') . '</div><h2>Партнёрский режим</h2>';
    echo '<p>Для учебных центров, ведущих, агентств и организаторов, которые хотят работать через ЦКМ со своими клиентами.</p>';
    echo '<p class="ckm-muted">В партнёрский режим входят все 8 базовых игр, собственная площадка и возможность подключать своих организаторов. Все используют общий месячный пул. Готовые платные сценарии и игры под заказ приобретаются отдельно.</p>';
    if ($partnerActive && $isOwner && $partnerUrl !== '') {
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="' . esc_url($partnerUrl) . '">Открыть партнёрский кабинет</a></div>';
    } elseif ($partnerActive) {
        echo '<div class="ckm-alert">Вы работаете внутри площадки с активным партнёрским режимом. Управление тарифом доступно владельцу площадки.</div>';
    } elseif ($canPartnerPlan && $partnerUrl !== '') {
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="' . esc_url($partnerUrl) . '">Выбрать партнёрский тариф</a></div>';
    } else {
        echo '<div class="ckm-alert">У этой площадки нет права на подключение партнёрского режима.</div>';
    }
    echo '</section></div>';

    echo '<div class="ckm-card"><h2>Важно</h2><p>Переход в партнёрский режим не создаёт новый аккаунт и не меняет поддомен. Отдельно купленные игры сохраняют свой срок доступа. Если партнёрский режим закончится, партнёрские права отключатся, а отдельные покупки останутся до окончания их собственного срока.</p></div>';
}

function ckm_quiz_pro_org_home(): void {
    global $wpdb;
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $available=ckm_quiz_pro_allowed_rows($wpdb->get_results("SELECT format_key FROM ".ckm_quiz_pro_table('quizzes')." WHERE status='published' AND ".ckmqp_content_list_clause(),ARRAY_A)?:[],$uid);
    $historyAdmin=ckm_quiz_pro_history_admin_access($uid,ckmqp_scope_id());
    $history=[];
    if($historyAdmin){
        $historyTrash=ckm_quiz_pro_table('history_trash');
        $history=$wpdb->get_results($wpdb->prepare('SELECT g.format_key_snapshot,g.status FROM '.ckm_quiz_pro_table('games').' g LEFT JOIN '.$historyTrash.' ht ON ht.game_id=g.id WHERE g.tenant_id=%d AND ht.game_id IS NULL',ckmqp_scope_id()),ARRAY_A)?:[];
    }
    $q=count($available); $g=count($history); $f=count(array_filter($history,static function ($r) { return $r['status']==='finished'; }));
    $gamesUrl=ckm_quiz_pro_organizer_url(['view'=>'games']);
    $customUrl=ckm_quiz_pro_create_own_game_url();
    echo '<div class="ckm-head"><div><div class="ckm-kicker">КАБИНЕТ ОРГАНИЗАТОРА</div><h1>'.esc_html(ckm_quiz_pro_ui_text('organizer_home_title')).'</h1><p class="ckm-muted">'.esc_html(ckm_quiz_pro_ui_text('organizer_home_lead')).'</p></div></div>';
    echo '<div class="ckm-grid ckm-direction-grid">';
    echo '<section class="ckm-card ckm-direction-card"><div class="ckm-kicker">ЗНАКОМСТВО С ПЛАТФОРМОЙ</div><h2>Базовые игры</h2><p class="ckm-muted">Демонстрационные версии игровых форматов. Их содержимое фиксировано и не редактируется организатором. Здесь выбирают формат и оплачивают доступ.</p><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($gamesUrl).'">Открыть базовые игры</a></div></section>';
    echo '<section class="ckm-card ckm-direction-card"><div class="ckm-kicker">ГОТОВОЕ СОДЕРЖАНИЕ</div><h2>Каталог готовых игр</h2><p class="ckm-muted">Законченные платные игры с описанием сюжета. Каталог создаёт и редактирует администратор сайта.</p><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_persuade_library_url()).'">Открыть каталог</a></div></section>';
    echo '<section class="ckm-card ckm-direction-card"><div class="ckm-kicker">С НУЛЯ</div><h2>Создать свою игру</h2><p class="ckm-muted">После активной оплаты можно создать новую игру с нуля. Сохранённую собственную игру организатор сможет запускать и редактировать в разделе «Мои игры».</p><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($customUrl).'">Создать свою игру</a></div></section>';
    echo '<section class="ckm-card ckm-direction-card"><div class="ckm-kicker">ПОД ЗАКАЗ</div><h2>Заказать сюжет</h2><p class="ckm-muted">Если не хотите собирать игру самостоятельно, опишите задачу — администратор подготовит сюжет для нужного формата.</p><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_scenario_order_url()).'">Заказать сюжет</a></div></section>';
    echo '</div>';
    if (function_exists('ckmqp_primary_site_for_user')) { $mySite=ckmqp_primary_site_for_user($uid); if ($mySite && !empty($mySite['hostname'])) echo '<div class="ckm-card"><h2>Моя площадка</h2><p><strong>'.esc_html($mySite['name']).'</strong><br><a href="'.esc_url('https://'.$mySite['hostname'].'/').'">'.esc_html($mySite['hostname']).'</a></p><p class="ckm-muted">На площадке используется та же архитектура: деловые игры, интеллектуальные игры и собственные сценарии.</p><p><a class="ckm-btn" href="'.esc_url('https://'.$mySite['hostname'].'/').'">Открыть площадку</a></p></div>'; }
    $paid=ckm_quiz_pro_paid_products($uid); $expiredProducts=function_exists('ckm_quiz_pro_expired_products')?ckm_quiz_pro_expired_products($uid):[]; $expiring=[];
    foreach($paid as $key=>$product){ $info=function_exists('ckm_quiz_pro_game_access_info')?ckm_quiz_pro_game_access_info($uid,$key):[]; if(!empty($info['expiring_soon'])) $expiring[]=['key'=>$key,'title'=>$product['title'],'info'=>$info]; }
    echo '<div class="ckm-card"><div class="ckm-access-summary-head"><div><div class="ckm-kicker">ДОСТУП</div><h2>Доступ и оплата</h2><p class="ckm-muted">Активных доступов: '.count($paid).'. Истёкших: '.count($expiredProducts).'.</p></div><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'payment'])).'">Управлять доступами</a></div>';
    if($expiring){ echo '<div class="ckm-access-notices">'; foreach($expiring as $row){ $txt=!empty($row['info']['display_text'])?(string)$row['info']['display_text']:'Доступ скоро закончится'; echo '<p class="ckm-access-warning"><strong>'.esc_html($row['title']).':</strong> '.esc_html($txt).' <a href="'.esc_url(ckm_quiz_pro_access_renew_url($row['key'])).'">Продлить</a></p>'; } echo '</div>'; }
    echo '</div>';
    if($historyAdmin) echo '<div class="ckm-stats"><div class="ckm-stat"><span>Доступных шаблонов</span><strong>'.$q.'</strong></div><div class="ckm-stat"><span>Запусков</span><strong>'.$g.'</strong></div><div class="ckm-stat"><span>Завершено</span><strong>'.$f.'</strong></div></div>';
    else echo '<div class="ckm-stats"><div class="ckm-stat"><span>Доступных шаблонов</span><strong>'.$q.'</strong></div></div>';
    echo '<div class="ckm-card"><h2>Управление</h2><div class="ckm-feature-grid"><div><strong>Мои игры</strong><p>Готовые игры, купленные форматы и ваши установленные сценарии.</p><p><a href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'library'])).'">Открыть</a></p></div>';
    if($historyAdmin) echo '<div><strong>История игр</strong><p>Все проведённые игры площадки, режим ведущего и итоговые результаты команд.</p><p><a href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'results'])).'">Открыть</a></p></div>';
    echo '<div><strong>Доступ и оплата</strong><p>Срок действия форматов, продление и подключение новых игр.</p><p><a href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'payment'])).'">Открыть</a></p></div><div><strong>Создать свою игру</strong><p>'.(ckm_quiz_pro_can_use_front_constructor()?'Конструктор доступен: на площадке есть активная оплата.':'Любая активная оплата открывает создание собственной игры в поддерживаемом формате.').'</p><p><a href="'.esc_url($customUrl).'">Открыть</a></p></div></div></div>';
}

/** Purchase controls use the existing organizer design, with no new CSS. */
function ckm_quiz_pro_org_payment_catalog(array $selected = []): void {
    $icons = ['classic_quiz'=>'🧠', 'chgk_v1'=>'⚡', 'jeopardy_v1'=>'🏆', 'decision_price_v1'=>'💰', 'negotiation_duel_v1'=>'🤝',
        'persuade_school_v1'=>'🎓','persuade_school_grade_v1'=>'⚗','persuade_student_v1'=>'📚','persuade_leader_v1'=>'🧭','persuade_family_v1'=>'🏠'];
    $uid = ckm_quiz_pro_effective_organizer_user_id();
    $products = ckm_quiz_pro_game_access_products();
    $paid = [];
    $expired = [];
    $unpaid = [];
    $legacyScenarioProducts=['persuade_school_v1','persuade_student_v1','persuade_leader_v1','persuade_family_v1'];
    foreach ($products as $key=>$product) {
        $hasAccess=$uid > 0 && ckm_quiz_pro_can_access_format($uid,$key);
        // Legacy category placeholders are no longer sold as products. Keep
        // already-owned access visible for backward compatibility.
        if(in_array($key,$legacyScenarioProducts,true) && !$hasAccess) continue;
        $orderable=!array_key_exists('orderable',$product) || !empty($product['orderable']);
        if ($hasAccess) {
            $paid[$key]=$product;
        } elseif ($uid > 0 && function_exists('ckm_quiz_pro_game_access_lifecycle_info')) {
            $life=ckm_quiz_pro_game_access_lifecycle_info($uid,$key);
            if (!empty($life['expired'])) $expired[$key]=array_merge($product,['access_info'=>$life]);
            elseif($orderable) $unpaid[$key]=$product;
        } elseif($orderable) $unpaid[$key]=$product;
    }

    if ($paid) {
        echo '<section class="ckm-organizer-purchases" aria-label="'.esc_attr(ckm_quiz_pro_ui_text('payment_paid_title')).'"><h2>'.esc_html(ckm_quiz_pro_ui_text('payment_paid_title')).'</h2><div class="ckm-grid">';
        foreach ($paid as $key=>$product) {
            $open = ckm_quiz_pro_organizer_url(['view'=>'library','format'=>$key]);
            $accessInfo=function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info($uid,$key) : [];
            $accessText=!empty($accessInfo['display_text']) ? (string)$accessInfo['display_text'] : '';
            echo '<article class="ckm-card ckm-game-card"><div class="ckm-badge">'.esc_html(ckm_quiz_pro_ui_text('payment_paid_badge')).'</div><h2><span aria-hidden="true">'.esc_html($icons[$key] ?? '').'</span> '.esc_html($product['title']).'</h2>'; if (!empty($product['description'])) echo '<p class="ckm-muted">'.esc_html((string)$product['description']).'</p>';
            if ($accessText!=='') echo '<p class="ckm-format-price is-owned'.(!empty($accessInfo['expiring_soon'])?' is-expiring':'').'">'.esc_html($accessText).'</p>';
            if (!empty($accessInfo['expiring_soon'])) echo '<p class="ckm-access-warning"><strong>Доступ скоро закончится.</strong> Можно заранее добавить ещё 30 дней.</p>';
            echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($open).'">'.esc_html(ckm_quiz_pro_ui_text('payment_open_button')).'</a>';
            if (!empty($accessInfo['expiring_soon']) && function_exists('ckm_quiz_pro_access_renew_url')) echo '<a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_access_renew_url($key)).'">Продлить на 30 дней</a>';
            echo '</div></article>';
        }
        echo '</div></section>';
    }

    if ($expired) {
        echo '<section class="ckm-organizer-purchases" aria-label="Истёкшие доступы"><h2>Истёкшие доступы</h2><div class="ckm-grid">';
        foreach ($expired as $key=>$product) {
            $info=is_array($product['access_info']??null)?$product['access_info']:[];
            $text=!empty($info['expired_display_text'])?(string)$info['expired_display_text']:'Доступ истёк';
            echo '<article class="ckm-card ckm-game-card"><div class="ckm-badge">Срок завершён</div><h2><span aria-hidden="true">'.esc_html($icons[$key] ?? '').'</span> '.esc_html($product['title']).'</h2><p class="ckm-format-price is-expired">'.esc_html($text).'</p><p class="ckm-access-expired-note">Игры и результаты сохранены. Возобновление откроет новые запуски ещё на 30 дней.</p><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_access_renew_url($key)).'">Возобновить на 30 дней</a></div></article>';
        }
        echo '</div></section>';
    }

    if (!$unpaid) {
        if (!$expired) echo '<div class="ckm-card"><strong>'.esc_html(ckm_quiz_pro_ui_text('payment_all_paid_text')).'</strong></div>';
        return;
    }

    echo '<section class="ckm-organizer-purchases" aria-label="'.esc_attr(ckm_quiz_pro_ui_text('payment_unpaid_title')).'"><h2>'.esc_html($selected ? ckm_quiz_pro_ui_text('payment_add_other_title') : ckm_quiz_pro_ui_text('payment_unpaid_title')).'</h2><div class="ckm-grid">';
    foreach ($unpaid as $key=>$product) {
        $in_cart = in_array($key, $selected, true);
        $keys = array_values(array_unique(array_merge($selected, [$key])));
        $url = ckm_quiz_pro_organizer_url(['view'=>'payment', 'games'=>implode(',', $keys)]);
        echo '<article class="ckm-card ckm-game-card"><h2><span aria-hidden="true">'.esc_html($icons[$key] ?? '').'</span> '.esc_html($product['title']).'</h2>'; if (!empty($product['description'])) echo '<p class="ckm-muted">'.esc_html((string)$product['description']).'</p>';
        echo '<p class="ckm-muted">'.esc_html($product['price']).' ₽ / 30 дней</p><div class="ckm-card-actions">';
        if ($in_cart) echo '<span class="ckm-badge">'.esc_html(ckm_quiz_pro_ui_text('payment_in_order_badge')).'</span>';
        else echo '<a class="ckm-btn ckm-btn-primary" href="'.esc_url($url).'" aria-label="'.esc_attr('Добавить: '.$product['title']).'">'.esc_html($selected ? ckm_quiz_pro_ui_text('payment_add_button') : ckm_quiz_pro_ui_text('payment_buy_button')).'</a>';
        echo '</div></article>';
    }
    echo '</div></section>';
}

function ckm_quiz_pro_org_payment(): void {
    if (function_exists('ckmqp_test_enabled') && ckmqp_test_enabled()) echo '<p class="ckm-badge">Тестовая оплата</p>';
    $selection = isset($_GET['games']) ? wp_unslash($_GET['games']) : (isset($_GET['game']) ? wp_unslash($_GET['game']) : '');
    $cart = ckm_quiz_pro_payment_cart($selection);
    $uid = ckm_quiz_pro_effective_organizer_user_id();
    $removedPaid = [];
    echo '<div class="ckm-head"><div><div class="ckm-kicker">'.esc_html(ckm_quiz_pro_ui_text('payment_kicker')).'</div><h1>'.esc_html(ckm_quiz_pro_ui_text('payment_title')).'</h1></div></div>';
    if (is_wp_error($cart)) {
        echo '<p class="ckm-alert ckm-alert-error">'.esc_html($cart->get_error_message()).'</p>';
        ckm_quiz_pro_org_payment_catalog();
        return;
    }
    $renewRequested=!empty($_GET['renew']) && count($cart['format_keys'])===1;
    $renewKey=$renewRequested ? (string)$cart['format_keys'][0] : '';
    $renewActive=$renewRequested && $uid>0 && ckm_quiz_pro_can_access_format($uid,$renewKey);
    $renewFlow=$renewRequested && $uid>0;

    if ($cart['items'] && $uid > 0) {
        $keep=[];
        foreach ($cart['format_keys'] as $key) {
            if ($renewFlow && $key===$renewKey) $keep[]=$key;
            elseif (ckm_quiz_pro_can_access_format($uid,$key)) $removedPaid[]=$key;
            else $keep[]=$key;
        }
        if ($removedPaid) {
            $cart=ckm_quiz_pro_payment_cart($keep);
            echo '<p class="ckm-alert ckm-alert-ok">'.esc_html(ckm_quiz_pro_ui_text('payment_removed_paid_notice')).'</p>';
        }
    }

    if (!$cart['items']) {
        ckm_quiz_pro_org_payment_catalog();
        return;
    }
    $keys = $cart['format_keys'];
    if ($renewFlow) {
        $currentInfo=function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info($uid,$renewKey) : [];
        $newExpiry=function_exists('ckm_quiz_pro_access_extension_expires') ? ckm_quiz_pro_access_extension_expires($uid,$renewKey,30,null,time()) : '';
        $newTs=$newExpiry!=='' ? strtotime($newExpiry.' UTC') : false;
        $after=$newTs ? wp_date('d.m.Y',$newTs) : '';
        $wasActive=!empty($currentInfo['active']);
        echo '<p class="ckm-alert ckm-alert-ok"><strong>'.esc_html($wasActive?'Продление доступа.':'Возобновление доступа.').'</strong> '.esc_html($wasActive?'К текущему сроку будет добавлено ещё 30 дней':'После оплаты доступ будет активен 30 дней').($after!==''?' — до '.esc_html($after):'').'.</p>';
    }
    $icons = ['classic_quiz'=>'🧠', 'chgk_v1'=>'⚡', 'jeopardy_v1'=>'🏆', 'decision_price_v1'=>'💰', 'negotiation_duel_v1'=>'🤝'];
    echo '<div class="ckm-checkout"><section class="ckm-card ckm-checkout__card">';
    foreach ($cart['items'] as $item) {
        $key = $item['format_key'];
        $remaining = array_values(array_diff($keys, [$key]));
        $remove_url = ckm_quiz_pro_organizer_url(['view'=>'payment', 'games'=>implode(',', $remaining)]);
        echo '<div class="ckm-card"><h2><span aria-hidden="true">'.esc_html($icons[$key] ?? '').'</span> '.esc_html($item['title']).'</h2>';
        if ($renewFlow && $key===$renewKey) {
            $info=function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info($uid,$key) : [];
            if (!empty($info['display_text'])) echo '<p class="ckm-format-price is-owned">'.esc_html((string)$info['display_text']).'</p>';
            echo '<p class="ckm-muted">'.esc_html(!empty($info['active'])?'Продление: +30 дней к текущему сроку':'Возобновление: 30 дней с момента оплаты').'</p><p>Стоимость: '.esc_html((string)($item['amount_minor'] / 100)).' ₽</p>';
        } else {
            echo '<p class="ckm-muted">'.esc_html(ckm_quiz_pro_ui_text('payment_access_text')).'</p><p>Стоимость: '.esc_html((string)($item['amount_minor'] / 100)).' ₽</p>';
            echo '<a href="'.esc_url($remove_url).'" aria-label="'.esc_attr(ckm_quiz_pro_ui_text('payment_remove_from_order').': '.$item['title']).'">'.esc_html(ckm_quiz_pro_ui_text('payment_remove_from_order')).'</a>';
        }
        echo '</div>';
    }
    echo '<h2>'.esc_html(ckm_quiz_pro_ui_text('payment_total_prefix')).' '.esc_html((string)($cart['total_minor'] / 100)).' ₽</h2>';
    echo '<div class="ckm-card-actions"><button type="button" class="ckm-btn ckm-btn-primary" data-ckm-payment-games="'.esc_attr(implode(',', $keys)).'"'.($renewFlow?' data-ckm-renew="1"':'').'>'.esc_html($renewFlow?($renewActive?'Продлить на 30 дней':'Возобновить на 30 дней'):ckm_quiz_pro_ui_text('payment_checkout_button')).'</button>';
    $unpaidKeys=[];
    foreach (ckm_quiz_pro_game_access_products() as $productKey=>$product) {
        if (ckm_quiz_pro_can_access_format($uid,$productKey)) continue;
        if(array_key_exists('orderable',$product) && empty($product['orderable'])) continue;
        $unpaidKeys[]=$productKey;
    }
    if (!$renewFlow && count($keys) < count($unpaidKeys)) {
        $add_url = ckm_quiz_pro_organizer_url(['view'=>'payment', 'games'=>implode(',', $keys), 'add_games'=>1]);
        echo '<a class="ckm-btn" href="'.esc_url($add_url).'#ckm-add-games">'.esc_html(ckm_quiz_pro_ui_text('payment_add_other_button')).'</a>';
    }
    echo '</div><p class="ckm-checkout__status" role="status" aria-live="polite" hidden></p><noscript><p>Для перехода к оплате включите JavaScript в браузере.</p></noscript></section></div>';
    if (!$renewFlow && !empty($_GET['add_games']) && count($keys) < count($unpaidKeys)) {
        echo '<div id="ckm-add-games">';
        ckm_quiz_pro_org_payment_catalog($keys);
        echo '</div>';
    }
    $config = ['ajax_url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('ckm_create_payment')];
    $previewUid=ckm_quiz_pro_preview_organizer_user_id();
    if ($previewUid>0) {
        $config['preview_user']=$previewUid;
        $config['preview_nonce']=wp_create_nonce('ckm_qp_preview_organizer_'.$previewUid);
    }
    echo '<script>window.CKM_CHECKOUT='.wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>';
    echo '<script src="'.esc_url(add_query_arg('ver', CKM_QUIZ_PRO_VERSION, CKM_QUIZ_PRO_URL.'assets/checkout/checkout.js')).'" defer></script>';
}

function ckm_quiz_pro_quiz_display_title(array $quiz): string {
    $slug=sanitize_key((string)($quiz['slug']??''));
    $builtins=[
        'demo-classic-quiz'=>'Классический квиз',
        'demo-battle-experts'=>'Битва знатоков',
        'ckm-demo-intellectual-battle'=>'Интеллектуальный батл',
        'demo-solution-price'=>'Управленческая игра "Ваш выбор"',
        'demo-negotiation-sales'=>'Эффективный продажник',
        'demo-negotiation-business'=>'Мастер переговоров',
        'demo-negotiation-express'=>'Экспресс-раунд',
        'demo-negotiation-communicate'=>'Переговори другого',
        'demo-negotiation-communicate-school'=>'Переговори другого — для школьников',
        'demo-negotiation-communicate-school-grade'=>'Переговори другого — для школьников: «Двойка, которой не было»',
        'demo-negotiation-communicate-student'=>'Переговори другого — для студентов',
        'demo-negotiation-communicate-leader'=>'Переговори другого — для руководителей',
        'demo-negotiation-communicate-family'=>'Переговори другого — Семейные ситуации',
    ];
    if(isset($builtins[$slug])) return $builtins[$slug];
    $title=trim((string)($quiz['title']??''));
    $title=(string)preg_replace('/^\s*Демо:\s*/u','',$title);
    return $title!==''?$title:'Игра';
}

function ckm_quiz_pro_org_library_launch_args(array $row): array {
    $args=['view'=>'new-game','quiz'=>(int)($row['id']??0)];
    $product=function_exists('ckm_quiz_pro_quiz_access_product')?ckm_quiz_pro_quiz_access_product($row):'';
    $scenarioRuntimeByProduct=[
        'persuade_school_v1'=>'persuade_school_v1',
        'persuade_school_grade_v1'=>'persuade_school_grade_v1',
        'persuade_student_v1'=>'persuade_student_v1',
        'persuade_leader_v1'=>'persuade_leader_v1',
        'persuade_family_v1'=>'persuade_family_v1',
    ];
    if(isset($scenarioRuntimeByProduct[$product])) $args['ckm_format']=$scenarioRuntimeByProduct[$product];
    return $args;
}

function ckm_quiz_pro_org_library(): void {
    global $wpdb;
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $tenant=function_exists('ckm_quiz_pro_access_tenant_id')?ckm_quiz_pro_access_tenant_id($uid,null):(function_exists('ckmqp_scope_id')?ckmqp_scope_id():0);

    // Purchases made before snapshot support receive their immutable copy lazily.
    if(function_exists('ckm_quiz_pro_ready_game_ensure_active_snapshots')) ckm_quiz_pro_ready_game_ensure_active_snapshots($uid,$tenant>=0?$tenant:null);

    $rows=$wpdb->get_results("SELECT q.*, p.package_id,p.package_version,p.trust_level,p.editable FROM ".ckm_quiz_pro_table('quizzes')." q LEFT JOIN ".ckm_quiz_pro_table('packages')." p ON p.quiz_id=q.id WHERE q.status='published' AND ".ckmqp_content_list_clause('q')." ORDER BY q.updated_at DESC,q.id DESC",ARRAY_A) ?: [];

    // Administrator catalogue SOURCE templates never belong to an organizer's library.
    if(function_exists('ckm_quiz_pro_ready_game_source_product_for_quiz')){
        $rows=array_values(array_filter($rows,static function($row){
            return ckm_quiz_pro_ready_game_source_product_for_quiz((int)($row['id']??0))==='';
        }));
    }

    $readyByQuiz=[];
    if(function_exists('ckm_quiz_pro_ready_instances_table_ready') && ckm_quiz_pro_ready_instances_table_ready() && function_exists('ckm_quiz_pro_ready_instance_scope')){
        $scope=ckm_quiz_pro_ready_instance_scope($uid,$tenant>=0?$tenant:null);
        if(!empty($scope['owner_key'])){
            $instanceRows=$wpdb->get_results($wpdb->prepare(
                'SELECT snapshot_quiz_id,product_key,catalog_revision,catalog_snapshot_json,created_at FROM '.ckm_quiz_pro_table('ready_instances').' WHERE owner_key=%s AND status=%s ORDER BY id DESC',
                (string)$scope['owner_key'],'active'
            ),ARRAY_A)?:[];
            foreach($instanceRows as $instance){
                $qid=(int)($instance['snapshot_quiz_id']??0);
                if($qid>0) $readyByQuiz[$qid]=$instance;
            }
        }
    }

    $baseProductKeys=['classic_quiz','chgk_v1','jeopardy_v1','decision_price_v1','negotiation_duel_v1'];
    $base=[];$ready=[];$own=[];
    foreach($rows as $row){
        $quizId=(int)($row['id']??0);
        $product=function_exists('ckm_quiz_pro_quiz_access_product')?ckm_quiz_pro_quiz_access_product($row):'';
        if(isset($readyByQuiz[$quizId])){
            $row['_library_product']=sanitize_key((string)$readyByQuiz[$quizId]['product_key']);
            $row['_library_instance']=$readyByQuiz[$quizId];
            $ready[]=$row;
            continue;
        }
        $scopeType=sanitize_key((string)($row['content_scope']??''));
        $managed=!empty($row['package_id']);
        if($scopeType==='private' && !$managed){
            $row['_library_product']=$product;
            $own[]=$row;
            continue;
        }
        if(in_array($product,$baseProductKeys,true)){
            $life=function_exists('ckm_quiz_pro_game_access_lifecycle_info')?ckm_quiz_pro_game_access_lifecycle_info($uid,$product,$tenant>=0?$tenant:null):[];
            if(!empty($life['ever_had_access'])){
                $row['_library_product']=$product;
                $row['_library_access']=$life;
                $base[]=$row;
            }
            continue;
        }
        // Legacy paid scenario rows stay visible under Ready Games until they are migrated to catalogue snapshots.
        if($product!==''){
            $life=function_exists('ckm_quiz_pro_game_access_lifecycle_info')?ckm_quiz_pro_game_access_lifecycle_info($uid,$product,$tenant>=0?$tenant:null):[];
            if(!empty($life['ever_had_access'])){
                $row['_library_product']=$product;
                $row['_library_access']=$life;
                $ready[]=$row;
            }
        }
    }

    $requested=is_string($_GET['format']??null)?ckm_quiz_pro_access_format_key($_GET['format']):'';
    if($requested!==''){
        $filter=static function(array $row) use ($requested): bool {
            return sanitize_key((string)($row['_library_product']??''))===$requested;
        };
        $base=array_values(array_filter($base,$filter));
        $ready=array_values(array_filter($ready,$filter));
        $own=array_values(array_filter($own,static function(array $row) use ($requested): bool {
            $product=function_exists('ckm_quiz_pro_quiz_access_product')?ckm_quiz_pro_quiz_access_product($row):'';
            return $product===$requested;
        }));
    }

    $cabinetActive=function_exists('ckm_quiz_pro_has_paid_cabinet_access')?ckm_quiz_pro_has_paid_cabinet_access($uid,$tenant>=0?$tenant:null):false;
    echo '<div class="ckm-head"><div><div class="ckm-kicker">МОИ ИГРЫ</div><h1>Мои игры</h1><p class="ckm-muted">Базовые оплаченные игры, купленные готовые сюжеты и созданные вами игры показаны отдельно. Готовые и собственные игры сохраняются даже после окончания доступа.</p></div></div>';
    if(isset($_GET['paid'])) {
        $paidKey=is_string($_GET['format']??null)?ckm_quiz_pro_access_format_key(wp_unslash($_GET['format'])):'';
        $products=ckm_quiz_pro_game_access_products();
        $paidTitle=$paidKey!=='' && isset($products[$paidKey]['title']) ? (string)$products[$paidKey]['title'] : '';
        echo '<div class="ckm-alert ckm-alert-ok"><strong>Оплата подтверждена.</strong> '.esc_html($paidTitle!==''?'Доступ к игре «'.$paidTitle.'» активирован.':'Доступ активирован.').' Теперь можно запускать оплаченные игры и создавать собственные игры с нуля.</div>';
    }
    if(isset($_GET['created'])) echo '<div class="ckm-alert ckm-alert-ok"><strong>Игра создана.</strong> Она сохранена в разделе «Собственные игры». Вы можете запускать и редактировать её, пока активен доступ к конструктору.</div>';

    echo '<div class="ckm-history-summary" aria-label="Сводка по моим играм">';
    echo '<div class="ckm-history-summary-card"><span>Базовые</span><strong>'.count($base).'</strong></div>';
    echo '<div class="ckm-history-summary-card"><span>Готовые</span><strong>'.count($ready).'</strong></div>';
    echo '<div class="ckm-history-summary-card"><span>Собственные</span><strong>'.count($own).'</strong></div>';
    echo '</div>';

    $renderCard=static function(array $r,string $kind) use ($wpdb,$uid,$tenant,$cabinetActive): void {
        $quizId=(int)($r['id']??0);
        $product=sanitize_key((string)($r['_library_product']??''));
        if($product==='' && function_exists('ckm_quiz_pro_quiz_access_product')) $product=ckm_quiz_pro_quiz_access_product($r);
        $cnt=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_pro_table('questions')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",$quizId,(int)($r['current_revision']??1)));
        $formatTitle=function_exists('ckm_quiz_pro_quiz_format_title')?ckm_quiz_pro_quiz_format_title($r):ckm_quiz_pro_format_title((string)($r['format_key']??''));
        $life=($kind==='own' || $product==='')?[]:(function_exists('ckm_quiz_pro_game_access_lifecycle_info')?ckm_quiz_pro_game_access_lifecycle_info($uid,$product,$tenant>=0?$tenant:null):[]);
        $active=$kind==='own' ? $cabinetActive : !empty($life['active']);
        $typeLabel=$kind==='base'?'Базовая игра':($kind==='ready'?'Готовая игра':'Собственная игра');
        echo '<article class="ckm-card ckm-game-card">';
        echo '<div class="ckm-badge">'.esc_html($typeLabel).'</div><h2>'.esc_html(ckm_quiz_pro_quiz_display_title($r)).'</h2>';
        echo '<p class="ckm-muted">'.esc_html($formatTitle).' · '.$cnt.' вопросов</p>';
        if($kind==='own'){
            echo '<p class="ckm-format-price '.($active?'is-owned':'').'">'.esc_html($active?'Можно запускать · активен доступ к платформе':'Игра сохранена · для запуска нужен любой активный доступ').'</p>';
        } elseif(!empty($life['display_text'])){
            echo '<p class="ckm-format-price '.(!empty($life['active'])?'is-owned':'').'">'.esc_html((string)$life['display_text']).'</p>';
        }
        if($kind==='ready' && !empty($r['_library_instance'])){
            $catalogRevision=max(1,(int)($r['_library_instance']['catalog_revision']??1));
            echo '<p class="ckm-muted">Куплена редакция каталога: v'.$catalogRevision.'</p>';
            if(!empty($r['_library_instance']['catalog_snapshot_json'])){
                $snapshot=json_decode((string)$r['_library_instance']['catalog_snapshot_json'],true);
                if(is_array($snapshot) && trim((string)($snapshot['description']??''))!=='') echo '<p class="ckm-muted">'.esc_html((string)$snapshot['description']).'</p>';
            }
        }
        echo '<div class="ckm-card-actions">';
        if($active){
            echo '<a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_organizer_url(ckm_quiz_pro_org_library_launch_args($r))).'">Запустить</a>';
            if($kind!=='own' && $product!=='' && function_exists('ckm_quiz_pro_access_renew_url')) echo '<a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_access_renew_url($product)).'">Продлить доступ</a>';
        } else {
            if($kind==='own') echo '<a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'games'])).'">Активировать доступ</a>';
            elseif($product!=='' && function_exists('ckm_quiz_pro_access_renew_url')) echo '<a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_access_renew_url($product)).'">Возобновить доступ</a>';
        }
        if($kind==='own' && $cabinetActive && function_exists('ckm_quiz_pro_can_edit_owned_quiz') && ckm_quiz_pro_can_edit_owned_quiz($r,$uid)) echo '<a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'builder','quiz'=>$quizId])).'">Редактировать</a>';
        echo '</div></article>';
    };

    echo '<section class="ckm-library-section"><div class="ckm-head"><div><div class="ckm-kicker">ОПЛАЧЕННЫЕ ФОРМАТЫ</div><h2>Базовые игры</h2><p class="ckm-muted">Неизменяемые демонстрационные игры для знакомства с механикой форматов.</p></div></div><div class="ckm-grid">';
    foreach($base as $row) $renderCard($row,'base');
    if(!$base) echo '<div class="ckm-card"><h3>Оплаченных базовых игр пока нет</h3><p class="ckm-muted">Выберите формат в разделе «Базовые игры».</p><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'games'])).'">Открыть базовые игры</a></div>';
    echo '</div></section>';

    echo '<section class="ckm-library-section"><div class="ckm-head"><div><div class="ckm-kicker">КУПЛЕННЫЕ СЮЖЕТЫ</div><h2>Готовые игры</h2><p class="ckm-muted">Купленные игры из каталога. Их приватная snapshot-копия не меняется при последующем редактировании каталога администратором.</p></div></div><div class="ckm-grid">';
    foreach($ready as $row) $renderCard($row,'ready');
    if(!$ready) echo '<div class="ckm-card"><h3>Готовых игр пока нет</h3><p class="ckm-muted">Вы можете купить законченную игру с готовым сюжетом.</p><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_persuade_library_url()).'">Каталог готовых игр</a></div>';
    echo '</div></section>';

    echo '<section class="ckm-library-section"><div class="ckm-head"><div><div class="ckm-kicker">СОЗДАНО ВАМИ</div><h2>Собственные игры</h2><p class="ckm-muted">Игры, созданные через конструктор с нуля. Организатор может запускать и редактировать собственные игры, пока активен доступ к конструктору.</p></div></div><div class="ckm-grid">';
    foreach($own as $row) $renderCard($row,'own');
    if(!$own) echo '<div class="ckm-card"><h3>Собственных игр пока нет</h3><p class="ckm-muted">После любой активной оплаты можно создать игру с нуля.</p><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_create_own_game_url()).'">Создать свою игру</a></div>';
    echo '</div></section>';
}


function ckm_quiz_pro_org_scenario_order(): void {
    $savedId=0;$error='';
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    if (($_SERVER['REQUEST_METHOD']??'')==='POST' && isset($_POST['ckm_qp_scenario_order'])) {
        $nonce=sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!wp_verify_nonce($nonce,'ckm_qp_scenario_order')) {
            $error='Сессия формы истекла. Обновите страницу и повторите отправку.';
        } else {
            $result=function_exists('ckm_quiz_pro_scenario_order_create') ? ckm_quiz_pro_scenario_order_create($uid,[
                'audience'=>wp_unslash($_POST['scenario_audience'] ?? ''),
                'game_format'=>wp_unslash($_POST['scenario_format'] ?? ''),
                'theme'=>wp_unslash($_POST['scenario_theme'] ?? ''),
                'details'=>wp_unslash($_POST['scenario_details'] ?? ''),
            ]) : new WP_Error('scenario_module','Модуль заказов недоступен.');
            if(is_wp_error($result)) $error=$result->get_error_message(); else $savedId=(int)$result;
        }
    }
    echo '<div class="ckm-head"><div><div class="ckm-kicker">СЮЖЕТ ПОД ЗАКАЗ</div><h1>Заказать сюжет игры</h1><p class="ckm-muted">Вы можете заказать у администратора готовый сюжет для любой игры платформы. Укажите аудиторию, формат и ситуации, которые нужно отработать.</p></div></div>';
    if($savedId>0) echo '<div class="ckm-alert ckm-alert-ok"><strong>Заказ #'.(int)$savedId.' сохранён.</strong> Администратор увидит его в очереди заказов. Статус можно отслеживать ниже.</div>';
    elseif($error!=='') echo '<div class="ckm-alert ckm-alert-error">'.esc_html($error).'</div>';
    echo '<div class="ckm-card"><form method="post">';wp_nonce_field('ckm_qp_scenario_order');
    echo '<label class="ckm-label">Для кого сценарий<input class="ckm-input" name="scenario_audience" placeholder="Например: отдел закупок, 8–9 класс, студенты-медики" required></label>';
    echo '<label class="ckm-label">Формат игры<input class="ckm-input" name="scenario_format" placeholder="Например: Переговори другого, Битва знатоков, Управленческая игра &quot;Ваш выбор&quot;"></label>';
    echo '<label class="ckm-label">Тема или контекст<input class="ckm-input" name="scenario_theme" placeholder="Например: конфликты в проектной команде"></label>';
    echo '<label class="ckm-label">Что нужно<textarea class="ckm-input" name="scenario_details" rows="8" placeholder="Опишите типичные ситуации, цели обучения, возраст или должности участников, желаемую сложность и любые ограничения." required></textarea></label>';
    echo '<div class="ckm-card-actions"><button class="ckm-btn ckm-btn-primary" name="ckm_qp_scenario_order" value="1">Отправить заказ</button><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_persuade_library_url()).'">Каталог готовых игр</a></div></form></div>';

    $orders=function_exists('ckm_quiz_pro_scenario_orders_for_user')?ckm_quiz_pro_scenario_orders_for_user($uid):[];
    echo '<section class="ckm-library-section"><div class="ckm-head"><div><div class="ckm-kicker">МОИ ЗАКАЗЫ</div><h2>Заказанные сюжеты</h2><p class="ckm-muted">Заявка сохраняется в платформе независимо от email. Здесь отображается текущий статус и ответ администратора.</p></div></div>';
    if(!$orders){ echo '<div class="ckm-card"><p class="ckm-muted">У вас пока нет заказов сюжетов.</p></div></section>'; return; }
    echo '<div class="ckm-grid">';
    foreach($orders as $order){
        $status=function_exists('ckm_quiz_pro_scenario_order_status_label')?ckm_quiz_pro_scenario_order_status_label((string)$order['status']):(string)$order['status'];
        $readyUrl=function_exists('ckm_quiz_pro_scenario_order_ready_game_url')?ckm_quiz_pro_scenario_order_ready_game_url($order):'';
        echo '<article class="ckm-card"><div class="ckm-format-top"><span class="ckm-badge">'.esc_html($status).'</span><span class="ckm-muted">#'.(int)$order['id'].'</span></div><h3>'.esc_html((string)($order['theme']?:'Сюжет под заказ')).'</h3><p class="ckm-muted"><strong>Формат:</strong> '.esc_html((string)($order['game_format']?:'не указан')).'<br><strong>Для кого:</strong> '.esc_html((string)$order['audience']).'</p><p>'.esc_html(wp_trim_words((string)$order['details'],32,'…')).'</p>';
        if(trim((string)$order['admin_note'])!=='') echo '<p class="ckm-muted"><strong>Комментарий администратора:</strong><br>'.nl2br(esc_html((string)$order['admin_note'])).'</p>';
        if($readyUrl!=='') echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($readyUrl).'">Открыть готовую игру</a></div>';
        echo '</article>';
    }
    echo '</div></section>';
}

function ckm_quiz_pro_org_new_game($result): void {
    global $wpdb;
    $quizzes=$wpdb->get_results("SELECT id,title,slug,format_key,format_settings_json,host_mode,min_teams,max_teams,created_by_user_id,content_scope FROM ".ckm_quiz_pro_table('quizzes')." WHERE status='published' AND ".ckmqp_content_list_clause()." ORDER BY title",ARRAY_A) ?: [];
    $quizzes=ckm_quiz_pro_allowed_rows($quizzes,ckm_quiz_pro_effective_organizer_user_id());
    $preferred=absint($_GET['quiz']??0);
    $hubRuntime = function_exists('ckmqp_hub_runtime_from_request') ? ckmqp_hub_runtime_from_request() : '';
    $scenarioRuntimeVariant=[
        'persuade_school_v1'=>'school',
        'persuade_school_grade_v1'=>'school_grade',
        'persuade_student_v1'=>'student',
        'persuade_leader_v1'=>'leader',
        'persuade_family_v1'=>'family',
    ];
    $requestedScenarioVariant=$scenarioRuntimeVariant[$hubRuntime] ?? null;
    $quizzes=array_values(array_filter($quizzes,static function($q) use ($requestedScenarioVariant){
        if(sanitize_key((string)($q['format_key']??''))!=='negotiation_duel') return true;
        $settings=ckm_quiz_json_decode((string)($q['format_settings_json']??''));
        if(!is_array($settings) || sanitize_key((string)($settings['negotiationMode']??''))!=='communicate') return true;
        $variant=sanitize_key((string)($settings['persuadeMeVariant']??''));
        if(!in_array($variant,['school','school_grade','student','leader','family'],true)) return true;
        return $requestedScenarioVariant!==null && $variant===$requestedScenarioVariant;
    }));
    if (!$preferred && $hubRuntime !== '' && function_exists('ckmqp_hub_find_quiz_for_runtime')) {
        $preferred = ckmqp_hub_find_quiz_for_runtime($quizzes, $hubRuntime);
    } elseif (!$preferred && function_exists('ckmqp_hub_requested_core_format')) {
        $requestedHubFormat = ckmqp_hub_requested_core_format();
        if ($requestedHubFormat !== '') {
            foreach ($quizzes as $candidate) {
                if (sanitize_key((string)($candidate['format_key'] ?? '')) === $requestedHubFormat) {
                    $preferred = (int)$candidate['id'];
                    break;
                }
            }
        }
    }
    if (in_array($hubRuntime,['persuade_me_v1','persuade_school_v1','persuade_school_grade_v1','persuade_student_v1','persuade_leader_v1','persuade_family_v1'],true) && !$preferred) { echo '<div class="ckm-alert">Выбранный сценарий «Переговори другого» ещё не подготовлен или доступ к нему не активирован.</div>'; return; }
    if ($preferred && !in_array($preferred,array_map(static function ($q) {return (int)$q['id'];},$quizzes),true)) { ckm_quiz_pro_format_denied(); return; }
    echo '<div class="ckm-head"><div><div class="ckm-kicker">'.esc_html(ckm_quiz_pro_ui_text('new_game_kicker')).'</div><h1>'.esc_html(ckm_quiz_pro_ui_text('new_game_title')).'</h1><p class="ckm-muted">'.esc_html(ckm_quiz_pro_ui_text('new_game_lead')).'</p></div></div>';
    if(is_array($result)){
        if(empty($result['ok'])) echo '<div class="ckm-alert ckm-alert-error">'.esc_html($result['error']??'Ошибка создания игры').'</div>';
        else ckm_quiz_pro_org_credentials($result);
    }
    echo '<div class="ckm-card"><form method="post" class="ckm-quiz-constructor" data-ckm-constructor>'; wp_nonce_field('ckm_qp_front_create_game');
    if (function_exists('ckm_quiz_pro_express_canary_launch_requested') && ckm_quiz_pro_express_canary_launch_requested()) {
        echo '<input type="hidden" name="ckm_express_canary" value="1">';
        echo '<div class="ckm-alert ckm-alert-ok"><strong>CANARY:</strong> следующий запуск встроенного «Экспресс-раунда» будет тестовым и не попадёт в коммерческую историю.</div>';
    }
    if ($hubRuntime !== '') {
        $runtimePayload = function_exists('ckmqp_hub_request_runtime_config') ? ckmqp_hub_request_runtime_config(array('ckm_format'=>$hubRuntime)) : array();
        echo '<input type="hidden" name="ckm_format" value="'.esc_attr($hubRuntime).'">';
        if ($runtimePayload) echo '<input type="hidden" name="ckm_runtime_config_json" value="'.esc_attr(wp_json_encode($runtimePayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'">';
    }
    echo '<div class="ckm-form-grid"><label class="ckm-label">Игра<select class="ckm-input" name="quiz_id" required>';
    foreach($quizzes as $q) {
        $minTeams=((string)($q['format_key']??'')==='chgk')?1:max(1,(int)($q['min_teams']??2));
        $maxTeams=((string)($q['format_key']??'')==='chgk')?1:max($minTeams,min(10,(int)($q['max_teams']??10)));
        $isMini=in_array((string)($q['slug']??''),['demo-solution-price','demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express'],true);
        $isPersuade=function_exists('ckm_quiz_pro_quiz_is_persuade_me') && ckm_quiz_pro_quiz_is_persuade_me($q);
        $singleTeamFriendly=in_array((string)($q['format_key']??''),['chgk','solution_price'],true)||$isMini;
        $defaultTeams=$isPersuade?3:($singleTeamFriendly?1:max(2,$minTeams));
        $displayTitle=ckm_quiz_pro_quiz_display_title($q);
        echo '<option value="'.(int)$q['id'].'" data-show-prep="'.($isPersuade?'1':'0').'" data-host="'.esc_attr((string)($q['host_mode']??'ai')).'" data-min-teams="'.$minTeams.'" data-max-teams="'.$maxTeams.'" data-default-teams="'.$defaultTeams.'" data-single-team="'.(((string)($q['format_key']??'')==='chgk')?'1':'0').'" data-solution-mini="'.($isMini?'1':'0').'" data-mini-slug="'.esc_attr((string)($q['slug']??'')).'" '.selected($preferred,(int)$q['id'],false).'>'.esc_html($displayTitle).'</option>';
    }
    echo '</select></label><label class="ckm-label" id="ckm-team-count-wrap">Количество команд<input class="ckm-input" type="number" name="team_count" value="1" min="1" max="10" id="ckm-team-count"></label></div><div id="ckm-team-names" class="ckm-form-grid"></div><p class="ckm-muted" id="ckm-host-mode-note">Режим ведущего определяется настройкой выбранной игры.</p><button class="ckm-btn ckm-btn-primary" name="ckm_qp_front_create_game" value="1">'.esc_html(ckm_quiz_pro_ui_text('new_game_create_button')).'</button></form></div>';
    echo '<script>(function(){const n=document.getElementById("ckm-team-count"),w=document.getElementById("ckm-team-count-wrap"),b=document.getElementById("ckm-team-names"),q=document.querySelector("select[name=quiz_id]"),h=document.getElementById("ckm-host-mode-note");function selected(){return q&&q.options[q.selectedIndex]?q.options[q.selectedIndex]:null;}function bounds(applyDefault){if(!q||!n)return;const o=selected(),single=!!(o&&o.dataset.singleTeam==="1");const min=single?1:Math.max(1,parseInt(o&&o.dataset.minTeams||2,10));const max=single?1:Math.max(min,parseInt(o&&o.dataset.maxTeams||10,10));n.min=String(min);n.max=String(max);if(w)w.hidden=single;if(single)n.value="1";else if(applyDefault){const d=Math.max(min,Math.min(max,parseInt(o&&o.dataset.defaultTeams||min,10)));n.value=String(d);}else{const v=Math.max(min,Math.min(max,parseInt(n.value||min,10)));n.value=String(v);}}function r(){bounds(false);const min=parseInt(n.min||1,10),max=parseInt(n.max||10,10);const c=Math.max(min,Math.min(max,parseInt(n.value||min,10)));b.innerHTML="";for(let i=1;i<=c;i++){const l=document.createElement("label");l.className="ckm-label";l.textContent="Команда "+i;const x=document.createElement("input");x.className="ckm-input";x.name="team_name_"+i;x.value="Команда "+String.fromCharCode(64+i);l.appendChild(x);b.appendChild(l);}}function hm(){if(!q||!h)return;const o=selected(),m=o?o.dataset.host:"ai";if(o&&o.dataset.showPrep==="1"){h.textContent="Переговори другого: две команды, четыре раунда. Раунды 1–2 идут без обязательного таймера; в «Неудобном вопросе» по умолчанию 60 секунд на ответ; финал: подготовка и рассказ без обязательного таймера, вопросы без таймера, ответы рассказчика по умолчанию до 30 секунд и тайное голосование. Максимум — 280 баллов.";return;}if(o&&o.dataset.singleTeam==="1"){h.textContent="Битва знатоков: одна команда играет против Игры. Побеждает сторона, первой набравшая 6 очков.";return;}const slug=o?String(o.dataset.miniSlug||""):"";if(slug==="demo-solution-price"){h.textContent="Мини-игра «Управленческая игра \"Ваш выбор\"»: 1 команда, ИИ-ведущий запускает первый этап автоматически после входа команды.";return;}if(slug==="demo-negotiation-express"){h.textContent="Экспресс-раунд: 1 команда, ИИ-ведущий; на каждую переговорную реплику — 60 секунд после окончания озвучивания.";return;}if(slug==="demo-negotiation-sales"){h.textContent="Эффективный продажник: 1 команда ведёт диалог с ИИ-клиентом; ИИ-ведущий управляет этапами и оценкой.";return;}if(slug==="demo-negotiation-business"){h.textContent="Мастер переговоров: 1 команда ведёт переговоры с ИИ-оппонентом; ИИ-ведущий управляет этапами и оценкой.";return;}h.textContent=m==="human"?"Ведущий: голосовой ведущий. После создания откройте панель ведущего и подключите микрофон.":"Ведущий: ИИ-ведущий. Ход игры управляется автоматически.";}function selectedChanged(){bounds(true);r();hm();}n.addEventListener("input",r);if(q)q.addEventListener("change",selectedChanged);bounds(true);r();hm();})();</script>';
}

function ckm_quiz_pro_org_credentials(array $result): void {
    $g=$result['game']; $c=$result['credentials'];
    $hostMode=(string)($g['host_mode_snapshot']??$g['host_mode']??'ai');
    $hostUrl=ckm_quiz_pro_host_url($g);
    $boardUrl=ckm_quiz_pro_scoreboard_url($g);
    echo '<div class="ckm-card ckm-success"><div class="ckm-kicker">ИГРА СОЗДАНА</div><h2>'.esc_html((string)preg_replace('/^\s*Демо:\s*/u','',(string)($g['title']??$g['game_code']))).'</h2><p>Код игры: <strong>'.esc_html($g['game_code']).'</strong></p><p class="ckm-muted"><strong>Комнаты ролей:</strong> команда · ведущий · публичное табло. Все три ссылки относятся к одной игре.</p><div class="ckm-links">';
    foreach($c['teams'] as $team){ $url=ckm_quiz_pro_team_url($g,$team); echo '<div><span>'.esc_html($team['teamName']).'</span><a target="_blank" rel="noopener" href="'.esc_url($url).'">Открыть комнату команды</a><code>'.esc_html($url).'</code></div>'; }
    echo '<div><span>Ведущий'.($hostMode==='ai'?' · ИИ-режим':'').'</span><a target="_blank" rel="noopener" href="'.esc_url($hostUrl).'">Открыть комнату ведущего</a><code>'.esc_html($hostUrl).'</code>'.($hostMode==='ai'?'<small class="ckm-muted">В ИИ-режиме комната ведущего доступна для наблюдения; ручные команды заблокированы сервером.</small>':'').'</div>';
    echo '<div><span>Публичное табло</span><a target="_blank" rel="noopener" href="'.esc_url($boardUrl).'">Открыть табло</a><code>'.esc_html($boardUrl).'</code></div></div></div>';
}

function ckm_quiz_pro_history_format_label(array $game): string {
    $format=sanitize_key((string)($game['format_key_snapshot'] ?? 'classic_quiz'));
    $aliases=['chgk_v1'=>'chgk','jeopardy_v1'=>'jeopardy','decision_price_v1'=>'solution_price','negotiation_duel_v1'=>'negotiation_duel'];
    if(isset($aliases[$format])) $format=$aliases[$format];
    if($format==='negotiation_duel'){
        $settings=json_decode((string)($game['format_settings_snapshot_json'] ?? ''),true);
        if(!is_array($settings)) $settings=[];
        $mode=sanitize_key((string)($settings['negotiationMode'] ?? 'sales'));
        if($mode==='communicate'){
            $variant=sanitize_key((string)($settings['persuadeMeVariant'] ?? ''));
            $variantLabels=[
                'school'=>'Переговори другого — для школьников',
                'student'=>'Переговори другого — для студентов',
                'leader'=>'Переговори другого — для руководителей',
                'family'=>'Переговори другого — Семейные ситуации',
            ];
            if(isset($variantLabels[$variant])) return $variantLabels[$variant];
        }
        $labels=['sales'=>'Эффективный продажник','business'=>'Мастер переговоров','express'=>'Экспресс-раунд','communicate'=>'Переговори другого'];
        return $labels[$mode] ?? 'Переговорный поединок';
    }
    $labels=[
        'classic_quiz'=>'Классический квиз',
        'chgk'=>'Битва знатоков',
        'jeopardy'=>'Интеллектуальный батл',
        'solution_price'=>'Управленческая игра "Ваш выбор"',
    ];
    return $labels[$format] ?? ($format!==''?$format:'Игра');
}

function ckm_quiz_pro_history_host_label(array $game): string {
    $mode=sanitize_key((string)($game['host_mode_snapshot'] ?? $game['host_mode'] ?? 'human'));
    return $mode==='ai' ? 'ИИ-ведущий' : 'Ведущий (с микрофоном/без микрофона)';
}

function ckm_quiz_pro_history_status_label(string $status): string {
    $status=sanitize_key($status);
    $labels=['waiting'=>'Ожидает старта','countdown'=>'Запуск','running'=>'Идёт','active'=>'Идёт','live'=>'Идёт','paused'=>'Пауза','finished'=>'Завершена','cancelled'=>'Отменена'];
    return $labels[$status] ?? ($status!==''?$status:'—');
}

function ckm_quiz_pro_history_status_class(string $status): string {
    $status=sanitize_key($status);
    if($status==='finished') return 'is-finished';
    if($status==='cancelled') return 'is-cancelled';
    if($status==='paused') return 'is-paused';
    if(in_array($status,['running','active','live','countdown'],true)) return 'is-live';
    return 'is-waiting';
}

function ckm_quiz_pro_history_date(array $game): string {
    $raw=(string)($game['started_at'] ?: $game['created_at'] ?? '');
    if($raw==='') return '—';
    return mysql2date('d.m.Y H:i',$raw,false);
}

function ckm_quiz_pro_history_datetime($raw): string {
    $raw=trim((string)$raw);
    return $raw!=='' ? mysql2date('d.m.Y H:i:s',$raw,false) : '—';
}

function ckm_quiz_pro_history_verdict_label(string $verdict): string {
    $verdict=sanitize_key($verdict);
    $labels=[
        'pending'=>'Ожидает оценки','correct'=>'Верно','accepted'=>'Засчитан',
        'incorrect'=>'Неверно','rejected'=>'Не засчитан','partial'=>'Частично',
    ];
    return $labels[$verdict] ?? ($verdict!==''?$verdict:'—');
}

function ckm_quiz_pro_history_actor_label(string $actor): string {
    $actor=sanitize_key($actor);
    $labels=[
        'admin'=>'Организатор','host'=>'Ведущий','ai_host'=>'ИИ-ведущий','participant'=>'Участник',
        'system'=>'Система','judge'=>'Арбитр','ai'=>'ИИ-арбитр','automatic'=>'Автоматически',
    ];
    return $labels[$actor] ?? ($actor!==''?$actor:'Система');
}

function ckm_quiz_pro_history_event_label(string $action): string {
    $action=sanitize_key($action);
    $labels=[
        'game_created'=>'Игра создана','game_started'=>'Игра началась','start_authorized'=>'Старт подтверждён',
        'team_member_joined'=>'Участник подключился','team_ready'=>'Команда готова','round_started'=>'Раунд начался',
        'round_closed'=>'Раунд завершён','question_started'=>'Вопрос открыт','next_question_started'=>'Открыт следующий вопрос',
        'answer_submitted'=>'Ответ отправлен','answer_updated_by_captain'=>'Ответ изменён','answer_scored'=>'Ответ оценён',
        'question_closed'=>'Вопрос закрыт','question_auto_closed'=>'Вопрос закрыт автоматически',
        'question_review_started'=>'Начат разбор вопроса','question_review_finished'=>'Разбор вопроса завершён',
        'game_paused'=>'Игра поставлена на паузу','game_resumed'=>'Игра продолжена','game_finished'=>'Игра завершена',
        'human_host_message'=>'Сообщение ведущего','ai_host_message'=>'Сообщение ИИ-ведущего',
        'chgk_discussion_started'=>'Началось обсуждение','chgk_final_answer_opened'=>'Открыт финальный ответ',
        'chgk_final_answer_submitted'=>'Финальный ответ отправлен','chgk_bonus_minute_used'=>'Использована бонусная минута',
        'chgk_early_answer_claimed'=>'Заявлен досрочный ответ','chgk_early_answer_window_closed'=>'Окно досрочного ответа закрыто',
        'jeopardy_cell_selected'=>'Выбрана ячейка','jeopardy_cell_played'=>'Ячейка сыграна','jeopardy_buzz_claimed'=>'Нажата кнопка ответа',
        'jeopardy_final_started'=>'Начался финал','jeopardy_final_revealed'=>'Ответы финала раскрыты','jeopardy_final_completed'=>'Финал завершён',
        'negotiation_show_preparation_started'=>'Началась подготовка','negotiation_show_dialogue_started'=>'Начался диалог',
        'negotiation_show_review_started'=>'Началась оценка арбитра','negotiation_show_review_finished'=>'Оценка арбитра завершена',
        'negotiation_show_round_started'=>'Начался новый раунд','negotiation_show_round_finished'=>'Раунд завершён',
        'negotiation_show_hard_question'=>'Задан неудобный вопрос','negotiation_show_story_started'=>'Начался рассказ',
        'negotiation_show_story_questions'=>'Начались уточняющие вопросы','negotiation_show_story_answer'=>'Ответ на уточняющий вопрос',
        'negotiation_show_story_vote'=>'Началось голосование','negotiation_show_story_result'=>'Результат голосования',
        'negotiation_show_next_attempt'=>'Следующее испытание','arbitration_pending'=>'Ожидается решение арбитра',
        'arbitration_comment_published'=>'Комментарий арбитра опубликован','answer_revealed'=>'Ответ раскрыт',
    ];
    if(isset($labels[$action])) return $labels[$action];
    return $action!=='' ? ucfirst(str_replace('_',' ',$action)) : 'Событие';
}

function ckm_quiz_pro_history_event_note(array $event): string {
    $payload=json_decode((string)($event['payload_json'] ?? ''),true);
    if(!is_array($payload)) return '';
    foreach(['showHostText','text','message','reason','detail','summary'] as $key){
        $value=trim((string)($payload[$key] ?? ''));
        if($value!=='') return $value;
    }
    if(isset($payload['decision'])) return 'Решение: '.sanitize_text_field((string)$payload['decision']);
    if(isset($payload['points'])) return 'Баллы: '.(int)$payload['points'];
    if(isset($payload['delta'])) return 'Изменение счёта: '.((int)$payload['delta']>=0?'+':'').(int)$payload['delta'];
    return '';
}

function ckm_quiz_pro_history_is_persuade_me(array $game): bool {
    $format=sanitize_key((string)($game['format_key_snapshot'] ?? ''));
    if($format==='persuade_me_v1' || $format==='persuade_me') return true;
    if($format==='negotiation_duel_v1') $format='negotiation_duel';
    if($format!=='negotiation_duel') return false;
    $settings=json_decode((string)($game['format_settings_snapshot_json'] ?? ''),true);
    return sanitize_key((string)($settings['negotiationMode'] ?? ''))==='communicate';
}

function ckm_quiz_pro_history_show_session(int $gameId,int $tenant,array $teams): ?array {
    if(!function_exists('ckmqp_show_table')) return null;
    global $wpdb;
    $table=ckmqp_show_table();
    $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
    if((string)$exists!==$table) return null;
    $row=$wpdb->get_row($wpdb->prepare("SELECT state_json,updated_at FROM {$table} WHERE game_id=%d AND tenant_id=%d LIMIT 1",$gameId,$tenant),ARRAY_A);
    if(!$row) return null;
    $state=json_decode((string)$row['state_json'],true);
    if(!is_array($state)) return null;
    if(function_exists('ckmqp_show_normalize_state')) $state=ckmqp_show_normalize_state($state);
    $state['_updated_at']=$row['updated_at'] ?? '';
    return $state;
}

function ckm_quiz_pro_history_render_persuade_me(array $state,array $teams): void {
    $names=[]; foreach($teams as $t) $names[(int)$t['slot_no']]=(string)$t['team_name'];
    $roundTitles=[0=>'Удержи цель',1=>'Скрытая задача',2=>'Неудобный вопрос',3=>'Проверь историю'];
    echo '<div class="ckm-card"><div class="ckm-kicker">РАУНДЫ</div><h2>Ход «Переговори другого»</h2><p class="ckm-muted">Диалоги, ответы и решения арбитра сохранены в серверной сессии игры.</p>';
    foreach($roundTitles as $round=>$title){
        echo '<div class="ckm-history-round"><h3>Раунд '.($round+1).'. '.esc_html($title).'</h3>';
        $has=false;
        if(in_array($round,[0,1],true)){
            foreach((array)($state['messages'] ?? []) as $m){
                if((int)($m['round'] ?? 0)!==$round) continue;
                $has=true; $slot=(int)($m['slot'] ?? 0); $attempt=(int)($m['attempt'] ?? 0)+1;
                echo '<div class="ckm-history-line"><strong>'.esc_html($names[$slot] ?? ('Команда '.$slot)).'</strong><span class="ckm-badge">испытание '.$attempt.'</span><div>'.nl2br(esc_html((string)($m['text'] ?? ''))).'</div></div>';
            }
        } elseif($round===2){
            $questions=function_exists('ckmqp_show_hard_questions')?ckmqp_show_hard_questions($state):[];
            foreach((array)($state['hardAnswers'] ?? []) as $attempt=>$rows){
                foreach((array)$rows as $q=>$ans){
                    $has=true;$slot=(int)$attempt+1;$qt=(string)($questions[$attempt][$q] ?? ('Вопрос '.((int)$q+1)));
                    echo '<div class="ckm-history-line"><strong>'.esc_html($names[$slot] ?? ('Команда '.$slot)).'</strong><div class="ckm-muted">'.esc_html($qt).'</div><div>'.nl2br(esc_html((string)($ans['text'] ?? ''))).'</div></div>';
                }
            }
        } else {
            foreach((array)($state['storyStories'] ?? []) as $attempt=>$story){
                $has=true;$slot=(int)$attempt+1;
                echo '<div class="ckm-history-line"><strong>'.esc_html($names[$slot] ?? ('Команда '.$slot)).' — рассказ</strong><div>'.nl2br(esc_html((string)($story['text'] ?? ''))).'</div></div>';
                foreach((array)($state['storyAnswers'][$attempt] ?? []) as $answer){
                    echo '<div class="ckm-history-subline"><span class="ckm-muted">Ответ:</span> '.nl2br(esc_html((string)($answer['text'] ?? ''))).'</div>';
                }
            }
        }
        $reviewSets=$round===0?($state['reviews']??[]):($round===1?($state['hiddenReviews']??[]):($round===2?($state['hardReviews']??[]):($state['storyResults']??[])));
        foreach((array)$reviewSets as $attempt=>$wrap){
            $review=$round===3?$wrap:($wrap['review']??null);
            if(!is_array($review)) continue;
            $has=true;$slot=(int)$attempt+1;$total=(int)($review['total'] ?? $review['storytellerPoints'] ?? 0);
            $summary=trim((string)($review['summary'] ?? ''));$recommendation=trim((string)($review['recommendation'] ?? ''));
            echo '<div class="ckm-history-review"><strong>Решение арбитра: '.esc_html($names[$slot] ?? ('Команда '.$slot)).' — '.$total.' очков</strong>';
            if($summary!=='') echo '<div>'.nl2br(esc_html($summary)).'</div>';
            if($recommendation!=='') echo '<div class="ckm-muted">Рекомендация: '.nl2br(esc_html($recommendation)).'</div>';
            echo '</div>';
        }
        if(!$has) echo '<p class="ckm-muted">Данных этого раунда пока нет.</p>';
        echo '</div>';
    }
    echo '</div>';
}

function ckm_quiz_pro_history_sanitize_date($value): string {
    $value=trim(sanitize_text_field(wp_unslash((string)$value)));
    if($value==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)) return '';
    [$year,$month,$day]=array_map('intval',explode('-',$value));
    return checkdate($month,$day,$year) ? $value : '';
}

function ckm_quiz_pro_history_game_day(array $game): string {
    $raw=trim((string)($game['started_at'] ?: ($game['created_at'] ?? '')));
    return $raw!=='' ? mysql2date('Y-m-d',$raw,false) : '';
}

function ckm_quiz_pro_history_filter_args(bool $includePage=true): array {
    $args=[];
    $q=trim(sanitize_text_field(wp_unslash($_GET['history_q'] ?? '')));
    $status=sanitize_key((string)($_GET['history_status'] ?? 'all'));
    $format=trim(sanitize_text_field(wp_unslash($_GET['history_format'] ?? '')));
    $dateFrom=ckm_quiz_pro_history_sanitize_date($_GET['history_date_from'] ?? '');
    $dateTo=ckm_quiz_pro_history_sanitize_date($_GET['history_date_to'] ?? '');
    if($dateFrom!=='' && $dateTo!=='' && $dateFrom>$dateTo) [$dateFrom,$dateTo]=[$dateTo,$dateFrom];
    $sort=sanitize_key((string)($_GET['history_sort'] ?? 'newest'));
    if(!in_array($sort,['newest','oldest','title','format'],true)) $sort='newest';
    $page=max(1,absint($_GET['history_page'] ?? 1));
    if($q!=='') $args['history_q']=$q;
    if(in_array($status,['finished','unfinished','test'],true)) $args['history_status']=$status;
    if($format!=='') $args['history_format']=$format;
    if($dateFrom!=='') $args['history_date_from']=$dateFrom;
    if($dateTo!=='') $args['history_date_to']=$dateTo;
    if($sort!=='newest') $args['history_sort']=$sort;
    if($includePage && $page>1) $args['history_page']=$page;
    return $args;
}

function ckm_quiz_pro_history_sales_attempts(int $tenantId): array {
    global $wpdb;
    if($tenantId<0 || !class_exists('\\CKM\\NegotiationMaster\\Schema')) return [];
    try{
        $sessions=\CKM\NegotiationMaster\Schema::table('sessions');
        $scenarios=\CKM\NegotiationMaster\Schema::table('scenarios');
        $evaluations=\CKM\NegotiationMaster\Schema::table('evaluations');
    }catch(\Throwable){
        return [];
    }
    foreach([$sessions,$scenarios,$evaluations] as $table){
        if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) return [];
    }
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT se.id,se.scenario_id,se.participant_key,se.mode,se.difficulty,se.status AS sales_status,se.started_at,se.last_activity_at,se.completed_at,se.created_at,
                sc.slug AS scenario_slug,sc.title AS scenario_title,sc.tenant_id AS scenario_tenant_id,
                ev.final_score,ev.result_type,ev.status AS evaluation_status
         FROM {$sessions} se
         INNER JOIN {$scenarios} sc ON sc.id=se.scenario_id
         LEFT JOIN {$evaluations} ev ON ev.session_id=se.id
         WHERE se.tenant_id=%d AND se.session_kind='player'
         ORDER BY se.id DESC",
        $tenantId
    ),ARRAY_A) ?: [];
    foreach($rows as &$row){
        $raw=sanitize_key((string)($row['sales_status']??''));
        $row['_history_type']='sales';
        $row['title']=trim((string)($row['scenario_title']??'')) ?: 'Эффективный продажник';
        $row['game_code']='SALES-'.(int)$row['id'];
        $row['test_mode']=0;
        $row['finished_at']=(string)($row['completed_at']??'');
        $row['status']=str_starts_with($raw,'completed_')?'finished':($raw==='in_progress'?'running':($raw==='abandoned'?'cancelled':'waiting'));
        $participant=trim((string)($row['participant_key']??''));
        $label=$participant!==''?$participant:'Участник';
        if(preg_match('/^user:(\\d+)$/',$participant,$m)){
            $u=get_userdata((int)$m[1]);
            if($u && trim((string)$u->display_name)!=='') $label=trim((string)$u->display_name);
        }
        $row['participant_label']=$label;
    }
    unset($row);
    return $rows;
}

function ckm_quiz_pro_history_dataset(int $userId,int $tenantId): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['search'=>'','statusFilter'=>'all','formatFilter'=>'','dateFrom'=>'','dateTo'=>'','sortOrder'=>'newest','games'=>[],'teamsByGame'=>[],'formatOptions'=>[],'filtered'=>[]];
    $search=trim(sanitize_text_field(wp_unslash($_GET['history_q'] ?? '')));
    $statusFilter=sanitize_key((string)($_GET['history_status'] ?? 'all'));
    if(!in_array($statusFilter,['all','finished','unfinished','test'],true)) $statusFilter='all';
    $formatFilter=trim(sanitize_text_field(wp_unslash($_GET['history_format'] ?? '')));
    $dateFrom=ckm_quiz_pro_history_sanitize_date($_GET['history_date_from'] ?? '');
    $dateTo=ckm_quiz_pro_history_sanitize_date($_GET['history_date_to'] ?? '');
    if($dateFrom!=='' && $dateTo!=='' && $dateFrom>$dateTo) [$dateFrom,$dateTo]=[$dateTo,$dateFrom];
    $sortOrder=sanitize_key((string)($_GET['history_sort'] ?? 'newest'));
    if(!in_array($sortOrder,['newest','oldest','title','format'],true)) $sortOrder='newest';

    $gamesTable=ckm_quiz_pro_table('games');
    $teamsTable=ckm_quiz_pro_table('teams');
    $trashTable=ckm_quiz_pro_table('history_trash');
    $games=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$gamesTable} g LEFT JOIN {$trashTable} ht ON ht.game_id=g.id WHERE g.tenant_id=%d AND ht.game_id IS NULL ORDER BY g.id DESC",$tenantId),ARRAY_A) ?: [];
    $teamsByGame=[];
    $rows=$wpdb->get_results($wpdb->prepare("SELECT t.game_id,t.team_name,t.score,t.final_score,t.slot_no FROM {$teamsTable} t INNER JOIN {$gamesTable} g ON g.id=t.game_id LEFT JOIN {$trashTable} ht ON ht.game_id=g.id WHERE g.tenant_id=%d AND ht.game_id IS NULL ORDER BY t.game_id,t.slot_no,t.id",$tenantId),ARRAY_A) ?: [];
    foreach($rows as $row) $teamsByGame[(int)$row['game_id']][]=$row;

    $salesAttempts=ckm_quiz_pro_history_sales_attempts($tenantId);
    $formatOptions=[];
    foreach($games as $g) $formatOptions[ckm_quiz_pro_history_format_label($g)]=true;
    if($salesAttempts) $formatOptions['Эффективный продажник']=true;
    $formatOptions=array_keys($formatOptions); natcasesort($formatOptions);

    $lower=static function(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value); };
    $needle=$lower($search);
    $filtered=[];
    foreach($games as $g){
        $isTest=!empty($g['test_mode']);
        $status=sanitize_key((string)($g['status'] ?? ''));
        if($statusFilter==='finished' && $status!=='finished') continue;
        if($statusFilter==='unfinished' && $status==='finished') continue;
        if($statusFilter==='test' && !$isTest) continue;
        $formatLabel=ckm_quiz_pro_history_format_label($g);
        if($formatFilter!=='' && $formatLabel!==$formatFilter) continue;
        $gameDay=ckm_quiz_pro_history_game_day($g);
        if($dateFrom!=='' && ($gameDay==='' || $gameDay<$dateFrom)) continue;
        if($dateTo!=='' && ($gameDay==='' || $gameDay>$dateTo)) continue;
        if($needle!==''){
            $parts=[(string)($g['title'] ?? ''),(string)($g['game_code'] ?? ''),$formatLabel,ckm_quiz_pro_history_host_label($g),ckm_quiz_pro_history_status_label($status)];
            foreach($teamsByGame[(int)$g['id']] ?? [] as $t) $parts[]=(string)($t['team_name'] ?? '');
            if(strpos($lower(implode(' ', $parts)),$needle)===false) continue;
        }
        $g['_history_type']='game';
        $filtered[]=$g;
    }
    foreach($salesAttempts as $g){
        $status=sanitize_key((string)($g['status']??''));
        if($statusFilter==='finished' && $status!=='finished') continue;
        if($statusFilter==='unfinished' && $status==='finished') continue;
        if($statusFilter==='test') continue;
        $formatLabel='Эффективный продажник';
        if($formatFilter!=='' && $formatLabel!==$formatFilter) continue;
        $gameDay=ckm_quiz_pro_history_game_day($g);
        if($dateFrom!=='' && ($gameDay==='' || $gameDay<$dateFrom)) continue;
        if($dateTo!=='' && ($gameDay==='' || $gameDay>$dateTo)) continue;
        if($needle!==''){
            $parts=[(string)($g['title']??''),(string)($g['game_code']??''),$formatLabel,'ИИ-клиент',ckm_quiz_pro_history_status_label($status),(string)($g['participant_label']??'')];
            if(strpos($lower(implode(' ',$parts)),$needle)===false) continue;
        }
        $filtered[]=$g;
    }

    $formatOf=static function(array $row): string {
        return (($row['_history_type']??'game')==='sales') ? 'Эффективный продажник' : ckm_quiz_pro_history_format_label($row);
    };
    usort($filtered,static function(array $a,array $b) use($sortOrder,$formatOf): int {
        $aid=(int)($a['id'] ?? 0); $bid=(int)($b['id'] ?? 0);
        if($sortOrder==='title'){
            $av=trim((string)($a['title'] ?: ($a['game_code'] ?? ''))); $bv=trim((string)($b['title'] ?: ($b['game_code'] ?? '')));
            $cmp=strnatcasecmp($av,$bv);
            return $cmp!==0 ? $cmp : ($bid<=>$aid);
        }
        if($sortOrder==='format'){
            $cmp=strnatcasecmp($formatOf($a),$formatOf($b));
            if($cmp!==0) return $cmp;
        }
        $av=(string)($a['started_at'] ?: ($a['created_at'] ?? '')); $bv=(string)($b['started_at'] ?: ($b['created_at'] ?? ''));
        $cmp=strcmp($av,$bv);
        if($cmp===0) $cmp=$aid<=>$bid;
        return $sortOrder==='oldest' ? $cmp : -$cmp;
    });

    return compact('search','statusFilter','formatFilter','dateFrom','dateTo','sortOrder','games','salesAttempts','teamsByGame','formatOptions','filtered');
}

function ckm_quiz_pro_history_summary(array $games): array {
    $summary=['total'=>count($games),'finished'=>0,'live'=>0,'tests'=>0,'popular_label'=>'—','popular_count'=>0];
    $realFormats=[];
    foreach($games as $game){
        $status=sanitize_key((string)($game['status'] ?? ''));
        $isTest=!empty($game['test_mode']);
        if($status==='finished') $summary['finished']++;
        if(in_array($status,['running','active','live','countdown'],true)) $summary['live']++;
        if($isTest){
            $summary['tests']++;
            continue;
        }
        $label=ckm_quiz_pro_history_format_label($game);
        $realFormats[$label]=($realFormats[$label] ?? 0)+1;
    }
    if($realFormats){
        $max=max($realFormats);
        $leaders=[];
        foreach($realFormats as $label=>$count) if($count===$max) $leaders[]=$label;
        natcasesort($leaders);
        $summary['popular_label']=implode(' · ',$leaders);
        $summary['popular_count']=$max;
    }
    return $summary;
}

function ckm_quiz_pro_history_team_stats(array $games,array $teamsByGame): array {
    $stats=[];
    $lower=static function(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value); };
    foreach($games as $game){
        if(!empty($game['test_mode'])) continue;
        if(sanitize_key((string)($game['status'] ?? ''))!=='finished') continue;
        $gameId=(int)($game['id'] ?? 0);
        $teams=$teamsByGame[$gameId] ?? [];
        if(!$teams) continue;
        $ranked=ckm_quiz_pro_history_rankings($teams,$game);
        $topScore=(count($teams)>1 && $ranked) ? (int)$ranked[0]['score'] : null;
        $format=ckm_quiz_pro_history_format_label($game);
        $playedRaw=trim((string)($game['finished_at'] ?: ($game['started_at'] ?: ($game['created_at'] ?? ''))));
        $seen=[];
        foreach($teams as $team){
            $name=trim((string)($team['team_name'] ?? ''));
            if($name==='') $name='Команда '.((int)($team['slot_no'] ?? 0) ?: '?');
            $key=$lower($name);
            if(isset($seen[$key])) continue; // Без постоянного team_id одинаковые названия внутри одной игры нельзя надёжно разделить.
            $seen[$key]=true;
            $score=ckm_quiz_pro_history_team_score($team);
            if(!isset($stats[$key])){
                $stats[$key]=[
                    'name'=>$name,'games'=>0,'first_places'=>0,'score_sum'=>0,'best_score'=>null,
                    'formats'=>[],'last_raw'=>'','last_label'=>'—',
                ];
            }
            $stats[$key]['games']++;
            $stats[$key]['score_sum']+=$score;
            if($stats[$key]['best_score']===null || $score>$stats[$key]['best_score']) $stats[$key]['best_score']=$score;
            $stats[$key]['formats'][$format]=true;
            if($topScore!==null && $score===$topScore) $stats[$key]['first_places']++;
            if($playedRaw!=='' && ($stats[$key]['last_raw']==='' || strcmp($playedRaw,$stats[$key]['last_raw'])>0)){
                $stats[$key]['last_raw']=$playedRaw;
                $stats[$key]['last_label']=mysql2date('d.m.Y',$playedRaw,false);
            }
        }
    }
    foreach($stats as &$row){
        $row['average_score']=$row['games']>0 ? round($row['score_sum']/$row['games'],1) : 0;
        $row['format_count']=count($row['formats']);
        unset($row['score_sum'],$row['formats']);
    }
    unset($row);
    uasort($stats,static function(array $a,array $b): int {
        if($a['games']!==$b['games']) return $b['games']<=>$a['games'];
        if($a['first_places']!==$b['first_places']) return $b['first_places']<=>$a['first_places'];
        $dateCmp=strcmp((string)$b['last_raw'],(string)$a['last_raw']);
        if($dateCmp!==0) return $dateCmp;
        return strnatcasecmp((string)$a['name'],(string)$b['name']);
    });
    return array_values($stats);
}

function ckm_quiz_pro_history_render_team_stats(array $games,array $teamsByGame): void {
    $rows=ckm_quiz_pro_history_team_stats($games,$teamsByGame);
    echo '<details class="ckm-card ckm-history-team-stats ckm-history-fold">';
    echo '<summary><div><div class="ckm-kicker">НАКОПИТЕЛЬНО</div><h2>Статистика команд</h2><p class="ckm-muted">Завершённые реальные игры. Тестовые и незавершённые запуски не учитываются.</p></div></summary>';
    echo '<div class="ckm-history-fold-body">';
    if(!$rows){
        echo '<p class="ckm-muted">Пока нет завершённых реальных игр, по которым можно построить статистику команд.</p>';
    }else{
        echo '<div class="ckm-table-wrap"><table class="ckm-table ckm-history-team-table"><thead><tr><th>Команда</th><th>Игр</th><th>1-х мест</th><th>Средний счёт</th><th>Лучший счёт</th><th>Форматов</th><th>Последняя игра</th></tr></thead><tbody>';
        foreach($rows as $row){
            $avg=(float)$row['average_score'];
            $avgText=abs($avg-round($avg))<0.00001 ? (string)(int)round($avg) : number_format($avg,1,',',' ');
            echo '<tr><td><strong>'.esc_html((string)$row['name']).'</strong></td><td>'.(int)$row['games'].'</td><td>'.(int)$row['first_places'].'</td><td>'.esc_html($avgText).'</td><td>'.esc_html((string)(int)$row['best_score']).'</td><td>'.(int)$row['format_count'].'</td><td>'.esc_html((string)$row['last_label']).'</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="ckm-muted ckm-history-team-note">Команды объединяются по одинаковому названию, потому что у текущего движка нет постоянного идентификатора команды между разными играми. «1-е место» считается только в завершённых играх с двумя и более командами; ничья за первое место учитывается у каждой команды. Средний и лучший счёт — арифметика сохранённых очков, поэтому напрямую сравнивать эти числа между форматами с разными шкалами не следует.</p>';
    }
    echo '</div></details>';
}

function ckm_quiz_pro_history_format_stats(array $games,array $teamsByGame): array {
    $stats=[];
    foreach($games as $game){
        if(!empty($game['test_mode'])) continue;
        $label=ckm_quiz_pro_history_format_label($game);
        if(!isset($stats[$label])){
            $stats[$label]=[
                'label'=>$label,'games'=>0,'finished'=>0,'team_sum'=>0,
                'duration_sum'=>0,'duration_count'=>0,'last_raw'=>'','last_label'=>'—',
            ];
        }
        $gameId=(int)($game['id'] ?? 0);
        $stats[$label]['games']++;
        $stats[$label]['team_sum']+=count($teamsByGame[$gameId] ?? []);
        $status=sanitize_key((string)($game['status'] ?? ''));
        if($status==='finished'){
            $stats[$label]['finished']++;
            $start=trim((string)($game['started_at'] ?: ($game['created_at'] ?? '')));
            $finish=trim((string)($game['finished_at'] ?? ''));
            if($start!=='' && $finish!==''){
                $startTs=strtotime($start); $finishTs=strtotime($finish);
                if($startTs!==false && $finishTs!==false && $finishTs>=$startTs){
                    $duration=$finishTs-$startTs;
                    if($duration<=7*DAY_IN_SECONDS){
                        $stats[$label]['duration_sum']+=$duration;
                        $stats[$label]['duration_count']++;
                    }
                }
            }
        }
        $playedRaw=trim((string)($game['started_at'] ?: ($game['created_at'] ?? '')));
        if($playedRaw!=='' && ($stats[$label]['last_raw']==='' || strcmp($playedRaw,$stats[$label]['last_raw'])>0)){
            $stats[$label]['last_raw']=$playedRaw;
            $stats[$label]['last_label']=mysql2date('d.m.Y',$playedRaw,false);
        }
    }
    foreach($stats as &$row){
        $row['average_teams']=$row['games']>0 ? round($row['team_sum']/$row['games'],1) : 0;
        $row['average_duration']=$row['duration_count']>0 ? (int)round($row['duration_sum']/$row['duration_count']) : null;
        unset($row['team_sum'],$row['duration_sum'],$row['duration_count']);
    }
    unset($row);
    uasort($stats,static function(array $a,array $b): int {
        if($a['finished']!==$b['finished']) return $b['finished']<=>$a['finished'];
        if($a['games']!==$b['games']) return $b['games']<=>$a['games'];
        $dateCmp=strcmp((string)$b['last_raw'],(string)$a['last_raw']);
        if($dateCmp!==0) return $dateCmp;
        return strnatcasecmp((string)$a['label'],(string)$b['label']);
    });
    return array_values($stats);
}

function ckm_quiz_pro_history_format_team_leaderboards(array $games,array $teamsByGame): array {
    $boards=[];
    $lower=static function(string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value); };
    foreach($games as $game){
        if(!empty($game['test_mode'])) continue;
        if(sanitize_key((string)($game['status'] ?? ''))!=='finished') continue;
        $gameId=(int)($game['id'] ?? 0);
        $teams=$teamsByGame[$gameId] ?? [];
        if(!$teams) continue;
        $format=ckm_quiz_pro_history_format_label($game);
        if(!isset($boards[$format])) $boards[$format]=[];
        $ranked=ckm_quiz_pro_history_rankings($teams,$game);
        $topScore=(count($teams)>1 && $ranked) ? (int)$ranked[0]['score'] : null;
        $playedRaw=trim((string)($game['finished_at'] ?: ($game['started_at'] ?: ($game['created_at'] ?? ''))));
        $seen=[];
        foreach($teams as $team){
            $name=trim((string)($team['team_name'] ?? ''));
            if($name==='') $name='Команда '.((int)($team['slot_no'] ?? 0) ?: '?');
            $key=$lower($name);
            if(isset($seen[$key])) continue;
            $seen[$key]=true;
            $score=ckm_quiz_pro_history_team_score($team);
            if(!isset($boards[$format][$key])){
                $boards[$format][$key]=[
                    'name'=>$name,'games'=>0,'first_places'=>0,'score_sum'=>0,'best_score'=>null,
                    'last_raw'=>'','last_label'=>'—',
                ];
            }
            $row=&$boards[$format][$key];
            $row['games']++;
            $row['score_sum']+=$score;
            if($row['best_score']===null || $score>$row['best_score']) $row['best_score']=$score;
            if($topScore!==null && $score===$topScore) $row['first_places']++;
            if($playedRaw!=='' && ($row['last_raw']==='' || strcmp($playedRaw,$row['last_raw'])>0)){
                $row['last_raw']=$playedRaw;
                $row['last_label']=mysql2date('d.m.Y',$playedRaw,false);
            }
            unset($row);
        }
    }
    foreach($boards as $format=>&$rows){
        foreach($rows as &$row){
            $row['average_score']=$row['games']>0 ? round($row['score_sum']/$row['games'],1) : 0;
            unset($row['score_sum']);
        }
        unset($row);
        uasort($rows,static function(array $a,array $b): int {
            if($a['first_places']!==$b['first_places']) return $b['first_places']<=>$a['first_places'];
            if($a['average_score']!==$b['average_score']) return $b['average_score']<=>$a['average_score'];
            if($a['best_score']!==$b['best_score']) return ((int)$b['best_score'])<=>((int)$a['best_score']);
            if($a['games']!==$b['games']) return $b['games']<=>$a['games'];
            return strnatcasecmp((string)$a['name'],(string)$b['name']);
        });
        $rows=array_values($rows);
    }
    unset($rows);
    uksort($boards,'strnatcasecmp');
    return $boards;
}

function ckm_quiz_pro_history_render_format_team_leaderboards(array $games,array $teamsByGame): void {
    $boards=ckm_quiz_pro_history_format_team_leaderboards($games,$teamsByGame);
    echo '<details class="ckm-card ckm-history-format-leaderboards ckm-history-fold">';
    echo '<summary><div><div class="ckm-kicker">СРАВНЕНИЕ ВНУТРИ ФОРМАТА</div><h2>Результаты команд по форматам</h2><p class="ckm-muted">Команды сравниваются только с результатами того же игрового формата.</p></div></summary>';
    echo '<div class="ckm-history-fold-body">';
    if(!$boards){
        echo '<p class="ckm-muted">Пока нет завершённых реальных игр, по которым можно сравнить результаты команд внутри форматов.</p>';
    }else{
        foreach($boards as $format=>$rows){
            echo '<details class="ckm-history-subfold">';
            echo '<summary><strong>'.esc_html((string)$format).'</strong><span class="ckm-muted">'.count($rows).' '.(count($rows)===1?'команда':(count($rows)>=2 && count($rows)<=4?'команды':'команд')).'</span></summary>';
            echo '<div class="ckm-history-subfold-body"><div class="ckm-table-wrap"><table class="ckm-table ckm-history-format-leaderboard-table"><thead><tr><th>Команда</th><th>Игр</th><th>1-х мест</th><th>Средний счёт</th><th>Лучший счёт</th><th>Последняя игра</th></tr></thead><tbody>';
            foreach($rows as $row){
                $avg=(float)$row['average_score'];
                $avgText=abs($avg-round($avg))<0.00001 ? (string)(int)round($avg) : number_format($avg,1,',',' ');
                echo '<tr><td><strong>'.esc_html((string)$row['name']).'</strong></td><td>'.(int)$row['games'].'</td><td>'.(int)$row['first_places'].'</td><td>'.esc_html($avgText).'</td><td>'.esc_html((string)(int)$row['best_score']).'</td><td>'.esc_html((string)$row['last_label']).'</td></tr>';
            }
            echo '</tbody></table></div></div></details>';
        }
        echo '<p class="ckm-muted ckm-history-format-leaderboard-note">Сравнение ведётся отдельно для каждого формата и только по завершённым реальным играм. «1-е место» учитывается лишь при двух и более командах; при ничьей первое место получает каждая команда с максимальным счётом. Команды объединяются по одинаковому названию. Если организатор меняет число вопросов или правила начисления внутри одного формата, средний счёт всё равно следует интерпретировать с учётом этих настроек.</p>';
    }
    echo '</div></details>';
}

function ckm_quiz_pro_history_duration_short(?int $seconds): string {
    if($seconds===null) return '—';
    $seconds=max(0,$seconds);
    if($seconds<60) return $seconds.' сек';
    $minutes=(int)round($seconds/60);
    if($minutes<60) return $minutes.' мин';
    $hours=intdiv($minutes,60); $rest=$minutes%60;
    return $rest>0 ? $hours.' ч '.$rest.' мин' : $hours.' ч';
}

function ckm_quiz_pro_history_render_format_stats(array $games,array $teamsByGame): void {
    $rows=ckm_quiz_pro_history_format_stats($games,$teamsByGame);
    echo '<details class="ckm-card ckm-history-format-stats ckm-history-fold">';
    echo '<summary><div><div class="ckm-kicker">ПО ФОРМАТАМ</div><h2>Статистика игровых форматов</h2><p class="ckm-muted">Только реальные игры. Тестовые запуски не учитываются.</p></div></summary>';
    echo '<div class="ckm-history-fold-body">';
    if(!$rows){
        echo '<p class="ckm-muted">Пока нет реальных игр, по которым можно построить статистику форматов.</p>';
    }else{
        echo '<div class="ckm-table-wrap"><table class="ckm-table ckm-history-format-table"><thead><tr><th>Формат</th><th>Запусков</th><th>Завершено</th><th>Среднее число команд</th><th>Средняя длительность</th><th>Последняя игра</th></tr></thead><tbody>';
        foreach($rows as $row){
            $avg=(float)$row['average_teams'];
            $avgTeams=abs($avg-round($avg))<0.00001 ? (string)(int)round($avg) : number_format($avg,1,',',' ');
            echo '<tr><td><strong>'.esc_html((string)$row['label']).'</strong></td><td>'.(int)$row['games'].'</td><td>'.(int)$row['finished'].'</td><td>'.esc_html($avgTeams).'</td><td>'.esc_html(ckm_quiz_pro_history_duration_short($row['average_duration'])).'</td><td>'.esc_html((string)$row['last_label']).'</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="ckm-muted ckm-history-format-note">Средняя длительность рассчитывается только по завершённым играм, где сохранены время начала и окончания. Запуски длительностью более 7 суток исключаются из среднего как вероятно незакрытые технические сессии.</p>';
    }
    echo '</div></details>';
}

function ckm_quiz_pro_history_csv_open(string $filename){
    while(ob_get_level()>0) @ob_end_clean();
    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.sanitize_file_name($filename).'"');
    header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w');
    if(!$out) wp_die('Не удалось открыть экспорт.');
    fwrite($out,"\xEF\xBB\xBF");
    return $out;
}

function ckm_quiz_pro_history_csv_row($out,array $row): void {
    fputcsv($out,array_map(static function($value){
        if(is_bool($value)) return $value?'Да':'Нет';
        if(is_array($value) || is_object($value)) return wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return (string)$value;
    },$row),';','"','\\');
}

function ckm_quiz_pro_history_export_list(int $userId,int $tenantId): void {
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)){status_header(403);wp_die('История доступна только WordPress-администратору.','',['response'=>403]);}
    $data=ckm_quiz_pro_history_dataset($userId,$tenantId);
    $out=ckm_quiz_pro_history_csv_open('ckm-game-history-'.wp_date('Y-m-d').'.csv');
    ckm_quiz_pro_history_csv_row($out,['Дата','Название','Код игры','Формат','Команды','Ведущий','Итог','Статус','Тестовая']);
    foreach($data['filtered'] as $g){
        if((string)($g['_history_type']??'game')==='sales'){
            $score=$g['final_score']!==null && $g['final_score']!=='' ? ((string)round((float)$g['final_score']).'/100') : '';
            ckm_quiz_pro_history_csv_row($out,[
                ckm_quiz_pro_history_date($g),(string)($g['title'] ?: $g['game_code']),(string)$g['game_code'],
                'Эффективный продажник',(string)($g['participant_label']??'Участник'),'ИИ-клиент',
                $score,ckm_quiz_pro_history_status_label((string)$g['status']),false
            ]);
            continue;
        }
        $teams=$data['teamsByGame'][(int)$g['id']] ?? [];
        $teamNames=[];$scoreParts=[];
        foreach($teams as $t){
            $name=trim((string)($t['team_name'] ?? 'Команда'));
            $teamNames[]=$name;
            $score=ckm_quiz_pro_history_team_score($t);
            $scoreParts[]=$name.': '.$score;
        }
        ckm_quiz_pro_history_csv_row($out,[
            ckm_quiz_pro_history_date($g),(string)($g['title'] ?: $g['game_code']),(string)$g['game_code'],
            ckm_quiz_pro_history_format_label($g),implode(' · ',$teamNames),ckm_quiz_pro_history_host_label($g),
            implode(' · ',$scoreParts),ckm_quiz_pro_history_status_label((string)$g['status']),!empty($g['test_mode'])
        ]);
    }
    fclose($out);
}

function ckm_quiz_pro_history_export_show_state($out,array $state,array $teams): void {
    $names=[]; foreach($teams as $t) $names[(int)$t['slot_no']]=(string)$t['team_name'];
    foreach((array)($state['messages'] ?? []) as $m){
        $slot=(int)($m['slot'] ?? 0);$round=(int)($m['round'] ?? 0)+1;$attempt=(int)($m['attempt'] ?? 0)+1;
        ckm_quiz_pro_history_csv_row($out,['Переговори другого','', 'Раунд '.$round.' / испытание '.$attempt,$names[$slot] ?? ('Команда '.$slot),(string)($m['text'] ?? ''),'','','','Диалог']);
    }
    $reviewGroups=['reviews'=>'Раунд 1','hiddenReviews'=>'Раунд 2','hardReviews'=>'Раунд 3','storyResults'=>'Раунд 4'];
    foreach($reviewGroups as $key=>$roundLabel){
        foreach((array)($state[$key] ?? []) as $attempt=>$wrap){
            $review=$key==='storyResults'?$wrap:($wrap['review']??null);
            if(!is_array($review)) continue;
            $slot=(int)$attempt+1;
            $total=(int)($review['total'] ?? $review['storytellerPoints'] ?? 0);
            ckm_quiz_pro_history_csv_row($out,['Переговори другого','',$roundLabel,$names[$slot] ?? ('Команда '.$slot),'',(string)($review['summary'] ?? ''),'',$total,(string)($review['recommendation'] ?? '')]);
        }
    }
}

function ckm_quiz_pro_history_export_game(int $gameId,int $userId,int $tenantId): void {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)){status_header(403);wp_die('История доступна только WordPress-администратору.','',['response'=>403]);}
    $game=$wpdb->get_row($wpdb->prepare('SELECT g.* FROM '.ckm_quiz_pro_table('games').' g LEFT JOIN '.ckm_quiz_pro_table('history_trash').' ht ON ht.game_id=g.id WHERE g.id=%d AND g.tenant_id=%d AND ht.game_id IS NULL LIMIT 1',$gameId,$tenantId),ARRAY_A);
    if(!$game){status_header(404);wp_die('Игра не найдена или не относится к этой площадке.','',['response'=>404]);}
    $teams=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('teams').' WHERE game_id=%d ORDER BY slot_no,id',$gameId),ARRAY_A) ?: [];
    $teamById=[];foreach($teams as $t)$teamById[(int)$t['id']]=$t;
    $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',(int)$game['quiz_id'],(int)$game['quiz_revision']),ARRAY_A) ?: [];
    $questionById=[];foreach($questions as $q)$questionById[(int)$q['id']]=$q;
    $answers=$wpdb->get_results($wpdb->prepare('SELECT a.*,t.team_name FROM '.ckm_quiz_pro_table('answers').' a LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=a.team_id WHERE a.game_id=%d ORDER BY a.question_id,a.submitted_at,a.id',$gameId),ARRAY_A) ?: [];
    $scores=$wpdb->get_results($wpdb->prepare('SELECT s.*,t.team_name FROM '.ckm_quiz_pro_table('score_events').' s LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=s.team_id WHERE s.game_id=%d ORDER BY s.created_at,s.id',$gameId),ARRAY_A) ?: [];
    $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('events').' WHERE game_id=%d ORDER BY id ASC',$gameId),ARRAY_A) ?: [];

    $code=preg_replace('/[^A-Za-z0-9_-]+/','-',(string)$game['game_code']);
    $out=ckm_quiz_pro_history_csv_open('ckm-game-'.$code.'.csv');
    ckm_quiz_pro_history_csv_row($out,['Раздел','Время','Объект','Команда','Текст / ответ','Решение / действие','Счёт до → после','Баллы','Комментарий']);
    ckm_quiz_pro_history_csv_row($out,['Игра',ckm_quiz_pro_history_datetime($game['created_at']??''),(string)($game['title'] ?: $game['game_code']),'',(string)$game['game_code'],ckm_quiz_pro_history_format_label($game),'','',ckm_quiz_pro_history_status_label((string)$game['status']).' · '.ckm_quiz_pro_history_host_label($game)]);
    foreach($teams as $t) ckm_quiz_pro_history_csv_row($out,['Команда','',(string)($t['team_name'] ?? ''),(string)($t['team_name'] ?? ''),'','Итог',(string)ckm_quiz_pro_history_team_score($t),'',(string)($t['final_summary']??'')]);
    foreach($answers as $a){
        $q=$questionById[(int)($a['question_id']??0)]??null;$qtext=$q?(string)($q['question_text']??''):('Вопрос #'.(int)($a['question_id']??0));
        ckm_quiz_pro_history_csv_row($out,['Ответ',ckm_quiz_pro_history_datetime($a['submitted_at']??''),$qtext,(string)($a['team_name']??''),(string)($a['answer_text']??''),ckm_quiz_pro_history_verdict_label((string)($a['verdict']??'')),'',(int)($a['awarded_points']??0),(string)($a['judge_comment']??'')]);
    }
    foreach($scores as $row) ckm_quiz_pro_history_csv_row($out,['Счёт',ckm_quiz_pro_history_datetime($row['created_at']??''),(string)($row['event_type']??''),(string)($row['team_name']??''),'',(string)($row['event_type']??''),(int)($row['score_before']??0).' → '.(int)($row['score_after']??0),(int)($row['points_delta']??0),(string)($row['reason']??'')]);
    foreach($events as $e){
        $team=$teamById[(int)($e['team_id']??0)]['team_name']??'';
        ckm_quiz_pro_history_csv_row($out,['Событие',ckm_quiz_pro_history_datetime($e['created_at']??''),ckm_quiz_pro_history_event_label((string)($e['action']??'')),$team,ckm_quiz_pro_history_event_note($e),(string)($e['action']??''),'','',ckm_quiz_pro_history_actor_label((string)($e['actor_type']??''))]);
    }
    if(ckm_quiz_pro_history_is_persuade_me($game)){
        $show=ckm_quiz_pro_history_show_session($gameId,$tenantId,$teams);
        if($show) ckm_quiz_pro_history_export_show_state($out,$show,$teams);
    }
    fclose($out);
}

function ckm_quiz_pro_history_can_delete(array $game): bool {
    // The organizer may deliberately delete any own history entry. Destructive
    // actions are protected by ownership/tenant checks, nonce and confirmation.
    return !empty($game['id']);
}

function ckm_quiz_pro_history_delete_game_rows(int $gameId,int $userId,int $tenantId): void {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) throw new RuntimeException('history_forbidden');
    $games=ckm_quiz_pro_table('games');
    if(function_exists('ckmqp_show_table')){
        $show=ckmqp_show_table();
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($show)));
        if((string)$exists===$show && $wpdb->query($wpdb->prepare("DELETE FROM {$show} WHERE game_id=%d AND tenant_id=%d",$gameId,$tenantId))===false) throw new RuntimeException('show_sessions');
    }
    foreach(['mechanics','score_events','answers','events','members','teams'] as $name){
        $table=ckm_quiz_pro_table($name);
        if($table==='' || $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE game_id=%d",$gameId))===false) throw new RuntimeException($name);
    }
    foreach(['drafts','appeals','thinking_analysis','methodology_analysis','report_jobs'] as $name){
        $table=ckm_quiz_pro_table($name);
        if($table==='') continue;
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if((string)$exists!==$table) continue;
        $hasGameId=$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s",'game_id'));
        if(!$hasGameId) continue;
        if($wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE game_id=%d",$gameId))===false) throw new RuntimeException($name);
    }
    $trash=ckm_quiz_pro_table('history_trash');
    if($trash!=='' && $wpdb->query($wpdb->prepare("DELETE FROM {$trash} WHERE game_id=%d AND tenant_id=%d",$gameId,$tenantId))===false) throw new RuntimeException('history_trash');
    $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$games} WHERE id=%d AND tenant_id=%d",$gameId,$tenantId));
    if($deleted!==1) throw new RuntimeException('game');
}

/** Ensure history support tables exist on upgraded installations. */
function ckm_quiz_pro_history_ensure_storage(): bool {
    global $wpdb;
    $names=['history_trash','history_audit'];
    $missing=false;
    foreach($names as $name){
        $table=ckm_quiz_pro_table($name);
        if($table==='') return false;
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if((string)$found!==$table){$missing=true;break;}
    }
    if($missing && function_exists('ckm_quiz_pro_install_schema')) ckm_quiz_pro_install_schema();
    foreach($names as $name){
        $table=ckm_quiz_pro_table($name);
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        if((string)$found!==$table) return false;
    }
    return true;
}

function ckm_quiz_pro_history_audit_action_label(string $action): string {
    return match($action){
        'trash'=>'В корзину',
        'restore'=>'Восстановлена',
        'permanent_delete'=>'Удалена навсегда',
        'purge_delete'=>'Удалена при очистке',
        default=>$action!=='' ? $action : 'Действие',
    };
}

function ckm_quiz_pro_history_audit_log(array $game,string $action,int $userId,int $tenantId,array $details=[]): bool {
    global $wpdb;
    if(!ckm_quiz_pro_history_ensure_storage()) return false;
    $table=ckm_quiz_pro_table('history_audit');
    if($table==='') return false;
    $actorId=get_current_user_id();
    $actor=$actorId>0 ? get_userdata($actorId) : false;
    $actorName=$actor ? trim((string)$actor->display_name) : '';
    if($actorName==='' && $actorId>0) $actorName='Пользователь #'.$actorId;
    $json=$details ? wp_json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
    $ok=$wpdb->insert($table,[
        'game_id'=>(int)($game['id']??0),
        'game_code'=>(string)($game['game_code']??''),
        'game_title'=>(string)($game['title']??''),
        'action_key'=>$action,
        'created_by_user_id'=>(int)($game['created_by_user_id'] ?? $userId),
        'tenant_id'=>$tenantId,
        'actor_user_id'=>$actorId,
        'actor_name_snapshot'=>$actorName,
        'details_json'=>$json,
        'occurred_at'=>current_time('mysql'),
    ],['%d','%s','%s','%s','%d','%d','%d','%s','%s','%s']);
    return $ok!==false;
}

function ckm_quiz_pro_history_trash_count(int $userId,int $tenantId): int {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return 0;
    if(!ckm_quiz_pro_history_ensure_storage()) return 0;
    $trash=ckm_quiz_pro_table('history_trash');
    return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trash} WHERE tenant_id=%d",$tenantId));
}

function ckm_quiz_pro_history_trash_game(int $gameId,int $userId,int $tenantId,string $confirmCode): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории. Обновите страницу и повторите действие.'];
    $games=ckm_quiz_pro_table('games');
    $trash=ckm_quiz_pro_table('history_trash');
    $game=$wpdb->get_row($wpdb->prepare("SELECT g.* FROM {$games} g LEFT JOIN {$trash} ht ON ht.game_id=g.id WHERE g.id=%d AND g.tenant_id=%d AND ht.game_id IS NULL LIMIT 1",$gameId,$tenantId),ARRAY_A);
    if(!$game) return ['ok'=>false,'error'=>'Игра не найдена, уже находится в корзине или не относится к этой площадке.'];
    if(!hash_equals((string)$game['game_code'],trim($confirmCode))) return ['ok'=>false,'error'=>'Код подтверждения не совпадает. Удаление отменено.'];
    $now=current_time('mysql');
    $wpdb->query('START TRANSACTION');
    try {
        $ok=$wpdb->insert($trash,[
            'game_id'=>$gameId,'created_by_user_id'=>(int)($game['created_by_user_id'] ?? 0),'tenant_id'=>$tenantId,
            'trashed_by_user_id'=>get_current_user_id(),'trashed_at'=>$now,
        ],['%d','%d','%d','%d','%s']);
        if($ok===false) throw new RuntimeException('trash_insert');
        if(!ckm_quiz_pro_history_audit_log($game,'trash',$userId,$tenantId,['mode'=>'single'])) throw new RuntimeException('audit_insert');
        $wpdb->query('COMMIT');
        return ['ok'=>true,'code'=>(string)$game['game_code']];
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось переместить игру в корзину. Все изменения отменены.'];
    }
}

function ckm_quiz_pro_history_bulk_trash_games(array $gameIds,int $userId,int $tenantId,string $confirm): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории. Обновите страницу и повторите действие.'];
    $gameIds=array_values(array_unique(array_filter(array_map('absint',$gameIds))));
    if(!$gameIds) return ['ok'=>false,'error'=>'Не выбрана ни одна игра.'];
    if(count($gameIds)>100) return ['ok'=>false,'error'=>'За один раз можно переместить в корзину не более 100 игр.'];
    if(!hash_equals('УДАЛИТЬ',trim($confirm))) return ['ok'=>false,'error'=>'Массовое удаление не подтверждено.'];
    $games=ckm_quiz_pro_table('games');$trash=ckm_quiz_pro_table('history_trash');
    $placeholders=implode(',',array_fill(0,count($gameIds),'%d'));
    $params=array_merge([$tenantId],$gameIds);
    $owned=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$games} g LEFT JOIN {$trash} ht ON ht.game_id=g.id WHERE g.tenant_id=%d AND ht.game_id IS NULL AND g.id IN ({$placeholders})",...$params),ARRAY_A) ?: [];
    if(count($owned)!==count($gameIds)) return ['ok'=>false,'error'=>'Одна или несколько выбранных игр недоступны или уже находятся в корзине.'];
    $byId=[];foreach($owned as $row)$byId[(int)$row['id']]=$row;
    $now=current_time('mysql');
    $wpdb->query('START TRANSACTION');
    try {
        foreach($gameIds as $gameId){
            $ok=$wpdb->insert($trash,['game_id'=>$gameId,'created_by_user_id'=>(int)($byId[$gameId]['created_by_user_id'] ?? 0),'tenant_id'=>$tenantId,'trashed_by_user_id'=>get_current_user_id(),'trashed_at'=>$now],['%d','%d','%d','%d','%s']);
            if($ok===false) throw new RuntimeException('trash_insert');
            if(!ckm_quiz_pro_history_audit_log($byId[$gameId]??['id'=>$gameId],'trash',$userId,$tenantId,['mode'=>'bulk'])) throw new RuntimeException('audit_insert');
        }
        $wpdb->query('COMMIT');
        return ['ok'=>true,'count'=>count($gameIds)];
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось переместить выбранные игры в корзину. Таблицы истории проверены; операция отменена без потери данных.'];
    }
}

function ckm_quiz_pro_history_restore_games(array $gameIds,int $userId,int $tenantId): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории.'];
    $gameIds=array_values(array_unique(array_filter(array_map('absint',$gameIds))));
    if(!$gameIds) return ['ok'=>false,'error'=>'Не выбрана ни одна игра.'];
    if(count($gameIds)>100) return ['ok'=>false,'error'=>'За один раз можно восстановить не более 100 игр.'];
    $trash=ckm_quiz_pro_table('history_trash');$games=ckm_quiz_pro_table('games');
    $placeholders=implode(',',array_fill(0,count($gameIds),'%d'));
    $params=array_merge([$tenantId],$gameIds);
    $owned=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$trash} ht INNER JOIN {$games} g ON g.id=ht.game_id WHERE ht.tenant_id=%d AND ht.game_id IN ({$placeholders})",...$params),ARRAY_A) ?: [];
    if(count($owned)!==count($gameIds)) return ['ok'=>false,'error'=>'Одна или несколько выбранных игр не найдены в вашей корзине.'];
    $byId=[];foreach($owned as $row)$byId[(int)$row['id']]=$row;
    $wpdb->query('START TRANSACTION');
    try {
        $deleteParams=array_merge([$tenantId],$gameIds);
        $deleted=$wpdb->query($wpdb->prepare("DELETE FROM {$trash} WHERE tenant_id=%d AND game_id IN ({$placeholders})",...$deleteParams));
        if($deleted===false || (int)$deleted!==count($gameIds)) throw new RuntimeException('trash_restore');
        foreach($gameIds as $gameId){
            if(!ckm_quiz_pro_history_audit_log($byId[$gameId]??['id'=>$gameId],'restore',$userId,$tenantId,['mode'=>'bulk'])) throw new RuntimeException('audit_insert');
        }
        $wpdb->query('COMMIT');
        return ['ok'=>true,'count'=>(int)$deleted];
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось восстановить выбранные игры. Все изменения отменены.'];
    }
}

function ckm_quiz_pro_history_permanent_delete_games(array $gameIds,int $userId,int $tenantId,string $confirm): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории.'];
    $gameIds=array_values(array_unique(array_filter(array_map('absint',$gameIds))));
    if(!$gameIds) return ['ok'=>false,'error'=>'Не выбрана ни одна игра.'];
    if(count($gameIds)>100) return ['ok'=>false,'error'=>'За один раз можно окончательно удалить не более 100 игр.'];
    if(!hash_equals('УДАЛИТЬ НАВСЕГДА',trim($confirm))) return ['ok'=>false,'error'=>'Окончательное удаление не подтверждено.'];
    $trash=ckm_quiz_pro_table('history_trash');$games=ckm_quiz_pro_table('games');
    $placeholders=implode(',',array_fill(0,count($gameIds),'%d'));
    $params=array_merge([$tenantId],$gameIds);
    $owned=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$trash} ht INNER JOIN {$games} g ON g.id=ht.game_id WHERE ht.tenant_id=%d AND ht.game_id IN ({$placeholders})",...$params),ARRAY_A) ?: [];
    if(count($owned)!==count($gameIds)) return ['ok'=>false,'error'=>'Одна или несколько выбранных игр не найдены в вашей корзине.'];
    $byId=[];foreach($owned as $row)$byId[(int)$row['id']]=$row;
    $wpdb->query('START TRANSACTION');
    try {
        foreach($gameIds as $gameId){
            if(!ckm_quiz_pro_history_audit_log($byId[$gameId]??['id'=>$gameId],'permanent_delete',$userId,$tenantId,['mode'=>'manual'])) throw new RuntimeException('audit_insert');
            ckm_quiz_pro_history_delete_game_rows($gameId,$userId,$tenantId);
        }
        $wpdb->query('COMMIT');
        return ['ok'=>true,'count'=>count($gameIds)];
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось окончательно удалить выбранные игры. Все изменения отменены.'];
    }
}

function ckm_quiz_pro_history_trash_period(string $raw): string {
    $raw=sanitize_key($raw);
    return in_array($raw,['all','today','7d','30d','older30'],true) ? $raw : 'all';
}

function ckm_quiz_pro_history_trash_cutoff(int $days): string {
    $days=max(1,$days);
    $now=new DateTimeImmutable('now',wp_timezone());
    return $now->modify('-'.$days.' days')->format('Y-m-d H:i:s');
}

/**
 * Hard-clear the complete history trash for the current tenant.
 * This path deliberately does not depend on selected checkboxes, filters, the games table join,
 * or optional history audit writes. It also removes orphan trash rows whose game row is already gone.
 */
function ckm_quiz_pro_history_clear_entire_trash(int $userId,int $tenantId): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицу корзины.'];

    $trash=ckm_quiz_pro_table('history_trash');
    $games=ckm_quiz_pro_table('games');
    if($trash==='' || $games==='') return ['ok'=>false,'error'=>'Не найдены таблицы истории.'];

    // Read directly from the trash table. Do not INNER JOIN games: orphan trash rows must also be removable.
    $trashRows=$wpdb->get_results($wpdb->prepare(
        "SELECT id,game_id FROM {$trash} WHERE tenant_id=%d ORDER BY id ASC",
        $tenantId
    ),ARRAY_A) ?: [];
    if(!$trashRows) return ['ok'=>true,'count'=>0,'failed'=>0];

    $tableExists=static function(string $table) use ($wpdb): bool {
        if($table==='') return false;
        $found=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
        return (string)$found===$table;
    };
    $hasGameId=static function(string $table) use ($wpdb,$tableExists): bool {
        if(!$tableExists($table)) return false;
        return (bool)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s",'game_id'));
    };

    $logicalTables=['mechanics','score_events','answers','events','members','teams','drafts','appeals','thinking_analysis','methodology_analysis','report_jobs','history_reports'];
    $related=[];
    foreach($logicalTables as $name){
        $table=ckm_quiz_pro_table($name);
        if($table!=='' && $hasGameId($table)) $related[$name]=$table;
    }
    $showTable=function_exists('ckmqp_show_table') ? ckmqp_show_table() : '';
    if($showTable!=='' && !$hasGameId($showTable)) $showTable='';

    $cleared=0;$failed=0;
    foreach($trashRows as $trashRow){
        $trashId=(int)($trashRow['id'] ?? 0);
        $gameId=(int)($trashRow['game_id'] ?? 0);
        if($trashId<=0){$failed++;continue;}

        // If the game still exists, delete all known child rows best-effort, then the game itself.
        $game=$gameId>0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$games} WHERE id=%d LIMIT 1",$gameId),ARRAY_A) : null;
        if($game){
            // Audit is best-effort here: it must never be able to block the admin's hard clear.
            if(function_exists('ckm_quiz_pro_history_audit_log')) @ckm_quiz_pro_history_audit_log($game,'purge_delete',$userId,$tenantId,['mode'=>'hard_clear_all']);

            // Remove generated PDF file before removing its report row, when available.
            if(isset($related['history_reports'])){
                $paths=$wpdb->get_col($wpdb->prepare("SELECT pdf_path FROM {$related['history_reports']} WHERE game_id=%d",$gameId)) ?: [];
                foreach($paths as $path){
                    $path=is_string($path)?trim($path):'';
                    if($path!=='' && is_file($path)) @unlink($path);
                }
            }

            if($showTable!=='') $wpdb->query($wpdb->prepare("DELETE FROM {$showTable} WHERE game_id=%d",$gameId));
            foreach($related as $table) $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE game_id=%d",$gameId));

            $deletedGame=$wpdb->query($wpdb->prepare("DELETE FROM {$games} WHERE id=%d",$gameId));
            if($deletedGame===false){$failed++;continue;}
        }

        // Delete the trash marker by its primary key. This also clears orphan markers.
        $deletedTrash=$wpdb->query($wpdb->prepare("DELETE FROM {$trash} WHERE id=%d AND tenant_id=%d",$trashId,$tenantId));
        if($deletedTrash===false){$failed++;continue;}
        $cleared++;
    }

    $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trash} WHERE tenant_id=%d",$tenantId));
    return ['ok'=>$remaining===0,'count'=>$cleared,'failed'=>$failed+$remaining,'remaining'=>$remaining,
        'error'=>$remaining===0?'':('Не удалось удалить записей из корзины: '.$remaining.'. Повторите очистку.')];
}

function ckm_quiz_pro_history_purge_all_trash(int $userId,int $tenantId,string $confirm): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории.'];
    if(!hash_equals('ОЧИСТИТЬ КОРЗИНУ',trim($confirm))) return ['ok'=>false,'error'=>'Полная очистка корзины не подтверждена.'];
    $trash=ckm_quiz_pro_table('history_trash');$games=ckm_quiz_pro_table('games');
    $rows=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$trash} ht INNER JOIN {$games} g ON g.id=ht.game_id WHERE ht.tenant_id=%d ORDER BY ht.trashed_at ASC,ht.id ASC LIMIT 500",$tenantId),ARRAY_A) ?: [];
    if(!$rows) return ['ok'=>true,'count'=>0,'remaining'=>0];
    $wpdb->query('START TRANSACTION');
    try {
        foreach($rows as $game){
            $gameId=(int)$game['id'];
            if(!ckm_quiz_pro_history_audit_log($game,'purge_delete',$userId,$tenantId,['mode'=>'all'])) throw new RuntimeException('audit_insert');
            ckm_quiz_pro_history_delete_game_rows($gameId,$userId,$tenantId);
        }
        $wpdb->query('COMMIT');
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось полностью очистить корзину. Все изменения отменены.'];
    }
    $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trash} WHERE tenant_id=%d",$tenantId));
    return ['ok'=>true,'count'=>count($rows),'remaining'=>$remaining];
}

function ckm_quiz_pro_history_purge_trash_by_age(int $days,int $userId,int $tenantId,string $confirm): array {
    global $wpdb;
    if(!ckm_quiz_pro_history_admin_access($userId,$tenantId)) return ['ok'=>false,'error'=>'История доступна только WordPress-администратору.'];
    if(!ckm_quiz_pro_history_ensure_storage()) return ['ok'=>false,'error'=>'Не удалось подготовить таблицы корзины и журнала истории.'];
    if(!in_array($days,[30,90,180],true)) return ['ok'=>false,'error'=>'Недопустимый срок очистки корзины.'];
    if(!hash_equals('ОЧИСТИТЬ КОРЗИНУ',trim($confirm))) return ['ok'=>false,'error'=>'Очистка корзины не подтверждена.'];
    $trash=ckm_quiz_pro_table('history_trash');$games=ckm_quiz_pro_table('games');
    $cutoff=ckm_quiz_pro_history_trash_cutoff($days);
    $rows=$wpdb->get_results($wpdb->prepare("SELECT g.* FROM {$trash} ht INNER JOIN {$games} g ON g.id=ht.game_id WHERE ht.tenant_id=%d AND ht.trashed_at<%s ORDER BY ht.trashed_at ASC,ht.id ASC LIMIT 500",$tenantId,$cutoff),ARRAY_A) ?: [];
    if(!$rows) return ['ok'=>true,'count'=>0,'remaining'=>0,'days'=>$days];
    $wpdb->query('START TRANSACTION');
    try {
        foreach($rows as $game){
            $gameId=(int)$game['id'];
            if(!ckm_quiz_pro_history_audit_log($game,'purge_delete',$userId,$tenantId,['days'=>$days])) throw new RuntimeException('audit_insert');
            ckm_quiz_pro_history_delete_game_rows($gameId,$userId,$tenantId);
        }
        $wpdb->query('COMMIT');
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>'Не удалось очистить корзину по сроку. Все изменения отменены.'];
    }
    $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trash} WHERE tenant_id=%d AND trashed_at<%s",$tenantId,$cutoff));
    return ['ok'=>true,'count'=>count($rows),'remaining'=>$remaining,'days'=>$days];
}

function ckm_quiz_pro_history_team_score(array $team): int {
    // `score` is the authoritative live/final total for the common quiz runtimes.
    // `final_score` exists in the schema but is not populated by every finish path.
    return (int)($team['score'] ?? $team['final_score'] ?? 0);
}

function ckm_quiz_pro_history_points_text(int $score): string {
    $n=abs($score);$n100=$n%100;$n10=$n%10;
    if($n100>=11 && $n100<=14) $word='очков';
    elseif($n10===1) $word='очко';
    elseif($n10>=2 && $n10<=4) $word='очка';
    else $word='очков';
    return $score.' '.$word;
}

function ckm_quiz_pro_history_duration(array $game): string {
    $start=trim((string)($game['started_at'] ?? ''));
    $end=trim((string)($game['finished_at'] ?? ''));
    if($start==='' || $end==='') return '—';
    $startTs=strtotime($start.' UTC');
    $endTs=strtotime($end.' UTC');
    if(!$startTs || !$endTs || $endTs<$startTs) return '—';
    $seconds=$endTs-$startTs;
    $hours=intdiv($seconds,3600);
    $minutes=intdiv($seconds%3600,60);
    $secs=$seconds%60;
    if($hours>0) return $hours.' ч '.str_pad((string)$minutes,2,'0',STR_PAD_LEFT).' мин';
    if($minutes>0) return $minutes.' мин '.str_pad((string)$secs,2,'0',STR_PAD_LEFT).' сек';
    return $secs.' сек';
}

function ckm_quiz_pro_history_activity_metric(array $answers,array $scoreEvents,array $events,?array $show): array {
    if(is_array($show)){
        $attempts=[];
        foreach((array)($show['messages'] ?? []) as $m){
            $attempts[(int)($m['round'] ?? 0).':'.(int)($m['attempt'] ?? 0)]=true;
        }
        foreach(['reviews'=>0,'hiddenReviews'=>1,'hardReviews'=>2,'storyResults'=>3] as $key=>$round){
            foreach((array)($show[$key] ?? []) as $attempt=>$unused) $attempts[$round.':'.(int)$attempt]=true;
        }
        foreach((array)($show['hardAnswers'] ?? []) as $attempt=>$unused) $attempts['2:'.(int)$attempt]=true;
        foreach((array)($show['storyStories'] ?? []) as $attempt=>$unused) $attempts['3:'.(int)$attempt]=true;
        return ['Испытаний',count($attempts)];
    }
    $questions=[];
    foreach([$answers,$scoreEvents,$events] as $rows){
        foreach($rows as $row){
            $qid=(int)($row['question_id'] ?? 0);
            if($qid>0) $questions[$qid]=true;
        }
    }
    return ['Сыграно вопросов',count($questions)];
}

function ckm_quiz_pro_history_rankings(array $teams,array $game): array {
    $finished=sanitize_key((string)($game['status'] ?? ''))==='finished';
    $ranked=[];
    foreach($teams as $team){
        $ranked[]=[
            'team'=>$team,
            'score'=>ckm_quiz_pro_history_team_score($team),
            'slot'=>(int)($team['slot_no'] ?? 0),
            'place'=>0,
        ];
    }
    usort($ranked,static function($a,$b){
        if($a['score']===$b['score']) return $a['slot']<=>$b['slot'];
        return $b['score']<=>$a['score'];
    });
    $lastScore=null;$lastPlace=0;
    foreach($ranked as $i=>&$row){
        if($lastScore===null || $row['score']!==$lastScore) $lastPlace=$i+1;
        $row['place']=$lastPlace;
        $lastScore=$row['score'];
    }
    unset($row);
    return $ranked;
}

function ckm_quiz_pro_history_result_copy(array $game,array $ranked): array {
    $status=sanitize_key((string)($game['status'] ?? ''));
    if($status!=='finished'){
        if($status==='cancelled') return ['Игра отменена','Финальный победитель не определяется. Ниже сохранён счёт на момент остановки.'];
        return ['Игра ещё не завершена','Показан текущий счёт. Итоговые места будут зафиксированы после завершения игры.'];
    }
    if(!$ranked) return ['Игра завершена','Команды или итоговый счёт в истории не найдены.'];
    if(count($ranked)===1){
        $name=(string)($ranked[0]['team']['team_name'] ?? 'Команда');
        return ['Результат команды: '.$name,'Итоговый результат — '.ckm_quiz_pro_history_points_text((int)$ranked[0]['score']).'.'];
    }
    $topScore=$ranked[0]['score'];
    $winners=[];
    foreach($ranked as $row){
        if($row['score']!==$topScore) break;
        $winners[]=(string)($row['team']['team_name'] ?? 'Команда');
    }
    if(count($winners)>1){
        return ['Первое место разделили '.implode(' и ',$winners),'У команд одинаковый итоговый результат — '.ckm_quiz_pro_history_points_text((int)$topScore).'.'];
    }
    return ['Победитель: '.$winners[0],'Итоговый результат победителя — '.ckm_quiz_pro_history_points_text((int)$topScore).'.'];
}

function ckm_quiz_pro_org_history_detail(int $gameId): void {
    global $wpdb;
    $uid=ckm_quiz_pro_effective_organizer_user_id();$tenant=ckmqp_scope_id();
    if(!ckm_quiz_pro_history_admin_access($uid,$tenant)){status_header(403);echo '<div class="ckm-card"><h1>История недоступна</h1><p>Раздел доступен только WordPress-администратору.</p></div>';return;}
    $game=$wpdb->get_row($wpdb->prepare('SELECT g.* FROM '.ckm_quiz_pro_table('games').' g LEFT JOIN '.ckm_quiz_pro_table('history_trash').' ht ON ht.game_id=g.id WHERE g.id=%d AND g.tenant_id=%d AND ht.game_id IS NULL LIMIT 1',$gameId,$tenant),ARRAY_A);
    $back=ckm_quiz_pro_organizer_url(array_merge(['view'=>'results'],ckm_quiz_pro_history_filter_args()));
    if(!$game){
        status_header(404);
        echo '<div class="ckm-card"><h1>История игры не найдена</h1><p>Эта игра не относится к вашему кабинету или площадке.</p><a class="ckm-btn" href="'.esc_url($back).'">К истории игр</a></div>';
        return;
    }
    $teams=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('teams').' WHERE game_id=%d ORDER BY slot_no,id',$gameId),ARRAY_A) ?: [];
    $teamById=[];foreach($teams as $t)$teamById[(int)$t['id']]=$t;
    $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',(int)$game['quiz_id'],(int)$game['quiz_revision']),ARRAY_A) ?: [];
    $questionById=[];foreach($questions as $q)$questionById[(int)$q['id']]=$q;
    $answers=$wpdb->get_results($wpdb->prepare('SELECT a.*,t.team_name,t.slot_no FROM '.ckm_quiz_pro_table('answers').' a LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=a.team_id WHERE a.game_id=%d ORDER BY a.question_id,a.submitted_at,a.id',$gameId),ARRAY_A) ?: [];
    $scoreEvents=$wpdb->get_results($wpdb->prepare('SELECT s.*,t.team_name,t.slot_no FROM '.ckm_quiz_pro_table('score_events').' s LEFT JOIN '.ckm_quiz_pro_table('teams').' t ON t.id=s.team_id WHERE s.game_id=%d ORDER BY s.created_at,s.id',$gameId),ARRAY_A) ?: [];
    $events=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('events').' WHERE game_id=%d ORDER BY id ASC',$gameId),ARRAY_A) ?: [];
    $show=ckm_quiz_pro_history_is_persuade_me($game) ? ckm_quiz_pro_history_show_session($gameId,$tenant,$teams) : null;
    $ranked=ckm_quiz_pro_history_rankings($teams,$game);
    [$resultTitle,$resultText]=ckm_quiz_pro_history_result_copy($game,$ranked);
    [$activityLabel,$activityCount]=ckm_quiz_pro_history_activity_metric($answers,$scoreEvents,$events,$show);

    $exportUrl=wp_nonce_url(ckm_quiz_pro_organizer_url(['view'=>'results','history_export'=>'game','history_game'=>$gameId]),'ckm_qp_history_export_game_'.$gameId);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">ИСТОРИЯ ИГРЫ</div><h1>'.esc_html((string)($game['title'] ?: $game['game_code'])).'</h1><p class="ckm-muted">Код игры: <code>'.esc_html((string)$game['game_code']).'</code></p></div><div class="ckm-history-head-actions"><button class="ckm-btn ckm-btn-small" type="button" id="ckm-history-expand-all">Развернуть всё</button><button class="ckm-btn ckm-btn-small" type="button" id="ckm-history-collapse-all">Свернуть всё</button><a class="ckm-btn" href="'.esc_url($back).'">← Все игры</a><a class="ckm-btn ckm-btn-primary" href="'.esc_url($exportUrl).'">Скачать CSV</a></div></div>';
    echo '<div class="ckm-history-meta">';
    $meta=[
        ['Формат',ckm_quiz_pro_history_format_label($game)],['Статус',ckm_quiz_pro_history_status_label((string)$game['status'])],
        ['Ведущий',ckm_quiz_pro_history_host_label($game)],['Создана',ckm_quiz_pro_history_datetime($game['created_at']??'')],
        ['Начало',ckm_quiz_pro_history_datetime($game['started_at']??'')],['Завершение',ckm_quiz_pro_history_datetime($game['finished_at']??'')],
    ];
    foreach($meta as $m){
        $value=esc_html($m[1]);
        if($m[0]==='Статус') $value='<strong class="ckm-history-status '.esc_attr(ckm_quiz_pro_history_status_class((string)$game['status'])).'">'.$value.'</strong>';
        else $value='<strong class="ckm-history-stat-value">'.$value.'</strong>';
        echo '<div class="ckm-stat"><span>'.esc_html($m[0]).'</span>'.$value.'</div>';
    }
    echo '</div>';

    echo '<div class="ckm-card ckm-history-result-card"><div class="ckm-kicker">РЕЗУЛЬТАТ</div><div class="ckm-history-result-head"><div><h2>'.esc_html($resultTitle).'</h2><p class="ckm-muted">'.esc_html($resultText).'</p></div><div class="ckm-history-result-metrics"><div><span>Длительность</span><strong>'.esc_html(ckm_quiz_pro_history_duration($game)).'</strong></div><div><span>'.esc_html($activityLabel).'</span><strong>'.(int)$activityCount.'</strong></div><div><span>Команд</span><strong>'.count($teams).'</strong></div></div></div>';
    if($ranked){
        echo '<div class="ckm-history-ranking">';
        $finished=sanitize_key((string)($game['status'] ?? ''))==='finished';
        foreach($ranked as $row){
            $name=(string)($row['team']['team_name'] ?? 'Команда');
            $place=count($ranked)===1 ? 'Результат' : ($finished ? ((int)$row['place'].' место') : ('Текущая позиция '.(int)$row['place']));
            echo '<div class="ckm-history-rank-row"><span class="ckm-history-place">'.esc_html($place).'</span><strong>'.esc_html($name).'</strong><span class="ckm-history-rank-score">'.esc_html(ckm_quiz_pro_history_points_text((int)$row['score'])).'</span></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    $scoreHeading=sanitize_key((string)($game['status'] ?? ''))==='finished' ? 'Итоговый счёт' : 'Текущий счёт';
    echo '<div class="ckm-card"><div class="ckm-kicker">ИТОГ</div><h2>Команды и результат</h2><div class="ckm-table-wrap"><table class="ckm-table"><thead><tr><th>Команда</th><th>'.esc_html($scoreHeading).'</th><th>Итог</th></tr></thead><tbody>';
    foreach($teams as $t){
        $summary=trim((string)($t['final_summary'] ?? ''));
        echo '<tr><td><strong>'.esc_html((string)$t['team_name']).'</strong></td><td>'.esc_html(ckm_quiz_pro_history_points_text(ckm_quiz_pro_history_team_score($t))).'</td><td>'.($summary!==''?nl2br(esc_html($summary)):'—').'</td></tr>';
    }
    if(!$teams) echo '<tr><td colspan="3">Команды не найдены.</td></tr>';
    echo '</tbody></table></div></div>';

    if($show) ckm_quiz_pro_history_render_persuade_me($show,$teams);

    $answersByQ=[];foreach($answers as $a)$answersByQ[(int)$a['question_id']][]=$a;
    $played=[];foreach($answers as $a)if((int)$a['question_id']>0)$played[(int)$a['question_id']]=true;foreach($scoreEvents as $s)if((int)$s['question_id']>0)$played[(int)$s['question_id']]=true;foreach($events as $e)if((int)$e['question_id']>0)$played[(int)$e['question_id']]=true;
    if($played){
        echo '<div class="ckm-card"><div class="ckm-kicker">ХОД ИГРЫ</div><h2>Вопросы и ответы</h2><p class="ckm-muted">Каждый вопрос можно свернуть отдельно. Это удобно для длинных игр.</p>';
        $ordered=array_keys($played);usort($ordered,static function($a,$b)use($questionById){return (int)($questionById[$a]['position']??999999)<=>(int)($questionById[$b]['position']??999999);});
        foreach($ordered as $qid){
            $q=$questionById[$qid]??null;$title=$q?trim((string)$q['question_text']):('Вопрос #'.$qid);$position=$q?(int)$q['position']:0;$roundTitle=$q?trim((string)($q['round_title']??'')):'';
            $qAnswers=$answersByQ[$qid]??[];
            echo '<details class="ckm-history-question ckm-history-fold" open><summary><div class="ckm-history-question-summary"><div><span class="ckm-badge">'.($position>0?'Вопрос '.$position:'Вопрос').'</span>'.($roundTitle!==''?' <span class="ckm-badge">'.esc_html($roundTitle).'</span>':'').' <span class="ckm-history-count">'.count($qAnswers).' '.(count($qAnswers)===1?'ответ':'ответов').'</span></div><strong>'.esc_html($title!==''?$title:'Без текста вопроса').'</strong></div></summary><div class="ckm-history-fold-body">';
            if($qAnswers){
                echo '<div class="ckm-table-wrap"><table class="ckm-table"><thead><tr><th>Команда</th><th>Ответ</th><th>Решение</th><th>Баллы</th><th>Комментарий</th><th>Время</th></tr></thead><tbody>';
                foreach($qAnswers as $a){
                    echo '<tr><td>'.esc_html((string)($a['team_name'] ?: 'Команда')).'</td><td>'.nl2br(esc_html((string)($a['answer_text']??''))).'</td><td>'.esc_html(ckm_quiz_pro_history_verdict_label((string)$a['verdict'])).'</td><td>'.(int)$a['awarded_points'].'</td><td>'.nl2br(esc_html(trim((string)($a['judge_comment']??'')) ?: '—')).'</td><td>'.esc_html(ckm_quiz_pro_history_datetime($a['submitted_at']??'')).'</td></tr>';
                }
                echo '</tbody></table></div>';
            } else echo '<p class="ckm-muted">Ответов по этому вопросу нет.</p>';
            echo '</div></details>';
        }
        echo '</div>';
    }

    echo '<details class="ckm-card ckm-history-fold ckm-history-major-fold" open><summary><div><div class="ckm-kicker">СЧЁТ</div><h2>Журнал начислений <span class="ckm-history-count">'.count($scoreEvents).'</span></h2></div></summary><div class="ckm-history-fold-body">';
    if($scoreEvents){
        echo '<div class="ckm-table-wrap"><table class="ckm-table"><thead><tr><th>Время</th><th>Команда</th><th>Событие</th><th>Изменение</th><th>Счёт</th><th>Причина</th></tr></thead><tbody>';
        foreach($scoreEvents as $s){$delta=(int)$s['points_delta'];echo '<tr><td>'.esc_html(ckm_quiz_pro_history_datetime($s['created_at']??'')).'</td><td>'.esc_html((string)($s['team_name']??'—')).'</td><td><code>'.esc_html((string)$s['event_type']).'</code></td><td><strong>'.($delta>=0?'+':'').$delta.'</strong></td><td>'.(int)$s['score_before'].' → '.(int)$s['score_after'].'</td><td>'.nl2br(esc_html(trim((string)($s['reason']??'')) ?: '—')).'</td></tr>';}
        echo '</tbody></table></div>';
    } else echo '<p class="ckm-muted">Отдельных записей начисления баллов нет.</p>';
    echo '</div></details>';

    echo '<details class="ckm-card ckm-history-fold ckm-history-major-fold"><summary><div><div class="ckm-kicker">СОБЫТИЯ</div><h2>Серверная хронология <span class="ckm-history-count">'.count($events).'</span></h2><p class="ckm-muted">Технический журнал по умолчанию свёрнут.</p></div></summary><div class="ckm-history-fold-body">';
    if($events){
        echo '<div class="ckm-history-timeline">';
        foreach($events as $e){$team=$teamById[(int)($e['team_id']??0)]['team_name']??'';$note=ckm_quiz_pro_history_event_note($e);echo '<div class="ckm-history-event"><div class="ckm-history-event-time">'.esc_html(ckm_quiz_pro_history_datetime($e['created_at']??'')).'</div><div><strong>'.esc_html(ckm_quiz_pro_history_event_label((string)$e['action'])).'</strong> <small><code>'.esc_html((string)$e['action']).'</code></small><div class="ckm-muted">'.esc_html(ckm_quiz_pro_history_actor_label((string)$e['actor_type']).($team!==''?' · '.$team:'')).'</div>'.($note!==''?'<div class="ckm-history-event-note">'.nl2br(esc_html($note)).'</div>':'').'</div></div>';}
        echo '</div>';
    } else echo '<p class="ckm-muted">События этой игры не найдены.</p>';
    echo '</div></details>';
    echo '<script>(function(){var root=document.querySelector(".ckm-main");if(!root)return;var folds=root.querySelectorAll("details.ckm-history-fold");var openBtn=document.getElementById("ckm-history-expand-all"),closeBtn=document.getElementById("ckm-history-collapse-all");if(openBtn)openBtn.addEventListener("click",function(){folds.forEach(function(x){x.open=true;});});if(closeBtn)closeBtn.addEventListener("click",function(){folds.forEach(function(x){x.open=false;});});})();</script>';
}

function ckm_quiz_pro_org_history_audit(): void {
    global $wpdb;
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $tenant=ckmqp_scope_id();
    if(!ckm_quiz_pro_history_admin_access($uid,$tenant)){status_header(403);echo '<div class="ckm-card"><h1>История недоступна</h1><p>Раздел доступен только WordPress-администратору.</p></div>';return;}
    $table=ckm_quiz_pro_table('history_audit');
    $action=sanitize_key((string)($_GET['audit_action'] ?? 'all'));
    $allowedActions=['all','trash','restore','permanent_delete','purge_delete'];
    if(!in_array($action,$allowedActions,true)) $action='all';
    $where='tenant_id=%d';$params=[$tenant];
    if($action!=='all'){$where.=' AND action_key=%s';$params[]=$action;}
    $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where}",...$params));
    $page=max(1,absint($_GET['history_page'] ?? 1));$perPage=50;$pageCount=max(1,(int)ceil($total/$perPage));if($page>$pageCount)$page=$pageCount;$offset=($page-1)*$perPage;
    $queryParams=array_merge($params,[$perPage,$offset]);
    $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY occurred_at DESC,id DESC LIMIT %d OFFSET %d",...$queryParams),ARRAY_A) ?: [];
    $back=ckm_quiz_pro_organizer_url(['view'=>'results']);
    $trash=ckm_quiz_pro_organizer_url(['view'=>'results','history_trash'=>1]);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">ИСТОРИЯ</div><h1>Журнал действий</h1><p class="ckm-muted">Кто и когда перемещал игры в корзину, восстанавливал или удалял их окончательно. Журнал доступен только для просмотра.</p></div><div class="ckm-history-head-actions"><a class="ckm-btn" href="'.esc_url($back).'">← К истории</a><a class="ckm-btn" href="'.esc_url($trash).'">Корзина</a></div></div>';
    echo '<form class="ckm-history-filters ckm-history-audit-filter" method="get"><input type="hidden" name="view" value="results"><input type="hidden" name="history_audit" value="1">';
    foreach(['ckm_preview_user','ckm_preview_nonce'] as $keep) if(isset($_GET[$keep])) echo '<input type="hidden" name="'.esc_attr($keep).'" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET[$keep]))).'">';
    echo '<label class="ckm-label">Действие<select class="ckm-input" name="audit_action"><option value="all" '.selected($action,'all',false).'>Все действия</option><option value="trash" '.selected($action,'trash',false).'>В корзину</option><option value="restore" '.selected($action,'restore',false).'>Восстановление</option><option value="permanent_delete" '.selected($action,'permanent_delete',false).'>Удаление навсегда</option><option value="purge_delete" '.selected($action,'purge_delete',false).'>Очистка по сроку</option></select></label>';
    echo '<div class="ckm-history-filter-actions"><button class="ckm-btn ckm-btn-primary" type="submit">Применить</button><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'results','history_audit'=>1])).'">Сбросить</a></div></form>';
    if(!$rows){echo '<div class="ckm-card"><h2>Журнал пока пуст</h2><p class="ckm-muted">Новые действия с историей начнут фиксироваться после установки этой версии плагина.</p></div>';return;}
    echo '<div class="ckm-table-wrap"><table class="ckm-table ckm-history-audit-table"><thead><tr><th>Время</th><th>Действие</th><th>Игра</th><th>Кто выполнил</th><th>Детали</th></tr></thead><tbody>';
    foreach($rows as $row){
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        $detailText='—';
        if(($row['action_key']??'')==='purge_delete' && !empty($details['days'])) $detailText='Очистка: старше '.(int)$details['days'].' дней';
        elseif(!empty($details['mode']) && $details['mode']==='bulk') $detailText='Массовое действие';
        elseif(!empty($details['mode']) && $details['mode']==='single') $detailText='Одна игра';
        $title=trim((string)($row['game_title']??''));$code=trim((string)($row['game_code']??''));
        $actor=trim((string)($row['actor_name_snapshot']??''));if($actor==='')$actor=(int)($row['actor_user_id']??0)>0?'Пользователь #'.(int)$row['actor_user_id']:'Система';
        echo '<tr><td>'.esc_html(ckm_quiz_pro_history_datetime($row['occurred_at']??'')).'</td><td><span class="ckm-badge">'.esc_html(ckm_quiz_pro_history_audit_action_label((string)($row['action_key']??''))).'</span></td><td><strong>'.esc_html($title!==''?$title:($code!==''?$code:'Игра #'.(int)($row['game_id']??0))).'</strong>'.($code!==''?'<br><small><code>'.esc_html($code).'</code></small>':'').'</td><td>'.esc_html($actor).((int)($row['actor_user_id']??0)>0?'<br><small>ID '.(int)$row['actor_user_id'].'</small>':'').'</td><td>'.esc_html($detailText).'</td></tr>';
    }
    echo '</tbody></table></div>';
    if($total>$perPage){$pageArgs=['view'=>'results','history_audit'=>1];if($action!=='all')$pageArgs['audit_action']=$action;echo '<nav class="ckm-history-pagination">';if($page>1)echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge($pageArgs,['history_page'=>$page-1]))).'">← Предыдущая</a>';echo '<span>Страница <strong>'.$page.'</strong> из <strong>'.$pageCount.'</strong></span>';if($page<$pageCount)echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge($pageArgs,['history_page'=>$page+1]))).'">Следующая →</a>';echo '</nav>';}
}

function ckm_quiz_pro_org_history_trash(): void {
    global $wpdb;
    $uid=ckm_quiz_pro_effective_organizer_user_id();$tenant=ckmqp_scope_id();
    if(!ckm_quiz_pro_history_admin_access($uid,$tenant)){status_header(403);echo '<div class="ckm-card"><h1>История недоступна</h1><p>Раздел доступен только WordPress-администратору.</p></div>';return;}
    $games=ckm_quiz_pro_table('games');$trash=ckm_quiz_pro_table('history_trash');$teams=ckm_quiz_pro_table('teams');
    $trashRawCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$trash} WHERE tenant_id=%d",$tenant));
    $allRows=$wpdb->get_results($wpdb->prepare("SELECT g.*,ht.trashed_at FROM {$trash} ht INNER JOIN {$games} g ON g.id=ht.game_id WHERE ht.tenant_id=%d ORDER BY ht.trashed_at DESC,ht.id DESC",$tenant),ARRAY_A) ?: [];
    $period=ckm_quiz_pro_history_trash_period((string)($_GET['trash_period'] ?? 'all'));
    $now=new DateTimeImmutable('now',wp_timezone());
    $todayStart=$now->setTime(0,0,0)->format('Y-m-d H:i:s');
    $cutoff7=$now->modify('-7 days')->format('Y-m-d H:i:s');
    $cutoff30=$now->modify('-30 days')->format('Y-m-d H:i:s');
    $periodCounts=['all'=>count($allRows),'today'=>0,'7d'=>0,'30d'=>0,'older30'=>0];
    foreach($allRows as $row){
        $dt=(string)($row['trashed_at'] ?? '');
        if($dt>=$todayStart)$periodCounts['today']++;
        if($dt>=$cutoff7)$periodCounts['7d']++;
        if($dt>=$cutoff30)$periodCounts['30d']++;
        if($dt!=='' && $dt<$cutoff30)$periodCounts['older30']++;
    }
    $rows=array_values(array_filter($allRows,static function($row)use($period,$todayStart,$cutoff7,$cutoff30){
        $dt=(string)($row['trashed_at'] ?? '');
        if($period==='today') return $dt>=$todayStart;
        if($period==='7d') return $dt>=$cutoff7;
        if($period==='30d') return $dt>=$cutoff30;
        if($period==='older30') return $dt!=='' && $dt<$cutoff30;
        return true;
    }));
    $teamsByGame=[];
    if($rows){
        $ids=array_map(static fn($g)=>(int)$g['id'],$rows);$ph=implode(',',array_fill(0,count($ids),'%d'));
        $teamRows=$wpdb->get_results($wpdb->prepare("SELECT game_id,team_name,score,final_score,slot_no FROM {$teams} WHERE game_id IN ({$ph}) ORDER BY game_id,slot_no,id",...$ids),ARRAY_A) ?: [];
        foreach($teamRows as $tr)$teamsByGame[(int)$tr['game_id']][]=$tr;
    }
    $page=max(1,absint($_GET['history_page'] ?? 1));$perPage=25;$total=count($rows);$pageCount=max(1,(int)ceil($total/$perPage));if($page>$pageCount)$page=$pageCount;$offset=($page-1)*$perPage;$pageRows=array_slice($rows,$offset,$perPage);
    $back=ckm_quiz_pro_organizer_url(['view'=>'results']);
    $audit=ckm_quiz_pro_organizer_url(['view'=>'results','history_audit'=>1]);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">ИСТОРИЯ</div><h1>Корзина</h1><p class="ckm-muted">Игры из корзины не отображаются в истории и аналитике, но их данные сохраняются до окончательного удаления.</p></div><div class="ckm-history-head-actions"><a class="ckm-btn" href="'.esc_url($audit).'">Журнал действий</a><a class="ckm-btn" href="'.esc_url($back).'">← К истории</a></div></div>';
    if(isset($_GET['history_clear_entire_done'])) echo '<div class="ckm-alert ckm-alert-ok">Корзина очищена полностью. Удалено записей: <strong>'.(int)$_GET['history_clear_entire_done'].'</strong>.</div>';
    if(isset($_GET['history_clear_entire_failed']) && (int)$_GET['history_clear_entire_failed']>0) echo '<div class="ckm-alert">Не удалось очистить записей: <strong>'.(int)$_GET['history_clear_entire_failed'].'</strong>. Повторите очистку.</div>';
    if(isset($_GET['history_restored'])) echo '<div class="ckm-alert ckm-alert-ok">Восстановлено игр: <strong>'.(int)$_GET['history_restored'].'</strong>.</div>';
    if(isset($_GET['history_permanent_deleted'])) echo '<div class="ckm-alert ckm-alert-ok">Окончательно удалено игр: <strong>'.(int)$_GET['history_permanent_deleted'].'</strong>.</div>';
    if(isset($_GET['history_purged_all'])) echo '<div class="ckm-alert ckm-alert-ok">Корзина очищена. Окончательно удалено игр: <strong>'.(int)$_GET['history_purged_all'].'</strong>.'.(isset($_GET['history_purge_all_remaining'])?' В корзине осталось: <strong>'.(int)$_GET['history_purge_all_remaining'].'</strong>. Нажмите «Очистить корзину полностью» ещё раз.':'').'</div>';
    if(isset($_GET['history_purge_all_none'])) echo '<div class="ckm-alert">Корзина уже пуста.</div>';
    if(isset($_GET['history_purged'])) echo '<div class="ckm-alert ckm-alert-ok">По сроку хранения окончательно удалено игр: <strong>'.(int)$_GET['history_purged'].'</strong>.'.(isset($_GET['history_purge_remaining'])?' Осталось подходящих под условие: <strong>'.(int)$_GET['history_purge_remaining'].'</strong>. Повторите очистку при необходимости.':'').'</div>';
    if(isset($_GET['history_purge_none'])) echo '<div class="ckm-alert">В корзине нет игр старше выбранного срока.</div>';
    if(isset($_GET['history_trash_error'])) echo '<div class="ckm-alert ckm-alert-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['history_trash_error']))).'</div>';

    echo '<form class="ckm-history-filters ckm-history-trash-filter" method="get">';
    echo '<input type="hidden" name="view" value="results"><input type="hidden" name="history_trash" value="1">';
    foreach(['ckm_preview_user','ckm_preview_nonce'] as $keep) if(isset($_GET[$keep])) echo '<input type="hidden" name="'.esc_attr($keep).'" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET[$keep]))).'">';
    echo '<label class="ckm-label">Когда удалено<select class="ckm-input" name="trash_period"><option value="all" '.selected($period,'all',false).'>Все ('.(int)$periodCounts['all'].')</option><option value="today" '.selected($period,'today',false).'>Сегодня ('.(int)$periodCounts['today'].')</option><option value="7d" '.selected($period,'7d',false).'>За 7 дней ('.(int)$periodCounts['7d'].')</option><option value="30d" '.selected($period,'30d',false).'>За 30 дней ('.(int)$periodCounts['30d'].')</option><option value="older30" '.selected($period,'older30',false).'>Старше 30 дней ('.(int)$periodCounts['older30'].')</option></select></label>';
    echo '<div class="ckm-history-filter-actions"><button class="ckm-btn ckm-btn-primary" type="submit">Применить</button><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'results','history_trash'=>1])).'">Сбросить</a></div></form>';

    $allowed=ckm_quiz_pro_preview_organizer_user_id()===0;
    if($allowed && $trashRawCount>0){
        echo '<form method="post" id="ckm-history-clear-entire-form" class="ckm-history-bulkbar ckm-history-purgebar">';wp_nonce_field('ckm_qp_history_clear_entire_trash');
        echo '<input type="hidden" name="ckm_qp_clear_entire_history_trash" value="1">';
        echo '<div><strong>Очистить всю корзину</strong><span class="ckm-muted"> Удалит абсолютно все записи корзины этой площадки, включая свежие и повреждённые записи.</span></div><div class="ckm-history-actions"><button class="ckm-btn ckm-btn-small ckm-btn-danger" id="ckm-history-clear-entire-button" type="submit">Очистить всю корзину</button></div></form>';

        echo '<form method="post" id="ckm-history-purge-form" class="ckm-history-bulkbar ckm-history-purgebar">';wp_nonce_field('ckm_qp_history_trash_actions');
        echo '<input type="hidden" name="ckm_qp_purge_history_trash_by_age" value="1"><input type="hidden" name="trash_purge_confirm" value=""><input type="hidden" name="trash_period" value="'.esc_attr($period).'">';
        echo '<div><strong>Очистка по сроку</strong><span class="ckm-muted"> Ничего не удаляется автоматически.</span></div><div class="ckm-history-actions"><select class="ckm-input" name="trash_purge_days"><option value="30">Старше 30 дней</option><option value="90">Старше 90 дней</option><option value="180">Старше 180 дней</option></select><button class="ckm-btn ckm-btn-small ckm-btn-danger" id="ckm-history-purge-button" type="submit">Удалить навсегда по сроку</button></div></form>';
    }

    if(!$allRows && $trashRawCount===0){echo '<div class="ckm-card"><h2>Корзина пуста</h2><p class="ckm-muted">Удалённые из истории игры будут появляться здесь.</p></div>';return;}
    if(!$allRows && $trashRawCount>0) echo '<div class="ckm-card"><h2>В корзине есть служебные записи</h2><p class="ckm-muted">Найдено записей: <strong>'.(int)$trashRawCount.'</strong>. Соответствующие строки игр уже отсутствуют, поэтому они не показываются в таблице. Нажмите «Очистить всю корзину», чтобы удалить их.</p></div>';
    if(!$rows) echo '<div class="ckm-card"><h2>По фильтру ничего не найдено</h2><p class="ckm-muted">В корзине есть игры, но ни одна не относится к выбранному периоду.</p></div>';
    if($allowed && $pageRows){
        echo '<form method="post" id="ckm-history-trash-form" class="ckm-history-bulkbar">';wp_nonce_field('ckm_qp_history_trash_actions');
        echo '<input type="hidden" name="history_permanent_confirm" value=""><input type="hidden" name="trash_period" value="'.esc_attr($period).'"><div><strong>Выбрано: <span id="ckm-history-selected-count">0</span></strong><span class="ckm-muted"> Можно восстановить или удалить навсегда.</span></div><div class="ckm-history-actions"><button class="ckm-btn ckm-btn-small" id="ckm-history-restore-button" type="submit" name="ckm_qp_restore_history_games" value="1" disabled>Восстановить</button><button class="ckm-btn ckm-btn-small ckm-btn-danger" id="ckm-history-permanent-button" type="submit" name="ckm_qp_permanent_delete_history_games" value="1" disabled>Удалить навсегда</button></div></form>';
    }
    if($pageRows){
        echo '<div class="ckm-table-wrap"><table class="ckm-table"><thead><tr>';
        if($allowed)echo '<th class="ckm-history-select-cell"><input type="checkbox" id="ckm-history-select-all" aria-label="Выбрать все игры на странице"></th>';
        echo '<th>Удалена</th><th>Игра</th><th>Формат</th><th>Команды</th><th>Итог</th><th>Статус</th></tr></thead><tbody>';
        foreach($pageRows as $g){$ts=$teamsByGame[(int)$g['id']]??[];$names=array_values(array_filter(array_map(static fn($t)=>trim((string)($t['team_name']??'')),$ts)));$score=[];foreach($ts as $t)$score[]=trim((string)($t['team_name']??'Команда')).': '.ckm_quiz_pro_history_team_score($t);
            echo '<tr>';if($allowed)echo '<td class="ckm-history-select-cell"><input class="ckm-history-row-check" type="checkbox" name="history_game_ids[]" value="'.(int)$g['id'].'" form="ckm-history-trash-form"></td>';
            echo '<td>'.esc_html(ckm_quiz_pro_history_datetime($g['trashed_at']??'')).'</td><td><strong>'.esc_html((string)($g['title']?:$g['game_code'])).'</strong><br><small><code>'.esc_html((string)$g['game_code']).'</code></small></td><td>'.esc_html(ckm_quiz_pro_history_format_label($g)).'</td><td>'.esc_html($names?implode(' · ',$names):'—').'</td><td>'.esc_html($score?implode(' · ',$score):'—').'</td><td><span class="ckm-history-status '.esc_attr(ckm_quiz_pro_history_status_class((string)$g['status'])).'">'.esc_html(ckm_quiz_pro_history_status_label((string)$g['status'])).'</span></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    if($total>$perPage){$pageArgs=['view'=>'results','history_trash'=>1];if($period!=='all')$pageArgs['trash_period']=$period;echo '<nav class="ckm-history-pagination">';if($page>1)echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge($pageArgs,['history_page'=>$page-1]))).'">← Предыдущая</a>';echo '<span>Страница <strong>'.$page.'</strong> из <strong>'.$pageCount.'</strong></span>';if($page<$pageCount)echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge($pageArgs,['history_page'=>$page+1]))).'">Следующая →</a>';echo '</nav>';}
    if($allowed) echo '<script>(function(){var form=document.getElementById("ckm-history-trash-form"),all=document.getElementById("ckm-history-select-all"),restore=document.getElementById("ckm-history-restore-button"),perm=document.getElementById("ckm-history-permanent-button"),count=document.getElementById("ckm-history-selected-count");if(form){var boxes=Array.prototype.slice.call(document.querySelectorAll(".ckm-history-row-check"));function selected(){return boxes.filter(function(x){return x.checked;});}function mirror(){form.querySelectorAll(".ckm-history-trash-id-mirror").forEach(function(x){x.remove();});selected().forEach(function(box){var hidden=document.createElement("input");hidden.type="hidden";hidden.name="history_game_ids[]";hidden.value=box.value;hidden.className="ckm-history-trash-id-mirror";form.appendChild(hidden);});}function sync(){var n=selected().length;if(count)count.textContent=String(n);if(restore)restore.disabled=n===0;if(perm)perm.disabled=n===0;if(all){all.checked=n>0&&n===boxes.length;all.indeterminate=n>0&&n<boxes.length;}}boxes.forEach(function(x){x.addEventListener("change",sync);});if(all)all.addEventListener("change",function(){boxes.forEach(function(x){x.checked=all.checked;});sync();});if(restore)restore.addEventListener("click",function(e){if(!selected().length||!window.confirm("Восстановить выбранные игры в историю?")){e.preventDefault();return;}mirror();});if(perm)perm.addEventListener("click",function(e){var n=selected().length;if(!n){e.preventDefault();return;}var entered=window.prompt("Выбранные игры будут удалены вместе с ответами и событиями без возможности восстановления. Для подтверждения напишите УДАЛИТЬ НАВСЕГДА");if(entered===null||entered.trim()!=="УДАЛИТЬ НАВСЕГДА"){e.preventDefault();return;}mirror();var input=form.querySelector("input[name=history_permanent_confirm]");if(input)input.value="УДАЛИТЬ НАВСЕГДА";});sync();}var clearAll=document.getElementById("ckm-history-clear-entire-form");if(clearAll)clearAll.addEventListener("submit",function(e){if(!window.confirm("Очистить ВСЮ корзину? Все находящиеся в ней игры будут удалены навсегда.")){e.preventDefault();}});var purge=document.getElementById("ckm-history-purge-form");if(purge)purge.addEventListener("submit",function(e){var select=purge.querySelector("select[name=trash_purge_days]");var days=select?select.value:"30";var entered=window.prompt("Игры, находящиеся в корзине дольше "+days+" дней, будут удалены навсегда вместе со всеми связанными данными. Для подтверждения напишите ОЧИСТИТЬ КОРЗИНУ");if(entered===null||entered.trim()!=="ОЧИСТИТЬ КОРЗИНУ"){e.preventDefault();return;}var input=purge.querySelector("input[name=trash_purge_confirm]");if(input)input.value="ОЧИСТИТЬ КОРЗИНУ";});})();</script>';
}

function ckm_quiz_pro_org_results(): void {
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $tenant=ckmqp_scope_id();
    if(!ckm_quiz_pro_history_admin_access($uid,$tenant)){status_header(403);echo '<div class="ckm-card"><h1>История недоступна</h1><p>Раздел доступен только WordPress-администратору.</p></div>';return;}
    if(!empty($_GET['history_audit'])){ckm_quiz_pro_org_history_audit();return;}
    if(!empty($_GET['history_trash'])){ckm_quiz_pro_org_history_trash();return;}
    $detailId=absint($_GET['history_game'] ?? 0);
    if($detailId>0){ckm_quiz_pro_org_history_detail($detailId);return;}

    $data=ckm_quiz_pro_history_dataset($uid,$tenant);
    $search=$data['search'];$statusFilter=$data['statusFilter'];$formatFilter=$data['formatFilter'];$dateFrom=$data['dateFrom'];$dateTo=$data['dateTo'];$sortOrder=$data['sortOrder'];
    $games=$data['games'];$salesAttempts=(array)($data['salesAttempts']??[]);$teamsByGame=$data['teamsByGame'];$formatOptions=$data['formatOptions'];$filtered=$data['filtered'];

    $page=max(1,absint($_GET['history_page'] ?? 1));
    $perPage=25;
    $total=count($filtered);
    $pageCount=max(1,(int)ceil($total/$perPage));
    if($page>$pageCount) $page=$pageCount;
    $offset=($page-1)*$perPage;
    $pageRows=array_slice($filtered,$offset,$perPage);
    $summary=ckm_quiz_pro_history_summary($games);
    foreach($salesAttempts as $attempt){
        $summary['total']++;
        $st=sanitize_key((string)($attempt['status']??''));
        if($st==='finished')$summary['finished']++;
        elseif(in_array($st,['running','active','live','countdown'],true))$summary['live']++;
    }
    $salesCount=count($salesAttempts);
    if($salesCount>0){
        if($salesCount>(int)$summary['popular_count']){$summary['popular_label']='Эффективный продажник';$summary['popular_count']=$salesCount;}
        elseif($salesCount===(int)$summary['popular_count'] && !str_contains((string)$summary['popular_label'],'Эффективный продажник')){$summary['popular_label']=trim((string)$summary['popular_label'].' · Эффективный продажник',' ·');}
    }

    $trashCount=ckm_quiz_pro_history_trash_count($uid,$tenant);
    $trashUrl=ckm_quiz_pro_organizer_url(['view'=>'results','history_trash'=>1]);
    $auditUrl=ckm_quiz_pro_organizer_url(['view'=>'results','history_audit'=>1]);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">ИСТОРИЯ</div><h1>История игр</h1><p class="ckm-muted">Все запуски организаторов этой площадки. История доступна только WordPress-администратору и сохраняется после окончания срока доступа к игровому формату.</p></div><div class="ckm-history-head-actions"><a class="ckm-btn" href="'.esc_url($auditUrl).'">Журнал действий</a><a class="ckm-btn" href="'.esc_url($trashUrl).'">Корзина'.($trashCount>0?' ('.$trashCount.')':'').'</a></div></div>';
    if(isset($_GET['history_trashed'])) echo '<div class="ckm-alert ckm-alert-ok">Игра перемещена в корзину.</div>';
    if(isset($_GET['history_delete_error'])) echo '<div class="ckm-alert ckm-alert-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['history_delete_error']))).'</div>';
    if(isset($_GET['history_bulk_trashed'])) echo '<div class="ckm-alert ckm-alert-ok">Перемещено в корзину игр: <strong>'.(int)$_GET['history_bulk_trashed'].'</strong>.</div>';
    if(isset($_GET['history_bulk_delete_error'])) echo '<div class="ckm-alert ckm-alert-error">'.esc_html(sanitize_text_field(wp_unslash($_GET['history_bulk_delete_error']))).'</div>';

    echo '<div class="ckm-history-summary" aria-label="Сводка по истории игр">';
    echo '<div class="ckm-history-summary-card"><span>Всего запусков</span><strong>'.(int)$summary['total'].'</strong></div>';
    echo '<div class="ckm-history-summary-card"><span>Завершено</span><strong>'.(int)$summary['finished'].'</strong></div>';
    echo '<div class="ckm-history-summary-card"><span>Идёт сейчас</span><strong>'.(int)$summary['live'].'</strong></div>';
    echo '<div class="ckm-history-summary-card"><span>Тестовых</span><strong>'.(int)$summary['tests'].'</strong></div>';
    echo '<div class="ckm-history-summary-card ckm-history-summary-format"><span>Чаще всего</span><strong>'.esc_html((string)$summary['popular_label']).'</strong><small>'.((int)$summary['popular_count']>0 ? (int)$summary['popular_count'].' '.((int)$summary['popular_count']===1?'игра':(((int)$summary['popular_count']>=2 && (int)$summary['popular_count']<=4)?'игры':'игр')) : 'реальных игр пока нет').'</small></div>';
    echo '</div>';

    ckm_quiz_pro_history_render_format_stats($games,$teamsByGame);
    ckm_quiz_pro_history_render_format_team_leaderboards($games,$teamsByGame);
    ckm_quiz_pro_history_render_team_stats($games,$teamsByGame);

    echo '<form class="ckm-history-filters" method="get">';
    echo '<input type="hidden" name="view" value="results">';
    foreach(['ckm_preview_user','ckm_preview_nonce'] as $keep) if(isset($_GET[$keep])) echo '<input type="hidden" name="'.esc_attr($keep).'" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET[$keep]))).'">';
    echo '<label class="ckm-label ckm-history-filter-search">Поиск<input class="ckm-input" type="search" name="history_q" value="'.esc_attr($search).'" placeholder="Название, код, команда"></label>';
    echo '<label class="ckm-label">Статус<select class="ckm-input" name="history_status"><option value="all" '.selected($statusFilter,'all',false).'>Все игры</option><option value="finished" '.selected($statusFilter,'finished',false).'>Завершённые</option><option value="unfinished" '.selected($statusFilter,'unfinished',false).'>Незавершённые</option><option value="test" '.selected($statusFilter,'test',false).'>Тестовые</option></select></label>';
    echo '<label class="ckm-label">Формат<select class="ckm-input" name="history_format"><option value="">Все форматы</option>';
    foreach($formatOptions as $label) echo '<option value="'.esc_attr($label).'" '.selected($formatFilter,$label,false).'>'.esc_html($label).'</option>';
    echo '</select></label>';
    echo '<label class="ckm-label">Дата с<input class="ckm-input" type="date" name="history_date_from" value="'.esc_attr($dateFrom).'" max="9999-12-31"></label>';
    echo '<label class="ckm-label">Дата по<input class="ckm-input" type="date" name="history_date_to" value="'.esc_attr($dateTo).'" max="9999-12-31"></label>';
    echo '<label class="ckm-label">Сортировка<select class="ckm-input" name="history_sort"><option value="newest" '.selected($sortOrder,'newest',false).'>Сначала новые</option><option value="oldest" '.selected($sortOrder,'oldest',false).'>Сначала старые</option><option value="title" '.selected($sortOrder,'title',false).'>По названию</option><option value="format" '.selected($sortOrder,'format',false).'>По формату</option></select></label>';
    echo '<div class="ckm-history-filter-actions"><button class="ckm-btn ckm-btn-primary" type="submit">Применить</button><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'results'])).'">Сбросить</a></div></form>';

    $exportArgs=array_merge(['view'=>'results','history_export'=>'list'],ckm_quiz_pro_history_filter_args(false));
    $exportUrl=wp_nonce_url(ckm_quiz_pro_organizer_url($exportArgs),'ckm_qp_history_export_list');
    $from=$total?($offset+1):0;$to=min($offset+$perPage,$total);
    $periodText='';
    if($dateFrom!=='' || $dateTo!==''){
        $prettyFrom=$dateFrom!=='' ? mysql2date('d.m.Y',$dateFrom,false) : 'начала истории';
        $prettyTo=$dateTo!=='' ? mysql2date('d.m.Y',$dateTo,false) : 'сегодня';
        $periodText=' Период: <strong>'.esc_html($prettyFrom).' — '.esc_html($prettyTo).'</strong>.';
    }
    $allHistoryCount=count($games)+count($salesAttempts);
    echo '<div class="ckm-history-listbar"><p class="ckm-muted">Показано: <strong>'.$from.'–'.$to.'</strong> из <strong>'.$total.'</strong> найденных записей. Всего в истории: '.$allHistoryCount.'.'.$periodText.'</p><a class="ckm-btn ckm-btn-small" href="'.esc_url($exportUrl).'">Экспорт выборки CSV</a></div>';

    $filterArgs=ckm_quiz_pro_history_filter_args();
    $bulkDeleteAllowed=ckm_quiz_pro_preview_organizer_user_id()===0;
    $bulkGameRows=array_values(array_filter($pageRows,static fn(array $row): bool=>(string)($row['_history_type']??'game')!=='sales'));
    $bulkColumn=$bulkDeleteAllowed && !empty($bulkGameRows);
    if($bulkColumn){
        echo '<form method="post" id="ckm-history-bulk-delete-form" class="ckm-history-bulkbar">';
        wp_nonce_field('ckm_qp_bulk_delete_history_games');
        echo '<input type="hidden" name="ckm_qp_bulk_delete_history_games" value="1"><input type="hidden" name="history_bulk_confirm" value="">';
        foreach($filterArgs as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
        echo '<div><strong>Выбрано: <span id="ckm-history-selected-count">0</span></strong><span class="ckm-muted"> Отметьте ненужные игры в таблице.</span></div>';
        echo '<button class="ckm-btn ckm-btn-small ckm-btn-danger" id="ckm-history-bulk-delete-button" type="submit" name="ckm_qp_bulk_delete_history_games" value="1" disabled>Удалить выбранные</button></form>';
    }

    echo '<div class="ckm-table-wrap"><table class="ckm-table"><thead><tr>';
    if($bulkColumn) echo '<th class="ckm-history-select-cell"><input type="checkbox" id="ckm-history-select-all" aria-label="Выбрать все игры на странице"></th>';
    echo '<th>Дата</th><th>Игра</th><th>Формат</th><th>Команды</th><th>Ведущий</th><th>Итог</th><th>Статус</th><th></th></tr></thead><tbody>';
    foreach($pageRows as $g){
        $isSales=(string)($g['_history_type']??'game')==='sales';
        if($isSales){
            $status=sanitize_key((string)($g['status']??''));
            $score=$g['final_score']!==null && $g['final_score']!=='' ? ((string)round((float)$g['final_score']).'/100') : '—';
            $openArgs=['sales_session'=>(int)$g['id'],'sales_format'=>'training'];
            if((int)($g['scenario_tenant_id']??0)>0)$openArgs['sales_custom_scenario']=(int)$g['scenario_id'];
            else $openArgs['sales_scenario']=(string)($g['scenario_slug']??'');
            $open=class_exists('\\CKM\\EffectiveSales\\SalesPage') ? \CKM\EffectiveSales\SalesPage::url($openArgs) : add_query_arg($openArgs,home_url('/ckm-sales-master/'));
            echo '<tr class="ckm-history-sales-row">';
            if($bulkColumn) echo '<td class="ckm-history-select-cell">—</td>';
            echo '<td>'.esc_html(ckm_quiz_pro_history_date($g)).'</td>';
            echo '<td><strong>'.esc_html((string)$g['title']).'</strong> <span class="ckm-badge">Тренировка</span><br><small><code>'.esc_html((string)$g['game_code']).'</code></small></td>';
            echo '<td>Эффективный продажник</td>';
            echo '<td>Индивидуально<br><small>'.esc_html((string)($g['participant_label']??'Участник')).'</small></td>';
            echo '<td>ИИ-клиент</td>';
            echo '<td>'.esc_html($score).'</td>';
            echo '<td><span class="ckm-history-status '.esc_attr(ckm_quiz_pro_history_status_class($status)).'">'.esc_html(ckm_quiz_pro_history_status_label($status)).'</span></td>';
            echo '<td><div class="ckm-history-actions"><a class="ckm-btn ckm-btn-small" href="'.esc_url($open).'">'.($status==='finished'?'Открыть результат':'Открыть тренировку').'</a></div></td></tr>';
            continue;
        }

        $teams=$teamsByGame[(int)$g['id']] ?? [];
        $teamNames=array_values(array_filter(array_map(static fn($t)=>trim((string)($t['team_name'] ?? '')),$teams)));
        $teamCount=count($teams);
        $teamText=$teamCount.' '.($teamCount===1?'команда':($teamCount>=2 && $teamCount<=4?'команды':'команд'));
        if($teamNames) $teamText.='<br><small>'.esc_html(implode(' · ',$teamNames)).'</small>';

        $scoreParts=[];
        foreach($teams as $t) $scoreParts[]=trim((string)($t['team_name'] ?? 'Команда')).': '.ckm_quiz_pro_history_team_score($t);
        $score=$scoreParts?implode(' · ',$scoreParts):'—';
        $open=ckm_quiz_pro_organizer_url(array_merge(['view'=>'results','history_game'=>(int)$g['id']],$filterArgs));
        $isTest=!empty($g['test_mode']);
        $canDelete=ckm_quiz_pro_history_can_delete($g) && ckm_quiz_pro_preview_organizer_user_id()===0;

        echo '<tr>';
        if($bulkColumn) echo '<td class="ckm-history-select-cell"><input class="ckm-history-row-check" type="checkbox" name="history_game_ids[]" value="'.(int)$g['id'].'" form="ckm-history-bulk-delete-form" aria-label="Выбрать '.esc_attr((string)($g['title'] ?: $g['game_code'])).'"></td>';
        echo '<td>'.esc_html(ckm_quiz_pro_history_date($g)).'</td>';
        echo '<td><strong>'.esc_html((string)($g['title'] ?: $g['game_code'])).'</strong>'.($isTest?' <span class="ckm-badge">Тест</span>':'').'<br><small><code>'.esc_html((string)$g['game_code']).'</code></small></td>';
        echo '<td>'.esc_html(ckm_quiz_pro_history_format_label($g)).'</td>';
        echo '<td>'.$teamText.'</td>';
        echo '<td>'.esc_html(ckm_quiz_pro_history_host_label($g)).'</td>';
        echo '<td>'.esc_html($score).'</td>';
        echo '<td><span class="ckm-history-status '.esc_attr(ckm_quiz_pro_history_status_class((string)$g['status'])).'">'.esc_html(ckm_quiz_pro_history_status_label((string)$g['status'])).'</span></td>';
        echo '<td><div class="ckm-history-actions"><a class="ckm-btn ckm-btn-small" href="'.esc_url($open).'">Открыть историю</a>';
        if($canDelete){
            echo '<form method="post" class="ckm-history-delete-form" data-game-code="'.esc_attr((string)$g['game_code']).'">';
            wp_nonce_field('ckm_qp_delete_history_game_'.(int)$g['id']);
            echo '<input type="hidden" name="ckm_qp_delete_history_game" value="1"><input type="hidden" name="history_game_id" value="'.(int)$g['id'].'"><input type="hidden" name="history_confirm_code" value="">';
            foreach($filterArgs as $k=>$v) echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'">';
            echo '<button class="ckm-btn ckm-btn-small ckm-btn-danger" type="submit">В корзину</button></form>';
        }
        echo '</div></td></tr>';
    }
    if(!$pageRows) echo '<tr><td colspan="'.($bulkColumn?9:8).'">По заданным фильтрам записей не найдено.</td></tr>';
    echo '</tbody></table></div>';

    if($total>$perPage){
        $baseArgs=ckm_quiz_pro_history_filter_args(false);
        echo '<nav class="ckm-history-pagination" aria-label="Страницы истории">';
        if($page>1) echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge(['view'=>'results','history_page'=>$page-1],$baseArgs))).'">← Предыдущая</a>';
        echo '<span>Страница <strong>'.$page.'</strong> из <strong>'.$pageCount.'</strong></span>';
        if($page<$pageCount) echo '<a class="ckm-btn ckm-btn-small" href="'.esc_url(ckm_quiz_pro_organizer_url(array_merge(['view'=>'results','history_page'=>$page+1],$baseArgs))).'">Следующая →</a>';
        echo '</nav>';
    }
    if($bulkDeleteAllowed) echo '<p class="ckm-muted ckm-history-delete-note"><strong>Удаление истории:</strong> отмеченные игры сначала перемещаются в корзину. Там их можно восстановить или удалить окончательно вместе с ответами, событиями и результатами.</p>';
    echo '<script>(function(){document.querySelectorAll(".ckm-history-delete-form").forEach(function(form){form.addEventListener("submit",function(e){var code=form.getAttribute("data-game-code")||"";var entered=window.prompt("Игра будет перемещена в корзину и её можно будет восстановить. Для подтверждения введите код игры: "+code);if(entered===null||entered!==code){e.preventDefault();return;}var input=form.querySelector("input[name=history_confirm_code]");if(input)input.value=entered;});});var bulk=document.getElementById("ckm-history-bulk-delete-form"),all=document.getElementById("ckm-history-select-all"),btn=document.getElementById("ckm-history-bulk-delete-button"),count=document.getElementById("ckm-history-selected-count");if(!bulk)return;var boxes=Array.prototype.slice.call(document.querySelectorAll(".ckm-history-row-check"));function sync(){var n=boxes.filter(function(x){return x.checked;}).length;if(count)count.textContent=String(n);if(btn)btn.disabled=n===0;if(all){all.checked=n>0&&n===boxes.length;all.indeterminate=n>0&&n<boxes.length;}}boxes.forEach(function(x){x.addEventListener("change",sync);});if(all)all.addEventListener("change",function(){boxes.forEach(function(x){x.checked=all.checked;});sync();});bulk.addEventListener("submit",function(e){var selected=boxes.filter(function(x){return x.checked;});if(!selected.length){e.preventDefault();return;}if(!window.confirm("Переместить выбранные игры в корзину: "+selected.length+"? Их можно будет восстановить.")){e.preventDefault();return;}bulk.querySelectorAll(".ckm-history-bulk-id-mirror").forEach(function(x){x.remove();});selected.forEach(function(box){var hidden=document.createElement("input");hidden.type="hidden";hidden.name="history_game_ids[]";hidden.value=box.value;hidden.className="ckm-history-bulk-id-mirror";bulk.appendChild(hidden);});var input=bulk.querySelector("input[name=history_bulk_confirm]");if(input)input.value="УДАЛИТЬ";});sync();})();</script>';
}

function ckm_quiz_pro_org_builder(): void {
    $isAdmin=current_user_can('manage_options');
    if (!$isAdmin && !ckm_quiz_pro_can_use_front_constructor()) {
        status_header(403);
        echo '<div class="ckm-card"><h1>Конструктор недоступен</h1><p>Нет действующего доступа к оплаченным играм. После любой активной оплаты вы сможете создать собственную игру с нуля. Сохранённые игры не удаляются.</p><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'games'])).'">Открыть базовые игры</a></div>';
        return;
    }
    global $wpdb;
    $id=absint($_GET['quiz']??0); $quiz=null; $questions=[];
    if($id && function_exists('ckm_quiz_pro_package_is_readonly_quiz') && ckm_quiz_pro_package_is_readonly_quiz($id)){
        echo '<div class="ckm-head"><div><div class="ckm-kicker">КОНСТРУКТОР</div><h1>Игра управляется платформой</h1><p class="ckm-muted">Купленные игровые пакеты обновляются централизованно и не редактируются вручную.</p></div></div>';
        return;
    }
    if($id){
        $quiz=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d',$id),ARRAY_A);
        if (!$quiz || (!$isAdmin && (!function_exists('ckm_quiz_pro_can_edit_owned_quiz') || !ckm_quiz_pro_can_edit_owned_quiz($quiz,ckm_quiz_pro_effective_organizer_user_id())))) { ckm_quiz_pro_format_denied(); return; }
        if($quiz) $questions=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".ckm_quiz_pro_table('questions')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active' ORDER BY position",(int)$quiz['id'],(int)$quiz['current_revision']),ARRAY_A) ?: [];
    }
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $supported=['classic_quiz'=>'Классический квиз','chgk'=>'Битва знатоков','jeopardy'=>'Интеллектуальный батл','solution_price'=>'Управленческая игра "Ваш выбор"','negotiation_duel'=>'Переговорный поединок','persuade_me_v1'=>'Переговори другого'];
    // Any active paid access unlocks the constructor as a platform feature.
    // The organizer may choose any supported format when creating a NEW game.
    $allowed=($isAdmin || ckm_quiz_pro_can_use_front_constructor()) ? $supported : [];
    if (!$allowed) { echo '<div class="ckm-card"><h1>Редактор этого формата пока недоступен</h1><p>Оплата активна, но в этой версии плагина ещё нет конструктора для оплаченного вами формата. Готовую игру можно запускать из раздела «Мои игры».</p></div>'; return; }
    $requestedChoice=isset($_GET['format']) && is_string($_GET['format'])?sanitize_key($_GET['format']):array_key_first($allowed);
    $choiceAliases=['chgk_v1'=>'chgk','jeopardy_v1'=>'jeopardy','decision_price_v1'=>'solution_price','negotiation_duel_v1'=>'negotiation_duel'];
    $requestedChoice=$choiceAliases[$requestedChoice]??$requestedChoice;
    if (!$quiz && !isset($allowed[$requestedChoice])) { ckm_quiz_pro_format_denied(); return; }
    $requested=$requestedChoice==='persuade_me_v1'?'negotiation_duel':$requestedChoice;
    $requestedMode=$requestedChoice==='persuade_me_v1'?'communicate':'';
    $educationMode=sanitize_key((string)($_GET['education_mode']??''));
    if(!in_array($educationMode,['classic','battle','learning'],true)) $educationMode='';
    $educationSubjects=function_exists('ckm_quiz_pro_education_subject_registry')?ckm_quiz_pro_education_subject_registry():[];
    $educationSubject=sanitize_key((string)($_GET['edu_subject']??''));
    if($educationSubject!=='' && !isset($educationSubjects[$educationSubject])) $educationSubject='';
    $educationGrade=max(0,(int)($_GET['edu_grade']??0));
    $educationTheme=sanitize_text_field(wp_unslash((string)($_GET['edu_theme']??'')));
    $educationSeed=[];
    if($educationMode!==''){
        $educationSeed=['educationMode'=>$educationMode,'educationSubject'=>$educationSubject,'educationGrade'=>$educationGrade,'educationTheme'=>$educationTheme];
    }

    if(!$quiz){
        $seedSettings=$requestedMode!==''?['negotiationMode'=>$requestedMode]:[];
        if($educationSeed) $seedSettings=array_merge($seedSettings,$educationSeed);
        $seedTitle='';
        if($educationMode!=='' && $educationSubject!=='' && isset($educationSubjects[$educationSubject])){
            $parts=[(string)$educationSubjects[$educationSubject]['title']];
            if($educationGrade>0) $parts[]=$educationGrade.' класс';
            if($educationTheme!=='') $parts[]=$educationTheme;
            $modeTitles=['classic'=>'Классический квиз','battle'=>'Интеллектуальный батл','learning'=>'Обучающий формат'];
            $parts[]=$modeTitles[$educationMode]??'Образовательная игра';
            $seedTitle=implode(' · ',$parts);
        }
        $quiz=['id'=>0,'title'=>$seedTitle,'slug'=>'','status'=>'published','seconds_per_question'=>$requested==='chgk'?60:($requested==='jeopardy'?90:($requested==='negotiation_duel'?($requestedMode==='communicate'?30:120):($requested==='solution_price'?60:30))),'format_key'=>$requested,'host_mode'=>'ai','format_settings_json'=>$seedSettings?wp_json_encode($seedSettings,JSON_UNESCAPED_UNICODE):''];
    }
    $formatKey=sanitize_key((string)($quiz['format_key']??'classic_quiz'));
    $existingMode=$formatKey==='negotiation_duel' ? ckm_quiz_pro_quiz_negotiation_mode($quiz) : '';
    $builderChoice=($formatKey==='negotiation_duel' && $existingMode==='communicate')?'persuade_me_v1':($quiz['id']?$formatKey:$requestedChoice);
    $formatTitle=function_exists('ckm_quiz_pro_quiz_format_title')?ckm_quiz_pro_quiz_format_title($quiz):ckm_quiz_pro_format_title($formatKey);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">КОНСТРУКТОР</div><h1>'.($id?'Редактировать игру':'Создать свою игру').'</h1><p class="ckm-muted">Выберите формат и подготовьте игру с нуля. Собственную сохранённую игру можно снова открыть из раздела «Мои игры», изменить и сохранить новой редакцией.</p></div></div>';
    if(isset($_GET['saved'])) echo '<div class="ckm-alert ckm-alert-ok">Игра сохранена новой редакцией.</div>';
    echo '<div class="ckm-card"><form method="post">'; wp_nonce_field('ckm_quiz_pro_save_quiz');
    $educationSettings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
    $educationModeCurrent=sanitize_key((string)($educationSettings['educationMode']??$educationMode));
    if(!in_array($educationModeCurrent,['classic','battle','learning'],true)) $educationModeCurrent='';
    $educationSubjectCurrent=sanitize_key((string)($educationSettings['educationSubject']??$educationSubject));
    $educationGradeCurrent=(int)($educationSettings['educationGrade']??$educationGrade);
    $educationThemeCurrent=sanitize_text_field((string)($educationSettings['educationTheme']??$educationTheme));
    if($educationModeCurrent!==''){
        echo '<input type="hidden" name="education_mode" value="'.esc_attr($educationModeCurrent).'">';
        echo '<input type="hidden" name="education_subject" value="'.esc_attr($educationSubjectCurrent).'">';
        echo '<input type="hidden" name="education_grade" value="'.(int)$educationGradeCurrent.'">';
        echo '<input type="hidden" name="education_theme" value="'.esc_attr($educationThemeCurrent).'">';
        $modeNames=['classic'=>'Классический квиз','battle'=>'Интеллектуальный батл','learning'=>'Обучающий формат'];
        $subjectName=isset($educationSubjects[$educationSubjectCurrent])?(string)$educationSubjects[$educationSubjectCurrent]['title']:'Школьный предмет';
        echo '<div class="ckm-alert"><strong>Образовательная игра:</strong> '.esc_html($subjectName).' · '.($educationGradeCurrent>0?(int)$educationGradeCurrent.' класс · ':'').esc_html($educationThemeCurrent).' · <strong>'.esc_html($modeNames[$educationModeCurrent]??'').'</strong>.';
        if($educationModeCurrent==='learning') echo ' Стройте материал блоками: базовый вопрос → пояснение после ответа → следующий вопрос на применение.';
        echo '</div>';
    }

    echo '<input type="hidden" name="quiz_id" value="'.(int)$quiz['id'].'">';
    if($id) echo '<input type="hidden" name="format_key" value="'.esc_attr($formatKey).'"><div class="ckm-badge">'.esc_html($formatTitle).'</div>';
    else {
        echo '<div class="ckm-form-grid"><label class="ckm-label">Формат<input type="hidden" name="format_key" value="'.esc_attr($formatKey).'"><select class="ckm-input" id="ckm-front-format">';
        foreach ($allowed as $key=>$title) echo '<option value="'.esc_attr($key).'" '.selected($builderChoice,$key,false).'>'.esc_html($title).'</option>';
        echo '</select></label><div class="ckm-label">&nbsp;<a class="ckm-btn" id="ckm-front-format-refresh" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'builder','format'=>$builderChoice])).'">Применить формат</a></div></div>';
    }
    $timeLabel=$formatKey==='solution_price'?'Время на этап':($formatKey==='negotiation_duel'?($existingMode==='communicate'?'Базовое время диалога':'Время на реплику'):'Время на вопрос'); $min=$formatKey==='jeopardy'?15:(in_array($formatKey,['negotiation_duel','solution_price'],true)?30:5); $max=600;
    echo '<div class="ckm-form-grid"><label class="ckm-label">Название<input class="ckm-input" name="title" value="'.esc_attr($quiz['title']).'" required></label><label class="ckm-label">Slug<input class="ckm-input" name="slug" value="'.esc_attr($quiz['slug']).'"></label><label class="ckm-label">Статус<select class="ckm-input" name="status"><option value="published" '.selected($quiz['status'],'published',false).'>Опубликован</option><option value="draft" '.selected($quiz['status'],'draft',false).'>Черновик</option></select></label>';
    if($formatKey==='chgk'){
        $timers=ckm_quiz_pro_chgk_timer_values($quiz);
        echo '<label class="ckm-label">Время обсуждения'.ckm_quiz_pro_chgk_timer_control('chgk_discussion_preset','chgk_discussion_custom',(int)$timers['discussion'],[60,90,120,180,300],60,300,'ckm-input').'<span class="ckm-muted">До завершения этого времени поле окончательного ответа скрыто.</span></label>';
        echo '<label class="ckm-label">Время на окончательный ответ<input type="hidden" name="chgk_final_preset" value="20"><strong>20 секунд</strong><span class="ckm-muted">Короткое окно фиксации ответа после обсуждения или досрочного ответа.</span></label>';
    } elseif($formatKey==='negotiation_duel' && $existingMode==='communicate') {
        $persuadeSettings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
        $dialogueLimitEnabled=!empty($persuadeSettings['persuadeDialogueLimitEnabled']);
        $dialogueLimitSeconds=max(60,min(600,(int)($persuadeSettings['persuadeDialogueLimitSeconds']??180)));
        $hiddenMessageLimit=max(3,min(7,(int)($persuadeSettings['persuadeHiddenMessageLimit']??7)));
        $storyLimitEnabled=!empty($persuadeSettings['persuadeStoryLimitEnabled']);
        $storyLimitSeconds=max(60,min(120,(int)($persuadeSettings['persuadeStoryLimitSeconds']??90)));
        $storyAnswerLimitEnabled=!empty($persuadeSettings['persuadeStoryAnswerLimitEnabled']);
        $storyAnswerSeconds=(int)($persuadeSettings['persuadeStoryAnswerSeconds']??30);if(!in_array($storyAnswerSeconds,[20,30,60],true))$storyAnswerSeconds=30;
        echo '<input type="hidden" name="seconds_per_question" value="30">';
        echo '<label class="ckm-label">Диалог<label class="ckm-check" style="margin-top:8px"><input type="checkbox" name="persuade_dialogue_limit_enabled" value="1" '.checked($dialogueLimitEnabled,true,false).'> Ограничивать время переговоров</label><span class="ckm-muted">По умолчанию выключено: после стартовой готовности диалог открыт сразу; ведущий завершает его вручную, а ИИ-ведущий — по естественному завершению разговора.</span></label>';
        echo '<label class="ckm-label">Лимит диалога, секунд<input class="ckm-input" type="number" min="60" max="600" name="persuade_dialogue_limit_seconds" value="'.(int)$dialogueLimitSeconds.'"><span class="ckm-muted">Используется только если включено ограничение времени переговоров.</span></label>';
        echo '<label class="ckm-label">Финальный рассказ<label class="ckm-check" style="margin-top:8px"><input type="checkbox" name="persuade_story_limit_enabled" value="1" '.checked($storyLimitEnabled,true,false).'> Ограничивать время рассказа</label><span class="ckm-muted">По умолчанию ограничение времени для подготовки и рассказа в «Проверь историю» выключено: рассказчик сам нажимает «Готов рассказать», затем «Зафиксировать рассказ».</span></label>';
        echo '<label class="ckm-label">Лимит рассказа, секунд<input class="ckm-input" type="number" min="60" max="120" step="30" name="persuade_story_limit_seconds" value="'.(int)$storyLimitSeconds.'"><span class="ckm-muted">Используется только если ограничение рассказа включено. Рекомендуемые значения: 60, 90 или 120.</span></label>';
        echo '<label class="ckm-label">Ответ рассказчика<label class="ckm-check" style="margin-top:8px"><input type="checkbox" name="persuade_story_answer_limit_enabled" value="1" '.checked($storyAnswerLimitEnabled,true,false).'> Ограничивать время ответа</label><span class="ckm-muted">По умолчанию ограничение времени для ответов рассказчика выключено.</span><select class="ckm-input" name="persuade_story_answer_seconds"><option value="20" '.selected($storyAnswerSeconds,20,false).'>20 секунд</option><option value="30" '.selected($storyAnswerSeconds,30,false).'>30 секунд</option><option value="60" '.selected($storyAnswerSeconds,60,false).'>60 секунд</option></select><span class="ckm-muted">Используется только при включённом ограничении. 20–30 секунд — для голосового режима; 60 секунд — для текстового режима.</span></label>';
        echo '<label class="ckm-label">Максимум реплик<input class="ckm-input" type="number" min="3" max="7" name="persuade_hidden_message_limit" value="'.(int)$hiddenMessageLimit.'"><span class="ckm-muted">Раунд «Скрытая задача»: по умолчанию 7 реплик на испытание. Активная команда — до 3 реплик, собеседник — до 2. После достижения общего лимита начинается оценка.</span></label>';
    } else {
        echo '<label class="ckm-label">'.esc_html($timeLabel).'<input class="ckm-input" type="number" min="'.$min.'" max="'.$max.'" name="seconds_per_question" value="'.(int)$quiz['seconds_per_question'].'"></label>';
    }
    echo '</div><div class="ckm-card" style="margin-top:14px"><div class="ckm-q-title">Ведущий</div><label class="ckm-check"><input type="radio" name="host_mode" value="ai" '.checked((string)($quiz['host_mode']??'ai'),'ai',false).'> ИИ-ведущий</label><label class="ckm-check"><input type="radio" name="host_mode" value="human" '.checked((string)($quiz['host_mode']??'ai'),'human',false).'> Ведущий (с микрофоном/без микрофона)</label><p class="ckm-muted">В режиме ИИ-ведущего голос Сергея подключается автоматически, а первый вопрос задаётся без кнопки «Задать вопрос». В режиме голосового ведущего человек подключает микрофон, говорит командам и управляет ходом игры из панели.</p></div>';
    if($formatKey==='negotiation_duel'){
        $negSettings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
        $negMode=ckm_quiz_pro_negotiation_mode((string)($negSettings['negotiationMode']??($requestedMode?:($_GET['mode']??'sales'))));
        if($negMode==='communicate'){
            echo '<div class="ckm-card"><div class="ckm-q-title">Формат</div><input type="hidden" name="negotiation_mode" value="communicate"><strong>Переговори другого</strong><p class="ckm-muted">Две команды и четыре раунда: «Удержи цель», «Скрытая задача», «Неудобный вопрос», «Проверь историю». ИИ-ведущий/ведущий-человек выбирается выше; максимальный итог — 280 баллов.</p></div>';
            echo '<div class="ckm-alert"><strong>Переговори другого:</strong> это отдельная игровая механика внутри общего переговорного движка. Созданная игра сохраняется как самостоятельный шаблон с режимом <code>communicate</code> и запускается строго на две команды.</div>';
        } else {
            echo '<div class="ckm-card"><div class="ckm-q-title">Режим переговоров</div><label class="ckm-label">Режим<select class="ckm-input" name="negotiation_mode"><option value="sales" '.selected($negMode,'sales',false).'>Эффективный продажник</option><option value="business" '.selected($negMode,'business',false).'>Мастер переговоров</option><option value="express" '.selected($negMode,'express',false).'>Экспресс-раунд</option></select></label><p class="ckm-muted">Один движок, три режима переговорного поединка. «Переговори другого» выбирается отдельным пунктом в списке форматов конструктора.</p></div>';
            echo '<div class="ckm-alert"><strong>Переговорный поединок:</strong> участник получает конкретную реплику или ситуацию и отвечает следующей рабочей репликой. ИИ/арбитр оценивает именно переговорный ход: вопросы, инициативу, ценность, уступки, работу с возражениями и следующий шаг.</div>';
        }
    }
    if($formatKey==='chgk') echo '<div class="ckm-alert"><strong>Битва знатоков — не квиз:</strong> играет одна команда Знатоков против Игры. Нужно минимум 11 обычных вопросов. Каждый вопрос стоит ровно 1 очко: правильный ответ — Знатокам, неправильный или пропущенный — Игре. Матч заканчивается сразу, когда одна сторона набрала 6. Сначала идёт обязательное обсуждение, затем открывается одно поле окончательного ответа.</div>';
    if($formatKey==='jeopardy'){
        echo '<div class="ckm-alert"><strong>Интеллектуальный батл:</strong> команда с правом выбора открывает ячейку, остальные борются за ответ кнопкой «ОТВЕЧАЕМ!». Неверный ответ не штрафуется. Спецячейка «Секретная передача» передаёт вопрос другой команде.</div>';
        ckm_quiz_pro_render_jeopardy_org_editor($quiz,$questions);
        echo '<div class="ckm-card-actions"><button class="ckm-btn ckm-btn-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></div></form></div>';
        echo '<script>(function(){const format=document.getElementById("ckm-front-format"),refresh=document.getElementById("ckm-front-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{const u=new URL(refresh.href);u.searchParams.set("format",format.value);refresh.href=u.toString();});}})();</script>';
        return;
    }
    if($formatKey==='solution_price') {
        ckm_quiz_pro_solution_price_builder_fields($questions);
        echo '</form></div>';
        return;
    }
    if($formatKey==='negotiation_duel' && isset($negMode) && $negMode==='communicate') {
        $persuadeContent=ckm_quiz_pro_persuade_me_content_from_quiz($quiz);
        ckm_quiz_pro_persuade_me_front_editor($persuadeContent);
        echo '<div class="ckm-alert"><strong>Что фиксировано:</strong> 2 команды, порядок четырёх раундов, таймеры и шкалы оценки. Максимум — 280 баллов. Секретные задания и досье попадают только в закрытые состояния соответствующих команд.</div>';
        echo '<div class="ckm-card-actions"><button class="ckm-btn ckm-btn-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></div></form></div>';
        echo '<script>(function(){const format=document.getElementById("ckm-front-format"),refresh=document.getElementById("ckm-front-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{const u=new URL(refresh.href);u.searchParams.set("format",format.value);refresh.href=u.toString();});}})();</script>';
        return;
    }
    echo '<h2>Вопросы</h2><div id="ckm-front-questions">';
    $i=0;
    foreach($questions as $q){
        if($formatKey==='chgk'){
            $answers=json_decode($q['correct_answers_json'],true)?:[]; $reference=(string)($answers[0]??''); $variants=implode("
",array_slice($answers,1));
            ckm_quiz_pro_org_chgk_question_editor($i++,(string)$q['question_text'],$reference,$variants,(int)$q['points'],(string)$q['explanation']);
        } elseif($formatKey==='negotiation_duel'){
            $rule=json_decode((string)($q['scoring_rule_json']??''),true) ?: [];
            $criteria=implode("\n",array_values((array)($rule['judgeCriteria']??[])));
            ckm_quiz_pro_org_negotiation_editor($i++,(string)$q['question_text'],$criteria,(int)$q['points'],(string)$q['explanation']);
        } else {
            $opts=json_decode($q['options_json'],true)?:[]; $map=[]; foreach($opts as $o) if(is_array($o)) $map[(string)($o['value']??'')]=(string)($o['label']??'');
            $correct=(json_decode($q['correct_answers_json'],true)?:['A'])[0]??'A';
            ckm_quiz_pro_org_question_editor($i++,$q['question_text'],$map,$correct,(int)$q['points'],(int)$q['time_limit_seconds'],(string)$q['explanation']);
        }
    }
    if($i===0){ if($formatKey==='chgk') ckm_quiz_pro_org_chgk_question_editor(0,'','','',1,''); elseif($formatKey==='negotiation_duel') ckm_quiz_pro_org_negotiation_editor(0,'','',20,''); else ckm_quiz_pro_org_question_editor(0,'',[],'A',1,(int)$quiz['seconds_per_question'],''); }
    echo '</div><div class="ckm-card-actions"><button type="button" class="ckm-btn" id="ckm-front-add-q">Добавить вопрос</button><button class="ckm-btn ckm-btn-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></div></form></div>';
    $isChgk=$formatKey==='chgk';
    $isNegotiation=$formatKey==='negotiation_duel';
    $classicTpl='<div class="ckm-q-title">Вопрос ${i+1}</div><label class="ckm-label">Текст<textarea class="ckm-input" name="questions[${i}][text]" rows="3"></textarea></label>${["A","B","C","D"].map(l=>`<label class="ckm-label">${l}<input class="ckm-input" name="questions[${i}][option_${l}]"></label>`).join("")}<div class="ckm-form-grid"><label class="ckm-label">Правильный<select class="ckm-input" name="questions[${i}][correct]"><option>A</option><option>B</option><option>C</option><option>D</option></select></label><label class="ckm-label">Баллы<input class="ckm-input" type="number" name="questions[${i}][points]" value="1" min="1" max="100"></label><label class="ckm-label">Время<input class="ckm-input" type="number" name="questions[${i}][time]" value="30" min="5" max="600"></label></div><label class="ckm-label">Пояснение<textarea class="ckm-input" name="questions[${i}][explanation]" rows="2"></textarea></label>';
    $chgkTpl='<div class="ckm-q-title">Вопрос ${i+1} · 1 очко</div><label class="ckm-label">Текст вопроса<textarea class="ckm-input" name="questions[${i}][text]" rows="3"></textarea></label><label class="ckm-label">Эталонный ответ<input class="ckm-input" name="questions[${i}][reference]"></label><label class="ckm-label">Допустимые варианты ответа<textarea class="ckm-input" name="questions[${i}][variants]" rows="2" placeholder="По одному варианту в строке"></textarea></label><div class="ckm-muted"><strong>1 очко за вопрос.</strong> Принято — Знатокам; не принято/нет ответа — Игре.</div><label class="ckm-label">Комментарий после ответа<textarea class="ckm-input" name="questions[${i}][explanation]" rows="2"></textarea></label>';
    $negotiationTpl='<div class="ckm-q-title">Реплика ${i+1}</div><label class="ckm-label">Ситуация / реплика оппонента / задача<textarea class="ckm-input" name="questions[${i}][text]" rows="5"></textarea></label><label class="ckm-label">Критерии оценки<textarea class="ckm-input" name="questions[${i}][criteria]" rows="2" placeholder="По одному критерию в строке"></textarea></label><label class="ckm-label">Баллы<input class="ckm-input" type="number" name="questions[${i}][points]" value="20" min="1" max="100"></label><label class="ckm-label">Методический ориентир<textarea class="ckm-input" name="questions[${i}][explanation]" rows="2"></textarea></label>';
    $dynamicTpl=$isChgk?$chgkTpl:($isNegotiation?$negotiationTpl:$classicTpl);
    echo '<script>(function(){const format=document.getElementById("ckm-front-format"),refresh=document.getElementById("ckm-front-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{const u=new URL(refresh.href);u.searchParams.set("format",format.value);refresh.href=u.toString();});}const box=document.getElementById("ckm-front-questions"),add=document.getElementById("ckm-front-add-q"),tpl='.wp_json_encode($dynamicTpl).';add.addEventListener("click",function(){const i=box.querySelectorAll(".ckm-q-card").length;const d=document.createElement("div");d.className="ckm-q-card";d.innerHTML=tpl.replaceAll("${i}",String(i)).replaceAll("${i+1}",String(i+1));box.appendChild(d);});})();</script>';
}

function ckm_quiz_pro_org_question_editor(int $i,string $text,array $map,string $correct,int $points,int $time,string $explanation): void {
    echo '<div class="ckm-q-card"><div class="ckm-q-title">Вопрос '.($i+1).'</div><label class="ckm-label">Текст<textarea class="ckm-input" name="questions['.$i.'][text]" rows="3">'.esc_textarea($text).'</textarea></label>';
    foreach(['A','B','C','D'] as $l) echo '<label class="ckm-label">'.$l.'<input class="ckm-input" name="questions['.$i.'][option_'.$l.']" value="'.esc_attr($map[$l]??'').'"></label>';
    echo '<div class="ckm-form-grid"><label class="ckm-label">Правильный<select class="ckm-input" name="questions['.$i.'][correct]">'; foreach(['A','B','C','D'] as $l) echo '<option value="'.$l.'" '.selected($correct,$l,false).'>'.$l.'</option>'; echo '</select></label><label class="ckm-label">Баллы<input class="ckm-input" type="number" name="questions['.$i.'][points]" value="'.$points.'" min="1" max="100"></label><label class="ckm-label">Время<input class="ckm-input" type="number" name="questions['.$i.'][time]" value="'.$time.'" min="5" max="600"></label></div><label class="ckm-label">Пояснение<textarea class="ckm-input" name="questions['.$i.'][explanation]" rows="2">'.esc_textarea($explanation).'</textarea></label></div>';
}


function ckm_quiz_pro_managed_frontdoor(): void {
    if (is_admin()) return;
    $gamesId=(int)get_option('ckm_quiz_pro_page_games',0);
    if ($gamesId>0 && is_page($gamesId)) { ckm_quiz_pro_render_managed_games_page(); exit; }
    if (!is_front_page()) return;
    if (ckmqp_scope_id() > 0) { ckm_quiz_pro_render_tenant_payment_frontdoor(); exit; }
    if (ckm_quiz_pro_is_organizer_only_user()) { wp_safe_redirect(ckm_quiz_pro_organizer_url()); exit; }
    ckm_quiz_pro_render_managed_frontdoor(); exit;
}
add_action('template_redirect', 'ckm_quiz_pro_managed_frontdoor', 30);

function ckm_quiz_pro_render_managed_games_page(): void {
    status_header(200); nocache_headers();
    ckm_quiz_pro_org_shell_start('Игры ЦКМ');
    echo '<main class="ckm-main" style="max-width:1280px;margin:0 auto">';
    ckm_quiz_pro_render_architecture_nav(true);
    ckm_quiz_pro_render_games_catalog(is_user_logged_in() && ckm_quiz_pro_can_organize());
    echo '</main>';
    ckm_quiz_pro_org_shell_end();
}

function ckm_quiz_pro_render_tenant_payment_frontdoor(): void {
    $tenantId = ckmqp_scope_id();
    $site = $tenantId > 0 && function_exists('ckmqp_tenant_site_row') ? ckmqp_tenant_site_row($tenantId) : null;
    $siteName = is_array($site) && !empty($site['name']) ? (string)$site['name'] : ckmqp_tenant_hostname((string)($_SERVER['HTTP_HOST'] ?? ''));

    status_header(200);
    nocache_headers();
    ckm_quiz_pro_org_shell_start('Игры площадки');
    echo '<main class="ckm-main" style="max-width:1280px;margin:0 auto">';
    ckm_quiz_pro_render_architecture_nav(true);
    echo '<div class="ckm-head"><div><div class="ckm-kicker">'.esc_html(ckm_quiz_pro_ui_text('tenant_kicker_prefix').' '.strtoupper($siteName)).'</div><h1>'.esc_html(ckm_quiz_pro_ui_text('tenant_title')).'</h1><p class="ckm-muted">'.esc_html(ckm_quiz_pro_ui_text('tenant_lead')).'</p></div></div>';
    ckm_quiz_pro_render_games_catalog(is_user_logged_in() && ckm_quiz_pro_can_organize());
    echo '</main>';
    ckm_quiz_pro_org_shell_end();
}

function ckm_quiz_pro_render_managed_frontdoor(): void {
    status_header(200); nocache_headers();
    $organizer=is_user_logged_in() && ckm_quiz_pro_can_organize();
    $links=ckm_quiz_pro_architecture_links();
    $accountUrl=$organizer?ckm_quiz_pro_organizer_url():ckm_quiz_pro_login_url();
    $accountLabel=$organizer?ckm_quiz_pro_ui_text('home_primary_logged_in'):ckm_quiz_pro_ui_text('home_primary_logged_out');
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html(ckm_quiz_pro_ui_text('home_title')); ?></title><style>
    @import url("https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&family=Ubuntu:ital,wght@0,400;0,500;0,700;1,400&display=swap");
    :root{font-family:"Ubuntu",Arial,sans-serif;color-scheme:dark;--bg:#07101d;--panel:#0c1829;--line:#243b5c;--text:#eef5ff;--muted:#9eb0cb;--accent:#dceaff}*{box-sizing:border-box}h1,h2,h3,h4,h5,h6,.ckm-brand{font-family:"Caveat","Bad Script",cursive}body{margin:0;background:var(--bg);color:var(--text);min-height:100vh}.ckm-front{width:min(1180px,100%);margin:0 auto;padding:18px 24px 48px}.ckm-top{display:flex;align-items:center;gap:18px;min-height:64px;border-bottom:1px solid var(--line)}.ckm-brand{font-size:30px;font-weight:900;text-decoration:none;color:var(--text)}.ckm-top nav{display:flex;gap:8px;flex:1;flex-wrap:wrap}.ckm-top nav a{padding:8px 11px;border-radius:9px;text-decoration:none;color:var(--muted)}.ckm-top nav a:hover{background:var(--panel);color:var(--text)}.ckm-account{padding:9px 13px;border:1px solid #355273;border-radius:10px;text-decoration:none;color:var(--text)}.ckm-hero{padding:64px 0 26px}.ckm-kicker{font-size:12px;letter-spacing:.15em;color:var(--muted);font-weight:800}.ckm-hero h1{font-size:clamp(42px,7vw,76px);line-height:1.01;margin:10px 0 20px;max-width:1050px}.ckm-lead{font-size:clamp(17px,2.2vw,22px);line-height:1.55;color:#c6d3e8;max-width:880px}.ckm-paths{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:34px}.ckm-path{display:flex;flex-direction:column;background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:23px;min-height:250px}.ckm-path h2{font-size:30px;margin:10px 0}.ckm-path p{color:var(--muted);line-height:1.55;margin:0 0 18px}.ckm-path .ckm-btn{margin-top:auto}.ckm-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:10px 15px;border:1px solid #355273;border-radius:10px;background:#12243d;text-decoration:none;color:#fff}.ckm-primary{background:var(--accent);border-color:var(--accent);color:#07101d;font-weight:800}.ckm-foot{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:26px;color:var(--muted);font-size:13px}.ckm-foot a{color:#cddaf0}@media(max-width:840px){.ckm-paths{grid-template-columns:1fr}.ckm-top{align-items:flex-start;flex-wrap:wrap;padding:10px 0}.ckm-top nav{order:3;width:100%}.ckm-account{margin-left:auto}.ckm-hero{padding-top:42px}}
    </style></head><body><main class="ckm-front"><header class="ckm-top"><a class="ckm-brand" href="<?php echo esc_url(home_url('/')); ?>">ЦКМ</a><nav><a href="<?php echo esc_url($links['business']['url']); ?>">Деловые игры</a><a href="<?php echo esc_url($links['intellectual']['url']); ?>">Интеллектуальные игры</a><a href="<?php echo esc_url($links['custom']['url']); ?>">Создать свою игру</a></nav><a class="ckm-account" href="<?php echo esc_url($accountUrl); ?>"><?php echo esc_html($accountLabel); ?></a></header>
    <section class="ckm-hero"><div class="ckm-kicker"><?php echo esc_html(ckm_quiz_pro_ui_text('home_kicker')); ?></div><h1><?php echo esc_html(ckm_quiz_pro_ui_text('home_title')); ?></h1><p class="ckm-lead"><?php echo esc_html(ckm_quiz_pro_ui_text('home_lead')); ?></p><div class="ckm-paths">
    <article class="ckm-path"><div class="ckm-kicker">ДЛЯ БИЗНЕСА И РАЗВИТИЯ</div><h2>Деловые игры</h2><p>Четыре игры для развития переговорных навыков и «Управленческая игра "Ваш выбор"» для развития навыка принятия управленческих решений. Для развития и оценки навыков на реальных бизнес-ситуациях.</p><a class="ckm-btn ckm-primary" href="<?php echo esc_url($links['business']['url']); ?>">Смотреть деловые игры</a></article>
    <article class="ckm-path"><div class="ckm-kicker">ДЛЯ ТУРНИРОВ И КОРПОРАТИВОВ</div><h2>Интеллектуальные игры</h2><p>Классический квиз, Битва знатоков и Интеллектуальный батл. Для командных соревнований, клубов и интеллектуального развлечения.</p><a class="ckm-btn ckm-primary" href="<?php echo esc_url($links['intellectual']['url']); ?>">Смотреть интеллектуальные игры</a></article>
    <article class="ckm-path"><div class="ckm-kicker">СВОЙ СЦЕНАРИЙ</div><h2>Создать свою игру</h2><p>Используйте собственные вопросы, кейсы, роли, изображения, видео и правила — на том же игровом движке.</p><a class="ckm-btn ckm-primary" href="<?php echo esc_url($links['custom']['url']); ?>">Создать свою игру</a></article></div></section>
    <footer class="ckm-foot"><span>Одна платформа · три понятных пути</span><?php if(ckm_quiz_pro_ui_text('home_secondary_url')!==''): ?><a href="<?php echo esc_url(ckm_quiz_pro_ui_text('home_secondary_url')); ?>" rel="noopener"><?php echo esc_html(ckm_quiz_pro_ui_text('home_secondary_label')); ?></a><?php endif; ?></footer></main></body></html><?php
}

function ckm_quiz_pro_org_chgk_question_editor(int $i,string $text,string $reference,string $variants,int $points,string $explanation): void {
    echo '<div class="ckm-q-card"><div class="ckm-q-title">Вопрос '.($i+1).' · 1 очко</div><label class="ckm-label">Текст вопроса<textarea class="ckm-input" name="questions['.$i.'][text]" rows="3">'.esc_textarea($text).'</textarea></label><label class="ckm-label">Эталонный ответ<input class="ckm-input" name="questions['.$i.'][reference]" value="'.esc_attr($reference).'"></label><label class="ckm-label">Допустимые варианты ответа<textarea class="ckm-input" name="questions['.$i.'][variants]" rows="2" placeholder="По одному варианту в строке">'.esc_textarea($variants).'</textarea></label><div class="ckm-muted"><strong>1 очко за вопрос.</strong> Принято — очко Знатокам; не принято или нет ответа — очко Игре.</div><label class="ckm-label">Комментарий после ответа<textarea class="ckm-input" name="questions['.$i.'][explanation]" rows="2">'.esc_textarea($explanation).'</textarea></label></div>';
}

function ckm_quiz_pro_org_shell_start(string $title,bool $cabinet=false,string $view=''): void {
    status_header(200);
    nocache_headers();
    ?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html($title); ?></title><style>
    @import url("https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&family=Ubuntu:ital,wght@0,400;0,500;0,700;1,400&display=swap");
    :root{font-family:"Ubuntu",Arial,sans-serif;color-scheme:dark;--bg:#07101d;--panel:#0c1829;--card:#10213a;--line:#243b5c;--text:#eef5ff;--muted:#9eb0cb;--accent:#7cb4ff;--accent2:#dbeaff;--danger:#ffabab;--ok:#8ee7ae}*{box-sizing:border-box}h1,h2,h3,h4,h5,h6,.ckm-logo,.brand{font-family:"Caveat","Bad Script",cursive}body{margin:0;background:var(--bg);color:var(--text);min-height:100vh}a{color:var(--accent)}button,input,select,textarea{font:inherit}.ckm-layout{display:grid;grid-template-columns:240px minmax(0,1fr);min-height:100vh}.ckm-sidebar{background:#091525;border-right:1px solid var(--line);padding:24px 18px;display:flex;flex-direction:column;gap:18px}.ckm-logo{font-size:28px;font-weight:900;letter-spacing:.08em}.ckm-org-name{font-size:13px;color:var(--muted);padding-bottom:12px;border-bottom:1px solid var(--line)}.ckm-sidebar nav{display:grid;gap:7px}.ckm-sidebar nav a{font-family:"Caveat","Bad Script",cursive;font-size:20px}.ckm-nav{display:block;text-decoration:none;color:#cddaf0;padding:11px 12px;border-radius:10px}.ckm-nav-sub{padding:7px 12px 7px 26px;font-size:17px!important;color:var(--muted)}.ckm-nav-section{margin:12px 12px 2px;font-size:10px;letter-spacing:.14em;color:#647792;font-weight:800}.ckm-direction-grid{grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:22px}.ckm-direction-card{display:flex;flex-direction:column;min-height:230px}.ckm-direction-card .ckm-card-actions{margin-top:auto}.ckm-access-summary-head{display:flex;align-items:center;justify-content:space-between;gap:18px}.ckm-access-notices{margin-top:12px}.ckm-nav.active,.ckm-nav:hover{background:#132843;color:#fff}.ckm-sidebar-foot{margin-top:auto;border-top:1px solid var(--line);padding-top:16px;display:grid;gap:5px;font-size:13px}.ckm-user{font-weight:700}.ckm-main{padding:34px;max-width:1280px;width:100%;margin:0 auto}.ckm-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-end;margin-bottom:24px}.ckm-head h1,.ckm-auth h1{font-size:clamp(30px,4vw,48px);margin:6px 0 8px}.ckm-kicker{font-size:12px;letter-spacing:.14em;color:var(--muted);font-weight:800}.ckm-muted{color:var(--muted);line-height:1.55}.ckm-card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:22px;margin-bottom:18px}.ckm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:16px}.ckm-game-card h2{margin:10px 0}.ckm-badge{display:inline-block;padding:5px 9px;border:1px solid var(--line);border-radius:999px;font-size:12px;color:var(--muted)}.ckm-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border:1px solid #355273;border-radius:10px;background:#12243d;color:#fff;text-decoration:none;cursor:pointer}.ckm-btn:hover{background:#183252}.ckm-btn-primary{background:#dceaff;color:#07101d;border-color:#dceaff;font-weight:800}.ckm-btn-small{min-height:34px;padding:6px 10px;font-size:13px}.ckm-card-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.ckm-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:20px 0}.ckm-stat{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:20px}.ckm-stat span{display:block;color:var(--muted);font-size:13px}.ckm-stat strong{display:block;font-size:38px;margin-top:5px}.ckm-feature-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.ckm-feature-grid>div{padding:16px;background:var(--card);border-radius:14px}.ckm-feature-grid p{color:var(--muted);margin-bottom:0}.ckm-label{display:grid;gap:7px;font-size:13px;font-weight:700;color:#cddaf0}.ckm-input{width:100%;min-height:44px;border:1px solid #355273;border-radius:10px;background:#0b1728;color:#fff;padding:10px 12px;font-size:16px}.ckm-input:focus{outline:2px solid var(--accent);outline-offset:1px}.ckm-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:14px}.ckm-alert{padding:13px 15px;border-radius:12px;margin:14px 0;border:1px solid var(--line)}.ckm-alert-error{background:#32171c;color:#ffd0d0;border-color:#6d3038}.ckm-alert-ok,.ckm-success{background:#0d2b1e;border-color:#285b41}.ckm-links{display:grid;gap:10px}.ckm-links>div{background:#0a1727;border:1px solid #23415f;border-radius:12px;padding:12px;display:grid;grid-template-columns:180px 150px minmax(0,1fr);gap:10px;align-items:center}.ckm-links code{overflow-wrap:anywhere;color:#a9bad4}.ckm-table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:16px}.ckm-table{width:100%;border-collapse:collapse;background:var(--panel);min-width:780px}.ckm-table th,.ckm-table td{text-align:left;padding:13px 14px;border-bottom:1px solid var(--line);vertical-align:top}.ckm-table th{color:var(--muted);font-size:12px;letter-spacing:.06em}.ckm-q-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:17px;margin:14px 0;display:grid;gap:12px}.ckm-q-title{font-size:18px;font-weight:800}.ckm-auth{min-height:100vh;display:grid;place-items:center;padding:24px}.ckm-auth-card{width:min(520px,100%)}.ckm-auth-register{display:grid;gap:12px;margin:18px 0 16px;padding:16px;border:1px solid #355273;border-radius:14px;background:#0f2037}.ckm-auth-register>div{display:grid;gap:5px}.ckm-auth-register strong{font-size:18px}.ckm-auth-register span{color:var(--muted);line-height:1.45;font-size:14px}.ckm-auth-register .ckm-btn{width:100%}.ckm-auth-divider{display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--muted);font-size:12px}.ckm-auth-divider:before,.ckm-auth-divider:after{content:"";height:1px;background:var(--line);flex:1}.ckm-auth-divider span{text-align:center}.ckm-auth form{display:grid;gap:15px}.ckm-check{display:flex;gap:8px;align-items:center;color:var(--muted);font-size:14px}.ckm-history-meta{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:20px 0}.ckm-history-stat-value{font-size:18px!important;line-height:1.3}.ckm-history-question{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:17px;margin:14px 0}.ckm-history-question h3{font-family:inherit;font-size:18px;line-height:1.45;margin:12px 0}.ckm-history-round{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px;margin:14px 0}.ckm-history-round h3{font-family:inherit;font-size:19px;margin:0 0 12px}.ckm-history-line,.ckm-history-review{border-top:1px solid var(--line);padding:12px 0;display:grid;gap:6px}.ckm-history-line:first-of-type{border-top:0}.ckm-history-subline{padding:8px 0 8px 16px}.ckm-history-review{background:#0b1728;border:1px solid #23415f;border-radius:10px;padding:12px;margin-top:10px}.ckm-history-timeline{display:grid;gap:0}.ckm-history-event{display:grid;grid-template-columns:150px minmax(0,1fr);gap:16px;padding:13px 0;border-bottom:1px solid var(--line)}.ckm-history-event:last-child{border-bottom:0}.ckm-history-event-time{color:var(--muted);font-size:13px}.ckm-history-event-note{margin-top:6px;line-height:1.5}.ckm-history-event small{margin-left:6px;color:var(--muted)}.ckm-history-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:0 0 16px}.ckm-history-summary-card{min-width:0;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px}.ckm-history-summary-card span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em;font-weight:800}.ckm-history-summary-card strong{display:block;margin-top:7px;font-size:30px;line-height:1.1;overflow-wrap:anywhere}.ckm-history-summary-card small{display:block;margin-top:7px;color:var(--muted);font-size:12px}.ckm-history-summary-format strong{font-size:17px;line-height:1.3;margin-top:9px}.ckm-history-format-stats,.ckm-history-team-stats{margin:0 0 16px}.ckm-history-team-stats>summary{display:block;padding-right:42px}.ckm-history-team-stats>summary h2{margin:6px 0 4px}.ckm-history-team-stats>summary p{margin:0}.ckm-history-format-table{min-width:820px}.ckm-history-format-note{margin:12px 2px 0;line-height:1.5}.ckm-history-team-table{min-width:860px}.ckm-history-team-note{margin:12px 2px 0;line-height:1.5}.ckm-history-filters{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:16px;margin:0 0 14px}.ckm-history-filter-search{grid-column:span 2}.ckm-history-filter-actions,.ckm-history-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ckm-history-delete-form{margin:0}.ckm-history-bulkbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:0 0 12px;padding:12px 14px;background:#0b1728;border:1px solid #355273;border-radius:14px}.ckm-history-select-cell{width:46px;text-align:center!important}.ckm-history-select-cell input{width:18px;height:18px;cursor:pointer;accent-color:#dceaff}.ckm-btn:disabled{opacity:.45;cursor:not-allowed}.ckm-btn-danger{border-color:#713943;background:#32171c;color:#ffd0d0}.ckm-btn-danger:hover{background:#482128}.ckm-history-delete-note{margin-top:14px}.ckm-history-actions .ckm-btn{white-space:nowrap}.ckm-history-listbar{display:flex;justify-content:space-between;align-items:center;gap:14px;margin:4px 0 14px}.ckm-history-listbar p{margin:0}.ckm-history-pagination{display:flex;justify-content:center;align-items:center;gap:14px;margin:18px 0}.ckm-history-head-actions{display:flex;gap:8px;flex-wrap:wrap}.ckm-history-result-card{background:linear-gradient(145deg,#0d1c31,#102541);overflow:hidden}.ckm-history-result-head{display:flex;justify-content:space-between;gap:24px;align-items:flex-start}.ckm-history-result-head h2{margin:7px 0 6px;font-family:inherit;font-size:28px}.ckm-history-result-head p{margin:0}.ckm-history-result-metrics{display:grid;grid-template-columns:repeat(3,minmax(110px,1fr));gap:9px;min-width:min(460px,48%)}.ckm-history-result-metrics>div{background:#0a1728;border:1px solid #294769;border-radius:13px;padding:12px}.ckm-history-result-metrics span{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}.ckm-history-result-metrics strong{display:block;margin-top:5px;font-size:20px}.ckm-history-ranking{display:grid;gap:8px;margin-top:18px}.ckm-history-rank-row{display:grid;grid-template-columns:135px minmax(0,1fr) auto;gap:14px;align-items:center;background:#0a1728;border:1px solid #294769;border-radius:12px;padding:12px 14px}.ckm-history-place{color:var(--accent2);font-size:13px;font-weight:800}.ckm-history-rank-score{font-weight:900;font-size:18px;white-space:nowrap}.ckm-history-status{display:inline-flex;align-items:center;gap:7px;width:max-content;padding:6px 10px;border-radius:999px;border:1px solid var(--line);font-size:12px;font-weight:800;line-height:1.1}.ckm-history-status:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.ckm-history-status.is-finished{background:#0d2b1e;border-color:#285b41;color:#8ee7b8}.ckm-history-status.is-live{background:#102b46;border-color:#2f6392;color:#9ed4ff}.ckm-history-status.is-paused{background:#332913;border-color:#6e5926;color:#f1cf77}.ckm-history-status.is-cancelled{background:#32171c;border-color:#713943;color:#ffb0b8}.ckm-history-status.is-waiting{background:#172234;border-color:#344760;color:#b9c8dc}.ckm-history-fold{overflow:hidden}.ckm-history-fold>summary{cursor:pointer;list-style:none;position:relative;padding-right:38px}.ckm-history-fold>summary::-webkit-details-marker{display:none}.ckm-history-fold>summary:after{content:"⌄";position:absolute;right:4px;top:50%;transform:translateY(-50%) rotate(-90deg);font-size:24px;color:var(--muted);transition:transform .16s ease}.ckm-history-fold[open]>summary:after{transform:translateY(-50%) rotate(0)}.ckm-history-fold>summary:hover{color:#fff}.ckm-history-fold-body{margin-top:14px}.ckm-history-major-fold>summary h2{margin:6px 0 0}.ckm-history-major-fold>summary p{margin:6px 0 0}.ckm-history-question.ckm-history-fold{padding:0}.ckm-history-question.ckm-history-fold>summary{padding:17px 50px 17px 17px}.ckm-history-question.ckm-history-fold>.ckm-history-fold-body{padding:0 17px 17px;margin-top:0}.ckm-history-question-summary{display:grid;gap:10px}.ckm-history-question-summary>strong{font-size:18px;line-height:1.45}.ckm-history-count{display:inline-flex;align-items:center;justify-content:center;min-width:26px;padding:3px 7px;margin-left:5px;border:1px solid #355273;border-radius:999px;color:#a9bad4;font-size:12px;font-weight:800;vertical-align:middle}.ckm-history-format-leaderboards{margin:0 0 16px}.ckm-history-format-leaderboards>summary{display:block;padding-right:42px}.ckm-history-format-leaderboards>summary h2{margin:6px 0 4px}.ckm-history-format-leaderboards>summary p{margin:0}.ckm-history-subfold{background:var(--card);border:1px solid var(--line);border-radius:14px;margin:10px 0;overflow:hidden}.ckm-history-subfold>summary{display:flex;justify-content:space-between;gap:12px;align-items:center;cursor:pointer;padding:14px 16px}.ckm-history-subfold-body{padding:0 14px 14px}.ckm-history-format-leaderboard-table{min-width:760px}.ckm-history-format-leaderboard-note{margin:12px 2px 0;line-height:1.5}<?php echo ckm_quiz_pro_games_catalog_inline_css(); ?>
    @media(max-width:1100px){.ckm-history-summary{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:820px){.ckm-history-summary{grid-template-columns:1fr 1fr}.ckm-history-result-head{flex-direction:column}.ckm-history-result-metrics{grid-template-columns:1fr 1fr 1fr;min-width:0;width:100%}.ckm-history-rank-row{grid-template-columns:1fr auto}.ckm-history-place{grid-column:1/-1}.ckm-history-listbar{align-items:stretch;flex-direction:column}.ckm-history-bulkbar{align-items:stretch;flex-direction:column}.ckm-history-bulkbar .ckm-btn{width:100%}.ckm-history-pagination{justify-content:space-between;flex-wrap:wrap}.ckm-history-head-actions{width:100%}.ckm-history-head-actions .ckm-btn{flex:1}.ckm-layout{grid-template-columns:1fr}.ckm-sidebar{position:static;border-right:0;border-bottom:1px solid var(--line);padding:14px}.ckm-sidebar nav{display:flex;overflow-x:auto}.ckm-nav{white-space:nowrap}.ckm-sidebar-foot{display:none}.ckm-main{padding:22px 14px}.ckm-head{align-items:flex-start;flex-direction:column}.ckm-stats,.ckm-feature-grid,.ckm-form-grid,.ckm-direction-grid,.ckm-history-meta{grid-template-columns:1fr}.ckm-access-summary-head{align-items:flex-start;flex-direction:column}.ckm-links>div{grid-template-columns:1fr}.ckm-grid{grid-template-columns:1fr}.ckm-history-event{grid-template-columns:1fr;gap:5px}.ckm-history-filters{grid-template-columns:1fr}.ckm-history-filter-search{grid-column:auto}.ckm-history-filter-actions{align-items:stretch}.ckm-history-filter-actions .ckm-btn{flex:1}}@media(max-width:520px){.ckm-history-summary{grid-template-columns:1fr}.ckm-history-summary-format{grid-column:auto}}
    </style></head><body><?php
    ckm_quiz_pro_custom_game_legacy_route();
}

function ckm_quiz_pro_org_shell_end(): void { echo '</body></html>'; }

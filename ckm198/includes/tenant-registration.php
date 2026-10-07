<?php
if (!defined('ABSPATH')) exit;

function ckmqp_my_sites(int $uid): array {
    if ($uid<=0 || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return [];
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT t.id,t.slug,t.name,t.status,d.hostname FROM '.ckmqp_tenant_table('members').' m INNER JOIN '.ckmqp_tenant_table('tenants').' t ON t.id=m.tenant_id LEFT JOIN '.ckmqp_tenant_table('domains')." d ON d.tenant_id=t.id AND d.status IN ('active','reserved') WHERE m.user_id=%d AND m.status='active' ORDER BY t.id",
        $uid
    ),ARRAY_A)?:[];
}

function ckmqp_tenant_site_row(int $tenantId): ?array {
    if ($tenantId<=0 || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return null;
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT t.id,t.slug,t.name,t.status,d.hostname FROM '.ckmqp_tenant_table('tenants').' t LEFT JOIN '.ckmqp_tenant_table('domains')." d ON d.tenant_id=t.id AND d.status IN ('active','reserved') WHERE t.id=%d ORDER BY d.id LIMIT 1",
        $tenantId
    ),ARRAY_A);
    return is_array($row)?$row:null;
}

function ckmqp_tenant_root_url(int $tenantId): string {
    $site=ckmqp_tenant_site_row($tenantId);
    return $site && !empty($site['hostname']) ? 'https://'.$site['hostname'].'/' : home_url('/');
}

/** Canonical first screen for an organizer on a concrete tenant. */
function ckmqp_tenant_base_games_url(int $tenantId): string {
    $base=ckm_quiz_pro_organizer_base_url();
    if($tenantId>0){
        $tenantUrl=ckmqp_tenant_link($base,$tenantId);
        if($tenantUrl!=='') $base=$tenantUrl;
    }
    return add_query_arg(['view'=>'games'],$base);
}

function ckmqp_primary_site_for_user(int $uid): ?array {
    $sites=ckmqp_my_sites($uid);
    return $sites ? $sites[0] : null;
}

/** Rewrite only platform URLs, only to a registered first-level subdomain. */
function ckmqp_tenant_link(string $url,int $tenant): string {
    if ($tenant===0) return $url;
    if ($tenant<0 || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return '';
    global $wpdb;
    $host=(string)$wpdb->get_var($wpdb->prepare('SELECT hostname FROM '.ckmqp_tenant_table('domains')." WHERE tenant_id=%d AND status IN ('active','reserved') ORDER BY id LIMIT 1",$tenant));
    $base=ckmqp_tenant_primary_host();
    if ($host!==ckmqp_tenant_hostname($host) || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.'.preg_quote($base,'/').'$/D',$host)) return '';
    $parts=wp_parse_url($url);
    if (!is_array($parts) || !in_array($parts['scheme']??'', ['http','https'],true) || isset($parts['user']) || isset($parts['pass'])) return '';
    $source=ckmqp_tenant_hostname((string)($parts['host']??''));
    $admin=ckmqp_tenant_hostname((string)wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST));
    if (!in_array($source,[$base,$admin,$host],true)) return '';
    return 'https://'.$host.($parts['path']??'/').(isset($parts['query'])?'?'.$parts['query']:'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');
}

function ckmqp_login_destination(int $uid): string {
    $id=ckmqp_return_order_id($_GET['test_order']??null);
    if ($id!=='') {
        $order=ckmqp_return_order($id,$uid);
        if ($order) return function_exists('ckmqp_return_check_url') ? ckmqp_return_check_url($order) : add_query_arg(['view'=>'payment','test_order'=>$id],ckm_quiz_pro_organizer_url());
    }

    // A storefront or an administrator-generated sale link may send the buyer
    // to login with a selected server-known product. The price is never carried
    // in the URL: checkout rebuilds it from ckm_quiz_pro_game_access_products().
    $buyRaw = is_string($_GET['buy']??null) ? sanitize_key(wp_unslash($_GET['buy'])) : '';
    $buy = $buyRaw!=='' ? ckm_quiz_pro_access_format_key($buyRaw) : '';
    if ($buy!=='' && isset(ckm_quiz_pro_game_access_products()[$buy])) {
        $args=['view'=>'payment','games'=>$buy];
        if(!empty($_GET['renew'])) $args['renew']=1;
        if (ckmqp_scope_id()>0) return ckm_quiz_pro_organizer_url($args);
        $site=ckmqp_primary_site_for_user($uid);
        if($site && !empty($site['id'])){
            $base=add_query_arg($args,ckm_quiz_pro_organizer_base_url());
            $tenantUrl=ckmqp_tenant_link($base,(int)$site['id']);
            if($tenantUrl!=='') return $tenantUrl;
        }
        return ckm_quiz_pro_organizer_url($args);
    }

    // Preserve only the fixed constructor destination, never a user-supplied URL.
    if (($_GET['create_game'] ?? '') === '1') {
        $builderUrl = ckm_quiz_pro_organizer_url(['view'=>'builder']);
        if (ckmqp_scope_id()===0) {
            $site = ckmqp_primary_site_for_user($uid);
            if ($site && !empty($site['id'])) {
                $tenantUrl = ckmqp_tenant_link($builderUrl, (int)$site['id']);
                if ($tenantUrl !== '') return $tenantUrl;
            }
        }
        return $builderUrl;
    }

    if (ckmqp_scope_id()===0) {
        $site=ckmqp_primary_site_for_user($uid);
        if ($site && !empty($site['id'])) return ckmqp_tenant_base_games_url((int)$site['id']);
    }
    return ckm_quiz_pro_organizer_url(['view'=>'games']);
}

// Check membership before wp_signon sets an authentication cookie.
add_filter('authenticate',static function ($user) {
    $tenant=ckmqp_scope_id();
    if ($user instanceof WP_User && ($tenant<0 || ($tenant>0 && !ckmqp_tenant_is_member($tenant,(int)$user->ID)))) return new WP_Error('tenant_access','Для этой учётной записи площадка недоступна.');
    return $user;
},99);

add_filter('admin_url',static function ($url,$path) {
    return $path==='admin-ajax.php' && ckmqp_scope_id()>0 ? ckmqp_tenant_link($url,ckmqp_scope_id()):$url;
},10,2);

function ckmqp_registration_open(): bool {
    return get_option('ckmqp_tenant_registration','0')==='1' && ckmqp_scope_id()===0;
}

function ckmqp_owner_login_valid(string $login): bool {
    return $login!=='' && strlen($login)<=60 && validate_username($login) && sanitize_user($login,true)===$login;
}

/** Create a new owner account and reserve a tenant atomically as far as WordPress allows. */
function ckmqp_signup_owner(string $siteName,string $slug,string $login,string $email,string $password,string $password2) {
    if (!ckmqp_registration_open()) return new WP_Error('disabled','Регистрация площадок пока закрыта.');
    $siteName=sanitize_text_field($siteName);
    $slug=strtolower(trim($slug));
    $login=trim($login);
    $email=sanitize_email($email);
    if (mb_strlen($siteName)<2 || mb_strlen($siteName)>190) return new WP_Error('site_name','Укажите название площадки от 2 до 190 символов.');
    if (!ckmqp_tenant_slug_valid($slug)) return new WP_Error('slug','Укажите адрес площадки латиницей: строчные буквы, цифры и дефис.');
    if (!ckmqp_owner_login_valid($login)) return new WP_Error('login','Укажите корректный логин владельца латиницей, без пробелов.');
    if (!is_email($email)) return new WP_Error('email','Укажите корректный email владельца.');
    if (strlen($password)<12 || strlen($password)>4096) return new WP_Error('password','Пароль должен содержать не менее 12 символов.');
    if (!hash_equals($password,$password2)) return new WP_Error('password_match','Пароли не совпадают.');

    global $wpdb;
    $lock='ckmqp_signup_'.md5($slug.'|'.$login.'|'.$email);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1) return new WP_Error('busy','Регистрация уже выполняется. Повторите позже.');
    $uid=0;
    try {
        if (!ckmqp_tenant_install()) return new WP_Error('database','Не удалось подготовить площадку.');
        $tenantTable=ckmqp_tenant_table('tenants');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $tenantTable WHERE slug=%s",$slug))) return new WP_Error('taken_slug','Такой адрес площадки уже занят.');
        if (username_exists($login)) return new WP_Error('taken_login','Такой логин владельца уже используется.');
        if (email_exists($email)) return new WP_Error('taken_email','Этот email уже зарегистрирован. Войдите в существующий аккаунт.');
        if (!get_role(CKM_QUIZ_PRO_ORGANIZER_ROLE)) return new WP_Error('role','Роль организатора ещё не установлена.');

        $uid=wp_insert_user([
            'user_login'=>$login,
            'user_email'=>$email,
            'user_pass'=>$password,
            'display_name'=>$login,
            'role'=>CKM_QUIZ_PRO_ORGANIZER_ROLE,
        ]);
        if (is_wp_error($uid)) return $uid;

        $result=ckmqp_tenant_reserve_owner($slug,$siteName,(int)$uid);
        if (is_wp_error($result)) {
            if (!function_exists('wp_delete_user')) require_once ABSPATH.'wp-admin/includes/user.php';
            wp_delete_user((int)$uid);
            return $result;
        }
        return ['user_id'=>(int)$uid,'tenant_id'=>(int)$result];
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
}

/** Existing signed-in owner can add another tenant without creating another password/account. */
function ckmqp_register_site_for_owner(string $siteName,string $slug,int $uid) {
    if (!ckmqp_registration_open()) return new WP_Error('disabled','Регистрация площадок пока закрыта.');
    if ($uid<=0 || (!user_can($uid,CKM_QUIZ_PRO_ORGANIZER_CAP) && !user_can($uid,'manage_options'))) return new WP_Error('forbidden','Для этого аккаунта создание площадки недоступно.');
    if (ckmqp_my_sites($uid)) return new WP_Error('one_site','Для этого аккаунта площадка уже создана.');
    $siteName=sanitize_text_field($siteName);
    $slug=strtolower(trim($slug));
    if (mb_strlen($siteName)<2 || mb_strlen($siteName)>190) return new WP_Error('site_name','Укажите название площадки от 2 до 190 символов.');
    if (!ckmqp_tenant_slug_valid($slug)) return new WP_Error('slug','Укажите адрес площадки латиницей: строчные буквы, цифры и дефис.');
    global $wpdb;
    $lock='ckmqp_site_'.md5($slug);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1) return new WP_Error('busy','Создание площадки уже выполняется. Повторите позже.');
    try {
        if (!ckmqp_tenant_install()) return new WP_Error('database','Не удалось подготовить площадку.');
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM '.ckmqp_tenant_table('tenants').' WHERE slug=%s',$slug))) return new WP_Error('taken_slug','Такой адрес площадки уже занят.');
        return ckmqp_tenant_reserve_owner($slug,$siteName,$uid);
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
}

add_action('template_redirect','ckmqp_sites_route',-50);
function ckmqp_sites_route(): void {
    $path=(string)wp_parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
    $base=rtrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');
    if (!in_array(rtrim($path,'/'),[$base.'/ckm-register',$base.'/ckm-sites'],true) || ckmqp_scope_id()!==0) return;
    nocache_headers();
    status_header(200);
    $signup=rtrim($path,'/')===$base.'/ckm-register';
    $error='';
    $success='';

    if (!$signup && !is_user_logged_in()) { wp_safe_redirect(ckm_quiz_pro_login_url()); exit; }

    if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
        if (!wp_verify_nonce((string)($_POST['_wpnonce']??''),'ckmqp_sites')) {
            $error='Сессия формы истекла. Обновите страницу.';
        } elseif ($signup && !is_user_logged_in()) {
            $key='ckmqp_signup_rate_'.hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));
            $count=(int)get_transient($key);
            if ($count>=10) {
                $error='Слишком много попыток. Повторите через 15 минут.';
            } else {
                set_transient($key,$count+1,15*MINUTE_IN_SECONDS);
                $result=ckmqp_signup_owner(
                    (string)wp_unslash($_POST['site_name']??''),
                    (string)wp_unslash($_POST['site_slug']??''),
                    (string)wp_unslash($_POST['owner_login']??''),
                    (string)wp_unslash($_POST['email']??''),
                    (string)wp_unslash($_POST['password']??''),
                    (string)wp_unslash($_POST['password2']??'')
                );
                if (is_wp_error($result)) {
                    $error=$result->get_error_message();
                } else {
                    $uid=(int)$result['user_id'];
                    wp_set_current_user($uid);
                    wp_set_auth_cookie($uid,true,is_ssl());
                    do_action('wp_login',get_userdata($uid)->user_login,get_userdata($uid));
                    wp_safe_redirect(ckmqp_tenant_base_games_url((int)$result['tenant_id']));
                    exit;
                }
            }
        } elseif ($signup && is_user_logged_in()) {
            $existing=ckmqp_primary_site_for_user(get_current_user_id());
            if ($existing && !empty($existing['id'])) {
                wp_safe_redirect(ckmqp_tenant_base_games_url((int)$existing['id']));
                exit;
            }
            $result=ckmqp_register_site_for_owner(
                (string)wp_unslash($_POST['site_name']??''),
                (string)wp_unslash($_POST['site_slug']??''),
                get_current_user_id()
            );
            if (is_wp_error($result)) $error=$result->get_error_message();
            else { wp_safe_redirect(ckmqp_tenant_base_games_url((int)$result)); exit; }
        } elseif (!$signup && ckm_quiz_pro_can_organize() && !ckmqp_my_sites(get_current_user_id())) {
            $user=wp_get_current_user();
            $slug=strtolower((string)$user->user_login);
            $result=ckmqp_tenant_reserve_owner($slug,(string)$user->display_name,(int)$user->ID);
            if (is_wp_error($result)) $error=$result->get_error_message(); else $success='Площадка создана. Адрес доступен сразу; игры открываются после оплаты.';
        }
    }

    ckm_quiz_pro_org_shell_start($signup?'Регистрация площадки':'Моя площадка');
    echo '<main class="ckm-main"><section class="ckm-card"><h1>'.($signup?'Регистрация площадки':'Моя площадка').'</h1>';
    if ($error!=='') echo '<div class="ckm-alert ckm-alert-error" role="alert">'.esc_html($error).'</div>';
    if ($success!=='') echo '<div class="ckm-alert"><strong>'.esc_html($success).'</strong></div>';

    if ($signup) {
        if (!ckmqp_registration_open()) {
            echo '<p>Регистрация площадок пока закрыта.</p>';
        } elseif (is_user_logged_in()) {
            $u=wp_get_current_user();
            $existing=ckmqp_primary_site_for_user((int)$u->ID);
            if ($existing && !empty($existing['id'])) {
                echo '<p class="ckm-muted">Для аккаунта <strong>'.esc_html($u->user_login).'</strong> площадка уже создана.</p>';
                echo '<p><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckmqp_tenant_root_url((int)$existing['id'])).'">Открыть мою площадку</a></p>';
            } else {
                echo '<p class="ckm-muted">Создайте свою площадку для текущего аккаунта <strong>'.esc_html($u->user_login).'</strong>.</p>';
                echo '<form method="post">'; wp_nonce_field('ckmqp_sites');
                echo '<label class="ckm-label">Название площадки<input class="ckm-input" name="site_name" maxlength="190" required placeholder="Например, Школа 222"></label>';
                echo '<label class="ckm-label">Адрес площадки<div><input class="ckm-input" style="max-width:260px;display:inline-block" name="site_slug" maxlength="63" pattern="[a-z0-9][a-z0-9-]*" autocomplete="off" required placeholder="222"> <strong>.'.esc_html(ckmqp_tenant_primary_host()).'</strong></div></label>';
                echo '<p><button class="ckm-btn ckm-btn-primary">Создать площадку</button></p></form>';
            }
        } else {
            echo '<p>Создайте аккаунт организатора и площадку. Ссылка на площадку появится сразу после регистрации; игровые форматы открываются после оплаты.</p>';
            echo '<form method="post" autocomplete="off">'; wp_nonce_field('ckmqp_sites');
            echo '<label class="ckm-label">Название площадки<input class="ckm-input" name="site_name" maxlength="190" required placeholder="Например, Школа 222"></label>';
            echo '<label class="ckm-label">Адрес площадки<div><input class="ckm-input" style="max-width:260px;display:inline-block" name="site_slug" maxlength="63" pattern="[a-z0-9][a-z0-9-]*" autocapitalize="none" spellcheck="false" autocomplete="off" required placeholder="222"> <strong>.'.esc_html(ckmqp_tenant_primary_host()).'</strong></div></label>';
            echo '<label class="ckm-label">Логин организатора<input class="ckm-input" name="owner_login" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>';
            echo '<label class="ckm-label">Email организатора<input class="ckm-input" name="email" type="email" autocomplete="email" required></label>';
            echo '<label class="ckm-label">Пароль<input class="ckm-input" name="password" type="password" minlength="12" autocomplete="new-password" required><span class="ckm-muted">Не менее 12 символов. CKM не хранит пароль в открытом виде.</span></label>';
            echo '<label class="ckm-label">Повторите пароль<input class="ckm-input" name="password2" type="password" minlength="12" autocomplete="new-password" required></label>';
            echo '<p><button class="ckm-btn ckm-btn-primary">Зарегистрироваться и создать площадку</button></p></form>';
            echo '<p class="ckm-muted">Уже зарегистрированы? <a href="'.esc_url(ckm_quiz_pro_login_url()).'">Войти</a>.</p>';
        }
    } else {
        $sites=ckmqp_my_sites(get_current_user_id());
        foreach ($sites as $site) {
            $paid=ckmqp_return_paid_titles((int)$site['id'],get_current_user_id());
            echo '<article class="ckm-card"><h2>'.esc_html($site['name']).'</h2><p><strong>Адрес площадки:</strong> <a href="'.esc_url('https://'.($site['hostname']??'')).'/">'.esc_html($site['hostname']??'').'</a></p><p>Площадка: <strong>'.((string)$site['status']==='active'?'активна':'доступна').'</strong>. Игровые форматы открываются после оплаты.</p>';
            if ($paid) {
                $products=ckm_quiz_pro_game_access_products();
                $paidDetails=[];
                foreach ($products as $formatKey=>$product) {
                    $accessInfo=function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info(get_current_user_id(),$formatKey,(int)$site['id']) : [];
                    $accessText=!empty($accessInfo['display_text']) ? (string)$accessInfo['display_text'] : '';
                    if ($accessText!=='') $paidDetails[]=$product['title'].' — '.str_replace('Доступ активен · ','',$accessText).(!empty($accessInfo['expiring_soon'])?' (скоро закончится)':'');
                }
                echo '<p>Оплаченные игры: '.esc_html($paidDetails ? implode('; ',$paidDetails) : implode(', ',$paid)).'.</p>';
            }
            echo '<div class="ckm-card-actions">';
            if (!empty($site['hostname'])) echo '<a class="ckm-btn" href="'.esc_url('https://'.$site['hostname'].'/ckm-organizer/').'">Открыть площадку</a>';
            echo '</div></article>';
        }
        if (!$sites && ckm_quiz_pro_can_organize()) {
            echo '<p>У аккаунта пока нет площадки.</p><p><a class="ckm-btn ckm-btn-primary" href="'.esc_url(home_url('/ckm-register/')).'">Создать площадку</a></p>';
        } elseif (!$sites) {
            echo '<p>Для вашей учётной записи площадка не создана.</p>';
        }
        echo '<p><a href="'.esc_url(ckm_quiz_pro_organizer_url()).'">Кабинет</a></p>';
    }
    echo '<p><a href="'.esc_url(ckm_quiz_pro_login_url()).'">Вход в кабинет</a></p></section></main>';
    ckm_quiz_pro_org_shell_end();
    exit;
}

add_action('admin_menu',static function () { add_submenu_page('ckm-quiz-pro','Регистрация площадок','Регистрация площадок','manage_options','ckmqp-registration','ckmqp_registration_admin'); });
function ckmqp_registration_admin(): void {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['save_registration'])) {
        check_admin_referer('ckmqp_registration');
        update_option('ckmqp_tenant_registration',isset($_POST['enabled'])?'1':'0',false);
    }
    echo '<div class="wrap"><h1>Регистрация площадок</h1><p>Открытая регистрация создаёт аккаунт организатора и его площадку. Один аккаунт получает одну площадку. Игры на площадке открываются после оплаты.</p><form method="post">';
    wp_nonce_field('ckmqp_registration');
    echo '<label><input type="checkbox" name="enabled" '.checked(get_option('ckmqp_tenant_registration','0'),'1',false).'> Разрешить регистрацию организаторов и площадок</label><p><button class="button button-primary" name="save_registration">Сохранить</button></p></form><p><a href="'.esc_url(home_url('/ckm-register/')).'">Страница регистрации</a> · <a href="'.esc_url(home_url('/ckm-sites/')).'">Моя площадка</a></p></div>';
}

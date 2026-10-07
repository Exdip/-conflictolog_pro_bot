<?php
/** Tenant foundation. Tenant sites are available immediately; paid entitlements gate games, not the site itself. */
if (!defined('ABSPATH')) exit;
const CKMQP_TENANT_SCHEMA = '1';
function ckmqp_tenant_table(string $name): string {
    global $wpdb;
    if (!in_array($name,['tenants','domains','members'],true)) throw new InvalidArgumentException('Unknown tenant table');
    return $wpdb->prefix.'ckmqp_'.$name;
}
/** Normalize only a hostname/Host header, never a URL or forwarded header. */
function ckmqp_tenant_hostname(string $host): string {
    if ($host==='' || preg_match('/[\s\/@\\\\?#,]/',$host)) return '';
    if (!preg_match('/^([a-z0-9.-]+)(?::([0-9]{1,5}))?$/iD',$host,$m)) return '';
    if (isset($m[2]) && ((int)$m[2]<1 || (int)$m[2]>65535)) return '';
    $name=strtolower(rtrim($m[1],'.'));
    if (strlen($name)>253) return '';
    foreach (explode('.',$name) as $label) if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$label)) return '';
    return $name;
}
function ckmqp_tenant_primary_host(): string {
    return ckmqp_tenant_hostname((string)wp_parse_url((string)get_option('home'),PHP_URL_HOST));
}
function ckmqp_tenant_slug_valid(string $slug): bool {
    $reserved=['www','admin','administrator','api','mail','smtp','imap','pop','ftp','sftp','cdn','static','assets','media','support','help','billing','pay','payment','payments','test','demo','staging','dev','localhost','autodiscover','webmail','ns1','ns2','root'];
    return (bool)preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$slug) && !in_array($slug,$reserved,true);
}
function ckmqp_tenant_install(): bool {
    global $wpdb;
    if (get_option('ckmqp_tenant_schema')===CKMQP_TENANT_SCHEMA) return true;
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $c=$wpdb->get_charset_collate();
    $t=ckmqp_tenant_table('tenants');$d=ckmqp_tenant_table('domains');$m=ckmqp_tenant_table('members');
    dbDelta("CREATE TABLE $t (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        slug varchar(63) NOT NULL,
        name varchar(190) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        branding_json longtext NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY slug (slug)
    ) ENGINE=InnoDB $c;");
    dbDelta("CREATE TABLE $d (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL,
        hostname varchar(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY hostname (hostname),
        KEY tenant_id (tenant_id)
    ) ENGINE=InnoDB $c;");
    dbDelta("CREATE TABLE $m (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL,
        user_id bigint unsigned NOT NULL,
        role varchar(20) NOT NULL DEFAULT 'organizer',
        status varchar(20) NOT NULL DEFAULT 'active',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY membership (tenant_id,user_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB $c;");
    foreach ([$t,$d,$m] as $table) {
        $row=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s',$table),ARRAY_A);
        if (!$row || strcasecmp((string)$row['Engine'],'InnoDB')!==0) return false;
    }
    update_option('ckmqp_tenant_schema',CKMQP_TENANT_SCHEMA,false);
    return true;
}
/** Admin-only reservation; no changes to existing accounts, orders or entitlements. */
function ckmqp_tenant_reserve(string $slug,string $name,int $owner) {
    if (!current_user_can('manage_options')) return new WP_Error('forbidden','Недостаточно прав.');
    return ckmqp_tenant_reserve_owner($slug,$name,$owner);
}
/** Internal provisioner; public routes must authorize the owner before calling. */
function ckmqp_tenant_reserve_owner(string $slug,string $name,int $owner) {
    $base=ckmqp_tenant_primary_host();
    if (!ckmqp_tenant_slug_valid($slug) || !$base || strlen($slug.'.'.$base)>253) return new WP_Error('invalid_slug','Нужен свободный адрес: латинские строчные буквы, цифры и дефис; до 63 символов. Служебные имена запрещены.');
    $user=get_userdata($owner);
    if (!$user || (!user_can($owner,'ckm_quiz_organize') && !user_can($owner,'manage_options'))) return new WP_Error('invalid_owner','Укажите существующего организатора или администратора.');
    if (!ckmqp_tenant_install()) return new WP_Error('schema','Не удалось подготовить таблицы площадок.');
    global $wpdb;
    $t=ckmqp_tenant_table('tenants');$d=ckmqp_tenant_table('domains');$m=ckmqp_tenant_table('members');
    $now=gmdate('Y-m-d H:i:s');
    if ($wpdb->query('START TRANSACTION')===false) return new WP_Error('database','Не удалось начать сохранение.');
    $ok=$wpdb->insert($t,['slug'=>$slug,'name'=>sanitize_text_field($name)?:$slug,'status'=>'active','created_at'=>$now]);
    $id=(int)$wpdb->insert_id;
    if ($ok!==false && $id>0) $ok=$wpdb->insert($d,['tenant_id'=>$id,'hostname'=>$slug.'.'.$base,'status'=>'active','created_at'=>$now]);
    if ($ok!==false && $id>0) $ok=$wpdb->insert($m,['tenant_id'=>$id,'user_id'=>$owner,'role'=>'owner','status'=>'active','created_at'=>$now]);
    if ($ok===false || $id<=0) { $wpdb->query('ROLLBACK'); return new WP_Error('reservation_failed','Не удалось зарезервировать площадку. Возможно, адрес уже занят.'); }
    if ($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); return new WP_Error('database','Не удалось завершить сохранение.'); }
    return $id;
}
/** Public resolution never creates a tenant or falls back to the main site. */

/** Allow safe redirects only to tenant domains registered in the platform database. */
function ckmqp_tenant_allowed_redirect_hosts(array $hosts, string $host): array {
    $host=ckmqp_tenant_hostname($host);
    if ($host==='' || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return $hosts;

    $primary=ckmqp_tenant_primary_host();
    $admin=ckmqp_tenant_hostname((string)wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST));
    if ($host===$primary || ($admin!=='' && $host===$admin)) {
        if (!in_array($host,$hosts,true)) $hosts[]=$host;
        return $hosts;
    }

    global $wpdb;
    $t=ckmqp_tenant_table('tenants');
    $d=ckmqp_tenant_table('domains');
    $allowed=(bool)$wpdb->get_var($wpdb->prepare(
        "SELECT d.id FROM $d d INNER JOIN $t t ON t.id=d.tenant_id WHERE d.hostname=%s AND d.status IN ('active','reserved') AND t.status IN ('active','staged') LIMIT 1",
        $host
    ));
    if ($allowed && !in_array($host,$hosts,true)) $hosts[]=$host;
    return array_values(array_unique($hosts));
}
add_filter('allowed_redirect_hosts','ckmqp_tenant_allowed_redirect_hosts',10,2);

function ckmqp_tenant_resolve(string $host): array {
    $host=ckmqp_tenant_hostname($host);
    if ($host==='') return ['kind'=>'invalid','tenant_id'=>0];
    $primary=ckmqp_tenant_primary_host();
    $admin=ckmqp_tenant_hostname((string)wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST));
    if ($host===$primary || ($admin!=='' && $host===$admin)) return ['kind'=>'platform','tenant_id'=>0];
    if (get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return ['kind'=>'unknown','tenant_id'=>0];
    global $wpdb;
    $t=ckmqp_tenant_table('tenants');$d=ckmqp_tenant_table('domains');
    $row=$wpdb->get_row($wpdb->prepare("SELECT t.id,t.status,d.status AS domain_status FROM $t t INNER JOIN $d d ON d.tenant_id=t.id WHERE d.hostname=%s AND d.status IN ('active','reserved')",$host),ARRAY_A);
    if (!$row) return ['kind'=>'unknown','tenant_id'=>0];
    // Backward compatibility: sites created by earlier builds used staged/reserved.
    // They are now treated as live; payment controls only access to games.
    if (in_array((string)$row['status'],['active','staged'],true)) return ['kind'=>'tenant','tenant_id'=>(int)$row['id']];
    return ['kind'=>'unknown','tenant_id'=>0];
}
function ckmqp_tenant_is_member(int $tenant,int $user): bool {
    if ($tenant<=0 || $user<=0 || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return false;
    global $wpdb;
    return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.ckmqp_tenant_table('members')." WHERE tenant_id=%d AND user_id=%d AND status='active'",$tenant,$user));
}
function ckmqp_tenant_request_guard(): void {
    if (PHP_SAPI==='cli' || (defined('WP_CLI') && WP_CLI)) return;
    $context=ckmqp_tenant_resolve((string)($_SERVER['HTTP_HOST']??''));
    $GLOBALS['ckmqp_tenant_context']=$context;
    ckmqp_dynamic_nocache();
    ckmqp_probe_respond($context);
    if (in_array($context['kind'],['platform','tenant'],true)) return;
    nocache_headers();
    wp_die('Площадка не найдена.','',['response'=>404]);
}
// Existing sites created by the staged rollout become live automatically.
function ckmqp_tenant_open_existing_sites(): void {
    if (get_option('ckmqp_tenant_open_policy','')==='1' || get_option('ckmqp_tenant_schema')!==CKMQP_TENANT_SCHEMA) return;
    global $wpdb;
    $t=ckmqp_tenant_table('tenants'); $d=ckmqp_tenant_table('domains');
    $wpdb->query("UPDATE $t SET status='active' WHERE status='staged'");
    $wpdb->query("UPDATE $d SET status='active' WHERE status='reserved'");
    update_option('ckmqp_tenant_open_policy','1',false);
}
add_action('init','ckmqp_tenant_open_existing_sites',0);

// Run before init, REST, frontend routes and canonical redirects.
add_action('plugins_loaded','ckmqp_tenant_request_guard',-1000);
add_action('admin_menu',static function () {
    add_submenu_page('ckm-quiz-pro','Площадки','Площадки','manage_options','ckm-quiz-pro-tenants','ckmqp_tenant_admin');
});
function ckmqp_tenant_admin(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><h1>Площадки — подготовка</h1>';
    if (!ckmqp_tenant_install()) { echo '<p>Не удалось создать таблицы. Проверьте доступ базы данных и поддержку InnoDB.</p></div>'; return; }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_admin_referer('ckmqp_tenant_reserve');
        $result=ckmqp_tenant_reserve(trim((string)wp_unslash($_POST['tenant_slug']??'')),(string)wp_unslash($_POST['tenant_name']??''),absint($_POST['owner_id']??0));
        echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Площадка создана и доступна по своему адресу. Игры открываются после оплаты.').'</p></div>';
    }
    echo '<p>Основная платформа: <strong>'.esc_html(ckmqp_tenant_primary_host()).'</strong>. Новые площадки доступны сразу после регистрации. Оплата открывает игровые форматы.</p><form method="post">';
    wp_nonce_field('ckmqp_tenant_reserve');
    echo '<p><label>Адрес площадки <input name="tenant_slug" required maxlength="63" pattern="[a-z0-9][a-z0-9-]*" placeholder="alfa">.'.esc_html(ckmqp_tenant_primary_host()).'</label></p><p><label>Название <input name="tenant_name" maxlength="190" required></label></p><p><label>ID организатора <input name="owner_id" type="number" min="1" required></label></p>';
    submit_button('Зарезервировать площадку');echo '</form>';
    global $wpdb;
    $rows=$wpdb->get_results('SELECT t.id,t.name,t.status,d.hostname FROM '.ckmqp_tenant_table('tenants').' t LEFT JOIN '.ckmqp_tenant_table('domains').' d ON d.tenant_id=t.id ORDER BY t.id DESC LIMIT 100',ARRAY_A)?:[];
    echo '<h2>Последние 100 площадок</h2><table class="widefat"><thead><tr><th>ID</th><th>Название</th><th>Адрес</th><th>Состояние</th></tr></thead><tbody>';
    foreach ($rows as $row) echo '<tr><td>'.(int)$row['id'].'</td><td>'.esc_html($row['name']).'</td><td>'.esc_html($row['hostname']??'').'</td><td>'.esc_html((string)$row['status']).'</td></tr>';
    echo '</tbody></table><h2>Подготовка переноса</h2><p>Существующие запуски и доступы пока остаются в прежней схеме. Владелец запуска — кандидат для переноса, но ещё не назначение площадки. При нескольких площадках владельца потребуется явный выбор. Общую библиотеку шаблонов переносить к одному клиенту нельзя.</p>';
    $games=ckm_quiz_pro_table('games');
    $groups=$wpdb->get_results("SELECT created_by_user_id,COUNT(*) AS total FROM $games GROUP BY created_by_user_id ORDER BY created_by_user_id LIMIT 100",ARRAY_A)?:[];
    echo '<table class="widefat"><thead><tr><th>ID создателя</th><th>Запусков</th><th>Действие при переносе</th></tr></thead><tbody>';
    foreach ($groups as $g) echo '<tr><td>'.(int)$g['created_by_user_id'].'</td><td>'.(int)$g['total'].'</td><td>'.((int)$g['created_by_user_id']>0?'Назначить площадку владельца':'Разобрать записи без владельца вручную').'</td></tr>';
    echo '</tbody></table><p>Следующий этап: привязка заказов, прав доступа, запусков и результатов к площадке; затем открытие регистрации поддоменов.</p></div>';
}

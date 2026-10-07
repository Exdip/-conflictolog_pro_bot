<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_game_access_products(): array {
    return apply_filters('ckm_quiz_pro_game_access_products', [
        'classic_quiz'=>['title'=>'Классический квиз','price'=>990],
        'chgk_v1'=>['title'=>'Битва знатоков','price'=>1990],
        'jeopardy_v1'=>['title'=>'Интеллектуальный батл','price'=>2490],
        'decision_price_v1'=>['title'=>'Управленческая игра "Ваш выбор"','price'=>990],
        'negotiation_duel_v1'=>['title'=>'Переговорные поединки','price'=>1490,'description'=>'Включает «Эффективный продажник», «Мастер переговоров», «Экспресс-раунд» и базовый бизнес-сценарий «Переговори другого».'],
        'persuade_school_v1'=>['title'=>'Переговори другого — для школьников','price'=>990,'description'=>'Отдельный сюжетный пакет для школьной аудитории. Механика «Переговори другого» не меняется.'],
        'persuade_student_v1'=>['title'=>'Переговори другого — для студентов','price'=>990,'description'=>'Отдельный сюжетный пакет для студенческой аудитории. Механика «Переговори другого» не меняется.'],
        'persuade_leader_v1'=>['title'=>'Переговори другого — для руководителей','price'=>990,'description'=>'Отдельный сюжетный пакет для руководителей. Механика «Переговори другого» не меняется.'],
        'persuade_family_v1'=>['title'=>'Переговори другого — Семейные ситуации','price'=>990,'description'=>'Отдельный сюжетный пакет для семейных ситуаций. Механика «Переговори другого» не меняется.'],
    ]);
}

/**
 * Determine the payment/access scope for organizer-facing checks.
 *
 * Tenant sites always use their tenant id. When an organizer is on the main
 * platform but owns a tenant site, payment status must still be read from that
 * tenant, not from legacy tenant_id=0 rows. This prevents one site's purchase
 * or an old platform-level grant from marking a game as "already paid" on a
 * different площадка.
 */
function ckm_quiz_pro_access_tenant_id(int $user_id, ?int $tenant_id = null): int {
    if ($tenant_id !== null) return $tenant_id;
    if (!function_exists('ckmqp_scope_id')) return -1;
    $scope = ckmqp_scope_id();
    if ($scope > 0) return $scope;
    if ($scope < 0) return -1;
    if ($user_id > 0 && function_exists('ckmqp_primary_site_for_user')) {
        $site = ckmqp_primary_site_for_user($user_id);
        if (is_array($site) && !empty($site['id'])) return (int)$site['id'];
    }
    return 0;
}

/** Build a strict SQL scope for access rows. Never falls back across tenants. */
function ckm_quiz_pro_access_scope_parts(int $user_id, ?int $tenant_id = null): ?array {
    if ($user_id <= 0 || !function_exists('ckmqp_scope_ready') || !ckmqp_scope_ready()) return null;
    $tenant_id = ckm_quiz_pro_access_tenant_id($user_id, $tenant_id);
    if ($tenant_id < 0) return null;
    if ($tenant_id > 0) {
        if (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenant_id, $user_id)) return null;
        return ['tenant_id'=>$tenant_id, 'sql'=>'tenant_id=%d', 'args'=>[$tenant_id]];
    }
    return ['tenant_id'=>0, 'sql'=>'tenant_id=0 AND user_id=%d', 'args'=>[$user_id]];
}

/**
 * Return the furthest active access expiry for one paid format.
 * Access is tenant-wide on tenant sites and user-specific on the main platform,
 * matching ckmqp_scope_access_clause(). Test-payment access participates too.
 */
function ckm_quiz_pro_game_access_info(int $user_id, string $format_key, ?int $tenant_id = null): array {
    $format_key=ckm_quiz_pro_access_format_key($format_key);
    $empty=['active'=>false,'format_key'=>$format_key,'expires_at'=>'','expires_ts'=>0,'seconds_remaining'=>0,'days_remaining'=>0,'remaining_text'=>'','display_text'=>'','expiring_soon'=>false,'warning_text'=>''];
    if ($user_id<=0 || !isset(ckm_quiz_pro_game_access_products()[$format_key])) return $empty;
    $scope = ckm_quiz_pro_access_scope_parts($user_id, $tenant_id);
    if (!$scope) return $empty;
    $scope_sql = (string)$scope['sql'];
    $scope_args = (array)$scope['args'];
    $tenant_id = (int)$scope['tenant_id'];

    global $wpdb;
    $candidates=[];
    $table=ckm_quiz_pro_table('game_access');
    $sql="SELECT MAX(expires_at) FROM {$table} WHERE {$scope_sql} AND format_key=%s AND status='active' AND expires_at>UTC_TIMESTAMP()";
    $args=array_merge($scope_args,[$format_key]);
    $main=$wpdb->get_var($wpdb->prepare($sql,...$args));
    if (is_string($main) && $main!=='') $candidates[]=$main;

    if (function_exists('ckmqp_test_enabled') && ckmqp_test_enabled() && function_exists('ckmqp_test_table')) {
        $test_table=ckmqp_test_table('access');
        $test_sql="SELECT MAX(expires_at) FROM {$test_table} WHERE {$scope_sql} AND format_key=%s AND expires_at>UTC_TIMESTAMP()";
        $test=$wpdb->get_var($wpdb->prepare($test_sql,...$args));
        if (is_string($test) && $test!=='') $candidates[]=$test;
    }

    if (!$candidates) return $empty;
    $best_ts=0;$best='';
    foreach ($candidates as $candidate) {
        $ts=strtotime($candidate.' UTC');
        if ($ts!==false && $ts>$best_ts) {$best_ts=$ts;$best=$candidate;}
    }
    if ($best_ts<=time()) return $empty;

    $seconds=max(0,$best_ts-time());
    $days=(int)ceil($seconds/DAY_IN_SECONDS);
    if ($seconds<DAY_IN_SECONDS) $remaining='осталось менее 1 дня';
    elseif ($days%10===1 && $days%100!==11) $remaining='остался '.$days.' день';
    elseif (in_array($days%10,[2,3,4],true) && !in_array($days%100,[12,13,14],true)) $remaining='осталось '.$days.' дня';
    else $remaining='осталось '.$days.' дней';
    $date=wp_date('d.m.Y',$best_ts);
    return [
        'active'=>true,
        'format_key'=>$format_key,
        'expires_at'=>$best,
        'expires_ts'=>$best_ts,
        'seconds_remaining'=>$seconds,
        'days_remaining'=>$days,
        'remaining_text'=>$remaining,
        'display_text'=>'Доступ активен · '.$remaining.' · до '.$date,
        'expiring_soon'=>$days<=7,
        'warning_text'=>$days<=7 ? 'Доступ скоро закончится' : '',
    ];
}


/**
 * Return the lifecycle state for one paid format: active, expired, or never purchased.
 * Uses the existing access tables only; no schema changes are required.
 */
function ckm_quiz_pro_game_access_lifecycle_info(int $user_id, string $format_key, ?int $tenant_id = null): array {
    $active=ckm_quiz_pro_game_access_info($user_id,$format_key,$tenant_id);
    if (!empty($active['active'])) {
        $active['state']='active';
        $active['expired']=false;
        $active['ever_had_access']=true;
        return $active;
    }

    $format_key=ckm_quiz_pro_access_format_key($format_key);
    $empty=array_merge($active,[
        'state'=>'none','expired'=>false,'ever_had_access'=>false,
        'expired_at'=>'','expired_ts'=>0,'expired_display_text'=>'',
    ]);
    if ($user_id<=0 || !isset(ckm_quiz_pro_game_access_products()[$format_key])) return $empty;
    $scope = ckm_quiz_pro_access_scope_parts($user_id, $tenant_id);
    if (!$scope) return $empty;
    $scope_sql = (string)$scope['sql'];
    $scope_args = (array)$scope['args'];
    $tenant_id = (int)$scope['tenant_id'];

    global $wpdb;
    $candidates=[];
    $table=ckm_quiz_pro_table('game_access');
    $sql="SELECT MAX(expires_at) FROM {$table} WHERE {$scope_sql} AND format_key=%s AND status='active' AND expires_at<=UTC_TIMESTAMP()";
    $args=array_merge($scope_args,[$format_key]);
    $main=$wpdb->get_var($wpdb->prepare($sql,...$args));
    if (is_string($main) && $main!=='') $candidates[]=$main;

    if (function_exists('ckmqp_test_enabled') && ckmqp_test_enabled() && function_exists('ckmqp_test_table')) {
        $test_table=ckmqp_test_table('access');
        $test_sql="SELECT MAX(expires_at) FROM {$test_table} WHERE {$scope_sql} AND format_key=%s AND expires_at<=UTC_TIMESTAMP()";
        $test=$wpdb->get_var($wpdb->prepare($test_sql,...$args));
        if (is_string($test) && $test!=='') $candidates[]=$test;
    }
    if (!$candidates) return $empty;

    $best='';$best_ts=0;
    foreach ($candidates as $candidate) {
        $ts=strtotime($candidate.' UTC');
        if ($ts!==false && $ts>$best_ts) {$best_ts=$ts;$best=$candidate;}
    }
    if ($best_ts<=0) return $empty;
    $date=wp_date('d.m.Y',$best_ts);
    return array_merge($empty,[
        'state'=>'expired','expired'=>true,'ever_had_access'=>true,
        'expired_at'=>$best,'expired_ts'=>$best_ts,
        'expired_display_text'=>'Доступ истёк · '.$date,
        'display_text'=>'Доступ истёк · '.$date,
    ]);
}

function ckm_quiz_pro_expired_products(int $uid, ?int $tenant_id = null): array {
    $expired=[];
    if ($uid<=0) return $expired;
    foreach (ckm_quiz_pro_game_access_products() as $key=>$product) {
        $info=ckm_quiz_pro_game_access_lifecycle_info($uid,$key,$tenant_id);
        if (!empty($info['expired'])) $expired[$key]=array_merge($product,['access_info'=>$info]);
    }
    return $expired;
}

function ckm_quiz_pro_access_renew_url(string $format_key): string {
    $format_key=ckm_quiz_pro_access_format_key($format_key);
    return ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$format_key,'renew'=>1]);
}

/**
 * Expiry used for a new paid period. Active time is preserved: a 30-day
 * renewal adds 30 days after the furthest active expiry instead of resetting
 * the customer to 30 days from today.
 */
function ckm_quiz_pro_access_extension_expires(int $user_id,string $format_key,int $days=30,?int $tenant_id=null,?int $paid_ts=null): string {
    $paid_ts=$paid_ts && $paid_ts>0 ? $paid_ts : time();
    $base=$paid_ts;
    $info=ckm_quiz_pro_game_access_info($user_id,$format_key,$tenant_id);
    if (!empty($info['active']) && (int)($info['expires_ts']??0)>$base) $base=(int)$info['expires_ts'];
    return gmdate('Y-m-d H:i:s',$base+max(1,$days)*DAY_IN_SECONDS);
}

function ckm_quiz_pro_game_access_display(int $user_id,string $format_key,?int $tenant_id=null): string {
    $info=ckm_quiz_pro_game_access_info($user_id,$format_key,$tenant_id);
    return !empty($info['active']) ? (string)$info['display_text'] : '';
}

function ckm_quiz_pro_has_game_access(int $user_id,string $format_key, ?int $tenant_id = null): bool {
    $format_key=ckm_quiz_pro_access_format_key($format_key);
    if (!isset(ckm_quiz_pro_game_access_products()[$format_key])) return false;
    $scope = ckm_quiz_pro_access_scope_parts($user_id, $tenant_id);
    if (!$scope) return false;
    if (function_exists('ckmqp_test_has_access') && ckmqp_test_has_access($user_id, $format_key, (int)$scope['tenant_id'])) return true;
    global $wpdb;
    $table=ckm_quiz_pro_table('game_access');
    $scope_sql=(string)$scope['sql'];
    $args=array_merge((array)$scope['args'],[$format_key]);
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE {$scope_sql} AND format_key=%s AND status='active' AND expires_at>UTC_TIMESTAMP()",
        ...$args
    ))>0;
}

function ckm_quiz_pro_game_access_guard(string $format_key): bool {
    $uid=get_current_user_id();
    if (!$uid || !ckm_quiz_pro_has_game_access($uid,$format_key)) {
        wp_die('Доступ к этой игре не активирован. Оформите доступ в каталоге игр.');
    }
    return true;
}

function ckm_quiz_pro_seed_game_access_products(): void {
    update_option('ckm_quiz_pro_game_access_products',ckm_quiz_pro_game_access_products(),false);
}
add_action('init','ckm_quiz_pro_seed_game_access_products');

/** Cabinet navigation becomes available only with an active purchased format. */
function ckm_quiz_pro_has_paid_cabinet_access(int $user_id, ?int $tenant_id = null): bool {
    if ($user_id <= 0) return false;
    foreach (array_keys(ckm_quiz_pro_game_access_products()) as $format_key) {
        if (ckm_quiz_pro_has_game_access($user_id, $format_key, $tenant_id)) return true;
    }
    return false;
}

/** Build an order exclusively from the server catalog, never a client price. */
function ckm_quiz_pro_payment_cart($selection) {
    if (is_string($selection)) $selection = $selection === '' ? [] : explode(',', $selection);
    if (!is_array($selection) || count($selection) > 20) return new WP_Error('invalid_cart', 'Некорректный список игр.');
    $products = ckm_quiz_pro_game_access_products();
    $selected = [];
    foreach ($selection as $key) {
        if (!is_string($key) || !isset($products[$key])) return new WP_Error('invalid_cart', 'В заказе есть неизвестная игра. Выберите игры заново.');
        $product=$products[$key];
        if(array_key_exists('orderable',$product) && !$product['orderable']){
            $uid=get_current_user_id();
            $hasAccess=$uid>0 && ckm_quiz_pro_can_access_format($uid,$key);
            $life=$uid>0 && function_exists('ckm_quiz_pro_game_access_lifecycle_info') ? ckm_quiz_pro_game_access_lifecycle_info($uid,$key) : [];
            if(!$hasAccess && empty($life['expired'])) return new WP_Error('game_not_for_sale','Эта готовая игра сейчас недоступна для новой покупки.');
        }
        $selected[$key] = true;
    }
    $cart = ['format_keys'=>[], 'items'=>[], 'total_minor'=>0, 'currency'=>'RUB'];
    foreach ($products as $key=>$product) {
        if (!isset($selected[$key])) continue;
        $amount = (int) $product['price'] * 100;
        $cart['format_keys'][] = $key;
        $cart['items'][] = ['format_key'=>$key, 'title'=>$product['title'], 'amount_minor'=>$amount, 'quantity'=>1, 'days'=>30];
        $cart['total_minor'] += $amount;
    }
    return $cart;
}

/** One entitlement key per format, shared by UI and server launch checks. */
function ckm_quiz_pro_access_format_key(string $format): string {
    $format=sanitize_key($format);
    $aliases=[
        'chgk'=>'chgk_v1','jeopardy'=>'jeopardy_v1','solution_price'=>'decision_price_v1','decision_price'=>'decision_price_v1',
        'negotiation_duel'=>'negotiation_duel_v1','negotiation'=>'negotiation_duel_v1',
        'persuade_me_v1'=>'negotiation_duel_v1','persuade_me'=>'negotiation_duel_v1',
        'persuade_school'=>'persuade_school_v1','persuade_school_grade'=>'persuade_school_grade_v1','persuade_student'=>'persuade_student_v1',
        'persuade_leader'=>'persuade_leader_v1','persuade_family'=>'persuade_family_v1'
    ];
    return $aliases[$format] ?? $format;
}
function ckm_quiz_pro_can_access_format(int $uid,string $format, ?int $tenant_id = null): bool {
    return ckm_quiz_pro_has_game_access($uid,ckm_quiz_pro_access_format_key($format),$tenant_id);
}
function ckm_quiz_pro_paid_products(int $uid, ?int $tenant_id = null): array {
    $paid=[];
    foreach (ckm_quiz_pro_game_access_products() as $key=>$product) {
        if (ckm_quiz_pro_can_access_format($uid,$key,$tenant_id)) $paid[$key]=$product;
    }
    return $paid;
}
function ckm_quiz_pro_quiz_access_product(array $row): string {
    $quizId=(int)($row['id'] ?? 0);
    if($quizId>0 && function_exists('ckm_quiz_pro_ready_game_product_for_quiz')){
        $readyProduct=ckm_quiz_pro_ready_game_product_for_quiz($quizId);
        if($readyProduct!=='') return $readyProduct;
    }
    $format=sanitize_key((string)($row['format_key'] ?? ''));
    if ($format!=='negotiation_duel') return ckm_quiz_pro_access_format_key($format);
    $settings=[];
    if (isset($row['format_settings_json'])) {
        $settings=function_exists('ckm_quiz_json_decode')
            ? ckm_quiz_json_decode((string)$row['format_settings_json'])
            : json_decode((string)$row['format_settings_json'],true);
        if(!is_array($settings)) $settings=[];
    }
    $mode=sanitize_key((string)($settings['negotiationMode'] ?? ''));
    if($mode!=='communicate') return 'negotiation_duel_v1';
    $variant=sanitize_key((string)($settings['persuadeMeVariant'] ?? ''));
    $map=[
        'school'=>'persuade_school_v1',
        'school_grade'=>'persuade_school_grade_v1',
        'student'=>'persuade_student_v1',
        'leader'=>'persuade_leader_v1',
        'family'=>'persuade_family_v1',
    ];
    if(isset($map[$variant])) return $map[$variant];

    $slug=sanitize_key((string)($row['slug'] ?? ''));
    $slugMap=[
        'demo-negotiation-communicate-school'=>'persuade_school_v1',
        'demo-negotiation-communicate-school-grade'=>'persuade_school_grade_v1',
        'demo-negotiation-communicate-student'=>'persuade_student_v1',
        'demo-negotiation-communicate-leader'=>'persuade_leader_v1',
        'demo-negotiation-communicate-family'=>'persuade_family_v1',
    ];
    return $slugMap[$slug] ?? 'negotiation_duel_v1';
}

function ckm_quiz_pro_allowed_rows(array $rows,int $uid,string $field='format_key', ?int $tenant_id = null): array {
    return array_values(array_filter($rows,static function ($row) use ($uid,$field,$tenant_id) {
        if(is_array($row) && (int)($row['created_by_user_id'] ?? 0)===$uid && empty($row['package_id'])){
            return ckm_quiz_pro_has_paid_cabinet_access($uid);
        }
        $key=(is_array($row) && ($field==='format_key') && (isset($row['format_settings_json']) || isset($row['slug'])))
            ? ckm_quiz_pro_quiz_access_product($row)
            : ckm_quiz_pro_access_format_key((string)($row[$field]??''));
        return ckm_quiz_pro_has_game_access($uid,$key,$tenant_id);
    }));
}
function ckm_quiz_pro_format_denied(): void {
    echo '<div class="ckm-card"><h2>Доступ к этой игре не оплачен</h2><p>Выберите её в разделе оплаты, чтобы открыть доступ.</p><a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'payment'])).'">Оплата игр</a></div>';
}

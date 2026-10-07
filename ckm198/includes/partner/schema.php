<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_PARTNER_SCHEMA_VERSION = '1.5.0';

function ckm_quiz_pro_partner_table(string $name): string {
    global $wpdb;
    $allowed = ['subscriptions', 'usage', 'grants', 'payment_settings', 'payment_orders', 'plan_orders', 'payment_methods'];
    if (!in_array($name, $allowed, true)) {
        throw new InvalidArgumentException('Unknown CKM Partner Rental table');
    }
    return $wpdb->prefix . 'ckmqp_partner_' . $name;
}

function ckm_quiz_pro_partner_install_schema(): void {
    if (get_option('ckm_quiz_pro_partner_schema_version', '') === CKM_QUIZ_PRO_PARTNER_SCHEMA_VERSION) return;

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $subscriptions = ckm_quiz_pro_partner_table('subscriptions');
    $usage = ckm_quiz_pro_partner_table('usage');
    $grants = ckm_quiz_pro_partner_table('grants');
    $paymentSettings = ckm_quiz_pro_partner_table('payment_settings');
    $paymentOrders = ckm_quiz_pro_partner_table('payment_orders');
    $planOrders = ckm_quiz_pro_partner_table('plan_orders');
    $paymentMethods = ckm_quiz_pro_partner_table('payment_methods');

    dbDelta("CREATE TABLE $subscriptions (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL,
        owner_user_id bigint unsigned NOT NULL DEFAULT 0,
        plan_key varchar(24) NOT NULL DEFAULT 'business',
        session_limit int unsigned NOT NULL DEFAULT 75,
        extra_sessions int unsigned NOT NULL DEFAULT 0,
        extra_period_key varchar(64) NOT NULL DEFAULT '',
        price_minor bigint unsigned NOT NULL DEFAULT 0,
        last_payment_ref varchar(96) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT 'active',
        started_at datetime NOT NULL,
        expires_at datetime NOT NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY tenant_id (tenant_id),
        KEY status_expiry (status,expires_at),
        KEY owner_user_id (owner_user_id)
    ) ENGINE=InnoDB $c;");

    dbDelta("CREATE TABLE $usage (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL DEFAULT 0,
        user_id bigint unsigned NOT NULL DEFAULT 0,
        game_id bigint unsigned NOT NULL,
        product_key varchar(64) NOT NULL DEFAULT '',
        usage_scope varchar(20) NOT NULL DEFAULT 'organizer',
        period_key varchar(64) NOT NULL DEFAULT '',
        counted_at datetime NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY game_id (game_id),
        KEY tenant_period (tenant_id,period_key),
        KEY user_period (user_id,period_key),
        KEY product_period (product_key,period_key),
        KEY usage_scope (usage_scope)
    ) ENGINE=InnoDB $c;");

    dbDelta("CREATE TABLE $grants (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        subscription_id bigint unsigned NOT NULL,
        tenant_id bigint unsigned NOT NULL,
        access_id bigint unsigned NOT NULL,
        format_key varchar(64) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY access_id (access_id),
        KEY subscription_id (subscription_id),
        KEY tenant_id (tenant_id)
    ) ENGINE=InnoDB $c;");


    dbDelta("CREATE TABLE $paymentSettings (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL,
        owner_user_id bigint unsigned NOT NULL DEFAULT 0,
        shop_id varchar(32) NOT NULL DEFAULT '',
        secret_cipher longtext NULL,
        secret_last4 varchar(8) NOT NULL DEFAULT '',
        mode varchar(12) NOT NULL DEFAULT 'test',
        vat_code tinyint unsigned NOT NULL DEFAULT 1,
        enabled tinyint unsigned NOT NULL DEFAULT 0,
        webhook_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        account_test tinyint unsigned NOT NULL DEFAULT 0,
        account_status varchar(24) NOT NULL DEFAULT '',
        fiscalization_enabled tinyint unsigned NOT NULL DEFAULT 0,
        last_checked_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY tenant_id (tenant_id),
        KEY owner_user_id (owner_user_id),
        KEY enabled (enabled)
    ) ENGINE=InnoDB $c;");

    dbDelta("CREATE TABLE $paymentOrders (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        order_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        public_token char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        tenant_id bigint unsigned NOT NULL,
        owner_user_id bigint unsigned NOT NULL DEFAULT 0,
        customer_user_id bigint unsigned NOT NULL DEFAULT 0,
        customer_name varchar(190) NOT NULL DEFAULT '',
        customer_login varchar(60) NOT NULL DEFAULT '',
        customer_email varchar(190) NOT NULL DEFAULT '',
        amount_minor bigint unsigned NOT NULL DEFAULT 0,
        currency char(3) NOT NULL DEFAULT 'RUB',
        description varchar(190) NOT NULL DEFAULT '',
        status varchar(24) NOT NULL DEFAULT 'created',
        access_status varchar(24) NOT NULL DEFAULT 'pending',
        provider varchar(32) NOT NULL DEFAULT 'yookassa',
        yookassa_payment_id varchar(80) NOT NULL DEFAULT '',
        tbank_payment_id varchar(80) NOT NULL DEFAULT '',
        confirmation_url text NULL,
        last_error text NULL,
        paid_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id),
        UNIQUE KEY public_token (public_token),
        KEY tenant_status (tenant_id,status),
        KEY customer_user_id (customer_user_id),
        KEY provider (provider),
        KEY yookassa_payment_id (yookassa_payment_id),
        KEY tbank_payment_id (tbank_payment_id)
    ) ENGINE=InnoDB $c;");

    dbDelta("CREATE TABLE $paymentMethods (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        tenant_id bigint unsigned NOT NULL,
        owner_user_id bigint unsigned NOT NULL DEFAULT 0,
        provider varchar(32) NOT NULL,
        enabled tinyint unsigned NOT NULL DEFAULT 0,
        is_default tinyint unsigned NOT NULL DEFAULT 0,
        credentials_cipher longtext NULL,
        credentials_hint varchar(64) NOT NULL DEFAULT '',
        mode varchar(12) NOT NULL DEFAULT 'test',
        account_status varchar(24) NOT NULL DEFAULT '',
        webhook_secret_cipher longtext NULL,
        last_checked_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY tenant_provider (tenant_id,provider),
        KEY tenant_enabled (tenant_id,enabled),
        KEY tenant_default (tenant_id,is_default)
    ) ENGINE=InnoDB $c;");


    dbDelta("CREATE TABLE $planOrders (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        order_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        tenant_id bigint unsigned NOT NULL,
        owner_user_id bigint unsigned NOT NULL DEFAULT 0,
        payer_user_id bigint unsigned NOT NULL DEFAULT 0,
        plan_key varchar(24) NOT NULL DEFAULT '',
        session_limit int unsigned NOT NULL DEFAULT 0,
        amount_minor bigint unsigned NOT NULL DEFAULT 0,
        currency char(3) NOT NULL DEFAULT 'RUB',
        status varchar(24) NOT NULL DEFAULT 'created',
        yookassa_payment_id varchar(80) NOT NULL DEFAULT '',
        confirmation_url text NULL,
        last_error text NULL,
        paid_at datetime NULL,
        activated_at datetime NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id),
        KEY tenant_status (tenant_id,status),
        KEY owner_user_id (owner_user_id),
        KEY payer_user_id (payer_user_id),
        KEY yookassa_payment_id (yookassa_payment_id)
    ) ENGINE=InnoDB $c;");

    if (get_option('ckmqp_organizer_monthly_session_limit', null) === null) {
        update_option('ckmqp_organizer_monthly_session_limit', 15, false);
    }
    if (get_option('ckmqp_partner_plan_settings', null) === null) {
        update_option('ckmqp_partner_plan_settings', [
            'start' => ['label' => 'Start', 'limit' => 30, 'price_rub' => 0],
            'business' => ['label' => 'Business', 'limit' => 75, 'price_rub' => 0],
            'pro' => ['label' => 'Pro', 'limit' => 150, 'price_rub' => 0],
        ], false);
    }

    update_option('ckm_quiz_pro_partner_schema_version', CKM_QUIZ_PRO_PARTNER_SCHEMA_VERSION, false);
}

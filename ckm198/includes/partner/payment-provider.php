<?php
if (!defined('ABSPATH')) exit;

/**
 * Universal payment-provider contract for partner-owned client payments.
 *
 * This layer deliberately wraps the existing YooKassa implementation first;
 * it does not change checkout behaviour. New providers (T-Bank, Sber, etc.)
 * can be added without spreading provider-specific conditionals through the
 * partner checkout, order or webhook code.
 */
interface CKM_Quiz_Pro_Partner_Payment_Provider_Interface {
    public function key(): string;
    public function label(): string;
    public function ready(int $tenantId): bool;
    public function save_settings(int $tenantId, int $ownerUserId, array $input): array|WP_Error;
    public function test_connection(int $tenantId): array|WP_Error;
    public function webhook_url(int $tenantId): string;
    public function create_order(int $tenantId, int $ownerUserId, array $input): array|WP_Error;
    public function create_payment(array $order): array|WP_Error;
    public function sync_order(array $order): array|WP_Error;
}

final class CKM_Quiz_Pro_Partner_YooKassa_Provider implements CKM_Quiz_Pro_Partner_Payment_Provider_Interface {
    public function key(): string { return 'yookassa'; }
    public function label(): string { return 'ЮKassa'; }

    public function ready(int $tenantId): bool {
        return function_exists('ckm_quiz_pro_partner_yk_ready')
            && ckm_quiz_pro_partner_yk_ready($tenantId);
    }

    public function save_settings(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
        return ckm_quiz_pro_partner_yk_save_settings($tenantId, $ownerUserId, $input);
    }

    public function test_connection(int $tenantId): array|WP_Error {
        return ckm_quiz_pro_partner_yk_test_connection($tenantId);
    }

    public function webhook_url(int $tenantId): string {
        return ckm_quiz_pro_partner_yk_webhook_url($tenantId);
    }

    public function create_order(int $tenantId, int $ownerUserId, array $input): array|WP_Error {
        return ckm_quiz_pro_partner_yk_create_order($tenantId, $ownerUserId, $input);
    }

    public function create_payment(array $order): array|WP_Error {
        return ckm_quiz_pro_partner_yk_create_payment($order);
    }

    public function sync_order(array $order): array|WP_Error {
        return ckm_quiz_pro_partner_yk_sync_order($order);
    }
}

function ckm_quiz_pro_partner_payment_providers(): array {
    static $providers = null;
    if ($providers !== null) return $providers;

    $providers = [
        'yookassa' => new CKM_Quiz_Pro_Partner_YooKassa_Provider(),
        'tbank' => new CKM_Quiz_Pro_Partner_TBank_Provider(),
    ];

    return $providers;
}

function ckm_quiz_pro_partner_payment_provider(string $key): ?CKM_Quiz_Pro_Partner_Payment_Provider_Interface {
    $key = sanitize_key($key);
    $providers = ckm_quiz_pro_partner_payment_providers();
    return $providers[$key] ?? null;
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * REAL PAYMENT ADAPTER LAYER 0.3.20
 * Bridges external CKM payment callbacks to game access.
 */

function ckm_quiz_pro_normalize_payment_format($payload): string {
    $format = $payload['format_key'] ?? ($payload['product_code'] ?? ($payload['product_id'] ?? ''));
    return sanitize_key((string)$format);
}

function ckm_quiz_pro_real_payment_success($payload): bool {
    if (!is_array($payload)) return false;

    $user_id = (int)($payload['user_id'] ?? 0);
    $format = ckm_quiz_pro_normalize_payment_format($payload);
    $tenant = array_key_exists('tenant_id',$payload) ? (int)$payload['tenant_id'] : null;

    if (!$user_id || !$format) return false;

    if (function_exists('ckm_quiz_pro_payment_create_access')) {
        $ref=function_exists('ckm_quiz_pro_payment_grant_ref') ? ckm_quiz_pro_payment_grant_ref($payload) : '';
        return ckm_quiz_pro_payment_create_access($user_id, $format, 30, $tenant, $ref);
    }

    return false;
}

add_action('ckm_yookassa_payment_success', 'ckm_quiz_pro_real_payment_success');
add_action('ckm_payment_completed', 'ckm_quiz_pro_real_payment_success');
add_action('ckm_order_paid', 'ckm_quiz_pro_real_payment_success');

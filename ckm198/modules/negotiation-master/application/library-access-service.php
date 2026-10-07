<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class LibraryAccessDeniedException extends \RuntimeException {}

/** Reuses the platform entitlement boundary without introducing a payment provider. */
final class LibraryAccessService {
    public function get(int $libraryId): array {
        global $wpdb;
        $context = Access::context();
        $table = Schema::table('libraries');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $libraryId), ARRAY_A);
        if (!$row || ($row['tenant_id'] !== null && !$context['admin'] && (int)$row['tenant_id'] !== (int)$context['tenant_id'])) {
            throw new \RuntimeException('Библиотека недоступна.');
        }
        return $row;
    }

    public function state(array $library): array {
        $context = Access::context();
        $status = (string)($library['status'] ?? 'draft');
        $visibility = (string)($library['visibility'] ?? 'public');
        $accessType = (string)($library['access_type'] ?? 'free');
        $productKey = sanitize_key((string)($library['product_key'] ?? ''));
        if ($status !== 'published') {
            return ['allowed'=>$context['admin'], 'state'=>'coming_soon', 'product_key'=>$productKey, 'purchase_url'=>null];
        }
        if ($visibility === 'private' && !$context['admin']) {
            return ['allowed'=>false, 'state'=>'private', 'product_key'=>$productKey, 'purchase_url'=>null];
        }
        if ($context['admin'] || $accessType === 'free') {
            return ['allowed'=>true, 'state'=>$context['admin'] && $accessType !== 'free' ? 'admin' : 'free', 'product_key'=>$productKey, 'purchase_url'=>null];
        }
        $allowed = false;
        if ($productKey !== '' && function_exists('ckm_quiz_pro_can_access_format')) {
            $uid = function_exists('ckm_quiz_pro_effective_organizer_user_id') ? (int)ckm_quiz_pro_effective_organizer_user_id() : (int)$context['user_id'];
            $allowed = ckm_quiz_pro_can_access_format($uid, $productKey, (int)$context['tenant_id']);
        }
        /** Allows an existing external licensing integration to grant the future library key without changing this module. */
        $allowed = (bool) apply_filters('ckm_neg_library_entitled', $allowed, $library, $context);
        return ['allowed'=>$allowed, 'state'=>$allowed ? 'owned' : 'locked', 'product_key'=>$productKey, 'purchase_url'=>$allowed ? null : $this->purchaseUrl($library)];
    }

    public function assertScenarioAccess(array $scenario): void {
        $libraryId = (int)($scenario['library_id'] ?? 0);
        if ($libraryId <= 0) { return; }
        $state = $this->state($this->get($libraryId));
        if (!$state['allowed']) { throw new LibraryAccessDeniedException('Для этого сценария требуется доступ к библиотеке.'); }
    }

    public function purchaseUrl(array $library): ?string {
        $productKey = sanitize_key((string)($library['product_key'] ?? ''));
        $url = apply_filters('ckm_neg_library_purchase_url', '', $library);
        if (is_string($url) && $url !== '') { return esc_url_raw($url); }
        if ($productKey === '' || !function_exists('ckm_quiz_pro_game_access_products') || !function_exists('ckm_quiz_pro_organizer_url')) { return null; }
        $products = ckm_quiz_pro_game_access_products();
        if (!isset($products[$productKey])) { return null; }
        return ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$productKey]);
    }
}

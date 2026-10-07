<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class Access {
    private static function assignedParticipantForTenant(int $tenantId, int $userId): bool {
        if ($userId <= 0 || version_compare((string)get_option('ckm_neg_db_version','0'), '1.8.0', '<')) { return false; }
        global $wpdb;
        try {
            $a = Schema::table('assignments'); $p = Schema::table('assignment_participants');
            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT p.id FROM `$p` p INNER JOIN `$a` a ON a.id=p.assignment_id WHERE p.user_id=%d AND a.tenant_id=%d AND a.status IN ('active','closed') LIMIT 1",
                $userId, $tenantId
            ));
        } catch (\Throwable) { return false; }
    }

    private static function assignedSessionAllowed(array $context, array $session): bool {
        $assignmentId = (int)($session['assignment_id'] ?? 0);
        if ($assignmentId <= 0 || ($session['session_kind'] ?? '') !== 'assignment') { return false; }
        global $wpdb;
        try {
            $a = Schema::table('assignments'); $p = Schema::table('assignment_participants');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT p.team_key,a.assignment_mode FROM `$p` p INNER JOIN `$a` a ON a.id=p.assignment_id WHERE p.assignment_id=%d AND p.user_id=%d AND a.tenant_id=%d LIMIT 1",
                $assignmentId, (int)$context['user_id'], (int)$context['tenant_id']
            ), ARRAY_A);
            if (!$row) { return false; }
            if ((string)$session['participant_key'] === 'user:' . (int)$context['user_id']) { return true; }
            if ((string)($row['assignment_mode'] ?? '') === 'team_shared' && !empty($row['team_key'])) {
                return hash_equals('assignment-team:' . $assignmentId . ':' . (string)$row['team_key'], (string)$session['participant_key']);
            }
        } catch (\Throwable) {}
        return false;
    }

    private static function canUseBuilderForOwnDraft(array $context, array $scenario): bool {
        if (!empty($context['admin'])) { return true; }
        if ($scenario['tenant_id'] === null || (int)$scenario['tenant_id'] !== (int)$context['tenant_id'] || (int)($scenario['created_by'] ?? 0) !== (int)$context['user_id']) { return false; }
        if (!function_exists('ckm_quiz_pro_can_use_front_constructor') || !ckm_quiz_pro_can_use_front_constructor()) { return false; }
        if (function_exists('ckm_quiz_pro_can_access_format')) {
            $uid = function_exists('ckm_quiz_pro_effective_organizer_user_id') ? (int)ckm_quiz_pro_effective_organizer_user_id() : (int)$context['user_id'];
            if (!ckm_quiz_pro_can_access_format($uid, 'negotiation_duel_v1')) { return false; }
        }
        return true;
    }
    /** No tenant/user/participant identity is accepted from a request payload. */
    public static function context(): array {
        $user = (int) get_current_user_id();
        $tenant = function_exists('ckmqp_scope_id') ? ckmqp_scope_id() : -1;
        if ($user <= 0 || $tenant < 0) { throw new \RuntimeException('NEG-CORE access denied.'); }
        $admin = current_user_can('manage_options');
        if ($tenant > 0 && !$admin && (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenant, $user)) && !self::assignedParticipantForTenant($tenant, $user)) {
            throw new \RuntimeException('NEG-CORE tenant access denied.');
        }
        return ['tenant_id' => $tenant, 'user_id' => $user, 'participant_key' => 'user:' . $user, 'admin' => $admin];
    }
    public static function admin(): array {
        $context = self::context();
        if (!$context['admin']) { throw new \RuntimeException('NEG-CORE administrator required.'); }
        return $context;
    }
    public static function session(int $id): array {
        global $wpdb;
        $context = self::context();
        $table = Schema::table('sessions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
        if (!$row) { throw new \RuntimeException('NEG-CORE session unavailable.'); }
        if (!$context['admin']) {
            $sameTenant = (int)$row['tenant_id'] === (int)$context['tenant_id'];
            $direct = $sameTenant && (string)$row['participant_key'] === (string)$context['participant_key'];
            $assigned = $sameTenant && self::assignedSessionAllowed($context, $row);
            if (!$direct && !$assigned) { throw new \RuntimeException('NEG-CORE session unavailable.'); }
        }
        return $row;
    }
    public static function scenario(int $id): array {
        global $wpdb;
        $context = self::context();
        $table = Schema::table('scenarios');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
        if (!$row) { throw new \RuntimeException('NEG-CORE scenario unavailable.'); }
        if (!$context['admin']) {
            $publishedVisible = in_array((string)$row['status'], ['published','archived'], true) && ($row['tenant_id'] === null || (int)$row['tenant_id'] === (int)$context['tenant_id']);
            $ownDraftVisible = (string)$row['status'] === 'draft' && self::canUseBuilderForOwnDraft($context, $row);
            if (!$publishedVisible && !$ownDraftVisible) { throw new \RuntimeException('NEG-CORE scenario unavailable.'); }
        }
        return $row;
    }
    public static function version(int $id): array {
        global $wpdb;
        $context = self::context();
        $table = Schema::table('scenario_versions');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
        if (!$row) { throw new \RuntimeException('NEG-CORE version unavailable.'); }
        $scenario = self::scenario((int) $row['scenario_id']);
        if (!$context['admin'] && $row['status'] !== 'published') {
            if ((string)$row['status'] !== 'draft' || !self::canUseBuilderForOwnDraft($context, $scenario)) { throw new \RuntimeException('NEG-CORE version unavailable.'); }
        }
        return $row;
    }
}

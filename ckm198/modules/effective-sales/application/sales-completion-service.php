<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

use CKM\NegotiationMaster\Access;
use CKM\NegotiationMaster\Schema;
use CKM\NegotiationMaster\StateConflictException;
use CKM\NegotiationMaster\SessionCompletedException;

final class SalesCompletionService {
    private static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($v) ? $v : []; }
        catch (\Throwable) { return []; }
    }

    public function complete(int $sessionId, ?int $expectedRevision = null): array {
        global $wpdb;
        SalesDomain::versionForSession($sessionId);
        $session = Access::session($sessionId);
        if (($session['status'] ?? '') !== 'in_progress') { throw new SessionCompletedException('Тренировка уже завершена.'); }
        if (($session['processing_status'] ?? 'idle') !== 'idle') { throw new StateConflictException('Дождитесь ответа клиента перед завершением тренировки.'); }

        $table = Schema::table('sessions');
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Не удалось начать завершение тренировки.'); }
        try {
            $locked = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d FOR UPDATE", $sessionId), ARRAY_A);
            if (!$locked) { throw new \RuntimeException('Тренировка недоступна.'); }
            if (($locked['status'] ?? '') !== 'in_progress') { throw new SessionCompletedException('Тренировка уже завершена.'); }
            if (($locked['processing_status'] ?? 'idle') !== 'idle') { throw new StateConflictException('Дождитесь ответа клиента перед завершением тренировки.'); }
            if ($expectedRevision !== null && (int)$locked['state_revision'] !== $expectedRevision) { throw new StateConflictException('Состояние диалога изменилось. Обновите экран и повторите завершение.'); }

            $now = current_time('mysql', true);
            $state = self::decode($locked['state_json'] ?? null);
            $state['completion'] = [
                'type'=>'sales',
                'outcome'=>'pending_evaluation',
                'completed_at'=>$now,
            ];
            $ok = $wpdb->query($wpdb->prepare(
                "UPDATE `$table` SET status='completed_sales',processing_status='idle',processing_lock_token=NULL,processing_lock_expires_at=NULL,active_client_id=NULL,writer_lock_expires_at=NULL,completed_at=%s,final_agreement_id=NULL,evaluation_status='pending',state_json=%s,state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'",
                $now, wp_json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $now, $now, $sessionId
            ));
            if ($ok !== 1) { throw new StateConflictException('Не удалось завершить тренировку: состояние уже изменилось.'); }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Не удалось сохранить завершение тренировки.'); }
            return ['status'=>'completed_sales','completed_at'=>$now];
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}

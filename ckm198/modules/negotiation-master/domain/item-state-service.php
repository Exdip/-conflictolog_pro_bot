<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }
if (!class_exists(ReopenPolicy::class)) { require_once __DIR__ . '/reopen-policy.php'; }

final class ItemStateService {
    private static function decode(?string $json): array {
        if ($json === null || $json === '') { return []; }
        try { $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (\Throwable) { return []; }
    }
    private static function normalize(mixed $value, array $item): mixed {
        if ($value === null) { return null; }
        $type = (string) ($item['value_type'] ?? '');
        $unit = (string) ($item['unit'] ?? '');
        if (in_array($type, ['integer','int','money','percent','number','decimal'], true) || in_array($unit, ['RUB','percent','months','days'], true)) {
            if (!is_numeric($value)) { throw new \UnexpectedValueException('Negotiation item requires a numeric value.'); }
            $number = (float) $value;
            if ($number < 0) { throw new \UnexpectedValueException('Negotiation item value is invalid.'); }
            if ($unit === 'percent' && $number > 100) { throw new \UnexpectedValueException('Percent value is invalid.'); }
            return abs($number - round($number)) < 0.000001 ? (int) round($number) : $number;
        }
        if (in_array($type, ['bool','boolean'], true)) { return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false; }
        if (is_scalar($value)) { return trim((string) $value); }
        throw new \UnexpectedValueException('Negotiation item value shape is invalid.');
    }
    private static function same(mixed $a, mixed $b): bool { return wp_json_encode($a) === wp_json_encode($b); }
    private static function other(string $actor): string { return $actor === 'player' ? 'opponent' : 'player'; }
    private static function reopenReasonType(array $update): string {
        $type = (string)($update['reopen_reason_type'] ?? $update['reason_type'] ?? 'none');
        return ReopenPolicy::validReasonType($type) ? $type : 'none';
    }
    private static function archiveAgreement(array &$current, array $message): mixed {
        $old = $current['agreed']['value'] ?? null;
        if (isset($current['agreed']) && is_array($current['agreed'])) {
            if (!isset($current['agreement_history']) || !is_array($current['agreement_history'])) { $current['agreement_history'] = []; }
            $history = $current['agreed'];
            $history['reopened_by'] = (string)($message['actor'] ?? '');
            $history['reopened_message_id'] = (int)($message['id'] ?? 0);
            $current['agreement_history'][] = $history;
            if (count($current['agreement_history']) > 12) { $current['agreement_history'] = array_slice($current['agreement_history'], -12); }
            $current['previous_agreed'] = $current['agreed'];
            unset($current['agreed']);
        }
        unset($current['acceptance_candidate'], $current['rejected_by']);
        return $old;
    }
    private static function requestPayload(string $actor, array $message, array $update, array $item): array {
        $value = array_key_exists('value', $update) && $update['value'] !== null ? self::normalize($update['value'], $item) : null;
        return [
            'actor' => $actor,
            'message_id' => (int)($message['id'] ?? 0),
            'reason_type' => self::reopenReasonType($update),
            'reason' => ReopenPolicy::sanitizeReason($update['reopen_reason'] ?? $update['reason'] ?? ''),
            'value' => $value,
            'bundle_key' => preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', (string)($update['bundle_key'] ?? '')) ? (string)$update['bundle_key'] : '',
        ];
    }
    private static function priority(array $update): int {
        return match ((string)($update['action'] ?? '')) {
            'reopen_confirm','reopen_reject' => 0,
            'reopen_request' => 1,
            'discussed' => 2,
            'proposed' => 3,
            'acceptance_candidate' => 4,
            'rejected' => 5,
            default => 9,
        };
    }

    public function apply(int $sessionId, array $message, array $updates): array {
        global $wpdb;
        $session = Access::session($sessionId);
        $itemsTable = Schema::table('items'); $stateTable = Schema::table('item_state');
        $items = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$itemsTable` WHERE scenario_version_id=%d", (int)$session['scenario_version_id']), ARRAY_A);
        $map = []; foreach ($items as $item) { $map[(string)$item['code']] = $item; }
        $updates = array_values(array_filter($updates, 'is_array'));
        usort($updates, static fn(array $a, array $b): int => self::priority($a) <=> self::priority($b));
        $changed = false; $applied = [];
        foreach ($updates as $update) {
            if (!is_array($update)) { continue; }
            $code = (string) ($update['item_code'] ?? ''); if (!isset($map[$code])) { continue; }
            $item = $map[$code];
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$stateTable` WHERE session_id=%d AND item_id=%d", $sessionId, (int)$item['id']), ARRAY_A);
            if (!$row) { continue; }
            $current = self::decode($row['current_value_json'] ?? null);
            $before = $current;
            $action = (string) ($update['action'] ?? ''); $actor = (string) $message['actor']; $other = self::other($actor);
            $oldActorOffer = $current['offers'][$actor]['value'] ?? null;
            $oldOtherOffer = $current['offers'][$other]['value'] ?? null;
            $newStatus = (string) $row['status']; $newProposedBy = (string) $row['proposed_by']; $newBundle = (string) $row['bundle_key'];
            $resolvedValue = null; $reopenResult = ''; $reopenReasonType = self::reopenReasonType($update); $reopenReason = ReopenPolicy::sanitizeReason($update['reopen_reason'] ?? $update['reason'] ?? '');
            $policy = ReopenPolicy::normalize($item['reopen_policy'] ?? 'with_reason');
            $agreementReached = false; $previousAgreed = $current['agreed']['value'] ?? null;

            if ($action === 'reopen_request') {
                if (!isset($current['agreed']['value']) && $newStatus !== 'reopen_requested') { continue; }
                $request = self::requestPayload($actor, $message, $update, $item);
                // Repeating the already agreed value is confirmation, not a
                // request to reopen the condition. It must never manufacture a
                // reopen_requested state. Also heal legacy same-value requests.
                if (isset($current['agreed']['value']) && $request['value'] !== null && self::same($request['value'], $current['agreed']['value'])) {
                    $pending = is_array($current['reopen_request'] ?? null) ? $current['reopen_request'] : null;
                    if ($newStatus === 'reopen_requested' && $pending && ($pending['value'] ?? null) !== null && self::same($pending['value'], $current['agreed']['value'])) {
                        unset($current['reopen_request']);
                        $newStatus = 'agreed';
                        $reopenResult = 'same_value_confirmation';
                    }
                } else {
                $decision = ReopenPolicy::decision($policy, $request['reason_type']);
                if ($decision === 'deny') {
                    $reopenResult = 'denied';
                } elseif ($decision === 'request') {
                    if (isset($current['reopen_request']) && (string)($current['reopen_request']['actor'] ?? '') !== $actor) { continue; }
                    $current['reopen_request'] = $request;
                    $newStatus = 'reopen_requested';
                    $reopenResult = 'requested';
                } else {
                    $previousAgreed = self::archiveAgreement($current, $message);
                    unset($current['reopen_request']);
                    if ($request['value'] !== null) {
                        $current['offers'][$actor] = ['value'=>$request['value'],'message_id'=>(int)$message['id'],'bundle_key'=>$request['bundle_key']];
                        $newProposedBy = $actor; $newBundle = $request['bundle_key']; $resolvedValue = $request['value'];
                    }
                    $newStatus = 'reopened';
                    $reopenResult = 'reopened';
                }
                }
            } elseif ($action === 'reopen_confirm') {
                $request = is_array($current['reopen_request'] ?? null) ? $current['reopen_request'] : null;
                if (!$request || (string)($request['actor'] ?? '') === $actor || $newStatus !== 'reopen_requested') { continue; }
                $previousAgreed = self::archiveAgreement($current, $message);
                $requestActor = (string)$request['actor'];
                if (array_key_exists('value', $request) && $request['value'] !== null) {
                    $current['offers'][$requestActor] = ['value'=>$request['value'],'message_id'=>(int)($request['message_id'] ?? 0),'bundle_key'=>(string)($request['bundle_key'] ?? '')];
                    $newProposedBy = $requestActor; $newBundle = (string)($request['bundle_key'] ?? ''); $resolvedValue = $request['value'];
                }
                $reopenReasonType = (string)($request['reason_type'] ?? 'none'); $reopenReason = (string)($request['reason'] ?? '');
                unset($current['reopen_request']);
                $newStatus = 'reopened'; $reopenResult = 'reopened';
            } elseif ($action === 'reopen_reject') {
                $request = is_array($current['reopen_request'] ?? null) ? $current['reopen_request'] : null;
                if (!$request || (string)($request['actor'] ?? '') === $actor || $newStatus !== 'reopen_requested') { continue; }
                $reopenReasonType = (string)($request['reason_type'] ?? 'none'); $reopenReason = (string)($request['reason'] ?? '');
                unset($current['reopen_request']);
                $newStatus = isset($current['agreed']['value']) ? 'agreed' : 'discussing';
                $reopenResult = 'rejected';
            } elseif ($action === 'discussed') {
                if (!in_array($newStatus, ['agreed','reopen_requested'], true) && $newStatus === 'not_discussed') { $newStatus = 'discussing'; }
            } elseif ($action === 'proposed') {
                $resolvedValue = self::normalize($update['value'] ?? null, $item);
                if ($resolvedValue === null) { continue; }
                $bundle = preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', (string)($update['bundle_key'] ?? '')) ? (string)$update['bundle_key'] : '';
                if (isset($current['agreed']['value']) && self::same($resolvedValue, $current['agreed']['value'])) {
                    // A verbatim repeat of an agreed condition is not a new offer.
                    // Keep the agreement intact and clear only a legacy same-value
                    // reopen request created by older builds.
                    $pending = is_array($current['reopen_request'] ?? null) ? $current['reopen_request'] : null;
                    if ($newStatus === 'reopen_requested' && $pending && ($pending['value'] ?? null) !== null && self::same($pending['value'], $current['agreed']['value'])) {
                        unset($current['reopen_request']);
                        $newStatus = 'agreed';
                        $reopenResult = 'same_value_confirmation';
                    }
                } elseif ($newStatus === 'agreed' && isset($current['agreed']['value'])) {
                    $decision = ReopenPolicy::decision($policy, $reopenReasonType);
                    if ($decision === 'deny') {
                        $reopenResult = 'denied';
                    } elseif ($decision === 'request') {
                        $current['reopen_request'] = ['actor'=>$actor,'message_id'=>(int)$message['id'],'reason_type'=>$reopenReasonType,'reason'=>$reopenReason,'value'=>$resolvedValue,'bundle_key'=>$bundle];
                        $newStatus = 'reopen_requested'; $reopenResult = 'requested';
                    } else {
                        $previousAgreed = self::archiveAgreement($current, $message);
                        $current['offers'][$actor] = ['value'=>$resolvedValue,'message_id'=>(int)$message['id'],'bundle_key'=>$bundle];
                        $newStatus = 'reopened'; $newProposedBy = $actor; $newBundle = $bundle; $reopenResult = 'reopened';
                    }
                } elseif ($newStatus === 'reopen_requested') {
                    $request = is_array($current['reopen_request'] ?? null) ? $current['reopen_request'] : null;
                    if ($request && (string)($request['actor'] ?? '') === $actor) {
                        $current['reopen_request']['value'] = $resolvedValue;
                        $current['reopen_request']['bundle_key'] = $bundle;
                        if ($reopenReasonType !== 'none') { $current['reopen_request']['reason_type'] = $reopenReasonType; }
                        if ($reopenReason !== '') { $current['reopen_request']['reason'] = $reopenReason; }
                        $reopenResult = 'requested';
                    } else { continue; }
                } else {
                    $newBundle = $bundle;
                    $current['offers'][$actor] = ['value'=>$resolvedValue,'message_id'=>(int)$message['id'],'bundle_key'=>$newBundle];
                    unset($current['acceptance_candidate'], $current['rejected_by']);
                    $newStatus = $newStatus === 'reopened' ? 'reopened' : 'proposed'; $newProposedBy = $actor;
                }
            } elseif ($action === 'acceptance_candidate') {
                if (in_array($newStatus, ['agreed','reopen_requested'], true)) { continue; }
                if (!isset($current['offers'][$other]['value'])) { continue; }
                $resolvedValue = ($update['value'] ?? null) === null ? $current['offers'][$other]['value'] : self::normalize($update['value'], $item);
                if (!self::same($resolvedValue, $current['offers'][$other]['value'])) { continue; }
                $current['agreed'] = ['value'=>$resolvedValue,'confirmed_by'=>[$other,$actor],'offer_message_id'=>(int)($current['offers'][$other]['message_id'] ?? 0),'confirmation_message_id'=>(int)$message['id']];
                unset($current['acceptance_candidate'], $current['rejected_by'], $current['reopen_request']);
                $newStatus = 'agreed'; $newProposedBy = ''; $newBundle = (string)($current['offers'][$other]['bundle_key'] ?? $newBundle); $agreementReached = true;
            } elseif ($action === 'rejected') {
                if ($newStatus === 'agreed') { continue; }
                $current['rejected_by'] = ['actor'=>$actor,'message_id'=>(int)$message['id']];
                $newStatus = 'rejected';
            } else { continue; }

            $json = $current ? wp_json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            $rowChanged = $newStatus !== (string)$row['status'] || $newProposedBy !== (string)$row['proposed_by'] || $newBundle !== (string)$row['bundle_key'] || !self::same($before, $current);
            if ($rowChanged) {
                $ok = $wpdb->query($wpdb->prepare(
                    "UPDATE `$stateTable` SET status=%s,current_value_json=%s,proposed_by=%s,bundle_key=%s,updated_at=%s WHERE id=%d",
                    $newStatus, $json, $newProposedBy, $newBundle, current_time('mysql', true), (int)$row['id']
                ));
                if ($ok === false) { throw new \RuntimeException('Unable to update negotiation item state.'); }
                $changed = true;
            }
            $applied[] = [
                'item_id'=>(int)$item['id'],'item_code'=>$code,'title'=>(string)$item['title'],'action'=>$action,'actor'=>$actor,
                'value'=>$resolvedValue,'bundle_key'=>$newBundle,'previous_actor_value'=>$oldActorOffer,'previous_other_value'=>$oldOtherOffer,
                'player_preference_direction'=>(string)$item['player_preference_direction'],'opponent_preference_direction'=>(string)$item['opponent_preference_direction'],
                'reopen_policy'=>$policy,'reopen_result'=>$reopenResult,'reopen_reason_type'=>$reopenReasonType,'reopen_reason'=>$reopenReason,
                'previous_agreed_value'=>$previousAgreed,'agreement_reached'=>$agreementReached,'state_status'=>$newStatus,'changed'=>$rowChanged,
            ];
        }
        return ['changed'=>$changed,'applied'=>$applied];
    }
}

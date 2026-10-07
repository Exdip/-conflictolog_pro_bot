<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }
if (!class_exists(ReopenPolicy::class)) { require_once dirname(__DIR__, 2) . '/domain/reopen-policy.php'; }

final class ArbiterValidator {
    public const EVENT_TYPES = [
        'question_asked','interest_probe','constraint_probe','alternative_probe','position_stated','argument_made',
        'offer_made','counteroffer_made','package_offer_made','concession_made','conditional_concession','boundary_stated',
        'red_line_candidate','item_discussed','item_proposed','item_acceptance_candidate','item_rejected',
        'interest_discovered','constraint_discovered','alternative_discovered','ultimatum','personal_attack','deescalation',
        'walkaway_warning','walkaway_candidate','acknowledgement','willing_to_consider','intention_expressed',
        'reopen_requested','item_reopened','reopen_rejected','unjustified_reopen_attempt','justified_reopen','strategic_repackaging','agreement_backtracking','item_agreed'
    ];
    public const DEAL_ACTIONS = ['discussed','proposed','acceptance_candidate','rejected','reopen_request','reopen_confirm','reopen_reject'];
    public const RULE_SIGNALS = ['red_line_candidate','dependency_candidate','contradiction_candidate','walkaway_candidate'];
    private const SEMANTIC_TYPES = ['question','position','interest','constraint','argument','offer','condition','acknowledgement','willingness','intention','commitment','responsibility'];

    private static function confidence(mixed $value, float $default = 0.75): float {
        $number = is_numeric($value) ? (float) $value : $default;
        // Some model/provider combinations return confidence on a 0-100 scale.
        // Normalize that representation instead of rejecting an otherwise valid analysis.
        if ($number > 1 && $number <= 100) { $number /= 100; }
        if ($number < 0 || $number > 1) { throw new \UnexpectedValueException('Arbiter confidence is invalid.'); }
        return round($number, 5);
    }
    private static function text(mixed $value, int $max = 600): string {
        $text = trim(strip_tags((string) $value));
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $max, 'UTF-8'); }
        return substr($text, 0, $max);
    }
    private static function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
    private static function hasAcceptanceCue(string $text): bool {
        $lower = self::lower($text);
        foreach (['согласен','согласны','принимаю','принимаем','устраивает','подтверждаю','подтверждаем','договорились','согласовано'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }
    private static function isOnlyWillingness(string $text): bool {
        $lower = self::lower($text);
        foreach (['готов рассмотреть','готовы рассмотреть','можно рассмотреть','готов обсуждать','готовы обсуждать','можно обсуждать'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }


    private static function hasReopenConfirmCue(string $text): bool {
        $lower = self::lower($text);
        foreach (['согласен пересмотреть','согласны пересмотреть','готов пересмотреть','готовы пересмотреть','давайте пересмотрим','можем пересмотреть','пересмотрим','согласен вернуться','согласны вернуться','готов вернуться','готовы вернуться','давайте вернёмся','давайте вернемся','можем вернуться','вернёмся к условию','вернемся к условию','да, пересмотрим'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }
    private static function hasReopenRejectCue(string $text): bool {
        $lower = self::lower($text);
        foreach (['оставим как есть','оставляем как есть','не пересматриваем','не будем пересматривать','не согласен пересматривать','не согласны пересматривать','условие остаётся','условие остается','оставим прежнее условие','оставляем прежнее условие','пересматривать не будем'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }

    public function validate(array $payload, array $context): array {
        $target = $context['target_message'] ?? [];
        // The analyzed target is authoritative server context. The model's message envelope
        // is advisory metadata and must not be able to redirect or block state processing.
        // This also tolerates providers that echo the schema example message_id=0.
        $actor = (string) $target['actor'];
        $targetText = (string) ($target['content'] ?? '');
        $itemMap = []; foreach ((array) ($context['items'] ?? []) as $item) { $itemMap[(string)$item['code']] = $item; }
        $factMap = []; foreach ((array) ($context['hidden_facts'] ?? []) as $fact) { $factMap[(string)$fact['code']] = $fact; }

        $semantic = [];
        foreach (array_slice(is_array($payload['semantic_units'] ?? null) ? $payload['semantic_units'] : [], 0, 20) as $unit) {
            if (!is_array($unit) || !in_array((string)($unit['type'] ?? ''), self::SEMANTIC_TYPES, true)) { continue; }
            $semantic[] = ['type'=>(string)$unit['type'],'text'=>self::text($unit['text'] ?? '', 400)];
        }

        $events = [];
        foreach (array_slice(is_array($payload['events'] ?? null) ? $payload['events'] : [], 0, 30) as $event) {
            if (!is_array($event)) { continue; }
            $type = (string) ($event['event_type'] ?? '');
            if (!in_array($type, self::EVENT_TYPES, true)) { continue; }
            $targetType = (string) ($event['target_type'] ?? 'none');
            if (!in_array($targetType, ['session','item','hidden_fact','message','none'], true)) { $targetType = 'none'; }
            $targetCode = self::text($event['target_code'] ?? '', 64);
            if ($targetType === 'item' && $targetCode !== '' && !isset($itemMap[$targetCode])) { continue; }
            if ($targetType === 'hidden_fact' && $targetCode !== '' && !isset($factMap[$targetCode])) { continue; }
            $eventConfidence = self::confidence($event['confidence'] ?? null);
            if ($eventConfidence < 0.65) { continue; }
            $events[] = [
                'event_type'=>$type,'target_type'=>$targetType,'target_code'=>$targetCode,
                'confidence'=>$eventConfidence,
                'payload'=>is_array($event['payload'] ?? null) ? $event['payload'] : [],
            ];
        }

        $hasProbe = false;
        foreach ($events as $event) { if (in_array($event['event_type'], ['question_asked','interest_probe','constraint_probe','alternative_probe'], true)) { $hasProbe = true; break; } }
        $facts = [];
        foreach (array_slice(is_array($payload['fact_updates'] ?? null) ? $payload['fact_updates'] : [], 0, 12) as $update) {
            if (!is_array($update)) { continue; }
            $code = (string) ($update['fact_code'] ?? '');
            $level = (string) ($update['suggested_level'] ?? '');
            if (!isset($factMap[$code]) || !in_array($level, ['partial','revealed'], true)) { continue; }
            if ($actor === 'player' && !$hasProbe) { continue; }
            if ($actor === 'player' && $level === 'revealed') { $level = 'partial'; }
            $discovery = (string) ($update['discovery_event'] ?? 'interest_discovered');
            if (!in_array($discovery, ['interest_discovered','constraint_discovered','alternative_discovered'], true)) { $discovery = 'interest_discovered'; }
            $facts[] = [
                'fact_code'=>$code,'suggested_level'=>$level,'confidence'=>self::confidence($update['confidence'] ?? null),
                'reason'=>self::text($update['reason'] ?? '', 500),'public_summary'=>self::text($update['public_summary'] ?? '', 500),
                'discovery_event'=>$discovery,
            ];
        }

        $deals = [];
        foreach (array_slice(is_array($payload['deal_updates'] ?? null) ? $payload['deal_updates'] : [], 0, 16) as $update) {
            if (!is_array($update)) { continue; }
            $code = (string) ($update['item_code'] ?? '');
            $action = (string) ($update['action'] ?? '');
            if (!isset($itemMap[$code]) || !in_array($action, self::DEAL_ACTIONS, true)) { continue; }
            $proposedBy = (string) ($update['proposed_by'] ?? $actor);
            if (!in_array($proposedBy, ['player','opponent'], true) || $proposedBy !== $actor) { $proposedBy = $actor; }
            if ($action === 'acceptance_candidate' && (self::isOnlyWillingness($targetText) || !self::hasAcceptanceCue($targetText))) {
                $action = 'discussed';
                $events[] = ['event_type'=>'willing_to_consider','target_type'=>'item','target_code'=>$code,'confidence'=>0.95,'payload'=>[]];
            }
            $currentValue = is_array($itemMap[$code]['current_value'] ?? null) ? $itemMap[$code]['current_value'] : [];
            $pendingReopen = is_array($currentValue['reopen_request'] ?? null) ? $currentValue['reopen_request'] : null;
            if ($action === 'reopen_request' && !in_array((string)($itemMap[$code]['state_status'] ?? ''), ['agreed','reopen_requested'], true)) { continue; }
            if ($action === 'reopen_confirm') {
                if (!$pendingReopen || (string)($pendingReopen['actor'] ?? '') === $actor || !self::hasReopenConfirmCue($targetText)) { continue; }
            }
            if ($action === 'reopen_reject') {
                if (!$pendingReopen || (string)($pendingReopen['actor'] ?? '') === $actor || !self::hasReopenRejectCue($targetText)) { continue; }
            }
            $dealConfidence = self::confidence($update['confidence'] ?? null);
            if ($dealConfidence < 0.65 || (in_array($action, ['acceptance_candidate','reopen_confirm','reopen_reject'], true) && $dealConfidence < 0.85)) { continue; }
            $reasonType = (string)($update['reopen_reason_type'] ?? $update['reason_type'] ?? 'none');
            if (!ReopenPolicy::validReasonType($reasonType)) { $reasonType = 'none'; }
            $deals[] = [
                'item_code'=>$code,'action'=>$action,'value'=>$update['value'] ?? null,'proposed_by'=>$proposedBy,
                'bundle_key'=>preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', (string)($update['bundle_key'] ?? '')) ? (string)$update['bundle_key'] : '',
                'reopen_reason_type'=>$reasonType,
                'reopen_reason'=>ReopenPolicy::sanitizeReason($update['reopen_reason'] ?? $update['reason'] ?? ''),
                'confidence'=>$dealConfidence,
            ];
        }
        $package = false; foreach ($events as $event) { if ($event['event_type'] === 'package_offer_made') { $package = true; break; } }
        if ($package) {
            $proposedIndexes = [];
            foreach ($deals as $i => $deal) { if ($deal['action'] === 'proposed') { $proposedIndexes[] = $i; } }
            if (count($proposedIndexes) >= 2) {
                $bundle = 'msg-' . (int) $target['id'];
                foreach ($proposedIndexes as $i) { $deals[$i]['bundle_key'] = $bundle; }
            }
        }

        $commitments = [];
        foreach (array_slice(is_array($payload['commitment_updates'] ?? null) ? $payload['commitment_updates'] : [], 0, 8) as $update) {
            if (!is_array($update)) { continue; }
            $action = (string)($update['action'] ?? '');
            if (!in_array($action, ['create','clarify','break'], true)) { continue; }
            $confidence = self::confidence($update['confidence'] ?? null);
            if ($confidence < 0.80) { continue; }
            $kind = (string)($update['kind'] ?? 'result');
            if (!in_array($kind, ['result','effort','responsibility'], true)) { $kind = 'result'; }
            $id = (int)($update['commitment_id'] ?? 0);
            if ($action !== 'create' && $id <= 0) { continue; }
            $summary = self::text($update['summary'] ?? '', 420);
            if ($action === 'create' && $summary === '') { continue; }
            $entry = [
                'action'=>$action,
                'commitment_id'=>$id,
                'kind'=>$kind,
                'condition_satisfied'=>!empty($update['condition_satisfied']),
                'confidence'=>$confidence,
            ];
            if ($action === 'create' || array_key_exists('summary', $update)) { $entry['summary'] = $summary; }
            if ($action === 'create' || array_key_exists('condition', $update)) { $entry['condition'] = self::text($update['condition'] ?? '', 320); }
            if ($action === 'create' || array_key_exists('deadline', $update)) { $entry['deadline'] = self::text($update['deadline'] ?? '', 120); }
            $commitments[] = $entry;
        }

        $signals = [];
        foreach (array_slice(is_array($payload['rule_signals'] ?? null) ? $payload['rule_signals'] : [], 0, 12) as $signal) {
            if (!is_array($signal)) { continue; }
            $type = (string) ($signal['type'] ?? '');
            if (!in_array($type, self::RULE_SIGNALS, true)) { continue; }
            $itemCode = (string) ($signal['item_code'] ?? '');
            if ($itemCode !== '' && !isset($itemMap[$itemCode])) { continue; }
            $signals[] = ['type'=>$type,'item_code'=>$itemCode,'confidence'=>self::confidence($signal['confidence'] ?? null),'payload'=>is_array($signal['payload'] ?? null)?$signal['payload']:[]];
        }

        $dialogue = is_array($payload['dialogue_state'] ?? null) ? $payload['dialogue_state'] : [];
        $tension = (string) ($dialogue['tension'] ?? 'normal');
        $risk = (string) ($dialogue['walkaway_risk'] ?? 'low');
        if (!in_array($tension, ['low','normal','high'], true)) { $tension = 'normal'; }
        if (!in_array($risk, ['low','medium','high'], true)) { $risk = 'low'; }

        return [
            'message'=>['message_id'=>(int)$target['id'],'actor'=>$actor],
            'semantic_units'=>$semantic,'events'=>$events,'fact_updates'=>$facts,'deal_updates'=>$deals,'commitment_updates'=>$commitments,'rule_signals'=>$signals,
            'dialogue_state'=>['tension'=>$tension,'walkaway_risk'=>$risk],
        ];
    }
}

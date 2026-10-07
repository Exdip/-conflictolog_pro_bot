<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** Server-side policy for reopening a condition that has already been mutually agreed. */
final class ReopenPolicy {
    public const REASONS = [
        'new_information',
        'new_risk',
        'scope_change',
        'linked_concession_withdrawn',
        'dependency_change',
        'changed_circumstances',
        'none',
    ];

    public static function normalize(mixed $policy): string {
        $value = trim((string)$policy);
        return match ($value) {
            'never' => 'never',
            'free_until_final' => 'free_until_final',
            'explicit_mutual_confirmation' => 'explicit_mutual_confirmation',
            'with_reason', '' => 'with_reason',
            default => 'with_reason',
        };
    }

    public static function validReasonType(mixed $reasonType): bool {
        return in_array((string)$reasonType, self::REASONS, true);
    }

    public static function reasonRequired(string $policy): bool {
        return self::normalize($policy) === 'with_reason';
    }

    public static function requiresMutualConfirmation(string $policy): bool {
        return self::normalize($policy) === 'explicit_mutual_confirmation';
    }

    /** @return 'deny'|'immediate'|'request' */
    public static function decision(string $policy, mixed $reasonType = 'none'): string {
        $policy = self::normalize($policy);
        $reasonType = self::validReasonType($reasonType) ? (string)$reasonType : 'none';
        if ($policy === 'never') { return 'deny'; }
        if ($policy === 'free_until_final') { return 'immediate'; }
        if ($policy === 'explicit_mutual_confirmation') { return 'request'; }
        return $reasonType !== 'none' ? 'immediate' : 'deny';
    }

    public static function sanitizeReason(mixed $reason, int $max = 420): string {
        $text = trim(strip_tags((string)$reason));
        if (function_exists('mb_substr')) { return mb_substr($text, 0, $max, 'UTF-8'); }
        return substr($text, 0, $max);
    }
}

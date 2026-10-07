<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class CoachResponseValidator {
    private static function length(string $text): int {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function sentenceCount(string $text): int {
        $parts = preg_split('/(?<=[.!?…])\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return max(1, count($parts ?: []));
    }

    public function validate(string $raw, string $level): string {
        $text = trim(wp_strip_all_tags($raw));
        if ($text === '') { throw new \UnexpectedValueException('Empty coach response.'); }
        if (!in_array($level, ['attention','direction','example','review_last_move'], true)) { throw new \UnexpectedValueException('Invalid coach level.'); }
        if (self::length($text) > 700) { throw new \UnexpectedValueException('Coach response is too long.'); }

        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        foreach (['opponent_hidden_interests','opponent_boundary','opponent_target','reveal_rules','hidden_fact_id','scenario_version_id','system prompt','системный промпт','внутренний prompt'] as $needle) {
            if (str_contains($lower, strtolower($needle))) { throw new \UnexpectedValueException('Coach exposed technical data.'); }
        }
        if (preg_match('/^\s*[\[{].*[\]}]\s*$/su', $text)) { throw new \UnexpectedValueException('Coach returned structured payload.'); }

        $sentences = self::sentenceCount($text);
        if (in_array($level, ['attention','direction'], true) && $sentences > 2) { throw new \UnexpectedValueException('Ответ ИИ-тренера слишком длинный для выбранного уровня подсказки.'); }
        if ($level === 'attention' && preg_match('/\b(спросите|уточните|предложите|скажите|попробуйте|выясните|задайте|сделайте|сформулируйте)\b/ui', $text)) {
            throw new \UnexpectedValueException('Attention level must not prescribe the next move.');
        }
        if ($level === 'direction' && (preg_match('/[«»"]/u', $text) || preg_match('/\b(например|скажите так|можно сказать)\b/ui', $text))) {
            throw new \UnexpectedValueException('Direction level must not contain a ready-made utterance.');
        }
        if ($level === 'review_last_move' && $sentences > 3) { throw new \UnexpectedValueException('Coach review is too long.'); }
        if ($level === 'example') {
            if ($sentences > 2 || self::length($text) > 360) { throw new \UnexpectedValueException('Coach example must be one short utterance.'); }
            if (preg_match('/(?:^|\n)\s*[-•*]\s+/u', $text)) { throw new \UnexpectedValueException('Coach example must not be a list.'); }
            if (preg_match('/^\s*(например|вариант|можно сказать|пояснение)\s*[:—-]/ui', $text)) { throw new \UnexpectedValueException('Coach example must contain only the utterance.'); }
        }
        return $text;
    }
}

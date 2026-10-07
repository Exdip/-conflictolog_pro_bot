<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Session-pinned opponent strategy level.
 * Difficulty changes negotiation behaviour, never hard rules, scoring, access or hidden-data boundaries.
 */
final class DifficultyPolicy {
    private const LEVELS = ['soft','medium','hard','expert'];

    public static function normalize(mixed $value, string $fallback = 'medium'): string {
        $value = strtolower(trim((string)$value));
        if (in_array($value, self::LEVELS, true)) { return $value; }
        return in_array($fallback, self::LEVELS, true) ? $fallback : 'medium';
    }

    public static function labels(): array {
        return ['soft'=>'Мягкий','medium'=>'Средний','hard'=>'Жёсткий','expert'=>'Эксперт'];
    }

    public static function label(string $level): string {
        $level = self::normalize($level);
        return self::labels()[$level];
    }

    public static function allowedForVersion(array $version): array {
        $allowed = self::LEVELS;
        $raw = (string)($version['mechanics_json'] ?? '');
        if ($raw !== '') {
            try {
                $mechanics = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($mechanics['allowed_difficulties'] ?? null)) {
                    $candidate = [];
                    foreach ($mechanics['allowed_difficulties'] as $value) {
                        $value = strtolower(trim((string)$value));
                        if (in_array($value, self::LEVELS, true) && !in_array($value, $candidate, true)) { $candidate[] = $value; }
                    }
                    if ($candidate) { $allowed = $candidate; }
                }
            } catch (\Throwable) {}
        }
        return $allowed;
    }

    public static function defaultForVersion(array $version): string {
        $allowed = self::allowedForVersion($version);
        $default = 'medium';
        $raw = (string)($version['mechanics_json'] ?? '');
        if ($raw !== '') {
            try {
                $mechanics = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $candidate = self::normalize($mechanics['default_difficulty'] ?? 'medium');
                if (in_array($candidate, $allowed, true)) { $default = $candidate; }
            } catch (\Throwable) {}
        }
        return in_array($default, $allowed, true) ? $default : $allowed[0];
    }

    public static function assertAllowed(string $level, array $version): string {
        $level = strtolower(trim($level));
        if (!in_array($level, self::LEVELS, true) || !in_array($level, self::allowedForVersion($version), true)) {
            throw new \InvalidArgumentException('Выбранный уровень оппонента недоступен для этого сценария.');
        }
        return $level;
    }

    public static function instruction(string $level): string {
        return match (self::normalize($level)) {
            'soft' => "МЯГКИЙ УРОВЕНЬ: быстрее проясняй интересы в ответ на хорошие вопросы, охотнее признавай сильные аргументы и делай небольшие обоснованные шаги навстречу. Веди торг преимущественно по одному-двум параметрам за раз. Не раскрывай скрытые лимиты и не нарушай hard constraints.",
            'hard' => "ЖЁСТКИЙ УРОВЕНЬ: раскрывай приоритеты менее прямо, требуй конкретного обоснования и встречного движения, чаще используй альтернативу и контрпредложения. Не делай две крупные уступки подряд без новой ценности для своей стороны. Оставайся профессиональным: жёсткость — это стратегия, а не грубость.",
            'expert' => "ЭКСПЕРТНЫЙ УРОВЕНЬ: скрывай относительную важность параметров, веди многопараметрический торг, проверяй последовательность позиции игрока и связывай уступки в пакеты. Используй альтернативу и ограничения стратегически, но не выдумывай факты, не нарушай hard constraints и не становись искусственно несговорчивым.",
            default => "СРЕДНИЙ УРОВЕНЬ: последовательно защищай интересы, требуй разумного обоснования уступок и встречного обмена, раскрывай мотивы постепенно и реагируй на объективное улучшение пакета без искусственной уступчивости или упрямства.",
        };
    }
}

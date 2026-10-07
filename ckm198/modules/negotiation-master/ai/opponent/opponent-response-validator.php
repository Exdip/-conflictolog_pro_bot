<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class OpponentResponseValidator {
    private static function lower(string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function clean(string $text): string {
        $text = trim(str_replace("\0", '', $text));
        $text = preg_replace('/^```(?:text|markdown)?\s*/iu', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/u', '', $text) ?? $text;
        $text = preg_replace('/^\s*(?:assistant|оппонент):\s*/iu', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
        return $text;
    }

    private static function numericLeaves(mixed $value, array &$out): void {
        if (is_array($value)) {
            foreach ($value as $v) { self::numericLeaves($v, $out); }
            return;
        }
        if (is_int($value) || is_float($value)) { $out[] = (float) $value; }
    }

    private static function numberVariants(float $number): array {
        if ($number <= 0) { return []; }
        $variants = [];
        if (abs($number - round($number)) < 0.00001) {
            $i = (int) round($number);
            $variants[] = (string) $i;
            if ($i >= 1000) {
                $variants[] = number_format($i, 0, ',', ' ');
                $variants[] = number_format($i, 0, '.', ' ');
            }
            if ($i >= 1000000) {
                $m = $i / 1000000;
                $variants[] = rtrim(rtrim(number_format($m, 2, ',', ''), '0'), ',') . ' млн';
                $variants[] = rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.') . ' млн';
            }
        }
        return array_values(array_unique(array_filter($variants)));
    }

    private static function leaksInternalBoundary(string $text, array $context): bool {
        $lower = self::lower($text);
        $cues = ['максим', 'миним', 'предел', 'лимит', 'потолок', 'ориентир', 'выше не', 'ниже не', 'больше не', 'меньше не', 'внутренн', 'готовы заплат', 'можем заплат', 'дойдём до', 'дойти до'];
        $hasCue = false;
        foreach ($cues as $cue) { if (str_contains($lower, $cue)) { $hasCue = true; break; } }
        if (!$hasCue) { return false; }
        $numbers = [];
        self::numericLeaves($context['constraints'] ?? null, $numbers);
        self::numericLeaves($context['concession_space'] ?? null, $numbers);
        foreach (array_unique($numbers, SORT_REGULAR) as $number) {
            foreach (self::numberVariants((float) $number) as $variant) {
                if ($variant !== '' && str_contains($lower, self::lower($variant))) { return true; }
            }
        }
        return false;
    }

    private static function mentionsOwnName(string $text, array $context): bool {
        $name = trim((string)($context['identity']['name'] ?? ''));
        if ($name === '') { return false; }

        $lower = self::lower($text);
        $nameLower = self::lower($name);
        $parts = preg_split('/\s+/u', $nameLower) ?: [];

        $variants = [$nameLower];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            $variants[] = $part;

            $chars = preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $length = count($chars);
            if ($length >= 4) {
                $stem = implode('', array_slice($chars, 0, $length - 1));
                if ($stem !== '') { $variants[] = $stem; }
            }
        }

        foreach (array_unique($variants) as $variant) {
            if ($variant === '') { continue; }
            $quoted = preg_quote($variant, '/');
            // Match only at a word start, but allow grammatical endings:
            // Антон -> Антона/Антону, Мария -> Марии, etc.
            if (preg_match('/(?<![\p{L}\p{N}_])' . $quoted . '[\p{L}-]*/u', $lower) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function isSales(array $context): bool {
        $mechanics = is_array($context['mechanics'] ?? null) ? $context['mechanics'] : [];
        return (string)($mechanics['training_domain'] ?? '') === 'sales';
    }

    private static function hasEarlySalesRoleAnchor(string $text): bool {
        $lower = self::lower($text);
        $sentences = preg_split('/(?<=[.!?])\s+|[\r\n]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $early = trim(implode(' ', array_slice($sentences, 0, 2)));
        if ($early === '') {
            $early = function_exists('mb_substr') ? mb_substr($lower, 0, 260, 'UTF-8') : substr($lower, 0, 260);
        }
        return preg_match('/(?<![\p{L}\p{N}_])(?:я|мне|меня|мой|моя|моё|мои|мы|нам|нас|наш|наша|наше|наши)(?![\p{L}\p{N}_])/u', $early) === 1
            || str_contains($early, 'у нас')
            || str_contains($early, 'для нас')
            || str_contains($early, 'в нашей')
            || str_contains($early, 'в нашей компании');
    }

    private static function looksLikeDetachedSalesConsultant(string $text, array $context): bool {
        if (!self::isSales($context)) { return false; }
        $lower = self::lower($text);
        $strongCues = [
            'важно рассмотреть','важно оценить','необходимо рассмотреть','необходимо оценить',
            'стоит рассмотреть','следует рассмотреть','часто является важным фактором',
            'для малых и средних предприятий','с другой стороны','может вызывать сомнения',
            'могут влиять на восприятие','может влиять на восприятие',
            'какие конкретные преимущества','насколько они оправдывают затраты',
            'может привести к нежеланию инвестировать','неопределенность в отношении ожидаемых результатов',
            'существует несколько факторов','существуют несколько факторов','есть несколько факторов',
            'есть несколько причин','существует несколько причин','существуют несколько причин',
            'если у компании','если у предприятия','возможно, существуют более доступные',
            'отсутствие информации','риски и неопределенности'
        ];
        $hits = 0;
        foreach ($strongCues as $cue) { if (str_contains($lower, $cue)) { $hits++; } }

        $startsGeneric = preg_match('/^\s*(?:существу(?:ет|ют)|есть)\s+(?:несколько|ряд)\s+(?:фактор|причин)|^\s*обе\s+причин|^\s*в\s+целом\b/iu', $lower) === 1;
        $bulletCount = preg_match_all('/(?:^|\n)\s*[-•]\s+/u', $text);
        $earlyPersonal = self::hasEarlySalesRoleAnchor($text);

        if ($startsGeneric || $bulletCount >= 2) { return true; }
        if ($hits >= 2) { return true; }
        if ($hits >= 1 && !$earlyPersonal) { return true; }

        // Long multi-sentence sales answers must establish the concrete client's own position early.
        // A stray "я могу помочь" at the end must not legitimize a detached consultant lecture.
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        $sentenceCount = count(preg_split('/(?<=[.!?])\s+|[\r\n]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if (($length > 260 || $sentenceCount >= 4) && !$earlyPersonal) { return true; }

        $genericSubject = preg_match('/(?:предприят(?:ие|ия|ий)|компани(?:я|и|й)|клиент(?:ы|ов)?)[^.!?]{0,120}(?:часто|обычно|могут|может|важн|следует|необходимо)/u', $lower) === 1;
        return $genericSubject && !$earlyPersonal;
    }

    private static function looksLikeCoachReply(string $text, array $context): bool {
        $lower = self::lower($text);
        // In SALES a client may legitimately accept the seller's proposed "next step".
        // Do not treat that first-person acceptance as coaching merely because it
        // repeats the phrase "следующий шаг". Other coaching markers still apply.
        $salesNextStepAcceptance = self::isSales($context)
            && self::hasEarlySalesRoleAnchor($text)
            && (
                str_contains($lower, 'мне подходит')
                || str_contains($lower, 'я готов')
                || str_contains($lower, 'мы готовы')
                || str_contains($lower, 'готов обсудить')
                || str_contains($lower, 'готова обсудить')
                || str_contains($lower, 'согласен')
                || str_contains($lower, 'согласна')
                || str_contains($lower, 'договорились')
            );
        foreach ([
            'попробуйте понять','попробуйте уточнить','вам следует','убедитесь, что',
            'такой подход поможет','следующий шаг','лучше уточнить','можете спросить',
            'стоит уточнить','полезно обсудить','можно установить','имеет смысл уточнить',
            'рекомендую','советую','вам лучше','вы могли бы','можно обсудить',
            'улучшить коммуникацию'
        ] as $cue) {
            if ($cue === 'следующий шаг' && $salesNextStepAcceptance) { continue; }
            if (str_contains($lower, $cue)) { return true; }
        }
        $name = self::lower(trim((string)($context['identity']['name'] ?? '')));
        if ($name !== '' && str_contains($lower, $name)) {
            foreach (['вы предлагаете','вы можете','попробуйте','уточните','спросите','убедитесь'] as $cue) {
                if (str_contains($lower, $cue)) { return true; }
            }
        }
        return false;
    }

    public function validate(string $text, array $context): string {
        $text = self::clean($text);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length < 1 || $length > 2500) { throw new \UnexpectedValueException('Opponent response length is invalid.'); }
        $lower = self::lower($text);
        foreach (['opponent_hidden_interests_json','opponent_constraints_json','ckm_neg_','<system>','</system>','begin system','developer message'] as $marker) {
            if (str_contains($lower, self::lower($marker))) { throw new \UnexpectedValueException('Opponent response contains technical material.'); }
        }
        if (self::leaksInternalBoundary($text, $context)) { throw new \UnexpectedValueException('Opponent response exposes an internal boundary.'); }
        if (self::mentionsOwnName($text, $context)) { throw new \UnexpectedValueException('Opponent response mentions its own persona in third person or as an addressee.'); }
        if (self::looksLikeCoachReply($text, $context)) { throw new \UnexpectedValueException('Opponent response leaves the assigned role and becomes coaching.'); }
        if (self::looksLikeDetachedSalesConsultant($text, $context)) { throw new \UnexpectedValueException('Sales client response becomes detached consulting instead of a client role.'); }
        return $text;
    }
}

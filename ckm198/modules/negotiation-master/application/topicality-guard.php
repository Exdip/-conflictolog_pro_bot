<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class OffTopicMessageException extends \InvalidArgumentException {}

/**
 * Server-side relevance gate for player negotiation messages.
 *
 * The guard is deliberately deterministic: ordinary negotiation language,
 * scenario vocabulary and short contextual follow-ups are allowed; clearly
 * unrelated conversation is rejected before the player message is stored.
 * No hidden scenario facts are exposed to the player by this service.
 */
final class TopicalityGuard {
    public const MESSAGE = 'Эта реплика не относится к текущим переговорам. Вернитесь к условиям сделки, интересам сторон или обсуждаемой ситуации.';

    private static function lower(string $text): string {
        $text = trim($text);
        if (function_exists('mb_strtolower')) { return mb_strtolower($text, 'UTF-8'); }
        $text = strtolower($text);
        return strtr($text, [
            'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м',
            'Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ',
            'Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я',
        ]);
    }

    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }

    private static function tokenStems(string $source): array {
        $source = self::lower($source);
        $parts = preg_split('/[^\\p{L}\\p{N}]+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = [
            'если','когда','чтобы','котор','какой','какая','какие','этот','эта','это','того','такой','также','может','нужно','надо','есть','быть',
            'мы','вы','они','для','или','при','без','про','под','над','как','что','чем','где','кто','мне','нам','вам','вас','наш','ваш','свой',
        ];
        $out = [];
        foreach ($parts as $part) {
            $chars = preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $len = count($chars);
            if ($len < 4 || in_array($part, $stop, true)) { continue; }
            $stemLen = $len >= 7 ? 5 : ($len >= 5 ? 4 : $len);
            $stem = implode('', array_slice($chars, 0, $stemLen));
            if ($stem !== '') { $out[$stem] = true; }
        }
        return array_keys($out);
    }

    private static function overlap(array $a, array $b): int {
        if (!$a || !$b) { return 0; }
        $map = array_fill_keys($b, true); $count = 0;
        foreach ($a as $token) { if (isset($map[$token])) { $count++; } }
        return $count;
    }

    private static function containsAny(string $text, array $cues): bool {
        foreach ($cues as $cue) { if ($cue !== '' && str_contains($text, $cue)) { return true; } }
        return false;
    }

    private static function length(string $text): int {
        if (function_exists('mb_strlen')) { return mb_strlen($text, 'UTF-8'); }
        return preg_match_all('/./us', $text) ?: 0;
    }

    /**
     * Pure classifier used both by runtime and regression tests.
     * Context contains only public/player-visible scenario information.
     */
    public static function classify(string $content, array $context): string {
        $text = self::lower($content);
        if ($text === '') { return 'off_topic'; }

        // Prompt-injection / explicit topic switching and entertainment requests
        // stay outside the role-play even when they mention a scenario keyword.
        $hardOffTopic = [
            'расскажи анекдот','расскажите анекдот','анекдот про','пошути','напиши стих','сочини стих','спой песн','напиши песн',
            'посоветуй фильм','посоветуй сериал','давай сменим тему','сменим тему','не по теме','забудь переговор','игнорируй инструкц',
            'игнорируй правила','выйди из роли','не будь оппонентом','расскажи шутку',
        ];
        if (self::containsAny($text, $hardOffTopic)) { return 'off_topic'; }

        // Courtesy and very short contextual continuations are part of a natural negotiation.
        $courtesy = ['здравствуйте','добрый день','добрый вечер','спасибо','благодарю','понял','поняла','хорошо','договорились'];
        if (self::length($text) <= 48 && self::containsAny($text, $courtesy)) { return 'relevant'; }
        if (self::length($text) <= 32 && preg_match('/^(а\s+)?(почему|насколько|что именно|и что|а если|тогда|сколько|когда)(?:\s|[?!.:,;]|$)/u', $text)) { return 'relevant'; }

        $scenarioText = implode(' ', array_filter([
            (string)($context['scenario_title'] ?? ''), (string)($context['situation'] ?? ''), (string)($context['task'] ?? ''),
            (string)($context['player_role'] ?? ''), (string)($context['opponent_role'] ?? ''),
            implode(' ', array_map('strval', (array)($context['items'] ?? []))),
            implode(' ', array_map('strval', (array)($context['known_facts'] ?? []))),
            implode(' ', array_map('strval', (array)($context['recent_dialogue'] ?? []))),
        ]));
        $messageTokens = self::tokenStems($text);
        $scenarioTokens = self::tokenStems($scenarioText);
        $anchorOverlap = self::overlap($messageTokens, $scenarioTokens);

        $negotiationCues = [
            'предлаг','услов','цен','стоим','срок','постав','оплат','скид','бюдж','договор','контракт','сделк','соглас','подтверж','отказ',
            'компромисс','уступ','риск','интерес','приоритет','важн','критич','вариант','пакет','обязат','гарант','ответствен','дедлайн','задерж',
            'готов','принима','обсуд','уточн','торг','требован','огранич','выгод','дорог','дешев','быстр','медлен','альтернатив','позици','решени',
            'фиксир','согласован','пересмотр','взамен','устраива','не устраива','можете предлож','что для вас','какие условия','на каких услов',
            'аудит','диагностик','разбор звонков','разобрать звонки','пробный разбор',
        ];
        $hasNegotiationCue = self::containsAny($text, $negotiationCues);

        // Scenario vocabulary or normal bargaining language is sufficient.
        if ($anchorOverlap > 0 || $hasNegotiationCue) {
            // Weather/news/etc. may be relevant only when explicitly tied to the scenario.
            return 'relevant';
        }

        // Numeric shorthand such as “А если 35?” is a valid contextual counter-offer.
        if (preg_match('/\d/u', $text) && self::length($text) <= 48 && preg_match('/\b(если|за|до|от|тогда|можно|готов|предлагаю)\b/u', $text)) {
            return 'relevant';
        }

        // Typical unrelated small-talk / general-assistant requests are rejected.
        $genericOffTopic = [
            'погод','прогноз погоды','новости','гороскоп','рецепт','столица','кто президент','курс валют','футбол','хоккей',
            'музыка','кино','сериал','отпуск','как дела','чем занимаешься','что ты умеешь','напиши код','реши пример','переведи текст',
        ];
        if (self::containsAny($text, $genericOffTopic)) { return 'off_topic'; }

        // Missing vocabulary overlap is uncertainty, not evidence of a topic switch.
        // Let the role-bound opponent and subsequent feedback handle weak sales moves.
        return 'relevant';
    }

    private function publicContext(int $sessionId): array {
        global $wpdb;
        $session = Access::session($sessionId);
        $version = Access::version((int)$session['scenario_version_id']);
        $scenario = Access::scenario((int)$session['scenario_id']);
        $itemsTable = Schema::table('items');
        $items = (array)$wpdb->get_col($wpdb->prepare(
            "SELECT title FROM `$itemsTable` WHERE scenario_version_id=%d ORDER BY sort_order ASC,id ASC",
            (int)$session['scenario_version_id']
        ));
        $known = self::decode((string)($version['player_known_facts_json'] ?? ''));
        $knownTexts = [];
        foreach ((array)$known as $row) {
            if (is_array($row)) { $knownTexts[] = trim((string)($row['title'] ?? '') . ' ' . (string)($row['content'] ?? '')); }
            elseif (is_scalar($row)) { $knownTexts[] = (string)$row; }
        }
        $recentDialogue = array_map(
            static fn(array $row): string => (string)($row['content'] ?? ''),
            (new MessageRepository())->listNegotiationForContext($sessionId, 6)
        );
        // Sales can render the opening directly, before any stored dialogue exists.
        if (!$recentDialogue) {
            $mechanics = self::decode((string)($version['mechanics_json'] ?? ''));
            $opening = trim((string)($mechanics['opening_message'] ?? ''));
            if ($opening !== '') { $recentDialogue[] = $opening; }
        }
        return [
            'scenario_title'=>(string)($scenario['title'] ?? ''),
            'situation'=>(string)($version['player_situation'] ?? ''),
            'task'=>(string)($version['player_task'] ?? ''),
            'player_role'=>(string)($version['player_role'] ?? ''),
            'opponent_role'=>(string)($version['opponent_role'] ?? ''),
            'items'=>$items,
            'known_facts'=>$knownTexts,
            // Repository checks session access and excludes coach/internal messages.
            'recent_dialogue'=>$recentDialogue,
        ];
    }

    public function assertRelevant(int $sessionId, string $content): void {
        if (self::classify($content, $this->publicContext($sessionId)) !== 'relevant') {
            throw new OffTopicMessageException(self::MESSAGE);
        }
    }
}

<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ArbiterUnavailableException extends \RuntimeException {}

final class ArbiterService {
    public const VERSION = 'neg-arbiter-1.7';
    private MessageRepository $messages;
    private ArbiterContextBuilder $contexts;
    private ArbiterPromptBuilder $prompts;
    private ArbiterResponseParser $parser;
    private ArbiterValidator $validator;
    private ItemStateService $items;
    private FactStateService $facts;
    private RuleEngine $rules;
    private CommitmentService $commitments;
    private RelationshipService $relationships;
    private EventService $events;
    private $transport;

    public function __construct(
        ?MessageRepository $messages = null,
        ?ArbiterContextBuilder $contexts = null,
        ?ArbiterPromptBuilder $prompts = null,
        ?ArbiterResponseParser $parser = null,
        ?ArbiterValidator $validator = null,
        ?ItemStateService $items = null,
        ?FactStateService $facts = null,
        ?RuleEngine $rules = null,
        ?CommitmentService $commitments = null,
        ?EventService $events = null,
        ?callable $transport = null,
        ?RelationshipService $relationships = null
    ) {
        $this->messages = $messages ?: new MessageRepository();
        $this->contexts = $contexts ?: new ArbiterContextBuilder($this->messages);
        $this->prompts = $prompts ?: new ArbiterPromptBuilder();
        $this->parser = $parser ?: new ArbiterResponseParser();
        $this->validator = $validator ?: new ArbiterValidator();
        $this->items = $items ?: new ItemStateService();
        $this->facts = $facts ?: new FactStateService();
        $this->rules = $rules ?: new RuleEngine();
        $this->commitments = $commitments ?: new CommitmentService();
        $this->events = $events ?: new EventService();
        $this->transport = $transport;
        $this->relationships = $relationships ?: new RelationshipService();
    }

    private function request(array $messages, int $timeout = 18): string {
        if ($this->transport) {
            $content = ($this->transport)($messages, $timeout);
            if (!is_string($content)) { throw new ArbiterUnavailableException('Arbiter transport failed.'); }
            return $content;
        }
        if (!function_exists('ckm_quiz_pro_aipuffer_post')) { throw new ArbiterUnavailableException('Arbiter AI is unavailable.'); }
        $settings = function_exists('ckm_quiz_pro_solution_price_ai_settings')
            ? ckm_quiz_pro_solution_price_ai_settings()
            : ['provider'=>'openai','model'=>'gpt-4o-mini'];
        $body = [
            'provider'=>(string)($settings['provider']??'openai'),
            'model'=>(string)($settings['model']??'gpt-4o-mini'),
            'messages'=>$messages,
            'ai_params'=>['temperature'=>0.05,'max_completion_tokens'=>1300],
            'stream'=>false,
        ];
        $response = ckm_quiz_pro_aipuffer_post($body, $timeout);
        if (is_wp_error($response)) { throw new ArbiterUnavailableException('Arbiter request failed.'); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int)wp_remote_retrieve_response_code($response) : 0;
        if ($code < 200 || $code >= 300) { throw new ArbiterUnavailableException('Arbiter request failed.'); }
        $raw = function_exists('wp_remote_retrieve_body') ? (string)wp_remote_retrieve_body($response) : '';
        $json = json_decode($raw, true);
        $content = is_array($json) ? (string)($json['content'] ?? $json['reply'] ?? '') : '';
        if ($content === '') { throw new ArbiterUnavailableException('Arbiter returned an empty response.'); }
        return $content;
    }

    private static function lower(string $value): string {
        if (function_exists('mb_strtolower')) { return mb_strtolower($value, 'UTF-8'); }
        return strtr(strtolower($value), [
            'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я',
        ]);
    }

    private static function unitAliases(string $unit): string {
        $unit = self::lower(trim($unit));
        $map = [
            'rub'=>'руб рубль рубля рублей',
            'percent'=>'процент процента процентов',
            'months'=>'месяц месяца месяцев',
            'days'=>'день дня дней',
            'people'=>'человек человека людей',
            'tasks'=>'задача задачи задач',
            'weeks'=>'неделя недели недель',
            'hours'=>'час часа часов',
            'minutes'=>'минута минуты минут',
            'deliveries'=>'поставка поставки отгрузка отгрузки',
            'visits'=>'визит визита визитов',
            'level'=>'уровень уровня уровней',
            'projects'=>'проект проекта проектов',
            'stages'=>'этап этапа этапов',
            'thousand_rub'=>'тыс тысяча тысячи тысяч рублей',
            'meetings'=>'встреча встречи встреч',
            'commitments'=>'обязательство обязательства обязательств',
            'examples'=>'пример примера примеров',
            'actions'=>'действие действия действий',
            'orders'=>'заказ заказа заказов',
            'elements'=>'элемент элемента элементов',
            'position'=>'позиция позиции позиций',
            'criteria'=>'критерий критерия критериев',
            'changes'=>'изменение изменения изменений',
            'exceptions'=>'исключение исключения исключений',
            'matters'=>'вопрос вопроса вопросов',
            'million_rub'=>'млн миллион миллиона миллионов рублей',
            'seats'=>'место места мест',
        ];
        return $map[$unit] ?? '';
    }

    private static function itemTokens(array $item): array {
        $unit = (string)($item['unit'] ?? '');
        $source = self::lower(trim((string)($item['title'] ?? '') . ' ' . $unit . ' ' . self::unitAliases($unit)));
        $parts = preg_split('/[^\p{L}\p{N}%]+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['контракт','контракта','условие','условия','значение','параметр','параметра'];
        $out = [];
        foreach ($parts as $part) {
            $chars = preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $len = count($chars);
            if (in_array($part, $stop, true) || $len < 4) { continue; }
            $stemLen = $len >= 7 ? 5 : ($len >= 5 ? 4 : $len);
            if ($len === 4 && preg_match('/[аяуюыеи]$/u', $part)) { $stemLen = 3; }
            $stem = implode('', array_slice($chars, 0, $stemLen));
            if ($stem !== '') { $out[$stem] = true; }
        }
        return array_keys($out);
    }

    private static function semanticTokens(string $source): array {
        $source = self::lower($source);
        $parts = preg_split('/[^\\p{L}\\p{N}]+/u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = [
            'если','когда','чтобы','котор','какой','какая','какие','этот','эта','это','того','такой','также','может','нужно','надо','есть','быть',
            'участник','участника','спрашивает','уточняет','вопрос','вопроса','факт','факта','информация','информации','сторона','стороны','другой',
            'условие','условия','проект','проекта','контракт','контракта','цена','цене','цену','срок','срока','поставка','поставки','важно','критичен',
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

    private static function salesSemanticTokens(string $source): array {
        $tokens = self::semanticTokens($source);
        $lower = self::lower($source);
        $groups = [
            '__sales_money' => ['бюдж','стоим','цена','цену','цены','ценой','цене','денег','оплат','платить','затрат'],
            '__sales_value' => ['окуп','эконом','выгод','эффект','результ','ценност','возврат инвест'],
            '__sales_problem' => ['проблем','не устраива','неудоб','затруднен','затруднён'],
            '__sales_process' => ['процесс','таблиц','мессендж','ручн','передач','скрипт','менеджер','ситуац','переста','не работает','не работают'],
            '__sales_leads' => ['лид','заяв','контакт','потер','завис','ворон'],
            '__sales_decision' => ['лпр','соглас','утверд','утвержд','директор','руковод','комитет','принимает решение','принятие реш'],
            '__sales_experience' => ['опыт','внедр','прошл','прижил','использ','ошиб','переход'],
            '__sales_deal_value' => ['прибыл','марж','доход','выруч','экономика одной сдел','экономики одной сдел','стоимост одной сдел','дополнительн сдел'],
        ];
        foreach ($groups as $marker => $cues) {
            foreach ($cues as $cue) {
                if (str_contains($lower, $cue)) { $tokens[] = $marker; break; }
            }
        }
        return array_values(array_unique($tokens));
    }

    private static function salesTopicMarkers(string $source): array {
        $tokens = self::salesSemanticTokens($source);
        return array_values(array_filter($tokens, static fn($token) => str_starts_with((string)$token, '__sales_')));
    }

    private static function salesPrimaryTopic(string $ruleText): string {
        $markers = self::salesTopicMarkers($ruleText);
        if (!$markers) { return ''; }
        // Prefer the most discriminating business topic. Generic value language is deliberately last.
        foreach (['__sales_decision','__sales_experience','__sales_deal_value','__sales_problem','__sales_process','__sales_money','__sales_leads','__sales_value'] as $marker) {
            if (in_array($marker, $markers, true)) { return $marker; }
        }
        return (string)($markers[0] ?? '');
    }

    private static function salesTopicMatch(string $message, string $ruleText): bool {
        $primary = self::salesPrimaryTopic($ruleText);
        if ($primary === '') { return false; }
        return in_array($primary, self::salesTopicMarkers($message), true);
    }

    /**
     * Resolve the authoritative SALES topic from the fact's title and reveal rules,
     * never from the hidden content itself. Hidden content can mention adjacent actors
     * (for example, a CEO in a past-CRM fact) and must not change the reveal topic.
     */
    private static function salesFactTopic(array $fact): string {
        $rules = is_array($fact['reveal_rules'] ?? null) ? $fact['reveal_rules'] : [];
        $contract = trim((string)($fact['title'] ?? '') . ' ' . (string)($rules['partial'] ?? '') . ' ' . (string)($rules['revealed'] ?? ''));
        return self::salesPrimaryTopic($contract);
    }

    private static function salesFactTopicMatches(string $message, array $fact): bool {
        $topic = self::salesFactTopic($fact);
        if ($topic === '') { return false; }
        return in_array($topic, self::salesTopicMarkers($message), true);
    }

    /** Topic markers prove subject alignment, not concrete disclosure. */
    private static function salesEvidenceTokens(array $tokens): array {
        return array_values(array_filter($tokens, static fn($token) => !str_starts_with((string)$token, '__sales_')));
    }

    private static function overlapCount(array $needles, array $haystack): int {
        if (!$needles || !$haystack) { return 0; }
        $map = array_fill_keys($haystack, true);
        $count = 0;
        foreach ($needles as $token) { if (isset($map[$token])) { $count++; } }
        return $count;
    }

    private static function isDiscoveryProbe(string $text): bool {
        $lower = self::lower($text);
        if (str_contains($lower, '?')) { return true; }
        foreach (['что ','почему','насколько','какой','какая','какие','правильно ли','хочу понять','уточн','риск','последств','дороже','критич'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }

    private static function salesNumericMentions(string $text): array {
        if ($text === '' || !preg_match('/\d/u', $text)) { return []; }
        preg_match_all('/(\d+(?:[\s\x{00A0}\x{202F}]\d{3})*(?:[.,]\d+)?)\s*(тыс(?:\.|яч[а-я]*)?|млн|миллион[а-я]*)?/iu', $text, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ((array)$matches as $match) {
            $raw = (string)($match[1] ?? '');
            $normalized = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $raw) ?? $raw;
            $normalized = str_replace(',', '.', $normalized);
            if ($normalized === '' || !is_numeric($normalized)) { continue; }
            $value = (float)$normalized;
            $multiplier = self::lower((string)($match[2] ?? ''));
            if ($multiplier !== '') {
                if (str_starts_with($multiplier, 'тыс')) { $value *= 1000; }
                elseif ($multiplier === 'млн' || str_starts_with($multiplier, 'миллион')) { $value *= 1000000; }
            }
            $out[] = $value;
        }
        return array_values(array_unique($out, SORT_REGULAR));
    }

    private static function salesNumericEvidenceMatches(string $message, string $factContent): bool {
        $factNumbers = self::salesNumericMentions($factContent);
        if (!$factNumbers) { return true; }
        $messageNumbers = self::salesNumericMentions($message);
        if (!$messageNumbers) { return false; }
        foreach ($factNumbers as $factNumber) {
            foreach ($messageNumbers as $messageNumber) {
                if (abs((float)$factNumber - (float)$messageNumber) < 0.00001) { return true; }
            }
        }
        return false;
    }

    private static function deterministicFactUpdates(array $context): array {
        $target = (array)($context['target_message'] ?? []);
        $actor = (string)($target['actor'] ?? '');
        $text = (string)($target['content'] ?? '');
        if (!in_array($actor, ['player','opponent'], true) || trim($text) === '') { return []; }
        if ($actor === 'player' && !self::isDiscoveryProbe($text)) { return []; }
        $mechanics = is_array($context['mechanics'] ?? null) ? $context['mechanics'] : [];
        $isSales = (string)($mechanics['training_domain'] ?? '') === 'sales';
        $messageTokens = $isSales ? self::salesSemanticTokens($text) : self::semanticTokens($text);
        if (!$messageTokens) { return []; }

        $updates = [];
        foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
            if (!is_array($fact)) { continue; }
            $code = (string)($fact['code'] ?? '');
            $current = (int)($fact['current_reveal_level'] ?? 0);
            if ($code === '' || $current >= 2) { continue; }
            $rules = is_array($fact['reveal_rules'] ?? null) ? $fact['reveal_rules'] : [];
            $tokenizer = $isSales ? [self::class, 'salesSemanticTokens'] : [self::class, 'semanticTokens'];
            $coreTokens = $tokenizer(trim((string)($fact['title'] ?? '') . ' ' . (string)($fact['content'] ?? '')));
            $partialTokens = $tokenizer((string)($rules['partial'] ?? ''));
            $revealedTokens = $tokenizer((string)($rules['revealed'] ?? ''));
            $allTokens = array_values(array_unique(array_merge($coreTokens, $partialTokens, $revealedTokens)));
            $coreOverlap = self::overlapCount($coreTokens, $messageTokens);
            $allOverlap = self::overlapCount($allTokens, $messageTokens);
            $partialOverlap = self::overlapCount($partialTokens, $messageTokens);
            $revealedOverlap = self::overlapCount($revealedTokens, $messageTokens);

            if ($actor === 'player') {
                // A player's question can establish only the specific topic actually probed.
                // In SALES the reveal_rules.partial text is the authoritative topic contract.
                if ($isSales) {
                    $matched = !empty($rules['partial']) && self::salesFactTopicMatches($text, $fact);
                } else {
                    $matched = ($coreOverlap >= 1 && $allOverlap >= 2) || $partialOverlap >= 2 || $revealedOverlap >= 2;
                }
                if (!$matched || $current >= 1 || empty($rules['partial'])) { continue; }
                $updates[] = [
                    'fact_code'=>$code,'suggested_level'=>'partial','confidence'=>0.92,
                    'reason'=>'Прямой вопрос участника соответствует правилу частичного раскрытия скрытого факта.',
                    'public_summary'=>'','discovery_event'=>'interest_discovered',
                ];
                continue;
            }

            // Opponent text may reveal a fact only when the concrete answer matches that fact's
            // own reveal topic. This prevents one broad client answer from unlocking unrelated facts.
            if ($isSales) {
                $factContent = (string)($fact['content'] ?? '');
                $factHasNumbers = !empty(self::salesNumericMentions($factContent));
                $numericEvidence = self::salesNumericEvidenceMatches($text, $factContent);
                $topicMatched = self::salesFactTopicMatches($text, $fact);
                // Topic markers prove only that client and fact discuss the same subject.
                // They must never count as concrete disclosure evidence: otherwise a
                // generic sentence such as «был опыт внедрения CRM» can falsely reveal
                // the hidden outcome of that implementation.
                $messageEvidenceTokens = self::salesEvidenceTokens($messageTokens);
                $coreEvidenceOverlap = self::overlapCount(self::salesEvidenceTokens($coreTokens), $messageEvidenceTokens);
                $revealedEvidenceOverlap = self::overlapCount(self::salesEvidenceTokens($revealedTokens), $messageEvidenceTokens);
                // A topic match is not enough for full disclosure. Numeric facts require
                // an actual scenario number in the client's line; non-numeric facts need
                // several concrete non-topic tokens from the stored fact/reveal contract.
                $concreteEvidence = $factHasNumbers
                    ? $numericEvidence
                    : ($coreEvidenceOverlap >= 3 || $revealedEvidenceOverlap >= 2);
                $revealed = !empty($rules['revealed'])
                    && $topicMatched
                    && $concreteEvidence;
                $partial = !$revealed && $current < 1 && !empty($rules['partial'])
                    && $topicMatched
                    && ($coreOverlap >= 1 || $partialOverlap >= 1);
            } else {
                $revealed = !empty($rules['revealed']) && ($coreOverlap >= 3 || ($coreOverlap >= 2 && $allOverlap >= 3) || $revealedOverlap >= 3);
                $partial = !empty($rules['partial']) && (($coreOverlap >= 1 && $allOverlap >= 2) || $partialOverlap >= 2);
            }
            if ($revealed) {
                $updates[] = [
                    'fact_code'=>$code,'suggested_level'=>'revealed','confidence'=>0.96,
                    'reason'=>'Ответ оппонента прямо раскрывает содержание скрытого факта.',
                    'public_summary'=>'','discovery_event'=>'interest_discovered',
                ];
                continue;
            }
            if ($partial && $current < 1) {
                $updates[] = [
                    'fact_code'=>$code,'suggested_level'=>'partial','confidence'=>0.90,
                    'reason'=>'Ответ оппонента частично раскрывает значимый скрытый интерес или риск.',
                    'public_summary'=>'','discovery_event'=>'interest_discovered',
                ];
            }
        }
        return $updates;
    }

    private static function canonicalizeFactUpdates(array $analysis, array $context): array {
        $fallback = self::deterministicFactUpdates($context);
        $mechanics = is_array($context['mechanics'] ?? null) ? $context['mechanics'] : [];
        $isSales = (string)($mechanics['training_domain'] ?? '') === 'sales';

        if ($isSales) {
            // SALES uses the data-driven reveal_rules as an evidence gate. The AI arbiter may
            // understand the conversation broadly, but it must not unlock unrelated hidden facts.
            $analysis['fact_updates'] = array_values($fallback);
            return $analysis;
        }

        if (!$fallback) { return $analysis; }
        $byCode = [];
        foreach ((array)($analysis['fact_updates'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $code = (string)($row['fact_code'] ?? '');
            if ($code !== '') { $byCode[$code] = $row; }
        }
        foreach ($fallback as $row) {
            $code = (string)($row['fact_code'] ?? '');
            if ($code === '') { continue; }
            $existing = $byCode[$code] ?? null;
            $existingLevel = is_array($existing) && (string)($existing['suggested_level'] ?? '') === 'revealed' ? 2 : (is_array($existing) ? 1 : 0);
            $fallbackLevel = (string)($row['suggested_level'] ?? '') === 'revealed' ? 2 : 1;
            if ($fallbackLevel > $existingLevel) { $byCode[$code] = $row; }
        }
        $analysis['fact_updates'] = array_values($byCode);
        return $analysis;
    }

    private static function explicitDealItemCodes(array $context): array {
        $text = self::lower((string)($context['target_message']['content'] ?? ''));
        if ($text === '' || !preg_match('/\d/u', $text)) { return []; }
        $codes = [];
        foreach ((array)($context['items'] ?? []) as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '') { continue; }
            foreach (self::itemTokens((array)$item) as $token) {
                if ($token !== '' && str_contains($text, $token)) { $codes[$code] = true; break; }
            }
        }
        return array_keys($codes);
    }

    private static function numericMentions(string $text): array {
        if ($text === '') { return []; }
        $out = [];
        if (preg_match('/\d/u', $text)) {
            preg_match_all('/(\d(?:[\d\x{00A0}\x{202F} ]*\d)?(?:[.,]\d+)?)/u', $text, $matches, PREG_OFFSET_CAPTURE);
            preg_match_all('/\d{1,4}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?/u', $text, $dateMatches, PREG_OFFSET_CAPTURE);
            $dateRanges = [];
            foreach (($dateMatches[0] ?? []) as $dateMatch) {
                $dateRanges[] = [(int)$dateMatch[1], (int)$dateMatch[1] + strlen((string)$dateMatch[0])];
            }
            $count = count($matches[1] ?? []);
            for ($i=0; $i<$count; $i++) {
                $token = (string)($matches[1][$i][0] ?? '');
                $pos = (int)($matches[1][$i][1] ?? -1);
                if ($token === '' || $pos < 0) { continue; }
                $prefix = substr($text, 0, $pos);
                $tail = substr($text, $pos);
                if (preg_match('/(?:^|[\r\n])\s*$/u', $prefix)
                    && preg_match('/^\d{1,3}[.)](?:\s|\*)/u', $tail)) { continue; }
                $insideDate = false;
                foreach ($dateRanges as [$dateStart,$dateEnd]) {
                    if ($pos >= $dateStart && $pos < $dateEnd) { $insideDate = true; break; }
                }
                if ($insideDate) { continue; }
                $raw = preg_replace('/[\x{00A0}\x{202F}\s]+/u', '', $token);
                $raw = str_replace(',', '.', (string)$raw);
                if (!is_numeric($raw)) { continue; }
                $number = (float)$raw;
                $tailText = substr($text, $pos + strlen($token));
                if (function_exists('mb_substr')) { $suffixChunk = mb_substr($tailText, 0, 24, 'UTF-8'); }
                elseif (preg_match('/^(.{0,24})/us', $tailText, $suffixMatch)) { $suffixChunk = (string)$suffixMatch[1]; }
                else { $suffixChunk = $tailText; }
                $suffix = self::lower($suffixChunk);
                if (preg_match('/\b(?:млн|миллион(?:а|ов)?)\b/u', $suffix)) { $number *= 1000000; }
                elseif (preg_match('/\b(?:тыс\.?|тысяч(?:а|и)?)\b/u', $suffix)) { $number *= 1000; }
                if ($number < 0) { continue; }
                $out[] = [
                    'value'=>abs($number - round($number)) < 0.000001 ? (int)round($number) : $number,
                    'pos'=>$pos,
                    'end'=>$pos + strlen($token),
                    'suffix'=>$suffix,
                    'percent'=>preg_match('/^\s*%/', $tailText) === 1,
                ];
            }
        }

        // Short Russian number words are common in natural negotiation speech
        // ("снять две задачи"). Ground them only later through a nearby item title/unit.
        $wordNumbers = [
            'один'=>1,'одна'=>1,'одно'=>1,'одного'=>1,'одной'=>1,'одну'=>1,
            'два'=>2,'две'=>2,'двух'=>2,'двум'=>2,
            'три'=>3,'трёх'=>3,'трех'=>3,'трём'=>3,'трем'=>3,
            'четыре'=>4,'четырёх'=>4,'четырех'=>4,'четырём'=>4,'четырем'=>4,
            'пять'=>5,'пяти'=>5,
            'шесть'=>6,'шести'=>6,
            'семь'=>7,'семи'=>7,
            'восемь'=>8,'восьми'=>8,
            'девять'=>9,'девяти'=>9,
            'десять'=>10,'десяти'=>10,
        ];
        $words = array_keys($wordNumbers);
        usort($words, static fn(string $a,string $b): int => strlen($b) <=> strlen($a));
        $pattern = '/(?<![\p{L}\p{N}_])(' . implode('|', array_map(static fn(string $w): string => preg_quote($w,'/'), $words)) . ')(?![\p{L}\p{N}_])/u';
        if (preg_match_all($pattern, $text, $wordMatches, PREG_OFFSET_CAPTURE)) {
            foreach (($wordMatches[1] ?? []) as $match) {
                $token = (string)($match[0] ?? '');
                $pos = (int)($match[1] ?? -1);
                if ($token === '' || $pos < 0 || !isset($wordNumbers[$token])) { continue; }
                $out[] = [
                    'value'=>$wordNumbers[$token],
                    'pos'=>$pos,
                    'end'=>$pos + strlen($token),
                    'suffix'=>'',
                    'percent'=>false,
                ];
            }
        }
        usort($out, static fn(array $a,array $b): int => ((int)$a['pos']) <=> ((int)$b['pos']));
        return $out;
    }

    private static function itemTokenPositions(string $text, array $item): array {
        $out = [];
        foreach (self::itemTokens($item) as $token) {
            if ($token === '') { continue; }
            $offset = 0;
            while (($pos = strpos($text, $token, $offset)) !== false) {
                $out[] = ['pos'=>(int)$pos,'token'=>$token];
                $offset = $pos + max(1, strlen($token));
            }
        }
        return $out;
    }

    private static function stemPositions(string $text, array $stems): array {
        $out = [];
        foreach ($stems as $stem) {
            $stem = (string)$stem;
            if ($stem === '') { continue; }
            $offset = 0;
            while (($pos = strpos($text, $stem, $offset)) !== false) {
                $out[] = (int)$pos;
                $offset = $pos + max(1, strlen($stem));
            }
        }
        sort($out);
        return $out;
    }

    private static function closestDistance(array $a, array $b): int {
        if (!$a || !$b) { return PHP_INT_MAX; }
        $best = PHP_INT_MAX;
        foreach ($a as $x) {
            foreach ($b as $y) {
                $best = min($best, abs((int)$x - (int)$y));
            }
        }
        return $best;
    }

    private static function explicitSelectValues(string $text, array $context): array {
        $messageTokens = self::semanticTokens($text);
        $messageMap = array_fill_keys($messageTokens, true);
        $values = [];
        $genericAnchorStems = ['зада','задач','ново','новой','новы','работ','услов','парам','блок','объём','объем'];

        foreach ((array)($context['items'] ?? []) as $item) {
            if ((string)($item['value_type'] ?? '') !== 'select') { continue; }
            $code = (string)($item['code'] ?? '');
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            $labels = is_array($config['labels'] ?? null) ? $config['labels'] : [];
            if ($code === '' || !$labels) { continue; }

            $titleOnly = (array)$item; $titleOnly['unit'] = '';
            $titleStems = array_values(array_filter(
                self::itemTokens($titleOnly),
                static fn(string $stem): bool => !in_array($stem, $genericAnchorStems, true)
            ));
            $titlePositions = self::stemPositions($text, $titleStems);
            $candidates = [];

            foreach ($labels as $value => $label) {
                $labelStems = self::semanticTokens((string)$label);
                if (!$labelStems) { continue; }
                $matchedStems = [];
                foreach ($labelStems as $stem) {
                    if (isset($messageMap[$stem])) { $matchedStems[] = $stem; }
                }
                $overlap = count($matchedStems);
                if ($overlap === 0) { continue; }

                $labelPositions = self::stemPositions($text, $matchedStems);
                $distance = self::closestDistance($labelPositions, $titlePositions);
                $strongLabel = $overlap >= 2;
                $anchoredSingle = $overlap === 1 && $distance <= 48;
                if (!$strongLabel && !$anchoredSingle) { continue; }

                $score = ($overlap * 100) + ($overlap === count($labelStems) ? 20 : 0);
                if ($distance !== PHP_INT_MAX) { $score += max(0, 48 - min(48, $distance)); }
                $candidates[] = ['value'=>$value,'score'=>$score];
            }
            if (!$candidates) { continue; }
            usort($candidates, static fn(array $a,array $b): int => $b['score'] <=> $a['score']);
            if (isset($candidates[1]) && $candidates[1]['score'] === $candidates[0]['score']
                && (string)$candidates[1]['value'] !== (string)$candidates[0]['value']) { continue; }
            $values[$code] = $candidates[0]['value'];
        }
        return $values;
    }

    private static function explicitDealValues(array $context): array {
        $text = self::lower((string)($context['target_message']['content'] ?? ''));
        if ($text === '') { return []; }
        $mentions = self::numericMentions($text);

        $tokens = [];
        foreach ((array)($context['items'] ?? []) as $item) {
            $code = (string)($item['code'] ?? '');
            if ($code === '' || (string)($item['value_type'] ?? '') !== 'integer') { continue; }
            $titleOnly = (array)$item; $titleOnly['unit'] = '';
            foreach (self::itemTokenPositions($text, $titleOnly) as $found) {
                $tokens[] = ['code'=>$code,'pos'=>(int)$found['pos'],'token'=>(string)$found['token'],'kind'=>'title'];
            }
            $unit = (string)($item['unit'] ?? '');
            $unitOnly = ['title'=>self::unitAliases($unit),'unit'=>$unit];
            foreach (self::itemTokenPositions($text, $unitOnly) as $found) {
                $tokens[] = ['code'=>$code,'pos'=>(int)$found['pos'],'token'=>(string)$found['token'],'kind'=>'unit'];
            }
        }

        $best = [];
        $unresolved = [];
        foreach ($mentions as $mention) {
            $titleCandidates = [];
            $allCandidates = [];
            $adjacentUnits = [];
            $wordNumber = preg_match('/^\p{L}/u', substr($text, (int)$mention['pos'], (int)$mention['end'] - (int)$mention['pos'])) === 1;
            foreach ($tokens as $found) {
                if (!empty($mention['percent'])) {
                    $matchedItem = null;
                    foreach ((array)($context['items'] ?? []) as $candidateItem) {
                        if ((string)($candidateItem['code'] ?? '') === (string)$found['code']) { $matchedItem = (array)$candidateItem; break; }
                    }
                    $candidateUnit = self::lower((string)($matchedItem['unit'] ?? ''));
                    if (!$matchedItem || !in_array($candidateUnit, ['percent','%'], true)) { continue; }
                }
                $distance = min(abs((int)$mention['pos'] - (int)$found['pos']), abs((int)$mention['end'] - (int)$found['pos']));
                if ($distance > 96) { continue; }
                // Offsets are bytes. Include the complete inflected word before testing
                // adjacency, rather than letting a distant narrative count become an offer.
                preg_match('/^\p{L}+/u', substr($text, (int)$found['pos']), $foundWord);
                $foundEnd = (int)$found['pos'] + strlen((string)($foundWord[0] ?? $found['token']));
                if ((int)$found['pos'] >= (int)$mention['end']) {
                    $gap = substr($text, (int)$mention['end'], (int)$found['pos'] - (int)$mention['end']);
                } elseif ($foundEnd <= (int)$mention['pos']) {
                    $gap = substr($text, $foundEnd, (int)$mention['pos'] - $foundEnd);
                } else { $gap = ''; }
                $adjacent = preg_match('/^[\s:—–\-]*(?:(?:за|в|на|до|по|равен|составляет|не\s+более|не\s+менее|тыс\.?|тысяч(?:а|и)?|млн|миллион(?:а|ов)?)[\s:—–\-]*)?$/u', $gap) === 1;
                if ($wordNumber && !$adjacent) { continue; }
                $row = ['code'=>(string)$found['code'],'distance'=>$distance,'kind'=>(string)$found['kind']];
                $allCandidates[] = $row;
                if ($row['kind'] === 'unit' && $adjacent && (int)$found['pos'] >= (int)$mention['end']) { $adjacentUnits[$row['code']] = $row; }
                if ($row['kind'] === 'title' && $distance <= 64) { $titleCandidates[] = $row; }
            }

            // An immediately following, unambiguous unit binds the number before a
            // later clause's title. Shared units still require the existing title checks.
            if (count($adjacentUnits) === 1) {
                $row = array_values($adjacentUnits)[0];
                $code = (string)$row['code'];
                if (!isset($best[$code]) || $best[$code]['distance'] >= 0) {
                    $best[$code] = ['distance'=>-1,'value'=>$mention['value']];
                }
                continue;
            }

            // A nearby item title is more informative than a shared unit such as "weeks".
            if ($titleCandidates) {
                usort($titleCandidates, static fn(array $a,array $b): int => $a['distance'] <=> $b['distance']);
                $first = $titleCandidates[0];
                $second = $titleCandidates[1] ?? null;
                if ($second === null || $second['distance'] > $first['distance'] + 8 || $second['code'] === $first['code']) {
                    $code = (string)$first['code'];
                    if (!isset($best[$code]) || $first['distance'] < $best[$code]['distance']) {
                        $best[$code] = ['distance'=>$first['distance'],'value'=>$mention['value']];
                    }
                    continue;
                }
            }

            if (!$allCandidates) { continue; }
            usort($allCandidates, static fn(array $a,array $b): int => $a['distance'] <=> $b['distance']);
            $min = $allCandidates[0]['distance'];
            $near = [];
            foreach ($allCandidates as $row) {
                if ($row['distance'] > $min + 4) { break; }
                $near[(string)$row['code']] = $row;
            }
            if (count($near) === 1) {
                $row = array_values($near)[0];
                $code = (string)$row['code'];
                if (!isset($best[$code]) || $row['distance'] < $best[$code]['distance']) {
                    $best[$code] = ['distance'=>$row['distance'],'value'=>$mention['value']];
                }
            } else {
                $unresolved[] = ['mention'=>$mention,'candidates'=>array_keys($near)];
            }
        }

        // Resolve shared-unit ambiguity by elimination after strongly anchored values are known.
        foreach ($unresolved as $row) {
            $remaining = array_values(array_filter(
                (array)$row['candidates'],
                static fn(string $code): bool => !array_key_exists($code, $best)
            ));
            if (count($remaining) !== 1) { continue; }
            $code = (string)$remaining[0];
            $best[$code] = ['distance'=>96,'value'=>$row['mention']['value']];
        }

        $values = [];
        foreach ($best as $code => $row) { $values[$code] = $row['value']; }
        foreach (self::explicitSelectValues($text, $context) as $code => $value) {
            $values[$code] = $value;
        }
        return $values;
    }

    private static function shortContextItemCode(array $context): string {
        $target = (array)($context['target_message'] ?? []);
        $text = trim((string)($target['content'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > 80 || count(self::numericMentions(self::lower($text))) !== 1) { return ''; }
        $targetId = (int)($target['id'] ?? 0);
        $previous = null;
        foreach (array_reverse((array)($context['recent_dialogue'] ?? [])) as $row) {
            if ((int)($row['id'] ?? 0) === $targetId) { continue; }
            $previous = (array)$row; break;
        }
        if ($previous) {
            $prevText = self::lower((string)($previous['content'] ?? ''));
            $codes = [];
            foreach ((array)($context['items'] ?? []) as $item) {
                $code = (string)($item['code'] ?? '');
                if ($code === '') { continue; }
                foreach (self::itemTokens((array)$item) as $token) {
                    if ($token !== '' && str_contains($prevText, $token)) { $codes[$code] = true; break; }
                }
            }
            if (count($codes) === 1) { return (string)array_key_first($codes); }
        }
        $active = [];
        foreach ((array)($context['items'] ?? []) as $item) {
            if (in_array((string)($item['state_status'] ?? ''), ['discussing','proposed','reopened','reopen_requested'], true)) {
                $code = (string)($item['code'] ?? ''); if ($code !== '') { $active[$code] = true; }
            }
        }
        return count($active) === 1 ? (string)array_key_first($active) : '';
    }

    private static function filterUngroundedDealUpdates(array $analysis, array $context): array {
        $explicit = self::explicitDealValues($context);
        $shortCode = self::shortContextItemCode($context);
        $target = (array)($context['target_message'] ?? []);
        $actor = (string)($target['actor'] ?? '');
        $other = $actor === 'player' ? 'opponent' : 'player';
        $targetText = (string)($target['content'] ?? '');
        $mentions = self::numericMentions(self::lower($targetText));
        $items = [];
        foreach ((array)($context['items'] ?? []) as $item) { $items[(string)($item['code'] ?? '')] = (array)$item; }

        $kept = [];
        foreach ((array)($analysis['deal_updates'] ?? []) as $update) {
            if (!is_array($update)) { continue; }
            $code = (string)($update['item_code'] ?? '');
            $action = (string)($update['action'] ?? '');
            $value = $update['value'] ?? null;
            if ($code === '' || $value === null || !is_numeric($value) || in_array($action, ['discussed','rejected','reopen_confirm','reopen_reject'], true)) {
                $kept[] = $update; continue;
            }
            if (array_key_exists($code, $explicit) && self::numericSame($value, $explicit[$code])) {
                $kept[] = $update; continue;
            }
            $item = $items[$code] ?? [];
            $current = is_array($item['current_value'] ?? null) ? $item['current_value'] : [];
            $otherOffer = $current['offers'][$other]['value'] ?? null;
            if ($action === 'acceptance_candidate' && self::hasAcceptanceCue($targetText) && $otherOffer !== null && self::numericSame($value, $otherOffer)) {
                $kept[] = $update; continue;
            }
            if ($shortCode === $code && count($mentions) === 1 && self::numericSame($value, $mentions[0]['value'] ?? null)) {
                $kept[] = $update; continue;
            }
            // Numeric model output without a textual or contextual anchor is ignored.
        }
        $analysis['deal_updates'] = $kept;
        return $analysis;
    }

    private static function numericSame(mixed $a, mixed $b): bool {
        if (!is_numeric($a) || !is_numeric($b)) { return $a === $b; }
        $a = (float)$a; $b = (float)$b;
        $scale = max(1.0, abs($a), abs($b));
        return abs($a - $b) <= 0.000001 * $scale;
    }

    private static function hasAcceptanceCue(string $text): bool {
        $lower = self::lower($text);
        foreach (['согласен','согласны','принимаю','принимаем','устраивает','подтверждаю','подтверждаем','договорились','согласовано'] as $cue) {
            if (str_contains($lower, $cue)) { return true; }
        }
        return false;
    }

    private static function deterministicExplicitDeals(array $context): array {
        $values = self::explicitDealValues($context);
        if (!$values) { return []; }
        $actor = (string)($context['target_message']['actor'] ?? 'player');
        $other = $actor === 'player' ? 'opponent' : 'player';
        $text = (string)($context['target_message']['content'] ?? '');
        $acceptance = self::hasAcceptanceCue($text);
        $bundle = count($values) >= 2 ? 'msg-' . (int)($context['target_message']['id'] ?? 0) : '';
        $itemMap = [];
        foreach ((array)($context['items'] ?? []) as $item) { $itemMap[(string)($item['code'] ?? '')] = (array)$item; }
        $updates = [];
        foreach ($values as $code => $value) {
            $item = $itemMap[$code] ?? [];
            $current = is_array($item['current_value'] ?? null) ? $item['current_value'] : [];
            $otherOffer = $current['offers'][$other]['value'] ?? null;
            $action = ($acceptance && $otherOffer !== null && self::numericSame($otherOffer, $value)) ? 'acceptance_candidate' : 'proposed';
            $updates[] = [
                'item_code'=>$code,'action'=>$action,'value'=>$value,'proposed_by'=>$actor,
                'bundle_key'=>$bundle,'reopen_reason_type'=>'none','reopen_reason'=>'','confidence'=>1.0,
            ];
        }
        return $updates;
    }

    private static function canonicalizeExplicitDeals(array $analysis, array $context): array {
        $fallback = self::deterministicExplicitDeals($context);
        if (!$fallback) { return $analysis; }
        $codes = [];
        foreach ($fallback as $row) { $codes[(string)$row['item_code']] = true; }
        $kept = [];
        foreach ((array)($analysis['deal_updates'] ?? []) as $row) {
            if (!is_array($row)) { continue; }
            $code = (string)($row['item_code'] ?? '');
            if ($code !== '' && isset($codes[$code])) { continue; }
            $kept[] = $row;
        }
        $analysis['deal_updates'] = array_merge($kept, $fallback);
        return $analysis;
    }

    private static function assertSemanticCompleteness(array $analysis, array $context): void {
        $expected = self::explicitDealValues($context);
        if (!$expected) { return; }
        $seen = [];
        foreach ((array)($analysis['deal_updates'] ?? []) as $update) {
            if (!is_array($update)) { continue; }
            $code = (string)($update['item_code'] ?? '');
            if ($code === '' || !array_key_exists($code, $expected)) { continue; }
            if (self::numericSame($update['value'] ?? null, $expected[$code])) { $seen[$code] = true; }
        }
        $missing = [];
        foreach ($expected as $code => $_value) { if (!isset($seen[$code])) { $missing[] = (string)$code; } }
        if ($missing) { throw new \UnexpectedValueException('Arbiter omitted explicit deal items: ' . implode(',', $missing)); }
    }

    private static function mergeState(array $state, array $patch, int $messageId, string $actor): array {
        if (!isset($state['arbiter']) || !is_array($state['arbiter'])) { $state['arbiter'] = []; }
        $state['arbiter']['version'] = self::VERSION;
        $state['arbiter']['last_message_id'] = $messageId;
        $state['arbiter']['last_actor'] = $actor;
        if (isset($patch['dialogue_state']) && is_array($patch['dialogue_state'])) { $state['arbiter']['dialogue_state'] = $patch['dialogue_state']; }
        if (isset($patch['rule_flags']) && is_array($patch['rule_flags'])) { $state['arbiter']['rule_flags'] = $patch['rule_flags']; }
        return $state;
    }

    private function apply(int $sessionId, array $message, array $analysis): array {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('Unable to start arbiter transaction.'); }
        try {
            $fresh = $this->messages->findById($sessionId, (int)$message['id']);
            if (!$fresh) { throw new \RuntimeException('Analyzed message disappeared.'); }
            if (($fresh['analysis_status'] ?? '') === 'complete') {
                $wpdb->query('COMMIT');
                return ['status'=>'complete','idempotent'=>true,'state_changed'=>false];
            }
            $session = Access::session($sessionId);
            $state = [];
            try { $decoded = json_decode((string)($session['state_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR); if (is_array($decoded)) { $state = $decoded; } }
            catch (\Throwable) {}

            $itemResult = $this->items->apply($sessionId, $fresh, $analysis['deal_updates'] ?? []);
            $factResult = $this->facts->apply($sessionId, $fresh, $analysis['fact_updates'] ?? []);
            $commitmentResult = $this->commitments->apply($state, $fresh, $analysis['commitment_updates'] ?? []);
            $state = is_array($commitmentResult['state'] ?? null) ? $commitmentResult['state'] : $state;
            $derived = $this->rules->derive($sessionId, $fresh, $analysis, $itemResult, $factResult);
            $behaviorEvents = array_merge((array)($derived['events'] ?? []), (array)($commitmentResult['events'] ?? []));
            $relationshipResult = $this->relationships->apply($state, $fresh, $behaviorEvents);
            $state = is_array($relationshipResult['state'] ?? null) ? $relationshipResult['state'] : $state;
            $derivedEvents = array_merge($behaviorEvents, (array)($relationshipResult['events'] ?? []));
            $eventResult = $this->events->appendBatch($sessionId, $fresh, $derivedEvents, self::VERSION);
            $changed = !empty($itemResult['changed']) || !empty($factResult['changed']) || !empty($commitmentResult['changed']) || !empty($relationshipResult['changed']) || !empty($eventResult['changed']);

            if ($changed) {
                $state = self::mergeState($state, $derived['state_patch'] ?? [], (int)$fresh['id'], (string)$fresh['actor']);
                $sessions = Schema::table('sessions');
                $ok = $wpdb->query($wpdb->prepare(
                    "UPDATE `$sessions` SET state_json=%s,state_revision=state_revision+1,last_activity_at=%s,updated_at=%s WHERE id=%d AND status='in_progress'",
                    wp_json_encode($state, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), current_time('mysql',true), current_time('mysql',true), $sessionId
                ));
                if ($ok === false) { throw new \RuntimeException('Unable to advance validated negotiation state.'); }
            }
            if (!$this->messages->setAnalysisStatus($sessionId, (int)$fresh['id'], 'complete')) {
                $check = $this->messages->findById($sessionId, (int)$fresh['id']);
                if (!$check || ($check['analysis_status'] ?? '') !== 'complete') { throw new \RuntimeException('Unable to finalize message analysis.'); }
            }
            if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('Unable to commit arbiter transaction.'); }
            return [
                'status'=>'complete','idempotent'=>false,'state_changed'=>$changed,
                'events_added'=>count($eventResult['inserted_ids'] ?? []),
                'items_changed'=>!empty($itemResult['changed']),'facts_changed'=>!empty($factResult['changed']),'commitments_changed'=>!empty($commitmentResult['changed']),'relationship_changed'=>!empty($relationshipResult['changed']),
            ];
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }

    public function analyze(int $sessionId, int $messageId): array {
        $message = $this->messages->findById($sessionId, $messageId);
        if (!$message || !in_array($message['actor'], ['player','opponent'], true) || !in_array($message['channel'], ['dialogue','negotiation'], true)) {
            return ['status'=>'skipped','idempotent'=>true,'state_changed'=>false];
        }
        if (($message['analysis_status'] ?? '') === 'complete') { return ['status'=>'complete','idempotent'=>true,'state_changed'=>false]; }
        $failureStage = 'context';
        try {
            $context = $this->contexts->build($sessionId, $messageId);
            $validated = null;
            for ($attempt=0;$attempt<2;$attempt++) {
                try {
                    $failureStage = 'transport';
                    $raw = $this->request($this->prompts->messages($context, $attempt>0));
                    $failureStage = 'parse';
                    $parsed = $this->parser->parse($raw);
                    $failureStage = 'validate';
                    $validated = $this->validator->validate($parsed, $context);
                    $validated = self::canonicalizeFactUpdates($validated, $context);
                    $validated = self::filterUngroundedDealUpdates($validated, $context);
                    $failureStage = 'semantic';
                    self::assertSemanticCompleteness($validated, $context);
                    break;
                } catch (\UnexpectedValueException $error) {
                    if ($attempt>0) {
                        if ($failureStage === 'semantic' && is_array($validated)) {
                            $validated = self::canonicalizeExplicitDeals($validated, $context);
                            self::assertSemanticCompleteness($validated, $context);
                            $failureStage = 'semantic_fallback';
                            break;
                        }
                        throw $error;
                    }
                }
            }
            if (!is_array($validated)) { throw new ArbiterUnavailableException('Arbiter response is invalid.'); }
            $failureStage = 'apply';
            return $this->apply($sessionId, $message, $validated);
        } catch (\Throwable $error) {
            try { $this->messages->setAnalysisStatus($sessionId, $messageId, 'failed'); } catch (\Throwable) {}
            return ['status'=>'failed','idempotent'=>false,'state_changed'=>false,'error'=>'analysis_failed','failure_stage'=>$failureStage];
        }
    }
}

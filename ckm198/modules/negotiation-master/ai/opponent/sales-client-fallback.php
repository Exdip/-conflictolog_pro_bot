<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Deterministic safety net for SALES only.
 * It never invents scenario facts: a fallback reply is built from the one
 * hidden fact whose reveal topic is actually probed by the player's message.
 */
final class SalesClientFallback {
    private static function lower(string $value): string {
        if (function_exists('mb_strtolower')) { return mb_strtolower($value, 'UTF-8'); }
        $value = strtolower($value);
        return strtr($value, ['А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я']);
    }

    private static function lowerFirst(string $text): string {
        $text = trim($text);
        if ($text === '') { return ''; }
        if (function_exists('mb_substr') && function_exists('mb_strtolower')) {
            return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        return strtolower(substr($text, 0, 1)) . substr($text, 1);
    }


    private static function stemTokens(string $text): array {
        $text = self::lower($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
        $stop = ['котор','этот','эта','это','того','чтобы','если','только','можно','нужно','какой','какая','какие','какое','почему','когда','где','после','перед','через','очень','просто','вопрос','уточнение','прямое','выяснение','предложение'];
        $out=[];
        foreach ((array)($matches[0] ?? []) as $token) {
            if (preg_match('/^\d+$/u',$token)) { $out[$token]=true; continue; }
            if (function_exists('mb_strlen')) { if (mb_strlen($token,'UTF-8') < 5) continue; $stem=mb_substr($token,0,5,'UTF-8'); }
            else { if (strlen($token)<5) continue; $stem=substr($token,0,5); }
            if (in_array($stem,$stop,true)) continue;
            $out[$stem]=true;
        }
        return array_keys($out);
    }

    private static function genericMatchScore(array $fact, string $playerText, string $ruleKey=''): int {
        $rules = is_array($fact['reveal_rules'] ?? null) ? $fact['reveal_rules'] : [];
        $basis = trim((string)($fact['title'] ?? '') . ' ' . (string)($rules['partial'] ?? '') . ' ' . (string)($rules['revealed'] ?? ''));
        if ($ruleKey !== '' && isset($rules[$ruleKey])) { $basis = (string)$rules[$ruleKey]; }
        $a=self::stemTokens($basis);$b=self::stemTokens($playerText);
        if(!$a||!$b)return 0;$set=array_fill_keys($a,true);$score=0;
        foreach($b as $stem){if(isset($set[$stem]))$score++;}
        return $score;
    }

    private static function adaptiveCueScore(array $fact,string $playerText): int {
        $code=(string)($fact['code']??'');
        if(!str_starts_with($code,'adaptive_'))return 0;
        $rules=is_array($fact['reveal_rules']??null)?$fact['reveal_rules']:[];
        $cues=(array)($rules['match_cues']??[]);
        if(!$cues)return 0;
        $text=self::lower($playerText);$score=0;
        foreach($cues as $cue){
            $cue=self::lower(trim((string)$cue));
            if($cue!==''&&str_contains($text,$cue))$score++;
        }
        return $score;
    }

    private static function adaptiveMatchingFact(array $context,string $playerText): ?array {
        $best=null;$bestScore=0;
        foreach((array)($context['hidden_facts']??[]) as $fact){
            if(!is_array($fact)||!self::usableFact($context,$fact,$playerText))continue;
            $score=self::adaptiveCueScore($fact,$playerText);
            if($score>$bestScore){$best=$fact;$bestScore=$score;}
        }
        return $bestScore>0?$best:null;
    }

    private static function genericMatchingFact(array $context, string $playerText): ?array {
        $best=null;$bestScore=0;
        foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
            if (!is_array($fact) || trim((string)($fact['content'] ?? ''))==='') continue;
            $score=self::genericMatchScore($fact,$playerText);
            if($score>$bestScore){$best=$fact;$bestScore=$score;}
        }
        return $bestScore>=1?$best:null;
    }

    private static function genericRequestsConcreteDetail(array $fact,string $playerText): bool {
        if(self::adaptiveCueScore($fact,$playerText)>0)return true;
        $t=self::lower($playerText);
        foreach(['что именно','сколько','какой','какая','какие','каких','кто ','почему','как ','где ','когда','каков','процент','доля','срок','критер','услов','риск','причин','что нужно','что требуется','в каких ситуац','переста','не работает','не работают'] as $cue){if(str_contains($t,$cue))return true;}
        return self::genericMatchScore($fact,$playerText,'revealed')>self::genericMatchScore($fact,$playerText,'partial');
    }

    public static function isSales(array $context): bool {
        $mechanics = is_array($context['mechanics'] ?? null) ? $context['mechanics'] : [];
        return (string)($mechanics['training_domain'] ?? '') === 'sales';
    }

    private static function replyTokens(string $text): array {
        $text = self::lower($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);
        $tokens = [];
        foreach ((array)($matches[0] ?? []) as $token) {
            $len = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($len < 3 && !preg_match('/^\d+$/u', $token)) { continue; }
            $tokens[$token] = true;
        }
        return array_keys($tokens);
    }

    private static function nearSameReply(string $left, string $right): bool {
        $a = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::lower($left)) ?? '');
        $b = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', self::lower($right)) ?? '');
        if ($a === '' || $b === '') { return false; }
        if ($a === $b) { return true; }
        $ta = self::replyTokens($a);
        $tb = self::replyTokens($b);
        if (count($ta) < 3 || count($tb) < 3) { return false; }
        $common = count(array_intersect($ta, $tb));
        $coverage = $common / max(1, min(count($ta), count($tb)));
        $union = count(array_unique(array_merge($ta, $tb)));
        $jaccard = $common / max(1, $union);
        if ($coverage >= 0.85 && $jaccard >= 0.70) { return true; }

        // Long replies may repeat the same meaning while changing surface wording.
        // Use the existing lightweight five-character stems only for sufficiently
        // detailed replies, so generic short sales phrases do not become false positives.
        $sa = self::stemTokens($a);
        $sb = self::stemTokens($b);
        if (count($sa) < 12 || count($sb) < 12) { return false; }
        $stemCommon = count(array_intersect($sa, $sb));
        $stemCoverage = $stemCommon / max(1, min(count($sa), count($sb)));
        $stemUnion = count(array_unique(array_merge($sa, $sb)));
        $stemJaccard = $stemCommon / max(1, $stemUnion);
        return $stemCommon >= 8 && $stemCoverage >= 0.75 && $stemJaccard >= 0.55;
    }

    /** Return a matching prior SALES client reply, if any. */
    public function recentDuplicate(array $context, string $candidate): string {
        if (!self::isSales($context) || trim($candidate) === '') { return ''; }

        // Preserve a legitimate direct re-question: if the player explicitly asks
        // again for this exact scenario fact, repeating that fact is useful, not a loop.
        $playerText = '';
        foreach (array_reverse((array)($context['recent_dialogue'] ?? [])) as $row) {
            if (!is_array($row) || (string)($row['actor'] ?? '') !== 'player') { continue; }
            $playerText = trim((string)($row['content'] ?? ''));
            if ($playerText !== '') { break; }
        }
        if ($playerText !== '') {
            foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
                if (!is_array($fact)) { continue; }
                $factContent = trim((string)($fact['content'] ?? ''));
                if ($factContent === '' || !self::nearSameReply($candidate, $factContent)) { continue; }
                if (self::mayRepeatRevealedFact($fact, $playerText)) { return ''; }
            }
        }

        $history = (array)($context['sales_reply_history'] ?? []);
        if (!$history) {
            $history = array_values(array_filter((array)($context['recent_dialogue'] ?? []), static function($row): bool {
                return is_array($row) && (string)($row['actor'] ?? '') === 'opponent';
            }));
        }
        foreach (array_reverse($history) as $row) {
            if (!is_array($row)) { continue; }
            $previous = trim((string)($row['content'] ?? ''));
            if ($previous === '') { continue; }
            if (self::nearSameReply($candidate, $previous)) { return $previous; }
        }
        return '';
    }

    /** Fallback phrases only need short-window rotation; full-session blocking would exhaust them. */
    public function recentFallbackDuplicate(array $context, string $candidate): string {
        if (!self::isSales($context) || trim($candidate) === '') { return ''; }
        $checked = 0;
        foreach (array_reverse((array)($context['recent_dialogue'] ?? [])) as $row) {
            if (!is_array($row) || (string)($row['actor'] ?? '') !== 'opponent') { continue; }
            $previous = trim((string)($row['content'] ?? ''));
            if ($previous === '') { continue; }
            $checked++;
            if (self::nearSameReply($candidate, $previous)) { return $previous; }
            if ($checked >= 4) { break; }
        }
        return '';
    }

    private static function specificUnsupportedMetric(array $context, string $playerText): string {
        $t = self::lower($playerText);
        $metrics = [
            'конверс' => ['конверс'],
            'средн чек' => ['средн чек', 'средний чек', 'среднего чека'],
            'ltv' => ['ltv'],
            'cac' => ['cac', 'стоимость привлеч'],
            'roi' => ['roi', 'romi', 'рентабельност'],
        ];
        foreach ($metrics as $aliases) {
            $asked = false;
            foreach ($aliases as $alias) {
                if (str_contains($t, $alias)) { $asked = true; break; }
            }
            if (!$asked) { continue; }
            foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
                if (!is_array($fact)) { continue; }
                $rules = is_array($fact['reveal_rules'] ?? null) ? $fact['reveal_rules'] : [];
                $basis = self::lower(trim((string)($fact['title'] ?? '') . ' ' . (string)($fact['content'] ?? '') . ' ' . (string)($rules['partial'] ?? '') . ' ' . (string)($rules['revealed'] ?? '')));
                foreach ($aliases as $alias) {
                    if (str_contains($basis, $alias)) { continue 3; }
                }
            }
            return 'Точного значения этого показателя в доступных мне данных нет. Давайте опираться только на те цифры, которые у нас действительно зафиксированы.';
        }
        return '';
    }

    private static function topic(string $text): string {
        $t = self::lower($text);
        if ($t === '') { return ''; }

        $hasAny = static function(array $cues) use ($t): bool {
            foreach ($cues as $cue) { if (str_contains($t, $cue)) { return true; } }
            return false;
        };

        // Prefer strong, specific intent cues over generic words such as "решение"
        // or "лид". This prevents a question about past CRM experience from being
        // reclassified as decision-making, and a workflow question from collapsing
        // into the generic leads topic.
        if ($hasAny(['прошл','раньше','ранее','опыт','внедрен','не прижил','что пошло','предыдущ','прошлая crm','предыдущая crm'])) { return 'experience'; }
        if ($hasAny(['где вед','где хран','таблиц','мессендж','прозрачн','руководител','в одной crm','параллельно','как именно устроен','как устроен процесс'])) { return 'process'; }
        if ($hasAny(['кто ','кто принима','кто реша','принимает окончательное решение','принимает решение','принимается решение','согласован','согласовани','утверд','лпр','генеральн','комитет','подписыва','решение принима'])) { return 'decision'; }
        if ($hasAny(['потерян','теря','зависш','лид','повторн','контакт','заявк'])) { return 'leads'; }
        if ($hasAny(['валов','прибыл','марж','стоимость одной сдел','экономическ эффект','экономический эффект'])) { return 'deal_value'; }
        if ($hasAny(['бюджет','дорог','цена','стоимост','окупа','за эти деньги','экономика решения','платить','платёж'])) { return 'money'; }
        return '';
    }

    private static function factTopic(array $fact): string {
        // Classify from the fact itself first. A reveal rule may mention adjacent
        // concepts (for example, a process question can mention leads) and must
        // not reclassify the fact into the wrong topic.
        $core = trim((string)($fact['title'] ?? '') . ' ' . (string)($fact['content'] ?? ''));
        $topic = self::topic($core);
        if ($topic !== '') { return $topic; }
        $rules = is_array($fact['reveal_rules'] ?? null) ? $fact['reveal_rules'] : [];
        return self::topic((string)($rules['partial'] ?? ''));
    }

    private static function revealLevel(array $context, array $fact): int {
        $code = trim((string)($fact['code'] ?? ''));
        if ($code === '') { return 0; }
        $state = is_array($context['validated_state'] ?? null) ? $context['validated_state'] : [];
        foreach ((array)($state['discovered_facts'] ?? []) as $row) {
            if (!is_array($row) || (string)($row['code'] ?? '') !== $code) { continue; }
            return max(0, (int)($row['reveal_level'] ?? 0));
        }
        return 0;
    }

    private static function mayRepeatRevealedFact(array $fact, string $playerText): bool {
        // A fully revealed fact may be repeated only for an explicit direct re-question.
        // This prevents a known fact from hijacking later value/next-step turns.
        if (!str_contains($playerText, '?')) { return false; }
        return self::genericMatchScore($fact, $playerText, 'revealed') >= 3;
    }

    private static function usableFact(array $context, array $fact, string $playerText): bool {
        if (trim((string)($fact['content'] ?? '')) === '') { return false; }
        if (self::revealLevel($context, $fact) < 2) { return true; }
        return self::mayRepeatRevealedFact($fact, $playerText);
    }

    private static function matchingFact(array $context, string $playerText): ?array {
        $adaptive=self::adaptiveMatchingFact($context,$playerText);
        if($adaptive)return $adaptive;
        $topic = self::topic($playerText);
        if ($topic !== '') {
            foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
                if (!is_array($fact) || !self::usableFact($context, $fact, $playerText)) { continue; }
                if (self::factTopic($fact) === $topic) { return $fact; }
            }
        }
        $best=null;$bestScore=0;
        foreach ((array)($context['hidden_facts'] ?? []) as $fact) {
            if (!is_array($fact) || !self::usableFact($context, $fact, $playerText)) { continue; }
            $score=self::genericMatchScore($fact,$playerText);
            if($score>$bestScore){$best=$fact;$bestScore=$score;}
        }
        // A direct question that names the stored fact title is specific enough
        // even when the generic lexical matcher has only one anchor (for example,
        // «проблема» -> hidden fact «Проблема»). This stays data-driven and does not
        // depend on a scenario slug or hard-coded fact code.
        if($best!==null&&$bestScore===1&&str_contains($playerText,'?')){
            $titleScore=self::genericMatchScore(['title'=>(string)($best['title']??''),'reveal_rules'=>[]],$playerText);
            if($titleScore>=1)return $best;
        }
        // Generic SALES matching otherwise needs at least two independent lexical anchors.
        // One shared word (for example, «менеджеров») is too weak to reveal an
        // unrelated hidden fact such as team size for a question about call selection.
        return $bestScore>=2?$best:null;
    }

    private static function firstPersonFact(array $fact, array $context): string {
        $text = trim((string)($fact['content'] ?? ''));
        if ($text === '') { return ''; }
        $name = trim((string)($context['identity']['name'] ?? ''));
        if ($name !== '') {
            $parts = preg_split('/\s+/u', $name) ?: [];
            $names = array_unique(array_filter([$name, (string)($parts[0] ?? '')]));
            // Convert only a known subject + predicate pair. Replacing a name
            // everywhere also corrupts object references and unknown verb forms.
            $verbs = ['успевает'=>'успеваю', 'может'=>'могу', 'хочет'=>'хочу',
                'считает'=>'считаю', 'знает'=>'знаю', 'видит'=>'вижу',
                'понимает'=>'понимаю', 'проверяет'=>'проверяю', 'прослушивает'=>'прослушиваю',
                'должен'=>'должен', 'должна'=>'должна', 'должны'=>'должны',
                'готова'=>'готова', 'готов'=>'готов', 'согласна'=>'согласна', 'согласен'=>'согласен'];
            foreach ($names as $subject) {
                $pattern = '/^' . preg_quote($subject, '/') . '\s+(не\s+)?([\p{L}]+)(.*)$/us';
                if (preg_match($pattern, $text, $m)) {
                    $verb = self::lower($m[2]);
                    if (!isset($verbs[$verb])) { return ''; }
                    $text = 'Я ' . ($m[1] ?? '') . $verbs[$verb] . $m[3];
                    break;
                }
            }
            // Unhandled mentions should be phrased by the role-bound model.
            foreach ($names as $subject) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($subject, '/') . '(?![\p{L}])/iu', $text)) { return ''; }
            }
        }

        // Safe grammatical conversions that do not add facts.
        $text = preg_replace('/\\bкомпания может\\b/iu', 'мы можем', $text) ?? $text;
        $text = preg_replace('/\\bкомпания не может\\b/iu', 'мы не можем', $text) ?? $text;
        $text = preg_replace('/^Клиент уже решает задачу некоторым способом и не станет менять его без понятной причины\\.?$/iu', 'Мы уже решаем задачу некоторым способом и не станем менять этот способ без понятной причины.', $text) ?? $text;
        $text = preg_replace('/^Клиент уже решает\\b/iu', 'Мы уже решаем', $text) ?? $text;
        $text = preg_replace('/^Клиент не\\b/iu', 'Мы не', $text) ?? $text;
        $text = preg_replace('/^У клиента есть\\b/iu', 'У нас есть', $text) ?? $text;
        $text = preg_replace('/^Клиент оценивает\\b/iu', 'Мы оцениваем', $text) ?? $text;
        $text = preg_replace('/^На решение влияет\\b/iu', 'На наше решение влияет', $text) ?? $text;
        $text = preg_replace('/^Проблема\\s+не\\s+в\\b/iu', 'У нас проблема не в', $text) ?? $text;
        $text = preg_replace('/^я\\s+/u', 'Я ', $text) ?? $text;

        $lower = self::lower($text);
        $hasAnchor = preg_match('/(?<![\\p{L}\\p{N}_])(?:я|мне|меня|мой|моя|моё|мои|мы|нам|нас|наш|наша|наше|наши)(?![\\p{L}\\p{N}_])/iu', $lower) === 1
            || str_contains($lower, 'у нас') || str_contains($lower, 'для нас');
        if (!$hasAnchor) { $text = 'У нас ' . self::lowerFirst($text); }
        return trim($text);
    }

    /**
     * A broad discovery question should not dump the entire hidden fact. This
     * classifier decides when the player's wording asks for the concrete detail
     * that is safe to reveal. It is domain-generic and never branches by slug.
     */
    private static function requestsConcreteDetail(string $topic, string $playerText): bool {
        $t = self::lower($playerText);
        $hasAny = static function(array $cues) use ($t): bool {
            foreach ($cues as $cue) { if (str_contains($t, $cue)) { return true; } }
            return false;
        };
        return match ($topic) {
            'money' => $hasAny(['бюджет','окуп','ограничение бюджета','неясно, какой результат','непонят','за эти деньги','экономика решения']),
            'leads' => $hasAny(['сколько','процент','%','масштаб','в месяц','количество лид','какая доля','какой объём','какой объем']),
            'deal_value' => $hasAny(['сколько','средн','марж','прибыл','руб','доход','выруч']),
            'decision' => $hasAny(['кто','кого ещё','кого еще','окончательное решение','кто принимает','кто согласовывает','лпр']),
            'experience' => $hasAny(['что было','что произошло','что именно произошло','что именно тогда произошло','что именно пошло','что пошло','пошло не так','почему','чем законч','как прош','не прижил','предыдущая crm','прошлая crm']),
            'process' => $hasAny(['где именно','в каких систем','где вед','где хран','что именно не вид','как именно перед','таблиц','мессендж','прозрачн','параллельно','в одной crm']),
            default => false,
        };
    }

    /**
     * Partial replies disclose only the topic, never the hidden quantity or
     * other concrete fact. Phrases are intentionally generic to the sales topic
     * and are supported by the selected hidden fact itself.
     */
    private static function partialReply(string $topic): string {
        return match ($topic) {
            'money' => 'Для нас вопрос не только в сумме. Мне пока непонятно, чем именно эта стоимость окупится.',
            'leads' => 'Да, с лидами у нас действительно есть потери и задержки повторного контакта. Точный масштаб лучше разобрать отдельно.',
            'deal_value' => 'Экономику одной дополнительной сделки у нас можно посчитать. Скажите, какой именно показатель вам нужен.',
            'decision' => 'Решение у нас принимаю не только я. Могу пояснить, кто ещё участвует, если это важно.',
            'experience' => 'У нас уже был опыт внедрения CRM, и он влияет на осторожность сейчас. Могу пояснить, что именно пошло не так.',
            'process' => 'Да, текущий процесс у нас разрознен, и мне не хватает полной прозрачности по сделкам. Давайте уточним, что именно вам важно понять.',
            default => 'Для нас это действительно значимый вопрос. Давайте уточним его конкретнее.',
        };
    }

    private static function looksLikePressureClose(string $playerText): bool {
        $t = self::lower($playerText);
        $close = false;
        foreach (['подписать','подпиш','оформим договор','оформить договор','заключить договор','закрыть сделк'] as $cue) {
            if (str_contains($t, $cue)) { $close = true; break; }
        }
        if (!$close) { return false; }
        foreach (['только сейчас','только сегодня','последний шанс','лучше не упускать','зафиксировать скидку','скидк'] as $cue) {
            if (str_contains($t, $cue)) { return true; }
        }
        return false;
    }

    private static function looksLikeAuditOffer(string $playerText): bool {
        $t = self::lower($playerText);
        if (!preg_match('/аудит|разбор.{0,30}звонк|разбер[её]м.{0,30}звонк/u', $t)) { return false; }
        // Past experience and a refusal are not an invitation to a new audit.
        if (preg_match('/не\s+(?:предлагаю|будем|нужно|надо|хочу)|без\s+аудита|(?:проводили|прошли|проводился|проводится|проводите).{0,40}аудит/u', $t)) { return false; }
        return preg_match('/предлага|давайте|провед[её]м|провести|проверим|разбер[её]м|можем|хотите|готовы|бесплатн/u', $t) === 1;
    }

    private static function looksLikeConcreteNextStep(string $playerText): bool {
        $t = self::lower($playerText);
        if (self::looksLikePressureClose($playerText)) { return false; }
        $proposal = preg_match('/(?:предлагаю|давайте|назначим|провед[её]м|созвонимся|встретимся|подготовлю|подготовим|пришлю|отправлю|согласуем|зафиксируем|запланируем|обсудим|разбер[её]м|подключим)/u', $t) === 1;
        if (!$proposal) { return false; }
        return preg_match('/(?:следующ|встреч|созвон|демо|демонстрац|пилот|тест|расч[её]т|кп|коммерческ|презентац|разбор|директор|руковод|участник|дата|время|срок|данн)/u', $t) === 1;
    }

    private static function looksLikeObjectionResolutionCheck(string $playerText): bool {
        $t = self::lower($playerText);
        if (!self::looksLikeValueArgument($playerText)) { return false; }
        return preg_match('/(?:снимет|снимает|закроет|закрывает|решит|решает)[^.!?]{0,28}(?:вопрос|сомнен|возражен|цен)|(?:вопрос|сомнен|возражен|цен)[^.!?]{0,28}(?:снимет|снят|закрыт|решен|решён|обоснован|приемлем)/u', $t) === 1;
    }

    private static function looksLikeFitDecision(array $context,string $playerText): bool {
        $mechanics=is_array($context['mechanics']??null)?$context['mechanics']:[];
        if((string)($mechanics['success_mode']??'')!=='fit_check')return false;
        $t=self::lower($playerText);
        foreach(['вам не нужен','вам это не нужно','не рекомендую','не стоит покупать','избыточ','более простой','проще реш','дешевле','минимально достаточ'] as $cue){if(str_contains($t,$cue))return true;}
        return false;
    }

    private static function priceObjectionResolved(array $context): bool {
        $rows=array_merge((array)($context['sales_reply_history']??[]),(array)($context['recent_dialogue']??[]));
        foreach($rows as $row){
            if(!is_array($row)||(string)($row['actor']??'')!=='opponent')continue;
            $text=self::lower(trim((string)($row['content']??'')));
            if($text==='')continue;
            if(preg_match('/(?:вопрос\s+цены|ценов(?:ой|ое)\s+возражен|цена)[^.!?]{0,45}(?:снят|закрыт|реш[её]н|не\s+является\s+проблем)/u',$text))return true;
        }
        return false;
    }

    private static function looksLikeValueContinuation(string $playerText): bool {
        $t=self::lower($playerText);
        return preg_match('/(?:позволит|даст|снизит|сократит|увеличит|ускорит|сэконом|прозрачн|разрознен|внедрен|использован|адаптац|контрол)/u',$t)===1;
    }

    private static function looksLikeValueArgument(string $playerText): bool {
        $t = self::lower($playerText);
        $hasValue = false;
        foreach (['окуп','расчёт','расчет','экономик','дополнительн','валов','прибыл','ценност','эффект','выгод','поможет','позволит','снизит','сократит','увеличит','ускорит','сэконом','решит','результат','избыточ','подходит','рекомендую'] as $cue) {
            if (str_contains($t, $cue)) { $hasValue = true; break; }
        }
        if (!$hasValue) { return false; }
        return preg_match('/\d/u', $t) === 1 || str_contains($t, 'ваш') || str_contains($t,'ваш') || str_contains($t,'для вас') || str_contains($t,'у вас') || str_contains($t,'на ваших данных') || str_contains($t,'на наших данных');
    }

    /**
     * SALES must progress after discovery too. These replies do not introduce
     * any hidden scenario fact: they only react to a grounded value argument or
     * to a concrete proposed next step.
     */
    public function buildProgressReply(array $context, string $playerText): string {
        if (!self::isSales($context)) { return ''; }
        $unsupported=self::specificUnsupportedMetric($context,$playerText);
        if($unsupported!==''){return $unsupported;}
        $fact=self::matchingFact($context,$playerText);
        // A direct diagnostic question about a matched scenario fact must be answered
        // from that grounded fact before any generic value/progress acknowledgement.
        if($fact&&str_contains($playerText,'?')){return '';}
        if($fact&&self::genericRequestsConcreteDetail($fact,$playerText)){return '';}
        $mechanics=is_array($context['mechanics']??null)?$context['mechanics']:[];
        $replies=is_array($mechanics['progress_replies']??null)?$mechanics['progress_replies']:[];
        if (self::looksLikePressureClose($playerText)) {
            return trim((string)($replies['pressure_reject']??'')) ?: 'Я не готов подписывать договор только ради скидки. Сначала мне нужна понятная экономика решения, а уже потом можно обсуждать оформление.';
        }
        if (self::looksLikeFitDecision($context,$playerText)) {
            return trim((string)($replies['fit_accept']??$replies['next_step_accept']??'')) ?: 'Такой честный вывод мне подходит. Лучше решить задачу проще, чем покупать лишнее.';
        }
        if (self::looksLikeAuditOffer($playerText)) {
            return 'Что именно вы предлагаете проверить в рамках аудита, сколько времени это займёт у нас и что мы получим по итогам?';
        }
        if (self::looksLikeObjectionResolutionCheck($playerText)) {
            return 'Да, при таком расчёте вопрос цены для меня снят. Теперь важно подтвердить исходные цифры и пройти согласование.';
        }
        if (self::looksLikeConcreteNextStep($playerText)) {
            $reply=trim((string)($replies['next_step_accept']??''));
            if($reply==='')return 'Да, мне такой следующий шаг подходит. Подготовьте расчёт на наших данных, и я готов обсудить его на отдельной встрече с теми, кто участвует в согласовании.';
            $lower=self::lower($reply);
            $hasAnchor=preg_match('/(?<![\p{L}\p{N}_])(?:я|мне|меня|мой|моя|моё|мои|мы|нам|нас|наш|наша|наше|наши)(?![\p{L}\p{N}_])/u',$lower)===1
                ||str_contains($lower,'у нас')||str_contains($lower,'для нас')||str_contains($lower,'в нашей');
            return $hasAnchor?$reply:('Мне подходит такой вариант. '.$reply);
        }
        if (self::priceObjectionResolved($context) && self::looksLikeValueContinuation($playerText)) {
            return 'Да, это относится к нашей ситуации. Вопрос цены мы уже сняли; теперь для меня важно понять, как это будет реализовано на практике.';
        }
        if (self::looksLikeValueArgument($playerText)) {
            return trim((string)($replies['value_ack']??'')) ?: 'Такой расчёт уже выглядит предметно. Если он опирается на наши данные, это можно обсуждать дальше.';
        }
        return '';
    }

    /**
     * Build a reply only when the player's message maps to one concrete hidden
     * fact. This is used before the language model so discovery turns are always
     * grounded in scenario data and cannot invent process details.
     */
    public function buildFactReply(array $context, string $playerText): string {
        if (!self::isSales($context)) { return ''; }
        if (self::looksLikeAuditOffer($playerText)) { return ''; }
        $fact = self::matchingFact($context, $playerText);
        if (!$fact) { return ''; }
        if (str_starts_with((string)($fact['code']??''),'adaptive_') && self::adaptiveCueScore($fact,$playerText)>0) {
            return self::firstPersonFact($fact,$context);
        }
        $topic = self::factTopic($fact);
        $playerTopic=self::topic($playerText);
        if ($topic !== '' && $playerTopic !== '' && $topic === $playerTopic) {
            if (!self::requestsConcreteDetail($topic, $playerText)) { return self::partialReply($topic); }
            return self::firstPersonFact($fact, $context);
        }
        if (!self::genericRequestsConcreteDetail($fact,$playerText)) { return 'Да, это для нас значимый вопрос. Уточните, что именно вы хотите понять, и я отвечу конкретнее.'; }
        return self::firstPersonFact($fact,$context);
    }

    public function build(array $context, string $playerText): string {
        if (!self::isSales($context)) { return ''; }

        $progressReply = $this->buildProgressReply($context, $playerText);
        if ($progressReply !== '') { return $progressReply; }

        $factReply = $this->buildFactReply($context, $playerText);
        if ($factReply !== '') { return $factReply; }

        $position = is_array($context['external_position'] ?? null) ? $context['external_position'] : [];
        $statement = trim((string)($position['statement'] ?? ''));
        if ($statement !== '' && !self::priceObjectionResolved($context)) { return $statement; }
        if (self::priceObjectionResolved($context)) { return 'Да, вопрос цены мы уже обсудили. Давайте двигаться дальше по существу.'; }

        return 'Для нас это пока недостаточно убедительно. Мне нужна конкретика именно по нашей ситуации.';
    }
}

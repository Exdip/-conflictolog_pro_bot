<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class OpponentUnavailableException extends \RuntimeException {}
final class OpponentBusyException extends \RuntimeException {}

final class OpponentService {
    private MessageRepository $messages;
    private SessionRepository $sessions;
    private OpponentContextBuilder $contexts;
    private OpponentPromptBuilder $prompts;
    private OpponentResponseValidator $validator;
    private $transport;

    public function __construct(
        ?MessageRepository $messages = null,
        ?SessionRepository $sessions = null,
        ?OpponentContextBuilder $contexts = null,
        ?OpponentPromptBuilder $prompts = null,
        ?OpponentResponseValidator $validator = null,
        ?callable $transport = null
    ) {
        $this->messages = $messages ?: new MessageRepository();
        $this->sessions = $sessions ?: new SessionRepository();
        $this->contexts = $contexts ?: new OpponentContextBuilder($this->messages);
        $this->prompts = $prompts ?: new OpponentPromptBuilder();
        $this->validator = $validator ?: new OpponentResponseValidator();
        $this->transport = $transport;
    }

    private static function lowerFirst(string $text): string {
        $text = trim($text);
        if ($text === '') { return ''; }
        if (function_exists('mb_substr') && function_exists('mb_strtolower')) {
            return mb_strtolower(mb_substr($text, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($text, 1, null, 'UTF-8');
        }
        $map = ['А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з','И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р','С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ','Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'];
        foreach ($map as $upper => $lower) {
            if (str_starts_with($text, $upper)) { return $lower . substr($text, strlen($upper)); }
        }
        return strtolower(substr($text, 0, 1)) . substr($text, 1);
    }

    private static function inlineListDescriptions(string $text): string {
        $lines = preg_split('/\R/u', $text) ?: [$text];
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim((string) $lines[$i]);
            if (preg_match('/^(\s*)-\s+(.+?):\s*$/u', $line, $head) && isset($lines[$i + 1])) {
                $next = (string) $lines[$i + 1];
                if (preg_match('/^(\s+)-\s+(.+?)\s*$/u', $next, $detail) && strlen($detail[1]) > strlen($head[1])) {
                    $description = preg_replace('/[.!?;:]+$/u', '', trim($detail[2])) ?? trim($detail[2]);
                    $description = self::lowerFirst($description);
                    $out[] = $head[1] . '- ' . trim($head[2]) . ' (' . $description . ').';
                    $i++;
                    continue;
                }
            }
            $out[] = $line;
        }
        return implode("\n", $out);
    }

    private static function cleanUserFacingText(string $text): string {
        $text = str_replace('**', '', $text);
        $text = str_replace('*', '', $text);
        $text = preg_replace('/(^|\R)\s*\d{1,3}[\.)]\s+/u', '$1- ', $text) ?? $text;
        $text = preg_replace('/(^|\R)\s*[•●▪]\s*/u', '$1- ', $text) ?? $text;
        $text = self::inlineListDescriptions($text);
        // User-facing list style: one item per line, no colon before the parenthetical explanation.
        $text = preg_replace('/(^|\R)(\s*-\s+[^()\r\n:]+):\s+\(/u', '$1$2 (', $text) ?? $text;
        // Normalize flat AI bullets such as "- Цена: Если срок критичен..." to
        // "- Цена (если срок критичен...).". This also fixes previously stored replies on display.
        $text = preg_replace_callback('/(^|\R)(\s*-\s+)([^()\r\n:]{1,120}):\s+([A-Za-zА-Яа-яЁё][^\r\n]*)(?=\R|$)/u', static function(array $m): string {
            $title = trim((string) $m[3]);
            $description = trim((string) $m[4]);
            if ($title === '' || $description === '') { return (string) $m[0]; }
            $description = preg_replace('/[.!?;:]+$/u', '', $description) ?? $description;
            $description = self::lowerFirst($description);
            return (string) $m[1] . (string) $m[2] . $title . ' (' . $description . ').';
        }, $text) ?? $text;
        return trim($text);
    }

    private function request(array $messages, int $timeout = 20): string {
        if ($this->transport) {
            $content = ($this->transport)($messages, $timeout);
            if (!is_string($content)) { throw new OpponentUnavailableException('Не удалось получить ответ оппонента.'); }
            return $content;
        }
        if (!function_exists('ckm_quiz_pro_aipuffer_post')) { throw new OpponentUnavailableException('ИИ-подключение недоступно.'); }
        $settings = function_exists('ckm_quiz_pro_solution_price_ai_settings')
            ? ckm_quiz_pro_solution_price_ai_settings()
            : ['provider'=>'openai','model'=>'gpt-4o-mini'];
        $body = [
            'provider' => (string) ($settings['provider'] ?? 'openai'),
            'model' => (string) ($settings['model'] ?? 'gpt-4o-mini'),
            'messages' => $messages,
            'ai_params' => ['temperature'=>0.55,'max_completion_tokens'=>320],
            'stream' => false,
        ];
        $response = ckm_quiz_pro_aipuffer_post($body, $timeout);
        if (is_wp_error($response)) { throw new OpponentUnavailableException('Не удалось получить ответ оппонента.'); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int) wp_remote_retrieve_response_code($response) : 0;
        if ($code < 200 || $code >= 300) { throw new OpponentUnavailableException('Не удалось получить ответ оппонента.'); }
        $raw = function_exists('wp_remote_retrieve_body') ? (string) wp_remote_retrieve_body($response) : '';
        $json = json_decode($raw, true);
        $content = is_array($json) ? (string) ($json['content'] ?? $json['reply'] ?? '') : '';
        if ($content === '') { throw new OpponentUnavailableException('Не удалось получить ответ оппонента.'); }
        return $content;
    }

    public function ensureReply(int $sessionId, int $playerMessageId): array {
        $existing = $this->messages->findReplyTo($sessionId, $playerMessageId);
        if ($existing) {
            $session = Access::session($sessionId);
            if (in_array($session['processing_status'], ['opponent_generating','opponent_failed','opponent_generation_pending','player_analyzed'], true)) {
                $this->sessions->setProcessingStatus($sessionId, 'opponent_saved', [$session['processing_status']]);
            }
            return ['message'=>$existing,'generated'=>false];
        }

        $player = $this->messages->findById($sessionId, $playerMessageId);
        if (!$player || $player['actor'] !== 'player' || !in_array($player['channel'], ['dialogue','negotiation'], true)) {
            throw new \InvalidArgumentException('Player message is unavailable.');
        }
        if (!$this->sessions->setProcessingStatus($sessionId, 'opponent_generating', ['opponent_generation_pending','player_analyzed','idle','opponent_failed'])) {
            $existing = $this->messages->findReplyTo($sessionId, $playerMessageId);
            if ($existing) { return ['message'=>$existing,'generated'=>false]; }
            throw new OpponentBusyException('Ответ оппонента уже формируется.');
        }

        try {
            $context = $this->contexts->build($sessionId, $playerMessageId);
            $validated = null;
            $isSales = SalesClientFallback::isSales($context);
            $generationError = null;

            // SALES first reacts to a grounded value argument or a concrete next step.
            // Only if this is still a discovery turn do we answer from the single
            // scenario fact whose reveal contract is actually probed; that path cannot invent operational details. This prevents
            // late-stage value/next-step messages from being hijacked by an already
            // known hidden fact mentioned in the same sentence.
            $salesFallback = $isSales ? new SalesClientFallback() : null;
            $duplicateAvoid = [];
            if ($isSales && $salesFallback) {
                $progressReply = $salesFallback->buildProgressReply($context, (string)($player['content'] ?? ''));
                if ($progressReply !== '') {
                    try {
                        $validated = self::cleanUserFacingText($this->validator->validate($progressReply, $context));
                    } catch (\UnexpectedValueException $fallbackValidationError) {
                        $generationError = $fallbackValidationError;
                        $validated = null;
                    }
                }
                if (!is_string($validated) || $validated === '') {
                    $factReply = $salesFallback->buildFactReply($context, (string)($player['content'] ?? ''));
                    if ($factReply !== '') {
                        try {
                            $validated = self::cleanUserFacingText($this->validator->validate($factReply, $context));
                        } catch (\UnexpectedValueException $fallbackValidationError) {
                            $generationError = $fallbackValidationError;
                            $validated = null;
                        }
                    }
                }
                if (is_string($validated) && $validated !== '') {
                    $duplicate = $salesFallback->recentDuplicate($context, $validated);
                    if ($duplicate !== '') {
                        $duplicateAvoid[] = $duplicate;
                        $validated = null;
                    }
                }
            }

            if (!is_string($validated) || $validated === '') {
                for ($attempt = 0; $attempt < 2; $attempt++) {
                    try {
                        $promptMessages = $this->prompts->messages($context, $attempt > 0 || !empty($duplicateAvoid));
                        if ($isSales && !empty($duplicateAvoid) && isset($promptMessages[0]['content'])) {
                            $promptMessages[0]['content'] .= "\n\nАНТИДУБЛЬ: не повторяй и не перефразируй почти дословно последние ответы клиента: "
                                . wp_json_encode(array_values(array_unique($duplicateAvoid)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                                . ". Ответь именно на последнюю реплику продавца и продвинь разговор.";
                        }
                        $raw = $this->request($promptMessages);
                        $validated = self::cleanUserFacingText($this->validator->validate($raw, $context));
                        if ($isSales && $salesFallback) {
                            $duplicate = $salesFallback->recentDuplicate($context, $validated);
                            if ($duplicate !== '') {
                                $duplicateAvoid[] = $duplicate;
                                $validated = null;
                                if ($attempt === 0) { continue; }
                            }
                        }
                        if (is_string($validated) && $validated !== '') { break; }
                    } catch (\UnexpectedValueException $validationError) {
                        $generationError = $validationError;
                        if (!$isSales && $attempt > 0) { throw $validationError; }
                    } catch (OpponentUnavailableException $requestError) {
                        $generationError = $requestError;
                        if (!$isSales) { throw $requestError; }
                        break;
                    }
                }
            }
            // SALES must never dead-end because the language model twice leaves the client role.
            // The fallback is deterministic and uses only the single scenario fact whose
            // reveal topic is actually probed by the player's message; it cannot invent data.
            if ((!is_string($validated) || $validated === '') && $isSales) {
                $salesFallback = $salesFallback ?: new SalesClientFallback();
                $fallback = $salesFallback->build($context, (string)($player['content'] ?? ''));
                if ($fallback !== '') {
                    try {
                        $fallback = self::cleanUserFacingText($this->validator->validate($fallback, $context));
                    } catch (\UnexpectedValueException $fallbackValidationError) {
                        $generationError = $fallbackValidationError;
                        $fallback = 'Для меня это пока недостаточно конкретно. Хочу понять, что именно изменится в нашей ситуации.';
                    }
                    if ($salesFallback->recentDuplicate($context, $fallback) === '') {
                        $validated = $fallback;
                    } else {
                        // Keep the deterministic SALES safety net from becoming its own loop.
                        // recentDuplicate() checks four previous client replies, so five distinct
                        // neutral prompts guarantee that one remains available without touching
                        // negotiation runtime or inventing scenario facts.
                        $loopReplies = [
                            'Мне пока не хватает новой конкретики. Что именно вы предлагаете сделать дальше?',
                            'Мы это уже обсудили. Что нового вы можете предложить по нашей ситуации?',
                            'Для меня важен конкретный результат. Что изменится после вашего предложения?',
                            'Мне нужен новый предмет обсуждения: что конкретно вы хотите проверить или показать?',
                            'Хорошо. Что именно вы предлагаете сделать дальше?',
                        ];
                        foreach ($loopReplies as $loopReply) {
                            if ($salesFallback->recentFallbackDuplicate($context, $loopReply) === '') {
                                $validated = $loopReply;
                                break;
                            }
                        }
                    }
                }
            }
            if (!is_string($validated) || $validated === '') {
                if ($generationError instanceof OpponentUnavailableException) { throw $generationError; }
                throw new OpponentUnavailableException('Не удалось получить корректный ответ оппонента.');
            }
            try {
                $this->messages->appendOpponent($sessionId, $playerMessageId, $validated);
            } catch (\Throwable $writeError) {
                $existing = $this->messages->findReplyTo($sessionId, $playerMessageId);
                if (!$existing) { throw $writeError; }
            }
            $reply = $this->messages->findReplyTo($sessionId, $playerMessageId);
            if (!$reply) { throw new OpponentUnavailableException('Ответ оппонента не удалось сохранить.'); }
            if (!$this->sessions->setProcessingStatus($sessionId, 'opponent_saved', ['opponent_generating'])) {
                $session = Access::session($sessionId);
                if ($session['processing_status'] !== 'opponent_saved') { throw new OpponentUnavailableException('Ответ получен, но сессия требует восстановления.'); }
            }
            return ['message'=>$reply,'generated'=>true];
        } catch (OpponentBusyException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $session = Access::session($sessionId);
            if ($session['processing_status'] === 'opponent_generating') {
                $this->sessions->setProcessingStatus($sessionId, 'opponent_failed', ['opponent_generating']);
            }
            if ($error instanceof OpponentUnavailableException) { throw $error; }
            throw new OpponentUnavailableException('Не удалось получить ответ оппонента.');
        }
    }
}

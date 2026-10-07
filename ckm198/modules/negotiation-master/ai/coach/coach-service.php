<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class CoachNotAvailableException extends \RuntimeException {}
final class CoachBusyException extends \RuntimeException {}
final class CoachUnavailableException extends \RuntimeException {}

final class CoachService {
    private static function cleanUserFacingText(string $text): string {
        $text=str_replace(['**','*'],'',$text);
        $text=preg_replace('/(^|\R)\s*\d{1,3}[\.)]\s+/u','$1- ',$text)??$text;
        $text=preg_replace('/(^|\R)\s*[•●▪]\s*/u','$1- ',$text)??$text;
        return trim($text);
    }
    private static function safeFallback(array $context, string $level): string {
        $isSales = (string)($context['training_domain'] ?? '') === 'sales';
        if ($isSales) {
            return match ($level) {
                'attention' => 'В разговоре пока есть важный пробел: не до конца понятно, что стоит за текущей позицией клиента.',
                'direction' => 'Уточните, что именно стоит за позицией клиента и какой результат он считает достаточным для следующего шага.',
                'example' => 'Что для вас сейчас важнее всего при принятии решения и какой результат сделал бы следующий шаг оправданным?',
                'review_last_move' => 'В последней реплике есть движение к цели, но важно не подменять выяснение интересов собственными предположениями. Сохраняйте опору на уже сказанное клиентом.',
                default => 'Сфокусируйтесь на уже сказанном собеседником и на одном следующем шаге разговора.',
            };
        }
        return match ($level) {
            'attention' => 'В разговоре пока есть важный пробел: не до конца понятны основания текущей позиции другой стороны.',
            'direction' => 'Уточните, что именно стоит за текущей позицией другой стороны и какой критерий для неё сейчас наиболее важен.',
            'example' => 'Что для вас сейчас важнее всего в этом вопросе и что помогло бы продвинуться дальше?',
            'review_last_move' => 'Последняя реплика поддерживает диалог, но важно не подменять выяснение интересов собственными предположениями. Сохраняйте опору на уже сказанное другой стороной.',
            default => 'Сфокусируйтесь на уже сказанном собеседником и на одном следующем шаге разговора.',
        };
    }

    private MessageRepository $messages;
    private PlayerSessionSnapshotBuilder $snapshots;
    private InGameCoachContextBuilder $contexts;
    private CoachPromptBuilder $prompts;
    private CoachResponseValidator $validator;
    private $transport;

    public function __construct(
        ?MessageRepository $messages = null,
        ?PlayerSessionSnapshotBuilder $snapshots = null,
        ?InGameCoachContextBuilder $contexts = null,
        ?CoachPromptBuilder $prompts = null,
        ?CoachResponseValidator $validator = null,
        ?callable $transport = null
    ) {
        $this->messages = $messages ?: new MessageRepository();
        $this->snapshots = $snapshots ?: new PlayerSessionSnapshotBuilder(null, $this->messages);
        $this->contexts = $contexts ?: new InGameCoachContextBuilder($this->snapshots);
        $this->prompts = $prompts ?: new CoachPromptBuilder();
        $this->validator = $validator ?: new CoachResponseValidator();
        $this->transport = $transport;
    }

    private function request(array $messages, int $timeout = 18): string {
        if ($this->transport) {
            $content = ($this->transport)($messages, $timeout);
            if (!is_string($content)) { throw new CoachUnavailableException('Не удалось получить подсказку тренера.'); }
            return $content;
        }
        if (!function_exists('ckm_quiz_pro_aipuffer_post')) { throw new CoachUnavailableException('ИИ-подключение недоступно.'); }
        $settings = function_exists('ckm_quiz_pro_solution_price_ai_settings')
            ? ckm_quiz_pro_solution_price_ai_settings()
            : ['provider'=>'openai','model'=>'gpt-4o-mini'];
        $body = [
            'provider' => (string)($settings['provider'] ?? 'openai'),
            'model' => (string)($settings['model'] ?? 'gpt-4o-mini'),
            'messages' => $messages,
            'ai_params' => ['temperature'=>0.35,'max_completion_tokens'=>260],
            'stream' => false,
        ];
        $response = ckm_quiz_pro_aipuffer_post($body, $timeout);
        if (is_wp_error($response)) { throw new CoachUnavailableException('Не удалось получить подсказку тренера.'); }
        $code = function_exists('wp_remote_retrieve_response_code') ? (int)wp_remote_retrieve_response_code($response) : 0;
        if ($code < 200 || $code >= 300) { throw new CoachUnavailableException('Не удалось получить подсказку тренера.'); }
        $raw = function_exists('wp_remote_retrieve_body') ? (string)wp_remote_retrieve_body($response) : '';
        $json = json_decode($raw, true);
        $content = is_array($json) ? (string)($json['content'] ?? $json['reply'] ?? '') : '';
        if ($content === '') { throw new CoachUnavailableException('Не удалось получить подсказку тренера.'); }
        return $content;
    }

    public function help(int $sessionId, string $level, string $clientRequestId): array {
        $session = Access::session($sessionId);
        if (($session['status'] ?? '') !== 'in_progress') { throw new \RuntimeException('Session is not active.'); }
        if (($session['mode'] ?? '') !== 'training') { throw new CoachNotAvailableException('Coach is unavailable in exam mode.'); }
        if (($session['processing_status'] ?? '') !== 'idle') { throw new CoachBusyException('Подождите завершения текущего хода.'); }
        if (!in_array($level, ['attention','direction','example','review_last_move'], true)) { throw new \InvalidArgumentException('Unsupported coach help level.'); }
        if (!preg_match('/^[A-Za-z0-9._:-]{1,96}$/D', $clientRequestId)) { throw new \InvalidArgumentException('Invalid coach request ID.'); }

        $existing = $this->messages->findByClientId($sessionId, $clientRequestId);
        if ($existing) {
            if (($existing['channel'] ?? '') !== 'coach' || ($existing['actor'] ?? '') !== 'coach' || ($existing['coach_level'] ?? '') !== $level) {
                throw new \InvalidArgumentException('Coach request ID conflicts with another message.');
            }
            return ['idempotent'=>true,'coach_message'=>$existing,'snapshot'=>$this->snapshots->build($sessionId)];
        }

        $context = $this->contexts->build($sessionId, $level);
        $validated = null;
        for ($attempt=0; $attempt<2; $attempt++) {
            try {
                $raw = $this->request($this->prompts->messages($context, $attempt > 0));
                $validated = self::cleanUserFacingText($this->validator->validate($raw, $level));
                break;
            } catch (\UnexpectedValueException $validationError) {
                // Первый неподходящий черновик автоматически отправляем на строгую перегенерацию.
                // Если и второй черновик не проходит формат, пользователю не показываем
                // техническую ошибку валидатора — выдаём короткую безопасную подсказку.
                if ($attempt > 0) {
                    $fallback = self::safeFallback($context, $level);
                    $validated = self::cleanUserFacingText($this->validator->validate($fallback, $level));
                    break;
                }
            }
        }
        if (!is_string($validated) || $validated === '') { throw new CoachUnavailableException('Не удалось получить корректную подсказку тренера.'); }

        $relatedId = isset($context['last_player_message']['id']) ? (int)$context['last_player_message']['id'] : null;
        try {
            $this->messages->appendCoach($sessionId, $clientRequestId, $level, $relatedId, $validated);
        } catch (\Throwable $writeError) {
            $existing = $this->messages->findByClientId($sessionId, $clientRequestId);
            if (!$existing || ($existing['channel'] ?? '') !== 'coach') { throw $writeError; }
        }
        $saved = $this->messages->findByClientId($sessionId, $clientRequestId);
        if (!$saved) { throw new CoachUnavailableException('Подсказку тренера не удалось сохранить.'); }
        return ['idempotent'=>false,'coach_message'=>$saved,'snapshot'=>$this->snapshots->build($sessionId)];
    }
}

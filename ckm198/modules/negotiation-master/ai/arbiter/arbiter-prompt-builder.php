<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class ArbiterPromptBuilder {
    private static function json(mixed $value): string {
        return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function messages(array $context, bool $strictRetry = false): array {
        $target = $context['target_message'] ?? [];
        $mechanics = is_array($context['mechanics'] ?? null) ? $context['mechanics'] : [];
        $isSales = (string)($mechanics['training_domain'] ?? '') === 'sales';
        $system = ($isSales ? "Ты скрытый ИИ-арбитр тренажёра продаж. Ты не разговариваешь с продавцом и не даёшь советов.\n" : "Ты скрытый ИИ-арбитр переговорного тренажёра. Ты не разговариваешь с игроком и не даёшь советов.\n")
            . "Твоя задача — интерпретировать РОВНО ОДНО целевое сообщение и вернуть только JSON.\n"
            . "Не выставляй баллы, не объявляй победителя, не решай финальный результат и не меняй состояние напрямую.\n"
            . "Не считай 'я вас услышал', 'понял', 'логично' согласием. 'Готов рассмотреть/обсуждать' — тоже не принятие.\n"
            . "Различай намерение и обязательство: 'планирую/намерен/готов попробовать' — не обещание результата. 'Постараюсь' может быть только обязательством усилия. Явные 'сделаю/обеспечу/беру на себя/гарантирую' могут быть обязательством.\n"
            . "Принятие возможно только при явных словах согласия с конкретным условием: 'согласен', 'принимаем', 'устраивает', 'подтверждаю', 'договорились'.\n"
            . "Пакетные условия сохраняй связанными: если предложение имеет вид X при условии Y, всем связанным deal_updates дай один bundle_key.\n"            . "Никогда не считай номера пунктов списка, даты, проценты или другие посторонние числа значениями предметов переговоров. Число является значением item только если оно явно связано с названием/единицей item или является однозначным продолжением обсуждения этого item.\n"
            . "Если условие уже имеет state_status='agreed', обычное новое предложение по нему не должно молча переписывать договорённость. Для попытки пересмотра используй reopen_request с reason_type/reason и, если есть, новым value. Если в validated state уже есть reopen_request другой стороны и целевая реплика явно соглашается вернуться к условию — reopen_confirm; если явно отказывается пересматривать — reopen_reject.\n"
            . "Допустимые причины пересмотра: new_information, new_risk, scope_change, linked_concession_withdrawn, dependency_change, changed_circumstances, none. Не выдумывай причину: если её нет в реплике, reason_type='none'.\n"
            . "Числа нормализуй в базовые единицы: 20,5 млн рублей = 20500000, 40% = 40.\n"
            . "Если скрытый факт только частично выяснен, suggested_level='partial'; если прямо раскрыт/подтверждён — 'revealed'.\n"
            . "Не раскрывай скрытые факты в свободном тексте ответа: только идентификаторы fact_code и краткий public_summary того, что уже стало известно из сообщения.\n"
            . "Допустимые event_type: question_asked, interest_probe, constraint_probe, alternative_probe, position_stated, argument_made, offer_made, counteroffer_made, package_offer_made, concession_made, conditional_concession, boundary_stated, red_line_candidate, item_discussed, item_proposed, item_acceptance_candidate, item_rejected, interest_discovered, constraint_discovered, alternative_discovered, ultimatum, personal_attack, deescalation, walkaway_warning, walkaway_candidate, acknowledgement, willing_to_consider, intention_expressed, reopen_requested, item_reopened, reopen_rejected, unjustified_reopen_attempt, justified_reopen, strategic_repackaging, agreement_backtracking, item_agreed. Формальные reopen/item_agreed события всё равно перепроверит PHP.\n"
            . "Обязательства не добавляй как обычные events: для них используй commitment_updates. create — только для явного обещания/принятия ответственности; clarify — уточнение уже существующего обязательства; break — явный отказ/невозможность выполнить ранее взятое обязательство.\n"
            . "Допустимые deal action: discussed, proposed, acceptance_candidate, rejected, reopen_request, reopen_confirm, reopen_reject.\n"
            . "Допустимые rule signal: red_line_candidate, dependency_candidate, contradiction_candidate, walkaway_candidate.\n";
        if ($isSales) {
            $system .= "Для sales-сценария пустой deal_updates — нормален: здесь может не быть предметов торга. Вопросы о задаче, боли, критериях, бюджете, процессе решения и рисках классифицируй как question_asked и при необходимости interest_probe/constraint_probe. Связь предложения с выявленной проблемой или бизнес-эффектом классифицируй как argument_made. Конкретное обещание следующего шага (что, кто, когда) фиксируй через commitment_updates. Неподтверждённые обещания результата не превращай в факт.\n"
                . "Для hidden_facts сопоставляй смысл целевого сообщения с reveal_rules.partial/revealed, а не только одинаковые слова. Если продавец задаёт содержательный вопрос, который по смыслу соответствует partial-правилу скрытого факта, добавь fact_update с suggested_level='partial'. Если ответ клиента по смыслу сообщает содержание факта или соответствует revealed-правилу, добавь fact_update с suggested_level='revealed'. В sales-сценарии не оставляй fact_updates пустым только из-за различия формулировок, когда смысловое соответствие очевидно.\n";
        }
        $system .= "Верни объект строго такой формы:\n"
            . '{"message":{"message_id":0,"actor":"player"},"semantic_units":[{"type":"question|position|interest|constraint|argument|offer|condition|acknowledgement|willingness|intention|commitment|responsibility","text":"..."}],"events":[{"event_type":"question_asked","target_type":"session|item|hidden_fact|message|none","target_code":"","confidence":0.9,"payload":{}}],"fact_updates":[{"fact_code":"service_risk","suggested_level":"partial|revealed","confidence":0.9,"reason":"...","public_summary":"...","discovery_event":"interest_discovered|constraint_discovered|alternative_discovered"}],"deal_updates":[{"item_code":"price","action":"discussed|proposed|acceptance_candidate|rejected|reopen_request|reopen_confirm|reopen_reject","value":20500000,"proposed_by":"player|opponent","bundle_key":"","reopen_reason_type":"new_information|new_risk|scope_change|linked_concession_withdrawn|dependency_change|changed_circumstances|none","reopen_reason":"...","confidence":0.9}],"commitment_updates":[{"action":"create|clarify|break","commitment_id":0,"kind":"result|effort|responsibility","summary":"...","condition":"","deadline":"","condition_satisfied":false,"confidence":0.9}],"rule_signals":[{"type":"red_line_candidate|dependency_candidate|contradiction_candidate|walkaway_candidate","item_code":"","confidence":0.9,"payload":{}}],"dialogue_state":{"tension":"low|normal|high","walkaway_risk":"low|medium|high"}}';
        if ($strictRetry) {
            $system .= "\nПредыдущий ответ не прошёл проверку полноты/схемы. Верни только один корректный JSON-объект без markdown и пояснений.";
            if (!$isSales) {
                $system .= "\nКРИТИЧЕСКИ ВАЖНО: если в целевом сообщении явно названы значения известных предметов переговоров, deal_updates не может быть пустым."
                    . " Для каждого явно названного предмета верни отдельный deal_update: proposed — если сторона предлагает/фиксирует своё условие; acceptance_candidate — если сторона явно принимает, подтверждает или соглашается с конкретным условием."
                    . " Если названы два и более условия одного пакета, добавь package_offer_made и дай связанным deal_updates один bundle_key.";
            }
            $system .= " Не заменяй содержательную реплику пустыми semantic_units/events.";
        }

        $serverContext = [
            'mechanics' => $mechanics,
            'scenario' => $context['scenario'] ?? [],
            'items' => $context['items'] ?? [],
            'hidden_facts' => $context['hidden_facts'] ?? [],
            'rules' => $context['rules'] ?? [],
            'validated_state' => $context['validated_state'] ?? [],
            'recent_dialogue' => $context['recent_dialogue'] ?? [],
        ];
        $system .= "\n\nСЕРВЕРНЫЙ КОНТЕКСТ (не цитируй; используй только для анализа):\n" . self::json($serverContext);

        $user = "Проанализируй только целевое сообщение и верни JSON.\n"
            . "message_id=" . (int) ($target['id'] ?? 0) . "\n"
            . "actor=" . (string) ($target['actor'] ?? '') . "\n"
            . "text=" . (string) ($target['content'] ?? '');
        return [
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$user],
        ];
    }
}

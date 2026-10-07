<?php
if (!defined('ABSPATH')) exit;

/**
 * «Переговорные поединки» — одна entitlement-семья, четыре пользовательских режима:
 *  - sales: продажи;
 *  - business: деловые переговоры;
 *  - express: экспресс-раунд;
 *  - communicate: четырёхраундовая игра «Переговори другого».
 *
 * Runtime intentionally reuses the stable sequential text-answer engine. Each
 * stage represents a concrete negotiation move/reply. After every stage the
 * answer is evaluated by AI Puffer when configured, with a deterministic local
 * rubric fallback so the game remains usable offline.
 */

function ckm_quiz_pro_negotiation_mode(string $mode): string {
    $mode = sanitize_key($mode);
    return in_array($mode, ['sales','business','express','communicate'], true) ? $mode : 'sales';
}

function ckm_quiz_pro_negotiation_mode_title(string $mode): string {
    $mode = ckm_quiz_pro_negotiation_mode($mode);
    if ($mode === 'communicate') return 'Переговори другого';
    if ($mode === 'business') return 'Мастер переговоров';
    if ($mode === 'express') return 'Экспресс-раунд';
    return 'Эффективный продажник';
}


function ckm_quiz_pro_quiz_negotiation_mode(array $quiz): string {
    if (sanitize_key((string)($quiz['format_key'] ?? '')) !== 'negotiation_duel') return '';
    $settings=json_decode((string)($quiz['format_settings_json'] ?? ''),true);
    if (!is_array($settings)) $settings=[];
    return ckm_quiz_pro_negotiation_mode((string)($settings['negotiationMode'] ?? 'sales'));
}

function ckm_quiz_pro_quiz_is_persuade_me(array $quiz): bool {
    return ckm_quiz_pro_quiz_negotiation_mode($quiz) === 'communicate';
}

function ckm_quiz_pro_quiz_format_title(array $quiz): string {
    if (sanitize_key((string)($quiz['format_key'] ?? '')) === 'negotiation_duel') {
        $mode=ckm_quiz_pro_quiz_negotiation_mode($quiz);
        if($mode==='communicate'){
            $settings=json_decode((string)($quiz['format_settings_json'] ?? ''),true);
            if(!is_array($settings)) $settings=[];
            $variant=sanitize_key((string)($settings['persuadeMeVariant'] ?? ''));
            $labels=[
                'school'=>'Переговори другого — для школьников',
                'school_grade'=>'Переговори другого — для школьников: «Двойка, которой не было»',
                'student'=>'Переговори другого — для студентов',
                'leader'=>'Переговори другого — для руководителей',
                'family'=>'Переговори другого — Семейные ситуации',
            ];
            if(isset($labels[$variant])) return $labels[$variant];
        }
        return ckm_quiz_pro_negotiation_mode_title($mode);
    }
    return function_exists('ckm_quiz_pro_format_title')
        ? ckm_quiz_pro_format_title((string)($quiz['format_key'] ?? 'classic_quiz'))
        : 'Игра';
}

function ckm_quiz_pro_negotiation_default_seconds(string $mode): int {
    $mode = ckm_quiz_pro_negotiation_mode($mode);
    if ($mode === 'communicate') return 30;
    if ($mode === 'express') return 60;
    if ($mode === 'business') return 180;
    return 120;
}

function ckm_quiz_pro_negotiation_settings(string $mode='sales', int $seconds=120, array $old=[]): array {
    $mode = ckm_quiz_pro_negotiation_mode($mode);
    $seconds = max(30, min(600, $seconds > 0 ? $seconds : ckm_quiz_pro_negotiation_default_seconds($mode)));
    $profiles = [
        'communicate'=>['request_specificity','respect','interests','flexibility','objections','agreement_fixation','emotional_control'],
        'sales'=>['active_listening','questions','value_presentation','objection_handling','next_step','result'],
        'business'=>['interests','position_control','options','concessions','argumentation','agreement_quality'],
        'express'=>['reaction_speed','frame_control','clarity','pressure_resistance','initiative','next_move'],
    ];
    return array_merge($old, [
        'negotiationMode'=>$mode,
        'answerType'=>'text',
        'judgeMode'=>'ai',
        'opponentMode'=>'scenario',
        'secondsPerTurn'=>$seconds,
        'assessmentProfile'=>$profiles[$mode],
        'autoCloseWhenAllAnswered'=>true,
        'victoryMode'=>'judge_rating',
        'standaloneVersion'=>'negotiation_duel_v1',
    ]);
}

function ckm_quiz_pro_negotiation_editor(int $i, string $text, string $criteria, int $points, string $explanation): void {
    echo '<div class="postbox ckm-qp-q"><div class="postbox-header"><h2>Реплика '.($i+1).'</h2></div><div class="inside">';
    echo '<p><textarea name="questions['.$i.'][text]" rows="5" style="width:100%" placeholder="Ситуация, реплика оппонента и задача игрока">'.esc_textarea($text).'</textarea></p>';
    echo '<p><textarea name="questions['.$i.'][criteria]" rows="2" style="width:100%" placeholder="Критерии оценки — по одному в строке">'.esc_textarea($criteria).'</textarea></p>';
    echo '<p>Баллы: <input type="number" name="questions['.$i.'][points]" value="'.(int)$points.'" min="1" max="100" style="width:80px"></p>';
    echo '<p><textarea name="questions['.$i.'][explanation]" rows="2" style="width:100%" placeholder="Методический ориентир / что должно быть достигнуто">'.esc_textarea($explanation).'</textarea></p>';
    echo '</div></div>';
}

function ckm_quiz_pro_org_negotiation_editor(int $i, string $text, string $criteria, int $points, string $explanation): void {
    echo '<div class="ckm-q-card"><div class="ckm-q-title">Реплика '.($i+1).'</div>';
    echo '<label class="ckm-label">Ситуация / реплика оппонента / задача<textarea class="ckm-input" name="questions['.$i.'][text]" rows="5">'.esc_textarea($text).'</textarea></label>';
    echo '<label class="ckm-label">Критерии оценки<textarea class="ckm-input" name="questions['.$i.'][criteria]" rows="2" placeholder="По одному критерию в строке">'.esc_textarea($criteria).'</textarea></label>';
    echo '<label class="ckm-label">Баллы<input class="ckm-input" type="number" name="questions['.$i.'][points]" value="'.(int)$points.'" min="1" max="100"></label>';
    echo '<label class="ckm-label">Методический ориентир<textarea class="ckm-input" name="questions['.$i.'][explanation]" rows="2">'.esc_textarea($explanation).'</textarea></label>';
    echo '</div>';
}

function ckm_quiz_pro_negotiation_seed_one(string $slug, string $title, string $mode, array $items): void {
    global $wpdb;
    $qz = ckm_quiz_pro_table('quizzes');
    $qq = ckm_quiz_pro_table('questions');
    $rr = ckm_quiz_pro_table('rounds');
    $now = current_time('mysql');
    $mode = ckm_quiz_pro_negotiation_mode($mode);
    $seconds = ckm_quiz_pro_negotiation_default_seconds($mode);
    $settings = ckm_quiz_pro_negotiation_settings($mode, $seconds);

    $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$qz} WHERE slug=%s LIMIT 1", $slug), ARRAY_A);
    if ($quiz) {
        $wpdb->update($qz, [
            'title'=>$title,
            'format_key'=>'negotiation_duel',
            'tenant_id'=>0,
            'content_scope'=>'shared',
            'min_teams'=>1,
            'max_teams'=>10,
            'host_mode'=>'ai',
            'judge_mode'=>'ai',
            'seconds_per_question'=>$seconds,
            'format_settings_json'=>wp_json_encode($settings, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>$now,
        ], ['id'=>(int)$quiz['id']]);
        $quizId=(int)$quiz['id'];
        $revision=max(1,(int)$quiz['current_revision']);
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",$quizId,$revision));
        if($count>=count($items)) return;
    } else {
        $wpdb->insert($qz,[
            'title'=>$title,
            'slug'=>$slug,
            'format_key'=>'negotiation_duel',
            'tenant_id'=>0,
            'content_scope'=>'shared',
            'short_description'=>'Переговорный тренажёр: '.ckm_quiz_pro_negotiation_mode_title($mode).'. Короткие реплики, измеримая оценка и разбор после каждого хода.',
            'instructions'=>'Прочитайте ситуацию и реплику оппонента. Сформулируйте следующую реплику так, как сказали бы её в реальных переговорах. ИИ оценивает не красоту текста, а качество переговорного хода.',
            'cover_url'=>'',
            'status'=>'published',
            'current_revision'=>1,
            'min_teams'=>1,
            'max_teams'=>10,
            'host_mode'=>'ai',
            'judge_mode'=>'ai',
            'seconds_per_question'=>$seconds,
            'scoring_policy_json'=>'{}',
            'settings_json'=>wp_json_encode(['demo'=>true,'negotiationMode'=>$mode],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'created_by_user_id'=>get_current_user_id(),
            'updated_by_user_id'=>get_current_user_id(),
            'created_at'=>$now,
            'updated_at'=>$now,
            'published_at'=>$now,
        ]);
        $quizId=(int)$wpdb->insert_id;
        $revision=1;
    }
    if($quizId<=0) return;

    $roundId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-1' LIMIT 1",$quizId,$revision));
    if($roundId<=0){
        $wpdb->insert($rr,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,
            'title'=>ckm_quiz_pro_negotiation_mode_title($mode),'round_type'=>'negotiation',
            'rules_json'=>'{}','settings_json'=>wp_json_encode(['negotiationMode'=>$mode],JSON_UNESCAPED_UNICODE),
            'status'=>'active','created_at'=>$now,'updated_at'=>$now,
        ]);
        $roundId=(int)$wpdb->insert_id;
    }

    foreach($items as $i=>$item){
        $key='turn-'.($i+1);
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND question_key=%s LIMIT 1",$quizId,$revision,$key));
        if($exists>0) continue;
        $criteria=(array)($item[2]??[]);
        $wpdb->insert($qq,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>$key,'position'=>$i+1,
            'round_no'=>1,'round_title'=>ckm_quiz_pro_negotiation_mode_title($mode),'round_id'=>$roundId,
            'question_stage'=>'main','question_type'=>'text','question_text'=>(string)$item[0],
            'options_json'=>'[]','correct_answers_json'=>'[]','numeric_tolerance'=>0,
            'points'=>(int)($item[3]??20),'time_limit_seconds'=>$seconds,
            'scoring_rule_json'=>wp_json_encode(['judgeCriteria'=>$criteria,'negotiationMode'=>$mode,'manualOrAiReview'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'explanation'=>(string)($item[1]??''),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
}

function ckm_quiz_pro_seed_demo_negotiation_duel(): void {
    if(function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();

    ckm_quiz_pro_negotiation_seed_one('demo-negotiation-sales','Эффективный продажник','sales',[
        [
            "Клиент говорит: «У нас уже есть поставщик, и в целом нас всё устраивает. Зачем мне тратить время на разговор с вами?»\n\nВаша задача: ответьте одной рабочей репликой и откройте разговор, не переходя сразу к презентации продукта.",
            'Сначала создайте основание для разговора: признайте позицию клиента, задайте уместный вопрос или обозначьте конкретную причину, почему разговор может быть полезен.',
            ['active_listening','questions','relevance','no_premature_pitch'],20
        ],
        [
            "Клиент говорит: «Хорошо. Но я не вижу, чем ваше предложение лучше того, что у нас есть сейчас».\n\nВаша задача: сформулируйте следующую реплику.",
            'Не перечисляйте абстрактные преимущества. Свяжите ценность предложения с задачей или потерями клиента и при необходимости уточните критерий выбора.',
            ['value_presentation','questions','client_context'],20
        ],
        [
            "Клиент говорит: «Ваше решение примерно на 15% дороже. При такой цене смысла менять поставщика нет».\n\nВаша задача: отработайте ценовое возражение без автоматической скидки.",
            'Уточните, что именно клиент сравнивает, верните разговор к полной ценности/стоимости владения/рискам и не уступайте без встречного условия.',
            ['objection_handling','price_argumentation','concession_control'],20
        ],
        [
            "Клиент говорит: «А если вы сорвёте сроки? Для нас это гораздо опаснее разницы в цене».\n\nВаша задача: ответьте так, чтобы снизить риск для клиента.",
            'Покажите понимание риска, предложите проверяемую гарантию, пилот, контрольную точку или другой механизм снижения неопределённости.',
            ['risk_handling','trust','concrete_guarantees'],20
        ],
        [
            "Клиент говорит: «Пришлите коммерческое предложение, мы посмотрим».\n\nВаша задача: завершите разговор так, чтобы появился конкретный следующий шаг, а не неопределённое «мы вам перезвоним».",
            'Зафиксируйте конкретное действие, срок, участника и цель следующего контакта.',
            ['next_step','commitment','clarity'],20
        ],
    ]);

    ckm_quiz_pro_negotiation_seed_one('demo-negotiation-business','Мастер переговоров','business',[
        [
            "Ситуация: вы — поставщик. Вам необходимо повысить цену на 12% из-за роста себестоимости.\nПокупатель говорит: «Повышение на 12% мы не примем. У нас утверждён бюджет».\n\nВаша задача: первая ответная реплика.",
            'Не спорьте с бюджетом как с фактом. Проясните интересы и ограничения покупателя и задайте рамку для обсуждения условий, а не только процента.',
            ['interests','frame_control','questions'],20
        ],
        [
            "Покупатель говорит: «Если вы настаиваете, мы проведём тендер и найдём альтернативу».\n\nВаша задача: ответьте, сохраняя рабочие отношения и свою позицию.",
            'Не реагируйте угрозой на угрозу. Уточните реальную альтернативу, стоимость перехода и критерии решения, одновременно обозначив собственные ограничения.',
            ['pressure_resistance','batna_awareness','position_control'],20
        ],
        [
            "Покупатель говорит: «Главное для нас — бесперебойные поставки. За последний год у вас были два сбоя».\n\nВаша задача: используйте новую информацию для продвижения переговоров.",
            'Переведите спор о цене к интересу надёжности: признайте проблему и предложите конкретный обмен условиями или механизм гарантии.',
            ['interests','option_generation','trust'],20
        ],
        [
            "Покупатель говорит: «Мы готовы обсуждать 5%, если вы зафиксируете SLA и увеличите отсрочку платежа до 60 дней».\n\nВаша задача: ответьте на пакетное предложение.",
            'Оценивайте пакет целиком. Не принимайте две уступки бесплатно; связывайте уступку с встречным условием и проверяйте экономику.',
            ['concessions','package_deal','reciprocity'],20
        ],
        [
            "Финал. Сформулируйте предложение, которым вы бы зафиксировали достигнутую договорённость и следующий шаг.",
            'Нужны конкретные условия, отсутствие двусмысленности и понятный следующий шаг.',
            ['agreement_quality','clarity','next_step'],20
        ],
    ]);

    ckm_quiz_pro_negotiation_seed_one('demo-negotiation-express','Экспресс-раунд','express',[
        [
            "Оппонент говорит: «Либо сегодня скидка 20%, либо завтра мы у конкурента».\n\nУ вас 60 секунд. Ответьте следующей репликой.",
            'Сохраните инициативу, не принимайте навязанную развилку автоматически, уточните основания требования и предложите управляемый следующий ход.',
            ['frame_control','pressure_resistance','questions','next_move'],20
        ],
        [
            "Оппонент говорит: «Ваш сотрудник сорвал срок. Мне не нужны объяснения — сегодня же компенсируйте нам весь ущерб».\n\nУ вас 60 секунд. Ответьте следующей репликой.",
            'Признайте значимость проблемы, не принимайте неподтверждённый объём обязательств и переведите разговор к фактам, критериям и процедуре решения.',
            ['emotional_stability','boundary_control','facts','next_move'],20
        ],
        [
            "Оппонент говорит: «Я уже всё решил. Обсуждать нечего».\n\nУ вас 60 секунд. Ваша задача — создать возможность продолжить разговор, не унижаясь и не переходя в конфликт.",
            'Проверьте, что именно решено и что остаётся открытым; используйте вопрос, последствия или альтернативу, не усиливая конфликт.',
            ['initiative','clarity','questions','conflict_control'],20
        ],
        [
            "Оппонент говорит: «Ваше предложение мне неинтересно. Назовите одну причину, почему я должен продолжать этот разговор».\n\nУ вас 60 секунд.",
            'Дайте короткий, конкретный и релевантный ответ, связанный с интересом оппонента, а не с качествами вашего продукта вообще.',
            ['relevance','clarity','value_presentation','brevity'],20
        ],
        [
            "Оппонент говорит: «Хорошо, допустим. Что вы предлагаете сделать прямо сейчас?»\n\nУ вас 60 секунд. Зафиксируйте следующий ход.",
            'Предложите конкретное действие, критерий результата и срок, сохраняя взаимность обязательств.',
            ['next_move','commitment','clarity','reciprocity'],20
        ],
    ]);

    update_option('ckm_quiz_pro_demo_negotiation_duel_seed','1',false);
}
add_action('admin_init','ckm_quiz_pro_seed_demo_negotiation_duel',9);

/**
 * Normalize speaker labels in all built-in negotiation games.
 * The wording rule is universal: when a task contains a direct spoken line,
 * show who speaks explicitly — e.g. "Клиент говорит:", "Покупатель говорит:",
 * "Оппонент говорит:". Only CKM built-in negotiation quizzes are migrated;
 * organizer-authored content is left unchanged in storage.
 */
function ckm_quiz_pro_upgrade_builtin_speaker_phrasing(): void {
    if (get_option('ckm_quiz_pro_builtin_speaker_phrasing_v2') === '1') return;
    global $wpdb;
    $qz = ckm_quiz_pro_table('quizzes');
    $qq = ckm_quiz_pro_table('questions');
    $slugs = ['demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express'];
    $replacements = [
        'Оппонент: «'   => 'Оппонент говорит: «',
        'Клиент: «'     => 'Клиент говорит: «',
        'Покупатель: «' => 'Покупатель говорит: «',
        'Заказчик: «'   => 'Заказчик говорит: «',
        'Партнёр: «'    => 'Партнёр говорит: «',
        'Партнер: «'    => 'Партнер говорит: «',
    ];
    foreach ($slugs as $slug) {
        $quizId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$qz} WHERE slug=%s LIMIT 1", $slug));
        if ($quizId <= 0) continue;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id,question_text FROM {$qq} WHERE quiz_id=%d", $quizId), ARRAY_A);
        foreach ((array)$rows as $row) {
            $oldText = (string)($row['question_text'] ?? '');
            $newText = strtr($oldText, $replacements);
            if ($newText !== $oldText) {
                $wpdb->update($qq, ['question_text'=>$newText], ['id'=>(int)$row['id']]);
            }
        }
    }
    update_option('ckm_quiz_pro_builtin_speaker_phrasing_v2', '1', false);
}
add_action('admin_init','ckm_quiz_pro_upgrade_builtin_speaker_phrasing',10);

function ckm_quiz_pro_negotiation_ai_review(array $game, array $question, array $answer): ?array {
    if(!function_exists('ckm_quiz_pro_solution_price_aipuffer_rest_key') || !function_exists('ckm_quiz_pro_solution_price_parse_ai_json')) return null;
    $key=ckm_quiz_pro_solution_price_aipuffer_rest_key();
    if($key==='') return null;
    $settings=function_exists('ckm_quiz_pro_solution_price_ai_settings') ? ckm_quiz_pro_solution_price_ai_settings() : ['provider'=>'openai','model'=>'gpt-4o-mini'];
    $formatSettings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : [];
    $rule=ckm_quiz_json_decode($question['scoring_rule_json']??'');
    $mode=ckm_quiz_pro_negotiation_mode((string)($rule['negotiationMode']??$formatSettings['negotiationMode']??'sales'));
    $modeTitle=ckm_quiz_pro_negotiation_mode_title($mode);
    $criteria=array_values((array)($rule['judgeCriteria']??$formatSettings['assessmentProfile']??[]));
    $maxPoints=max(1,(int)($question['points']??20));
    $system='Ты ИИ-арбитр тренажёра переговоров. Оценивай именно переговорный ход, а не литературный стиль. Не награждай за многословие. Учитывай контекст, интересы сторон, контроль уступок, качество вопросов, работу с возражениями и конкретность следующего шага. Верни ТОЛЬКО JSON без markdown: {"score":0,"verdict":"accepted|partial|rejected","strengths":["..."],"mistakes":["..."],"recommendation":"...","comment":"Короткий разбор в 2–4 предложениях на русском"}.';
    if($mode==='express') $system.=' В экспресс-раунде отдельно оцени скорость смысловой реакции: реплика должна быть короткой, управляемой и не отдавать инициативу автоматически.';
    if($mode==='sales') $system.=' В режиме продаж проверяй активное слушание, вопросы, связь ценности с потребностью, работу с возражением и перевод на конкретный следующий шаг.';
    if($mode==='business') $system.=' В деловых переговорах проверяй интересы, позиции, альтернативы, взаимность уступок, пакетирование условий и качество договорённости.';
    $prompt="РЕЖИМ: {$modeTitle}\n\nСИТУАЦИЯ / РЕПЛИКА ОППОНЕНТА:\n".(string)($question['question_text']??'')."\n\nРЕПЛИКА ИГРОКА:\n".(string)($answer['answer_text']??'')."\n\nМЕТОДИЧЕСКИЙ ОРИЕНТИР:\n".(string)($question['explanation']??'')."\n\nКРИТЕРИИ:\n".implode(', ',$criteria)."\n\nМаксимум баллов: {$maxPoints}.";
    $body=[
        'provider'=>$settings['provider'],
        'model'=>$settings['model'],
        'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],
        'ai_params'=>['temperature'=>0.2,'max_completion_tokens'=>450],
        'stream'=>false,
    ];
    $response=wp_remote_post(ckm_quiz_pro_aipuffer_endpoint(),[
        'timeout'=>8,'redirection'=>0,
        'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key],
        'body'=>wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
    if(is_wp_error($response)) return null;
    $code=(int)wp_remote_retrieve_response_code($response);
    if($code<200||$code>=300) return null;
    $json=json_decode((string)wp_remote_retrieve_body($response),true);
    $content=is_array($json)?(string)($json['content']??$json['reply']??''):'';
    return ckm_quiz_pro_solution_price_parse_ai_json($content,$maxPoints);
}

function ckm_quiz_pro_negotiation_contains_any(string $text,array $needles): bool {
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    foreach($needles as $needle){
        $needle=function_exists('mb_strtolower')?mb_strtolower((string)$needle,'UTF-8'):strtolower((string)$needle);
        if($needle!=='' && strpos($text,$needle)!==false) return true;
    }
    return false;
}

function ckm_quiz_pro_negotiation_local_review(array $game,array $question,array $answer): array {
    $text=trim((string)($answer['answer_text']??''));
    $len=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);
    $maxPoints=max(1,(int)($question['points']??20));
    $formatSettings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : [];
    $rule=ckm_quiz_json_decode($question['scoring_rule_json']??'');
    $mode=ckm_quiz_pro_negotiation_mode((string)($rule['negotiationMode']??$formatSettings['negotiationMode']??'sales'));
    $score=$len<12?1:($len<40?6:($len<120?10:12));
    $strengths=[];$mistakes=[];
    if($len>=25){$strengths[]='реплика достаточно содержательна';}else{$mistakes[]='реплика слишком короткая и почти не управляет ситуацией';}
    if($mode==='express' && $len>260){$score-=2;$mistakes[]='для экспресс-раунда реплика перегружена и теряет управляемость';}

    $questionSignals=['?','что ','как ','какой','почему','верно ли','правильно понимаю','уточн'];
    if(ckm_quiz_pro_negotiation_contains_any($text,$questionSignals)){$score+=2;$strengths[]='есть вопрос или уточнение позиции оппонента';}
    else $mistakes[]='не используется вопрос для получения информации или перехвата инициативы';

    $nextSignals=['предлагаю','давайте','следующ','сегодня','завтра','до ','встреч','созвон','пилот','шаг','согласуем','зафикс'];
    if(ckm_quiz_pro_negotiation_contains_any($text,$nextSignals)){$score+=2;$strengths[]='есть конкретный следующий ход';}

    if($mode==='sales'){
        if(ckm_quiz_pro_negotiation_contains_any($text,['вам','ваш','задач','важн','риск','результ','выгод','ценност','критер'])){$score+=3;$strengths[]='реплика привязана к интересу или ценности для клиента';}
        else $mistakes[]='ценность не связана с задачей клиента';
        if(ckm_quiz_pro_negotiation_contains_any($text,['скидк','дешев','дорог']) && !ckm_quiz_pro_negotiation_contains_any($text,['услов','объём','срок','взамен','если']))$mistakes[]='обсуждение цены не связано со встречными условиями';
    } elseif($mode==='business'){
        if(ckm_quiz_pro_negotiation_contains_any($text,['интерес','услов','вариант','альтернатив','если','взамен','при условии','пакет','срок','объём'])){$score+=3;$strengths[]='есть работа с условиями, интересами или обменом уступками';}
        else $mistakes[]='реплика остаётся на уровне позиции и не переводит разговор к интересам/вариантам';
        if(ckm_quiz_pro_negotiation_contains_any($text,['соглас','фиксир','подтверд','итог'])){$score+=1;}
    } else {
        if(ckm_quiz_pro_negotiation_contains_any($text,['либо','иначе','обязан','должны']) && !ckm_quiz_pro_negotiation_contains_any($text,['уточн','правильно','давайте','предлагаю','если']))$mistakes[]='есть риск принять навязанную рамку или ответить симметричным давлением';
        if(ckm_quiz_pro_negotiation_contains_any($text,['давайте','предлагаю','уточн','правильно понимаю','что именно','если'])){$score+=3;$strengths[]='реплика создаёт управляемый следующий ход';}
    }
    if(ckm_quiz_pro_negotiation_contains_any($text,['понимаю','согласен','вижу','важно','правильно понимаю'])){$score+=1;$strengths[]='есть сигнал активного слушания';}
    $score=max(0,min($maxPoints,$score));
    $verdict=$score>=$maxPoints*0.7?'accepted':($score>=$maxPoints*0.35?'partial':'rejected');
    $comment='Сильные стороны: '.($strengths?implode('; ',$strengths):'сильный переговорный ход пока не проявился').'. ';
    $comment.='Что улучшить: '.($mistakes?implode('; ',$mistakes):'существенных пропусков не обнаружено').'. ';
    $orientation=trim((string)($question['explanation']??''));
    if($orientation!=='')$comment.='Ориентир: '.$orientation;
    return ['score'=>$score,'verdict'=>$verdict,'comment'=>$comment,'provider'=>'local_rubric'];
}

function ckm_quiz_pro_negotiation_review_closed_question(int $gameId,int $questionId): array {
    if($gameId<=0||$questionId<=0)return ['ok'=>true,'rows'=>[],'provider'=>'none'];
    $game=ckm_quiz_get_game($gameId);
    if(!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='negotiation_duel')return ['ok'=>true,'rows'=>[],'provider'=>'none'];
    $question=ckm_quiz_get_question($questionId);
    if(!$question)return ['ok'=>true,'rows'=>[],'provider'=>'none'];
    $judgeMode=function_exists('ckm_quiz_judge_mode') ? ckm_quiz_judge_mode((string)($game['judge_mode_snapshot']??'ai'),'ai') : sanitize_key((string)($game['judge_mode_snapshot']??'ai'));
    global $wpdb;
    $answers=$wpdb->get_results($wpdb->prepare(
        "SELECT a.*,tm.team_name FROM ".ckm_quiz_answers_table()." a INNER JOIN ".ckm_quiz_teams_table()." tm ON tm.id=a.team_id WHERE a.game_id=%d AND a.question_id=%d ORDER BY tm.slot_no,a.id",
        $gameId,$questionId
    ),ARRAY_A)?:[];
    $rows=[];
    foreach($answers as $answer){
        if((string)($answer['verdict']??'pending')!=='pending'){
            $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>(int)$answer['awarded_points'],'verdict'=>(string)$answer['verdict'],'comment'=>(string)$answer['judge_comment'],'provider'=>(string)$answer['judge_mode']];
            continue;
        }
        if($judgeMode==='human'){
            $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>0,'verdict'=>'pending','comment'=>'Ожидается решение ведущего.','provider'=>'human'];
            continue;
        }
        $review=ckm_quiz_pro_negotiation_ai_review($game,$question,$answer);
        if(!$review && $judgeMode==='hybrid'){
            $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>0,'verdict'=>'pending','comment'=>'ИИ-оценка недоступна; ответ оставлен ведущему.','provider'=>'human_fallback'];
            continue;
        }
        if(!$review)$review=ckm_quiz_pro_negotiation_local_review($game,$question,$answer);
        $wpdb->query('START TRANSACTION');
        try{
            $lockedGame=ckm_quiz_get_game($gameId,true);
            $lockedAnswer=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".ckm_quiz_answers_table()." WHERE id=%d FOR UPDATE",(int)$answer['id']),ARRAY_A);
            if($lockedGame && $lockedAnswer && (string)($lockedAnswer['verdict']??'pending')==='pending'){
                $applied=ckm_quiz_apply_score_locked(
                    $lockedGame,$question,$lockedAnswer,(int)$review['score'],(string)$review['verdict'],(string)$review['comment'],'ai_host',0,
                    'negotiation-review-'.(int)$answer['id'].'-attempt-'.(int)($answer['attempt_no']??1)
                );
                if(empty($applied['ok']))throw new RuntimeException((string)($applied['code']??'negotiation_score_failed'));
            }
            $wpdb->query('COMMIT');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            error_log('CKM Quiz negotiation review failed: '.$e->getMessage());
        }
        $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>(int)$review['score'],'verdict'=>(string)$review['verdict'],'comment'=>(string)$review['comment'],'provider'=>(string)$review['provider']];
    }
    return ['ok'=>true,'rows'=>$rows,'provider'=>!empty($rows)?(string)($rows[0]['provider']??'none'):'none'];
}

function ckm_quiz_pro_negotiation_maybe_auto_close(int $gameId): array {
    $game=ckm_quiz_get_game($gameId);
    if(!$game || (string)($game['quiz_phase']??'')!=='question_open' || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='negotiation_duel')return ['ok'=>true,'skipped'=>true];
    $qid=(int)($game['current_question_id']??0); if($qid<=0)return ['ok'=>true,'skipped'=>true];
    global $wpdb;
    $teams=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_teams_table()." WHERE game_id=%d AND team_status='active'",$gameId));
    $answers=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT team_id) FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d",$gameId,$qid));
    if($teams<=0 || $answers<$teams)return ['ok'=>true,'skipped'=>true,'answered'=>$answers,'teams'=>$teams];
    $closed=ckm_quiz_close_current_question($gameId,'ai_host',0);
    if(empty($closed['ok']))return $closed;
    if(function_exists('ckm_quiz_ai_host_classic_autopilot'))ckm_quiz_ai_host_classic_autopilot($gameId);
    return ['ok'=>true,'closed'=>true];
}

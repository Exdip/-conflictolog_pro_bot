<?php
/**
 * Game Format Layer registry for CKM Quizzes.
 *
 * alpha.69.20 keeps machine-readable contracts and connects both CHGK arbitration
 * and the CHGK text host to ИИ while preserving the shared engine. Jeopardy server runtime is active; the remaining planned formats stay
 * blocked until their own runtime adapters are implemented.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CKM_EA_DEFAULT_GAME_FORMAT = 'classic_quiz';

function ckm_quiz_format_builtin_definitions(): array {
    return array(
        'classic_quiz' => array(
            'title'=>'Классический квиз',
            'description'=>'Текущий последовательный квиз. Используется для всех существующих квизов после миграции.',
            'status'=>'active',
            'engine_contract'=>'quiz_v1',
            'capabilities'=>array('rounds','sequential_questions','server_timer','team_answer','automatic_reference_check','manual_judge','speed_bonus'),
            'defaults'=>array('answerMode'=>'team','victoryMode'=>'points','speedBonusEnabled'=>true,'speedBonusPoints'=>1),
            'contract'=>array(
                'contractVersion'=>1,
                'allowedRoundTypes'=>array('questions'),
                'allowedMechanics'=>array('bonus','penalty'),
                'victory'=>array('mode'=>'points','tieBreakers'=>array('correct_answers','speed_bonus','shared_place')),
                'specialParameters'=>array(
                    'answerMode'=>array('type'=>'enum','values'=>array('team'),'default'=>'team'),
                    'speedBonusEnabled'=>array('type'=>'boolean','default'=>true),
                    'speedBonusPoints'=>array('type'=>'integer','min'=>0,'max'=>20,'default'=>1),
                ),
            ),
        ),
        'chgk' => array(
            'title'=>'Битва знатоков',
            'description'=>'Одна команда Знатоков играет против Игры. Каждый вопрос стоит ровно 1 очко: после обсуждения команда фиксирует один окончательный ответ; победа — первой стороне, набравшей 6 очков.',
            'status'=>'active',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','single_team_duel','team_discussion','single_final_answer','team_device_mode','lobby_ready','server_preflight','auto_close_all_answered','argumentation_judge','ai_or_human_judge','ai_puffer_arbitration','ai_puffer_host','human_fallback'),
            'defaults'=>array('discussionSeconds'=>60,'finalAnswerSeconds'=>20,'earlyAnswerSeconds'=>5,'answerType'=>'text','judgeMode'=>'hybrid','singleFinalAnswer'=>true,'participationMode'=>'team_device','finalizationMode'=>'any_member','requireAllTeamsReady'=>true,'closeWhenAllAnswered'=>true,'questionReviewEnabled'=>false,'requireQuestionReview'=>false,'singleTeamOnly'=>true,'winScore'=>6,'pointsPerCorrectAnswer'=>1,'comparativeAnalysisEnabled'=>false,'methodologyAnalysisEnabled'=>false,'victoryMode'=>'first_to_six'),
            'contract'=>array(
                'contractVersion'=>2,
                'allowedRoundTypes'=>array('questions'),
                'allowedMechanics'=>array(),
                'victory'=>array('mode'=>'first_to_six','tieBreakers'=>array()),
                'specialParameters'=>array(
                    'discussionSeconds'=>array('type'=>'integer','min'=>60,'max'=>300,'default'=>60),
                    'finalAnswerSeconds'=>array('type'=>'integer','min'=>20,'max'=>20,'default'=>20),
                    'earlyAnswerSeconds'=>array('type'=>'integer','min'=>5,'max'=>60,'default'=>5),
                    'answerType'=>array('type'=>'enum','values'=>array('text'),'default'=>'text'),
                    'judgeMode'=>array('type'=>'enum','values'=>array('ai','human','hybrid'),'default'=>'hybrid'),
                    'singleFinalAnswer'=>array('type'=>'boolean','default'=>true),
                    'participationMode'=>array('type'=>'enum','values'=>array('team_device'),'default'=>'team_device'),
                    'finalizationMode'=>array('type'=>'enum','values'=>array('any_member'),'default'=>'any_member'),
                    'requireAllTeamsReady'=>array('type'=>'boolean','default'=>true),
                    'closeWhenAllAnswered'=>array('type'=>'boolean','default'=>true),
                    'questionReviewEnabled'=>array('type'=>'boolean','default'=>false),
                    'requireQuestionReview'=>array('type'=>'boolean','default'=>false),
                    'singleTeamOnly'=>array('type'=>'boolean','default'=>true),
                    'winScore'=>array('type'=>'integer','min'=>6,'max'=>6,'default'=>6),
                    'comparativeAnalysisEnabled'=>array('type'=>'boolean','default'=>false),
                    'methodologyAnalysisEnabled'=>array('type'=>'boolean','default'=>false),
                ),
            ),
        ),
        'jeopardy' => array(
            'title'=>'Интеллектуальный батл',
            'description'=>'Категории и номиналы, кнопка ответа, «Секретная передача», AI/человек-арбитр и простой финальный вопрос на 500 баллов.',
            'status'=>'active',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','categories','question_values','question_selection','selector_team','board_state','special_questions','final_round','closed_answers','ai_or_human_judge','ai_puffer_arbitration','ai_puffer_host','human_fallback','results_reports','admin_builder','publish_readiness'),
            'defaults'=>array('categoryCount'=>5,'categoryTitles'=>array('Категория 1','Категория 2','Категория 3','Категория 4','Категория 5'),'questionValues'=>array(100,200,300,400,500),'buzzerSeconds'=>15,'judgeMode'=>'hybrid','victoryMode'=>'points'),
            'contract'=>array(
                'contractVersion'=>3,
                'allowedRoundTypes'=>array('board','final_question'),
                'allowedMechanics'=>array('bonus','penalty','jeopardy_cell','jeopardy_cat_in_bag','jeopardy_final_control'),
                'victory'=>array('mode'=>'points','tieBreakers'=>array('correct_answers','shared_place')),
                'specialParameters'=>array(
                    'categoryCount'=>array('type'=>'integer','min'=>1,'max'=>12,'default'=>5),
                    'questionValues'=>array('type'=>'integer_list','minItem'=>1,'maxItem'=>10000,'default'=>array(100,200,300,400,500)),
                    'buzzerSeconds'=>array('type'=>'integer','min'=>5,'max'=>60,'default'=>15),
                    'judgeMode'=>array('type'=>'enum','values'=>array('ai','human','hybrid'),'default'=>'hybrid'),
                ),
            ),
        ),
        'solution_price' => array(
            'title'=>'Управленческая игра "Ваш выбор"',
            'description'=>'Команды предлагают решение проблемы с ограничениями и ресурсами; арбитр оценивает эффект, реалистичность и качество мышления.',
            'status'=>'active',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','cases','constraints','resources','solution_cost','argumentation_judge','team_answer'),
            'defaults'=>array('answerType'=>'text','judgeCriteria'=>array('effect','realism','thinking_quality'),'victoryMode'=>'judge_rating'),
            'contract'=>array(
                'contractVersion'=>1,
                'allowedRoundTypes'=>array('case','final_case'),
                'allowedMechanics'=>array('bonus','penalty'),
                'victory'=>array('mode'=>'judge_rating','tieBreakers'=>array('thinking_quality','effect','shared_place')),
                'specialParameters'=>array(
                    'answerType'=>array('type'=>'enum','values'=>array('text'),'default'=>'text'),
                    'judgeCriteria'=>array('type'=>'string_list','default'=>array('effect','realism','thinking_quality')),
                    'requireSolutionCost'=>array('type'=>'boolean','default'=>true),
                ),
            ),
        ),
        'negotiation_duel' => array(
            'title'=>'Переговорный поединок',
            'description'=>'Переговорные поединки: Эффективный продажник, Мастер переговоров, Переговорный раунд и полноценная четырёхраундовая игра «Переговори другого». ИИ/арбитр оценивает переговорные ходы, а финальный раунд завершается объективным голосованием и итоговым рейтингом.',
            'status'=>'active',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','negotiation_cases','text_replies','sales_mode','business_mode','express_mode','ai_or_human_judge','ai_feedback','team_competition'),
            'defaults'=>array('negotiationMode'=>'sales','answerType'=>'text','judgeMode'=>'ai','opponentMode'=>'scenario','secondsPerTurn'=>120,'assessmentProfile'=>array('active_listening','questions','value_presentation','objection_handling','next_step'),'victoryMode'=>'judge_rating'),
            'contract'=>array(
                'contractVersion'=>1,
                'allowedRoundTypes'=>array('negotiation','negotiation_final'),
                'allowedMechanics'=>array('bonus','penalty'),
                'victory'=>array('mode'=>'judge_rating','tieBreakers'=>array('agreement_quality','initiative','shared_place')),
                'specialParameters'=>array(
                    'negotiationMode'=>array('type'=>'enum','values'=>array('sales','business','express','communicate'),'default'=>'sales'),
                    'answerType'=>array('type'=>'enum','values'=>array('text'),'default'=>'text'),
                    'judgeMode'=>array('type'=>'enum','values'=>array('ai','human','hybrid'),'default'=>'ai'),
                    'secondsPerTurn'=>array('type'=>'integer','min'=>30,'max'=>600,'default'=>120),
                    'assessmentProfile'=>array('type'=>'string_list','default'=>array('active_listening','questions','value_presentation','objection_handling','next_step')),
                ),
            ),
        ),
        'debates' => array(
            'title'=>'Дебаты',
            'description'=>'Команды получают роли «за» и «против»; оцениваются аргументы, контраргументы и доказательность.',
            'status'=>'planned',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','team_roles','arguments','counterarguments','evidence_judge'),
            'defaults'=>array('roles'=>array('pro','contra'),'judgeCriteria'=>array('arguments','counterarguments','evidence'),'victoryMode'=>'judge_rating'),
            'contract'=>array(
                'contractVersion'=>1,
                'allowedRoundTypes'=>array('opening','rebuttal','closing'),
                'allowedMechanics'=>array('bonus','penalty'),
                'victory'=>array('mode'=>'judge_rating','tieBreakers'=>array('evidence','counterarguments','shared_place')),
                'specialParameters'=>array(
                    'roles'=>array('type'=>'string_list','default'=>array('pro','contra')),
                    'judgeCriteria'=>array('type'=>'string_list','default'=>array('arguments','counterarguments','evidence')),
                ),
            ),
        ),
        'business_simulation' => array(
            'title'=>'Бизнес-симуляция',
            'description'=>'Команды принимают управленческие решения, а сценарий рассчитывает их последствия.',
            'status'=>'planned',
            'engine_contract'=>'format_layer_v1',
            'capabilities'=>array('rounds','decisions','state_variables','calculated_consequences','branching'),
            'defaults'=>array('branchingEnabled'=>true,'victoryMode'=>'scenario_result'),
            'contract'=>array(
                'contractVersion'=>1,
                'allowedRoundTypes'=>array('decision','result'),
                'allowedMechanics'=>array('bonus','penalty'),
                'victory'=>array('mode'=>'scenario_result','tieBreakers'=>array('final_state_score','shared_place')),
                'specialParameters'=>array(
                    'branchingEnabled'=>array('type'=>'boolean','default'=>true),
                    'stateVariables'=>array('type'=>'json','default'=>array()),
                    'decisionOptions'=>array('type'=>'json','default'=>array()),
                ),
            ),
        ),
    );
}

function ckm_quiz_format_normalize_key(string $key): string {
    $key = sanitize_key($key);
    return $key !== '' ? $key : CKM_EA_DEFAULT_GAME_FORMAT;
}

function ckm_quiz_format_seed_builtins(): bool {
    global $wpdb;
    $table = ckm_quiz_formats_table();
    $now = current_time('mysql');
    foreach (ckm_quiz_format_builtin_definitions() as $key=>$definition) {
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id,is_builtin FROM {$table} WHERE format_key=%s LIMIT 1", $key), ARRAY_A);
        $data = array(
            'title'=>(string)$definition['title'],
            'description'=>(string)$definition['description'],
            'format_version'=>1,
            'engine_contract'=>(string)$definition['engine_contract'],
            'status'=>(string)$definition['status'],
            'is_builtin'=>1,
            'capabilities_json'=>wp_json_encode((array)$definition['capabilities'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'default_settings_json'=>wp_json_encode((array)$definition['defaults'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'contract_json'=>wp_json_encode((array)($definition['contract'] ?? array()), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>$now,
        );
        if ($existing) {
            // Built-ins are code-owned. Custom rows are edited by the alpha.69.5
            // constructor and are never touched by this seeder.
            if ((int)$existing['is_builtin'] === 1) {
                if ($wpdb->update($table, $data, array('id'=>(int)$existing['id'])) === false) return false;
            }
        } else {
            $data['format_key'] = $key;
            $data['created_at'] = $now;
            if ($wpdb->insert($table, $data) === false) return false;
        }
    }
    return true;
}

function ckm_quiz_format_get(string $key): ?array {
    global $wpdb;
    $key = ckm_quiz_format_normalize_key($key);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . ckm_quiz_formats_table() . " WHERE format_key=%s LIMIT 1", $key), ARRAY_A);
    if (!is_array($row)) return null;
    $row['format_version'] = (int)$row['format_version'];
    $row['is_builtin'] = (int)$row['is_builtin'];
    $row['capabilities'] = function_exists('ckm_quiz_json_decode') ? ckm_quiz_json_decode($row['capabilities_json']) : (json_decode((string)$row['capabilities_json'], true) ?: array());
    $row['default_settings'] = function_exists('ckm_quiz_json_decode') ? ckm_quiz_json_decode($row['default_settings_json']) : (json_decode((string)$row['default_settings_json'], true) ?: array());
    $row['contract'] = ckm_quiz_format_normalize_contract(
        function_exists('ckm_quiz_json_decode') ? ckm_quiz_json_decode($row['contract_json'] ?? '') : (json_decode((string)($row['contract_json'] ?? ''), true) ?: array()),
        $row
    );
    return $row;
}

/**
 * Normalise the machine-readable format contract. This keeps future custom
 * formats forward-compatible while guaranteeing the four alpha.69.4 sections.
 */
function ckm_quiz_format_normalize_contract(array $contract, array $format = array()): array {
    $defaults = isset($format['default_settings']) && is_array($format['default_settings']) ? $format['default_settings'] : array();
    $victoryMode = sanitize_key((string)($contract['victory']['mode'] ?? $defaults['victoryMode'] ?? 'points'));
    if ($victoryMode === '') $victoryMode = 'points';

    $roundTypes = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($contract['allowedRoundTypes'] ?? array('questions'))))));
    if (!$roundTypes) $roundTypes = array('questions');
    $mechanics = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($contract['allowedMechanics'] ?? array())))));
    $tieBreakers = array_values(array_unique(array_filter(array_map('sanitize_key', (array)($contract['victory']['tieBreakers'] ?? array('shared_place'))))));
    if (!$tieBreakers) $tieBreakers = array('shared_place');

    return array(
        'contractVersion'=>max(1, (int)($contract['contractVersion'] ?? 1)),
        'allowedRoundTypes'=>$roundTypes,
        'allowedMechanics'=>$mechanics,
        'victory'=>array('mode'=>$victoryMode, 'tieBreakers'=>$tieBreakers),
        'specialParameters'=>isset($contract['specialParameters']) && is_array($contract['specialParameters']) ? $contract['specialParameters'] : array(),
    );
}

function ckm_quiz_format_contract(string $key): ?array {
    $format = ckm_quiz_format_get($key);
    return $format ? (array)($format['contract'] ?? array()) : null;
}

function ckm_quiz_format_round_type_allowed(string $key, string $roundType): bool {
    $contract = ckm_quiz_format_contract($key);
    if (!$contract) return false;
    return in_array(sanitize_key($roundType), (array)($contract['allowedRoundTypes'] ?? array()), true);
}

function ckm_quiz_format_mechanic_allowed(string $key, string $mechanicType): bool {
    $contract = ckm_quiz_format_contract($key);
    if (!$contract) return false;
    return in_array(sanitize_key($mechanicType), (array)($contract['allowedMechanics'] ?? array()), true);
}

function ckm_quiz_format_victory_mode(string $key): string {
    $contract = ckm_quiz_format_contract($key);
    return sanitize_key((string)($contract['victory']['mode'] ?? 'points')) ?: 'points';
}

function ckm_quiz_format_sanitize_parameter($value, array $spec) {
    $type = sanitize_key((string)($spec['type'] ?? 'string'));
    if ($type === 'boolean') {
        if (is_bool($value)) return $value;
        if (is_numeric($value)) return (int)$value !== 0;
        return in_array(strtolower(trim((string)$value)), array('1','true','yes','on'), true);
    }
    if ($type === 'integer') {
        $number = (int)$value;
        if (isset($spec['min'])) $number = max((int)$spec['min'], $number);
        if (isset($spec['max'])) $number = min((int)$spec['max'], $number);
        return $number;
    }
    if ($type === 'enum') {
        $values = array_values(array_map('strval', (array)($spec['values'] ?? array())));
        $candidate = (string)$value;
        if (in_array($candidate, $values, true)) return $candidate;
        return isset($spec['default']) ? (string)$spec['default'] : ($values[0] ?? '');
    }
    if ($type === 'integer_list') {
        $items = is_array($value) ? $value : array();
        $out = array();
        foreach ($items as $item) {
            $number = (int)$item;
            if (isset($spec['minItem'])) $number = max((int)$spec['minItem'], $number);
            if (isset($spec['maxItem'])) $number = min((int)$spec['maxItem'], $number);
            $out[] = $number;
        }
        return $out;
    }
    if ($type === 'string_list') {
        $items = is_array($value) ? $value : array();
        return array_values(array_filter(array_map(static function($item): string {
            return sanitize_key((string)$item);
        }, $items)));
    }
    if ($type === 'json') return is_array($value) ? $value : array();
    return sanitize_text_field((string)$value);
}

/**
 * Apply code-owned defaults and sanitise only parameters described by the
 * contract. Unknown keys are preserved for forward compatibility.
 */
function ckm_quiz_format_resolve_settings(string $key, array $overrides = array()): array {
    $format = ckm_quiz_format_get($key);
    if (!$format) return $overrides;
    $settings = array_replace_recursive((array)($format['default_settings'] ?? array()), $overrides);
    $contract = (array)($format['contract'] ?? array());
    foreach ((array)($contract['specialParameters'] ?? array()) as $name=>$spec) {
        if (!is_array($spec)) continue;
        if (!array_key_exists($name, $settings) && array_key_exists('default', $spec)) $settings[$name] = $spec['default'];
        if (array_key_exists($name, $settings)) {
            // Lists/objects in an override replace the whole default value; they
            // are not recursively merged by numeric index.
            $sourceValue = array_key_exists($name, $overrides) ? $overrides[$name] : $settings[$name];
            $settings[$name] = ckm_quiz_format_sanitize_parameter($sourceValue, $spec);
        }
    }
    $settings['victoryMode'] = (string)($contract['victory']['mode'] ?? ($settings['victoryMode'] ?? 'points'));
    return $settings;
}

function ckm_quiz_format_validate_contract(array $contract): array {
    $errors = array();
    if ((int)($contract['contractVersion'] ?? 0) < 1) $errors[] = 'contract_version';
    if (empty($contract['allowedRoundTypes']) || !is_array($contract['allowedRoundTypes'])) $errors[] = 'round_types';
    if (!isset($contract['allowedMechanics']) || !is_array($contract['allowedMechanics'])) $errors[] = 'mechanics';
    if (empty($contract['victory']['mode'])) $errors[] = 'victory_mode';
    if (!isset($contract['specialParameters']) || !is_array($contract['specialParameters'])) $errors[] = 'special_parameters';
    return array('ok'=>!$errors, 'errors'=>$errors);
}

function ckm_quiz_format_list(bool $includePlanned = true): array {
    global $wpdb;
    $where = $includePlanned ? '' : " WHERE status='active'";
    return $wpdb->get_results("SELECT * FROM " . ckm_quiz_formats_table() . $where . " ORDER BY is_builtin DESC,id ASC", ARRAY_A) ?: array();
}

function ckm_quiz_format_is_runnable(string $key): bool {
    $format = ckm_quiz_format_get($key);
    if (!is_array($format) || (string)$format['status'] !== 'active') return false;
    if ((string)$format['engine_contract'] === 'quiz_v1') return true;
    return function_exists('ckm_quiz_format_runtime_is_supported')
        && ckm_quiz_format_runtime_is_supported((string)$format['format_key'], $format);
}

/**
 * Human-readable format state for admin UI.
 * The registry may contain formats before their runtime contract is implemented.
 */
function ckm_quiz_format_status_label(array $format): string {
    $status = (string)($format['status'] ?? 'planned');
    if ($status === 'active' && function_exists('ckm_quiz_format_is_runnable') && ckm_quiz_format_is_runnable((string)($format['format_key'] ?? ''))) return 'Доступен сейчас';
    if ($status === 'planned' && empty($format['is_builtin'])) return 'Пользовательский формат · черновик механики';
    if ($status === 'planned') return 'Механика в разработке';
    if ($status === 'active') return 'Зарегистрирован';
    return $status !== '' ? $status : 'Неизвестно';
}

/**
 * Assignment is broader than execution: an administrator may prepare a draft
 * for a planned format, but only runnable formats may be published/started.
 */
function ckm_quiz_format_is_assignable(string $key): bool {
    return ckm_quiz_format_get($key) !== null;
}

function ckm_quiz_format_can_publish(string $key): bool {
    return ckm_quiz_format_is_runnable($key);
}

/**
 * Resolve the editor status for a format without blocking preparation work.
 * Planned formats may always be saved, but a publish request is downgraded
 * to draft until the format has a runnable engine contract.
 */
function ckm_quiz_format_resolve_quiz_status(string $key, string $requestedStatus): array {
    $status = sanitize_key($requestedStatus);
    if (!in_array($status, array('draft','published','archived'), true)) $status = 'draft';
    if ($status === 'published' && !ckm_quiz_format_can_publish($key)) {
        return array(
            'status'=>'draft',
            'downgraded'=>true,
            'reason'=>'format_runtime_pending',
        );
    }
    return array(
        'status'=>$status,
        'downgraded'=>false,
        'reason'=>'',
    );
}

function ckm_quiz_format_sync_rounds(int $quizId, int $revision): bool {
    if ($quizId <= 0 || $revision <= 0) return false;
    global $wpdb;
    $questions = ckm_quiz_questions_table();
    $rounds = ckm_quiz_rounds_table();
    $quizFormat = sanitize_key((string)$wpdb->get_var($wpdb->prepare("SELECT format_key FROM " . ckm_quiz_quizzes_table() . " WHERE id=%d LIMIT 1", $quizId)));
    $mainRoundType = $quizFormat === 'jeopardy' ? 'board' : ($quizFormat === 'solution_price' ? 'case' : ($quizFormat === 'negotiation_duel' ? 'negotiation' : 'questions'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT round_no,MIN(position) first_position,MAX(round_title) round_title,
                CASE
                    WHEN SUM(CASE WHEN question_stage='main' THEN 1 ELSE 0 END)>0 THEN %s
                    WHEN %s='jeopardy' AND SUM(CASE WHEN question_stage='final' THEN 1 ELSE 0 END)>0 THEN 'final_question'
                    ELSE 'tiebreak'
                END round_type
         FROM {$questions}
         WHERE quiz_id=%d AND quiz_revision=%d AND status='active'
         GROUP BY round_no ORDER BY round_no ASC",
        $mainRoundType,
        $quizFormat,
        $quizId,
        $revision
    ), ARRAY_A) ?: array();
    foreach ($rows as $row) {
        $roundNo = max(1, (int)$row['round_no']);
        $roundKey = 'round-' . $roundNo;
        $roundId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$rounds} WHERE quiz_id=%d AND quiz_revision=%d AND (round_key=%s OR position=%d) ORDER BY CASE WHEN round_key=%s THEN 0 ELSE 1 END,id ASC LIMIT 1",
            $quizId,
            $revision,
            $roundKey,
            $roundNo,
            $roundKey
        ));
        if ($roundId <= 0) {
            $title = trim((string)($row['round_title'] ?? ''));
            if ($title === '') $title = 'Раунд ' . $roundNo;
            $now = current_time('mysql');
            $ok = $wpdb->insert($rounds, array(
                'quiz_id'=>$quizId,
                'quiz_revision'=>$revision,
                'round_key'=>$roundKey,
                'position'=>$roundNo,
                'title'=>$title,
                'round_type'=>(string)($row['round_type'] ?? 'questions'),
                'rules_json'=>'{}',
                'settings_json'=>'{}',
                'status'=>'active',
                'created_at'=>$now,
                'updated_at'=>$now,
            ));
            if ($ok === false) return false;
            $roundId = (int)$wpdb->insert_id;
        }
        if ($roundId > 0) {
            $roundType = (string)($row['round_type'] ?? 'questions');
            $roundTitle = trim((string)($row['round_title'] ?? ''));
            if ($roundTitle === '') $roundTitle = $roundType === 'tiebreak' ? 'Тай-брейк' : ($roundType === 'final_question' ? 'Финальный раунд' : ('Раунд ' . $roundNo));
            $roundUpdated = $wpdb->update($rounds, array(
                'title'=>$roundTitle,
                'round_type'=>$roundType,
                'updated_at'=>current_time('mysql'),
            ), array('id'=>$roundId));
            if ($roundUpdated === false) return false;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$questions} SET round_id=%d WHERE quiz_id=%d AND quiz_revision=%d AND round_no=%d",
                $roundId,
                $quizId,
                $revision,
                $roundNo
            ));
            if ($updated === false) return false;
        }
    }
    return true;
}

function ckm_quiz_format_admin_menu(): void {
    add_submenu_page(
        'ckm-elevenagents-home',
        'Форматы соревнований',
        'Форматы соревнований',
        'manage_options',
        'ckm-ea-game-formats',
        'ckm_quiz_format_admin_page'
    );
}
add_action('admin_menu', 'ckm_quiz_format_admin_menu', 21);

function ckm_quiz_format_admin_page(): void {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $rows = ckm_quiz_format_list(true);
    echo '<div class="wrap"><h1 class="wp-heading-inline">Форматы соревнований</h1> ';
    echo '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=ckm-ea-format-builder')) . '">Создать свой формат</a>';
    echo '<hr class="wp-header-end">';
    echo '<p>alpha.85: поверх общего Game Format Layer работают три исполняемых формата: <strong>Классический квиз</strong>, <strong>Битва знатоков</strong> и <strong>Интеллектуальный батл</strong>. «Битва знатоков» — отдельный одномандатный матч «Знатоки против Игры»: обсуждение, серверный таймер, финальная фиксация ответа и ИИ/человек-арбитраж; победа — первой стороне, набравшей 6 очков. «Интеллектуальный батл» имеет серверную доску категорий и номиналов, buzzer, «Секретную передачу» и отдельный финальный вопрос фиксированной стоимостью 500 баллов: финальные ответы команд скрыты до общего раскрытия, итог проходит через score ledger. «Управленческая игра "Ваш выбор"», дебаты, бизнес-симуляция и пользовательские форматы остаются заблокированными до своих runtime-адаптеров.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Ключ</th><th>Название</th><th>Статус</th><th>Runtime-контракт</th><th>Раунды</th><th>Механики</th><th>Победа</th><th>Параметры</th><th>Действия</th></tr></thead><tbody>';
    foreach ($rows as $rawRow) {
        $format = ckm_quiz_format_get((string)$rawRow['format_key']);
        if (!$format) continue;
        $contract = (array)($format['contract'] ?? array());
        $roundTypes = (array)($contract['allowedRoundTypes'] ?? array());
        $mechanics = (array)($contract['allowedMechanics'] ?? array());
        $victory = (string)($contract['victory']['mode'] ?? 'points');
        $params = array_keys((array)($contract['specialParameters'] ?? array()));
        $key = (string)$format['format_key'];
        echo '<tr>';
        echo '<td><code>' . esc_html($key) . '</code></td>';
        echo '<td><strong>' . esc_html((string)$format['title']) . '</strong><br><span style="color:#646970">' . esc_html((string)$format['description']) . '</span></td>';
        echo '<td>' . esc_html(ckm_quiz_format_status_label($format)) . '</td>';
        echo '<td><code>' . esc_html((string)$format['engine_contract']) . '</code><br><small>format v' . (int)($format['format_version'] ?? 1) . ' · contract v' . (int)($contract['contractVersion'] ?? 1) . '</small></td>';
        echo '<td>' . esc_html(implode(', ', array_map('strval', $roundTypes))) . '</td>';
        echo '<td>' . esc_html($mechanics ? implode(', ', array_map('strval', $mechanics)) : '—') . '</td>';
        echo '<td><code>' . esc_html($victory) . '</code></td>';
        echo '<td>' . esc_html($params ? implode(', ', array_map('strval', $params)) : '—') . '</td>';
        echo '<td>';
        if (!empty($format['is_builtin'])) {
            echo '<a class="button button-small" href="' . esc_url(add_query_arg(array('page'=>'ckm-ea-format-builder','source'=>$key), admin_url('admin.php'))) . '">Создать на основе</a>';
        } else {
            echo '<a class="button button-small button-primary" href="' . esc_url(add_query_arg(array('page'=>'ckm-ea-format-builder','edit'=>$key), admin_url('admin.php'))) . '">Редактировать</a>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p><a class="button button-secondary" href="' . esc_url(admin_url('admin.php?page=ckm-quiz-format-layer-smoke-test')) . '">Проверить Game Format Layer</a></p>';
    echo '<p><strong>Важно:</strong> «Битва знатоков» использует общий движок через runtime-адаптер: обсуждение, единый финальный ответ и ИИ/человек-арбитраж. Шаблонный ИИ-ведущий остаётся отдельным модулем. Игровое поле «Интеллектуального батла», роли дебатов, расчёт бизнес-симуляции и другие специальные механики будут подключаться тем же способом.</p>';
    echo '</div>';
}

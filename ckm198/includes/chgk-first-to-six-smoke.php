<?php
/**
 * End-to-end smoke test for the single-team CHGK / "Битва знатоков" flow.
 *
 * The test creates temporary test_mode rooms and exercises the real server
 * state machine. Test game data is removed in finally blocks.
 */
if (!defined('ABSPATH')) exit;

function ckmqp_chgk_e2e_menu(): void {
    add_submenu_page(
        'ckm-quiz-pro',
        'Smoke: Битва знатоков',
        'Smoke: Битва знатоков',
        'manage_options',
        'ckm-quiz-pro-chgk-e2e',
        'ckmqp_chgk_e2e_page'
    );
}
add_action('admin_menu','ckmqp_chgk_e2e_menu',36);

function ckmqp_chgk_e2e_check(array &$checks, string $name, bool $ok, string $detail=''): void {
    $checks[] = array('name'=>$name,'ok'=>$ok,'detail'=>$detail);
}

function ckmqp_chgk_e2e_code(array $result): string {
    return sanitize_key((string)($result['code'] ?? ''));
}

function ckmqp_chgk_e2e_cleanup(int $gameId): void {
    if ($gameId <= 0) return;
    global $wpdb;
    $tables = array();
    foreach (array(
        'ckm_quiz_mechanics_table',
        'ckm_quiz_drafts_table',
        'ckm_quiz_members_table',
        'ckm_quiz_answers_table',
        'ckm_quiz_score_events_table',
        'ckm_quiz_events_table',
        'ckm_quiz_teams_table',
    ) as $fn) {
        if (!function_exists($fn)) continue;
        $table = (string)$fn();
        if ($table !== '') $tables[] = $table;
    }
    foreach (array_unique($tables) as $table) {
        // All tables above are game-scoped by game_id.
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE game_id=%d", $gameId));
    }
    if (function_exists('ckm_quiz_games_table')) {
        $wpdb->query($wpdb->prepare("DELETE FROM `" . ckm_quiz_games_table() . "` WHERE id=%d", $gameId));
    }
}

function ckmqp_chgk_e2e_run(): array {
    global $wpdb;
    $checks = array();
    $gameId = 0;
    $uid = get_current_user_id();

    try {
        if (function_exists('ckm_quiz_pro_seed_demo_chgk')) ckm_quiz_pro_seed_demo_chgk();
        $quiz = $wpdb->get_row(
            "SELECT * FROM " . ckm_quiz_quizzes_table() . " WHERE slug='demo-battle-experts' LIMIT 1",
            ARRAY_A
        );
        if (!$quiz) throw new RuntimeException('Демо «Битва знатоков» не найдено.');
        $quizId = (int)$quiz['id'];
        $revision = max(1,(int)$quiz['current_revision']);
        $questionCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . ckm_quiz_questions_table() . " WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",
            $quizId,$revision
        ));
        ckmqp_chgk_e2e_check($checks,'В шаблоне минимум 11 вопросов',$questionCount>=11,'вопросов: '.$questionCount);
        ckmqp_chgk_e2e_check($checks,'Шаблон строго на 1 команду',(int)$quiz['min_teams']===1 && (int)$quiz['max_teams']===1,'min='.(int)$quiz['min_teams'].', max='.(int)$quiz['max_teams']);

        // API guard: a two-team CHGK room must be impossible even if UI is bypassed.
        $twoTeams = ckm_quiz_create_room(array(
            'quiz_id'=>$quizId,
            'team_count'=>2,
            'teams'=>array(array('name'=>'Smoke A'),array('name'=>'Smoke B')),
            'host_mode'=>'human',
            'judge_mode'=>'human',
            'test_mode'=>1,
        ),$uid);
        ckmqp_chgk_e2e_check(
            $checks,
            'API запрещает 2 команды',
            empty($twoTeams['ok']) && ckmqp_chgk_e2e_code($twoTeams)==='chgk_single_team_only',
            ckmqp_chgk_e2e_code($twoTeams) ?: 'неожиданный ответ'
        );

        $runtimePayload = array(
            'runtime'=>'chgk_v1',
            'settings'=>array(
                'discussion_time_seconds'=>120,
                'final_answer_seconds'=>20,
                'single_final_answer'=>true,
                'roulette_enabled'=>true,
                'arbitration_mode'=>'host',
            ),
        );
        $created = ckm_quiz_create_room(array(
            'quiz_id'=>$quizId,
            'team_count'=>1,
            'teams'=>array(array('name'=>'Smoke Знатоки')),
            'host_mode'=>'human',
            'judge_mode'=>'human',
            'test_mode'=>1,
            'ckm_runtime_config_json'=>wp_json_encode($runtimePayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ),$uid);
        if (empty($created['ok'])) throw new RuntimeException('Не удалось создать тестовую комнату: '.(string)($created['code'] ?? $created['error'] ?? 'unknown'));
        $game = (array)($created['game'] ?? array());
        $gameId = (int)($game['id'] ?? 0);
        if ($gameId<=0) throw new RuntimeException('Тестовая комната создана без ID.');
        ckmqp_chgk_e2e_check($checks,'Создание комнаты с 1 командой',true,'game #'.$gameId);

        $formatSettings = ckm_quiz_json_decode((string)($game['format_settings_snapshot_json'] ?? ''));
        ckmqp_chgk_e2e_check($checks,'Время обсуждения 120 сек.',(int)($formatSettings['discussionSeconds'] ?? 0)===120,(int)($formatSettings['discussionSeconds'] ?? 0).' сек.');
        $selection = function_exists('ckm_quiz_chgk_question_selection_settings') ? ckm_quiz_chgk_question_selection_settings($game) : array();
        ckmqp_chgk_e2e_check($checks,'Рулетка не может быть включена',(string)($selection['mode'] ?? '')==='sequential' && empty($selection['rouletteEnabled']),'mode='.(string)($selection['mode'] ?? 'нет'));
        $competition = function_exists('ckm_quiz_chgk_competition_settings') ? ckm_quiz_chgk_competition_settings($game) : array();
        ckmqp_chgk_e2e_check($checks,'Только последовательный порядок',(string)($selection['mode'] ?? '')==='sequential' && empty($selection['randomized']),'mode='.(string)($selection['mode'] ?? 'нет'));
        ckmqp_chgk_e2e_check($checks,'Апелляции и тай-брейк отключены',empty($competition['appealsEnabled']) && (string)($competition['tieBreakMode'] ?? '')==='disabled','appeals='.(int)!empty($competition['appealsEnabled']).', tiebreak='.(string)($competition['tieBreakMode'] ?? ''));
        ckmqp_chgk_e2e_check($checks,'Окно окончательного ответа 20 сек.',(int)($formatSettings['finalAnswerSeconds'] ?? 0)===20,(int)($formatSettings['finalAnswerSeconds'] ?? 0).' сек.');
        ckmqp_chgk_e2e_check($checks,'Победа первым до 6',(string)($formatSettings['victoryMode'] ?? '')==='first_to_six' && (int)($formatSettings['winScore'] ?? 0)===6,'mode='.(string)($formatSettings['victoryMode'] ?? '').', target='.(int)($formatSettings['winScore'] ?? 0));
        ckmqp_chgk_e2e_check($checks,'Один вопрос = одно очко',(int)($formatSettings['pointsPerCorrectAnswer'] ?? 0)===1,'points='.(int)($formatSettings['pointsPerCorrectAnswer'] ?? 0));
        $flowSettings = ckm_quiz_chgk_flow_settings($game);
        ckmqp_chgk_e2e_check($checks,'Роль капитана отключена',
            (string)($flowSettings['participationMode'] ?? '')==='team_device'
            && (string)($flowSettings['finalizationMode'] ?? '')==='team_device'
            && empty($flowSettings['captainSupported']),
            'mode='.(string)($flowSettings['participationMode'] ?? '').', finalize='.(string)($flowSettings['finalizationMode'] ?? '')
        );

        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_teams_table() . " WHERE game_id=%d ORDER BY slot_no ASC LIMIT 1",
            $gameId
        ),ARRAY_A);
        if (!$team) throw new RuntimeException('Команда тестовой комнаты не найдена.');
        $join = ckm_quiz_join_guest_team($game,$team,'Smoke team device');
        if (empty($join['ok'])) throw new RuntimeException('Не удалось подключить тестового участника.');
        $member = (array)($join['member'] ?? array());
        $participantAuth = array(
            'role'=>'participant',
            'game_id'=>$gameId,
            'team_id'=>(int)$team['id'],
            'user_id'=>(int)($member['user_id'] ?? 0),
        );
        if ($participantAuth['user_id']<=0) throw new RuntimeException('Гостевая membership создана без user_id.');
        $participantState = ckm_quiz_build_state(ckm_quiz_get_game($gameId),$participantAuth,0);
        ckmqp_chgk_e2e_check($checks,'Участник не получает капитанскую роль',
            empty($participantState['you']['isCaptain']) && (string)($participantState['you']['memberRole'] ?? '')==='team_device'
            && !empty($participantState['you']['canFinalize']),
            'role='.(string)($participantState['you']['memberRole'] ?? '').', canFinalize='.(int)!empty($participantState['you']['canFinalize'])
        );
        $ready = ckm_quiz_chgk_set_team_ready($participantAuth,array('ready'=>true));
        if (empty($ready['ok'])) throw new RuntimeException('Не удалось подтвердить готовность команды.');
        ckmqp_chgk_e2e_check($checks,'Одна команда проходит preflight',!empty(ckm_quiz_chgk_preflight(ckm_quiz_get_game($gameId))['canStart']),'ready');

        $hostAuth = array('role'=>'host','game_id'=>$gameId,'user_id'=>$uid);
        $expectedExperts=0;
        $expectedGame=0;

        for ($round=1; $round<=11; $round++) {
            $opened = ckm_quiz_open_next_question($gameId,'host',$uid);
            if (empty($opened['ok'])) throw new RuntimeException('Раунд '.$round.': вопрос не открылся: '.(string)($opened['code'] ?? $opened['error'] ?? 'unknown'));
            $game = ckm_quiz_get_game($gameId);
            if (!$game) throw new RuntimeException('Раунд '.$round.': игра потеряна.');
            $flow = ckm_quiz_chgk_question_flow_state($game);
            if ($round===1) {
                ckmqp_chgk_e2e_check($checks,'Стартовая фаза = окно досрочного ответа',(string)($flow['phase'] ?? '')==='early_answer_offer',(string)($flow['phase'] ?? ''));

                // The current product rule is: question -> early-answer offer -> discussion -> final answer.
                // Start the discussion explicitly before the deterministic pause/F5/takeover contract.
                $discussionStart = ckm_quiz_chgk_start_discussion($gameId,'host',$uid);
                $game = ckm_quiz_get_game($gameId);
                $flow = ckm_quiz_chgk_question_flow_state($game);
                ckmqp_chgk_e2e_check($checks,'После окна досрочного ответа = discussion',
                    !empty($discussionStart['ok']) && (string)($flow['phase'] ?? '')==='discussion',
                    (string)($flow['phase'] ?? '')
                );

                // Deterministic pause test: emulate 73 seconds remaining, pause, rebuild state (F5),
                // take control from AI -> human without losing the phase, then resume.
                $forcedDiscussionDeadline=ckm_quiz_deadline_mysql(73);
                $wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$forcedDiscussionDeadline),array('id'=>$gameId));
                $paused=ckm_quiz_chgk_pause_game($gameId,$hostAuth);
                $pauseState=(array)($paused['pause'] ?? array());
                $discussionRemaining=(int)($pauseState['remainingSeconds'] ?? 0);
                ckmqp_chgk_e2e_check($checks,'Пауза сохраняет остаток обсуждения',
                    !empty($paused['ok']) && $discussionRemaining>=70 && $discussionRemaining<=73
                    && (string)($pauseState['pausedFormatPhase'] ?? '')==='discussion',
                    'код='.ckmqp_chgk_e2e_code($paused).', остаток='.$discussionRemaining.', phase='.(string)($pauseState['pausedFormatPhase'] ?? '')
                );

                $pausedGame=ckm_quiz_get_game($gameId);
                $stateBeforeF5=ckm_quiz_build_state($pausedGame,$participantAuth,0);
                $stateAfterF5=ckm_quiz_build_state(ckm_quiz_get_game($gameId),$participantAuth,0);
                $f5Remaining=(int)($stateAfterF5['game']['pausedRemainingSeconds'] ?? -1);
                ckmqp_chgk_e2e_check($checks,'F5 восстанавливает паузу обсуждения',
                    !empty($stateBeforeF5['game']['isPaused']) && !empty($stateAfterF5['game']['isPaused'])
                    && (int)($stateAfterF5['game']['questionDeadlineUnix'] ?? -1)===0
                    && (string)($stateAfterF5['game']['pausedPhase'] ?? '')==='discussion'
                    && abs($f5Remaining-$discussionRemaining)<=1
                    && (string)($stateAfterF5['questionFlow']['phase'] ?? '')==='discussion',
                    'остаток='.$f5Remaining.', deadline='.(int)($stateAfterF5['game']['questionDeadlineUnix'] ?? -1).', paused='.(int)!empty($stateAfterF5['game']['isPaused'])
                );

                $pausedAnswer=ckm_quiz_chgk_finalize_answer($participantAuth,array('answer_text'=>'Ответ на паузе','argumentation'=>''));
                ckmqp_chgk_e2e_check($checks,'Ответ на паузе блокируется движком',
                    empty($pausedAnswer['ok']) && ckmqp_chgk_e2e_code($pausedAnswer)==='game_paused',
                    ckmqp_chgk_e2e_code($pausedAnswer)
                );

                // Simulate an AI-controlled room and verify human takeover preserves the paused round.
                $wpdb->update(ckm_quiz_games_table(),array('host_mode_snapshot'=>'ai','host_mode'=>'ai'),array('id'=>$gameId));
                $takeover=ckm_quiz_chgk_switch_host_mode($gameId,'human',$hostAuth);
                $afterTakeover=ckm_quiz_get_game($gameId);
                ckmqp_chgk_e2e_check($checks,'ИИ → человек без сброса раунда',
                    !empty($takeover['ok']) && (string)($afterTakeover['host_mode_snapshot'] ?? '')==='human'
                    && !empty($afterTakeover['is_paused'])
                    && (int)($afterTakeover['current_question_id'] ?? 0)===(int)($game['current_question_id'] ?? 0)
                    && (int)($afterTakeover['paused_remaining_seconds'] ?? 0)>0,
                    'код='.ckmqp_chgk_e2e_code($takeover).', mode='.(string)($afterTakeover['host_mode_snapshot'] ?? '').', paused='.(int)!empty($afterTakeover['is_paused']).', остаток='.(int)($afterTakeover['paused_remaining_seconds'] ?? 0)
                );

                $resumed=ckm_quiz_chgk_resume_game($gameId,$hostAuth);
                $resumedGame=ckm_quiz_get_game($gameId);
                $resumedDeadline=ckm_quiz_mysql_timestamp((string)($resumedGame['question_deadline_at'] ?? ''));
                $resumedRemaining=$resumedDeadline>0 ? max(0,$resumedDeadline-ckm_quiz_now_timestamp()) : 0;
                ckmqp_chgk_e2e_check($checks,'Продолжить восстанавливает тот же остаток',
                    !empty($resumed['ok']) && empty($resumedGame['is_paused'])
                    && abs($resumedRemaining-$discussionRemaining)<=1
                    && (string)(ckm_quiz_chgk_question_flow_state($resumedGame)['phase'] ?? '')==='discussion',
                    'код='.ckmqp_chgk_e2e_code($resumed).', до='.$discussionRemaining.', после='.$resumedRemaining
                );

                $aiConfigured=function_exists('ckm_quiz_chgk_ai_host_available')
                    ? ckm_quiz_chgk_ai_host_available()
                    : (function_exists('ckm_quiz_ai_host_is_configured') && ckm_quiz_ai_host_is_configured());
                $switchAi=ckm_quiz_chgk_switch_host_mode($gameId,'ai',$hostAuth);
                if ($aiConfigured) {
                    $aiGame=ckm_quiz_get_game($gameId);
                    ckmqp_chgk_e2e_check($checks,'Человек → ИИ разрешён в discussion',
                        !empty($switchAi['ok']) && (string)($aiGame['host_mode_snapshot'] ?? '')==='ai'
                        && (int)($aiGame['current_question_id'] ?? 0)===(int)($resumedGame['current_question_id'] ?? 0)
                        && (string)(ckm_quiz_chgk_question_flow_state($aiGame)['phase'] ?? '')==='discussion',
                        'mode='.(string)($aiGame['host_mode_snapshot'] ?? '')
                    );
                    $backHuman=ckm_quiz_chgk_switch_host_mode($gameId,'human',$hostAuth);
                    if (empty($backHuman['ok'])) throw new RuntimeException('Не удалось вернуть управление человеку после проверки ИИ.');
                } else {
                    ckmqp_chgk_e2e_check($checks,'Человек → ИИ корректно отклонён без настроенного AI Host',
                        empty($switchAi['ok']) && ckmqp_chgk_e2e_code($switchAi)==='ai_host_unavailable',
                        ckmqp_chgk_e2e_code($switchAi)
                    );
                }

                $early = ckm_quiz_chgk_finalize_answer($participantAuth,array('answer_text'=>'Ранний ответ','argumentation'=>''));
                ckmqp_chgk_e2e_check($checks,'Ранний финальный ответ запрещён',empty($early['ok']) && ckmqp_chgk_e2e_code($early)==='final_answer_not_open',ckmqp_chgk_e2e_code($early));
            }

            $finalWindow = ckm_quiz_chgk_open_final_answer_window($gameId,'host',$uid);
            if (empty($finalWindow['ok'])) throw new RuntimeException('Раунд '.$round.': окно финального ответа не открылось.');
            $game = ckm_quiz_get_game($gameId);
            $flow = ckm_quiz_chgk_question_flow_state($game);
            if ($round===1) {
                ckmqp_chgk_e2e_check($checks,'После обсуждения = final_answer_open',(string)($flow['phase'] ?? '')==='final_answer_open',(string)($flow['phase'] ?? ''));

                // The final-answer window has its own pause/resume contract.
                $forcedFinalDeadline=ckm_quiz_deadline_mysql(17);
                $wpdb->update(ckm_quiz_games_table(),array('question_deadline_at'=>$forcedFinalDeadline),array('id'=>$gameId));
                $pausedFinal=ckm_quiz_chgk_pause_game($gameId,$hostAuth);
                $finalPauseState=(array)($pausedFinal['pause'] ?? array());
                $finalRemaining=(int)($finalPauseState['remainingSeconds'] ?? 0);
                $finalF5=ckm_quiz_build_state(ckm_quiz_get_game($gameId),$participantAuth,0);
                ckmqp_chgk_e2e_check($checks,'Пауза окна окончательного ответа',
                    !empty($pausedFinal['ok']) && $finalRemaining>=14 && $finalRemaining<=17
                    && (string)($finalPauseState['pausedFormatPhase'] ?? '')==='final_answer_open'
                    && !empty($finalF5['game']['isPaused'])
                    && (string)($finalF5['questionFlow']['phase'] ?? '')==='final_answer_open',
                    'код='.ckmqp_chgk_e2e_code($pausedFinal).', остаток='.$finalRemaining.', phase='.(string)($finalPauseState['pausedFormatPhase'] ?? '')
                );
                $answerDuringFinalPause=ckm_quiz_chgk_finalize_answer($participantAuth,array('answer_text'=>'Не должен сохраниться','argumentation'=>''));
                ckmqp_chgk_e2e_check($checks,'Финальный ответ на паузе запрещён',
                    empty($answerDuringFinalPause['ok']) && ckmqp_chgk_e2e_code($answerDuringFinalPause)==='game_paused',
                    ckmqp_chgk_e2e_code($answerDuringFinalPause)
                );
                $resumeFinal=ckm_quiz_chgk_resume_game($gameId,$hostAuth);
                $afterFinalResume=ckm_quiz_get_game($gameId);
                $finalDeadlineTs=ckm_quiz_mysql_timestamp((string)($afterFinalResume['question_deadline_at'] ?? ''));
                $afterFinalRemaining=$finalDeadlineTs>0 ? max(0,$finalDeadlineTs-ckm_quiz_now_timestamp()) : 0;
                ckmqp_chgk_e2e_check($checks,'Окно ответа продолжено с остатка',
                    !empty($resumeFinal['ok']) && empty($afterFinalResume['is_paused'])
                    && abs($afterFinalRemaining-$finalRemaining)<=1
                    && (string)(ckm_quiz_chgk_question_flow_state($afterFinalResume)['phase'] ?? '')==='final_answer_open',
                    'код='.ckmqp_chgk_e2e_code($resumeFinal).', до='.$finalRemaining.', после='.$afterFinalRemaining
                );
            }

            // Rounds 1,3,5,7,9 and 11 go to Experts; even rounds go to Game.
            $expertsRound = ($round % 2) === 1;
            if ($expertsRound) {
                $finalized = ckm_quiz_chgk_finalize_answer($participantAuth,array(
                    'answer_text'=>'Smoke accepted '.$round,
                    'argumentation'=>'Smoke E2E',
                ));
                if (empty($finalized['ok'])) throw new RuntimeException('Раунд '.$round.': финальный ответ не зафиксирован: '.(string)($finalized['code'] ?? $finalized['error'] ?? 'unknown'));
                if ($round===1) {
                    $repeat = ckm_quiz_chgk_finalize_answer($participantAuth,array('answer_text'=>'Повтор','argumentation'=>''));
                    ckmqp_chgk_e2e_check($checks,'Повтор финального ответа запрещён',empty($repeat['ok']) && ckmqp_chgk_e2e_code($repeat)==='final_answer_locked',ckmqp_chgk_e2e_code($repeat));
                }
                $game = ckm_quiz_get_game($gameId);
                if ((string)($game['quiz_phase'] ?? '')==='question_open') {
                    $closed = ckm_quiz_close_current_question($gameId,'host',$uid);
                    if (empty($closed['ok'])) throw new RuntimeException('Раунд '.$round.': вопрос не закрылся.');
                }
                $answer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE game_id=%d AND team_id=%d AND question_id=%d LIMIT 1",
                    $gameId,(int)$team['id'],(int)(ckm_quiz_get_game($gameId)['current_question_id'] ?? 0)
                ),ARRAY_A);
                if (!$answer) throw new RuntimeException('Раунд '.$round.': ответ не найден после фиксации.');
                $scored = ckm_quiz_score_answer($gameId,(int)$answer['id'],1,'accepted','Smoke accepted',$hostAuth,'chgk-e2e-'.$round);
                if (empty($scored['ok'])) throw new RuntimeException('Раунд '.$round.': арбитраж accepted не сохранён.');
                $expectedExperts++;
            } else {
                // Deliberately close without an answer: this must award the round to Game.
                $closed = ckm_quiz_close_current_question($gameId,'host',$uid);
                if (empty($closed['ok'])) throw new RuntimeException('Раунд '.$round.': вопрос без ответа не закрылся.');
                $expectedGame++;
            }

            $game = ckm_quiz_get_game($gameId);
            $beforeReveal = ckm_quiz_chgk_question_flow_state($game);
            if ($round===1) {
                ckmqp_chgk_e2e_check($checks,'Правильный ответ скрыт до reveal',empty($beforeReveal['answerRevealed']) && empty($beforeReveal['publicReveal']),'phase='.(string)($beforeReveal['phase'] ?? ''));
                if (!empty($aiConfigured)) {
                    $closedQuestionId=(int)($game['current_question_id'] ?? 0);
                    $closedSwitch=ckm_quiz_chgk_switch_host_mode($gameId,'ai',$hostAuth);
                    $afterClosedSwitch=ckm_quiz_get_game($gameId);
                    $afterClosedFlow=ckm_quiz_chgk_question_flow_state($afterClosedSwitch);
                    ckmqp_chgk_e2e_check($checks,'Человек → ИИ разрешён после закрытия ответа до reveal',
                        !empty($closedSwitch['ok'])
                        && (string)($afterClosedSwitch['host_mode_snapshot'] ?? '')==='ai'
                        && (int)($afterClosedSwitch['current_question_id'] ?? 0)===$closedQuestionId
                        && empty($afterClosedFlow['answerRevealed']),
                        'код='.ckmqp_chgk_e2e_code($closedSwitch).', mode='.(string)($afterClosedSwitch['host_mode_snapshot'] ?? '').', phase='.(string)($afterClosedFlow['phase'] ?? '')
                    );
                    $backHuman=ckm_quiz_chgk_switch_host_mode($gameId,'human',$hostAuth);
                    if (empty($backHuman['ok'])) throw new RuntimeException('Не удалось вернуть управление человеку после проверки закрытого ответа.');
                    $game=ckm_quiz_get_game($gameId);
                }
            }
            $revealed = ckm_quiz_chgk_reveal_answer($gameId,$hostAuth);
            if (empty($revealed['ok'])) throw new RuntimeException('Раунд '.$round.': правильный ответ не раскрылся: '.(string)($revealed['code'] ?? $revealed['error'] ?? 'unknown'));
            $game = ckm_quiz_get_game($gameId);
            $duel = ckm_quiz_chgk_single_team_duel_state($game);
            $scoreOk = (int)($duel['expertsScore'] ?? -1)===$expectedExperts && (int)($duel['gameScore'] ?? -1)===$expectedGame;
            ckmqp_chgk_e2e_check($checks,'Раунд '.$round.': счёт '.$expectedExperts.':'.$expectedGame,$scoreOk,'факт '.(int)($duel['expertsScore'] ?? -1).':'.(int)($duel['gameScore'] ?? -1));

            if ($round < 10) {
                ckmqp_chgk_e2e_check($checks,'Раунд '.$round.': матч ещё идёт',(string)($game['status'] ?? '')!=='finished','status='.(string)($game['status'] ?? ''));
            } elseif ($round===10) {
                ckmqp_chgk_e2e_check($checks,'После 10 вопросов счёт 5:5 и матч продолжается',$expectedExperts===5 && $expectedGame===5 && (string)($game['status'] ?? '')!=='finished','status='.(string)($game['status'] ?? '').', score 5:5');
            } else {
                ckmqp_chgk_e2e_check($checks,'11-й вопрос завершает матч 6:5',(string)($game['status'] ?? '')==='finished' && (string)($duel['winner'] ?? '')==='experts' && (int)($duel['expertsScore'] ?? 0)===6 && (int)($duel['gameScore'] ?? 0)===5,'status='.(string)($game['status'] ?? '').', winner='.(string)($duel['winner'] ?? '').', score '.(int)($duel['expertsScore'] ?? 0).':'.(int)($duel['gameScore'] ?? 0));
            }
        }

        $afterFinish = ckm_quiz_open_next_question($gameId,'host',$uid);
        $finishCode = ckmqp_chgk_e2e_code($afterFinish);
        ckmqp_chgk_e2e_check($checks,'После 6-го очка следующий вопрос запрещён',empty($afterFinish['ok']) && in_array($finishCode,array('game_finished','chgk_match_complete'),true),$finishCode ?: 'неожиданный ответ');

        $eventNames = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT action FROM " . ckm_quiz_events_table() . " WHERE game_id=%d",
            $gameId
        )) ?: array();
        foreach (array('chgk_discussion_started','game_paused','game_resumed','human_host_takeover','chgk_discussion_closed','chgk_final_answer_opened','chgk_answers_closed','chgk_answer_revealed','game_finished') as $eventName) {
            ckmqp_chgk_e2e_check($checks,'Событие '.$eventName,in_array($eventName,$eventNames,true),in_array($eventName,$eventNames,true)?'есть':'нет');
        }
        if (!empty($aiConfigured)) {
            ckmqp_chgk_e2e_check($checks,'Событие ai_host_takeover',in_array('ai_host_takeover',$eventNames,true),in_array('ai_host_takeover',$eventNames,true)?'есть':'нет');
        }
    } catch (Throwable $e) {
        $checks[] = array('name'=>'Исключение smoke-теста','ok'=>false,'detail'=>$e->getMessage());
    } finally {
        if ($gameId>0) ckmqp_chgk_e2e_cleanup($gameId);
    }

    $passed=0;
    foreach($checks as $check) if(!empty($check['ok'])) $passed++;
    return array('ok'=>$checks && $passed===count($checks),'passed'=>$passed,'total'=>count($checks),'checks'=>$checks);
}

function ckmqp_chgk_e2e_page(): void {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $result = null;
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckmqp_run_chgk_e2e'])) {
        check_admin_referer('ckmqp_chgk_e2e');
        $result = ckmqp_chgk_e2e_run();
    }
    echo '<div class="wrap"><h1>Smoke-тест: Битва знатоков</h1>';
    echo '<p>Тест создаёт временную комнату <code>test_mode=1</code>, реально проверяет паузу/F5/перехват ведущего, затем проходит серверный цикл из 11 вопросов по схеме <strong>5:5 → 6:5</strong> и удаляет тестовые игровые данные.</p>';
    echo '<form method="post">'; wp_nonce_field('ckmqp_chgk_e2e');
    echo '<p><button class="button button-primary" name="ckmqp_run_chgk_e2e" value="1">Запустить smoke-тест «Битвы знатоков»</button></p></form>';
    if (is_array($result)) {
        echo '<h2>Результат: '.(int)$result['passed'].' / '.(int)$result['total'].' PASS</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Проверка</th><th>Результат</th><th>Детали</th></tr></thead><tbody>';
        foreach ((array)$result['checks'] as $check) {
            $ok=!empty($check['ok']);
            echo '<tr><td>'.esc_html((string)$check['name']).'</td><td><strong style="color:'.($ok?'#008a20':'#b32d2e').'">'.($ok?'PASS':'FAIL').'</strong></td><td><code>'.esc_html((string)$check['detail']).'</code></td></tr>';
        }
        echo '</tbody></table>';
        if (!empty($result['ok'])) echo '<div class="notice notice-success inline"><p><strong>Новый цикл «Битвы знатоков» прошёл сквозную серверную проверку.</strong></p></div>';
        else echo '<div class="notice notice-error inline"><p><strong>Есть FAIL. Не продолжайте изменения механики, пока ошибка не устранена.</strong></p></div>';
    }
    echo '</div>';
}

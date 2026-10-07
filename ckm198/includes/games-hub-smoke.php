<?php
/** Admin end-to-end smoke test for all eight public Games Hub formats. */
if (!defined('ABSPATH')) exit;

function ckmqp_games_hub_smoke_menu(): void {
    $formatCount = count(ckmqp_games_hub_smoke_cases());
    $title = 'Диагностика ' . $formatCount . ' форматов';
    add_submenu_page(
        'ckm-quiz-pro',
        $title,
        $title,
        'manage_options',
        'ckm-quiz-pro-games-hub-smoke',
        'ckmqp_games_hub_smoke_page'
    );
}
add_action('admin_menu','ckmqp_games_hub_smoke_menu',35);

function ckmqp_games_hub_smoke_cases(): array {
    return array(
        'classic_quiz_v1'=>array(
            'label'=>'Классический квиз','slug'=>'demo-classic-quiz','format'=>'classic_quiz','mode'=>'',
            'settings'=>array('answer_time_seconds'=>37,'speed_bonus_enabled'=>false),
        ),
        'chgk_v1'=>array(
            'label'=>'Битва знатоков','slug'=>'demo-battle-experts','format'=>'chgk','mode'=>'',
            'settings'=>array('discussion_time_seconds'=>60,'single_final_answer'=>true,'roulette_enabled'=>true,'arbitration_mode'=>'ai_or_host'),
        ),
        'jeopardy_v1'=>array(
            'label'=>'Интеллектуальный батл','slug'=>'ckm-demo-intellectual-battle','format'=>'jeopardy','mode'=>'',
            'settings'=>array('answer_time_seconds'=>20,'category_choice_enabled'=>true,'question_values_enabled'=>true,'negative_score_enabled'=>true),
        ),
        'decision_price_v1'=>array(
            'label'=>'Управленческая игра "Ваш выбор"','slug'=>'demo-solution-price','format'=>'solution_price','mode'=>'',
            'settings'=>array('decision_time_seconds'=>120,'consequence_round_enabled'=>true,'team_defense_enabled'=>true,'arbitration_mode'=>'host'),
        ),
        'persuade_me_v1'=>array(
            'label'=>'Переговори другого','slug'=>'demo-negotiation-communicate','format'=>'negotiation_duel','mode'=>'communicate',
            'settings'=>array('round_time_seconds'=>30,'prep_time_seconds'=>30),
        ),
        'sales_v1'=>array(
            'label'=>'Эффективный продажник','slug'=>'demo-negotiation-sales','format'=>'negotiation_duel','mode'=>'sales',
            'settings'=>array('round_time_seconds'=>90,'objections_enabled'=>true,'next_step_required'=>true,'client_role_mode'=>'ai','arbitration_mode'=>'ai'),
        ),
        'business_negotiation_v1'=>array(
            'label'=>'Мастер переговоров','slug'=>'demo-negotiation-business','format'=>'negotiation_duel','mode'=>'business',
            'settings'=>array('round_time_seconds'=>240,'private_briefs_enabled'=>true,'agreement_capture_enabled'=>true,'arbitration_mode'=>'ai_or_host'),
        ),
        'express_round_v1'=>array(
            'label'=>'Экспресс-раунд','slug'=>'demo-negotiation-express','format'=>'negotiation_duel','mode'=>'express',
            'settings'=>array('round_time_seconds'=>180,'prep_time_seconds'=>15,'role_swap_enabled'=>true,'rapid_feedback_enabled'=>true,'arbitration_mode'=>'host'),
        ),
    );
}

function ckmqp_games_hub_smoke_cleanup(int $gameId): void {
    if ($gameId <= 0) return;
    global $wpdb;
    $tables = array();
    foreach (array('ckm_quiz_mechanics_table','ckm_quiz_members_table','ckm_quiz_answers_table','ckm_quiz_score_events_table','ckm_quiz_events_table','ckm_quiz_teams_table','ckm_quiz_games_table') as $fn) {
        if (function_exists($fn)) $tables[] = $fn();
    }
    foreach (array_unique($tables) as $table) {
        $wpdb->query($wpdb->prepare("DELETE FROM `{$table}` WHERE game_id=%d", $gameId));
    }
    // games table uses id, not game_id.
    if (function_exists('ckm_quiz_games_table')) {
        $wpdb->query($wpdb->prepare("DELETE FROM `" . ckm_quiz_games_table() . "` WHERE id=%d", $gameId));
    }
}

function ckmqp_games_hub_smoke_equal($actual, $expected): bool {
    if (is_bool($expected)) return (bool)$actual === $expected;
    if (is_int($expected)) return (int)$actual === $expected;
    return (string)$actual === (string)$expected;
}


/** Pure end-to-end guard for the four-round "Переговори другого" state machine. */
function ckmqp_games_hub_smoke_persuade_full_flow(): array {
    if (!function_exists('ckmqp_show_initial') || !function_exists('ckmqp_show_reduce') || !function_exists('ckmqp_show_tick') || !function_exists('ckmqp_show_story_cumulative_score')) {
        return array('ok'=>false,'detail'=>'state-machine контракт не найден');
    }
    $s = ckmqp_show_initial();
    $now = 1000;
    $act = static function(array $state,int $slot,string $command,int $at,array $extra=array()): array {
        $payload = array_merge(array(
            'command'=>$command,
            'round'=>(int)($state['round']??0),
            'attempt'=>(int)($state['attempt']??0),
            'question_index'=>(int)($state['questionIndex']??($state['storyQuestionIndex']??0)),
        ),$extra);
        return ckmqp_show_reduce($state,array('role'=>'participant','slot'=>$slot,'team_id'=>100+$slot),$payload,$at);
    };
    $readyAll = static function(array $state,int $at) use ($act): array {
        foreach(array(1,2,3) as $slot){$r=$act($state,$slot,'ready',$at);if(empty($r['ok']))return array();$state=$r['session'];}
        return $state;
    };
    $advance = static function(array $state,int $at): array {
        $r=ckmqp_show_reduce($state,array('role'=>'host'),array('command'=>'advance','round'=>(int)($state['round']??0),'attempt'=>(int)($state['attempt']??0)),$at);
        return empty($r['ok'])?array():$r['session'];
    };

    // Only the initial game start asks all three teams for readiness.
    $s=$readyAll($s,$now);if(!$s||($s['phase']??'')!=='dialogue'||(int)($s['deadline']??0)!==0)return array('ok'=>false,'detail'=>'стартовая готовность → свободный диалог');

    // Round 1: Удержи цель. After each review the host uses one transition; no repeated readiness.
    foreach(array(0=>76,1=>70,2=>64) as $attempt=>$score){
        if((int)($s['attempt']??-1)!==$attempt || (int)($s['round']??-1)!==0)return array('ok'=>false,'detail'=>'раунд 1: неверное испытание');
        if(($s['phase']??'')!=='dialogue'||(int)($s['deadline']??0)!==0)return array('ok'=>false,'detail'=>'раунд 1: свободный диалог без подготовки по таймеру');
        $closed=ckmqp_show_reduce($s,array('role'=>'host'),array('command'=>'finish_dialogue','round'=>0,'attempt'=>$attempt),$now+120);if(empty($closed['ok']))return array('ok'=>false,'detail'=>'раунд 1: завершение диалога');$s=$closed['session'];
        $s['reviews'][$attempt]=array('status'=>'done','review'=>array('total'=>$score),'completedAt'=>$now+121);
        $s=$advance($s,$now+126);if(!$s)return array('ok'=>false,'detail'=>'раунд 1: единый переход ведущего');
        $now+=200;
    }
    if((int)($s['round']??-1)!==1||(int)($s['attempt']??-1)!==0||($s['phase']??'')!=='dialogue')return array('ok'=>false,'detail'=>'переход 1 → 2 без повторной готовности');

    // Round 2: Скрытая задача.
    foreach(array(0=>76,1=>70,2=>64) as $attempt=>$score){
        if((int)($s['attempt']??-1)!==$attempt || (int)($s['round']??-1)!==1)return array('ok'=>false,'detail'=>'раунд 2: неверное испытание');
        if(($s['phase']??'')!=='dialogue'||(int)($s['deadline']??0)!==0)return array('ok'=>false,'detail'=>'раунд 2: свободный диалог без подготовки по таймеру');
        $closed=ckmqp_show_reduce($s,array('role'=>'host'),array('command'=>'finish_dialogue','round'=>1,'attempt'=>$attempt),$now+120);if(empty($closed['ok']))return array('ok'=>false,'detail'=>'раунд 2: завершение диалога');$s=$closed['session'];
        $s['hiddenReviews'][$attempt]=array('status'=>'done','review'=>array('total'=>$score),'completedAt'=>$now+121);
        $s=$advance($s,$now+126);if(!$s)return array('ok'=>false,'detail'=>'раунд 2: единый переход ведущего');
        $now+=200;
    }
    if((int)($s['round']??-1)!==2||(int)($s['attempt']??-1)!==0||($s['phase']??'')!=='hard_answer')return array('ok'=>false,'detail'=>'переход 2 → 3 без повторной готовности');

    // Round 3: Неудобный вопрос.
    foreach(array(0=>76,1=>70,2=>64) as $attempt=>$score){
        if(($s['phase']??'')!=='hard_answer'||(int)($s['questionIndex']??-1)!==0)return array('ok'=>false,'detail'=>'раунд 3: старт вопросов');
        $active=$attempt+1;
        for($q=0;$q<3;$q++){
            $at=max(1,(int)($s['deadline']??30)-20);
            $r=$act($s,$active,'hard_answer',$at,array('question_index'=>$q,'text'=>'Конкретный ответ '.$attempt.'-'.$q,'request_id'=>'e2e_hard_'.$attempt.'_'.$q.'_request'));if(empty($r['ok']))return array('ok'=>false,'detail'=>'раунд 3: ответ '.($q+1).' / '.($r['code']??'no_code'));$s=$r['session'];
        }
        if(($s['phase']??'')!=='review')return array('ok'=>false,'detail'=>'раунд 3: арбитраж');
        $s['hardReviews'][$attempt]=array('status'=>'done','review'=>array('total'=>$score),'completedAt'=>$now+5);
        $s=$advance($s,$now+10);if(!$s)return array('ok'=>false,'detail'=>'раунд 3: единый переход ведущего');
        $now+=100;
    }
    if((int)($s['round']??-1)!==3||(int)($s['attempt']??-1)!==0||($s['phase']??'')!=='preparation'||count($s['storyModes']??array())!==3)return array('ok'=>false,'detail'=>'переход 3 → финал без повторной готовности');

    // Round 4: Проверь историю.
    foreach(array(0,1,2) as $attempt){
        if(($s['phase']??'')!=='preparation'||(int)($s['deadline']??0)!==0)return array('ok'=>false,'detail'=>'финал: подготовка должна быть без таймера');
        $active=$attempt+1;$storyAt=$now+1;
        $r=$act($s,$active,'story_ready',$storyAt);if(empty($r['ok']))return array('ok'=>false,'detail'=>'финал: готовность рассказчика / '.($r['code']??'no_code'));$s=$r['session'];
        if(($s['phase']??'')!=='story_tell'||(int)($s['deadline']??0)!==0)return array('ok'=>false,'detail'=>'финал: рассказ должен быть без таймера по умолчанию');
        $r=$act($s,$active,'story_submit',$storyAt+1,array('text'=>'Проверочная история команды '.$active,'request_id'=>'e2e_story_'.$attempt.'_request'));if(empty($r['ok']))return array('ok'=>false,'detail'=>'финал: рассказ / '.($r['code']??'no_code'));$s=$r['session'];
        $opponents=ckmqp_show_story_opponent_slots($attempt);$qAt=$storyAt+1;
        foreach($opponents as $opp){
            for($i=0;$i<2;$i++){
                $r=$act($s,$opp,'story_question',$qAt++,array('text'=>'Уточняющий вопрос '.$opp.'-'.$i.'?','request_id'=>'e2e_story_q_'.$attempt.'_'.$opp.'_'.$i));if(empty($r['ok']))return array('ok'=>false,'detail'=>'финал: четыре вопроса');$s=$r['session'];
            }
        }
        for($q=0;$q<4;$q++){
            $aAt=max(1,(int)($s['deadline']??20)-10);
            $r=$act($s,$active,'story_answer',$aAt,array('question_index'=>$q,'text'=>'Ответ на вопрос '.$q,'request_id'=>'e2e_story_answer_'.$attempt.'_'.$q.'_request'));if(empty($r['ok']))return array('ok'=>false,'detail'=>'финал: ответ '.($q+1).' / '.($r['code']??'no_code'));$s=$r['session'];
        }
        $mode=(string)($s['storyModes'][$attempt]??'');$voteAt=$qAt+20;
        foreach($opponents as $opp){$r=$act($s,$opp,'story_vote',$voteAt++,array('vote'=>$mode));if(empty($r['ok']))return array('ok'=>false,'detail'=>'финал: тайный голос');$s=$r['session'];}
        if(($s['phase']??'')!=='review'||!is_array($s['storyResults'][$attempt]??null))return array('ok'=>false,'detail'=>'финал: раскрытие результата');
        $storyScore=array(76,70,64)[$attempt];
        $s['storyReviews'][$attempt]=array('status'=>'done','review'=>array('total'=>$storyScore,'participants'=>array('speaker'=>array('slot'=>$active,'total'=>$storyScore))),'completedAt'=>$now+55);
        $s=$advance($s,$now+56);if(!$s)return array('ok'=>false,'detail'=>'финал: единый переход ведущего');
        $now+=100;
    }
    if(($s['phase']??'')!=='game_complete'||!ckmqp_show_story_all_results_done($s))return array('ok'=>false,'detail'=>'игра не дошла до game_complete');
    $scores=array(1=>ckmqp_show_story_cumulative_score($s,1),2=>ckmqp_show_story_cumulative_score($s,2),3=>ckmqp_show_story_cumulative_score($s,3));
    if($scores!==array(1=>304,2=>280,3=>256))return array('ok'=>false,'detail'=>'накопительный счёт: '.implode('/',$scores));
    if(max($scores)>304)return array('ok'=>false,'detail'=>'превышен максимум 304');
    return array('ok'=>true,'detail'=>'4 раунда → game_complete; одна стартовая готовность, далее единый переход ведущего');
}

function ckmqp_games_hub_smoke_run_case(string $runtime, array $case, int $userId): array {
    global $wpdb;
    $checks = array();
    $gameId = 0;
    try {
        $mapped = function_exists('ckmqp_hub_runtime_to_core_format') ? ckmqp_hub_runtime_to_core_format($runtime) : '';
        $checks[] = array('name'=>'Маршрутизация каталога','ok'=>$mapped === $case['format'],'detail'=>$mapped ?: 'нет');
        $mode = function_exists('ckmqp_hub_runtime_negotiation_mode') ? ckmqp_hub_runtime_negotiation_mode($runtime) : '';
        $checks[] = array('name'=>'Режим формата','ok'=>$mode === $case['mode'],'detail'=>$mode ?: '—');

        $quiz = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ckm_quiz_quizzes_table() . ' WHERE slug=%s LIMIT 1',
            $case['slug']
        ), ARRAY_A);
        if (!$quiz) throw new RuntimeException('Демо-шаблон не найден: ' . $case['slug']);
        $checks[] = array('name'=>'Демо-шаблон','ok'=>true,'detail'=>'ID ' . (int)$quiz['id']);

        if (function_exists('ckmqp_hub_find_quiz_for_runtime')) {
            $routeRows = $wpdb->get_results(
                'SELECT id,format_key,format_settings_json FROM ' . ckm_quiz_quizzes_table() . " WHERE status='published' ORDER BY id",
                ARRAY_A
            ) ?: array();
            $routedId = ckmqp_hub_find_quiz_for_runtime($routeRows, $runtime);
            $checks[] = array(
                'name'=>'Каталог → нужный шаблон',
                'ok'=>$routedId === (int)$quiz['id'],
                'detail'=>'ожидался #' . (int)$quiz['id'] . ', выбран #' . $routedId
            );
        }

        $payload = array('runtime'=>$runtime,'settings'=>$case['settings']);
        $teamCount = max(1, (int)($quiz['min_teams'] ?? 1));
        $teams = array();
        for ($i=1; $i<=$teamCount; $i++) $teams[] = array('name'=>'Smoke ' . $i);
        $created = ckm_quiz_create_room(array(
            'quiz_id'=>(int)$quiz['id'],
            'team_count'=>$teamCount,
            'teams'=>$teams,
            'host_mode'=>'human',
            'test_mode'=>1,
            'ckm_runtime_config_json'=>wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ), $userId);
        if (empty($created['ok'])) throw new RuntimeException('Комната не создана: ' . (string)($created['code'] ?? $created['error'] ?? 'unknown'));
        $game = (array)($created['game'] ?? array());
        $gameId = (int)($game['id'] ?? 0);
        if ($gameId <= 0) throw new RuntimeException('Комната создана без ID.');
        $checks[] = array('name'=>'Тестовая комната','ok'=>true,'detail'=>'game #' . $gameId);
        $checks[] = array('name'=>'Core runtime','ok'=>(string)($game['format_key_snapshot'] ?? '') === $case['format'],'detail'=>(string)($game['format_key_snapshot'] ?? ''));

        $room = ckm_quiz_json_decode($game['settings_snapshot_json'] ?? '');
        $format = ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
        $checks[] = array('name'=>'Runtime в snapshot','ok'=>(string)($room['gamesHubRuntime'] ?? '') === $runtime,'detail'=>(string)($room['gamesHubRuntime'] ?? 'нет'));

        if ($runtime === 'classic_quiz_v1') {
            $checks[] = array('name'=>'Таймер','ok'=>(int)($room['secondsPerQuestion'] ?? 0) === 37,'detail'=>(int)($room['secondsPerQuestion'] ?? 0) . ' сек.');
            $checks[] = array('name'=>'Бонус скорости','ok'=>array_key_exists('speedBonusEnabled',$room) && $room['speedBonusEnabled'] === false,'detail'=>!empty($room['speedBonusEnabled'])?'включён':'выключен');
        } elseif ($runtime === 'chgk_v1') {
            $checks[] = array('name'=>'Время обсуждения','ok'=>(int)($format['discussionSeconds'] ?? 0) === 60,'detail'=>(int)($format['discussionSeconds'] ?? 0) . ' сек.');
            $flowSettings = function_exists('ckm_quiz_chgk_question_flow_settings') ? ckm_quiz_chgk_question_flow_settings($game) : array();
            $checks[] = array('name'=>'Окно досрочного ответа','ok'=>(int)($flowSettings['earlyAnswerSeconds'] ?? 0) === 5,'detail'=>(int)($flowSettings['earlyAnswerSeconds'] ?? 0) . ' сек.');
            $checks[] = array('name'=>'Окно окончательного ответа','ok'=>(int)($flowSettings['finalAnswerSeconds'] ?? 0) === 20,'detail'=>(int)($flowSettings['finalAnswerSeconds'] ?? 0) . ' сек.');
            $voiceBridge = function_exists('ckm_quiz_chgk_start_ai_early_answer_after_voice');
            $gateSource = @file_get_contents(__DIR__ . '/standalone-chgk.php');
            $flowSource = @file_get_contents(dirname(__DIR__) . '/core-source/includes/quiz/quiz-chgk-question-flow.php');
            $voiceGate = $voiceBridge
                && is_string($gateSource) && strpos($gateSource,'chgk_ai_arbitration_voice_completed') !== false
                && strpos($gateSource,'chgk_answer_reveal_voice_completed') !== false
                && is_string($flowSource) && strpos($flowSource,"\$hostEvent==='question_closed' || \$hostEvent==='answer_revealed'") !== false;
            $checks[] = array('name'=>'Voice-gate ИИ-ведущего','ok'=>$voiceGate,'detail'=>$voiceGate?'вопрос → 5 сек. → арбитраж → правильный ответ → следующий вопрос':'контракт не найден');
            $sel = function_exists('ckm_quiz_chgk_question_selection_settings') ? ckm_quiz_chgk_question_selection_settings($game) : array();
            $checks[] = array('name'=>'Рулетка отключена','ok'=>(string)($sel['mode'] ?? '') === 'sequential' && empty($sel['rouletteEnabled']) && empty($sel['randomized']),'detail'=>'mode=' . (string)($sel['mode'] ?? 'нет'));
            $checks[] = array('name'=>'Арбитраж','ok'=>(string)($game['judge_mode_snapshot'] ?? '') === 'hybrid','detail'=>(string)($game['judge_mode_snapshot'] ?? ''));
            $q = ckm_quiz_get_question_at((int)$game['quiz_id'],(int)$game['quiz_revision'],0);
            if ($q) {
                $seconds = ckm_quiz_runtime_question_seconds($game,$q,60);
                $checks[] = array('name'=>'Стартовый дедлайн вопроса','ok'=>$seconds === 5,'detail'=>$seconds . ' сек.');
            }
        } elseif ($runtime === 'jeopardy_v1') {
            $checks[] = array('name'=>'Таймер ответа','ok'=>(int)($format['buzzerSeconds'] ?? 0) === 20,'detail'=>(int)($format['buzzerSeconds'] ?? 0) . ' сек.');
            $checks[] = array('name'=>'Отрицательные баллы','ok'=>function_exists('ckm_quiz_jeopardy_negative_score_enabled') && ckm_quiz_jeopardy_negative_score_enabled($game),'detail'=>!empty($format['negativeScoreEnabled'])?'включены':'выключены');
            $team = $created['credentials']['teams'][0] ?? array();
            $teamId = (int)($team['teamId'] ?? 0);
            $q = ckm_quiz_get_question_at((int)$game['quiz_id'],(int)$game['quiz_revision'],0);
            if ($teamId > 0 && $q && function_exists('ckm_quiz_jeopardy_wrong_answer_penalty')) {
                $value = function_exists('ckm_quiz_jeopardy_question_value') ? ckm_quiz_jeopardy_question_value($q) : 0;
                $p1 = ckm_quiz_jeopardy_wrong_answer_penalty($gameId,$game,$q,$teamId,'admin',$userId,'smoke');
                $score1 = (int)$wpdb->get_var($wpdb->prepare('SELECT score FROM ' . ckm_quiz_teams_table() . ' WHERE id=%d',$teamId));
                $p2 = ckm_quiz_jeopardy_wrong_answer_penalty($gameId,$game,$q,$teamId,'admin',$userId,'smoke');
                $score2 = (int)$wpdb->get_var($wpdb->prepare('SELECT score FROM ' . ckm_quiz_teams_table() . ' WHERE id=%d',$teamId));
                $ok = !empty($p1['ok']) && !empty($p2['ok']) && $value > 0 && $score1 === -$value && $score2 === -$value;
                $checks[] = array('name'=>'Штраф + защита от дубля','ok'=>$ok,'detail'=>'номинал ' . $value . ', счёт ' . $score1 . ' → ' . $score2);
            }
        } elseif ($runtime === 'decision_price_v1') {
            $checks[] = array('name'=>'Время решения','ok'=>(int)($format['secondsPerStage'] ?? 0) === 120,'detail'=>(int)($format['secondsPerStage'] ?? 0) . ' сек.');
            $checks[] = array('name'=>'Арбитраж ведущего','ok'=>(string)($game['judge_mode_snapshot'] ?? '') === 'human','detail'=>(string)($game['judge_mode_snapshot'] ?? ''));
        } elseif ($runtime === 'persuade_me_v1') {
            $credentialsTeams = (array)($created['credentials']['teams'] ?? array());
            $checks[] = array('name'=>'Три команды','ok'=>count($credentialsTeams) === 3,'detail'=>count($credentialsTeams) . ' команды');
            $checks[] = array('name'=>'Режим «Переговори другого»','ok'=>function_exists('ckmqp_show_is_game') && ckmqp_show_is_game($game),'detail'=>(string)($format['negotiationMode'] ?? 'нет'));
            $timingOk = false;
            if (function_exists('ckmqp_show_initial') && function_exists('ckmqp_show_reduce') && function_exists('ckmqp_show_tick')) {
                $session = ckmqp_show_initial();
                foreach (array(1,2,3) as $slot) {
                    $step = ckmqp_show_reduce($session,array('role'=>'participant','slot'=>$slot,'team_id'=>$slot),array('command'=>'ready','attempt'=>0),100);
                    if (empty($step['ok'])) { $session=array(); break; }
                    $session=$step['session'];
                }
                if ($session && ($session['phase'] ?? '') === 'dialogue' && (int)($session['deadline'] ?? 0) === 0) {
                    $timingOk = (int)($session['hardAnswerLimit']??-1)===0 && (int)($session['storyAnswerLimit']??0)===0;
                }
            }
            $checks[] = array('name'=>'Этапы шоу','ok'=>$timingOk,'detail'=>$timingOk?'свободный диалог; неудобный вопрос без отсчёта; ответ рассказчика без обязательного лимита':'контракт этапов не совпал');
            $reviewOk = function_exists('ckmqp_show_run_review') && function_exists('ckmqp_show_validate_review');
            $checks[] = array('name'=>'ИИ-арбитраж шоу','ok'=>$reviewOk,'detail'=>$reviewOk?'подключён':'контракт не найден');
            $finalOk = false;
            if (function_exists('ckmqp_show_story_dossiers') && function_exists('ckmqp_show_story_result') && function_exists('ckmqp_show_all_hard_reviews_done')) {
                $final = ckmqp_show_initial(); $final['round']=2; $final['attempt']=2; $final['phase']='round3_complete';
                foreach(array(0,1,2) as $a) $final['hardReviews'][$a]=array('status'=>'done','review'=>array('total'=>0));
                $step=ckmqp_show_reduce($final,array('role'=>'host'),array('command'=>'advance','round'=>2,'attempt'=>2),200);
                $final=empty($step['ok'])?array():$step['session'];
                if($final && (int)($final['round']??-1)===3 && ($final['phase']??'')==='preparation' && (int)($final['deadline']??0)===0) {
                    $step=ckmqp_show_reduce($final,array('role'=>'participant','slot'=>1,'team_id'=>1),array('command'=>'story_ready','round'=>3,'attempt'=>0),210);
                    if(!empty($step['ok'])){$story=$step['session'];$finalOk=($story['phase']??'')==='story_tell' && (int)($story['deadline']??0)===0 && count(ckmqp_show_story_dossiers())===3;}
                }
            }
            $checks[] = array('name'=>'Финал «Проверь историю»','ok'=>$finalOk,'detail'=>$finalOk?'подготовка без таймера → «Готов рассказать» → рассказ без таймера → 2×2 вопроса → ответы по 30 сек. → тайное голосование':'контракт финала не найден');
            $fullFlow = ckmqp_games_hub_smoke_persuade_full_flow();
            $checks[] = array('name'=>'Сквозной цикл 4 раундов','ok'=>!empty($fullFlow['ok']),'detail'=>(string)($fullFlow['detail']??'нет результата'));
        } else {
            $expectedSeconds = (int)$case['settings']['round_time_seconds'];
            $checks[] = array('name'=>'Время раунда','ok'=>(int)($format['secondsPerTurn'] ?? 0) === $expectedSeconds,'detail'=>(int)($format['secondsPerTurn'] ?? 0) . ' сек.');
            $checks[] = array('name'=>'Переговорный режим','ok'=>(string)($format['negotiationMode'] ?? '') === $case['mode'],'detail'=>(string)($format['negotiationMode'] ?? ''));
            if ($runtime === 'sales_v1') {
                $checks[] = array('name'=>'Эффективный продажник: возражения/следующий шаг','ok'=>!empty($format['objectionsEnabled']) && !empty($format['nextStepRequired']),'detail'=>'objections=' . (!empty($format['objectionsEnabled'])?'1':'0') . ', next=' . (!empty($format['nextStepRequired'])?'1':'0'));
            } elseif ($runtime === 'business_negotiation_v1') {
                $checks[] = array('name'=>'Деловые: закрытые вводные/соглашение','ok'=>!empty($format['privateBriefsEnabled']) && !empty($format['agreementCaptureEnabled']),'detail'=>'briefs=' . (!empty($format['privateBriefsEnabled'])?'1':'0') . ', agreement=' . (!empty($format['agreementCaptureEnabled'])?'1':'0'));
            } elseif ($runtime === 'express_round_v1') {
                $checks[] = array('name'=>'Экспресс: подготовка/смена ролей','ok'=>(int)($format['prepTimeSeconds'] ?? -1) === 15 && !empty($format['roleSwapEnabled']) && !empty($format['rapidFeedbackEnabled']),'detail'=>'prep=' . (int)($format['prepTimeSeconds'] ?? -1) . ', swap=' . (!empty($format['roleSwapEnabled'])?'1':'0'));
            }
        }

        $allOk = true;
        foreach ($checks as $check) if (empty($check['ok'])) { $allOk=false; break; }
        return array('runtime'=>$runtime,'label'=>$case['label'],'ok'=>$allOk,'checks'=>$checks,'error'=>'');
    } catch (Throwable $e) {
        return array('runtime'=>$runtime,'label'=>$case['label'],'ok'=>false,'checks'=>$checks,'error'=>$e->getMessage());
    } finally {
        if ($gameId > 0) ckmqp_games_hub_smoke_cleanup($gameId);
    }
}

function ckmqp_games_hub_smoke_run_all(): array {
    $uid = get_current_user_id();
    $rows = array();
    foreach (ckmqp_games_hub_smoke_cases() as $runtime=>$case) {
        $rows[] = ckmqp_games_hub_smoke_run_case($runtime,$case,$uid);
    }
    return $rows;
}

function ckmqp_games_hub_smoke_page(): void {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $rows = array();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckmqp_run_games_hub_smoke'])) {
        check_admin_referer('ckmqp_games_hub_smoke');
        $rows = ckmqp_games_hub_smoke_run_all();
    }
    $formatCount = count(ckmqp_games_hub_smoke_cases());
    echo '<div class="wrap"><h1>Диагностика '.(int)$formatCount.' форматов</h1>';
    echo '<p>Тест создаёт временные комнаты <code>test_mode=1</code>, проверяет каталог → core runtime → snapshot → ключевую механику и затем удаляет тестовые данные.</p>';
    echo '<form method="post">'; wp_nonce_field('ckmqp_games_hub_smoke');
    echo '<p><button class="button button-primary" name="ckmqp_run_games_hub_smoke" value="1">Запустить smoke-тест '.(int)$formatCount.' форматов</button></p></form>';
    if ($rows) {
        $passed = 0;
        echo '<table class="widefat striped"><thead><tr><th>Формат</th><th>Итог</th><th>Проверки</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (!empty($row['ok'])) $passed++;
            $status = !empty($row['ok']) ? '<strong style="color:#008a20">PASS</strong>' : '<strong style="color:#b32d2e">FAIL</strong>';
            $details = '';
            foreach ((array)$row['checks'] as $check) {
                $mark = !empty($check['ok']) ? '✅' : '❌';
                $details .= '<div>'.$mark.' <strong>'.esc_html((string)$check['name']).'</strong>: '.esc_html((string)$check['detail']).'</div>';
            }
            if (!empty($row['error'])) $details .= '<div style="color:#b32d2e"><strong>Ошибка:</strong> '.esc_html((string)$row['error']).'</div>';
            echo '<tr><td><strong>'.esc_html((string)$row['label']).'</strong><br><code>'.esc_html((string)$row['runtime']).'</code></td><td>'.$status.'</td><td>'.$details.'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<h2>Результат: '.$passed.' / '.count($rows).' PASS</h2>';
        if ($passed === count($rows)) echo '<div class="notice notice-success inline"><p><strong>Все '.count($rows).' форматов прошли сквозную проверку.</strong></p></div>';
        else echo '<div class="notice notice-error inline"><p><strong>Есть ошибки. Не запускайте изменение в продакшене, пока FAIL не устранены.</strong></p></div>';
    }
    echo '</div>';
}

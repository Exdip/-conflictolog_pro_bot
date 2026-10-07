<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_chgk_settings(int $discussionSeconds = 60, int $finalAnswerSeconds = 20, int $earlyAnswerSeconds = 5): array {
    $discussionSeconds = max(60, min(300, $discussionSeconds));
    $finalAnswerSeconds = 20;
    $earlyAnswerSeconds = $earlyAnswerSeconds < 5 ? 5 : max(5, min(60, $earlyAnswerSeconds));
    return [
        'discussionSeconds'=>$discussionSeconds,
        'finalAnswerSeconds'=>20,
        'earlyAnswerSeconds'=>$earlyAnswerSeconds,
        'answerType'=>'text',
        'judgeMode'=>'ai',
        'singleFinalAnswer'=>true,
        'participationMode'=>'team_device',
        'finalizationMode'=>'any_member',
        'requireAllTeamsReady'=>true,
        'closeWhenAllAnswered'=>true,
        'questionReviewEnabled'=>false,
        'requireQuestionReview'=>false,
        'singleTeamOnly'=>true,
        'winScore'=>6,
        'pointsPerCorrectAnswer'=>1,
        'comparativeAnalysisEnabled'=>false,
        'methodologyAnalysisEnabled'=>false,
        'victoryMode'=>'first_to_six',
        'standaloneLocalJudge'=>'reference_match',
    ];
}


function ckm_quiz_pro_chgk_timer_values(array $quiz): array {
    $settings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
    $discussion=max(60,min(300,(int)($settings['discussionSeconds']??($quiz['seconds_per_question']??60))));
    $final=20;
    $earlyRaw=(int)($settings['earlyAnswerSeconds']??5);
    $early=$earlyRaw<5?5:max(5,min(60,$earlyRaw));
    return ['discussion'=>$discussion,'final'=>$final,'early'=>$early];
}

function ckm_quiz_pro_chgk_timer_control(string $presetName,string $customName,int $current,array $presets,int $min,int $max,string $class=''): string {
    $current=max($min,min($max,$current));
    $presets=array_values(array_unique(array_map('intval',$presets)));
    $custom=!in_array($current,$presets,true);
    $id='ckm-'.sanitize_html_class($customName);
    $css=$class!==''?' class="'.esc_attr($class).'"':'';
    $js="document.getElementById('".$id."').style.display=this.value==='custom'?'inline-block':'none'";
    $html='<select'.$css.' name="'.esc_attr($presetName).'" onchange="'.esc_attr($js).'">';
    foreach($presets as $value){
        if($value<$min || $value>$max) continue;
        $html.='<option value="'.$value.'" '.selected(!$custom?$current:0,$value,false).'>'.$value.' сек.</option>';
    }
    $html.='<option value="custom" '.selected($custom,true,false).'>Другое…</option></select> ';
    $style=$custom?'':'display:none;';
    $html.='<input'.$css.' id="'.esc_attr($id).'" type="number" name="'.esc_attr($customName).'" min="'.$min.'" max="'.$max.'" value="'.$current.'" style="'.$style.'max-width:130px" aria-label="Своё значение времени в секундах">';
    return $html;
}

function ckm_quiz_pro_format_title(string $formatKey): string {
    $formatKey = sanitize_key($formatKey);
    if ($formatKey === 'chgk') return 'Битва знатоков';
    if ($formatKey === 'jeopardy') return 'Интеллектуальный батл';
    if ($formatKey === 'solution_price') return 'Управленческая игра "Ваш выбор"';
    if ($formatKey === 'negotiation_duel') return 'Переговорный поединок';
    return 'Классический квиз';
}

function ckm_quiz_pro_seed_demo_chgk(): void {
    // 0.3.9 does not change the DB schema, so an ordinary upgrade may not run
    // the schema migrator. Seed the shared format registry here as well.
    if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();
    global $wpdb;
    $qz=ckm_quiz_pro_table('quizzes');
    $qq=ckm_quiz_pro_table('questions');
    $rr=ckm_quiz_pro_table('rounds');
    $now=current_time('mysql');
    $quiz=$wpdb->get_row("SELECT * FROM {$qz} WHERE slug='demo-battle-experts' LIMIT 1", ARRAY_A);
    // Текущая «Битва знатоков»: одна команда против Игры, первым до 6.
    // Каждый обычный вопрос стоит ровно одно очко.
    $wpdb->query("UPDATE {$qz} SET min_teams=1,max_teams=1 WHERE format_key='chgk' AND (min_teams<>1 OR max_teams<>1)");
    if ($quiz) { $quiz['min_teams']=1; $quiz['max_teams']=1; }
    if ($quiz) {
        if ((string)($quiz['title'] ?? '') !== 'Битва знатоков') {
            $wpdb->update($qz,['title'=>'Битва знатоков','updated_at'=>$now],['id'=>(int)$quiz['id']]);
            $quiz['title']='Битва знатоков';
        }
        $formatSettings=json_decode((string)($quiz['format_settings_json']??''),true);
        if(!is_array($formatSettings)) $formatSettings=array();
        $settings=ckm_quiz_pro_chgk_settings(60,(int)($formatSettings['finalAnswerSeconds']??20),(int)($formatSettings['earlyAnswerSeconds']??5));
        $wpdb->update($qz,[
            'short_description'=>'Одна команда Знатоков против Игры. Обсуждение, один окончательный ответ, победа — первым до 6 очков.',
            'instructions'=>'Откройте одну командную комнату и комнату ведущего. Каждый вопрос стоит 1 очко: правильный ответ — Знатокам, неправильный или пропущенный — Игре.',
            'min_teams'=>1,'max_teams'=>1,'seconds_per_question'=>60,'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE),'updated_at'=>$now
        ],['id'=>(int)$quiz['id']]);
        $wpdb->query($wpdb->prepare("UPDATE {$qq} SET points=1 WHERE quiz_id=%d AND points<>1",(int)$quiz['id']));
        $quiz=array_merge($quiz,['min_teams'=>1,'max_teams'=>1,'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE)]);
    }
    if ($quiz && get_option('ckm_quiz_pro_demo_chgk_seed', '') === '1') {
        $existingCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",(int)$quiz['id'],max(1,(int)$quiz['current_revision'])));
        if($existingCount>=11) return;
    }
    if (!$quiz) {
        $settings=ckm_quiz_pro_chgk_settings(60);
        $wpdb->insert($qz,[
            'title'=>'Битва знатоков','slug'=>'demo-battle-experts','format_key'=>'chgk',
            'short_description'=>'Одна команда Знатоков против Игры. Обсуждение, один окончательный ответ, победа — первым до 6 очков.','instructions'=>'Откройте одну командную комнату и комнату ведущего. Каждый вопрос стоит 1 очко: правильный ответ — Знатокам, неправильный или пропущенный — Игре.',
            'status'=>'published','current_revision'=>1,'min_teams'=>1,'max_teams'=>1,'host_mode'=>'ai','judge_mode'=>'ai','seconds_per_question'=>60,
            'scoring_policy_json'=>'{}','settings_json'=>'{}','format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE),
            'created_by_user_id'=>get_current_user_id(),'updated_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'published_at'=>$now
        ]);
        $quizId=(int)$wpdb->insert_id;
        $revision=1;
    } else {
        $quizId=(int)$quiz['id'];
        $revision=max(1,(int)$quiz['current_revision']);
    }
    if ($quizId<=0) return;
    $roundId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-1' LIMIT 1",$quizId,$revision));
    if ($roundId<=0) {
        $wpdb->insert($rr,['quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,'title'=>'Основной раунд','round_type'=>'questions','rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $roundId=(int)$wpdb->insert_id;
    }
    $items=[
        ['Какой предмет, по легенде, Архимед попросил дать ему вместе с точкой опоры, чтобы он смог перевернуть Землю?','Рычаг',['рычаг'],'Фраза Архимеда связана с законом рычага.'],
        ['Какое слово объединяет клавишу компьютера, архитектурный элемент и место входа в помещение?','Вход',['вход'],'В компьютерной терминологии Enter — ввод/вход; в архитектуре и помещении — вход.'],
        ['Что общего у секундомера, песочных часов и таймера вопроса в интеллектуальной игре?','Они измеряют время',['измеряют время','отсчитывают время'],'Все три устройства или механизма служат для измерения/отсчёта времени.'],
        ['Как называется принцип, согласно которому из двух объяснений обычно предпочитают более простое, если они одинаково хорошо объясняют факты?','Бритва Оккама',['бритва оккама','принцип оккама'],'Это методологический принцип экономии допущений.'],
        ['Какое число является единственным чётным простым числом?','2',['два'],'Все остальные чётные числа делятся на 2 и потому составные.'],
        ['Как называется ситуация, когда из-за ограниченности информации человек выбирает первое удовлетворительное решение вместо поиска идеального?','Ограниченная рациональность',['ограниченная рациональность','bounded rationality'],'Понятие связано с Гербертом Саймоном: люди принимают решения в условиях ограничений информации, времени и вычислительных возможностей.'],
        ['Как называется логическая ошибка, когда свойства части без достаточных оснований переносят на целое?','Ошибка композиции',['ошибка композиции','композиционная ошибка'],'То, что верно для отдельных элементов, не обязательно верно для всей системы.'],
        ['Как называется эффект, при котором уже понесённые и невозвратные затраты заставляют продолжать невыгодный проект?','Ошибка невозвратных затрат',['эффект невозвратных затрат','ошибка невозвратных затрат','sunk cost'],'Рациональное решение должно учитывать будущие выгоды и издержки, а не уже потерянные ресурсы.'],
        ['Как называется принцип проверки идеи через попытку найти факт, который мог бы её опровергнуть?','Фальсификация',['фальсифицируемость','принцип фальсификации'],'В критическом рационализме сильная гипотеза должна допускать возможность опровержения опытом.'],
        ['Как называется систематическая склонность искать и замечать прежде всего сведения, подтверждающие уже имеющееся мнение?','Предвзятость подтверждения',['ошибка подтверждения','предвзятость подтверждения','confirmation bias'],'Человек легче замечает подтверждающую информацию и хуже учитывает опровергающие данные.'],
        ['Как называется ситуация в теории игр, когда ни одному участнику невыгодно в одиночку менять свою стратегию при неизменных стратегиях остальных?','Равновесие Нэша',['равновесие нэша','nash equilibrium'],'В равновесии Нэша стратегия каждого является лучшим ответом на стратегии остальных участников.'],
    ];
    foreach($items as $i=>$it){
        $key='q-'.($i+1);
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND question_key=%s LIMIT 1",$quizId,$revision,$key));
        if($exists>0) continue;
        $answers=[]; $answerSeen=[];
        foreach(array_merge([$it[1]],$it[2]) as $candidate){
            $display=trim((string)$candidate);
            $key=function_exists('mb_strtolower') ? mb_strtolower($display,'UTF-8') : strtolower($display);
            if($display==='' || isset($answerSeen[$key])) continue;
            $answerSeen[$key]=true; $answers[]=$display;
        }
        $wpdb->insert($qq,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>$key,'position'=>$i+1,'round_no'=>1,'round_title'=>'Основной раунд','round_id'=>$roundId,'question_stage'=>'main',
            'question_type'=>'text','question_text'=>$it[0],'options_json'=>'[]','correct_answers_json'=>wp_json_encode($answers,JSON_UNESCAPED_UNICODE),'numeric_tolerance'=>0,
            'points'=>1,'time_limit_seconds'=>60,'scoring_rule_json'=>wp_json_encode(['acceptedVariants'=>$it[2]],JSON_UNESCAPED_UNICODE),'explanation'=>$it[3],'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
    }
    update_option('ckm_quiz_pro_demo_chgk_seed','1',false);
}
function ckm_quiz_pro_normalize_chgk_one_team(): void {
    global $wpdb;
    $qz=ckm_quiz_pro_table('quizzes');
    $qq=ckm_quiz_pro_table('questions');
    if (!$qz || !$qq) return;
    $wpdb->query("UPDATE {$qz} SET min_teams=1,max_teams=1 WHERE format_key='chgk' AND (min_teams<>1 OR max_teams<>1)");
    $wpdb->query("UPDATE {$qq} q INNER JOIN {$qz} z ON z.id=q.quiz_id SET q.points=1 WHERE z.format_key='chgk' AND q.points<>1");
}
add_action('init','ckm_quiz_pro_normalize_chgk_one_team',6);
add_action('admin_init','ckm_quiz_pro_seed_demo_chgk',7);


/**
 * Local DEV judge for CHGK.
 *
 * Shared Core deliberately routes CHGK through an external arbitration layer,
 * so its generic auto-score function skips these answers. Standalone has no
 * Облачный сервис connection yet; therefore this adapter performs only a strict
 * normalized reference/accepted-variant match with typo tolerance and writes the result through
 * the SAME core score ledger. Semantic judgement is still not attempted here; obvious typos and spelling variants are tolerated.
 */

function ckm_quiz_pro_chgk_local_judge_answer(int $gameId, int $answerId, bool $replace = false): array {
    if ($gameId <= 0 || $answerId <= 0) return ['ok'=>false,'code'=>'target_missing','error'=>'Ответ не найден.'];
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game = ckm_quiz_get_game($gameId, true);
        if (!$game || ckm_quiz_runtime_format_key($game) !== 'chgk') {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'code'=>'chgk_required','error'=>'Это действие доступно только для «Битвы знатоков».'];
        }
        $answer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . ckm_quiz_answers_table() . " WHERE id=%d AND game_id=%d LIMIT 1 FOR UPDATE",
            $answerId, $gameId
        ), ARRAY_A);
        if (!$answer) {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'code'=>'answer_not_found','error'=>'Ответ не найден.'];
        }
        if (!$replace && (string)($answer['verdict'] ?? 'pending') !== 'pending') {
            $wpdb->query('COMMIT');
            return ['ok'=>true,'skipped'=>true,'reason'=>'already_scored','answerId'=>$answerId];
        }
        $question = ckm_quiz_get_question((int)$answer['question_id']);
        if (!$question) throw new RuntimeException('question_missing');
        $evaluation = ckm_quiz_evaluate_answer($question, $answer, 'ai');
        if (empty($evaluation['judged'])) {
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'code'=>'ai_judge_needs_human','error'=>'ИИ-оценка недоступна: требуется решение арбитра.'];
        }
        $key = ($replace ? 'standalone-chgk-ai-repeat-' : 'standalone-chgk-ai-') . $answerId . '-attempt-' . (int)($answer['attempt_no'] ?? 1) . ($replace ? '-' . time() : '');
        $score = ckm_quiz_apply_score_locked(
            $game, $question, $answer, (int)$evaluation['points'], (string)$evaluation['verdict'], (string)$evaluation['reason'],
            'ai_host', 0, $key
        );
        if (empty($score['ok'])) throw new RuntimeException((string)($score['error'] ?? 'ai_score_failed'));
        $eventId = ckm_quiz_append_event(
            $gameId,
            'chgk_ai_arbitration_completed',
            'ai_host',
            0,
            (int)$answer['team_id'],
            (int)$answer['question_id'],
            'answer',
            $answerId,
            ['answerId'=>$answerId,'decision'=>(string)$evaluation['verdict'],'points'=>(int)$evaluation['points'],'comment'=>(string)$evaluation['reason']],
            'chgk-ai-arbitration-' . $answerId . '-' . substr(hash('sha256', $key), 0, 12)
        );
        if ($eventId <= 0) throw new RuntimeException('ai_arbitration_event_failed');
        $wpdb->query('COMMIT');
        return ['ok'=>true,'answerId'=>$answerId,'evaluation'=>$evaluation,'score'=>$score,'eventId'=>$eventId];
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        error_log('Игровая платформа standalone CHGK one-answer judge failed: ' . $e->getMessage());
        return ['ok'=>false,'code'=>'local_chgk_answer_judge_failed','error'=>'Не удалось оценить ответ.'];
    }
}

function ckm_quiz_pro_chgk_local_judge_closed_question(int $gameId): array {
    if ($gameId<=0) return ['ok'=>false,'code'=>'game_missing'];
    global $wpdb;
    $wpdb->query('START TRANSACTION');
    try {
        $game=ckm_quiz_get_game($gameId,true);
        if(!$game || ckm_quiz_runtime_format_key($game)!=='chgk' || (string)($game['quiz_phase']??'')!=='question_closed') {
            $wpdb->query('ROLLBACK');
            return ['ok'=>true,'skipped'=>true,'reason'=>'not_closed_chgk'];
        }
        $question=ckm_quiz_get_question((int)($game['current_question_id']??0));
        if(!$question){
            $wpdb->query('ROLLBACK');
            return ['ok'=>false,'code'=>'question_missing'];
        }
        $answers=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d AND verdict='pending' ORDER BY id ASC FOR UPDATE",
            $gameId,(int)$question['id']
        ),ARRAY_A) ?: [];
        $scored=0;
        foreach($answers as $answer){
            $evaluation=ckm_quiz_evaluate_answer($question,$answer,'ai');
            if(empty($evaluation['judged'])) continue;
            $score=ckm_quiz_apply_score_locked(
                $game,$question,$answer,(int)$evaluation['points'],(string)$evaluation['verdict'],
                (string)$evaluation['reason'],'ai_host',0,
                'standalone-chgk-answer-'.(int)$answer['id'].'-attempt-'.(int)($answer['attempt_no']??1)
            );
            if(empty($score['ok'])) throw new RuntimeException((string)($score['error']??'local_chgk_score_failed'));
            $scored++;
        }
        $wpdb->query('COMMIT');
        return ['ok'=>true,'scored'=>$scored,'mode'=>'reference_match'];
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        error_log('Игровая платформа standalone CHGK local judge failed: '.$e->getMessage());
        return ['ok'=>false,'code'=>'local_chgk_judge_failed','error'=>'Не удалось локально оценить ответы.'];
    }
}

function ckm_quiz_pro_chgk_event_key_exists(int $gameId,string $eventKey): bool {
    if($gameId<=0 || $eventKey==='') return false;
    global $wpdb;
    return (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".ckm_quiz_events_table()." WHERE game_id=%d AND event_key=%s",
        $gameId,$eventKey
    ))>0;
}

function ckm_quiz_pro_chgk_ai_phase_tick(array $game): array {
    $gameId=(int)($game['id']??0);
    if($gameId>0){$fresh=ckm_quiz_get_game($gameId);if($fresh)$game=$fresh;}
    if((string)($game['host_mode_snapshot']??'')!=='ai') return ['ok'=>true,'skipped'=>true,'reason'=>'host_mode_human'];
    if((string)($game['quiz_phase']??'')!=='question_open') return ['ok'=>true,'skipped'=>true,'reason'=>'question_not_open'];
    $qid=(int)($game['current_question_id']??0);
    if($gameId<=0 || $qid<=0) return ['ok'=>true,'skipped'=>true,'reason'=>'question_missing'];
    $question=ckm_quiz_get_question($qid);
    if(!$question) return ['ok'=>true,'skipped'=>true,'reason'=>'question_missing'];
    $flow=function_exists('ckm_quiz_chgk_question_flow_state') ? ckm_quiz_chgk_question_flow_state($game) : [];
    $phase=(string)($flow['phase']??'discussion');
    $deadline=ckm_quiz_mysql_timestamp((string)($game['question_deadline_at']??''));
    $remaining=max(0,$deadline-ckm_quiz_now_timestamp());
    $settings=function_exists('ckm_quiz_runtime_format_settings') ? ckm_quiz_runtime_format_settings($game) : [];

    if($phase==='question_narration'){
        return ['ok'=>true,'skipped'=>true,'reason'=>'question_narration_running'];
    }

    if($phase==='early_answer_offer'){
        $earlyAnswerSeconds=max(5,min(60,(int)($settings['earlyAnswerSeconds']??5)));
        $voiceFirst=ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_question_narration_completed');
        if($voiceFirst){
            return ['ok'=>true,'skipped'=>true,'reason'=>'early_answer_offer_running','remainingSeconds'=>$remaining,'earlyAnswerSeconds'=>$earlyAnswerSeconds];
        }
        $key='ai-chgk-early-answer-offer-'.$qid;
        if(!ckm_quiz_pro_chgk_event_key_exists($gameId,$key)){
            $published=ckm_quiz_publish_ai_host_event($gameId,'early_answer_offer',$question,['earlyAnswerSeconds'=>$earlyAnswerSeconds],$key);
            return ['ok'=>!empty($published['ok']),'action'=>'early_answer_offer','earlyAnswerSeconds'=>$earlyAnswerSeconds,'aiHost'=>$published];
        }
        return ['ok'=>true,'skipped'=>true,'reason'=>'early_answer_offer_running','remainingSeconds'=>$remaining,'earlyAnswerSeconds'=>$earlyAnswerSeconds];
    }

    if($phase==='discussion'){
        $discussion=max(60,min(300,(int)($settings['discussionSeconds']??($game['seconds_per_question_snapshot']??60))));
        $startKey='ai-chgk-discussion-started-'.$qid;
        $voiceFirst=ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_question_narration_completed');
        if(!$voiceFirst && !ckm_quiz_pro_chgk_event_key_exists($gameId,$startKey)){
            $published=ckm_quiz_publish_ai_host_event($gameId,'discussion_started',$question,['discussionSeconds'=>$discussion],$startKey);
            return ['ok'=>!empty($published['ok']),'action'=>'discussion_started','discussionSeconds'=>$discussion,'aiHost'=>$published];
        }
        $thresholds=$discussion>=180 ? [10,30,60] : ($discussion>=120 ? [10,30] : [10]);
        $target=0;
        foreach($thresholds as $threshold){
            if($remaining>0 && $remaining<=$threshold){ $target=$threshold; break; }
        }
        if($target>0){
            $key='ai-chgk-discussion-warning-'.$target.'-'.$qid;
            if(!ckm_quiz_pro_chgk_event_key_exists($gameId,$key)){
                $published=ckm_quiz_publish_ai_host_event($gameId,'discussion_warning',$question,['remainingSeconds'=>$target],$key);
                return ['ok'=>!empty($published['ok']),'action'=>'discussion_warning','remainingSeconds'=>$target,'aiHost'=>$published];
            }
        }
        return ['ok'=>true,'skipped'=>true,'reason'=>'discussion_running','remainingSeconds'=>$remaining];
    }

    if($phase==='final_answer_open'){
        $final=20;
        $openKey='ai-chgk-final-answer-opened-'.$qid;
        if(!ckm_quiz_pro_chgk_event_key_exists($gameId,$openKey)){
            $published=ckm_quiz_publish_ai_host_event($gameId,'final_answer_opened',$question,['finalAnswerSeconds'=>$final],$openKey);
            return ['ok'=>!empty($published['ok']),'action'=>'final_answer_opened','finalAnswerSeconds'=>$final,'aiHost'=>$published];
        }
        if($final>=20 && $remaining>0 && $remaining<=10){
            $warnKey='ai-chgk-final-answer-warning-10-'.$qid;
            if(!ckm_quiz_pro_chgk_event_key_exists($gameId,$warnKey)){
                $published=ckm_quiz_publish_ai_host_event($gameId,'final_answer_warning',$question,['remainingSeconds'=>10],$warnKey);
                return ['ok'=>!empty($published['ok']),'action'=>'final_answer_warning','remainingSeconds'=>10,'aiHost'=>$published];
            }
        }
        return ['ok'=>true,'skipped'=>true,'reason'=>'final_answer_running','remainingSeconds'=>$remaining];
    }
    return ['ok'=>true,'skipped'=>true,'reason'=>'phase_'.$phase];
}

/**
 * Standalone CHGK uses the shared CHGK runtime but has no Cloud AI yet.
 * Answers are therefore judged locally by normalized reference match. This
 * adapter only advances the already-scored shared game state; it does not fork
 * the core engine.
 */
function ckm_quiz_pro_chgk_local_autopilot(int $gameId): array {
    if ($gameId<=0) return ['ok'=>true,'skipped'=>true,'reason'=>'game_missing'];
    $game=ckm_quiz_get_game($gameId);
    if(!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='chgk') return ['ok'=>true,'skipped'=>true,'reason'=>'format_not_chgk'];
    if((string)($game['host_mode_snapshot']??'')!=='ai') return ['ok'=>true,'skipped'=>true,'reason'=>'host_mode_human'];
    if((string)($game['status']??'')==='finished') return ['ok'=>true,'skipped'=>true,'reason'=>'game_finished'];
    $phase=(string)($game['quiz_phase']??'waiting');
    if($phase==='waiting' && function_exists('ckm_quiz_chgk_ai_host_autostart')) {
        return ckm_quiz_chgk_ai_host_autostart($gameId);
    }
    if($phase==='question_open') return ckm_quiz_pro_chgk_ai_phase_tick($game);
    if($phase!=='question_closed') return ['ok'=>true,'skipped'=>true,'reason'=>'phase_'.$phase];
    $judged=ckm_quiz_pro_chgk_local_judge_closed_question($gameId);
    if(empty($judged['ok'])) return $judged;
    $game=ckm_quiz_get_game($gameId) ?: $game;
    if((string)($game['host_mode_snapshot']??'')!=='ai') return ['ok'=>true,'skipped'=>true,'reason'=>'manual_takeover_after_judging'];
    $flow=function_exists('ckm_quiz_chgk_question_flow_state') ? ckm_quiz_chgk_question_flow_state($game) : [];
    if((int)($flow['pendingArbitration']??0)>0) return ['ok'=>true,'action'=>'arbitration_pending','flow'=>$flow];
    $qid=(int)($game['current_question_id']??0);
    $question=$qid>0 ? ckm_quiz_get_question($qid) : null;
    $voiceGate=function_exists('ckm_quiz_pro_ai_voice_provider') && ckm_quiz_pro_ai_voice_provider()==='gateway'
        && function_exists('ckm_quiz_pro_voice_ready') && ckm_quiz_pro_voice_ready();

    // First let Sergey finish the arbitration comment. The normal close path
    // already publishes question_closed; this fallback covers legacy/stale rooms.
    if(empty($flow['answerRevealed'])){
        $hasArbitrationMessage=ckm_quiz_pro_chgk_event_key_exists($gameId,'ai-question-closed-'.$qid)
            || ckm_quiz_pro_chgk_event_key_exists($gameId,'ai-chgk-arbitration-final-'.$qid);
        if(!$hasArbitrationMessage && $question && function_exists('ckm_quiz_publish_ai_host_event')){
            $published=ckm_quiz_publish_ai_host_event($gameId,'question_closed',$question,['arbitrationFinal'=>true],'ai-question-closed-'.$qid);
            return ['ok'=>!empty($published['ok']),'action'=>'arbitration_comment_published','aiHost'=>$published];
        }
        if($voiceGate && !ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_ai_arbitration_voice_completed')){
            return ['ok'=>true,'action'=>'arbitration_voice_hold','questionId'=>$qid];
        }
        if(function_exists('ckm_quiz_chgk_reveal_answer')) {
            $revealed=ckm_quiz_chgk_reveal_answer($gameId,['role'=>'ai_host','game_id'=>$gameId,'team_id'=>0,'user_id'=>0]);
            return ['ok'=>!empty($revealed['ok']),'action'=>'answer_revealed','reveal'=>$revealed];
        }
    }

    // After the correct answer is shown, do not open the next question until
    // Sergey has finished saying the answer, explanation and score.
    if(!empty($flow['answerRevealed']) && $voiceGate
        && !ckm_quiz_chgk_question_event_exists($gameId,$qid,'chgk_answer_reveal_voice_completed')){
        return ['ok'=>true,'action'=>'answer_reveal_voice_hold','questionId'=>$qid];
    }
    $opened=ckm_quiz_open_next_question($gameId,'ai_host',0);
    if(!empty($opened['ok'])) return ['ok'=>true,'action'=>'next_question_started','opened'=>$opened];
    $code=(string)($opened['code']??'');
    if($code==='questions_complete') {
        $finished=ckm_quiz_finish_game($gameId,'ai_host',0,true);
        return ['ok'=>!empty($finished['ok']),'action'=>'game_finished','finish'=>$finished];
    }
    if(in_array($code,['question_already_open','game_finished'],true)) return ['ok'=>true,'skipped'=>true,'reason'=>'already_advanced'];
    return $opened;
}


function ckm_quiz_pro_migrate_chgk_early_answer_default_to_five(): void {
    if (get_option('ckm_quiz_pro_chgk_early_5_migrated','') === '1') return;
    global $wpdb;
    $quizTable=ckm_quiz_pro_table('quizzes');
    $gameTable=ckm_quiz_pro_table('games');
    if (!$quizTable || !$gameTable) return;

    $quizzes=$wpdb->get_results("SELECT id,format_settings_json FROM {$quizTable} WHERE format_key='chgk'",ARRAY_A);
    foreach((array)$quizzes as $row){
        $settings=json_decode((string)($row['format_settings_json']??''),true);
        if(!is_array($settings)) $settings=[];
        $old=array_key_exists('earlyAnswerSeconds',$settings)?(int)$settings['earlyAnswerSeconds']:10;
        if($old!==10) continue;
        $settings['earlyAnswerSeconds']=5;
        $wpdb->update($quizTable,['format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE)],['id'=>(int)$row['id']]);
    }

    $games=$wpdb->get_results("SELECT id,format_settings_snapshot_json FROM {$gameTable} WHERE format_key_snapshot='chgk' AND status<>'finished'",ARRAY_A);
    foreach((array)$games as $row){
        $settings=json_decode((string)($row['format_settings_snapshot_json']??''),true);
        if(!is_array($settings)) $settings=[];
        $old=array_key_exists('earlyAnswerSeconds',$settings)?(int)$settings['earlyAnswerSeconds']:10;
        if($old!==10) continue;
        $settings['earlyAnswerSeconds']=5;
        $wpdb->update($gameTable,['format_settings_snapshot_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE)],['id'=>(int)$row['id']]);
    }
    update_option('ckm_quiz_pro_chgk_early_5_migrated','1',false);
}
add_action('init','ckm_quiz_pro_migrate_chgk_early_answer_default_to_five',8);

<?php
if (!defined('ABSPATH')) exit;

/**
 * «Управленческая игра "Ваш выбор"» — минимальная короткая версия.
 * Использует общий Quiz Engine: один сценарий, 5 коротких текстовых этапов.
 */
function ckm_quiz_pro_solution_price_settings(int $seconds = 60): array {
    $seconds = max(30, min(600, $seconds));
    return [
        'answerType'=>'text',
        'judgeMode'=>'ai',
        'victoryMode'=>'judge_rating',
        'judgeCriteria'=>['problem_analysis','solution_quality','risks','argumentation'],
        'requireSolutionCost'=>false,
        'standaloneMiniVersion'=>true,
        'secondsPerStage'=>$seconds,
    ];
}

function ckm_quiz_pro_seed_demo_solution_price(): void {
    if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();
    global $wpdb;
    $qz = ckm_quiz_pro_table('quizzes');
    $qq = ckm_quiz_pro_table('questions');
    $rr = ckm_quiz_pro_table('rounds');
    $now = current_time('mysql');

    $quiz = $wpdb->get_row("SELECT * FROM {$qz} WHERE slug='demo-solution-price' LIMIT 1", ARRAY_A);
    $settings = ckm_quiz_pro_solution_price_settings(60);
    if ($quiz) {
        // Keep existing demo rows aligned with the current mini-game contract.
        // Older builds could leave min_teams=2 even though the demo is intended
        // to run immediately with one team.
        $wpdb->update($qz,[
            'title'=>'Управленческая игра "Ваш выбор"',
            'format_key'=>'solution_price',
            'min_teams'=>1,
            'max_teams'=>10,
            'host_mode'=>'ai',
            'judge_mode'=>'ai',
            'seconds_per_question'=>60,
            'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE),
            'updated_at'=>$now,
        ],['id'=>(int)$quiz['id']]);
        $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$qz} WHERE id=%d LIMIT 1", (int)$quiz['id']), ARRAY_A) ?: $quiz;
        $cnt = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",
            (int)$quiz['id'], max(1, (int)$quiz['current_revision'])
        ));
        if ($cnt >= 5) return;
    }

    if (!$quiz) {
        $wpdb->insert($qz,[
            'title'=>'Управленческая игра "Ваш выбор"',
            'slug'=>'demo-solution-price',
            'format_key'=>'solution_price',
            'short_description'=>'Короткая управленческая мини-игра из 5 этапов: анализ, решение, риски, корректировка и финальное обоснование.',
            'instructions'=>'Команда получает одну проблемную ситуацию и последовательно формирует решение. Ответы текстовые.',
            'cover_url'=>'',
            'status'=>'published',
            'current_revision'=>1,
            'min_teams'=>1,
            'max_teams'=>10,
            'host_mode'=>'ai',
            'judge_mode'=>'ai',
            'seconds_per_question'=>60,
            'scoring_policy_json'=>'{}',
            'settings_json'=>wp_json_encode(['demo'=>true,'mini'=>true],JSON_UNESCAPED_UNICODE),
            'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE),
            'created_by_user_id'=>get_current_user_id(),
            'updated_by_user_id'=>get_current_user_id(),
            'created_at'=>$now,
            'updated_at'=>$now,
            'published_at'=>$now,
        ]);
        $quizId=(int)$wpdb->insert_id;
        $revision=1;
    } else {
        $quizId=(int)$quiz['id'];
        $revision=max(1,(int)$quiz['current_revision']);
    }
    if ($quizId<=0) return;

    $roundId=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-1' LIMIT 1",
        $quizId,$revision
    ));
    if ($roundId<=0) {
        $wpdb->insert($rr,[
            'quiz_id'=>$quizId,
            'quiz_revision'=>$revision,
            'round_key'=>'round-1',
            'position'=>1,
            'title'=>'Кейс',
            'round_type'=>'case',
            'rules_json'=>'{}',
            'settings_json'=>wp_json_encode(['mini'=>true],JSON_UNESCAPED_UNICODE),
            'status'=>'active',
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
        $roundId=(int)$wpdb->insert_id;
    }

    $items=[
        [
            'Ситуация: компания потеряла ключевого клиента, который обеспечивал значительную часть выручки. Бюджет ограничен, а новый крупный клиент пока не найден. В чём, по вашему мнению, главная проблема, которую нужно решить в первую очередь?',
            'Сформулируйте проблему, отделяя причину от симптомов. Укажите, каких данных не хватает для уверенного решения.',
            ['problem_analysis','missing_information']
        ],
        [
            'Предложите основное решение для компании на ближайшие 3 месяца. Что конкретно вы будете делать?',
            'Решение должно быть конкретным, выполнимым и учитывать ограниченность ресурсов.',
            ['solution_quality','realism']
        ],
        [
            'Какие два главных риска создаёт ваше решение и как вы собираетесь их снизить?',
            'Оцените не только прямые, но и возможные побочные последствия.',
            ['risks','second_order_effects']
        ],
        [
            'Новое обстоятельство: крупнейший конкурент снизил цены на 15%. Нужно ли менять ваше решение? Если да — как именно?',
            'Покажите способность корректировать стратегию при появлении новой информации.',
            ['adaptability','constraints']
        ],
        [
            'Сформулируйте финальное решение команды в 3–5 предложениях и объясните, почему оно предпочтительнее основных альтернатив.',
            'Финальный ответ должен связывать проблему, решение, риски и ожидаемый результат.',
            ['argumentation','system_thinking','final_solution']
        ],
    ];

    foreach($items as $i=>$item){
        $key='stage-'.($i+1);
        $exists=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND question_key=%s LIMIT 1",
            $quizId,$revision,$key
        ));
        if ($exists>0) continue;
        $wpdb->insert($qq,[
            'quiz_id'=>$quizId,
            'quiz_revision'=>$revision,
            'question_key'=>$key,
            'position'=>$i+1,
            'round_no'=>1,
            'round_title'=>'Кейс',
            'round_id'=>$roundId,
            'question_stage'=>'main',
            'question_type'=>'text',
            'question_text'=>$item[0],
            'options_json'=>'[]',
            'correct_answers_json'=>'[]',
            'numeric_tolerance'=>0,
            'points'=>20,
            'time_limit_seconds'=>60,
            'scoring_rule_json'=>wp_json_encode([
                'judgeCriteria'=>$item[2],
                'manualOrAiReview'=>true,
            ],JSON_UNESCAPED_UNICODE),
            'explanation'=>$item[1],
            'host_script'=>'',
            'media_url'=>'',
            'media_type'=>'',
            'media_start_seconds'=>0,
            'media_end_seconds'=>0,
            'status'=>'active',
            'created_by_user_id'=>get_current_user_id(),
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
    }
    update_option('ckm_quiz_pro_demo_solution_price_seed','1',false);
}
add_action('admin_init','ckm_quiz_pro_seed_demo_solution_price',8);

function ckm_quiz_pro_solution_price_question_editor(int $i,string $text,string $criteria,int $points,string $explanation): void {
    echo '<div class="postbox ckm-qp-q"><div class="postbox-header"><h2>Этап '.($i+1).'</h2></div><div class="inside">';
    echo '<p><textarea name="questions['.$i.'][text]" rows="4" style="width:100%" placeholder="Ситуация или задание">'.esc_textarea($text).'</textarea></p>';
    echo '<p><textarea name="questions['.$i.'][criteria]" rows="2" style="width:100%" placeholder="Критерии оценки — по одному в строке">'.esc_textarea($criteria).'</textarea></p>';
    echo '<p>Баллы: <input type="number" name="questions['.$i.'][points]" value="'.(int)$points.'" min="1" max="100" style="width:80px"></p>';
    echo '<p><textarea name="questions['.$i.'][explanation]" rows="2" style="width:100%" placeholder="Методический комментарий / ориентир для оценки">'.esc_textarea($explanation).'</textarea></p>';
    echo '</div></div>';
}

function ckm_quiz_pro_org_solution_price_question_editor(int $i,string $text,string $criteria,int $points,string $explanation): void {
    echo '<div class="ckm-q-card"><div class="ckm-q-title">Этап '.($i+1).'</div>';
    echo '<label class="ckm-label">Ситуация / задание<textarea class="ckm-input" name="questions['.$i.'][text]" rows="4">'.esc_textarea($text).'</textarea></label>';
    echo '<label class="ckm-label">Критерии оценки<textarea class="ckm-input" name="questions['.$i.'][criteria]" rows="2" placeholder="По одному критерию в строке">'.esc_textarea($criteria).'</textarea></label>';
    echo '<label class="ckm-label">Баллы<input class="ckm-input" type="number" name="questions['.$i.'][points]" value="'.(int)$points.'" min="1" max="100"></label>';
    echo '<label class="ckm-label">Методический комментарий<textarea class="ckm-input" name="questions['.$i.'][explanation]" rows="2">'.esc_textarea($explanation).'</textarea></label>';
    echo '</div>';
}

/**
 * alpha.70.3.7 — AI/Local review for «Управленческая игра "Ваш выбор"».
 *
 * The standalone build did not have a text-AI adapter wired at all, therefore
 * text answers stayed `pending` and the AI host had nothing useful to explain.
 * This layer first tries AI Puffer's REST API when its developer REST key is
 * available on the same WordPress install.  If AI Puffer REST is not enabled,
 * a deterministic rubric fallback still produces a useful methodological
 * review, so the mini-game never silently skips feedback.
 */
function ckm_quiz_pro_solution_price_find_nested_value($value, array $keys) {
    if (!is_array($value)) return null;
    foreach ($value as $k=>$v) {
        $normalized = strtolower((string)$k);
        if (in_array($normalized,$keys,true) && is_string($v) && trim($v)!=='') return trim($v);
        if (is_array($v)) {
            $found = ckm_quiz_pro_solution_price_find_nested_value($v,$keys);
            if (is_string($found) && $found!=='') return $found;
        }
    }
    return null;
}

function ckm_quiz_pro_solution_price_aipuffer_rest_key(): string {
    if (defined('CKM_AIPUFFER_REST_KEY') && is_string(CKM_AIPUFFER_REST_KEY) && strlen(trim(CKM_AIPUFFER_REST_KEY))>=12) {
        return trim(CKM_AIPUFFER_REST_KEY);
    }
    $env=getenv('CKM_AIPUFFER_REST_KEY');
    if (is_string($env) && strlen(trim($env))>=12) return trim($env);
    $direct=(string)get_option('ckm_quiz_pro_aipuffer_rest_key','');
    if (strlen(trim($direct))>=12) return trim($direct);

    // AI Puffer has changed its settings layout over time.  Search only its
    // own option namespace and only for a developer REST key field; provider
    // API keys are never copied or exposed to the browser.
    global $wpdb;
    $rows=$wpdb->get_results(
        "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'aipkit_%' OR option_name LIKE 'wpaicg_%' LIMIT 250",
        ARRAY_A
    ) ?: [];
    $candidateKeys=['rest_api_key','restapikey','rest_api_token','developer_rest_api_key','api_rest_key'];
    foreach($rows as $row){
        $raw=maybe_unserialize($row['option_value']??'');
        if (is_string($raw) && in_array(strtolower((string)($row['option_name']??'')),['aipkit_rest_api_key','wpaicg_rest_api_key'],true) && strlen(trim($raw))>=12) return trim($raw);
        $found=ckm_quiz_pro_solution_price_find_nested_value($raw,$candidateKeys);
        if (is_string($found) && strlen($found)>=12) return $found;
    }
    return '';
}

function ckm_quiz_pro_solution_price_ai_settings(): array {
    $saved=get_option('ckm_quiz_pro_solution_ai_settings',[]);
    if(!is_array($saved))$saved=[];
    return [
        'provider'=>sanitize_key((string)($saved['provider']??'openai')) ?: 'openai',
        'model'=>sanitize_text_field((string)($saved['model']??'gpt-4o-mini')) ?: 'gpt-4o-mini',
    ];
}

function ckm_quiz_pro_solution_price_parse_ai_json(string $content,int $maxPoints): ?array {
    $content=trim($content);
    if($content==='')return null;
    $content=preg_replace('/^```(?:json)?\s*/i','',$content);
    $content=preg_replace('/\s*```$/','',$content);
    $data=json_decode($content,true);
    if(!is_array($data) && preg_match('/\{.*\}/s',$content,$m)) $data=json_decode($m[0],true);
    if(!is_array($data))return null;
    $score=max(0,min($maxPoints,(int)($data['score']??0)));
    $verdict=sanitize_key((string)($data['verdict']??''));
    if(!in_array($verdict,['accepted','partial','rejected'],true))$verdict=$score>=$maxPoints*0.7?'accepted':($score>=$maxPoints*0.35?'partial':'rejected');
    $comment=trim(sanitize_textarea_field((string)($data['comment']??$data['feedback']??'')));
    $strengths=array_values(array_filter(array_map('sanitize_text_field',(array)($data['strengths']??[]))));
    $mistakes=array_values(array_filter(array_map('sanitize_text_field',(array)($data['mistakes']??[]))));
    $recommendation=trim(sanitize_textarea_field((string)($data['recommendation']??'')));
    if($comment===''){
        $parts=[];
        if($strengths)$parts[]='Сильная сторона: '.implode('; ',$strengths).'.';
        if($mistakes)$parts[]='Что упущено: '.implode('; ',$mistakes).'.';
        if($recommendation!=='')$parts[]='Рекомендация: '.$recommendation;
        $comment=implode(' ',$parts);
    }
    if($comment==='')return null;
    return ['score'=>$score,'verdict'=>$verdict,'comment'=>$comment,'provider'=>'ai_puffer'];
}

function ckm_quiz_pro_solution_price_aipuffer_review(array $question,array $answer): ?array {
    $key=ckm_quiz_pro_solution_price_aipuffer_rest_key();
    if($key==='')return null;
    $settings=ckm_quiz_pro_solution_price_ai_settings();
    $maxPoints=max(1,(int)($question['points']??20));
    $rule=ckm_quiz_json_decode($question['scoring_rule_json']??'');
    $criteria=array_values((array)($rule['judgeCriteria']??[]));
    $prompt="ЗАДАНИЕ:\n".(string)($question['question_text']??'')."\n\nОТВЕТ КОМАНДЫ:\n".(string)($answer['answer_text']??'')."\n\nМЕТОДИЧЕСКИЙ ОРИЕНТИР:\n".(string)($question['explanation']??'')."\n\nКРИТЕРИИ:\n".implode(', ',$criteria)."\n\nМаксимум баллов: {$maxPoints}.";
    $body=[
        'provider'=>$settings['provider'],
        'model'=>$settings['model'],
        'messages'=>[
            ['role'=>'system','content'=>'Ты ИИ-ведущий управленческой игры «Управленческая игра "Ваш выбор"». Критически оцени решение, не подыгрывай команде. Отделяй сильные стороны от ошибок и пропусков. Верни ТОЛЬКО JSON без markdown: {"score":0,"verdict":"accepted|partial|rejected","strengths":["..."],"mistakes":["..."],"recommendation":"...","comment":"Короткий разбор в 2–4 предложениях на русском"}.'],
            ['role'=>'user','content'=>$prompt],
        ],
        'ai_params'=>['temperature'=>0.2,'max_completion_tokens'=>450],
        'stream'=>false,
    ];
    $response=wp_remote_post(ckm_quiz_pro_aipuffer_endpoint(),[
        'timeout'=>8,
        'redirection'=>0,
        'headers'=>['Content-Type'=>'application/json','Authorization'=>'Bearer '.$key],
        'body'=>wp_json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
    if(is_wp_error($response))return null;
    $code=(int)wp_remote_retrieve_response_code($response);
    if($code<200||$code>=300)return null;
    $json=json_decode((string)wp_remote_retrieve_body($response),true);
    $content=is_array($json)?(string)($json['content']??$json['reply']??''):'';
    return ckm_quiz_pro_solution_price_parse_ai_json($content,$maxPoints);
}

function ckm_quiz_pro_solution_price_contains_any(string $text,array $needles): bool {
    $text=function_exists('mb_strtolower')?mb_strtolower($text,'UTF-8'):strtolower($text);
    foreach($needles as $n){
        $n=function_exists('mb_strtolower')?mb_strtolower($n,'UTF-8'):strtolower($n);
        if($n!=='' && strpos($text,$n)!==false)return true;
    }
    return false;
}

function ckm_quiz_pro_solution_price_local_review(array $question,array $answer): array {
    $text=trim((string)($answer['answer_text']??''));
    $len=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);
    $maxPoints=max(1,(int)($question['points']??20));
    $position=max(1,(int)($question['position']??1));
    $score=$len<25?2:($len<70?7:($len<150?11:14));
    $strengths=[];$mistakes=[];
    if($len>=70)$strengths[]='ответ достаточно развёрнут'; else $mistakes[]='ответ слишком краткий для обоснованного решения';
    $stageRules=[
        1=>[
            ['причин','почему','корн','данн','провер','гипотез'],
            'есть попытка отделить причину от симптома',
            'не показано, как проверить корневую причину и каких данных не хватает'
        ],
        2=>[
            ['конкрет','шаг','недел','месяц','ответствен','клиент','продаж','ресурс','переговор'],
            'есть конкретизация действий',
            'не хватает последовательности действий, сроков или ответственных'
        ],
        3=>[
            ['риск','вероят','сниз','предотв','резерв','последств','ущерб'],
            'риски и меры снижения хотя бы частично обозначены',
            'риски названы недостаточно конкретно либо нет мер по их снижению'
        ],
        4=>[
            ['15','цен','марж','конкур','сегмент','ценност','меня','коррект'],
            'ответ реагирует на новое ограничение',
            'не объяснено, что именно меняется после снижения цены конкурентом и почему'
        ],
        5=>[
            ['потому','альтернатив','риск','результ','метрик','ожида','выруч','клиент'],
            'финальный выбор хотя бы частично обоснован',
            'не сопоставлены альтернативы и не указан проверяемый ожидаемый результат'
        ],
    ];
    $rule=$stageRules[$position]??$stageRules[5];
    if(ckm_quiz_pro_solution_price_contains_any($text,$rule[0])){$score+=4;$strengths[]=$rule[1];}else{$mistakes[]=$rule[2];}
    if(preg_match('/\b\d+[\s%а-яa-z-]*/ui',$text)){$score+=1;$strengths[]='есть измеримый ориентир или числовая конкретика';}
    if(ckm_quiz_pro_solution_price_contains_any($text,['потому','так как','поэтому','следовательно','если'])){$score+=1;$strengths[]='есть причинно-следственное обоснование';}else{$mistakes[]='слабо раскрыта логика «почему это решение должно сработать»';}
    $score=max(0,min($maxPoints,$score));
    $verdict=$score>=$maxPoints*0.7?'accepted':($score>=$maxPoints*0.35?'partial':'rejected');
    $comment='Сильные стороны: '.($strengths?implode('; ',$strengths):'явных сильных сторон пока недостаточно').'. ';
    $comment.='Что улучшить: '.($mistakes?implode('; ',$mistakes):'существенных пропусков не обнаружено').'. ';
    $explanation=trim((string)($question['explanation']??''));
    if($explanation!=='')$comment.='Ориентир: '.$explanation;
    return ['score'=>$score,'verdict'=>$verdict,'comment'=>$comment,'provider'=>'local_rubric'];
}

function ckm_quiz_pro_solution_price_review_closed_question(int $gameId,int $questionId): array {
    if($gameId<=0||$questionId<=0)return ['ok'=>true,'rows'=>[],'provider'=>'none'];
    $game=ckm_quiz_get_game($gameId);
    if(!$game || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='solution_price')return ['ok'=>true,'rows'=>[],'provider'=>'none'];
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
        $review=ckm_quiz_pro_solution_price_aipuffer_review($question,$answer);
        if(!$review && $judgeMode==='hybrid'){
            $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>0,'verdict'=>'pending','comment'=>'ИИ-оценка недоступна; ответ оставлен ведущему.','provider'=>'human_fallback'];
            continue;
        }
        if(!$review)$review=ckm_quiz_pro_solution_price_local_review($question,$answer);
        $wpdb->query('START TRANSACTION');
        try{
            $lockedGame=ckm_quiz_get_game($gameId,true);
            $lockedAnswer=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".ckm_quiz_answers_table()." WHERE id=%d FOR UPDATE",(int)$answer['id']),ARRAY_A);
            if($lockedGame && $lockedAnswer && (string)($lockedAnswer['verdict']??'pending')==='pending'){
                $applied=ckm_quiz_apply_score_locked(
                    $lockedGame,$question,$lockedAnswer,(int)$review['score'],(string)$review['verdict'],(string)$review['comment'],'ai_host',0,
                    'solution-review-'.(int)$answer['id'].'-attempt-'.(int)($answer['attempt_no']??1)
                );
                if(empty($applied['ok']))throw new RuntimeException((string)($applied['code']??'solution_score_failed'));
            }
            $wpdb->query('COMMIT');
        }catch(Throwable $e){
            $wpdb->query('ROLLBACK');
            error_log('CKM Quiz solution-price review failed: '.$e->getMessage());
        }
        $rows[]=['teamId'=>(int)$answer['team_id'],'teamName'=>(string)$answer['team_name'],'score'=>(int)$review['score'],'verdict'=>(string)$review['verdict'],'comment'=>(string)$review['comment'],'provider'=>(string)$review['provider']];
    }
    return ['ok'=>true,'rows'=>$rows,'provider'=>!empty($rows)?(string)($rows[0]['provider']??'none'):'none'];
}

/** Close a «Управленческая игра "Ваш выбор"» stage as soon as every active team has answered. */
function ckm_quiz_pro_solution_price_maybe_auto_close(int $gameId): array {
    $game=ckm_quiz_get_game($gameId);
    if(!$game || (string)($game['quiz_phase']??'')!=='question_open' || !function_exists('ckm_quiz_runtime_format_key') || ckm_quiz_runtime_format_key($game)!=='solution_price')return ['ok'=>true,'skipped'=>true];
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

/** Validate every stage before changing the current quiz revision. */
function ckm_quiz_pro_solution_price_builder_rows($input) {
    if (!is_array($input) || count($input)>100) return new WP_Error('invalid_stages','Добавьте от одного до ста этапов.');
    $rows=[];
    foreach ($input as $row) {
        if (!is_array($row)) return new WP_Error('invalid_stage','Некорректные данные этапа.');
        foreach (['text','criteria','explanation','points'] as $key) {
            if (isset($row[$key]) && !is_scalar($row[$key])) return new WP_Error('invalid_stage','Некорректное поле этапа.');
        }
        $text=trim(sanitize_textarea_field(wp_unslash((string)($row['text']??''))));
        $raw=trim(sanitize_textarea_field(wp_unslash((string)($row['criteria']??''))));
        if ($text==='' || $raw==='') return new WP_Error('empty_stage','Заполните ситуацию и критерии оценки каждого этапа. Лишний этап можно удалить.');
        $criteria=array_values(array_filter(array_map('trim',preg_split('/\R/u',$raw)?:[]),static fn($v)=>$v!==''));
        $rows[]=['text'=>$text,'criteria'=>$criteria,'points'=>max(1,min(100,(int)($row['points']??20))),
            'explanation'=>sanitize_textarea_field(wp_unslash((string)($row['explanation']??'')))];
    }
    return $rows ?: new WP_Error('empty_stages','Добавьте хотя бы один этап.');
}

function ckm_quiz_pro_solution_price_save_builder_rows(int $id,int $revision,int $seconds,array $rows,string $now): void {
    global $wpdb;
    $saved=$wpdb->insert(ckm_quiz_pro_table('rounds'),['quiz_id'=>$id,'quiz_revision'=>$revision,'round_key'=>'case-1','position'=>1,'title'=>'Кейс','round_type'=>'case','rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $roundId=(int)$wpdb->insert_id;
    if ($saved===false || $roundId<=0) {
        $wpdb->query('ROLLBACK');
        wp_die('Не удалось сохранить раунд. Изменения отменены.');
    }
    foreach ($rows as $i=>$row) {
        $saved=$wpdb->insert(ckm_quiz_pro_table('questions'),[
            'quiz_id'=>$id,'quiz_revision'=>$revision,'question_key'=>'stage-'.($i+1),'position'=>$i+1,
            'round_no'=>1,'round_title'=>'Кейс','round_id'=>$roundId,'question_stage'=>'main','question_type'=>'text','question_text'=>$row['text'],
            'options_json'=>'[]','correct_answers_json'=>'[]','numeric_tolerance'=>0,'points'=>$row['points'],'time_limit_seconds'=>$seconds,
            'scoring_rule_json'=>wp_json_encode(['judgeCriteria'=>$row['criteria'],'manualOrAiReview'=>true],JSON_UNESCAPED_UNICODE),
            'explanation'=>$row['explanation'],'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
        if ($saved===false) {
            $wpdb->query('ROLLBACK');
            wp_die('Не удалось сохранить этап. Изменения отменены.');
        }
    }
}

/** Shared by the organizer cabinet and technical admin editor. */
function ckm_quiz_pro_solution_price_builder_fields(array $questions): void {
    echo '<p class="ckm-muted">Добавьте этапы собственного кейса. Команды дают текстовые ответы; для каждого этапа задайте критерии оценки и максимум баллов. Укажите в задании все нужные участникам условия.</p><h2>Этапы</h2><div id="ckm-solution-stages">';
    foreach ($questions as $i=>$q) {
        $rule=json_decode((string)($q['scoring_rule_json']??''),true)?:[];
        ckm_quiz_pro_org_solution_price_question_editor($i,(string)$q['question_text'],implode("\n",(array)($rule['judgeCriteria']??[])),(int)$q['points'],(string)$q['explanation']);
    }
    if (!$questions) ckm_quiz_pro_org_solution_price_question_editor(0,'','',20,'');
    echo '</div><div class="ckm-card-actions"><button type="button" class="ckm-btn" id="ckm-solution-add">Добавить этап</button><button class="ckm-btn ckm-btn-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></div>';
    ob_start();
    ckm_quiz_pro_org_solution_price_question_editor(0,'','',20,'');
    $template=(string)ob_get_clean();
    echo '<script>(function(){const box=document.getElementById("ckm-solution-stages"),add=document.getElementById("ckm-solution-add"),tpl='.wp_json_encode($template,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT).';function number(){box.querySelectorAll(".ckm-q-card").forEach((card,i)=>{card.querySelector(".ckm-q-title").textContent="Этап "+(i+1);card.querySelectorAll("[name]").forEach(el=>{el.name=el.name.replace(/questions\[\d+\]/,"questions["+i+"]");});});}function setup(card){card.querySelectorAll("textarea").forEach(el=>{if(/\[(text|criteria)\]$/.test(el.name))el.required=true;});const remove=document.createElement("button");remove.type="button";remove.className="ckm-btn";remove.textContent="Удалить этап";remove.addEventListener("click",()=>{card.remove();number();});card.appendChild(remove);}box.querySelectorAll(".ckm-q-card").forEach(setup);add.addEventListener("click",()=>{if(box.children.length>=100)return;const holder=document.createElement("div");holder.innerHTML=tpl;const card=holder.firstElementChild;setup(card);box.appendChild(card);number();});const format=document.getElementById("ckm-front-format"),refresh=document.getElementById("ckm-front-format-refresh");if(format&&refresh)format.addEventListener("change",()=>{const url=new URL(refresh.href);url.searchParams.set("format",format.value);refresh.href=url.href;});})();</script>';
}

function ckm_quiz_pro_solution_price_admin_builder(array $quiz,array $questions): void {
    echo '<div class="wrap"><h1>Управленческая игра "Ваш выбор" — конструктор</h1>';
    if (isset($_GET['saved'])) echo '<p>Игра сохранена новой редакцией.</p>';
    echo '<form method="post">';
    wp_nonce_field('ckm_quiz_pro_save_quiz');
    echo '<input type="hidden" name="quiz_id" value="'.(int)$quiz['id'].'"><input type="hidden" name="format_key" value="solution_price">';
    echo '<p><label>Название <input name="title" value="'.esc_attr($quiz['title']).'" required></label></p><input type="hidden" name="slug" value="'.esc_attr($quiz['slug']).'">';
    echo '<p><label>Статус <select name="status"><option value="draft" '.selected($quiz['status'],'draft',false).'>Черновик</option><option value="published" '.selected($quiz['status'],'published',false).'>Опубликован</option></select></label></p>';
    echo '<p><label>Время на этап, секунд <input type="number" name="seconds_per_question" min="30" max="600" value="'.(int)$quiz['seconds_per_question'].'"></label></p>';
    echo '<p><label>Ведущий <select name="host_mode"><option value="ai" '.selected($quiz['host_mode'],'ai',false).'>ИИ-ведущий</option><option value="human" '.selected($quiz['host_mode'],'human',false).'>Ведущий (с микрофоном/без микрофона)</option></select></label></p>';
    ckm_quiz_pro_solution_price_builder_fields($questions);
    echo '</form></div>';
}

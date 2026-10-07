<?php
if (!defined('ABSPATH')) exit;

/**
 * Standalone adapter for CKM format «Интеллектуальный батл».
 * Shared gameplay remains in Quiz Core (jeopardy_v1); this file provides
 * standalone seeding, authoring UI helpers and local objective arbitration.
 */

function ckm_quiz_pro_jeopardy_values(): array { return [100,200,300]; }

function ckm_quiz_pro_jeopardy_default_categories(): array {
    return ['Логика','Наука','Технологии','Бизнес'];
}

function ckm_quiz_pro_jeopardy_settings(int $seconds=90, array $categories=[], int $buzzerSeconds=15): array {
    $seconds=max(15,min(300,$seconds));
    $buzzerSeconds=max(5,min(60,$buzzerSeconds));
    $defaults=ckm_quiz_pro_jeopardy_default_categories();
    $out=[];
    for($i=0;$i<4;$i++){
        $title=sanitize_text_field((string)($categories[$i]??$defaults[$i]));
        if($title==='') $title=$defaults[$i];
        $out[]=$title;
    }
    return [
        'categoryCount'=>4,
        'categoryTitles'=>$out,
        'questionValues'=>ckm_quiz_pro_jeopardy_values(),
        'buzzerSeconds'=>$buzzerSeconds,
        'judgeMode'=>'ai',
        'victoryMode'=>'points',
        'standaloneLocalJudge'=>'reference_match',
        'finalPoints'=>500,
        'secondsPerQuestion'=>$seconds,
    ];
}

function ckm_quiz_pro_jeopardy_post_settings(int $seconds, array $fallback=[]): array {
    $cats=$_POST['jeopardy_categories']??($fallback['categoryTitles']??[]);
    if(!is_array($cats)) $cats=[];
    $buzzer=(int)($_POST['jeopardy_buzzer_seconds']??($fallback['buzzerSeconds']??15));
    return ckm_quiz_pro_jeopardy_settings($seconds,$cats,$buzzer);
}

function ckm_quiz_pro_jeopardy_answers_from_post(array $row): array {
    $reference=trim(sanitize_text_field(wp_unslash($row['reference']??'')));
    $variantsRaw=sanitize_textarea_field(wp_unslash($row['variants']??''));
    $variants=preg_split('/[\r\n|]+/u',$variantsRaw) ?: [];
    $variants=array_values(array_filter(array_map('trim',$variants),static fn($v)=>$v!==''));
    $answers=$reference!=='' ? array_values(array_unique(array_merge([$reference],$variants))) : $variants;
    return [$reference,$variants,$answers];
}

/** Save the board and optional fixed-500 final into a new quiz revision. */
function ckm_quiz_pro_jeopardy_save_questions(int $quizId,int $revision,int $seconds,array $settings,string $now,int $userId): void {
    global $wpdb;
    $rounds=ckm_quiz_pro_table('rounds');
    $questions=ckm_quiz_pro_table('questions');
    $wpdb->insert($rounds,[
        'quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,
        'title'=>'Основное поле','round_type'=>'board','rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now
    ]);
    $boardRoundId=(int)$wpdb->insert_id;
    $cats=(array)($settings['categoryTitles']??ckm_quiz_pro_jeopardy_default_categories());
    $values=ckm_quiz_pro_jeopardy_values();
    $posted=$_POST['jeopardy_cells']??[];
    if(!is_array($posted)) $posted=[];
    $pos=0;
    foreach($cats as $ci=>$catTitle){
        foreach($values as $value){
            $row=$posted[$ci][$value]??[];
            if(!is_array($row)) continue;
            $text=trim(sanitize_textarea_field(wp_unslash($row['text']??'')));
            [$reference,$variants,$answers]=ckm_quiz_pro_jeopardy_answers_from_post($row);
            if($text==='' || $reference==='') continue;
            $pos++;
            $special=sanitize_key((string)($row['special']??''));
            if($special!=='cat_in_bag') $special='';
            $categoryKey=function_exists('ckm_quiz_jeopardy_normalize_category_key')
                ? ckm_quiz_jeopardy_normalize_category_key((string)$catTitle)
                : sanitize_key((string)$catTitle);
            $wpdb->insert($questions,[
                'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>'board-'.$ci.'-'.$value,'position'=>$pos,
                'round_no'=>1,'round_title'=>'Основное поле','round_id'=>$boardRoundId,'question_stage'=>'main',
                'jeopardy_category_key'=>$categoryKey,'jeopardy_category_title'=>(string)$catTitle,'jeopardy_value'=>$value,'jeopardy_special_type'=>$special,
                'question_type'=>'text','question_text'=>$text,'options_json'=>'[]','correct_answers_json'=>wp_json_encode($answers,JSON_UNESCAPED_UNICODE),
                'numeric_tolerance'=>0,'points'=>$value,'time_limit_seconds'=>$seconds,
                'scoring_rule_json'=>wp_json_encode(['acceptedVariants'=>$variants],JSON_UNESCAPED_UNICODE),
                'explanation'=>sanitize_textarea_field(wp_unslash($row['explanation']??'')),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
                'status'=>'active','created_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now
            ]);
        }
    }
    $final=$_POST['jeopardy_final']??[];
    if(!is_array($final)) $final=[];
    $finalText=trim(sanitize_textarea_field(wp_unslash($final['text']??'')));
    [$finalReference,$finalVariants,$finalAnswers]=ckm_quiz_pro_jeopardy_answers_from_post($final);
    if($finalText!=='' && $finalReference!==''){
        $wpdb->insert($rounds,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-2','position'=>2,'title'=>'Финал','round_type'=>'final_question',
            'rules_json'=>'{}','settings_json'=>wp_json_encode(['fixedPoints'=>500],JSON_UNESCAPED_UNICODE),'status'=>'active','created_at'=>$now,'updated_at'=>$now
        ]);
        $finalRoundId=(int)$wpdb->insert_id;
        $wpdb->insert($questions,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>'final','position'=>$pos+1,
            'round_no'=>2,'round_title'=>'Финал','round_id'=>$finalRoundId,'question_stage'=>'final',
            'jeopardy_category_key'=>'','jeopardy_category_title'=>'','jeopardy_value'=>0,'jeopardy_special_type'=>'',
            'question_type'=>'text','question_text'=>$finalText,'options_json'=>'[]','correct_answers_json'=>wp_json_encode($finalAnswers,JSON_UNESCAPED_UNICODE),
            'numeric_tolerance'=>0,'points'=>500,'time_limit_seconds'=>$seconds,
            'scoring_rule_json'=>wp_json_encode(['acceptedVariants'=>$finalVariants,'fixedPoints'=>500],JSON_UNESCAPED_UNICODE),
            'explanation'=>sanitize_textarea_field(wp_unslash($final['explanation']??'')),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>$userId,'created_at'=>$now,'updated_at'=>$now
        ]);
    }
}

function ckm_quiz_pro_jeopardy_editor_data(array $quiz,array $questions): array {
    $settings=json_decode((string)($quiz['format_settings_json']??''),true); if(!is_array($settings)) $settings=[];
    $categories=(array)($settings['categoryTitles']??ckm_quiz_pro_jeopardy_default_categories());
    for($i=0;$i<4;$i++) if(empty($categories[$i])) $categories[$i]=ckm_quiz_pro_jeopardy_default_categories()[$i];
    $cells=[]; $final=null;
    foreach($questions as $q){
        if((string)($q['question_stage']??'main')==='final'){ $final=$q; continue; }
        $cat=(string)($q['jeopardy_category_title']??''); $ci=array_search($cat,$categories,true);
        if($ci===false) continue;
        $cells[(int)$ci][(int)($q['jeopardy_value']??0)]=$q;
    }
    return ['settings'=>$settings,'categories'=>$categories,'cells'=>$cells,'final'=>$final];
}

function ckm_quiz_pro_jeopardy_q_values(?array $q): array {
    if(!$q) return ['text'=>'','reference'=>'','variants'=>'','special'=>'','explanation'=>''];
    $answers=json_decode((string)($q['correct_answers_json']??''),true); if(!is_array($answers)) $answers=[];
    return [
        'text'=>(string)($q['question_text']??''),
        'reference'=>(string)($answers[0]??''),
        'variants'=>implode("\n",array_slice($answers,1)),
        'special'=>(string)($q['jeopardy_special_type']??''),
        'explanation'=>(string)($q['explanation']??''),
    ];
}

function ckm_quiz_pro_render_jeopardy_admin_editor(array $quiz,array $questions): void {
    $d=ckm_quiz_pro_jeopardy_editor_data($quiz,$questions); $values=ckm_quiz_pro_jeopardy_values();
    echo '<h2>Игровое поле</h2><p class="description">4 категории × 3 номинала. Одна или несколько ячеек могут быть «Секретной передачей».</p>';
    echo '<table class="form-table"><tr><th>Время после кнопки «ОТВЕЧАЕМ!»</th><td><input type="number" min="5" max="60" name="jeopardy_buzzer_seconds" value="'.(int)($d['settings']['buzzerSeconds']??15).'"> сек.</td></tr></table>';
    foreach($d['categories'] as $ci=>$cat){
        echo '<div class="postbox"><div class="postbox-header"><h2>Категория '.($ci+1).'</h2></div><div class="inside">';
        echo '<p><label>Название категории: <input class="regular-text" name="jeopardy_categories['.$ci.']" value="'.esc_attr($cat).'"></label></p>';
        foreach($values as $value){ $v=ckm_quiz_pro_jeopardy_q_values($d['cells'][$ci][$value]??null);
            echo '<hr><h3>'.$value.' баллов</h3><p><textarea name="jeopardy_cells['.$ci.']['.$value.'][text]" rows="3" style="width:100%" placeholder="Текст вопроса">'.esc_textarea($v['text']).'</textarea></p>';
            echo '<p><label>Эталонный ответ: <input class="regular-text" name="jeopardy_cells['.$ci.']['.$value.'][reference]" value="'.esc_attr($v['reference']).'"></label></p>';
            echo '<p><textarea name="jeopardy_cells['.$ci.']['.$value.'][variants]" rows="2" style="width:100%" placeholder="Допустимые варианты — по одному в строке">'.esc_textarea($v['variants']).'</textarea></p>';
            echo '<p><label>Механика: <select name="jeopardy_cells['.$ci.']['.$value.'][special]"><option value="" '.selected($v['special'],'',false).'>Обычный вопрос</option><option value="cat_in_bag" '.selected($v['special'],'cat_in_bag',false).'>Секретная передача</option></select></label></p>';
            echo '<p><textarea name="jeopardy_cells['.$ci.']['.$value.'][explanation]" rows="2" style="width:100%" placeholder="Комментарий после ответа">'.esc_textarea($v['explanation']).'</textarea></p>';
        }
        echo '</div></div>';
    }
    $f=ckm_quiz_pro_jeopardy_q_values($d['final']);
    echo '<div class="postbox"><div class="postbox-header"><h2>Финал · 500 баллов</h2></div><div class="inside"><p class="description">Финальный вопрос необязателен. Все команды отвечают скрыто; верный ответ даёт фиксированные 500 баллов, ставок нет.</p>';
    echo '<p><textarea name="jeopardy_final[text]" rows="3" style="width:100%" placeholder="Текст финального вопроса">'.esc_textarea($f['text']).'</textarea></p><p><label>Эталонный ответ: <input class="regular-text" name="jeopardy_final[reference]" value="'.esc_attr($f['reference']).'"></label></p><p><textarea name="jeopardy_final[variants]" rows="2" style="width:100%" placeholder="Допустимые варианты">'.esc_textarea($f['variants']).'</textarea></p><p><textarea name="jeopardy_final[explanation]" rows="2" style="width:100%" placeholder="Комментарий после финала">'.esc_textarea($f['explanation']).'</textarea></p></div></div>';
}

function ckm_quiz_pro_render_jeopardy_org_editor(array $quiz,array $questions): void {
    $d=ckm_quiz_pro_jeopardy_editor_data($quiz,$questions); $values=ckm_quiz_pro_jeopardy_values();
    echo '<div class="ckm-card"><h2>Игровое поле</h2><p class="ckm-muted">4 категории × 3 номинала. Для специальной ячейки можно выбрать «Секретную передачу».</p><label class="ckm-label">Время после кнопки «ОТВЕЧАЕМ!»<input class="ckm-input" type="number" min="5" max="60" name="jeopardy_buzzer_seconds" value="'.(int)($d['settings']['buzzerSeconds']??15).'"></label></div>';
    foreach($d['categories'] as $ci=>$cat){
        echo '<div class="ckm-card"><label class="ckm-label">Категория '.($ci+1).'<input class="ckm-input" name="jeopardy_categories['.$ci.']" value="'.esc_attr($cat).'"></label>';
        foreach($values as $value){ $v=ckm_quiz_pro_jeopardy_q_values($d['cells'][$ci][$value]??null);
            echo '<div class="ckm-q-card"><div class="ckm-q-title">'.$value.' баллов</div><label class="ckm-label">Вопрос<textarea class="ckm-input" name="jeopardy_cells['.$ci.']['.$value.'][text]" rows="3">'.esc_textarea($v['text']).'</textarea></label><label class="ckm-label">Эталонный ответ<input class="ckm-input" name="jeopardy_cells['.$ci.']['.$value.'][reference]" value="'.esc_attr($v['reference']).'"></label><label class="ckm-label">Допустимые варианты<textarea class="ckm-input" name="jeopardy_cells['.$ci.']['.$value.'][variants]" rows="2" placeholder="По одному в строке">'.esc_textarea($v['variants']).'</textarea></label><label class="ckm-label">Механика<select class="ckm-input" name="jeopardy_cells['.$ci.']['.$value.'][special]"><option value="" '.selected($v['special'],'',false).'>Обычный вопрос</option><option value="cat_in_bag" '.selected($v['special'],'cat_in_bag',false).'>Секретная передача</option></select></label><label class="ckm-label">Комментарий<textarea class="ckm-input" name="jeopardy_cells['.$ci.']['.$value.'][explanation]" rows="2">'.esc_textarea($v['explanation']).'</textarea></label></div>';
        }
        echo '</div>';
    }
    $f=ckm_quiz_pro_jeopardy_q_values($d['final']);
    echo '<div class="ckm-card"><h2>Финал · 500 баллов</h2><p class="ckm-muted">Необязательный финал без ставок: все команды отвечают скрыто, верный ответ даёт 500 баллов.</p><label class="ckm-label">Финальный вопрос<textarea class="ckm-input" name="jeopardy_final[text]" rows="3">'.esc_textarea($f['text']).'</textarea></label><label class="ckm-label">Эталонный ответ<input class="ckm-input" name="jeopardy_final[reference]" value="'.esc_attr($f['reference']).'"></label><label class="ckm-label">Допустимые варианты<textarea class="ckm-input" name="jeopardy_final[variants]" rows="2">'.esc_textarea($f['variants']).'</textarea></label><label class="ckm-label">Комментарий<textarea class="ckm-input" name="jeopardy_final[explanation]" rows="2">'.esc_textarea($f['explanation']).'</textarea></label></div>';
}

function ckm_quiz_pro_seed_demo_jeopardy(): void {
    global $wpdb;
    $qz=ckm_quiz_pro_table('quizzes'); $qq=ckm_quiz_pro_table('questions'); $rr=ckm_quiz_pro_table('rounds');
    $now=current_time('mysql');
    $existing=$wpdb->get_row("SELECT * FROM {$qz} WHERE slug='ckm-demo-intellectual-battle' LIMIT 1",ARRAY_A);
    if($existing && (string)($existing['title']??'')!=='Интеллектуальный батл'){ $wpdb->update($qz,['title'=>'Интеллектуальный батл','updated_at'=>$now],['id'=>(int)$existing['id']]); $existing['title']='Интеллектуальный батл'; }
    if($existing){
        $cnt=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",(int)$existing['id'],(int)$existing['current_revision']));
        if($cnt>=13) return;
    }
    $path=CKM_QUIZ_PRO_DIR.'fixtures/demo-jeopardy.json';
    if(!is_readable($path)) return;
    $fixture=json_decode((string)file_get_contents($path),true); if(!is_array($fixture)) return;
    $settings=ckm_quiz_pro_jeopardy_settings((int)($fixture['secondsPerQuestion']??90),(array)($fixture['formatSettings']['categoryTitles']??[]),(int)($fixture['formatSettings']['buzzerSeconds']??15));
    if(!$existing){
        $wpdb->insert($qz,[
            'title'=>'Интеллектуальный батл','slug'=>'ckm-demo-intellectual-battle','format_key'=>'jeopardy',
            'short_description'=>(string)($fixture['shortDescription']??''),'instructions'=>(string)($fixture['instructions']??''),'cover_url'=>'','status'=>'published','current_revision'=>1,
            'min_teams'=>2,'max_teams'=>10,'host_mode'=>'ai','judge_mode'=>'ai','seconds_per_question'=>(int)($fixture['secondsPerQuestion']??90),
            'scoring_policy_json'=>'{}','settings_json'=>wp_json_encode(['demo'=>true],JSON_UNESCAPED_UNICODE),'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE),
            'created_by_user_id'=>get_current_user_id(),'updated_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'published_at'=>$now
        ]);
        $quizId=(int)$wpdb->insert_id; $revision=1;
    } else { $quizId=(int)$existing['id']; $revision=max(1,(int)$existing['current_revision']); }
    if($quizId<=0) return;
    $boardRoundId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-1'",$quizId,$revision));
    if($boardRoundId<=0){ $wpdb->insert($rr,['quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,'title'=>'Основное поле','round_type'=>'board','rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now]); $boardRoundId=(int)$wpdb->insert_id; }
    $finalRoundId=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-2'",$quizId,$revision));
    $pos=0;
    foreach((array)($fixture['questions']??[]) as $item){
        if(!is_array($item)) continue; $pos++;
        $stage=sanitize_key((string)($item['questionStage']??'main'))==='final'?'final':'main';
        if($stage==='final' && $finalRoundId<=0){ $wpdb->insert($rr,['quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-2','position'=>2,'title'=>'Финал','round_type'=>'final_question','rules_json'=>'{}','settings_json'=>wp_json_encode(['fixedPoints'=>500]),'status'=>'active','created_at'=>$now,'updated_at'=>$now]); $finalRoundId=(int)$wpdb->insert_id; }
        $key=sanitize_key((string)($item['key']??('q-'.$pos))); if($key==='') $key='q-'.$pos;
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND question_key=%s",$quizId,$revision,$key)); if($exists) continue;
        $cat=(string)($item['category']??''); $special=sanitize_key((string)($item['specialType']??'')); if($special!=='cat_in_bag') $special='';
        $answers=(array)($item['correctAnswers']??[]); $rule=(array)($item['scoringRule']??[]);
        $wpdb->insert($qq,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>$key,'position'=>(int)($item['position']??$pos),
            'round_no'=>$stage==='final'?2:1,'round_title'=>$stage==='final'?'Финал':'Основное поле','round_id'=>$stage==='final'?$finalRoundId:$boardRoundId,'question_stage'=>$stage,
            'jeopardy_category_key'=>$stage==='main'&&function_exists('ckm_quiz_jeopardy_normalize_category_key')?ckm_quiz_jeopardy_normalize_category_key($cat):'',
            'jeopardy_category_title'=>$stage==='main'?$cat:'','jeopardy_value'=>$stage==='main'?(int)($item['value']??0):0,'jeopardy_special_type'=>$stage==='main'?$special:'',
            'question_type'=>'text','question_text'=>(string)($item['text']??''),'options_json'=>'[]','correct_answers_json'=>wp_json_encode($answers,JSON_UNESCAPED_UNICODE),'numeric_tolerance'=>0,
            'points'=>$stage==='final'?500:(int)($item['points']??$item['value']??100),'time_limit_seconds'=>(int)($item['timeLimitSeconds']??90),
            'scoring_rule_json'=>wp_json_encode($rule,JSON_UNESCAPED_UNICODE),'explanation'=>(string)($item['explanation']??''),'host_script'=>(string)($item['hostScript']??''),
            'media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
    }
    update_option('ckm_quiz_pro_demo_jeopardy_seed','1',false);
}

/** Strict local evaluation of an already submitted Jeopardy answer. */
function ckm_quiz_pro_jeopardy_local_decision(int $gameId,int $teamId): ?string {
    $game=ckm_quiz_get_game($gameId); if(!$game || ckm_quiz_runtime_format_key($game)!=='jeopardy') return null;
    $question=ckm_quiz_get_question((int)($game['current_question_id']??0)); if(!$question) return null;
    $answer=ckm_quiz_get_answer($gameId,$teamId,(int)$question['id']); if(!$answer) return null;
    $evaluation=ckm_quiz_evaluate_answer($question,$answer,'ai');
    if(empty($evaluation['judged'])) return null;
    return (string)$evaluation['verdict']==='correct'?'accepted':'rejected';
}

function ckm_quiz_pro_jeopardy_local_resolve_after_answer(int $gameId,int $teamId): array {
    $game=ckm_quiz_get_game($gameId); if(!$game || ckm_quiz_runtime_format_key($game)!=='jeopardy') return ['ok'=>true,'skipped'=>true];
    if((string)($game['host_mode_snapshot']??'')!=='ai') return ['ok'=>true,'skipped'=>true,'reason'=>'human_host'];
    if(function_exists('ckm_quiz_jeopardy_final_is_current') && ckm_quiz_jeopardy_final_is_current($game)) return ['ok'=>true,'skipped'=>true,'reason'=>'final_hidden'];
    $decision=ckm_quiz_pro_jeopardy_local_decision($gameId,$teamId); if(!$decision) return ['ok'=>true,'skipped'=>true,'reason'=>'not_objective'];
    $auth=['role'=>'ai_host','actor_type'=>'ai_host','game_id'=>$gameId,'team_id'=>0,'user_id'=>0];
    $cat=ckm_quiz_jeopardy_cat_state($game);
    if(!empty($cat['enabled']) && (int)($cat['targetTeamId']??0)===$teamId) return ckm_quiz_jeopardy_resolve_cat($gameId,$decision,$auth);
    return ckm_quiz_jeopardy_resolve_buzzer($gameId,$decision,$auth);
}

/** Hook expected by shared AI-host final autopilot; local standalone uses exact/variant matching only. */
function ckm_quiz_jeopardy_ai_arbitrate_final_all(int $gameId): array {
    $game=ckm_quiz_get_game($gameId); if(!$game || ckm_quiz_runtime_format_key($game)!=='jeopardy') return ['ok'=>true,'skipped'=>true];
    $state=ckm_quiz_jeopardy_final_state($game,'host'); if(empty($state['revealed'])) return ['ok'=>true,'skipped'=>true,'reason'=>'not_revealed'];
    $auth=['role'=>'ai_host','actor_type'=>'ai_host','game_id'=>$gameId,'team_id'=>0,'user_id'=>0]; $resolved=0;
    foreach((array)($state['rows']??[]) as $row){
        $teamId=(int)($row['teamId']??0); if($teamId<=0 || !empty($row['resolved'])) continue;
        $decision=ckm_quiz_pro_jeopardy_local_decision($gameId,$teamId) ?: 'rejected';
        $r=ckm_quiz_jeopardy_resolve_final_team($gameId,$teamId,$decision,$auth); if(empty($r['ok'])) return $r; $resolved++;
    }
    return ['ok'=>true,'resolved'=>$resolved,'mode'=>'reference_match'];
}

function ckm_quiz_pro_jeopardy_all_teams_joined(array $game): bool {
    $gameId=(int)($game['id']??0); if($gameId<=0) return false;
    global $wpdb;
    $active=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_teams_table()." WHERE game_id=%d AND team_status='active'",$gameId));
    $joined=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT team_id) FROM ".ckm_quiz_members_table()." WHERE game_id=%d AND member_status='active'",$gameId));
    return $active>0 && $joined>=$active;
}

function ckm_quiz_pro_jeopardy_local_autopilot(int $gameId): array {
    $game=ckm_quiz_get_game($gameId);
    if(!$game || ckm_quiz_runtime_format_key($game)!=='jeopardy') return ['ok'=>true,'skipped'=>true,'reason'=>'format'];
    if((string)($game['host_mode_snapshot']??'')!=='ai' || (string)($game['status']??'')==='finished') return ['ok'=>true,'skipped'=>true,'reason'=>'mode'];
    if(function_exists('ckm_quiz_jeopardy_ai_autopilot')) ckm_quiz_jeopardy_ai_autopilot($gameId);
    $game=ckm_quiz_get_game($gameId) ?: $game;
    if((string)($game['status']??'')==='finished') return ['ok'=>true,'action'=>'finished'];
    $final=function_exists('ckm_quiz_jeopardy_final_question') ? ckm_quiz_jeopardy_final_question($game) : null;
    if(!$final && function_exists('ckm_quiz_jeopardy_final_board_complete') && ckm_quiz_jeopardy_final_board_complete($game) && (string)($game['quiz_phase']??'')!=='question_open'){
        global $wpdb;
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND verdict='pending'",$gameId));
        if($pending===0) return ckm_quiz_finish_game($gameId,'ai_host',0,true);
    }
    return ['ok'=>true,'skipped'=>true,'reason'=>'waiting'];
}

/**
 * Open the question currently selected on the Jeopardy board.
 *
 * 0.3.12.2 deliberately keeps this outside the selection transaction: the
 * shared Core owns question start/round/access semantics.  The helper is also
 * used as a recovery path for rooms left with a selected cell but no open
 * question by 0.3.12/0.3.12.1.
 */
function ckm_quiz_pro_jeopardy_open_selected_question(array $game,int $uid,bool $isAi): array {
    $gameId=(int)($game['id']??0);
    $fresh=ckm_quiz_get_game($gameId) ?: $game;
    if((string)($fresh['quiz_phase']??'')==='question_open') {
        return ['ok'=>true,'alreadyOpen'=>true,'game'=>$fresh,'question'=>ckm_quiz_get_question((int)($fresh['current_question_id']??0))];
    }
    $selectedId=(int)($fresh['jeopardy_selected_question_id']??0);
    if($selectedId<=0) return ckm_quiz_error(409,'Сначала выберите ячейку игрового поля.','jeopardy_cell_selection_required');
    $q=ckm_quiz_get_question($selectedId);
    if(!$q) return ckm_quiz_error(404,'Выбранный вопрос не найден.','jeopardy_selected_question_missing');
    if(function_exists('ckm_quiz_jeopardy_is_cat_question') && ckm_quiz_jeopardy_is_cat_question($q)) {
        return ['ok'=>true,'needsTarget'=>true,'game'=>$fresh,'question'=>$q];
    }
    $opened=ckm_quiz_open_next_question($gameId,$isAi?'ai_host':'participant',$isAi?0:$uid);
    if(empty($opened['ok'])) return $opened;
    return ['ok'=>true,'opened'=>$opened,'game'=>ckm_quiz_get_game($gameId) ?: $fresh,'question'=>$q];
}

function ckm_quiz_pro_jeopardy_team_action(array $game,array $team,array $member,string $command,array $input): array {
    $gameId=(int)$game['id']; $teamId=(int)$team['id']; $uid=(int)$member['user_id'];
    $auth=['role'=>'participant','actor_type'=>'participant','game_id'=>$gameId,'team_id'=>$teamId,'user_id'=>$uid,'member_role'=>$member['member_role']??'player'];
    if((string)($game['host_mode_snapshot']??'')!=='ai') {
        return ckm_quiz_error(409,'Самостоятельный выбор команды доступен только в режиме ИИ-ведущего.','jeopardy_ai_host_required');
    }
    if($command==='buzz') return ckm_quiz_jeopardy_claim_buzzer($auth);
    if($command==='select_cell'){
        $questionId=absint($input['question_id']??0);
        if($questionId<=0) return ckm_quiz_error(422,'Не выбрана ячейка игрового поля.','jeopardy_selection_missing');
        // Compatibility recovery for rooms that were left in a selected-but-not-open
        // state by 0.3.12.1/0.3.12.2. Fresh games follow the main-site sequence below.
        $fresh=ckm_quiz_get_game($gameId) ?: $game;
        $pendingId=(int)($fresh['jeopardy_selected_question_id']??0);
        if($pendingId>0 && (string)($fresh['quiz_phase']??'')!=='question_open'){
            if($pendingId!==$questionId) return ckm_quiz_error(409,'Другая ячейка уже выбрана и ожидает запуска.','jeopardy_cell_already_selected');
            $opened=ckm_quiz_open_next_question($gameId,'participant',$uid);
            if(empty($opened['ok'])) return $opened;
            return ['ok'=>true,'recovered'=>true,'opened'=>$opened];
        }
        $selected=ckm_quiz_jeopardy_select_cell($gameId,$teamId,$questionId,$auth);
        if(empty($selected['ok'])) return $selected;
        $q=isset($selected['question']) && is_array($selected['question']) ? $selected['question'] : ckm_quiz_get_question($questionId);
        if($q && function_exists('ckm_quiz_jeopardy_is_cat_question') && ckm_quiz_jeopardy_is_cat_question($q)) {
            return ['ok'=>true,'selection'=>$selected,'needsTarget'=>true];
        }
        $opened=ckm_quiz_open_next_question($gameId,'participant',$uid);
        if(empty($opened['ok'])) return $opened;
        return ['ok'=>true,'selection'=>$selected,'opened'=>$opened];
    }
    if($command==='assign_cat'){
        $target=absint($input['target_team_id']??0);
        $assigned=ckm_quiz_jeopardy_assign_cat_target($gameId,$target,$auth);
        if(empty($assigned['ok'])) return $assigned;
        $opened=ckm_quiz_open_next_question($gameId,'participant',$uid);
        if(empty($opened['ok'])) return $opened;
        return ['ok'=>true,'cat'=>$assigned,'opened'=>$opened];
    }
    return ckm_quiz_error(400,'Неизвестное действие «Интеллектуального батла».','jeopardy_action_unknown');
}

function ckm_quiz_pro_jeopardy_host_action(array $game,string $command,array $input,int $uid): array {
    $gameId=(int)$game['id']; $auth=['role'=>'host','actor_type'=>'host','game_id'=>$gameId,'team_id'=>0,'user_id'=>$uid];
    if($command==='jeopardy_select'){
        $teamId=absint($input['selector_team_id']??($game['jeopardy_selector_team_id']??0)); $qid=absint($input['question_id']??0);
        $fresh=ckm_quiz_get_game($gameId) ?: $game;
        $pendingId=(int)($fresh['jeopardy_selected_question_id']??0);
        if($pendingId>0 && (string)($fresh['quiz_phase']??'')!=='question_open'){
            if($pendingId!==$qid) return ckm_quiz_error(409,'Другая ячейка уже выбрана и ожидает запуска.','jeopardy_cell_already_selected');
            return ckm_quiz_open_next_question($gameId,'host',$uid);
        }
        $r=ckm_quiz_jeopardy_select_cell($gameId,$teamId,$qid,$auth); if(empty($r['ok'])) return $r;
        $q=ckm_quiz_get_question($qid); if($q && ckm_quiz_jeopardy_is_cat_question($q)) return $r+['needsTarget'=>true];
        return ckm_quiz_open_next_question($gameId,'host',$uid);
    }
    if($command==='jeopardy_set_selector') return ckm_quiz_jeopardy_set_selector($gameId,absint($input['team_id']??0),$auth);
    if($command==='jeopardy_assign_cat'){
        $r=ckm_quiz_jeopardy_assign_cat_target($gameId,absint($input['target_team_id']??0),$auth); if(empty($r['ok'])) return $r;
        return ckm_quiz_open_next_question($gameId,'host',$uid);
    }
    if($command==='jeopardy_resolve_buzz') return ckm_quiz_jeopardy_resolve_buzzer($gameId,sanitize_key((string)($input['decision']??'')),$auth);
    if($command==='jeopardy_resolve_cat') return ckm_quiz_jeopardy_resolve_cat($gameId,sanitize_key((string)($input['decision']??'')),$auth);
    if($command==='jeopardy_close_question') {
        $fresh=ckm_quiz_get_game($gameId) ?: $game;
        if((string)($fresh['quiz_phase']??'')!=='question_open') return ['ok'=>true,'alreadyClosed'=>true];
        global $wpdb;
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".ckm_quiz_answers_table()." WHERE game_id=%d AND question_id=%d AND verdict='pending'",$gameId,(int)($fresh['current_question_id']??0)));
        if($pending>0) return ckm_quiz_error(409,'Сначала примите или отклоните сохранённый ответ.','jeopardy_answer_pending');
        return ckm_quiz_close_current_question($gameId,'host',$uid);
    }
    if($command==='jeopardy_start_final') return ckm_quiz_jeopardy_start_final($gameId,$auth);
    if($command==='jeopardy_close_final') return ckm_quiz_jeopardy_close_final_submission($gameId,'host',$uid);
    if($command==='jeopardy_reveal_final') return ckm_quiz_jeopardy_reveal_final($gameId,$auth);
    if($command==='jeopardy_resolve_final') return ckm_quiz_jeopardy_resolve_final_team($gameId,absint($input['team_id']??0),sanitize_key((string)($input['decision']??'')),$auth);
    if($command==='jeopardy_finish_final') return ckm_quiz_jeopardy_finish_final($gameId,$auth);
    if($command==='jeopardy_finish_game') return ckm_quiz_finish_game($gameId,'host',$uid,true);
    return ckm_quiz_error(400,'Неизвестная команда ведущего.','jeopardy_host_action_unknown');
}

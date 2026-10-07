<?php
if (!defined('ABSPATH')) exit;

function ckm_quiz_pro_admin_menu(): void {
    add_menu_page('Игровая платформа','Игровая платформа','manage_options','ckm-quiz-pro','ckm_quiz_pro_dashboard','dashicons-games',58);
    add_submenu_page('ckm-quiz-pro','Игровые пакеты','Игровые пакеты','manage_options','ckm-quiz-pro-my-games','ckm_quiz_pro_my_games_page');
    add_submenu_page('ckm-quiz-pro','Технические шаблоны','Технические шаблоны','manage_options','ckm-quiz-pro-quizzes','ckm_quiz_pro_quizzes_page');
    add_submenu_page('ckm-quiz-pro','Новый шаблон','Новый шаблон','manage_options','ckm-quiz-pro-edit','ckm_quiz_pro_edit_page');
    add_submenu_page('ckm-quiz-pro','Тестовый запуск','Тестовый запуск','manage_options','ckm-quiz-pro-games','ckm_quiz_pro_games_page');
}
add_action('admin_menu','ckm_quiz_pro_admin_menu');

function ckm_quiz_pro_dashboard(): void {
    if(!current_user_can('manage_options')) return;
    global $wpdb;
    $q=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('quizzes'));
    $g=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('games'));
    echo '<div class="wrap"><h1>Игровая платформа</h1><p><strong>Единая архитектура:</strong> Деловые игры · Интеллектуальные игры · Создать свою игру. Ниже — техническое управление шаблонами и тестовыми запусками.</p>';
    $readyCount=post_type_exists('ckm_ready_game') ? (int)(wp_count_posts('ckm_ready_game')->publish ?? 0) : 0;
    $scenarioOrderCount=(function_exists('ckm_quiz_pro_scenario_orders_table_ready') && ckm_quiz_pro_scenario_orders_table_ready()) ? (int)$wpdb->get_var("SELECT COUNT(*) FROM ".ckm_quiz_pro_table('scenario_orders')." WHERE status IN ('new','in_work','ready')") : 0;
    echo '<div style="display:flex;gap:16px;flex-wrap:wrap"><div class="card"><h2>Технические шаблоны</h2><p style="font-size:32px;margin:0">'.$q.'</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-quizzes')).'">Открыть</a></p></div>';
    echo '<div class="card"><h2>Каталог готовых игр</h2><p style="font-size:32px;margin:0">'.$readyCount.'</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-ready-games')).'">Добавлять и редактировать</a></p></div>';
    echo '<div class="card"><h2>Заказы сюжетов</h2><p style="font-size:32px;margin:0">'.$scenarioOrderCount.'</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-scenario-orders')).'">Открыть очередь</a></p></div>';
    echo '<div class="card"><h2>Игровые сессии</h2><p style="font-size:32px;margin:0">'.$g.'</p><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-games')).'">Открыть</a></p></div></div>';
    $ls=ckm_quiz_pro_license_state(); $lp=(array)($ls['payload']??[]);
    echo '<p>Облачный сервис: <strong>'.esc_html($lp?((string)($lp['status']??'unknown')):'не подключён').'</strong>. <a href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-license')).'">Лицензия и Ed25519</a>.</p></div>';
}

function ckm_quiz_pro_quizzes_page(): void {
    if(!current_user_can('manage_options')) return;
    global $wpdb;
    $rows=$wpdb->get_results('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' ORDER BY id DESC',ARRAY_A) ?: [];
    echo '<div class="wrap"><h1 class="wp-heading-inline">Технические шаблоны</h1> <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit')).'">Создать игру</a><hr class="wp-header-end">';
    echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Название</th><th>Статус</th><th>Вопросов</th><th>Действия</th></tr></thead><tbody>';
    foreach($rows as $r){
        $cnt=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d AND status=\'active\'',(int)$r['id'],(int)$r['current_revision']));
        $readonly=function_exists('ckm_quiz_pro_package_is_readonly_quiz') && ckm_quiz_pro_package_is_readonly_quiz((int)$r['id']);
        $actions=[];
        if(!$readonly) $actions[]='<a class="button" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit&quiz='.(int)$r['id'])).'">Редактировать</a>';
        else $actions[]='<span style="color:#646970">Управляется пакетом</span>';
        $export=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_export_package&quiz_id='.(int)$r['id']),'ckm_quiz_pro_export_package_'.(int)$r['id']);
        $actions[]='<a class="button" href="'.esc_url($export).'">Экспорт .ckmgame</a>';
        echo '<tr><td>'.(int)$r['id'].'</td><td><strong>'.esc_html($r['title']).'</strong><br><code>'.esc_html($r['slug']).'</code></td><td>'.esc_html($r['status']).'</td><td>'.$cnt.'</td><td>'.implode(' ',$actions).'</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="5">Игр пока нет.</td></tr>';
    echo '</tbody></table></div>';
}

function ckm_quiz_pro_save_quiz_from_post(): int {
    if(!function_exists('ckm_quiz_pro_can_edit_quizzes') || !ckm_quiz_pro_can_edit_quizzes()) wp_die('Недостаточно прав.');
    check_admin_referer('ckm_quiz_pro_save_quiz');
    global $wpdb;
    $id=absint($_POST['quiz_id']??0);
    if($id>0 && function_exists('ckm_quiz_pro_package_is_readonly_quiz') && ckm_quiz_pro_package_is_readonly_quiz($id)) wp_die('Эта игра управляется игровой пакет и не редактируется вручную.');
    $old=null;
    if($id>0){
        $old=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d',$id),ARRAY_A);
        if(!$old) wp_die('Игра не найдена.');
    }
    if (!ckmqp_content_ready()) wp_die('Обновление игровых шаблонов не завершено.');
    $technical=is_admin() && current_user_can('manage_options') && ckmqp_scope_id()===0;
    if (!$technical) {
        $editorId=ckm_quiz_pro_effective_organizer_user_id();
        if (!ckmqp_scope_user_allowed($editorId)) wp_die('Нет доступа к редактированию этой игры.','',['response'=>403]);
        if ($old && (!function_exists('ckm_quiz_pro_can_edit_owned_quiz') || !ckm_quiz_pro_can_edit_owned_quiz($old,$editorId))) wp_die('Можно редактировать только собственную игру, созданную в этой площадке.','',['response'=>403]);
    }
    $postedFormat=sanitize_key((string)($_POST['format_key']??''));
    if($postedFormat==='persuade_me_v1') $postedFormat='negotiation_duel';
    $formatKey=$old ? sanitize_key((string)$old['format_key']) : $postedFormat;
    if(!in_array($formatKey,['classic_quiz','chgk','jeopardy','solution_price','negotiation_duel'],true)) $formatKey='classic_quiz';
    $postedNegotiationMode=$formatKey==='negotiation_duel'?ckm_quiz_pro_negotiation_mode((string)($_POST['negotiation_mode']??(($old?json_decode((string)($old['format_settings_json']??''),true):[])['negotiationMode']??'sales'))):'';
    $isPersuadeMe=$formatKey==='negotiation_duel' && $postedNegotiationMode==='communicate';
    if (!$technical && !ckm_quiz_pro_constructor_enabled_for_current_user()) wp_die('Для создания игры нужен хотя бы один активный оплаченный доступ.', '', ['response'=>403]);
    $title=sanitize_text_field(wp_unslash($_POST['title']??''));
    if($title==='' && $formatKey==='solution_price') $title='Новая игра «Управленческая игра "Ваш выбор"»';
    if($title==='') $title=$formatKey==='chgk'?'Новая Битва знатоков':($formatKey==='jeopardy'?'Новый Интеллектуальный батл':($formatKey==='negotiation_duel'?($isPersuadeMe?'Новая игра «Переговори другого»':'Новый Переговорный поединок'):'Новый квиз'));
    $slug=sanitize_title(wp_unslash($_POST['slug']??'')); if($slug==='') $slug=sanitize_title($title);
    if (!$old && !$technical) $slug='t'.ckmqp_scope_id().'-u'.get_current_user_id().'-'.wp_generate_uuid4();
    $status=in_array(($_POST['status']??'draft'),['draft','published'],true)?$_POST['status']:'draft';
    $oldFormatSettings=$old ? (json_decode((string)($old['format_settings_json']??''),true) ?: []) : [];
    $finalAnswerSeconds=20;
    if($formatKey==='chgk'){
        $fallbackDiscussion=(int)($oldFormatSettings['discussionSeconds']??($old['seconds_per_question']??60));
        $discussionPreset=sanitize_text_field(wp_unslash($_POST['chgk_discussion_preset']??''));
        if($discussionPreset==='custom') $discussionRaw=(int)($_POST['chgk_discussion_custom']??$fallbackDiscussion);
        elseif(ctype_digit($discussionPreset)) $discussionRaw=(int)$discussionPreset;
        else $discussionRaw=(int)($_POST['seconds_per_question']??$fallbackDiscussion);
        $seconds=max(60,min(300,$discussionRaw));
        $fallbackEarly=(int)($oldFormatSettings['earlyAnswerSeconds']??5);
        if($fallbackEarly<5) $fallbackEarly=5;
        $earlyPreset=sanitize_text_field(wp_unslash($_POST['chgk_early_preset']??''));
        if($earlyPreset==='custom') $earlyRaw=(int)($_POST['chgk_early_custom']??$fallbackEarly);
        elseif(ctype_digit($earlyPreset)) $earlyRaw=(int)$earlyPreset;
        else $earlyRaw=$fallbackEarly;
        $earlyAnswerSeconds=$earlyRaw<5?5:max(5,min(60,$earlyRaw));
        $fallbackFinal=20;
        $finalPreset=sanitize_text_field(wp_unslash($_POST['chgk_final_preset']??''));
        if($finalPreset==='custom') $finalRaw=(int)($_POST['chgk_final_custom']??$fallbackFinal);
        elseif(ctype_digit($finalPreset)) $finalRaw=(int)$finalPreset;
        else $finalRaw=$fallbackFinal;
        $finalAnswerSeconds=20;
    } else {
        $minSeconds=$formatKey==='jeopardy'?15:(in_array($formatKey,['negotiation_duel','solution_price'],true)?30:5);
        $maxSeconds=600;
        $defaultSeconds=$formatKey==='jeopardy'?90:($formatKey==='negotiation_duel'?120:($formatKey==='solution_price'?60:30));
        $seconds=max($minSeconds,min($maxSeconds,(int)($_POST['seconds_per_question']??$defaultSeconds)));
    }
    $hostMode=sanitize_key((string)($_POST['host_mode']??($old['host_mode']??'ai')));
    if(!in_array($hostMode,['ai','human'],true)) $hostMode='ai';
    $formatSettings=$formatKey==='chgk' ? ckm_quiz_pro_chgk_settings($seconds,$finalAnswerSeconds,(int)($earlyAnswerSeconds??5)) : ($formatKey==='jeopardy' ? ckm_quiz_pro_jeopardy_post_settings($seconds,$oldFormatSettings) : ($formatKey==='negotiation_duel' ? ckm_quiz_pro_negotiation_settings($postedNegotiationMode,$seconds,$oldFormatSettings) : []));
    $educationMode=sanitize_key((string)($_POST['education_mode']??($oldFormatSettings['educationMode']??'')));
    if(in_array($educationMode,['classic','battle','learning'],true)){
        $educationSubject=sanitize_key((string)($_POST['education_subject']??($oldFormatSettings['educationSubject']??'')));
        $educationGrade=max(0,min(11,(int)($_POST['education_grade']??($oldFormatSettings['educationGrade']??0))));
        $educationTheme=sanitize_text_field(wp_unslash((string)($_POST['education_theme']??($oldFormatSettings['educationTheme']??''))));
        $formatSettings['educationMode']=$educationMode;
        $formatSettings['educationSubject']=$educationSubject;
        $formatSettings['educationGrade']=$educationGrade;
        $formatSettings['educationTheme']=$educationTheme;
    }

    if($isPersuadeMe){
        // The first two dialogue rounds keep 30-second preparation; final story preparation is untimed.
        // Keep the legacy base value for compatibility with the shared negotiation schema; the show runtime uses its own dialogue limit fields.
        $seconds=30;
        $formatSettings=ckm_quiz_pro_negotiation_settings('communicate',$seconds,$oldFormatSettings);
        $limitEnabled=isset($_POST['persuade_dialogue_limit_enabled']) && (string)$_POST['persuade_dialogue_limit_enabled']==='1';
        $fallbackLimit=(int)($oldFormatSettings['persuadeDialogueLimitSeconds']??180);
        $limitSeconds=max(60,min(600,(int)($_POST['persuade_dialogue_limit_seconds']??$fallbackLimit)));
        $formatSettings['persuadeDialogueLimitEnabled']=$limitEnabled;
        $formatSettings['persuadeDialogueLimitSeconds']=$limitSeconds;
        $storyLimitEnabled=isset($_POST['persuade_story_limit_enabled']) && (string)$_POST['persuade_story_limit_enabled']==='1';
        $fallbackStoryLimit=(int)($oldFormatSettings['persuadeStoryLimitSeconds']??90);
        $storyLimitSeconds=max(60,min(120,(int)($_POST['persuade_story_limit_seconds']??$fallbackStoryLimit)));
        $formatSettings['persuadeStoryLimitEnabled']=$storyLimitEnabled;
        $formatSettings['persuadeStoryLimitSeconds']=$storyLimitSeconds;
        unset($formatSettings['persuadeHardAnswerSeconds']); // Раунд «Неудобный вопрос» больше не использует таймер.
        $storyAnswerLimitEnabled=isset($_POST['persuade_story_answer_limit_enabled']) && (string)$_POST['persuade_story_answer_limit_enabled']==='1';
        $storyAnswerSeconds=(int)($_POST['persuade_story_answer_seconds']??($oldFormatSettings['persuadeStoryAnswerSeconds']??30));
        $formatSettings['persuadeStoryAnswerLimitEnabled']=$storyAnswerLimitEnabled;
        $formatSettings['persuadeStoryAnswerSeconds']=in_array($storyAnswerSeconds,[20,30,60],true)?$storyAnswerSeconds:30;
        $fallbackHiddenMessages=(int)($oldFormatSettings['persuadeHiddenMessageLimit']??7);
        $formatSettings['persuadeHiddenMessageLimit']=max(3,min(7,(int)($_POST['persuade_hidden_message_limit']??$fallbackHiddenMessages)));
        $fallbackContent=$oldFormatSettings['persuadeMeContent']??ckm_quiz_pro_persuade_me_default_content();
        $formatSettings['persuadeMeContent']=ckm_quiz_pro_persuade_me_content_from_post($_POST['persuade_me']??null,$fallbackContent);
    }
    $solutionRows=[];
    if($formatKey==='solution_price') {
        $formatSettings=ckm_quiz_pro_solution_price_settings($seconds);
        $solutionRows=ckm_quiz_pro_solution_price_builder_rows($_POST['questions']??[]);
        if(is_wp_error($solutionRows)) wp_die($solutionRows->get_error_message(), '', ['response'=>400, 'back_link'=>true]);
        if($wpdb->query('START TRANSACTION')===false) wp_die('Не удалось начать сохранение игры.');
    }
    $now=current_time('mysql');
    $revision=1;
    if($id>0){
        $revision=max(1,(int)$old['current_revision'])+1;
        $saved=$wpdb->update(ckm_quiz_pro_table('quizzes'),[
            'title'=>$title,'slug'=>$slug,'status'=>$status,'current_revision'=>$revision,'min_teams'=>$isPersuadeMe?2:(in_array($formatKey,['chgk','solution_price'],true)?1:(int)($old['min_teams']??2)),'max_teams'=>$isPersuadeMe?2:($formatKey==='chgk'?1:(int)($old['max_teams']??10)),'host_mode'=>$hostMode,'judge_mode'=>'ai','seconds_per_question'=>$seconds,
            'format_settings_json'=>wp_json_encode($formatSettings,JSON_UNESCAPED_UNICODE),'updated_by_user_id'=>get_current_user_id(),'updated_at'=>$now,'published_at'=>$status==='published'?$now:null
        ],['id'=>$id]);
    } else {
        $saved=$wpdb->insert(ckm_quiz_pro_table('quizzes'),[
            'tenant_id'=>ckmqp_scope_id(),'content_scope'=>$technical?'shared':'private',
            'title'=>$title,'slug'=>$slug,'format_key'=>$formatKey,'short_description'=>'','instructions'=>'','cover_url'=>'','status'=>$status,'current_revision'=>1,'min_teams'=>$isPersuadeMe?2:(in_array($formatKey,['chgk','negotiation_duel','solution_price'],true)?1:2),'max_teams'=>$isPersuadeMe?2:($formatKey==='chgk'?1:10),'host_mode'=>$hostMode,'judge_mode'=>'ai','seconds_per_question'=>$seconds,
            'scoring_policy_json'=>'{}','settings_json'=>'{}','format_settings_json'=>wp_json_encode($formatSettings,JSON_UNESCAPED_UNICODE),'created_by_user_id'=>get_current_user_id(),'updated_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'published_at'=>$status==='published'?$now:null
        ]);
        $id=(int)$wpdb->insert_id;
    }
    if ($saved===false || $id<=0) {
        if($formatKey==='solution_price') $wpdb->query('ROLLBACK');
        wp_die('Не удалось сохранить шаблон игры.');
    }
    if($formatKey==='jeopardy'){
        ckm_quiz_pro_jeopardy_save_questions($id,$revision,$seconds,$formatSettings,$now,get_current_user_id());
        return $id;
    }
    if($formatKey==='solution_price') {
        ckm_quiz_pro_solution_price_save_builder_rows($id,$revision,$seconds,$solutionRows,$now);
        if($wpdb->query('COMMIT')===false) { $wpdb->query('ROLLBACK'); wp_die('Не удалось завершить сохранение игры.'); }
        return $id;
    }
    $wpdb->insert(ckm_quiz_pro_table('rounds'),['quiz_id'=>$id,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,'title'=>($formatKey==='negotiation_duel'?($isPersuadeMe?'Переговори другого':'Переговоры'):'Основной раунд'),'round_type'=>($formatKey==='negotiation_duel'?'negotiation':'questions'),'rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
    $roundId=(int)$wpdb->insert_id;
    if($isPersuadeMe){
        // The show runtime does not consume generic quiz questions, but the shared room engine requires
        // at least one active question linked to a round. Store one non-user-facing contract marker.
        $wpdb->insert(ckm_quiz_pro_table('questions'),[
            'quiz_id'=>$id,'quiz_revision'=>$revision,'question_key'=>'persuade-me-contract','position'=>1,'round_no'=>1,'round_title'=>'Переговори другого','round_id'=>$roundId,
            'question_stage'=>'main','question_type'=>'text','question_text'=>'Переговори другого — четырёхраундовая механика',
            'options_json'=>'[]','correct_answers_json'=>'[]','numeric_tolerance'=>0,'points'=>1,'time_limit_seconds'=>90,
            'scoring_rule_json'=>wp_json_encode(['runtime'=>'persuade_me_v1','contentInFormatSettings'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'explanation'=>'Технический маркер общей игровой механики. Игрокам не показывается.','host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
        return $id;
    }
    $questions=$_POST['questions']??[];
    if(!is_array($questions)) $questions=[];
    $pos=0;
    foreach($questions as $row){
        if(!is_array($row)) continue;
        $text=trim(sanitize_textarea_field(wp_unslash($row['text']??'')));
        if($text==='') continue;
        $pos++;
        $points=max(1,min(100,(int)($row['points']??1)));
        if($formatKey==='chgk'){
            $points=1;
            $reference=trim(sanitize_text_field(wp_unslash($row['reference']??'')));
            if($reference==='') continue;
            $variantsRaw=sanitize_textarea_field(wp_unslash($row['variants']??''));
            $variants=preg_split('/[
|]+/u',$variantsRaw) ?: [];
            $variants=array_values(array_filter(array_map('trim',$variants),static fn($v)=>$v!==''));
            $answers=array_values(array_unique(array_merge([$reference],$variants)));
            $wpdb->insert(ckm_quiz_pro_table('questions'),[
                'quiz_id'=>$id,'quiz_revision'=>$revision,'question_key'=>'q-'.$pos,'position'=>$pos,'round_no'=>1,'round_title'=>'Основной раунд','round_id'=>$roundId,'question_stage'=>'main','question_type'=>'text','question_text'=>$text,
                'options_json'=>'[]','correct_answers_json'=>wp_json_encode($answers,JSON_UNESCAPED_UNICODE),'numeric_tolerance'=>0,'points'=>$points,'time_limit_seconds'=>$seconds,
                'scoring_rule_json'=>wp_json_encode(['acceptedVariants'=>$variants],JSON_UNESCAPED_UNICODE),'explanation'=>sanitize_textarea_field(wp_unslash($row['explanation']??'')),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
            ]);
        } elseif($formatKey==='negotiation_duel'){
            $criteriaRaw=sanitize_textarea_field(wp_unslash($row['criteria']??''));
            $criteria=preg_split('/[\r\n|]+/u',$criteriaRaw) ?: [];
            $criteria=array_values(array_filter(array_map('trim',$criteria),static fn($v)=>$v!==''));
            $mode=ckm_quiz_pro_negotiation_mode((string)($formatSettings['negotiationMode']??'sales'));
            $wpdb->insert(ckm_quiz_pro_table('questions'),[
                'quiz_id'=>$id,'quiz_revision'=>$revision,'question_key'=>'q-'.$pos,'position'=>$pos,'round_no'=>1,'round_title'=>ckm_quiz_pro_negotiation_mode_title($mode),'round_id'=>$roundId,'question_stage'=>'main','question_type'=>'text','question_text'=>$text,
                'options_json'=>'[]','correct_answers_json'=>'[]','numeric_tolerance'=>0,'points'=>$points,'time_limit_seconds'=>$seconds,
                'scoring_rule_json'=>wp_json_encode(['judgeCriteria'=>$criteria,'negotiationMode'=>$mode,'manualOrAiReview'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'explanation'=>sanitize_textarea_field(wp_unslash($row['explanation']??'')),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
            ]);
        } else {
            $options=[];
            foreach(['A','B','C','D'] as $letter){
                $label=sanitize_text_field(wp_unslash($row['option_'.$letter]??''));
                if($label!=='') $options[]=['value'=>$letter,'label'=>$label];
            }
            $correct=strtoupper(sanitize_key($row['correct']??'A')); if(!in_array($correct,['A','B','C','D'],true)) $correct='A';
            $time=max(5,min(600,(int)($row['time']??$seconds)));
            $wpdb->insert(ckm_quiz_pro_table('questions'),[
                'quiz_id'=>$id,'quiz_revision'=>$revision,'question_key'=>'q-'.$pos,'position'=>$pos,'round_no'=>1,'round_title'=>'Основной раунд','round_id'=>$roundId,'question_stage'=>'main','question_type'=>'single_choice','question_text'=>$text,
                'options_json'=>wp_json_encode($options,JSON_UNESCAPED_UNICODE),'correct_answers_json'=>wp_json_encode([$correct],JSON_UNESCAPED_UNICODE),'numeric_tolerance'=>0,'points'=>$points,'time_limit_seconds'=>$time,'scoring_rule_json'=>'{}','explanation'=>sanitize_textarea_field(wp_unslash($row['explanation']??'')),'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
            ]);
        }
    }
    return $id;
}

function ckm_quiz_pro_edit_page(): void {
    if(!current_user_can('manage_options')) return;
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_save_quiz'])){
        $id=ckm_quiz_pro_save_quiz_from_post();
        do_action('ckm_quiz_pro_quiz_saved',$id);
        wp_safe_redirect(admin_url('admin.php?page=ckm-quiz-pro-edit&quiz='.$id.'&saved=1')); exit;
    }
    global $wpdb;
    $id=absint($_GET['quiz']??0); $quiz=null; $questions=[];
    if($id && function_exists('ckm_quiz_pro_package_is_readonly_quiz') && ckm_quiz_pro_package_is_readonly_quiz($id)){ echo '<div class="wrap"><h1>Игра управляется пакетом</h1><div class="notice notice-info"><p>Эта игра установлена как управляемый игровой пакет. Обновляйте её через «Мои игры», чтобы не нарушать версионность.</p></div><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-my-games')).'">Мои игры</a></p></div>'; return; }
    if($id){
        $quiz=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d',$id),ARRAY_A);
        if($quiz) $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d AND status=\'active\' ORDER BY position',(int)$quiz['id'],(int)$quiz['current_revision']),ARRAY_A) ?: [];
    }
    $requestedChoice=sanitize_key((string)($_GET['format']??'classic_quiz'));
    $requested=$requestedChoice==='persuade_me_v1'?'negotiation_duel':$requestedChoice;
    if(!in_array($requested,['classic_quiz','chgk','jeopardy','solution_price','negotiation_duel'],true)) {$requested='classic_quiz';$requestedChoice='classic_quiz';}
    if(!$quiz) $quiz=['id'=>0,'title'=>'','slug'=>'','status'=>'published','seconds_per_question'=>$requested==='chgk'?60:($requested==='jeopardy'?90:($requested==='negotiation_duel'?($requestedChoice==='persuade_me_v1'?30:120):($requested==='solution_price'?60:30))),'format_key'=>$requested,'host_mode'=>'ai','format_settings_json'=>$requestedChoice==='persuade_me_v1'?wp_json_encode(['negotiationMode'=>'communicate'],JSON_UNESCAPED_UNICODE):''];
    $formatKey=sanitize_key((string)($quiz['format_key']??'classic_quiz'));
    $isPersuadeBuilder=$formatKey==='negotiation_duel' && function_exists('ckm_quiz_pro_quiz_is_persuade_me') && ckm_quiz_pro_quiz_is_persuade_me($quiz);
    if($formatKey==='solution_price') {
        ckm_quiz_pro_solution_price_admin_builder($quiz,$questions);
        return;
    }

    echo '<div class="wrap"><h1>'.($id?'Редактировать игру':'Создать игру').'</h1>';
    if(isset($_GET['saved'])) echo '<div class="notice notice-success"><p>Игра сохранена новой редакцией.</p></div>';
    echo '<form method="post">'; wp_nonce_field('ckm_quiz_pro_save_quiz');
    echo '<input type="hidden" name="quiz_id" value="'.(int)$quiz['id'].'">';
    if($id) echo '<input type="hidden" name="format_key" value="'.esc_attr($formatKey).'"><p><strong>Формат:</strong> '.esc_html(function_exists('ckm_quiz_pro_quiz_format_title')?ckm_quiz_pro_quiz_format_title($quiz):ckm_quiz_pro_format_title($formatKey)).'</p>';
    else echo '<table class="form-table"><tr><th>Формат</th><td><select name="format_key" id="ckm-qp-format"><option value="classic_quiz" '.selected($formatKey,'classic_quiz',false).'>Классический квиз</option><option value="chgk" '.selected($formatKey,'chgk',false).'>Битва знатоков</option><option value="jeopardy" '.selected($formatKey,'jeopardy',false).'>Интеллектуальный батл</option><option value="negotiation_duel" '.selected($formatKey==='negotiation_duel' && (!function_exists('ckm_quiz_pro_quiz_is_persuade_me')||!ckm_quiz_pro_quiz_is_persuade_me($quiz)),true,false).'>Переговорный поединок</option><option value="persuade_me_v1" '.selected(function_exists('ckm_quiz_pro_quiz_is_persuade_me')&&ckm_quiz_pro_quiz_is_persuade_me($quiz),true,false).'>Переговори другого</option></select><p class="description">После первого сохранения формат фиксируется.</p></td></tr></table>';
    $timeLabel=$formatKey==='negotiation_duel'?'Время на реплику':'Время на вопрос';
    $min=$formatKey==='jeopardy'?15:(in_array($formatKey,['negotiation_duel','solution_price'],true)?30:5); $max=600;
    echo '<table class="form-table"><tr><th>Название</th><td><input class="regular-text" name="title" value="'.esc_attr($quiz['title']).'" required></td></tr><tr><th>Slug</th><td><input class="regular-text" name="slug" value="'.esc_attr($quiz['slug']).'"></td></tr><tr><th>Статус</th><td><select name="status"><option value="published" '.selected($quiz['status'],'published',false).'>Опубликован</option><option value="draft" '.selected($quiz['status'],'draft',false).'>Черновик</option></select></td></tr><tr><th>Ведущий</th><td><select name="host_mode"><option value="ai" '.selected((string)($quiz['host_mode']??'ai'),'ai',false).'>ИИ-ведущий</option><option value="human" '.selected((string)($quiz['host_mode']??'ai'),'human',false).'>Голосовой ведущий</option></select><p class="description">В режиме ИИ-ведущего голос Сергея подключается автоматически, а первый вопрос задаётся без кнопки «Задать вопрос». В режиме голосового ведущего человек подключает микрофон, говорит командам и управляет ходом игры из панели.</p></td></tr>';
    if($formatKey==='chgk'){
        $timers=ckm_quiz_pro_chgk_timer_values($quiz);
        echo '<tr><th>Время на кнопку «Досрочный ответ»</th><td>'.ckm_quiz_pro_chgk_timer_control('chgk_early_preset','chgk_early_custom',(int)($timers['early']??5),[5,10,15,20,30,45,60],5,60).'<p class="description">Сколько секунд команда видит кнопку «Досрочный ответ» до начала обсуждения. Это можно менять и в панели ведущего.</p></td></tr>';
        echo '<tr><th>Время обсуждения</th><td>'.ckm_quiz_pro_chgk_timer_control('chgk_discussion_preset','chgk_discussion_custom',(int)$timers['discussion'],[60,90,120,180,300],60,300).'<p class="description">Общее время, когда команда обсуждает вопрос. Финальный ответ в этой фазе недоступен.</p></td></tr>';
        echo '<tr><th>Время на окончательный ответ</th><td><strong>20 секунд</strong><input type="hidden" name="chgk_final_preset" value="20"><p class="description">Короткое окно фиксации ответа после обсуждения или досрочного ответа.</p></td></tr>';
    } elseif($isPersuadeBuilder) {
        $persuadeSettings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
        $dialogueLimitEnabled=!empty($persuadeSettings['persuadeDialogueLimitEnabled']);
        $dialogueLimitSeconds=max(60,min(600,(int)($persuadeSettings['persuadeDialogueLimitSeconds']??180)));
        $hiddenMessageLimit=max(3,min(7,(int)($persuadeSettings['persuadeHiddenMessageLimit']??7)));
        $storyLimitEnabled=!empty($persuadeSettings['persuadeStoryLimitEnabled']);
        $storyLimitSeconds=max(60,min(120,(int)($persuadeSettings['persuadeStoryLimitSeconds']??90)));
        $storyAnswerLimitEnabled=!empty($persuadeSettings['persuadeStoryAnswerLimitEnabled']);
        $storyAnswerSeconds=(int)($persuadeSettings['persuadeStoryAnswerSeconds']??30);if(!in_array($storyAnswerSeconds,[20,30,60],true))$storyAnswerSeconds=30;
        echo '<input type="hidden" name="seconds_per_question" value="30">';
        echo '<tr><th>Диалог</th><td><label><input type="checkbox" name="persuade_dialogue_limit_enabled" value="1" '.checked($dialogueLimitEnabled,true,false).'> Ограничивать время переговоров</label><p class="description">По умолчанию подготовка не отсчитывается: после стартовой готовности диалог открыт сразу. Ограничение времени выключено, пока организатор его не включит. Ведущий-человек завершает его кнопкой «Завершить диалог», ИИ-ведущий — автоматически по ходу разговора.</p></td></tr>';
        echo '<tr><th>Лимит диалога</th><td><input type="number" min="60" max="600" name="persuade_dialogue_limit_seconds" value="'.(int)$dialogueLimitSeconds.'"> сек.<p class="description">Используется только при включённом ограничении переговоров.</p></td></tr>';
        echo '<tr><th>Финальный рассказ</th><td><label><input type="checkbox" name="persuade_story_limit_enabled" value="1" '.checked($storyLimitEnabled,true,false).'> Ограничивать время рассказа</label><p class="description">По умолчанию ограничение времени для подготовки и рассказа в раунде «Проверь историю» выключено. Рассказчик сам нажимает «Готов рассказать», затем «Зафиксировать рассказ».</p></td></tr>';
        echo '<tr><th>Лимит рассказа</th><td><input type="number" min="60" max="120" step="30" name="persuade_story_limit_seconds" value="'.(int)$storyLimitSeconds.'"> сек.<p class="description">Используется только если включено ограничение рассказа. Допустимо 60, 90 или 120 секунд.</p></td></tr>';
        echo '<tr><th>Ответ рассказчика</th><td><label><input type="checkbox" name="persuade_story_answer_limit_enabled" value="1" '.checked($storyAnswerLimitEnabled,true,false).'> Ограничивать время ответа</label><p class="description">По умолчанию ограничение времени для ответов рассказчика выключено.</p><select name="persuade_story_answer_seconds"><option value="20" '.selected($storyAnswerSeconds,20,false).'>20 секунд</option><option value="30" '.selected($storyAnswerSeconds,30,false).'>30 секунд</option><option value="60" '.selected($storyAnswerSeconds,60,false).'>60 секунд</option></select><p class="description">Используется только при включённом ограничении. 20–30 секунд подходят для голосового режима; для текстового режима при необходимости используйте 60 секунд.</p></td></tr>';
        echo '<tr><th>Максимум реплик</th><td><input type="number" min="3" max="7" name="persuade_hidden_message_limit" value="'.(int)$hiddenMessageLimit.'"><p class="description">Раунд «Скрытая задача»: по умолчанию максимум 7 реплик на испытание. Активная команда может отправить до 3 реплик, каждый собеседник — до 2. После общего лимита диалог автоматически передаётся на оценку.</p></td></tr>';
    } else {
        echo '<tr><th>'.$timeLabel.'</th><td><input type="number" min="'.$min.'" max="'.$max.'" name="seconds_per_question" value="'.(int)$quiz['seconds_per_question'].'"> сек.</td></tr>';
    }
    echo '</table>';
    if(!$id) echo '<p class="description">Для смены формы полей до первого сохранения выберите формат и нажмите «Обновить форму».</p><p><a class="button" id="ckm-qp-format-refresh" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit&format='.$requestedChoice)).'">Обновить форму</a></p>';
    if($formatKey==='negotiation_duel'){
        $negSettings=json_decode((string)($quiz['format_settings_json']??''),true) ?: [];
        $negMode=ckm_quiz_pro_negotiation_mode((string)($negSettings['negotiationMode']??'sales'));
        echo '<table class="form-table"><tr><th>Режим переговоров</th><td><select name="negotiation_mode"><option value="sales" '.selected($negMode,'sales',false).'>Эффективный продажник</option><option value="business" '.selected($negMode,'business',false).'>Мастер переговоров</option><option value="express" '.selected($negMode,'express',false).'>Переговорный раунд</option><option value="communicate" '.selected($negMode,'communicate',false).'>Переговори другого</option></select><p class="description">Три переговорных режима и отдельная четырёхраундовая игра «Переговори другого».</p></td></tr></table>';
        if($negMode==='communicate'){
            ckm_quiz_pro_persuade_me_admin_editor(ckm_quiz_pro_persuade_me_content_from_quiz($quiz));
            echo '<p><button class="button button-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></p></form></div>';
            echo '<script>(function(){const format=document.getElementById("ckm-qp-format"),refresh=document.getElementById("ckm-qp-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{refresh.href="'.esc_js(admin_url('admin.php?page=ckm-quiz-pro-edit&format=')).'"+encodeURIComponent(format.value);});}})();</script>';
            return;
        }
    }
    if($formatKey==='jeopardy'){
        ckm_quiz_pro_render_jeopardy_admin_editor($quiz,$questions);
        echo '<p><button class="button button-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></p></form>';
        echo '<script>(function(){const format=document.getElementById("ckm-qp-format"),refresh=document.getElementById("ckm-qp-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{refresh.href="'.esc_js(admin_url('admin.php?page=ckm-quiz-pro-edit&format=')).'"+encodeURIComponent(format.value);});}})();</script></div>';
        return;
    }
    if($formatKey==='chgk') echo '<div class="notice notice-info inline"><p><strong>Правила «Битвы знатоков»:</strong> одна команда против Игры; минимум 11 обычных вопросов; каждый вопрос стоит ровно 1 очко; матч заканчивается сразу при счёте 6:x или x:6.</p></div>';
    echo '<h2>Вопросы</h2><div id="ckm-qp-questions">';
    $i=0;
    foreach($questions as $q){
        if($formatKey==='chgk'){
            $answers=json_decode($q['correct_answers_json'],true)?:[]; $reference=(string)($answers[0]??''); $variants=implode("
",array_slice($answers,1));
            ckm_quiz_pro_chgk_question_editor($i++,(string)$q['question_text'],$reference,$variants,(int)$q['points'],(string)$q['explanation']);
        } elseif($formatKey==='negotiation_duel'){
            $rule=json_decode((string)($q['scoring_rule_json']??''),true) ?: [];
            $criteria=implode("\n",array_values((array)($rule['judgeCriteria']??[])));
            ckm_quiz_pro_negotiation_editor($i++,(string)$q['question_text'],$criteria,(int)$q['points'],(string)$q['explanation']);
        } else {
            $opts=json_decode($q['options_json'],true)?:[]; $map=[]; foreach($opts as $o) if(is_array($o)) $map[(string)($o['value']??'')]=(string)($o['label']??'');
            $correct=(json_decode($q['correct_answers_json'],true)?:['A'])[0]??'A'; ckm_quiz_pro_question_editor($i++,$q['question_text'],$map,$correct,(int)$q['points'],(int)$q['time_limit_seconds'],(string)$q['explanation']);
        }
    }
    if($i===0){ if($formatKey==='chgk') ckm_quiz_pro_chgk_question_editor(0,'','','',1,''); elseif($formatKey==='negotiation_duel') ckm_quiz_pro_negotiation_editor(0,'','',20,''); else ckm_quiz_pro_question_editor(0,'',[],'A',1,(int)$quiz['seconds_per_question'],''); }
    echo '</div><p><button type="button" class="button" id="ckm-qp-add-question">Добавить вопрос</button></p><p><button class="button button-primary" name="ckm_qp_save_quiz" value="1">Сохранить игру</button></p></form>';
    $isChgk=$formatKey==='chgk';
    $isNegotiation=$formatKey==='negotiation_duel';
    $adminClassicTpl='<div class="postbox-header"><h2>Вопрос ${i+1}</h2></div><div class="inside"><p><textarea name="questions[${i}][text]" rows="3" style="width:100%" placeholder="Текст вопроса"></textarea></p>${["A","B","C","D"].map(l=>`<p><label>${l}: <input class="regular-text" name="questions[${i}][option_${l}]"></label></p>`).join("")}<p>Правильный: <select name="questions[${i}][correct]"><option>A</option><option>B</option><option>C</option><option>D</option></select> &nbsp; Баллы: <input type="number" name="questions[${i}][points]" value="1" min="1" max="100" style="width:80px"> &nbsp; Время: <input type="number" name="questions[${i}][time]" value="30" min="5" max="600" style="width:80px"></p><p><textarea name="questions[${i}][explanation]" rows="2" style="width:100%" placeholder="Пояснение после ответа"></textarea></p></div>';
    $adminChgkTpl='<div class="postbox-header"><h2>Вопрос ${i+1} · 1 очко</h2></div><div class="inside"><p><textarea name="questions[${i}][text]" rows="3" style="width:100%" placeholder="Текст вопроса"></textarea></p><p><label>Эталонный ответ: <input class="regular-text" name="questions[${i}][reference]"></label></p><p><textarea name="questions[${i}][variants]" rows="2" style="width:100%" placeholder="Допустимые варианты ответа — по одному в строке"></textarea></p><p class="description"><strong>Фиксированно 1 очко:</strong> принято — Знатокам, не принято/нет ответа — Игре.</p><p><textarea name="questions[${i}][explanation]" rows="2" style="width:100%" placeholder="Комментарий после ответа"></textarea></p></div>';
    $adminNegTpl='<div class="postbox-header"><h2>Реплика ${i+1}</h2></div><div class="inside"><p><textarea name="questions[${i}][text]" rows="5" style="width:100%" placeholder="Ситуация / реплика оппонента / задача"></textarea></p><p><textarea name="questions[${i}][criteria]" rows="2" style="width:100%" placeholder="Критерии оценки — по одному в строке"></textarea></p><p>Баллы: <input type="number" name="questions[${i}][points]" value="20" min="1" max="100" style="width:80px"></p><p><textarea name="questions[${i}][explanation]" rows="2" style="width:100%" placeholder="Методический ориентир"></textarea></p></div>';
    $adminDynamicTpl=$isChgk?$adminChgkTpl:($isNegotiation?$adminNegTpl:$adminClassicTpl);
    echo '<script>(function(){const format=document.getElementById("ckm-qp-format"),refresh=document.getElementById("ckm-qp-format-refresh");if(format&&refresh){format.addEventListener("change",()=>{refresh.href="'.esc_js(admin_url('admin.php?page=ckm-quiz-pro-edit&format=')).'"+encodeURIComponent(format.value);});}const box=document.getElementById("ckm-qp-questions"),add=document.getElementById("ckm-qp-add-question"),tpl='.wp_json_encode($adminDynamicTpl).';add.addEventListener("click",function(){const i=box.querySelectorAll(".ckm-qp-q").length;const d=document.createElement("div");d.className="postbox ckm-qp-q";d.innerHTML=tpl.replaceAll("${i}",String(i)).replaceAll("${i+1}",String(i+1));box.appendChild(d);});})();</script></div>';
}

function ckm_quiz_pro_question_editor(int $i,string $text,array $map,string $correct,int $points,int $time,string $explanation): void {
    echo '<div class="postbox ckm-qp-q"><div class="postbox-header"><h2>Вопрос '.($i+1).'</h2></div><div class="inside"><p><textarea name="questions['.$i.'][text]" rows="3" style="width:100%" placeholder="Текст вопроса">'.esc_textarea($text).'</textarea></p>';
    foreach(['A','B','C','D'] as $l) echo '<p><label>'.$l.': <input class="regular-text" name="questions['.$i.'][option_'.$l.']" value="'.esc_attr($map[$l]??'').'"></label></p>';
    echo '<p>Правильный: <select name="questions['.$i.'][correct]">'; foreach(['A','B','C','D'] as $l) echo '<option value="'.$l.'" '.selected($correct,$l,false).'>'.$l.'</option>'; echo '</select> &nbsp; Баллы: <input type="number" name="questions['.$i.'][points]" value="'.$points.'" min="1" max="100" style="width:80px"> &nbsp; Время: <input type="number" name="questions['.$i.'][time]" value="'.$time.'" min="5" max="600" style="width:80px"></p><p><textarea name="questions['.$i.'][explanation]" rows="2" style="width:100%" placeholder="Пояснение после ответа">'.esc_textarea($explanation).'</textarea></p></div></div>';
}

function ckm_quiz_pro_chgk_question_editor(int $i,string $text,string $reference,string $variants,int $points,string $explanation): void {
    echo '<div class="postbox ckm-qp-q"><div class="postbox-header"><h2>Вопрос '.($i+1).' · 1 очко</h2></div><div class="inside"><p><textarea name="questions['.$i.'][text]" rows="3" style="width:100%" placeholder="Текст вопроса">'.esc_textarea($text).'</textarea></p><p><label>Эталонный ответ: <input class="regular-text" name="questions['.$i.'][reference]" value="'.esc_attr($reference).'"></label></p><p><textarea name="questions['.$i.'][variants]" rows="2" style="width:100%" placeholder="Допустимые варианты ответа — по одному в строке">'.esc_textarea($variants).'</textarea></p><p class="description"><strong>Стоимость вопроса фиксирована:</strong> принятый ответ = +1 Знатокам; непринятый или пропущенный = +1 Игре.</p><p><textarea name="questions['.$i.'][explanation]" rows="2" style="width:100%" placeholder="Комментарий после ответа">'.esc_textarea($explanation).'</textarea></p></div></div>';
}

function ckm_quiz_pro_games_page(): void {
    if(!current_user_can('manage_options')) return;
    global $wpdb;
    $result=null;
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_create_game'])){
        check_admin_referer('ckm_quiz_pro_create_game');
        $quizId=absint($_POST['quiz_id']??0);
        $quizRow=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d AND status=\'published\'',$quizId),ARRAY_A);
        $isChgk=$quizRow && (string)($quizRow['format_key']??'')==='chgk';
        $minTeams=$quizRow ? ($isChgk ? 1 : max(1,(int)($quizRow['min_teams']??2))) : 2;
        $maxTeams=$quizRow ? ($isChgk ? 1 : max($minTeams,min(10,(int)($quizRow['max_teams']??10)))) : 10;
        $count=$isChgk ? 1 : max($minTeams,min($maxTeams,(int)($_POST['team_count']??$minTeams)));
        $hostMode=$quizRow && in_array((string)($quizRow['host_mode']??'ai'),['ai','human'],true) ? (string)$quizRow['host_mode'] : 'ai';
        $teams=[]; for($i=1;$i<=$count;$i++) $teams[]=['name'=>sanitize_text_field(wp_unslash($_POST['team_name_'.$i]??('Команда '.ckm_quiz_core_team_key_from_slot($i))))];
        $result=ckm_quiz_create_room(['quiz_id'=>$quizId,'team_count'=>$count,'teams'=>$teams,'host_mode'=>$hostMode,'judge_mode'=>'ai','test_mode'=>1],get_current_user_id());
    }
    $quizzes=$wpdb->get_results("SELECT id,title,slug,format_key,host_mode,min_teams,max_teams FROM ".ckm_quiz_pro_table('quizzes')." WHERE status='published' ORDER BY title",ARRAY_A) ?: [];
    $preferred=absint($_POST['quiz_id']??($_GET['quiz']??0));
    echo '<div class="wrap"><h1>Тестовый запуск</h1><h2>Создать тестовую комнату</h2><form method="post">'; wp_nonce_field('ckm_quiz_pro_create_game');
    echo '<table class="form-table"><tr><th>Шаблон</th><td><select name="quiz_id" id="ckm-qp-admin-quiz">'; foreach($quizzes as $q){ $min=((string)($q['format_key']??'')==='chgk')?1:max(1,(int)($q['min_teams']??2)); $max=((string)($q['format_key']??'')==='chgk')?1:max($min,min(10,(int)($q['max_teams']??10))); $def=((string)($q['format_key']??'')==='chgk'||in_array((string)($q['slug']??''),['demo-solution-price','demo-negotiation-sales','demo-negotiation-business','demo-negotiation-express'],true))?1:max(2,$min); $label=function_exists('ckm_quiz_pro_quiz_display_title')?ckm_quiz_pro_quiz_display_title($q):(string)preg_replace('/^\s*Демо:\s*/u','',(string)$q['title']); echo '<option value="'.(int)$q['id'].'" data-min="'.$min.'" data-max="'.$max.'" data-default="'.$def.'" '.selected($preferred,(int)$q['id'],false).'>'.esc_html($label.' · '.(((string)($q['host_mode']??'ai')==='human')?'Голосовой ведущий':'ИИ-ведущий')).'</option>'; } echo '</select></td></tr><tr><th>Количество команд</th><td><input id="ckm-qp-admin-team-count" type="number" name="team_count" value="2" min="1" max="10"></td></tr><tr><th>Ведущий</th><td>Режим берётся из шаблона игры. Изменить его можно в конструкторе.</td></tr></table><p><button class="button button-primary" name="ckm_qp_create_game" value="1">Создать игру</button></p></form>';
    echo '<script>(function(){const q=document.getElementById("ckm-qp-admin-quiz"),n=document.getElementById("ckm-qp-admin-team-count");if(!q||!n)return;function sync(def){const o=q.options[q.selectedIndex];const min=Math.max(1,parseInt(o&&o.dataset.min||2,10)),max=Math.max(min,parseInt(o&&o.dataset.max||10,10));n.min=String(min);n.max=String(max);if(def)n.value=String(Math.max(min,Math.min(max,parseInt(o&&o.dataset.default||min,10))));else n.value=String(Math.max(min,Math.min(max,parseInt(n.value||min,10))));}q.addEventListener("change",function(){sync(true);});sync(true);})();</script>';
    if(is_array($result)){
        if(empty($result['ok'])) echo '<div class="notice notice-error"><p>'.esc_html($result['error']??'Ошибка').'</p></div>';
        else ckm_quiz_pro_render_credentials($result);
    }
    $games=$wpdb->get_results('SELECT * FROM '.ckm_quiz_pro_table('games').' ORDER BY id DESC LIMIT 30',ARRAY_A) ?: [];
    echo '<h2>Последние игры</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Код</th><th>Название</th><th>Статус</th><th>Счёт</th><th>Ссылки</th></tr></thead><tbody>';
    foreach($games as $g){
        $teams=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('teams').' WHERE game_id=%d ORDER BY slot_no',(int)$g['id']),ARRAY_A) ?: [];
        $scores=implode(' · ',array_map(fn($t)=>$t['team_name'].': '.$t['score'],$teams));
        $links=[]; foreach($teams as $t){ $cred=['teamKey'=>$t['team_key'],'teamToken'=>$t['join_token'],'nonce'=>$t['join_nonce'],'teamName'=>$t['team_name']]; $links[]='<a href="'.esc_url(ckm_quiz_pro_team_url($g,$cred)).'" target="_blank">'.esc_html($t['team_name']).'</a>'; } $links[]='<a href="'.esc_url(ckm_quiz_pro_host_url($g)).'" target="_blank">Ведущий</a>'; $links[]='<a href="'.esc_url(ckm_quiz_pro_scoreboard_url($g)).'" target="_blank">Табло</a>'; echo '<tr><td>'.(int)$g['id'].'</td><td><code>'.esc_html($g['game_code']).'</code></td><td>'.esc_html($g['title']).'</td><td>'.esc_html($g['status']).'</td><td>'.esc_html($scores).'</td><td>'.implode(' · ',$links).'</td></tr>';
    }
    echo '</tbody></table></div>';
}

function ckm_quiz_pro_render_credentials(array $result): void {
    $g=$result['game']; $c=$result['credentials'];
    echo '<div class="notice notice-success"><p><strong>Игра создана: '.esc_html($g['game_code']).'</strong></p></div><div class="card" style="max-width:1000px"><h2>Ссылки игры</h2>';
    foreach($c['teams'] as $team){ $url=ckm_quiz_pro_team_url($g,$team); echo '<p><strong>'.esc_html($team['teamName']).':</strong> <a href="'.esc_url($url).'" target="_blank">'.esc_html($url).'</a></p>'; }
    $hostMode=(string)($g['host_mode_snapshot']??$g['host_mode']??'ai');
    echo '<p><strong>Ведущий'.($hostMode==='ai'?' (ИИ-режим)':'').':</strong> <a href="'.esc_url(ckm_quiz_pro_host_url($g)).'" target="_blank">Открыть комнату ведущего</a>'.($hostMode==='ai'?' — ручные команды недоступны':'').'</p>';
    echo '<p><strong>Табло:</strong> <a href="'.esc_url(ckm_quiz_pro_scoreboard_url($g)).'" target="_blank">Открыть</a></p></div>';
}

function ckm_quiz_pro_smoke_menu(): void {
    add_submenu_page('ckm-quiz-pro','Проверка MVP','Проверка MVP','manage_options','ckm-quiz-pro-smoke','ckm_quiz_pro_smoke_page');
}
add_action('admin_menu','ckm_quiz_pro_smoke_menu',40);

function ckm_quiz_pro_smoke_page(): void {
    if(!current_user_can('manage_options')) return;
    global $wpdb;
    $repairNotice='';
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_restore_demo'])){
        check_admin_referer('ckm_quiz_pro_restore_demo');
        ckm_quiz_pro_seed_demo_quiz();
        ckm_quiz_pro_seed_demo_chgk();
        ckm_quiz_pro_seed_demo_jeopardy();
        $repairNotice='Демонстрационные игры проверены и восстановлены при необходимости.';
    }
    $checks=[];
    foreach(['quizzes','questions','formats','rounds','games','teams','members','answers','score_events','events','mechanics','packages','entitlements'] as $logical){
        $table=ckm_quiz_pro_table($logical); $exists=$table!=='' && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table; $checks[]=['Таблица '.$logical,$exists,$exists?$table:'не найдена'];
    }
    $services=ckm_quiz_core_registered_services();
    foreach(['storage_table','team_policy','validate_room_access','consume_access_locked','preflight_access'] as $s) $checks[]=['Adapter service: '.$s,in_array($s,$services,true),in_array($s,$services,true)?'registered':'missing'];
    $classic=ckm_quiz_format_get('classic_quiz'); $checks[]=['Общий Core: classic_quiz',is_array($classic)&&ckm_quiz_format_is_runnable('classic_quiz'),'runtime='.($classic?'active':'missing')];
    $chgk=ckm_quiz_format_get('chgk'); $checks[]=['Общий Core: Битва знатоков',is_array($chgk)&&ckm_quiz_format_is_runnable('chgk'),'runtime='.($chgk?'active':'missing')];
    $checks[]=['CHGK standalone adapter',function_exists('ckm_quiz_pro_chgk_local_autopilot')&&function_exists('ckm_quiz_pro_chgk_local_judge_closed_question')&&function_exists('ckm_quiz_chgk_ai_host_autostart')&&function_exists('ckm_quiz_chgk_maybe_auto_close_question'),'team-device + local autopilot'];
    $jeopardy=ckm_quiz_format_get('jeopardy'); $checks[]=['Общий Core: Интеллектуальный батл',is_array($jeopardy)&&ckm_quiz_format_is_runnable('jeopardy'),'runtime='.($jeopardy?'jeopardy_v1':'missing')];
    $checks[]=['Jeopardy standalone adapter',function_exists('ckm_quiz_pro_jeopardy_team_action')&&function_exists('ckm_quiz_pro_jeopardy_host_action')&&function_exists('ckm_quiz_jeopardy_claim_buzzer')&&function_exists('ckm_quiz_jeopardy_start_final'),'board + buzzer + secret transfer + fixed final'];
    $checks[]=['Выбор ведущего',function_exists('ckm_quiz_pro_ajax_host_action')&&function_exists('ckm_quiz_pro_local_judge_closed_question'),'ai | human + host panel'];
    $checks[]=['Микрофон ведущего',function_exists('ckm_quiz_pro_ajax_voice_token')&&file_exists(CKM_QUIZ_PRO_DIR.'assets/standalone-voice.js'),function_exists('ckm_quiz_pro_voice_ready')&&ckm_quiz_pro_voice_ready()?'Gateway configured':'transport ready; Gateway secret not configured'];
    $demo=(int)$wpdb->get_var("SELECT id FROM ".ckm_quiz_pro_table('quizzes')." WHERE slug='demo-classic-quiz' LIMIT 1");
    $cnt=$demo?(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND status=\'active\'',$demo)):0;
    $checks[]=['Классический квиз', $demo>0 && $cnt>=5, $demo>0?('вопросов: '.$cnt):'не найден'];
    $chgkDemo=(int)$wpdb->get_var("SELECT id FROM ".ckm_quiz_pro_table('quizzes')." WHERE slug='demo-battle-experts' LIMIT 1");
    $chgkCnt=$chgkDemo?(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND status=\'active\'',$chgkDemo)):0;
    $checks[]=['Битва знатоков', $chgkDemo>0 && $chgkCnt>=11, $chgkDemo>0?('вопросов: '.$chgkCnt):'не найден'];
    $jeopardyDemo=(int)$wpdb->get_var("SELECT id FROM ".ckm_quiz_pro_table('quizzes')." WHERE slug='ckm-demo-intellectual-battle' LIMIT 1");
    $jeopardyCnt=$jeopardyDemo?(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND status=\'active\'',$jeopardyDemo)):0;
    $checks[]=['Интеллектуальный батл', $jeopardyDemo>0 && $jeopardyCnt>=13, $jeopardyDemo>0?('вопросов: '.$jeopardyCnt):'не найден'];
    $pkgBuild=$demo?ckm_quiz_pro_package_build_payload($demo):new WP_Error('no_demo','demo missing');
    $checks[]=['игровой пакет v1',!is_wp_error($pkgBuild),is_wp_error($pkgBuild)?$pkgBuild->get_error_message():'export payload ready'];
    $checks[]=['ZIP для .ckmgame',class_exists('ZipArchive'),class_exists('ZipArchive')?'available':'ZipArchive missing'];
    foreach(ckm_quiz_pro_crypto_self_test() as $cryptoCheck) $checks[]=$cryptoCheck;
    foreach(['play','host','scoreboard'] as $p){$id=(int)get_option('ckm_quiz_pro_page_'.$p,0);$checks[]=['Страница '.$p,$id>0&&get_post($id),(string)$id];}
    $roomPath=(string)parse_url(ckm_quiz_pro_page_url('play',['game'=>'TEST','team'=>'A','token'=>'x','nonce'=>'y']),PHP_URL_PATH);
    $hostPath=(string)parse_url(ckm_quiz_pro_page_url('host',['game'=>'TEST','token'=>'x','nonce'=>'y']),PHP_URL_PATH);
    $boardPath=(string)parse_url(ckm_quiz_pro_page_url('scoreboard',['game'=>'TEST','token'=>'x','nonce'=>'y']),PHP_URL_PATH);
    $checks[]=['Маршруты комнат как на основном сайте',$roomPath==='/ckm/quiz-room.html'&&$hostPath==='/ckm/quiz-host.html'&&$boardPath==='/ckm/quiz-scoreboard.html',$roomPath.' | '.$hostPath.' | '.$boardPath];
    $orgRole=get_role(CKM_QUIZ_PRO_ORGANIZER_ROLE); $checks[]=['Роль организатора', $orgRole && $orgRole->has_cap(CKM_QUIZ_PRO_ORGANIZER_CAP), $orgRole?'registered':'missing'];
    foreach(['organizer','login'] as $p){$id=(int)get_option('ckm_quiz_pro_page_'.$p,0);$checks[]=['Frontend '.$p,$id>0&&get_post($id),(string)$id];}
    $all=!array_filter($checks,fn($r)=>!$r[1]);
    echo '<div class="wrap"><h1>MVP Smoke Test</h1>';
    if($repairNotice!=='') echo '<div class="notice notice-success"><p>'.esc_html($repairNotice).'</p></div>';
    echo '<div class="notice notice-'.($all?'success':'error').'"><p><strong>'.($all?'PASS — Классический квиз + Битва знатоков + Интеллектуальный батл + ведущий/микрофон + игровой пакет готовы к тесту.':'FAIL — есть незавершённые проверки.').'</strong></p></div>';
    if(!$demo || $cnt<5 || !$chgkDemo || $chgkCnt<5 || !$jeopardyDemo || $jeopardyCnt<13){ echo '<form method="post" style="margin:12px 0">'; wp_nonce_field('ckm_quiz_pro_restore_demo'); echo '<button class="button button-primary" name="ckm_qp_restore_demo" value="1">Восстановить демо-игры</button></form>'; }
    echo '<table class="widefat striped"><thead><tr><th>Проверка</th><th>Результат</th><th>Детали</th></tr></thead><tbody>';
    foreach($checks as $r) echo '<tr><td>'.esc_html($r[0]).'</td><td><strong style="color:'.($r[1]?'#008a20':'#b32d2e').'">'.($r[1]?'PASS':'FAIL').'</strong></td><td><code>'.esc_html($r[2]).'</code></td></tr>';
    echo '</tbody></table></div>';
}

<?php
if (!defined('ABSPATH')) exit;

/** Preparation-only vertical slice. Secret briefs never enter shared quiz JSON. */
function ckmqp_show_is_game(array $game): bool {
    if (($game['format_key_snapshot'] ?? '') !== 'negotiation_duel') return false;
    $settings=ckm_quiz_json_decode($game['format_settings_snapshot_json'] ?? '');
    return ($settings['negotiationMode'] ?? '') === 'communicate';
}

function ckmqp_show_seed(): void {
    if (get_option('ckmqp_show_prep_seed') === '1') return;
    if (!function_exists('ckmqp_content_ready') || !ckmqp_content_ready()) return;
    if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();
    ckm_quiz_pro_negotiation_seed_one('demo-negotiation-communicate','Переговори другого','communicate',[
        ['Коллега задерживает данные для общего отчёта.','',[],0],
        ['Руководитель добавляет срочную задачу при полной загрузке.','',[],0],
        ['Клиент требует дополнительную работу бесплатно.','',[],0],
    ]);
    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug=%s",'demo-negotiation-communicate'));
    if (!$id) return;
    $ok=$wpdb->update($table,[
        'min_teams'=>2,'max_teams'=>2,
        'short_description'=>'Подготовительная комната на две команды: стороны переговоров получают разные роли и закрытые вводные.',
        'instructions'=>'Откройте две командные ссылки. A и B по очереди меняются ролями переговорщика и оппонента. ИИ-арбитр наблюдает и оценивает обе стороны.',
    ],['id'=>$id]);
    $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=1 AND status=%s',$id,'active'));
    if ($ok !== false && $count === 3) update_option('ckmqp_show_prep_seed','1',false);
}
add_action('init','ckmqp_show_seed',20);

/** Seed paid story variants for «Переговори другого». Mechanics stay unchanged. */
function ckmqp_show_variant_seed_one(string $slug,string $title,string $variant,array $content,string $description): bool {
    if (!function_exists('ckm_quiz_pro_negotiation_seed_one')) return false;
    $items=[];
    foreach (($content['round1']['cases'] ?? []) as $case) {
        $items[]=[(string)($case['situation']??''),'',[],0];
    }
    ckm_quiz_pro_negotiation_seed_one($slug,$title,'communicate',$items);

    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $quiz=$wpdb->get_row($wpdb->prepare(
        "SELECT id,format_settings_json FROM {$table} WHERE slug=%s AND tenant_id=0 AND content_scope='shared' LIMIT 1",
        $slug
    ),ARRAY_A);
    if (!$quiz) return false;

    $settings=ckm_quiz_json_decode((string)($quiz['format_settings_json']??''));
    if (!is_array($settings)) $settings=[];
    $settings['negotiationMode']='communicate';
    $settings['persuadeMeVariant']=$variant;
    $settings['persuadeMeContent']=$content;
    $settings['standaloneVersion']='persuade_me_v1';

    $ok=$wpdb->update($table,[
        'title'=>$title,
        'min_teams'=>2,
        'max_teams'=>2,
        'host_mode'=>'ai',
        'judge_mode'=>'ai',
        'short_description'=>$description,
        'instructions'=>'Две команды и четыре неизменных раунда: «Удержи цель», «Скрытая задача», «Неудобный вопрос», «Проверь историю». Меняется только сюжет. Максимум — 280 баллов.',
        'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        'updated_at'=>current_time('mysql'),
    ],['id'=>(int)$quiz['id']]);
    return $ok!==false;
}

function ckmqp_show_paid_variants_seed(): void {
    if (get_option('ckmqp_show_paid_variants_seed_v214') === '1') return;
    if (!function_exists('ckmqp_content_ready') || !ckmqp_content_ready()) return;
    if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();

    $variants=[
        [
            'slug'=>'demo-negotiation-communicate-school',
            'title'=>'Переговори другого — для школьников',
            'variant'=>'school',
            'fn'=>'ckm_quiz_pro_persuade_me_school_content',
            'description'=>'Школьные проекты, одноклассники, совместная подготовка, неудобные вопросы и проверка истории.',
        ],
        [
            'slug'=>'demo-negotiation-communicate-school-grade',
            'title'=>'Переговори другого — для школьников: «Двойка, которой не было»',
            'variant'=>'school_grade',
            'fn'=>'ckm_quiz_pro_persuade_me_school_grade_content',
            'description'=>'Лера оспаривает двойку за контрольную по химии: она решала вариант Б, а работа могла быть проверена как вариант А. Роли: Лера и Виктор Палыч.',
        ],
        [
            'slug'=>'demo-negotiation-communicate-student',
            'title'=>'Переговори другого — для студентов',
            'variant'=>'student',
            'fn'=>'ckm_quiz_pro_persuade_me_student_content',
            'description'=>'Учебные проекты, студенческая команда, общежитие, дедлайны и сложные разговоры.',
        ],
        [
            'slug'=>'demo-negotiation-communicate-leader',
            'title'=>'Переговори другого — для руководителей',
            'variant'=>'leader',
            'fn'=>'ckm_quiz_pro_persuade_me_leader_content',
            'description'=>'Управленческие переговоры, приоритеты, нагрузка, ответственность, обратная связь и конфликты.',
        ],
        [
            'slug'=>'demo-negotiation-communicate-family',
            'title'=>'Переговори другого — Семейные ситуации',
            'variant'=>'family',
            'fn'=>'ckm_quiz_pro_persuade_me_family_content',
            'description'=>'Домашние договорённости, границы, совместные планы, распределение дел и сложные семейные разговоры.',
        ],
    ];
    foreach($variants as $v){
        if(!function_exists($v['fn'])) return;
        if(!ckmqp_show_variant_seed_one($v['slug'],$v['title'],$v['variant'],$v['fn'](),$v['description'])) return;
    }
    update_option('ckmqp_show_paid_variants_seed_v214','1',false);
}
add_action('init','ckmqp_show_paid_variants_seed',21);


/**
 * dev.267: new «Переговори другого» rooms are strictly two-team.
 * Existing live rooms are not rewritten: their state_json has no teamCount and
 * normalizes to the legacy three-team contract. Technical templates are moved
 * to 2/2 so every newly created room uses the new duel model.
 */
function ckmqp_show_two_team_migration(): void {
    if (get_option('ckmqp_show_two_team_migration_v1')==='1') return;
    if (!function_exists('ckm_quiz_pro_table')) return;
    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $rows=$wpdb->get_results("SELECT id,format_settings_json FROM {$table} WHERE format_key='negotiation_duel' AND status IN ('published','draft')",ARRAY_A)?:[];
    $changed=[];$now=current_time('mysql');
    foreach($rows as $row){
        $settings=json_decode((string)($row['format_settings_json']??''),true);
        if(!is_array($settings) || (string)($settings['negotiationMode']??'')!=='communicate') continue;
        $id=(int)$row['id'];
        $ok=$wpdb->update($table,[
            'min_teams'=>2,'max_teams'=>2,
            'instructions'=>'Две команды и четыре неизменных раунда: «Удержи цель», «Скрытая задача», «Неудобный вопрос», «Проверь историю». ИИ-арбитр наблюдает и оценивает обе стороны. Максимум — 280 баллов на команду.',
            'updated_at'=>$now,
        ],['id'=>$id]);
        if($ok!==false)$changed[]=$id;
    }
    if($changed && post_type_exists('ckm_ready_game')){
        $ids=get_posts([
            'post_type'=>'ckm_ready_game','post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,
            'meta_query'=>[['key'=>'_ckm_ready_quiz_id','value'=>array_map('strval',$changed),'compare'=>'IN']],
        ]);
        foreach($ids as $postId){
            if(function_exists('ckm_quiz_pro_ready_game_integrity_invalidate'))ckm_quiz_pro_ready_game_integrity_invalidate((int)$postId);
            foreach(['_ckm_ready_qa_passed_fingerprint','_ckm_ready_qa_passed_game_id','_ckm_ready_qa_passed_at','_ckm_ready_qa_passed_by','_ckm_ready_qa_catalog_revision','_ckm_ready_qa_last_test_fingerprint','_ckm_ready_qa_last_test_game_id','_ckm_ready_qa_last_test_quiz_id','_ckm_ready_qa_last_test_quiz_revision','_ckm_ready_qa_last_test_started_at'] as $key) delete_post_meta((int)$postId,$key);
        }
    }
    update_option('ckmqp_show_two_team_migration_v1','1',false);
}
add_action('init','ckmqp_show_two_team_migration',29);

/** dev.269: persist authored first-speaker rules for all two-team «Переговори другого» templates. */
function ckmqp_show_turn_order_migration(): void {
    if(get_option('ckmqp_show_turn_order_migration_v1')==='1')return;
    if(!function_exists('ckm_quiz_pro_table')||!function_exists('ckm_quiz_pro_persuade_me_normalize_content'))return;
    global $wpdb;
    $table=ckm_quiz_pro_table('quizzes');
    $rows=$wpdb->get_results("SELECT id,format_settings_json FROM {$table} WHERE format_key='negotiation_duel' AND status IN ('published','draft')",ARRAY_A)?:[];
    $changed=[];$now=current_time('mysql');
    foreach($rows as $row){
        $settings=json_decode((string)($row['format_settings_json']??''),true);
        if(!is_array($settings)||(string)($settings['negotiationMode']??'')!=='communicate')continue;
        $normalized=ckm_quiz_pro_persuade_me_normalize_content($settings['persuadeMeContent']??null);
        $before=wp_json_encode($settings['persuadeMeContent']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $after=wp_json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($before===$after)continue;
        $settings['persuadeMeContent']=$normalized;
        $ok=$wpdb->update($table,[
            'format_settings_json'=>wp_json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'updated_at'=>$now,
        ],['id'=>(int)$row['id']]);
        if($ok!==false)$changed[]=(int)$row['id'];
    }
    if($changed&&post_type_exists('ckm_ready_game')){
        $ids=get_posts([
            'post_type'=>'ckm_ready_game',
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>-1,
            'fields'=>'ids',
            'no_found_rows'=>true,
            'meta_query'=>[['key'=>'_ckm_ready_quiz_id','value'=>array_map('strval',$changed),'compare'=>'IN']],
        ]);
        foreach($ids as $postId){
            if(function_exists('ckm_quiz_pro_ready_game_integrity_invalidate'))ckm_quiz_pro_ready_game_integrity_invalidate((int)$postId);
            foreach([
                '_ckm_ready_qa_passed_fingerprint','_ckm_ready_qa_passed_game_id','_ckm_ready_qa_passed_at','_ckm_ready_qa_passed_by',
                '_ckm_ready_qa_catalog_revision','_ckm_ready_qa_last_test_fingerprint','_ckm_ready_qa_last_test_game_id',
                '_ckm_ready_qa_last_test_quiz_id','_ckm_ready_qa_last_test_quiz_revision','_ckm_ready_qa_last_test_started_at',
            ] as $key)delete_post_meta((int)$postId,$key);
        }
    }
    update_option('ckmqp_show_turn_order_migration_v1','1',false);
}
add_action('init','ckmqp_show_turn_order_migration',30);


/** Auth is produced by the existing token/nonce and tenant boundaries. */
function ckmqp_show_project(array $game, array $auth, array $teams): array {
    $validGame=(int)($game['id']??0)>0 && (int)($auth['game_id']??0)===(int)$game['id'];
    $mine=null;
    if ($validGame && ($auth['role']??'')==='participant') {
        foreach ($teams as $team) if ((int)$team['id']===(int)($auth['team_id']??0)) {$mine=$team;break;}
    }
    $content=ckm_quiz_pro_persuade_me_content_from_game($game);$case=$content['round1']['cases'][0];
    $show=[
        'stage'=>'preparation','teamName'=>$mine ? (string)$mine['team_name'] : '',
        'situation'=>'Удержи цель. '.(string)$case['situation'],
        'roleTitle'=>'Общее табло',
    ];
    if ($validGame && in_array(($auth['role']??''),['host','admin'],true)) $show['roleTitle']='Организатор · подготовка команд';
    if ($mine) {
        $cards=[
            1=>['Переговорщик',(string)$case['speaker']],
            2=>['Оппонент',(string)$case['opponent']],
        ];
        // Legacy three-team rooms may still fall back to this projection while
        // finishing. New two-team rooms never create this third card.
        if(count($teams)>=3) $cards[3]=['Наблюдатель',(string)($content['round1']['observerBrief']??'')];
        $card=$cards[(int)$mine['slot_no']]??null;
        if ($card) {$show['roleTitle']=$card[0];$show['brief']=$card[1];}
    }
    $publicTeams=[];
    foreach ($teams as $team) $publicTeams[]=[
        'id'=>(int)$team['id'],'key'=>(string)$team['team_key'],
        'name'=>(string)$team['team_name'],'slot'=>(int)$team['slot_no'],'score'=>0,
    ];
    return [
        'serverTime'=>time(),
        'game'=>['id'=>(int)$game['id'],'code'=>(string)$game['game_code'],'title'=>(string)$game['title'],
            'formatKey'=>'negotiation_duel','phase'=>'waiting','status'=>(string)$game['status'],
            'hostMode'=>(string)$game['host_mode_snapshot'],'questionDeadlineUnix'=>0,'startAuthorized'=>false],
        'teams'=>$publicTeams,'question'=>null,'negotiationShow'=>$show,'events'=>[],
    ];
}

function ckmqp_show_ai_host_events(int $gameId): array {
    if ($gameId<1 || !function_exists('ckm_quiz_events_after')) return [];
    $events=ckm_quiz_events_after($gameId,0,250);
    return array_values(array_filter($events,static fn($event)=>(string)($event['action']??'')==='ai_host_message'));
}

function ckmqp_show_state(array $game, array $auth): array {
    global $wpdb;
    $teams=$wpdb->get_results($wpdb->prepare(
        'SELECT id,team_key,team_name,slot_no FROM '.ckm_quiz_teams_table().' WHERE game_id=%d ORDER BY slot_no ASC,id ASC',
        (int)$game['id']),ARRAY_A) ?: [];
    if ((int)($auth['game_id']??0)!==(int)$game['id']) return ckmqp_show_project($game,$auth,$teams);
    $result=ckmqp_show_session($game,$auth,['command'=>'poll']);
    if (empty($result['ok'])) { $out=ckmqp_show_project($game,$auth,$teams); $out['negotiationShow']['error']=$result['error']; return $out; }
    $out=ckmqp_show_dialogue_project($game,$auth,$teams,$result['session']);
    $events=ckmqp_show_ai_host_events((int)$game['id']);
    $out['events']=$events;
    $out['lastEventId']=$events?(int)$events[count($events)-1]['id']:0;
    $out['hasMoreEvents']=false;
    return $out;
}

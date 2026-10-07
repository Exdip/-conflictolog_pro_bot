<?php
if (!defined('ABSPATH')) exit;

/**
 * Administrator-managed catalogue of finished games.
 *
 * Organizers can only purchase/launch these entries. Only WordPress
 * administrators (manage_options) can create, edit or remove catalogue games.
 */
function ckm_quiz_pro_ready_game_post_type(): string { return 'ckm_ready_game'; }

add_action('init', static function (): void {
    register_post_type(ckm_quiz_pro_ready_game_post_type(), [
        'labels'=>[
            'name'=>'Каталог готовых игр',
            'singular_name'=>'Готовая игра',
            'menu_name'=>'Каталог готовых игр',
            'add_new'=>'Добавить игру',
            'add_new_item'=>'Добавить готовую игру',
            'edit_item'=>'Редактировать готовую игру',
            'new_item'=>'Новая готовая игра',
            'view_item'=>'Просмотреть готовую игру',
            'search_items'=>'Найти готовую игру',
            'not_found'=>'Готовые игры не найдены',
            'not_found_in_trash'=>'В корзине готовых игр нет',
        ],
        'public'=>false,
        'show_ui'=>true,
        'show_in_menu'=>false,
        'show_in_rest'=>false,
        'supports'=>['title','editor','excerpt'],
        'map_meta_cap'=>false,
        'capabilities'=>[
            'edit_post'=>'manage_options',
            'read_post'=>'manage_options',
            'delete_post'=>'manage_options',
            'edit_posts'=>'manage_options',
            'edit_others_posts'=>'manage_options',
            'publish_posts'=>'manage_options',
            'read_private_posts'=>'manage_options',
            'delete_posts'=>'manage_options',
            'delete_private_posts'=>'manage_options',
            'delete_published_posts'=>'manage_options',
            'delete_others_posts'=>'manage_options',
            'edit_private_posts'=>'manage_options',
            'edit_published_posts'=>'manage_options',
            'create_posts'=>'manage_options',
        ],
        'menu_icon'=>'dashicons-archive',
    ]);
}, 12);

function ckm_quiz_pro_ready_game_meta_defaults(): array {
    return [
        'category'=>'Другие игры',
        'subtitle'=>'',
        'audience'=>'',
        'price'=>990,
        'product_key'=>'',
        'quiz_id'=>0,
        'icon'=>'◆',
        'roles'=>'',
        'rounds'=>'',
        'rules'=>'',
    ];
}

function ckm_quiz_pro_ready_game_meta(int $postId): array {
    $d=ckm_quiz_pro_ready_game_meta_defaults();
    foreach(array_keys($d) as $key){
        $v=get_post_meta($postId,'_ckm_ready_'.$key,true);
        if($v!=='' && $v!==null) $d[$key]=$v;
    }
    $d['price']=max(0,(int)$d['price']);
    $d['quiz_id']=max(0,(int)$d['quiz_id']);
    $d['product_key']=sanitize_key((string)$d['product_key']);
    if($d['product_key']==='') $d['product_key']='ready_game_'.$postId;
    return $d;
}


/**
 * Last commercially released snapshot of a ready game.
 *
 * The administrator may continue editing the working card/template after a
 * release. Buyers keep seeing the last QA-approved release until the new
 * working revision is tested and published.
 */
function ckm_quiz_pro_ready_game_release_snapshot(int $postId): array {
    if($postId<=0) return [];
    $raw=(string)get_post_meta($postId,'_ckm_ready_release_snapshot',true);
    if($raw==='') return [];
    $decoded=json_decode($raw,true);
    return is_array($decoded)?$decoded:[];
}

function ckm_quiz_pro_ready_game_has_release(int $postId): bool {
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    return !empty($release['product']) && (int)($release['quiz_id']??0)>0;
}

/** Release-history storage for immutable commercial releases. */
function ckm_quiz_pro_ready_game_release_table_ready(): bool {
    if(!function_exists('ckm_quiz_pro_table')) return false;
    global $wpdb;
    $table=ckm_quiz_pro_table('ready_game_releases');
    if($table==='') return false;
    $ready=(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
    if(!$ready && function_exists('ckm_quiz_pro_install_schema')){
        ckm_quiz_pro_install_schema();
        $ready=(string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
    }
    return $ready;
}

function ckm_quiz_pro_ready_game_release_rows(int $postId): array {
    if($postId<=0 || !ckm_quiz_pro_ready_game_release_table_ready()) return [];
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_game_releases').' WHERE catalog_post_id=%d ORDER BY release_no DESC,id DESC',
        $postId
    ),ARRAY_A) ?: [];
}

function ckm_quiz_pro_ready_game_active_release_row(int $postId): array {
    if($postId<=0 || !ckm_quiz_pro_ready_game_release_table_ready()) return [];
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_game_releases').' WHERE catalog_post_id=%d AND status=%s ORDER BY release_no DESC,id DESC LIMIT 1',
        $postId,'active'
    ),ARRAY_A);
    return is_array($row)?$row:[];
}

/**
 * Append one commercial release event. This table is append-only from the
 * administrator's point of view: publishing and rollback both create a new
 * release number, so the live-catalog history remains auditable.
 */
function ckm_quiz_pro_ready_game_append_release_event(int $postId,array $released,string $action='publish',int $sourceReleaseId=0): array {
    if($postId<=0 || !current_user_can('manage_options')) return ['ok'=>false,'error'=>'forbidden'];
    if(!ckm_quiz_pro_ready_game_release_table_ready()) return ['ok'=>false,'error'=>'release_history_storage_missing'];
    $action=in_array($action,['publish','rollback','seed'],true)?$action:'publish';
    global $wpdb;
    $table=ckm_quiz_pro_table('ready_game_releases');
    $next=max(1,(int)$wpdb->get_var($wpdb->prepare('SELECT MAX(release_no) FROM '.$table.' WHERE catalog_post_id=%d',$postId))+1);
    $released['release_no']=$next;
    $released['release_action']=$action;
    $released['source_release_id']=max(0,$sourceReleaseId);
    $released['release_activated_at']=current_time('mysql');
    $snapshotJson=wp_json_encode($released,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($snapshotJson) || $snapshotJson==='') return ['ok'=>false,'error'=>'release_snapshot_encode_failed'];
    $now=current_time('mysql');
    if($wpdb->query('START TRANSACTION')===false) return ['ok'=>false,'error'=>'release_history_transaction_failed'];
    try{
        $wpdb->update($table,['status'=>'superseded'],['catalog_post_id'=>$postId,'status'=>'active'],['%s'],['%d','%s']);
        $ok=$wpdb->insert($table,[
            'catalog_post_id'=>$postId,
            'release_no'=>$next,
            'catalog_revision'=>max(1,(int)($released['catalog_revision']??1)),
            'release_quiz_id'=>max(0,(int)($released['quiz_id']??0)),
            'source_quiz_id'=>max(0,(int)($released['source_quiz_id']??0)),
            'source_quiz_revision'=>max(1,(int)($released['source_quiz_revision']??1)),
            'snapshot_json'=>$snapshotJson,
            'status'=>'active',
            'action'=>$action,
            'source_release_id'=>max(0,$sourceReleaseId),
            'created_by_user_id'=>get_current_user_id(),
            'created_at'=>$now,
            'activated_at'=>$now,
        ]);
        if($ok===false) throw new RuntimeException('release_history_insert_failed');
        $rowId=(int)$wpdb->insert_id;
        if($wpdb->query('COMMIT')===false) throw new RuntimeException('release_history_commit_failed');
        update_post_meta($postId,'_ckm_ready_release_row_id',$rowId);
        update_post_meta($postId,'_ckm_ready_release_no',$next);
        return ['ok'=>true,'item'=>$released,'release_id'=>$rowId,'release_no'=>$next];
    }catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
}

/** Seed the history table from the current pre-dev.264 release pointer once. */
add_action('admin_init', static function (): void {
    if(!current_user_can('manage_options') || !ckm_quiz_pro_ready_game_release_table_ready()) return;
    if(get_option('ckm_quiz_pro_ready_release_history_seed_v1','')==='1') return;
    $ids=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish','draft','private'],
        'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,'suppress_filters'=>false,
    ]);
    foreach($ids as $id){
        $id=(int)$id;
        if($id<=0 || ckm_quiz_pro_ready_game_release_rows($id)) continue;
        $release=ckm_quiz_pro_ready_game_release_snapshot($id);
        if(!$release || (int)($release['quiz_id']??0)<=0) continue;
        $logged=ckm_quiz_pro_ready_game_append_release_event($id,$release,'seed',0);
        if(!empty($logged['ok'])){
            $release=(array)$logged['item'];
            update_post_meta($id,'_ckm_ready_release_snapshot',wp_json_encode($release,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
    update_option('ckm_quiz_pro_ready_release_history_seed_v1','1',false);
}, 45);

/**
 * Freeze the currently QA-approved source into an immutable technical release.
 * New buyers are cloned from this release quiz, never from the working source.
 */
function ckm_quiz_pro_ready_game_capture_release(int $postId,int $catalogRevision=0): array {
    if($postId<=0 || !current_user_can('manage_options')) return ['ok'=>false,'error'=>'forbidden'];
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return ['ok'=>false,'error'=>'not_found'];
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $sourceQuizId=max(0,(int)$m['quiz_id']);
    if($sourceQuizId<=0) return ['ok'=>false,'error'=>'source_quiz_missing'];

    $catalogRevision=$catalogRevision>0?$catalogRevision:ckm_quiz_pro_ready_game_current_revision($postId);
    $releaseTitle=(string)$post->post_title.' — релиз v'.max(1,$catalogRevision);
    $releaseQuizId=ckm_quiz_pro_ready_game_clone_source_quiz($sourceQuizId,$releaseTitle);
    if($releaseQuizId<=0) return ['ok'=>false,'error'=>'release_quiz_clone_failed'];

    global $wpdb;
    // Mark immutable release templates so they do not appear as editable
    // source choices in the administrator's template selector.
    $wpdb->update(
        ckm_quiz_pro_table('quizzes'),
        ['slug'=>substr('ready-release-'.$postId.'-v'.max(1,$catalogRevision).'-'.substr(hash('sha256',(string)$releaseQuizId.'|'.microtime(true)),0,8),0,190)],
        ['id'=>$releaseQuizId],
        ['%s'],['%d']
    );
    $sourceQuizRevision=max(1,(int)$wpdb->get_var($wpdb->prepare(
        'SELECT current_revision FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',
        $sourceQuizId
    )));

    $current=ckm_quiz_pro_ready_game_item_from_post($post);
    $released=$current;
    $released['catalog_post_id']=$postId;
    $released['catalog_revision']=max(1,$catalogRevision);
    $released['catalog_status']='publish';
    $released['working_status']='publish';
    $released['ready_for_publish']=true;
    $released['validation_errors']=[];
    $released['integrity_ok']=true;
    $released['integrity_errors']=[];
    $released['qa_passed']=true;
    $released['qa_reason']='Опубликованный релиз прошёл QA.';
    $released['orderable']=true;
    $released['hidden']=false;
    $released['draft']=false;
    $released['source_quiz_id']=$sourceQuizId;
    $released['source_quiz_revision']=$sourceQuizRevision;
    $released['quiz_id']=$releaseQuizId;
    $released['release_quiz_id']=$releaseQuizId;
    $released['release_created_at']=current_time('mysql');

    $releaseLog=ckm_quiz_pro_ready_game_append_release_event($postId,$released,'publish',0);
    if(empty($releaseLog['ok'])) return ['ok'=>false,'error'=>'release_history_failed','detail'=>(string)($releaseLog['error']??'')];
    $released=(array)$releaseLog['item'];

    update_post_meta($postId,'_ckm_ready_release_snapshot',wp_json_encode($released,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    update_post_meta($postId,'_ckm_ready_release_catalog_revision',max(1,$catalogRevision));
    update_post_meta($postId,'_ckm_ready_release_quiz_id',$releaseQuizId);
    update_post_meta($postId,'_ckm_ready_release_source_quiz_id',$sourceQuizId);
    update_post_meta($postId,'_ckm_ready_release_source_quiz_revision',$sourceQuizRevision);
    update_post_meta($postId,'_ckm_ready_release_created_at',current_time('mysql'));
    update_post_meta($postId,'_ckm_ready_release_fingerprint',ckm_quiz_pro_ready_game_qa_fingerprint($postId));
    return ['ok'=>true,'item'=>$released,'release_quiz_id'=>$releaseQuizId,'catalog_revision'=>max(1,$catalogRevision),'release_id'=>(int)($releaseLog['release_id']??0),'release_no'=>(int)($releaseLog['release_no']??0)];
}


/**
 * Upgrade bridge: freeze the last already-published QA-approved games once so
 * administrators can start editing immediately without taking the live
 * catalogue version offline.
 */
add_action('admin_init', static function (): void {
    if(!current_user_can('manage_options')) return;
    if(get_option('ckm_quiz_pro_ready_release_seed_v1','')==='1') return;
    $ids=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish'],
        'posts_per_page'=>-1,
        'fields'=>'ids',
        'no_found_rows'=>true,
        'suppress_filters'=>false,
    ]);
    foreach($ids as $id){
        $id=(int)$id;
        if($id<=0 || ckm_quiz_pro_ready_game_has_release($id)) continue;
        $validation=ckm_quiz_pro_ready_game_validate_post($id);
        $integrity=ckm_quiz_pro_ready_game_integrity_check($id);
        $qa=ckm_quiz_pro_ready_game_qa_status($id);
        if(empty($validation['ready']) || empty($integrity['ok']) || empty($qa['passed'])) continue;
        ckm_quiz_pro_ready_game_capture_release($id,ckm_quiz_pro_ready_game_current_revision($id));
    }
    update_option('ckm_quiz_pro_ready_release_seed_v1','1',false);
}, 40);


/**
 * Publication readiness for an administrator-managed ready game.
 * Drafts may be incomplete; publication is allowed only when every required
 * commercial/catalogue field and the linked playable source template are ready.
 */
function ckm_quiz_pro_ready_game_validate_values(array $values,int $postId=0): array {
    $checks=[];
    $errors=[];
    $add=static function(string $key,string $label,bool $ok,string $error='') use (&$checks,&$errors): void {
        $checks[$key]=['label'=>$label,'ok'=>$ok];
        if(!$ok) $errors[$key]=$error!==''?$error:$label;
    };

    $title=trim(wp_strip_all_tags((string)($values['title']??'')));
    $excerpt=trim(wp_strip_all_tags((string)($values['excerpt']??'')));
    $content=trim(wp_strip_all_tags((string)($values['content']??'')));
    $category=trim((string)($values['category']??''));
    $audience=trim((string)($values['audience']??''));
    $price=max(0,(int)($values['price']??0));
    $productKey=sanitize_key((string)($values['product_key']??''));
    $quizId=max(0,(int)($values['quiz_id']??0));
    $roundsText=trim((string)($values['rounds']??''));
    $rules=trim((string)($values['rules']??''));

    $add('title','Название игры',$title!=='','Укажите название игры.');
    $add('excerpt','Краткое описание для карточки',$excerpt!=='','Добавьте краткое описание, которое увидит организатор в каталоге.');
    $add('content','Сюжет / ситуация',$content!=='','Добавьте полный сюжет или описание игровой ситуации.');
    $add('category','Категория',$category!=='','Укажите категорию готовой игры.');
    $add('audience','Аудитория',$audience!=='','Укажите, для кого предназначена игра.');
    $add('price','Цена',$price>0,'Для платного каталога укажите цену больше 0 ₽.');
    $add('product_key','Ключ оплаты',$productKey!=='','Укажите уникальный ключ оплаты.');

    $productUnique=true;
    if($productKey!==''){
        $ids=get_posts([
            'post_type'=>ckm_quiz_pro_ready_game_post_type(),
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>-1,
            'fields'=>'ids',
            'meta_key'=>'_ckm_ready_product_key',
            'meta_value'=>$productKey,
            'no_found_rows'=>true,
            'suppress_filters'=>false,
        ]);
        foreach($ids as $id){
            if((int)$id!==$postId){ $productUnique=false; break; }
        }
    }
    $add('product_unique','Уникальный ключ оплаты',$productKey!=='' && $productUnique,'Такой ключ оплаты уже используется другой готовой игрой.');

    $quizOk=false;
    if($quizId>0 && function_exists('ckm_quiz_pro_table')){
        global $wpdb;
        $quiz=$wpdb->get_row($wpdb->prepare(
            'SELECT id,status FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',
            $quizId
        ),ARRAY_A);
        $quizOk=is_array($quiz) && (string)($quiz['status']??'')==='published';
    }
    $add('quiz','Опубликованный игровой шаблон',$quizOk,'Выберите существующий опубликованный игровой шаблон.');

    $rounds=ckm_quiz_pro_ready_game_parse_pairs($roundsText);
    $add('rounds','Раунды / этапы',count($rounds)>0,'Опишите хотя бы один раунд или этап игры.');
    $add('rules','Правила определения результата',$rules!=='','Опишите, как определяется результат или победитель игры.');

    return ['ready'=>count($errors)===0,'checks'=>$checks,'errors'=>$errors];
}

function ckm_quiz_pro_ready_game_validate_post(int $postId): array {
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()){
        return ['ready'=>false,'checks'=>[],'errors'=>['post'=>'Готовая игра не найдена.']];
    }
    $m=ckm_quiz_pro_ready_game_meta($postId);
    return ckm_quiz_pro_ready_game_validate_values([
        'title'=>(string)$post->post_title,
        'excerpt'=>(string)$post->post_excerpt,
        'content'=>(string)$post->post_content,
        'category'=>(string)$m['category'],
        'audience'=>(string)$m['audience'],
        'price'=>(int)$m['price'],
        'product_key'=>(string)$m['product_key'],
        'quiz_id'=>(int)$m['quiz_id'],
        'rounds'=>(string)$m['rounds'],
        'rules'=>(string)$m['rules'],
    ],$postId);
}

function ckm_quiz_pro_ready_game_render_readiness(array $validation,string $heading='Готовность к публикации'): void {
    $ready=!empty($validation['ready']);
    echo '<div style="max-width:920px;margin:16px 0;padding:14px 16px;border:1px solid '.($ready?'#00a32a':'#dba617').';border-radius:8px;background:#fff">';
    echo '<h2 style="margin:0 0 10px;font-size:16px">'.esc_html($heading).' — '.($ready?'<span style="color:#008a20">готова</span>':'<span style="color:#996800">есть незаполненные пункты</span>').'</h2>';
    echo '<ul style="margin:0;columns:2;max-width:900px">';
    foreach((array)($validation['checks']??[]) as $check){
        $ok=!empty($check['ok']);
        echo '<li style="margin:4px 18px 4px 0">'.($ok?'✓':'✕').' '.esc_html((string)($check['label']??'')).'</li>';
    }
    echo '</ul>';
    if(!$ready && !empty($validation['errors'])){
        echo '<p style="margin:10px 0 0"><strong>Опубликовать игру нельзя, пока не исправлены отмеченные пункты.</strong></p>';
    }
    echo '</div>';
}


/**
 * Deep integrity preflight for a finished catalogue game.
 * Unlike the publication checklist, this validates the linked technical quiz
 * and its current revision so a commercially visible game cannot point to a
 * broken or incomplete runtime source.
 */
function ckm_quiz_pro_ready_game_integrity_check(int $postId): array {
    $checks=[];$errors=[];$warnings=[];$details=[];
    $add=static function(string $key,string $label,bool $ok,string $severity='error',string $message='') use (&$checks,&$errors,&$warnings): void {
        $checks[$key]=['label'=>$label,'ok'=>$ok,'severity'=>$severity];
        if($ok) return;
        $message=$message!==''?$message:$label;
        if($severity==='warning') $warnings[$key]=$message; else $errors[$key]=$message;
    };
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()){
        return ['ok'=>false,'checks'=>[],'errors'=>['post'=>'Готовая игра не найдена.'],'warnings'=>[],'details'=>[]];
    }
    $publication=ckm_quiz_pro_ready_game_validate_post($postId);
    $add('catalog_ready','Карточка каталога заполнена',!empty($publication['ready']),'error','Сначала исправьте чек-лист готовности к публикации.');
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $quizId=max(0,(int)$m['quiz_id']);
    $add('quiz_selected','Игровой шаблон привязан',$quizId>0,'error','К карточке не привязан игровой шаблон.');
    if($quizId<=0) return ['ok'=>false,'checks'=>$checks,'errors'=>$errors,'warnings'=>$warnings,'details'=>$details];
    if(!function_exists('ckm_quiz_pro_table')){
        $add('storage','Хранилище игрового движка доступно',false,'error','Не удалось получить таблицы игрового движка.');
        return ['ok'=>false,'checks'=>$checks,'errors'=>$errors,'warnings'=>$warnings,'details'=>$details];
    }
    global $wpdb;
    $quiz=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',$quizId),ARRAY_A);
    $exists=is_array($quiz) && !empty($quiz['id']);
    $add('quiz_exists','Связанный шаблон существует',$exists,'error','Связанный технический шаблон удалён или недоступен.');
    if(!$exists) return ['ok'=>false,'checks'=>$checks,'errors'=>$errors,'warnings'=>$warnings,'details'=>$details];

    $published=(string)($quiz['status']??'')==='published';
    $add('quiz_published','Шаблон опубликован',$published,'error','Связанный игровой шаблон должен иметь статус published.');
    $revision=max(1,(int)($quiz['current_revision']??1));
    $format=sanitize_key((string)($quiz['format_key']??''));
    $supported=['classic_quiz','chgk','jeopardy','solution_price','negotiation_duel'];
    $add('format','Формат поддерживается текущим движком',in_array($format,$supported,true),'error','Формат «'.$format.'» не входит в набор запускаемых форматов этой сборки.');
    $details['quiz_id']=$quizId;$details['quiz_revision']=$revision;$details['format_key']=$format;

    foreach(['settings_json'=>'Основные настройки JSON','format_settings_json'=>'Настройки формата JSON','scoring_policy_json'=>'Политика подсчёта JSON'] as $field=>$label){
        $raw=trim((string)($quiz[$field]??''));
        $ok=$raw==='' || is_array(json_decode($raw,true));
        $add('json_'.$field,$label,$ok,'error','Поле '.$field.' содержит некорректный JSON.');
    }

    $minTeams=max(0,(int)($quiz['min_teams']??0));
    $maxTeams=max(0,(int)($quiz['max_teams']??0));
    $add('teams','Диапазон количества команд корректен',$minTeams>=1 && $maxTeams>=$minTeams,'error','Проверьте min_teams/max_teams игрового шаблона.');
    $details['min_teams']=$minTeams;$details['max_teams']=$maxTeams;

    $questions=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".ckm_quiz_pro_table('questions')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",
        $quizId,$revision
    ));
    $rounds=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".ckm_quiz_pro_table('rounds')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active'",
        $quizId,$revision
    ));
    $details['active_questions']=$questions;$details['active_rounds']=$rounds;
    $add('content','В текущей редакции есть игровое содержание',($questions+$rounds)>0,'error','В текущей редакции нет ни активных вопросов, ни активных раундов.');

    $blankQuestions=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".ckm_quiz_pro_table('questions')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND (question_text IS NULL OR TRIM(question_text)='')",
        $quizId,$revision
    ));
    $add('question_text','У активных вопросов заполнен текст',$blankQuestions===0,'error','Есть активные вопросы без текста: '.$blankQuestions.'.');

    $orphanRounds=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM ".ckm_quiz_pro_table('questions')." q LEFT JOIN ".ckm_quiz_pro_table('rounds')." r ON r.id=q.round_id AND r.quiz_id=q.quiz_id AND r.quiz_revision=q.quiz_revision WHERE q.quiz_id=%d AND q.quiz_revision=%d AND q.status='active' AND q.round_id>0 AND r.id IS NULL",
        $quizId,$revision
    ));
    $add('round_refs','Ссылки вопросов на раунды целы',$orphanRounds===0,'error','Найдены вопросы со ссылками на отсутствующие раунды: '.$orphanRounds.'.');

    if($format==='negotiation_duel'){
        $settings=json_decode((string)($quiz['format_settings_json']??''),true);
        if(!is_array($settings)) $settings=[];
        $isPersuade=(string)($settings['negotiationMode']??'')==='communicate';
        if($isPersuade){
            $add('persuade_teams','«Переговори другого» настроена на 2 команды',$minTeams===2 && $maxTeams===2,'error','Для новой версии «Переговори другого» требуется ровно 2 команды.');
            $content=is_array($settings['persuadeMeContent']??null)?$settings['persuadeMeContent']:[];
            $fourRounds=true;
            foreach(['round1','round2','round3','round4'] as $rk){ if(empty($content[$rk]) || !is_array($content[$rk])){$fourRounds=false;break;} }
            $add('persuade_rounds','В содержании «Переговори другого» есть все 4 раунда',$fourRounds,'error','В persuadeMeContent отсутствует один или несколько обязательных раундов round1–round4.');
            $rubric=(string)($quiz['instructions']??'');
            $add('persuade_280','Описание игры содержит шкалу 280',str_contains($rubric,'280'),'warning','Проверьте, что описание/инструкция соответствует текущей шкале 70×4 = 280.');
        }
    }

    if($format==='jeopardy'){
        $badCells=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM ".ckm_quiz_pro_table('questions')." WHERE quiz_id=%d AND quiz_revision=%d AND status='active' AND (jeopardy_category_title='' OR jeopardy_value<=0)",
            $quizId,$revision
        ));
        $add('jeopardy_cells','У ячеек заполнены категория и стоимость',$badCells===0,'warning','Есть ячейки «Своей игры» без категории или стоимости: '.$badCells.'.');
    }

    $result=['ok'=>count($errors)===0,'checks'=>$checks,'errors'=>$errors,'warnings'=>$warnings,'details'=>$details,'checked_at'=>current_time('mysql')];
    update_post_meta($postId,'_ckm_ready_integrity_result',wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    update_post_meta($postId,'_ckm_ready_integrity_checked_at',(string)$result['checked_at']);
    update_post_meta($postId,'_ckm_ready_integrity_quiz_revision',$revision);
    update_post_meta($postId,'_ckm_ready_integrity_plugin_version',defined('CKM_QUIZ_PRO_VERSION')?CKM_QUIZ_PRO_VERSION:'');
    return $result;
}

function ckm_quiz_pro_ready_game_integrity_cached(int $postId): array {
    $raw=(string)get_post_meta($postId,'_ckm_ready_integrity_result',true);
    $data=$raw!==''?json_decode($raw,true):[];
    return is_array($data)?$data:[];
}

function ckm_quiz_pro_ready_game_integrity_invalidate(int $postId): void {
    delete_post_meta($postId,'_ckm_ready_integrity_result');
    delete_post_meta($postId,'_ckm_ready_integrity_checked_at');
    delete_post_meta($postId,'_ckm_ready_integrity_quiz_revision');
    delete_post_meta($postId,'_ckm_ready_integrity_plugin_version');
}


/**
 * QA gate for commercially published ready games.
 * A successful administrator test belongs to the exact catalogue/template
 * fingerprint that was tested. Any content or source-template revision change
 * makes the previous pass stale automatically.
 */
function ckm_quiz_pro_ready_game_qa_fingerprint(int $postId): string {
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return '';
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $quizRevision=0;$quizContract='';
    if((int)$m['quiz_id']>0 && function_exists('ckm_quiz_pro_table')){
        global $wpdb;
        $quizRow=$wpdb->get_row($wpdb->prepare(
            'SELECT current_revision,min_teams,max_teams,host_mode,judge_mode,format_settings_json,settings_json,scoring_policy_json FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',
            (int)$m['quiz_id']
        ),ARRAY_A);
        if($quizRow){
            $quizRevision=max(0,(int)$quizRow['current_revision']);
            $quizContract=hash('sha256',wp_json_encode($quizRow,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
    $payload=[
        'title'=>(string)$post->post_title,
        'excerpt'=>(string)$post->post_excerpt,
        'content'=>(string)$post->post_content,
        'category'=>(string)$m['category'],
        'subtitle'=>(string)$m['subtitle'],
        'audience'=>(string)$m['audience'],
        'price'=>(int)$m['price'],
        'product_key'=>(string)$m['product_key'],
        'quiz_id'=>(int)$m['quiz_id'],
        'quiz_revision'=>$quizRevision,
        'quiz_contract'=>$quizContract,
        'icon'=>(string)$m['icon'],
        'roles'=>(string)$m['roles'],
        'rounds'=>(string)$m['rounds'],
        'rules'=>(string)$m['rules'],
    ];
    return hash('sha256',wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function ckm_quiz_pro_ready_game_qa_status(int $postId): array {
    $fingerprint=ckm_quiz_pro_ready_game_qa_fingerprint($postId);
    $passedFingerprint=(string)get_post_meta($postId,'_ckm_ready_qa_passed_fingerprint',true);
    $gameId=max(0,(int)get_post_meta($postId,'_ckm_ready_qa_passed_game_id',true));
    $passedAt=(string)get_post_meta($postId,'_ckm_ready_qa_passed_at',true);
    $passedBy=max(0,(int)get_post_meta($postId,'_ckm_ready_qa_passed_by',true));
    $passed=$fingerprint!=='' && $passedFingerprint!=='' && hash_equals($fingerprint,$passedFingerprint) && $gameId>0;
    $reason='Тест текущей редакции ещё не подтверждён.';
    if($passed){
        $reason='Тест текущей редакции пройден.';
    } elseif($passedFingerprint!=='' && $fingerprint!=='' && !hash_equals($fingerprint,$passedFingerprint)){
        $reason='После последнего пройденного теста карточка или игровой шаблон были изменены. Нужен новый тест.';
    }
    return [
        'passed'=>$passed,
        'reason'=>$reason,
        'fingerprint'=>$fingerprint,
        'passed_fingerprint'=>$passedFingerprint,
        'game_id'=>$gameId,
        'passed_at'=>$passedAt,
        'passed_by'=>$passedBy,
        'catalog_revision'=>max(1,(int)get_post_meta($postId,'_ckm_ready_qa_catalog_revision',true)),
    ];
}

function ckm_quiz_pro_ready_game_render_qa(array $qa,string $heading='Тестовый прогон'): void {
    $passed=!empty($qa['passed']);
    echo '<div style="max-width:920px;margin:16px 0;padding:14px 16px;border:1px solid '.($passed?'#00a32a':'#dba617').';border-radius:8px;background:#fff">';
    echo '<h2 style="margin:0 0 8px;font-size:16px">'.esc_html($heading).' — '.($passed?'<span style="color:#008a20">тест пройден</span>':'<span style="color:#996800">требуется тест</span>').'</h2>';
    echo '<p style="margin:0">'.esc_html((string)($qa['reason']??'')).'</p>';
    if($passed){
        echo '<p style="margin:8px 0 0;color:#646970">Тестовая сессия #'.(int)($qa['game_id']??0).($qa['passed_at']!==''?' · '.esc_html((string)$qa['passed_at']):'').'</p>';
    }
    echo '</div>';
}

function ckm_quiz_pro_ready_game_last_test(int $postId): array {
    $gameId=max(0,(int)get_post_meta($postId,'_ckm_ready_qa_last_test_game_id',true));
    if($gameId<=0 || !function_exists('ckm_quiz_pro_table')) return [];
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT id,quiz_id,quiz_revision,status,test_mode,created_by_user_id,created_at,finished_at FROM '.ckm_quiz_pro_table('games').' WHERE id=%d LIMIT 1',
        $gameId
    ),ARRAY_A);
    return is_array($row)?$row:[];
}

function ckm_quiz_pro_ready_game_render_integrity(array $result,string $heading='Проверка целостности игры'): void {
    $ok=!empty($result['ok']);
    echo '<div style="max-width:920px;margin:16px 0;padding:14px 16px;border:1px solid '.($ok?'#00a32a':'#d63638').';border-radius:8px;background:#fff">';
    echo '<h2 style="margin:0 0 10px;font-size:16px">'.esc_html($heading).' — '.($ok?'<span style="color:#008a20">пройдена</span>':'<span style="color:#b32d2e">есть ошибки</span>').'</h2>';
    if(!empty($result['checked_at'])) echo '<p style="margin:0 0 8px;color:#646970">Проверено: '.esc_html((string)$result['checked_at']).'</p>';
    echo '<ul style="margin:0;columns:2;max-width:900px">';
    foreach((array)($result['checks']??[]) as $check){
        $pass=!empty($check['ok']);$sev=(string)($check['severity']??'error');
        $mark=$pass?'✓':($sev==='warning'?'⚠':'✕');
        echo '<li style="margin:4px 18px 4px 0">'.$mark.' '.esc_html((string)($check['label']??'')).'</li>';
    }
    echo '</ul>';
    foreach(['errors'=>'Ошибки','warnings'=>'Предупреждения'] as $key=>$label){
        if(empty($result[$key])) continue;
        echo '<p style="margin:10px 0 4px"><strong>'.esc_html($label).':</strong></p><ul>';
        foreach((array)$result[$key] as $msg) echo '<li>'.esc_html((string)$msg).'</li>';
        echo '</ul>';
    }
    echo '</div>';
}

function ckm_quiz_pro_ready_game_revision_table_ready(): bool {
    if(!function_exists('ckm_quiz_pro_table')) return false;
    global $wpdb;
    $table=ckm_quiz_pro_table('ready_game_revisions');
    return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table;
}

function ckm_quiz_pro_ready_game_current_revision(int $postId): int {
    return max(1,(int)get_post_meta($postId,'_ckm_ready_catalog_revision',true));
}

function ckm_quiz_pro_ready_game_revision_snapshot(int $postId): array {
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return [];
    $meta=ckm_quiz_pro_ready_game_meta($postId);
    $quiz=[];
    if((int)$meta['quiz_id']>0 && function_exists('ckm_quiz_pro_table')){
        global $wpdb;
        $quiz=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',(int)$meta['quiz_id']),ARRAY_A) ?: [];
    }
    return [
        'post'=>[
            'post_title'=>(string)$post->post_title,
            'post_excerpt'=>(string)$post->post_excerpt,
            'post_content'=>(string)$post->post_content,
            'post_status'=>(string)$post->post_status,
        ],
        'meta'=>$meta,
        'source_quiz'=>$quiz,
        'source_quiz_id'=>(int)($meta['quiz_id']??0),
        'source_quiz_revision'=>max(1,(int)($quiz['current_revision']??1)),
    ];
}

function ckm_quiz_pro_ready_game_record_revision(int $postId,string $reason='save'): int {
    if($postId<=0 || !ckm_quiz_pro_ready_game_revision_table_ready()) return 0;
    $snapshot=ckm_quiz_pro_ready_game_revision_snapshot($postId);
    if(!$snapshot) return 0;
    global $wpdb;
    $table=ckm_quiz_pro_table('ready_game_revisions');
    $last=$wpdb->get_row($wpdb->prepare('SELECT revision_no,snapshot_json FROM '.$table.' WHERE catalog_post_id=%d ORDER BY revision_no DESC LIMIT 1',$postId),ARRAY_A);
    $json=wp_json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(is_array($last) && hash('sha256',(string)$last['snapshot_json'])===hash('sha256',(string)$json)){
        $rev=max(1,(int)$last['revision_no']);
        update_post_meta($postId,'_ckm_ready_catalog_revision',$rev);
        return $rev;
    }
    $revision=is_array($last)?max(1,(int)$last['revision_no'])+1:1;
    $ok=$wpdb->insert($table,[
        'catalog_post_id'=>$postId,
        'revision_no'=>$revision,
        'snapshot_json'=>$json,
        'source_quiz_id'=>(int)$snapshot['source_quiz_id'],
        'source_quiz_revision'=>(int)$snapshot['source_quiz_revision'],
        'reason'=>sanitize_key($reason)?:'save',
        'created_by_user_id'=>get_current_user_id(),
        'created_at'=>current_time('mysql'),
    ]);
    if($ok===false) return 0;
    update_post_meta($postId,'_ckm_ready_catalog_revision',$revision);
    return $revision;
}

function ckm_quiz_pro_ready_game_revision_rows(int $postId): array {
    if($postId<=0 || !ckm_quiz_pro_ready_game_revision_table_ready()) return [];
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_game_revisions').' WHERE catalog_post_id=%d ORDER BY revision_no DESC,id DESC',
        $postId
    ),ARRAY_A) ?: [];
}

add_action('ckm_quiz_pro_quiz_saved', static function (int $quizId): void {
    if($quizId<=0 || !current_user_can('manage_options')) return;
    $ids=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish','draft','pending','private'],
        'posts_per_page'=>-1,
        'fields'=>'ids',
        'meta_key'=>'_ckm_ready_quiz_id',
        'meta_value'=>(string)$quizId,
        'no_found_rows'=>true,
    ]);
    foreach($ids as $id){ ckm_quiz_pro_ready_game_record_revision((int)$id,'template-save'); ckm_quiz_pro_ready_game_integrity_invalidate((int)$id); }
}, 20);

add_action('admin_init', static function (): void {
    if(!current_user_can('manage_options') || !ckm_quiz_pro_ready_game_revision_table_ready()) return;
    if(get_option('ckm_quiz_pro_ready_game_revision_seed_v1','')==='1') return;
    $ids=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish','draft','pending','private'],
        'posts_per_page'=>-1,
        'fields'=>'ids',
        'no_found_rows'=>true,
    ]);
    foreach($ids as $id) ckm_quiz_pro_ready_game_record_revision((int)$id,'baseline');
    update_option('ckm_quiz_pro_ready_game_revision_seed_v1','1',false);
}, 30);

/** Build one catalogue item from a ready-game post. */
function ckm_quiz_pro_ready_game_item_from_post(WP_Post $post): array {
    $m=ckm_quiz_pro_ready_game_meta((int)$post->ID);
    $key=(string)$m['product_key'];
    $description=trim((string)$post->post_excerpt);
    if($description==='') $description=wp_trim_words(wp_strip_all_tags((string)$post->post_content),32,'…');
    $status=(string)$post->post_status;
    $validation=ckm_quiz_pro_ready_game_validate_post((int)$post->ID);
    $integrity=ckm_quiz_pro_ready_game_integrity_check((int)$post->ID);
    $qa=ckm_quiz_pro_ready_game_qa_status((int)$post->ID);
    $release=ckm_quiz_pro_ready_game_release_snapshot((int)$post->ID);
    return [
        'catalog_post_id'=>(int)$post->ID,
        'catalog_revision'=>ckm_quiz_pro_ready_game_current_revision((int)$post->ID),
        'has_live_release'=>!empty($release),
        'live_release_revision'=>max(0,(int)($release['catalog_revision']??0)),
        'live_release_quiz_id'=>max(0,(int)($release['quiz_id']??0)),
        'catalog_status'=>$status,
        'ready_for_publish'=>!empty($validation['ready']) && !empty($integrity['ok']) && !empty($qa['passed']),
        'validation_errors'=>(array)($validation['errors']??[]),
        'integrity_ok'=>!empty($integrity['ok']),
        'integrity_errors'=>(array)($integrity['errors']??[]),
        'integrity_warnings'=>(array)($integrity['warnings']??[]),
        'qa_passed'=>!empty($qa['passed']),
        'qa_reason'=>(string)($qa['reason']??''),
        'orderable'=>$status==='publish' && !empty($validation['ready']) && !empty($integrity['ok']) && !empty($qa['passed']),
        'hidden'=>$status==='private',
        'draft'=>$status==='draft',
        'title'=>(string)$post->post_title,
        'full_title'=>(string)($m['subtitle'] ?: $post->post_title),
        'description'=>$description,
        'audience'=>(string)$m['audience'],
        'product'=>$key,
        'category'=>(string)$m['category'],
        'icon'=>(string)($m['icon'] ?: '◆'),
        'price'=>(int)$m['price'],
        'quiz_id'=>(int)$m['quiz_id'],
        'situation'=>wp_strip_all_tags((string)$post->post_content),
        'roles'=>ckm_quiz_pro_ready_game_parse_pairs((string)$m['roles']),
        'rounds'=>ckm_quiz_pro_ready_game_parse_pairs((string)$m['rounds']),
        'rules'=>(string)$m['rules'],
    ];
}


/** Normalize one catalogue value for semantic release comparison. */
function ckm_quiz_pro_ready_game_diff_normalize($value): string {
    if(is_array($value)){
        $value=wp_json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } elseif(is_bool($value)){
        $value=$value?'1':'0';
    }
    $text=wp_strip_all_tags((string)$value);
    $text=preg_replace('/\s+/u',' ',trim($text));
    return is_string($text)?$text:'';
}

/** Short human-readable value used by the administrator diff table. */
function ckm_quiz_pro_ready_game_diff_display($value,string $key=''): string {
    if(is_array($value)){
        $parts=[];
        foreach($value as $label=>$description){
            if(is_array($description)){
                $parts[]=(string)$label.': '.wp_json_encode($description,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            }else{
                $label=is_string($label)?trim($label):'';
                $description=trim((string)$description);
                $parts[]=$label!=='' ? $label.($description!==''?' — '.$description:'') : $description;
            }
        }
        $value=implode("\n",array_filter($parts,static fn($v)=>trim((string)$v)!==''));
    }
    if($key==='price' && is_numeric($value)) return number_format_i18n((int)$value).' ₽ / 30 дней';
    $text=trim(wp_strip_all_tags((string)$value));
    if($text==='') return '—';
    return wp_trim_words($text,42,'…');
}

/**
 * Compare the administrator's current working version with the stable release
 * that new buyers receive. This never mutates either side.
 */
function ckm_quiz_pro_ready_game_compare_to_release(int $postId): array {
    $post=$postId>0?get_post($postId):null;
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return ['ok'=>false,'error'=>'not_found','changes'=>[]];
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    $current=ckm_quiz_pro_ready_game_item_from_post($post);
    $currentRevision=ckm_quiz_pro_ready_game_current_revision($postId);
    if(!$release){
        return [
            'ok'=>true,'has_release'=>false,'has_changes'=>true,'changes'=>[],
            'working_revision'=>$currentRevision,'release_revision'=>0,'release_no'=>0,
        ];
    }

    $labels=[
        'title'=>'Название',
        'full_title'=>'Серия / подзаголовок',
        'description'=>'Краткое описание',
        'situation'=>'Сюжет / ситуация',
        'category'=>'Категория',
        'audience'=>'Для кого',
        'price'=>'Цена',
        'roles'=>'Роли',
        'rounds'=>'Раунды / этапы',
        'rules'=>'Определение результата',
    ];
    $changes=[];
    foreach($labels as $key=>$label){
        $old=$release[$key]??'';
        $new=$current[$key]??'';
        if(ckm_quiz_pro_ready_game_diff_normalize($old)===ckm_quiz_pro_ready_game_diff_normalize($new)) continue;
        $changes[]=[
            'key'=>$key,'label'=>$label,
            'old'=>ckm_quiz_pro_ready_game_diff_display($old,$key),
            'new'=>ckm_quiz_pro_ready_game_diff_display($new,$key),
        ];
    }

    $meta=ckm_quiz_pro_ready_game_meta($postId);
    $currentQuizId=max(0,(int)($meta['quiz_id']??0));
    $currentQuizRevision=1;
    if($currentQuizId>0 && function_exists('ckm_quiz_pro_table')){
        global $wpdb;
        $currentQuizRevision=max(1,(int)$wpdb->get_var($wpdb->prepare(
            'SELECT current_revision FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',$currentQuizId
        )));
    }
    $releaseSourceId=max(0,(int)($release['source_quiz_id']??0));
    $releaseSourceRevision=max(1,(int)($release['source_quiz_revision']??1));
    if($currentQuizId!==$releaseSourceId || $currentQuizRevision!==$releaseSourceRevision){
        $changes[]=[
            'key'=>'source_quiz','label'=>'Игровой шаблон',
            'old'=>$releaseSourceId>0 ? '#'.$releaseSourceId.' / rev '.$releaseSourceRevision : '—',
            'new'=>$currentQuizId>0 ? '#'.$currentQuizId.' / rev '.$currentQuizRevision : '—',
        ];
    }

    return [
        'ok'=>true,'has_release'=>true,'has_changes'=>!empty($changes),'changes'=>$changes,
        'working_revision'=>$currentRevision,
        'release_revision'=>max(1,(int)($release['catalog_revision']??1)),
        'release_no'=>max(0,(int)($release['release_no']??get_post_meta($postId,'_ckm_ready_release_no',true))),
        'working_quiz_id'=>$currentQuizId,'working_quiz_revision'=>$currentQuizRevision,
        'release_source_quiz_id'=>$releaseSourceId,'release_source_quiz_revision'=>$releaseSourceRevision,
    ];
}

/** Administrator screen: working version versus the release currently sold. */
function ckm_quiz_pro_ready_game_admin_compare(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $diff=ckm_quiz_pro_ready_game_compare_to_release($postId);
    echo '<div class="wrap"><h1>Сравнение версий: '.esc_html((string)$post->post_title).'</h1>';
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a> ';
    echo '<a class="button button-primary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId])).'">Редактировать рабочую версию</a> ';
    echo '<a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'history','id'=>$postId])).'">История редакций</a> ';
    if(!empty($diff['has_release'])) echo '<a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'releases','id'=>$postId])).'">Опубликованные релизы</a>';
    echo '</p>';
    if(empty($diff['has_release'])){
        echo '<div class="notice notice-info inline"><p><strong>Первой публикации ещё не было.</strong> Сравнивать пока не с чем: после первого QA-релиза здесь появится точный список отличий рабочей версии от версии в продаже.</p></div></div>';
        return;
    }
    $releaseLabel='v'.(int)$diff['release_revision'].((int)$diff['release_no']>0?' · R'.(int)$diff['release_no']:'');
    echo '<p><strong>В продаже:</strong> '.esc_html($releaseLabel).' &nbsp; <strong>Рабочая:</strong> v'.(int)$diff['working_revision'].'</p>';
    if(empty($diff['has_changes'])){
        echo '<div class="notice notice-success inline"><p><strong>Отличий нет.</strong> Рабочая версия совпадает по содержанию и игровому шаблону с текущим стабильным релизом.</p></div></div>';
        return;
    }
    echo '<div class="notice notice-warning inline"><p><strong>Есть неопубликованные изменения: '.count((array)$diff['changes']).'.</strong> Они не попадут к новым покупателям, пока новая редакция не пройдёт проверку, тестовый запуск и публикацию.</p></div>';
    echo '<table class="widefat striped" style="max-width:1100px;margin-top:16px"><thead><tr><th style="width:20%">Что изменилось</th><th style="width:40%">Сейчас в продаже</th><th style="width:40%">Рабочая версия</th></tr></thead><tbody>';
    foreach((array)$diff['changes'] as $change){
        echo '<tr><td><strong>'.esc_html((string)$change['label']).'</strong></td><td style="white-space:pre-wrap">'.esc_html((string)$change['old']).'</td><td style="white-space:pre-wrap">'.esc_html((string)$change['new']).'</td></tr>';
    }
    echo '</tbody></table></div>';
}

add_action('add_meta_boxes', static function (): void {
    add_meta_box('ckm-ready-game-settings','Параметры каталога','ckm_quiz_pro_ready_game_meta_box',ckm_quiz_pro_ready_game_post_type(),'normal','high');
});

function ckm_quiz_pro_ready_game_meta_box(WP_Post $post): void {
    if(!current_user_can('manage_options')) return;
    $m=ckm_quiz_pro_ready_game_meta((int)$post->ID);
    wp_nonce_field('ckm_quiz_pro_ready_game_save','ckm_ready_game_nonce');
    echo '<p><strong>Как заполнять:</strong> заголовок записи — название игры; краткое описание — поле «Отрывок»; полный сюжет — основной редактор WordPress.</p>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="ckm_ready_category">Категория</label></th><td><input class="regular-text" id="ckm_ready_category" name="ckm_ready_category" value="'.esc_attr((string)$m['category']).'" placeholder="Например: Школьные ситуации"></td></tr>';
    echo '<tr><th><label for="ckm_ready_subtitle">Подзаголовок</label></th><td><input class="regular-text" id="ckm_ready_subtitle" name="ckm_ready_subtitle" value="'.esc_attr((string)$m['subtitle']).'" placeholder="Например: Переговори другого — для школьников"></td></tr>';
    echo '<tr><th><label for="ckm_ready_audience">Для кого</label></th><td><input class="regular-text" id="ckm_ready_audience" name="ckm_ready_audience" value="'.esc_attr((string)$m['audience']).'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_price">Цена, ₽ / 30 дней</label></th><td><input type="number" min="0" step="10" id="ckm_ready_price" name="ckm_ready_price" value="'.(int)$m['price'].'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_product_key">Ключ оплаты</label></th><td><input class="regular-text" id="ckm_ready_product_key" name="ckm_ready_product_key" value="'.esc_attr((string)$m['product_key']).'"><p class="description">Уникальный системный ключ. Для новой игры можно оставить значение, созданное автоматически.</p></td></tr>';
    global $wpdb;
    $quizRows=[];
    if(function_exists('ckm_quiz_pro_table')){
        $quizRows=$wpdb->get_results("SELECT id,title,slug,format_key FROM ".ckm_quiz_pro_table('quizzes')." WHERE status='published' AND slug NOT LIKE 'ready-release-%' ORDER BY title,id",ARRAY_A) ?: [];
    }
    echo '<tr><th><label for="ckm_ready_quiz_id">Игровой шаблон</label></th><td><select id="ckm_ready_quiz_id" name="ckm_ready_quiz_id"><option value="0">— выберите опубликованный шаблон —</option>';
    foreach($quizRows as $q){
        $label=(string)$q['title'].' · #'.(int)$q['id'].' · '.(string)$q['format_key'];
        echo '<option value="'.(int)$q['id'].'" '.selected((int)$m['quiz_id'],(int)$q['id'],false).'>'.esc_html($label).'</option>';
    }
    echo '</select><p class="description">После покупки запускается именно этот шаблон. Его содержимое редактирует только администратор в «Технических шаблонах».</p>';
    if((int)$m['quiz_id']>0) echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit&quiz='.(int)$m['quiz_id'])).'">Редактировать игровой шаблон</a></p>';
    echo '</td></tr>';
    echo '<tr><th><label for="ckm_ready_icon">Значок</label></th><td><input class="small-text" id="ckm_ready_icon" name="ckm_ready_icon" value="'.esc_attr((string)$m['icon']).'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_roles">Роли</label></th><td><textarea class="large-text" rows="5" id="ckm_ready_roles" name="ckm_ready_roles" placeholder="Переговорщик | Лера — десятиклассница...\nОппонент | Виктор Палыч — учитель химии...">'.esc_textarea((string)$m['roles']).'</textarea><p class="description">Одна роль на строку: Название роли | описание.</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_rounds">Этапы / раунды</label></th><td><textarea class="large-text" rows="6" id="ckm_ready_rounds" name="ckm_ready_rounds" placeholder="Р1 · Удержи цель | Описание этапа">'.esc_textarea((string)$m['rounds']).'</textarea><p class="description">Один этап на строку: название | описание. Подходит не только для «Переговори другого».</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_rules">Как определяется результат</label></th><td><textarea class="large-text" rows="4" id="ckm_ready_rules" name="ckm_ready_rules" placeholder="Опишите систему баллов и правило определения победителя">'.esc_textarea((string)$m['rules']).'</textarea></td></tr>';
    echo '</tbody></table>';
}

add_action('save_post_'.'ckm_ready_game', static function (int $postId, WP_Post $post): void {
    if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if(!current_user_can('manage_options')) return;
    $nonce=sanitize_text_field(wp_unslash($_POST['ckm_ready_game_nonce'] ?? ''));
    if($nonce==='' || !wp_verify_nonce($nonce,'ckm_quiz_pro_ready_game_save')) return;
    $fields=[
        'category'=>sanitize_text_field(wp_unslash($_POST['ckm_ready_category'] ?? 'Другие игры')),
        'subtitle'=>sanitize_text_field(wp_unslash($_POST['ckm_ready_subtitle'] ?? '')),
        'audience'=>sanitize_text_field(wp_unslash($_POST['ckm_ready_audience'] ?? '')),
        'price'=>max(0,(int)($_POST['ckm_ready_price'] ?? 990)),
        'product_key'=>sanitize_key((string)wp_unslash($_POST['ckm_ready_product_key'] ?? '')),
        'quiz_id'=>max(0,(int)($_POST['ckm_ready_quiz_id'] ?? 0)),
        'icon'=>sanitize_text_field(wp_unslash($_POST['ckm_ready_icon'] ?? '◆')),
        'roles'=>sanitize_textarea_field(wp_unslash($_POST['ckm_ready_roles'] ?? '')),
        'rounds'=>sanitize_textarea_field(wp_unslash($_POST['ckm_ready_rounds'] ?? '')),
        'rules'=>sanitize_textarea_field(wp_unslash($_POST['ckm_ready_rules'] ?? '')),
    ];
    if($fields['category']==='') $fields['category']='Другие игры';
    if($fields['product_key']==='') $fields['product_key']='ready_game_'.$postId;
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    if(!empty($release['product'])) $fields['product_key']=sanitize_key((string)$release['product']);
    foreach($fields as $key=>$value) update_post_meta($postId,'_ckm_ready_'.$key,$value);
    ckm_quiz_pro_ready_game_integrity_invalidate($postId);
    ckm_quiz_pro_ready_game_record_revision($postId,'wp-save');
}, 10, 2);



/**
 * Dedicated administrator UI for the finished-games catalogue.
 * The CPT is only the storage layer; administrators manage entries here.
 */
add_action('admin_menu', static function (): void {
    add_submenu_page(
        'ckm-quiz-pro',
        'Каталог готовых игр',
        'Каталог готовых игр',
        'manage_options',
        'ckm-quiz-pro-ready-games',
        'ckm_quiz_pro_ready_games_admin_page',
        7
    );
}, 18);

function ckm_quiz_pro_ready_games_admin_url(array $args=[]): string {
    return add_query_arg($args, admin_url('admin.php?page=ckm-quiz-pro-ready-games'));
}


/**
 * Administrative sale/grant helpers for finished catalogue games.
 * A sale link never grants access by itself: it only opens the normal checkout
 * for the selected catalogue product. A free grant uses the same entitlement
 * and immutable snapshot path as a confirmed payment.
 */
function ckm_quiz_pro_ready_game_admin_sale_targets(): array {
    if(!defined('CKM_QUIZ_PRO_ORGANIZER_ROLE')) return [];
    $users=get_users(['role'=>CKM_QUIZ_PRO_ORGANIZER_ROLE,'orderby'=>'display_name','order'=>'ASC']);
    $rows=[];
    foreach($users as $user){
        $uid=(int)$user->ID;
        $sites=function_exists('ckmqp_my_sites') ? ckmqp_my_sites($uid) : [];
        if(!$sites){
            $rows[]=[
                'user_id'=>$uid,'display_name'=>(string)$user->display_name,'login'=>(string)$user->user_login,'email'=>(string)$user->user_email,
                'tenant_id'=>0,'site_name'=>'Основная платформа','hostname'=>'',
            ];
            continue;
        }
        foreach($sites as $site){
            $rows[]=[
                'user_id'=>$uid,'display_name'=>(string)$user->display_name,'login'=>(string)$user->user_login,'email'=>(string)$user->user_email,
                'tenant_id'=>max(0,(int)($site['id']??0)),'site_name'=>(string)($site['name']?:($site['hostname']??'')),'hostname'=>(string)($site['hostname']??''),
            ];
        }
    }
    return $rows;
}

function ckm_quiz_pro_ready_game_admin_sale_link(int $postId,int $userId,int $tenantId=0,bool $renew=false): string {
    if($postId<=0 || $userId<=0) return '';
    $post=get_post($postId);
    $user=get_user_by('id',$userId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type() || !$user || !defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE,(array)$user->roles,true)) return '';
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    if(!$release || (int)($release['quiz_id']??0)<=0) return '';
    $product=sanitize_key((string)($release['product']??ckm_quiz_pro_ready_game_meta($postId)['product_key']??''));
    if($product==='' || !isset(ckm_quiz_pro_game_access_products()[$product])) return '';
    if($tenantId>0){
        if(!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenantId,$userId)) return '';
        $login=ckm_quiz_pro_login_url();
        $login=function_exists('ckmqp_tenant_link') ? ckmqp_tenant_link($login,$tenantId) : '';
        if($login==='') return '';
    }else{
        $login=ckm_quiz_pro_login_url();
    }
    $args=['buy'=>$product];
    if($renew) $args['renew']=1;
    return add_query_arg($args,$login);
}

function ckm_quiz_pro_ready_game_admin_grant_free(int $postId,int $userId,int $tenantId=0,int $days=30): array {
    if(!current_user_can('manage_options')) return ['ok'=>false,'error'=>'Недостаточно прав.'];
    $post=get_post($postId);
    $user=get_user_by('id',$userId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return ['ok'=>false,'error'=>'Готовая игра не найдена.'];
    if(!$user || !defined('CKM_QUIZ_PRO_ORGANIZER_ROLE') || !in_array(CKM_QUIZ_PRO_ORGANIZER_ROLE,(array)$user->roles,true)) return ['ok'=>false,'error'=>'Организатор не найден.'];
    if($tenantId>0 && (!function_exists('ckmqp_tenant_is_member') || !ckmqp_tenant_is_member($tenantId,$userId))) return ['ok'=>false,'error'=>'Организатор не привязан к выбранной площадке.'];
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    if(!$release || (int)($release['quiz_id']??0)<=0) return ['ok'=>false,'error'=>'Сначала опубликуйте стабильный релиз игры.'];
    $product=sanitize_key((string)($release['product']??ckm_quiz_pro_ready_game_meta($postId)['product_key']??''));
    if($product==='' || !isset(ckm_quiz_pro_game_access_products()[$product])) return ['ok'=>false,'error'=>'Ключ доступа игры не найден.'];
    if(function_exists('ckm_quiz_pro_can_access_format') && ckm_quiz_pro_can_access_format($userId,$product,$tenantId)){
        $snapshot=function_exists('ckm_quiz_pro_ready_game_snapshot_ensure') ? ckm_quiz_pro_ready_game_snapshot_ensure($userId,$product,$tenantId) : 0;
        return ['ok'=>true,'already_active'=>true,'product'=>$product,'snapshot_quiz_id'=>$snapshot];
    }
    $days=max(1,min(365,$days));
    $grantRef='admin-free-ready:'.$postId.':'.$userId.':'.$tenantId.':'.gmdate('Y-m-d');
    $ok=function_exists('ckm_quiz_pro_payment_create_access') && ckm_quiz_pro_payment_create_access($userId,$product,$days,$tenantId,$grantRef);
    if(!$ok) return ['ok'=>false,'error'=>'Не удалось выдать доступ. Проверьте площадку и права организатора.'];
    $snapshot=function_exists('ckm_quiz_pro_ready_game_snapshot_ensure') ? ckm_quiz_pro_ready_game_snapshot_ensure($userId,$product,$tenantId) : 0;
    if($snapshot<=0) return ['ok'=>false,'error'=>'Доступ создан, но snapshot игры не сформирован. Проверьте релизный шаблон.'];
    return ['ok'=>true,'already_active'=>false,'product'=>$product,'snapshot_quiz_id'=>$snapshot];
}

function ckm_quiz_pro_ready_game_admin_sales(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $release=ckm_quiz_pro_ready_game_release_snapshot($postId);
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $product=sanitize_key((string)($release['product']??$m['product_key']??''));
    echo '<div class="wrap"><h1>Продать / выдать: '.esc_html((string)$post->post_title).'</h1>';
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a></p>';
    if(isset($_GET['granted'])) echo '<div class="notice notice-success is-dismissible"><p>Готовая игра выдана организатору бесплатно. Доступ активирован, snapshot создан в «Мои игры».</p></div>';
    if(isset($_GET['already_active'])) echo '<div class="notice notice-info is-dismissible"><p>У организатора уже был активный доступ. Новый период не добавлялся; существующий snapshot сохранён.</p></div>';
    if(isset($_GET['grant_error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash((string)$_GET['grant_error']))).'</p></div>';
    if(!$release || (int)($release['quiz_id']??0)<=0){
        echo '<div class="notice notice-error inline"><p><strong>Продажа заблокирована.</strong> У игры ещё нет стабильного опубликованного релиза. Сначала выполните QA и публикацию.</p></div></div>';
        return;
    }
    if((string)$post->post_status==='private') echo '<div class="notice notice-warning inline"><p>Игра скрыта из публичного каталога. Персональную ссылку можно сформировать для проверки, но для обычной новой продажи сначала верните игру в публикацию.</p></div>';
    echo '<p><strong>Товар:</strong> <code>'.esc_html($product).'</code> · <strong>Цена:</strong> '.(int)($release['price']??$m['price']).' ₽ / 30 дней · <strong>релиз:</strong> R'.max(1,(int)($release['release_no']??1)).'.</p>';
    echo '<p><strong>«Продать»</strong> не выдаёт игру само по себе: ссылка ведёт в штатный checkout, цена берётся с сервера, а snapshot создаётся только после подтверждённой оплаты. <strong>«Выдать бесплатно»</strong> активирует доступ на 30 дней без платежа.</p>';
    $targets=ckm_quiz_pro_ready_game_admin_sale_targets();
    echo '<table class="widefat striped"><thead><tr><th>Организатор</th><th>Площадка</th><th>Доступ</th><th>Ссылка оплаты</th><th>Бесплатная выдача</th></tr></thead><tbody>';
    foreach($targets as $target){
        $uid=(int)$target['user_id'];$tenant=(int)$target['tenant_id'];
        $access=function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info($uid,$product,$tenant) : [];
        $active=!empty($access['active']);
        $sale=ckm_quiz_pro_ready_game_admin_sale_link($postId,$uid,$tenant,$active);
        $siteLabel=$tenant>0 ? ((string)$target['site_name'].' · '.(string)$target['hostname']) : 'Основная платформа';
        echo '<tr><td><strong>'.esc_html((string)$target['display_name']).'</strong><br><code>'.esc_html((string)$target['login']).'</code><br>'.esc_html((string)$target['email']).'</td><td>'.esc_html($siteLabel).'</td><td>'.($active?'<span style="color:#008a20">'.esc_html((string)($access['display_text']??'Доступ активен')).'</span>':'Нет активного доступа').'</td><td>';
        if($sale!==''){
            echo '<input type="text" class="large-text code" readonly value="'.esc_attr($sale).'"> <a class="button button-small" target="_blank" rel="noopener" href="'.esc_url($sale).'">'.esc_html($active?'Продлить через оплату':'Открыть оплату').'</a>';
        } else echo 'Не удалось сформировать ссылку.';
        echo '</td><td>';
        if($active){
            echo '<span class="description">Уже активна — бесплатная выдача не продлевает текущий период.</span>';
        }else{
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin:0">';
            echo '<input type="hidden" name="action" value="ckm_quiz_pro_ready_game_free_grant"><input type="hidden" name="id" value="'.$postId.'"><input type="hidden" name="user_id" value="'.$uid.'"><input type="hidden" name="tenant_id" value="'.$tenant.'"><input type="hidden" name="days" value="30">';
            wp_nonce_field('ckm_quiz_pro_ready_game_free_grant_'.$postId.'_'.$uid.'_'.$tenant);
            echo '<button class="button button-small" type="submit" onclick="return confirm(&quot;Выдать эту готовую игру организатору бесплатно на 30 дней?&quot;);">Выдать бесплатно</button></form>';
        }
        echo '</td></tr>';
    }
    if(!$targets) echo '<tr><td colspan="5">Организаторов пока нет. Сначала создайте организатора в разделе «Организаторы».</td></tr>';
    echo '</tbody></table></div>';
}

add_action('admin_post_ckm_quiz_pro_ready_game_free_grant', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_POST['id']??0));$userId=max(0,(int)($_POST['user_id']??0));$tenantId=max(0,(int)($_POST['tenant_id']??0));$days=max(1,(int)($_POST['days']??30));
    check_admin_referer('ckm_quiz_pro_ready_game_free_grant_'.$postId.'_'.$userId.'_'.$tenantId);
    $result=ckm_quiz_pro_ready_game_admin_grant_free($postId,$userId,$tenantId,$days);
    $args=['action'=>'sales','id'=>$postId];
    if(!empty($result['ok'])) $args[!empty($result['already_active'])?'already_active':'granted']=1;
    else $args['grant_error']=(string)($result['error']??'Не удалось выдать игру.');
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url($args));
    exit;
});

function ckm_quiz_pro_ready_game_admin_form(int $postId=0): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=$postId>0 ? get_post($postId) : null;
    if($postId>0 && (!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type())) wp_die('Готовая игра не найдена.');
    $m=$postId>0 ? ckm_quiz_pro_ready_game_meta($postId) : ckm_quiz_pro_ready_game_meta_defaults();
    $release=$postId>0 ? ckm_quiz_pro_ready_game_release_snapshot($postId) : [];
    $title=$post ? (string)$post->post_title : '';
    $excerpt=$post ? (string)$post->post_excerpt : '';
    $content=$post ? (string)$post->post_content : '';
    $scenarioOrderId=$postId===0 ? max(0,(int)($_GET['scenario_order'] ?? 0)) : 0;
    if($scenarioOrderId>0 && function_exists('ckm_quiz_pro_scenario_order_get')){
        $order=ckm_quiz_pro_scenario_order_get($scenarioOrderId);
        if($order){
            $title=trim((string)$order['theme'])!=='' ? (string)$order['theme'] : 'Игра по заказу #'.$scenarioOrderId;
            $excerpt=wp_trim_words((string)$order['details'],32,'…');
            $content=(string)$order['details'];
            $m['audience']=(string)$order['audience'];
        } else $scenarioOrderId=0;
    }
    $status=$post ? (string)$post->post_status : 'draft';
    global $wpdb;
    $quizRows=[];
    if(function_exists('ckm_quiz_pro_table')){
        $quizRows=$wpdb->get_results("SELECT id,title,slug,format_key,status FROM ".ckm_quiz_pro_table('quizzes')." WHERE slug NOT LIKE 'ready-release-%' ORDER BY title,id",ARRAY_A) ?: [];
    }
    echo '<div class="wrap"><h1>'.($postId>0?'Редактировать готовую игру':'Добавить готовую игру').'</h1>';
    echo '<p><a href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Вернуться в каталог</a></p>';
    if($postId>0){
        echo '<p><strong>Рабочая редакция:</strong> v'.ckm_quiz_pro_ready_game_current_revision($postId);
        if($release){
            echo ' · <strong>Сейчас в каталоге:</strong> v'.max(1,(int)($release['catalog_revision']??1));
            echo ' · <strong>релизный шаблон:</strong> #'.max(0,(int)($release['quiz_id']??0));
        }
        echo '</p>';
        if($release && (int)($release['catalog_revision']??0)!==ckm_quiz_pro_ready_game_current_revision($postId)){
            echo '<div class="notice notice-info inline"><p>Покупатели продолжают видеть последнюю опубликованную версию. Текущие изменения станут публичными только после новой технической проверки, тестового прогона и публикации.</p></div>';
        }
    }
    if(isset($_GET['publish_blocked'])){
        $why=[];
        if(!empty($_GET['integrity_blocked'])) $why[]='исправьте ошибки технической проверки';
        if(!empty($_GET['qa_blocked'])) $why[]='полностью завершите тестовый запуск и подтвердите «Тест пройден»';
        if(!empty($_GET['release_blocked'])) $why[]='не удалось создать неизменяемый релизный снимок игрового шаблона';
        if(!$why) $why[]='заполните обязательные пункты чек-листа';
        echo '<div class="notice notice-error"><p><strong>Публикация заблокирована.</strong> Игра сохранена как черновик: '.esc_html(implode('; ',$why)).'.</p></div>';
    }
    $readiness=$postId>0
        ? ckm_quiz_pro_ready_game_validate_post($postId)
        : ckm_quiz_pro_ready_game_validate_values([
            'title'=>$title,'excerpt'=>$excerpt,'content'=>$content,'category'=>(string)$m['category'],
            'audience'=>(string)$m['audience'],'price'=>(int)$m['price'],'product_key'=>(string)$m['product_key'],
            'quiz_id'=>(int)$m['quiz_id'],'rounds'=>(string)$m['rounds'],'rules'=>(string)$m['rules'],
        ],0);
    ckm_quiz_pro_ready_game_render_readiness($readiness);
    if($postId>0) ckm_quiz_pro_ready_game_render_qa(ckm_quiz_pro_ready_game_qa_status($postId),'Тестовый прогон');
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="ckm_quiz_pro_ready_game_save">';
    echo '<input type="hidden" name="post_id" value="'.(int)$postId.'">';
    if($scenarioOrderId>0) echo '<input type="hidden" name="scenario_order_id" value="'.(int)$scenarioOrderId.'">';
    wp_nonce_field('ckm_quiz_pro_ready_game_admin_save','ckm_ready_admin_nonce');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th><label for="ckm_ready_title">Название игры</label></th><td><input required class="regular-text" id="ckm_ready_title" name="title" value="'.esc_attr($title).'" placeholder="Например: Двойка, которой не было"></td></tr>';
    echo '<tr><th><label for="ckm_ready_excerpt">Краткое описание</label></th><td><textarea class="large-text" rows="3" id="ckm_ready_excerpt" name="excerpt">'.esc_textarea($excerpt).'</textarea><p class="description">Показывается на карточке в каталоге организатора.</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_content">Сюжет / ситуация</label></th><td><textarea class="large-text" rows="8" id="ckm_ready_content" name="content">'.esc_textarea($content).'</textarea></td></tr>';
    echo '<tr><th><label for="ckm_ready_category">Категория</label></th><td><input class="regular-text" id="ckm_ready_category" name="category" value="'.esc_attr((string)$m['category']).'" placeholder="Школьные ситуации"></td></tr>';
    echo '<tr><th><label for="ckm_ready_subtitle">Серия / подзаголовок</label></th><td><input class="regular-text" id="ckm_ready_subtitle" name="subtitle" value="'.esc_attr((string)$m['subtitle']).'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_audience">Для кого</label></th><td><input class="regular-text" id="ckm_ready_audience" name="audience" value="'.esc_attr((string)$m['audience']).'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_price">Цена, ₽ / 30 дней</label></th><td><input type="number" min="0" step="10" id="ckm_ready_price" name="price" value="'.(int)$m['price'].'"></td></tr>';
    $productReadonly=$release?' readonly':'';
    echo '<tr><th><label for="ckm_ready_product_key">Ключ оплаты</label></th><td><input class="regular-text" id="ckm_ready_product_key" name="product_key" value="'.esc_attr((string)$m['product_key']).'"'.$productReadonly.'><p class="description">'.($release?'После первой публикации ключ оплаты фиксируется, чтобы не ломать уже выданные доступы.':'Для новой игры можно оставить пустым — ключ создастся автоматически.').'</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_quiz_id">Игровой шаблон</label></th><td><select id="ckm_ready_quiz_id" name="quiz_id"><option value="0">— без шаблона —</option>';
    foreach($quizRows as $q){
        $label=(string)$q['title'].' · #'.(int)$q['id'].' · '.(string)$q['format_key'].' · '.(string)$q['status'];
        echo '<option value="'.(int)$q['id'].'" '.selected((int)$m['quiz_id'],(int)$q['id'],false).'>'.esc_html($label).'</option>';
    }
    echo '</select> <a class="button" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit')).'">Создать новый шаблон</a>';
    if((int)$m['quiz_id']>0) echo ' <a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit&quiz='.(int)$m['quiz_id'])).'">Редактировать игру</a>';
    echo '<p class="description">Карточка каталога продаёт и запускает выбранный игровой шаблон.</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_icon">Значок</label></th><td><input class="small-text" id="ckm_ready_icon" name="icon" value="'.esc_attr((string)$m['icon']).'"></td></tr>';
    echo '<tr><th><label for="ckm_ready_roles">Роли</label></th><td><textarea class="large-text" rows="5" id="ckm_ready_roles" name="roles">'.esc_textarea((string)$m['roles']).'</textarea><p class="description">Одна роль на строку: Название роли | описание.</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_rounds">Раунды / этапы</label></th><td><textarea class="large-text" rows="6" id="ckm_ready_rounds" name="rounds">'.esc_textarea((string)$m['rounds']).'</textarea><p class="description">Один этап на строку: название | описание.</p></td></tr>';
    echo '<tr><th><label for="ckm_ready_rules">Определение результата</label></th><td><textarea class="large-text" rows="4" id="ckm_ready_rules" name="rules">'.esc_textarea((string)$m['rules']).'</textarea></td></tr>';
    echo '<tr><th><label for="ckm_ready_status">Статус рабочей версии</label></th><td><select id="ckm_ready_status" name="status"><option value="draft" '.selected($status,'draft',false).'>Черновик</option><option value="publish" '.selected($status,'publish',false).'>Опубликовать новую версию</option><option value="private" '.selected($status,'private',false).'>Скрыть из каталога</option></select><p class="description">Если у игры уже есть опубликованный релиз, сохранение новой рабочей редакции не снимает его с продажи. Новая версия заменит релиз только после QA и публикации. «Скрыть» убирает игру из каталога для новых покупателей.</p></td></tr>';
    echo '</tbody></table>';
    if($postId>0){
        echo '<p><a class="button button-primary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'integrity','id'=>$postId])).'">Проверить игру</a> ';
        echo '<a class="button button-secondary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'test-launch','id'=>$postId])).'">Тестовый запуск</a> ';
        echo '<a class="button button-secondary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'preview','id'=>$postId])).'">Предварительный просмотр</a> ';
        echo '<a class="button button-secondary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'history','id'=>$postId])).'">История версий</a> ';
        if($release) echo '<a class="button button-secondary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'compare','id'=>$postId])).'">Сравнить с релизом</a>';
        echo '</p>';
    }
    submit_button($postId>0?'Сохранить изменения':'Добавить игру');
    echo '</form></div>';
}

function ckm_quiz_pro_ready_game_admin_preview(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=$postId>0 ? get_post($postId) : null;
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $item=ckm_quiz_pro_ready_game_item_from_post($post);
    $statusMap=['publish'=>'Опубликована','draft'=>'Черновик','private'=>'Скрыта'];
    $status=$statusMap[(string)$post->post_status] ?? (string)$post->post_status;
    $back=ckm_quiz_pro_ready_games_admin_url();
    $edit=ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId]);
    echo '<div class="wrap"><h1>Предварительный просмотр готовой игры</h1>';
    $testLaunch=ckm_quiz_pro_ready_games_admin_url(['action'=>'test-launch','id'=>$postId]);
    $compare=ckm_quiz_pro_ready_games_admin_url(['action'=>'compare','id'=>$postId]);
    echo '<p><a class="button" href="'.esc_url($back).'">← Каталог</a> <a class="button button-primary" href="'.esc_url($edit).'">Редактировать</a> <a class="button" href="'.esc_url($testLaunch).'">Тестовый запуск</a> '.(ckm_quiz_pro_ready_game_has_release($postId)?'<a class="button" href="'.esc_url($compare).'">Сравнить с релизом</a>':'').'</p>';
    ckm_quiz_pro_ready_game_render_readiness(ckm_quiz_pro_ready_game_validate_post($postId));
    ckm_quiz_pro_ready_game_render_integrity(ckm_quiz_pro_ready_game_integrity_check($postId));
    ckm_quiz_pro_ready_game_render_qa(ckm_quiz_pro_ready_game_qa_status($postId),'Тестовый прогон');
    echo '<div style="max-width:980px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;margin-top:16px">';
    echo '<p><strong>Редакция:</strong> v'.(int)$item['catalog_revision'].' · <strong>Статус:</strong> '.esc_html($status).' · <strong>Категория:</strong> '.esc_html((string)$item['category']).' · <strong>Цена:</strong> '.(int)$item['price'].' ₽ / 30 дней</p>';
    echo '<h2 style="font-size:28px;margin-bottom:4px">'.esc_html((string)$item['title']).'</h2>';
    if((string)$item['full_title']!==(string)$item['title']) echo '<p style="font-size:17px;color:#646970;margin-top:0">'.esc_html((string)$item['full_title']).'</p>';
    echo '<p><strong>Карточка каталога:</strong> '.esc_html((string)$item['description']).'</p>';
    if((string)$item['audience']!=='') echo '<p><strong>Для кого:</strong> '.esc_html((string)$item['audience']).'</p>';
    echo '<hr><h3>Сюжет / ситуация</h3><p>'.nl2br(esc_html((string)$item['situation'])).'</p>';
    if(!empty($item['roles'])){ echo '<h3>Роли</h3><ul>'; foreach($item['roles'] as $role=>$desc) echo '<li><strong>'.esc_html((string)$role).':</strong> '.esc_html((string)$desc).'</li>'; echo '</ul>'; }
    if(!empty($item['rounds'])){ echo '<h3>Раунды / этапы</h3><ol>'; foreach($item['rounds'] as $round=>$desc) echo '<li><strong>'.esc_html((string)$round).'</strong> — '.esc_html((string)$desc).'</li>'; echo '</ol>'; }
    if((string)$item['rules']!=='') echo '<h3>Определение результата</h3><p>'.nl2br(esc_html((string)$item['rules'])).'</p>';
    echo '<p><strong>Связанный игровой шаблон:</strong> '.((int)$item['quiz_id']>0?'#'.(int)$item['quiz_id']:'не выбран').'</p>';
    echo '</div></div>';
}



function ckm_quiz_pro_ready_game_admin_integrity(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=$postId>0 ? get_post($postId) : null;
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $result=ckm_quiz_pro_ready_game_integrity_check($postId);
    $edit=ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId]);
    $preview=ckm_quiz_pro_ready_games_admin_url(['action'=>'preview','id'=>$postId]);
    echo '<div class="wrap"><h1>Проверка игры: '.esc_html((string)$post->post_title).'</h1>';
    $testLaunch=ckm_quiz_pro_ready_games_admin_url(['action'=>'test-launch','id'=>$postId]);
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a> <a class="button" href="'.esc_url($edit).'">Редактировать карточку</a> <a class="button" href="'.esc_url($preview).'">Предпросмотр</a> <a class="button button-primary" href="'.esc_url($testLaunch).'">Тестовый запуск</a></p>';
    echo '<p>Проверка не запускает настоящую игровую сессию и не расходует доступ. Она проверяет связанную редакцию технического шаблона, структуру данных и обязательные условия текущего runtime.</p>';
    ckm_quiz_pro_ready_game_render_readiness(ckm_quiz_pro_ready_game_validate_post($postId),'Карточка каталога');
    ckm_quiz_pro_ready_game_render_integrity($result);
    ckm_quiz_pro_ready_game_render_qa(ckm_quiz_pro_ready_game_qa_status($postId),'Тестовый прогон');
    if(!empty($result['details'])){
        $d=$result['details'];
        echo '<div style="max-width:920px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px"><h2 style="font-size:16px;margin-top:0">Технические данные</h2>';
        echo '<p><strong>Шаблон:</strong> #'.(int)($d['quiz_id']??0).' · <strong>ревизия:</strong> '.(int)($d['quiz_revision']??0).' · <strong>формат:</strong> '.esc_html((string)($d['format_key']??'—')).'</p>';
        echo '<p><strong>Активных раундов:</strong> '.(int)($d['active_rounds']??0).' · <strong>активных вопросов:</strong> '.(int)($d['active_questions']??0).' · <strong>команд:</strong> '.(int)($d['min_teams']??0).'–'.(int)($d['max_teams']??0).'</p></div>';
    }
    if(!empty($result['ok'])) echo '<div class="notice notice-success inline"><p><strong>Проверка пройдена.</strong> Технических блокирующих ошибок не найдено.</p></div>';
    else echo '<div class="notice notice-error inline"><p><strong>Игра не должна публиковаться или продаваться, пока не исправлены ошибки выше.</strong></p></div>';
    echo '</div>';
}


/**
 * Administrator-only live test launch for one catalogue game.
 * Creates a real room with test_mode=1, so payment/access is not consumed and
 * the room is excluded from commercial usage counters. It still uses the real
 * runtime, host/team pages and current source revision.
 */
function ckm_quiz_pro_ready_game_admin_test_launch(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=$postId>0 ? get_post($postId) : null;
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $quizId=max(0,(int)$m['quiz_id']);
    $result=null;
    $quiz=null;
    $minTeams=1;$maxTeams=10;$defaultTeams=2;$hostMode='ai';
    if($quizId>0 && function_exists('ckm_quiz_pro_table')){
        global $wpdb;
        $quiz=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',$quizId),ARRAY_A);
        if($quiz){
            $format=sanitize_key((string)($quiz['format_key']??''));
            $minTeams=max(1,(int)($quiz['min_teams']??1));
            $maxTeams=max($minTeams,min(10,(int)($quiz['max_teams']??$minTeams)));
            if($format==='chgk') $minTeams=$maxTeams=1;
            $settings=json_decode((string)($quiz['format_settings_json']??''),true);
            if(is_array($settings) && (string)($settings['negotiationMode']??'')==='communicate') $minTeams=$maxTeams=2;
            $defaultTeams=$minTeams;
            $hostMode=in_array((string)($quiz['host_mode']??'ai'),['ai','human'],true)?(string)$quiz['host_mode']:'ai';
        }
    }

    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_ready_test_launch'])){
        check_admin_referer('ckm_quiz_pro_ready_game_test_launch_'.$postId,'ckm_ready_test_nonce');
        if(!$quiz || $quizId<=0){
            $result=['ok'=>false,'error'=>'Связанный игровой шаблон не найден.'];
        } elseif(!function_exists('ckm_quiz_create_room')){
            $result=['ok'=>false,'error'=>'Игровой движок недоступен.'];
        } else {
            $count=max($minTeams,min($maxTeams,(int)($_POST['team_count']??$defaultTeams)));
            $teams=[];
            for($i=1;$i<=$count;$i++){
                $name=sanitize_text_field(wp_unslash($_POST['team_name_'.$i]??('Команда '.chr(64+$i))));
                if($name==='') $name='Команда '.chr(64+$i);
                $teams[]=['name'=>$name];
            }
            $result=ckm_quiz_create_room([
                'quiz_id'=>$quizId,
                'team_count'=>$count,
                'teams'=>$teams,
                'host_mode'=>$hostMode,
                'judge_mode'=>'ai',
                'test_mode'=>1,
                'title'=>'[ТЕСТ] '.sanitize_text_field((string)$post->post_title),
            ],get_current_user_id());
            if(is_array($result) && !empty($result['ok']) && !empty($result['game']['id'])){
                $testGame=(array)$result['game'];
                update_post_meta($postId,'_ckm_ready_qa_last_test_game_id',(int)$testGame['id']);
                update_post_meta($postId,'_ckm_ready_qa_last_test_fingerprint',ckm_quiz_pro_ready_game_qa_fingerprint($postId));
                update_post_meta($postId,'_ckm_ready_qa_last_test_quiz_id',(int)($testGame['quiz_id']??$quizId));
                update_post_meta($postId,'_ckm_ready_qa_last_test_quiz_revision',max(1,(int)($testGame['quiz_revision']??1)));
                update_post_meta($postId,'_ckm_ready_qa_last_test_started_at',current_time('mysql'));
            }
        }
    }

    echo '<div class="wrap"><h1>Тестовый запуск: '.esc_html((string)$post->post_title).'</h1>';
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'integrity','id'=>$postId])).'">Проверить игру</a> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId])).'">Редактировать</a></p>';
    echo '<div class="notice notice-info inline"><p><strong>Это тестовая комната.</strong> Она использует настоящий игровой runtime, но не требует оплаты, не расходует доступ и не учитывается как коммерческая сессия. В истории администратора она остаётся с пометкой «тест».</p></div>';
    ckm_quiz_pro_ready_game_render_readiness(ckm_quiz_pro_ready_game_validate_post($postId),'Карточка каталога');
    $integrity=ckm_quiz_pro_ready_game_integrity_check($postId);
    ckm_quiz_pro_ready_game_render_integrity($integrity,'Техническая проверка перед тестом');
    $qa=ckm_quiz_pro_ready_game_qa_status($postId);
    ckm_quiz_pro_ready_game_render_qa($qa,'Публикационный тест');
    $lastTest=ckm_quiz_pro_ready_game_last_test($postId);
    if($lastTest){
        $sameFingerprint=(string)get_post_meta($postId,'_ckm_ready_qa_last_test_fingerprint',true)===ckm_quiz_pro_ready_game_qa_fingerprint($postId);
        echo '<div class="card" style="max-width:920px"><h2>Последний тест</h2><p>Сессия #'.(int)$lastTest['id'].' · статус: <strong>'.esc_html((string)$lastTest['status']).'</strong>'.(!empty($lastTest['finished_at'])?' · завершена '.esc_html((string)$lastTest['finished_at']):'').'</p>';
        if((int)$lastTest['test_mode']===1 && (string)$lastTest['status']==='finished' && $sameFingerprint){
            $passUrl=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_ready_game_test_pass&id='.$postId.'&game_id='.(int)$lastTest['id']),'ckm_quiz_pro_ready_game_test_pass_'.$postId.'_'.$lastTest['id']);
            echo '<p><a class="button button-primary" href="'.esc_url($passUrl).'">Подтвердить: тест пройден</a></p>';
        } elseif(!$sameFingerprint){
            echo '<p><strong>Этот тест относится к предыдущей редакции. Запустите игру заново.</strong></p>';
        } else {
            echo '<p>Чтобы подтвердить тест, сначала полностью завершите тестовую игру.</p>';
        }
        echo '</div>';
    }
    if(isset($_GET['qa_passed'])) echo '<div class="notice notice-success inline"><p>Тест текущей редакции подтверждён. Публикационный барьер снят.</p></div>';
    if(!$quiz){
        echo '<div class="notice notice-error inline"><p>Связанный игровой шаблон не найден. Сначала выберите или создайте шаблон.</p></div></div>';
        return;
    }
    echo '<div class="card" style="max-width:920px"><h2>Создать тестовую комнату</h2><form method="post">';
    wp_nonce_field('ckm_quiz_pro_ready_game_test_launch_'.$postId,'ckm_ready_test_nonce');
    echo '<table class="form-table"><tr><th>Шаблон</th><td>#'.(int)$quizId.' · '.esc_html((string)($quiz['title']??'')).' · rev '.max(1,(int)($quiz['current_revision']??1)).'</td></tr>';
    echo '<tr><th><label for="ckm-ready-test-teams">Количество команд</label></th><td><input id="ckm-ready-test-teams" type="number" name="team_count" value="'.(int)$defaultTeams.'" min="'.(int)$minTeams.'" max="'.(int)$maxTeams.'" '.($minTeams===$maxTeams?'readonly':'').'><p class="description">Допустимо: '.(int)$minTeams.'–'.(int)$maxTeams.'.</p></td></tr>';
    echo '<tr><th>Ведущий</th><td>'.($hostMode==='ai'?'ИИ-ведущий':'Ведущий-человек').'</td></tr></table>';
    echo '<p><button class="button button-primary" name="ckm_ready_test_launch" value="1">Создать тестовую комнату</button></p></form></div>';
    if(is_array($result)){
        if(empty($result['ok'])) echo '<div class="notice notice-error inline"><p>'.esc_html((string)($result['error']??'Не удалось создать тестовую комнату.')).'</p></div>';
        elseif(function_exists('ckm_quiz_pro_render_credentials')){
            echo '<h2>Тестовая комната создана</h2>';
            ckm_quiz_pro_render_credentials($result);
        }
    }
    echo '</div>';
}


add_action('admin_post_ckm_quiz_pro_ready_game_test_pass', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_GET['id']??0));
    $gameId=max(0,(int)($_GET['game_id']??0));
    check_admin_referer('ckm_quiz_pro_ready_game_test_pass_'.$postId.'_'.$gameId);
    $post=$postId>0?get_post($postId):null;
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    if($gameId<=0 || !function_exists('ckm_quiz_pro_table')) wp_die('Тестовая сессия не найдена.');
    global $wpdb;
    $game=$wpdb->get_row($wpdb->prepare(
        'SELECT id,quiz_id,quiz_revision,status,test_mode FROM '.ckm_quiz_pro_table('games').' WHERE id=%d LIMIT 1',
        $gameId
    ),ARRAY_A);
    if(!$game || (int)$game['test_mode']!==1 || (string)$game['status']!=='finished') wp_die('Тест должен быть полностью завершён.');
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $lastGameId=max(0,(int)get_post_meta($postId,'_ckm_ready_qa_last_test_game_id',true));
    $lastFingerprint=(string)get_post_meta($postId,'_ckm_ready_qa_last_test_fingerprint',true);
    $currentFingerprint=ckm_quiz_pro_ready_game_qa_fingerprint($postId);
    if($lastGameId!==$gameId || $lastFingerprint==='' || $currentFingerprint==='' || !hash_equals($currentFingerprint,$lastFingerprint)) wp_die('После теста игра была изменена. Запустите новый тест.');
    if((int)$game['quiz_id']!==(int)$m['quiz_id']) wp_die('Тест относится к другому игровому шаблону.');
    $currentQuizRevision=max(1,(int)$wpdb->get_var($wpdb->prepare('SELECT current_revision FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',(int)$m['quiz_id'])));
    if((int)$game['quiz_revision']!==$currentQuizRevision) wp_die('После теста игровой шаблон был изменён. Запустите новый тест.');
    update_post_meta($postId,'_ckm_ready_qa_passed_fingerprint',$currentFingerprint);
    update_post_meta($postId,'_ckm_ready_qa_passed_game_id',$gameId);
    update_post_meta($postId,'_ckm_ready_qa_passed_at',current_time('mysql'));
    update_post_meta($postId,'_ckm_ready_qa_passed_by',get_current_user_id());
    update_post_meta($postId,'_ckm_ready_qa_catalog_revision',ckm_quiz_pro_ready_game_current_revision($postId));
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['action'=>'test-launch','id'=>$postId,'qa_passed'=>1]));
    exit;
});


function ckm_quiz_pro_ready_game_rollback_release(int $postId,int $releaseId): array {
    if($postId<=0 || $releaseId<=0 || !current_user_can('manage_options')) return ['ok'=>false,'error'=>'forbidden'];
    if(!ckm_quiz_pro_ready_game_release_table_ready()) return ['ok'=>false,'error'=>'release_history_storage_missing'];
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_game_releases').' WHERE id=%d AND catalog_post_id=%d LIMIT 1',
        $releaseId,$postId
    ),ARRAY_A);
    if(!$row) return ['ok'=>false,'error'=>'release_not_found'];
    $released=json_decode((string)($row['snapshot_json']??''),true);
    if(!is_array($released) || !$released) return ['ok'=>false,'error'=>'release_snapshot_invalid'];
    $releaseQuizId=max(0,(int)($row['release_quiz_id']??($released['quiz_id']??0)));
    if($releaseQuizId<=0) return ['ok'=>false,'error'=>'release_quiz_missing'];
    $quiz=$wpdb->get_row($wpdb->prepare(
        'SELECT id,status FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',$releaseQuizId
    ),ARRAY_A);
    if(!$quiz || (string)($quiz['status']??'')!=='published') return ['ok'=>false,'error'=>'release_quiz_unavailable'];

    // Commercial identity is never rolled back. Only the live content pointer is.
    $currentMeta=ckm_quiz_pro_ready_game_meta($postId);
    $released['product']=sanitize_key((string)$currentMeta['product_key']);
    $released['catalog_post_id']=$postId;
    $released['quiz_id']=$releaseQuizId;
    $released['release_quiz_id']=$releaseQuizId;
    $released['catalog_revision']=max(1,(int)($row['catalog_revision']??($released['catalog_revision']??1)));
    $released['source_quiz_id']=max(0,(int)($row['source_quiz_id']??($released['source_quiz_id']??0)));
    $released['source_quiz_revision']=max(1,(int)($row['source_quiz_revision']??($released['source_quiz_revision']??1)));
    $released['release_created_at']=current_time('mysql');
    $released['qa_passed']=true;
    $released['qa_reason']='Восстановлен ранее опубликованный QA-проверенный релиз.';
    $released['ready_for_publish']=true;
    $released['integrity_ok']=true;
    $released['orderable']=true;

    $logged=ckm_quiz_pro_ready_game_append_release_event($postId,$released,'rollback',$releaseId);
    if(empty($logged['ok'])) return ['ok'=>false,'error'=>'rollback_log_failed','detail'=>(string)($logged['error']??'')];
    $released=(array)$logged['item'];
    update_post_meta($postId,'_ckm_ready_release_snapshot',wp_json_encode($released,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    update_post_meta($postId,'_ckm_ready_release_catalog_revision',max(1,(int)$released['catalog_revision']));
    update_post_meta($postId,'_ckm_ready_release_quiz_id',$releaseQuizId);
    update_post_meta($postId,'_ckm_ready_release_source_quiz_id',max(0,(int)$released['source_quiz_id']));
    update_post_meta($postId,'_ckm_ready_release_source_quiz_revision',max(1,(int)$released['source_quiz_revision']));
    update_post_meta($postId,'_ckm_ready_release_created_at',current_time('mysql'));
    update_post_meta($postId,'_ckm_ready_release_fingerprint',hash('sha256',wp_json_encode($released)));
    update_post_meta($postId,'_ckm_ready_release_restored_from_id',$releaseId);
    update_post_meta($postId,'_ckm_ready_release_restored_at',current_time('mysql'));
    return ['ok'=>true,'release_no'=>(int)($logged['release_no']??0),'source_release_id'=>$releaseId,'item'=>$released];
}

function ckm_quiz_pro_ready_game_admin_releases(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $rows=ckm_quiz_pro_ready_game_release_rows($postId);
    $active=ckm_quiz_pro_ready_game_active_release_row($postId);
    $activeId=max(0,(int)($active['id']??0));
    $byId=[];
    foreach($rows as $r) $byId[(int)$r['id']]=$r;
    echo '<div class="wrap"><h1>Опубликованные релизы: '.esc_html((string)$post->post_title).'</h1>';
    if(isset($_GET['rolled_back'])) echo '<div class="notice notice-success is-dismissible"><p>Предыдущий стабильный релиз возвращён в каталог как новый релиз R'.(int)$_GET['rolled_back'].'. Рабочая редакция игры не изменялась.</p></div>';
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'compare','id'=>$postId])).'">Сравнить с рабочей версией</a> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'history','id'=>$postId])).'">История рабочих редакций</a> <a class="button button-primary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId])).'">Редактировать рабочую версию</a></p>';
    echo '<div class="notice notice-info inline"><p><strong>Откат релиза не меняет рабочую редакцию.</strong> Он только переключает версию, которую получают новые покупатели. Уже купленные snapshot-копии организаторов остаются неизменными. Если игра скрыта, откат сам по себе не возвращает её в публичный каталог.</p></div>';
    echo '<table class="widefat striped"><thead><tr><th>Релиз</th><th>Редакция каталога</th><th>Дата</th><th>Действие</th><th>Игровой шаблон</th><th>Статус</th><th>Действия</th></tr></thead><tbody>';
    foreach($rows as $row){
        $id=(int)($row['id']??0);$releaseNo=max(1,(int)($row['release_no']??1));$catalogRev=max(1,(int)($row['catalog_revision']??1));
        $action=(string)($row['action']??'publish');
        $actionLabel=['publish'=>'Публикация','rollback'=>'Откат','seed'=>'Исходный релиз'][$action]??$action;
        if($action==='rollback' && (int)($row['source_release_id']??0)>0){
            $src=$byId[(int)$row['source_release_id']]??null;
            if($src) $actionLabel.=' к R'.max(1,(int)$src['release_no']);
        }
        $isActive=$id===$activeId || (string)($row['status']??'')==='active';
        $rollback=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_ready_game_release_rollback&id='.$postId.'&release_id='.$id),'ckm_quiz_pro_ready_game_release_rollback_'.$postId.'_'.$id);
        echo '<tr><td><strong>R'.$releaseNo.'</strong></td><td>v'.$catalogRev.'</td><td>'.esc_html((string)($row['created_at']??'')).'</td><td>'.esc_html($actionLabel).'</td><td>#'.(int)($row['release_quiz_id']??0).'<br><span class="description">источник #'.(int)($row['source_quiz_id']??0).' / rev '.max(1,(int)($row['source_quiz_revision']??1)).'</span></td><td>'.($isActive?'<span style="color:#008a20"><strong>В каталоге сейчас</strong></span>':'Предыдущий стабильный').'</td><td>';
        if(!$isActive) echo '<a class="button button-small" href="'.esc_url($rollback).'" onclick="return confirm(&quot;Вернуть этот ранее опубликованный стабильный релиз в каталог? Рабочая версия и уже купленные игры не изменятся.&quot;);">Вернуть в каталог</a>';
        else echo '—';
        echo '</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="7">Опубликованных релизов пока нет.</td></tr>';
    echo '</tbody></table></div>';
}

add_action('admin_post_ckm_quiz_pro_ready_game_release_rollback', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_GET['id']??0));
    $releaseId=max(0,(int)($_GET['release_id']??0));
    check_admin_referer('ckm_quiz_pro_ready_game_release_rollback_'.$postId.'_'.$releaseId);
    $result=ckm_quiz_pro_ready_game_rollback_release($postId,$releaseId);
    if(empty($result['ok'])) wp_die('Не удалось вернуть выбранный релиз: '.esc_html((string)($result['error']??'unknown_error')));
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['action'=>'releases','id'=>$postId,'rolled_back'=>(int)($result['release_no']??0)]));
    exit;
});

function ckm_quiz_pro_ready_game_admin_history(int $postId): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    ckm_quiz_pro_ready_game_record_revision($postId,'baseline');
    $rows=ckm_quiz_pro_ready_game_revision_rows($postId);
    $current=ckm_quiz_pro_ready_game_current_revision($postId);
    echo '<div class="wrap"><h1>История версий: '.esc_html((string)$post->post_title).'</h1>';
    if(isset($_GET['restored'])) echo '<div class="notice notice-success is-dismissible"><p>Редакция v'.(int)$_GET['restored'].' восстановлена. Текущее состояние сохранено как новая редакция.</p></div>';
    echo '<p><a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url()).'">← Каталог</a> <a class="button" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'releases','id'=>$postId])).'">Опубликованные релизы</a> <a class="button button-primary" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>$postId])).'">Редактировать текущую версию</a></p>';
    echo '<p>Каждое содержательное сохранение создаёт новую редакцию. Восстановление старой версии не удаляет историю, а создаёт новую редакцию поверх неё.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Редакция</th><th>Дата</th><th>Автор</th><th>Причина</th><th>Шаблон</th><th>Действия</th></tr></thead><tbody>';
    foreach($rows as $row){
        $rev=max(1,(int)($row['revision_no']??1));
        $user=get_userdata((int)($row['created_by_user_id']??0));
        $who=$user ? (string)$user->display_name : 'Система';
        $restore=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_ready_game_restore&id='.$postId.'&revision='.$rev),'ckm_quiz_pro_ready_game_restore_'.$postId.'_'.$rev);
        $isCurrent=$rev===$current;
        echo '<tr><td><strong>v'.$rev.'</strong>'.($isCurrent?' <span style="color:#008a20">(текущая)</span>':'').'</td><td>'.esc_html((string)($row['created_at']??'')).'</td><td>'.esc_html($who).'</td><td>'.esc_html((string)($row['reason']??'save')).'</td><td>#'.(int)($row['source_quiz_id']??0).' / rev '.(int)($row['source_quiz_revision']??1).'</td><td>';
        if(!$isCurrent) echo '<a class="button button-small" href="'.esc_url($restore).'" onclick="return confirm(&quot;Восстановить редакцию v'.$rev.'? Текущее состояние сохранится в истории и не будет потеряно.&quot;);">Восстановить</a>';
        else echo '—';
        echo '</td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="6">История версий пока пуста.</td></tr>';
    echo '</tbody></table></div>';
}

function ckm_quiz_pro_ready_game_restore_revision(int $postId,int $revision): bool {
    if($postId<=0 || $revision<=0 || !ckm_quiz_pro_ready_game_revision_table_ready()) return false;
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_game_revisions').' WHERE catalog_post_id=%d AND revision_no=%d LIMIT 1',
        $postId,$revision
    ),ARRAY_A);
    if(!$row) return false;
    $snapshot=json_decode((string)$row['snapshot_json'],true);
    if(!is_array($snapshot) || empty($snapshot['post']) || empty($snapshot['meta'])) return false;
    $post=$snapshot['post'];
    $status=(string)($post['post_status']??'draft');
    if(!in_array($status,['publish','draft','private'],true)) $status='draft';
    $saved=wp_update_post([
        'ID'=>$postId,
        'post_title'=>sanitize_text_field((string)($post['post_title']??'')),
        'post_excerpt'=>sanitize_textarea_field((string)($post['post_excerpt']??'')),
        'post_content'=>wp_kses_post((string)($post['post_content']??'')),
        'post_status'=>$status,
    ],true);
    if(is_wp_error($saved)) return false;
    $currentMeta=ckm_quiz_pro_ready_game_meta($postId);
    $meta=is_array($snapshot['meta'])?$snapshot['meta']:[];
    foreach(array_keys(ckm_quiz_pro_ready_game_meta_defaults()) as $key){
        if($key==='product_key'){
            // The commercial identity is immutable once assigned: never roll it back.
            update_post_meta($postId,'_ckm_ready_product_key',(string)$currentMeta['product_key']);
            continue;
        }
        if(array_key_exists($key,$meta)) update_post_meta($postId,'_ckm_ready_'.$key,$meta[$key]);
    }
    $quiz=is_array($snapshot['source_quiz']??null)?$snapshot['source_quiz']:[];
    $quizId=max(0,(int)($snapshot['source_quiz_id']??0));
    if($quizId>0 && $quiz){
        $existing=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d LIMIT 1',$quizId),ARRAY_A);
        $targetRevision=max(1,(int)($snapshot['source_quiz_revision']??1));
        $questionCount=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.ckm_quiz_pro_table('questions').' WHERE quiz_id=%d AND quiz_revision=%d',
            $quizId,$targetRevision
        ));
        $roundCount=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.ckm_quiz_pro_table('rounds').' WHERE quiz_id=%d AND quiz_revision=%d',
            $quizId,$targetRevision
        ));
        if($existing && ($questionCount>0 || $roundCount>0)){
            $restore=$quiz;
            unset($restore['id'],$restore['created_at']);
            $restore['current_revision']=$targetRevision;
            $restore['updated_by_user_id']=get_current_user_id();
            $restore['updated_at']=current_time('mysql');
            $wpdb->update(ckm_quiz_pro_table('quizzes'),$restore,['id'=>$quizId]);
        }
    }
    ckm_quiz_pro_ready_game_integrity_invalidate($postId);
    return ckm_quiz_pro_ready_game_record_revision($postId,'restore-v'.$revision)>0;
}

add_action('admin_post_ckm_quiz_pro_ready_game_restore', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_GET['id']??0));
    $revision=max(0,(int)($_GET['revision']??0));
    check_admin_referer('ckm_quiz_pro_ready_game_restore_'.$postId.'_'.$revision);
    if(!ckm_quiz_pro_ready_game_restore_revision($postId,$revision)) wp_die('Не удалось восстановить выбранную редакцию.');
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['action'=>'history','id'=>$postId,'restored'=>$revision]));
    exit;
});

/** Usage counters for one catalogue game. Purchases/snapshots and played sessions are immutable history. */
function ckm_quiz_pro_ready_game_usage(int $postId): array {
    $out=['instances'=>0,'games'=>0,'test_games'=>0];
    if($postId<=0 || !function_exists('ckm_quiz_pro_table')) return $out;
    global $wpdb;
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) return $out;
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $product=sanitize_key((string)$m['product_key']);
    $sourceQuizId=max(0,(int)$m['quiz_id']);
    if(function_exists('ckm_quiz_pro_ready_instances_table_ready') && ckm_quiz_pro_ready_instances_table_ready()){
        $ri=ckm_quiz_pro_table('ready_instances');
        $out['instances']=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM '.$ri.' WHERE (catalog_post_id=%d OR product_key=%s) AND status=%s',
            $postId,$product,'active'
        ));
        $snapshotIds=$wpdb->get_col($wpdb->prepare(
            'SELECT snapshot_quiz_id FROM '.$ri.' WHERE (catalog_post_id=%d OR product_key=%s) AND status=%s AND snapshot_quiz_id>0',
            $postId,$product,'active'
        )) ?: [];
    } else {
        $snapshotIds=[];
    }
    $quizIds=array_values(array_unique(array_filter(array_map('intval',array_merge([$sourceQuizId],$snapshotIds)))));
    if($quizIds){
        $games=ckm_quiz_pro_table('games');
        $placeholders=implode(',',array_fill(0,count($quizIds),'%d'));
        $sql=$wpdb->prepare('SELECT COUNT(*) FROM '.$games.' WHERE quiz_id IN ('.$placeholders.') AND test_mode=0',...$quizIds);
        $out['games']=(int)$wpdb->get_var($sql);
        $testSql=$wpdb->prepare('SELECT COUNT(*) FROM '.$games.' WHERE quiz_id IN ('.$placeholders.') AND test_mode=1',...$quizIds);
        $out['test_games']=(int)$wpdb->get_var($testSql);
    }
    return $out;
}

/** Deep-copy the current revision of a technical quiz template for administrator use. */
function ckm_quiz_pro_ready_game_clone_source_quiz(int $sourceQuizId,string $newTitle): int {
    if($sourceQuizId<=0 || !current_user_can('manage_options')) return 0;
    global $wpdb;
    $qz=ckm_quiz_pro_table('quizzes');
    $rr=ckm_quiz_pro_table('rounds');
    $qq=ckm_quiz_pro_table('questions');
    $source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$qz.' WHERE id=%d LIMIT 1',$sourceQuizId),ARRAY_A);
    if(!$source) return 0;
    $rev=max(1,(int)($source['current_revision']??1));
    $rounds=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$rr.' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',$sourceQuizId,$rev),ARRAY_A) ?: [];
    $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$qq.' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',$sourceQuizId,$rev),ARRAY_A) ?: [];
    $now=current_time('mysql');
    $copy=$source;
    unset($copy['id']);
    $copy['title']=sanitize_text_field($newTitle!==''?$newTitle:((string)$source['title'].' — копия'));
    $copy['slug']=substr(sanitize_title('admin-copy-'.$sourceQuizId.'-'.substr(hash('sha256',microtime(true).'|'.wp_rand()),0,14)),0,190);
    $copy['current_revision']=1;
    $copy['created_by_user_id']=get_current_user_id();
    $copy['updated_by_user_id']=get_current_user_id();
    $copy['created_at']=$now;
    $copy['updated_at']=$now;
    if(($copy['status']??'')==='published') $copy['published_at']=$now;
    if(array_key_exists('archived_at',$copy)) $copy['archived_at']=null;
    if($wpdb->query('START TRANSACTION')===false) return 0;
    try{
        if($wpdb->insert($qz,$copy)===false) throw new RuntimeException('admin_clone_quiz_insert_failed');
        $newQuizId=(int)$wpdb->insert_id;
        if($newQuizId<=0) throw new RuntimeException('admin_clone_quiz_id_missing');
        $roundMap=[];
        foreach($rounds as $row){
            $oldId=(int)($row['id']??0);
            unset($row['id']);
            $row['quiz_id']=$newQuizId;
            $row['quiz_revision']=1;
            $row['created_at']=$now;
            $row['updated_at']=$now;
            if($wpdb->insert($rr,$row)===false) throw new RuntimeException('admin_clone_round_insert_failed');
            if($oldId>0) $roundMap[$oldId]=(int)$wpdb->insert_id;
        }
        foreach($questions as $row){
            $oldRoundId=(int)($row['round_id']??0);
            unset($row['id']);
            $row['quiz_id']=$newQuizId;
            $row['quiz_revision']=1;
            $row['round_id']=$oldRoundId>0 ? (int)($roundMap[$oldRoundId]??0) : 0;
            $row['created_by_user_id']=get_current_user_id();
            $row['created_at']=$now;
            $row['updated_at']=$now;
            if($wpdb->insert($qq,$row)===false) throw new RuntimeException('admin_clone_question_insert_failed');
        }
        if($wpdb->query('COMMIT')===false) throw new RuntimeException('admin_clone_commit_failed');
        return $newQuizId;
    } catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return 0;
    }
}

function ckm_quiz_pro_ready_games_admin_page(): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $action=sanitize_key((string)($_GET['action'] ?? ''));
    $postId=max(0,(int)($_GET['id'] ?? 0));
    if(in_array($action,['add','edit'],true)){
        ckm_quiz_pro_ready_game_admin_form($action==='edit'?$postId:0);
        return;
    }
    if($action==='preview'){
        ckm_quiz_pro_ready_game_admin_preview($postId);
        return;
    }
    if($action==='history'){
        ckm_quiz_pro_ready_game_admin_history($postId);
        return;
    }
    if($action==='releases'){
        ckm_quiz_pro_ready_game_admin_releases($postId);
        return;
    }
    if($action==='compare'){
        ckm_quiz_pro_ready_game_admin_compare($postId);
        return;
    }
    if($action==='sales'){
        ckm_quiz_pro_ready_game_admin_sales($postId);
        return;
    }
    if($action==='integrity'){
        ckm_quiz_pro_ready_game_admin_integrity($postId);
        return;
    }
    if($action==='test-launch'){
        ckm_quiz_pro_ready_game_admin_test_launch($postId);
        return;
    }
    $posts=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish','draft','pending','private'],
        'posts_per_page'=>-1,
        'orderby'=>['date'=>'DESC'],
        'suppress_filters'=>false,
    ]);
    echo '<div class="wrap"><h1 class="wp-heading-inline">Каталог готовых игр</h1> <a class="page-title-action" href="'.esc_url(ckm_quiz_pro_ready_games_admin_url(['action'=>'add'])).'">Добавить готовую игру</a><hr class="wp-header-end">';
    if(isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Готовая игра сохранена.</p></div>';
    if(isset($_GET['deleted'])) echo '<div class="notice notice-success is-dismissible"><p>Готовая игра удалена.</p></div>';
    if(isset($_GET['cloned'])) echo '<div class="notice notice-success is-dismissible"><p>Создана независимая копия готовой игры и её игрового шаблона. Копия сохранена как черновик.</p></div>';
    if(isset($_GET['archived'])) echo '<div class="notice notice-warning is-dismissible"><p>Удаление заблокировано: у игры уже есть покупки или история. Игра скрыта из каталога, а купленные экземпляры и история сохранены.</p></div>';
    echo '<p>Здесь администратор полностью управляет платным каталогом: добавляет игры, меняет описание и цену, редактирует связанный игровой шаблон и удаляет игры из каталога.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Игра</th><th>Редакция</th><th>Категория</th><th>Цена</th><th>Статус</th><th>Игровой шаблон</th><th>Использование</th><th>Действия</th></tr></thead><tbody>';
    foreach($posts as $post){
        $m=ckm_quiz_pro_ready_game_meta((int)$post->ID);
        $edit=ckm_quiz_pro_ready_games_admin_url(['action'=>'edit','id'=>(int)$post->ID]);
        $delete=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_ready_game_delete&id='.(int)$post->ID),'ckm_quiz_pro_ready_game_delete_'.(int)$post->ID);
        $statusMap=['publish'=>'Опубликована','draft'=>'Черновик','private'=>'Скрыта'];
        $status=$statusMap[(string)$post->post_status] ?? (string)$post->post_status;
        $validation=ckm_quiz_pro_ready_game_validate_post((int)$post->ID);
        $readinessLabel=!empty($validation['ready']) ? '<span style="color:#008a20">✓ карточка готова</span>' : '<span style="color:#996800">✕ карточка не готова ('.count((array)($validation['errors']??[])).')</span>';
        $cachedIntegrity=ckm_quiz_pro_ready_game_integrity_cached((int)$post->ID);
        $integrityLabel=!$cachedIntegrity ? '<span style="color:#646970">○ игра не проверена</span>' : (!empty($cachedIntegrity['ok'])?'<span style="color:#008a20">✓ игра проверена</span>':'<span style="color:#b32d2e">✕ ошибки игры</span>');
        $qa=ckm_quiz_pro_ready_game_qa_status((int)$post->ID);
        $qaLabel=!empty($qa['passed'])?'<span style="color:#008a20">✓ тест пройден</span>':'<span style="color:#996800">○ нужен тест</span>';
        $template=(int)$m['quiz_id']>0 ? '#'.(int)$m['quiz_id'] : '—';
        $preview=ckm_quiz_pro_ready_games_admin_url(['action'=>'preview','id'=>(int)$post->ID]);
        $integrityUrl=ckm_quiz_pro_ready_games_admin_url(['action'=>'integrity','id'=>(int)$post->ID]);
        $testLaunchUrl=ckm_quiz_pro_ready_games_admin_url(['action'=>'test-launch','id'=>(int)$post->ID]);
        $clone=wp_nonce_url(admin_url('admin-post.php?action=ckm_quiz_pro_ready_game_clone&id='.(int)$post->ID),'ckm_quiz_pro_ready_game_clone_'.(int)$post->ID);
        $usage=ckm_quiz_pro_ready_game_usage((int)$post->ID);
        $isUsed=((int)$usage['instances']>0 || (int)$usage['games']>0);
        $history=ckm_quiz_pro_ready_games_admin_url(['action'=>'history','id'=>(int)$post->ID]);
        $releases=ckm_quiz_pro_ready_games_admin_url(['action'=>'releases','id'=>(int)$post->ID]);
        $release=ckm_quiz_pro_ready_game_release_snapshot((int)$post->ID);
        $currentRevision=ckm_quiz_pro_ready_game_current_revision((int)$post->ID);
        $releaseRevision=max(0,(int)($release['catalog_revision']??0));
        $revisionCell='<strong>Рабочая v'.$currentRevision.'</strong>';
        if($releaseRevision>0){
            $releaseNo=max(0,(int)($release['release_no']??get_post_meta((int)$post->ID,'_ckm_ready_release_no',true)));
            $revisionCell.='<br><span style="color:#008a20">В каталоге v'.$releaseRevision.($releaseNo>0?' · R'.$releaseNo:'').'</span>';
            if($releaseRevision!==$currentRevision) $revisionCell.='<br><span style="color:#996800">есть неопубликованные изменения</span>';
        }else{
            $revisionCell.='<br><span style="color:#646970">ещё не публиковалась</span>';
        }
        $revisionCell.='<br><a href="'.esc_url($history).'">Редакции</a>';
        if($releaseRevision>0) $revisionCell.=' · <a href="'.esc_url($releases).'">Релизы</a>';
        $compare=ckm_quiz_pro_ready_games_admin_url(['action'=>'compare','id'=>(int)$post->ID]);
        $sales=ckm_quiz_pro_ready_games_admin_url(['action'=>'sales','id'=>(int)$post->ID]);
        echo '<tr><td><strong>'.esc_html((string)$post->post_title).'</strong><br><code>'.esc_html((string)$m['product_key']).'</code></td><td>'.$revisionCell.'</td><td>'.esc_html((string)$m['category']).'</td><td>'.(int)$m['price'].' ₽</td><td>'.esc_html($status).'<br>'.$readinessLabel.'<br>'.$integrityLabel.'<br>'.$qaLabel.'</td><td>'.esc_html($template).'</td><td>Покупок: '.(int)$usage['instances'].'<br>Сессий: '.(int)$usage['games'].'<br>Тестов: '.(int)($usage['test_games']??0).'</td><td><a class="button button-small button-primary" href="'.esc_url($integrityUrl).'">Проверить игру</a> <a class="button button-small" href="'.esc_url($testLaunchUrl).'">Тестовый запуск</a> <a class="button button-small" href="'.esc_url($preview).'">Предпросмотр</a> '.($releaseRevision>0?'<a class="button button-small" href="'.esc_url($sales).'">Продать / выдать</a> <a class="button button-small" href="'.esc_url($compare).'">Сравнить</a> <a class="button button-small" href="'.esc_url($releases).'">Релизы</a> ':'').'<a class="button button-small" href="'.esc_url($edit).'">Редактировать карточку</a> ';
        if((int)$m['quiz_id']>0) echo '<a class="button button-small" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-edit&quiz='.(int)$m['quiz_id'])).'">Редактировать игру</a> ';
        echo '<a class="button button-small" href="'.esc_url($clone).'">Клонировать</a> ';
        if($isUsed){
            echo '<a class="button button-small" href="'.esc_url($delete).'" onclick="return confirm(&quot;У этой игры уже есть покупки или история. Она будет скрыта из каталога, а данные сохранятся. Продолжить?&quot;);">Скрыть / архивировать</a></td></tr>';
        } else {
            echo '<a class="button button-small button-link-delete" href="'.esc_url($delete).'" onclick="return confirm(&quot;Игра ещё не использовалась. Удалить её из каталога безвозвратно?&quot;);">Удалить</a></td></tr>';
        }
    }
    if(!$posts) echo '<tr><td colspan="8">В каталоге пока нет готовых игр.</td></tr>';
    echo '</tbody></table></div>';
}

add_action('admin_post_ckm_quiz_pro_ready_game_save', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    check_admin_referer('ckm_quiz_pro_ready_game_admin_save','ckm_ready_admin_nonce');
    $postId=max(0,(int)($_POST['post_id'] ?? 0));
    if($postId>0){
        $existing=get_post($postId);
        if(!$existing || $existing->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    }
    $title=sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    if($title==='') wp_die('Укажите название игры.');
    $requestedStatus=sanitize_key((string)($_POST['status'] ?? 'draft'));
    if(!in_array($requestedStatus,['draft','publish','private'],true)) $requestedStatus='draft';
    // Publish only after metadata has been saved and the full readiness check passes.
    $status=$requestedStatus==='publish' ? 'draft' : $requestedStatus;
    $postData=[
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_title'=>$title,
        'post_excerpt'=>sanitize_textarea_field(wp_unslash($_POST['excerpt'] ?? '')),
        'post_content'=>wp_kses_post(wp_unslash($_POST['content'] ?? '')),
        'post_status'=>$status,
    ];
    if($postId>0) $postData['ID']=$postId;
    $saved=wp_insert_post($postData,true);
    if(is_wp_error($saved)) wp_die(esc_html($saved->get_error_message()));
    $postId=(int)$saved;
    $fields=[
        'category'=>sanitize_text_field(wp_unslash($_POST['category'] ?? 'Другие игры')),
        'subtitle'=>sanitize_text_field(wp_unslash($_POST['subtitle'] ?? '')),
        'audience'=>sanitize_text_field(wp_unslash($_POST['audience'] ?? '')),
        'price'=>max(0,(int)($_POST['price'] ?? 990)),
        'product_key'=>sanitize_key((string)wp_unslash($_POST['product_key'] ?? '')),
        'quiz_id'=>max(0,(int)($_POST['quiz_id'] ?? 0)),
        'icon'=>sanitize_text_field(wp_unslash($_POST['icon'] ?? '◆')),
        'roles'=>sanitize_textarea_field(wp_unslash($_POST['roles'] ?? '')),
        'rounds'=>sanitize_textarea_field(wp_unslash($_POST['rounds'] ?? '')),
        'rules'=>sanitize_textarea_field(wp_unslash($_POST['rules'] ?? '')),
    ];
    if($fields['category']==='') $fields['category']='Другие игры';
    if($fields['product_key']==='') $fields['product_key']='ready_game_'.$postId;

    // Once a commercial release exists, the product key is an entitlement
    // identity and must not drift while a new working revision is prepared.
    $existingRelease=ckm_quiz_pro_ready_game_release_snapshot($postId);
    if(!empty($existingRelease['product'])){
        $fields['product_key']=sanitize_key((string)$existingRelease['product']);
    }

    foreach($fields as $key=>$value) update_post_meta($postId,'_ckm_ready_'.$key,$value);
    ckm_quiz_pro_ready_game_integrity_invalidate($postId);

    $validation=ckm_quiz_pro_ready_game_validate_post($postId);
    $integrity=ckm_quiz_pro_ready_game_integrity_check($postId);
    $qa=ckm_quiz_pro_ready_game_qa_status($postId);
    $publishBlocked=$requestedStatus==='publish' && (empty($validation['ready']) || empty($integrity['ok']) || empty($qa['passed']));
    $releaseBlocked=false;
    $catalogRevision=0;

    if($requestedStatus==='publish' && !$publishBlocked){
        // Record the exact working revision first, then freeze its linked
        // technical quiz into an immutable release for public sales.
        $catalogRevision=ckm_quiz_pro_ready_game_record_revision($postId,'publish');
        $releaseResult=ckm_quiz_pro_ready_game_capture_release($postId,$catalogRevision);
        if(empty($releaseResult['ok'])){
            $publishBlocked=true;
            $releaseBlocked=true;
            $status='draft';
        }else{
            wp_update_post(['ID'=>$postId,'post_status'=>'publish']);
            $status='publish';
        }
    } elseif($publishBlocked){
        $status='draft';
    }

    $scenarioOrderId=max(0,(int)($_POST['scenario_order_id'] ?? 0));
    if($scenarioOrderId<=0) $scenarioOrderId=max(0,(int)get_post_meta($postId,'_ckm_ready_scenario_order_id',true));
    if($scenarioOrderId>0 && function_exists('ckm_quiz_pro_scenario_order_get')){
        $order=ckm_quiz_pro_scenario_order_get($scenarioOrderId);
        if($order){
            update_post_meta($postId,'_ckm_ready_scenario_order_id',$scenarioOrderId);
            global $wpdb;
            $orderStatus=$status==='publish'?'ready':'in_work';
            $wpdb->update(ckm_quiz_pro_table('scenario_orders'),[
                'ready_game_post_id'=>$postId,'status'=>$orderStatus,'updated_at'=>current_time('mysql')
            ],['id'=>$scenarioOrderId],['%d','%s','%s'],['%d']);
        }
    }
    if($catalogRevision<=0){
        $catalogRevision=ckm_quiz_pro_ready_game_record_revision($postId,$publishBlocked?'publish-blocked':'save');
    }
    if($publishBlocked){
        wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url([
            'action'=>'edit','id'=>$postId,'publish_blocked'=>1,
            'integrity_blocked'=>empty($integrity['ok'])?1:0,
            'qa_blocked'=>empty($qa['passed'])?1:0,
            'release_blocked'=>$releaseBlocked?1:0,
            'revision'=>$catalogRevision
        ]));
    } else {
        wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['saved'=>1,'revision'=>$catalogRevision,'released'=>$requestedStatus==='publish'?1:0]));
    }
    exit;
});



// Defense-in-depth for the hidden native WordPress editor: an incomplete
// catalogue entry cannot be left published by bypassing the dedicated screen.
add_action('save_post_'.'ckm_ready_game', static function (int $postId, WP_Post $post): void {
    static $guard=false;
    if($guard || !current_user_can('manage_options')) return;
    if((string)($post->post_status??'')!=='publish') return;
    if((string)($_POST['action']??'')!=='editpost' || (int)($_POST['post_ID']??0)!==$postId) return;
    ckm_quiz_pro_ready_game_integrity_invalidate($postId);
    $validation=ckm_quiz_pro_ready_game_validate_post($postId);
    $integrity=ckm_quiz_pro_ready_game_integrity_check($postId);
    $qa=ckm_quiz_pro_ready_game_qa_status($postId);
    if(!empty($validation['ready']) && !empty($integrity['ok']) && !empty($qa['passed'])) return;
    $guard=true;
    wp_update_post(['ID'=>$postId,'post_status'=>'draft']);
    ckm_quiz_pro_ready_game_record_revision($postId,'native-publish-blocked');
    $guard=false;
},100,2);

add_action('admin_post_ckm_quiz_pro_ready_game_delete', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_GET['id'] ?? 0));
    check_admin_referer('ckm_quiz_pro_ready_game_delete_'.$postId);
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $usage=ckm_quiz_pro_ready_game_usage($postId);
    if((int)$usage['instances']>0 || (int)$usage['games']>0){
        wp_update_post(['ID'=>$postId,'post_status'=>'private']);
        update_post_meta($postId,'_ckm_ready_retired','1');
        update_post_meta($postId,'_ckm_ready_retired_at',current_time('mysql'));
        ckm_quiz_pro_ready_game_record_revision($postId,'archive');
        wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['archived'=>1]));
        exit;
    }
    if(ckm_quiz_pro_ready_game_revision_table_ready()){
        global $wpdb;
        $wpdb->delete(ckm_quiz_pro_table('ready_game_revisions'),['catalog_post_id'=>$postId],['%d']);
    }
    if(ckm_quiz_pro_ready_game_release_table_ready()){
        global $wpdb;
        $wpdb->delete(ckm_quiz_pro_table('ready_game_releases'),['catalog_post_id'=>$postId],['%d']);
    }
    wp_delete_post($postId,true);
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['deleted'=>1]));
    exit;
});

add_action('admin_post_ckm_quiz_pro_ready_game_clone', static function (): void {
    if(!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    $postId=max(0,(int)($_GET['id'] ?? 0));
    check_admin_referer('ckm_quiz_pro_ready_game_clone_'.$postId);
    $post=get_post($postId);
    if(!$post || $post->post_type!==ckm_quiz_pro_ready_game_post_type()) wp_die('Готовая игра не найдена.');
    $m=ckm_quiz_pro_ready_game_meta($postId);
    $newQuizId=0;
    if((int)$m['quiz_id']>0){
        $newQuizId=ckm_quiz_pro_ready_game_clone_source_quiz((int)$m['quiz_id'],(string)$post->post_title.' — копия');
        if($newQuizId<=0) wp_die('Не удалось создать независимую копию игрового шаблона.');
    }
    $newPost=wp_insert_post([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>'draft',
        'post_title'=>(string)$post->post_title.' — копия',
        'post_excerpt'=>(string)$post->post_excerpt,
        'post_content'=>(string)$post->post_content,
    ],true);
    if(is_wp_error($newPost) || (int)$newPost<=0) wp_die('Не удалось клонировать карточку готовой игры.');
    $newPost=(int)$newPost;
    foreach(array_keys(ckm_quiz_pro_ready_game_meta_defaults()) as $key){
        if($key==='product_key') continue;
        $value=$m[$key]??'';
        if($key==='quiz_id') $value=$newQuizId;
        update_post_meta($newPost,'_ckm_ready_'.$key,$value);
    }
    update_post_meta($newPost,'_ckm_ready_product_key','ready_game_'.$newPost);
    update_post_meta($newPost,'_ckm_ready_cloned_from_post_id',$postId);
    ckm_quiz_pro_ready_game_record_revision($newPost,'clone');
    wp_safe_redirect(ckm_quiz_pro_ready_games_admin_url(['cloned'=>1]));
    exit;
});


function ckm_quiz_pro_ready_game_parse_pairs(string $text): array {
    $out=[];
    foreach(preg_split('/\R/u',$text) ?: [] as $line){
        $line=trim($line); if($line==='') continue;
        $parts=preg_split('/\s*\|\s*/u',$line,2) ?: [];
        $label=trim((string)($parts[0] ?? ''));
        $description=trim((string)($parts[1] ?? ''));
        if($label!=='') $out[$label]=$description;
    }
    return $out;
}

/**
 * Catalogue registry.
 * Public mode returns only published games. Internal mode also returns Draft
 * and Hidden entries so existing purchasers keep entitlement/snapshot access.
 */
function ckm_quiz_pro_ready_games_registry(bool $includeNonPublic=false): array {
    // Public and entitlement flows must be able to use the last released
    // snapshot even while the administrator is working on a newer draft.
    $posts=get_posts([
        'post_type'=>ckm_quiz_pro_ready_game_post_type(),
        'post_status'=>['publish','draft','private'],
        'posts_per_page'=>-1,
        'orderby'=>['menu_order'=>'ASC','date'=>'DESC'],
        'suppress_filters'=>false,
    ]);
    $out=[];
    foreach($posts as $post){
        $working=ckm_quiz_pro_ready_game_item_from_post($post);
        $release=ckm_quiz_pro_ready_game_release_snapshot((int)$post->ID);
        $status=(string)$post->post_status;

        if($release){
            $item=$release;
            $item['catalog_post_id']=(int)$post->ID;
            $item['working_status']=$status;
            $item['current_catalog_revision']=ckm_quiz_pro_ready_game_current_revision((int)$post->ID);
            $item['has_live_release']=true;
            $item['live_release_revision']=max(1,(int)($release['catalog_revision']??1));
            $item['hidden']=$status==='private';
            $item['draft']=$status==='draft';
            $item['orderable']=$status!=='private';
            $item['catalog_status']=$status==='private'?'private':'publish';
            if(!$includeNonPublic && $status==='private') continue;
        }else{
            $item=$working;
            if(!$includeNonPublic){
                if($status!=='publish' || empty($item['ready_for_publish'])) continue;
            }
        }

        $key=sanitize_key((string)($item['product']??''));
        if($key==='') continue;
        $out[$key]=$item;
    }
    return $out;
}



/**
 * Snapshot ownership for purchased catalogue games.
 *
 * A finished game in the administrator catalogue is a SOURCE template. The
 * first paid access for a tenant/user creates one private quiz copy and stores
 * its provenance here. Later edits of the catalogue source never overwrite
 * that purchased copy. Renewals continue to use the same snapshot.
 */
function ckm_quiz_pro_ready_instances_table_ready(): bool {
    static $ready=false;
    if($ready===true) return true;
    global $wpdb;
    $table=ckm_quiz_pro_table('ready_instances');
    if($table==='') return false;
    $pattern=method_exists($wpdb,'esc_like')?$wpdb->esc_like($table):$table;
    $ready=((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$pattern))===$table);
    return $ready;
}

function ckm_quiz_pro_ready_instance_scope(int $userId, ?int $tenantId=null): array {
    if($userId<=0) return ['tenant_id'=>-1,'owner_key'=>''];
    if($tenantId===null){
        $tenantId=function_exists('ckm_quiz_pro_access_tenant_id')
            ? ckm_quiz_pro_access_tenant_id($userId,null)
            : (function_exists('ckmqp_scope_id') ? ckmqp_scope_id() : 0);
    }
    if($tenantId<0) return ['tenant_id'=>-1,'owner_key'=>''];
    return [
        'tenant_id'=>$tenantId,
        'owner_key'=>$tenantId>0 ? 'tenant:'.$tenantId : 'user:'.$userId,
    ];
}

function ckm_quiz_pro_ready_game_snapshot_row(int $userId,string $productKey,?int $tenantId=null): array {
    if(!ckm_quiz_pro_ready_instances_table_ready()) return [];
    $productKey=sanitize_key($productKey);
    $scope=ckm_quiz_pro_ready_instance_scope($userId,$tenantId);
    if($productKey==='' || $scope['owner_key']==='') return [];
    global $wpdb;
    $row=$wpdb->get_row($wpdb->prepare(
        'SELECT * FROM '.ckm_quiz_pro_table('ready_instances').' WHERE owner_key=%s AND product_key=%s AND status=%s LIMIT 1',
        $scope['owner_key'],$productKey,'active'
    ),ARRAY_A);
    return is_array($row)?$row:[];
}

function ckm_quiz_pro_ready_game_snapshot_quiz_id(int $userId,string $productKey,?int $tenantId=null): int {
    $row=ckm_quiz_pro_ready_game_snapshot_row($userId,$productKey,$tenantId);
    if(!$row) return 0;
    global $wpdb;
    $quizId=(int)($row['snapshot_quiz_id']??0);
    if($quizId<=0) return 0;
    return (int)$wpdb->get_var($wpdb->prepare(
        'SELECT id FROM '.ckm_quiz_pro_table('quizzes').' WHERE id=%d AND status=%s LIMIT 1',
        $quizId,'published'
    ));
}

function ckm_quiz_pro_ready_game_source_product_for_quiz(int $quizId): string {
    if($quizId<=0 || !post_type_exists(ckm_quiz_pro_ready_game_post_type())) return '';
    foreach(['_ckm_ready_quiz_id','_ckm_ready_release_quiz_id'] as $metaKey){
        $ids=get_posts([
            'post_type'=>ckm_quiz_pro_ready_game_post_type(),
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>1,
            'fields'=>'ids',
            'meta_key'=>$metaKey,
            'meta_value'=>(string)$quizId,
            'no_found_rows'=>true,
        ]);
        if($ids){
            $release=ckm_quiz_pro_ready_game_release_snapshot((int)$ids[0]);
            if($metaKey==='_ckm_ready_release_quiz_id' && !empty($release['product'])) return sanitize_key((string)$release['product']);
            return (string)ckm_quiz_pro_ready_game_meta((int)$ids[0])['product_key'];
        }
    }
    return '';
}

/** Clone one exact quiz revision into a private revision-1 organizer copy. */
function ckm_quiz_pro_ready_game_clone_snapshot(array $item,int $userId,int $tenantId,string $ownerKey): int {
    global $wpdb;
    $sourceQuizId=max(0,(int)($item['quiz_id']??0));
    if($sourceQuizId<=0) return 0;
    $qz=ckm_quiz_pro_table('quizzes');
    $rr=ckm_quiz_pro_table('rounds');
    $qq=ckm_quiz_pro_table('questions');
    $source=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$qz.' WHERE id=%d AND status=%s LIMIT 1',$sourceQuizId,'published'),ARRAY_A);
    if(!$source) return 0;
    $sourceRevision=max(1,(int)($source['current_revision']??1));
    $rounds=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$rr.' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',$sourceQuizId,$sourceRevision),ARRAY_A)?:[];
    $questions=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.$qq.' WHERE quiz_id=%d AND quiz_revision=%d ORDER BY position,id',$sourceQuizId,$sourceRevision),ARRAY_A)?:[];
    if(!$questions) return 0;

    $signature=hash('sha256',wp_json_encode([
        'catalog_item'=>$item,
        'source_quiz'=>$source,
        'source_revision'=>$sourceRevision,
        'rounds'=>$rounds,
        'questions'=>$questions,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $now=current_time('mysql');
    $copy=$source;
    unset($copy['id']);
    $copy['title']=sanitize_text_field((string)($item['title']??$source['title']));
    $copy['slug']=substr(sanitize_title('owned-'.$item['product'].'-'.substr(hash('sha256',$ownerKey.'|'.microtime(true).'|'.wp_rand()),0,14)),0,190);
    $copy['status']='published';
    $copy['current_revision']=1;
    $copy['created_by_user_id']=$userId;
    $copy['updated_by_user_id']=$userId;
    $copy['created_at']=$now;
    $copy['updated_at']=$now;
    $copy['published_at']=$now;
    if(array_key_exists('archived_at',$copy)) $copy['archived_at']=null;
    if(array_key_exists('tenant_id',$copy)) $copy['tenant_id']=$tenantId;
    if(array_key_exists('content_scope',$copy)) $copy['content_scope']='private';
    if(trim((string)($item['description']??''))!=='') $copy['short_description']=sanitize_textarea_field((string)$item['description']);

    if($wpdb->query('START TRANSACTION')===false) return 0;
    try{
        if($wpdb->insert($qz,$copy)===false) throw new RuntimeException('snapshot_quiz_insert_failed');
        $snapshotQuizId=(int)$wpdb->insert_id;
        if($snapshotQuizId<=0) throw new RuntimeException('snapshot_quiz_id_missing');

        $roundMap=[];
        foreach($rounds as $row){
            $oldId=(int)($row['id']??0);
            unset($row['id']);
            $row['quiz_id']=$snapshotQuizId;
            $row['quiz_revision']=1;
            $row['created_at']=$now;
            $row['updated_at']=$now;
            if($wpdb->insert($rr,$row)===false) throw new RuntimeException('snapshot_round_insert_failed');
            if($oldId>0) $roundMap[$oldId]=(int)$wpdb->insert_id;
        }
        foreach($questions as $row){
            $oldRoundId=(int)($row['round_id']??0);
            unset($row['id']);
            $row['quiz_id']=$snapshotQuizId;
            $row['quiz_revision']=1;
            $row['round_id']=$oldRoundId>0 ? (int)($roundMap[$oldRoundId]??0) : 0;
            $row['created_by_user_id']=$userId;
            $row['created_at']=$now;
            $row['updated_at']=$now;
            if($wpdb->insert($qq,$row)===false) throw new RuntimeException('snapshot_question_insert_failed');
        }

        $instance=[
            'tenant_id'=>$tenantId,
            'owner_user_id'=>$userId,
            'owner_key'=>$ownerKey,
            'product_key'=>sanitize_key((string)$item['product']),
            'catalog_post_id'=>(int)($item['catalog_post_id']??0),
            'catalog_revision'=>max(1,(int)($item['catalog_revision']??1)),
            'source_quiz_id'=>$sourceQuizId,
            'source_quiz_revision'=>$sourceRevision,
            'snapshot_quiz_id'=>$snapshotQuizId,
            'snapshot_revision'=>1,
            'source_signature'=>$signature,
            'catalog_snapshot_json'=>wp_json_encode($item,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'status'=>'active',
            'created_at'=>$now,
            'updated_at'=>$now,
        ];
        if($wpdb->insert(ckm_quiz_pro_table('ready_instances'),$instance)===false) throw new RuntimeException('snapshot_instance_insert_failed');
        if($wpdb->query('COMMIT')===false) throw new RuntimeException('snapshot_commit_failed');
        return $snapshotQuizId;
    }catch(Throwable $e){
        $wpdb->query('ROLLBACK');
        return 0;
    }
}

/** Ensure one immutable catalogue copy exists for this paid owner/product. */
function ckm_quiz_pro_ready_game_snapshot_ensure(int $userId,string $productKey,?int $tenantId=null): int {
    if($userId<=0 || !ckm_quiz_pro_ready_instances_table_ready()) return 0;
    $productKey=sanitize_key($productKey);
    $registry=ckm_quiz_pro_ready_games_registry(true);
    if($productKey==='' || !isset($registry[$productKey])) return 0; // base formats are not catalogue snapshots
    $scope=ckm_quiz_pro_ready_instance_scope($userId,$tenantId);
    if($scope['owner_key']==='' || $scope['tenant_id']<0) return 0;
    if(function_exists('ckm_quiz_pro_can_access_format') && !ckm_quiz_pro_can_access_format($userId,$productKey,(int)$scope['tenant_id'])) return 0;

    $existing=ckm_quiz_pro_ready_game_snapshot_quiz_id($userId,$productKey,(int)$scope['tenant_id']);
    if($existing>0) return $existing;

    global $wpdb;
    $lock='ckm_qp_snap_'.substr(hash('sha256',$scope['owner_key'].'|'.$productKey),0,32);
    if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)',$lock))!==1) return 0;
    try{
        $existing=ckm_quiz_pro_ready_game_snapshot_quiz_id($userId,$productKey,(int)$scope['tenant_id']);
        if($existing>0) return $existing;
        return ckm_quiz_pro_ready_game_clone_snapshot($registry[$productKey],$userId,(int)$scope['tenant_id'],(string)$scope['owner_key']);
    }finally{
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
}

/** Lazy migration for purchases made before snapshot support existed. */
function ckm_quiz_pro_ready_game_ensure_active_snapshots(int $userId,?int $tenantId=null): array {
    $out=[];
    if($userId<=0) return $out;
    foreach(ckm_quiz_pro_ready_games_registry(true) as $productKey=>$item){
        if(!ckm_quiz_pro_can_access_format($userId,$productKey,$tenantId)) continue;
        $quizId=ckm_quiz_pro_ready_game_snapshot_ensure($userId,$productKey,$tenantId);
        if($quizId>0) $out[$productKey]=$quizId;
    }
    return $out;
}

/** Add administrator-created catalogue products to the existing checkout/access system. */
add_filter('ckm_quiz_pro_game_access_products', static function (array $products): array {
    if(!post_type_exists(ckm_quiz_pro_ready_game_post_type())) return $products;
    foreach(ckm_quiz_pro_ready_games_registry(true) as $key=>$item){
        $products[$key]=[
            'title'=>(string)$item['title'],
            'price'=>(int)$item['price'],
            'description'=>(string)$item['description'],
            'ready_game'=>true,
            'quiz_id'=>(int)$item['quiz_id'],
            'catalog_status'=>(string)($item['catalog_status']??'draft'),
            'orderable'=>!empty($item['orderable']),
            'hidden'=>!empty($item['hidden']),
        ];
    }
    return $products;
}, 20);

function ckm_quiz_pro_ready_game_product_for_quiz(int $quizId): string {
    if($quizId<=0) return '';
    if(ckm_quiz_pro_ready_instances_table_ready()){
        global $wpdb;
        $product=(string)$wpdb->get_var($wpdb->prepare(
            'SELECT product_key FROM '.ckm_quiz_pro_table('ready_instances').' WHERE snapshot_quiz_id=%d AND status=%s LIMIT 1',
            $quizId,'active'
        ));
        if($product!=='') return sanitize_key($product);
    }
    return ckm_quiz_pro_ready_game_source_product_for_quiz($quizId);
}

/**
 * Seed the built-in «Переговори другого» scenarios into the new
 * administrator-managed catalogue. This is idempotent and upgrades the
 * earlier school-grade-only seed on already installed sites.
 */
add_action('init', static function (): void {
    if(get_option('ckm_quiz_pro_ready_games_seeded_v2','')==='1') return;
    if(!post_type_exists(ckm_quiz_pro_ready_game_post_type())) return;
    if(!function_exists('ckm_quiz_pro_table')) return;

    global $wpdb;
    $variants=[
        [
            'slug'=>'demo-negotiation-communicate-school',
            'title'=>'Переговори другого — для школьников',
            'category'=>'Школьные ситуации',
            'subtitle'=>'Переговори другого — для школьников',
            'audience'=>'Школьники, подростковые группы, классные часы и тренинги коммуникации.',
            'product'=>'persuade_school_v1',
            'icon'=>'🎓',
            'excerpt'=>'Сюжеты о школьных проектах, одноклассниках, совместной подготовке и сложных разговорах.',
            'content'=>'Готовая сюжетная игра для школьников: переговоры об учебных проектах, распределении ролей, сроках и совместных решениях. Механика «Переговори другого» сохраняется: 2 команды и 4 раунда.',
            'roles'=>"Переговорщик | Участник школьной ситуации, которому нужно добиться конкретного результата без разрушения отношений.\nОппонент | Другой участник ситуации со своей позицией, интересами и ограничениями.",
            'rounds'=>"Р1 · Удержи цель | Добиться конкретного результата и сохранить рабочий диалог.\nР2 · Скрытая задача | Продвинуть скрытую коммуникативную задачу, не раскрывая её напрямую.\nР3 · Неудобный вопрос | Отвечать на жёсткие вопросы конкретно и без встречной агрессии.\nР4 · Проверь историю | Отделить подтверждённые факты от предположений и завершить разговор договорённостью.",
        ],
        [
            'slug'=>'demo-negotiation-communicate-school-grade',
            'title'=>'Двойка, которой не было',
            'category'=>'Школьные ситуации',
            'subtitle'=>'Переговори другого — для школьников',
            'audience'=>'10 класс, подростковые группы, классные часы и тренинги коммуникации.',
            'product'=>'persuade_school_grade_v1',
            'icon'=>'⚗',
            'excerpt'=>'Лера уверена, что контрольную по химии проверили не по её варианту. Ей нужно добиться предметной перепроверки без конфликта.',
            'content'=>'Десятиклассница Лера уверена, что учитель химии Виктор Палыч поставил ей двойку за контрольную ошибочно: она решала вариант Б, а работа, по её мнению, была проверена как вариант А. Лера идёт разговаривать. Виктор Палыч — человек старой закалки и не любит, когда ученики «качают права».',
            'roles'=>"Переговорщик | Лера — десятиклассница. Её задача — спокойно добиться проверки фактов и корректного решения.\nОппонент | Виктор Палыч — учитель химии. Он уверен в своей проверке, ценит порядок и болезненно реагирует на давление.",
            'rounds'=>"Р1 · Удержи цель | Добиться предметной перепроверки контрольной, не переводя разговор в конфликт.\nР2 · Скрытая задача | Сохранить рабочий тон и вывести разговор на проверяемые факты и процедуру пересмотра.\nР3 · Неудобный вопрос | Ответить на жёсткие вопросы Виктора Палыча без ухода от сути и встречной агрессии.\nР4 · Проверь историю | Отделить подтверждённые факты от предположений и завершить разговор конкретной договорённостью.",
        ],
        [
            'slug'=>'demo-negotiation-communicate-student',
            'title'=>'Переговори другого — для студентов',
            'category'=>'Для студентов',
            'subtitle'=>'Переговори другого — для студентов',
            'audience'=>'Студенты, проектные команды, студенческие объединения и практикумы коммуникации.',
            'product'=>'persuade_student_v1',
            'icon'=>'📚',
            'excerpt'=>'Сюжеты о студенческих проектах, дедлайнах, общежитии, распределении ответственности и сложных разговорах.',
            'content'=>'Готовая сюжетная игра для студентов: переговоры об учебных проектах, сроках, распределении ответственности, совместной работе и спорных решениях.',
            'roles'=>"Переговорщик | Студент, которому нужно добиться решения по учебной или командной ситуации.\nОппонент | Одногруппник или другой участник ситуации со своей позицией и ограничениями.",
            'rounds'=>"Р1 · Удержи цель | Добиться результата по студенческой ситуации.\nР2 · Скрытая задача | Продвинуть дополнительную задачу, не раскрывая её напрямую.\nР3 · Неудобный вопрос | Отвечать на неудобные вопросы без ухода от сути.\nР4 · Проверь историю | Проверить факты и завершить разговор конкретной договорённостью.",
        ],
        [
            'slug'=>'demo-negotiation-communicate-leader',
            'title'=>'Переговори другого — для руководителей',
            'category'=>'Для руководителей',
            'subtitle'=>'Переговори другого — для руководителей',
            'audience'=>'Руководители, предприниматели, проектные команды и кадровый резерв.',
            'product'=>'persuade_leader_v1',
            'icon'=>'🧭',
            'excerpt'=>'Управленческие переговоры о приоритетах, нагрузке, ответственности, обратной связи и конфликтах.',
            'content'=>'Готовая сюжетная игра для руководителей: переговоры о приоритетах, нагрузке, ответственности, обратной связи, ресурсах и конфликтных управленческих ситуациях.',
            'roles'=>"Переговорщик | Руководитель или менеджер, которому нужно добиться управленческого результата.\nОппонент | Сотрудник, коллега или партнёр со своей позицией, интересами и ограничениями.",
            'rounds'=>"Р1 · Удержи цель | Добиться управленческого результата и сохранить рабочие отношения.\nР2 · Скрытая задача | Решить дополнительную управленческую задачу, не раскрывая её напрямую.\nР3 · Неудобный вопрос | Отвечать на жёсткие вопросы и возражения конкретно.\nР4 · Проверь историю | Проверить факты, отделить интерпретации и зафиксировать решение.",
        ],
        [
            'slug'=>'demo-negotiation-communicate-family',
            'title'=>'Переговори другого — Семейные ситуации',
            'category'=>'Семейные ситуации',
            'subtitle'=>'Переговори другого — Семейные ситуации',
            'audience'=>'Семьи, родители и дети, семейные практикумы и тренинги коммуникации.',
            'product'=>'persuade_family_v1',
            'icon'=>'🏠',
            'excerpt'=>'Сюжеты о домашних договорённостях, границах, совместных планах, распределении дел и сложных семейных разговорах.',
            'content'=>'Готовая сюжетная игра о семейных ситуациях: домашние договорённости, границы, совместные планы, распределение дел и поиск решения без эскалации конфликта.',
            'roles'=>"Переговорщик | Член семьи, которому нужно добиться конкретной договорённости.\nОппонент | Другой член семьи со своей позицией, привычками, интересами и ограничениями.",
            'rounds'=>"Р1 · Удержи цель | Добиться семейной договорённости без эскалации конфликта.\nР2 · Скрытая задача | Решить дополнительную задачу, не раскрывая её напрямую.\nР3 · Неудобный вопрос | Отвечать на острые вопросы спокойно и конкретно.\nР4 · Проверь историю | Отделить факты от предположений и зафиксировать договорённость.",
        ],
    ];

    foreach($variants as $v){
        $existing=get_posts([
            'post_type'=>ckm_quiz_pro_ready_game_post_type(),
            'post_status'=>['publish','draft','pending','private'],
            'posts_per_page'=>1,
            'fields'=>'ids',
            'meta_key'=>'_ckm_ready_product_key',
            'meta_value'=>$v['product'],
        ]);
        if($existing) continue;

        $quizId=(int)$wpdb->get_var($wpdb->prepare(
            'SELECT id FROM '.ckm_quiz_pro_table('quizzes').' WHERE slug=%s ORDER BY id DESC LIMIT 1',
            $v['slug']
        ));
        if($quizId<=0) continue;

        $postId=wp_insert_post([
            'post_type'=>ckm_quiz_pro_ready_game_post_type(),
            'post_status'=>'publish',
            'post_title'=>$v['title'],
            'post_excerpt'=>$v['excerpt'],
            'post_content'=>$v['content'],
        ],true);
        if(is_wp_error($postId) || (int)$postId<=0) continue;

        $meta=[
            'category'=>$v['category'],
            'subtitle'=>$v['subtitle'],
            'audience'=>$v['audience'],
            'price'=>990,
            'product_key'=>$v['product'],
            'quiz_id'=>$quizId,
            'icon'=>$v['icon'],
            'roles'=>$v['roles'],
            'rounds'=>$v['rounds'],
            'rules'=>'Каждый раунд оценивается ИИ-арбитром по семи критериям от 0 до 10. Максимум одного раунда — 70, всей игры — 280. Бонусов и штрафов нет. Если итоговый разрыв не превышает 3 баллов, объявляется обоюдная победа — ничья в пользу диалога.',
        ];
        foreach($meta as $key=>$value) update_post_meta((int)$postId,'_ckm_ready_'.$key,$value);
    }

    update_option('ckm_quiz_pro_ready_games_seeded_v2','1',false);
}, 30);

add_filter('manage_'.'ckm_ready_game'.'_posts_columns', static function (array $columns): array {
    return [
        'cb'=>$columns['cb'] ?? '<input type="checkbox" />',
        'title'=>'Игра',
        'ckm_ready_category'=>'Категория',
        'ckm_ready_price'=>'Цена',
        'ckm_ready_quiz'=>'Шаблон',
        'ckm_ready_product'=>'Ключ оплаты',
        'date'=>$columns['date'] ?? 'Дата',
    ];
});
add_action('manage_'.'ckm_ready_game'.'_posts_custom_column', static function (string $column,int $postId): void {
    $m=ckm_quiz_pro_ready_game_meta($postId);
    if($column==='ckm_ready_category') echo esc_html((string)$m['category']);
    elseif($column==='ckm_ready_price') echo (int)$m['price'].' ₽';
    elseif($column==='ckm_ready_quiz') echo $m['quiz_id'] ? '#'.(int)$m['quiz_id'] : '—';
    elseif($column==='ckm_ready_product') echo '<code>'.esc_html((string)$m['product_key']).'</code>';
},10,2);

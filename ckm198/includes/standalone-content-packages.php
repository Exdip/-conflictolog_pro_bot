<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_CONTENT_SCHEMA = 'ckm.game-package.v1';
const CKM_QUIZ_PRO_CONTENT_SIGNATURE_SCHEMA = 'ckm.content-package.v1';

function ckm_quiz_pro_package_json($value, bool $pretty = true): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) $flags |= JSON_PRETTY_PRINT;
    $json = wp_json_encode($value, $flags);
    return is_string($json) ? $json : '';
}

function ckm_quiz_pro_package_table_columns(string $table): array {
    global $wpdb;
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A) ?: [];
    $out = [];
    foreach ($rows as $row) if (!empty($row['Field'])) $out[(string)$row['Field']] = true;
    return $out;
}

function ckm_quiz_pro_package_filter_row(string $table, array $row): array {
    $cols = ckm_quiz_pro_package_table_columns($table);
    if (!$cols) return [];
    return array_intersect_key($row, $cols);
}

function ckm_quiz_pro_package_unique_slug(string $base, int $ignoreId = 0): string {
    global $wpdb;
    $table = ckm_quiz_pro_table('quizzes');
    $base = sanitize_title($base);
    if ($base === '') $base = 'ckm-game';
    $slug = $base;
    for ($i=2; $i<1000; $i++) {
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug=%s AND id<>%d", $slug, $ignoreId));
        if ($exists === 0) return $slug;
        $slug = $base . '-' . $i;
    }
    return $base . '-' . time();
}

function ckm_quiz_pro_package_clean_quiz(array $quiz): array {
    foreach (['id','created_by_user_id','updated_by_user_id','created_at','updated_at','published_at','archived_at'] as $k) unset($quiz[$k]);
    $quiz['current_revision'] = 1;
    return $quiz;
}

function ckm_quiz_pro_package_clean_round(array $round): array {
    foreach (['id','quiz_id','quiz_revision','created_at','updated_at'] as $k) unset($round[$k]);
    return $round;
}

function ckm_quiz_pro_package_clean_question(array $question, string $roundKey = ''): array {
    foreach (['id','quiz_id','quiz_revision','round_id','created_by_user_id','created_at','updated_at'] as $k) unset($question[$k]);
    if ($roundKey !== '') $question['round_key'] = $roundKey;
    return $question;
}

function ckm_quiz_pro_package_build_payload(int $quizId, array $opts = []) {
    global $wpdb;
    $qz = ckm_quiz_pro_table('quizzes');
    $qq = ckm_quiz_pro_table('questions');
    $rr = ckm_quiz_pro_table('rounds');
    $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$qz} WHERE id=%d LIMIT 1", $quizId), ARRAY_A);
    if (!$quiz) return new WP_Error('package_quiz_missing','Квиз для экспорта не найден.');
    $revision = max(1, (int)($quiz['current_revision'] ?? 1));
    $rounds = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND status='active' ORDER BY position,id", $quizId, $revision), ARRAY_A) ?: [];
    $questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND status='active' ORDER BY position,id", $quizId, $revision), ARRAY_A) ?: [];
    if (!$questions) return new WP_Error('package_questions_missing','В текущей редакции квиза нет активных вопросов.');

    $roundKeys = [];
    foreach ($rounds as $r) $roundKeys[(int)$r['id']] = (string)($r['round_key'] ?? ('round-'.(int)($r['position'] ?? 1)));
    $cleanRounds = array_map('ckm_quiz_pro_package_clean_round', $rounds);
    $cleanQuestions = [];
    foreach ($questions as $q) $cleanQuestions[] = ckm_quiz_pro_package_clean_question($q, $roundKeys[(int)($q['round_id'] ?? 0)] ?? '');
    $cleanQuiz = ckm_quiz_pro_package_clean_quiz($quiz);

    $quizJson = ckm_quiz_pro_package_json($cleanQuiz);
    $roundsJson = ckm_quiz_pro_package_json(array_values($cleanRounds));
    $questionsJson = ckm_quiz_pro_package_json(array_values($cleanQuestions));
    if ($quizJson === '' || $roundsJson === '' || $questionsJson === '') return new WP_Error('package_json_failed','Не удалось сериализовать пакет игры.');

    $fileHashes = [
        'quiz.json'=>hash('sha256',$quizJson),
        'rounds.json'=>hash('sha256',$roundsJson),
        'questions.json'=>hash('sha256',$questionsJson),
    ];
    $contentHash = hash('sha256', implode('', $fileHashes));
    $host = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
    $stable = $host.'|'.(string)($quiz['slug'] ?? '');
    $packageId = isset($opts['package_id']) && is_string($opts['package_id']) && $opts['package_id'] !== ''
        ? sanitize_key($opts['package_id'])
        : 'ckm-local-' . substr(hash('sha256',$stable),0,24);
    $manifest = [
        'schema'=>CKM_QUIZ_PRO_CONTENT_SCHEMA,
        'package_id'=>$packageId,
        'package_version'=>'r'.$revision,
        'title'=>(string)($quiz['title'] ?? 'Игра CKM'),
        'slug'=>(string)($quiz['slug'] ?? ''),
        'format_key'=>(string)($quiz['format_key'] ?? 'classic_quiz'),
        'publisher'=>(string)($opts['publisher'] ?? get_bloginfo('name')),
        'source_site'=>$host,
        'source_quiz_id'=>$quizId,
        'source_revision'=>$revision,
        'editable'=>array_key_exists('editable',$opts) ? (bool)$opts['editable'] : true,
        'distribution'=>(string)($opts['distribution'] ?? 'local'),
        'created_at'=>gmdate('c'),
        'content_sha256'=>$contentHash,
        'signature_mode'=>'local_unsigned',
    ];
    $manifestJson = ckm_quiz_pro_package_json($manifest);
    $hashes = ['schema'=>'ckm.game-package.hashes.v1','files'=>$fileHashes,'content_sha256'=>$contentHash];
    $hashesJson = ckm_quiz_pro_package_json($hashes);
    return [
        'manifest'=>$manifest,
        'files'=>[
            'manifest.json'=>$manifestJson,
            'quiz.json'=>$quizJson,
            'rounds.json'=>$roundsJson,
            'questions.json'=>$questionsJson,
            'hashes.json'=>$hashesJson,
        ],
    ];
}

function ckm_quiz_pro_package_zip_from_payload(array $payload) {
    if (!class_exists('ZipArchive')) return new WP_Error('zip_unavailable','На сервере недоступно расширение PHP ZipArchive.');
    $tmp = wp_tempnam('ckm-game-package');
    if (!$tmp) return new WP_Error('package_temp_failed','Не удалось создать временный файл пакета.');
    @unlink($tmp);
    $zipPath = $tmp . '.ckmgame';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return new WP_Error('package_zip_open_failed','Не удалось создать .ckmgame.');
    foreach ((array)($payload['files'] ?? []) as $name=>$content) $zip->addFromString((string)$name,(string)$content);
    $zip->close();
    return $zipPath;
}

function ckm_quiz_pro_package_stream_quiz(int $quizId): void {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');
    check_admin_referer('ckm_quiz_pro_export_package_'.$quizId);
    $payload = ckm_quiz_pro_package_build_payload($quizId,['publisher'=>get_bloginfo('name'),'editable'=>true,'distribution'=>'local_backup']);
    if (is_wp_error($payload)) wp_die(esc_html($payload->get_error_message()));
    $path = ckm_quiz_pro_package_zip_from_payload($payload);
    if (is_wp_error($path)) wp_die(esc_html($path->get_error_message()));
    $slug = sanitize_file_name((string)($payload['manifest']['slug'] ?? 'ckm-game'));
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$slug.'-'.$payload['manifest']['package_version'].'.ckmgame"');
    header('Content-Length: '.filesize($path));
    readfile($path);
    @unlink($path);
    exit;
}
add_action('admin_post_ckm_quiz_pro_export_package', static function(){ ckm_quiz_pro_package_stream_quiz(absint($_GET['quiz_id'] ?? 0)); });

function ckm_quiz_pro_package_read_zip(string $path) {
    if (!class_exists('ZipArchive')) return new WP_Error('zip_unavailable','На сервере недоступно расширение PHP ZipArchive.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return new WP_Error('package_open_failed','Файл не является корректным .ckmgame-пакетом.');
    $allowed = ['manifest.json','quiz.json','rounds.json','questions.json','hashes.json','signature.json'];
    $files = [];
    for ($i=0; $i<$zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!in_array($name,$allowed,true)) continue;
        $data = $zip->getFromIndex($i);
        if ($data !== false) $files[$name] = $data;
    }
    $zip->close();
    foreach (['manifest.json','quiz.json','rounds.json','questions.json','hashes.json'] as $required) {
        if (!isset($files[$required])) return new WP_Error('package_file_missing','В пакете отсутствует '.$required.'.');
    }
    return $files;
}

function ckm_quiz_pro_package_decode_json(string $raw, string $name) {
    $v = json_decode($raw,true);
    if (!is_array($v)) return new WP_Error('package_json_invalid','Некорректный JSON: '.$name.'.');
    return $v;
}

function ckm_quiz_pro_package_verify_files(array $files) {
    $manifest = ckm_quiz_pro_package_decode_json($files['manifest.json'],'manifest.json');
    $quiz = ckm_quiz_pro_package_decode_json($files['quiz.json'],'quiz.json');
    $rounds = ckm_quiz_pro_package_decode_json($files['rounds.json'],'rounds.json');
    $questions = ckm_quiz_pro_package_decode_json($files['questions.json'],'questions.json');
    $hashes = ckm_quiz_pro_package_decode_json($files['hashes.json'],'hashes.json');
    foreach ([$manifest,$quiz,$rounds,$questions,$hashes] as $v) if (is_wp_error($v)) return $v;
    if ((string)($manifest['schema'] ?? '') !== CKM_QUIZ_PRO_CONTENT_SCHEMA) return new WP_Error('package_schema_invalid','Неподдерживаемая версия формата игровой пакет.');
    if (empty($manifest['package_id']) || empty($manifest['title']) || empty($manifest['format_key'])) return new WP_Error('package_manifest_invalid','В manifest.json отсутствуют обязательные поля.');
    if (!is_array($rounds) || !is_array($questions) || !$questions) return new WP_Error('package_content_invalid','Пакет не содержит вопросов.');
    $expectedFiles = (array)($hashes['files'] ?? []);
    $actual = [];
    foreach (['quiz.json','rounds.json','questions.json'] as $name) {
        $actual[$name] = hash('sha256',$files[$name]);
        if (empty($expectedFiles[$name]) || !hash_equals(strtolower((string)$expectedFiles[$name]), strtolower($actual[$name]))) return new WP_Error('package_hash_mismatch','Нарушена целостность '.$name.'.');
    }
    $contentHash = hash('sha256', implode('', $actual));
    if (empty($hashes['content_sha256']) || !hash_equals(strtolower((string)$hashes['content_sha256']), strtolower($contentHash))) return new WP_Error('package_content_hash_mismatch','Нарушена целостность содержимого пакета.');
    if (empty($manifest['content_sha256']) || !hash_equals(strtolower((string)$manifest['content_sha256']), strtolower($contentHash))) return new WP_Error('package_manifest_hash_mismatch','Manifest не соответствует содержимому пакета.');

    $trust = 'local_unsigned'; $signingKey = '';
    if (isset($files['signature.json'])) {
        $sig = ckm_quiz_pro_package_decode_json($files['signature.json'],'signature.json');
        if (is_wp_error($sig)) return $sig;
        if (!function_exists('ckm_quiz_pro_verify_signed_envelope')) return new WP_Error('package_signature_unavailable','Проверка Ed25519-подписи недоступна.');
        $signed = ckm_quiz_pro_verify_signed_envelope($sig, CKM_QUIZ_PRO_CONTENT_SIGNATURE_SCHEMA);
        if (is_wp_error($signed)) return $signed;
        foreach (['package_id','package_version','content_sha256','manifest_sha256'] as $required) if (!isset($signed[$required])) return new WP_Error('package_signature_payload_invalid','Неполный signed content-package.');
        if (!hash_equals((string)$manifest['package_id'],(string)$signed['package_id']) || !hash_equals((string)$manifest['package_version'],(string)$signed['package_version']) || !hash_equals(strtolower($contentHash),strtolower((string)$signed['content_sha256'])) || !hash_equals(strtolower(hash('sha256',$files['manifest.json'])),strtolower((string)$signed['manifest_sha256']))) return new WP_Error('package_signature_binding_failed','Ed25519-подпись не соответствует этому пакету.');
        $trust = 'ckm_signed'; $signingKey = (string)($sig['key_id'] ?? '');
    }
    return ['manifest'=>$manifest,'quiz'=>$quiz,'rounds'=>$rounds,'questions'=>$questions,'trust_level'=>$trust,'signing_key_id'=>$signingKey,'content_sha256'=>$contentHash];
}

function ckm_quiz_pro_package_import_file(string $path) {
    if (!current_user_can('manage_options')) return new WP_Error('forbidden','Недостаточно прав.');
    $files = ckm_quiz_pro_package_read_zip($path); if (is_wp_error($files)) return $files;
    $package = ckm_quiz_pro_package_verify_files($files); if (is_wp_error($package)) return $package;
    global $wpdb;
    $manifest = $package['manifest'];
    $pkgTable = ckm_quiz_pro_table('packages');
    $qz = ckm_quiz_pro_table('quizzes'); $rr = ckm_quiz_pro_table('rounds'); $qq = ckm_quiz_pro_table('questions');
    $packageId = sanitize_key((string)$manifest['package_id']);
    $existingPkg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$pkgTable} WHERE package_id=%s LIMIT 1",$packageId),ARRAY_A);
    if ($existingPkg && !empty($existingPkg['content_sha256']) && hash_equals(strtolower((string)$existingPkg['content_sha256']), strtolower((string)$package['content_sha256']))) {
        return ['ok'=>true,'quiz_id'=>(int)$existingPkg['quiz_id'],'quiz_revision'=>(int)$existingPkg['quiz_revision'],'package_id'=>$packageId,'title'=>(string)$manifest['title'],'trust_level'=>$package['trust_level'],'updated'=>false,'already_current'=>true];
    }
    $now = current_time('mysql');
    $wpdb->query('START TRANSACTION');
    try {
        $quizId = $existingPkg ? (int)$existingPkg['quiz_id'] : 0;
        $revision = 1;
        $quizRow = (array)$package['quiz'];
        if (!ckmqp_content_ready()) throw new RuntimeException('Обновление принадлежности шаблонов не завершено.');
        $quizRow['tenant_id']=0;
        $quizRow['content_scope']='shared';
        $quizRow['title'] = sanitize_text_field((string)($manifest['title'] ?? ($quizRow['title'] ?? 'Игра CKM')));
        $quizRow['format_key'] = sanitize_key((string)($manifest['format_key'] ?? ($quizRow['format_key'] ?? 'classic_quiz')));
        $quizRow['status'] = 'published';
        $quizRow['created_by_user_id'] = get_current_user_id();
        $quizRow['updated_by_user_id'] = get_current_user_id();
        $quizRow['updated_at'] = $now;
        $quizRow['published_at'] = $now;
        $quizRow['archived_at'] = null;
        if ($quizId > 0) {
            $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$qz} WHERE id=%d FOR UPDATE",$quizId),ARRAY_A);
            if (!$old) throw new RuntimeException('Связанный квиз пакета не найден.');
            $revision = max(1,(int)$old['current_revision']) + 1;
            $quizRow['current_revision'] = $revision;
            $quizRow['slug'] = (string)$old['slug'];
            $quizRow = ckm_quiz_pro_package_filter_row($qz,$quizRow);
            unset($quizRow['id'],$quizRow['created_at']);
            if ($wpdb->update($qz,$quizRow,['id'=>$quizId]) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось обновить квиз пакета.');
        } else {
            $quizRow['current_revision'] = 1;
            $quizRow['slug'] = ckm_quiz_pro_package_unique_slug((string)($manifest['slug'] ?? sanitize_title($quizRow['title'])));
            $quizRow['created_at'] = $now;
            $quizRow = ckm_quiz_pro_package_filter_row($qz,$quizRow);
            unset($quizRow['id']);
            if ($wpdb->insert($qz,$quizRow) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось создать квиз из пакета.');
            $quizId = (int)$wpdb->insert_id;
        }

        $roundMap = [];
        $roundRows = (array)$package['rounds'];
        if (!$roundRows) $roundRows = [['round_key'=>'round-1','position'=>1,'title'=>'Основной раунд','round_type'=>'questions','rules_json'=>'{}','settings_json'=>'{}','status'=>'active']];
        foreach ($roundRows as $i=>$r) {
            if (!is_array($r)) continue;
            $key = sanitize_key((string)($r['round_key'] ?? 'round-'.($i+1))); if ($key==='') $key='round-'.($i+1);
            $r['quiz_id']=$quizId; $r['quiz_revision']=$revision; $r['round_key']=$key; $r['position']=max(1,(int)($r['position'] ?? ($i+1))); $r['status']='active'; $r['created_at']=$now; $r['updated_at']=$now;
            $r=ckm_quiz_pro_package_filter_row($rr,$r); unset($r['id']);
            if ($wpdb->insert($rr,$r) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось импортировать раунд.');
            $roundMap[$key]=(int)$wpdb->insert_id;
        }
        $defaultRoundId = (int)reset($roundMap);
        $pos=0;
        foreach ((array)$package['questions'] as $q) {
            if (!is_array($q)) continue; $pos++;
            $roundKey=sanitize_key((string)($q['round_key'] ?? '')); unset($q['round_key']);
            $q['quiz_id']=$quizId; $q['quiz_revision']=$revision; $q['round_id']=$roundMap[$roundKey] ?? $defaultRoundId; $q['position']=max(1,(int)($q['position'] ?? $pos)); $q['question_key']=sanitize_key((string)($q['question_key'] ?? 'q-'.$pos)); if($q['question_key']==='')$q['question_key']='q-'.$pos;
            $q['status']='active'; $q['created_by_user_id']=get_current_user_id(); $q['created_at']=$now; $q['updated_at']=$now;
            $q=ckm_quiz_pro_package_filter_row($qq,$q); unset($q['id']);
            if ($wpdb->insert($qq,$q) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось импортировать вопрос №'.$pos.'.');
        }
        if ($pos===0) throw new RuntimeException('В пакете нет импортируемых вопросов.');

        $pkgRow=[
            'package_id'=>$packageId,'package_version'=>sanitize_text_field((string)($manifest['package_version'] ?? '1')),'title'=>sanitize_text_field((string)$manifest['title']),'format_key'=>sanitize_key((string)$manifest['format_key']),'quiz_id'=>$quizId,'quiz_revision'=>$revision,
            'source_type'=>$package['trust_level']==='ckm_signed'?'ckm_cloud':'manual_import','trust_level'=>$package['trust_level'],'signing_key_id'=>$package['signing_key_id'],'editable'=>!empty($manifest['editable'])?1:0,'content_sha256'=>$package['content_sha256'],'manifest_json'=>ckm_quiz_pro_package_json($manifest,false),'installed_at'=>$existingPkg?(string)$existingPkg['installed_at']:$now,'updated_at'=>$now,
        ];
        $pkgRow=ckm_quiz_pro_package_filter_row($pkgTable,$pkgRow);
        if ($existingPkg) {
            if ($wpdb->update($pkgTable,$pkgRow,['id'=>(int)$existingPkg['id']]) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось обновить запись пакета.');
        } else {
            if ($wpdb->insert($pkgTable,$pkgRow) === false) throw new RuntimeException($wpdb->last_error ?: 'Не удалось зарегистрировать пакет.');
        }
        $wpdb->query('COMMIT');
        return ['ok'=>true,'quiz_id'=>$quizId,'quiz_revision'=>$revision,'package_id'=>$packageId,'title'=>(string)$manifest['title'],'trust_level'=>$package['trust_level'],'updated'=>(bool)$existingPkg];
    } catch (Throwable $e) {
        $wpdb->query('ROLLBACK');
        return new WP_Error('package_import_failed',$e->getMessage());
    }
}


function ckm_quiz_pro_package_runtime_ready(string $formatKey): bool {
    // All three standalone runtimes are available locally. Protected semantic AI
    // remains a Облачный сервис concern; Jeopardy uses the local reference matcher here.
    return in_array(sanitize_key($formatKey), ['classic_quiz','chgk','jeopardy'], true);
}

function ckm_quiz_pro_package_is_readonly_quiz(int $quizId): bool {
    global $wpdb;
    $table=ckm_quiz_pro_table('packages');
    if ($table==='') return false;
    $v=$wpdb->get_var($wpdb->prepare("SELECT editable FROM {$table} WHERE quiz_id=%d ORDER BY id DESC LIMIT 1",$quizId));
    return $v !== null && (int)$v === 0;
}

function ckm_quiz_pro_my_games_page(): void {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $notice=''; $error='';
    if (function_exists('ckm_quiz_pro_entitlements_admin_actions')) { $ea=ckm_quiz_pro_entitlements_admin_actions(); if(!empty($ea['notice']))$notice=$ea['notice']; if(!empty($ea['error']))$error=$ea['error']; }
    if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['ckm_qp_import_package'])) {
        check_admin_referer('ckm_quiz_pro_import_package');
        $f=$_FILES['ckmgame'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) $error='Выберите файл .ckmgame.';
        elseif ((int)($f['size'] ?? 0) > 50*1024*1024) $error='Пакет больше 50 МБ.';
        elseif (strtolower(pathinfo((string)($f['name'] ?? ''),PATHINFO_EXTENSION))!=='ckmgame') $error='Нужен файл с расширением .ckmgame.';
        else {
            $r=ckm_quiz_pro_package_import_file((string)$f['tmp_name']);
            if (is_wp_error($r)) $error=$r->get_error_message();
            else $notice=(!empty($r['already_current'])?'Эта версия уже установлена: ':($r['updated']?'Пакет обновлён: ':'Игра установлена: ')).$r['title'].' · доверие: '.$r['trust_level'].'.';
        }
    }
    $rows=$wpdb->get_results('SELECT p.*,q.status quiz_status,q.title quiz_title FROM '.ckm_quiz_pro_table('packages').' p LEFT JOIN '.ckm_quiz_pro_table('quizzes').' q ON q.id=p.quiz_id ORDER BY p.updated_at DESC,p.id DESC',ARRAY_A) ?: [];
    echo '<div class="wrap"><h1>Игровые пакеты</h1><p>Здесь отображаются игры, установленные из игровой пакет. После подключения Облачный сервис этот же экран будет автоматически показывать игры, купленные по вашей лицензии.</p>';
    if($notice!=='') echo '<div class="notice notice-success"><p>'.esc_html($notice).'</p></div>'; if($error!=='') echo '<div class="notice notice-error"><p>'.esc_html($error).'</p></div>';
    if (function_exists('ckm_quiz_pro_render_entitlements_catalog')) ckm_quiz_pro_render_entitlements_catalog();
    echo '<div class="card" style="max-width:900px"><h2>Импортировать игру</h2><form method="post" enctype="multipart/form-data">'; wp_nonce_field('ckm_quiz_pro_import_package'); echo '<input type="file" name="ckmgame" accept=".ckmgame,application/zip" required> <button class="button button-primary" name="ckm_qp_import_package" value="1">Установить .ckmgame</button></form><p><small>Подписанные CKM-пакеты проверяются Ed25519. Локальные пакеты без подписи допускаются только при ручном импорте администратором и помечаются как local_unsigned.</small></p></div>';
    echo '<h2>Установленные пакеты</h2><table class="widefat striped"><thead><tr><th>Игра</th><th>Формат</th><th>Версия</th><th>Доверие</th><th>Редактирование</th><th>Действия</th></tr></thead><tbody>';
    foreach($rows as $r){ $canRun=ckm_quiz_pro_package_runtime_ready((string)$r['format_key']); $actions=[]; if($canRun)$actions[]='<a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ckm-quiz-pro-games&quiz='.(int)$r['quiz_id'])).'">Создать игру</a>'; else $actions[]='<span style="color:#996800">Установлено · standalone runtime этого формата будет подключён следующим этапом</span>'; echo '<tr><td><strong>'.esc_html($r['title']).'</strong><br><code>'.esc_html($r['package_id']).'</code></td><td>'.esc_html($r['format_key']).'</td><td>'.esc_html($r['package_version']).'<br><small>ревизия '.$r['quiz_revision'].'</small></td><td>'.($r['trust_level']==='ckm_signed'?'<strong style="color:#008a20">Ed25519</strong>':'<span style="color:#996800">local_unsigned</span>').'</td><td>'.((int)$r['editable']===1?'Разрешено':'Управляется пакетом').'</td><td>'.implode(' ',$actions).'</td></tr>'; }
    if(!$rows) echo '<tr><td colspan="6">Импортированных игр пока нет.</td></tr>';
    echo '</tbody></table></div>';
}

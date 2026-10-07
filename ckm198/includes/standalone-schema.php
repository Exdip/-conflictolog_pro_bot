<?php
if (!defined('ABSPATH')) exit;

const CKM_QUIZ_PRO_DB_VERSION = '0.3.14.16';

function ckm_quiz_pro_install_schema(): bool {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $t = static fn(string $n): string => $wpdb->prefix . 'ckm_quiz_' . $n;

    $sql = [];
    $sql[] = "CREATE TABLE {$t('quizzes')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      title varchar(190) NOT NULL DEFAULT '', slug varchar(190) NOT NULL DEFAULT '', format_key varchar(64) NOT NULL DEFAULT 'classic_quiz',
      short_description text NULL, instructions longtext NULL, cover_url text NULL, status varchar(20) NOT NULL DEFAULT 'draft', current_revision int unsigned NOT NULL DEFAULT 1,
      min_teams tinyint unsigned NOT NULL DEFAULT 2, max_teams tinyint unsigned NOT NULL DEFAULT 10, host_mode varchar(20) NOT NULL DEFAULT 'ai', judge_mode varchar(20) NOT NULL DEFAULT 'ai',
      seconds_per_question int unsigned NOT NULL DEFAULT 30, scoring_policy_json longtext NULL, settings_json longtext NULL, format_settings_json longtext NULL,
      created_by_user_id bigint unsigned NOT NULL DEFAULT 0, updated_by_user_id bigint unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL, published_at datetime NULL, archived_at datetime NULL,
      PRIMARY KEY (id), UNIQUE KEY slug (slug), KEY status (status), KEY format_key (format_key)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('questions')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, quiz_id bigint unsigned NOT NULL DEFAULT 0, quiz_revision int unsigned NOT NULL DEFAULT 1,
      question_key varchar(64) NOT NULL DEFAULT '', position int unsigned NOT NULL DEFAULT 1, round_no smallint unsigned NOT NULL DEFAULT 1, round_title varchar(190) NOT NULL DEFAULT '', round_id bigint unsigned NOT NULL DEFAULT 0,
      question_stage varchar(20) NOT NULL DEFAULT 'main', jeopardy_category_key varchar(64) NOT NULL DEFAULT '', jeopardy_category_title varchar(190) NOT NULL DEFAULT '', jeopardy_value int unsigned NOT NULL DEFAULT 0, jeopardy_special_type varchar(32) NOT NULL DEFAULT '',
      question_type varchar(24) NOT NULL DEFAULT 'single_choice', question_text longtext NULL, options_json longtext NULL, correct_answers_json longtext NULL, numeric_tolerance decimal(14,4) NOT NULL DEFAULT 0,
      points int unsigned NOT NULL DEFAULT 1, time_limit_seconds int unsigned NOT NULL DEFAULT 30, scoring_rule_json longtext NULL, explanation longtext NULL, host_script longtext NULL,
      media_url text NULL, media_type varchar(16) NOT NULL DEFAULT '', media_start_seconds int unsigned NOT NULL DEFAULT 0, media_end_seconds int unsigned NOT NULL DEFAULT 0,
      status varchar(20) NOT NULL DEFAULT 'active', created_by_user_id bigint unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY quiz_revision_question (quiz_id,quiz_revision,question_key), KEY quiz_revision_position (quiz_id,quiz_revision,position), KEY round_id (round_id), KEY status (status)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('formats')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, format_key varchar(64) NOT NULL DEFAULT '', title varchar(190) NOT NULL DEFAULT '', description text NULL,
      format_version int unsigned NOT NULL DEFAULT 1, engine_contract varchar(40) NOT NULL DEFAULT 'format_layer_v1', status varchar(20) NOT NULL DEFAULT 'planned', is_builtin tinyint unsigned NOT NULL DEFAULT 0,
      capabilities_json longtext NULL, default_settings_json longtext NULL, contract_json longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY format_key (format_key), KEY status (status)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('rounds')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, quiz_id bigint unsigned NOT NULL DEFAULT 0, quiz_revision int unsigned NOT NULL DEFAULT 1, round_key varchar(64) NOT NULL DEFAULT '', position smallint unsigned NOT NULL DEFAULT 1,
      title varchar(190) NOT NULL DEFAULT '', round_type varchar(40) NOT NULL DEFAULT 'questions', rules_json longtext NULL, settings_json longtext NULL, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY quiz_revision_round_key (quiz_id,quiz_revision,round_key), UNIQUE KEY quiz_revision_position (quiz_id,quiz_revision,position), KEY quiz_id (quiz_id)
    ) {$c};";

    // Shared Quiz Core uses this table for Jeopardy cells/buzzer/special mechanics
    // and other format mechanics. 0.3.12 accidentally mapped the table in the
    // adapter but never created it, so the first cell selection failed with
    // mechanic_insert_failed. Keep this definition in sync with the main CKM
    // quiz schema.
    $sql[] = "CREATE TABLE {$t('mechanics')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      game_id bigint unsigned NOT NULL DEFAULT 0, team_id bigint unsigned NOT NULL DEFAULT 0, round_id bigint unsigned NOT NULL DEFAULT 0, question_id bigint unsigned NOT NULL DEFAULT 0,
      mechanic_type varchar(24) NOT NULL DEFAULT '', mechanic_key varchar(64) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'pending',
      value_int int NOT NULL DEFAULT 0, result_int int NOT NULL DEFAULT 0, actor_type varchar(20) NOT NULL DEFAULT 'system', actor_user_id bigint unsigned NOT NULL DEFAULT 0,
      idempotency_key varchar(64) NOT NULL DEFAULT '', score_event_id bigint unsigned NOT NULL DEFAULT 0, payload_json longtext NULL,
      created_at datetime NOT NULL, resolved_at datetime NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_idempotency (game_id,idempotency_key), KEY game_team_type (game_id,team_id,mechanic_type), KEY game_status (game_id,status),
      KEY round_id (round_id), KEY question_id (question_id), KEY score_event_id (score_event_id), KEY created_at (created_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('games')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_code varchar(32) NOT NULL DEFAULT '', game_type varchar(20) NOT NULL DEFAULT 'quiz', sale_id bigint unsigned NOT NULL DEFAULT 0, access_id bigint unsigned NOT NULL DEFAULT 0, training_id bigint unsigned NOT NULL DEFAULT 0,
      quiz_id bigint unsigned NOT NULL DEFAULT 0, quiz_revision int unsigned NOT NULL DEFAULT 1, format_key_snapshot varchar(64) NOT NULL DEFAULT 'classic_quiz', format_version_snapshot int unsigned NOT NULL DEFAULT 1,
      format_settings_snapshot_json longtext NULL, format_contract_snapshot_json longtext NULL, created_by_user_id bigint unsigned NOT NULL DEFAULT 0, host_mode_snapshot varchar(20) NOT NULL DEFAULT 'ai', judge_mode_snapshot varchar(20) NOT NULL DEFAULT 'ai', settings_snapshot_json longtext NULL,
      title varchar(190) NOT NULL DEFAULT '', team_count tinyint unsigned NOT NULL DEFAULT 2, host_mode varchar(20) NOT NULL DEFAULT 'ai', live_room varchar(190) NOT NULL DEFAULT '', status varchar(20) NOT NULL DEFAULT 'waiting', auto_start tinyint unsigned NOT NULL DEFAULT 0,
      countdown_seconds tinyint unsigned NOT NULL DEFAULT 0, countdown_started_at datetime NULL, scoreboard_token varchar(96) NOT NULL DEFAULT '', scoreboard_nonce varchar(96) NOT NULL DEFAULT '', judge_token varchar(96) NOT NULL DEFAULT '', host_token varchar(96) NOT NULL DEFAULT '', host_nonce varchar(96) NOT NULL DEFAULT '',
      quiz_phase varchar(24) NOT NULL DEFAULT 'waiting', round_phase varchar(24) NOT NULL DEFAULT 'waiting', current_round_id bigint unsigned NOT NULL DEFAULT 0, current_round_position smallint unsigned NOT NULL DEFAULT 0, round_started_at datetime NULL, round_closed_at datetime NULL,
      current_question_id bigint unsigned NOT NULL DEFAULT 0, jeopardy_selector_team_id bigint unsigned NOT NULL DEFAULT 0, jeopardy_selected_question_id bigint unsigned NOT NULL DEFAULT 0, current_question_position int unsigned NOT NULL DEFAULT 0,
      question_started_at datetime NULL, question_deadline_at datetime NULL, first_question_started_at datetime NULL, access_consumed_at datetime NULL, state_version bigint unsigned NOT NULL DEFAULT 0, test_mode tinyint unsigned NOT NULL DEFAULT 0,
      is_paused tinyint unsigned NOT NULL DEFAULT 0, paused_at datetime NULL, paused_quiz_phase varchar(24) NOT NULL DEFAULT '', paused_format_phase varchar(32) NOT NULL DEFAULT '', paused_remaining_seconds int unsigned NOT NULL DEFAULT 0,
      arbiter_status varchar(20) NOT NULL DEFAULT 'pending', arbiter_report longtext NULL, arbiter_conversation_id varchar(190) NOT NULL DEFAULT '', arbiter_payload_hash char(64) NOT NULL DEFAULT '', arbiter_error text NULL, arbiter_generated_at datetime NULL,
      created_at datetime NOT NULL, started_at datetime NULL, finished_at datetime NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_code (game_code), KEY quiz_id (quiz_id), KEY status (status), KEY format_key_snapshot (format_key_snapshot)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('history_trash')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, created_by_user_id bigint unsigned NOT NULL DEFAULT 0, tenant_id bigint unsigned NOT NULL DEFAULT 0,
      trashed_by_user_id bigint unsigned NOT NULL DEFAULT 0, trashed_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_id (game_id), KEY owner_tenant (created_by_user_id,tenant_id), KEY trashed_at (trashed_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('history_audit')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, game_code varchar(32) NOT NULL DEFAULT '', game_title varchar(190) NOT NULL DEFAULT '',
      action_key varchar(32) NOT NULL DEFAULT '', created_by_user_id bigint unsigned NOT NULL DEFAULT 0, tenant_id bigint unsigned NOT NULL DEFAULT 0, actor_user_id bigint unsigned NOT NULL DEFAULT 0, actor_name_snapshot varchar(190) NOT NULL DEFAULT '',
      details_json longtext NULL, occurred_at datetime NOT NULL,
      PRIMARY KEY (id), KEY owner_tenant_time (created_by_user_id,tenant_id,occurred_at), KEY game_id (game_id), KEY action_key (action_key)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('history_reports')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, tenant_id bigint unsigned NOT NULL DEFAULT 0, recipient varchar(190) NOT NULL DEFAULT '',
      status varchar(20) NOT NULL DEFAULT 'pending', pdf_path text NULL, pdf_sha256 char(64) NOT NULL DEFAULT '', attempts smallint unsigned NOT NULL DEFAULT 0, last_error text NULL,
      created_at datetime NOT NULL, updated_at datetime NOT NULL, sent_at datetime NULL,
      PRIMARY KEY (id), UNIQUE KEY game_id (game_id), KEY tenant_status (tenant_id,status), KEY sent_at (sent_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('game_teams')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, team_key varchar(8) NOT NULL DEFAULT '', slot_no tinyint unsigned NOT NULL DEFAULT 0, team_name varchar(190) NOT NULL DEFAULT '', captain_user_id bigint unsigned NOT NULL DEFAULT 0,
      team_status varchar(20) NOT NULL DEFAULT 'active', join_token varchar(96) NOT NULL DEFAULT '', join_nonce varchar(96) NOT NULL DEFAULT '', score int NOT NULL DEFAULT 0, turns_count int unsigned NOT NULL DEFAULT 0, facts_count int unsigned NOT NULL DEFAULT 0,
      final_score int NOT NULL DEFAULT 0, final_summary longtext NULL, final_rationale longtext NULL, ready_at datetime NULL, created_at datetime NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_team (game_id,team_key), KEY game_id (game_id), KEY game_slot (game_id,slot_no)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('game_team_members')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, team_id bigint unsigned NOT NULL DEFAULT 0, user_id bigint unsigned NOT NULL,
      member_role varchar(20) NOT NULL DEFAULT 'player', display_name_snapshot varchar(190) NOT NULL DEFAULT '', member_status varchar(20) NOT NULL DEFAULT 'active', joined_at datetime NOT NULL, left_at datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_user (game_id,user_id), KEY team_id (team_id), KEY game_team (game_id,team_id)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('answers')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, team_id bigint unsigned NOT NULL DEFAULT 0, question_id bigint unsigned NOT NULL DEFAULT 0, quiz_revision int unsigned NOT NULL DEFAULT 1, attempt_no smallint unsigned NOT NULL DEFAULT 1,
      answer_text longtext NULL, answer_payload_json longtext NULL, normalized_answer longtext NULL, submitted_by_user_id bigint unsigned NOT NULL DEFAULT 0, response_time_ms int unsigned NOT NULL DEFAULT 0,
      auto_points int NOT NULL DEFAULT 0, awarded_points int NOT NULL DEFAULT 0, verdict varchar(20) NOT NULL DEFAULT 'pending', judge_mode varchar(20) NOT NULL DEFAULT 'automatic', judged_by_user_id bigint unsigned NOT NULL DEFAULT 0, judge_comment longtext NULL,
      submitted_at datetime NOT NULL, reviewed_at datetime NULL, created_at datetime NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_team_question (game_id,team_id,question_id), KEY game_id (game_id), KEY question_id (question_id), KEY verdict (verdict)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('score_events')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, team_id bigint unsigned NOT NULL DEFAULT 0, question_id bigint unsigned NOT NULL DEFAULT 0, answer_id bigint unsigned NOT NULL DEFAULT 0,
      event_type varchar(30) NOT NULL DEFAULT 'answer', points_delta int NOT NULL DEFAULT 0, score_before int NOT NULL DEFAULT 0, score_after int NOT NULL DEFAULT 0, reason text NULL, actor_type varchar(20) NOT NULL DEFAULT 'system', actor_user_id bigint unsigned NOT NULL DEFAULT 0,
      idempotency_key varchar(64) NULL, metadata_json longtext NULL, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_idempotency (game_id,idempotency_key), KEY game_team_created (game_id,team_id,created_at), KEY question_id (question_id)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('events')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, game_id bigint unsigned NOT NULL DEFAULT 0, event_key varchar(64) NULL, action varchar(60) NOT NULL DEFAULT '', actor_type varchar(20) NOT NULL DEFAULT 'system', actor_user_id bigint unsigned NOT NULL DEFAULT 0,
      team_id bigint unsigned NOT NULL DEFAULT 0, question_id bigint unsigned NOT NULL DEFAULT 0, entity_type varchar(30) NOT NULL DEFAULT '', entity_id bigint unsigned NOT NULL DEFAULT 0, payload_json longtext NULL, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY game_event_key (game_id,event_key), KEY game_created (game_id,created_at), KEY action (action)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('packages')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, package_id varchar(190) NOT NULL DEFAULT '', package_version varchar(64) NOT NULL DEFAULT '1', title varchar(190) NOT NULL DEFAULT '', format_key varchar(64) NOT NULL DEFAULT 'classic_quiz',
      quiz_id bigint unsigned NOT NULL DEFAULT 0, quiz_revision int unsigned NOT NULL DEFAULT 1, source_type varchar(32) NOT NULL DEFAULT 'manual_import', trust_level varchar(32) NOT NULL DEFAULT 'local_unsigned', signing_key_id varchar(190) NOT NULL DEFAULT '', editable tinyint unsigned NOT NULL DEFAULT 0,
      content_sha256 char(64) NOT NULL DEFAULT '', manifest_json longtext NULL, installed_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY package_id (package_id), KEY quiz_id (quiz_id), KEY format_key (format_key), KEY trust_level (trust_level)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('game_access')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, user_id bigint unsigned NOT NULL DEFAULT 0, format_key varchar(64) NOT NULL DEFAULT '', started_at datetime NOT NULL, expires_at datetime NOT NULL, status varchar(20) NOT NULL DEFAULT 'active', created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), KEY user_format (user_id,format_key), KEY expires_at (expires_at)
    ) {$c};";

    // Immutable organizer-owned copies of administrator catalogue games.
    // One snapshot is retained per tenant/user + catalogue product so later
    // edits of the administrator source template affect only future buyers.
    $sql[] = "CREATE TABLE {$t('ready_instances')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, tenant_id bigint unsigned NOT NULL DEFAULT 0, owner_user_id bigint unsigned NOT NULL DEFAULT 0, owner_key varchar(96) NOT NULL DEFAULT '',
      product_key varchar(64) NOT NULL DEFAULT '', catalog_post_id bigint unsigned NOT NULL DEFAULT 0, catalog_revision int unsigned NOT NULL DEFAULT 1, source_quiz_id bigint unsigned NOT NULL DEFAULT 0, source_quiz_revision int unsigned NOT NULL DEFAULT 1,
      snapshot_quiz_id bigint unsigned NOT NULL DEFAULT 0, snapshot_revision int unsigned NOT NULL DEFAULT 1, source_signature char(64) NOT NULL DEFAULT '', catalog_snapshot_json longtext NULL, status varchar(20) NOT NULL DEFAULT 'active',
      created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY owner_product (owner_key,product_key), UNIQUE KEY snapshot_quiz_id (snapshot_quiz_id), KEY tenant_product (tenant_id,product_key), KEY source_quiz_id (source_quiz_id), KEY catalog_post_revision (catalog_post_id,catalog_revision)
    ) {$c};";

    // Append-only revision history for administrator-managed ready games.
    // Restoring never rewrites this history: a restore creates a new revision.
    $sql[] = "CREATE TABLE {$t('ready_game_revisions')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, catalog_post_id bigint unsigned NOT NULL DEFAULT 0, revision_no int unsigned NOT NULL DEFAULT 1,
      snapshot_json longtext NOT NULL, source_quiz_id bigint unsigned NOT NULL DEFAULT 0, source_quiz_revision int unsigned NOT NULL DEFAULT 1,
      reason varchar(64) NOT NULL DEFAULT 'save', created_by_user_id bigint unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY post_revision (catalog_post_id,revision_no), KEY catalog_post_id (catalog_post_id), KEY created_at (created_at)
    ) {$c};";

    // Append-only history of commercially published ready-game releases.
    // A rollback never rewrites an older row: it creates a new release event
    // pointing at the already QA-approved immutable technical release quiz.
    $sql[] = "CREATE TABLE {$t('ready_game_releases')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, catalog_post_id bigint unsigned NOT NULL DEFAULT 0, release_no int unsigned NOT NULL DEFAULT 1, catalog_revision int unsigned NOT NULL DEFAULT 1,
      release_quiz_id bigint unsigned NOT NULL DEFAULT 0, source_quiz_id bigint unsigned NOT NULL DEFAULT 0, source_quiz_revision int unsigned NOT NULL DEFAULT 1, snapshot_json longtext NOT NULL,
      status varchar(20) NOT NULL DEFAULT 'active', action varchar(24) NOT NULL DEFAULT 'publish', source_release_id bigint unsigned NOT NULL DEFAULT 0,
      created_by_user_id bigint unsigned NOT NULL DEFAULT 0, created_at datetime NOT NULL, activated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY post_release (catalog_post_id,release_no), KEY post_status (catalog_post_id,status), KEY release_quiz_id (release_quiz_id), KEY created_at (created_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('scenario_orders')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, tenant_id bigint unsigned NOT NULL DEFAULT 0, user_id bigint unsigned NOT NULL DEFAULT 0,
      audience varchar(190) NOT NULL DEFAULT '', game_format varchar(190) NOT NULL DEFAULT '', theme varchar(190) NOT NULL DEFAULT '', details longtext NULL,
      status varchar(24) NOT NULL DEFAULT 'new', admin_note longtext NULL, ready_game_post_id bigint unsigned NOT NULL DEFAULT 0,
      created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), KEY tenant_user (tenant_id,user_id), KEY status (status), KEY ready_game_post_id (ready_game_post_id), KEY created_at (created_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$t('entitlements')} (
      id bigint unsigned NOT NULL AUTO_INCREMENT, package_id varchar(190) NOT NULL DEFAULT '', title varchar(190) NOT NULL DEFAULT '', format_key varchar(64) NOT NULL DEFAULT 'classic_quiz', package_version varchar(64) NOT NULL DEFAULT '1',
      entitlement varchar(32) NOT NULL DEFAULT 'owned', delivery_mode varchar(32) NOT NULL DEFAULT 'cloud_package', package_url text NULL, expires_at varchar(64) NOT NULL DEFAULT '', metadata_json longtext NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY package_id (package_id), KEY entitlement (entitlement), KEY format_key (format_key)
    ) {$c};";

    // Negotiation Core v1 uses its own append-only session/turn/event/request
    // storage. Existing negotiation_duel_v1 games are not routed here yet.
    $ns = $wpdb->prefix . 'ckm_negotiation_';
    $sql[] = "CREATE TABLE {$ns}sessions (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      tenant_id bigint unsigned NOT NULL DEFAULT 0, game_id bigint unsigned NOT NULL DEFAULT 0,
      session_id varchar(64) NOT NULL DEFAULT '', runtime varchar(64) NOT NULL DEFAULT '',
      status varchar(20) NOT NULL DEFAULT 'created', phase varchar(64) NOT NULL DEFAULT 'idle', turn_number int unsigned NOT NULL DEFAULT 0, state_version bigint unsigned NOT NULL DEFAULT 1,
      host_mode varchar(20) NOT NULL DEFAULT 'ai', ai_generation bigint unsigned NOT NULL DEFAULT 0, voice_generation bigint unsigned NOT NULL DEFAULT 0,
      deadline_at datetime NULL, runtime_state_json longtext NULL, state_json longtext NULL,
      started_at datetime NULL, finished_at datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY uniq_session (session_id), KEY game_session (game_id,session_id), KEY tenant_status (tenant_id,status), KEY runtime_status (runtime,status), KEY active_lookup (tenant_id,game_id,status), KEY updated_at (updated_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$ns}turns (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      tenant_id bigint unsigned NOT NULL DEFAULT 0, session_id varchar(64) NOT NULL DEFAULT '', message_seq bigint unsigned NOT NULL DEFAULT 0, turn_number int unsigned NOT NULL DEFAULT 0,
      actor varchar(24) NOT NULL DEFAULT 'system', message_type varchar(32) NOT NULL DEFAULT 'message', message_text longtext NULL, input_mode varchar(20) NOT NULL DEFAULT 'system',
      phase varchar(64) NOT NULL DEFAULT '', runtime varchar(64) NOT NULL DEFAULT '', analysis_json longtext NULL, evaluation_json longtext NULL, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY session_message_seq (session_id,message_seq), KEY session_turn (session_id,turn_number), KEY session_created (session_id,created_at), KEY session_actor (session_id,actor)
    ) {$c};";

    $sql[] = "CREATE TABLE {$ns}events (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      tenant_id bigint unsigned NOT NULL DEFAULT 0, session_id varchar(64) NOT NULL DEFAULT '', event_seq bigint unsigned NOT NULL DEFAULT 0,
      event_type varchar(64) NOT NULL DEFAULT '', phase varchar(64) NOT NULL DEFAULT '', turn_number int unsigned NOT NULL DEFAULT 0, actor varchar(24) NOT NULL DEFAULT 'system',
      payload_json longtext NULL, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY session_event_seq (session_id,event_seq), KEY session_created (session_id,created_at), KEY session_event_type (session_id,event_type), KEY tenant_created (tenant_id,created_at)
    ) {$c};";

    $sql[] = "CREATE TABLE {$ns}requests (
      id bigint unsigned NOT NULL AUTO_INCREMENT,
      session_id varchar(64) NOT NULL DEFAULT '', client_request_id varchar(96) NOT NULL DEFAULT '', action varchar(64) NOT NULL DEFAULT '',
      state_version_before bigint unsigned NOT NULL DEFAULT 0, state_version_after bigint unsigned NOT NULL DEFAULT 0,
      response_json longtext NULL, created_at datetime NOT NULL,
      PRIMARY KEY (id), UNIQUE KEY session_request (session_id,client_request_id), KEY session_action (session_id,action), KEY created_at (created_at)
    ) {$c};";

    foreach ($sql as $statement) dbDelta($statement);

    // Defensive migration for existing installations where dbDelta did not add
    // the CHGK pause columns because the DB version had already been recorded.
    // Smoke tests rely on these fields to preserve pause/F5/resume state.
    $gamesTable = $t('games');
    $pauseColumns = array(
        'is_paused' => "ALTER TABLE {$gamesTable} ADD COLUMN is_paused tinyint unsigned NOT NULL DEFAULT 0",
        'paused_at' => "ALTER TABLE {$gamesTable} ADD COLUMN paused_at datetime NULL",
        'paused_quiz_phase' => "ALTER TABLE {$gamesTable} ADD COLUMN paused_quiz_phase varchar(24) NOT NULL DEFAULT ''",
        'paused_format_phase' => "ALTER TABLE {$gamesTable} ADD COLUMN paused_format_phase varchar(32) NOT NULL DEFAULT ''",
        'paused_remaining_seconds' => "ALTER TABLE {$gamesTable} ADD COLUMN paused_remaining_seconds int unsigned NOT NULL DEFAULT 0",
    );
    foreach ($pauseColumns as $column => $alterSql) {
        $exists = (string)$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$gamesTable}` LIKE %s", $column));
        if ($exists === '') $wpdb->query($alterSql);
    }

    update_option('ckm_quiz_pro_db_version', CKM_QUIZ_PRO_DB_VERSION, false);
    return true;
}

function ckm_quiz_pro_seed_demo_quiz(): void {
    global $wpdb;
    $qz = ckm_quiz_pro_table('quizzes');
    $qq = ckm_quiz_pro_table('questions');
    $rr = ckm_quiz_pro_table('rounds');
    $now = current_time('mysql');

    // Idempotent repair: previous 0.3.1 skipped the demo whenever ANY quiz
    // already existed. The demo is now keyed only by its own stable slug.
    $quiz = $wpdb->get_row("SELECT * FROM {$qz} WHERE slug='demo-classic-quiz' LIMIT 1", ARRAY_A);
    if (!$quiz) {
        $wpdb->insert($qz,[
            'title'=>'Классический квиз','slug'=>'demo-classic-quiz','format_key'=>'classic_quiz','short_description'=>'Готовый тестовый квиз из 5 вопросов.','instructions'=>'Откройте ссылки команд на разных устройствах или в разных профилях браузера.',
            'status'=>'published','current_revision'=>1,'min_teams'=>2,'max_teams'=>10,'host_mode'=>'ai','judge_mode'=>'ai','seconds_per_question'=>20,'scoring_policy_json'=>'{}','settings_json'=>'{}','format_settings_json'=>'{}',
            'created_by_user_id'=>get_current_user_id(),'updated_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'published_at'=>$now
        ]);
        $quizId=(int)$wpdb->insert_id;
        if ($quizId <= 0) return;
        $revision=1;
    } else {
        $quizId=(int)$quiz['id'];
        $revision=max(1,(int)$quiz['current_revision']);
        if ((string)($quiz['title'] ?? '') !== 'Классический квиз') {
            $wpdb->update($qz,['title'=>'Классический квиз','updated_at'=>$now],['id'=>$quizId]);
        }
    }

    $roundId=(int)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$rr} WHERE quiz_id=%d AND quiz_revision=%d AND round_key='round-1' LIMIT 1",
        $quizId,$revision
    ));
    if ($roundId <= 0) {
        $wpdb->insert($rr,['quiz_id'=>$quizId,'quiz_revision'=>$revision,'round_key'=>'round-1','position'=>1,'title'=>'Основной раунд','round_type'=>'questions','rules_json'=>'{}','settings_json'=>'{}','status'=>'active','created_at'=>$now,'updated_at'=>$now]);
        $roundId=(int)$wpdb->insert_id;
    }

    $items=[
        ['Какова столица Австралии?',['A'=>'Сидней','B'=>'Канберра','C'=>'Мельбурн','D'=>'Перт'],'B','Канберра — столица Австралии.'],
        ['Чему равно 2 + 3 × 4?',['A'=>'20','B'=>'14','C'=>'24','D'=>'11'],'B','Сначала выполняется умножение: 3×4=12, затем 2+12=14.'],
        ['Какова химическая формула воды?',['A'=>'CO₂','B'=>'O₂','C'=>'H₂O','D'=>'NaCl'],'C','Молекула воды состоит из двух атомов водорода и одного атома кислорода: H₂O.'],
        ['Как называется естественный спутник Земли?',['A'=>'Луна','B'=>'Марс','C'=>'Венера','D'=>'Титан'],'A','Естественный спутник Земли — Луна.'],
        ['В каком году состоялся первый полёт человека в космос?',['A'=>'1957','B'=>'1961','C'=>'1969','D'=>'1975'],'B','Юрий Гагарин совершил первый полёт человека в космос 12 апреля 1961 года.'],
    ];
    foreach($items as $i=>$it){
        $key='q-'.($i+1);
        $exists=(int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$qq} WHERE quiz_id=%d AND quiz_revision=%d AND question_key=%s LIMIT 1",
            $quizId,$revision,$key
        ));
        if ($exists>0) continue;
        $opts=[]; foreach($it[1] as $v=>$label) $opts[]=['value'=>$v,'label'=>$label];
        $wpdb->insert($qq,[
            'quiz_id'=>$quizId,'quiz_revision'=>$revision,'question_key'=>$key,'position'=>$i+1,'round_no'=>1,'round_title'=>'Основной раунд','round_id'=>$roundId,'question_stage'=>'main',
            'question_type'=>'single_choice','question_text'=>$it[0],'options_json'=>wp_json_encode($opts,JSON_UNESCAPED_UNICODE),'correct_answers_json'=>wp_json_encode([$it[2]],JSON_UNESCAPED_UNICODE),'numeric_tolerance'=>0,
            'points'=>1,'time_limit_seconds'=>20,'scoring_rule_json'=>'{}','explanation'=>$it[3],'host_script'=>'','media_url'=>'','media_type'=>'','media_start_seconds'=>0,'media_end_seconds'=>0,
            'status'=>'active','created_by_user_id'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now
        ]);
    }
}

function ckm_quiz_pro_create_pages(): void {
    $defs=[
        'play'=>['Игрок','ckm-quiz-pro-play'],
        'host'=>['Ведущий','ckm-quiz-pro-host'],
        'scoreboard'=>['Табло','ckm-quiz-pro-scoreboard'],
    ];
    foreach($defs as $key=>$d){
        $id=(int)get_option('ckm_quiz_pro_page_'.$key,0);
        if($id>0 && get_post($id)) continue;
        $existing=get_page_by_path($d[1]);
        if($existing){ update_option('ckm_quiz_pro_page_'.$key,(int)$existing->ID,false); continue; }
        $id=wp_insert_post(['post_title'=>$d[0],'post_name'=>$d[1],'post_status'=>'publish','post_type'=>'page','post_content'=>'']);
        if(!is_wp_error($id)) update_option('ckm_quiz_pro_page_'.$key,(int)$id,false);
    }
}

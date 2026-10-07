<?php
if (!defined('ABSPATH')) exit;

/**
 * Defensive storage verifier for Negotiation Core v1.
 *
 * WordPress dbDelta is intentionally still the primary migration mechanism,
 * but older/incomplete installs may already have the target DB version stored
 * while one of the new tables/columns is missing. This guard verifies the
 * actual database shape before the test runtime writes anything and repairs
 * only the isolated ckm_negotiation_* storage when necessary.
 */

const CKM_NEGOTIATION_STORAGE_GUARD_VERSION = 'negotiation_storage_guard_v1';

function ckm_negotiation_storage_spec(): array {
    return [
        'sessions' => [
            'columns' => [
                'id','tenant_id','game_id','session_id','runtime','status','phase','turn_number','state_version',
                'host_mode','ai_generation','voice_generation','deadline_at','runtime_state_json','state_json',
                'started_at','finished_at','created_at','updated_at',
            ],
            'indexes' => ['PRIMARY','uniq_session','game_session','tenant_status','runtime_status','active_lookup','updated_at'],
        ],
        'turns' => [
            'columns' => [
                'id','tenant_id','session_id','message_seq','turn_number','actor','message_type','message_text','input_mode',
                'phase','runtime','analysis_json','evaluation_json','created_at',
            ],
            'indexes' => ['PRIMARY','session_message_seq','session_turn','session_created','session_actor'],
        ],
        'events' => [
            'columns' => ['id','tenant_id','session_id','event_seq','event_type','phase','turn_number','actor','payload_json','created_at'],
            'indexes' => ['PRIMARY','session_event_seq','session_created','session_event_type','tenant_created'],
        ],
        'requests' => [
            'columns' => ['id','session_id','client_request_id','action','state_version_before','state_version_after','response_json','created_at'],
            'indexes' => ['PRIMARY','session_request','session_action','created_at'],
        ],
    ];
}

function ckm_negotiation_storage_health(): array {
    global $wpdb;
    $missingTables = [];
    $missingColumns = [];
    $missingIndexes = [];

    foreach (ckm_negotiation_storage_spec() as $logical => $spec) {
        $table = ckm_negotiation_core_table($logical);
        if ($table === '') {
            $missingTables[] = $logical;
            continue;
        }
        $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($table) : addcslashes($table, '_%');
        $found = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($found === '') {
            $missingTables[] = $logical;
            continue;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $columnSet = array_fill_keys(array_map('strval', is_array($columns) ? $columns : []), true);
        foreach ((array)$spec['columns'] as $column) {
            if (!isset($columnSet[$column])) $missingColumns[$logical][] = $column;
        }

        $indexRows = $wpdb->get_results("SHOW INDEX FROM `{$table}`", defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        $indexSet = [];
        foreach ((array)$indexRows as $row) {
            if (is_array($row) && isset($row['Key_name'])) $indexSet[(string)$row['Key_name']] = true;
        }
        foreach ((array)$spec['indexes'] as $index) {
            if (!isset($indexSet[$index])) $missingIndexes[$logical][] = $index;
        }
    }

    return [
        'ok' => !$missingTables && !$missingColumns && !$missingIndexes,
        'missing_tables' => $missingTables,
        'missing_columns' => $missingColumns,
        'missing_indexes' => $missingIndexes,
        'db_error' => trim((string)($wpdb->last_error ?? '')),
    ];
}

function ckm_negotiation_storage_create_missing_tables(array $missingTables): void {
    global $wpdb;
    if (!$missingTables) return;
    $c = $wpdb->get_charset_collate();
    $ns = $wpdb->prefix . 'ckm_negotiation_';
    $sql = [];

    if (in_array('sessions', $missingTables, true)) {
        $sql[] = "CREATE TABLE {$ns}sessions (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          tenant_id bigint unsigned NOT NULL DEFAULT 0, game_id bigint unsigned NOT NULL DEFAULT 0,
          session_id varchar(64) NOT NULL DEFAULT '', runtime varchar(64) NOT NULL DEFAULT '',
          status varchar(20) NOT NULL DEFAULT 'created', phase varchar(64) NOT NULL DEFAULT 'idle', turn_number int unsigned NOT NULL DEFAULT 0, state_version bigint unsigned NOT NULL DEFAULT 1,
          host_mode varchar(20) NOT NULL DEFAULT 'ai', ai_generation bigint unsigned NOT NULL DEFAULT 0, voice_generation bigint unsigned NOT NULL DEFAULT 0,
          deadline_at datetime NULL, runtime_state_json longtext NULL, state_json longtext NULL,
          started_at datetime NULL, finished_at datetime NULL, created_at datetime NOT NULL, updated_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY uniq_session (session_id), KEY game_session (game_id,session_id), KEY tenant_status (tenant_id,status), KEY runtime_status (runtime,status), KEY active_lookup (tenant_id,game_id,status), KEY updated_at (updated_at)
        ) {$c}";
    }
    if (in_array('turns', $missingTables, true)) {
        $sql[] = "CREATE TABLE {$ns}turns (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          tenant_id bigint unsigned NOT NULL DEFAULT 0, session_id varchar(64) NOT NULL DEFAULT '', message_seq bigint unsigned NOT NULL DEFAULT 0, turn_number int unsigned NOT NULL DEFAULT 0,
          actor varchar(24) NOT NULL DEFAULT 'system', message_type varchar(32) NOT NULL DEFAULT 'message', message_text longtext NULL, input_mode varchar(20) NOT NULL DEFAULT 'system',
          phase varchar(64) NOT NULL DEFAULT '', runtime varchar(64) NOT NULL DEFAULT '', analysis_json longtext NULL, evaluation_json longtext NULL, created_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY session_message_seq (session_id,message_seq), KEY session_turn (session_id,turn_number), KEY session_created (session_id,created_at), KEY session_actor (session_id,actor)
        ) {$c}";
    }
    if (in_array('events', $missingTables, true)) {
        $sql[] = "CREATE TABLE {$ns}events (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          tenant_id bigint unsigned NOT NULL DEFAULT 0, session_id varchar(64) NOT NULL DEFAULT '', event_seq bigint unsigned NOT NULL DEFAULT 0,
          event_type varchar(64) NOT NULL DEFAULT '', phase varchar(64) NOT NULL DEFAULT '', turn_number int unsigned NOT NULL DEFAULT 0, actor varchar(24) NOT NULL DEFAULT 'system',
          payload_json longtext NULL, created_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY session_event_seq (session_id,event_seq), KEY session_created (session_id,created_at), KEY session_event_type (session_id,event_type), KEY tenant_created (tenant_id,created_at)
        ) {$c}";
    }
    if (in_array('requests', $missingTables, true)) {
        $sql[] = "CREATE TABLE {$ns}requests (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          session_id varchar(64) NOT NULL DEFAULT '', client_request_id varchar(96) NOT NULL DEFAULT '', action varchar(64) NOT NULL DEFAULT '',
          state_version_before bigint unsigned NOT NULL DEFAULT 0, state_version_after bigint unsigned NOT NULL DEFAULT 0,
          response_json longtext NULL, created_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY session_request (session_id,client_request_id), KEY session_action (session_id,action), KEY created_at (created_at)
        ) {$c}";
    }
    foreach ($sql as $statement) $wpdb->query($statement);
}

function ckm_negotiation_storage_repair_columns(array $missingColumns): void {
    global $wpdb;
    $defs = [
        'sessions' => [
            'runtime_state_json' => 'longtext NULL',
            'state_json' => 'longtext NULL',
            'state_version' => 'bigint unsigned NOT NULL DEFAULT 1',
            'ai_generation' => 'bigint unsigned NOT NULL DEFAULT 0',
            'voice_generation' => 'bigint unsigned NOT NULL DEFAULT 0',
        ],
        'turns' => [
            'message_seq' => 'bigint unsigned NOT NULL DEFAULT 0',
            'analysis_json' => 'longtext NULL',
            'evaluation_json' => 'longtext NULL',
        ],
    ];
    foreach ($missingColumns as $logical => $columns) {
        $table = ckm_negotiation_core_table((string)$logical);
        foreach ((array)$columns as $column) {
            if (!isset($defs[$logical][$column])) continue;
            $safeColumn = preg_replace('/[^a-z0-9_]/i', '', (string)$column);
            if ($safeColumn === '') continue;
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$safeColumn}` {$defs[$logical][$column]}");
        }
    }
}

function ckm_negotiation_storage_repair_indexes(array $missingIndexes): void {
    global $wpdb;
    $defs = [
        'sessions' => [
            'uniq_session' => 'UNIQUE KEY uniq_session (session_id)',
            'game_session' => 'KEY game_session (game_id,session_id)',
            'tenant_status' => 'KEY tenant_status (tenant_id,status)',
            'runtime_status' => 'KEY runtime_status (runtime,status)',
            'active_lookup' => 'KEY active_lookup (tenant_id,game_id,status)',
            'updated_at' => 'KEY updated_at (updated_at)',
        ],
        'turns' => [
            'session_message_seq' => 'UNIQUE KEY session_message_seq (session_id,message_seq)',
            'session_turn' => 'KEY session_turn (session_id,turn_number)',
            'session_created' => 'KEY session_created (session_id,created_at)',
            'session_actor' => 'KEY session_actor (session_id,actor)',
        ],
        'events' => [
            'session_event_seq' => 'UNIQUE KEY session_event_seq (session_id,event_seq)',
            'session_created' => 'KEY session_created (session_id,created_at)',
            'session_event_type' => 'KEY session_event_type (session_id,event_type)',
            'tenant_created' => 'KEY tenant_created (tenant_id,created_at)',
        ],
        'requests' => [
            'session_request' => 'UNIQUE KEY session_request (session_id,client_request_id)',
            'session_action' => 'KEY session_action (session_id,action)',
            'created_at' => 'KEY created_at (created_at)',
        ],
    ];
    foreach ($missingIndexes as $logical => $indexes) {
        $table = ckm_negotiation_core_table((string)$logical);
        foreach ((array)$indexes as $index) {
            if ($index === 'PRIMARY' || !isset($defs[$logical][$index])) continue;
            $wpdb->query("ALTER TABLE `{$table}` ADD {$defs[$logical][$index]}");
        }
    }
}

function ckm_negotiation_storage_ensure(): array {
    static $cached = null;
    if (is_array($cached) && !empty($cached['ok'])) return $cached;

    $health = ckm_negotiation_storage_health();
    if (!empty($health['ok'])) return $cached = $health;

    // First retry the canonical plugin migration. This is enough for the most
    // common case: the DB version option was stale or activation was skipped.
    if (function_exists('ckm_quiz_pro_install_schema')) ckm_quiz_pro_install_schema();
    $health = ckm_negotiation_storage_health();
    if (!empty($health['ok'])) return $cached = $health;

    // Defensive fallback only for isolated Negotiation Core storage.
    ckm_negotiation_storage_create_missing_tables((array)($health['missing_tables'] ?? []));
    $health = ckm_negotiation_storage_health();
    ckm_negotiation_storage_repair_columns((array)($health['missing_columns'] ?? []));
    $health = ckm_negotiation_storage_health();
    ckm_negotiation_storage_repair_indexes((array)($health['missing_indexes'] ?? []));
    $health = ckm_negotiation_storage_health();

    return $cached = $health;
}

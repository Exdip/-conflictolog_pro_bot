<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** Player-safe product projection. Hidden scenario data is never selected here. */
final class ProductCatalog {
    private static ?array $metaCache = null;

    public static function url(array $args = []): string {
        $pageId = (int) get_option('ckm_neg_session_page_id', 0);
        $base = $pageId > 0 && get_post($pageId) ? (string) get_permalink($pageId) : home_url('/ckm-negotiation-master/');
        if (function_exists('ckmqp_tenant_link')) { $base = ckmqp_tenant_link($base, ckmqp_scope_id()); }
        return $args ? add_query_arg($args, $base) : $base;
    }

    public static function meta(string $slug): array {
        if (self::$metaCache === null) {
            $loaded = require dirname(__DIR__) . '/content/public-product-meta.php';
            self::$metaCache = is_array($loaded) ? $loaded : [];
        }
        return self::$metaCache[$slug] ?? [
            'category'=>'Переговоры','description'=>'Свободные переговоры с ИИ-оппонентом и итоговым разбором.','difficulty'=>'Средний','duration'=>'15–25 мин',
        ];
    }

    private static function decorate(array $rows): array {
        global $wpdb;
        $context = Access::context();
        $sessions = Schema::table('sessions');
        $evaluations = Schema::table('evaluations');
        foreach ($rows as &$row) {
            $row['meta'] = self::meta((string)$row['slug']);
            $row['active_session'] = null; $row['latest_completed'] = null; $row['attempt_count'] = 0;
            if ((string)($row['status'] ?? 'published') !== 'published' || empty($row['current_version_id'])) { continue; }
            $scenarioId = (int)$row['id'];
            $row['active_session'] = $wpdb->get_row($wpdb->prepare(
                "SELECT id,status,mode,difficulty,last_activity_at FROM `$sessions` WHERE tenant_id=%d AND participant_key=%s AND scenario_id=%d AND session_kind='player' AND status IN ('in_progress','paused') ORDER BY id DESC LIMIT 1",
                (int)$context['tenant_id'], (string)$context['participant_key'], $scenarioId
            ), ARRAY_A) ?: null;
            $row['latest_completed'] = $wpdb->get_row($wpdb->prepare(
                "SELECT se.id,se.status,se.mode,se.difficulty,se.started_at,se.completed_at,se.last_activity_at,ev.final_score,ev.result_type,ev.status AS evaluation_status FROM `$sessions` se LEFT JOIN `$evaluations` ev ON ev.session_id=se.id WHERE se.tenant_id=%d AND se.participant_key=%s AND se.scenario_id=%d AND se.session_kind='player' AND se.status LIKE 'completed_%%' ORDER BY COALESCE(se.completed_at,se.last_activity_at) DESC,se.id DESC LIMIT 1",
                (int)$context['tenant_id'], (string)$context['participant_key'], $scenarioId
            ), ARRAY_A) ?: null;
            $row['attempt_count'] = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$sessions` WHERE tenant_id=%d AND participant_key=%s AND scenario_id=%d AND session_kind='player'",
                (int)$context['tenant_id'], (string)$context['participant_key'], $scenarioId
            ));
        }
        unset($row);
        return $rows;
    }

    /** Published system scenarios, optionally within one library. */
    public static function cards(?int $libraryId = null): array {
        global $wpdb;
        Access::context();
        $scenarios = Schema::table('scenarios'); $versions = Schema::table('scenario_versions');
        $where = "s.tenant_id IS NULL AND s.status='published' AND v.status='published'";
        $sql = "SELECT s.id,s.library_id,s.slug,s.title,s.status,s.current_version_id,v.player_situation,v.opponent_name,v.opponent_role FROM `$scenarios` s INNER JOIN `$versions` v ON v.id=s.current_version_id AND v.scenario_id=s.id WHERE $where";
        if ($libraryId !== null) { $sql .= $wpdb->prepare(' AND s.library_id=%d', $libraryId); }
        $sql .= ' ORDER BY s.id ASC';
        return self::decorate((array)$wpdb->get_results($sql, ARRAY_A));
    }

    /** Published tenant scenarios created through the frontend builder. */
    public static function ownCards(): array {
        global $wpdb;
        $context = Access::context();
        $scenarios = Schema::table('scenarios'); $versions = Schema::table('scenario_versions');
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.library_id,s.slug,s.title,s.status,s.current_version_id,v.player_situation,v.opponent_name,v.opponent_role FROM `$scenarios` s INNER JOIN `$versions` v ON v.id=s.current_version_id AND v.scenario_id=s.id WHERE s.tenant_id=%d AND s.status='published' AND v.status='published' ORDER BY s.updated_at DESC,s.id DESC",
            (int)$context['tenant_id']
        ), ARRAY_A);
        return self::decorate($rows);
    }

    public static function libraryBySlug(string $slug): ?array {
        global $wpdb;
        Access::context();
        if (!preg_match('/^[a-z0-9-]{1,191}$/D', $slug)) { throw new \InvalidArgumentException('Invalid library slug.'); }
        $table = Schema::table('libraries');
        $rows = (array)$wpdb->get_results($wpdb->prepare("SELECT id,slug,title,description,status,visibility,sort_order,access_type,product_key FROM `$table` WHERE tenant_id IS NULL AND slug=%s LIMIT 2", $slug), ARRAY_A);
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous library.'); }
        if (!$rows) { return null; }
        $context = Access::context();
        if ((string)($rows[0]['visibility'] ?? 'public') === 'private' && empty($context['admin'])) { return null; }
        $rows[0]['access'] = (new LibraryAccessService())->state($rows[0]);
        return $rows[0];
    }

    /** Public library cards. Draft public libraries are visible as «Скоро». */
    public static function libraries(): array {
        global $wpdb;
        Access::context();
        $table = Schema::table('libraries'); $scenarios = Schema::table('scenarios'); $sessions = Schema::table('sessions');
        $rows = (array)$wpdb->get_results("SELECT id,slug,title,description,status,visibility,sort_order,access_type,product_key FROM `$table` WHERE tenant_id IS NULL AND visibility='public' AND status IN ('published','draft') AND slug<>'basic' ORDER BY sort_order ASC,id ASC", ARRAY_A);
        $access = new LibraryAccessService(); $context = Access::context();
        foreach ($rows as &$row) {
            $row['access'] = $access->state($row);
            $row['scenario_count'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$scenarios` WHERE tenant_id IS NULL AND library_id=%d AND status IN ('published','draft')", (int)$row['id']));
            $row['playable_count'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$scenarios` WHERE tenant_id IS NULL AND library_id=%d AND status='published' AND current_version_id IS NOT NULL", (int)$row['id']));
            $row['completed_count'] = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT se.scenario_id) FROM `$sessions` se INNER JOIN `$scenarios` sc ON sc.id=se.scenario_id WHERE sc.library_id=%d AND se.tenant_id=%d AND se.participant_key=%s AND se.session_kind='player' AND se.status LIKE 'completed_%%'", (int)$row['id'], (int)$context['tenant_id'], (string)$context['participant_key']));
        }
        unset($row);
        return $rows;
    }

    /** All public cards inside a library; draft rows are player-safe «Скоро» placeholders. */
    public static function libraryCards(int $libraryId): array {
        global $wpdb;
        Access::context();
        $scenarios = Schema::table('scenarios'); $versions = Schema::table('scenario_versions');
        $rows = (array)$wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.library_id,s.slug,s.title,s.status,s.current_version_id,v.player_situation,v.opponent_name,v.opponent_role FROM `$scenarios` s LEFT JOIN `$versions` v ON v.id=s.current_version_id AND v.scenario_id=s.id WHERE s.tenant_id IS NULL AND s.library_id=%d AND s.status IN ('published','draft') ORDER BY s.id ASC",
            $libraryId
        ), ARRAY_A);
        return self::decorate($rows);
    }

    public static function libraryForScenario(array $scenario): ?array {
        $libraryId = (int)($scenario['library_id'] ?? 0);
        if ($libraryId <= 0) { return null; }
        global $wpdb;
        $table = Schema::table('libraries');
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,slug,title,description,status,visibility,sort_order,access_type,product_key FROM `$table` WHERE id=%d", $libraryId), ARRAY_A);
        if (!$row) { return null; }
        $row['access'] = (new LibraryAccessService())->state($row);
        return $row;
    }

    /** Player-safe attempt list; no state_json, hidden facts, rules or opponent limits. */
    public static function attempts(int $scenarioId, int $limit = 8): array {
        global $wpdb;
        $context = Access::context(); Access::scenario($scenarioId);
        $sessions = Schema::table('sessions'); $evaluations = Schema::table('evaluations');
        $limit = max(1, min(30, $limit));
        return (array)$wpdb->get_results($wpdb->prepare(
            "SELECT se.id,se.mode,se.difficulty,se.status,se.started_at,se.last_activity_at,se.completed_at,ev.final_score,ev.result_type,ev.status AS evaluation_status FROM `$sessions` se LEFT JOIN `$evaluations` ev ON ev.session_id=se.id WHERE se.tenant_id=%d AND se.participant_key=%s AND se.scenario_id=%d AND se.session_kind='player' ORDER BY se.id DESC LIMIT %d",
            (int)$context['tenant_id'], (string)$context['participant_key'], $scenarioId, $limit
        ), ARRAY_A);
    }

    public static function requestedSession(int $sessionId, int $scenarioId): ?array {
        if ($sessionId < 1) { return null; }
        $row = Access::session($sessionId);
        if (($row['session_kind'] ?? 'player') !== 'player') { return null; }
        return (int)$row['scenario_id'] === $scenarioId ? $row : null;
    }
}

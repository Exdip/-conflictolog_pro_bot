<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** Administrative summaries read only from the database; never expose secrets. */
final class ScenarioDiagnostics {
    public static function response() {
        global $wpdb;
        $permission = Health::permission();
        if (is_wp_error($permission)) { return $permission; }
        $previous = $wpdb->suppress_errors(true);
        try {
            $table = Schema::table('scenarios');
            $versions = Schema::table('scenario_versions');
            $scenarios = $wpdb->get_results("SELECT id,library_id,title,status,current_version_id FROM `$table` WHERE tenant_id IS NULL AND status='published' ORDER BY id ASC", ARRAY_A);
            if (!is_array($scenarios) || $wpdb->last_error) { throw new \RuntimeException('Scenario read failed.'); }
            $summaries = []; $ok = count($scenarios) > 0;
            foreach ($scenarios as $scenario) {
                $version = $wpdb->get_row($wpdb->prepare("SELECT id,scenario_id,version_number,status,mechanics_json FROM `$versions` WHERE id=%d", (int) $scenario['current_version_id']), ARRAY_A);
                $valid = $version && (int) $version['scenario_id'] === (int) $scenario['id'] && $version['status'] === 'published' && $scenario['status'] === 'published';
                $summary = ['scenario' => $scenario['title'], 'version' => $valid ? (int) $version['version_number'] : null, 'status' => $valid ? $version['status'] : 'invalid', 'items' => 0, 'hidden_facts' => 0, 'rules' => 0, 'evaluation_criteria' => 0, 'evaluation_weight' => 0, 'current_version' => $valid ? 'valid' : 'invalid'];
                if ($valid) {
                    foreach (['items'=>'items','hidden_facts'=>'hidden_facts','rules'=>'rules','evaluation_rules'=>'evaluation_criteria'] as $key => $field) {
                        $componentTable = Schema::table($key);
                        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$componentTable` WHERE scenario_version_id=%d", (int) $version['id']));
                        if ($count === null || $wpdb->last_error) { throw new \RuntimeException('Component read failed.'); }
                        $summary[$field] = (int) $count;
                    }
                    $criteria = Schema::table('evaluation_rules');
                    $summary['evaluation_weight'] = (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(weight),0) FROM `$criteria` WHERE scenario_version_id=%d", (int) $version['id']));
                    if ($wpdb->last_error) { throw new \RuntimeException('Weight read failed.'); }
                    $manifest = json_decode((string) $version['mechanics_json'], true, 512, JSON_THROW_ON_ERROR)['content_manifest'] ?? [];
                    foreach (['items'=>'items','hidden_facts'=>'hidden_facts','rules'=>'rules','evaluation_rules'=>'evaluation_criteria','evaluation_weight'=>'evaluation_weight'] as $expected => $field) {
                        if (!isset($manifest[$expected]) || (float) $manifest[$expected] !== (float) $summary[$field]) { $ok = false; }
                    }
                    if ($summary['evaluation_weight'] !== 100.0) { $ok = false; }
                } else { $ok = false; }
                $summaries[] = $summary;
            }
            $librariesTable = Schema::table('libraries');
            $libraryRows = (array)$wpdb->get_results("SELECT id,slug,title,status,access_type,product_key FROM `$librariesTable` WHERE tenant_id IS NULL ORDER BY sort_order ASC,id ASC", ARRAY_A);
            $librarySummaries = [];
            $expectedLibraryCounts = ['basic'=>3, 'conflict-negotiation'=>10, 'price-question'=>10, 'business-talk'=>10, 'intersection-of-interests'=>10, 'share-of-influence'=>10];
            foreach ($libraryRows as $library) {
                $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id IS NULL AND library_id=%d AND status IN ('published','draft')", (int)$library['id']));
                $published = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id IS NULL AND library_id=%d AND status='published' AND current_version_id IS NOT NULL", (int)$library['id']));
                $draft = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id IS NULL AND library_id=%d AND status='draft'", (int)$library['id']));
                $invalidPublished = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table` WHERE tenant_id IS NULL AND library_id=%d AND status='published' AND current_version_id IS NULL", (int)$library['id']));
                $expected = $expectedLibraryCounts[(string)$library['slug']] ?? null;
                $complete = $total > 0 && $published === $total && $draft === 0 && $invalidPublished === 0 && ($expected === null || $total === $expected);
                if ($expected !== null && !$complete) { $ok = false; }
                $librarySummaries[] = ['slug'=>$library['slug'],'title'=>$library['title'],'status'=>$library['status'],'access_type'=>$library['access_type'],'product_key'=>$library['product_key'],'scenarios'=>$total,'expected_scenarios'=>$expected,'published_scenarios'=>$published,'draft_scenarios'=>$draft,'invalid_published_scenarios'=>$invalidPublished,'complete'=>$complete];
            }
            if (count($librarySummaries) < 2) { $ok = false; }
            $ok = $ok && get_option('ckm_neg_content_version', '') === CKM_NEG_CONTENT_VERSION;
            return new \WP_REST_Response(['status' => $ok ? 'ok' : 'degraded', 'content_version' => (string) get_option('ckm_neg_content_version', ''), 'required_content_version' => CKM_NEG_CONTENT_VERSION, 'migration_status' => (string) get_option('ckm_neg_content_migration_status', 'not_started'), 'libraries'=>$librarySummaries, 'scenarios' => $summaries], $ok ? 200 : 503, ['Cache-Control'=>'no-store, private']);
        } catch (\Throwable $error) {
            return new \WP_REST_Response(['status'=>'degraded','scenarios'=>[]], 503, ['Cache-Control'=>'no-store, private']);
        } finally { $wpdb->suppress_errors($previous); }
    }
    public static function register(): void {
        register_rest_route('ckm/v1', '/negotiation/scenarios/health', ['methods'=>'GET','callback'=>[self::class,'response'],'permission_callback'=>[Health::class,'permission']]);
    }
}

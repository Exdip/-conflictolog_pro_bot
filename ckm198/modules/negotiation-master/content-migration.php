<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/** Data-only importer; no scenario slug, rule, or negotiation runtime branches. */
final class ContentMigration extends Repository {
    private const COMPONENTS = ['items', 'hidden_facts', 'rules', 'evaluation_rules'];

    private static function execute(string $sql): void {
        global $wpdb;
        if ($wpdb->query($sql) === false) { throw new \RuntimeException('Content query failed.'); }
    }

    private static function validate(array $pack): void {
        if (($pack['pack_version'] ?? '') !== CKM_NEG_CONTENT_VERSION || empty($pack['libraries']) || empty($pack['scenarios'])) {
            throw new \RuntimeException('Invalid content pack.');
        }
        $librarySlugs = [];
        foreach ($pack['libraries'] as $library) {
            $slug = (string)($library['slug'] ?? '');
            if (!preg_match('/^[a-z0-9-]{1,191}$/D', $slug) || isset($librarySlugs[$slug])) { throw new \RuntimeException('Invalid library identity.'); }
            if (!in_array((string)($library['status'] ?? ''), ['draft','published','archived'], true)) { throw new \RuntimeException('Invalid library status.'); }
            if (!in_array((string)($library['visibility'] ?? ''), ['public','private','unlisted'], true)) { throw new \RuntimeException('Invalid library visibility.'); }
            if (!in_array((string)($library['access_type'] ?? ''), ['free','paid','licensed'], true)) { throw new \RuntimeException('Invalid library access type.'); }
            $productKey = (string)($library['product_key'] ?? '');
            if ($productKey !== '' && !preg_match('/^[a-z0-9_\-]{1,191}$/D', $productKey)) { throw new \RuntimeException('Invalid library product key.'); }
            $librarySlugs[$slug] = true;
        }

        $scenarioSlugs = [];
        foreach ((array)($pack['scenario_stubs'] ?? []) as $stub) {
            $slug = (string)($stub['slug'] ?? '');
            $library = (string)($stub['library_slug'] ?? '');
            if (!preg_match('/^[a-z0-9-]{1,191}$/D', $slug) || isset($scenarioSlugs[$slug]) || !isset($librarySlugs[$library]) || (string)($stub['status'] ?? '') !== 'draft') {
                throw new \RuntimeException('Invalid scenario stub.');
            }
            $scenarioSlugs[$slug] = true;
        }

        foreach ($pack['scenarios'] as $entry) {
            $slug = $entry['scenario']['slug'] ?? '';
            $library = (string)($entry['scenario']['library_slug'] ?? '');
            if (!preg_match('/^[a-z0-9-]{1,191}$/D', $slug) || isset($scenarioSlugs[$slug]) || !isset($librarySlugs[$library]) || ($entry['version']['version_number'] ?? 0) < 1 || ($entry['version']['status'] ?? '') !== 'published') {
                throw new \RuntimeException('Invalid content identity.');
            }
            $scenarioSlugs[$slug] = true;
            foreach (self::COMPONENTS as $key) {
                $rows = $entry['components'][$key] ?? [];
                $codes = array_column($rows, 'code');
                if (count($rows) !== ($entry['expected'][$key] ?? -1) || count(array_unique($codes)) !== count($rows) || count($codes) !== count($rows)) {
                    throw new \RuntimeException('Invalid component manifest.');
                }
                foreach ($codes as $code) {
                    if (!is_string($code) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $code)) { throw new \RuntimeException('Invalid component code.'); }
                }
            }
            $weight = array_sum(array_column($entry['components']['evaluation_rules'], 'weight'));
            if ((float)$weight !== 100.0 || (float)($entry['expected']['evaluation_weight'] ?? 0) !== 100.0) { throw new \RuntimeException('Invalid evaluation weights.'); }
        }
    }

    /** Existing published rows are immutable: verify, never overwrite silently. */
    private static function matches(array $row, array $expected): bool {
        foreach ($expected as $field => $value) {
            if (!array_key_exists($field, $row)) { return false; }
            if (substr($field, -5) === '_json' && $value !== null) {
                if (json_decode((string) $row[$field], true, 512, JSON_THROW_ON_ERROR) != $value) { return false; }
            } elseif ($value === null) {
                if ($row[$field] !== null) { return false; }
            } elseif (is_int($value) || is_float($value)) {
                if (!is_numeric($row[$field]) || (float) $row[$field] !== (float) $value) { return false; }
            } elseif ((string) $row[$field] !== (string) $value) { return false; }
        }
        return true;
    }

    private static function importLibraries(array $libraries): array {
        global $wpdb;
        $table = Schema::table('libraries');
        $ids = [];
        foreach ($libraries as $library) {
            $data = $library + ['tenant_id'=>null, 'created_by'=>0];
            $rows = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id IS NULL AND slug=%s", $data['slug']), ARRAY_A);
            if ($wpdb->last_error !== '' || count($rows) > 1) { throw new \RuntimeException('Library query failed.'); }
            $now = current_time('mysql', true);
            if (!$rows) {
                $id = self::insert('libraries', $data + ['created_at'=>$now,'updated_at'=>$now]);
            } else {
                $row = $rows[0];
                $promoting = (string)($row['status'] ?? '') === 'draft' && (string)($data['status'] ?? '') === 'published';
                $expected = $data;
                unset($expected['status'], $expected['description']);
                if (!self::matches($row, $expected) || (!$promoting && (string)($row['status'] ?? '') !== (string)($data['status'] ?? ''))) { throw new \RuntimeException('System library conflict.'); }
                if ($promoting) {
                    self::execute($wpdb->prepare("UPDATE `$table` SET status='published',description=%s,updated_at=%s WHERE id=%d AND status='draft'", (string)$data['description'], $now, (int)$row['id']));
                } elseif ((string)($row['description'] ?? '') !== (string)($data['description'] ?? '')) {
                    throw new \RuntimeException('System library conflict.');
                }
                $id = (int)$row['id'];
            }
            $ids[(string)$data['slug']] = $id;
        }
        return $ids;
    }

    private static function importStub(array $stub, array $libraries): void {
        global $wpdb;
        $table = Schema::table('scenarios');
        $librarySlug = (string)$stub['library_slug'];
        $data = $stub;
        unset($data['library_slug']);
        $data += ['tenant_id'=>null, 'library_id'=>$libraries[$librarySlug], 'created_by'=>0];
        $rows = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE tenant_id IS NULL AND slug=%s", $data['slug']), ARRAY_A);
        if ($wpdb->last_error !== '' || count($rows) > 1) { throw new \RuntimeException('Scenario stub query failed.'); }
        $now = current_time('mysql', true);
        if (!$rows) {
            self::insert('scenarios', $data + ['created_at'=>$now,'updated_at'=>$now]);
            return;
        }
        if (!self::matches($rows[0], $data) || $rows[0]['current_version_id'] !== null) { throw new \RuntimeException('Scenario stub conflict.'); }
    }

    private static function import(array $entry, array $libraries): void {
        global $wpdb;
        $scenarios = Schema::table('scenarios');
        $versions = Schema::table('scenario_versions');
        $scenarioData = $entry['scenario'];
        $librarySlug = (string)$scenarioData['library_slug'];
        unset($scenarioData['library_slug']);
        $scenarioData += ['tenant_id'=>null, 'library_id'=>$libraries[$librarySlug], 'created_by'=>0];
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$scenarios` WHERE tenant_id IS NULL AND slug=%s", $scenarioData['slug']), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Scenario query failed.'); }
        if (count($rows) > 1) { throw new \RuntimeException('Ambiguous system scenario.'); }
        $now = current_time('mysql', true);
        $fresh = !$rows; $promotingStub = false;
        if ($fresh) {
            $scenarioId = self::insert('scenarios', $scenarioData + ['created_at'=>$now,'updated_at'=>$now]);
        } else {
            $row = $rows[0];
            $promotingStub = (string)($row['status'] ?? '') === 'draft' && $row['current_version_id'] === null && (string)$scenarioData['status'] === 'published';
            $expected = $scenarioData;
            unset($expected['library_id'], $expected['status']);
            if (!self::matches($row, $expected) || (!$promotingStub && (string)$row['status'] !== (string)$scenarioData['status'])) { throw new \RuntimeException('System scenario conflict.'); }
            $existingLibrary = $row['library_id'] === null ? 0 : (int)$row['library_id'];
            if ($existingLibrary === 0) {
                self::execute($wpdb->prepare("UPDATE `$scenarios` SET library_id=%d,updated_at=%s WHERE id=%d AND library_id IS NULL", (int)$scenarioData['library_id'], $now, (int)$row['id']));
                $existingLibrary = (int)$scenarioData['library_id'];
            }
            if ($existingLibrary !== (int)$scenarioData['library_id']) { throw new \RuntimeException('Scenario library conflict.'); }
            if ($promotingStub) { self::execute($wpdb->prepare("UPDATE `$scenarios` SET status='published',updated_at=%s WHERE id=%d AND status='draft' AND current_version_id IS NULL", $now, (int)$row['id'])); }
            $scenarioId = (int)$row['id'];
        }
        $versionData = $entry['version'];
        $versionData['mechanics_json']['content_manifest'] = $entry['expected'];
        $versionData['scenario_id'] = $scenarioId;
        $existing = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$versions` WHERE scenario_id=%d", $scenarioId), ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('Version query failed.'); }
        $targetVersionNumber = (int)($versionData['version_number'] ?? 0);
        $targetRows = array_values(array_filter($existing, static fn(array $row): bool => (int)($row['version_number'] ?? 0) === $targetVersionNumber));
        if (count($targetRows) > 1) { throw new \RuntimeException('Ambiguous published version.'); }
        $targetFresh = !$targetRows;
        if ($targetFresh) {
            $versionId = self::insert('scenario_versions', $versionData + ['created_at'=>$now,'published_at'=>$now]);
        } else {
            if (!self::matches($targetRows[0], $versionData) || empty($targetRows[0]['published_at'])) { throw new \RuntimeException('Published version conflict.'); }
            $versionId = (int)$targetRows[0]['id'];
        }
        foreach (self::COMPONENTS as $key) {
            $table = Schema::table($key);
            $existingRows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE scenario_version_id=%d", $versionId), ARRAY_A);
            if ($wpdb->last_error !== '') { throw new \RuntimeException('Component query failed.'); }
            $byCode = array_column($existingRows, null, 'code');
            if (!$targetFresh && count($existingRows) !== count($entry['components'][$key])) { throw new \RuntimeException('Component count conflict.'); }
            foreach ($entry['components'][$key] as $component) {
                $data = $component + ['scenario_version_id'=>$versionId];
                if ($targetFresh) { self::insert($key, $data); }
                elseif (!isset($byCode[$component['code']]) || !self::matches($byCode[$component['code']], $data)) { throw new \RuntimeException('Published component conflict.'); }
            }
        }
        $currentVersionId = $fresh ? 0 : (int)($rows[0]['current_version_id'] ?? 0);
        $currentVersionNumber = 0;
        foreach ($existing as $existingVersion) {
            if ((int)($existingVersion['id'] ?? 0) === $currentVersionId) { $currentVersionNumber = (int)($existingVersion['version_number'] ?? 0); break; }
        }
        if ($currentVersionId > 0 && $currentVersionNumber > $targetVersionNumber) { throw new \RuntimeException('System scenario current version is newer than content pack.'); }
        if ($currentVersionId !== $versionId) {
            self::execute($wpdb->prepare("UPDATE `$scenarios` SET current_version_id=%d,updated_at=%s WHERE id=%d", $versionId, $now, $scenarioId));
        }
    }

    public static function run(): bool {
        global $wpdb;
        if (get_option('ckm_neg_db_version', '') !== CKM_NEG_DB_VERSION) { return false; }
        $installed = (string) get_option('ckm_neg_content_version', '0');
        if ($installed === CKM_NEG_CONTENT_VERSION) { return true; }
        if (version_compare($installed, CKM_NEG_CONTENT_VERSION, '>')) { return false; }
        $token = null; $transaction = false;
        $previous = $wpdb->suppress_errors(true);
        try {
            $pack = require __DIR__ . '/content/system-v1.php';
            self::validate($pack);
            $token = Migrations::acquire();
            if ($token === null) { return false; }
            self::execute('START TRANSACTION'); $transaction = true;
            $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s FOR UPDATE", 'ckm_neg_migration_lock'));
            if ($held !== $token) { throw new \RuntimeException('Content lease lost.'); }
            $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", 'ckm_neg_content_version'));
            if ($wpdb->last_error !== '' || version_compare($current ?: '0', CKM_NEG_CONTENT_VERSION, '>')) { throw new \RuntimeException('Content version changed.'); }
            $libraries = self::importLibraries($pack['libraries']);
            foreach ($pack['scenarios'] as $entry) { self::import($entry, $libraries); }
            foreach ((array)($pack['scenario_stubs'] ?? []) as $stub) { self::importStub($stub, $libraries); }
            self::execute('COMMIT'); $transaction = false;
            update_option('ckm_neg_content_version', CKM_NEG_CONTENT_VERSION, false);
            if (get_option('ckm_neg_content_version', '') !== CKM_NEG_CONTENT_VERSION) { throw new \RuntimeException('Content marker failed.'); }
            update_option('ckm_neg_content_migration_status', 'complete', false);
            delete_option('ckm_neg_content_migration_error');
            return true;
        } catch (\Throwable $error) {
            if ($transaction) { $wpdb->query('ROLLBACK'); }
            update_option('ckm_neg_content_migration_status', 'failed', false);
            update_option('ckm_neg_content_migration_error', 'content_install_failed_or_conflict', false);
            return false;
        } finally {
            if ($token !== null) { Migrations::release($token); }
            $wpdb->suppress_errors($previous);
        }
    }
}

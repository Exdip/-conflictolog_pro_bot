<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class Schema {
    public static function spec(): array {
        static $spec;
        if ($spec === null) {
            $spec = json_decode(file_get_contents(__DIR__ . '/schema.json'), true, 512, JSON_THROW_ON_ERROR);
            if (count($spec) !== 17) { throw new \RuntimeException('Invalid NEG-CORE schema.'); }
        }
        return $spec;
    }
    public static function table(string $key): string {
        global $wpdb;
        if (!isset(self::spec()[$key])) { throw new \InvalidArgumentException('Unknown NEG-CORE table.'); }
        return $wpdb->prefix . 'ckm_neg_' . $key;
    }
    public static function sql(string $key): string {
        global $wpdb;
        $spec = self::spec()[$key];
        $lines = [];
        foreach ($spec['columns'] as $column => $type) { $lines[] = "$column $type"; }
        $lines[] = 'PRIMARY KEY  (id)';
        foreach ($spec['unique'] as $name => $columns) { $lines[] = 'UNIQUE KEY ' . $name . ' (' . implode(',', $columns) . ')'; }
        foreach ($spec['indexes'] as $name => $columns) { $lines[] = 'KEY ' . $name . ' (' . implode(',', $columns) . ')'; }
        return 'CREATE TABLE ' . self::table($key) . " (\n" . implode(",\n", $lines) . "\n) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';';
    }
    public static function inspect(): array {
        global $wpdb;
        $result = [];
        foreach (self::spec() as $key => $spec) {
            $table = self::table($key);
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
            $columns = $exists ? (array) $wpdb->get_col("SHOW COLUMNS FROM `$table`") : [];
            $indexes = [];
            if ($exists) {
                foreach ((array) $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A) as $row) {
                    $indexes[$row['Key_name']]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
                    $indexes[$row['Key_name']]['unique'] = !(bool) $row['Non_unique'];
                }
            }
            $missingIndexes = [];
            foreach (['PRIMARY' => ['id']] + $spec['unique'] + $spec['indexes'] as $name => $expected) {
                $actual = $indexes[$name]['columns'] ?? [];
                ksort($actual);
                if (array_values($actual) !== $expected || ((isset($spec['unique'][$name]) || $name === 'PRIMARY') && empty($indexes[$name]['unique']))) {
                    $missingIndexes[] = $name;
                }
            }
            $missingColumns = array_values(array_diff(array_keys($spec['columns']), $columns));
            $result[$key] = ['ok' => $exists && !$missingColumns && !$missingIndexes, 'exists' => $exists, 'missing_columns' => $missingColumns, 'missing_indexes' => $missingIndexes];
        }
        return $result;
    }
    public static function healthy(array $tables): bool {
        return count($tables) === 17 && !in_array(false, array_column($tables, 'ok'), true);
    }
}

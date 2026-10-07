<?php
if (!defined('ABSPATH')) exit;

/**
 * One-time migration for the public game-title rename introduced in dev.278.
 * Internal runtime/product keys deliberately stay unchanged.
 */
function ckm_quiz_pro_title_renames_278_pairs(): array {
    return [
        'Ваш выбор' => 'Управленческая игра "Ваш выбор"',
        'Продажи' => 'Эффективный продажник',
        'Деловые переговоры' => 'Мастер переговоров',
        'Экспресс-раунд' => 'Переговорный раунд',
        'Переговори меня' => 'Переговори другого',
    ];
}

function ckm_quiz_pro_title_renames_278_string(string $value): string {
    // The new management-game title contains the old words "Ваш выбор".
    // Shield the already-renamed form so a retry can never compound it.
    $shield = "\x1ACKM_TITLE_278\x1A";
    $value = str_replace('Управленческая игра "Ваш выбор"', $shield, $value);
    foreach (ckm_quiz_pro_title_renames_278_pairs() as $old => $new) {
        $value = str_replace($old, $new, $value);
    }
    return str_replace($shield, 'Управленческая игра "Ваш выбор"', $value);
}

function ckm_quiz_pro_title_renames_278_deep($value) {
    if (is_string($value)) return ckm_quiz_pro_title_renames_278_string($value);
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = ckm_quiz_pro_title_renames_278_deep($v);
        return $value;
    }
    if (is_object($value)) {
        foreach (get_object_vars($value) as $k => $v) $value->{$k} = ckm_quiz_pro_title_renames_278_deep($v);
        return $value;
    }
    return $value;
}

function ckm_quiz_pro_title_renames_278_update_json_column(string $table, string $idColumn, string $jsonColumn): void {
    global $wpdb;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return;

    $pairs = ckm_quiz_pro_title_renames_278_pairs();
    $likes = [];
    $args = [];
    foreach (array_keys($pairs) as $old) {
        $likes[] = "`{$jsonColumn}` LIKE %s";
        $args[] = '%' . $wpdb->esc_like($old) . '%';
    }
    $sql = "SELECT `{$idColumn}`, `{$jsonColumn}` FROM `{$table}` WHERE " . implode(' OR ', $likes);
    $prepared = $wpdb->prepare($sql, ...$args);
    $rows = $wpdb->get_results($prepared, ARRAY_A) ?: [];
    foreach ($rows as $row) {
        $raw = (string)($row[$jsonColumn] ?? '');
        if ($raw === '') continue;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) continue;
        $updated = ckm_quiz_pro_title_renames_278_deep($decoded);
        if ($updated === $decoded) continue;
        $wpdb->update(
            $table,
            [$jsonColumn => wp_json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            [$idColumn => $row[$idColumn]],
            ['%s'],
            ['%s']
        );
    }
}

function ckm_quiz_pro_title_renames_278_migrate(): void {
    if (get_option('ckm_quiz_pro_title_renames_278_done', '') === '1') return;

    global $wpdb;
    $pairs = ckm_quiz_pro_title_renames_278_pairs();

    // Plain-text plugin-owned columns. These values are not serialized.
    $targets = [
        [$wpdb->prefix . 'ckm_quiz_formats', ['title', 'description']],
        [$wpdb->prefix . 'ckm_quiz_games', ['title']],
        [$wpdb->prefix . 'ckm_quiz_quizzes', ['title', 'short_description']],
        [$wpdb->prefix . 'ckm_quiz_questions', ['round_title', 'question_text']],
        [$wpdb->prefix . 'ckm_quiz_rounds', ['title']],
        [$wpdb->prefix . 'ckm_quiz_game_teams', ['final_summary']],
        [$wpdb->prefix . 'ckm_quiz_history_audit', ['game_title']],
    ];
    foreach ($targets as [$table, $columns]) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
        foreach ($columns as $column) {
            foreach ($pairs as $old => $new) {
                // Avoid compounding the one rename whose new title contains its old title.
                if ($old === 'Ваш выбор') {
                    $where = "`{$column}` LIKE %s AND `{$column}` NOT LIKE %s";
                    $wpdb->query($wpdb->prepare(
                        "UPDATE `{$table}` SET `{$column}`=REPLACE(`{$column}`,%s,%s) WHERE {$where}",
                        $old,
                        $new,
                        '%' . $wpdb->esc_like($old) . '%',
                        '%Управленческая игра "Ваш выбор"%'
                    ));
                } else {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE `{$table}` SET `{$column}`=REPLACE(`{$column}`,%s,%s) WHERE `{$column}` LIKE %s",
                        $old,
                        $new,
                        '%' . $wpdb->esc_like($old) . '%'
                    ));
                }
            }
        }
    }

    // JSON columns must be decoded and re-encoded because the new management title contains quotes.
    ckm_quiz_pro_title_renames_278_update_json_column($wpdb->prefix . 'ckm_quiz_events', 'id', 'payload_json');
    ckm_quiz_pro_title_renames_278_update_json_column($wpdb->prefix . 'ckm_quiz_ready_game_revisions', 'id', 'snapshot_json');

    // Plugin options can be serialized arrays: let WordPress handle serialization lengths.
    foreach (['ckm_quiz_pro_game_access_products'] as $optionName) {
        $oldValue = get_option($optionName, null);
        if ($oldValue === null) continue;
        $newValue = ckm_quiz_pro_title_renames_278_deep($oldValue);
        if ($newValue !== $oldValue) update_option($optionName, $newValue, false);
    }

    // WPCode hotfixes 255–257 keep running code unchanged, but their admin titles
    // should follow the new public game name as well. Changing only post_title
    // does not disable or rewrite the active snippets.
    $wpcodeRows = $wpdb->get_results(
        "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type='wpcode' AND post_title LIKE '%Переговори меня%'",
        ARRAY_A
    ) ?: [];
    foreach ($wpcodeRows as $row) {
        $oldTitle = (string)($row['post_title'] ?? '');
        $newTitle = ckm_quiz_pro_title_renames_278_string($oldTitle);
        if ($newTitle !== $oldTitle) {
            $wpdb->update($wpdb->posts, ['post_title'=>$newTitle], ['ID'=>(int)$row['ID']], ['%s'], ['%d']);
            clean_post_cache((int)$row['ID']);
        }
    }

    // Ready-game metadata can also be serialized; update through the metadata API.
    $metaRows = $wpdb->get_results(
        "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_ckm_ready_subtitle','_ckm_ready_integrity_result')",
        ARRAY_A
    ) ?: [];
    foreach ($metaRows as $metaRow) {
        $mid = (int)($metaRow['meta_id'] ?? 0);
        if ($mid <= 0) continue;
        $meta = get_metadata_by_mid('post', $mid);
        if (!$meta) continue;
        $oldValue = $meta->meta_value;
        $newValue = ckm_quiz_pro_title_renames_278_deep($oldValue);
        if ($newValue !== $oldValue) update_metadata_by_mid('post', $mid, $newValue);
    }

    update_option('ckm_quiz_pro_title_renames_278_done', '1', false);
}
add_action('init', 'ckm_quiz_pro_title_renames_278_migrate', 3);

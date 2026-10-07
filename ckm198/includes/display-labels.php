<?php
if (!defined('ABSPATH')) exit;

/** Presentation only; never rewrite URLs, identifiers or stored game content. */
function ckm_quiz_pro_display_label($label): string {
    $label = preg_replace('/(?<![\p{L}\p{N}_])(?:CKM|ЦКМ|СКМ)(?![\p{L}\p{N}_])/u', '', (string)$label);
    return trim(preg_replace('/[ \t]{2,}/u', ' ', $label), " \t—·-");
}

// Update only the plugin's own generated page titles, preserving custom titles.
add_action('init', function () {
    if (get_option('ckm_quiz_pro_display_labels_version') === '0.3.23.14') return;
    $ids = [(int)get_option('ckm_quiz_pro_checkout_page_id', 0)];
    foreach (['organizer', 'login', 'play', 'host', 'scoreboard'] as $key) $ids[] = (int)get_option('ckm_quiz_pro_page_'.$key, 0);
    $titles = [
        'CKM Quiz Pro — Оплата'=>'Оплата игр',
        'CKM — Кабинет организатора'=>'Кабинет организатора',
        'CKM — Вход организатора'=>'Вход организатора',
        'CKM Quiz Pro — Игрок'=>'Игрок',
        'CKM Quiz Pro — Ведущий'=>'Ведущий',
        'CKM Quiz Pro — Табло'=>'Табло'
    ];
    $ok = true;
    foreach (array_unique($ids) as $id) {
        $page = $id > 0 ? get_post($id) : null;
        if (!$page || $page->post_type !== 'page' || !isset($titles[$page->post_title])) continue;
        $result = wp_update_post(['ID'=>$id, 'post_title'=>$titles[$page->post_title]], true);
        if (is_wp_error($result) || !$result) $ok = false;
    }
    if ($ok) update_option('ckm_quiz_pro_display_labels_version', '0.3.23.14', false);
}, 40);


/** Canonical public titles for bundled games. Technical slugs stay unchanged. */
function ckm_quiz_pro_builtin_game_titles(): array {
    return [
        'demo-classic-quiz'=>'Классический квиз',
        'demo-battle-experts'=>'Битва знатоков',
        'ckm-demo-intellectual-battle'=>'Интеллектуальный батл',
        'demo-solution-price'=>'Управленческая игра "Ваш выбор"',
        'demo-negotiation-sales'=>'Эффективный продажник',
        'demo-negotiation-business'=>'Мастер переговоров',
        'demo-negotiation-express'=>'Переговорный раунд',
        'demo-negotiation-communicate'=>'Переговори другого',
        'demo-negotiation-communicate-school'=>'Переговори другого — для школьников',
        'demo-negotiation-communicate-school-grade'=>'Переговори другого — для школьников: «Двойка, которой не было»',
        'demo-negotiation-communicate-student'=>'Переговори другого — для студентов',
        'demo-negotiation-communicate-leader'=>'Переговори другого — для руководителей',
        'demo-negotiation-communicate-family'=>'Переговори другого — Семейные ситуации',
    ];
}

function ckm_quiz_pro_normalize_builtin_game_titles(): void {
    if (get_option('ckm_quiz_pro_game_titles_version') === '0.3.23.214') return;
    if (!function_exists('ckm_quiz_pro_table')) return;
    global $wpdb;
    $quizzes=ckm_quiz_pro_table('quizzes');
    $games=ckm_quiz_pro_table('games');
    $audit=ckm_quiz_pro_table('history_audit');
    if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$quizzes)) !== $quizzes) return;
    $ok=true;
    foreach (ckm_quiz_pro_builtin_game_titles() as $slug=>$title) {
        $quiz=$wpdb->get_row($wpdb->prepare("SELECT id,title FROM {$quizzes} WHERE slug=%s LIMIT 1",$slug),ARRAY_A);
        if (!$quiz) continue;
        $quizId=(int)$quiz['id'];
        if ((string)$quiz['title'] !== $title && $wpdb->update($quizzes,['title'=>$title],['id'=>$quizId])===false) $ok=false;
        if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$games)) === $games) {
            if ($wpdb->query($wpdb->prepare("UPDATE {$games} SET title=%s WHERE quiz_id=%d AND title<>%s",$title,$quizId,$title))===false) $ok=false;
        }
        if ((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$audit)) === $audit && (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$games)) === $games) {
            if ($wpdb->query($wpdb->prepare("UPDATE {$audit} a INNER JOIN {$games} g ON g.id=a.game_id SET a.game_title=%s WHERE g.quiz_id=%d AND a.game_title<>%s",$title,$quizId,$title))===false) $ok=false;
        }
    }
    if ($ok) update_option('ckm_quiz_pro_game_titles_version','0.3.23.214',false);
}
add_action('init','ckm_quiz_pro_normalize_builtin_game_titles',45);

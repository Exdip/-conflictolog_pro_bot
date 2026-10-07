<?php
if (!defined('ABSPATH')) exit;

/**
 * CKM remote AI Puffer connection.
 * Keeps the developer REST key server-side. Nothing is exposed to game clients.
 */
function ckm_quiz_pro_aipuffer_endpoint(): string {
    $saved = trim((string)get_option('ckm_quiz_pro_aipuffer_url', ''));
    if ($saved === '') {
        $saved = 'https://centr-razvitia-uma.ru/wp-json/aipkit/v1/generate';
    }
    $saved = esc_url_raw($saved, ['https']);
    return $saved !== '' ? $saved : 'https://centr-razvitia-uma.ru/wp-json/aipkit/v1/generate';
}

function ckm_quiz_pro_aipuffer_connection_mode(): string {
    $endpoint = ckm_quiz_pro_aipuffer_endpoint();
    $local = rest_url('aipkit/v1/generate');
    return untrailingslashit($endpoint) === untrailingslashit($local) ? 'local' : 'remote';
}

function ckm_quiz_pro_aipuffer_post(array $body, int $timeout = 30) {
    if (!function_exists('ckm_quiz_pro_solution_price_aipuffer_rest_key')) {
        return new WP_Error('ckm_aipuffer_key_helper_missing', 'Модуль подключения AI Puffer не загружен.');
    }
    $key = ckm_quiz_pro_solution_price_aipuffer_rest_key();
    if ($key === '') {
        return new WP_Error('ckm_aipuffer_key_missing', 'Не найден REST-ключ AI Puffer. Организатору нужно настроить подключение ИИ.');
    }
    return wp_remote_post(ckm_quiz_pro_aipuffer_endpoint(), [
        'timeout' => max(3, $timeout),
        'redirection' => 0,
        'sslverify' => true,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$key,
            'User-Agent' => 'CKM-Quiz-Pro/'.CKM_QUIZ_PRO_VERSION,
        ],
        'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

add_action('admin_menu', static function () {
    add_submenu_page(
        'ckm-quiz-pro',
        'ИИ-арбитр',
        'ИИ-арбитр',
        'manage_options',
        'ckm-quiz-pro-ai-arbiter',
        'ckm_quiz_pro_ai_arbiter_admin_page'
    );
}, 31);

function ckm_quiz_pro_ai_arbiter_admin_page(): void {
    if (!current_user_can('manage_options')) wp_die('Недостаточно прав.');

    $notice = '';
    $noticeClass = 'notice-success';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckmqp_ai_save'])) {
        check_admin_referer('ckmqp_ai_arbiter_save');
        $url = isset($_POST['aipuffer_url']) ? esc_url_raw(trim((string)wp_unslash($_POST['aipuffer_url'])), ['https']) : '';
        if ($url === '') $url = 'https://centr-razvitia-uma.ru/wp-json/aipkit/v1/generate';
        update_option('ckm_quiz_pro_aipuffer_url', $url, false);

        $newKey = isset($_POST['aipuffer_key']) ? trim((string)wp_unslash($_POST['aipuffer_key'])) : '';
        if ($newKey !== '') {
            if (strlen($newKey) < 12) {
                $notice = 'REST-ключ слишком короткий; старый ключ сохранён без изменений.';
                $noticeClass = 'notice-error';
            } else {
                update_option('ckm_quiz_pro_aipuffer_rest_key', $newKey, false);
            }
        }
        if ($notice === '') $notice = 'Настройки ИИ-арбитра сохранены.';
    }

    $url = ckm_quiz_pro_aipuffer_endpoint();
    $keySet = function_exists('ckm_quiz_pro_solution_price_aipuffer_rest_key') && ckm_quiz_pro_solution_price_aipuffer_rest_key() !== '';
    $mode = ckm_quiz_pro_aipuffer_connection_mode();

    echo '<div class="wrap"><h1>ИИ-арбитр</h1>';
    if ($notice !== '') echo '<div class="notice '.esc_attr($noticeClass).' is-dismissible"><p>'.esc_html($notice).'</p></div>';
    echo '<p>Центральное подключение AI Puffer для оценивания игровых ответов. REST-ключ хранится только на сервере WordPress и не передаётся в браузер участника.</p>';
    echo '<form method="post">';
    wp_nonce_field('ckmqp_ai_arbiter_save');
    echo '<table class="form-table" role="presentation">';
    echo '<tr><th scope="row"><label for="ckmqp-aipuffer-url">URL AI Puffer</label></th><td><input id="ckmqp-aipuffer-url" class="regular-text code" type="url" name="aipuffer_url" value="'.esc_attr($url).'" required><p class="description">Для общей инфраструктуры ЦКМ: https://centr-razvitia-uma.ru/wp-json/aipkit/v1/generate</p></td></tr>';
    echo '<tr><th scope="row"><label for="ckmqp-aipuffer-key">REST-ключ AI Puffer</label></th><td><input id="ckmqp-aipuffer-key" class="regular-text" type="password" name="aipuffer_key" value="" autocomplete="new-password" placeholder="'.($keySet?'Ключ уже сохранён — оставьте пустым':'Вставьте developer REST key').'"><p class="description">'.($keySet?'Ключ сохранён. Чтобы оставить его без изменений, не заполняйте поле.':'Ключ ещё не настроен.').'</p></td></tr>';
    echo '<tr><th scope="row">Режим</th><td><strong>'.esc_html($mode === 'remote' ? 'Удалённый AI Puffer' : 'Локальный AI Puffer').'</strong><p class="description">Текущий endpoint: <code>'.esc_html($url).'</code></p></td></tr>';
    echo '</table>';
    submit_button('Сохранить подключение', 'primary', 'ckmqp_ai_save');
    echo '</form></div>';
}

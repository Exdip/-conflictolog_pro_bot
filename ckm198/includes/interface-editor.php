<?php
if (!defined('ABSPATH')) exit;

/**
 * CKM Quiz Pro interface editor.
 * Stores editable labels/visibility flags in wp_options so plugin updates do not
 * erase the owner's copy. Runtime helpers are intentionally tiny and safe to call
 * from frontend shells before the full WordPress theme loads.
 */
function ckm_quiz_pro_interface_defaults(): array {
    return [
        // Main platform homepage.
        'home_enabled' => '1',
        'home_kicker' => 'ЦКМ · ИНТЕЛЛЕКТУАЛЬНЫЕ СОРЕВНОВАНИЯ',
        'home_title' => 'Интеллектуальные соревнования для бизнеса, обучения и развлечения',
        'home_lead' => 'Деловые игры для развития навыков, интеллектуальные игры для турниров и возможность создавать собственные сценарии — на одном игровом движке.',
        'home_primary_logged_in' => 'Кабинет организатора',
        'home_primary_logged_out' => 'Войти организатору',
        'home_secondary_label' => 'О Центре развития качественного мышления',
        'home_secondary_url' => 'https://centr-razvitia-uma.ru/',
        'home_card_organizers_visible' => '0',
        'home_card_organizers_title' => 'Организаторам',
        'home_card_organizers_text' => 'В кабинете доступны ваши игры, запуск команд, ссылки участников и результаты. Возможность конструктора определяется вашим тарифом.',

        // Tenant storefront / payment selection.
        'tenant_kicker_prefix' => 'ПЛОЩАДКА',
        'tenant_title' => 'Игры площадки',
        'tenant_lead' => 'Оплаченные игры можно сразу открыть. Остальные игровые форматы доступны для оплаты на 30 дней.',
        'payment_kicker' => 'КАБИНЕТ ОРГАНИЗАТОРА',
        'payment_title' => 'Оплата игр',
        'payment_paid_title' => 'Оплаченные игры',
        'payment_unpaid_title' => 'Игры для оплаты',
        'payment_add_other_title' => 'Добавить другие игры',
        'payment_all_paid_text' => 'Все доступные игровые форматы уже оплачены.',
        'payment_tenant_all_paid_text' => 'Все игровые форматы на этой площадке уже оплачены.',
        'payment_paid_badge' => 'Оплачено',
        'payment_open_button' => 'Открыть игру',
        'payment_buy_button' => 'Оплатить',
        'payment_add_button' => 'Добавить',
        'payment_in_order_badge' => 'Уже в заказе',
        'payment_access_text' => 'Доступ: 30 дней',
        'payment_total_prefix' => 'Итого:',
        'payment_checkout_button' => 'Перейти к оплате',
        'payment_add_other_button' => 'Добавить другие игры',
        'payment_remove_from_order' => 'Убрать из заказа',
        'payment_removed_paid_notice' => 'Уже оплаченные игры удалены из нового заказа. Их можно открыть в разделе «Оплаченные игры».',

        // Organizer cabinet / start page.
        'organizer_home_title' => 'Выберите направление',
        'organizer_home_lead' => 'Деловые игры, интеллектуальные игры или собственный сценарий — три понятных пути внутри одной платформы.',
        'organizer_start_button' => 'Запустить игру',
        'library_kicker' => 'МОИ ИГРЫ',
        'library_title' => 'Мои игры',
        'library_lead' => 'Здесь отдельно показаны оплаченные базовые игры, купленные готовые сюжеты и собственные игры.',
        'library_empty_title' => 'Игр пока нет',
        'library_empty_text' => 'После оплаты, покупки готовой игры или создания собственной игры она появится здесь.',
        'new_game_kicker' => 'НОВЫЙ ЗАПУСК',
        'new_game_title' => 'Запустить игру',
        'new_game_lead' => 'Выберите игру и количество команд. Ссылки будут созданы автоматически.',
        'new_game_create_button' => 'Создать ссылки команд',

        // Public rooms.
        'room_play_label' => 'Комната участника',
        'room_host_label' => 'Комната ведущего',
        'room_scoreboard_label' => 'Публичное табло',
        'room_connecting' => 'Подключение…',
        'room_waiting_phase' => 'Ожидание',
        'room_default_title' => 'Игра',
        'room_sync_status' => 'Синхронизация с сервером…',
        'host_panel_label' => 'Панель ведущего',
        'host_waiting_question' => 'Ожидание старта',
        'host_start_ai_button' => 'Запустить игру',
        'host_start_human_button' => 'Задать вопрос',
        'host_next_button' => 'Следующий вопрос',
        'host_close_button' => 'Закрыть вопрос',
        'host_finish_button' => 'Завершить игру',
        'host_voice_prestart_note' => 'Перед стартом: подключите голос ведущего в верхней панели. Для ИИ-ведущего кнопка «Запустить игру» станет доступна после готовности Сергея.',
        'host_answers_title' => 'Ответы команд',
        'host_answers_empty' => 'Ответов пока нет.',
        'score_title' => 'Счёт',
        'scoreboard_title' => 'Табло',
        'participant_waiting_question' => 'Ожидайте старта игры',
        'participant_team_label' => 'Команда',
    ];
}

function ckm_quiz_pro_interface_settings(): array {
    $defaults = ckm_quiz_pro_interface_defaults();
    $saved = get_option('ckm_quiz_pro_interface_settings', []);
    if (!is_array($saved)) $saved = [];
    return array_merge($defaults, array_intersect_key($saved, $defaults));
}

function ckm_quiz_pro_ui_text(string $key, string $fallback = ''): string {
    $settings = ckm_quiz_pro_interface_settings();
    $value = isset($settings[$key]) ? trim((string)$settings[$key]) : '';
    if ($value === '') {
        $defaults = ckm_quiz_pro_interface_defaults();
        $value = isset($defaults[$key]) ? (string)$defaults[$key] : $fallback;
    }
    return $value;
}

function ckm_quiz_pro_ui_enabled(string $key, bool $fallback = true): bool {
    $settings = ckm_quiz_pro_interface_settings();
    if (!array_key_exists($key, $settings)) return $fallback;
    return (string)$settings[$key] === '1';
}

function ckm_quiz_pro_ui_js_labels(): array {
    $keys = [
        'host_start_ai_button','host_start_human_button','host_next_button','host_close_button','host_finish_button',
        'host_answers_empty','participant_team_label','participant_waiting_question','score_title','scoreboard_title'
    ];
    $out = [];
    foreach ($keys as $key) $out[$key] = ckm_quiz_pro_ui_text($key);
    return $out;
}


/** Upgrade only old default copy. Explicit owner edits stay untouched. */
function ckm_quiz_pro_navigation_architecture_migrate_ui(): void {
    if (get_option('ckm_quiz_pro_nav_architecture_ui','')==='1') return;
    $saved=get_option('ckm_quiz_pro_interface_settings',[]);
    if (!is_array($saved)) $saved=[];
    $legacy=[
        'home_kicker'=>'УПРАВЛЯЕМАЯ ИГРОВАЯ ПЛАТФОРМА',
        'home_title'=>'Игровая платформа',
        'home_lead'=>'Здесь организаторы запускают подготовленные для них интеллектуальные игры и тренинги. Участники входят по персональной ссылке, которую присылает организатор.',
        'home_primary_logged_in'=>'Открыть кабинет организатора',
        'home_card_organizers_visible'=>'1',
        'organizer_home_title'=>'Игровая платформа',
        'organizer_home_lead'=>'Выбирайте и оплачивайте игры, запускайте команды и просматривайте результаты.',
    ];
    $new=ckm_quiz_pro_interface_defaults(); $changed=false;
    foreach($legacy as $key=>$old) if (array_key_exists($key,$saved) && (string)$saved[$key]===(string)$old) { $saved[$key]=$new[$key]; $changed=true; }
    if ($changed) update_option('ckm_quiz_pro_interface_settings',$saved,false);
    update_option('ckm_quiz_pro_nav_architecture_ui','1',false);
}
add_action('init','ckm_quiz_pro_navigation_architecture_migrate_ui',25);

function ckm_quiz_pro_interface_admin_menu(): void {
    add_submenu_page(
        'ckm-quiz-pro',
        'Редактор интерфейса',
        'Редактор интерфейса',
        'manage_options',
        'ckm-quiz-pro-interface',
        'ckm_quiz_pro_interface_admin_page'
    );
}
add_action('admin_menu','ckm_quiz_pro_interface_admin_menu',30);

function ckm_quiz_pro_interface_admin_page(): void {
    if (!current_user_can('manage_options')) return;
    $defaults = ckm_quiz_pro_interface_defaults();
    $saved = ckm_quiz_pro_interface_settings();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_interface_save'])) {
        check_admin_referer('ckm_qp_interface_save');
        $new = [];
        foreach ($defaults as $key => $default) {
            if (str_ends_with($key, '_visible') || str_ends_with($key, '_enabled')) {
                $new[$key] = !empty($_POST[$key]) ? '1' : '0';
            } elseif (str_ends_with($key, '_url')) {
                $new[$key] = esc_url_raw((string)wp_unslash($_POST[$key] ?? $default));
            } else {
                $new[$key] = sanitize_textarea_field((string)wp_unslash($_POST[$key] ?? $default));
            }
        }
        update_option('ckm_quiz_pro_interface_settings', $new, false);
        $saved = array_merge($defaults, $new);
        echo '<div class="notice notice-success is-dismissible"><p>Редактор интерфейса: настройки сохранены.</p></div>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ckm_qp_interface_reset'])) {
        check_admin_referer('ckm_qp_interface_reset');
        delete_option('ckm_quiz_pro_interface_settings');
        $saved = $defaults;
        echo '<div class="notice notice-warning is-dismissible"><p>Редактор интерфейса: возвращены настройки по умолчанию.</p></div>';
    }

    echo '<div class="wrap"><h1>Редактор интерфейса CKM</h1><p>Здесь редактируются тексты, кнопки и часть видимости блоков игровых страниц. Настройки сохраняются в базе данных и не стираются при обновлении плагина.</p>';
    echo '<form method="post">';
    wp_nonce_field('ckm_qp_interface_save');
    echo '<style>.ckm-ui-section{background:#fff;border:1px solid #c3c4c7;border-radius:8px;margin:18px 0;padding:18px}.ckm-ui-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ckm-ui-field{display:grid;gap:6px}.ckm-ui-field label{font-weight:600}.ckm-ui-field textarea{min-height:78px}.ckm-ui-wide{grid-column:1/-1}@media(max-width:900px){.ckm-ui-grid{grid-template-columns:1fr}}</style>';

    ckm_quiz_pro_interface_section('Главная страница ckkm.ru', [
        'home_kicker'=>'Надзаголовок', 'home_title'=>'Заголовок', 'home_lead'=>'Описание',
        'home_primary_logged_in'=>'Кнопка для вошедшего организатора', 'home_primary_logged_out'=>'Кнопка входа',
        'home_secondary_label'=>'Ссылка внизу первого экрана', 'home_secondary_url'=>'URL ссылки внизу',
    ], $saved, ['home_lead']);

    ckm_quiz_pro_interface_section('Страница выбора игр и оплаты', [
        'tenant_kicker_prefix'=>'Префикс надзаголовка площадки', 'tenant_title'=>'Заголовок витрины площадки', 'tenant_lead'=>'Описание витрины площадки',
        'payment_kicker'=>'Надзаголовок оплаты', 'payment_title'=>'Заголовок оплаты', 'payment_paid_title'=>'Блок оплаченных игр',
        'payment_unpaid_title'=>'Блок игр для оплаты', 'payment_add_other_title'=>'Заголовок добавления игр',
        'payment_all_paid_text'=>'Сообщение: всё оплачено', 'payment_tenant_all_paid_text'=>'Сообщение площадки: всё оплачено',
        'payment_paid_badge'=>'Бейдж оплаты', 'payment_open_button'=>'Кнопка открыть игру', 'payment_buy_button'=>'Кнопка оплатить',
        'payment_add_button'=>'Кнопка добавить', 'payment_in_order_badge'=>'Бейдж уже в заказе', 'payment_access_text'=>'Текст доступа',
        'payment_total_prefix'=>'Префикс итого', 'payment_checkout_button'=>'Кнопка перехода к оплате', 'payment_add_other_button'=>'Кнопка добавить другие игры',
        'payment_remove_from_order'=>'Ссылка убрать из заказа', 'payment_removed_paid_notice'=>'Уведомление об удалении оплаченных игр',
    ], $saved, ['tenant_lead','payment_removed_paid_notice']);

    ckm_quiz_pro_interface_section('Кабинет организатора', [
        'organizer_home_title'=>'Заголовок обзора', 'organizer_home_lead'=>'Описание обзора', 'organizer_start_button'=>'Кнопка запуска',
        'library_kicker'=>'Надзаголовок библиотеки', 'library_title'=>'Заголовок библиотеки', 'library_lead'=>'Описание библиотеки',
        'library_empty_title'=>'Пустая библиотека: заголовок', 'library_empty_text'=>'Пустая библиотека: текст',
        'new_game_kicker'=>'Надзаголовок запуска', 'new_game_title'=>'Заголовок запуска', 'new_game_lead'=>'Описание запуска',
        'new_game_create_button'=>'Кнопка создания ссылок',
    ], $saved, ['organizer_home_lead','library_lead','library_empty_text','new_game_lead']);

    ckm_quiz_pro_interface_section('Комната ведущего / участника / табло', [
        'room_play_label'=>'Верхняя подпись: участник', 'room_host_label'=>'Верхняя подпись: ведущий', 'room_scoreboard_label'=>'Верхняя подпись: табло',
        'room_connecting'=>'Код игры до подключения', 'room_waiting_phase'=>'Фаза по умолчанию', 'room_default_title'=>'Заголовок по умолчанию', 'room_sync_status'=>'Статус синхронизации',
        'host_panel_label'=>'Подпись панели ведущего', 'host_waiting_question'=>'Текст вопроса до старта',
        'host_start_ai_button'=>'Кнопка старта ИИ-ведущего', 'host_start_human_button'=>'Кнопка старта человека', 'host_next_button'=>'Кнопка следующий вопрос',
        'host_close_button'=>'Кнопка закрыть вопрос', 'host_finish_button'=>'Кнопка завершить игру',
        'host_voice_prestart_note'=>'Подсказка перед стартом с голосом', 'host_answers_title'=>'Заголовок ответов', 'host_answers_empty'=>'Пустой список ответов',
        'score_title'=>'Заголовок счёта', 'scoreboard_title'=>'Заголовок табло', 'participant_waiting_question'=>'Ожидание в комнате участника', 'participant_team_label'=>'Подпись команды',
    ], $saved, ['host_voice_prestart_note']);

    echo '<p><button class="button button-primary" name="ckm_qp_interface_save" value="1">Сохранить редактор интерфейса</button></p>';
    echo '</form>';
    echo '<form method="post" onsubmit="return confirm(\'Вернуть все тексты интерфейса по умолчанию?\');">';
    wp_nonce_field('ckm_qp_interface_reset');
    echo '<p><button class="button" name="ckm_qp_interface_reset" value="1">Сбросить к значениям по умолчанию</button></p></form></div>';
}

function ckm_quiz_pro_interface_section(string $title, array $fields, array $values, array $textareaKeys = []): void {
    echo '<section class="ckm-ui-section"><h2>'.esc_html($title).'</h2><div class="ckm-ui-grid">';
    foreach ($fields as $key => $label) {
        $isBool = str_ends_with((string)$key, '_visible') || str_ends_with((string)$key, '_enabled');
        $wide = in_array($key, $textareaKeys, true) ? ' ckm-ui-wide' : '';
        echo '<div class="ckm-ui-field'.$wide.'">';
        if ($isBool) {
            echo '<label><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked((string)($values[$key] ?? '') === '1', true, false).'> '.esc_html($label).'</label>';
        } elseif (in_array($key, $textareaKeys, true)) {
            echo '<label for="'.esc_attr($key).'">'.esc_html($label).'</label><textarea class="large-text" id="'.esc_attr($key).'" name="'.esc_attr($key).'">'.esc_textarea((string)($values[$key] ?? '')).'</textarea>';
        } else {
            echo '<label for="'.esc_attr($key).'">'.esc_html($label).'</label><input class="regular-text" type="text" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr((string)($values[$key] ?? '')).'">';
        }
        echo '</div>';
    }
    echo '</div></section>';
}

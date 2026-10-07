<?php
if (!defined('ABSPATH')) exit;

/**
 * One authoritative storefront registry for all eight user-facing formats.
 * Runtime keys stay compatible with the existing engine; three negotiation
 * cards intentionally share one paid product / engine family.
 */
function ckm_quiz_pro_games_catalog_registry(): array {
    return [
        'business' => [
            'title' => 'Деловые игры',
            'lead'  => 'Два направления: развитие переговорных навыков и развитие навыка принятия управленческих решений.',
            'items' => [
                'decision_price_v1' => [
                    'title'=>'Управленческая игра "Ваш выбор"',
                    'tagline'=>'Сделать управленческий выбор и оценить последствия',
                    'description'=>'Участники анализируют управленческую ситуацию, выбирают вариант действий и оценивают последствия своего выбора.',
                    'audience'=>'Руководители, предприниматели, проектные команды и кадровый резерв.',
                    'badge'=>'Управленческие решения',
                    'icon'=>'◆',
                    'product'=>'decision_price_v1',
                ],
                'sales_v1' => [
                    'title'=>'Эффективный продажник',
                    'tagline'=>'Понять клиента и привести его к решению',
                    'description'=>'Участник выявляет потребность, работает с возражениями и продвигает разговор к следующему шагу.',
                    'audience'=>'Отделы продаж, коммерческие команды, предприниматели, B2B-менеджеры.',
                    'badge'=>'',
                    'icon'=>'↗',
                    'product'=>'negotiation_duel_v1',
                    'effective_sales'=>true,
                ],
                'business_negotiation_v1' => [
                    'title'=>'Мастер переговоров',
                    'tagline'=>'Защитить интересы и заключить хорошее соглашение',
                    'description'=>'У сторон разные цели, ограничения и возможности для обмена. Важно не выиграть спор, а заключить качественную сделку.',
                    'audience'=>'Руководители, закупщики, аккаунт-менеджеры, предприниматели, B2B-команды.',
                    'badge'=>'',
                    'icon'=>'⇄',
                    'product'=>'negotiation_duel_v1',
                ],
                'negotiation_master_v1' => [
                    'title'=>'Мастер переговоров',
                    'tagline'=>'Договориться, управляя интересами, условиями и уступками',
                    'description'=>'Свободные переговоры с ИИ-оппонентом: исследуйте интересы, защищайте границы, собирайте пакет условий и фиксируйте соглашение или рациональный выход без сделки.',
                    'audience'=>'Руководители, предприниматели, закупщики, коммерческие команды и специалисты, ведущие сложные переговоры.',
                    'badge'=>'ИИ-оппонент · ИИ-тренер',
                    'icon'=>'⇆',
                    'product'=>'negotiation_duel_v1',
                    'negotiation_master'=>true,
                ],
                'express_round_v1' => [
                    'title'=>'Экспресс-раунд',
                    'tagline'=>'Быстро ответить на сложный переговорный ход',
                    'description'=>'Короткий формат почти без подготовки: участник получает ситуацию или реплику оппонента и формулирует следующую рабочую реплику.',
                    'audience'=>'Командные турниры, обучение коммуникации, корпоративные мероприятия.',
                    'badge'=>'',
                    'icon'=>'⚡',
                    'product'=>'negotiation_duel_v1',
                ],
                'persuade_me_v1' => [
                    'title'=>'Переговори другого',
                    'tagline'=>'Переговорные поединки · коммуникативное многоборье',
                    'description'=>'Три команды и разные роли. Четыре раунда: «Удержи цель», «Скрытая задача», «Неудобный вопрос» и финал «Проверь историю» с закрытым досье, уточняющими вопросами и тайным голосованием.',
                    'audience'=>'Командное обучение и корпоративные встречи.',
                    'badge'=>'4 раунда · ИИ-арбитраж',
                    'icon'=>'◇',
                    'product'=>'negotiation_duel_v1',
                ],
            ],
        ],
        'intellectual' => [
            'title' => 'Интеллектуальные игры',
            'lead'  => 'Для командных турниров, корпоративов, клубов и интеллектуального развлечения.',
            'items' => [
                'classic_quiz_v1' => [
                    'title'=>'Классический квиз',
                    'tagline'=>'Вопросы, ответы, скорость и командный счёт',
                    'description'=>'Знакомая командная механика с раундами, таймерами, ответами и общей таблицей результатов.',
                    'audience'=>'Корпоративы, клубы, мероприятия, дружеские соревнования.',
                    'badge'=>'Простой вход',
                    'icon'=>'?',
                    'product'=>'classic_quiz',
                ],
                'chgk_v1' => [
                    'title'=>'Битва знатоков',
                    'tagline'=>'Одна команда против Игры · первым до 6',
                    'description'=>'Не квиз на скорость: Знатоки вместе обсуждают сложный вопрос и фиксируют один окончательный ответ. Правильно — +1 Знатокам, ошибка или пропуск — +1 Игре; матч идёт до 6 очков.',
                    'audience'=>'Корпоративные группы, интеллектуальные клубы, тематические игры одной команды против системы.',
                    'badge'=>'1 команда',
                    'icon'=>'◎',
                    'product'=>'chgk_v1',
                ],
                'jeopardy_v1' => [
                    'title'=>'Интеллектуальный батл',
                    'tagline'=>'Выбирайте тему, стоимость вопроса и стратегию игры',
                    'description'=>'Команды выбирают категории и стоимость заданий, борются за право ответа и управляют риском по ходу турнира.',
                    'audience'=>'Динамичные турниры, корпоративы и зрелищные командные игры.',
                    'badge'=>'',
                    'icon'=>'★',
                    'product'=>'jeopardy_v1',
                ],
            ],
        ],
        'education' => [
            'title' => 'Образовательные игры',
            'lead'  => 'Тематические обучающие игры по школьным предметам. Это отдельное направление и не связано с сюжетной библиотекой «Переговори другого».',
            'items' => [],
        ],
    ];
}


/** Unified navigation targets for the three customer-facing product paths. */
function ckm_quiz_pro_games_catalog_url(string $anchor = ''): string {
    $id=(int)get_option('ckm_quiz_pro_page_games',0);
    $url=$id>0 && get_post($id) ? get_permalink($id) : home_url('/games/');
    if (function_exists('ckmqp_tenant_link')) $url=ckmqp_tenant_link($url,ckmqp_scope_id());
    return $anchor!=='' ? rtrim($url,'/').'/#'.ltrim($anchor,'#') : $url;
}

function ckm_quiz_pro_create_own_game_url(): string {
    return ckm_quiz_pro_organizer_url(['view'=>'builder']);
}


function ckm_quiz_pro_persuade_library_url(): string {
    return ckm_quiz_pro_organizer_url(['view'=>'persuade-library']);
}

function ckm_quiz_pro_scenario_order_url(): string {
    return ckm_quiz_pro_organizer_url(['view'=>'scenario-order']);
}

function ckm_quiz_pro_negotiation_master_url(array $args = []): string {
    $id=(int)get_option('ckm_neg_session_page_id',0);
    $url=$id>0 && get_post($id) ? get_permalink($id) : home_url('/ckm-negotiation-master/');
    if(function_exists('ckmqp_tenant_link')) $url=ckmqp_tenant_link($url,ckmqp_scope_id());
    return $args ? add_query_arg($args,$url) : $url;
}


function ckm_quiz_pro_education_library_url(array $args = []): string {
    return ckm_quiz_pro_organizer_url(array_merge(['view'=>'education-library'], $args));
}

/**
 * Educational catalog hierarchy: subject -> grade -> theme -> delivery mode.
 * The theme list is intentionally compact starter metadata; question banks are
 * created/installed separately and can reuse the same hierarchy.
 */
function ckm_quiz_pro_education_subject_registry(): array {
    return [
        'math'=>[
            'title'=>'Математика','icon'=>'∑','grades'=>[
                5=>['Обыкновенные дроби','Проценты'],
                6=>['Отношения и пропорции','Рациональные числа'],
                7=>['Линейные уравнения','Функции'],
                8=>['Квадратные уравнения','Геометрия'],
                9=>['Системы уравнений','Вероятность и статистика'],
                10=>['Тригонометрия','Стереометрия'],
                11=>['Производная','Вероятность и статистика'],
            ],
        ],
        'russian'=>[
            'title'=>'Русский язык','icon'=>'А','grades'=>[
                5=>['Орфография','Части речи'],
                6=>['Морфология','Словообразование'],
                7=>['Причастие и деепричастие','Служебные части речи'],
                8=>['Простое предложение','Односоставные предложения'],
                9=>['Сложное предложение','Пунктуация'],
                10=>['Лексика и культура речи','Орфография и пунктуация'],
                11=>['Текст и речевые нормы','Итоговое повторение'],
            ],
        ],
        'history'=>[
            'title'=>'История','icon'=>'⌛','grades'=>[
                5=>['Древний мир'],6=>['Средние века'],7=>['Россия XVI–XVII веков'],
                8=>['Россия XVIII–XIX веков'],9=>['Россия XX века'],
                10=>['История России до начала XX века'],11=>['Россия и мир в XX–XXI веках'],
            ],
        ],
        'social'=>[
            'title'=>'Обществознание','icon'=>'§','grades'=>[
                6=>['Человек и общество'],7=>['Социальные отношения'],8=>['Экономика'],
                9=>['Право'],10=>['Общество и культура'],11=>['Экономика, политика и право'],
            ],
        ],
        'biology'=>[
            'title'=>'Биология','icon'=>'◉','grades'=>[
                5=>['Живые организмы'],6=>['Растения'],7=>['Животные'],8=>['Человек и его организм'],
                9=>['Общая биология'],10=>['Клетка и наследственность'],11=>['Эволюция и экология'],
            ],
        ],
        'geography'=>[
            'title'=>'География','icon'=>'◎','grades'=>[
                5=>['Географическая карта'],6=>['Оболочки Земли'],7=>['Материки и океаны'],
                8=>['География России'],9=>['Население и хозяйство России'],10=>['Экономическая география мира'],11=>['Глобальные процессы'],
            ],
        ],
        'physics'=>[
            'title'=>'Физика','icon'=>'⚙','grades'=>[
                7=>['Механические явления'],8=>['Тепловые и электрические явления'],9=>['Механика'],
                10=>['Молекулярная физика и электродинамика'],11=>['Колебания, волны и квантовая физика'],
            ],
        ],
        'chemistry'=>[
            'title'=>'Химия','icon'=>'⚗','grades'=>[
                8=>['Строение вещества и химические реакции'],9=>['Неорганическая химия'],
                10=>['Органическая химия'],11=>['Общая химия'],
            ],
        ],
    ];
}

function ckm_quiz_pro_education_mode_registry(): array {
    return [
        'classic'=>[
            'title'=>'Классический квиз',
            'description'=>'Последовательные вопросы для повторения, тренировки и проверки знаний по теме.',
            'badge'=>'Проверка знаний',
            'format'=>'classic_quiz',
            'product'=>'classic_quiz',
            'icon'=>'?',
        ],
        'battle'=>[
            'title'=>'Интеллектуальный батл',
            'description'=>'Категории и номиналы: команды выбирают тему и сложность вопроса и строят стратегию игры.',
            'badge'=>'Игровое соревнование',
            'format'=>'jeopardy',
            'product'=>'jeopardy_v1',
            'icon'=>'★',
        ],
        'learning'=>[
            'title'=>'Обучающий формат',
            'description'=>'Учебный цикл: вопрос на понимание → пояснение → следующий вопрос на применение знания.',
            'badge'=>'Вопрос → объяснение → применение',
            'format'=>'classic_quiz',
            'product'=>'classic_quiz',
            'icon'=>'→',
        ],
    ];
}

function ckm_quiz_pro_education_selection(): array {
    $subjects=ckm_quiz_pro_education_subject_registry();
    $subject=sanitize_key((string)($_GET['edu_subject'] ?? 'math'));
    if(!isset($subjects[$subject])) $subject='math';
    $grades=array_keys((array)$subjects[$subject]['grades']);
    $grade=(int)($_GET['edu_grade'] ?? ($grades[0] ?? 5));
    if(!isset($subjects[$subject]['grades'][$grade])) $grade=(int)($grades[0] ?? 5);
    $themes=(array)$subjects[$subject]['grades'][$grade];
    $theme=sanitize_text_field(wp_unslash((string)($_GET['edu_theme'] ?? ($themes[0] ?? ''))));
    if($theme==='' || !in_array($theme,$themes,true)) $theme=(string)($themes[0] ?? '');
    return compact('subject','grade','theme','subjects','themes');
}

function ckm_quiz_pro_education_mode_action(string $modeKey, array $selection): array {
    $modes=ckm_quiz_pro_education_mode_registry();
    $mode=$modes[$modeKey] ?? $modes['classic'];
    $uid=is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize()
        ? ckm_quiz_pro_effective_organizer_user_id() : 0;
    $product=(string)$mode['product'];
    if($uid>0 && ckm_quiz_pro_can_access_format($uid,$product)){
        return [
            'state'=>'owned',
            'label'=>'Создать тематическую игру',
            'url'=>ckm_quiz_pro_organizer_url([
                'view'=>'builder','format'=>(string)$mode['format'],'education_mode'=>$modeKey,
                'edu_subject'=>(string)$selection['subject'],'edu_grade'=>(int)$selection['grade'],'edu_theme'=>(string)$selection['theme'],
            ]),
        ];
    }
    if($uid>0){
        return ['state'=>'pay','label'=>'Открыть доступ к формату','url'=>ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$product])];
    }
    return ['state'=>'login','label'=>'Войти организатором','url'=>ckm_quiz_pro_login_url()];
}

function ckm_quiz_pro_render_education_library(): void {
    $sel=ckm_quiz_pro_education_selection();
    $subjects=$sel['subjects'];
    $current=$subjects[$sel['subject']];
    echo '<div class="ckm-head"><div><div class="ckm-kicker">ОБРАЗОВАТЕЛЬНЫЕ ИГРЫ</div><h1>Тематические игры по школьным предметам</h1><p class="ckm-muted">Один тематический материал можно проводить в трёх режимах. Структура каталога: предмет → класс → тема → режим игры.</p></div></div>';
    echo '<section class="ckm-card"><form method="get" class="ckm-form-grid">';
    echo '<input type="hidden" name="view" value="education-library">';
    echo '<label class="ckm-label">Предмет<select class="ckm-input" name="edu_subject" onchange="this.form.submit()">';
    foreach($subjects as $key=>$subject) echo '<option value="'.esc_attr($key).'" '.selected($sel['subject'],$key,false).'>'.esc_html($subject['title']).'</option>';
    echo '</select></label>';
    echo '<label class="ckm-label">Класс<select class="ckm-input" name="edu_grade" onchange="this.form.submit()">';
    foreach(array_keys((array)$current['grades']) as $grade) echo '<option value="'.(int)$grade.'" '.selected((int)$sel['grade'],(int)$grade,false).'>'.(int)$grade.' класс</option>';
    echo '</select></label>';
    echo '<label class="ckm-label">Тема<select class="ckm-input" name="edu_theme">';
    foreach($sel['themes'] as $theme) echo '<option value="'.esc_attr($theme).'" '.selected($sel['theme'],$theme,false).'>'.esc_html($theme).'</option>';
    echo '</select></label>';
    echo '<div class="ckm-label">&nbsp;<button class="ckm-btn ckm-btn-primary" type="submit">Показать режимы</button></div>';
    echo '</form></section>';

    echo '<div class="ckm-card"><div class="ckm-kicker">ВЫБРАННАЯ ТЕМА</div><h2>'.esc_html($current['title']).' · '.(int)$sel['grade'].' класс · '.esc_html($sel['theme']).'</h2><p class="ckm-muted">Вопросы, ответы и объяснения хранятся как единый тематический материал. Меняется способ проведения игры.</p></div>';
    echo '<div class="ckm-grid ckm-catalog-grid">';
    foreach(ckm_quiz_pro_education_mode_registry() as $modeKey=>$mode){
        $action=ckm_quiz_pro_education_mode_action((string)$modeKey,$sel);
        echo '<article class="ckm-card ckm-format-card"><div class="ckm-format-top"><span class="ckm-format-icon">'.esc_html($mode['icon']).'</span><span class="ckm-badge">'.esc_html($mode['badge']).'</span></div>';
        echo '<h2>'.esc_html($mode['title']).'</h2><p class="ckm-muted">'.esc_html($mode['description']).'</p>';
        if($modeKey==='learning') echo '<p class="ckm-format-audience"><span>Методика:</span> базовый вопрос проверяет понимание, поле «Пояснение после ответа» даёт разбор, следующий вопрос требует применить полученное знание.</p>';
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($action['url']).'">'.esc_html($action['label']).'</a></div></article>';
    }
    echo '</div>';
    echo '<section class="ckm-card"><div class="ckm-kicker">ЕДИНАЯ МОДЕЛЬ КОНТЕНТА</div><h2>Один банк заданий — три способа проведения</h2><p class="ckm-muted">Для тематического материала сохраняются предмет, класс, тема, категория, сложность, вопрос, правильный ответ и пояснение. Поэтому один и тот же материал можно переиспользовать в квизе, батле и обучающем формате без создания трёх независимых библиотек.</p></section>';
}


/** Fragments are browser-only: keep old custom-game bookmarks working. */
function ckm_quiz_pro_custom_game_legacy_route(): void {
    $url = wp_json_encode(ckm_quiz_pro_create_own_game_url(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    echo '<script>(function(){function route(){if(location.hash!=="#ckm-custom-game")return;var target=new URL('.$url.',location.href);if(location.pathname===target.pathname && new URLSearchParams(location.search).get("view")==="builder")return;location.replace(target.href);}route();window.addEventListener("hashchange",route);})();</script>';
}

function ckm_quiz_pro_architecture_links(): array {
    return [
        'business'=>['label'=>'Деловые игры','url'=>ckm_quiz_pro_games_catalog_url('ckm-business-games')],
        'intellectual'=>['label'=>'Интеллектуальные игры','url'=>ckm_quiz_pro_games_catalog_url('ckm-intellectual-games')],
        'education'=>['label'=>'Образовательные игры','url'=>ckm_quiz_pro_education_library_url()],
        'scenarios'=>['label'=>'Каталог готовых игр','url'=>ckm_quiz_pro_persuade_library_url()],
        'custom'=>['label'=>'Создать свою игру','url'=>ckm_quiz_pro_create_own_game_url()],
    ];
}

function ckm_quiz_pro_render_architecture_nav(bool $showAccount = true): void {
    $links=ckm_quiz_pro_architecture_links();
    $home=function_exists('ckmqp_tenant_link') ? ckmqp_tenant_link(home_url('/'),ckmqp_scope_id()) : home_url('/');
    echo '<div class="ckm-architecture-nav"><a class="ckm-architecture-brand" href="'.esc_url($home).'">ЦКМ</a><nav>';
    foreach($links as $link) echo '<a href="'.esc_url($link['url']).'">'.esc_html($link['label']).'</a>';
    echo '</nav>';
    if ($showAccount && function_exists('ckm_quiz_pro_can_organize')) {
        if (is_user_logged_in() && ckm_quiz_pro_can_organize()) echo '<a class="ckm-architecture-account" href="'.esc_url(ckm_quiz_pro_organizer_url()).'">Кабинет</a>';
        elseif (function_exists('ckm_quiz_pro_login_url')) echo '<a class="ckm-architecture-account" href="'.esc_url(ckm_quiz_pro_login_url()).'">Войти</a>';
    }
    echo '</div>';
}

function ckm_quiz_pro_catalog_item_action(string $runtime, array $item, bool $organizerContext = false): array {
    if (!empty($item['effective_sales'])) {
        $product=sanitize_key((string)($item['product'] ?? 'negotiation_duel_v1'));
        $uid=is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize() ? ckm_quiz_pro_effective_organizer_user_id() : 0;
        if($uid>0 && ckm_quiz_pro_can_access_format($uid,$product)) return ['url'=>ckm_quiz_pro_effective_sales_url(),'label'=>'Открыть тренировки','state'=>'owned'];
        if($uid>0){
            $life=function_exists('ckm_quiz_pro_game_access_lifecycle_info') ? ckm_quiz_pro_game_access_lifecycle_info($uid,$product) : [];
            if(!empty($life['expired'])) return ['url'=>ckm_quiz_pro_access_renew_url($product),'label'=>'Возобновить на 30 дней','state'=>'expired','access_info'=>$life];
            return ['url'=>ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$product]),'label'=>'Открыть доступ','state'=>'buy'];
        }
        return ['url'=>ckm_quiz_pro_login_url(['buy'=>$product]),'label'=>'Выбрать игру','state'=>'login'];
    }
    if (!empty($item['negotiation_master'])) {
        $product=sanitize_key((string)($item['product'] ?? 'negotiation_duel_v1'));
        $uid=is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize() ? ckm_quiz_pro_effective_organizer_user_id() : 0;
        if($uid>0 && ckm_quiz_pro_can_access_format($uid,$product)) return ['url'=>ckm_quiz_pro_negotiation_master_url(),'label'=>'Открыть сценарии','state'=>'owned'];
        if($uid>0){
            $life=function_exists('ckm_quiz_pro_game_access_lifecycle_info') ? ckm_quiz_pro_game_access_lifecycle_info($uid,$product) : [];
            if(!empty($life['expired'])) return ['url'=>ckm_quiz_pro_access_renew_url($product),'label'=>'Возобновить на 30 дней','state'=>'expired','access_info'=>$life];
            return ['url'=>ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$product]),'label'=>'Открыть доступ','state'=>'buy'];
        }
        return ['url'=>ckm_quiz_pro_login_url(['buy'=>$product]),'label'=>'Выбрать игру','state'=>'login'];
    }
    if (!empty($item['scenario_library'])) {
        if (is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize()) {
            return ['url'=>ckm_quiz_pro_persuade_library_url(),'label'=>'Выбрать сценарий','state'=>'library'];
        }
        return ['url'=>ckm_quiz_pro_login_url(),'label'=>'Выбрать сценарий','state'=>'login'];
    }
    $product = sanitize_key((string)($item['product'] ?? ''));
    $uid = is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize()
        ? ckm_quiz_pro_effective_organizer_user_id() : 0;
    $hasAccess = $uid > 0 && $product !== '' && ckm_quiz_pro_can_access_format($uid, $product);

    if ($hasAccess) {
        return [
            'url'=>ckm_quiz_pro_organizer_url(['view'=>'new-game','ckm_format'=>$runtime]),
            'label'=>'Создать игру',
            'state'=>'owned',
        ];
    }
    if ($uid > 0) {
        $life=function_exists('ckm_quiz_pro_game_access_lifecycle_info') ? ckm_quiz_pro_game_access_lifecycle_info($uid,$product) : [];
        if (!empty($life['expired'])) {
            return [
                'url'=>ckm_quiz_pro_access_renew_url($product),
                'label'=>'Возобновить на 30 дней',
                'state'=>'expired',
                'access_info'=>$life,
            ];
        }
        return [
            'url'=>ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$product]),
            'label'=>'Открыть доступ',
            'state'=>'buy',
        ];
    }
    return [
        'url'=>ckm_quiz_pro_login_url(['buy'=>$product]),
        'label'=>'Выбрать игру',
        'state'=>'login',
    ];
}

function ckm_quiz_pro_render_games_catalog(bool $organizerContext = false): void {
    ckm_quiz_pro_custom_game_legacy_route();
    $registry = ckm_quiz_pro_games_catalog_registry();
    $uid = is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize() ? ckm_quiz_pro_effective_organizer_user_id() : 0;

    $renderCard = static function(string $runtime, array $item) use ($organizerContext, $uid): void {
        $action = ckm_quiz_pro_catalog_item_action($runtime, $item, $organizerContext);
        echo '<article class="ckm-card ckm-game-card ckm-format-card'.(!empty($item['badge'])?' is-featured':'').'">';
        echo '<div class="ckm-format-top"><span class="ckm-format-icon" aria-hidden="true">'.esc_html($item['icon']).'</span>';
        if (!empty($item['badge'])) echo '<span class="ckm-badge">'.esc_html($item['badge']).'</span>';
        echo '</div>';
        echo '<h2>'.esc_html($item['title']).'</h2>';
        echo '<p class="ckm-format-tagline"><strong>'.esc_html($item['tagline']).'</strong></p>';
        echo '<p class="ckm-muted">'.esc_html($item['description']).'</p>';
        echo '<p class="ckm-format-audience"><span>Подходит для:</span> '.esc_html($item['audience']).'</p>';
        $productKey = sanitize_key((string)($item['product'] ?? ''));
        if (($action['state'] ?? '') === 'library') {
            $accessInfo=[];
            echo '<p class="ckm-format-price">Бизнес-сценарий входит в «Переговорные поединки» · дополнительные сценарии оплачиваются отдельно</p>';
        } elseif (($action['state'] ?? '') === 'expired') {
            $accessInfo=is_array($action['access_info'] ?? null) ? $action['access_info'] : [];
            $expiredText=!empty($accessInfo['expired_display_text']) ? (string)$accessInfo['expired_display_text'] : 'Доступ истёк';
            echo '<p class="ckm-format-price is-expired">'.esc_html($expiredText).'</p>';
            echo '<p class="ckm-access-expired-note"><strong>Доступ завершён.</strong> Игры и результаты сохраняются; для новых запусков возобновите доступ.</p>';
        } elseif (($action['state'] ?? '') !== 'owned') {
            $products = ckm_quiz_pro_game_access_products();
            if ($productKey !== '' && isset($products[$productKey]['price'])) {
                echo '<p class="ckm-format-price">'.esc_html((string)$products[$productKey]['price']).' ₽ / 30 дней</p>';
            }
            $accessInfo = [];
        } else {
            $accessInfo = $uid > 0 && function_exists('ckm_quiz_pro_game_access_info') ? ckm_quiz_pro_game_access_info($uid,$productKey) : [];
            $accessText = !empty($accessInfo['display_text']) ? (string)$accessInfo['display_text'] : 'Доступ активен';
            echo '<p class="ckm-format-price is-owned'.(!empty($accessInfo['expiring_soon'])?' is-expiring':'').'">'.esc_html($accessText).'</p>';
            if (!empty($accessInfo['expiring_soon'])) echo '<p class="ckm-access-warning"><strong>Доступ скоро закончится.</strong> Продление добавит ещё 30 дней к текущему сроку.</p>';
        }
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($action['url']).'">'.esc_html($action['label']).'</a>';
        if (($action['state'] ?? '') === 'owned' && !empty($accessInfo['expiring_soon']) && function_exists('ckm_quiz_pro_access_renew_url')) {
            echo '<a class="ckm-btn" href="'.esc_url(ckm_quiz_pro_access_renew_url($productKey)).'">Продлить на 30 дней</a>';
        }
        echo '</div></article>';
    };

    echo '<div class="ckm-games-catalog">';
    echo '<div class="ckm-head ckm-catalog-head"><div><div class="ckm-kicker">БАЗОВЫЕ ИГРЫ</div><h1>Познакомьтесь с базовыми играми</h1><p class="ckm-muted">Это базовые версии игровых форматов ЦКМ для знакомства с их механикой. Организатор не может изменять их содержимое. После оплаты выбранного формата открываются запуски и возможность создать собственную игру с нуля.</p></div></div>';
    echo '<div class="ckm-catalog-tabs" role="navigation" aria-label="Категории игр"><a href="#ckm-business-games">Деловые игры</a><a href="#ckm-intellectual-games">Интеллектуальные игры</a><a href="#ckm-educational-games">Образовательные игры</a><a href="'.esc_url(ckm_quiz_pro_persuade_library_url()).'">Каталог готовых игр</a><a href="'.esc_url(ckm_quiz_pro_create_own_game_url()).'">Создать свою игру</a></div>';

    foreach ($registry as $groupKey=>$group) {
        $groupId = $groupKey === 'business' ? 'ckm-business-games' : ($groupKey === 'intellectual' ? 'ckm-intellectual-games' : 'ckm-educational-games');
        echo '<section class="ckm-catalog-section" id="'.esc_attr($groupId).'">';
        echo '<div class="ckm-catalog-section-head"><h2>'.esc_html($group['title']).'</h2><p class="ckm-muted">'.esc_html($group['lead']).'</p></div>';

        if ($groupKey === 'business') {
            $businessGroups = [
                [
                    'title'=>'Деловые переговоры',
                    'lead'=>'Четыре разные задачи: ответить, продать, убедить другого или договориться о пакете условий.',
                    'keys'=>['express_round_v1','sales_v1','persuade_me_v1','negotiation_master_v1'],
                ],
                [
                    'title'=>'Игра для развития навыка принятия управленческих решений',
                    'lead'=>'Практика анализа управленческой ситуации, выбора вариантов действий и оценки последствий.',
                    'keys'=>['decision_price_v1'],
                ],
            ];
            foreach ($businessGroups as $businessGroup) {
                echo '<div class="ckm-business-skill-group">';
                echo '<div class="ckm-business-skill-head"><h3>'.esc_html($businessGroup['title']).'</h3><p class="ckm-muted">'.esc_html($businessGroup['lead']).'</p></div>';
                echo '<div class="ckm-grid ckm-catalog-grid">';
                foreach ($businessGroup['keys'] as $runtime) {
                    if (!isset($group['items'][$runtime])) continue;
                    $renderCard($runtime, $group['items'][$runtime]);
                }
                echo '</div></div>';
            }
        } elseif ($groupKey === 'education') {
            echo '<div class="ckm-card ckm-education-placeholder"><h3>Предмет → класс → тема → режим</h3><p class="ckm-muted">Тематические игры организованы по школьным предметам и классам. Для каждой темы доступны три способа проведения: «Классический квиз», «Интеллектуальный батл» и «Обучающий формат».</p><div class="ckm-subject-chips"><span>Классический квиз</span><span>Интеллектуальный батл</span><span>Обучающий формат</span></div><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_education_library_url()).'">Открыть образовательную библиотеку</a></div></div>';
        } else {
            echo '<div class="ckm-grid ckm-catalog-grid">';
            foreach ($group['items'] as $runtime=>$item) $renderCard((string)$runtime, $item);
            echo '</div>';
        }
        echo '</section>';
    }

    echo '<section class="ckm-card ckm-custom-game-card" id="ckm-custom-game"><div><div class="ckm-kicker">СВОЙ СЦЕНАРИЙ</div><h2>Создайте свою игру</h2><p class="ckm-muted">Используйте собственные вопросы, кейсы, роли, изображения, видео и правила. Готовые форматы остаются основой, но сценарий и контент — ваши.</p></div>';
    if (is_user_logged_in() && function_exists('ckm_quiz_pro_can_use_front_constructor') && ckm_quiz_pro_can_use_front_constructor()) {
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_organizer_url(['view'=>'builder'])).'">Создать свою игру</a></div>';
    } elseif (is_user_logged_in() && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize()) {
        echo '<div class="ckm-card-actions"><span class="ckm-muted">Оплатите или продлите нужный формат — его конструктор откроется автоматически.</span></div>';
    } else {
        echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_create_own_game_url()).'">Войти организатором</a></div>';
    }
    echo '</section></div>';
}


function ckm_quiz_pro_persuade_scenario_registry(): array {
    // Backward-compatible function name; the source of truth is now the
    // administrator-managed catalogue of finished games.
    return function_exists('ckm_quiz_pro_ready_games_registry')
        ? ckm_quiz_pro_ready_games_registry()
        : [];
}


function ckm_quiz_pro_persuade_library_action(string $runtime, array $item, int $uid, array $products): array {
    $product=(string)($item['product'] ?? '');
    $owned=$uid>0 && $product!=='' && ckm_quiz_pro_can_access_format($uid,$product);
    $price=(int)($products[$product]['price'] ?? 0);
    if($owned){
        // Purchased catalogue games launch from the organizer's frozen copy,
        // not from the administrator's mutable source template.
        $quizId=function_exists('ckm_quiz_pro_ready_game_snapshot_ensure')
            ? ckm_quiz_pro_ready_game_snapshot_ensure($uid,$product)
            : max(0,(int)($item['quiz_id'] ?? 0));
        return [
            'url'=>$quizId>0
                ? ckm_quiz_pro_organizer_url(['view'=>'new-game','quiz'=>$quizId])
                : ckm_quiz_pro_persuade_library_url(),
            'label'=>$quizId>0?'Запустить игру':'Подготовить копию',
            'owned'=>true,
            'snapshot_ready'=>$quizId>0,
            'quiz_id'=>$quizId,
            'price'=>$price,
        ];
    }
    $life=$uid>0 && $product!=='' && function_exists('ckm_quiz_pro_game_access_lifecycle_info') ? ckm_quiz_pro_game_access_lifecycle_info($uid,$product) : [];
    if(!empty($life['expired'])){
        return [
            'url'=>ckm_quiz_pro_access_renew_url($product),
            'label'=>'Возобновить на 30 дней',
            'owned'=>false,
            'expired'=>true,
            'price'=>$price,
        ];
    }
    return [
        'url'=>$uid>0 ? ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>$product]) : ckm_quiz_pro_login_url(['buy'=>$product]),
        'label'=>'Открыть доступ',
        'owned'=>false,
        'price'=>$price,
    ];
}

function ckm_quiz_pro_render_persuade_library_detail(string $runtime, array $item, int $uid, array $products): void {
    $action=ckm_quiz_pro_persuade_library_action($runtime,$item,$uid,$products);
    $back=ckm_quiz_pro_persuade_library_url();
    echo '<div class="ckm-head"><div><div class="ckm-kicker">КАТАЛОГ ГОТОВЫХ ИГР · '.esc_html((string)($item['category'] ?? 'Игра')).'</div><h1>'.esc_html((string)$item['title']).'</h1><p class="ckm-muted">'.esc_html((string)$item['full_title']).'</p></div></div>';
    echo '<div class="ckm-library-detail">';
    echo '<section class="ckm-card ckm-library-detail-main"><div class="ckm-format-top"><span class="ckm-format-icon">'.esc_html((string)($item['icon'] ?? '◆')).'</span><span class="ckm-badge">'.esc_html((string)($item['category'] ?? 'Игра')).'</span></div>';
    echo '<h2>Ситуация</h2><p>'.esc_html((string)($item['situation'] ?? $item['description'] ?? '')).'</p>';
    if(!empty($item['roles']) && is_array($item['roles'])){
        echo '<h2>Роли</h2><div class="ckm-library-role-grid">';
        foreach($item['roles'] as $role=>$description){
            echo '<div class="ckm-library-role"><strong>'.esc_html((string)$role).'</strong><p>'.esc_html((string)$description).'</p></div>';
        }
        echo '</div>';
    }
    if(!empty($item['rounds']) && is_array($item['rounds'])){
        echo '<h2>Этапы / раунды</h2><ol class="ckm-library-rounds">';
        foreach($item['rounds'] as $round=>$description){
            echo '<li><strong>'.esc_html((string)$round).'</strong><span>'.esc_html((string)$description).'</span></li>';
        }
        echo '</ol>';
    }
    if(!empty($item['rules'])) echo '<h2>Как определяется результат</h2><p>'.nl2br(esc_html((string)$item['rules'])).'</p>';
    echo '</section>';
    echo '<aside class="ckm-card ckm-library-detail-aside"><h2>Игра</h2><p class="ckm-muted">'.esc_html((string)$item['description']).'</p><p class="ckm-format-audience"><span>Подходит для:</span> '.esc_html((string)$item['audience']).'</p>';
    if(!empty($action['owned'])){
        $info=ckm_quiz_pro_game_access_info($uid,(string)$item['product']);
        echo '<p class="ckm-format-price is-owned">'.esc_html($info['display_text'] ?: 'Доступ активен').'</p>';
    } elseif(($action['price'] ?? 0)>0){
        echo '<p class="ckm-format-price">'.(int)$action['price'].' ₽ / 30 дней</p>';
    }
    echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url((string)$action['url']).'">'.esc_html((string)$action['label']).'</a><a class="ckm-btn" href="'.esc_url($back).'">Назад в каталог</a></div></aside>';
    echo '</div>';
}

function ckm_quiz_pro_render_persuade_library(): void {
    $uid=ckm_quiz_pro_effective_organizer_user_id();
    $products=ckm_quiz_pro_game_access_products();
    $registry=ckm_quiz_pro_persuade_scenario_registry();
    $selected=isset($_GET['game']) ? sanitize_key((string)wp_unslash($_GET['game'])) : '';
    if($selected!=='' && isset($registry[$selected])){
        ckm_quiz_pro_render_persuade_library_detail($selected,$registry[$selected],$uid,$products);
        return;
    }
    $groups=[];
    foreach($registry as $runtime=>$item){
        $category=trim((string)($item['category'] ?? 'Другие игры')) ?: 'Другие игры';
        if(!isset($groups[$category])) $groups[$category]=[];
        $groups[$category][$runtime]=$item;
    }

    echo '<div class="ckm-head"><div><div class="ckm-kicker">КАТАЛОГ ГОТОВЫХ ИГР</div><h1>Готовые игры</h1><p class="ckm-muted">Законченные игры с готовым сюжетом и содержанием. Их добавляет и редактирует только администратор сайта. Организатор может купить доступ и запустить игру, но не изменять её.</p></div></div>';

    if(!$groups){
        echo '<div class="ckm-card"><h2>Готовых игр пока нет</h2><p class="ckm-muted">Администратор добавляет их в WordPress: Игровая платформа → Каталог готовых игр.</p></div>';
        return;
    }

    if(count($groups)>1){
        echo '<nav class="ckm-library-category-nav" aria-label="Разделы каталога">';
        foreach(array_keys($groups) as $category){
            $anchor='ckm-library-'.sanitize_title($category);
            echo '<a href="#'.esc_attr($anchor).'">'.esc_html($category).'</a>';
        }
        echo '</nav>';
    }

    foreach($groups as $category=>$items){
        $anchor='ckm-library-'.sanitize_title($category);
        echo '<section class="ckm-library-group" id="'.esc_attr($anchor).'"><div class="ckm-library-group-head"><h2>'.esc_html($category).'</h2><span>'.count($items).' '.(count($items)===1?'игра':'игры').'</span></div><div class="ckm-grid ckm-catalog-grid">';
        foreach($items as $runtime=>$item){
            $product=(string)$item['product'];
            $action=ckm_quiz_pro_persuade_library_action($runtime,$item,$uid,$products);
            $owned=!empty($action['owned']);
            $price=(int)($action['price'] ?? 0);
            $url=(string)$action['url'];
            $label=(string)$action['label'];
            echo '<article class="ckm-card ckm-format-card"><div class="ckm-format-top"><span class="ckm-format-icon">'.esc_html($item['icon']).'</span>';
            if(!empty($item['included'])) echo '<span class="ckm-badge">Базовая игра</span>';
            else echo '<span class="ckm-badge">'.esc_html($category).'</span>';
            echo '</div><h3>'.esc_html($item['title']).'</h3><p class="ckm-format-tagline"><strong>'.esc_html($item['full_title']).'</strong></p><p class="ckm-muted">'.esc_html($item['description']).'</p><p class="ckm-format-audience"><span>Подходит для:</span> '.esc_html($item['audience']).'</p>';
            if($owned){
                $info=ckm_quiz_pro_game_access_info($uid,$product);
                echo '<p class="ckm-format-price is-owned">'.esc_html($info['display_text'] ?: 'Доступ активен').'</p>';
            } elseif($price>0) {
                echo '<p class="ckm-format-price">'.$price.' ₽ / 30 дней</p>';
            }
            $detailUrl=add_query_arg('game',$runtime,ckm_quiz_pro_persuade_library_url());
            echo '<div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url($url).'">'.esc_html($label).'</a><a class="ckm-btn" href="'.esc_url($detailUrl).'">Подробнее</a></div></article>';
        }
        echo '</div></section>';
    }

    echo '<section class="ckm-card ckm-custom-game-card"><div><div class="ckm-kicker">ИНДИВИДУАЛЬНЫЙ СЮЖЕТ</div><h2>Сценарий под заказ</h2><p class="ckm-muted">Опишите аудиторию, тему и типичные ситуации. Механика «Переговори другого» останется прежней, а сюжет будет разработан под вашу задачу.</p></div><div class="ckm-card-actions"><a class="ckm-btn ckm-btn-primary" href="'.esc_url(ckm_quiz_pro_scenario_order_url()).'">Сценарий под заказ</a></div></section>';
}

function ckm_quiz_pro_games_catalog_inline_css(): string {
    return '.ckm-architecture-nav{display:flex;align-items:center;gap:18px;min-height:58px;margin:0 auto 26px;padding:10px 0;border-bottom:1px solid var(--line,#243b5c)}.ckm-architecture-brand{font-family:"Caveat","Bad Script",cursive;font-size:28px;font-weight:900;color:var(--text,#eef5ff);text-decoration:none}.ckm-architecture-nav nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex:1}.ckm-architecture-nav nav a,.ckm-architecture-account{padding:8px 11px;border-radius:9px;color:var(--muted,#9eb0cb);text-decoration:none}.ckm-architecture-nav nav a:hover,.ckm-architecture-account:hover{background:var(--panel,#0c1829);color:var(--text,#eef5ff)}.ckm-architecture-account{border:1px solid var(--line,#243b5c);color:var(--text,#eef5ff)}.ckm-games-catalog{max-width:1280px;margin:0 auto}.ckm-catalog-head{margin-bottom:14px}.ckm-catalog-tabs{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 30px}.ckm-catalog-tabs a{display:inline-flex;padding:9px 13px;border:1px solid var(--line,#243b5c);border-radius:999px;text-decoration:none;color:var(--text,#eef5ff);background:var(--panel,#0c1829)}.ckm-catalog-section{scroll-margin-top:20px;margin:0 0 42px}.ckm-catalog-section-head{margin-bottom:16px}.ckm-catalog-section-head h2{font-size:32px;margin:0 0 4px}.ckm-business-skill-group{margin:0 0 30px;padding:20px;border:1px solid var(--line,#243b5c);border-radius:16px;background:rgba(12,24,41,.38)}.ckm-business-skill-group:last-child{margin-bottom:0}.ckm-business-skill-head{margin:0 0 16px}.ckm-business-skill-head h3{margin:0 0 6px;font-size:23px;color:var(--text,#eef5ff)}.ckm-catalog-grid{align-items:stretch}.ckm-format-card{display:flex;flex-direction:column;min-height:100%}.ckm-format-card.is-featured{border-color:#567aa6;box-shadow:inset 0 0 0 1px rgba(124,180,255,.12)}.ckm-format-top{display:flex;justify-content:space-between;align-items:center;gap:10px;min-height:34px}.ckm-format-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#132843;border:1px solid #29486c;font-weight:900}.ckm-format-tagline{line-height:1.45;margin:2px 0 8px}.ckm-format-audience{font-size:13px;line-height:1.5;color:var(--muted,#9eb0cb);margin-top:14px}.ckm-format-audience span{color:var(--text,#eef5ff);font-weight:700}.ckm-format-price{font-size:13px;font-weight:800;margin:6px 0 0;color:#dbeaff}.ckm-format-price.is-owned{color:#8ee7ae}.ckm-format-price.is-owned.is-expiring{color:#ffd58a}.ckm-format-price.is-expired{color:#ff8f8f}.ckm-access-expired-note{font-size:13px;line-height:1.45;margin:7px 0 0;color:#ffaaaa}.ckm-access-warning{font-size:13px;line-height:1.45;margin:7px 0 0;color:#ffd58a}.ckm-format-card .ckm-card-actions{margin-top:auto;padding-top:18px}.ckm-custom-game-card{display:flex;align-items:center;justify-content:space-between;gap:24px;margin-top:12px}.ckm-custom-game-card>div:first-child{max-width:820px}@media(max-width:760px){.ckm-architecture-nav{align-items:flex-start;flex-wrap:wrap}.ckm-architecture-nav nav{order:3;width:100%}.ckm-architecture-account{margin-left:auto}.ckm-custom-game-card{align-items:flex-start;flex-direction:column}.ckm-catalog-section-head h2{font-size:28px}}.ckm-subject-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.ckm-subject-chips span{display:inline-flex;padding:7px 10px;border:1px solid var(--line,#243b5c);border-radius:999px;color:var(--muted,#9eb0cb);background:var(--panel,#0c1829)}.ckm-library-category-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 28px}.ckm-library-category-nav a{display:inline-flex;padding:8px 12px;border:1px solid var(--line,#243b5c);border-radius:999px;background:var(--panel,#0c1829);color:var(--text,#eef5ff);text-decoration:none}.ckm-library-category-nav a:hover{border-color:#567aa6}.ckm-library-group{scroll-margin-top:20px;margin:0 0 38px}.ckm-library-group-head{display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin:0 0 14px}.ckm-library-group-head h2{margin:0;font-size:26px}.ckm-library-group-head span{color:var(--muted,#9eb0cb);font-size:13px}.ckm-library-group .ckm-format-card h3{font-size:24px;margin:14px 0 6px}.ckm-library-detail{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start}.ckm-library-detail-main h2{margin:24px 0 10px}.ckm-library-detail-main h2:first-of-type{margin-top:18px}.ckm-library-role-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ckm-library-role{padding:14px;border:1px solid var(--line,#243b5c);border-radius:12px;background:rgba(12,24,41,.38)}.ckm-library-role p{margin:7px 0 0;color:var(--muted,#9eb0cb);line-height:1.5}.ckm-library-rounds{display:grid;gap:10px;margin:0;padding-left:22px}.ckm-library-rounds li{padding-left:4px}.ckm-library-rounds li strong,.ckm-library-rounds li span{display:block}.ckm-library-rounds li span{margin-top:3px;color:var(--muted,#9eb0cb);line-height:1.5}.ckm-library-detail-aside{position:sticky;top:18px}.ckm-library-detail-aside .ckm-card-actions{display:flex;flex-direction:column;gap:9px}@media(max-width:900px){.ckm-library-detail{grid-template-columns:1fr}.ckm-library-detail-aside{position:static}}@media(max-width:760px){.ckm-library-group-head{align-items:flex-start;flex-direction:column;gap:4px}.ckm-library-role-grid{grid-template-columns:1fr}}';
}

/** Ensure a native /games/ page exists even on already-installed sites. */
function ckm_quiz_pro_games_catalog_install(): void {
    if (get_option('ckm_quiz_pro_games_catalog_version','') === '1') return;
    $id = (int)get_option('ckm_quiz_pro_page_games', 0);
    if ($id <= 0 || !get_post($id)) {
        $existing = get_page_by_path('games');
        if ($existing) {
            $id = (int)$existing->ID;
        } else {
            $id = wp_insert_post([
                'post_title'=>'Игры',
                'post_name'=>'games',
                'post_status'=>'publish',
                'post_type'=>'page',
                'post_content'=>'[ckm_quiz_store]',
            ]);
            if (is_wp_error($id)) $id = 0;
        }
        if ($id > 0) update_option('ckm_quiz_pro_page_games', $id, false);
    }
    update_option('ckm_quiz_pro_games_catalog_version','1',false);
}
add_action('init','ckm_quiz_pro_games_catalog_install',6);

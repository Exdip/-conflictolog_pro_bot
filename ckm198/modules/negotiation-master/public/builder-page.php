<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class BuilderPage {
    private const PAGE_OPTION='ckm_neg_builder_page_id';
    private const PAGE_VERSION_OPTION='ckm_neg_builder_page_version';
    private const PAGE_VERSION='5';

    public static function url(array $args=[]): string {
        $id=(int)get_option(self::PAGE_OPTION,0);
        $base=$id>0&&get_post($id)?(string)get_permalink($id):home_url('/ckm-negotiation-builder/');
        if(function_exists('ckmqp_tenant_link'))$base=ckmqp_tenant_link($base,ckmqp_scope_id());
        return $args?add_query_arg($args,$base):$base;
    }
    public static function register(): void { add_shortcode('ckm_negotiation_builder',[self::class,'render']); }
    public static function maybeInstall(): void { if(get_option(self::PAGE_VERSION_OPTION,'')!==self::PAGE_VERSION)self::install(); }
    public static function install(): void {
        $id=(int)get_option(self::PAGE_OPTION,0);
        if($id>0&&get_post($id)){update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $existing=get_page_by_path('ckm-negotiation-builder');
        if($existing){update_option(self::PAGE_OPTION,(int)$existing->ID,false);update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $id=wp_insert_post(['post_title'=>'Конструктор сценариев переговоров','post_name'=>'ckm-negotiation-builder','post_status'=>'publish','post_type'=>'page','post_content'=>'[ckm_negotiation_builder]']);
        if(!is_wp_error($id)&&(int)$id>0){update_option(self::PAGE_OPTION,(int)$id,false);update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);}
    }
    private static function enqueue(): void {
        $base=CKM_QUIZ_PRO_URL.'modules/negotiation-master/assets/';
        wp_enqueue_style('ckm-neg-session',$base.'negotiation-session.css',[],CKM_QUIZ_PRO_VERSION);
        wp_enqueue_style('ckm-neg-builder',$base.'negotiation-builder.css',['ckm-neg-session'],CKM_QUIZ_PRO_VERSION);
        wp_enqueue_script('ckm-neg-builder',$base.'negotiation-builder.js',[],CKM_QUIZ_PRO_VERSION,true);
        wp_localize_script('ckm-neg-builder','CKMNegBuilderConfig',[
            'restBase'=>esc_url_raw(rest_url('ckm/v1/negotiation/builder')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'catalogUrl'=>ProductCatalog::url(),
            'builderUrl'=>self::url(),
            'openScenarioId'=>isset($_GET['builder_scenario'])?absint($_GET['builder_scenario']):0,
        ]);
    }

    public static function render(): string {
        if(!is_user_logged_in())return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><h2>Конструктор сценариев</h2><p>Для работы необходимо войти.</p></div></div>';
        if(!ScenarioBuilderService::canUse()){
            $pay=function_exists('ckm_quiz_pro_organizer_url')?ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>'negotiation_duel_v1']):ProductCatalog::url();
            return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><div class="ckm-neg-kicker">Мастер переговоров</div><h2>Конструктор сценариев недоступен</h2><p>Для создания собственных сценариев нужен действующий доступ к «Мастеру переговоров» и к фронтенд-конструктору площадки.</p><a class="ckm-neg-btn ckm-neg-primary" href="'.esc_url($pay).'">Проверить доступ</a></div></div>';
        }
        self::enqueue();
        ob_start(); ?>
        <div class="ckm-neg-shell ckm-neg-builder-shell" id="ckm-neg-builder">
            <section class="ckm-neg-catalog-hero ckm-neg-card ckm-neg-builder-hero">
                <a class="ckm-neg-back" href="<?php echo esc_url(ProductCatalog::url()); ?>">← Мастер переговоров</a>
                <div class="ckm-neg-kicker">Конструктор</div>
                <h1>Сценарии «Мастера переговоров»</h1>
                <p class="ckm-neg-lead">Создавайте собственные переговорные ситуации без изменения PHP-кода игры. Черновик сохраняется автоматически и может быть протестирован до публикации. Опубликованные версии остаются неизменяемыми для уже начатых попыток.</p>
                <div class="ckm-neg-builder-actions"><button class="ckm-neg-btn ckm-neg-primary" type="button" id="ckm-neg-builder-new">Создать сценарий</button><a class="ckm-neg-btn" href="<?php echo esc_url(AssignmentPage::url()); ?>">Назначения участникам</a><button class="ckm-neg-btn" type="button" id="ckm-neg-builder-refresh">Обновить список</button></div>
            </section>

            <section id="ckm-neg-builder-home">
                <div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Мои сценарии</div><h2>Черновики и опубликованные версии</h2></div></div>
                <div class="ckm-neg-builder-grid" id="ckm-neg-builder-own"><div class="ckm-neg-muted">Загрузка…</div></div>
                <div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Использовать как основу</div><h2>Доступные системные сценарии</h2></div></div>
                <div class="ckm-neg-builder-grid" id="ckm-neg-builder-templates"></div>
            </section>

            <section id="ckm-neg-builder-editor" hidden>
                <div class="ckm-neg-card ckm-neg-builder-toolbar">
                    <div><button class="ckm-neg-btn" type="button" id="ckm-neg-builder-back">← Мои сценарии</button><span class="ckm-neg-builder-version" id="ckm-neg-builder-version"></span></div>
                    <div class="ckm-neg-builder-toolbar-actions"><button class="ckm-neg-btn" type="button" id="ckm-neg-builder-test">Тестировать черновик</button><button class="ckm-neg-btn" type="button" id="ckm-neg-builder-validate">Проверить</button><button class="ckm-neg-btn" type="button" id="ckm-neg-builder-save">Сохранить черновик</button><button class="ckm-neg-btn ckm-neg-primary" type="button" id="ckm-neg-builder-publish">Опубликовать</button></div>
                </div>
                <div class="ckm-neg-builder-validation" id="ckm-neg-builder-validation" hidden></div>
                <div class="ckm-neg-builder-tabs" role="tablist">
                    <button type="button" class="is-active" data-tab="situation">1. Ситуация</button>
                    <button type="button" data-tab="player">2. Участник</button>
                    <button type="button" data-tab="opponent">3. ИИ-оппонент</button>
                    <button type="button" data-tab="items">4. Предметы переговоров</button>
                    <button type="button" data-tab="rules">5. Правила и оценка</button>
                </div>
                <form class="ckm-neg-builder-form" id="ckm-neg-builder-form" onsubmit="return false;">
                    <section class="ckm-neg-builder-tab is-active" data-panel="situation">
                        <div class="ckm-neg-card"><h2>Ситуация</h2>
                            <label>Название<input id="nb-title" maxlength="255" required></label>
                            <label>Ситуация<textarea id="nb-situation" rows="6"></textarea></label>
                            <label>Задача участника<textarea id="nb-task" rows="5"></textarea></label>
                            <div class="ckm-neg-builder-section-head ckm-neg-known-head"><div><h3>Что известно участнику в начале</h3><p>Добавьте факты, которые игрок видит до начала переговоров. Каждый факт задаётся обычным названием и содержанием.</p></div><button class="ckm-neg-btn" type="button" data-add="known">+ Добавить известный факт</button></div>
                            <div id="nb-known-facts" class="ckm-neg-builder-repeater"></div>
                            <div id="nb-known-legacy-note" class="ckm-neg-builder-result-note" hidden><strong>Сложные данные старого шаблона сохранены.</strong><span>Они не удаляются при сохранении. Стартовые позиции по предметам переговоров редактируйте в разделе «4. Предметы переговоров».</span></div>
                        </div>
                        <div class="ckm-neg-card"><h2>Механика</h2><div class="ckm-neg-builder-checks"><label><input type="checkbox" id="nb-mode-training" checked> Тренировка</label><label><input type="checkbox" id="nb-mode-exam" checked> Экзамен</label><label><input type="checkbox" id="nb-voice" checked> Голосовой ввод</label><label><input type="checkbox" id="nb-walkaway" checked> Оппонент может завершить переговоры</label><label><input type="checkbox" id="nb-nodeal" checked> Разрешить завершение без соглашения</label></div></div>
                    </section>

                    <section class="ckm-neg-builder-tab" data-panel="player">
                        <div class="ckm-neg-card"><h2>Карточка участника</h2>
                            <label>Роль<textarea id="nb-player-role" rows="3"></textarea></label>
                            <div class="ckm-neg-builder-result-note">
                                <strong>Идеальный результат, цели и красные линии задаются по каждому предмету переговоров.</strong>
                                <span>Откройте раздел «4. Предметы переговоров». Старые дополнительные условия сценария сохраняются автоматически.</span>
                            </div>
                            <label>Что вы сделаете, если соглашение не будет достигнуто <small>Кратко опишите реальную альтернативу участника: другой клиент, перенос решения, отказ от сделки, эскалация и т. п.</small><textarea id="nb-player-alt-description" rows="5" placeholder="Например: отказаться от сделки и вернуться к переговорам с другим заказчиком."></textarea></label>
                            <div class="ckm-neg-builder-two ckm-neg-builder-alt-score">
                                <label class="ckm-neg-inline-check"><input type="checkbox" id="nb-player-alt-score-enabled"> Сравнивать итог сделки с альтернативой без соглашения</label>
                                <label>Ценность альтернативы, 0–100 <small>0 — крайне плохой исход, 100 — результат не хуже идеального. Сделка ниже этого уровня считается хуже доступной альтернативы.</small><input id="nb-player-alt-score" type="number" min="0" max="100" step="1" inputmode="decimal" placeholder="Например: 65" disabled></label>
                            </div>
                        </div>
                    </section>

                    <section class="ckm-neg-builder-tab" data-panel="opponent">
                        <div class="ckm-neg-card"><h2>ИИ-оппонент</h2><div class="ckm-neg-builder-two"><label>Имя<input id="nb-opponent-name" maxlength="255"></label><label>Роль<input id="nb-opponent-role" maxlength="500"></label></div>
                            <div class="ckm-neg-builder-two"><label>Стиль и манера переговоров <small>Как оппонент говорит, принимает решения и реагирует на аргументы.</small><textarea id="nb-opponent-style" rows="5" placeholder="Например: деловой, требовательный к обоснованию цены, готов обсуждать пакет условий."></textarea></label><label>Характерные черты <small>Каждая черта с новой строки.</small><textarea id="nb-opponent-traits" rows="5" placeholder="решительный&#10;осторожный к обещаниям&#10;ориентирован на результат"></textarea></label></div>
                            <div class="ckm-neg-builder-two"><label>Скрытые интересы и приоритеты <small>Каждый интерес с новой строки. Они не показываются участнику напрямую.</small><textarea id="nb-opponent-priorities" rows="6" placeholder="Снизить риск простоя&#10;Сохранить ликвидность"></textarea></label><label>Альтернатива без соглашения <small>Что оппонент реально сделает, если договориться не удастся.</small><textarea id="nb-opponent-alt-description" rows="6" placeholder="Например: вернуться к предложению другого поставщика."></textarea></label></div>
                            <div class="ckm-neg-builder-result-note"><strong>Открытая позиция и пространство уступок задаются по каждому предмету переговоров.</strong><span>В разделе «4. Предметы переговоров» укажите стартовую позицию ИИ-оппонента, его цель, допустимые границы и варианты уступок. Специальные поля старых шаблонов сохраняются автоматически.</span></div>
                            <div class="ckm-neg-builder-two"><label>Публичная позиция словами <small>Необязательно. Используйте, если у оппонента есть явное заявление, не сводимое к отдельному предмету.</small><textarea id="nb-opponent-statement" rows="4"></textarea></label><label>Дополнительные ограничения пакета <small>Каждое условие с новой строки. Границы отдельных предметов задаются в разделе 4.</small><textarea id="nb-opponent-package-constraints" rows="4"></textarea></label></div>
                            <div class="ckm-neg-builder-two"><label>Когда оппонент прекращает переговоры <textarea id="nb-opponent-walkaway-condition" rows="4" placeholder="Опишите поведение или пакет, после которого оппонент выходит из переговоров."></textarea></label><label>Сколько повторов нарушения допускается <small>Оставьте пустым, если точный порог не задан.</small><input id="nb-opponent-walkaway-repeat" type="number" min="1" step="1" inputmode="numeric" placeholder="Например: 2"></label></div>
                        </div>
                        <div class="ckm-neg-card"><div class="ckm-neg-builder-section-head"><div><h2>Скрытые факты</h2><p>Факты раскрываются игроку только по правилам сценария.</p></div><button class="ckm-neg-btn" type="button" data-add="fact">+ Добавить факт</button></div><div id="nb-facts" class="ckm-neg-builder-repeater"></div></div>
                    </section>

                    <section class="ckm-neg-builder-tab" data-panel="items">
                        <div class="ckm-neg-card"><div class="ckm-neg-builder-section-head"><div><h2>Предметы переговоров</h2><p>Цена, срок, ответственность, автономия и любые другие обсуждаемые параметры.</p></div><button class="ckm-neg-btn" type="button" data-add="item">+ Добавить предмет</button></div><div id="nb-items" class="ckm-neg-builder-repeater"></div></div>
                    </section>

                    <section class="ckm-neg-builder-tab" data-panel="rules">
                        <div class="ckm-neg-card"><div class="ckm-neg-builder-section-head"><div><h2>Правила</h2><p>Красные линии, зависимости, жёсткие ограничения и условия завершения.</p></div><button class="ckm-neg-btn" type="button" data-add="rule">+ Добавить правило</button></div><div id="nb-rules" class="ckm-neg-builder-repeater"></div></div>
                        <div class="ckm-neg-card"><div class="ckm-neg-builder-section-head"><div><h2>Критерии оценки</h2><p>Сумма весов опубликованного сценария должна быть ровно 100.</p></div><button class="ckm-neg-btn" type="button" data-add="evaluation">+ Добавить критерий</button></div><div id="nb-evaluation" class="ckm-neg-builder-repeater"></div><div class="ckm-neg-builder-weight">Сумма весов: <strong id="nb-weight-total">0</strong></div></div>
                    </section>
                </form>
                <div class="ckm-neg-card ckm-neg-builder-footer"><button class="ckm-neg-btn ckm-neg-danger" type="button" id="ckm-neg-builder-archive">Архивировать сценарий</button><a class="ckm-neg-btn" id="ckm-neg-builder-play" href="#" hidden>Открыть опубликованный сценарий</a><span class="ckm-neg-status" id="ckm-neg-builder-status"></span></div>
            </section>

            <template id="nb-known-template"><div class="ckm-neg-builder-row ckm-neg-known-row" data-kind="known"><button type="button" class="ckm-neg-builder-remove" title="Удалить">×</button><div class="ckm-neg-builder-two"><label>Название факта<input data-ui="known_label" placeholder="Например: Бюджет проекта"></label><label>Что известно участнику<textarea data-ui="known_value" rows="3" placeholder="Например: утверждённый бюджет — до 2 млн рублей."></textarea></label></div></div></template>
            <template id="nb-fact-template"><div class="ckm-neg-builder-row ckm-neg-hidden-fact-row" data-kind="fact"><button type="button" class="ckm-neg-builder-remove" title="Удалить">×</button><input type="hidden" data-f="code"><div class="ckm-neg-builder-three"><label>Название<input data-f="title"></label><label>Важность<input data-f="importance" type="number" min="0.1" step="0.1" value="1"></label><label>Что известно в начале<select data-f="initial_level"><option value="0">Скрыт</option><option value="1">Частично известен</option><option value="2">Полностью известен</option></select></label></div><label>Содержание скрытого факта<textarea data-f="content" rows="3"></textarea></label><input type="hidden" data-f="reveal_rules_json" class="is-json" value="{}"><div class="ckm-neg-builder-two ckm-neg-fact-reveal-grid"><label>Когда дать частичную информацию <small>Какой вопрос или ход участника должен показать, что он приблизился к этому факту.</small><textarea data-ui="fact_partial" rows="3" placeholder="Например: участник спросил об альтернативных предложениях."></textarea></label><label>Когда раскрыть факт полностью <small>Какой более точный вопрос или выяснение позволяет раскрыть содержание целиком.</small><textarea data-ui="fact_revealed" rows="3" placeholder="Например: участник уточнил сопоставимость альтернатив по цене и рискам."></textarea></label></div><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="fact_automatic"> Разрешить автоматическое раскрытие, если условие уверенно распознано</label></div></template>
            <template id="nb-item-template">
                <div class="ckm-neg-builder-row ckm-neg-item-row" data-kind="item">
                    <button type="button" class="ckm-neg-builder-remove" title="Удалить">×</button>
                    <input type="hidden" data-f="code">
                    <div class="ckm-neg-builder-three">
                        <label>Название<input data-f="title"></label>
                        <label>Тип<select data-f="value_type"><option value="money">Деньги</option><option value="number">Число</option><option value="integer">Целое число</option><option value="percent">Процент</option><option value="select">Выбор из списка</option><option value="boolean">Да / нет</option><option value="date">Дата</option><option value="term">Срок</option><option value="text">Текст</option></select></label>
                        <label>Единица<input data-f="unit" placeholder="руб."></label>
                    </div>
                    <input type="hidden" data-f="player_target_json" class="is-json" value="{}">
                    <input type="hidden" data-f="player_boundary_json" class="is-json" value="{}">
                    <input type="hidden" data-f="opponent_target_json" class="is-json" value="{}">
                    <input type="hidden" data-f="opponent_boundary_json" class="is-json" value="{}">
                    <div class="ckm-neg-item-sides">
                        <fieldset class="ckm-neg-item-side">
                            <legend>Участник</legend>
                            <div class="ckm-neg-builder-three ckm-neg-item-target-grid">
                                <label>Идеальный результат<input data-ui="player_ideal_value" placeholder="Лучший желаемый результат"></label>
                                <label>Цель<select data-ui="player_target_mode"><option value="">Не задана</option><option value="target">Целевое значение</option><option value="min">Не ниже</option><option value="max">Не выше</option><option value="allowed">Один из вариантов</option></select></label>
                                <label>Значение цели<input data-ui="player_target_value" placeholder="Например: 20 500 000"></label>
                            </div>
                            <div class="ckm-neg-item-subtitle-row"><p class="ckm-neg-item-subtitle">Красная линия участника</p><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="player_redline_enabled"> Считать эту границу красной линией</label></div>
                            <div class="ckm-neg-builder-four ckm-neg-item-boundary-grid">
                                <label>Минимально допустимо<input data-ui="player_boundary_min" placeholder="Не ниже"></label>
                                <label>Максимально допустимо<input data-ui="player_boundary_max" placeholder="Не выше"></label>
                                <label>Разрешённые варианты<input data-ui="player_boundary_allowed" placeholder="Варианты через запятую"></label>
                                <label>Обязательное значение<input data-ui="player_boundary_hard" placeholder="Если допустимо только одно"></label>
                            </div>
                        </fieldset>
                        <fieldset class="ckm-neg-item-side is-opponent">
                            <legend>ИИ-оппонент</legend>
                            <div class="ckm-neg-builder-two ckm-neg-item-opponent-opening">
                                <label>Стартовая позиция<input data-ui="opponent_opening_value" placeholder="С чего начинает торг"></label>
                                <label>Допустимые варианты уступки<input data-ui="opponent_concession_allowed" placeholder="Варианты через запятую"></label>
                            </div>
                            <div class="ckm-neg-builder-two ckm-neg-item-target-grid">
                                <label>Цель<select data-ui="opponent_target_mode"><option value="">Не задана</option><option value="target">Целевое значение</option><option value="min">Не ниже</option><option value="max">Не выше</option><option value="allowed">Один из вариантов</option></select></label>
                                <label>Значение цели<input data-ui="opponent_target_value" placeholder="Например: 19 500 000"></label>
                            </div>
                            <p class="ckm-neg-item-subtitle">Граница ИИ-оппонента</p>
                            <div class="ckm-neg-builder-four ckm-neg-item-boundary-grid">
                                <label>Минимально допустимо<input data-ui="opponent_boundary_min" placeholder="Не ниже"></label>
                                <label>Максимально допустимо<input data-ui="opponent_boundary_max" placeholder="Не выше"></label>
                                <label>Разрешённые варианты<input data-ui="opponent_boundary_allowed" placeholder="Варианты через запятую"></label>
                                <label>Обязательное значение<input data-ui="opponent_boundary_hard" placeholder="Если допустимо только одно"></label>
                            </div>
                        </fieldset>
                    </div>
                    <div class="ckm-neg-builder-four"><label>Предпочтение участника<select data-f="player_preference_direction"><option value="">—</option><option value="higher_better">Больше лучше</option><option value="lower_better">Меньше лучше</option><option value="target_value">Целевое значение</option><option value="categorical">Категориальное</option><option value="custom_rule">По правилу</option></select></label><label>Предпочтение оппонента<select data-f="opponent_preference_direction"><option value="">—</option><option value="higher_better">Больше лучше</option><option value="lower_better">Меньше лучше</option><option value="target_value">Целевое значение</option><option value="categorical">Категориальное</option><option value="custom_rule">По правилу</option></select></label><label>Пересмотр<select data-f="reopen_policy"><option value="with_reason">С причиной</option><option value="never">Нельзя</option><option value="free_until_final">Свободно до финала</option><option value="explicit_mutual_confirmation">Только по взаимному подтверждению</option></select></label><label>Важность<input data-f="importance_weight" type="number" min="0.1" step="0.1" value="1"></label></div>
                    <label class="ckm-neg-inline-check"><input data-f="required_for_agreement" type="checkbox"> Обязателен для итогового соглашения</label>
                    <input type="hidden" data-f="config_json" class="is-json" value="{}">
                    <fieldset class="ckm-neg-item-config-fieldset"><legend>Значения предмета</legend>
                        <div class="ckm-neg-builder-two">
                            <label>Исходное значение <small>Значение, от которого начинается работа с этим предметом.</small><input data-ui="item_config_opening" placeholder="Необязательно"></label>
                            <label data-ui="item_config_step_wrap">Шаг изменения <small>Например: 1, 5 или 1000. Используется для числового перебора допустимых пакетов.</small><input data-ui="item_config_step" type="number" min="0.000001" step="any" placeholder="1"></label>
                        </div>
                        <div data-ui="item_config_options_wrap" hidden>
                            <div class="ckm-neg-builder-section-head ckm-neg-item-options-head"><div><strong>Варианты выбора</strong><p>Добавьте варианты, которые сможет выбрать участник. Служебные значения будут храниться внутри сценария.</p></div><button class="ckm-neg-btn" type="button" data-item-add-option>+ Добавить вариант</button></div>
                            <div class="ckm-neg-item-options" data-ui="item_config_options"></div>
                        </div>
                        <div class="ckm-neg-builder-result-note" data-ui="item_config_preserved" hidden><strong>Дополнительные параметры старого сценария сохранены.</strong><span>Они не будут удалены при сохранении.</span></div>
                    </fieldset>
                </div>
            </template>
            <template id="nb-rule-template"><div class="ckm-neg-builder-row ckm-neg-rule-row" data-kind="rule"><button type="button" class="ckm-neg-builder-remove" title="Удалить">×</button>
                <input type="hidden" data-f="code"><div class="ckm-neg-builder-two"><label>Тип правила<select data-f="rule_type"><option value="boundary">Красная линия</option><option value="package_constraint">Зависимость условий</option><option value="completeness">Полнота соглашения</option><option value="walkaway">Завершение переговоров</option><option value="confirmation">Финальное подтверждение</option><option value="hard_constraint">Жёсткое ограничение</option><option value="process">Правило процесса переговоров</option><optgroup label="Совместимость со старыми сценариями"><option value="red_line">Красная линия (старый тип)</option><option value="dependency">Зависимость (старый тип)</option><option value="agreement_requirement">Условие соглашения (старый тип)</option><option value="validation">Проверка (старый тип)</option></optgroup></select></label><label>Приоритет<input data-f="priority" type="number" value="0"></label></div>
                <fieldset class="ckm-neg-rule-fieldset"><legend>Когда правило срабатывает</legend>
                    <label>Способ проверки<select data-ui="rule_condition_mode"><option value="comparisons">По значениям предметов переговоров</option><option value="event">При событии игры</option><option value="walkaway_repeat">При повторном нарушении границ</option><option value="preserved">Сложное условие старого сценария</option></select></label>
                    <div data-ui="rule_condition_comparisons"><div class="ckm-neg-builder-two"><label>Логика<select data-ui="rule_condition_logic"><option value="single">Одно условие</option><option value="all">Все условия одновременно</option><option value="any">Хотя бы одно условие</option></select></label><div class="ckm-neg-rule-clause-help">Предмет, оператор и значение задаются отдельными строками.</div></div><div class="ckm-neg-rule-clauses" data-ui="rule_condition_clauses"></div><button class="ckm-neg-btn ckm-neg-rule-add" type="button" data-rule-add-clause="condition">+ Добавить условие</button></div>
                    <label data-ui="rule_condition_event_wrap">Когда срабатывает<select data-ui="rule_condition_event"><option value="agreement_proposed">Участник предложил соглашение</option><option value="agreement_finalize_requested">Запрошено финальное подтверждение соглашения</option></select></label>
                    <div class="ckm-neg-rule-special" data-ui="rule_condition_walkaway"><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="rule_walkaway_outside"> Повторяются требования за пределами жёсткой границы оппонента</label><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="rule_walkaway_unchanged"> Пакет условий не меняется</label><label>После какого повторения можно завершить переговоры <small>оставьте пустым, если порог задаётся другой логикой</small><input type="number" min="1" step="1" data-ui="rule_walkaway_threshold"></label></div>
                    <div class="ckm-neg-builder-result-note" data-ui="rule_condition_preserved" hidden><strong>Сложное условие сохранено.</strong><span>Оно не будет потеряно. Чтобы заменить его, выберите другой способ проверки.</span></div>
                    <input type="hidden" data-f="condition_json" class="is-json" value="{}">
                </fieldset>
                <fieldset class="ckm-neg-rule-fieldset"><legend>Что происходит при срабатывании</legend>
                    <label>Действие<select data-ui="rule_action_type"><option value="flag_breach">Отметить нарушение красной линии</option><option value="require">Потребовать одно условие</option><option value="require_any">Потребовать хотя бы одно из условий</option><option value="require_items">Потребовать заполнение предметов</option><option value="block">Заблокировать недопустимый пакет</option><option value="opponent_walkaway">ИИ-оппонент завершает переговоры</option><option value="require_explicit_confirmation">Потребовать явное подтверждение</option><option value="preserved">Сложное действие старого сценария</option></select></label>
                    <div class="ckm-neg-builder-two" data-ui="rule_action_flag"><label>Чья граница<select data-ui="rule_action_side"><option value="player">Участника</option><option value="opponent">ИИ-оппонента</option></select></label><label>Предмет переговоров<select data-ui="rule_action_item"></select></label></div>
                    <div data-ui="rule_action_require"><div class="ckm-neg-rule-clauses" data-ui="rule_action_require_clauses"></div></div>
                    <div data-ui="rule_action_require_any"><div class="ckm-neg-rule-clauses" data-ui="rule_action_any_clauses"></div><button class="ckm-neg-btn ckm-neg-rule-add" type="button" data-rule-add-clause="action_any">+ Добавить допустимое условие</button></div>
                    <div data-ui="rule_action_items"><div class="ckm-neg-rule-item-checks" data-ui="rule_action_items_list"></div></div>
                    <div data-ui="rule_action_block"><input type="hidden" data-ui="rule_action_reason"><label>Сообщение участнику <small>необязательно</small><textarea data-ui="rule_action_message" rows="3" placeholder="Объясните, почему такой пакет условий недопустим."></textarea></label></div>
                    <label data-ui="rule_action_walkaway">Причина завершения переговоров<textarea data-ui="rule_action_description" rows="3"></textarea></label>
                    <div class="ckm-neg-rule-special" data-ui="rule_action_confirmation"><div>Кто должен подтвердить соглашение</div><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="rule_confirm_player"> Участник</label><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="rule_confirm_opponent"> ИИ-оппонент</label><label class="ckm-neg-inline-check"><input type="checkbox" data-ui="rule_confirm_same_package"> Подтверждается один и тот же пакет условий</label></div>
                    <div class="ckm-neg-builder-result-note" data-ui="rule_action_preserved" hidden><strong>Сложное действие сохранено.</strong><span>Оно не будет потеряно. Чтобы заменить его, выберите другое действие.</span></div>
                    <input type="hidden" data-f="action_json" class="is-json" value="{}">
                </fieldset>
                <label class="ckm-neg-inline-check"><input data-f="is_active" type="checkbox" checked> Правило активно</label>
            </div></template>
            <template id="nb-evaluation-template"><div class="ckm-neg-builder-row ckm-neg-evaluation-row" data-kind="evaluation"><button type="button" class="ckm-neg-builder-remove" title="Удалить">×</button>
                <input type="hidden" data-f="code"><div class="ckm-neg-builder-three"><label>Критерий<input data-f="title"></label><label>Вес, %<input data-f="weight" type="number" min="0" max="100" step="0.5" value="10"></label><label>Как оценивать<select data-f="evaluation_type"><option value="hybrid">Формальные правила + ИИ</option><option value="php">Только формальные правила</option><option value="ai">Только ИИ</option><option value="rubric">Рубрика старого сценария</option></select></label></div>
                <fieldset class="ckm-neg-evaluation-fieldset"><legend>Рубрика критерия</legend>
                    <label>Что именно оценивается<textarea data-ui="evaluation_description" rows="3" placeholder="Коротко опишите, какое поведение или результат оценивает этот критерий."></textarea></label>
                    <div class="ckm-neg-builder-three"><label>Шкала<select data-ui="evaluation_rubric_mode"><option value="levels5">5 уровней: слабый → отличный</option><option value="numeric3">3 опорные точки: минимум → середина → максимум</option><option value="preserved">Сложная рубрика старого сценария</option></select></label><label>Минимальный балл<input data-ui="evaluation_scale_min" type="number" step="0.01" value="0"></label><label>Максимальный балл<input data-ui="evaluation_scale_max" type="number" step="0.01" value="100"></label></div>
                    <div class="ckm-neg-evaluation-anchors" data-ui="evaluation_levels5">
                        <label>Слабый результат<textarea data-ui="evaluation_anchor_weak" rows="2"></textarea></label>
                        <label>Ограниченный результат<textarea data-ui="evaluation_anchor_limited" rows="2"></textarea></label>
                        <label>Приемлемый результат<textarea data-ui="evaluation_anchor_adequate" rows="2"></textarea></label>
                        <label>Сильный результат<textarea data-ui="evaluation_anchor_strong" rows="2"></textarea></label>
                        <label>Отличный результат<textarea data-ui="evaluation_anchor_excellent" rows="2"></textarea></label>
                    </div>
                    <div class="ckm-neg-evaluation-anchors is-three" data-ui="evaluation_numeric3" hidden>
                        <label>Минимум шкалы<textarea data-ui="evaluation_anchor_min" rows="2"></textarea></label>
                        <label>Середина шкалы<textarea data-ui="evaluation_anchor_mid" rows="2"></textarea></label>
                        <label>Максимум шкалы<textarea data-ui="evaluation_anchor_max" rows="2"></textarea></label>
                    </div>
                    <div class="ckm-neg-builder-result-note" data-ui="evaluation_preserved" hidden><strong>Сложная рубрика сохранена.</strong><span>Её данные не будут потеряны. Чтобы заменить рубрику обычными полями, выберите один из двух вариантов шкалы.</span></div>
                    <input type="hidden" data-f="rubric_json" class="is-json" value="{}">
                </fieldset>
                <fieldset class="ckm-neg-evaluation-fieldset"><legend>Параметры оценки</legend>
                    <div class="ckm-neg-builder-checks"><label><input type="checkbox" data-ui="evaluation_evidence_required" checked> Требовать подтверждение репликами или событиями</label><label><input type="checkbox" data-ui="evaluation_runtime_enabled" checked> Использовать критерий при итоговой оценке</label></div>
                    <label data-ui="evaluation_php_share_wrap">Доля формальной оценки, % <small>Остальная часть критерия оценивается ИИ. Используется только для комбинированного типа.</small><input data-ui="evaluation_php_share" type="number" min="0" max="100" step="1" value="50"></label>
                    <div class="ckm-neg-builder-result-note" data-ui="evaluation_config_preserved" hidden><strong>Дополнительные параметры старого сценария сохранены.</strong><span>Они останутся в конфигурации при повторном сохранении.</span></div>
                    <input type="hidden" data-f="config_json" class="is-json" value="{}">
                </fieldset>
            </div></template>
        </div>
        <?php return (string)ob_get_clean();
    }
}

<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class PlayerPage {
    private const PAGE_OPTION = 'ckm_neg_session_page_id';
    private const PAGE_VERSION_OPTION = 'ckm_neg_session_page_version';
    private const PAGE_VERSION = '5';

    public static function register(): void {
        add_shortcode('ckm_negotiation_master', [self::class, 'render']);
    }

    public static function maybeInstall(): void {
        if (get_option(self::PAGE_VERSION_OPTION, '') === self::PAGE_VERSION) { return; }
        self::install();
    }

    public static function install(): void {
        $id = (int) get_option(self::PAGE_OPTION, 0);
        if ($id > 0 && get_post($id)) {
            update_option(self::PAGE_VERSION_OPTION, self::PAGE_VERSION, false);
            return;
        }
        $existing = get_page_by_path('ckm-negotiation-master');
        if ($existing) {
            update_option(self::PAGE_OPTION, (int) $existing->ID, false);
            update_option(self::PAGE_VERSION_OPTION, self::PAGE_VERSION, false);
            return;
        }
        $id = wp_insert_post([
            'post_title' => 'Мастер переговоров',
            'post_name' => 'ckm-negotiation-master',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[ckm_negotiation_master]',
        ]);
        if (!is_wp_error($id) && (int) $id > 0) {
            update_option(self::PAGE_OPTION, (int) $id, false);
            update_option(self::PAGE_VERSION_OPTION, self::PAGE_VERSION, false);
        }
    }

    private static function decode(?string $json): mixed {
        if ($json === null || $json === '') { return null; }
        try { return json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return null; }
    }

    private static function keyLabel(string $key, array $itemLabels = []): string {
        if (isset($itemLabels[$key]) && $itemLabels[$key] !== '') { return (string) $itemLabels[$key]; }
        $map = [
            'price'=>'Цена','prepayment'=>'Предоплата','service_months'=>'Сервис, месяцев','delivery_days'=>'Поставка, дней',
            'min'=>'не ниже','max'=>'не выше','target'=>'цель','description'=>'Описание','currency'=>'Валюта','opening'=>'Исходные условия',
            'buyer_opening'=>'Позиция покупателя','opening_position'=>'Исходная позиция','package_requirements'=>'Условия пакета',
            'require'=>'Требуется','require_any'=>'Одно из условий','if'=>'Если','principle'=>'Принцип','source_frame'=>'Исходная рамка',
            'hard_package'=>'Обязательный пакет','credibility'=>'Надёжность договорённости','employee_value'=>'Ценность сотрудника',
            'replacement_risk'=>'Риск замены','outcome_path'=>'Вариант результата','decision_scope'=>'Границы решений',
            'proposal_path'=>'Порядок предложений','feedback_channel'=>'Канал обратной связи','client_ownership'=>'Ответственность за клиента',
            'shared_kpi'=>'Общий показатель результата','decision_authority'=>'Полномочия по решениям','control_method'=>'Способ контроля',
            'price_lt'=>'если цена ниже','price_gte'=>'если цена не ниже','prepayment_gte'=>'предоплата не ниже',
            'service_months_gte'=>'сервис не короче','delivery_days_lte'=>'поставка не дольше','delivery_days_gte'=>'поставка не раньше',
        ];
        if (isset($map[$key])) { return $map[$key]; }
        $operators = ['_lte'=>' — не более','_gte'=>' — не менее','_lt'=>' — меньше','_gt'=>' — больше','_eq'=>' — равно'];
        foreach ($operators as $suffix => $label) {
            if (str_ends_with($key, $suffix)) {
                $base = substr($key, 0, -strlen($suffix));
                return self::keyLabel($base, $itemLabels) . $label;
            }
        }
        return 'Параметр';
    }

    private static function valueText(mixed $value, ?string $key = null, array $valueLabels = []): string {
        if (is_bool($value)) { return $value ? 'Да' : 'Нет'; }
        if ($value === null) { return '—'; }
        if (is_string($value) && $key !== null && isset($valueLabels[$key][$value])) { return (string) $valueLabels[$key][$value]; }
        if (is_string($value)) {
            $common = [
                'RUB'=>'рубли','yes'=>'Да','no'=>'Нет','none'=>'Нет','full'=>'Полный','limited'=>'Ограниченный','demo'=>'Демонстрационный',
                'prototype'=>'Прототип','stable'=>'Стабильная версия','production'=>'Готовая к эксплуатации версия','current'=>'Текущий',
                'expanded'=>'Расширенный','peer'=>'Помощь коллеги','assistant'=>'Выделенный помощник','pilot'=>'Пилотный вариант',
                'recommendation'=>'Рекомендация','defined'=>'Определено','stay'=>'Остаться','planned_exit'=>'Плановый уход',
                'private'=>'Лично','public'=>'Публично','shared'=>'Совместно','balanced'=>'Сбалансированно','high'=>'Высокий',
            ];
            if (isset($common[$value])) { return $common[$value]; }
        }
        if (is_int($value) || is_float($value)) {
            if (abs((float)$value) >= 1000000) { return number_format((float)$value / 1000000, 1, ',', ' ') . ' млн'; }
            return number_format((float)$value, 0, ',', ' ');
        }
        return (string) $value;
    }

    private static function renderTree(mixed $value, array $itemLabels = [], array $valueLabels = [], ?string $currentKey = null): string {
        if (!is_array($value)) { return '<span>'.esc_html(self::valueText($value, $currentKey, $valueLabels)).'</span>'; }
        if ($value === []) { return '<span>—</span>'; }
        $list = '<ul class="ckm-neg-tree">';
        foreach ($value as $key => $child) {
            $stringKey = is_int($key) ? null : (string) $key;
            $label = $stringKey === null ? '' : '<strong>'.esc_html(self::keyLabel($stringKey, $itemLabels)).':</strong> ';
            $list .= '<li>'.$label.self::renderTree($child, $itemLabels, $valueLabels, $stringKey).'</li>';
        }
        return $list.'</ul>';
    }

    private static function startUnit(string $unit): string {
        $unit = trim($unit);
        if ($unit === '') { return ''; }
        return [
            'RUB'=>'руб.', 'rub'=>'руб.', '₽'=>'руб.',
            'дней'=>'дней', 'день'=>'день', 'days'=>'дней',
            'месяцев'=>'мес.', 'months'=>'мес.', '%'=>'%',
        ][$unit] ?? $unit;
    }

    private static function startValue(mixed $value, string $itemCode, array $itemUnits = [], array $valueLabels = []): string {
        $text = self::valueText($value, $itemCode, $valueLabels);
        $unit = self::startUnit((string)($itemUnits[$itemCode] ?? ''));
        if ($unit === '' || $value === null || is_bool($value)) { return $text; }
        return trim($text . ' ' . $unit);
    }

    private static function renderKnownFactsStart(mixed $value, array $itemLabels = [], array $valueLabels = []): string {
        if (!is_array($value) || $value === []) { return '<p class="ckm-neg-muted">Дополнительных исходных данных нет.</p>'; }
        $rows = [];
        foreach ($value as $fact) {
            if (!is_array($fact)) { $rows = []; break; }
            $title = trim((string)($fact['title'] ?? ''));
            $content = trim((string)($fact['content'] ?? ''));
            if ($title === '' && $content === '') { continue; }
            $rows[] = '<div class="ckm-neg-brief-fact">'
                . ($title !== '' ? '<strong>'.esc_html($title).'</strong>' : '')
                . ($content !== '' ? '<span>'.esc_html($content).'</span>' : '')
                . '</div>';
        }
        if ($rows !== []) { return '<div class="ckm-neg-brief-facts">'.implode('', $rows).'</div>'; }
        return self::renderTree($value, $itemLabels, $valueLabels);
    }

    private static function renderThresholdsStart(mixed $value, array $itemLabels = [], array $itemUnits = [], array $valueLabels = []): string {
        if (!is_array($value) || $value === []) { return '<p class="ckm-neg-muted">Не заданы.</p>'; }
        $operatorLabels = ['min'=>'не ниже','max'=>'не выше','target'=>'цель'];
        $rows = [];
        foreach ($value as $itemCode => $rule) {
            if (is_int($itemCode)) { continue; }
            $itemCode = (string)$itemCode;
            $itemTitle = (string)($itemLabels[$itemCode] ?? self::keyLabel($itemCode, $itemLabels));
            if (is_array($rule)) {
                foreach ($rule as $operator => $threshold) {
                    $operator = (string)$operator;
                    $label = $operatorLabels[$operator] ?? self::keyLabel($operator, $itemLabels);
                    $rows[] = '<div class="ckm-neg-brief-row"><span>'.esc_html($itemTitle).'</span><strong>'.esc_html($label.' '.self::startValue($threshold, $itemCode, $itemUnits, $valueLabels)).'</strong></div>';
                }
            } else {
                $rows[] = '<div class="ckm-neg-brief-row"><span>'.esc_html($itemTitle).'</span><strong>'.esc_html(self::startValue($rule, $itemCode, $itemUnits, $valueLabels)).'</strong></div>';
            }
        }
        if ($rows === []) { return self::renderTree($value, $itemLabels, $valueLabels); }
        return '<div class="ckm-neg-brief-list">'.implode('', $rows).'</div>';
    }

    private static function findScenario(): ?array {
        $repo = new ScenarioRepository();
        $id = isset($_GET['neg_scenario_id']) ? absint($_GET['neg_scenario_id']) : 0;
        if ($id > 0) {
            $scenario = $repo->get($id);
            if ((string)($scenario['status'] ?? '') !== 'published' || empty($scenario['current_version_id'])) { return null; }
            return $scenario;
        }
        // Backward compatibility for links generated before .373. Do not use
        // sanitize_key(): legacy Cyrillic titles were stored as literal %xx bytes.
        $raw = $_GET['neg_scenario'] ?? '';
        $slug = is_string($raw) ? trim((string)wp_unslash($raw)) : '';
        if ($slug === '') { return null; }
        return $repo->findPublishedBySlug($slug);
    }

    private static function scenarioArgs(array $scenario, array $extra = []): array {
        return array_merge(['neg_scenario_id'=>(int)($scenario['id'] ?? 0)], $extra);
    }

    private static function findLibrary(): ?array {
        $slug = isset($_GET['neg_library']) ? sanitize_key(wp_unslash($_GET['neg_library'])) : '';
        if ($slug === '') { return null; }
        return ProductCatalog::libraryBySlug($slug);
    }

    private static function enqueueStyle(): void {
        $base = CKM_QUIZ_PRO_URL . 'modules/negotiation-master/assets/';
        wp_enqueue_style('ckm-neg-session', $base . 'negotiation-session.css', [], CKM_QUIZ_PRO_VERSION);
    }

    private static function enqueue(array $config): void {
        self::enqueueStyle();
        $base = CKM_QUIZ_PRO_URL . 'modules/negotiation-master/assets/';
        wp_enqueue_script('ckm-neg-session', $base . 'negotiation-session.js', [], CKM_QUIZ_PRO_VERSION, true);
        wp_localize_script('ckm-neg-session', 'CKMNegSessionConfig', $config);
    }

    private static function resultTypeLabel(string $type): string {
        return [
            'agreement_strong'=>'Сильное соглашение','agreement_acceptable'=>'Приемлемый компромисс','agreement_weak'=>'Слабое соглашение',
            'rational_walkaway'=>'Рациональный отказ','unavoidable_no_deal'=>'Без соглашения','premature_walkaway'=>'Преждевременный выход',
            'zopa_destroyed'=>'Рабочее пространство потеряно','opponent_walkaway_caused'=>'Завершил оппонент','unclear_no_deal'=>'Без соглашения',
        ][$type] ?? 'Итог переговоров';
    }

    private static function statusLabel(string $status): string {
        if ($status === 'in_progress') { return 'В процессе'; }
        if ($status === 'paused') { return 'Приостановлено'; }
        if ($status === 'completed_agreement') { return 'Соглашение'; }
        if (str_starts_with($status, 'completed_no_agreement')) { return 'Без соглашения'; }
        if ($status === 'abandoned') { return 'Не завершено'; }
        return 'Попытка';
    }


    private static function difficultyLabel(string $difficulty): string {
        return DifficultyPolicy::label($difficulty);
    }

    private static function renderScenarioCard(array $card, bool $libraryAllowed = true, ?string $purchaseUrl = null): string {
        $meta = is_array($card['meta'] ?? null) ? $card['meta'] : [];
        $active = $card['active_session'] ?? null; $latest = $card['latest_completed'] ?? null;
        $published = (string)($card['status'] ?? 'published') === 'published' && !empty($card['current_version_id']);
        ob_start(); ?>
        <article class="ckm-neg-scenario-card<?php echo $published ? '' : ' is-coming-soon'; ?>">
            <div class="ckm-neg-scenario-top"><span class="ckm-neg-chip"><?php echo esc_html((string)($meta['category'] ?? 'Переговоры')); ?></span><span class="ckm-neg-chip is-subtle"><?php echo esc_html((string)($meta['difficulty'] ?? 'Средний')); ?></span></div>
            <h2><?php echo esc_html((string)$card['title']); ?></h2>
            <p class="ckm-neg-scenario-desc"><?php echo esc_html((string)($meta['description'] ?? $card['player_situation'] ?? '')); ?></p>
            <div class="ckm-neg-scenario-meta"><span>⏱ <?php echo esc_html((string)($meta['duration'] ?? '15–25 мин')); ?></span><span>🎭 Скрытые интересы</span></div>
            <?php if (!$published): ?>
                <div class="ckm-neg-attempt-state"><strong>Скоро</strong><span>Сценарий готовится к публикации</span></div>
            <?php elseif (!$libraryAllowed): ?>
                <div class="ckm-neg-attempt-state is-locked"><strong>Нужен доступ к библиотеке</strong><span>Скрытое содержание сценария не раскрывается до запуска</span></div>
            <?php elseif ($active): ?>
                <div class="ckm-neg-attempt-state is-active"><strong><?php echo esc_html(self::statusLabel((string)$active['status'])); ?></strong><span>Есть незавершённая попытка · Оппонент: <?php echo esc_html(self::difficultyLabel((string)($active['difficulty']??'medium'))); ?></span></div>
            <?php elseif ($latest): ?>
                <div class="ckm-neg-attempt-state"><strong>Последний результат<?php if ($latest['final_score'] !== null): ?> · <?php echo esc_html((string)round((float)$latest['final_score'])); ?>/100<?php endif; ?></strong><span><?php echo esc_html(self::resultTypeLabel((string)($latest['result_type'] ?? ''))); ?> · Оппонент: <?php echo esc_html(self::difficultyLabel((string)($latest['difficulty']??'medium'))); ?></span></div>
            <?php else: ?>
                <div class="ckm-neg-attempt-state"><strong>Не пройдено</strong><span>Начните первую попытку</span></div>
            <?php endif; ?>
            <div class="ckm-neg-card-actions">
                <?php if (!$published): ?>
                    <span class="ckm-neg-btn is-disabled">Скоро</span>
                <?php elseif (!$libraryAllowed): ?>
                    <?php if ($purchaseUrl): ?><a class="ckm-neg-btn ckm-neg-primary" href="<?php echo esc_url($purchaseUrl); ?>">Получить библиотеку</a><?php else: ?><span class="ckm-neg-btn is-disabled">Доступ по лицензии</span><?php endif; ?>
                <?php elseif ($active): ?>
                    <a class="ckm-neg-btn ckm-neg-primary" href="<?php echo esc_url(ProductCatalog::url(self::scenarioArgs($card, ['neg_session'=>(int)$active['id']]))); ?>">Продолжить</a>
                <?php else: ?>
                    <a class="ckm-neg-btn ckm-neg-primary" href="<?php echo esc_url(ProductCatalog::url(self::scenarioArgs($card))); ?>"><?php echo $latest ? 'Пройти ещё раз' : 'Начать'; ?></a>
                <?php endif; ?>
                <?php if ($published && $libraryAllowed && $latest): ?><a class="ckm-neg-btn" href="<?php echo esc_url(ProductCatalog::url(self::scenarioArgs($card, ['neg_session'=>(int)$latest['id']]))); ?>">Посмотреть результат</a><?php endif; ?>
            </div>
            <?php if ($published && $libraryAllowed && (int)($card['attempt_count'] ?? 0) > 0): ?><div class="ckm-neg-attempt-count">Попыток: <?php echo (int)$card['attempt_count']; ?></div><?php endif; ?>
        </article>
        <?php return (string)ob_get_clean();
    }

    private static function renderCatalog(): string {
        $basic = ProductCatalog::libraryBySlug('basic');
        $cards = $basic ? ProductCatalog::cards((int)$basic['id']) : ProductCatalog::cards();
        $libraries = ProductCatalog::libraries();
        $own = ProductCatalog::ownCards();
        $builderAllowed = ScenarioBuilderService::canUse();
        ob_start(); ?>
        <div class="ckm-neg-shell ckm-neg-catalog-shell">
            <section class="ckm-neg-catalog-hero ckm-neg-card">
                <div class="ckm-neg-kicker">Деловые переговоры</div>
                <h1>Мастер переговоров</h1>
                <p class="ckm-neg-lead">Свободные переговоры с ИИ-оппонентом. Выясняйте интересы другой стороны, управляйте уступками, защищайте свои границы и формируйте устойчивое соглашение.</p>
                <div class="ckm-neg-catalog-principle"><strong>Главная задача:</strong> не «победить» оппонента, а договориться на условиях, которые имеют смысл для обеих сторон.</div>
                <div class="ckm-neg-card-actions"><a class="ckm-neg-btn" href="<?php echo esc_url(AssignmentPage::url()); ?>"><?php echo AssignmentService::canManage() ? 'Назначения участникам' : 'Мои назначения'; ?></a><?php if ($builderAllowed): ?><a class="ckm-neg-btn" href="<?php echo esc_url(BuilderPage::url()); ?>">Мои сценарии и конструктор</a><?php endif; ?></div>
            </section>
            <div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Базовые сценарии</div><h2>Выберите ситуацию</h2></div><span class="ckm-neg-catalog-count"><?php echo (int)count($cards); ?> сценария</span></div>
            <section class="ckm-neg-scenario-grid"><?php foreach ($cards as $card) { echo self::renderScenarioCard($card, true); } ?></section>

            <?php if ($own || $builderAllowed): ?>
            <div class="ckm-neg-catalog-head ckm-neg-library-head"><div><div class="ckm-neg-kicker">Собственные сценарии</div><h2>Сценарии вашей площадки</h2></div><?php if ($builderAllowed): ?><a class="ckm-neg-btn" href="<?php echo esc_url(BuilderPage::url()); ?>">Открыть конструктор</a><?php endif; ?></div>
            <?php if ($own): ?><section class="ckm-neg-scenario-grid"><?php foreach ($own as $card) { echo self::renderScenarioCard($card, true); } ?></section><?php else: ?><section class="ckm-neg-card"><p class="ckm-neg-muted">Опубликованных собственных сценариев пока нет. Создайте новый сценарий или сделайте копию доступного системного кейса.</p></section><?php endif; ?>
            <?php endif; ?>

            <div class="ckm-neg-catalog-head ckm-neg-library-head"><div><div class="ckm-neg-kicker">Библиотеки сценариев</div><h2>Тематические наборы</h2></div></div>
            <section class="ckm-neg-library-grid">
                <?php foreach ($libraries as $library): $access=is_array($library['access']??null)?$library['access']:[]; $soon=(string)$library['status']!=='published'; ?>
                    <article class="ckm-neg-library-card<?php echo $soon?' is-coming-soon':''; ?>">
                        <div class="ckm-neg-scenario-top"><span class="ckm-neg-chip"><?php echo $soon?'Скоро':'Библиотека'; ?></span><?php if (!$soon): ?><span class="ckm-neg-chip is-subtle"><?php echo !empty($access['allowed'])?'Доступ открыт':'Отдельная лицензия'; ?></span><?php endif; ?></div>
                        <h2><?php echo esc_html((string)$library['title']); ?></h2>
                        <p><?php echo esc_html((string)$library['description']); ?></p>
                        <div class="ckm-neg-library-stats"><span><?php echo (int)$library['scenario_count']; ?> сценариев</span><?php if (!$soon): ?><span><?php echo (int)$library['playable_count']; ?> доступно сейчас</span><?php endif; ?></div>
                        <div class="ckm-neg-card-actions">
                            <?php if ($soon): ?><span class="ckm-neg-btn is-disabled">Скоро</span><?php else: ?><a class="ckm-neg-btn ckm-neg-primary" href="<?php echo esc_url(ProductCatalog::url(['neg_library'=>$library['slug']])); ?>">Посмотреть сценарии</a><?php endif; ?>
                            <?php if (!$soon && empty($access['allowed']) && !empty($access['purchase_url'])): ?><a class="ckm-neg-btn" href="<?php echo esc_url((string)$access['purchase_url']); ?>">Получить библиотеку</a><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function renderLibrary(array $library): string {
        $cards = ProductCatalog::libraryCards((int)$library['id']);
        $access = is_array($library['access'] ?? null) ? $library['access'] : [];
        $allowed = !empty($access['allowed']);
        ob_start(); ?>
        <div class="ckm-neg-shell ckm-neg-catalog-shell">
            <section class="ckm-neg-catalog-hero ckm-neg-card">
                <a class="ckm-neg-back" href="<?php echo esc_url(ProductCatalog::url()); ?>">← Все библиотеки и сценарии</a>
                <div class="ckm-neg-kicker">Библиотека сценариев</div>
                <h1><?php echo esc_html((string)$library['title']); ?></h1>
                <p class="ckm-neg-lead"><?php echo esc_html((string)$library['description']); ?></p>
                <?php if (!$allowed): ?><div class="ckm-neg-library-lock"><strong>Отдельная лицензия</strong><span>Названия и открытые описания доступны для ознакомления. Игровое содержание и скрытые данные остаются закрытыми до предоставления доступа.</span><?php if (!empty($access['purchase_url'])): ?><a class="ckm-neg-btn ckm-neg-primary" href="<?php echo esc_url((string)$access['purchase_url']); ?>">Получить библиотеку</a><?php endif; ?></div><?php endif; ?>
            </section>
            <div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Сценарии</div><h2><?php echo count($cards); ?> ситуаций</h2></div><span class="ckm-neg-catalog-count"><?php echo count(array_filter($cards,fn($c)=>(string)($c['status']??'')==='published')); ?> доступно сейчас</span></div>
            <section class="ckm-neg-scenario-grid"><?php foreach ($cards as $card) { echo self::renderScenarioCard($card, $allowed, $access['purchase_url'] ?? null); } ?></section>
        </div>
        <?php return (string)ob_get_clean();
    }

    private static function renderAttempts(array $attempts, array $scenario): string {
        if (!$attempts) { return ''; }
        ob_start(); ?>
        <div class="ckm-neg-card ckm-neg-attempts-card">
            <div class="ckm-neg-attempts-head"><div><div class="ckm-neg-kicker">История</div><h3>Мои попытки</h3></div><a class="ckm-neg-text-link" href="<?php echo esc_url(ProductCatalog::url()); ?>">Все сценарии</a></div>
            <div class="ckm-neg-attempt-list">
                <?php foreach ($attempts as $attempt):
                    $status=(string)($attempt['status']??''); $date=(string)($attempt['completed_at'] ?: $attempt['last_activity_at'] ?: $attempt['started_at'] ?? '');
                    $isActive=in_array($status,['in_progress','paused'],true); $isCompleted=str_starts_with($status,'completed_'); ?>
                    <div class="ckm-neg-attempt-row">
                        <div><strong><?php echo esc_html($date !== '' ? wp_date('d.m.Y H:i', strtotime($date.' UTC')) : 'Попытка'); ?></strong><span><?php echo esc_html(($attempt['mode']??'training')==='exam'?'Экзамен':'Тренировка'); ?> · Оппонент: <?php echo esc_html(self::difficultyLabel((string)($attempt['difficulty']??'medium'))); ?> · <?php echo esc_html(self::statusLabel($status)); ?></span></div>
                        <div class="ckm-neg-attempt-result"><?php if ($attempt['final_score'] !== null): ?><strong><?php echo esc_html((string)round((float)$attempt['final_score'])); ?>/100</strong><?php elseif ($isCompleted): ?><span>Разбор готовится</span><?php endif; ?></div>
                        <?php if ($isActive || $isCompleted): ?><a class="ckm-neg-btn ckm-neg-btn-small" href="<?php echo esc_url(ProductCatalog::url(self::scenarioArgs($scenario, ['neg_session'=>(int)$attempt['id']]))); ?>"><?php echo $isActive?'Продолжить':'Открыть результат'; ?></a><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php return (string)ob_get_clean();
    }

    public static function render(): string {
        if (!is_user_logged_in()) {
            $url = function_exists('wp_login_url') ? wp_login_url((string) get_permalink()) : '#';
            return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><h2>Мастер переговоров</h2><p>Для начала переговоров необходимо войти.</p><a class="ckm-neg-btn ckm-neg-primary" href="'.esc_url($url).'">Войти</a></div></div>';
        }
        try {
            $incomingAssignmentSession = isset($_GET['neg_assignment_session']) ? absint($_GET['neg_assignment_session']) : 0;
            if ($incomingAssignmentSession <= 0 && !current_user_can('manage_options') && function_exists('ckm_quiz_pro_can_organize') && ckm_quiz_pro_can_organize() && function_exists('ckm_quiz_pro_can_access_format') && !ckm_quiz_pro_can_access_format(ckm_quiz_pro_effective_organizer_user_id(), 'negotiation_duel_v1')) {
                self::enqueueStyle();
                $buy = ckm_quiz_pro_organizer_url(['view'=>'payment','games'=>'negotiation_duel_v1']);
                return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><div class="ckm-neg-kicker">Мастер переговоров</div><h2>Нужен доступ к деловым переговорам</h2><p class="ckm-neg-muted">Используется существующий доступ к переговорным играм ЦКМ. Новая платёжная система для «Мастера переговоров» не создаётся.</p><a class="ckm-neg-btn ckm-neg-primary" href="'.esc_url($buy).'">Открыть доступ</a></div></div>';
            }
            $repo = new ScenarioRepository();
            $testSessionId = isset($_GET['neg_test_session']) ? absint($_GET['neg_test_session']) : 0;
            $assignmentSessionId = $incomingAssignmentSession;
            $isBuilderTest = false;
            $isAssignmentSession = false;
            $assignmentId = 0;
            $scenarioLibrary = null;
            $requested = null;
            $ongoing = null;
            $attempts = [];
            if ($testSessionId > 0) {
                if (!ScenarioBuilderService::canUse()) { throw new \RuntimeException('Тестовый запуск конструктора недоступен.'); }
                $testSession = Access::session($testSessionId);
                if (($testSession['session_kind'] ?? 'player') !== 'builder_test') { throw new \RuntimeException('Тестовая сессия недоступна.'); }
                $scenario = $repo->get((int)$testSession['scenario_id']);
                if ($scenario['tenant_id'] === null) { throw new \RuntimeException('Тестовый запуск разрешён только для собственного черновика.'); }
                $version = $repo->playerVersion((int)$testSession['scenario_version_id']);
                $items = $repo->playerItems((int)$testSession['scenario_version_id']);
                $requested = $testSession;
                $sessionToLoad = $testSession;
                $isBuilderTest = true;
            } elseif ($assignmentSessionId > 0) {
                $assignmentSession = Access::session($assignmentSessionId);
                if (($assignmentSession['session_kind'] ?? '') !== 'assignment' || (int)($assignmentSession['assignment_id'] ?? 0) <= 0) { throw new \RuntimeException('Назначенная сессия недоступна.'); }
                $scenario = $repo->get((int)$assignmentSession['scenario_id']);
                $version = $repo->playerVersion((int)$assignmentSession['scenario_version_id']);
                $items = $repo->playerItems((int)$assignmentSession['scenario_version_id']);
                $requested = $assignmentSession;
                $sessionToLoad = $assignmentSession;
                $isAssignmentSession = true;
                $assignmentId = (int)$assignmentSession['assignment_id'];
            } else {
                $scenario = self::findScenario();
                if (!$scenario) {
                    self::enqueueStyle();
                    $library = self::findLibrary();
                    return $library ? self::renderLibrary($library) : self::renderCatalog();
                }
                $scenarioLibrary = ProductCatalog::libraryForScenario($scenario);
                if ($scenarioLibrary && (string)($scenarioLibrary['visibility'] ?? 'public') === 'private' && !current_user_can('manage_options')) {
                    throw new \RuntimeException('Сценарий недоступен.');
                }
                if ($scenarioLibrary && empty($scenarioLibrary['access']['allowed'])) {
                    self::enqueueStyle();
                    $back = ProductCatalog::url(['neg_library'=>$scenarioLibrary['slug']]);
                    $purchase = $scenarioLibrary['access']['purchase_url'] ?? null;
                    return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><a class="ckm-neg-back" href="'.esc_url($back).'">← Библиотека</a><div class="ckm-neg-kicker">'.esc_html((string)$scenarioLibrary['title']).'</div><h2>Нужен доступ к библиотеке</h2><p class="ckm-neg-muted">Скрытое содержание сценария не передаётся в браузер до предоставления доступа.</p>'.($purchase?'<a class="ckm-neg-btn ckm-neg-primary" href="'.esc_url((string)$purchase).'">Получить библиотеку</a>':'<span class="ckm-neg-btn is-disabled">Доступ по отдельной лицензии</span>').'</div></div>';
                }
                if (empty($scenario['current_version_id'])) { throw new \RuntimeException('Сценарий недоступен.'); }
                $version = $repo->playerVersion((int)$scenario['current_version_id']);
                $items = $repo->playerItems((int)$scenario['current_version_id']);
                $requestedId = isset($_GET['neg_session']) ? absint($_GET['neg_session']) : 0;
                $requested = $requestedId > 0 ? ProductCatalog::requestedSession($requestedId, (int)$scenario['id']) : null;
                $ongoing = (new SessionService())->activeForScenario((int)$scenario['id']);
                $sessionToLoad = $requested ?: $ongoing;
                $attempts = ProductCatalog::attempts((int)$scenario['id']);
            }
            // Labels are required by the pre-start renderer in every launch path, including
            // builder tests and assignments. Previously they were initialized only for the
            // ordinary catalog path, which caused those special launches to fail while rendering.
            $itemLabels = [];
            $itemUnits = [];
            $valueLabels = [];
            foreach ($items as $item) {
                $code = (string)($item['code'] ?? '');
                if ($code === '') { continue; }
                $itemLabels[$code] = (string)($item['title'] ?? $code);
                $itemUnits[$code] = (string)($item['unit'] ?? '');
                $config = self::decode($item['config_json'] ?? null);
                if (is_array($config) && is_array($config['labels'] ?? null)) { $valueLabels[$code] = $config['labels']; }
            }

            $returnUrl = $isBuilderTest ? BuilderPage::url(['builder_scenario'=>(int)$scenario['id']]) : ($isAssignmentSession ? AssignmentPage::url(['assignment'=>$assignmentId]) : ProductCatalog::url());
            $allowedDifficulties = DifficultyPolicy::allowedForVersion($version);
            $defaultDifficulty = DifficultyPolicy::defaultForVersion($version);
            if ($sessionToLoad && !empty($sessionToLoad['difficulty'])) { $defaultDifficulty = DifficultyPolicy::normalize((string)$sessionToLoad['difficulty']); }
            $difficultyDescriptions = [
                'soft'=>'Быстрее раскрывает интересы и легче принимает сильные аргументы.',
                'medium'=>'Требует обоснования и взаимного обмена уступками.',
                'hard'=>'Медленнее уступает, требует конкретики и активнее использует альтернативы.',
                'expert'=>'Скрывает приоритеты, торгуется пакетами и проверяет последовательность вашей позиции.',
            ];

            self::enqueue([
                'restBase' => esc_url_raw(rest_url('ckm/v1/negotiation')),
                'nonce' => wp_create_nonce('wp_rest'),
                'scenarioId' => (int) $scenario['id'],
                'activeSessionId' => $sessionToLoad ? (int) $sessionToLoad['id'] : 0,
                'activeStatus' => $sessionToLoad ? (string) $sessionToLoad['status'] : '',
                'autoResume' => $requested !== null,
                'catalogUrl' => esc_url_raw($returnUrl),
                'builderTest' => $isBuilderTest,
                'assignmentSession' => $isAssignmentSession,
                'assignmentId' => $assignmentId,
                'assignmentUrl' => esc_url_raw($isAssignmentSession ? AssignmentPage::url(['assignment'=>$assignmentId]) : AssignmentPage::url()),
                'builderUrl' => esc_url_raw(BuilderPage::url(['builder_scenario'=>(int)$scenario['id']])),
                'itemLabels' => $itemLabels,
                'valueLabels' => $valueLabels,
            ]);

            ob_start(); ?>
            <div class="ckm-neg-shell" id="ckm-neg-app">
                <section class="ckm-neg-prestart ckm-neg-start-screen" id="ckm-neg-prestart">
                    <div class="ckm-neg-start-layout">
                        <div class="ckm-neg-start-content">
                            <section class="ckm-neg-card ckm-neg-start-hero">
                                <a class="ckm-neg-back" href="<?php echo esc_url($isBuilderTest ? BuilderPage::url(['builder_scenario'=>(int)$scenario['id']]) : ($isAssignmentSession ? AssignmentPage::url(['assignment'=>$assignmentId]) : ($scenarioLibrary && (string)$scenarioLibrary['slug'] !== 'basic' ? ProductCatalog::url(['neg_library'=>$scenarioLibrary['slug']]) : ProductCatalog::url()))); ?>">← <?php echo $isBuilderTest ? 'Конструктор' : ($isAssignmentSession ? 'Назначение' : ($scenarioLibrary && (string)$scenarioLibrary['slug'] !== 'basic' ? 'Библиотека' : 'Все сценарии')); ?></a>
                                <div class="ckm-neg-kicker">Мастер переговоров</div>
                                <?php if ($isBuilderTest): ?><div class="ckm-neg-test-banner"><strong>Тест черновика</strong><span>Эта попытка не учитывается в обычной истории и прогрессе.</span></div><?php endif; ?>
                                <?php if ($isAssignmentSession): ?><div class="ckm-neg-test-banner"><strong>Назначенная игра</strong><span>Режим, число попыток и дедлайн заданы организатором.</span></div><?php endif; ?>
                                <h1><?php echo esc_html($scenario['title']); ?></h1>
                                <p class="ckm-neg-lead"><?php echo esc_html($version['player_situation'] ?? ''); ?></p>
                                <div class="ckm-neg-start-people" aria-label="Участники переговоров">
                                    <div class="ckm-neg-start-person"><span>Ваша роль</span><strong><?php echo esc_html($version['player_role'] ?? ''); ?></strong></div>
                                    <div class="ckm-neg-start-person"><span>Оппонент</span><strong><?php echo esc_html($version['opponent_name'] ?? ''); ?></strong><small><?php echo esc_html($version['opponent_role'] ?? ''); ?></small></div>
                                </div>
                            </section>

                            <section class="ckm-neg-card ckm-neg-start-mission">
                                <div class="ckm-neg-kicker">Переговорная задача</div>
                                <h2>Что нужно сделать</h2>
                                <p><?php echo esc_html($version['player_task'] ?? ''); ?></p>
                            </section>

                            <div class="ckm-neg-start-brief-grid">
                                <section class="ckm-neg-card ckm-neg-start-brief ckm-neg-start-known">
                                    <div class="ckm-neg-start-section-head"><span class="ckm-neg-start-section-icon" aria-hidden="true">01</span><div><div class="ckm-neg-kicker">Исходные данные</div><h3>Что известно в начале</h3></div></div>
                                    <?php echo self::renderKnownFactsStart(self::decode($version['player_known_facts_json'] ?? null), $itemLabels, $valueLabels); ?>
                                </section>
                                <section class="ckm-neg-card ckm-neg-start-brief">
                                    <div class="ckm-neg-start-section-head"><span class="ckm-neg-start-section-icon" aria-hidden="true">02</span><div><div class="ckm-neg-kicker">Ориентир</div><h3>Целевой результат</h3></div></div>
                                    <?php echo self::renderThresholdsStart(self::decode($version['player_target_result_json'] ?? null), $itemLabels, $itemUnits, $valueLabels); ?>
                                </section>
                                <section class="ckm-neg-card ckm-neg-start-brief is-redline">
                                    <div class="ckm-neg-start-section-head"><span class="ckm-neg-start-section-icon" aria-hidden="true">03</span><div><div class="ckm-neg-kicker">Границы</div><h3>Красные линии</h3></div></div>
                                    <?php echo self::renderThresholdsStart(self::decode($version['player_red_lines_json'] ?? null), $itemLabels, $itemUnits, $valueLabels); ?>
                                </section>
                            </div>
                        </div>

                        <aside class="ckm-neg-card ckm-neg-launch-panel">
                            <div class="ckm-neg-kicker">Настройка попытки</div>
                            <h2>Перед началом</h2>
                            <p class="ckm-neg-launch-help">Выберите режим и уровень оппонента. Эти настройки можно менять перед каждой новой попыткой.</p>

                            <fieldset class="ckm-neg-launch-fieldset">
                                <legend>Режим</legend>
                                <div class="ckm-neg-start-choice-grid">
                                    <label class="ckm-neg-mode ckm-neg-start-choice"><input type="radio" name="ckm-neg-mode" value="training" checked><span><strong>Тренировка</strong><small>Можно обращаться к ИИ-тренеру.</small></span></label>
                                    <label class="ckm-neg-mode ckm-neg-start-choice"><input type="radio" name="ckm-neg-mode" value="exam"><span><strong>Экзамен</strong><small>Подсказки во время игры недоступны.</small></span></label>
                                </div>
                            </fieldset>

                            <fieldset class="ckm-neg-launch-fieldset ckm-neg-difficulty-block">
                                <legend>Уровень оппонента</legend>
                                <p class="ckm-neg-muted">Меняет стратегию ИИ, но не правила сценария и оценку.</p>
                                <div class="ckm-neg-difficulty-grid ckm-neg-start-difficulty-grid">
                                    <?php foreach ($allowedDifficulties as $difficulty): ?>
                                        <label class="ckm-neg-difficulty"><input type="radio" name="ckm-neg-difficulty" value="<?php echo esc_attr($difficulty); ?>" <?php checked($defaultDifficulty, $difficulty); ?>><span><strong><?php echo esc_html(DifficultyPolicy::label($difficulty)); ?></strong><small><?php echo esc_html($difficultyDescriptions[$difficulty] ?? ''); ?></small></span></label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <label class="ckm-neg-voice ckm-neg-start-voice"><input type="checkbox" id="ckm-neg-voice"><span><strong>Голосовой ввод</strong><small>Разрешить диктовку реплик с микрофона в этой попытке.</small></span></label>

                            <div id="ckm-neg-active" class="ckm-neg-active" <?php echo $ongoing ? '' : 'hidden'; ?>>
                                <strong>У вас есть незавершённые переговоры.</strong>
                                <div class="ckm-neg-actions"><button type="button" class="ckm-neg-btn ckm-neg-primary" id="ckm-neg-continue">Продолжить</button><button type="button" class="ckm-neg-btn" id="ckm-neg-restart">Начать заново</button></div>
                            </div>
                            <div class="ckm-neg-actions ckm-neg-start-actions" id="ckm-neg-start-actions" <?php echo $ongoing ? 'hidden' : ''; ?>><button type="button" class="ckm-neg-btn ckm-neg-primary" id="ckm-neg-start">Начать переговоры</button></div>
                            <p class="ckm-neg-status" id="ckm-neg-prestatus" role="status"></p>
                        </aside>
                    </div>
                    <?php echo self::renderAttempts($attempts, $scenario); ?>
                </section>

                <section class="ckm-neg-game" id="ckm-neg-game" hidden>
                    <div class="ckm-neg-mobile-tools">
                        <button type="button" class="ckm-neg-btn" id="ckm-neg-mobile-task">Задача</button>
                        <a class="ckm-neg-btn" href="<?php echo esc_url($returnUrl); ?>"><?php echo $isBuilderTest ? 'Конструктор' : ($isAssignmentSession ? 'Назначение' : 'Сценарии'); ?></a>
                        <button type="button" class="ckm-neg-btn" id="ckm-neg-mobile-state">Ход переговоров</button>
                    </div>
                    <aside class="ckm-neg-panel ckm-neg-task">
                        <div class="ckm-neg-kicker">Задача</div>
                        <h2 id="ckm-neg-game-title"></h2>
                        <h4>Ваша роль</h4><p id="ckm-neg-role"></p>
                        <h4>Цель</h4><div id="ckm-neg-target"></div>
                        <h4>Красные линии</h4><div id="ckm-neg-redlines"></div>
                        <h4>Режим</h4><p id="ckm-neg-mode-label"></p>
                        <h4>Уровень оппонента</h4><p id="ckm-neg-difficulty-label"></p>
                    </aside>
                    <main class="ckm-neg-panel ckm-neg-dialogue">
                        <header class="ckm-neg-opponent"><div><strong id="ckm-neg-opponent-name"></strong><small id="ckm-neg-opponent-role"></small></div><span class="ckm-neg-live-dot"></span></header>
                        <div class="ckm-neg-messages" id="ckm-neg-messages"><div class="ckm-neg-empty">Вы начинаете переговоры. Сформулируйте первую реплику.</div></div>
                        <div class="ckm-neg-compose">
                            <textarea id="ckm-neg-input" rows="4" maxlength="6000" placeholder="Введите вашу реплику…"></textarea>
                            <div class="ckm-neg-coach" id="ckm-neg-coach" hidden>
                                <div class="ckm-neg-coach-head"><button type="button" class="ckm-neg-btn" id="ckm-neg-coach-toggle">ИИ-тренер</button><span class="ckm-neg-coach-counts" id="ckm-neg-coach-counts"></span></div>
                                <div class="ckm-neg-coach-menu" id="ckm-neg-coach-menu" hidden>
                                    <button type="button" class="ckm-neg-coach-choice" data-coach-level="attention">На что обратить внимание</button>
                                    <button type="button" class="ckm-neg-coach-choice" data-coach-level="direction">Подсказать направление</button>
                                    <button type="button" class="ckm-neg-coach-choice" data-coach-level="example">Показать пример реплики</button>
                                    <button type="button" class="ckm-neg-coach-choice" data-coach-level="review_last_move">Разобрать мою последнюю реплику</button>
                                </div>
                                <div class="ckm-neg-coach-history" id="ckm-neg-coach-history"></div>
                            </div>
                            <div class="ckm-neg-compose-actions"><button type="button" class="ckm-neg-btn" id="ckm-neg-pause">Сохранить и выйти</button><button type="button" class="ckm-neg-btn" id="ckm-neg-retry-opponent" hidden>Повторить ответ</button><button type="button" class="ckm-neg-btn" id="ckm-neg-retry-agreement" hidden>Повторить фиксацию соглашения</button><button type="button" class="ckm-neg-btn" id="ckm-neg-agreement">Зафиксировать соглашение</button><button type="button" class="ckm-neg-btn ckm-neg-danger" id="ckm-neg-no-deal">Завершить без соглашения</button><button type="button" class="ckm-neg-btn ckm-neg-primary" id="ckm-neg-send">Отправить</button></div>
                            <p class="ckm-neg-status" id="ckm-neg-status" role="status"></p>
                        </div>
                    </main>
                    <aside class="ckm-neg-panel ckm-neg-state">
                        <div class="ckm-neg-kicker">Ход переговоров</div>
                        <h4>Согласовано</h4><div id="ckm-neg-agreed" class="ckm-neg-state-list"></div>
                        <h4>Обсуждается</h4><div id="ckm-neg-discussing" class="ckm-neg-state-list"></div>
                        <h4>Вы выяснили</h4><div id="ckm-neg-facts" class="ckm-neg-state-list"></div>
                        <h4>Обязательства</h4><div id="ckm-neg-commitments" class="ckm-neg-state-list"></div>
                        <h4>Осталось решить</h4><div id="ckm-neg-remaining" class="ckm-neg-state-list"></div>
                    </aside>
                </section>

                <section class="ckm-neg-completed ckm-neg-card" id="ckm-neg-completed" hidden>
                    <div class="ckm-neg-kicker">Переговоры завершены</div>
                    <h2 id="ckm-neg-completed-title"></h2>
                    <div id="ckm-neg-completed-package"></div>
                    <p id="ckm-neg-completed-note" class="ckm-neg-muted"></p>
                    <div class="ckm-neg-result" id="ckm-neg-result">
                        <div class="ckm-neg-result-loading" id="ckm-neg-result-loading">Готовим итоговый разбор…</div>
                        <div class="ckm-neg-result-ready" id="ckm-neg-result-ready" hidden>
                            <div class="ckm-neg-score-line"><strong id="ckm-neg-score"></strong><span id="ckm-neg-result-type"></span></div>
                            <div class="ckm-neg-redline-result" id="ckm-neg-redline-result"></div>
                            <h3>По критериям</h3><div class="ckm-neg-criteria" id="ckm-neg-criteria"></div>
                            <div class="ckm-neg-result-columns">
                                <div><h3>Сильные стороны</h3><div id="ckm-neg-strengths"></div></div>
                                <div><h3>Что улучшить</h3><div id="ckm-neg-improvements"></div></div>
                            </div>
                            <div class="ckm-neg-zopa-result" id="ckm-neg-zopa-result" hidden>
                                <h3>Можно ли было договориться</h3>
                                <p id="ckm-neg-zopa-summary" class="ckm-neg-muted"></p>
                                <div id="ckm-neg-zopa-details"></div>
                            </div>
                            <div class="ckm-neg-relationship-result" id="ckm-neg-relationship-result" hidden>
                                <h3>Динамика взаимодействия</h3>
                                <div id="ckm-neg-relationship-dynamics"></div>
                            </div>
                            <div class="ckm-neg-independence" id="ckm-neg-independence"></div>
                        </div>
                        <div class="ckm-neg-result-failed" id="ckm-neg-result-failed" hidden>
                            <p>Итоговый разбор временно недоступен. Завершённая попытка сохранена.</p>
                            <button type="button" class="ckm-neg-btn" id="ckm-neg-result-retry">Повторить расчёт</button>
                        </div>
                    </div>
                    <div class="ckm-neg-actions"><?php if (!$isBuilderTest && !$isAssignmentSession): ?><button type="button" class="ckm-neg-btn ckm-neg-primary" id="ckm-neg-replay">Пройти ещё раз</button><?php endif; ?><a class="ckm-neg-btn<?php echo ($isBuilderTest||$isAssignmentSession)?' ckm-neg-primary':''; ?>" href="<?php echo esc_url($returnUrl); ?>"><?php echo $isBuilderTest?'Вернуться в конструктор':($isAssignmentSession?'Вернуться к назначению':'Вернуться к сценариям'); ?></a></div>
                </section>

                <div class="ckm-neg-modal" id="ckm-neg-agreement-modal" hidden>
                    <div class="ckm-neg-modal-card">
                        <div class="ckm-neg-kicker">Итоговый пакет</div><h2>Зафиксировать соглашение</h2>
                        <div id="ckm-neg-agreement-package" class="ckm-neg-package"></div>
                        <div id="ckm-neg-agreement-warning" class="ckm-neg-warning"></div>
                        <div class="ckm-neg-actions"><button type="button" class="ckm-neg-btn" id="ckm-neg-agreement-cancel">Вернуться к переговорам</button><button type="button" class="ckm-neg-btn ckm-neg-primary" id="ckm-neg-agreement-propose">Предложить итоговое соглашение</button></div>
                    </div>
                </div>

                <div class="ckm-neg-modal" id="ckm-neg-no-deal-modal" hidden>
                    <div class="ckm-neg-modal-card">
                        <div class="ckm-neg-kicker">Завершение переговоров</div><h2>Завершить без соглашения?</h2>
                        <div id="ckm-neg-no-deal-summary" class="ckm-neg-package"></div>
                        <label class="ckm-neg-comment-label">Комментарий участника <span>(необязательно)</span><textarea id="ckm-neg-no-deal-comment" rows="3" maxlength="1000"></textarea></label>
                        <div class="ckm-neg-actions"><button type="button" class="ckm-neg-btn" id="ckm-neg-no-deal-cancel">Вернуться</button><button type="button" class="ckm-neg-btn ckm-neg-danger" id="ckm-neg-no-deal-confirm">Завершить без соглашения</button></div>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><h2>Мастер переговоров</h2><p>'.esc_html($error->getMessage()).'</p></div></div>';
        }
    }
}

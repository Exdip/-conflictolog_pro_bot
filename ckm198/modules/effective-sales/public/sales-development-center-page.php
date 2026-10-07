<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesDevelopmentCenterPage {
    private static function url(array $args=[]): string { return SalesPage::url($args); }

    public static function render(array $library): string {
        $requested=sanitize_text_field((string)wp_unslash($_GET['sales_methodology']??''));
        $script=SalesDevelopmentCenterService::selectScript($requested);
        $scripts=SalesScriptService::all();$notice=SalesPage::takeScriptNotice();
        ob_start(); ?>
        <section class="ckm-sales-card ckm-sales-center-hero">
            <div class="ckm-sales-kicker">ЦЕНТР РАЗВИТИЯ ПРОДАЖ</div>
            <h1>От методики — к проверяемому навыку</h1>
            <p class="ckm-sales-lead">Стандарт задаёт обязательные принципы, а адаптивный Полигон учит выбирать действие по ситуации: новый клиент → новая развилка → обратная связь → усложнение → контроль переноса.</p>
            <div class="ckm-sales-center-tabs">
                <a href="#ckm-center-methodology">Методика</a><a href="#ckm-center-polygon">Полигон продаж</a><a href="#ckm-center-check">Проверка</a>
                <a href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers'])); ?>">Практика</a><a href="#ckm-center-real-dialog">Реальный разговор</a><a href="#ckm-center-result">Аналитика</a>
            </div>
        </section>
        <?php if(!empty($notice['message'])): ?><div class="ckm-sales-inline-status <?php echo !empty($notice['error'])?'ckm-sales-error':''; ?>"><?php echo esc_html((string)$notice['message']); ?></div><?php endif; ?>
        <?php if(!$script): ?>
        <section class="ckm-sales-card ckm-sales-center-empty">
            <div class="ckm-sales-kicker">ПЕРВЫЙ ШАГ</div><h2>Создайте методику продаж</h2>
            <p>Задайте продукт, клиента, цель и ограничения. После этого система создаст ИИ-клиента для тренировки и проверки.</p>
            <div class="ckm-sales-card-actions"><a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>">Создать методику</a>
            <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts'])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?>
            <input type="hidden" name="ckm_sales_script_action" value="create_adaptive_template"><button class="ckm-sales-btn" type="submit">Взять готовый пример</button></form></div>
        </section>
        <?php return (string)ob_get_clean(); endif;
        $w=SalesDevelopmentCenterService::workspace($script);$progress=SalesDevelopmentCenterService::progress($w);
        $scenarioId=(int)$w['scenario_id'];$training=(array)$w['training_attempts'];$checks=(array)$w['check_attempts'];
        $latest=is_array($w['latest_check']??null)?$w['latest_check']:null;$scriptId=(string)$script['id'];
        $adaptive=SalesAdaptivePolygonService::recommendation($script);$adaptiveHistory=SalesAdaptivePolygonService::history($script);
        $pendingAdaptive=!empty($adaptive['pending'])&&is_array($adaptive['case']??null)?$adaptive['case']:null; ?>
        <section class="ckm-sales-center-progress" aria-label="Цикл развития продаж"><?php foreach($progress as $i=>$step): ?>
            <div class="<?php echo !empty($step['done'])?'is-done':''; ?>"><b><?php echo (int)($i+1); ?></b><span><?php echo esc_html((string)$step['title']); ?></span></div>
        <?php endforeach; ?></section>
        <section class="ckm-sales-card ckm-sales-center-method-picker"><div><div class="ckm-sales-kicker">АКТИВНАЯ МЕТОДИКА</div>
            <strong><?php echo esc_html((string)$script['title']); ?></strong><span><?php echo esc_html((string)$script['situation_class']); ?> · <?php echo (string)$script['status']==='approved'?'утверждена':'черновик'; ?></span></div>
            <?php if(count($scripts)>1): ?><div class="ckm-sales-center-method-list"><?php foreach($scripts as $item): ?>
            <a class="<?php echo (string)($item['id']??'')===$scriptId?'is-active':''; ?>" href="<?php echo esc_url(self::url(['sales_methodology'=>(string)($item['id']??'')])); ?>"><?php echo esc_html((string)($item['title']??'Методика')); ?></a>
            <?php endforeach; ?></div><?php endif; ?>
        </section>

        <section class="ckm-sales-section" id="ckm-center-methodology">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">01 · МЕТОДИКА</div><h2>Что именно должен уметь продавец</h2></div>
            <span class="ckm-sales-status <?php echo !empty($w['methodology_ready'])?'is-approved':''; ?>"><?php echo !empty($w['methodology_ready'])?'Утверждена':'Нужно утвердить'; ?></span></div>
            <div class="ckm-sales-center-two">
                <article class="ckm-sales-card ckm-sales-center-panel"><div class="ckm-sales-center-facts">
                    <div><small>Продукт</small><p><?php echo esc_html((string)$script['product']); ?></p></div>
                    <div><small>Клиент</small><p><?php echo esc_html((string)$script['client']); ?></p></div>
                    <div><small>Цель разговора</small><p><?php echo esc_html((string)$script['goal']); ?></p></div>
                    <div><small>Ограничения</small><p><?php echo esc_html((string)$script['constraints']); ?></p></div>
                </div><div class="ckm-sales-card-actions"><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>$scriptId])); ?>">Открыть и изменить методику</a></div></article>
                <article class="ckm-sales-card ckm-sales-center-panel"><div class="ckm-sales-kicker">КАРТА ДЕЙСТВИЙ</div>
                <div class="ckm-sales-script-steps ckm-sales-center-playbook"><?php foreach((array)$w['playbook'] as $i=>$row): ?>
                    <article><b><?php echo (int)($i+1); ?></b><div><h3><?php echo esc_html((string)($row[0]??'')); ?></h3><p><?php echo esc_html((string)($row[1]??'')); ?></p></div></article>
                <?php endforeach; ?></div></article>
            </div>
        </section>
        <section class="ckm-sales-card ckm-sales-center-criteria">
            <div class="ckm-sales-kicker">ЕДИНЫЙ СТАНДАРТ ПРОВЕРКИ · 100 БАЛЛОВ</div>
            <div class="ckm-sales-center-criteria-grid"><?php foreach((array)$w['criteria'] as $criterion): ?>
                <div><strong><?php echo esc_html((string)$criterion['weight']); ?></strong><h3><?php echo esc_html((string)$criterion['title']); ?></h3><p><?php echo esc_html((string)$criterion['description']); ?></p></div>
            <?php endforeach; ?></div>
            <p class="ckm-sales-muted">Порог первого контрольного цикла: <?php echo (int)$w['pass_score']; ?>/100. В следующих версиях порог станет настройкой методики.</p>
        </section>

        <section class="ckm-sales-section" id="ckm-center-polygon">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">02 · АДАПТИВНЫЙ ПОЛИГОН ПРОДАЖ</div><h2>Тренировать не реплику, а выбор действия</h2></div><span class="ckm-sales-count"><?php echo count($adaptiveHistory); ?> завершённых ситуаций</span></div>
            <div class="ckm-sales-card ckm-sales-center-action"><div><strong>Базовая тренировка</strong><p>Сначала сотрудник проходит обычную ситуацию по методике. После результата Полигон определяет слабое место и начинает менять условия следующих кейсов.</p></div>
            <?php if(!$w['methodology_ready']): ?>
                <a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>$scriptId])); ?>">Сначала утвердить методику</a>
            <?php elseif(!$w['polygon_ready']): ?>
                <form method="post" action="<?php echo esc_url(self::url(['sales_view'=>'scripts','sales_script'=>$scriptId])); ?>"><?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?>
                <input type="hidden" name="ckm_sales_script_action" value="generate_client"><input type="hidden" name="script_id" value="<?php echo esc_attr($scriptId); ?>">
                <button class="ckm-sales-btn ckm-sales-primary" type="submit">Создать базового ИИ-клиента</button></form>
            <?php else: ?>
                <a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>$scenarioId,'sales_format'=>'training','sales_methodology'=>$scriptId])); ?>">Базовая тренировка</a>
            <?php endif; ?></div>

            <?php if($w['methodology_ready']&&$w['polygon_ready']): ?>
            <?php $isTransfer=$pendingAdaptive&&!empty($pendingAdaptive['transfer']);$isMastered=!empty($adaptive['mastered']); ?>
            <article class="ckm-sales-card ckm-sales-adaptive-card <?php echo $isTransfer?'is-transfer':($isMastered?'is-mastered':''); ?>">
                <div class="ckm-sales-adaptive-head">
                    <div><div class="ckm-sales-kicker"><?php echo $isMastered?'НАВЫК ПОДТВЕРЖДЁН':($isTransfer?'КОНТРОЛЬ ПЕРЕНОСА':'СЛЕДУЮЩАЯ АДАПТИВНАЯ СИТУАЦИЯ'); ?></div>
                    <h3><?php echo esc_html($isMastered?'Адаптивный цикл завершён':($isTransfer?'Незнакомая ситуация':($pendingAdaptive?(string)($pendingAdaptive['label']??'Адаптивный кейс'):(string)($adaptive['focus_title']??'Ситуационная адаптивность')))); ?></h3></div>
                    <?php if(!$pendingAdaptive&&!$isMastered): ?><span class="ckm-sales-adaptive-level">уровень <?php echo (int)($adaptive['level']??1); ?></span><?php endif; ?>
                </div>
                <p class="ckm-sales-adaptive-why"><?php echo esc_html($isTransfer?'Система проверит, переносится ли навык на новую ситуацию без подсказки о том, что именно сейчас проверяется.':(string)($pendingAdaptive['why']??$adaptive['why']??'')); ?></p>
                <div class="ckm-sales-adaptive-task">
                    <small><?php echo $isTransfer?'Условия контроля':($isMastered?'Результат':'Что меняется в ситуации'); ?></small>
                    <p><?php echo esc_html($isTransfer?'Слабое место и логика кейса заранее скрыты. Ориентируйтесь только на ответы и поведение клиента.':(string)($pendingAdaptive['brief']??$adaptive['brief']??'')); ?></p>
                </div>
                <div class="ckm-sales-card-actions">
                <?php if(!$w['training_done']&&!$pendingAdaptive): ?>
                    <span class="ckm-sales-muted">Сначала завершите базовую тренировку — после неё следующий кейс будет выбран по фактическому результату.</span>
                <?php elseif($isMastered): ?>
                    <span class="ckm-sales-status is-approved">Устойчивость подтверждена на незнакомой ситуации</span>
                <?php elseif($pendingAdaptive):
                    $adaptiveScenario=(int)($pendingAdaptive['scenario_id']??0); ?>
                    <?php if($isTransfer): ?>
                        <button class="ckm-sales-btn ckm-sales-primary" type="button" data-sales-center-check data-scenario-id="<?php echo $adaptiveScenario; ?>" data-script-id="<?php echo esc_attr($scriptId); ?>">Пройти контроль переноса</button>
                    <?php else: ?>
                        <a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>$adaptiveScenario,'sales_format'=>'training','sales_methodology'=>$scriptId])); ?>">Пройти следующий кейс</a>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="post" action="<?php echo esc_url(self::url(['sales_methodology'=>$scriptId])); ?>">
                        <?php wp_nonce_field('ckm_sales_script','_ckm_sales_script_nonce'); ?>
                        <input type="hidden" name="ckm_sales_script_action" value="adaptive_next_case"><input type="hidden" name="script_id" value="<?php echo esc_attr($scriptId); ?>">
                        <button class="ckm-sales-btn ckm-sales-primary" type="submit"><?php echo !empty($adaptive['transfer'])?'Сформировать контроль переноса':'Подобрать следующий кейс'; ?></button>
                    </form>
                <?php endif; ?>
                </div>
            </article>
            <?php endif; ?>

            <?php if($adaptiveHistory): ?><div class="ckm-sales-adaptive-history">
                <?php foreach(array_slice($adaptiveHistory,0,6) as $h):
                    $res=(array)($h['result']??[]);$score=(int)round((float)($res['final_score']??0));$focus=(string)($h['focus_code']??'');
                    $catalog=SalesAdaptivePolygonService::focusCatalog();$focusTitle=$focus!==''?(string)($catalog[$focus]['title']??$focus):'Базовая ситуация'; ?>
                    <div><span><?php echo !empty($h['transfer'])?'Перенос':($focusTitle!==''?esc_html($focusTitle):'База'); ?></span><strong><?php echo $score; ?>/100</strong><small><?php echo esc_html((string)($res['completed_at']??$h['attempt']['completed_at']??'')); ?></small></div>
                <?php endforeach; ?>
            </div><?php endif; ?>
            <div class="ckm-sales-inline-status" id="ckm-sales-adaptive-status" aria-live="polite"></div>
        </section>

        <section class="ckm-sales-section" id="ckm-center-real-dialog">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">РЕАЛЬНАЯ ПРАКТИКА → ПОЛИГОН</div><h2>Превратить реальный разговор в тренировочный кейс</h2></div></div>
            <div class="ckm-sales-card ckm-sales-real-dialog-card" id="ckm-sales-real-dialog-import" data-script-id="<?php echo esc_attr($scriptId); ?>">
                <div class="ckm-sales-real-dialog-intro">
                    <div><strong>Звонок или чат сотрудника</strong><p>Вставьте расшифровку разговора или загрузите текстовый файл. Система сохранит разговор в контуре практики и создаст обезличенную ситуацию для Полигона.</p></div>
                    <span class="ckm-sales-status is-approved">Контакты не попадут в кейс</span>
                </div>
                <div class="ckm-sales-real-dialog-grid">
                    <label><span>Канал</span><select id="ckm-sales-real-dialog-channel"><option value="phone">Телефонный звонок</option><option value="telegram">Telegram</option><option value="max">MAX</option><option value="whatsapp">WhatsApp</option><option value="web">Другой чат</option></select></label>
                    <label><span>Исход разговора</span><select id="ckm-sales-real-dialog-outcome"><option value="unsuccessful">Сделка не состоялась</option><option value="stalled">Диалог остановился</option><option value="successful">Успешный разговор</option></select></label>
                    <label class="ckm-sales-real-dialog-file"><span>Загрузить расшифровку</span><input id="ckm-sales-real-dialog-file" type="file" accept=".txt,.md,.csv,text/plain,text/markdown,text/csv"><small>TXT, MD или CSV. Файл читается в браузере и отправляется как текст.</small></label>
                </div>
                <label class="ckm-sales-real-dialog-text"><span>Расшифровка разговора</span>
                    <textarea id="ckm-sales-real-dialog-text" rows="12" placeholder="Клиент: Нам кажется, что это слишком дорого.&#10;Менеджер: Давайте я ещё раз расскажу о преимуществах.&#10;Клиент: Цена всё равно выше, чем мы планировали."></textarea>
                </label>
                <div class="ckm-sales-real-dialog-help">
                    <strong>Как оформить</strong><span>Каждая реплика с новой строки: <b>Клиент:</b> … и <b>Менеджер:</b> … (также распознаются «Покупатель», «Продавец», «Сотрудник», «Оператор»).</span>
                </div>
                <div class="ckm-sales-card-actions">
                    <button class="ckm-sales-btn ckm-sales-primary" type="button" id="ckm-sales-real-dialog-create">Создать тренировочный кейс</button>
                    <span class="ckm-sales-muted">Исходный разговор остаётся в журнале практики; в тренировочный сценарий передаётся только обезличенный паттерн.</span>
                </div>
                <div class="ckm-sales-inline-status" id="ckm-sales-real-dialog-status" aria-live="polite"></div>
            </div>
        </section>

        <section class="ckm-sales-section" id="ckm-center-check">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">03 · ПРОВЕРКА СТАНДАРТА</div><h2>Контрольный срез без подсказок</h2></div><span class="ckm-sales-count"><?php echo count($checks); ?> проверок</span></div>
            <div class="ckm-sales-card ckm-sales-center-action is-check"><div><strong>Базовый контроль</strong><p>Здесь проверяется соблюдение корпоративного стандарта на исходной ситуации. Отдельный контроль переноса появляется на адаптивном Полигоне после серии вариативных тренировок.</p></div>
            <button class="ckm-sales-btn ckm-sales-primary" type="button" data-sales-center-check data-scenario-id="<?php echo $scenarioId; ?>" data-script-id="<?php echo esc_attr($scriptId); ?>" <?php echo (!$w['methodology_ready']||!$w['polygon_ready'])?'disabled':''; ?>>Начать проверку</button></div>
            <div class="ckm-sales-inline-status" id="ckm-sales-center-check-status" aria-live="polite"></div>
        </section>
        <section class="ckm-sales-section" id="ckm-center-result">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">04 · РЕЗУЛЬТАТ РУКОВОДИТЕЛЮ</div><h2>Не впечатление, а измеримый результат</h2></div></div>
            <?php if(!$latest): ?>
                <div class="ckm-sales-card ckm-sales-center-empty-result"><strong>Контрольной оценки пока нет</strong><p>После первой завершённой проверки здесь появятся общий балл, пять критериев и приоритетная зона развития.</p></div>
            <?php else:
                $attempt=(array)$latest['attempt'];$result=(array)$latest['result'];$summary=(array)($result['summary']??[]);
                $score=(int)round((float)($result['final_score']??0));$passed=!empty($w['passed']); ?>
                <div class="ckm-sales-center-result-grid">
                    <article class="ckm-sales-card ckm-sales-center-score <?php echo $passed?'is-pass':'is-repeat'; ?>">
                        <small>Последняя проверка</small><strong><?php echo $score; ?>/100</strong><b><?php echo $passed?'Методика освоена':'Нужно повторить'; ?></b>
                        <span>Порог: <?php echo (int)$w['pass_score']; ?>/100 · <?php echo esc_html((string)($result['completed_at']??$attempt['completed_at']??'')); ?></span>
                    </article>
                    <article class="ckm-sales-card ckm-sales-center-result-detail">
                        <div class="ckm-sales-center-result-criteria"><?php foreach((array)($result['criteria']??[]) as $criterion): ?>
                            <div><strong><?php echo (int)round((float)($criterion['raw_score']??0)); ?></strong><span><?php echo esc_html((string)($criterion['title']??$criterion['code']??'')); ?></span><small>вес <?php echo esc_html((string)($criterion['weight']??0)); ?>%</small></div>
                        <?php endforeach; ?></div>
                        <?php if(is_array($w['lowest_criterion']??null)): ?><div class="ckm-sales-center-recommendation"><strong>Приоритет следующей тренировки</strong><span><?php echo esc_html((string)($w['lowest_criterion']['title']??'')); ?> — <?php echo (int)round((float)($w['lowest_criterion']['raw_score']??0)); ?>/100</span></div><?php endif; ?>
                        <div class="ckm-sales-feedback-grid">
                            <div><h4>Что получилось</h4><?php $strengths=(array)($summary['strengths']??[]);if(!$strengths): ?><p class="ckm-sales-muted">Сильные стороны появятся после более полного результата.</p><?php else: foreach($strengths as $x): ?><p><?php echo esc_html((string)($x['text']??'')); ?></p><?php endforeach; endif; ?></div>
                            <div><h4>Что улучшить</h4><?php $improvements=(array)($summary['improvements']??[]);if(!$improvements): ?><p class="ckm-sales-muted">Критичных зон развития не выявлено.</p><?php else: foreach($improvements as $x): ?><p><?php echo esc_html((string)($x['text']??'')); ?></p><?php endforeach; endif; ?></div>
                        </div>
                        <div class="ckm-sales-card-actions">
                            <a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>$scenarioId,'sales_session'=>(int)$attempt['id'],'sales_format'=>'check','sales_methodology'=>$scriptId])); ?>">Открыть результат</a>
                            <a class="ckm-sales-btn ckm-sales-primary" href="<?php echo esc_url(self::url(['sales_custom_scenario'=>$scenarioId,'sales_format'=>'training','sales_methodology'=>$scriptId])); ?>">Повторить на Полигоне</a>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </section>
        <section class="ckm-sales-section ckm-sales-center-next">
            <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ДАЛЬШЕ</div><h2>Практика замкнёт цикл</h2></div></div>
            <div class="ckm-sales-mode-grid ckm-sales-mode-grid-two">
                <article class="ckm-sales-card ckm-sales-mode"><span>💬</span><h3>Практика</h3><p>Существующий ИИ-продавец и реальные диалоги остаются рабочим контуром применения методики.</p>
                    <div class="ckm-sales-card-actions"><a class="ckm-sales-btn" href="<?php echo esc_url(self::url(['sales_view'=>'ai-sellers'])); ?>">Открыть практику</a></div></article>
                <article class="ckm-sales-card ckm-sales-mode"><span>📈</span><h3>Командная аналитика</h3><p>Следующий этап: сравнение сотрудников, динамика по критериям и превращение реальных ошибок в новые тренировочные кейсы.</p><small>Следующий крупный блок после .558</small></article>
            </div>
        </section>
        <?php return (string)ob_get_clean();
    }
}

<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class SalesTeamDevelopmentPage {
    private static function score($v): string { return $v===null?'—':(string)(int)round((float)$v).'/100'; }
    private static function delta(float $v): string {
        $n=(int)round($v);if($n===0)return '0';return ($n>0?'+':'').$n;
    }
    private static function scoreClass($v): string {
        if($v===null)return 'is-empty';$n=(float)$v;
        return $n>=75?'is-good':($n>=50?'is-mid':'is-low');
    }

    public static function render(): string {
        $d=SalesTeamDevelopmentService::dashboard();$employees=(array)$d['employees'];$criteria=(array)$d['team_criteria'];$recommendations=(array)$d['recommendations'];$practice=SalesPracticeFeedbackService::teamEffectiveness();$notice=SalesPage::takeScriptNotice();
        ob_start(); ?>
        <?php if($notice): ?><div class="ckm-sales-card ckm-sales-workspace-notice <?php echo !empty($notice['error'])?'is-error':''; ?>"><?php echo esc_html((string)($notice['message']??'')); ?></div><?php endif; ?>
        <section class="ckm-sales-card ckm-sales-script-hero ckm-sales-team-hero">
          <div class="ckm-sales-kicker">УПРАВЛЕНИЕ РАЗВИТИЕМ КОМАНДЫ</div>
          <h1>Где отдел теряет качество продаж — и кого тренировать следующим</h1>
          <p class="ckm-sales-lead">Экран собирается из уже завершённых тренировок и проверок. Для каждого сотрудника берётся его актуальный результат по пяти критериям, поэтому частые попытки одного человека не искажают картину отдела.</p>
          <div class="ckm-sales-team-metrics">
            <div><small>Сотрудников</small><strong><?php echo (int)$d['employee_count']; ?></strong></div>
            <div><small>Завершённых попыток</small><strong><?php echo (int)$d['attempt_count']; ?></strong></div>
            <div><small>Текущий средний балл</small><strong><?php echo esc_html(self::score($d['team_latest_average'])); ?></strong></div>
            <div><small>Нужна тренировка</small><strong><?php echo (int)$d['needs_training']; ?></strong></div>
          </div>
        </section>

        <section class="ckm-sales-section ckm-sales-practice-effectiveness">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ЭФФЕКТ В РЕАЛЬНОЙ ПРАКТИКЕ</div><h2>Исправляются ли ошибки после тренировки</h2></div><span class="ckm-sales-muted">Учитываются только связанные реальные разговоры сотрудников</span></div>
          <div class="ckm-sales-practice-effectiveness-metrics">
            <article class="ckm-sales-card"><small>Реальных ошибок</small><strong><?php echo (int)$practice['errors']; ?></strong></article>
            <article class="ckm-sales-card"><small>Пройдено тренировок</small><strong><?php echo (int)$practice['trained']; ?></strong></article>
            <article class="ckm-sales-card"><small>Перенос подтверждён</small><strong><?php echo (int)$practice['confirmed']; ?></strong></article>
            <article class="ckm-sales-card"><small>Ошибка повторилась</small><strong><?php echo (int)$practice['repeated']; ?></strong></article>
            <article class="ckm-sales-card"><small>Эффективность переноса</small><strong><?php echo $practice['rate']===null?'—':esc_html((string)$practice['rate']).'%'; ?></strong></article>
          </div>
          <?php if(!empty($practice['employees'])): ?>
          <div class="ckm-sales-practice-effectiveness-table-wrap"><table class="ckm-sales-practice-effectiveness-table">
            <thead><tr><th>Сотрудник</th><th>Ошибки</th><th>Тренировки</th><th>Подтверждено</th><th>Повторилось</th><th>Ждём практику</th><th>Перенос</th></tr></thead>
            <tbody><?php foreach((array)$practice['employees'] as $row): ?>
              <tr>
                <td><strong><?php echo esc_html((string)$row['label']); ?></strong></td>
                <td><?php echo (int)$row['errors']; ?></td>
                <td><?php echo (int)$row['trained']; ?></td>
                <td><?php echo (int)$row['confirmed']; ?></td>
                <td><?php echo (int)$row['repeated']; ?></td>
                <td><?php echo (int)$row['pending']; ?></td>
                <td><span class="ckm-sales-status <?php echo $row['rate']!==null&&(float)$row['rate']>=75?'is-approved':''; ?>"><?php echo $row['rate']===null?'Нет данных':esc_html((string)$row['rate']).'%'; ?></span></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table></div>
          <?php else: ?>
            <div class="ckm-sales-card ckm-sales-team-empty"><h3>Пока нет замкнутых циклов</h3><p>После привязки реального разговора к сотруднику, назначения кейса и следующего разговора здесь появится фактический эффект обучения.</p></div>
          <?php endif; ?>
        </section>

        <section class="ckm-sales-section">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">КАРТА ОТДЕЛА</div><h2>Пять критериев качества</h2></div><span class="ckm-sales-muted">Порог устойчивого навыка: <?php echo (int)$d['pass_score']; ?>/100</span></div>
          <?php if(!$employees): ?>
            <div class="ckm-sales-card ckm-sales-team-empty"><h3>Пока нет данных для сравнения</h3><p>Завершите хотя бы одну тренировку или проверку сотрудника. После появления оценки она автоматически попадёт сюда.</p></div>
          <?php else: ?>
          <div class="ckm-sales-team-criteria">
            <?php foreach($criteria as $row): ?>
              <article class="ckm-sales-card ckm-sales-team-criterion <?php echo esc_attr(self::scoreClass($row['score'])); ?>">
                <small><?php echo esc_html((string)$row['title']); ?></small>
                <strong><?php echo esc_html(self::score($row['score'])); ?></strong>
                <div class="ckm-sales-team-bar"><i style="width:<?php echo esc_attr((string)max(0,min(100,(float)($row['score']??0)))); ?>%"></i></div>
                <span><?php echo (int)$row['people']; ?> чел.</span>
              </article>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <?php if($employees): ?>
        <section class="ckm-sales-card ckm-sales-team-table-card">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">СОТРУДНИКИ</div><h2>Текущий уровень и динамика</h2></div></div>
          <div class="ckm-sales-team-table-wrap"><table class="ckm-sales-team-table">
            <thead><tr><th>Сотрудник</th><th>Попытки</th><th>Сейчас</th><th>Динамика</th><th>Слабое место</th><th>Что делать</th></tr></thead>
            <tbody><?php foreach($employees as $e):$weak=$e['weakest']??null; ?>
              <tr>
                <td><strong><?php echo esc_html((string)$e['label']); ?></strong><small><?php echo esc_html((string)$e['latest_context']); ?></small></td>
                <td><?php echo (int)$e['attempt_count']; ?></td>
                <td><span class="ckm-sales-team-score <?php echo esc_attr(self::scoreClass($e['latest_score'])); ?>"><?php echo esc_html(self::score($e['latest_score'])); ?></span></td>
                <td><span class="ckm-sales-team-delta <?php echo (float)$e['delta']>0?'is-up':((float)$e['delta']<0?'is-down':''); ?>"><?php echo esc_html(self::delta((float)$e['delta'])); ?></span></td>
                <td><?php if($weak): ?><strong><?php echo esc_html((string)$weak['title']); ?></strong><small><?php echo esc_html(self::score($weak['score'])); ?></small><?php else: ?>—<?php endif; ?></td>
                <td><?php echo esc_html((string)$e['recommendation']); ?></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table></div>
        </section>

        <section class="ckm-sales-section">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">КОГО И ЧЕМУ ТРЕНИРОВАТЬ</div><h2>Очередь развития</h2></div></div>
          <?php if(!$recommendations): ?>
            <div class="ckm-sales-card ckm-sales-team-empty"><h3>Критических зон нет</h3><p>У всех сотрудников слабейший актуальный критерий не ниже <?php echo (int)$d['pass_score']; ?>. Следующий шаг — контроль переноса и поддерживающие кейсы.</p></div>
          <?php else: ?><div class="ckm-sales-team-recommendations">
            <?php foreach($recommendations as $i=>$r):$assigned=is_array($r['assignment']??null)?$r['assignment']:null; ?>
              <article class="ckm-sales-card"><span class="ckm-sales-team-rank"><?php echo (int)($i+1); ?></span><div class="ckm-sales-team-rec-body"><strong><?php echo esc_html((string)$r['label']); ?></strong><h3><?php echo esc_html((string)$r['focus_title']); ?> · <?php echo esc_html(self::score($r['score'])); ?></h3><p><?php echo esc_html((string)$r['action']); ?></p>
                <div class="ckm-sales-team-rec-actions">
                <?php if($assigned): ?>
                  <span class="ckm-sales-status is-approved"><?php echo esc_html((string)($assigned['status']??'Назначено')); ?></span>
                  <?php if(!empty($assigned['assignment_url'])): ?><a class="ckm-sales-btn" href="<?php echo esc_url((string)$assigned['assignment_url']); ?>">Открыть назначение</a><?php endif; ?>
                <?php elseif((int)($r['user_id']??0)>0): ?>
                  <form method="post" action="<?php echo esc_url(SalesPage::url(['sales_view'=>'results'])); ?>">
                    <?php wp_nonce_field('ckm_sales_team_development','_ckm_sales_team_nonce'); ?>
                    <input type="hidden" name="ckm_sales_team_action" value="assign_training">
                    <input type="hidden" name="participant_key" value="<?php echo esc_attr((string)$r['participant_key']); ?>">
                    <input type="hidden" name="focus_code" value="<?php echo esc_attr((string)$r['focus_code']); ?>">
                    <input type="hidden" name="weak_score" value="<?php echo esc_attr((string)$r['score']); ?>">
                    <input type="hidden" name="script_id" value="<?php echo esc_attr((string)$r['script_id']); ?>">
                    <button class="ckm-sales-btn ckm-sales-primary" type="submit">Назначить тренировку</button>
                  </form>
                <?php endif; ?>
                </div>
              </div></article>
            <?php endforeach; ?>
          </div><?php endif; ?>
        </section>

        <section class="ckm-sales-section">
          <div class="ckm-sales-section-head"><div><div class="ckm-sales-kicker">ИСТОРИЯ СОТРУДНИКА</div><h2>Как меняются результаты</h2></div><span class="ckm-sales-muted">Последние 8 завершённых попыток</span></div>
          <div class="ckm-sales-team-history-grid">
          <?php foreach($employees as $e): ?>
            <article class="ckm-sales-card ckm-sales-team-history">
              <div class="ckm-sales-team-history-head"><div><strong><?php echo esc_html((string)$e['label']); ?></strong><small><?php echo (int)$e['attempt_count']; ?> завершённых попыток</small></div><span class="ckm-sales-team-delta <?php echo (float)$e['delta']>0?'is-up':((float)$e['delta']<0?'is-down':''); ?>"><?php echo esc_html(self::delta((float)$e['delta'])); ?></span></div>
              <div class="ckm-sales-team-mini-criteria">
              <?php foreach((array)$e['criteria'] as $c): ?><div><span><?php echo esc_html((string)$c['title']); ?></span><b><?php echo esc_html(self::score($c['latest'])); ?></b></div><?php endforeach; ?>
              </div>
              <div class="ckm-sales-team-history-list">
              <?php foreach((array)$e['history'] as $h): ?><div><span><?php echo esc_html((string)($h['completed_at']??'')); ?></span><span><?php echo esc_html((string)($h['focus_title']??'Базовая ситуация')); ?></span><b><?php echo esc_html(self::score($h['final_score']??null)); ?></b></div><?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
          </div>
        </section>
        <?php endif; ?>
        <?php return (string)ob_get_clean();
    }
}

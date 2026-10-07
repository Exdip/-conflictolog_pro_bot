<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

final class AssignmentPage {
    private const PAGE_OPTION='ckm_neg_assignment_page_id';
    private const PAGE_VERSION_OPTION='ckm_neg_assignment_page_version';
    private const PAGE_VERSION='1';

    public static function register(): void { add_shortcode('ckm_negotiation_assignments',[self::class,'render']); }
    public static function url(array $args=[]): string {
        $id=(int)get_option(self::PAGE_OPTION,0);
        $base=$id>0&&get_post($id)?(string)get_permalink($id):home_url('/ckm-negotiation-assignments/');
        if(function_exists('ckmqp_tenant_link'))$base=ckmqp_tenant_link($base,ckmqp_scope_id());
        return $args?add_query_arg($args,$base):$base;
    }
    public static function maybeInstall(): void { if(get_option(self::PAGE_VERSION_OPTION,'')!==self::PAGE_VERSION)self::install(); }
    public static function install(): void {
        $id=(int)get_option(self::PAGE_OPTION,0);
        if($id>0&&get_post($id)){update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $existing=get_page_by_path('ckm-negotiation-assignments');
        if($existing){update_option(self::PAGE_OPTION,(int)$existing->ID,false);update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);return;}
        $id=wp_insert_post(['post_title'=>'Назначения — Мастер переговоров','post_name'=>'ckm-negotiation-assignments','post_status'=>'publish','post_type'=>'page','post_content'=>'[ckm_negotiation_assignments]']);
        if(!is_wp_error($id)&&(int)$id>0){update_option(self::PAGE_OPTION,(int)$id,false);update_option(self::PAGE_VERSION_OPTION,self::PAGE_VERSION,false);}
    }
    private static function enqueue(bool $manager): void {
        $base=CKM_QUIZ_PRO_URL.'modules/negotiation-master/assets/';
        wp_enqueue_style('ckm-neg-session',$base.'negotiation-session.css',[],CKM_QUIZ_PRO_VERSION);
        wp_enqueue_style('ckm-neg-assignments',$base.'negotiation-assignments.css',['ckm-neg-session'],CKM_QUIZ_PRO_VERSION);
        wp_enqueue_script('ckm-neg-assignments',$base.'negotiation-assignments.js',[],CKM_QUIZ_PRO_VERSION,true);
        wp_localize_script('ckm-neg-assignments','CKMNegAssignmentsConfig',[
            'restBase'=>esc_url_raw(rest_url('ckm/v1/negotiation')),'nonce'=>wp_create_nonce('wp_rest'),'manager'=>$manager,
            'pageUrl'=>esc_url_raw(self::url()),'catalogUrl'=>esc_url_raw(ProductCatalog::url()),'requestedAssignment'=>isset($_GET['assignment'])?absint($_GET['assignment']):0,
        ]);
    }
    public static function render(): string {
        if(!is_user_logged_in()){
            $target=self::url(isset($_GET['assignment'])?['assignment'=>absint($_GET['assignment'])]:[]);
            $url=wp_login_url($target);return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><h2>Назначения</h2><p>Для просмотра назначений необходимо войти.</p><a class="ckm-neg-btn ckm-neg-primary" href="'.esc_url($url).'">Войти</a></div></div>';
        }
        try{Access::context();}catch(\Throwable $e){return '<div class="ckm-neg-shell"><div class="ckm-neg-card"><h2>Назначения</h2><p>'.esc_html($e->getMessage()).'</p></div></div>';}
        $manager=AssignmentService::canManage();self::enqueue($manager);
        ob_start(); ?>
        <div class="ckm-neg-shell ckm-neg-assignment-shell" id="ckm-neg-assignments-app">
            <section class="ckm-neg-card ckm-neg-catalog-hero">
                <a class="ckm-neg-back" href="<?php echo esc_url(ProductCatalog::url()); ?>">← Все сценарии</a>
                <h1>Назначения</h1>
                <p class="ckm-neg-lead">Организатор закрепляет конкретную версию сценария, режим, уровень оппонента, число попыток и дедлайн. Участник проходит назначение в обычном игровом движке.</p>
            </section>
            <?php if($manager): ?>
            <section class="ckm-neg-card" id="ckm-neg-assignment-create-card">
                <div class="ckm-neg-assignment-head"><div><div class="ckm-neg-kicker">Организатор</div><h2>Создать назначение</h2></div></div>
                <form id="ckm-neg-assignment-create" class="ckm-neg-assignment-form">
                    <label>Название<input id="na-title" maxlength="255" placeholder="Например: Переговоры для отдела продаж"></label>
                    <label>Сценарий<select id="na-scenario" required><option value="">Загрузка…</option></select></label>
                    <label>Режим<select id="na-mode"><option value="training">Тренировка</option><option value="exam">Экзамен</option></select></label>
                    <label>Уровень оппонента<select id="na-difficulty"><option value="soft">Мягкий</option><option value="medium" selected>Средний</option><option value="hard">Жёсткий</option><option value="expert">Эксперт</option></select></label>
                    <label>Формат<select id="na-assignment-mode"><option value="individual">Индивидуально</option><option value="team_shared">Общая сессия команды</option></select></label>
                    <label>Максимум попыток<input id="na-attempts" type="number" min="1" max="20" value="1"></label>
                    <label>Дедлайн<input id="na-deadline" type="datetime-local"></label>
                    <label>Результат участнику<select id="na-result-visibility"><option value="immediate">Сразу после завершения</option><option value="after_deadline">После дедлайна / закрытия</option><option value="organizer_only">Только организатору</option></select></label>
                    <label class="ckm-neg-inline-check"><input id="na-voice" type="checkbox" checked> Разрешить голосовой ввод</label>
                    <div class="ckm-neg-actions"><button type="submit" class="ckm-neg-btn ckm-neg-primary">Создать черновик</button></div>
                </form><p class="ckm-neg-status" id="na-create-status"></p>
            </section>
            <section><div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Организатор</div><h2>Мои назначения</h2></div></div><div id="na-manager-list" class="ckm-neg-assignment-list"></div></section>
            <?php endif; ?>
            <section><div class="ckm-neg-catalog-head"><div><div class="ckm-neg-kicker">Участник</div><h2>Мои задания</h2></div></div><div id="na-mine-list" class="ckm-neg-assignment-list"></div></section>
            <div class="ckm-neg-modal" id="na-detail-modal" hidden><div class="ckm-neg-modal-card ckm-neg-assignment-modal"><button type="button" class="ckm-neg-builder-remove" id="na-detail-close">×</button><div id="na-detail"></div></div></div>
        </div>
        <?php return (string)ob_get_clean();
    }
}

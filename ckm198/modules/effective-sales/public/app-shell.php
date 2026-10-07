<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

final class AppShell {
    public static function active(): bool {
        $id=(int)get_queried_object_id();
        return ($id>0 && $id===(int)get_option('ckm_sales_page_id',0)) || is_page('ckm-sales-master');
    }

    public static function template(string $template): string {
        if (!self::active()) { return $template; }
        $own=__DIR__.'/app-template.php';
        return is_file($own)?$own:$template;
    }

    public static function enqueue(): void {
        if (!self::active()) { return; }
        $base=CKM_QUIZ_PRO_URL.'modules/effective-sales/assets/';
        wp_enqueue_style('ckm-sales-fonts','https://fonts.googleapis.com/css2?family=Caveat:wght@500;600;700&family=Ubuntu:ital,wght@0,400;0,500;0,700;1,400&display=swap',[],null);
        wp_enqueue_style('ckm-sales-app',$base.'sales-app.css',['ckm-sales-fonts'],CKM_QUIZ_PRO_VERSION);
        wp_enqueue_script('ckm-sales-session',$base.'sales-session.js',[],CKM_QUIZ_PRO_VERSION,true);
        if((string)($_GET['sales_view']??'')==='ai-seller')wp_enqueue_script('ckm-sales-ai-seller',$base.'sales-ai-seller.js',[],CKM_QUIZ_PRO_VERSION,true);
        $teamAuth=SalesCompetitionService::currentTeamAuthArgs();
        wp_localize_script('ckm-sales-session','CKMSales',[
            'rest'=>esc_url_raw(rest_url('ckm/v1/sales/')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'page'=>SalesPage::url(),
            'teamJoin'=>(string)($teamAuth['sales_team_join']??''),
            'teamSig'=>(string)($teamAuth['sales_team_sig']??''),
        ]);
    }

    public static function bodyClass(array $classes): array {
        if (self::active()) { $classes[]='ckm-sales-app-document'; }
        return array_values(array_unique($classes));
    }

    public static function showAdminBar(bool $show): bool { return self::active()?false:$show; }

    public static function logoUrl(): string {
        return (string)apply_filters('ckm_sales_brand_logo_url','https://ckkm.ru/wp-content/uploads/2026/09/CKKM-logo-icon.webp');
    }

    public static function organizerUrl(): string {
        return function_exists('ckm_quiz_pro_organizer_url') ? (string)ckm_quiz_pro_organizer_url(['view'=>'games']) : home_url('/');
    }
}

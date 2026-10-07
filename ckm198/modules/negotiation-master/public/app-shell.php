<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

/**
 * Theme-independent shell for the public Negotiation Master application pages.
 * It deliberately replaces the WordPress theme chrome only on plugin-owned pages.
 */
final class AppShell {
    private const PAGE_OPTIONS = [
        'catalog' => 'ckm_neg_session_page_id',
        'builder' => 'ckm_neg_builder_page_id',
        'assignments' => 'ckm_neg_assignment_page_id',
    ];

    public static function activeSection(): string {
        $id = (int) get_queried_object_id();
        foreach (self::PAGE_OPTIONS as $section => $option) {
            if ($id > 0 && $id === (int) get_option($option, 0)) { return $section; }
        }
        if (is_page('ckm-negotiation-builder')) { return 'builder'; }
        if (is_page('ckm-negotiation-assignments')) { return 'assignments'; }
        if (is_page('ckm-negotiation-master')) { return 'catalog'; }
        return '';
    }

    public static function active(): bool { return self::activeSection() !== ''; }

    public static function template(string $template): string {
        if (!self::active()) { return $template; }
        $own = __DIR__ . '/app-template.php';
        return is_file($own) ? $own : $template;
    }

    public static function enqueue(): void {
        if (!self::active()) { return; }
        $base = CKM_QUIZ_PRO_URL . 'modules/negotiation-master/assets/';
        // Match the public CKM site typography exactly: Ubuntu for body text, Caveat for headings.
        wp_enqueue_style('ckm-neg-site-fonts', 'https://fonts.googleapis.com/css2?family=Caveat:wght@400;500;600;700&family=Ubuntu:ital,wght@0,400;0,500;0,700;1,400&display=swap', [], null);
        // Load all visual dependencies before wp_head; the app skin is deliberately last.
        wp_enqueue_style('ckm-neg-session', $base . 'negotiation-session.css', [], CKM_QUIZ_PRO_VERSION);
        $section = self::activeSection();
        $deps = ['ckm-neg-session'];
        if ($section === 'builder') {
            wp_enqueue_style('ckm-neg-builder', $base . 'negotiation-builder.css', ['ckm-neg-session'], CKM_QUIZ_PRO_VERSION);
            $deps = ['ckm-neg-builder'];
        } elseif ($section === 'assignments') {
            wp_enqueue_style('ckm-neg-assignments', $base . 'negotiation-assignments.css', ['ckm-neg-session'], CKM_QUIZ_PRO_VERSION);
            $deps = ['ckm-neg-assignments'];
        }
        $deps[] = 'ckm-neg-site-fonts';
        wp_enqueue_style('ckm-neg-app', $base . 'negotiation-app.css', array_values(array_unique($deps)), CKM_QUIZ_PRO_VERSION);
    }

    public static function bodyClass(array $classes): array {
        if (!self::active()) { return $classes; }
        $classes[] = 'ckm-neg-app-document';
        $section = self::activeSection();
        if ($section !== '') { $classes[] = 'ckm-neg-app-' . sanitize_html_class($section); }
        return array_values(array_unique($classes));
    }

    public static function showAdminBar(bool $show): bool { return self::active() ? false : $show; }

    public static function logoUrl(): string {
        $default = 'https://ckkm.ru/wp-content/uploads/2026/09/CKKM-logo-icon.webp';
        return (string) apply_filters('ckm_neg_brand_logo_url', $default);
    }

    public static function organizerUrl(): string {
        if (function_exists('ckm_quiz_pro_organizer_url')) {
            return (string) ckm_quiz_pro_organizer_url(['view' => 'games']);
        }
        return home_url('/');
    }

    public static function nav(): array {
        return [
            'catalog' => ['label'=>'Сценарии','url'=>ProductCatalog::url()],
            'builder' => ['label'=>'Конструктор','url'=>BuilderPage::url()],
            'assignments' => ['label'=>'Назначения','url'=>AssignmentPage::url()],
        ];
    }
}

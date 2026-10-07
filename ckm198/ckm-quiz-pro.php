<?php
/**
 * Plugin Name: CKM Quiz Pro — Intellectual Games + Negotiation Duel
 * Description: CKM platform for business games and intellectual games with a unified game engine, organizer cabinet, AI/human host, payments, tenant/subdomain storefronts, licensing and entitlements.
 * Version: 0.3.23.554-dev.586-REPEATED-ERROR-RETRAINING
 * Requires PHP: 8.3
 * Requires at least: 6.9
 * Author: CKM
 */
if (!defined('ABSPATH')) exit;

define('CKM_QUIZ_PRO_VERSION', '0.3.23.554-dev.586-REPEATED-ERROR-RETRAINING');
// Compatibility marker retained for historical regression tests: 0.3.23.246-dev.278-GAME-TITLE-RENAMES
define('CKM_QUIZ_PRO_FILE',__FILE__);
define('CKM_QUIZ_PRO_DIR',plugin_dir_path(__FILE__));
define('CKM_QUIZ_PRO_URL',plugin_dir_url(__FILE__));

// Shared Quiz Core: these files are copied by the alpha.87.2 export manifest.
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-core-boundary.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-adapter.php';
require_once CKM_QUIZ_PRO_DIR.'includes/games-hub-integration.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-repository.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-host-contract.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-auto-host.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-human-host.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-format-registry.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-format-runtime.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-jeopardy-runtime.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-jeopardy-final.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-chgk-roulette.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-chgk-question-flow.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-engine.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-chgk-team-flow.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-chgk-lobby.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-chgk-appeals-tiebreak.php';
require_once CKM_QUIZ_PRO_DIR.'core-source/includes/quiz/quiz-mechanics.php';

require_once CKM_QUIZ_PRO_DIR.'includes/standalone-schema.php';
require_once CKM_QUIZ_PRO_DIR.'includes/title-renames-278.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-core.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-storage-guard.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-repository.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-express-runtime.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-express-canary.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-test-api.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-core-smoke.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-chgk.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-jeopardy.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-solution-price.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-ai-arbiter.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-negotiation-duel.php';
require_once CKM_QUIZ_PRO_DIR.'includes/persuade-me-content.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-dialogue.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-review.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-manual-review.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-hidden.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-hard-question.php';
require_once CKM_QUIZ_PRO_DIR.'includes/negotiation-show-story.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-crypto.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-license.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-updater.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-content-packages.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-entitlements.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-game-access.php';
require_once CKM_QUIZ_PRO_DIR.'includes/ready-games-catalog.php';
require_once CKM_QUIZ_PRO_DIR.'includes/scenario-orders.php';
require_once CKM_QUIZ_PRO_DIR.'includes/games-catalog.php';
require_once CKM_QUIZ_PRO_DIR.'includes/decision-price-minigame-lite.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-game-store.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-payment-connector.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-organizer.php';
require_once CKM_QUIZ_PRO_DIR.'includes/game-history-pdf-mail.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-admin.php';
require_once CKM_QUIZ_PRO_DIR.'includes/games-hub-smoke.php';
require_once CKM_QUIZ_PRO_DIR.'includes/chgk-first-to-six-smoke.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-api.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-public.php';
require_once CKM_QUIZ_PRO_DIR.'includes/interface-editor.php';
require_once CKM_QUIZ_PRO_DIR.'includes/standalone-voice.php';
require_once CKM_QUIZ_PRO_DIR.'includes/completion-signal.php';

function ckm_quiz_pro_activate(): void {
    ckm_quiz_pro_install_schema();
    if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();
    ckm_quiz_pro_seed_demo_quiz();
    ckm_quiz_pro_seed_demo_chgk();
    ckm_quiz_pro_seed_demo_jeopardy();
    ckm_quiz_pro_seed_demo_solution_price();
    ckm_quiz_pro_seed_demo_negotiation_duel();
    ckm_quiz_pro_create_pages();
    ckm_quiz_pro_ensure_installation_id();
    ckm_quiz_pro_organizer_install();
    if (function_exists('ckm_quiz_pro_partner_install_schema')) ckm_quiz_pro_partner_install_schema();
    flush_rewrite_rules(false);
}
register_activation_hook(__FILE__,'ckm_quiz_pro_activate');

function ckm_quiz_pro_maybe_upgrade(): void {
    if (get_option('ckm_quiz_pro_db_version','') !== CKM_QUIZ_PRO_DB_VERSION) {
        ckm_quiz_pro_install_schema();
        if (function_exists('ckm_quiz_format_seed_builtins')) ckm_quiz_format_seed_builtins();
        ckm_quiz_pro_seed_demo_quiz();
        ckm_quiz_pro_seed_demo_chgk();
        ckm_quiz_pro_seed_demo_jeopardy();
        ckm_quiz_pro_seed_demo_solution_price();
        ckm_quiz_pro_seed_demo_negotiation_duel();
        ckm_quiz_pro_create_pages();
        ckm_quiz_pro_ensure_installation_id();
        ckm_quiz_pro_organizer_install();
    }
}
add_action('init','ckm_quiz_pro_maybe_upgrade',1);

// The shared registry also registers the CKM-platform formats submenu. Standalone
// exposes its own menu, so remove that CKM-only admin hook after all plugins load.
add_action('plugins_loaded',static function(){ remove_action('admin_menu','ckm_quiz_format_admin_menu',21); },99);

require_once CKM_QUIZ_PRO_DIR . 'includes/standalone-real-payment-adapter.php';


// Native WordPress checkout; the active theme owns the page template.
require_once __DIR__ . '/includes/checkout-render-fix.php';
require_once __DIR__ . '/includes/checkout-page-content-injector.php';
require_once __DIR__ . '/includes/checkout-frontend-complete.php';
require_once __DIR__ . '/includes/checkout-endpoint.php';

require_once __DIR__ . '/includes/display-labels.php';

require_once __DIR__ . '/includes/test-payment-bridge.php';

require_once __DIR__ . '/includes/tenant-foundation.php';

require_once __DIR__ . '/includes/tenant-data-scope.php';

require_once __DIR__ . '/includes/tenant-transfer.php';

require_once __DIR__ . '/includes/tenant-registration.php';


require_once __DIR__ . '/includes/tenant-payment-return.php';

require_once __DIR__ . '/includes/tenant-content.php';

require_once __DIR__ . '/includes/tenant-diagnostics.php';


// Integrated partner rental: partner plans, shared session quotas and child organizers.
require_once __DIR__ . '/includes/partner/loader.php';

// NEG-CORE: isolated storage foundation for Master of Negotiation.
require_once __DIR__ . '/modules/negotiation-master/bootstrap.php';

// SALES: dedicated UX and sales-domain adapter over the proven conversation core.
require_once __DIR__ . '/modules/effective-sales/bootstrap.php';

if (!function_exists('ckm_quiz_pro_effective_sales_url')) {
    function ckm_quiz_pro_effective_sales_url(array $args = []): string {
        return \CKM\EffectiveSales\SalesPage::url($args);
    }
}

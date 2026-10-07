<?php
namespace CKM\EffectiveSales;
if (!defined('ABSPATH')) { exit; }

require_once __DIR__ . '/sales-domain.php';
require_once __DIR__ . '/application/sales-completion-service.php';
require_once __DIR__ . '/application/sales-script-service.php';
require_once __DIR__ . '/application/sales-script-client-service.php';
require_once __DIR__ . '/application/sales-development-center-service.php';
require_once __DIR__ . '/application/sales-adaptive-polygon-service.php';
require_once __DIR__ . '/application/sales-ai-seller-workspace-service.php';
require_once __DIR__ . '/application/sales-ai-seller-service.php';
require_once __DIR__ . '/application/sales-partner-integration-service.php';
require_once __DIR__ . '/application/sales-telegram-service.php';
require_once __DIR__ . '/application/sales-max-service.php';
require_once __DIR__ . '/application/sales-whatsapp-service.php';
require_once __DIR__ . '/application/sales-live-sip-service.php';
require_once __DIR__ . '/application/sales-live-sideband-service.php';
require_once __DIR__ . '/application/sales-phone-gateway-service.php';
require_once __DIR__ . '/application/sales-telephony-service.php';
require_once __DIR__ . '/application/sales-competition-service.php';
require_once __DIR__ . '/application/sales-team-development-service.php';
require_once __DIR__ . '/application/sales-practice-feedback-service.php';
require_once __DIR__ . '/application/sales-standard-recertification-service.php';
require_once __DIR__ . '/application/sales-standard-impact-service.php';
require_once __DIR__ . '/application/sales-standard-revision-decision-service.php';
require_once __DIR__ . '/api/sales-controller.php';
require_once __DIR__ . '/public/app-shell.php';
require_once __DIR__ . '/public/sales-development-center-page.php';
require_once __DIR__ . '/public/sales-team-development-page.php';
require_once __DIR__ . '/public/sales-page.php';

add_action('rest_api_init', [SalesController::class, 'register']);
add_filter('rest_pre_serve_request', [SalesWhatsAppService::class, 'serveRawChallenge'], 10, 4);
add_action('init', [SalesPartnerIntegrationService::class, 'installSchema'], 35);
add_filter('cron_schedules', [SalesAiSellerService::class, 'cronSchedules']);
add_action('init', [SalesAiSellerService::class, 'ensureCron'], 36);
add_action('ckm_sales_ai_seller_tick', [SalesAiSellerService::class, 'cronTick']);
add_action('init', [SalesCompetitionService::class, 'maybeJoinFromLink'], 20);
add_filter('template_include', [AppShell::class, 'template'], 100);
add_action('wp_enqueue_scripts', [AppShell::class, 'enqueue'], 6);
add_action('template_redirect', [SalesPage::class, 'handleRequest'], 5);
add_filter('body_class', [AppShell::class, 'bodyClass']);
add_filter('show_admin_bar', [AppShell::class, 'showAdminBar']);
add_action('init', [SalesPage::class, 'register'], 34);
add_action('init', [SalesPage::class, 'maybeInstall'], 35);
register_activation_hook(CKM_QUIZ_PRO_FILE, [SalesPage::class, 'install']);
register_deactivation_hook(CKM_QUIZ_PRO_FILE, [SalesAiSellerService::class, 'deactivateCron']);

function url(array $args = []): string { return SalesPage::url($args); }

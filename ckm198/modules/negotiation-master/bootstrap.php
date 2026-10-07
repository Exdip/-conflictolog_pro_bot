<?php
namespace CKM\NegotiationMaster;
if (!defined('ABSPATH')) { exit; }

define('CKM_NEG_DB_VERSION', '1.8.0');
define('CKM_NEG_CONTENT_VERSION', '1.50.0');

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/domain/difficulty-policy.php';
require_once __DIR__ . '/domain/reopen-policy.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/repositories.php';
require_once __DIR__ . '/health.php';

require_once __DIR__ . '/ai/opponent/opponent-context-builder.php';
require_once __DIR__ . '/ai/opponent/opponent-prompt-builder.php';
require_once __DIR__ . '/ai/opponent/opponent-response-validator.php';
require_once __DIR__ . '/ai/opponent/sales-client-fallback.php';
require_once __DIR__ . '/ai/opponent/opponent-service.php';
require_once __DIR__ . '/ai/opponent/agreement-response-service.php';

require_once __DIR__ . '/domain/item-state-service.php';
require_once __DIR__ . '/domain/fact-state-service.php';
require_once __DIR__ . '/domain/event-service.php';
require_once __DIR__ . '/domain/commitment-service.php';
require_once __DIR__ . '/domain/relationship-service.php';
require_once __DIR__ . '/domain/alternative-value-service.php';
require_once __DIR__ . '/domain/zopa-service.php';
require_once __DIR__ . '/domain/rule-engine.php';
require_once __DIR__ . '/domain/agreement-validator.php';
require_once __DIR__ . '/ai/arbiter/arbiter-context-builder.php';
require_once __DIR__ . '/ai/arbiter/arbiter-prompt-builder.php';
require_once __DIR__ . '/ai/arbiter/arbiter-response-parser.php';
require_once __DIR__ . '/ai/arbiter/arbiter-validator.php';
require_once __DIR__ . '/ai/arbiter/arbiter-service.php';

require_once __DIR__ . '/ai/coach/in-game-coach-context-builder.php';
require_once __DIR__ . '/ai/coach/coach-prompt-builder.php';
require_once __DIR__ . '/ai/coach/coach-response-validator.php';
require_once __DIR__ . '/ai/coach/coach-service.php';

require_once __DIR__ . '/evaluation/evaluation-context-builder.php';
require_once __DIR__ . '/evaluation/criterion-calculator.php';
require_once __DIR__ . '/evaluation/php-evaluator.php';
require_once __DIR__ . '/evaluation/ai-evaluator.php';
require_once __DIR__ . '/evaluation/no-deal-evaluator.php';
require_once __DIR__ . '/evaluation/result-builder.php';
require_once __DIR__ . '/evaluation/evaluation-service.php';

require_once __DIR__ . '/application/library-access-service.php';
require_once __DIR__ . '/application/assignment-service.php';
require_once __DIR__ . '/application/scenario-builder-service.php';
require_once __DIR__ . '/application/player-session-snapshot-builder.php';
require_once __DIR__ . '/application/session-service.php';
require_once __DIR__ . '/application/completion-service.php';
require_once __DIR__ . '/application/agreement-service.php';
require_once __DIR__ . '/application/no-deal-service.php';
require_once __DIR__ . '/application/recovery-service.php';
require_once __DIR__ . '/application/topicality-guard.php';
require_once __DIR__ . '/application/message-service.php';
require_once __DIR__ . '/api/session-controller.php';
require_once __DIR__ . '/api/builder-controller.php';
require_once __DIR__ . '/api/assignment-controller.php';
require_once __DIR__ . '/public/product-catalog.php';
require_once __DIR__ . '/public/app-shell.php';
require_once __DIR__ . '/public/player-page.php';
require_once __DIR__ . '/public/builder-page.php';
require_once __DIR__ . '/public/assignment-page.php';

// Separate activation hook also supports replacing the ZIP while already active.
register_activation_hook(CKM_QUIZ_PRO_FILE, [Migrations::class, 'run']);
add_action('init', [Migrations::class, 'run'], 30);
add_action('rest_api_init', [Health::class, 'register']);
add_action('rest_api_init', [SessionController::class, 'register']);
add_action('rest_api_init', [BuilderController::class, 'register']);
add_action('rest_api_init', [AssignmentController::class, 'register']);
add_filter('template_include', [AppShell::class, 'template'], 99);
add_action('wp_enqueue_scripts', [AppShell::class, 'enqueue'], 5);
add_filter('body_class', [AppShell::class, 'bodyClass']);
add_filter('show_admin_bar', [AppShell::class, 'showAdminBar']);
add_action('init', [PlayerPage::class, 'register'], 32);
add_action('init', [BuilderPage::class, 'register'], 32);
add_action('init', [AssignmentPage::class, 'register'], 32);
add_action('init', [PlayerPage::class, 'maybeInstall'], 33);
add_action('init', [BuilderPage::class, 'maybeInstall'], 33);
add_action('init', [AssignmentPage::class, 'maybeInstall'], 33);
register_activation_hook(CKM_QUIZ_PRO_FILE, [PlayerPage::class, 'install']);
register_activation_hook(CKM_QUIZ_PRO_FILE, [BuilderPage::class, 'install']);
register_activation_hook(CKM_QUIZ_PRO_FILE, [AssignmentPage::class, 'install']);
// Deliberately no deactivation/uninstall deletion hooks.

require_once __DIR__ . '/content-migration.php';
require_once __DIR__ . '/scenario-diagnostics.php';
register_activation_hook(CKM_QUIZ_PRO_FILE, [ContentMigration::class, 'run']);
add_action('init', [ContentMigration::class, 'run'], 31);
add_action('rest_api_init', [ScenarioDiagnostics::class, 'register']);

<?php
/** Load production title functions without running WordPress lifecycle hooks. */
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__, 2) . '/');
if (!function_exists('add_action')) { function add_action(...$args): void {} }
if (!function_exists('add_filter')) { function add_filter(...$args): void {} }
if (!function_exists('sanitize_key')) {
    function sanitize_key($value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
}
require_once dirname(__DIR__, 2) . '/includes/games-catalog.php';
require_once dirname(__DIR__, 2) . '/includes/standalone-organizer.php';
require_once dirname(__DIR__, 2) . '/includes/partner/plans.php';
require_once dirname(__DIR__, 2) . '/includes/display-labels.php';
require_once dirname(__DIR__, 2) . '/includes/title-renames-278.php';

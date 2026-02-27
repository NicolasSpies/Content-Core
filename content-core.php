<?php
/**
 * Plugin Name: Content Core
 * Plugin URI:  
 * Description: Content Core is a custom engineered WordPress framework built from scratch by Nicolas Spies. It provides structured content architecture, controlled admin environments, modular extensions, and scalable backend logic for modern agency projects..
 * Version:     1.2.1
 * Author:      Nicolas Spies
 * Text Domain: content-core
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define('CONTENT_CORE_VERSION', '1.2.1');
define('CONTENT_CORE_PLUGIN_FILE', __FILE__);
define('CONTENT_CORE_PLUGIN_DIR', plugin_dir_path(CONTENT_CORE_PLUGIN_FILE));
define('CONTENT_CORE_PLUGIN_URL', plugin_dir_url(CONTENT_CORE_PLUGIN_FILE));

// Simple PSR-4 Autoloader mapping ContentCore\ to src/
spl_autoload_register(function ($class) {
	$prefix = 'ContentCore\\';
	$base_dir = CONTENT_CORE_PLUGIN_DIR . 'src/';

	$len = strlen($prefix);
	if (strncmp($prefix, $class, $len) !== 0) {
		return;
	}

	$relative_class = substr($class, $len);
	$file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

	if (file_exists($file)) {
		require $file;
	}
});


// ── Internal Error Logger ────────────────────────────────────────────────────
// Instantiated immediately after the autoloader so errors during plugin
// bootstrap are captured too. Stored in a global so AdminMenu can access it.
$GLOBALS['cc_error_logger'] = new \ContentCore\Admin\ErrorLogger(CONTENT_CORE_PLUGIN_DIR);
$GLOBALS['cc_error_logger']->register_handlers();

// Initialize the Plugin
function content_core_init()
{
	// Step A: Enable safe logging
	if (defined('WP_DEBUG') && WP_DEBUG) {
		ini_set('display_errors', '0');
		ini_set('log_errors', '1');
	}

	$plugin = \ContentCore\Plugin::get_instance();
	$plugin->init();

	// Initialize GitHub Updater
	if (is_admin()) {
		$updater = new \ContentCore\Updater\GitHubUpdater(CONTENT_CORE_PLUGIN_FILE);
		$updater->init();
	}
}
add_action('plugins_loaded', 'content_core_init');
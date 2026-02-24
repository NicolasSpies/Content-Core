<?php
/**
 * Plugin Name: Content Core
 * Plugin URI:  
 * Description: Modular internal agency framework for headless WordPress projects (Phase 1).
 * Version:     1.0.3
 * Author:      Nicolas Spies
 * Text Domain: content-core
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define('CONTENT_CORE_VERSION', '1.0.3');
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

// Initialize the Plugin
function content_core_init()
{
	$plugin = \ContentCore\Plugin::get_instance();
	$plugin->init();

	// Initialize GitHub Updater
	if (is_admin()) {
		$updater = new \ContentCore\Updater\GitHubUpdater(CONTENT_CORE_PLUGIN_FILE);
		$updater->init();
	}
}
add_action('plugins_loaded', 'content_core_init');
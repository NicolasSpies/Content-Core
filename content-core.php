<?php
/**
 * Plugin Name: Content Core
 * Plugin URI:  
 * Description: Content Core is a custom engineered WordPress framework built from scratch by Nicolas Spies. It provides structured content architecture, controlled admin environments, modular extensions, and scalable backend logic for modern agency projects..
 * Version:     1.4.1
 * Author:      Nicolas Spies
 * Text Domain: content-core
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define('CONTENT_CORE_VERSION', '1.3.4');
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

// ── SVG Upload and Sanitizer (Admins Only) ───────────────────────────────────

add_filter('upload_mimes', function ($mimes) {
	if (!current_user_can('manage_options')) {
		return $mimes;
	}
	$mimes['svg'] = 'image/svg+xml';
	$mimes['svgz'] = 'image/svg+xml';
	return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes, $real_mime = null) {
	if (!current_user_can('manage_options')) {
		return $data;
	}

	$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
	if ($ext === 'svg' || $ext === 'svgz') {
		$data['ext'] = $ext;
		$data['type'] = 'image/svg+xml';
	}

	return $data;
}, 10, 6);

add_filter('wp_handle_upload_prefilter', function ($file) {
	if (!current_user_can('manage_options')) {
		return $file;
	}

	$name = isset($file['name']) ? $file['name'] : '';
	$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
	if ($ext !== 'svg' && $ext !== 'svgz') {
		return $file;
	}

	// Basic checks
	if (!isset($file['tmp_name']) || !is_readable($file['tmp_name'])) {
		$file['error'] = 'Upload tmp file not readable.';
		return $file;
	}

	$raw = file_get_contents($file['tmp_name']);
	if ($raw === false || $raw === '') {
		$file['error'] = 'Empty SVG.';
		return $file;
	}

	// Very basic sanitization. Recommended: replace with a real sanitizer library.
	$sanitized = cc_content_core_sanitize_svg($raw);
	if ($sanitized === null) {
		$file['error'] = 'SVG rejected by sanitizer.';
		return $file;
	}

	file_put_contents($file['tmp_name'], $sanitized);
	return $file;
});

function cc_content_core_sanitize_svg(string $svg): ?string
{
	// Reject obvious dangerous patterns fast
	$lower = strtolower($svg);
	if (strpos($lower, '<script') !== false)
		return null;
	if (strpos($lower, 'javascript:') !== false)
		return null;
	if (strpos($lower, 'onload=') !== false)
		return null;
	if (strpos($lower, 'onerror=') !== false)
		return null;

	// Parse XML safely
	$prev = libxml_use_internal_errors(true);
	$dom = new DOMDocument();

	// Prevent network access
	$ok = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOENT);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);

	if (!$ok)
		return null;

	// Remove any script tags if present
	$scripts = $dom->getElementsByTagName('script');
	while ($scripts->length > 0) {
		$scripts->item(0)->parentNode->removeChild($scripts->item(0));
	}

	return $dom->saveXML($dom->documentElement);
}

// ── Custom WP Login Logo ─────────────────────────────────────────────────────

// Logic is moved to BrandingModule to allow independent login screen branding.

function cc_site_options_logo_url(): ?string
{
	// Content Core stores site options by language, e.g. cc_site_options_de
	$settings = get_option('cc_languages_settings', []);
	$lang = $settings['default_lang'] ?? 'de';
	$opts = get_option('cc_site_options_' . $lang);

	if (is_array($opts) && !empty($opts['logo_id'])) {
		$url = wp_get_attachment_url((int) $opts['logo_id']);
		if ($url)
			return $url;
	}

	return null;
}

// ── Google SEO Preview ───────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', function ($hook) {
	if (strpos($hook, 'cc-seo') === false && strpos($hook, 'cc-site-settings') === false)
		return;

	wp_enqueue_script(
		'cc-seo-preview',
		plugins_url('assets/cc-seo-preview.js', __FILE__),
		[],
		'1.0.0',
		true
	);

	wp_localize_script('cc-seo-preview', 'CC_SEO_PREVIEW', [
		'siteUrl' => home_url('/'),
		'defaultTitle' => get_bloginfo('name'),
		'defaultDesc' => get_bloginfo('description'),
	]);
});

function cc_render_seo_preview_box()
{
	echo '
	<div class="cc-seo-preview" style="margin-top:16px;padding:16px;border:1px solid #dcdcde;border-radius:8px;background:#fff;max-width:720px;">
		<div class="cc-seo-preview-domain" style="font-size:12px;color:#5f6368;margin-bottom:6px;"></div>
		<div class="cc-seo-preview-title" style="font-size:20px;line-height:1.3;color:#1a0dab;margin-bottom:6px;word-break:break-word;"></div>
		<div class="cc-seo-preview-desc" style="font-size:14px;line-height:1.4;color:#4d5156;word-break:break-word;"></div>
	</div>';
}

// ── Site Images Helpers ──────────────────────────────────────────────────────

function cc_get_site_image_id($key): int
{
	// Primary source: unified cc_site_settings option (new React-driven storage)
	$settings = get_option('cc_site_settings', []);
	$images = isset($settings['images']) && is_array($settings['images']) ? $settings['images'] : [];
	if (isset($images[$key]) && $images[$key]) {
		return absint($images[$key]);
	}
	// Fallback: legacy cc_site_images option (backward compatibility)
	$legacy = get_option('cc_site_images', []);
	return isset($legacy[$key]) ? absint($legacy[$key]) : 0;
}

function cc_get_site_image_url($key): string
{
	$id = cc_get_site_image_id($key);
	if (!$id)
		return '';
	$url = wp_get_attachment_image_url($id, 'full');
	return $url ?: '';
}

function cc_get_default_og_image_url(): string
{
	// social_id is the OG / social preview image in both old and new schemas
	return cc_get_site_image_url('social_id');
}

add_action('wp_head', function () {
	// social_id = 64×64 social icon (stored in cc_site_settings.images.social_id)
	$icon_url = cc_get_site_image_url('social_id');
	if ($icon_url) {
		echo '<link rel="icon" href="' . esc_url($icon_url) . '" sizes="64x64">' . "\n";
		echo '<link rel="shortcut icon" href="' . esc_url($icon_url) . '">' . "\n";
		echo '<link rel="apple-touch-icon" href="' . esc_url($icon_url) . '">' . "\n";
	}

	// og_default_id = 1200×630 social preview (OG image)
	$og_url = cc_get_site_image_url('og_default_id');
	if ($og_url) {
		echo '<meta property="og:image" content="' . esc_url($og_url) . '">' . "\n";
		echo '<meta name="twitter:image" content="' . esc_url($og_url) . '">' . "\n";
	}
});
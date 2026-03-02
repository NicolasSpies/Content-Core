<?php
/**
 * Plugin Name: Content Core
 * Plugin URI:  
 * Description: Content Core is a custom engineered WordPress framework built from scratch by Nicolas Spies. It provides structured content architecture, controlled admin environments, modular extensions, and scalable backend logic for modern agency projects..
 * Version:     1.6.3
 * Author:      Nicolas Spies
 * Text Domain: content-core
 */

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Define plugin constants
define('CONTENT_CORE_VERSION', '1.6.3');
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
// Instantiated and handlers registered immediately for bootstrap capture.
\ContentCore\Plugin::get_instance()->get_error_logger()->register_handlers();

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
	// 1. Initial rejection of obvious dangerous patterns
	$lower = strtolower($svg);
	$blocked = ['<script', 'javascript:', 'vbscript:', 'onload=', 'onerror=', 'onclick=', 'onmouseover=', 'onmouseenter='];
	foreach ($blocked as $pattern) {
		if (strpos($lower, $pattern) !== false) {
			\ContentCore\Logger::warning('SVG rejected: dangerous pattern detected.', ['pattern' => $pattern]);
			return null;
		}
	}

	// 2. Parse XML safely
	$prev = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	// Prevent ENTITY loading (XXE)
	$ok = $dom->loadXML($svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_DTDLOAD);
	libxml_clear_errors();
	libxml_use_internal_errors($prev);

	if (!$ok || !$dom->documentElement) {
		return null;
	}

	// 3. Remove dangerous tags
	$dangerous_tags = ['script', 'foreignObject', 'iframe', 'object', 'embed', 'applet', 'meta', 'link', 'style'];
	foreach ($dangerous_tags as $tag) {
		$nodes = $dom->getElementsByTagName($tag);
		while ($nodes->length > 0) {
			$nodes->item(0)->parentNode->removeChild($nodes->item(0));
		}
	}

	// 4. Strip ALL 'on*' attributes and hazardous 'href' values
	$all_elements = $dom->getElementsByTagName('*');
	foreach ($all_elements as $element) {
		if (!$element instanceof \DOMElement) {
			continue;
		}
		$attr_names = [];
		if ($element->hasAttributes()) {
			foreach ($element->attributes as $attr) {
				$attr_names[] = $attr->nodeName;
			}
		}

		foreach ($attr_names as $attr_name) {
			$name_lower = strtolower($attr_name);
			$value_lower = strtolower($element->getAttribute($attr_name));

			// Block any attribute starting with 'on' (event handlers)
			if (strpos($name_lower, 'on') === 0) {
				$element->removeAttribute($attr_name);
				continue;
			}

			// Block URI-based attributes with dangerous protocols
			$uri_attrs = ['href', 'xlink:href', 'src', 'action', 'formaction', 'data'];
			if (in_array($name_lower, $uri_attrs, true)) {
				$blocked_protocols = ['javascript:', 'vbscript:', 'data:', 'file:'];
				foreach ($blocked_protocols as $protocol) {
					if (strpos($value_lower, $protocol) === 0) {
						$element->removeAttribute($attr_name);
						break;
					}
				}
			}

			// Block hazardous style contents (tracking/expression XSS)
			if ($name_lower === 'style') {
				if (strpos($value_lower, 'url(') !== false || strpos($value_lower, 'expression(') !== false) {
					$element->removeAttribute($attr_name);
				}
			}
		}
	}

	// 5. Final check: ensure the root is actually <svg>
	if (strtolower($dom->documentElement->nodeName) !== 'svg') {
		return null;
	}

	return $dom->saveXML($dom->documentElement);
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
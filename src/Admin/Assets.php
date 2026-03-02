<?php
namespace ContentCore\Admin;

/**
 * Class Assets
 *
 * Centralizes asset registration and management for Content Core.
 */
class Assets
{
    /**
     * Initialize asset hooks
     */
    public function init(): void
    {
        // Setup missing directories/files if needed
        $this->ensure_minimal_files();

        // Register all assets early (priority 5) before enqueueing logic
        add_action('admin_enqueue_scripts', [$this, 'register_all_assets'], 5);
    }

    /**
     * Register all Content Core assets globally
     */
    public function register_all_assets(): void
    {
        // 1. CSS Assets
        $this->register_style('cc-admin-modern', 'assets/css/admin.css');
        $this->register_style('cc-post-edit', 'assets/css/post-edit.css');
        $this->register_style('cc-metabox-ui', 'assets/css/metabox-ui.css');

        // 2. JS Assets
        $this->register_script('cc-admin-js', 'assets/js/admin.js', ['jquery', 'media-views']);
        $this->register_script('cc-settings-js', 'assets/js/settings.js', ['jquery', 'jquery-ui-sortable']);
        $this->register_script('cc-site-settings-app', 'assets/js/site-settings-app.js', ['wp-element', 'wp-api-fetch', 'wp-i18n']);
        $this->register_script('cc-schema-editor', 'assets/js/schema-editor.js', ['jquery', 'jquery-ui-sortable']);
    }

    /**
     * Helper to safely register a stylesheet
     */
    private function register_style(string $handle, string $relative_path): void
    {
        $file_path = CONTENT_CORE_PLUGIN_DIR . $relative_path;
        $version = file_exists($file_path) ? filemtime($file_path) : CONTENT_CORE_VERSION;

        wp_register_style(
            $handle,
            CONTENT_CORE_PLUGIN_URL . $relative_path,
            [],
            $version
        );
    }

    /**
     * Helper to safely register a script
     */
    private function register_script(string $handle, string $relative_path, array $deps = []): void
    {
        $file_path = CONTENT_CORE_PLUGIN_DIR . $relative_path;
        $version = file_exists($file_path) ? filemtime($file_path) : CONTENT_CORE_VERSION;

        wp_register_script(
            $handle,
            CONTENT_CORE_PLUGIN_URL . $relative_path,
            $deps,
            $version,
            true
        );
    }

    /**
     * Phase 5 requirement: Ensure folders and minimal files exist so health checks don't fail setup.
     */
    private function ensure_minimal_files(): void
    {
        $assets_dir = CONTENT_CORE_PLUGIN_DIR . 'assets';
        $css_dir = $assets_dir . '/css';
        $js_dir = $assets_dir . '/js';

        try {
            // Create CSS directory with error handling
            if (!is_dir($css_dir)) {
                if (!@mkdir($css_dir, 0755, true) && !is_dir($css_dir)) {
                    throw new \Exception("Failed to create CSS directory: {$css_dir}");
                }
            }

            // Create JS directory with error handling
            if (!is_dir($js_dir)) {
                if (!@mkdir($js_dir, 0755, true) && !is_dir($js_dir)) {
                    throw new \Exception("Failed to create JS directory: {$js_dir}");
                }
            }

            $files = [
                $css_dir . '/admin.css' => '/* Minimal Content Core Admin CSS */',
                $css_dir . '/post-edit.css' => '/* Minimal Content Core Post Edit CSS */',
                $css_dir . '/metabox-ui.css' => '/* Minimal Content Core Metabox UI CSS */',
                $js_dir . '/admin.js' => '/* Minimal Content Core Admin JS */console.log("wp-content/plugins/Content-Core/assets/js/admin.js loaded");'
            ];

            foreach ($files as $file => $content) {
                if (!file_exists($file)) {
                    $result = @file_put_contents($file, $content);
                    if ($result === false) {
                        throw new \Exception("Failed to write file: {$file}");
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but don't break plugin initialization
            if (defined('WP_DEBUG') && WP_DEBUG) {
                trigger_error('Content Core: ' . $e->getMessage(), E_USER_WARNING);
            }
            if (class_exists('\ContentCore\Logger')) {
                \ContentCore\Logger::warning('Asset initialization failed: ' . $e->getMessage());
            }
        }
    }
}
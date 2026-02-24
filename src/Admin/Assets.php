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
        // 1. cc-admin-modern (CSS)
        $this->register_style(
            'cc-admin-modern',
            'assets/css/admin.css'
        );

        // 2. cc-post-edit (CSS)
        $this->register_style(
            'cc-post-edit',
            'assets/css/post-edit.css'
        );

        // 3. cc-metabox-ui (CSS)
        $this->register_style(
            'cc-metabox-ui',
            'assets/css/metabox-ui.css'
        );

        // 4. cc-admin-modern (JS) - renamed handle to avoid conflict with CSS
        $file_path = CONTENT_CORE_PLUGIN_DIR . 'assets/js/admin.js';
        if (file_exists($file_path)) {
            wp_register_script(
                'cc-admin-js',
                CONTENT_CORE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'media-views'],
                filemtime($file_path),
                true
            );
        }
    }

    /**
     * Helper to safely register a stylesheet if it exists
     */
    private function register_style(string $handle, string $relative_path): void
    {
        $file_path = CONTENT_CORE_PLUGIN_DIR . $relative_path;
        if (file_exists($file_path)) {
            wp_register_style(
                $handle,
                CONTENT_CORE_PLUGIN_URL . $relative_path,
            [],
                filemtime($file_path)
            );
        }
    }

    /**
     * Phase 5 requirement: Ensure folders and minimal files exist so health checks don't fail setup.
     */
    private function ensure_minimal_files(): void
    {
        $assets_dir = CONTENT_CORE_PLUGIN_DIR . 'assets';
        $css_dir = $assets_dir . '/css';
        $js_dir = $assets_dir . '/js';

        if (!is_dir($css_dir)) {
            mkdir($css_dir, 0755, true);
        }
        if (!is_dir($js_dir)) {
            mkdir($js_dir, 0755, true);
        }

        $files = [
            $css_dir . '/admin.css' => '/* Minimal Content Core Admin CSS */',
            $css_dir . '/post-edit.css' => '/* Minimal Content Core Post Edit CSS */',
            $css_dir . '/metabox-ui.css' => '/* Minimal Content Core Metabox UI CSS */',
            $js_dir . '/admin.js' => '/* Minimal Content Core Admin JS */console.log("wp-content/plugins/Content-Core/assets/js/admin.js loaded");'
        ];

        foreach ($files as $file => $content) {
            if (!file_exists($file)) {
                file_put_contents($file, $content);
            }
        }
    }
}
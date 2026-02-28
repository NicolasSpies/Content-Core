<?php
namespace ContentCore\Modules\Diagnostics\Checks;

use ContentCore\Modules\Diagnostics\Engine\HealthCheckInterface;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckResult;

class SettingsIntegrityCheck implements HealthCheckInterface
{
    public function get_id(): string
    {
        return 'settings_integrity';
    }

    public function get_name(): string
    {
        return __('Settings & Media Integrity', 'content-core');
    }

    public function get_category(): string
    {
        return 'settings';
    }

    public function run_check(): array
    {
        $results = [];

        // 1. Check Core Options
        $core_keys = ['cc_languages_settings', 'cc_site_images', 'cc_site_seo'];
        foreach ($core_keys as $key) {
            $opt = get_option($key);
            if ($opt === false) {
                $results[] = new HealthCheckResult(
                    'missing_core_option_' . $key,
                    'warning',
                    sprintf(__('Core option key %s is completely missing. Re-save settings to populate.', 'content-core'), $key),
                    true,
                    ['type' => 'populate_option', 'key' => $key]
                );
            }
        }

        // 2. Image Dimensions (Favicon and Social)
        $images = get_option('cc_site_images', []);

        if (!empty($images['social_icon_id'])) {
            $meta = wp_get_attachment_metadata($images['social_icon_id']);
            if (isset($meta['width'], $meta['height'])) {
                if ($meta['width'] < 64 || $meta['height'] < 64 || $meta['width'] !== $meta['height']) {
                    $results[] = new HealthCheckResult(
                        'favicon_dimensions_invalid',
                        'warning',
                        __('Favicon dimensions are sub-optimal. Recommended: Square image, at least 64x64px.', 'content-core'),
                        false
                    );
                }
            } else {
                $results[] = new HealthCheckResult(
                    'favicon_not_found',
                    'warning',
                    __('Favicon ID references a broken or missing media file.', 'content-core'),
                    false
                );
            }
        }

        if (!empty($images['og_default_id'])) {
            $meta = wp_get_attachment_metadata($images['og_default_id']);
            if (isset($meta['width'], $meta['height'])) {
                if ($meta['width'] < 1200 || $meta['height'] < 630 || ($meta['width'] / $meta['height'] < 1.9)) {
                    $results[] = new HealthCheckResult(
                        'social_dimensions_invalid',
                        'warning',
                        __('A default social image (OG:Image) is set but is smaller than recommended sizes. Recommended: 1200x630px or wider.', 'content-core'),
                        false
                    );
                }
            }
        }

        // 3. Broken Visiblity Slugs (check if menus actually exist)
        $visibility = get_option('content_core_admin_menu_settings', []);
        if (!empty($visibility['nav_menus'])) {
            $registered_menus = get_registered_nav_menus();
            foreach ($visibility['nav_menus'] as $slug => $state) {
                if (!isset($registered_menus[$slug])) {
                    $results[] = new HealthCheckResult(
                        'broken_nav_visibility_' . $slug,
                        'warning',
                        sprintf(__('Menu visibility settings reference a broken or deleted theme location: %s', 'content-core'), $slug),
                        true,
                        ['type' => 'cleanup_broken_nav', 'slug' => $slug]
                    );
                }
            }
        }

        return $results;
    }

    public function get_fix_preview(string $issue_id, $context_data = null): ?array
    {
        if (strpos($issue_id, 'missing_core_option_') === 0 && !empty($context_data['key'])) {
            return [
                'description' => sprintf(__('This will initialize the missing option "%s" with default values to ensure settings integrity.', 'content-core'), esc_html($context_data['key']))
            ];
        }

        if (strpos($issue_id, 'broken_nav_visibility_') === 0) {
            return [
                'description' => sprintf(__('This will purge the orphaned navigation location "%s" from your site visibility overrides safely, preventing ghost UI entries.', 'content-core'), esc_html($context_data['slug'] ?? 'Unknown'))
            ];
        }

        return null;
    }

    public function apply_fix(string $issue_id, $context_data = null)
    {
        // 1. Missing Core Option Fix
        if (strpos($issue_id, 'missing_core_option_') === 0 && !empty($context_data['key'])) {
            $key = $context_data['key'];
            $settings_module = \ContentCore\Plugin::get_instance()->get_module('settings');
            if ($settings_module instanceof \ContentCore\Modules\Settings\SettingsModule) {
                $registry = $settings_module->get_registry();
                $defaults = $registry->get_defaults($key);
                update_option($key, $defaults);
                return true;
            }
            return false;
        }

        // 2. Broken Nav Fix
        if (strpos($issue_id, 'broken_nav_visibility_') === 0 && !empty($context_data['slug'])) {
            $visibility = get_option('content_core_admin_menu_settings', []);
            if (isset($visibility['nav_menus'][$context_data['slug']])) {
                unset($visibility['nav_menus'][$context_data['slug']]);
                update_option('content_core_admin_menu_settings', $visibility);
            }
            return true;
        }

        return new \WP_Error('invalid_fix', 'This issue cannot be auto-fixed.');
    }
}

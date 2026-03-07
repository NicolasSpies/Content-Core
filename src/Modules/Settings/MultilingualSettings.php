<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles multilingual-related settings and logic.
 */
class MultilingualSettings
{
    /**
     * @var SettingsModule
     */
    private $module;

    /**
     * @param SettingsModule $module
     */
    public function __construct(SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Initialize Multilingual settings registration.
     */
    public function init(): void
    {
        $this->module->get_registry()->register('cc_languages_settings', [
            'default' => [
                'enabled' => false,
                'default_lang' => 'de',
                'languages' => [
                    [
                        'code' => 'de',
                        'label' => 'Deutsch',
                        'flag_id' => 0
                    ]
                ],
                'fallback_enabled' => false,
                'fallback_lang' => 'de',
                'permalink_enabled' => false,
                'permalink_bases' => [],
                'taxonomy_bases' => [],
                'enable_rest_seo' => false,
                'enable_headless_fallback' => false,
                'enable_localized_taxonomies' => false,
                'enable_sitemap_endpoint' => false,
            ],
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    /**
     * Sanitize multilingual settings.
     */
    public function sanitize_settings(array $settings): array
    {
        $settings['enabled'] = !empty($settings['enabled']);
        $settings['fallback_enabled'] = !empty($settings['fallback_enabled']);
        $settings['permalink_enabled'] = !empty($settings['permalink_enabled']);
        $settings['enable_rest_seo'] = !empty($settings['enable_rest_seo']);
        $settings['enable_headless_fallback'] = !empty($settings['enable_headless_fallback']);
        $settings['enable_localized_taxonomies'] = !empty($settings['enable_localized_taxonomies']);
        $settings['enable_sitemap_endpoint'] = !empty($settings['enable_sitemap_endpoint']);

        $settings['languages'] = $this->sanitize_languages($settings['languages'] ?? []);
        $active_codes = array_map(static function (array $lang): string {
            return (string) ($lang['code'] ?? '');
        }, $settings['languages']);
        $active_codes = array_values(array_filter($active_codes, static function (string $code): bool {
            return $code !== '';
        }));

        $default_lang = sanitize_key((string) ($settings['default_lang'] ?? ''));
        if ($default_lang === '' || !in_array($default_lang, $active_codes, true)) {
            $default_lang = $active_codes[0] ?? 'de';
        }
        $settings['default_lang'] = $default_lang;

        $fallback_lang = sanitize_key((string) ($settings['fallback_lang'] ?? ''));
        if ($fallback_lang === '' || !in_array($fallback_lang, $active_codes, true)) {
            $fallback_lang = $default_lang;
        }
        $settings['fallback_lang'] = $fallback_lang;

        $settings['permalink_bases'] = $this->sanitize_language_base_map($settings['permalink_bases'] ?? [], $active_codes);
        $settings['taxonomy_bases'] = $this->sanitize_language_base_map($settings['taxonomy_bases'] ?? [], $active_codes);

        return $settings;
    }

    private function sanitize_languages($languages): array
    {
        if (!is_array($languages)) {
            $languages = [];
        }

        $normalized = [];
        $seen_codes = [];

        foreach ($languages as $key => $entry) {
            $code = '';
            $label = '';
            $flag_id = 0;

            if (is_array($entry)) {
                $raw_code = (string) ($entry['code'] ?? $key);
                $code = sanitize_key($raw_code);
                $label = sanitize_text_field((string) ($entry['label'] ?? ''));
                $flag_id = absint($entry['flag_id'] ?? 0);
            } elseif (is_string($entry)) {
                $code = sanitize_key((string) $key);
                $label = sanitize_text_field($entry);
            } else {
                continue;
            }

            if ($code === '' || isset($seen_codes[$code])) {
                continue;
            }

            if ($label === '') {
                $label = strtoupper($code);
            }

            $normalized[] = [
                'code' => $code,
                'label' => $label,
                'flag_id' => $flag_id,
            ];
            $seen_codes[$code] = true;
        }

        if (empty($normalized)) {
            $normalized[] = [
                'code' => 'de',
                'label' => 'Deutsch',
                'flag_id' => 0,
            ];
        }

        return $normalized;
    }

    private function sanitize_language_base_map($map, array $active_codes): array
    {
        if (!is_array($map) || empty($active_codes)) {
            return [];
        }

        $active_lookup = array_fill_keys($active_codes, true);
        $sanitized = [];

        foreach ($map as $entity => $by_lang) {
            if (!is_array($by_lang)) {
                continue;
            }

            $entity_key = sanitize_key((string) $entity);
            if ($entity_key === '') {
                continue;
            }

            foreach ($by_lang as $lang_code => $slug) {
                $code = sanitize_key((string) $lang_code);
                if ($code === '' || !isset($active_lookup[$code])) {
                    continue;
                }

                $sanitized[$entity_key][$code] = sanitize_title((string) $slug);
            }
        }

        return $sanitized;
    }

    /**
     * Renders the Multilingual configuration form section for Site Settings.
     */
    public function maybe_render_form_section(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml_mod = $plugin->get_module('multilingual');
        if (!$ml_mod) {
            return;
        }

        \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render($this->module);
    }
}

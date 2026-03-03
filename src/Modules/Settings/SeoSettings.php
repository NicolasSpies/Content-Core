<?php
namespace ContentCore\Modules\Settings;

/**
 * Handles SEO and site-wide image settings.
 */
class SeoSettings
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
     * Initialize SEO settings registration.
     */
    public function init(): void
    {
        $default_title = (string) get_bloginfo('name');
        $default_desc = (string) get_bloginfo('description');
        $default_separator = '—';
        $default_title_template = '{page} {site}';

        $this->module->get_registry()->register(SettingsModule::SEO_KEY, [
            'default' => [
                // Legacy-compatible top-level keys
                'title' => $default_title,
                'description' => $default_desc,
                'title_separator' => $default_separator,
                'title_template' => $default_title_template,

                // Extended SEO model
                'global' => [
                    'title' => $default_title,
                    'description' => $default_desc,
                    'title_separator' => $default_separator,
                    'title_template' => $default_title_template,
                    'robots' => 'index,follow',
                    'canonical_url' => '',
                    'og_image_id' => 0,
                ],
                'page_overrides' => [],
                'post_type_templates' => [],
                'global_by_lang' => [],
                'page_overrides_by_lang' => [],
                'post_type_templates_by_lang' => [],
            ],
            'sanitize_callback' => [$this, 'sanitize_seo_settings'],
        ]);
    }

    /**
     * Sanitize SEO settings.
     */
    public function sanitize_seo_settings(array $settings): array
    {
        $default_title = (string) get_bloginfo('name');
        $default_desc = (string) get_bloginfo('description');
        $default_separator = '—';
        $default_title_template = '{page} {site}';

        $input_global = isset($settings['global']) && is_array($settings['global']) ? $settings['global'] : [];

        // Legacy payload fallback
        if (empty($input_global)) {
            $input_global = [
                'title' => $settings['title'] ?? '',
                'description' => $settings['description'] ?? '',
                'title_separator' => $settings['title_separator'] ?? $default_separator,
                'title_template' => $settings['title_template'] ?? $default_title_template,
            ];
        }

        $global = $this->sanitize_global_entry($input_global, $default_title, $default_desc, $default_separator, $default_title_template);

        if ($global['title'] === '') {
            $global['title'] = $default_title;
        }
        if ($global['title_separator'] === '') {
            $global['title_separator'] = $default_separator;
        }
        if ($global['title_template'] === '') {
            $global['title_template'] = $default_title_template;
        }

        $page_overrides = [];
        if (isset($settings['page_overrides']) && is_array($settings['page_overrides'])) {
            foreach ($settings['page_overrides'] as $post_id => $row) {
                $id = absint($post_id);
                if ($id <= 0 || !is_array($row)) {
                    continue;
                }

                $page_overrides[(string) $id] = $this->sanitize_page_override_entry($row);
            }
        }

        $post_type_templates = [];
        if (isset($settings['post_type_templates']) && is_array($settings['post_type_templates'])) {
            foreach ($settings['post_type_templates'] as $post_type => $row) {
                $slug = sanitize_key((string) $post_type);
                if ($slug === '' || !is_array($row)) {
                    continue;
                }

                $post_type_templates[$slug] = $this->sanitize_post_type_template_entry($row);
            }
        }

        $global_by_lang = [];
        if (isset($settings['global_by_lang']) && is_array($settings['global_by_lang'])) {
            foreach ($settings['global_by_lang'] as $lang => $entry) {
                $lang_code = sanitize_key((string) $lang);
                if ($lang_code === '' || !is_array($entry)) {
                    continue;
                }
                $global_by_lang[$lang_code] = $this->sanitize_global_entry($entry, $default_title, $default_desc, $default_separator, $default_title_template);
            }
        }

        $page_overrides_by_lang = [];
        if (isset($settings['page_overrides_by_lang']) && is_array($settings['page_overrides_by_lang'])) {
            foreach ($settings['page_overrides_by_lang'] as $lang => $rows) {
                $lang_code = sanitize_key((string) $lang);
                if ($lang_code === '' || !is_array($rows)) {
                    continue;
                }
                $clean_rows = [];
                foreach ($rows as $post_id => $row) {
                    $id = absint($post_id);
                    if ($id <= 0 || !is_array($row)) {
                        continue;
                    }
                    $clean_rows[(string) $id] = $this->sanitize_page_override_entry($row);
                }
                $page_overrides_by_lang[$lang_code] = $clean_rows;
            }
        }

        $post_type_templates_by_lang = [];
        if (isset($settings['post_type_templates_by_lang']) && is_array($settings['post_type_templates_by_lang'])) {
            foreach ($settings['post_type_templates_by_lang'] as $lang => $rows) {
                $lang_code = sanitize_key((string) $lang);
                if ($lang_code === '' || !is_array($rows)) {
                    continue;
                }
                $clean_rows = [];
                foreach ($rows as $post_type => $row) {
                    $slug = sanitize_key((string) $post_type);
                    if ($slug === '' || !is_array($row)) {
                        continue;
                    }
                    $clean_rows[$slug] = $this->sanitize_post_type_template_entry($row);
                }
                $post_type_templates_by_lang[$lang_code] = $clean_rows;
            }
        }

        return [
            // Keep top-level keys for older consumers.
            'title' => $global['title'],
            'description' => $global['description'],
            'title_separator' => $global['title_separator'],
            'title_template' => $global['title_template'],
            // Extended model.
            'global' => $global,
            'page_overrides' => $page_overrides,
            'post_type_templates' => $post_type_templates,
            'global_by_lang' => $global_by_lang,
            'page_overrides_by_lang' => $page_overrides_by_lang,
            'post_type_templates_by_lang' => $post_type_templates_by_lang,
        ];
    }

    private function sanitize_global_entry(array $input_global, string $default_title, string $default_desc, string $default_separator, string $default_title_template): array
    {
        $global = [
            'title' => sanitize_text_field((string) ($input_global['title'] ?? $default_title)),
            'description' => sanitize_textarea_field((string) ($input_global['description'] ?? $default_desc)),
            'title_separator' => sanitize_text_field((string) ($input_global['title_separator'] ?? $default_separator)),
            'title_template' => sanitize_text_field((string) ($input_global['title_template'] ?? $default_title_template)),
            'robots' => $this->sanitize_robots((string) ($input_global['robots'] ?? 'index,follow')),
            'canonical_url' => esc_url_raw((string) ($input_global['canonical_url'] ?? '')),
            'og_image_id' => absint($input_global['og_image_id'] ?? 0),
        ];

        if ($global['title'] === '') {
            $global['title'] = $default_title;
        }
        if ($global['title_separator'] === '') {
            $global['title_separator'] = $default_separator;
        }
        if ($global['title_template'] === '') {
            $global['title_template'] = $default_title_template;
        }

        return $global;
    }

    private function sanitize_page_override_entry(array $row): array
    {
        return [
            'title' => sanitize_text_field((string) ($row['title'] ?? '')),
            'description' => sanitize_textarea_field((string) ($row['description'] ?? '')),
            'robots' => $this->sanitize_robots((string) ($row['robots'] ?? '')),
            'canonical_url' => esc_url_raw((string) ($row['canonical_url'] ?? '')),
            'og_image_id' => absint($row['og_image_id'] ?? 0),
        ];
    }

    private function sanitize_post_type_template_entry(array $row): array
    {
        return [
            'title_template' => sanitize_text_field((string) ($row['title_template'] ?? '')),
            'description_template' => sanitize_textarea_field((string) ($row['description_template'] ?? '')),
            'robots' => $this->sanitize_robots((string) ($row['robots'] ?? '')),
            'canonical_template' => sanitize_text_field((string) ($row['canonical_template'] ?? '')),
            'og_image_id' => absint($row['og_image_id'] ?? 0),
            'og_image_token' => sanitize_text_field((string) ($row['og_image_token'] ?? '')),
        ];
    }

    private function sanitize_robots(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = ['index,follow', 'noindex,nofollow', 'index,nofollow', 'noindex,follow'];
        if (!in_array($value, $allowed, true)) {
            return 'index,follow';
        }
        return $value;
    }
}

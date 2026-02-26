<?php
namespace ContentCore\Modules\Multilingual;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\Multilingual\Admin\LanguageEditor;
use ContentCore\Modules\Multilingual\Admin\LanguageListColumns;
use ContentCore\Modules\Multilingual\Rest\MultilingualRestHandler;
use ContentCore\Modules\Multilingual\Data\TranslationManager;

class MultilingualModule implements ModuleInterface
{
    const SETTINGS_KEY = 'cc_languages_settings';

    private ?LanguageEditor $editor = null;
    private ?LanguageListColumns $columns = null;
    private ?MultilingualRestHandler $rest = null;
    private ?TranslationManager $translation_manager = null;

    public function init(): void
    {
        $this->translation_manager = new TranslationManager($this);

        if (is_admin()) {
            $this->editor = new LanguageEditor($this);
            $this->editor->init();

            $this->columns = new LanguageListColumns($this);
            $this->columns->init();


            add_action('registered_post_type', [$this, 'handle_registered_post_type'], 10, 2);
            add_action('admin_bar_menu', [$this, 'add_admin_bar_switcher'], 100);


            add_action('admin_action_cc_create_translation', [$this, 'handle_create_translation']);

            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);
            add_action('admin_init', [$this, 'handle_forms_backfill']);
        }

        $this->rest = new MultilingualRestHandler($this);
        $this->rest->init();

        add_action('save_post', [$this, 'handle_post_save'], 10, 2);
        add_action('init', [$this, 'cc_add_rewrite_rules'], 10);
        add_action('init', [$this, 'maybe_flush_rewrites'], 11);

        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('post_link', [$this, 'cc_filter_post_link'], 10, 2);
        add_filter('page_link', [$this, 'cc_filter_page_link'], 10, 2);
        add_filter('post_type_link', [$this, 'cc_filter_post_link'], 10, 2);

        // Taxonomy hooks
        add_action('edited_term', [$this, 'handle_term_save'], 10, 3);
        add_action('create_term', [$this, 'handle_term_save'], 10, 3);
        add_filter('term_link', [$this, 'cc_filter_term_link'], 10, 3);
    }

    public function register_query_vars($vars): array
    {
        $vars[] = 'cc_lang';
        return $vars;
    }

    public function get_settings(): array
    {
        $defaults = [
            'enabled' => false,
            'default_lang' => 'de',
            'languages' => [],
            'fallback_enabled' => false,
            'fallback_lang' => 'de',
            'permalink_enabled' => false,
            'permalink_bases' => [],
            'taxonomy_bases' => [],
            'enable_rest_seo' => false,
            'enable_headless_fallback' => false,
            'enable_localized_taxonomies' => false,
            'enable_sitemap_endpoint' => false,
            'show_admin_bar' => false,
        ];
        $settings = get_option(self::SETTINGS_KEY, []);
        return array_merge($defaults, is_array($settings) ? $settings : []);
    }

    public function handle_registered_post_type(string $post_type, \WP_Post_Type $args): void
    {
        if (($args->public || $post_type === 'cc_form') && $post_type !== 'attachment') {
            add_post_type_support($post_type, 'cc-multilingual');

            if (is_admin()) {
            }
        }
    }

    public function is_active(): bool
    {
        $settings = $this->get_settings();
        return !empty($settings['enabled']) && !empty($settings['languages']);
    }

    public function handle_post_save(int $post_id, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if ($post->post_status === 'auto-draft' || $post->post_type === 'revision')
            return;
        if (!post_type_supports($post->post_type, 'cc-multilingual'))
            return;

        if (!$this->is_active())
            return;

        // Ensure default language on new posts
        if (!get_post_meta($post_id, '_cc_language', true)) {
            $settings = $this->get_settings();
            $admin_lang = get_user_meta(get_current_user_id(), 'cc_admin_language', true);
            $lang = (!empty($admin_lang) && $admin_lang !== 'all') ? $admin_lang : ($settings['default_lang'] ?? 'de');
            update_post_meta($post_id, '_cc_language', $lang);
        }

        // Ensure translation group ID
        if (!get_post_meta($post_id, '_cc_translation_group', true)) {
            update_post_meta($post_id, '_cc_translation_group', wp_generate_uuid4());
        }

        // Auto-generate slug if empty and title exists
        if (empty($post->post_name) && !empty($post->post_title)) {
            $desired_slug = sanitize_title($post->post_title);
            $unique_slug = wp_unique_post_slug(
                $desired_slug,
                $post_id,
                $post->post_status,
                $post->post_type,
                $post->post_parent
            );

            if ($unique_slug !== $post->post_name) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    ['post_name' => $unique_slug],
                    ['ID' => $post_id]
                );
                // Clean cache
                clean_post_cache($post_id);
            }
        }
    }


    public function handle_create_translation(): void
    {
        check_admin_referer('cc_create_translation_' . $_GET['post'], 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to create translations.', 'content-core'));
        }

        $source_id = (int) $_GET['post'];
        $target_lang = sanitize_text_field($_GET['lang']);

        $new_id = $this->translation_manager->create_translation($source_id, $target_lang);

        if (is_wp_error($new_id)) {
            wp_die($new_id->get_error_message());
        }

        wp_safe_redirect(get_edit_post_link($new_id, 'raw'));
        exit;
    }

    public function get_translation_manager(): TranslationManager
    {
        return $this->translation_manager;
    }

    public function get_columns_handler(): ?LanguageListColumns
    {
        return $this->columns;
    }

    /**
     * Enqueue column styles only on relevant admin screens
     */
    public function enqueue_admin_styles($hook): void
    {
        if (!in_array($hook, ['edit.php', 'post.php', 'post-new.php'], true)) {
            return;
        }

        if (!$this->is_active()) {
            return;
        }

        $css = "
            /* Translation Column Flags - Unified Styling */
            .column-cc_translations {
                text-align: left !important;
                width: 125px !important;
            }
            .column-cc_translations .cc-translation-column-wrap {
                display: flex !important;
                flex-direction: row !important;
                justify-content: flex-start !important;
                gap: 6px !important;
                align-items: center !important;
                vertical-align: middle !important;
                flex-wrap: nowrap !important;
                line-height: 1 !important;
            }
            .column-cc_translations .cc-flag {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                text-decoration: none !important;
                transition: all 0.2s ease-in-out !important;
                height: 20px !important;
                padding: 0 !important;
                margin: 0 !important;
                line-height: 0 !important;
            }
            
            /* Semantic Opacity Rules */
            .cc-flag.cc-flag--missing,
            .cc-flag.cc-flag--missing img,
            .cc-flag.cc-flag--missing span {
                opacity: 0.3 !important;
            }
            
            .cc-flag.cc-flag--exists,
            .cc-flag.cc-flag--exists img,
            .cc-flag.cc-flag--exists span {
                opacity: 1.0 !important;
            }
            
            /* Hover interactions */
            .cc-flag:hover,
            .cc-flag:hover img,
            .cc-flag:hover span {
                opacity: 1.0 !important;
                transform: scale(1.1) !important;
            }
            
            /* Content consistency */
            .cc-flag img,
            .cc-flag span {
                max-width: 18px !important;
                height: auto !important;
                border-radius: 1px !important;
                display: inline-block !important;
            }
        ";

        wp_add_inline_style('common', $css);
    }

    /**
     * Enqueue minimal styles for the admin toolbar switcher badge
     */
    public function enqueue_toolbar_styles(): void
    {
        if (!$this->get_settings()['show_admin_bar']) {
            return;
        }

        // Guard clause: Toolbar styles are only enqueued when admin bar UI is enabled

        if (!is_admin_bar_showing() || !$this->is_active()) {
            return;
        }

        $css = "
            /* Badge styling for top-level only */
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > a.ab-item {
                background-color: #f59e0b !important;
                color: #ffffff !important;
                border-radius: 12px !important;
                margin-top: 4px !important;
                height: 24px !important;
                line-height: 24px !important;
                padding: 0 12px !important;
                display: flex !important;
                align-items: center !important;
                font-weight: 600 !important;
                font-size: 11px !important;
            }
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > .ab-item:before,
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > .ab-item .ab-icon {
                display: none !important;
            }
            /* Flag styling in top-level badge */
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > .ab-item img,
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > .ab-item .cc-chip-emoji,
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher > .ab-item span[style*='font-size: 16px'] {
                margin: 0 6px 0 0 !important;
                display: flex !important;
                align-items: center !important;
                height: 100% !important;
                width: auto !important;
            }
            /* Submenu resets */
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item {
                background-color: transparent !important;
                color: inherit !important;
                display: block !important;
                height: auto !important;
                padding: 6px 15px !important;
                margin: 0 !important;
                border-radius: 0 !important;
                font-weight: normal !important;
                font-size: 13px !important;
                line-height: 1.4 !important;
            }
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item:hover {
                color: #72aee6 !important;
            }
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-row {
                display: inline-flex !important;
                align-items: center !important;
                gap: 10px !important;
                width: 100% !important;
            }
            /* Flag styling in submenu */
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-flag {
                display: flex !important;
                align-items: center !important;
                width: 20px !important;
                justify-content: center !important;
            }
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-flag img,
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-flag .cc-chip-emoji,
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-flag span[style*='font-size: 16px'] {
                margin: 0 !important;
                display: block !important;
                height: 14px !important;
                width: auto !important;
                line-height: 1 !important;
                vertical-align: top !important;
            }
            #wpadminbar #wp-admin-bar-cc-multilingual-switcher .ab-sub-wrapper .ab-item .cc-lang-label {
                display: inline-block !important;
                line-height: 1.4 !important;
            }
            @media screen and (max-width: 782px) {
                #wpadminbar #wp-admin-bar-cc-multilingual-switcher > a.ab-item {
                    margin-top: 11px !important;
                }
            }
        ";

        wp_add_inline_style('admin-bar', $css);
    }

    public function add_admin_bar_switcher(\WP_Admin_Bar $wp_admin_bar): void
    {
        // Internal: Admin bar language UI is intentionally disabled to reduce confusion
        // While leaving list table filtering and translation management intact.
        if (!$this->get_settings()['show_admin_bar']) {
            return;
        }

        if (!$this->is_active() || !current_user_can('edit_posts')) {
            return;
        }

        $settings = $this->get_settings();
        $languages = $settings['languages'];
        $default_lang = $settings['default_lang'] ?? 'de';

        // Sort languages: Default first, then alphabetical by label
        usort($languages, function ($a, $b) use ($default_lang) {
            if ($a['code'] === $default_lang)
                return -1;
            if ($b['code'] === $default_lang)
                return 1;
            return strcmp($a['label'], $b['label']);
        });

        $current_user_id = get_current_user_id();
        $admin_lang = get_user_meta($current_user_id, 'cc_admin_language', true) ?: 'all';

        $current_label = '';
        $current_flag = '';

        // Default language if 'all' or not set
        $active_code = ($admin_lang === 'all') ? ($settings['default_lang'] ?? 'de') : $admin_lang;

        foreach ($languages as $l) {
            if ($l['code'] === $active_code) {
                $current_label = $l['label'] . ' (' . strtoupper($l['code']) . ')';
                $current_flag = $this->get_flag_html($l['code'], $l['flag_id'] ?? 0);
                break;
            }
        }

        // Final fallback if something is weird
        if (empty($current_label)) {
            $current_label = strtoupper($active_code);
        }

        $wp_admin_bar->add_node([
            'id' => 'cc-multilingual-switcher',
            'title' => $current_flag . ' ' . esc_html($current_label),
            'href' => '#',
            'meta' => [
                'title' => __('Switch Admin Language', 'content-core'),
                'class' => 'cc-multilingual-badge'
            ]
        ]);

        foreach ($languages as $lang) {
            $flag = $this->get_flag_html($lang['code'], $lang['flag_id'] ?? 0);
            $wp_admin_bar->add_node([
                'id' => 'cc-ml-' . $lang['code'],
                'parent' => 'cc-multilingual-switcher',
                'title' => '<span class="cc-lang-row"><span class="cc-lang-flag">' . $flag . '</span><span class="cc-lang-label">' . esc_html($lang['label']) . ' (' . strtoupper(esc_html($lang['code'])) . ')</span></span>',
                'href' => add_query_arg([
                    'action' => 'cc_switch_admin_language',
                    'lang' => $lang['code'],
                    'nonce' => wp_create_nonce('cc_switch_admin_language')
                ], admin_url('admin.php'))
            ]);
        }

        // Add context translation links if editing a post
        $screen = get_current_screen();
        if ($screen && $screen->base === 'post') {
            $post = get_post();
            if ($post && post_type_supports($post->post_type, 'cc-multilingual')) {
                $group_id = get_post_meta($post->ID, '_cc_translation_group', true);
                if ($group_id) {
                    $translations = $this->translation_manager->get_translations($group_id);

                    $wp_admin_bar->add_node([
                        'id' => 'cc-ml-separator',
                        'parent' => 'cc-multilingual-switcher',
                        'title' => '<hr style="margin: 4px 0; border: none; border-top: 1px solid #444;">',
                        'meta' => ['class' => 'ab-item-separator']
                    ]);

                    foreach ($languages as $lang) {
                        $code = $lang['code'];
                        if ($code === get_post_meta($post->ID, '_cc_language', true))
                            continue;

                        if (isset($translations[$code])) {
                            $title = sprintf(__('Edit %s', 'content-core'), strtoupper($code));
                            $href = get_edit_post_link($translations[$code]);
                        } else {
                            $title = sprintf(__('Translate to %s', 'content-core'), strtoupper($code));
                            $href = add_query_arg([
                                'action' => 'cc_create_translation',
                                'post' => $post->ID,
                                'lang' => $code,
                                'nonce' => wp_create_nonce('cc_create_translation_' . $post->ID)
                            ], admin_url('admin.php'));
                        }

                        $wp_admin_bar->add_node([
                            'id' => 'cc-ml-ctx-' . $code,
                            'parent' => 'cc-multilingual-switcher',
                            'title' => '<span class="cc-lang-row"><span class="cc-lang-flag">' . $this->get_flag_html($code) . '</span><span class="cc-lang-label">' . esc_html($title) . '</span></span>',
                            'href' => $href
                        ]);
                    }
                }
            }
        }
    }

    public function get_flag_html(string $code, int $flag_id = 0): string
    {
        if ($flag_id > 0) {
            $img = wp_get_attachment_image_src($flag_id, [32, 32]);
            if ($img) {
                return '<img src="' . esc_url($img[0]) . '" alt="' . esc_attr($code) . '" />';
            }
        }

        $flags = [
            'de' => '🇩🇪',
            'en' => '🇬🇧',
            'fr' => '🇫🇷',
            'it' => '🇮🇹',
            'es' => '🇪🇸',
            'nl' => '🇳🇱',
            'pt' => '🇵🇹',
            'pl' => '🇵🇱',
            'ru' => '🇷🇺',
            'tr' => '🇹🇷'
        ];

        if (isset($flags[$code])) {
            return '<span class="cc-chip-emoji">' . $flags[$code] . '</span>';
        }

        // Fallback to text badge
        return '<span class="cc-lang-badge">' . esc_html(strtoupper($code)) . '</span>';
    }

    public function cc_filter_post_link(string $post_link, \WP_Post $post): string
    {
        if (!post_type_supports($post->post_type, 'cc-multilingual'))
            return $post_link;
        $settings = $this->get_settings();
        if (empty($settings['permalink_enabled']))
            return $post_link;

        $lang = get_post_meta($post->ID, '_cc_language', true);
        $default_lang = $settings['default_lang'] ?? 'de';
        if (!$lang || $lang === $default_lang)
            return $post_link;

        $bases = $settings['permalink_bases'] ?? [];
        $obj = get_post_type_object($post->post_type);
        $default_slug = $obj->rewrite['slug'] ?? ($post->post_type === 'post' ? '' : $post->post_type);
        $base = $bases[$post->post_type][$lang] ?? $default_slug;

        $home_url = home_url('/');
        $slug = $post->post_name;
        $url_path = $lang . '/' . (!empty($base) ? $base . '/' : '') . $slug;
        return user_trailingslashit($home_url . $url_path);
    }

    public function cc_filter_page_link(string $post_link, int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page')
            return $post_link;
        $settings = $this->get_settings();
        if (empty($settings['permalink_enabled']))
            return $post_link;

        $lang = get_post_meta($post_id, '_cc_language', true);
        $default_lang = $settings['default_lang'] ?? 'de';
        if (!$lang || $lang === $default_lang)
            return $post_link;

        return user_trailingslashit(home_url('/') . $lang . '/' . get_page_uri($post_id));
    }

    public function handle_term_save(int $term_id, int $tt_id, string $taxonomy): void
    {
        if (isset($_POST['cc_term_language'])) {
            update_term_meta($term_id, '_cc_language', sanitize_text_field($_POST['cc_term_language']));
        }
    }

    public function cc_filter_term_link(string $url, \WP_Term $term, string $taxonomy): string
    {
        $settings = $this->get_settings();
        if (empty($settings['permalink_enabled']))
            return $url;

        $lang = get_term_meta($term->term_id, '_cc_language', true) ?: $settings['default_lang'];
        if ($lang === $settings['default_lang'])
            return $url;

        $home_url = home_url('/');
        $path = str_replace($home_url, '', $url);
        $tax_bases = $settings['taxonomy_bases'] ?? [];
        $tax_obj = get_taxonomy($taxonomy);
        $default_base = $tax_obj->rewrite['slug'] ?? $taxonomy;
        $localized_base = $tax_bases[$taxonomy][$lang] ?? $default_base;

        if ($localized_base !== $default_base) {
            $path = preg_replace('/^' . preg_quote($default_base, '/') . '\//', $localized_base . '/', $path);
        }

        return $home_url . $lang . '/' . $path;
    }

    public function cc_add_rewrite_rules(): void
    {
        $settings = $this->get_settings();
        if (empty($settings['enabled']) || empty($settings['permalink_enabled']))
            return;

        $languages = array_column($settings['languages'], 'code');
        $other_langs = array_diff($languages, [$settings['default_lang']]);
        if (empty($other_langs))
            return;

        $lang_regex = '(' . implode('|', $other_langs) . ')';
        add_rewrite_rule('^' . $lang_regex . '/(.?.+?)(?:/([0-9]+))?/?$', 'index.php?pagename=$matches[2]&cc_lang=$matches[1]&page=$matches[3]', 'top');

        $public_pts = get_post_types(['public' => true], 'objects');
        $bases = $settings['permalink_bases'] ?? [];
        foreach ($public_pts as $pt) {
            if ($pt->name === 'page' || $pt->name === 'attachment')
                continue;
            foreach ($other_langs as $lang) {
                $base = $bases[$pt->name][$lang] ?? ($pt->rewrite['slug'] ?? $pt->name);
                if (empty($base))
                    continue;
                add_rewrite_rule('^' . $lang . '/' . preg_quote($base, '/') . '/([^/]+)/?$', 'index.php?' . $pt->query_var . '=$matches[1]&cc_lang=' . $lang, 'top');
            }
        }
    }

    public function maybe_flush_rewrites(): void
    {
        if (get_transient('cc_flush_multilingual_rewrites')) {
            delete_transient('cc_flush_multilingual_rewrites');
            flush_rewrite_rules();
        }
    }

    /**
     * One-time backfill for cc_form posts to ensure they have multilingual meta.
     */
    public function handle_forms_backfill(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (get_option('cc_forms_migrated_v1')) {
            return;
        }

        $settings = $this->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        $posts = get_posts([
            'post_type' => 'cc_form',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
            // Only find posts missing the meta
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_cc_language',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_cc_translation_group',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);

        if (!empty($posts)) {
            foreach ($posts as $post_id) {
                if (!get_post_meta($post_id, '_cc_language', true)) {
                    update_post_meta($post_id, '_cc_language', $default_lang);
                }
                if (!get_post_meta($post_id, '_cc_translation_group', true)) {
                    update_post_meta($post_id, '_cc_translation_group', wp_generate_uuid4());
                }
            }
        }

        update_option('cc_forms_migrated_v1', time());
    }

    public static function get_language_catalog(): array
    {
        return [
            'de' => ['label' => 'Deutsch', 'code' => 'de'],
            'en' => ['label' => 'English', 'code' => 'en'],
            'fr' => ['label' => 'Français', 'code' => 'fr'],
            'it' => ['label' => 'Italiano', 'code' => 'it'],
            'es' => ['label' => 'Español', 'code' => 'es']
        ];
    }
}
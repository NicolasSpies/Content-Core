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

            // Handle admin language switching
            add_action('admin_action_cc_switch_admin_language', [$this, 'handle_switch_admin_language']);

            // Handle translation creation
            add_action('admin_action_cc_create_translation', [$this, 'handle_create_translation']);
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
        ];
        $settings = get_option(self::SETTINGS_KEY, []);
        return array_merge($defaults, is_array($settings) ? $settings : []);
    }

    public function handle_registered_post_type(string $post_type, \WP_Post_Type $args): void
    {
        if ($args->public && $post_type !== 'attachment') {
            add_post_type_support($post_type, 'cc-multilingual');
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

    public function handle_switch_admin_language(): void
    {
        check_admin_referer('cc_switch_admin_language', 'nonce');

        if (!current_user_can('manage_options') && !current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to switch language.', 'content-core'));
        }

        $lang = sanitize_text_field($_GET['lang'] ?? 'all');
        update_user_meta(get_current_user_id(), 'cc_admin_language', $lang);

        $referer = wp_get_referer();
        wp_safe_redirect($referer ?: admin_url());
        exit;
    }

    public function handle_create_translation(): void
    {
        check_admin_referer('cc_create_translation_' . $_GET['post'], 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to create translations.', 'content-core'));
        }

        $source_id = (int)$_GET['post'];
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

    public function add_admin_bar_switcher(\WP_Admin_Bar $wp_admin_bar): void
    {
        if (!$this->is_active() || !current_user_can('edit_posts')) {
            return;
        }

        $settings = $this->get_settings();
        $languages = $settings['languages'];
        $current_user_id = get_current_user_id();
        $admin_lang = get_user_meta($current_user_id, 'cc_admin_language', true) ?: 'all';

        $current_label = __('All', 'content-core');
        $current_flag = '';
        if ($admin_lang !== 'all') {
            foreach ($languages as $l) {
                if ($l['code'] === $admin_lang) {
                    $current_label = strtoupper($l['code']);
                    $current_flag = $this->get_flag_html($l['code'], $l['flag_id'] ?? 0);
                    break;
                }
            }
        }

        $wp_admin_bar->add_node([
            'id' => 'cc-multilingual-switcher',
            'title' => '<span class="ab-icon dashicons-translation"></span> ' . $current_flag . ' ' . esc_html($current_label),
            'href' => '#',
            'meta' => ['title' => __('Switch Admin Language', 'content-core')]
        ]);

        $wp_admin_bar->add_node([
            'id' => 'cc-ml-all',
            'parent' => 'cc-multilingual-switcher',
            'title' => __('Show All Languages', 'content-core'),
            'href' => add_query_arg([
                'action' => 'cc_switch_admin_language',
                'lang' => 'all',
                'nonce' => wp_create_nonce('cc_switch_admin_language')
            ], admin_url('admin.php'))
        ]);

        foreach ($languages as $lang) {
            $flag = $this->get_flag_html($lang['code'], $lang['flag_id'] ?? 0);
            $wp_admin_bar->add_node([
                'id' => 'cc-ml-' . $lang['code'],
                'parent' => 'cc-multilingual-switcher',
                'title' => $flag . ' ' . esc_html($lang['label']) . ' (' . strtoupper(esc_html($lang['code'])) . ')',
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
                        }
                        else {
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
                            'title' => esc_html($title),
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
                return '<img src="' . esc_url($img[0]) . '" style="width:18px; height:12px; object-fit:cover; vertical-align:middle; border-radius:1px; margin-right:4px;" />';
            }
        }

        $flags = [
            'de' => '🇩🇪', 'en' => '🇬🇧', 'fr' => '🇫🇷', 'it' => '🇮🇹', 'es' => '🇪🇸',
            'nl' => '🇳🇱', 'pt' => '🇵🇹', 'pl' => '🇵🇱', 'ru' => '🇷🇺', 'tr' => '🇹🇷'
        ];
        $emoji = $flags[$code] ?? '🌐';
        return '<span style="font-size: 16px; margin-right: 4px;">' . $emoji . '</span>';
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
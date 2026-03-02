<?php
namespace ContentCore\Modules\Multilingual;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\Multilingual\Admin\LanguageEditor;
use ContentCore\Modules\Multilingual\Admin\LanguageListColumns;
use ContentCore\Modules\Multilingual\Admin\TermLanguageColumns;
use ContentCore\Modules\Multilingual\Admin\TermNativeLock;
use ContentCore\Modules\Multilingual\Admin\TermsManagerAdmin;
use ContentCore\Modules\Multilingual\Rest\MultilingualRestHandler;
use ContentCore\Modules\Multilingual\Rest\TermsManagerRestController;
use ContentCore\Modules\Multilingual\Data\TranslationManager;
use ContentCore\Modules\Multilingual\Data\TermTranslationManager;

class MultilingualModule implements ModuleInterface
{
    const SETTINGS_KEY = 'cc_languages_settings';

    private ?array $settings_cache = null;
    private ?LanguageEditor $editor = null;
    private ?LanguageListColumns $columns = null;
    private ?TermLanguageColumns $term_columns = null;
    private ?MultilingualRestHandler $rest = null;
    private ?TranslationManager $translation_manager = null;
    private ?TermTranslationManager $term_translation_manager = null;
    private ?TermNativeLock $term_lock = null;
    private ?TermsManagerAdmin $terms_manager_admin = null;
    private ?TermsManagerRestController $terms_manager_rest = null;
    private ?\ContentCore\Modules\Multilingual\Sync\PostSyncManager $post_sync = null;
    private ?\ContentCore\Modules\Multilingual\Sync\UrlRewriteManager $url_rewrite = null;
    private ?\ContentCore\Modules\Multilingual\Admin\AdminUIInjector $admin_ui = null;
    private ?\ContentCore\Modules\Multilingual\Sync\TermSyncManager $term_sync = null;
    private ?\ContentCore\Modules\Multilingual\Query\TermQueryInterceptor $term_query_interceptor = null;

    public function init(): void
    {
        $this->translation_manager = new TranslationManager([$this, 'get_settings']);
        $this->term_translation_manager = new TermTranslationManager([$this, 'get_settings']);

        $this->term_sync = new \ContentCore\Modules\Multilingual\Sync\TermSyncManager(
            [$this, 'get_settings'],
            $this->term_translation_manager
        );
        $this->term_sync->init();

        $this->post_sync = new \ContentCore\Modules\Multilingual\Sync\PostSyncManager(
            [$this, 'get_settings'],
            [$this, 'is_active'],
            $this->translation_manager
        );
        $this->post_sync->init();

        $this->url_rewrite = new \ContentCore\Modules\Multilingual\Sync\UrlRewriteManager([$this, 'get_settings']);
        $this->url_rewrite->init();

        // Manually add support for core types because registered_post_type already fired
        add_post_type_support('post', 'cc-multilingual');
        add_post_type_support('page', 'cc-multilingual');

        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            $this->editor = new LanguageEditor($this);
            $this->editor->init();

            $this->columns = new LanguageListColumns($this);
            $this->columns->init();

            $this->term_columns = new TermLanguageColumns($this, $this->term_translation_manager);
            $this->term_columns->init();

            add_action('registered_post_type', [$this, 'handle_registered_post_type'], 10, 2);

            $this->admin_ui = new \ContentCore\Modules\Multilingual\Admin\AdminUIInjector([$this, 'is_active']);
            $this->admin_ui->init();

            add_action('admin_init', [$this, 'handle_forms_backfill']);
            add_action('admin_init', [$this, 'maybe_migrate_legacy_terms']);

            // TermNativeLock is now a no-op — WordPress admin is fully restored.
            $this->term_lock = new TermNativeLock();
            $this->term_lock->init();

            // Terms Manager admin page (no AJAX, no legacy logic)
            $this->terms_manager_admin = new TermsManagerAdmin($this);
            add_action('admin_enqueue_scripts', [$this->terms_manager_admin, 'enqueue_assets']);
        }

        // Register Terms Manager REST routes
        add_action('rest_api_init', function () {
            $ns = \ContentCore\Plugin::get_instance()->get_rest_namespace();
            $this->terms_manager_rest = new TermsManagerRestController($this, $ns);
            $this->terms_manager_rest->register_routes();
        });

        // Query Interception
        $this->term_query_interceptor = new \ContentCore\Modules\Multilingual\Query\TermQueryInterceptor(
            [$this, 'get_settings'],
            [$this, 'is_active']
        );
        $this->term_query_interceptor->init();

        $this->rest = new MultilingualRestHandler($this);
        $this->rest->init();

        add_filter('query_vars', [$this, 'register_query_vars']);

        add_action('updated_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
        add_action('added_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
        add_action('deleted_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
    }

    public function clear_settings_cache(): void
    {
        $this->settings_cache = null;
    }

    public function register_query_vars($vars): array
    {
        $vars[] = 'cc_lang';
        return $vars;
    }

    public function get_settings(): array
    {
        if ($this->settings_cache !== null) {
            return $this->settings_cache;
        }

        $defaults = [
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
            'show_admin_bar' => false,
        ];
        $settings = get_option(self::SETTINGS_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $merged = array_merge((array) $defaults, (array) $settings);

        if (empty($merged['languages']) || !is_array($merged['languages'])) {
            $merged['languages'] = (array) ($defaults['languages'] ?? []);
        }

        $this->settings_cache = $merged;
        return $merged;
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

    public function get_translation_manager(): TranslationManager
    {
        return $this->translation_manager;
    }

    public function get_term_translation_manager(): TermTranslationManager
    {
        return $this->term_translation_manager;
    }

    public function get_columns_handler(): ?LanguageListColumns
    {
        return $this->columns;
    }

    public function get_flag_html(string $code, int $flag_id = 0): string
    {
        return $this->admin_ui->get_flag_html($code, $flag_id);
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

    /**
     * One-time migration: backfill _cc_language and _cc_translation_group on all
     * legacy terms that pre-date the multilingual system (i.e. are missing those metas).
     *
     * Safe on large datasets:
     *  - Uses get_terms() with NOT EXISTS meta_query so only un-tagged terms are fetched.
     *  - Checks each individual meta before writing (never overwrites existing values).
     *  - Guarded by a version option so it only runs once per installation.
     */
    public function maybe_migrate_legacy_terms(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        // Version flag — bump this string if you ever need to re-run the migration.
        if (get_option('cc_terms_lang_migrated_v3')) {
            return;
        }

        $settings = $this->get_settings();
        $default_lang = $settings['default_lang'] ?? 'de';

        // Get all public taxonomies (built-in + custom).
        $taxonomies = get_taxonomies(['public' => true], 'names');
        if (empty($taxonomies)) {
            update_option('cc_terms_lang_migrated_v2', time());
            return;
        }

        // Fetch terms that are missing EITHER meta — we'll check individually before writing.
        $terms = get_terms([
            'taxonomy' => array_values($taxonomies),
            'hide_empty' => false,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_cc_language',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => '_cc_translation_group',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term_id) {
                if (!get_term_meta($term_id, '_cc_language', true)) {
                    update_term_meta($term_id, '_cc_language', $default_lang);
                }
                if (!get_term_meta($term_id, '_cc_translation_group', true)) {
                    update_term_meta($term_id, '_cc_translation_group', wp_generate_uuid4());
                }
            }
        }

        update_option('cc_terms_lang_migrated_v3', time());
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
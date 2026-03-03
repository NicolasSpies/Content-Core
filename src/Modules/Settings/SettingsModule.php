<?php
namespace ContentCore\Modules\Settings;

use ContentCore\Modules\ModuleInterface;

/**
 * Class SettingsModule
 *
 * Orchestrates the various settings sub-modules and delegates UI/Logic to specialized services.
 */
class SettingsModule implements ModuleInterface
{
    public const OPTION_KEY = 'content_core_admin_menu_settings';
    public const ORDER_KEY = 'content_core_admin_menu_order';
    public const MEDIA_KEY = 'cc_media_settings';
    public const REDIRECT_KEY = 'cc_redirect_settings';
    public const ADMIN_BAR_KEY = 'cc_admin_bar_settings';
    public const SEO_KEY = 'cc_site_seo';
    public const COOKIE_KEY = 'cc_cookie_settings';

    public const ADMIN_SAFETY_SLUGS = ['options-general.php', 'plugins.php', 'content-core'];
    public const DEFAULT_HIDDEN = ['edit-comments.php', 'link-manager.php'];

    /** @var Data\SettingsRegistry */
    private $registry;

    /** @var Rest\SettingsRestController */
    private $rest_controller;

    /** @var SeoSettings */
    private $seo_settings;

    /** @var MediaSettings */
    private $media_settings;

    /** @var RedirectSettings */
    private $redirect_settings;

    /** @var VisibilitySettings */
    private $visibility_settings;

    /** @var MultilingualSettings */
    private $multilingual_settings;

    /** @var SiteImagesSettings */
    private $site_images_settings;

    /** @var CookieSettings */
    private $cookie_settings;

    /** @var SiteOptionsSettings */
    private $site_options_settings;

    /** @var Logic\MenuCategorizer */
    private $menu_categorizer;

    /** @var UI\SettingsAssets */
    private $assets_service;

    /** @var UI\SettingsRenderer */
    private $renderer_service;

    public function init(): void
    {
        $this->registry = new Data\SettingsRegistry();

        // Initialize sub-modules
        $this->seo_settings = new SeoSettings($this);
        $this->seo_settings->init();

        $this->media_settings = new MediaSettings($this);
        $this->media_settings->init();

        $this->site_images_settings = new SiteImagesSettings($this);
        $this->site_images_settings->init();

        $this->redirect_settings = new RedirectSettings($this);
        $this->redirect_settings->init();

        $this->visibility_settings = new VisibilitySettings($this);
        $this->visibility_settings->init();

        $this->multilingual_settings = new MultilingualSettings($this);
        $this->multilingual_settings->init();

        $this->cookie_settings = new CookieSettings($this);
        $this->cookie_settings->init();

        // Apply external registration filters
        $external_schemas = apply_filters('cc_settings_registry_schema', []);
        foreach ($external_schemas as $key => $schema) {
            $this->registry->register($key, $schema);
        }

        $this->site_options_settings = new SiteOptionsSettings($this);
        // Site options registration

        // Initialize Services
        $this->menu_categorizer = new Logic\MenuCategorizer($this);
        $this->assets_service = new UI\SettingsAssets();
        $this->renderer_service = new UI\SettingsRenderer($this);

        // Initialize REST controller
        if (class_exists('ContentCore\\Modules\\Settings\\Rest\\SettingsRestController')) {
            $this->rest_controller = new Rest\SettingsRestController($this);
            add_action('rest_api_init', [$this->rest_controller, 'register_routes']);
        }

        // Hooks
        add_action('admin_page_access_denied', [$this, 'handle_legacy_admin_redirects'], 1);
        add_action('init', [$this->redirect_settings, 'handle_frontend_redirect']);
        add_filter('upload_size_limit', [$this->media_settings, 'apply_upload_size_limit']);

        if (is_admin()) {
            add_action('admin_menu', [$this->visibility_settings, 'apply_menu_visibility'], 9999);
            add_action('admin_head', [$this->visibility_settings, 'apply_menu_visibility']);
            add_action('admin_menu', [$this->visibility_settings, 'apply_menu_order'], 999);
            add_action('admin_notices', [$this, 'render_admin_notices']);
            add_action('admin_enqueue_scripts', [$this->assets_service, 'enqueue']);

            add_action('wp_before_admin_bar_render', [$this->visibility_settings, 'apply_admin_bar_visibility']);
            add_action('admin_bar_menu', [$this->visibility_settings, 'apply_admin_bar_site_link'], 999);
            add_action('admin_init', [$this, 'handle_settings_post_save']);
        }
    }

    /**
     * Handle traditional PHP POST saves for settings.
     */
    public function handle_settings_post_save(): void
    {
        if (!is_admin() || !isset($_POST['cc_menu_settings_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['cc_menu_settings_nonce'], 'cc_save_menu_settings')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $submit_id = $_POST['cc_submit_id'] ?? '';
        $redirect_to = remove_query_arg(['cc_action', 'cc_settings_error'], $_SERVER['REQUEST_URI']);
        $success = true;
        $should_flush_multilingual_rewrites = false;

        // 1. Visibility Settings
        if (isset($_POST['cc_menu_admin']) || isset($_POST['cc_menu_client']) || isset($_POST['cc_admin_bar'])) {
            $vis_data = [
                'admin' => $_POST['cc_menu_admin'] ?? [],
                'client' => $_POST['cc_menu_client'] ?? []
            ];
            $success = $success && $this->registry->save(self::OPTION_KEY, $vis_data);

            if (isset($_POST['cc_admin_bar'])) {
                $success = $success && $this->registry->save(self::ADMIN_BAR_KEY, $_POST['cc_admin_bar']);
            }

            // Order handling
            if (isset($_POST['cc_core_order_admin']) || isset($_POST['cc_core_order_client'])) {
                $order = [
                    'admin' => $this->parse_order_input($_POST['cc_core_order_admin'] ?? ''),
                    'client' => $this->parse_order_input($_POST['cc_core_order_client'] ?? '')
                ];
                update_option(self::ORDER_KEY, $order);
            }
        }

        // 2. Media Settings
        if (isset($_POST['cc_media_settings'])) {
            $success = $success && $this->registry->save(self::MEDIA_KEY, $_POST['cc_media_settings']);
        }

        // 3. Redirect Settings
        if (isset($_POST['cc_redirect_settings'])) {
            $success = $success && $this->registry->save(self::REDIRECT_KEY, $_POST['cc_redirect_settings']);

            if (isset($_POST['cc_admin_bar_link'])) {
                $success = $success && $this->registry->save(self::ADMIN_BAR_KEY, $_POST['cc_admin_bar_link']);
            }
        }

        // 4. Multilingual Settings
        if (isset($_POST['cc_languages'])) {
            $success = $success && $this->registry->save('cc_languages_settings', $_POST['cc_languages']);
            $should_flush_multilingual_rewrites = true;
        }

        // 5. Branding Settings (PHP page)
        if (isset($_POST['cc_branding_settings']) && is_array($_POST['cc_branding_settings'])) {
            $success = $success && $this->registry->save('cc_branding_settings', $_POST['cc_branding_settings']);
        }

        if ($success) {
            if ($should_flush_multilingual_rewrites) {
                set_transient('cc_flush_multilingual_rewrites', 1, 300);
            }
            wp_safe_redirect(add_query_arg('cc_action', 'settings_saved', $redirect_to));
            exit;
        } else {
            set_transient('cc_settings_error', __('Failed to save some settings.', 'content-core'), 30);
            wp_safe_redirect($redirect_to);
            exit;
        }
    }

    /**
     * Get a specific settings sub-module
     */
    public function get_submodule(string $id)
    {
        $map = [
            'seo' => $this->seo_settings,
            'media' => $this->media_settings,
            'redirect' => $this->redirect_settings,
            'visibility' => $this->visibility_settings,
            'multilingual' => $this->multilingual_settings,
            'cookie' => $this->cookie_settings,
            'site_options' => $this->site_options_settings,
        ];
        return $map[$id] ?? null;
    }

    public function get_settings(): array
    {
        return $this->registry->get(self::OPTION_KEY);
    }

    public function get_registry(): Data\SettingsRegistry
    {
        return $this->registry;
    }

    public function handle_legacy_admin_redirects(): void
    {
        $redirector = new Logic\LegacyRedirector();
        $redirector->handle_legacy_admin_redirects();
    }

    public function get_all_menu_items(): array
    {
        return $this->menu_categorizer->get_all_menu_items();
    }

    public function categorize_items(array $items): array
    {
        return $this->menu_categorizer->categorize_items($items);
    }

    public function render_site_settings_page(): void
    {
        $this->renderer_service->render_site_settings_page();
    }

    public function render_settings_page(): void
    {
        $this->renderer_service->render_settings_page();
    }

    public function update_merged_option(string $key, array $new_data): void
    {
        $this->registry->save($key, $new_data);
    }

    public function render_admin_notices(): void
    {
        if (!is_admin())
            return;

        $error = get_transient('cc_settings_error');
        if ($error) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
            delete_transient('cc_settings_error');
        }

        // Success feedback is handled via shared JS toast to keep UX consistent across React and PHP pages.
    }

    /**
     * Parse visibility order input from either JSON array or comma-separated list.
     */
    private function parse_order_input($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode(wp_unslash($raw), true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map(function ($item) {
                return sanitize_text_field((string) $item);
            }, $decoded), function ($item) {
                return $item !== '';
            }));
        }

        $parts = explode(',', $raw);
        return array_values(array_filter(array_map(function ($item) {
            return sanitize_text_field(trim((string) $item));
        }, $parts), function ($item) {
            return $item !== '';
        }));
    }
}

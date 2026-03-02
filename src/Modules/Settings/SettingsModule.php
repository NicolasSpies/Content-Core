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
        $this->media_settings = new MediaSettings($this);
        $this->redirect_settings = new RedirectSettings($this);
        $this->visibility_settings = new VisibilitySettings($this);
        $this->multilingual_settings = new MultilingualSettings($this);
        $this->cookie_settings = new CookieSettings($this);
        $this->site_options_settings = new SiteOptionsSettings($this);

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

        if (isset($_GET['cc_action']) && $_GET['cc_action'] === 'settings_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'content-core') . '</p></div>';
        }
    }
}

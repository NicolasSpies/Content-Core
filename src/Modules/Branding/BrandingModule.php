<?php
namespace ContentCore\Modules\Branding;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Plugin;

class BrandingModule implements ModuleInterface
{
    public const SETTINGS_KEY = 'cc_branding_settings';

    /** @var Admin\BrandingInjector */
    private $injector;

    /** @var Admin\BrandingSettings */
    private $settings;

    /** @var array */
    private $settings_cache = null;

    public function init(): void
    {
        $this->injector = new Admin\BrandingInjector($this);
        $this->injector->init();

        $this->settings = new Admin\BrandingSettings($this);
        $this->settings->init();

        add_action('updated_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
        add_action('added_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
        add_action('deleted_option_' . self::SETTINGS_KEY, [$this, 'clear_settings_cache']);
    }

    public function get_settings(): array
    {
        if ($this->settings_cache !== null) {
            return $this->settings_cache;
        }

        $defaults = [
            'enabled' => false,
            'exclude_admins' => true,
            'login_logo' => '',
            'login_bg_color' => '#f0f0f1',
            'login_btn_color' => '#2271b1',
            'login_logo_link_url' => '',
            'admin_bar_logo' => '',
            'admin_bar_logo_url' => '',
            'admin_bar_logo_link_url' => '',
            'use_site_icon_for_admin_bar' => false,
            'custom_primary_color' => '',
            'custom_accent_color' => '',
            'remove_wp_mentions' => false,
            'custom_footer_text' => '',
        ];

        $saved = get_option(self::SETTINGS_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $this->settings_cache = array_merge($defaults, $saved);
        return $this->settings_cache;
    }

    public function clear_settings_cache(): void
    {
        $this->settings_cache = null;
    }
}

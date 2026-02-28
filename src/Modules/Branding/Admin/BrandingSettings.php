<?php
namespace ContentCore\Modules\Branding\Admin;

use ContentCore\Modules\Branding\BrandingModule;

class BrandingSettings
{
    /** @var BrandingModule */
    private $module;

    public function __construct(BrandingModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_filter('cc_settings_registry_schema', [$this, 'register_schema']);

        // Expose settings to the React App
        add_filter('cc_site_settings_localize', [$this, 'localize_settings']);
    }

    public function register_schema(array $registry): array
    {
        $registry[BrandingModule::SETTINGS_KEY] = [
            'type' => 'object',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => $this->module->get_settings()
        ];
        return $registry;
    }

    public function sanitize_settings(array $data): array
    {
        $clean = $this->module->get_settings();

        if (isset($data['enabled'])) {
            $clean['enabled'] = (bool) $data['enabled'];
        }
        if (isset($data['exclude_admins'])) {
            $clean['exclude_admins'] = (bool) $data['exclude_admins'];
        }
        if (isset($data['login_logo'])) {
            $val = $data['login_logo'];
            if (is_numeric($val)) {
                $clean['login_logo'] = absint($val);
            } elseif (is_string($val) && !empty($val)) {
                $id = attachment_url_to_postid($val);
                $clean['login_logo'] = $id ? $id : $val; // Store ID if found, else keep URL for now
            }
        }
        if (isset($data['login_bg_color'])) {
            $clean['login_bg_color'] = sanitize_hex_color($data['login_bg_color']);
        }
        if (isset($data['login_btn_color'])) {
            $clean['login_btn_color'] = sanitize_hex_color($data['login_btn_color']);
        }
        if (isset($data['login_logo_url'])) {
            $clean['login_logo_url'] = esc_url_raw($data['login_logo_url']);
        }
        if (isset($data['login_logo_link_url'])) {
            $clean['login_logo_link_url'] = esc_url_raw($data['login_logo_link_url']);
        }
        if (isset($data['admin_bar_logo'])) {
            $val = $data['admin_bar_logo'];
            if (is_numeric($val)) {
                $clean['admin_bar_logo'] = absint($val);
            } elseif (is_string($val) && !empty($val)) {
                $id = attachment_url_to_postid($val);
                $clean['admin_bar_logo'] = $id ? $id : $val;
            }
        }
        if (isset($data['admin_bar_logo_url'])) {
            $clean['admin_bar_logo_url'] = esc_url_raw($data['admin_bar_logo_url']);
        }
        if (isset($data['admin_bar_logo_link_url'])) {
            $clean['admin_bar_logo_link_url'] = esc_url_raw($data['admin_bar_logo_link_url']);
        }
        if (isset($data['use_site_icon_for_admin_bar'])) {
            $clean['use_site_icon_for_admin_bar'] = (bool) $data['use_site_icon_for_admin_bar'];
        }
        if (isset($data['custom_primary_color'])) {
            $clean['custom_primary_color'] = sanitize_hex_color($data['custom_primary_color']);
        }
        if (isset($data['custom_accent_color'])) {
            $clean['custom_accent_color'] = sanitize_hex_color($data['custom_accent_color']);
        }
        if (isset($data['remove_wp_mentions'])) {
            $clean['remove_wp_mentions'] = (bool) $data['remove_wp_mentions'];
        }
        if (isset($data['custom_footer_text'])) {
            $clean['custom_footer_text'] = wp_kses_post($data['custom_footer_text']);
        }

        return $clean;
    }

    public function localize_settings(array $data): array
    {
        // This makes sure the React app knows the current state when booting
        $data['branding'] = $this->module->get_settings();
        return $data;
    }
}

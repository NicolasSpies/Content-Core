<?php
namespace ContentCore\Modules\Branding\Admin;

use ContentCore\Modules\Branding\BrandingModule;

class BrandingInjector
{
    /** @var BrandingModule */
    private $module;

    public function __construct(BrandingModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('login_enqueue_scripts', [$this, 'inject_login_css']);
        add_filter('login_headerurl', [$this, 'filter_login_headerurl']);
        add_filter('login_headertext', [$this, 'filter_login_headertext']);

        // Hide language dropdown and "Go to..." link
        add_filter('login_display_language_dropdown', '__return_false');
        add_filter('login_site_html_link', '__return_empty_string');

        // Hooks that only run if we should brand the current user
        add_action('init', [$this, 'conditional_hooks']);
    }

    public function conditional_hooks(): void
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        if (!empty($settings['exclude_admins']) && current_user_can('manage_options')) {
            return;
        }

        add_action('admin_bar_menu', [$this, 'replace_admin_bar_logo'], 999);
        add_action('admin_enqueue_scripts', [$this, 'inject_admin_css']);

        if (!empty($settings['remove_wp_mentions'])) {
            add_filter('admin_footer_text', [$this, 'override_footer_text'], 999);
            add_filter('update_footer', '__return_empty_string', 999);
        }
    }

    public function inject_login_css(): void
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        $accent = !empty($settings['login_btn_color']) ? sanitize_hex_color($settings['login_btn_color']) : '#2271b1';
        $bg = !empty($settings['login_bg_color']) ? sanitize_hex_color($settings['login_bg_color']) : '#0f172a';

        // 1. Branding Module Logo (Attachment ID or URL)
        $logo_url = '';
        $val = $settings['login_logo'] ?? '';
        if (is_numeric($val) && (int) $val > 0) {
            $logo_url = wp_get_attachment_image_url($val, 'full') ?: wp_get_attachment_url($val);
        } elseif ($val && is_string($val)) {
            $logo_url = $val;
        }

        // 2. Fallback to raw URL field if still empty
        if (!$logo_url && !empty($settings['login_logo_url'])) {
            $logo_url = esc_url_raw($settings['login_logo_url']);
        }

        $logo_css = $logo_url
            ? "background-image:url('" . esc_url($logo_url) . "');"
            : "display:none;";

        $css = "
        :root{
            --cc-accent: {$accent};
            --cc-bg: {$bg};
            --cc-glass: rgba(255, 255, 255, 0.01);
            --cc-border: rgba(255, 255, 255, 0.05);
            --cc-ink: #f8fafc;
            --cc-ink-soft: #a2a8af;
        }

        html, body {
            height: 100% !important;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }

        body.login {
            background-color: var(--cc-bg) !important;
            background-image: radial-gradient(circle at center, rgba(255,255,255,0.02) 0%, transparent 100%) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            min-height: 100vh !important;
            color: var(--cc-ink);
        }

        body.login #login {
            width: 400px;
            padding: 0;
            margin: 0;
            z-index: 10;
        }

        /* Logo Styling */
        body.login h1 {
            margin-bottom: 32px;
        }
        body.login h1 a {
            width: 100%;
            height: 80px;
            background-size: contain;
            background-position: center;
            background-repeat: no-repeat;
            {$logo_css}
            margin: 0;
            padding: 0;
        }

        /* Form Card */
        body.login form {
            background: var(--cc-glass) !important;
            backdrop-filter: blur(40px);
            -webkit-backdrop-filter: blur(40px);
            border: 1px solid var(--cc-border) !important;
            border-radius: 28px !important;
            box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.6) !important;
            padding: 32px !important;
            margin: 0 !important;
        }

        body.login label {
            color: var(--cc-ink-soft) !important;
            font-size: 14px !important;
            font-weight: 500 !important;
            margin-bottom: 8px !important;
        }

        /* Specifically target form labels that should be blocks */
        body.login #loginform label[for='user_login'],
        body.login #loginform label[for='user_pass'] {
            display: block !important;
        }

        /* Fix Remember Me alignment */
        body.login .forgetmenot {
            margin-bottom: 24px !important;
            float: none !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        body.login .forgetmenot label {
            display: inline-block !important;
            margin-bottom: 0 !important;
            cursor: pointer;
            padding: 0 !important;
        }

        /* Input Styling */
        body.login input[type=text],
        body.login input[type=password] {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.08) !important;
            border-radius: 14px !important;
            color: #fff !important;
            padding: 14px 18px !important;
            box-shadow: none !important;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 15px !important;
        }

        body.login input[type=text]:focus,
        body.login input[type=password]:focus {
            background: rgba(255, 255, 255, 0.06) !important;
            border-color: var(--cc-accent) !important;
            outline: none !important;
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.05) !important;
        }

        /* Custom Checkbox */
        body.login input[type=checkbox] {
            background: rgba(255, 255, 255, 0.03) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 6px !important;
            width: 18px !important;
            height: 18px !important;
            margin-top: 2px !important;
        }
        body.login input[type=checkbox]:checked:before {
            content: url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'><path fill='white' d='M14.83 4.89l1.03 1.06L7.31 14.5l-4.15-4.15 1.06-1.06 3.09 3.09z'/></svg>\");
            margin: -3px 0 0 -1px !important;
        }

        body.login .forgetmenot {
            margin-bottom: 28px;
        }

        /* Primary Button */
        body.login .button-primary {
            background: var(--cc-accent) !important;
            border: none !important;
            border-radius: 14px !important;
            height: 52px !important;
            line-height: 52px !important;
            padding: 0 24px !important;
            font-weight: 600 !important;
            font-size: 15px !important;
            text-shadow: none !important;
            width: 100% !important;
            box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.3) !important;
            transition: transform 0.2s ease, filter 0.2s ease, box-shadow 0.2s ease !important;
        }
        body.login .button-primary:hover {
            filter: brightness(1.1) !important;
            box-shadow: 0 12px 20px -4px rgba(0, 0, 0, 0.4) !important;
        }
        body.login .button-primary:active {
            transform: translateY(1px);
        }

        /* Error/Message blocks */
        body.login #login_error,
        body.login .message,
        body.login .success {
            background: rgba(255, 255, 255, 0.05) !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-left: 4px solid var(--cc-accent) !important;
            border-radius: 14px !important;
            color: var(--cc-ink) !important;
            margin-bottom: 28px !important;
            box-shadow: none !important;
            backdrop-filter: blur(10px);
        }

        /* Navigation Links (Lost Password, etc) */
        body.login #nav,
        body.login #backtoblog {
            padding: 0 !important;
            margin-top: 28px !important;
            text-align: center !important;
        }

        body.login #nav a,
        body.login #backtoblog a {
            color: var(--cc-ink-soft) !important;
            font-size: 13px !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            display: inline-block;
            background: rgba(255, 255, 255, 0.03);
            padding: 10px 20px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        body.login #nav a:hover,
        body.login #backtoblog a:hover {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.08);
            border-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-1px);
        }

        /* Completely hide language selector and privacy link */
        .language-switcher,
        .privacy-policy-page-link,
        #login h1 a::after {
            display: none !important;
        }

        /* Subtle focus state */
        body.login input:focus {
            outline: none !important;
            box-shadow: 0 0 0 3px var(--cc-accent) !important;
        }
        ";

        echo "<style id='cc-branding-login-css'>\n" . $css . "\n</style>\n";
    }

    public function filter_login_headerurl(string $url): string
    {
        $settings = $this->module->get_settings();
        if (!empty($settings['enabled']) && !empty($settings['login_logo_link_url'])) {
            return esc_url($settings['login_logo_link_url']);
        }
        return home_url('/');
    }

    public function filter_login_headertext(string $text): string
    {
        return '';
    }

    public function replace_admin_bar_logo(\WP_Admin_Bar $wp_admin_bar): void
    {
        $settings = $this->module->get_settings();
        $use_site_icon = !empty($settings['use_site_icon_for_admin_bar']);
        $custom_logo = !empty($settings['admin_bar_logo']);

        // Remove standard WP Logo
        if (!empty($settings['remove_wp_mentions']) || $custom_logo || $use_site_icon) {
            $wp_admin_bar->remove_node('wp-logo');
        }

        // Add custom logo if set
        $logo_img_url = '';
        if ($use_site_icon) {
            $site_icon_id = get_option('site_icon');
            if ($site_icon_id) {
                $logo_img_url = wp_get_attachment_image_url($site_icon_id, 'full');
            }
        } elseif ($custom_logo) {
            $logo_img_url = wp_get_attachment_image_url($settings['admin_bar_logo'], 'full');
        }

        if ($logo_img_url) {
            $link_url = !empty($settings['admin_bar_logo_link_url']) ? esc_url($settings['admin_bar_logo_link_url']) : home_url('/');

            $wp_admin_bar->add_node([
                'id' => 'cc-client-logo',
                'title' => "<img src='{$logo_img_url}' alt='Client Logo' style='max-height: 20px; vertical-align: middle;' />",
                'href' => $link_url,
                'meta' => [
                    'class' => 'cc-admin-bar-logo'
                ]
            ]);
        }
    }

    public function inject_admin_css(): void
    {
        $settings = $this->module->get_settings();
        $css = [];

        if (!empty($settings['custom_primary_color'])) {
            $primary = sanitize_hex_color($settings['custom_primary_color']);
            if ($primary) {
                $css[] = ":root { --cc-brand-primary: {$primary}; }";
                // Gentle CSS overrides using CSS variables inside admin panels
                $css[] = "#wpadminbar { background-color: var(--cc-brand-primary) !important; }";
                $css[] = "#adminmenu .wp-menu-arrow div { background-color: var(--cc-brand-primary) !important; }";
            }
        }

        if (!empty($settings['custom_accent_color'])) {
            $accent = sanitize_hex_color($settings['custom_accent_color']);
            if ($accent) {
                $css[] = ":root { --cc-brand-accent: {$accent}; }";
                $css[] = ".wp-core-ui .button-primary { background: var(--cc-brand-accent) !important; border-color: var(--cc-brand-accent) !important; color: #fff !important; }";
                $css[] = ".wp-core-ui .button-primary:hover { filter: brightness(0.9) !important; }";

                // Active menu item and submenus
                $css[] = "#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu, #adminmenu li.current a.menu-top, #adminmenu .wp-menu-arrow div { background-color: var(--cc-brand-accent) !important; color: #fff !important; }";
                $css[] = "#adminmenu li.wp-has-current-submenu a.wp-has-current-submenu .wp-menu-image:before, #adminmenu li.current a.menu-top .wp-menu-image:before { color: #fff !important; }";

                // Admin menu hover states
                $css[] = "#adminmenu a:hover, #adminmenu li.menu-top:hover, #adminmenu .wp-submenu a:hover { color: var(--cc-brand-accent) !important; }";
                $css[] = "#adminmenu li.menu-top:hover .wp-menu-image:before { color: var(--cc-brand-accent) !important; }";

                // Submenu panel styling and positioning fix
                $css[] = "#adminmenu .wp-has-current-submenu .wp-submenu, #adminmenu .wp-has-current-submenu .wp-submenu.sub-open, #adminmenu .wp-has-current-submenu.opened .wp-submenu { background-color: #2F363D !important; border-left: 4px solid var(--cc-brand-accent) !important; box-sizing: border-box; }";
                // Only use left offset for fly-outs when the menu is NOT expanded
                $css[] = "body.folded #adminmenu .wp-has-current-submenu .wp-submenu, #adminmenu .opensub .wp-submenu { left: 160px !important; }";
            }
        }

        if (!empty($css)) {
            wp_add_inline_style('common', ":root { --cc-brand-active: 1; }\n" . implode("\n", $css));
        }
    }

    public function override_footer_text(string $text): string
    {
        $settings = $this->module->get_settings();
        if (!empty($settings['custom_footer_text'])) {
            return wp_kses_post($settings['custom_footer_text']);
        }
        return '';
    }
}

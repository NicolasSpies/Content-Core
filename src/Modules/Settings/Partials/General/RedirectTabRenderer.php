<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;
use ContentCore\Modules\Settings\RedirectSettings;

/**
 * Renders the Redirect Tab in General Settings.
 */
class RedirectTabRenderer
{
    public static function render(SettingsModule $settings_mod): void
    {
        $red_settings = $settings_mod->get_registry()->get(SettingsModule::REDIRECT_KEY);
        $red_defaults = RedirectSettings::get_defaults();
        if (!is_array($red_settings)) {
            $red_settings = [];
        }
        $red_settings = wp_parse_args($red_settings, $red_defaults);
        $red_settings['exclusions'] = wp_parse_args(
            is_array($red_settings['exclusions'] ?? null) ? $red_settings['exclusions'] : [],
            is_array($red_defaults['exclusions'] ?? null) ? $red_defaults['exclusions'] : []
        );
        ?>
        <div id="cc-settings-redirect">
            <!-- Card: Root Redirection -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-randomize"></span>
                        <?php _e('Root Redirection', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Configure where users are sent when visiting the site root (e.g. your CMS subdomain).', 'content-core'); ?>
                    </p>

                    <div class="cc-grid">
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Enable Root Redirect', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_redirect_settings[enabled]" value="0">
                                    <input type="checkbox" name="cc_redirect_settings[enabled]" value="1" <?php checked($red_settings['enabled']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('From Path', 'content-core'); ?></label>
                            <input type="text" name="cc_redirect_settings[from_path]"
                                value="<?php echo esc_attr($red_settings['from_path']); ?>" placeholder="/">
                            <span class="cc-help"><?php _e('The path to redirect from (usually /).', 'content-core'); ?></span>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Target Path / URL', 'content-core'); ?></label>
                            <input type="text" name="cc_redirect_settings[target]"
                                value="<?php echo esc_attr($red_settings['target']); ?>" placeholder="/wp-admin">
                            <span class="cc-help"><?php _e('Where to send the user.', 'content-core'); ?></span>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Status Code', 'content-core'); ?></label>
                            <select name="cc_redirect_settings[status_code]">
                                <option value="302" <?php selected($red_settings['status_code'], '302'); ?>>302 Found
                                    (Temporary)</option>
                                <option value="301" <?php selected($red_settings['status_code'], '301'); ?>>301 Moved
                                    Permanently</option>
                            </select>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Forward Query Strings', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_redirect_settings[pass_query]" value="0">
                                    <input type="checkbox" name="cc_redirect_settings[pass_query]" value="1" <?php checked($red_settings['pass_query']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field cc-grid-full">
                            <label class="cc-field-label"><?php _e('Exclusions', 'content-core'); ?></label>
                            <div>
                                <label>
                                    <input type="checkbox" name="cc_redirect_settings[exclusions][admin]" value="1" <?php checked($red_settings['exclusions']['admin']); ?>>
                                    <?php _e('Admin Area (/wp-admin)', 'content-core'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="cc_redirect_settings[exclusions][ajax]" value="1" <?php checked($red_settings['exclusions']['ajax']); ?>>
                                    <?php _e('AJAX Requests', 'content-core'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="cc_redirect_settings[exclusions][rest]" value="1" <?php checked($red_settings['exclusions']['rest']); ?>>
                                    <?php _e('REST API', 'content-core'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="cc_redirect_settings[exclusions][cron]" value="1" <?php checked($red_settings['exclusions']['cron']); ?>>
                                    <?php _e('WP Cron', 'content-core'); ?>
                                </label>
                                <label>
                                    <input type="checkbox" name="cc_redirect_settings[exclusions][cli]" value="1" <?php checked($red_settings['exclusions']['cli']); ?>>
                                    <?php _e('WP CLI', 'content-core'); ?>
                                </label>
                            </div>
                            <span class="cc-help">
                                <?php _e('Requests matching these criteria will NEVER be redirected, even if the From Path matches.', 'content-core'); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: Admin Bar Site Link -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php _e('Admin Bar Link', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Configure the direct link on the site name in the WordPress admin bar.', 'content-core'); ?>
                    </p>

                    <?php
                    $ab_link_settings = $settings_mod->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
                    ?>

                    <div class="cc-grid">
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Override Link', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_admin_bar_link[enabled]" value="0">
                                    <input type="checkbox" name="cc_admin_bar_link[enabled]" value="1" <?php checked($ab_link_settings['enabled']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Target URL', 'content-core'); ?></label>
                            <input type="text" name="cc_admin_bar_link[url]"
                                value="<?php echo esc_attr($ab_link_settings['url']); ?>"
                                placeholder="<?php echo esc_attr(home_url()); ?>">
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Open in New Tab', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_admin_bar_link[new_tab]" value="0">
                                    <input type="checkbox" name="cc_admin_bar_link[new_tab]" value="1" <?php checked($ab_link_settings['new_tab']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

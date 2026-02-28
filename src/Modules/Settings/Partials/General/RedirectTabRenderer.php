<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Redirect Tab in General Settings.
 */
class RedirectTabRenderer
{
    /**
     * Render the tab content.
     *
     * @param SettingsModule $settings_mod
     */
    public static function render(SettingsModule $settings_mod): void
    {
        $red_settings = $settings_mod->get_registry()->get(SettingsModule::REDIRECT_KEY);
        ?>
        <div id="cc-tab-redirect" class="cc-tab-content">
            <div class="cc-card">
                <h2 style="margin-top: 0;">
                    <?php _e('Root Redirection', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Configure where users are sent when visiting the site root (e.g. your CMS subdomain).', 'content-core'); ?>
                </p>

                <table class="form-table" style="margin-top: 20px;">
                    <tr>
                        <th scope="row">
                            <?php _e('Enable Root Redirect', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_redirect[enabled]" value="0">
                                <input type="checkbox" name="cc_redirect[enabled]" value="1" <?php
                                checked($red_settings['enabled']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('From Path', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="text" name="cc_redirect[from_path]"
                                value="<?php echo esc_attr($red_settings['from_path']); ?>" class="regular-text"
                                placeholder="/">
                            <p class="description">
                                <?php _e('The path to redirect from (usually /).', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Target Path / URL', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="text" name="cc_redirect[target]"
                                value="<?php echo esc_attr($red_settings['target']); ?>" class="regular-text"
                                placeholder="/wp-admin">
                            <p class="description">
                                <?php _e('Where to send the user.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Status Code', 'content-core'); ?>
                        </th>
                        <td>
                            <select name="cc_redirect[status_code]">
                                <option value="302" <?php selected($red_settings['status_code'], '302'); ?>>302 Found
                                    (Temporary)</option>
                                <option value="301" <?php selected($red_settings['status_code'], '301'); ?>>301 Moved
                                    Permanently</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Forward Query Strings', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_redirect[pass_query]" value="0">
                                <input type="checkbox" name="cc_redirect[pass_query]" value="1" <?php checked($red_settings['pass_query']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Exclusions', 'content-core'); ?>
                        </th>
                        <td>
                            <fieldset>
                                <label><input type="checkbox" name="cc_redirect[exclusions][admin]" value="1" <?php checked($red_settings['exclusions']['admin']); ?>> Admin Area (/wp-admin)</label><br>
                                <label><input type="checkbox" name="cc_redirect[exclusions][ajax]" value="1" <?php checked($red_settings['exclusions']['ajax']); ?>> AJAX Requests</label><br>
                                <label><input type="checkbox" name="cc_redirect[exclusions][rest]" value="1" <?php checked($red_settings['exclusions']['rest']); ?>> REST API</label><br>
                                <label><input type="checkbox" name="cc_redirect[exclusions][cron]" value="1" <?php checked($red_settings['exclusions']['cron']); ?>> WP Cron</label><br>
                                <label><input type="checkbox" name="cc_redirect[exclusions][cli]" value="1" <?php checked($red_settings['exclusions']['cli']); ?>> WP CLI</label>
                            </fieldset>
                            <p class="description">
                                <?php _e('Requests matching these criteria will NEVER be redirected, even if the From Path matches.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                <!-- ═══ Admin Bar Site Link (Restored) ═══ -->
                <h2 style="margin-top: 30px;">
                    <?php _e('Admin Bar Link', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Configure the direct link on the site name in the WordPress admin bar.', 'content-core'); ?>
                </p>

                <?php
                // Reuse existing ADMIN_BAR_KEY but specifically for these fields
                $ab_link_settings = $settings_mod->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
                ?>

                <table class="form-table" style="margin-top: 20px;">
                    <tr>
                        <th scope="row">
                            <?php _e('Override Link', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_admin_bar_link[enabled]" value="0">
                                <input type="checkbox" name="cc_admin_bar_link[enabled]" value="1" <?php checked($ab_link_settings['enabled']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Target URL', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="text" name="cc_admin_bar_link[url]"
                                value="<?php echo esc_attr($ab_link_settings['url']); ?>" class="regular-text"
                                placeholder="<?php echo esc_attr(home_url()); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Open in New Tab', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_admin_bar_link[new_tab]" value="0">
                                <input type="checkbox" name="cc_admin_bar_link[new_tab]" value="1" <?php checked($ab_link_settings['new_tab']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}

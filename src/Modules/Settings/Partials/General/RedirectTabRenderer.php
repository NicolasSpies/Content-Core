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
        $red_defaults = [
            'enabled' => false,
            'from_path' => '/',
            'target' => '/wp-admin',
            'status_code' => '302',
            'pass_query' => false,
            'exclusions' => [
                'admin' => true,
                'ajax' => true,
                'rest' => true,
                'cron' => true,
                'cli' => true,
            ]
        ];
        $saved_red = get_option(SettingsModule::REDIRECT_KEY, []);
        $red_settings = array_merge($red_defaults, is_array($saved_red) ? $saved_red : []);
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
            </div>
        </div>
        <?php
    }
}

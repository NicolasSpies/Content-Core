<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Visibility Tab in General Settings.
 */
class VisibilityTabRenderer
{
    /**
     * Render the tab content.
     *
     * @param SettingsModule $settings_mod
     */
    public static function render(SettingsModule $settings_mod): void
    {
        $vis_settings = $settings_mod->get_registry()->get(SettingsModule::OPTION_KEY);
        $order_settings = get_option(SettingsModule::ORDER_KEY, []);
        $all_items = $settings_mod->get_all_menu_items();

        $admin_vis = $vis_settings['admin'] ?? [];
        $client_vis = $vis_settings['client'] ?? [];
        $has_vis = !empty($vis_settings['admin']) || !empty($vis_settings['client']);

        // Sort items by existing order if available
        $items_by_slug = $all_items;
        $ordered_items = [];
        $admin_order = $order_settings['admin'] ?? [];

        if (!empty($admin_order)) {
            foreach ($admin_order as $slug) {
                if (isset($items_by_slug[$slug])) {
                    $ordered_items[$slug] = $items_by_slug[$slug];
                    unset($items_by_slug[$slug]);
                }
            }
        }
        // Append remaining
        foreach ($items_by_slug as $slug => $title) {
            $ordered_items[$slug] = $title;
        }

        $categories = $settings_mod->categorize_items($ordered_items);
        ?>
        <div id="cc-settings-visibility">

            <!-- ═══ Visibility Table ═══ -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-menu"></span>
                        <?php _e('Menu Visibility & Order', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Toggle sidebar items on or off. Admins always keep Settings, Plugins, and Content Core. Drag rows to reorder.', 'content-core'); ?>
                    </p>

                    <div class="cc-table-wrap">
                        <table class="cc-table cc-table-flush cc-visibility-sortable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;"></th>
                                    <th><?php _e('Menu Item', 'content-core'); ?></th>
                                    <th style="text-align: center; width: 140px;"><?php _e('Admins', 'content-core'); ?></th>
                                    <th style="text-align: center; width: 140px;">
                                        <?php _e('Editors / Clients', 'content-core'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <input type="hidden" name="cc_core_order_admin" id="cc-core-order-admin-input" value="">
                                <input type="hidden" name="cc_core_order_client" id="cc-core-order-client-input" value="">
                                <?php foreach ($categories as $group_name => $group_items): ?>
                                    <?php if (empty($group_items))
                                        continue; ?>
                                    <tr class="cc-category-header">
                                        <td colspan="4"
                                            style="background:var(--cc-bg-soft); font-weight:800; font-size:11px; text-transform:uppercase; letter-spacing:1px; padding:12px 20px;">
                                            <?php echo esc_html($group_name); ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($group_items as $slug => $title):
                                        $a_checked = true;
                                        $c_checked = true;

                                        if ($has_vis) {
                                            $a_checked = $admin_vis[$slug] ?? true;
                                            $c_checked = $client_vis[$slug] ?? true;
                                        } else {
                                            $a_locked = in_array($slug, SettingsModule::ADMIN_SAFETY_SLUGS, true);
                                            $c_checked = !in_array($slug, SettingsModule::DEFAULT_HIDDEN, true);
                                        }

                                        $a_locked = in_array($slug, ['options-general.php', 'plugins.php', 'content-core'], true);
                                        ?>
                                        <tr data-slug="<?php echo esc_attr($slug); ?>">
                                            <td style="text-align: center;">
                                                <span class="dashicons dashicons-menu cc-drag-handle"
                                                    style="cursor:grab; color:var(--cc-text-muted);"></span>
                                            </td>
                                            <td>
                                                <div style="font-weight:700; font-size:14px;"><?php echo esc_html($title); ?></div>
                                                <code
                                                    style="font-size: 11px; opacity:0.6; display:inline-block; margin-top:2px;"><?php echo esc_html($slug); ?></code>
                                            </td>
                                            <td style="text-align: center;">
                                                <div class="cc-toggle-wrap" style="justify-content:center;">
                                                    <label class="cc-toggle">
                                                        <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]"
                                                            value="0">
                                                        <input type="checkbox" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]"
                                                            value="1" <?php checked($a_checked || $a_locked); ?>                 <?php if ($a_locked)
                                                                                     echo 'disabled'; ?>>
                                                        <span class="cc-slider"></span>
                                                    </label>
                                                </div>
                                                <?php if ($a_locked): ?>
                                                    <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]" value="1">
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: center;">
                                                <div class="cc-toggle-wrap" style="justify-content:center;">
                                                    <label class="cc-toggle">
                                                        <input type="hidden" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                            value="0">
                                                        <input type="checkbox" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                            value="1" <?php checked($c_checked); ?>>
                                                        <span class="cc-slider"></span>
                                                    </label>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ Admin Bar Visibility ═══ -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-welcome-view-site"></span>
                        <?php _e('Admin Bar Controls', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Hide specific items from the WordPress admin bar for Editors and Clients.', 'content-core'); ?>
                    </p>

                    <div class="cc-grid">
                        <?php
                        $ab_settings = $settings_mod->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
                        $bar_items = [
                            'hide_wp_logo' => [
                                'title' => __('Hide WordPress Logo', 'content-core'),
                                'desc' => __('Removes the WordPress logo dropdown from the admin bar.', 'content-core')
                            ],
                            'hide_comments' => [
                                'title' => __('Hide Comments Bubble', 'content-core'),
                                'desc' => __('Removes the comments icon from the admin bar.', 'content-core')
                            ],
                            'hide_new_content' => [
                                'title' => __('Hide "+ New" Menu', 'content-core'),
                                'desc' => __('Removes the quick-create menu from the admin bar.', 'content-core')
                            ]
                        ];

                        foreach ($bar_items as $key => $info): ?>
                            <div class="cc-field">
                                <label class="cc-field-label"><?php echo esc_html($info['title']); ?></label>
                                <div class="cc-toggle-wrap">
                                    <label class="cc-toggle">
                                        <input type="hidden" name="cc_admin_bar[<?php echo $key; ?>]" value="0">
                                        <input type="checkbox" name="cc_admin_bar[<?php echo $key; ?>]" value="1" <?php checked($ab_settings[$key] ?? false); ?>>
                                        <span class="cc-slider"></span>
                                    </label>
                                    <span class="cc-help"><?php echo esc_html($info['desc']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

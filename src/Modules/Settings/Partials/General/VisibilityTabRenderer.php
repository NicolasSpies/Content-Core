<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Visibility Tab in General Settings.
 */
class VisibilityTabRenderer {
    /**
     * Render the tab content.
     *
     * @param SettingsModule $settings_mod
     */
    public static function render(SettingsModule $settings_mod): void {
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
        <div id="cc-tab-menu" class="cc-tab-content active">

            <!-- ═══ Visibility Table ═══ -->
            <div class="cc-card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0;">
                    <?php _e('Menu Visibility', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Toggle sidebar items on or off. Admins always keep Settings, Plugins, and Content Core.', 'content-core'); ?>
                </p>

                <table class="wp-list-table widefat fixed striped cc-visibility-sortable" style="margin-top: 16px;">
                    <thead>
                        <tr>
                            <th style="width: 40px;"></th>
                            <th style="width: 35%;">
                                <?php _e('Menu Item', 'content-core'); ?>
                            </th>
                            <th style="width: 30%; text-align: center;">
                                <?php _e('Administrators', 'content-core'); ?>
                            </th>
                            <th style="width: 30%; text-align: center;">
                                <?php _e('Editors / Clients', 'content-core'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <input type="hidden" name="cc_core_order_admin" id="cc-core-order-admin-input" value="">
                        <input type="hidden" name="cc_core_order_client" id="cc-core-order-client-input" value="">
                        <?php foreach ($categories as $group_name => $group_items): ?>
                            <?php if (empty($group_items))
                                continue; ?>
                            <tr class="cc-category-header" style="background: #f0f0f1;">
                                <td colspan="4"><strong>
                                        <?php echo esc_html($group_name); ?>
                                    </strong></td>
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
                                    <td style="text-align: center; vertical-align: middle; cursor: grab;">
                                        <span class="dashicons dashicons-menu cc-drag-handle" style="color: #a0a5aa;"></span>
                                    </td>
                                    <td>
                                        <strong>
                                            <?php echo esc_html($title); ?>
                                        </strong>
                                        <br><code style="font-size: 11px; color: #646970;"><?php echo esc_html($slug); ?></code>
                                    </td>
                                    <td style="text-align: center;">
                                        <label class="cc-toggle">
                                            <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]" value="0">
                                            <input type="checkbox" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]"
                                                value="1" <?php checked($a_checked || $a_locked); ?> <?php if ($a_locked) echo 'disabled'; ?>>
                                            <span class="cc-toggle-slider"></span>
                                        </label>
                                        <?php if ($a_locked): ?>
                                            <input type="hidden" name="cc_menu_admin[<?php echo esc_attr($slug); ?>]" value="1">
                                            <?php
                                        endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <label class="cc-toggle">
                                            <input type="hidden" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                value="0">
                                            <input type="checkbox" name="cc_menu_client[<?php echo esc_attr($slug); ?>]"
                                                value="1" <?php checked($c_checked); ?>>
                                            <span class="cc-toggle-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                                <?php
                            endforeach; ?>
                            <?php
                        endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- ═══ Admin Bar Visibility ═══ -->
            <div class="cc-card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0;">
                    <?php _e('Admin Bar', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Hide specific items from the WordPress admin bar for Editors and Clients.', 'content-core'); ?>
                </p>

                <?php
                $ab_settings = $settings_mod->get_registry()->get(SettingsModule::ADMIN_BAR_KEY);
                ?>

                <table class="form-table" style="margin-top: 16px;">
                    <tr>
                        <th scope="row"><?php _e('Hide WordPress Logo Menu', 'content-core'); ?></th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_admin_bar[hide_wp_logo]" value="0">
                                <input type="checkbox" name="cc_admin_bar[hide_wp_logo]" value="1" <?php checked($ab_settings['hide_wp_logo']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Removes the WordPress logo dropdown (id: wp-logo) from the admin bar.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Hide Comments Bubble', 'content-core'); ?></th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_admin_bar[hide_comments]" value="0">
                                <input type="checkbox" name="cc_admin_bar[hide_comments]" value="1" <?php checked($ab_settings['hide_comments']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Removes the comments bubble icon (id: comments) from the admin bar.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Hide "+ New" Menu', 'content-core'); ?></th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_admin_bar[hide_new_content]" value="0">
                                <input type="checkbox" name="cc_admin_bar[hide_new_content]" value="1" <?php checked($ab_settings['hide_new_content']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Removes the "+&nbsp;New" quick-create menu (id: new-content) from the admin bar.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }
}

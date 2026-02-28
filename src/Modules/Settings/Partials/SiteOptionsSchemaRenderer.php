<?php
namespace ContentCore\Modules\Settings\Partials;

use ContentCore\Plugin;
use ContentCore\Modules\Multilingual\MultilingualModule;

class SiteOptionsSchemaRenderer
{
    public static function render(): void
    {
        $plugin = Plugin::get_instance();
        /** @var \ContentCore\Modules\SiteOptions\SiteOptionsModule|null $site_mod */
        $site_mod = $plugin->get_module('site_options');

        if (!$site_mod instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Site Options module not active.', 'content-core') . '</p></div>';
            return;
        }

        $schema = $site_mod->get_schema();
        $ml = $plugin->get_module('multilingual');
        /** @var \ContentCore\Modules\Multilingual\MultilingualModule|null $ml */
        $languages = ($ml instanceof MultilingualModule) ? $ml->get_settings()['languages'] : [];

        ?>
        <form method="post">
            <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>
            <input type="hidden" name="settings_group" value="site_settings">

            <div class="cc-card">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                    <div>
                        <h2 style="margin-top: 0;">
                            <?php _e('Site Options Schema', 'content-core'); ?>
                        </h2>
                        <p style="color: #646970;">
                            <?php _e('Define groups and fields for global business information. These fields will appear on the Site Options page.', 'content-core'); ?>
                        </p>
                    </div>
                    <button type="submit" name="cc_reset_site_options_schema" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_attr__('Reset Site Options schema to defaults? Your values will be preserved.', 'content-core'); ?>');">
                        <?php _e('Reset to Default Template', 'content-core'); ?>
                    </button>
                </div>

                <div id="cc-schema-editor" style="margin-top: 24px;">
                    <?php foreach ($schema as $section_id => $section): ?>
                        <div class="cc-schema-section cc-card"
                            style="background: #f8f9fa; margin-bottom: 20px; border: 1px solid #dcdcde;"
                            data-id="<?php echo esc_attr($section_id); ?>">
                            <div
                                style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #dcdcde; padding-bottom: 15px;">
                                <span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab;"></span>
                                <div style="flex-grow: 1;">
                                    <input type="text" name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][title]"
                                        value="<?php echo esc_attr($section['title'] ?? ''); ?>" class="large-text"
                                        style="font-weight: 600;" placeholder="<?php esc_attr_e('Group title', 'content-core'); ?>">
                                </div>
                                <button type="button" class="button button-link-delete cc-remove-section">
                                    <span class="dashicons dashicons-trash"></span>
                                </button>
                            </div>

                            <div class="cc-schema-fields" style="padding-left: 20px;">
                                <?php foreach ($section['fields'] ?? [] as $field_id => $field): ?>
                                    <div class="cc-schema-field" data-id="<?php echo esc_attr($field_id); ?>"
                                        style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 15px; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px;">
                                        <span class="dashicons dashicons-menu"
                                            style="color: #a0a5aa; cursor: grab; margin-top: 8px;"></span>
                                        <div style="flex-grow: 1;">
                                            <div
                                                style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                                <div>
                                                    <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                        <?php _e('Stable Key', 'content-core'); ?>
                                                    </label>
                                                    <input type="text" value="<?php echo esc_attr($field_id); ?>" class="regular-text"
                                                        style="width: 100%; font-family: monospace;" readonly disabled>
                                                </div>
                                                <div>
                                                    <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                        <?php _e('Type', 'content-core'); ?>
                                                    </label>
                                                    <select
                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][type]"
                                                        style="width: 100%;">
                                                        <option value="text" <?php selected($field['type'], 'text'); ?>>Text</option>
                                                        <option value="email" <?php selected($field['type'], 'email'); ?>>Email
                                                        </option>
                                                        <option value="url" <?php selected($field['type'], 'url'); ?>>URL</option>
                                                        <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>Textarea
                                                        </option>
                                                        <option value="image" <?php selected($field['type'], 'image'); ?>>Image/Logo
                                                        </option>
                                                    </select>
                                                </div>
                                                <div style="display: flex; gap: 15px; align-items: center; padding-top: 20px;">
                                                    <label><input type="checkbox"
                                                            name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_visible]"
                                                            value="1" <?php checked(!empty($field['client_visible'])); ?>>
                                                        <?php _e('Visible', 'content-core'); ?>
                                                    </label>
                                                    <label><input type="checkbox"
                                                            name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_editable]"
                                                            value="1" <?php checked(!empty($field['client_editable'])); ?>>
                                                        <?php _e('Editable', 'content-core'); ?>
                                                    </label>
                                                </div>
                                            </div>

                                            <div
                                                style="display: grid; grid-template-columns: repeat(<?php echo count($languages) ?: 1; ?>, 1fr); gap: 10px;">
                                                <?php foreach ($languages as $lang):
                                                    $label_val = is_array($field['label']) ? ($field['label'][$lang['code']] ?? '') : ($lang['code'] === 'de' ? $field['label'] : '');
                                                    ?>
                                                    <div>
                                                        <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                            <?php echo esc_html($lang['label']); ?>
                                                            <?php _e('Label', 'content-core'); ?>
                                                        </label>
                                                        <input type="text"
                                                            name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][label][<?php echo esc_attr($lang['code']); ?>]"
                                                            value="<?php echo esc_attr($label_val); ?>" style="width: 100%;">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <button type="button" class="button button-link-delete cc-remove-field"
                                            style="margin-top: 5px;">
                                            <span class="dashicons dashicons-no-alt"></span>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                                <button type="button" class="button button-secondary cc-add-field"
                                    data-section="<?php echo esc_attr($section_id); ?>">
                                    <?php _e('+ Add Field', 'content-core'); ?>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <button type="button" class="button button-secondary cc-add-section">
                        <?php _e('+ Add Group', 'content-core'); ?>
                    </button>
                </div>
            </div>

            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dcdcde;">
                <?php submit_button(__('Save Site Options Schema', 'content-core'), 'primary', 'submit', false); ?>
            </div>
        </form>
        <?php
    }
}

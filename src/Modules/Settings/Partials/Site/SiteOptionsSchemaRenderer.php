<?php
namespace ContentCore\Modules\Settings\Partials\Site;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Site Options Schema Editor in Site Settings.
 */
class SiteOptionsSchemaRenderer
{
    /**
     * Render the schema editor content.
     */
    public static function render(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $site_mod = $plugin->get_module('site_options');
        if (!$site_mod || !method_exists($site_mod, 'get_schema')) {
            return;
        }

        $schema = $site_mod->get_schema();
        ?>
        <div id="cc-site-options-schema-section" class="cc-site-options-schema-section"
            style="display: none; margin-top: 30px;">
            <div class="cc-card">
                <h2 style="margin-top: 0; display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-layout"></span>
                    <?php _e('Site Options Schema Editor', 'content-core'); ?>
                </h2>
                <p style="color: #646970; margin-bottom: 24px;">
                    <?php _e('Define the configuration fields available in the "Site Options" page. These fields are accessible via REST API for your headless frontend.', 'content-core'); ?>
                </p>

                <div id="cc-schema-editor-root" data-initial-schema="<?php echo esc_attr(json_encode($schema)); ?>">
                    <!-- JS will mount here -->
                </div>

                <div
                    style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #dcdcde; display: flex; gap: 12px; align-items: center;">
                    <button type="submit" name="submit" class="button button-primary">
                        <?php _e('Save Schema', 'content-core'); ?>
                    </button>
                    <button type="submit" name="cc_reset_site_options_schema" class="button button-secondary"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to reset the schema to defaults? All custom fields will be removed.', 'content-core')); ?>');">
                        <?php _e('Reset to Defaults', 'content-core'); ?>
                    </button>
                    <span id="cc-schema-editor-status" style="margin-left: auto; color: #46b450; display: none;">
                        <span class="dashicons dashicons-yes"></span>
                        <?php _e('Schema changed, don\'t forget to save.', 'content-core'); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }
}

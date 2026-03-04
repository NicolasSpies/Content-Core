<?php
namespace ContentCore\Modules\Settings\UI;

/**
 * Class SettingsRenderer
 * 
 * Encapsulates the rendering logic for Content Core settings pages.
 */
class SettingsRenderer
{
    /** @var \ContentCore\Modules\Settings\SettingsModule */
    private $module;

    public function __construct(\ContentCore\Modules\Settings\SettingsModule $module)
    {
        $this->module = $module;
    }

    /**
     * Render the Site Settings page (React-based)
     */
    public function render_site_settings_page(): void
    {
        $title = get_admin_page_title();
        $page_slug = sanitize_text_field($_GET['page'] ?? '');
        ?>
        <div class="wrap content-core-admin cc-settings-single-page">
            <div class="cc-header">
                <h1>
                    <?php echo esc_html($title); ?>
                </h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <!-- ── React Shell (SEO, Site Images, Cookie Banner, Site Options tab nav) ── -->
            <div id="cc-site-settings-react-root"></div>

            <?php if ($page_slug !== 'cc-site-options'): ?>
                <!-- ── Site Options Schema — PHP form, shown/hidden by React tab ── -->
                <div id="cc-site-options-schema-section">
                    <?php \ContentCore\Modules\Settings\Partials\SiteOptionsSchemaRenderer::render(); ?>
                </div>
            <?php endif; ?>

        </div>
        <?php
    }

    /**
     * Render a generic settings page (PHP-based with tabs)
     */
    public function render_settings_page(): void
    {
        $page_slug = $_GET['page'] ?? '';
        $title = get_admin_page_title();

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php echo esc_html($title); ?></h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <form method="post">
                <?php wp_nonce_field('cc_save_menu_settings', 'cc_menu_settings_nonce'); ?>
                <?php
                $submit_name = 'cc_save_general';
                if ($page_slug === 'cc-multilingual') {
                    $submit_name = 'cc_save_multilingual';
                }
                ?>
                <input type="hidden" name="cc_submit_id" value="<?php echo esc_attr($submit_name); ?>">

                <div class="cc-settings-content">
                    <?php
                    if ($page_slug === 'cc-visibility') {
                        \ContentCore\Modules\Settings\Partials\General\VisibilityTabRenderer::render($this->module);
                    } elseif ($page_slug === 'cc-media') {
                        \ContentCore\Modules\Settings\Partials\General\MediaTabRenderer::render($this->module);
                    } elseif ($page_slug === 'cc-redirect') {
                        \ContentCore\Modules\Settings\Partials\General\RedirectTabRenderer::render($this->module);
                    } elseif ($page_slug === 'cc-multilingual') {
                        \ContentCore\Modules\Settings\Partials\General\MultilingualTabRenderer::render($this->module);
                    } elseif ($page_slug === 'cc-branding') {
                        \ContentCore\Modules\Settings\Partials\General\BrandingTabRenderer::render($this->module);
                    }
                    ?>
                </div>

                <!-- ═══ Form Actions ═══ -->
                <div class="cc-form-actions">
                    <button type="submit" name="<?php echo esc_attr($submit_name); ?>" class="cc-button-primary">
                        <span class="dashicons dashicons-saved"></span>
                        <?php _e('Save Settings', 'content-core'); ?>
                    </button>
                    
                    <button type="submit" name="cc_reset_menu" class="cc-button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Reset this setting module to defaults?', 'content-core'); ?>');">
                        <span class="dashicons dashicons-undo"></span>
                        <?php _e('Reset to Defaults', 'content-core'); ?>
                    </button>
                </div>
            </form>

            <!-- ═══ Safety Notice ═══ -->
            <div class="cc-card">
                <div class="cc-card-body">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <h3>
                            <?php _e('Safety & Recovery', 'content-core'); ?>
                        </h3>
                        <p>
                            <?php _e('Administrator roles always maintain access to Settings, Plugins, and Content Core regardless of visibility rules.', 'content-core'); ?>
                            <br>
                            <span>
                                <?php _e('Direct Access URL:', 'content-core'); ?> 
                                <code><?php echo esc_url(admin_url('admin.php?page=' . urlencode($page_slug))); ?></code>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

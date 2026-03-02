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
        ?>
        <div class="wrap content-core-admin cc-settings-single-page">
            <div class="cc-header">
                <h1>
                    <?php echo esc_html($title); ?>
                </h1>
            </div>

            <?php settings_errors('cc_settings'); ?>

            <!-- ── React Shell (SEO, Site Images, Cookie Banner, Site Options tab nav) ── -->
            <div id="cc-site-settings-react-root" style="margin-top: 24px;"></div>

            <!-- ── Site Options Schema — PHP form, shown/hidden by React tab ── -->
            <div id="cc-site-options-schema-section" style="display:none; margin-top: 0;">
                <?php \ContentCore\Modules\Settings\Partials\SiteOptionsSchemaRenderer::render(); ?>
            </div>

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

            <form method="post" style="margin-top: 32px;">
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
                    }
                    ?>
                </div>

                <!-- ═══ Form Actions ═══ -->
                <div class="cc-form-actions" style="display: flex; gap: 16px; align-items: center; margin-top: 40px; padding: 24px; background: var(--cc-bg-card); border: 1px solid var(--cc-border); border-radius: var(--cc-radius); box-shadow: var(--cc-shadow);">
                    <button type="submit" name="<?php echo esc_attr($submit_name); ?>" class="cc-button-primary">
                        <span class="dashicons dashicons-saved" style="font-size:18px; width:18px; height:18px;"></span>
                        <?php _e('Save Settings', 'content-core'); ?>
                    </button>
                    
                    <button type="submit" name="cc_reset_menu" class="cc-button-secondary"
                        onclick="return confirm('<?php esc_attr_e('Reset this setting module to defaults?', 'content-core'); ?>');">
                        <span class="dashicons dashicons-undo" style="font-size:16px; width:16px; height:16px; margin-right:4px; vertical-align:middle;"></span>
                        <?php _e('Reset to Defaults', 'content-core'); ?>
                    </button>
                </div>
            </form>

            <!-- ═══ Safety Notice ═══ -->
            <div class="cc-card" style="margin-top: 48px; border-left: 4px solid var(--cc-warning);">
                <div class="cc-card-body" style="padding: 24px; display: flex; align-items: flex-start; gap: 16px;">
                    <span class="dashicons dashicons-warning" style="color: var(--cc-warning); font-size: 24px; width: 24px; height: 24px; margin-top: 2px;"></span>
                    <div>
                        <h3 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 700; color: var(--cc-text); text-transform: uppercase; letter-spacing: 0.05em;">
                            <?php _e('Safety & Recovery', 'content-core'); ?>
                        </h3>
                        <p style="margin: 0; font-size: 13px; color: var(--cc-text-muted); line-height: 1.5;">
                            <?php _e('Administrator roles always maintain access to Settings, Plugins, and Content Core regardless of visibility rules.', 'content-core'); ?>
                            <br>
                            <span style="display: inline-block; margin-top: 8px;">
                                <?php _e('Direct Access URL:', 'content-core'); ?> 
                                <code style="background: var(--cc-bg-soft); padding: 2px 6px; border-radius: 4px; font-size: 11px;"><?php echo esc_url(admin_url('admin.php?page=' . urlencode($page_slug))); ?></code>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php $this->render_settings_styles_and_scripts(); ?>
        <?php
    }

    /**
     * Render inline styles and scripts for settings pages
     */
    private function render_settings_styles_and_scripts(): void
    {
        ?>
        <style>
            /* Hide legacy WP tab UI if present */
            .nav-tab-wrapper.cc-settings-tabs,
            .content-core-admin .cc-react-tabs,
            .content-core-admin .nav-tab-wrapper {
                display: none !important;
            }

            /* Sortable List Styles (Redundant but kept for custom UI if used outside main cards) */
            .cc-sortable-list {
                list-style: none;
                margin: 0;
                padding: 0;
            }

            .cc-sortable-list li {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 14px;
                margin-bottom: 4px;
                background: #fff;
                border: 1px solid var(--cc-border);
                border-radius: var(--cc-radius);
                cursor: grab;
                box-shadow: var(--cc-shadow);
                user-select: none;
            }

            .cc-sortable-list li:active {
                cursor: grabbing;
            }

            .cc-sortable-list li .cc-order-title {
                font-weight: 600;
                font-size: 13px;
            }

            .cc-sortable-list .ui-sortable-placeholder {
                visibility: visible !important;
                background: var(--cc-bg-soft);
                border: 2px dashed var(--cc-accent-color);
                border-radius: var(--cc-radius);
                margin-bottom: 4px;
            }
        </style>

        <script>
            jQuery(function ($) {
                function serializeVisibilityOrder() {
                    var order = [];
                    $('.cc-visibility-sortable tbody tr[data-slug]').each(function () {
                        var slug = $(this).data('slug');
                        if (slug) order.push(slug);
                    });
                    $('#cc-core-order-admin-input').val(JSON.stringify(order));
                    $('#cc-core-order-client-input').val(JSON.stringify(order));
                }

                if ($.fn.sortable) {
                    $('.cc-visibility-sortable tbody').sortable({
                        handle: '.cc-drag-handle',
                        items: 'tr[data-slug]',
                        placeholder: 'ui-sortable-placeholder',
                        update: function (event, ui) {
                            serializeVisibilityOrder();
                        }
                    });
                    serializeVisibilityOrder();
                }
            });
        </script>
        <?php
    }
}

<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

class BrandingTabRenderer
{
    public static function render(SettingsModule $settings_mod): void
    {
        wp_enqueue_media();

        $settings = $settings_mod->get_registry()->get('cc_branding_settings');
        if (!is_array($settings)) {
            $settings = [];
        }

        $enabled = !empty($settings['enabled']);
        $exclude_admins = !empty($settings['exclude_admins']);
        $remove_wp_mentions = !empty($settings['remove_wp_mentions']);
        $primary = (string) ($settings['custom_primary_color'] ?? $settings['login_bg_color'] ?? '#1e1e1e');
        $accent = (string) ($settings['custom_accent_color'] ?? $settings['login_btn_color'] ?? '#2271b1');
        $footer = (string) ($settings['custom_footer_text'] ?? '');
        $login_logo = $settings['login_logo'] ?? '';
        $login_logo_url = (string) ($settings['login_logo_url'] ?? '');
        if ($login_logo_url === '' && is_numeric($login_logo) && (int) $login_logo > 0) {
            $resolved = wp_get_attachment_image_url((int) $login_logo, 'full');
            if ($resolved) {
                $login_logo_url = (string) $resolved;
            }
        }
        ?>
        <div id="cc-settings-branding">
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2><span class="dashicons dashicons-admin-appearance"></span><?php _e('Branding', 'content-core'); ?></h2>
                </div>
                <div class="cc-card-body">
                    <div class="cc-grid cc-grid-2">
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Activate Branding', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_branding_settings[enabled]" value="0">
                                    <input type="checkbox" name="cc_branding_settings[enabled]" value="1" <?php checked($enabled); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Exclude Administrators', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_branding_settings[exclude_admins]" value="0">
                                    <input type="checkbox" name="cc_branding_settings[exclude_admins]" value="1" <?php checked($exclude_admins); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Hide WordPress Mentions', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_branding_settings[remove_wp_mentions]" value="0">
                                    <input type="checkbox" name="cc_branding_settings[remove_wp_mentions]" value="1" <?php checked($remove_wp_mentions); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Background Color', 'content-core'); ?></label>
                            <input type="color" name="cc_branding_settings[custom_primary_color]" value="<?php echo esc_attr($primary); ?>" class="cc-branding-background-color">
                            <input type="hidden" name="cc_branding_settings[login_bg_color]" value="<?php echo esc_attr($primary); ?>" class="cc-branding-login-bg-sync">
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Accent Color', 'content-core'); ?></label>
                            <input type="color" name="cc_branding_settings[custom_accent_color]" value="<?php echo esc_attr($accent); ?>" class="cc-branding-accent-color">
                            <input type="hidden" name="cc_branding_settings[login_btn_color]" value="<?php echo esc_attr($accent); ?>" class="cc-branding-login-btn-sync">
                        </div>

                        <div class="cc-field" style="grid-column:1 / -1;">
                            <label class="cc-field-label"><?php _e('Login Logo', 'content-core'); ?></label>
                            <input type="hidden" name="cc_branding_settings[login_logo_link_url]" value="">
                            <input type="hidden" name="cc_branding_settings[use_site_icon_for_admin_bar]" value="1">
                            <input type="hidden" name="cc_branding_settings[admin_bar_logo]" value="">
                            <input type="hidden" name="cc_branding_settings[admin_bar_logo_url]" value="">
                            <input type="hidden" name="cc_branding_settings[admin_bar_logo_link_url]" value="">
                            <div class="cc-field-input">
                                <input type="hidden" name="cc_branding_settings[login_logo]" value="<?php echo esc_attr((string) $login_logo); ?>" class="cc-media-target-id">
                                <input type="hidden" name="cc_branding_settings[login_logo_url]" value="<?php echo esc_attr($login_logo_url); ?>" class="cc-media-target-url">
                            </div>
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
                                <button type="button" class="cc-button-secondary cc-open-media"><?php echo $login_logo_url !== '' ? esc_html__('Replace Logo', 'content-core') : esc_html__('Select Logo', 'content-core'); ?></button>
                                <button type="button" class="cc-button-secondary cc-clear-media"><?php _e('Remove Logo', 'content-core'); ?></button>
                            </div>
                            <div class="cc-media-preview-wrap" style="margin-top:10px;min-height:56px;">
                                <img class="cc-media-preview" src="<?php echo esc_url($login_logo_url); ?>" alt="" style="<?php echo $login_logo_url !== '' ? 'max-height:56px;max-width:220px;display:block;' : 'display:none;'; ?>">
                            </div>
                        </div>

                        <div class="cc-field" style="grid-column:1 / -1;">
                            <label class="cc-field-label"><?php _e('Footer Text (HTML allowed)', 'content-core'); ?></label>
                            <div class="cc-field-input">
                                <textarea rows="3" name="cc_branding_settings[custom_footer_text]"><?php echo esc_textarea($footer); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function ($) {
                if (typeof wp === 'undefined' || !wp.media) {
                    return;
                }

                $(document)
                    .off('click.ccBrandingMedia', '#cc-settings-branding .cc-open-media')
                    .on('click.ccBrandingMedia', '#cc-settings-branding .cc-open-media', function (e) {
                        e.preventDefault();
                        var $group = $(this).closest('.cc-field');
                        var $targetId = $group.find('.cc-media-target-id');
                        var $targetUrl = $group.find('.cc-media-target-url');
                        var $preview = $group.find('.cc-media-preview');

                        var frame = wp.media({
                            title: '<?php echo esc_js(__('Select Image', 'content-core')); ?>',
                            button: {text: '<?php echo esc_js(__('Use this image', 'content-core')); ?>'},
                            multiple: false
                        });

                        frame.on('select', function () {
                            var att = frame.state().get('selection').first().toJSON();
                            var url = (att.sizes && att.sizes.large) ? att.sizes.large.url : att.url;
                            $targetId.val(att.id || '');
                            $targetUrl.val(url || '');
                            if (url) {
                                $preview.attr('src', url).show();
                            } else {
                                $preview.hide();
                            }
                            $group.find('.cc-open-media').text('<?php echo esc_js(__('Replace Logo', 'content-core')); ?>');
                        });

                        frame.open();
                    })
                    .off('click.ccBrandingMediaClear', '#cc-settings-branding .cc-clear-media')
                    .on('click.ccBrandingMediaClear', '#cc-settings-branding .cc-clear-media', function (e) {
                        e.preventDefault();
                        var $group = $(this).closest('.cc-field');
                        $group.find('.cc-media-target-id').val('');
                        $group.find('.cc-media-target-url').val('');
                        $group.find('.cc-media-preview').attr('src', '').hide();
                        $group.find('.cc-open-media').text('<?php echo esc_js(__('Select Logo', 'content-core')); ?>');
                    });

                $(document)
                    .off('change.ccBrandingColors', '#cc-settings-branding .cc-branding-background-color, #cc-settings-branding .cc-branding-accent-color')
                    .on('change.ccBrandingColors', '#cc-settings-branding .cc-branding-background-color, #cc-settings-branding .cc-branding-accent-color', function () {
                        var bg = $('#cc-settings-branding .cc-branding-background-color').val() || '#1e1e1e';
                        var accent = $('#cc-settings-branding .cc-branding-accent-color').val() || '#2271b1';
                        $('#cc-settings-branding .cc-branding-login-bg-sync').val(bg);
                        $('#cc-settings-branding .cc-branding-login-btn-sync').val(accent);
                    });
            })(jQuery);
        </script>
        <?php
    }
}

<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Media Tab in General Settings.
 */
class MediaTabRenderer
{
    public static function render(SettingsModule $settings_mod): void
    {
        $media_settings = $settings_mod->get_registry()->get(SettingsModule::MEDIA_KEY);
        ?>
        <div id="cc-settings-media">
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-format-image"></span>
                        <?php _e('Media Optimization', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Automatically optimize images on upload. Converts to WebP and resizes if necessary.', 'content-core'); ?>
                    </p>

                    <div class="cc-grid">
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Enable Optimization', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_media_settings[enabled]" value="0">
                                    <input type="checkbox" name="cc_media_settings[enabled]" value="1" <?php checked($media_settings['enabled']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Max Width (px)', 'content-core'); ?></label>
                            <input type="number" name="cc_media_settings[max_width_px]"
                                value="<?php echo esc_attr($media_settings['max_width_px']); ?>" step="1" min="100">
                            <span
                                class="cc-help"><?php _e('Images wider than this will be resized down.', 'content-core'); ?></span>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Quality', 'content-core'); ?></label>
                            <input type="number" name="cc_media_settings[quality]"
                                value="<?php echo esc_attr($media_settings['quality']); ?>" min="1" max="100">
                            <span
                                class="cc-help"><?php _e('1-100. Lower is more compressed. Default: 70.', 'content-core'); ?></span>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('PNG Conversion Mode', 'content-core'); ?></label>
                            <select name="cc_media_settings[png_mode]">
                                <option value="lossless" <?php selected($media_settings['png_mode'], 'lossless'); ?>>
                                    <?php _e('Lossless (High Quality)', 'content-core'); ?>
                                </option>
                                <option value="lossy" <?php selected($media_settings['png_mode'], 'lossy'); ?>>
                                    <?php _e('Lossy (Lower Size)', 'content-core'); ?>
                                </option>
                            </select>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Delete Original', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_media_settings[delete_original]" value="0">
                                    <input type="checkbox" name="cc_media_settings[delete_original]" value="1" <?php checked($media_settings['delete_original']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Delete original source (jpg/png) after WebP conversion.', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Upload Limit (MB)', 'content-core'); ?></label>
                            <input type="number" id="cc_media_settings_upload_limit_mb"
                                name="cc_media_settings[upload_limit_mb]"
                                value="<?php echo esc_attr($media_settings['upload_limit_mb']); ?>" min="1" max="300" step="1"
                                placeholder="25">
                            <span class="cc-help">
                                <?php
                                $cc_wp_max_mb = round(wp_max_upload_size() / 1048576, 1);
                                $cc_limit_val = $media_settings['upload_limit_mb'];
                                if ($cc_limit_val !== '' && is_numeric($cc_limit_val) && intval($cc_limit_val) >= 1) {
                                    $cc_effective_mb = min((float) $cc_limit_val, $cc_wp_max_mb);
                                    printf(
                                        /* translators: 1: configured MB, 2: effective MB after server cap */
                                        esc_html__('1–300 MB. Max: %2$s MB.', 'content-core'),
                                        esc_html($cc_limit_val),
                                        esc_html($cc_effective_mb)
                                    );
                                } else {
                                    printf(
                                        esc_html__('WordPress max: %s MB.', 'content-core'),
                                        esc_html($cc_wp_max_mb)
                                    );
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

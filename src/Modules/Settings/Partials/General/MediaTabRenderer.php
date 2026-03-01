<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Settings\SettingsModule;

/**
 * Renders the Media Tab in General Settings.
 */
class MediaTabRenderer
{
    /**
     * Render the tab content.
     *
     * @param SettingsModule $settings_mod
     */
    public static function render(SettingsModule $settings_mod): void
    {
        $media_settings = $settings_mod->get_registry()->get(SettingsModule::MEDIA_KEY);
        ?>
        <div id="cc-tab-media" class="cc-tab-content">
            <div class="cc-card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0;">
                    <?php _e('Media Optimization', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Automatically optimize images on upload. Converts to WebP and resizes if necessary.', 'content-core'); ?>
                </p>

                <table class="form-table" style="margin-top: 16px;">
                    <tr>
                        <th scope="row">
                            <?php _e('Enable Optimization', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_media_settings[enabled]" value="0">
                                <input type="checkbox" name="cc_media_settings[enabled]" value="1" <?php
                                checked($media_settings['enabled']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Max Width (px)', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="number" name="cc_media_settings[max_width_px]"
                                value="<?php echo esc_attr($media_settings['max_width_px']); ?>" class="regular-text" step="1"
                                min="100">
                            <p class="description">
                                <?php _e('Images wider than this will be resized down.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Output Format', 'content-core'); ?>
                        </th>
                        <td>
                            <select name="cc_media_settings[output_format]" disabled class="regular-text">
                                <option value="webp" selected>WebP</option>
                            </select>
                            <p class="description">
                                <?php _e('Standardized to WebP for modern performance.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Quality', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="number" name="cc_media_settings[quality]"
                                value="<?php echo esc_attr($media_settings['quality']); ?>" class="small-text" min="1"
                                max="100">
                            <p class="description">
                                <?php _e('1-100. Lower is more compressed. Default: 70.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('PNG Conversion Mode', 'content-core'); ?>
                        </th>
                        <td>
                            <select name="cc_media_settings[png_mode]" class="regular-text">
                                <option value="lossless" <?php selected($media_settings['png_mode'], 'lossless'); ?>>
                                    <?php _e('Lossless (High Quality)', 'content-core'); ?>
                                </option>
                                <option value="lossy" <?php selected($media_settings['png_mode'], 'lossy'); ?>>
                                    <?php _e('Lossy (Lower Size)', 'content-core'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Delete Original', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_media_settings[delete_original]" value="0">
                                <input type="checkbox" name="cc_media_settings[delete_original]" value="1" <?php
                                checked($media_settings['delete_original']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('If enabled, the original source file (jpg/png/gif) is deleted after successful conversion to WebP.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Upload Limit (MB)', 'content-core'); ?>
                        </th>
                        <td>
                            <input type="number" id="cc_media_settings_upload_limit_mb"
                                name="cc_media_settings[upload_limit_mb]"
                                value="<?php echo esc_attr($media_settings['upload_limit_mb']); ?>" class="small-text" min="1"
                                max="300" step="1" placeholder="25">
                            <p class="description">
                                <?php
                                $cc_wp_max_mb = round(wp_max_upload_size() / 1048576, 1);
                                $cc_limit_val = $media_settings['upload_limit_mb'];
                                if ($cc_limit_val !== '' && is_numeric($cc_limit_val) && intval($cc_limit_val) >= 1) {
                                    $cc_effective_mb = min((float) $cc_limit_val, $cc_wp_max_mb);
                                    printf(
                                        /* translators: 1: configured MB, 2: effective MB after server cap */
                                        esc_html__('1–300 MB. Leave blank to keep WordPress default. Configured: %1$s MB — Effective after server limits: %2$s MB.', 'content-core'),
                                        '<strong>' . esc_html($cc_limit_val) . '</strong>',
                                        '<strong>' . esc_html($cc_effective_mb) . '</strong>'
                                    );
                                } else {
                                    printf(
                                        esc_html__('1–300 MB. Leave blank to keep WordPress default. Current WordPress max: %s MB.', 'content-core'),
                                        '<strong>' . esc_html($cc_wp_max_mb) . '</strong>'
                                    );
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                </table>

            </div>
        </div>
        <?php
    }
}

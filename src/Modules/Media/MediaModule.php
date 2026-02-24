<?php
namespace ContentCore\Modules\Media;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\Settings\SettingsModule;

class MediaModule implements ModuleInterface
{
    /**
     * Recursion guard to prevent infinite loops during metadata regeneration
     */
    private static bool $is_regenerating = false;

    /**
     * Initialize the module
     */
    public function init(): void
    {
        // Intercept metadata generation to convert to WebP
        add_filter('wp_generate_attachment_metadata', [$this, 'handle_media_optimization'], 10, 2);

        // Auto alt text
        add_action('add_attachment', [$this, 'handle_auto_alt_text']);
    }

    /**
     * Handle image optimization and WebP conversion during metadata generation
     */
    public function handle_media_optimization(array $metadata, int $attachment_id): array
    {
        // Guard against recursion
        if (self::$is_regenerating) {
            return $metadata;
        }

        // Guard against deletion flow
        if (function_exists('wp_is_removing_attachment') && wp_is_removing_attachment($attachment_id)) {
            return $metadata;
        }

        $settings = get_option(SettingsModule::MEDIA_KEY, []);
        if (empty($settings['enabled'])) {
            return $metadata;
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) {
            return $metadata;
        }

        $mime_type = get_post_mime_type($attachment_id);
        $allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];

        if (!in_array($mime_type, $allowed_mimes, true)) {
            return $metadata;
        }

        // Prepare editor
        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) {
            return $metadata;
        }

        // WebP support check
        if (!method_exists($editor, 'supports_mime_type') || !$editor->supports_mime_type('image/webp')) {
            return $metadata;
        }

        $original_file = $file;
        $info = pathinfo($original_file);

        // If already webp, do nothing
        if (strtolower($info['extension']) === 'webp') {
            return $metadata;
        }

        $webp_file = $info['dirname'] . '/' . $info['filename'] . '.webp';

        // Resize if needed (handles the "big image" requirement manually)
        $size = $editor->get_size();
        $max_width = intval($settings['max_width_px'] ?: 2000);
        if ($size['width'] > $max_width) {
            $editor->resize($max_width, null, false);
        }

        // Set quality
        $editor->set_quality(intval($settings['quality'] ?: 70));

        // Save as WebP
        $saved = $editor->save($webp_file, 'image/webp');
        if (is_wp_error($saved)) {
            return $metadata;
        }

        // Update attachment to use the new WebP file as the canonical original
        update_attached_file($attachment_id, $webp_file);

        // Update mime type and GUID to reflect the new canonical original
        wp_update_post([
            'ID' => $attachment_id,
            'post_mime_type' => 'image/webp',
            'guid' => str_replace($info['basename'], basename($webp_file), get_the_guid($attachment_id))
        ]);

        // Generate new metadata for the webp file (this handles sub-sizes in webp)
        self::$is_regenerating = true;
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $new_metadata = wp_generate_attachment_metadata($attachment_id, $webp_file);
        self::$is_regenerating = false;

        // Crucial: Clean up the original raster file (could be .jpg or -scaled.jpg)
        if (file_exists($original_file)) {
            @unlink($original_file);
        }

        // Check for "-scaled.jpg" specifically if WordPress created one
        // (Even if we just converted a non-scaled original, WP might have left a scaled one behind)
        $scaled_file = str_replace('.' . $info['extension'], '-scaled.' . $info['extension'], $original_file);
        if ($scaled_file !== $original_file && file_exists($scaled_file)) {
            @unlink($scaled_file);
        }

        // Return the clean WebP metadata
        return !is_wp_error($new_metadata) ? $new_metadata : $metadata;
    }

    /**
     * Handle automatic alt text generation from filename
     */
    public function handle_auto_alt_text(int $attachment_id): void
    {
        $file = get_attached_file($attachment_id);
        if (!$file) {
            return;
        }

        $filename = pathinfo($file, PATHINFO_FILENAME);

        // Cleanup filename
        $alt_text = str_replace(['-', '_'], ' ', $filename);
        $alt_text = preg_replace('/\s+/', ' ', $alt_text);
        $alt_text = trim(ucwords(strtolower($alt_text)));

        if (!empty($alt_text)) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
        }
    }
}
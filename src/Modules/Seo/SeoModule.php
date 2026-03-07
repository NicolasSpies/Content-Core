<?php
namespace ContentCore\Modules\Seo;

use ContentCore\Modules\ModuleInterface;

class SeoModule implements ModuleInterface
{
    /**
     * Initialize the SEO module
     */
    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'add_seo_meta_box']);
        add_action('save_post', [$this, 'save_seo_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Enqueue JS for media uploader on post edit screens
     */
    public function enqueue_assets(string $hook): void
    {
        if (in_array($hook, ['post.php', 'post-new.php'], true) && current_user_can('manage_options')) {
            wp_enqueue_media();
        }
    }

    /**
     * Register the SEO meta box for all public post types
     */
    public function add_seo_meta_box(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'cc_seo_meta_box',
                __('SEO Settings', 'content-core'),
                [$this, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the SEO meta box HTML
     */
    public function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('cc_seo_save_data', 'cc_seo_meta_nonce');

        $title = get_post_meta($post->ID, 'cc_seo_title', true);
        $description = get_post_meta($post->ID, 'cc_seo_description', true);
        $image_id = get_post_meta($post->ID, 'cc_seo_og_image_id', true);
        $noindex = get_post_meta($post->ID, 'cc_seo_noindex', true);
        ?>
        <div>
            <p>
                <label for="cc_seo_title">
                    <?php _e('SEO Title', 'content-core'); ?>
                </label>
                <input type="text" id="cc_seo_title" name="cc_seo_title" value="<?php echo esc_attr($title); ?>" />
            </p>

            <p>
                <label for="cc_seo_description">
                    <?php _e('Meta Description', 'content-core'); ?>
                </label>
                <textarea id="cc_seo_description" name="cc_seo_description" rows="4"><?php echo esc_textarea($description); ?></textarea>
            </p>

            <p>
                <label>
                    <?php _e('OG Image', 'content-core'); ?>
                </label>
                <input type="hidden" id="cc_seo_og_image_id" name="cc_seo_og_image_id" value="<?php echo esc_attr($image_id); ?>" />
                
                <div id="cc-seo-meta-image-preview">
                    <?php
                    if (!empty($image_id)) {
                        echo wp_get_attachment_image(absint($image_id), 'thumbnail', false, ['class' => 'cc-media-preview-image']);
                    }
                    ?>
                </div>

                <button type="button" class="button" id="cc-seo-meta-image-button">
                    <?php _e('Select Image', 'content-core'); ?>
                </button>
                <button type="button" class="button button-link-delete" id="cc-seo-meta-image-remove">
                    <?php _e('Remove Image', 'content-core'); ?>
                </button>
            </p>

            <p>
                <label for="cc_seo_noindex">
                    <input type="checkbox" id="cc_seo_noindex" name="cc_seo_noindex" value="1" <?php checked($noindex, '1'); ?> />
                    <?php _e('Noindex (Hide from search engines)', 'content-core'); ?>
                </label>
            </p>
        </div>

        <script>
            jQuery(document).ready(function($) {
                var metaMediaFrame;
                $('#cc-seo-meta-image-button').on('click', function(e) {
                    e.preventDefault();
                    if (metaMediaFrame) {
                        metaMediaFrame.open();
                        return;
                    }
                    metaMediaFrame = wp.media({
                        title: '<?php echo esc_js(__('Select OG Image', 'content-core')); ?>',
                        button: { text: '<?php echo esc_js(__('Use this image', 'content-core')); ?>' },
                        multiple: false
                    });
                    metaMediaFrame.on('select', function() {
                        var attachment = metaMediaFrame.state().get('selection').first().toJSON();
                        $('#cc_seo_og_image_id').val(attachment.id);
                        var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                        $('#cc-seo-meta-image-preview').html('<img src="' + imgUrl + '" />').show();
                        $('#cc-seo-meta-image-remove').show();
                    });
                    metaMediaFrame.open();
                });

                $('#cc-seo-meta-image-remove').on('click', function(e) {
                    e.preventDefault();
                    $('#cc_seo_og_image_id').val('');
                    $('#cc-seo-meta-image-preview').hide().html('');
                    $(this).hide();
                });
            });
        </script>
        <?php
    }

    /**
     * Save the SEO meta box data
     */
    public function save_seo_meta(int $post_id, \WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST['cc_seo_meta_nonce']) || !wp_verify_nonce($_POST['cc_seo_meta_nonce'], 'cc_seo_save_data')) {
            return;
        }

        // Avoid autosaves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Administrator only check
        if (!current_user_can('manage_options')) {
            return;
        }

        // Post type capability check
        $post_type_object = get_post_type_object($post->post_type);
        if (!$post_type_object || !current_user_can($post_type_object->cap->edit_post, $post_id)) {
            return;
        }

        // Fields to save and their sanitization strategy
        $fields = [
            'cc_seo_title' => 'text',
            'cc_seo_description' => 'textarea',
            'cc_seo_og_image_id' => 'int',
        ];

        foreach ($fields as $key => $type) {
            if (isset($_POST[$key]) && $_POST[$key] !== '') {
                $value = $_POST[$key];
                
                switch ($type) {
                    case 'text':
                        $value = sanitize_text_field($value);
                        break;
                    case 'textarea':
                        $value = sanitize_textarea_field($value);
                        break;
                    case 'int':
                        $value = absint($value);
                        break;
                }
                
                update_post_meta($post_id, $key, $value);
            } else {
                delete_post_meta($post_id, $key);
            }
        }

        // Noindex checkbox handles differently
        if (!empty($_POST['cc_seo_noindex'])) {
            update_post_meta($post_id, 'cc_seo_noindex', '1');
        } else {
            delete_post_meta($post_id, 'cc_seo_noindex');
        }
    }
}

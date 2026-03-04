<?php
namespace ContentCore\Modules\ContentTypes\Admin;

use ContentCore\Modules\ContentTypes\Data\PostTypeDefinition;
use ContentCore\Modules\ContentTypes\Data\TaxonomyDefinition;

class ContentTypesAdmin
{
    private const DASHICON_GROUPS = [
        'General Content' => [
            'dashicons-media-document' => 'Variant A',
            'dashicons-admin-post' => 'Variant B',
            'dashicons-media-text' => 'Variant C',
        ],
        'News' => [
            'dashicons-rss' => 'Variant A',
            'dashicons-megaphone' => 'Variant B',
            'dashicons-format-aside' => 'Variant C',
        ],
        'References' => [
            'dashicons-format-image' => 'Variant A',
            'dashicons-portfolio' => 'Variant B',
            'dashicons-images-alt2' => 'Variant C',
        ],
        'Pages' => [
            'dashicons-welcome-write-blog' => 'Variant A',
            'dashicons-admin-page' => 'Variant B',
            'dashicons-media-default' => 'Variant C',
        ],
        'Events' => [
            'dashicons-clock' => 'Variant A',
            'dashicons-calendar-alt' => 'Variant B',
            'dashicons-calendar' => 'Variant C',
        ],
        'Team' => [
            'dashicons-businessperson' => 'Variant A',
            'dashicons-groups' => 'Variant B',
            'dashicons-universal-access' => 'Variant C',
        ],
        'Testimonials' => [
            'dashicons-testimonial' => 'Variant A',
            'dashicons-format-quote' => 'Variant B',
            'dashicons-thumbs-up' => 'Variant C',
        ],
        'FAQ' => [
            'dashicons-editor-help' => 'Variant A',
            'dashicons-editor-ul' => 'Variant B',
            'dashicons-editor-ol' => 'Variant C',
        ],
        'Case Studies' => [
            'dashicons-chart-line' => 'Variant A',
            'dashicons-analytics' => 'Variant B',
            'dashicons-chart-bar' => 'Variant C',
        ],
        'Downloads' => [
            'dashicons-media-archive' => 'Variant A',
            'dashicons-download' => 'Variant B',
            'dashicons-backup' => 'Variant C',
        ],
        'Jobs' => [
            'dashicons-id-alt' => 'Variant A',
            'dashicons-id' => 'Variant B',
            'dashicons-businesswoman' => 'Variant C',
        ],
        'Locations' => [
            'dashicons-location-alt' => 'Variant A',
            'dashicons-location' => 'Variant B',
            'dashicons-admin-site' => 'Variant C',
        ],
        'Services' => [
            'dashicons-admin-tools' => 'Variant A',
            'dashicons-hammer' => 'Variant B',
            'dashicons-admin-generic' => 'Variant C',
        ],
        'Products' => [
            'dashicons-cart' => 'Variant A',
            'dashicons-products' => 'Variant B',
            'dashicons-store' => 'Variant C',
        ],
        'Legal' => [
            'dashicons-media-text' => 'Variant A',
            'dashicons-yes-alt' => 'Variant B',
            'dashicons-shield' => 'Variant C',
        ],
        'Support' => [
            'dashicons-sos' => 'Variant A',
            'dashicons-editor-help' => 'Variant B',
            'dashicons-phone' => 'Variant C',
        ],
        'Marketing' => [
            'dashicons-megaphone' => 'Variant A',
            'dashicons-chart-area' => 'Variant B',
            'dashicons-share' => 'Variant C',
        ],
        'Media Library' => [
            'dashicons-format-gallery' => 'Variant A',
            'dashicons-video-alt3' => 'Variant B',
            'dashicons-camera' => 'Variant C',
        ],
        'Commerce' => [
            'dashicons-cart' => 'Variant A',
            'dashicons-money-alt' => 'Variant B',
            'dashicons-tickets-alt' => 'Variant C',
        ],
        'Education' => [
            'dashicons-welcome-learn-more' => 'Variant A',
            'dashicons-book-alt' => 'Variant B',
            'dashicons-welcome-write-blog' => 'Variant C',
        ],
        'Data' => [
            'dashicons-database' => 'Variant A',
            'dashicons-chart-pie' => 'Variant B',
            'dashicons-clipboard' => 'Variant C',
        ],
        'Integrations' => [
            'dashicons-admin-plugins' => 'Variant A',
            'dashicons-randomize' => 'Variant B',
            'dashicons-cloud' => 'Variant C',
        ],
        'System' => [
            'dashicons-admin-settings' => 'Variant A',
            'dashicons-performance' => 'Variant B',
            'dashicons-update' => 'Variant C',
        ],
        'People' => [
            'dashicons-admin-users' => 'Variant A',
            'dashicons-groups' => 'Variant B',
            'dashicons-businessperson' => 'Variant C',
        ],
    ];

    /**
     * Register hooks
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . PostTypeDefinition::POST_TYPE, [$this, 'save_post_type_definition'], 10, 2);
        add_action('save_post_' . TaxonomyDefinition::POST_TYPE, [$this, 'save_taxonomy_definition'], 10, 2);
    }

    /**
     * Add meta boxes for Post Type and Taxonomy definitions
     */
    public function add_meta_boxes(): void
    {
        // Post Type Settings
        add_meta_box(
            'cc_pt_settings',
            __('Post Type Settings', 'content-core'),
            [$this, 'render_post_type_settings'],
            PostTypeDefinition::POST_TYPE,
            'normal',
            'high'
        );

        // Taxonomy Settings
        add_meta_box(
            'cc_tax_settings',
            __('Taxonomy Settings', 'content-core'),
            [$this, 'render_taxonomy_settings'],
            TaxonomyDefinition::POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Render settings for Post Type Definition
     */
    public function render_post_type_settings(\WP_Post $post): void
    {
        wp_nonce_field('save_cc_pt_def', 'cc_pt_def_nonce');

        $slug = get_post_meta($post->ID, '_cc_pt_slug', true);
        $singular = get_post_meta($post->ID, '_cc_pt_singular', true);
        $plural = get_post_meta($post->ID, '_cc_pt_plural', true);
        $public = get_post_meta($post->ID, '_cc_pt_public', true) !== '0';
        $has_archive = get_post_meta($post->ID, '_cc_pt_has_archive', true) === '1';
        $supports = get_post_meta($post->ID, '_cc_pt_supports', true);
        $menu_icon = get_post_meta($post->ID, '_cc_pt_menu_icon', true);
        if (!is_array($supports)) {
            $supports = ['title', 'editor', 'thumbnail'];
        }
        if (!$this->is_allowed_dashicon($menu_icon)) {
            $menu_icon = 'dashicons-media-document';
        }

        $all_supports = [
            'title' => __('Title', 'content-core'),
            'editor' => __('Editor (Content)', 'content-core'),
            'thumbnail' => __('Featured Image', 'content-core'),
            'excerpt' => __('Excerpt', 'content-core'),
            'author' => __('Author', 'content-core'),
            'revisions' => __('Revisions', 'content-core'),
            'custom-fields' => __('Native Custom Fields', 'content-core'),
            'page-attributes' => __('Page Attributes (Hierarchical)', 'content-core'),
        ];

        ?>
        <div class="cc-fields-container">
            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label for="cc_pt_slug"><?php _e('Slug / ID', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <input name="cc_pt_slug" type="text" id="cc_pt_slug" value="<?php echo esc_attr($slug); ?>" class="cc-input-full" <?php echo $slug ? 'readonly' : ''; ?> placeholder="e.g. portfolio_item" pattern="[a-z0-9_]+" title="Lowercase letters, numbers and underscores only.">
                    <p class="description">
                        <?php if ($slug) : ?>
                            <?php _e('The unique identifier. This cannot be changed.', 'content-core'); ?>
                        <?php else : ?>
                            <?php _e('Lowercase letters and underscores only. This will be used in URLs and queries.', 'content-core'); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label for="cc_pt_singular"><?php _e('Singular Label', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <input name="cc_pt_singular" type="text" id="cc_pt_singular" value="<?php echo esc_attr($singular); ?>" class="cc-input-full" placeholder="e.g. Portfolio Item">
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label for="cc_pt_plural"><?php _e('Plural Label', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <input name="cc_pt_plural" type="text" id="cc_pt_plural" value="<?php echo esc_attr($plural); ?>" class="cc-input-full" placeholder="e.g. Portfolio">
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label><?php _e('Core Settings', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <div>
                        <label><input name="cc_pt_public" type="checkbox" value="1" <?php checked($public); ?>> <strong><?php _e('Public', 'content-core'); ?></strong> — <?php _e('Visible on frontend and in admin menu.', 'content-core'); ?></label>
                    </div>
                    <div>
                        <label><input name="cc_pt_has_archive" type="checkbox" value="1" <?php checked($has_archive); ?>> <strong><?php _e('Has Archive', 'content-core'); ?></strong> — <?php _e('Enables a listing page at the post type slug URL.', 'content-core'); ?></label>
                    </div>
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label><?php _e('Supports', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <div class="cc-grid-options">
                        <?php foreach ($all_supports as $key => $label) : ?>
                            <label>
                                <input name="cc_pt_supports[]" type="checkbox" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $supports)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php _e('Select which features the editor should support.', 'content-core'); ?></p>
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label><?php _e('Menu Icon', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <div class="cc-dashicon-groups" role="radiogroup" aria-label="<?php echo esc_attr__('Choose menu icon', 'content-core'); ?>">
                        <?php foreach (self::DASHICON_GROUPS as $group_label => $group_icons): ?>
                            <div class="cc-dashicon-group">
                                <div class="cc-dashicon-group-title"><?php echo esc_html__($group_label, 'content-core'); ?></div>
                                <div class="cc-dashicon-picker">
                                    <?php foreach ($group_icons as $icon_class => $_variant_label): ?>
                                        <?php $input_id = 'cc_pt_menu_icon_' . esc_attr($icon_class); ?>
                                        <label class="cc-dashicon-option" for="<?php echo $input_id; ?>">
                                            <input
                                                type="radio"
                                                id="<?php echo $input_id; ?>"
                                                name="cc_pt_menu_icon"
                                                value="<?php echo esc_attr($icon_class); ?>"
                                                <?php checked($menu_icon, $icon_class); ?>
                                            >
                                            <span class="cc-dashicon-swatch dashicons <?php echo esc_attr($icon_class); ?>" aria-hidden="true"></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="description"><?php _e('Choose the sidebar icon for this post type.', 'content-core'); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings for Taxonomy Definition
     */
    public function render_taxonomy_settings(\WP_Post $post): void
    {
        wp_nonce_field('save_cc_tax_def', 'cc_tax_def_nonce');

        $slug = get_post_meta($post->ID, '_cc_tax_slug', true);
        $label = get_post_meta($post->ID, '_cc_tax_label', true);
        $hierarchical = get_post_meta($post->ID, '_cc_tax_hierarchical', true) === '1';
        $object_types = get_post_meta($post->ID, '_cc_tax_object_types', true);
        if (!is_array($object_types)) {
            $object_types = [];
        }

        $all_post_types = get_post_types(['public' => true], 'objects');

        ?>
        <div class="cc-fields-container">
            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label for="cc_tax_slug"><?php _e('Slug / ID', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <input name="cc_tax_slug" type="text" id="cc_tax_slug" value="<?php echo esc_attr($slug); ?>" class="cc-input-full" <?php echo $slug ? 'readonly' : ''; ?> placeholder="e.g. project_category" pattern="[a-z0-9_]+" title="Lowercase letters and underscores only.">
                    <p class="description"><?php _e('The unique identifier for the taxonomy.', 'content-core'); ?></p>
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label for="cc_tax_label"><?php _e('Label', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <input name="cc_tax_label" type="text" id="cc_tax_label" value="<?php echo esc_attr($label); ?>" class="cc-input-full" placeholder="e.g. Categories">
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label><?php _e('Type', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <label><input name="cc_tax_hierarchical" type="checkbox" value="1" <?php checked($hierarchical); ?>> <strong><?php _e('Hierarchical', 'content-core'); ?></strong></label>
                    <p class="description"><?php _e('Checked: Like Categories (nested). Unchecked: Like Tags (flat).', 'content-core'); ?></p>
                </div>
            </div>

            <div class="cc-field-row">
                <div class="cc-field-label">
                    <label><?php _e('Assign to Post Types', 'content-core'); ?></label>
                </div>
                <div class="cc-field-input">
                    <div class="cc-grid-options">
                        <?php foreach ($all_post_types as $pt) : if ($pt->name === PostTypeDefinition::POST_TYPE || $pt->name === TaxonomyDefinition::POST_TYPE) continue; ?>
                            <label>
                                <input name="cc_tax_object_types[]" type="checkbox" value="<?php echo esc_attr($pt->name); ?>" <?php checked(in_array($pt->name, $object_types)); ?>>
                                <?php echo esc_html($pt->label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save Post Type Definition
     */
    public function save_post_type_definition(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['cc_pt_def_nonce']) || !wp_verify_nonce($_POST['cc_pt_def_nonce'], 'save_cc_pt_def')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // Slug is only set once and must be validated
        if (!get_post_meta($post_id, '_cc_pt_slug', true) && !empty($_POST['cc_pt_slug'])) {
            $slug = sanitize_title($_POST['cc_pt_slug']);
            if (!post_type_exists($slug) && !$this->is_reserved_slug($slug)) {
                update_post_meta($post_id, '_cc_pt_slug', $slug);
            }
        }

        $singular = sanitize_text_field($_POST['cc_pt_singular'] ?? '');
        $plural = sanitize_text_field($_POST['cc_pt_plural'] ?? '');

        update_post_meta($post_id, '_cc_pt_singular', $singular);
        update_post_meta($post_id, '_cc_pt_plural', $plural);

        // Keep the definition post title aligned with the singular label
        // so admin headings and internal references never fall back to "post".
        if ($singular !== '' && $post->post_title !== $singular) {
            remove_action('save_post_' . PostTypeDefinition::POST_TYPE, [$this, 'save_post_type_definition'], 10);
            wp_update_post([
                'ID' => $post_id,
                'post_title' => $singular,
            ]);
            add_action('save_post_' . PostTypeDefinition::POST_TYPE, [$this, 'save_post_type_definition'], 10, 2);
        }
        update_post_meta($post_id, '_cc_pt_public', isset($_POST['cc_pt_public']) ? '1' : '0');
        update_post_meta($post_id, '_cc_pt_has_archive', isset($_POST['cc_pt_has_archive']) ? '1' : '0');
        
        $supports = isset($_POST['cc_pt_supports']) ? (array)$_POST['cc_pt_supports'] : [];
        update_post_meta($post_id, '_cc_pt_supports', array_map('sanitize_text_field', $supports));

        $menu_icon = sanitize_text_field($_POST['cc_pt_menu_icon'] ?? 'dashicons-media-document');
        if (!$this->is_allowed_dashicon($menu_icon)) {
            $menu_icon = 'dashicons-media-document';
        }
        update_post_meta($post_id, '_cc_pt_menu_icon', $menu_icon);

        // Flush rewrite rules on next load if definition changes
        update_option('cc_flush_rewrite_rules', 1);
    }

    /**
     * Save Taxonomy Definition
     */
    public function save_taxonomy_definition(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['cc_tax_def_nonce']) || !wp_verify_nonce($_POST['cc_tax_def_nonce'], 'save_cc_tax_def')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // Slug is only set once
        if (!get_post_meta($post_id, '_cc_tax_slug', true) && !empty($_POST['cc_tax_slug'])) {
            $slug = sanitize_title($_POST['cc_tax_slug']);
            if (!taxonomy_exists($slug) && !$this->is_reserved_slug($slug)) {
                update_post_meta($post_id, '_cc_tax_slug', $slug);
            }
        }

        update_post_meta($post_id, '_cc_tax_label', sanitize_text_field($_POST['cc_tax_label'] ?? ''));
        update_post_meta($post_id, '_cc_tax_hierarchical', isset($_POST['cc_tax_hierarchical']) ? '1' : '0');
        
        $object_types = isset($_POST['cc_tax_object_types']) ? (array)$_POST['cc_tax_object_types'] : [];
        update_post_meta($post_id, '_cc_tax_object_types', array_map('sanitize_text_field', $object_types));

        update_option('cc_flush_rewrite_rules', 1);
    }

    /**
     * Check if a slug is reserved by WordPress
     */
    private function is_reserved_slug(string $slug): bool
    {
        $reserved = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'action', 'author', 'order', 'theme', 'attachment_id', 'author_name', 'calendar', 'cat', 'category_name', 'comments_popup', 'customize_messenger_channel', 'customized', 'error', 'm', 'more', 'name', 'order', 'orderby', 'p', 'page_id', 'paged', 'pagename', 'pb', 'posts', 'preview', 'published', 'robots', 's', 'search', 'second', 'sentence', 'static', 'subpost', 'subpost_id', 'taxonomy', 'tag', 'tag_id', 'tb', 'term', 'type', 'w', 'year'];
        return in_array($slug, $reserved, true);
    }

    private function is_allowed_dashicon($icon): bool
    {
        if (!is_string($icon)) {
            return false;
        }

        foreach (self::DASHICON_GROUPS as $group_icons) {
            if (isset($group_icons[$icon])) {
                return true;
            }
        }

        return false;
    }
}

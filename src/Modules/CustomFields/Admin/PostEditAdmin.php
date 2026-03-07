<?php
namespace ContentCore\Modules\CustomFields\Admin;

use ContentCore\Modules\CustomFields\Data\FieldGroupPostType;
use ContentCore\Modules\CustomFields\Data\FieldRegistry;

class PostEditAdmin
{

    /**
     * Register hooks for standard post edit screens
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes'], 10, 2);
        // Action metabox disabled: buttons moved to right-rail top header.
        // add_action('add_meta_boxes', [$this, 'register_sidebar_action_metabox'], 9, 2);
        add_action('add_meta_boxes', [$this, 'remove_legacy_acf_meta_boxes'], 999, 2);
        add_action('save_post', [$this, 'save_post_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_head', [$this, 'suppress_add_new_button']);
        add_action('edit_form_top', [$this, 'render_editor_header']);
        add_action('add_meta_boxes', [$this, 'rename_metaboxes'], 20);
        add_filter('get_user_option_meta-box-order_referenz', [$this, 'enforce_referenz_sidebar_order'], 20, 3);
    }

    /**
     * Register meta boxes for the current post type
     */
    public function register_meta_boxes(string $post_type, \WP_Post $post): void
    {
        // Don't add custom fields to our own field group builder CPT
        if (FieldGroupPostType::POST_TYPE === $post_type) {
            return;
        }

        // Build context for rule evaluation
        $context = [
            'post_id' => $post->ID,
            'post_type' => $post_type,
            'page_template' => get_post_meta($post->ID, '_wp_page_template', true),
            'taxonomy_terms' => FieldRegistry::get_context_taxonomy_terms($post->ID),
        ];

        $groups = FieldRegistry::get_field_groups($context);

        foreach ($groups as $group) {
            $id = 'cc_group_' . $group->ID;
            add_meta_box(
                $id,
                esc_html($group->post_title),
                [$this, 'render_meta_box'],
                $post_type,
                'normal',
                'high',
                ['group_id' => $group->ID]
            );
        }
    }

    /**
     * Register right-rail action box (Preview + Publish/Update) at the top of the sidebar stack.
     */
    public function register_sidebar_action_metabox(string $post_type, \WP_Post $post): void
    {
        if (FieldGroupPostType::POST_TYPE === $post_type) {
            return;
        }

        $groups = $this->get_field_groups_for_post_type($post_type);
        if (empty($groups)) {
            return;
        }

        add_meta_box(
            'cc-editor-actions-top',
            '',
            [$this, 'render_sidebar_action_metabox'],
            $post_type,
            'side',
            'high'
        );
    }

    /**
     * Explicitly remove orphaned legacy ACF meta boxes that might still be rendering
     * from database transients or active plugins.
     */
    public function remove_legacy_acf_meta_boxes(string $post_type, \WP_Post $post): void
    {
        global $wp_meta_boxes;

        // Remove default WordPress "Custom Fields" (Individuelle Felder) meta box
        remove_meta_box('postcustom', $post_type, 'normal');
        remove_meta_box('postcustom', $post_type, 'advanced');

        // Explicitly remove known ACF static locations
        remove_meta_box('acf_after_title', $post_type, 'normal');
        remove_meta_box('acf_after_title', $post_type, 'advanced');
        remove_meta_box('acf_after_title', $post_type, 'side');

        // Dynamically strip any metabox starting with acf-
        if (isset($wp_meta_boxes[$post_type]) && is_array($wp_meta_boxes[$post_type])) {
            foreach ($wp_meta_boxes[$post_type] as $context => $priorities) {
                if (!is_array($priorities))
                    continue;
                foreach ($priorities as $priority => $boxes) {
                    if (!is_array($boxes))
                        continue;
                    foreach ($boxes as $id => $box) {
                        if (strpos($id, 'acf-') === 0 || strpos($id, 'acf_') === 0) {
                            remove_meta_box($id, $post_type, $context);
                        }
                    }
                }
            }
        }
    }


    /**
     * Render the meta box for a specific field group
     */
    public function render_meta_box(\WP_Post $post, array $args): void
    {
        $group_id = $args['args']['group_id'] ?? 0;
        \ContentCore\Logger::debug('Rendering meta box for group_id: ' . $group_id);
        if (!$group_id) {
            return;
        }

        static $nonce_rendered = false;
        if (!$nonce_rendered) {
            wp_nonce_field('save_cc_post_meta', 'cc_post_meta_nonce');
            $nonce_rendered = true;
        }

        $fields = get_post_meta($group_id, '_cc_fields', true);
        \ContentCore\Logger::debug('Fields for group ' . $group_id . ': ' . (is_array($fields) ? count($fields) : 'NOT AN ARRAY'));
        if (!is_array($fields) || empty($fields)) {
            echo '<p>' . esc_html__('No fields defined for this group.', 'content-core') . '</p>';
            return;
        }

        $group = get_post($group_id);
        $group_title = $group ? (string) $group->post_title : '';
        $description = $group ? $group->post_content : '';

        // Debug marker
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo "\n<!-- Content Core metabox UI: enabled -->\n";
        }

        if ($description) {
            echo '<div class="cc-metabox-desc">' . wp_kses_post($description) . '</div>';
        }
        echo '<div class="cc-metabox-body">';

        $section_index = 0;
        $normalized_group_title = $this->normalize_group_or_section_title($group_title);

        foreach ($fields as $index => $field) {
            $type = $field['type'] ?? '';

            if ($type === 'section' || $type === 'ui_section') {
                $section_label = isset($field['label']) ? (string) $field['label'] : '';
                $normalized_section_title = $this->normalize_group_or_section_title($section_label);
                $hide_title = (
                    $normalized_group_title !== ''
                    && $normalized_section_title !== ''
                    && $normalized_section_title === $normalized_group_title
                );

                $this->render_section_start($field, $group_id, $section_index, $hide_title);

                // Render tree children
                if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                    foreach ($field['sub_fields'] as $child_field) {
                        $this->render_field($post->ID, $child_field);
                    }
                }

                echo '</div></div>'; // Close section body and section wrapper
                $section_index++;
                continue;
            }

            // Root-level fields fallback (usually not used because builder enforces sections).
            $this->render_field($post->ID, $field);
        }

        echo '</div>'; // .cc-metabox-body
    }

    /**
     * Render the start of a section
     */
    private function render_section_start(array $field, int $group_id, int $index, bool $hide_title = false): void
    {
        $label = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $collapsible = !empty($field['collapsible']);
        $default_state = $field['default_state'] ?? 'expanded';

        $section_id = $GLOBALS['post']->ID . '_' . absint($group_id) . '_' . $index;

        $classes = ['cc-editor-section'];
        if ($collapsible) {
            $classes[] = 'is-collapsible';
        }
        if ($hide_title) {
            $classes[] = 'cc-editor-section--title-hidden';
        }

        // First section open by default, others follow logic
        if ($index === 0 || $default_state === 'expanded') {
            $classes[] = 'is-open';
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" data-section-id="' . esc_attr($section_id) . '">';
        if (!$hide_title) {
            echo '<div class="cc-editor-section-header">';
            echo '<div class="cc-editor-section-title-wrap">';
            if ($label) {
                echo '<h2 class="cc-editor-section-title">' . esc_html($label) . '</h2>';
            }
            if ($description) {
                echo '<p class="cc-editor-section-description">' . esc_html($description) . '</p>';
            }
            echo '</div>';
            if ($collapsible) {
                echo '<span class="cc-editor-section-toggle dashicons dashicons-arrow-down-alt2"></span>';
            }
            echo '</div>';
        }
        echo '<div class="cc-editor-section-body">';
    }

    private function normalize_group_or_section_title(string $value): string
    {
        $normalized = trim(wp_strip_all_tags($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = html_entity_decode($normalized, ENT_QUOTES, 'UTF-8');
        $normalized = str_replace(['-', '_', '/', '\\'], ' ', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalized, 'UTF-8');
        }

        return strtolower($normalized);
    }

    /**
     * Render a single field based on its type
     */
    private function render_field(int $post_id, array $field): void
    {
        $name = $field['name'] ?? '';
        $label = $field['label'] ?? '';
        $type = $field['type'] ?? 'text';
        $required = $field['required'] ?? false;
        $default_value = $field['default_value'] ?? '';
        $field_desc = $field['description'] ?? '';
        $req_mark = $required ? '<span class="cc-required-mark">*</span>' : '';

        if (!$name) {
            return;
        }

        $current_value = get_post_meta($post_id, $name, true);
        if ('' === $current_value && !empty($default_value)) {
            $current_value = $default_value;
        }

        $field_id = 'cc_field_' . esc_attr($name);
        $field_name = 'cc_meta[' . esc_attr($name) . ']';
        $req_attr = $required ? 'required="required"' : '';

        // Width Logic (6-column base)
        $width_class = 'cc-editor-field--half'; // Span 3
        if (in_array($type, ['textarea', 'image', 'gallery', 'repeater', 'group'])) {
            $width_class = 'cc-editor-field--full'; // Span 6
        } elseif ($type === 'number') {
            $width_class = 'cc-editor-field--third'; // Span 2
        }

        $field_classes = ['cc-editor-field', $width_class];
        $field_classes[] = 'cc-editor-field-type-' . esc_attr($type);

        if ($type === 'boolean') {
            $field_classes[] = 'cc-editor-field--toggle';
        }

        echo '<div class="' . esc_attr(implode(' ', $field_classes)) . '">';

        // Label (except for toggles which handle it inside)
        if ($type !== 'boolean') {
            echo '<label class="cc-editor-field-label" for="' . esc_attr($field_id) . '">' . esc_html($label) . $req_mark . '</label>';
        }

        echo '<div class="cc-editor-field-input">';

        switch ($type) {
            case 'textarea':
                echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" rows="4" ' . $req_attr . '>' . esc_textarea($current_value) . '</textarea>';
                break;
            case 'number':
                echo '<input type="number" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($current_value) . '" ' . $req_attr . '>';
                break;
            case 'email':
                echo '<input type="email" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($current_value) . '" ' . $req_attr . '>';
                break;
            case 'url':
                echo '<input type="url" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_url($current_value) . '" ' . $req_attr . '>';
                break;
            case 'boolean':
                $checked = !empty($current_value) ? 'checked="checked"' : '';
                echo '<label class="cc-editor-field-label" for="' . esc_attr($field_id) . '">' . esc_html($label) . $req_mark . '</label>';
                echo '<div class="cc-toggle">';
                echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0">';
                echo '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" value="1" ' . $checked . ' ' . $req_attr . '> ';
                echo '<span class="cc-toggle-slider"></span>';
                echo '</div>';
                break;
            case 'image':
            case 'file':
                $this->render_media_field($field_name, $field_id, $current_value, $type);
                break;
            case 'gallery':
                $gallery_ids = $current_value;
                if (is_string($gallery_ids)) {
                    $gallery_ids = json_decode($gallery_ids, true);
                }
                $this->render_gallery_field($field_name, $field_id, is_array($gallery_ids) ? $gallery_ids : []);
                break;
            case 'repeater':
                // Repeater logic stays similar but we use the new field rendering inside it if possible
                // For now, keeping the structural logic but the theme handles the "premium" look
                $sub_fields = $field['sub_fields'] ?? [];
                $encoded_sub_fields = htmlspecialchars(wp_json_encode($sub_fields), ENT_QUOTES, 'UTF-8');
                $rows = is_string($current_value) ? json_decode($current_value, true) : $current_value;
                $rows = is_array($rows) ? $rows : [];

                echo '<div class="cc-repeater-container" data-name="' . esc_attr($name) . '" data-sub-fields="' . $encoded_sub_fields . '">';
                echo '<div class="cc-repeater-rows">';
                foreach ($rows as $index => $row) {
                    $this->render_repeater_row($name, $index, $field['sub_fields'], $row);
                }
                echo '</div>';
                echo '<div class="cc-repeater-footer">';
                echo '<button type="button" class="button cc-add-repeater-row-btn">' . esc_html__('+ Add Row', 'content-core') . '</button>';
                echo '</div>';
                echo '</div>';
                break;
            case 'video':
                echo '<div class="cc-video-split">';
                echo '<input type="url" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_url($current_value) . '" placeholder="https://youtube.com/..." ' . $req_attr . '>';
                echo '<div class="cc-video-preview">';
                if ($current_value) {
                    echo '<div class="cc-video-placeholder"><span class="dashicons dashicons-video-alt3"></span></div>';
                } else {
                    echo '<div class="cc-video-placeholder is-empty"></div>';
                }
                echo '</div>';
                echo '</div>';
                break;
            default:
                echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($current_value) . '" ' . $req_attr . '>';
                break;
        }

        if ($field_desc && $type !== 'boolean') {
            echo '<p class="cc-editor-field-help description">' . esc_html($field_desc) . '</p>';
        }

        echo '</div>'; // .cc-editor-field-input
        echo '</div>'; // .cc-editor-field
    }

    /**
     * Render a media (image/file) upload field
     */
    private function render_media_field(string $field_name, string $field_id, $value, string $type): void
    {
        echo '<div class="cc-media-uploader" data-type="' . esc_attr($type) . '">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" class="cc-media-id-input">';

        echo '<div class="cc-image-preview-wrapper cc-media-upload-btn">';

        if ($value) {
            if ('image' === $type) {
                echo wp_get_attachment_image($value, 'large', false, ['class' => 'cc-image-preview']);
                echo '<div class="cc-media-actions">';
                echo '<button type="button" class="button cc-media-upload-btn">' . esc_html__('Replace', 'content-core') . '</button>';
                echo '<button type="button" class="button cc-media-remove-btn">' . esc_html__('Remove', 'content-core') . '</button>';
                echo '</div>';
            } else {
                // File Card UI
                $url = wp_get_attachment_url($value);
                $file_path = get_attached_file($value);
                $file_size = $file_path ? size_format(filesize($file_path)) : '';
                $extension = strtoupper(pathinfo($url, PATHINFO_EXTENSION));

                echo '<div class="cc-file-card">';
                echo '<div class="cc-file-icon"><span class="dashicons dashicons-media-document"></span></div>';
                echo '<div class="cc-file-info">';
                echo '<span class="cc-file-name">' . esc_html(basename($url)) . '</span>';
                echo '<span class="cc-file-meta">' . esc_html($extension) . ($file_size ? ' &bull; ' . esc_html($file_size) : '') . '</span>';
                echo '</div>';
                echo '<div class="cc-file-actions">';
                echo '<button type="button" class="button cc-media-upload-btn">' . esc_html__('Replace', 'content-core') . '</button>';
                echo '<button type="button" class="button cc-media-remove-btn">' . esc_html__('Remove', 'content-core') . '</button>';
                echo '</div>';
                echo '</div>';
            }
        } else {
            // Placeholder Card
            echo '<div class="cc-media-placeholder">';
            echo '<i class="dashicons dashicons-' . ('image' === $type ? 'format-image' : 'media-default') . '"></i>';
            echo '<span>' . ('image' === $type ? esc_html__('Select Image', 'content-core') : esc_html__('Select File', 'content-core')) . '</span>';
            echo '<button type="button" class="button button-secondary">' . esc_html__('Choose', 'content-core') . '</button>';
            echo '</div>';
        }

        echo '</div>'; // .cc-image-preview-wrapper
        echo '</div>'; // .cc-media-uploader
    }

    /**
     * Render a gallery field
     */
    private function render_gallery_field(string $field_name, string $field_id, $value): void
    {
        $ids = is_array($value) ? $value : [];
        $ids_json = wp_json_encode($ids);

        echo '<div class="cc-gallery-container">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($ids_json) . '" class="cc-gallery-input">';

        echo '<div class="cc-gallery-grid">';
        foreach ($ids as $id) {
            $thumb = wp_get_attachment_image_src($id, 'thumbnail');
            if ($thumb) {
                echo '<div class="cc-gallery-item" data-id="' . esc_attr($id) . '">';
                echo '<img src="' . esc_url($thumb[0]) . '" />';
                echo '<button type="button" class="cc-gallery-remove"><span class="dashicons dashicons-no-alt"></span></button>';
                echo '</div>';
            }
        }

        // Add Placeholder Tile
        echo '<button type="button" class="cc-gallery-add cc-gallery-add-btn" aria-label="' . esc_attr__('Add images', 'content-core') . '">';
        echo '<i class="dashicons dashicons-plus"></i>';
        echo '<span>' . esc_html__('Add', 'content-core') . '</span>';
        echo '</button>';

        echo '</div>'; // .cc-gallery-grid
        echo '</div>'; // .cc-gallery-container
    }

    /**
     * Render a single row of a repeater
     */
    private function render_repeater_row(string $parent_name, int $index, array $sub_fields, array $row_data): void
    {
        // Try to get a preview label from the first text field
        $first_val = '';
        foreach ($sub_fields as $sf) {
            if (isset($row_data[$sf['name']]) && !is_array($row_data[$sf['name']])) {
                $first_val = (string) $row_data[$sf['name']];
                if ($first_val)
                    break;
            }
        }
        $row_title = $first_val ? wp_trim_words($first_val, 8) : sprintf(__('Row %d', 'content-core'), $index + 1);

        echo '<div class="cc-repeater-row" data-index="' . $index . '">';
        echo '<div class="cc-repeater-row-header">';
        echo '<span class="cc-repeater-row-handle dashicons dashicons-menu"></span>';
        echo '<span class="cc-repeater-row-index">' . ($index + 1) . '</span>';
        echo '<span class="cc-repeater-row-title">' . esc_html($row_title) . '</span>';
        echo '<div class="cc-repeater-row-actions">';
        echo '<button type="button" class="cc-repeater-row-toggle" title="' . esc_attr__('Toggle Row', 'content-core') . '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
        echo '<button type="button" class="cc-repeater-row-remove" title="' . esc_attr__('Remove Row', 'content-core') . '"><span class="dashicons dashicons-trash"></span></button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="cc-repeater-row-content">';

        foreach ($sub_fields as $sub) {
            $sub_name = $sub['name'] ?? '';
            $sub_label = $sub['label'] ?? '';
            $sub_type = $sub['type'] ?? 'text';
            $field_val = $row_data[$sub_name] ?? '';
            $field_id = 'cc_field_' . esc_attr($parent_name) . '_' . $index . '_' . esc_attr($sub_name);
            $field_name = 'cc_meta[' . esc_attr($parent_name) . '][' . $index . '][' . esc_attr($sub_name) . ']';

            $compact_class = ($sub_type === 'boolean') ? ' cc-field-compact' : '';
            $media_class = in_array($sub_type, ['image', 'file', 'gallery']) ? ' cc-field-media' : '';

            echo '<div class="cc-field-row cc-field-type-' . esc_attr($sub_type) . $compact_class . $media_class . '">';
            echo '<div class="cc-field-label"><label for="' . esc_attr($field_id) . '">' . esc_html($sub_label) . '</label></div>';
            echo '<div class="cc-field-control">';

            switch ($sub_type) {
                case 'textarea':
                    echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" rows="3">' . esc_textarea($field_val) . '</textarea>';
                    break;
                case 'number':
                    echo '<input type="number" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
                case 'email':
                    echo '<input type="email" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
                case 'url':
                    echo '<input type="url" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_url($field_val) . '">';
                    break;
                case 'boolean':
                    $checked = !empty($field_val) ? 'checked="checked"' : '';
                    echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0">';
                    echo '<label><input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" value="1" ' . $checked . '> ' . esc_html__('Yes', 'content-core') . '</label>';
                    break;
                case 'image':
                case 'file':
                    $this->render_media_field($field_name, $field_id, $field_val, $sub_type);
                    break;
                case 'text':
                default:
                    echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
            }
            echo '</div></div>';
        }
        echo '</div></div>';
    }

    /**
     * Render a group field (nested fields)
     */
    private function render_group_field(int $post_id, string $name, array $sub_fields): void
    {
        $current_value = get_post_meta($post_id, $name, true);
        if (is_string($current_value)) {
            $current_value = json_decode($current_value, true);
        }
        $group_data = is_array($current_value) ? $current_value : [];

        echo '<div class="cc-group-container">';

        foreach ($sub_fields as $sub) {
            $sub_name = $sub['name'] ?? '';
            $sub_label = $sub['label'] ?? '';
            $sub_type = $sub['type'] ?? 'text';
            $field_val = $group_data[$sub_name] ?? '';

            $field_id = 'cc_field_' . esc_attr($name) . '_' . esc_attr($sub_name);
            $field_name = 'cc_meta[' . esc_attr($name) . '][' . esc_attr($sub_name) . ']';

            $compact_class = ($sub_type === 'boolean') ? ' cc-field-compact' : '';
            $media_class = in_array($sub_type, ['image', 'file', 'gallery']) ? ' cc-field-media' : '';

            echo '<div class="cc-field-row cc-field-type-' . esc_attr($sub_type) . $compact_class . $media_class . '">';
            echo '<div class="cc-field-label"><label for="' . esc_attr($field_id) . '">' . esc_html($sub_label) . '</label></div>';
            echo '<div class="cc-field-control">';

            switch ($sub_type) {
                case 'textarea':
                    echo '<textarea id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" rows="3">' . esc_textarea($field_val) . '</textarea>';
                    break;
                case 'number':
                    echo '<input type="number" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
                case 'email':
                    echo '<input type="email" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
                case 'url':
                    echo '<input type="url" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_url($field_val) . '">';
                    break;
                case 'boolean':
                    $checked = !empty($field_val) ? 'checked="checked"' : '';
                    echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0">';
                    echo '<label><input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" value="1" ' . $checked . '> ' . esc_html__('Yes', 'content-core') . '</label>';
                    break;
                case 'image':
                case 'file':
                    $this->render_media_field($field_name, $field_id, $field_val, $sub_type);
                    break;
                case 'text':
                default:
                    echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($field_val) . '">';
                    break;
            }
            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Helper to get field groups for the current context
     */
    private function get_field_groups_for_post_type(string $post_type, int $post_id = 0): array
    {
        if (FieldGroupPostType::POST_TYPE === $post_type) {
            return [];
        }

        $page_template = '';
        if ($post_id > 0) {
            $page_template = (string) get_post_meta($post_id, '_wp_page_template', true);
        }

        $context = [
            'post_id' => $post_id,
            'post_type' => $post_type,
            'page_template' => $page_template,
            'taxonomy_terms' => $post_id > 0 ? FieldRegistry::get_context_taxonomy_terms($post_id) : [],
        ];

        return FieldRegistry::get_field_groups($context);
    }

    /**
     * Save the custom field values
     */
    public function save_post_meta(int $post_id, \WP_Post $post): void
    {
        if (FieldGroupPostType::POST_TYPE === $post->post_type)
            return;
        if (!isset($_POST['cc_post_meta_nonce']) || !wp_verify_nonce($_POST['cc_post_meta_nonce'], 'save_cc_post_meta'))
            return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            return;
        if (!current_user_can('edit_post', $post_id))
            return;
        if (!isset($_POST['cc_meta']) || !is_array($_POST['cc_meta'])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
            }
            return;
        }

        $groups = $this->get_field_groups_for_post_type($post->post_type, $post_id);
        $valid_fields = [];
        foreach ($groups as $group) {
            $fields = get_post_meta($group->ID, '_cc_fields', true);
            if (is_array($fields)) {
                // Use the static registry helper to extract all nested fields (Sections, etc)
                \ContentCore\Modules\CustomFields\Data\FieldRegistry::extract_fields_recursive($fields, $valid_fields);
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
        }

        foreach ($_POST['cc_meta'] as $name => $value) {
            if (!isset($valid_fields[$name]))
                continue;

            $sanitized = $this->sanitize_field_value($value, $valid_fields[$name]);

            if (null === $sanitized || '' === $sanitized || (is_array($sanitized) && empty($sanitized))) {
                delete_post_meta($post_id, $name);
            } else {
                // Store as JSON string if it's a structural field (repeater/group/gallery)
                if (in_array($valid_fields[$name]['type'], ['repeater', 'group', 'gallery'])) {
                    $sanitized = wp_json_encode($sanitized);
                }
                update_post_meta($post_id, $name, $sanitized);
            }
        }
    }

    /**
     * Recursive field value sanitization
     */
    private function sanitize_field_value($value, array $schema)
    {
        $type = $schema['type'] ?? 'text';

        switch ($type) {
            case 'text':
                return sanitize_text_field($value);
            case 'textarea':
                return sanitize_textarea_field($value);
            case 'number':
                return is_numeric($value) ? (string) ($value + 0) : '';
            case 'email':
                return sanitize_email($value);
            case 'url':
                return esc_url_raw($value);
            case 'boolean':
                return !empty($value) ? '1' : '0';
            case 'image':
            case 'file':
                return absint($value) ?: '';
            case 'repeater':
                if (!is_array($value))
                    return [];
                $sanitized_rows = [];
                $sub_schemas = [];
                foreach (($schema['sub_fields'] ?? []) as $sub)
                    $sub_schemas[$sub['name']] = $sub;

                foreach ($value as $row) {
                    if (!is_array($row))
                        continue;
                    $sanitized_row = [];
                    foreach ($sub_schemas as $sub_name => $sub_schema) {
                        $sanitized_row[$sub_name] = $this->sanitize_field_value($row[$sub_name] ?? null, $sub_schema);
                    }
                    $sanitized_rows[] = $sanitized_row;
                }
                return array_values($sanitized_rows);
            case 'group':
                if (!is_array($value))
                    return null;
                $sanitized_group = [];
                $sub_schemas = [];
                foreach (($schema['sub_fields'] ?? []) as $sub)
                    $sub_schemas[$sub['name']] = $sub;

                foreach ($sub_schemas as $sub_name => $sub_schema) {
                    $sanitized_group[$sub_name] = $this->sanitize_field_value($value[$sub_name] ?? null, $sub_schema);
                }
                return $sanitized_group;
            case 'gallery':
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                if (!is_array($value)) {
                    return null;
                }
                return array_filter(array_map('absint', $value));
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Enqueue media scripts and custom admin styles/scripts
     */
    public function enqueue_scripts($hook): void
    {
        // Robust screen check for post edit screens
        if (!is_admin())
            return;

        $screen = get_current_screen();
        if (!$screen)
            return;

        if ($screen->base !== 'post' && $screen->base !== 'post-new') {
            return;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
        }

        // We enqueue unconditionally on post screens because selectors are scoped to .cc-metabox
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue the core Javascript containing wp.media click listeners
        wp_enqueue_script('cc-admin-js');

        $plugin_root = dirname(dirname(dirname(__DIR__)));

        // Enqueue unified admin UI stylesheet.
        wp_enqueue_style('cc-admin-ui');

        // Debug diagnostic probe
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $probe_script = sprintf(
                "window.ContentCoreHealth = window.ContentCoreHealth || {};
                window.ContentCoreHealth.postEditMetaBoxCssLoaded = true;
                window.ContentCoreHealth.screen = %s;",
                wp_json_encode([
                    'id' => $screen->id,
                    'base' => $screen->base,
                    'post_type' => $screen->post_type
                ])
            );
            // Attach to jquery as it's a reliable dependency
            wp_add_inline_script('jquery', $probe_script);
        }

        // Inject dynamic repeater template data
        $script = "
        window.ccRepeaterTemplates = window.ccRepeaterTemplates || {};
        ";
        // We use jquery-ui-sortable as a guaranteed enqueued handle on post screens.
        wp_add_inline_script('jquery-ui-sortable', $script);
    }

    /**
     * Render the custom premium editor header
     */
    public function render_editor_header(\WP_Post $post): void
    {
        $groups = $this->get_field_groups_for_post_type($post->post_type);
        if (empty($groups)) {
            return;
        }

        $back_url = admin_url('edit.php?post_type=' . $post->post_type);
        $status = get_post_status($post->ID);
        if ('auto-draft' === $status) {
            $save_status = __('Not saved yet', 'content-core');
        } elseif ('publish' === $status) {
            $save_status = __('Published', 'content-core');
        } else {
            $save_status = __('Saved', 'content-core');
        }
        $header_languages = $this->get_editor_header_languages($post);

        echo '<div class="cc-editor-header v3-tighter">';
        echo '  <div class="cc-editor-topbar-main">';
        echo '    <a href="' . esc_url($back_url) . '" class="cc-editor-back">';
        echo '      ' . esc_html__('Back to Posts', 'content-core');
        echo '    </a>';
        echo '    <span class="cc-editor-save-status">' . esc_html($save_status) . '</span>';
        if (!empty($header_languages['languages']) && count($header_languages['languages']) > 1) {
            echo '    <div class="cc-editor-language-switcher">';
            echo '      <label class="screen-reader-text" for="cc-header-language-select">' . esc_html__('Current language', 'content-core') . '</label>';
            echo '      <select id="cc-header-language-select" class="cc-editor-lang-select" data-cc-header-language-select aria-label="' . esc_attr__('Current language', 'content-core') . '">';
            foreach ($header_languages['languages'] as $language) {
                $option_text = $language['flag'] !== '' ? $language['flag'] : strtoupper((string) $language['code']);
                $target_attr = '';
                if (!empty($language['target_url'])) {
                    $target_attr = ' data-target="' . esc_url($language['target_url']) . '"';
                }
                echo '        <option value="' . esc_attr($language['code']) . '"' . $target_attr . selected($header_languages['current'], $language['code'], false) . '>' . esc_html($option_text) . '</option>';
            }
            echo '      </select>';
            echo '    </div>';
        }
        echo '  </div>';
        echo '</div>';

        // Render right top controls as a sibling Piece for the right rail.
        $status_obj = get_post_status($post->ID);
        $is_published = 'publish' === $status_obj;
        $btn_label = $is_published ? __('Update', 'content-core') : __('Publish', 'content-core');

        echo '<div class="cc-editor-topbar-actions">';
        echo '  <div class="cc-editor-sidebar-actions">';
        echo '    <button type="button" class="cc-editor-btn cc-editor-btn-secondary cc-editor-preview-trigger">' . esc_html__('Preview', 'content-core') . '</button>';
        echo '    <button type="button" class="cc-editor-btn cc-editor-btn-primary cc-editor-publish-trigger">' . esc_html($btn_label) . '</button>';
        echo '  </div>';
        echo '</div>';
    }

    /**
     * Render top action controls inside the right sidebar rail.
     */
    public function render_sidebar_action_metabox(\WP_Post $post): void
    {
        $status = get_post_status($post->ID);
        $is_published = 'publish' === $status;
        $btn_label = $is_published ? __('Update', 'content-core') : __('Publish', 'content-core');

        echo '<div class="cc-editor-sidebar-actions">';
        echo '  <button type="button" class="cc-editor-btn cc-editor-btn-secondary cc-editor-preview-trigger">' . esc_html__('Preview', 'content-core') . '</button>';
        echo '  <button type="button" class="cc-editor-btn cc-editor-btn-primary cc-editor-publish-trigger">' . esc_html($btn_label) . '</button>';
        echo '</div>';
    }

    private function get_editor_header_languages(\WP_Post $post): array
    {
        $settings = get_option('cc_languages_settings', []);
        if (!is_array($settings) || empty($settings['enabled']) || empty($settings['languages']) || !is_array($settings['languages'])) {
            return ['current' => '', 'languages' => []];
        }

        $default_lang = sanitize_key((string) ($settings['default_lang'] ?? 'de'));
        $current_lang = sanitize_key((string) get_post_meta($post->ID, '_cc_language', true));
        if ($current_lang === '') {
            $current_lang = $default_lang;
        }
        $translations = $this->get_post_translations_map($post, $current_lang);

        $languages = [];
        foreach ($settings['languages'] as $language) {
            if (!is_array($language) || empty($language['code'])) {
                continue;
            }

            $code = sanitize_key((string) $language['code']);
            if ($code === '') {
                continue;
            }

            $label = trim((string) ($language['label'] ?? ''));
            if ($label === '') {
                $label = strtoupper($code);
            }

            $target_url = '';
            $target_post_id = isset($translations[$code]) ? (int) $translations[$code] : 0;

            if ($target_post_id > 0 && current_user_can('edit_post', $target_post_id)) {
                $target_url = (string) get_edit_post_link($target_post_id, 'url');
            } elseif ($post->ID > 0 && $code !== $current_lang && current_user_can('edit_post', $post->ID)) {
                $target_url = (string) add_query_arg([
                    'action' => 'cc_create_translation',
                    'post' => $post->ID,
                    'lang' => $code,
                    'nonce' => wp_create_nonce('cc_create_translation_' . $post->ID),
                ], admin_url('admin.php'));
            }

            $languages[] = [
                'code' => $code,
                'label' => $label,
                'flag' => $this->language_code_to_flag_emoji($code),
                'target_url' => $target_url,
            ];
        }

        return [
            'current' => $current_lang,
            'languages' => $languages,
        ];
    }

    private function get_post_translations_map(\WP_Post $post, string $current_lang): array
    {
        $map = [];
        $group_id = (string) get_post_meta($post->ID, '_cc_translation_group', true);

        if ($group_id !== '') {
            global $wpdb;

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT g.post_id, l.meta_value AS lang
                 FROM {$wpdb->postmeta} g
                 INNER JOIN {$wpdb->postmeta} l ON l.post_id = g.post_id AND l.meta_key = '_cc_language'
                 INNER JOIN {$wpdb->posts} p ON p.ID = g.post_id
                 WHERE g.meta_key = '_cc_translation_group'
                   AND g.meta_value = %s
                   AND p.post_status <> 'trash'",
                $group_id
            ));

            foreach ($results as $row) {
                $lang = sanitize_key((string) ($row->lang ?? ''));
                $post_id = isset($row->post_id) ? (int) $row->post_id : 0;
                if ($lang !== '' && $post_id > 0) {
                    $map[$lang] = $post_id;
                }
            }
        }

        if ($current_lang !== '' && !isset($map[$current_lang])) {
            $map[$current_lang] = (int) $post->ID;
        }

        return $map;
    }

    private function language_code_to_flag_emoji(string $language_code): string
    {
        $language_code = strtolower(trim($language_code));
        if ($language_code === '') {
            return '';
        }

        $parts = preg_split('/[-_]/', $language_code);
        $base_lang = $parts[0] ?? $language_code;
        $region = strtoupper($parts[1] ?? '');

        if ($region === '') {
            $map = [
                'de' => 'DE',
                'en' => 'GB',
                'fr' => 'FR',
                'it' => 'IT',
                'es' => 'ES',
                'pt' => 'PT',
                'nl' => 'NL',
                'da' => 'DK',
                'sv' => 'SE',
                'no' => 'NO',
                'fi' => 'FI',
                'pl' => 'PL',
                'cs' => 'CZ',
                'sk' => 'SK',
                'hu' => 'HU',
                'ro' => 'RO',
                'bg' => 'BG',
                'hr' => 'HR',
                'sl' => 'SI',
                'et' => 'EE',
                'lv' => 'LV',
                'lt' => 'LT',
                'el' => 'GR',
                'tr' => 'TR',
                'uk' => 'UA',
                'ru' => 'RU',
            ];
            $region = $map[$base_lang] ?? strtoupper(substr($base_lang, 0, 2));
        }

        if (!preg_match('/^[A-Z]{2}$/', $region)) {
            return '';
        }

        $first = ord($region[0]) + 127397;
        $second = ord($region[1]) + 127397;

        return html_entity_decode('&#' . $first . ';&#' . $second . ';', ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Suppress the "Add New" button on CC post type edit screens
     */
    public function suppress_add_new_button(): void
    {
        $screen = get_current_screen();
        if (!$screen || ($screen->base !== 'post' && $screen->base !== 'post-new')) {
            return;
        }

        // Only for CC post types (we can check if we have field groups for it)
        $groups = $this->get_field_groups_for_post_type($screen->post_type);
        if (!empty($groups)) {
            echo '<style>
                h1.wp-heading-inline, .page-title-action { display: none !important; }
                #poststuff { padding-top: 0; }
                .cc-editor-header { margin-bottom: 24px; }
            </style>';
        }
    }

    /**
     * Helper to get field groups for a post type (duplicated for simplicity or moved to a shared trait)
     */

    /**
     * Rename core WordPress metaboxes to match editorial vision.
     */
    public function rename_metaboxes(): void
    {
        global $wp_meta_boxes;
        $post_type = get_post_type();

        if ('referenz' !== $post_type) {
            return;
        }

        if (!isset($wp_meta_boxes['referenz']['side']) || !is_array($wp_meta_boxes['referenz']['side'])) {
            return;
        }

        // Hide SEO Metabox (Lives in dedicated page)
        remove_meta_box('cc_seo_meta_box', 'referenz', 'normal');

        // Rename 'Publish' to 'Post Settings'
        foreach (['high', 'core', 'default', 'low'] as $priority) {
            if (isset($wp_meta_boxes['referenz']['side'][$priority]['submitdiv'])) {
                $wp_meta_boxes['referenz']['side'][$priority]['submitdiv']['title'] = __('Post Settings', 'content-core');
            }
        }

        // Use taxonomy display name for the taxonomy metabox title (no forced "Summary").
        $taxonomy = get_taxonomy('referenzen');
        if ($taxonomy && !empty($taxonomy->labels->name)) {
            foreach (['high', 'core', 'default', 'low'] as $priority) {
                if (isset($wp_meta_boxes['referenz']['side'][$priority]['referenzendiv'])) {
                    $wp_meta_boxes['referenz']['side'][$priority]['referenzendiv']['title'] = $taxonomy->labels->name;
                }
            }
        }

        // Force sidebar order: taxonomy -> language -> post settings.
        $ordered_ids = ['cc-editor-actions-top', 'referenzendiv', 'cc-language-box', 'submitdiv'];
        $collected = [];

        foreach (['high', 'core', 'default', 'low'] as $priority) {
            if (!isset($wp_meta_boxes['referenz']['side'][$priority]) || !is_array($wp_meta_boxes['referenz']['side'][$priority])) {
                continue;
            }

            foreach ($ordered_ids as $id) {
                if (isset($wp_meta_boxes['referenz']['side'][$priority][$id])) {
                    $collected[$id] = $wp_meta_boxes['referenz']['side'][$priority][$id];
                    unset($wp_meta_boxes['referenz']['side'][$priority][$id]);
                }
            }
        }

        if (!isset($wp_meta_boxes['referenz']['side']['high']) || !is_array($wp_meta_boxes['referenz']['side']['high'])) {
            $wp_meta_boxes['referenz']['side']['high'] = [];
        }

        $existing_high = $wp_meta_boxes['referenz']['side']['high'];
        $wp_meta_boxes['referenz']['side']['high'] = [];

        foreach ($ordered_ids as $id) {
            if (isset($collected[$id])) {
                $wp_meta_boxes['referenz']['side']['high'][$id] = $collected[$id];
            }
        }

        foreach ($existing_high as $id => $box) {
            $wp_meta_boxes['referenz']['side']['high'][$id] = $box;
        }
    }

    /**
     * Keep the side column order stable even when a user has older saved drag-order prefs.
     *
     * @param mixed $result
     * @param string $option
     * @param mixed $user
     * @return mixed
     */
    public function enforce_referenz_sidebar_order($result, string $option = '', $user = null)
    {
        if (!is_admin()) {
            return $result;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || ($screen->base !== 'post' && $screen->base !== 'post-new')) {
            return $result;
        }

        if ($screen->post_type !== 'referenz') {
            return $result;
        }

        $required = ['cc-editor-actions-top', 'referenzendiv', 'cc-language-box', 'submitdiv'];

        if (!is_array($result)) {
            $result = [];
        }

        $existing_side = [];
        if (isset($result['side']) && is_string($result['side']) && trim($result['side']) !== '') {
            $existing_side = array_values(array_filter(array_map('trim', explode(',', $result['side']))));
        }

        $existing_side = array_values(array_diff($existing_side, $required));
        $result['side'] = implode(',', array_merge($required, $existing_side));

        return $result;
    }
}

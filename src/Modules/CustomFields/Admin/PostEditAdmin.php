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
        add_action('add_meta_boxes', [$this, 'remove_legacy_acf_meta_boxes'], 999, 2);
        add_action('save_post', [$this, 'save_post_meta'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
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
            add_meta_box(
                'cc_group_' . $group->ID,
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
        if (!$group_id) {
            return;
        }

        static $nonce_rendered = false;
        if (!$nonce_rendered) {
            wp_nonce_field('save_cc_post_meta', 'cc_post_meta_nonce');
            $nonce_rendered = true;
        }

        $fields = get_post_meta($group_id, '_cc_fields', true);
        if (!is_array($fields) || empty($fields)) {
            echo '<p>' . esc_html__('No fields defined for this group.', 'content-core') . '</p>';
            return;
        }

        $group = get_post($group_id);
        $title = $group ? $group->post_title : '';
        $description = $group ? $group->post_content : '';

        // Debug marker
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo "\n<!-- Content Core metabox UI: enabled -->\n";
        }

        echo '<div class="cc-metabox">';
        echo '<div class="cc-metabox-header">';

        // Internal title is omitted because it duplicates the WP meta box title
        // unless we want it as a sub-header. User asked to avoid duplication.

        if ($description) {
            echo '<div class="cc-metabox-desc">' . wp_kses_post($description) . '</div>';
        }
        echo '</div>'; // .cc-metabox-header
        echo '<div class="cc-metabox-body">';

        $section_index = 0;

        foreach ($fields as $index => $field) {
            $type = $field['type'] ?? '';

            if ($type === 'section' || $type === 'ui_section') {
                $this->render_section_start($field, $group_id, $section_index);

                // Render tree children
                if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                    foreach ($field['sub_fields'] as $child_field) {
                        $this->render_field($post->ID, $child_field);
                    }
                }

                echo '</div></div>'; // Close section body and section wrapper
                $section_index++;
            }
            else {
                // Render root-level fields natively without artificial section wrappers
                $this->render_field($post->ID, $field);
            }
        }

        echo '</div>'; // .cc-metabox-body
        echo '</div>'; // .cc-metabox
    }

    /**
     * Render the start of a section
     */
    private function render_section_start(array $field, int $group_id, int $index): void
    {
        $label = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $style = $field['style'] ?? 'default';
        $collapsible = !empty($field['collapsible']);
        $default_state = $field['default_state'] ?? 'expanded';

        $section_id = $GLOBALS['post']->ID . '_' . absint($group_id) . '_' . $index;
        $classes = ['cc-section', 'cc-section-style-' . esc_attr($style)];
        if ($collapsible) {
            $classes[] = 'is-collapsible';
        }
        if ($default_state === 'expanded') {
            $classes[] = 'default-open';
        }

        echo '<div class="' . esc_attr(implode(' ', $classes)) . '" data-section-id="' . esc_attr($section_id) . '">';
        echo '<div class="cc-section-header">';
        echo '<div class="cc-section-title-wrap">';
        if ($label) {
            echo '<h3 class="cc-section-title">' . esc_html($label) . '</h3>';
        }
        if ($description) {
            echo '<p class="cc-section-description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
        if ($collapsible) {
            echo '<span class="cc-section-toggle-icon dashicons dashicons-arrow-down-alt2"></span>';
        }
        echo '</div>';
        echo '<div class="cc-section-body">';
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
        $field_desc = $field['description'] ?? ''; // Added missing description variable
        $req_mark = $required ? '<span class="cc-required-mark">*</span>' : ''; // Added missing req_mark variable

        if (!$name) {
            return;
        }

        // Retrieve the current value, fallback to default if not set
        $current_value = get_post_meta($post_id, $name, true);
        if ('' === $current_value && !empty($default_value)) {
            $current_value = $default_value;
        }

        $field_id = 'cc_field_' . esc_attr($name);
        $field_name = 'cc_meta[' . esc_attr($name) . ']';
        $req_attr = $required ? 'required="required"' : '';
        $compact_class = ($type === 'boolean') ? ' cc-field-compact' : '';
        $media_class = in_array($type, ['image', 'file', 'gallery']) ? ' cc-field-media' : '';

        echo '<div class="cc-field-row cc-field-type-' . esc_attr($type) . $compact_class . $media_class . '">';
        echo '<div class="cc-field-label">';
        echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . $req_mark . '</label>';
        echo '</div>';
        echo '<div class="cc-field-control">';

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
                echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0">';
                echo '<label class="cc-checkbox-label">';
                echo '<input type="checkbox" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" value="1" ' . $checked . ' ' . $req_attr . '> ';
                echo esc_html__('Yes', 'content-core');
                echo '</label>';
                break;
            case 'image':
            case 'file':
                $this->render_media_field($field_name, $field_id, $current_value, $type);
                break;
            case 'gallery':
                // Decode gallery JSON if stored as string
                $gallery_ids = $current_value;
                if (is_string($gallery_ids)) {
                    $gallery_ids = json_decode($gallery_ids, true);
                }
                $this->render_gallery_field($field_name, $field_id, is_array($gallery_ids) ? $gallery_ids : []);
                break;
            case 'repeater':
                $sub_fields = $field['sub_fields'] ?? [];
                $encoded_sub_fields = htmlspecialchars(wp_json_encode($sub_fields), ENT_QUOTES, 'UTF-8');

                // Decode JSON if stored as string
                $rows = $current_value;
                if (is_string($rows)) {
                    $rows = json_decode($rows, true);
                }
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
            case 'group':
                $sub_fields = $field['sub_fields'] ?? [];
                $this->render_group_field($post_id, $name, $sub_fields);
                break;
            default:
                echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($current_value) . '" ' . $req_attr . '>';
                break;
        }

        if ($field_desc) {
            echo '<p class="description">' . esc_html($field_desc) . '</p>';
        }

        echo '</div>'; // .cc-field-control
        echo '</div>'; // .cc-field-row
    }

    /**
     * Render a media (image/file) upload field
     */
    private function render_media_field(string $field_name, string $field_id, $value, string $type): void
    {
        $bt = 'image' === $type ? __('Select Image', 'content-core') : __('Select File', 'content-core');
        echo '<div class="cc-media-uploader" data-type="' . esc_attr($type) . '">';
        echo '<input type="hidden" name="' . esc_attr($field_name) . '" id="' . esc_attr($field_id) . '" value="' . esc_attr($value) . '" class="cc-media-id-input">';
        echo '<div class="cc-media-preview">';
        if ($value) {
            if ('image' === $type) {
                echo wp_get_attachment_image($value, 'large');
            }
            else {
                $url = wp_get_attachment_url($value);
                echo '<div class="cc-media-filename">' . esc_html(basename($url)) . '</div>';
            }
        }
        echo '</div>';
        echo '<div class="cc-media-actions" style="margin-top: 10px;">';
        echo '<button type="button" class="button cc-media-upload-btn">' . esc_html($bt) . '</button> ';
        echo '<button type="button" class="button cc-media-remove-btn" style="' . (!$value ? 'display:none;' : '') . '">' . esc_html__('Remove', 'content-core') . '</button>';
        echo '</div>';
        echo '</div>';
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
        echo '<div class="cc-gallery-list">';
        foreach ($ids as $id) {
            $thumb = wp_get_attachment_image_src($id, 'thumbnail');
            if ($thumb) {
                echo '<div class="cc-gallery-item" data-id="' . esc_attr($id) . '">';
                echo '<img src="' . esc_url($thumb[0]) . '" />';
                echo '<button type="button" class="cc-gallery-remove">&times;</button>';
                echo '</div>';
            }
        }
        echo '</div>';
        echo '<button type="button" class="button cc-gallery-add-btn">' . esc_html__('Add Images', 'content-core') . '</button>';
        echo '</div>';
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
                $first_val = (string)$row_data[$sf['name']];
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
            $page_template = (string)get_post_meta($post_id, '_wp_page_template', true);
        }

        $context = [
            'post_id' => $post_id,
            'post_type' => $post_type,
            'page_template' => $page_template,
            'taxonomy_terms' => $post_id > 0 ?FieldRegistry::get_context_taxonomy_terms($post_id) : [],
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
                error_log(sprintf('Content Core Save: No cc_meta found in request for Post %d', $post_id));
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
            error_log(sprintf('Content Core Save: Processing %d fields for Post %d', count($_POST['cc_meta']), $post_id));
        }

        foreach ($_POST['cc_meta'] as $name => $value) {
            if (!isset($valid_fields[$name]))
                continue;

            $sanitized = $this->sanitize_field_value($value, $valid_fields[$name]);

            if (null === $sanitized || '' === $sanitized || (is_array($sanitized) && empty($sanitized))) {
                delete_post_meta($post_id, $name);
            }
            else {
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
                return is_numeric($value) ? (string)($value + 0) : '';
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
            error_log('Content Core: Enqueueing assets for Post Edit screen');
        }

        // We enqueue unconditionally on post screens because selectors are scoped to .cc-metabox
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue the core Javascript containing wp.media click listeners
        wp_enqueue_script('cc-admin-js');

        $plugin_root = dirname(dirname(dirname(__DIR__)));

        // Enqueue legacy/base post-edit styles (already registered in AdminMenu)
        wp_enqueue_style('cc-post-edit');

        // Enqueue the improved client UI styles (already registered in AdminMenu)
        wp_enqueue_style('cc-metabox-ui');

        // Debug diagnostic probe
        if (defined('WP_DEBUG') && WP_DEBUG) {
            wp_add_inline_style('cc-metabox-ui', '/* Content Core metabox UI Loaded */ .cc-metabox-debug { display: none; }');

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
        // We use jquery-ui-sortable as the handle here or another enqueued script
        // In the original it was cc-admin-modern but that's only on cc pages.
        // Let's use jquery as the base for this inline script too if needed.
        wp_add_inline_script('jquery-ui-sortable', $script);
    }
}
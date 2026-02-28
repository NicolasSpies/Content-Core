<?php
namespace ContentCore\Modules\OptionsPages\Admin;

use ContentCore\Modules\OptionsPages\Data\OptionsPagePostType;
use ContentCore\Modules\CustomFields\Data\FieldRegistry;

class OptionsPageAdmin
{

    /**
     * Register hooks for the Options Page UI
     */
    public function register(): void
    {
        // add_action('admin_menu', [$this, 'register_admin_menus'], 60);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Query all published cc_options_page CPTs and dynamically create submenus for them
     */
    public function register_admin_menus(): void
    {
        $options_pages = get_posts([
            'post_type' => OptionsPagePostType::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($options_pages as $page) {
            $slug = $page->post_name;
            $menu_slug = 'cc-options-' . $slug;

            add_submenu_page(
                'cc-settings-hub',
                esc_html($page->post_title),
                esc_html($page->post_title),
                'manage_options',
                $menu_slug,
                function () use ($page, $slug) {
                    $this->render_options_page($page, $slug);
                }
            );
        }
    }

    /**
     * Render the UI for the specific options page
     */
    private function render_options_page(\WP_Post $page, string $slug): void
    {
        if (isset($_POST['cc_options_nonce']) && wp_verify_nonce($_POST['cc_options_nonce'], 'save_cc_options_' . $slug)) {
            $this->save_options($slug);
        }

        $assignment_key = 'cc_option_page_' . $slug;
        $context = ['options_page' => $assignment_key];
        $groups = FieldRegistry::get_field_groups($context);

        $fields = [];
        foreach ($groups as $group) {
            $group_fields = get_post_meta($group->ID, '_cc_fields', true);
            if (is_array($group_fields)) {
                foreach ($group_fields as $f) {
                    if (!empty($f['name'])) {
                        $fields[$f['name']] = $f;
                    }
                }
            }
        }

        echo '<div class="content-core-admin wrap">';
        echo '<div class="cc-header"><h1>' . esc_html($page->post_title) . '</h1></div>';

        if (empty($fields)) {
            echo '<div class="cc-card"><p>' . esc_html__('No fields are assigned to this options page yet.', 'content-core') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<form method="post" action="">';
        wp_nonce_field('save_cc_options_' . $slug, 'cc_options_nonce');

        foreach ($groups as $group) {
            $group_fields = get_post_meta($group->ID, '_cc_fields', true);
            if (!is_array($group_fields) || empty($group_fields)) {
                continue;
            }

            echo '<div class="cc-group-wrap" style="margin-bottom: 40px;">';
            echo '<h2 style="font-size: 1.5rem; margin-bottom: 20px; border-bottom: 2px solid #ccc; padding-bottom: 10px;">' . esc_html($group->post_title) . '</h2>';
            echo '<div class="cc-fields-container">';

            $in_section = false;
            $section_index = 0;

            foreach ($group_fields as $field) {
                if ($field['type'] === 'section') {
                    if ($in_section) {
                        echo '</div></div>'; // Close content and section div
                    }
                    $this->render_section_start($field, $slug, (int) $group->ID, $section_index);
                    $in_section = true;
                    $section_index++;
                } else {
                    $this->render_field($field, $slug);
                }
            }

            if ($in_section) {
                echo '</div></div>';
            }

            echo '</div>'; // .cc-fields-container
            echo '</div>'; // .cc-group-wrap
        }

        submit_button(__('Save Changes', 'content-core'), 'primary cc-button-save');
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render a single field based on its type
     */
    private function render_field(array $field, string $slug): void
    {
        $name = $field['name'] ?? '';
        $label = $field['label'] ?? '';
        $type = $field['type'] ?? 'text';
        $required = $field['required'] ?? false;
        $default_value = $field['default_value'] ?? '';

        if (!$name) {
            return;
        }

        $option_key = 'cc_option_' . $slug . '_' . $name;
        $current_value = get_option($option_key, '');
        if ('' === $current_value)
            $current_value = $default_value;

        $field_id = 'cc_field_' . esc_attr($name);
        $field_name = 'cc_options[' . esc_attr($name) . ']';
        $req_attr = $required ? 'required="required"' : '';
        $req_mark = $required ? ' <span class="cc-required-mark" title="' . esc_attr__('Required', 'content-core') . '">*</span>' : '';
        $description = $field['description'] ?? '';

        echo '<div class="cc-field-row cc-field-type-' . esc_attr($type) . '">';
        echo '<div class="cc-field-label">';
        echo '<label for="' . esc_attr($field_id) . '">' . esc_html($label) . $req_mark . '</label>';
        echo '</div>';
        echo '<div class="cc-field-input">';

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
                echo '<label class="cc-checkbox-label">';
                echo '<input type="hidden" name="' . esc_attr($field_name) . '" value="0">';
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

                echo '<div class="cc-repeater-container" data-id="' . esc_attr($name) . '" data-sub-fields="' . $encoded_sub_fields . '" data-field-type="options">';
                echo '<div class="cc-repeater-rows">';
                foreach ($rows as $index => $row_data) {
                    $this->render_repeater_row($name, $index, $sub_fields, $row_data);
                }
                echo '</div>';
                echo '<div class="cc-repeater-footer">';
                echo '<button type="button" class="button cc-add-repeater-row-btn">' . esc_html__('+ Add Row', 'content-core') . '</button>';
                echo '</div>';
                echo '</div>';
                break;
            case 'group':
                $sub_fields = $field['sub_fields'] ?? [];
                $this->render_group_field($name, $sub_fields, $slug);
                break;
            default:
                echo '<input type="text" id="' . esc_attr($field_id) . '" name="' . esc_attr($field_name) . '" class="cc-input-full" value="' . esc_attr($current_value) . '" ' . $req_attr . '>';
                break;
        }

        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }

        echo '</div>'; // .cc-field-input
        echo '</div>'; // .cc-field-row
    }

    /**
     * Render the start of a section
     */
    private function render_section_start(array $field, string $slug, int $group_id, int $index): void
    {
        $label = $field['label'] ?? '';
        $description = $field['description'] ?? '';
        $style = $field['style'] ?? 'default';
        $collapsible = !empty($field['collapsible']);
        $default_state = $field['default_state'] ?? 'expanded';

        // Unique ID for options page sections
        $section_id = 'opt_' . esc_attr($slug) . '_' . absint($group_id) . '_' . $index;

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
        echo '<div class="cc-section-content" style="padding: 20px;">';
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
                echo wp_get_attachment_image($value, 'thumbnail');
            } else {
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
        echo '<span class="cc-repeater-row-title">' . esc_html($row_title) . '</span>';
        echo '<div class="cc-repeater-row-actions">';
        echo '<button type="button" class="cc-repeater-row-toggle" title="' . esc_attr__('Toggle Row', 'content-core') . '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
        echo '<button type="button" class="cc-repeater-row-remove" title="' . esc_attr__('Remove Row', 'content-core') . '"><span class="dashicons dashicons-trash"></span></button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="cc-repeater-row-content" style="display:none;">';

        foreach ($sub_fields as $sub) {
            $sub_name = $sub['name'] ?? '';
            $sub_label = $sub['label'] ?? '';
            $sub_type = $sub['type'] ?? 'text';
            $field_val = $row_data[$sub_name] ?? '';
            $field_id = 'cc_field_' . esc_attr($parent_name) . '_' . $index . '_' . esc_attr($sub_name);
            $field_name = 'cc_options[' . esc_attr($parent_name) . '][' . $index . '][' . esc_attr($sub_name) . ']';

            echo '<div class="cc-field-row cc-field-type-' . esc_attr($sub_type) . '">';
            echo '<div class="cc-field-label"><label for="' . esc_attr($field_id) . '">' . esc_html($sub_label) . '</label></div>';
            echo '<div class="cc-field-input">';

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
    private function render_group_field(string $name, array $sub_fields, string $slug): void
    {
        $option_key = 'cc_option_' . $slug . '_' . $name;
        $raw_value = get_option($option_key, []);
        $group_data = $raw_value;
        if (is_string($group_data)) {
            $group_data = json_decode($group_data, true);
        }
        $group_data = is_array($group_data) ? $group_data : [];

        echo '<div class="cc-group-container">';

        foreach ($sub_fields as $sub) {
            $sub_name = $sub['name'] ?? '';
            $sub_label = $sub['label'] ?? '';
            $sub_type = $sub['type'] ?? 'text';
            $field_val = $group_data[$sub_name] ?? '';

            $field_id = 'cc_field_' . esc_attr($name) . '_' . esc_attr($sub_name);
            // Storage key in cc_options is cc_options[group_name][child_name]
            $field_name = 'cc_options[' . esc_attr($name) . '][' . esc_attr($sub_name) . ']';

            echo '<div class="cc-field-row cc-field-type-' . esc_attr($sub_type) . '">';
            echo '<div class="cc-field-label"><label for="' . esc_attr($field_id) . '">' . esc_html($sub_label) . '</label></div>';
            echo '<div class="cc-field-input">';

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
     * Save the options values
     */
    private function save_options(string $slug): void
    {
        if (!current_user_can('manage_options'))
            return;
        if (!isset($_POST['cc_options']) || !is_array($_POST['cc_options']))
            return;

        $assignment_key = 'cc_option_page_' . $slug;
        $fields = FieldRegistry::get_fields_for_post_type($assignment_key);

        foreach ($_POST['cc_options'] as $name => $value) {
            if (!isset($fields[$name]))
                continue;

            $sanitized = $this->sanitize_field_value($value, $fields[$name]);
            $option_key = 'cc_option_' . $slug . '_' . $name;

            if (null === $sanitized || '' === $sanitized || (is_array($sanitized) && empty($sanitized))) {
                delete_option($option_key);
            } else {
                // Store structural fields as JSON strings
                if (in_array($fields[$name]['type'], ['repeater', 'group', 'gallery'])) {
                    $sanitized = wp_json_encode($sanitized);
                }
                update_option($option_key, $sanitized);
            }
        }

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Options saved.', 'content-core') . '</p></div>';
        });
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
        if (strpos($hook, 'cc-options-') === false) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue modern assets
        wp_enqueue_style(
            'cc-admin-modern',
            plugins_url('assets/css/admin.css', dirname(__DIR__, 4)),
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'cc-admin-modern',
            plugins_url('assets/js/admin.js', dirname(__DIR__, 4)),
            ['jquery', 'jquery-ui-sortable'],
            '1.0.0',
            true
        );

    }
}
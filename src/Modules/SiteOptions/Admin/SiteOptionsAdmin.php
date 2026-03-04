<?php
namespace ContentCore\Modules\SiteOptions\Admin;

use ContentCore\Modules\SiteOptions\SiteOptionsModule;

class SiteOptionsAdmin
{
    private SiteOptionsModule $module;

    public function __construct(SiteOptionsModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            null,
            __('Site Profile', 'content-core'),
            __('Site Profile', 'content-core'),
            'edit_posts',
            'cc-site-options',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook): void
    {
        $is_site_profile_page = strpos($hook, 'cc-site-options') !== false;
        $is_schema_page = strpos($hook, 'cc-site-profile-fields') !== false;

        if (!$is_site_profile_page && !$is_schema_page) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('cc-admin-ui');
        wp_enqueue_script('cc-admin-js');

        if ($is_schema_page) {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('cc-schema-editor');
            wp_localize_script('cc-schema-editor', 'ccSchemaEditorConfig', [
                'languages' => [],
                'singleLabel' => true,
                'strings' => [
                    'sectionTitle' => __('Group Title', 'content-core'),
                    'addField' => __('+ Add Field', 'content-core'),
                    'confirmRemoveSection' => __('Remove this entire group and all its fields?', 'content-core'),
                    'stableKey' => __('Stable Key', 'content-core'),
                    'type' => __('Type', 'content-core'),
                    'visible' => __('Visible', 'content-core'),
                    'editable' => __('Editable', 'content-core'),
                    'label' => __('Label', 'content-core'),
                    'confirmRemoveField' => __('Remove this field?', 'content-core'),
                ],
            ]);
        }
    }

    public function render_page(): void
    {
        if (!current_user_can('edit_posts')) {
            return;
        }

        // Handle Save
        if (isset($_POST['cc_site_options_nonce']) && wp_verify_nonce($_POST['cc_site_options_nonce'], 'cc_site_options_save')) {
            $this->save_options();
        }

        $schema = $this->module->get_schema();
        $options = $this->module->get_options();
        ?>
        <div class="wrap content-core-admin cc-site-options-page">
            <h1 class="wp-heading-inline"><?php _e('Site Profile', 'content-core'); ?></h1>
            <hr class="wp-header-end">

            <form method="post" action="" id="post">
                <?php wp_nonce_field('cc_site_options_save', 'cc_site_options_nonce'); ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <div class="cc-site-profile-sections">
                            <?php foreach ($schema as $section_id => $section):
                                $visible_fields = array_filter($section['fields'], function ($f) {
                                    return !isset($f['client_visible']) || $f['client_visible'];
                                });

                                if (empty($visible_fields))
                                    continue;
                                ?>
                                <div class="cc-card cc-site-profile-card">
                                    <h2 class="cc-site-profile-card-title">
                                        <?php echo esc_html($section['title']); ?>
                                    </h2>

                                    <div class="cc-options-grid">
                                        <?php foreach ($visible_fields as $field_id => $field):
                                            $value = $options[$field_id] ?? '';
                                            $is_editable = !isset($field['client_editable']) || $field['client_editable'];
                                            $field_label = is_array($field['label'] ?? null)
                                                ? (string) reset($field['label'])
                                                : (string) ($field['label'] ?? $field_id);
                                            $row_class = ($field['type'] === 'textarea') ? ' cc-option-row--full' : '';
                                            ?>
                                            <div class="cc-option-row<?php echo esc_attr($row_class); ?>">
                                                <label>
                                                    <?php echo esc_html($field_label); ?>
                                                    <?php if (!$is_editable): ?>
                                                        <span class="dashicons dashicons-lock cc-lock-icon"
                                                            title="<?php esc_attr_e('System-reserved (Read Only)', 'content-core'); ?>"></span>
                                                        <?php
                                                    endif; ?>
                                                </label>

                                                <?php $this->render_field($field_id, $field, $value, $is_editable); ?>
                                            </div>
                                            <?php
                                        endforeach; ?>
                                    </div>
                                </div>
                                <?php
                            endforeach; ?>
                            </div>
                            <div class="cc-site-profile-actions">
                                <input type="submit" name="save" id="publish"
                                    class="button button-primary button-large"
                                    value="<?php _e('Save Site Profile', 'content-core'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <?php
    }

    public function render_schema_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (
            isset($_POST['cc_site_options_schema_nonce']) &&
            wp_verify_nonce($_POST['cc_site_options_schema_nonce'], 'cc_site_options_schema_save')
        ) {
            if (isset($_POST['cc_reset_site_options_schema'])) {
                $this->module->reset_schema();
                wp_safe_redirect(add_query_arg(['page' => 'cc-site-profile-fields', 'cc_schema_saved' => 'reset'], admin_url('admin.php')));
                exit;
            }

            $raw_schema = isset($_POST['cc_site_options_schema']) && is_array($_POST['cc_site_options_schema'])
                ? $_POST['cc_site_options_schema']
                : [];
            $clean_schema = $this->sanitize_schema_input($raw_schema);
            $this->module->update_schema($clean_schema);

            wp_safe_redirect(add_query_arg(['page' => 'cc-site-profile-fields', 'cc_schema_saved' => 1], admin_url('admin.php')));
            exit;
        }

        if (!empty($_GET['cc_schema_saved'])) {
            add_action('admin_notices', function () {
                $is_reset = ($_GET['cc_schema_saved'] === 'reset');
                $message = $is_reset
                    ? __('Site Profile field groups reset to default.', 'content-core')
                    : __('Site Profile field groups saved.', 'content-core');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            });
        }

        $schema = $this->module->get_schema();
        ?>
        <div class="wrap content-core-admin cc-site-options-page cc-site-profile-schema-page">
            <h1 class="wp-heading-inline"><?php _e('Site Profile Field Groups', 'content-core'); ?></h1>
            <hr class="wp-header-end">

            <form method="post">
                <?php wp_nonce_field('cc_site_options_schema_save', 'cc_site_options_schema_nonce'); ?>
                <div class="cc-card cc-schema-shell">
                    <div class="cc-schema-shell-header">
                        <p><?php _e('Manage field groups and fields used in Site Profile.', 'content-core'); ?></p>
                        <button type="submit" name="cc_reset_site_options_schema" class="button"
                            onclick="return confirm('<?php echo esc_js(__('Reset Site Profile field groups to defaults? Existing saved values remain.', 'content-core')); ?>');">
                            <?php _e('Reset to Defaults', 'content-core'); ?>
                        </button>
                    </div>

                    <div id="cc-schema-editor">
                        <?php foreach ($schema as $section_id => $section): ?>
                            <div class="cc-schema-section cc-card" data-id="<?php echo esc_attr($section_id); ?>">
                                <div class="cc-schema-section__header">
                                    <span class="dashicons dashicons-menu"></span>
                                    <div class="cc-grow">
                                        <input type="text" name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][title]"
                                            value="<?php echo esc_attr($section['title'] ?? ''); ?>" class="large-text"
                                            placeholder="<?php esc_attr_e('Group title', 'content-core'); ?>">
                                    </div>
                                    <button type="button" class="button button-link-delete cc-remove-section">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>

                                <div class="cc-schema-fields">
                                    <?php foreach ($section['fields'] ?? [] as $field_id => $field): ?>
                                        <div class="cc-schema-field" data-id="<?php echo esc_attr($field_id); ?>">
                                            <span class="dashicons dashicons-menu"></span>
                                            <div class="cc-grow">
                                                <div class="cc-schema-grid-3">
                                                    <div>
                                                        <label class="cc-schema-field-label">
                                                            <?php _e('Stable Key', 'content-core'); ?>
                                                        </label>
                                                        <input type="text" value="<?php echo esc_attr($field_id); ?>"
                                                            class="regular-text cc-input-mono" readonly
                                                            disabled>
                                                    </div>
                                                    <div>
                                                        <label class="cc-schema-field-label">
                                                            <?php _e('Type', 'content-core'); ?>
                                                        </label>
                                                        <select
                                                            name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][type]">
                                                            <option value="text" <?php selected($field['type'] ?? 'text', 'text'); ?>>Text
                                                            </option>
                                                            <option value="email" <?php selected($field['type'] ?? '', 'email'); ?>>Email
                                                            </option>
                                                            <option value="url" <?php selected($field['type'] ?? '', 'url'); ?>>URL</option>
                                                            <option value="textarea" <?php selected($field['type'] ?? '', 'textarea'); ?>>
                                                                Textarea</option>
                                                            <option value="image" <?php selected($field['type'] ?? '', 'image'); ?>>Image/Logo
                                                            </option>
                                                        </select>
                                                    </div>
                                                    <div class="cc-schema-flags">
                                                        <label><input type="checkbox"
                                                                name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_visible]"
                                                                value="1" <?php checked(!empty($field['client_visible'])); ?>>
                                                            <?php _e('Visible', 'content-core'); ?>
                                                        </label>
                                                        <label><input type="checkbox"
                                                                name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][client_editable]"
                                                                value="1" <?php checked(!empty($field['client_editable'])); ?>>
                                                            <?php _e('Editable', 'content-core'); ?>
                                                        </label>
                                                    </div>
                                                </div>

                                                <div>
                                                    <label class="cc-schema-field-label">
                                                        <?php _e('Label', 'content-core'); ?>
                                                    </label>
                                                    <input type="text"
                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][label]"
                                                        value="<?php echo esc_attr(is_array($field['label'] ?? null) ? (string) reset($field['label']) : (string) ($field['label'] ?? '')); ?>">
                                                </div>
                                            </div>
                                            <button type="button" class="button button-link-delete cc-remove-field cc-btn-top-gap">
                                                <span class="dashicons dashicons-no-alt"></span>
                                            </button>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="button" class="button button-secondary cc-add-field"
                                        data-section="<?php echo esc_attr($section_id); ?>">
                                        <?php _e('+ Add Field', 'content-core'); ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <button type="button" class="button button-secondary cc-add-section">
                            <?php _e('+ Add Group', 'content-core'); ?>
                        </button>
                    </div>
                </div>

                <div class="cc-schema-submit">
                    <input type="submit" class="button button-primary button-large"
                        value="<?php echo esc_attr__('Save Field Groups', 'content-core'); ?>">
                </div>
            </form>
        </div>
        <?php
    }

    private function render_field(string $id, array $field, $value, bool $editable = true): void
    {
        $name = "cc_options[{$id}]";
        $attr = $editable ? '' : ' disabled readonly';
        $class = 'widefat' . ($editable ? '' : ' cc-readonly-field');

        switch ($field['type']) {
            case 'textarea':
                echo '<textarea name="' . esc_attr($name) . '" class="' . esc_attr($class) . '" rows="4"' . $attr . '>' . esc_textarea($value) . '</textarea>';
                break;
            case 'email':
                echo '<input type="email" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '"' . $attr . '>';
                break;
            case 'url':
                echo '<input type="url" name="' . esc_attr($name) . '" value="' . esc_url($value) . '" class="' . esc_attr($class) . '"' . $attr . '>';
                break;
            case 'image':
                $this->render_image_field($name, $value, $editable);
                break;
            default:
                echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '"' . $attr . '>';
                break;
        }
    }

    private function render_image_field(string $name, $value, bool $editable = true): void
    {
        $preview_url = $value ? wp_get_attachment_url($value) : '';
        ?>
        <div class="cc-media-uploader">
            <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>"
                class="cc-media-id-input">
            <div class="cc-media-preview">
                <?php if ($preview_url): ?>
                    <img src="<?php echo esc_url($preview_url); ?>" class="cc-media-preview-image">
                    <?php
                endif; ?>
            </div>
            <div class="cc-media-actions">
                <?php if ($editable): ?>
                    <button type="button" class="button cc-media-upload-btn">
                        <?php _e('Logo wählen', 'content-core'); ?>
                    </button>
                    <button type="button" class="button cc-media-remove-btn<?php echo $value ? '' : ' hidden'; ?>"<?php echo $value ? '' : ' hidden'; ?>>
                        <?php _e('Entfernen', 'content-core'); ?>
                    </button>
                    <?php
                else: ?>
                    <p class="description">
                        <?php _e('Cannot be changed.', 'content-core'); ?>
                    </p>
                    <?php
                endif; ?>
            </div>
        </div>
        <?php
    }

    private function save_options(): void
    {
        if (!isset($_POST['cc_options']) || !is_array($_POST['cc_options']))
            return;

        $schema = $this->module->get_schema();
        $existing_options = $this->module->get_options();
        $sanitized = [];

        foreach ($schema as $section) {
            foreach ($section['fields'] as $id => $field) {
                // Respect client_editable flag server-side
                if (isset($field['client_editable']) && !$field['client_editable']) {
                    if (isset($existing_options[$id])) {
                        $sanitized[$id] = $existing_options[$id];
                    }
                    continue;
                }

                if (!isset($_POST['cc_options'][$id])) {
                    if (isset($existing_options[$id])) {
                        $sanitized[$id] = $existing_options[$id];
                    }
                    continue;
                }

                $val = $_POST['cc_options'][$id];
                switch ($field['type']) {
                    case 'email':
                        $sanitized[$id] = sanitize_email($val);
                        break;
                    case 'url':
                        $sanitized[$id] = esc_url_raw($val);
                        break;
                    case 'textarea':
                        $sanitized[$id] = sanitize_textarea_field($val);
                        break;
                    case 'image':
                        $sanitized[$id] = max(0, (int) $val);
                        break;
                    default:
                        $sanitized[$id] = sanitize_text_field($val);
                        break;
                }
            }
        }

        update_option(\ContentCore\Modules\SiteOptions\SiteOptionsModule::DATA_OPTION, $sanitized);

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Site Profile saved.', 'content-core') . '</p></div>';
        });
    }

    private function sanitize_schema_input(array $raw_schema): array
    {
        $allowed_types = ['text', 'email', 'url', 'textarea', 'image'];
        $clean_schema = [];

        foreach ($raw_schema as $section_id => $section) {
            $section_key = sanitize_key((string) $section_id);
            if ($section_key === '') {
                continue;
            }

            $section_title = sanitize_text_field((string) ($section['title'] ?? $section_key));
            $section_fields = isset($section['fields']) && is_array($section['fields']) ? $section['fields'] : [];
            $clean_fields = [];

            foreach ($section_fields as $field_id => $field) {
                $field_key = sanitize_key((string) $field_id);
                if ($field_key === '') {
                    continue;
                }

                $label_source = $field['label'] ?? '';
                if (is_array($label_source)) {
                    $label_source = (string) reset($label_source);
                }
                $clean_label = sanitize_text_field((string) $label_source);
                if ($clean_label === '') {
                    $clean_label = ucwords(str_replace('_', ' ', $field_key));
                }

                $type = sanitize_key((string) ($field['type'] ?? 'text'));
                if (!in_array($type, $allowed_types, true)) {
                    $type = 'text';
                }

                $clean_fields[$field_key] = [
                    'label' => $clean_label,
                    'type' => $type,
                    'default' => '',
                    'client_visible' => !empty($field['client_visible']),
                    'client_editable' => !empty($field['client_editable']),
                ];
            }

            if (empty($clean_fields)) {
                continue;
            }

            $clean_schema[$section_key] = [
                'title' => $section_title,
                'fields' => $clean_fields,
            ];
        }

        return $clean_schema ?: $this->module->get_default_schema();
    }

}

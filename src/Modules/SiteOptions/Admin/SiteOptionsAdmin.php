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
        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_style('cc-metabox-ui');
        wp_enqueue_script('cc-admin-js');
        wp_add_inline_style('cc-admin-modern', $this->get_accent_inline_css());
        wp_add_inline_style('cc-admin-modern', $this->get_site_options_inline_css());

        if ($is_schema_page) {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_script('cc-schema-editor');
            wp_add_inline_style('cc-admin-modern', $this->get_site_profile_schema_inline_css());
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
                                <div class="cc-card"
                                    style="margin-bottom: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                                    <h2
                                        style="margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1; font-size: 1.3em;">
                                        <?php echo esc_html($section['title']); ?>
                                    </h2>

                                    <div class="cc-options-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                        <?php foreach ($visible_fields as $field_id => $field):
                                            $value = $options[$field_id] ?? '';
                                            $is_editable = !isset($field['client_editable']) || $field['client_editable'];
                                            $field_label = is_array($field['label'] ?? null)
                                                ? (string) reset($field['label'])
                                                : (string) ($field['label'] ?? $field_id);
                                            ?>
                                            <div class="cc-option-row"
                                                style="<?php echo $field['type'] === 'textarea' ? 'grid-column: span 2;' : ''; ?>">
                                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">
                                                    <?php echo esc_html($field_label); ?>
                                                    <?php if (!$is_editable): ?>
                                                        <span class="dashicons dashicons-lock"
                                                            style="font-size: 14px; color: #a0a5aa; vertical-align: middle;"
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

        <script>
            jQuery(document).ready(function ($) {
                // Simple Media Picker for logo_id
                $('.cc-media-upload-btn').on('click', function (e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var $container = $btn.closest('.cc-media-uploader');
                    var $input = $container.find('.cc-media-id-input');
                    var $preview = $container.find('.cc-media-preview');
                    var $removeBtn = $container.find('.cc-media-remove-btn');

                    var frame = wp.media({
                        title: 'Logo auswählen',
                        button: { text: 'Logo verwenden' },
                        multiple: false
                    });

                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first().toJSON();
                        $input.val(attachment.id);
                        $preview.html('<img src="' + attachment.url + '" style="max-height: 100px; display: block; margin-bottom: 10px;" />');
                        $removeBtn.show();
                    });

                    frame.open();
                });

                $('.cc-media-remove-btn').on('click', function () {
                    var $container = $(this).closest('.cc-media-uploader');
                    $container.find('.cc-media-id-input').val('');
                    $container.find('.cc-media-preview').empty();
                    $(this).hide();
                });
            });
        </script>
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
                            <div class="cc-schema-section cc-card"
                                style="background: #f8f9fa; margin-bottom: 20px; border: 1px solid #dcdcde;"
                                data-id="<?php echo esc_attr($section_id); ?>">
                                <div
                                    style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #dcdcde; padding-bottom: 15px;">
                                    <span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab;"></span>
                                    <div style="flex-grow: 1;">
                                        <input type="text" name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][title]"
                                            value="<?php echo esc_attr($section['title'] ?? ''); ?>" class="large-text"
                                            style="font-weight: 600;" placeholder="<?php esc_attr_e('Group title', 'content-core'); ?>">
                                    </div>
                                    <button type="button" class="button button-link-delete cc-remove-section">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </div>

                                <div class="cc-schema-fields" style="padding-left: 20px;">
                                    <?php foreach ($section['fields'] ?? [] as $field_id => $field): ?>
                                        <div class="cc-schema-field" data-id="<?php echo esc_attr($field_id); ?>"
                                            style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 15px; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px;">
                                            <span class="dashicons dashicons-menu"
                                                style="color: #a0a5aa; cursor: grab; margin-top: 8px;"></span>
                                            <div style="flex-grow: 1;">
                                                <div
                                                    style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                                                    <div>
                                                        <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                            <?php _e('Stable Key', 'content-core'); ?>
                                                        </label>
                                                        <input type="text" value="<?php echo esc_attr($field_id); ?>"
                                                            class="regular-text" style="width: 100%; font-family: monospace;" readonly
                                                            disabled>
                                                    </div>
                                                    <div>
                                                        <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                            <?php _e('Type', 'content-core'); ?>
                                                        </label>
                                                        <select
                                                            name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][type]"
                                                            style="width: 100%;">
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
                                                    <div style="display: flex; gap: 15px; align-items: center; padding-top: 20px;">
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
                                                    <label style="display: block; font-size: 11px; margin-bottom: 3px;">
                                                        <?php _e('Label', 'content-core'); ?>
                                                    </label>
                                                    <input type="text"
                                                        name="cc_site_options_schema[<?php echo esc_attr($section_id); ?>][fields][<?php echo esc_attr($field_id); ?>][label]"
                                                        value="<?php echo esc_attr(is_array($field['label'] ?? null) ? (string) reset($field['label']) : (string) ($field['label'] ?? '')); ?>"
                                                        style="width: 100%;">
                                                </div>
                                            </div>
                                            <button type="button" class="button button-link-delete cc-remove-field"
                                                style="margin-top: 5px;">
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

                <div style="margin-top:16px;">
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
                    <img src="<?php echo esc_url($preview_url); ?>" style="max-height: 100px; display: block; margin-bottom: 10px;">
                    <?php
                endif; ?>
            </div>
            <div class="cc-media-actions">
                <?php if ($editable): ?>
                    <button type="button" class="button cc-media-upload-btn">
                        <?php _e('Logo wählen', 'content-core'); ?>
                    </button>
                    <button type="button" class="button cc-media-remove-btn" style="<?php echo $value ? '' : 'display:none;'; ?>">
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

    private function get_accent_inline_css(): string
    {
        $accent = '#2271b1';
        $plugin = \ContentCore\Plugin::get_instance();
        $branding = $plugin->get_module('branding');
        if ($branding instanceof \ContentCore\Modules\Branding\BrandingModule) {
            $settings = $branding->get_settings();
            if (!empty($settings['custom_accent_color'])) {
                $candidate = sanitize_hex_color((string) $settings['custom_accent_color']);
                if ($candidate) {
                    $accent = $candidate;
                }
            }
        }

        [$r, $g, $b] = $this->hex_to_rgb($accent);
        return sprintf(':root{--cc-accent-color:%1$s;--cc-accent-rgb:%2$d,%3$d,%4$d;}', esc_attr($accent), $r, $g, $b);
    }

    private function hex_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return [34, 113, 177];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
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

    private function get_site_options_inline_css(): string
    {
        return '
        .cc-site-options-page{
            max-width:none!important;
            width:100%!important;
            margin-left:0!important;
            margin-right:0!important;
            padding-right:0!important;
        }
        .cc-site-options-page:not(.cc-site-profile-schema-page){
            width:calc(100% - 20px)!important;
            max-width:none!important;
            margin-right:20px!important;
            padding-right:0!important;
        }
        .cc-site-options-page:not(.cc-site-profile-schema-page) #poststuff,
        .cc-site-options-page:not(.cc-site-profile-schema-page) #post-body,
        .cc-site-options-page:not(.cc-site-profile-schema-page) #post-body-content{
            width:100%!important;
            max-width:none!important;
            margin:0!important;
        }
        .cc-site-options-page:not(.cc-site-profile-schema-page) #post-body{
            min-height:auto!important;
        }
        .cc-site-options-page #poststuff{padding-top:10px;}
        .cc-site-options-page #post-body.columns-2{display:grid;grid-template-columns:1fr;gap:18px;}
        .cc-site-options-page #post-body.columns-2 #post-body-content{float:none!important;width:auto!important;margin:0!important;}
        .cc-site-options-page #post-body-content{display:flex;flex-direction:column;gap:16px;}
        .cc-site-options-page .cc-site-profile-sections{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:16px;
            align-items:start;
        }
        .cc-site-options-page #post-body-content .cc-card{
            margin:0!important;
            padding:18px!important;
            background:var(--cc-bg-card)!important;
            border:1px solid var(--cc-border)!important;
            border-radius:var(--cc-radius)!important;
            box-shadow:var(--cc-shadow)!important;
            overflow:hidden;
            width:auto!important;
            min-width:0;
        }
        .cc-site-options-page #post-body-content .cc-card h2{
            margin:0 0 14px 0!important;
            padding:0 0 10px 0!important;
            border-bottom:1px solid var(--cc-border-light)!important;
            font-size:15px!important;
            letter-spacing:.04em!important;
            text-transform:uppercase!important;
            font-weight:700!important;
            color:var(--cc-text)!important;
        }
        .cc-site-options-page .cc-options-grid{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:16px!important;}
        .cc-site-options-page .cc-option-row{min-width:0;}
        .cc-site-options-page .cc-option-row[style*=\"grid-column: span 2\"]{grid-column:1 / -1;}
        .cc-site-options-page .cc-option-row label{display:block!important;font-weight:700!important;margin:0 0 8px 0!important;font-size:14px;}
        .cc-site-options-page .description{margin-top:6px!important;color:var(--cc-text-muted)!important;font-style:italic!important;}
        .cc-site-options-page .cc-site-profile-actions{
            display:flex;
            justify-content:flex-start;
            margin-top:8px;
        }
        .cc-site-options-page .cc-site-profile-actions .button-primary{
            width:auto!important;
            min-width:220px;
        }
        .cc-site-options-page .postbox{
            border:1px solid var(--cc-border)!important;
            border-radius:var(--cc-radius)!important;
            box-shadow:var(--cc-shadow)!important;
            overflow:hidden;
            margin:0!important;
            background:var(--cc-bg-card)!important;
        }
        .cc-site-options-page .postbox .hndle{
            border-bottom:1px solid var(--cc-border-light)!important;
            padding:12px 14px!important;
            font-size:14px!important;
            text-transform:uppercase!important;
            letter-spacing:.04em!important;
            color:var(--cc-text)!important;
            background:var(--cc-bg-soft)!important;
        }
        .cc-site-options-page .postbox .inside{padding:14px!important;margin:0!important;background:var(--cc-bg-card);}
        .cc-site-options-page .widefat,
        .cc-site-options-page input[type=\"text\"],
        .cc-site-options-page input[type=\"email\"],
        .cc-site-options-page input[type=\"url\"],
        .cc-site-options-page textarea,
        .cc-site-options-page select{
            border:1px solid var(--cc-border)!important;
            border-radius:10px!important;
            min-height:42px;
            background:#fff;
            padding:8px 12px;
            box-shadow:none!important;
        }
        .cc-site-options-page input[type=\"text\"]:focus,
        .cc-site-options-page input[type=\"email\"]:focus,
        .cc-site-options-page input[type=\"url\"]:focus,
        .cc-site-options-page textarea:focus,
        .cc-site-options-page select:focus{
            border-color:var(--cc-accent-color)!important;
            box-shadow:0 0 0 3px rgba(var(--cc-accent-rgb), .14)!important;
            outline:none!important;
        }
        .cc-site-options-page textarea{min-height:110px;resize:vertical;}
        .cc-site-options-page .button{
            border-radius:10px!important;
            min-height:38px!important;
            padding:0 14px!important;
            font-weight:600!important;
            box-shadow:none!important;
            transition:all .16s ease!important;
        }
        .cc-site-options-page .button:not(.button-primary){
            border:1px solid var(--cc-border)!important;
            background:var(--cc-bg-soft)!important;
            color:var(--cc-text)!important;
        }
        .cc-site-options-page .button:not(.button-primary):hover{
            border-color:var(--cc-accent-color)!important;
            color:var(--cc-accent-color)!important;
            background:#fff!important;
        }
        .cc-site-options-page .button-primary{
            background:var(--cc-accent-color)!important;
            border-color:var(--cc-accent-color)!important;
            color:#fff!important;
            border-radius:10px!important;
            min-height:44px!important;
            padding:0 18px!important;
            font-weight:700!important;
            box-shadow:none!important;
        }
        .cc-site-options-page .button-primary:hover,
        .cc-site-options-page .button-primary:focus{
            filter:brightness(1.04)!important;
            background:var(--cc-accent-color)!important;
            border-color:var(--cc-accent-color)!important;
            color:#fff!important;
        }
        .cc-site-options-page #publishing-action{padding:0!important;text-align:left!important;}
        .cc-site-options-page #publishing-action .button-primary{width:100%;}
        .cc-site-options-page .button-small{min-height:32px!important;}
        .cc-site-options-page #major-publishing-actions{border:0!important;background:transparent!important;}
        @media (max-width: 1024px){
            .cc-site-options-page .cc-site-profile-sections{grid-template-columns:1fr;}
        }';
    }

    private function get_site_profile_schema_inline_css(): string
    {
        return '
        .cc-site-profile-schema-page{
            width:100%!important;
            max-width:960px!important;
            margin-left:auto!important;
            margin-right:auto!important;
            padding:6px 0 18px 0!important;
        }
        .cc-site-profile-schema-page h1.wp-heading-inline{
            margin-bottom:12px!important;
        }
        .cc-site-profile-schema-page .cc-schema-shell{
            margin:0!important;
            padding:8px 0 0!important;
            background:transparent!important;
            border:0!important;
            border-radius:0!important;
            box-shadow:none!important;
        }
        .cc-site-profile-schema-page .cc-schema-shell-header{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            margin:0 0 20px 0;
            padding:0 6px;
        }
        .cc-site-profile-schema-page .cc-schema-shell-header p{
            margin:0;
            color:var(--cc-text-muted);
            font-size:14px;
        }
        .cc-site-profile-schema-page #cc-schema-editor{
            display:flex;
            flex-direction:column;
            gap:20px;
            width:100%;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-card{
            margin:0!important;
            border:1px solid var(--cc-border)!important;
            border-radius:14px!important;
            box-shadow:none!important;
            background:var(--cc-bg-soft)!important;
            overflow:hidden!important;
            width:100%!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-schema-section > div:first-child{
            background:var(--cc-bg-card)!important;
            padding:16px 18px!important;
            border-bottom:1px solid var(--cc-border-light)!important;
            margin:0!important;
            gap:14px!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-schema-fields{
            padding:20px!important;
            display:flex;
            flex-direction:column;
            gap:14px;
            background:var(--cc-bg-soft)!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-schema-field{
            margin:0!important;
            border:1px solid var(--cc-border)!important;
            border-radius:12px!important;
            background:#fff!important;
            padding:16px!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-schema-field > div{
            width:100%;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-schema-field label{
            font-size:12px!important;
            text-transform:uppercase!important;
            letter-spacing:.04em!important;
            color:var(--cc-text-muted)!important;
            font-weight:700!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor input[type=\"text\"],
        .cc-site-profile-schema-page #cc-schema-editor select{
            min-height:42px!important;
            border:1px solid var(--cc-border)!important;
            border-radius:10px!important;
            background:#fff!important;
            box-shadow:none!important;
            padding:10px 12px!important;
            color:var(--cc-text)!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor input[type=\"text\"]:focus,
        .cc-site-profile-schema-page #cc-schema-editor select:focus{
            border-color:var(--cc-accent-color)!important;
            box-shadow:0 0 0 3px rgba(var(--cc-accent-rgb), .14)!important;
            outline:none!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .dashicons-menu{
            color:var(--cc-text-muted)!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-remove-section,
        .cc-site-profile-schema-page #cc-schema-editor .cc-remove-field{
            border:1px solid var(--cc-border)!important;
            border-radius:10px!important;
            background:var(--cc-bg-soft)!important;
            color:var(--cc-text)!important;
            min-height:44px!important;
            min-width:56px!important;
            box-shadow:none!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-remove-section:hover,
        .cc-site-profile-schema-page #cc-schema-editor .cc-remove-field:hover{
            border-color:#d63638!important;
            color:#d63638!important;
            background:#fff!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-add-field,
        .cc-site-profile-schema-page #cc-schema-editor .cc-add-section{
            align-self:flex-start;
            border:1px solid var(--cc-border)!important;
            border-radius:10px!important;
            background:#fff!important;
            color:var(--cc-text)!important;
            min-height:42px!important;
            padding:0 18px!important;
            font-weight:700!important;
            box-shadow:none!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .cc-add-field:hover,
        .cc-site-profile-schema-page #cc-schema-editor .cc-add-section:hover{
            border-color:var(--cc-accent-color)!important;
            color:var(--cc-accent-color)!important;
            background:#fff!important;
        }
        .cc-site-profile-schema-page .cc-schema-shell > .button,
        .cc-site-profile-schema-page .cc-schema-shell-header .button,
        .cc-site-profile-schema-page input[type=\"submit\"].button-primary{
            border-radius:10px!important;
            min-height:44px!important;
            padding:0 18px!important;
            font-weight:700!important;
            box-shadow:none!important;
        }
        .cc-site-profile-schema-page input[type=\"submit\"].button-primary{
            background:var(--cc-accent-color)!important;
            border-color:var(--cc-accent-color)!important;
            color:#fff!important;
        }
        .cc-site-profile-schema-page input[type=\"submit\"].button-primary:hover{
            filter:brightness(1.04)!important;
            background:var(--cc-accent-color)!important;
            border-color:var(--cc-accent-color)!important;
            color:#fff!important;
        }
        .cc-site-profile-schema-page #cc-schema-editor .button{
            border-radius:10px!important;
            transition:all .15s ease!important;
        }
        @media (max-width: 1024px){
            .cc-site-profile-schema-page{
                max-width:none!important;
            }
            .cc-site-profile-schema-page #cc-schema-editor .cc-schema-field > div > div{
                grid-template-columns:1fr!important;
            }
            .cc-site-profile-schema-page .cc-schema-shell-header{
                flex-direction:column;
                align-items:flex-start;
            }
        }';
    }
}

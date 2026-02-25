<?php
namespace ContentCore\Modules\SiteOptions\Admin;

use ContentCore\Modules\SiteOptions\SiteOptionsModule;
use ContentCore\Modules\Multilingual\MultilingualModule;

class SiteOptionsAdmin
{
    private SiteOptionsModule $module;

    public function __construct(SiteOptionsModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 60);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Site Options', 'content-core'),
            __('Site Options', 'content-core'),
            'manage_options',
            'cc-site-options',
        [$this, 'render_page'],
            'dashicons-admin-settings',
            31 // Just below Content Core (30) or between Tools (75) and Settings (8
        );

        // Adjust priority to move it
        global $menu;
    // Find Site Options and move it if necessary, but 78 should work as position.
    }

    public function enqueue_assets($hook): void
    {
        if ($hook !== 'toplevel_page_cc-site-options') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_style('cc-metabox-ui');
        wp_enqueue_script('cc-admin-js');
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle Save
        if (isset($_POST['cc_site_options_nonce']) && wp_verify_nonce($_POST['cc_site_options_nonce'], 'cc_site_options_save')) {
            $this->save_options();
        }

        // Handle Translation Creation
        if (isset($_GET['action']) && $_GET['action'] === 'cc_create_site_options_translation' && isset($_GET['lang']) && isset($_GET['nonce'])) {
            $target_lang = sanitize_text_field($_GET['lang']);
            if (wp_verify_nonce($_GET['nonce'], 'cc_create_site_options_translation_' . $target_lang)) {
                $source_lang = $this->get_current_admin_language();
                $this->module->duplicate_options($source_lang, $target_lang);

                wp_safe_redirect(add_query_arg(['lang' => $target_lang, 'cc_options_created' => 1], menu_page_url('cc-site-options', false)));
                exit;
            }
        }

        if (isset($_GET['cc_options_created'])) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>' . __('Übersetzung wurde erfolgreich erstellt und Werte kopiert.', 'content-core') . '</p></div>';
            });
        }

        $current_lang = $this->get_current_admin_language();
        $schema = $this->module->get_localized_schema($current_lang);
        $languages = $this->get_languages();
        $default_lang = $this->get_default_language();

        $options = $this->module->get_options($current_lang);
        $defaults = $this->module->get_options($default_lang);
        $is_default_lang = ($current_lang === $default_lang);
        $group_id = $this->module->get_translation_group_id();

        // Prepare language labels
        $lang_catalog = \ContentCore\Modules\Multilingual\MultilingualModule::get_language_catalog();
?>
<div class="wrap content-core-admin">
    <h1 class="wp-heading-inline"><?php _e('Site Options', 'content-core'); ?></h1>
    <hr class="wp-header-end">

    <form method="post" action="" id="post">
        <?php wp_nonce_field('cc_site_options_save', 'cc_site_options_nonce'); ?>
        <input type="hidden" name="cc_lang" value="<?php echo esc_attr($current_lang); ?>">

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">
                
                <!-- Left Column -->
                <div id="post-body-content">
                    <?php foreach ($schema as $section_id => $section):
            $visible_fields = array_filter($section['fields'], function ($f) {
                return !isset($f['client_visible']) || $f['client_visible'];
            });

            if (empty($visible_fields))
                continue;
?>
                    <div class="cc-card" style="margin-bottom: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h2 style="margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #f0f0f1; font-size: 1.3em;">
                            <?php echo esc_html($section['title']); ?>
                        </h2>

                        <div class="cc-options-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <?php foreach ($visible_fields as $field_id => $field):
                $value = $options[$field_id] ?? '';
                $fallback_value = $defaults[$field_id] ?? '';
                $show_fallback = !$is_default_lang && empty($value) && !empty($fallback_value);
                $is_editable = !isset($field['client_editable']) || $field['client_editable'];
?>
                            <div class="cc-option-row" style="<?php echo $field['type'] === 'textarea' ? 'grid-column: span 2;' : ''; ?>">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">
                                    <?php echo esc_html($field['label']); ?>
                                    <?php if (!$is_editable): ?>
                                    <span class="dashicons dashicons-lock" style="font-size: 14px; color: #a0a5aa; vertical-align: middle;" title="<?php esc_attr_e('System-reserved (Read Only)', 'content-core'); ?>"></span>
                                    <?php
                endif; ?>
                                </label>

                                <?php $this->render_field($field_id, $field, $value, $is_editable); ?>

                                <?php if ($show_fallback): ?>
                                <p class="description" style="color: #646970; font-style: italic; margin-top: 4px;">
                                    <?php printf(__('Vorschau Fallback (%s): %s', 'content-core'), strtoupper($default_lang), esc_html($this->format_fallback_display($fallback_value, $field['type']))); ?>
                                </p>
                                <?php
                endif; ?>
                            </div>
                            <?php
            endforeach; ?>
                        </div>
                    </div>
                    <?php
        endforeach; ?>
                </div>

                <!-- Right Column (Sidebar) -->
                <div id="postbox-container-1" class="postbox-container">
                    
                    <!-- Language Metabox -->
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Language', 'content-core'); ?></span></h2>
                        <div class="inside">
                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Current Language', 'content-core'); ?></label>
                                <select id="cc-site-options-lang-switch" class="widefat" style="height: 30px;">
                                    <?php foreach ($languages as $lang): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($current_lang, $lang['code']); ?>>
                                        <?php
            $label = $lang_catalog[$lang['code']]['label'] ?? $lang['label'];
            echo esc_html($label . ' (' . strtoupper($lang['code']) . ')');
?>
                                    </option>
                                    <?php
        endforeach; ?>
                                </select>
                            </div>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 5px; font-weight: 600;"><?php _e('Translation Group ID', 'content-core'); ?></label>
                                <input type="text" class="widefat" value="<?php echo esc_attr($group_id); ?>" readonly disabled style="background: #f0f0f1; color: #646970;">
                            </div>

                            <div class="cc-translations-list">
                                <p style="font-weight: 600; margin-bottom: 8px; border-bottom: 1px solid #f0f0f1; padding-bottom: 5px;">
                                    <?php _e('Translations', 'content-core'); ?>
                                </p>
                                <table class="widefat fixed striped" style="border: none; background: transparent; box-shadow: none;">
                                    <tbody>
                                        <?php foreach ($languages as $lang):
            if ($lang['code'] === $current_lang)
                continue;
            $lang_data = $this->module->get_options($lang['code']);
            $exists = !empty($lang_data);
            $label = $lang_catalog[$lang['code']]['label'] ?? $lang['label'];
?>
                                        <tr>
                                            <td style="padding: 8px 0; vertical-align: middle;">
                                                <?php echo esc_html($label); ?>
                                            </td>
                                            <td style="padding: 8px 0; text-align: right; vertical-align: middle;">
                                                <?php if ($exists): ?>
                                                    <a href="<?php echo esc_url(add_query_arg('lang', $lang['code'])); ?>" class="button button-small" title="<?php _e('Edit', 'content-core'); ?>">
                                                        <span class="dashicons dashicons-edit" style="font-size: 16px; margin: 2px 4px 0 0;"></span>
                                                        <?php _e('Edit', 'content-core'); ?>
                                                    </a>
                                                <?php
            else: ?>
                                                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action' => 'cc_create_site_options_translation', 'lang' => $lang['code']]), 'cc_create_site_options_translation_' . $lang['code'])); ?>" class="button button-small" title="<?php _e('+ Create', 'content-core'); ?>">
                                                        <span class="dashicons dashicons-plus" style="font-size: 14px; margin: 3px 2px 0 0;"></span>
                                                        <?php _e('Create', 'content-core'); ?>
                                                    </a>
                                                <?php
            endif; ?>
                                            </td>
                                        </tr>
                                        <?php
        endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Save Metabox -->
                    <div class="postbox">
                        <h2 class="hndle"><span><?php _e('Site Options speichern', 'content-core'); ?></span></h2>
                        <div class="inside" style="padding: 12px;">
                            <div id="major-publishing-actions" style="margin: -12px; background: #fff; border: none;">
                                <div id="publishing-action" style="padding: 12px; text-align: right;">
                                    <input type="submit" name="save" id="publish" class="button button-primary button-large" value="<?php _e('Site Options speichern', 'content-core'); ?>">
                                </div>
                                <div class="clear"></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </form>
</div>

<script>
    jQuery(document).ready(function ($) {
        // Language Switcher Dropdown
        $('#cc-site-options-lang-switch').on('change', function() {
            var lang = $(this).val();
            window.location.href = window.location.pathname + '?page=cc-site-options&lang=' + lang;
        });

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

    private function format_fallback_display($value, $type): string
    {
        if (empty($value))
            return '';
        if ($type === 'image')
            return __('Ein Logo ist gesetzt', 'content-core');
        return (string)$value;
    }

    private function save_options(): void
    {
        if (!isset($_POST['cc_options']) || !is_array($_POST['cc_options']))
            return;

        $lang = sanitize_text_field($_POST['cc_lang'] ?? 'de');
        $schema = $this->module->get_schema();
        $existing_options = $this->module->get_options($lang);
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
                        $sanitized[$id] = absint($val);
                        break;
                    default:
                        $sanitized[$id] = sanitize_text_field($val);
                        break;
                }
            }
        }

        update_option("cc_site_options_{$lang}", $sanitized);

        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible"><p>' . __('Site Options gespeichert.', 'content-core') . '</p></div>';
        });
    }

    private function get_languages(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml = $plugin->get_module('multilingual');
        if ($ml instanceof MultilingualModule) {
            $settings = $ml->get_settings();
            if (!empty($settings['languages'])) {
                return $settings['languages'];
            }
        }
        return [['code' => 'de', 'label' => 'Deutsch']];
    }

    private function get_current_admin_language(): string
    {
        if (isset($_GET['lang'])) {
            return sanitize_text_field($_GET['lang']);
        }
        $user_lang = get_user_meta(get_current_user_id(), 'cc_admin_language', true);
        if ($user_lang && $user_lang !== 'all') {
            return $user_lang;
        }
        return $this->get_default_language();
    }

    private function get_default_language(): string
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml = $plugin->get_module('multilingual');
        if ($ml instanceof MultilingualModule) {
            $settings = $ml->get_settings();
            return $settings['default_lang'] ?? 'de';
        }
        return 'de';
    }
}
<?php
namespace ContentCore\Modules\Forms\Admin;

class FormAdmin
{
    public function init(): void
    {
        add_action('init', [$this, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_cc_form', [$this, 'save_form_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function register_post_types(): void
    {
        // cc_form
        register_post_type('cc_form', [
            'labels' => [
                'name' => __('Forms', 'content-core'),
                'singular_name' => __('Form', 'content-core'),
                'add_new' => __('Form hinzufügen', 'content-core'),
                'add_new_item' => __('Neues Formular erstellen', 'content-core'),
                'edit_item' => __('Formular bearbeiten', 'content-core'),
                'new_item' => __('Neues Formular', 'content-core'),
                'view_item' => __('Formular ansehen', 'content-core'),
                'search_items' => __('Formulare durchsuchen', 'content-core'),
                'not_found' => __('Keine Formulare gefunden', 'content-core'),
                'not_found_in_trash' => __('Keine Formulare im Papierkorb', 'content-core'),
                'menu_name' => __('Forms', 'content-core'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-feedback',
            'supports' => ['title'],
            'capability_type' => 'post',
            'capabilities' => [
                'create_posts' => 'manage_options',
            ],
            'map_meta_cap' => true,
        ]);

        // cc_form_entry
        register_post_type('cc_form_entry', [
            'labels' => [
                'name' => __('Form Entries', 'content-core'),
                'singular_name' => __('Entry', 'content-core'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=cc_form',
            'supports' => ['title'],
            'capabilities' => [
                'create_posts' => 'do_not_allow',
            ],
            'map_meta_cap' => true,
        ]);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box(
            'cc_form_fields',
            __('Field Builder', 'content-core'),
        [$this, 'render_field_builder'],
            'cc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'cc_form_settings',
            __('Form Settings', 'content-core'),
        [$this, 'render_form_settings'],
            'cc_form',
            'side'
        );
    }

    public function render_field_builder($post): void
    {
        $fields = get_post_meta($post->ID, 'cc_form_fields', true) ?: [];
        wp_nonce_field('cc_form_meta_save', 'cc_form_meta_nonce');

        // Field Adder UI
?>
<div class="cc-form-builder-toolbar"
    style="margin-bottom: 20px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px; display: flex; align-items: center; gap: 10px;">
    <label for="cc-add-field-type"><strong>
            <?php _e('Neues Feld:', 'content-core'); ?>
        </strong></label>
    <select id="cc-add-field-type" class="postbox">
        <option value="text">Text</option>
        <option value="email">Email</option>
        <option value="tel">Tel (Telefon)</option>
        <option value="number">Number (Zahl)</option>
        <option value="textarea">Textarea</option>
        <option value="date">Date (Datum)</option>
        <option value="time">Time (Zeit)</option>
        <option value="select">Select</option>
        <option value="checkbox">Checkbox</option>
        <option value="radio">Radio</option>
        <option value="multiple">Multiple Choice</option>
        <option value="file">File (Datei)</option>
        <option value="consent">Consent (DSGVO)</option>
    </select>
    <button type="button" id="cc-add-field-btn" class="button button-primary">
        <?php _e('Hinzufügen', 'content-core'); ?>
    </button>
</div>

<div id="cc-form-field-builder"></div>
<input type="hidden" name="cc_form_fields_json" id="cc_form_fields_json"
    value="<?php echo esc_attr(json_encode($fields)); ?>">
<?php
    }

    public function render_form_settings($post): void
    {
        $settings = get_post_meta($post->ID, 'cc_form_settings', true) ?: [
            'recipient_email' => get_option('admin_email'),
            'sender_email' => get_option('admin_email'),
            'subject_template' => __('New Submission: {form_title}', 'content-core'),
            'redirect_url' => '',
            'enable_honeypot' => true,
            'enable_rate_limit' => true,
            'enable_turnstile' => false
        ];

?>
<div class="cc-form-settings-wrap">
    <p>
        <label><strong>
                <?php _e('Recipient Email', 'content-core'); ?>
            </strong></label><br>
        <input type="email" name="cc_form_settings[recipient_email]"
            value="<?php echo esc_attr($settings['recipient_email']); ?>" class="widefat">
    </p>
    <p>
        <label><strong>
                <?php _e('Sender Email', 'content-core'); ?>
            </strong></label><br>
        <input type="email" name="cc_form_settings[sender_email]"
            value="<?php echo esc_attr($settings['sender_email']); ?>" class="widefat">
    </p>
    <p>
        <label><strong>
                <?php _e('Subject Template', 'content-core'); ?>
            </strong></label><br>
        <input type="text" name="cc_form_settings[subject_template]"
            value="<?php echo esc_attr($settings['subject_template']); ?>" class="widefat">
    </p>
    <p>
        <label><strong>
                <?php _e('Redirect URL', 'content-core'); ?>
            </strong></label><br>
        <input type="url" name="cc_form_settings[redirect_url]"
            value="<?php echo esc_attr($settings['redirect_url']); ?>" class="widefat">
    </p>
    <hr>
    <p>
        <label><input type="checkbox" name="cc_form_settings[enable_honeypot]" value="1" <?php
        checked($settings['enable_honeypot']); ?>>
            <?php _e('Enable Honeypot', 'content-core'); ?>
        </label>
    </p>
    <p>
        <label><input type="checkbox" name="cc_form_settings[enable_rate_limit]" value="1" <?php
        checked($settings['enable_rate_limit']); ?>>
            <?php _e('Enable Rate Limiting', 'content-core'); ?>
        </label>
    </p>
    <p>
        <label><input type="checkbox" name="cc_form_settings[enable_turnstile]" value="1" <?php
        checked($settings['enable_turnstile']); ?>>
            <?php _e('Enable Turnstile', 'content-core'); ?>
        </label>
    </p>
</div>
<?php
    }

    public function save_form_meta($post_id): void
    {
        if (!isset($_POST['cc_form_meta_nonce']) || !wp_verify_nonce($_POST['cc_form_meta_nonce'], 'cc_form_meta_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        // Save Fields
        if (isset($_POST['cc_form_fields_json'])) {
            $fields = json_decode(stripslashes($_POST['cc_form_fields_json']), true);
            if (is_array($fields)) {
                $sanitized_fields = [];
                $allowed_types = ['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'multiple', 'tel', 'number', 'date', 'time', 'file', 'consent'];

                foreach ($fields as $field) {
                    $type = sanitize_key($field['type'] ?? 'text');
                    if (!in_array($type, $allowed_types))
                        continue;

                    $clean_field = [
                        'type' => $type,
                        'name' => sanitize_key($field['name'] ?? ''),
                        'label' => sanitize_text_field($field['label'] ?? ''),
                        'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                        'required' => (bool)($field['required'] ?? false)
                    ];

                    // Advanced Config Logic
                    switch ($type) {
                        case 'number':
                            $clean_field['min'] = isset($field['min']) ? (float)$field['min'] : null;
                            $clean_field['max'] = isset($field['max']) ? (float)$field['max'] : null;
                            $clean_field['step'] = isset($field['step']) ? (float)$field['step'] : null;
                            break;
                        case 'select':
                        case 'radio':
                        case 'multiple':
                            $clean_field['options'] = $this->sanitize_options($field['options'] ?? []);
                            break;
                        case 'file':
                            $clean_field['max_size_mb'] = (int)($field['max_size_mb'] ?? 5);
                            $clean_field['multiple'] = (bool)($field['multiple'] ?? false);
                            $clean_field['allowed_types'] = is_array($field['allowed_types'] ?? null) ? array_map('sanitize_key', $field['allowed_types']) : ['pdf', 'jpg', 'png'];
                            break;
                        case 'consent':
                            $clean_field['consent_text'] = sanitize_text_field($field['consent_text'] ?? '');
                            $clean_field['consent_link_url'] = esc_url_raw($field['consent_link_url'] ?? '');
                            $clean_field['required'] = true; // Always required for consent if present in schema usually
                            break;
                    }

                    $sanitized_fields[] = $clean_field;
                }
                update_post_meta($post_id, 'cc_form_fields', $sanitized_fields);
            }
        }

        // Save Settings
        if (isset($_POST['cc_form_settings'])) {
            $raw = $_POST['cc_form_settings'];
            $settings = [
                'recipient_email' => sanitize_email($raw['recipient_email'] ?? get_option('admin_email')),
                'sender_email' => sanitize_email($raw['sender_email'] ?? get_option('admin_email')),
                'subject_template' => sanitize_text_field($raw['subject_template'] ?? ''),
                'redirect_url' => esc_url_raw($raw['redirect_url'] ?? ''),
                'enable_honeypot' => !empty($raw['enable_honeypot']),
                'enable_rate_limit' => !empty($raw['enable_rate_limit']),
                'enable_turnstile' => !empty($raw['enable_turnstile'])
            ];
            update_post_meta($post_id, 'cc_form_settings', $settings);
        }
    }

    private function sanitize_options(array $options): array
    {
        $sanitized = [];
        foreach ($options as $opt) {
            if (isset($opt['label']) || isset($opt['value'])) {
                $sanitized[] = [
                    'label' => sanitize_text_field($opt['label'] ?? ''),
                    'value' => sanitize_text_field($opt['value'] ?? '')
                ];
            }
        }
        return $sanitized;
    }

    public function enqueue_assets($hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'cc_form') {
            return;
        }

        // Enqueue Admin Assets
        wp_enqueue_style('cc-form-admin-css', plugins_url('/assets/css/forms-admin.css', dirname(dirname(dirname(dirname(__FILE__))))));
        wp_enqueue_script('cc-form-builder-js', plugins_url('/assets/js/forms-builder.js', dirname(dirname(dirname(dirname(__FILE__))))), ['jquery', 'wp-util'], '1.0.0', true);
    }
}
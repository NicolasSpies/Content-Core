<?php
namespace ContentCore\Modules\Forms\Handlers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Post;

class FormSubmissionHandler
{
    private WP_Post $form;
    private array $fields;
    private array $settings;
    private array $errors = [];
    private array $attachments = [];

    public function __construct(WP_Post $form)
    {
        $this->form = $form;
        $this->fields = get_post_meta($form->ID, 'cc_form_fields', true) ?: [];
        $this->settings = get_post_meta($form->ID, 'cc_form_settings', true) ?: [];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // 1. Spam Protection
        if (!$this->verify_spam_protection($request)) {
            return new WP_REST_Response(['message' => __('Spam check failed.', 'content-core')], 403);
        }

        // 2. Data Extraction & Validation
        $submission_data = $this->extract_and_validate($request);
        if (!empty($this->errors)) {
            return new WP_REST_Response([
                'message' => __('Validation failed.', 'content-core'),
                'errors' => $this->errors
            ], 400);
        }

        // 3. Store Entry
        $entry_id = $this->store_entry($submission_data);

        // 4. Send Notification
        $this->send_notification($submission_data, $entry_id);

        // 5. Cleanup
        $this->cleanup_attachments();

        // 6. Response
        $redirect = $this->settings['redirect_url'] ?? '';
        return new WP_REST_Response([
            'message' => __('Submission successful.', 'content-core'),
            'entry_id' => $entry_id,
            'redirect' => $redirect
        ], 200);
    }

    private function verify_spam_protection(WP_REST_Request $request): bool
    {
        // Honeypot
        if (!empty($this->settings['enable_honeypot'])) {
            if (!empty($request->get_param('cc_hp_field'))) {
                return false;
            }
        }

        // Rate Limit (ID based on IP)
        if (!empty($this->settings['enable_rate_limit'])) {
            $ip = $request->get_remote_addr();
            $key = 'cc_form_rl_' . md5($ip . '_' . $this->form->ID);
            if (get_transient($key)) {
                return false;
            }
            set_transient($key, true, 60); // 1 minute limit
        }

        return true;
    }

    private function extract_and_validate(WP_REST_Request $request): array
    {
        $data = [];
        $params = $request->get_params();

        foreach ($this->fields as $field) {
            $name = $field['name'];
            $value = $params[$name] ?? null;

            // Handle Files separately
            if ($field['type'] === 'file') {
                $data[$name] = $this->handle_file_upload($field, $name);
                continue;
            }

            // Required Check
            if (!empty($field['required']) && empty($value) && $value !== '0') {
                $this->errors[$name] = sprintf(__('%s is required.', 'content-core'), $field['label']);
                continue;
            }

            // Type Validation
            switch ($field['type']) {
                case 'email':
                    $value = sanitize_email($value);
                    if (!is_email($value) && !empty($value)) {
                        $this->errors[$name] = __('Invalid email format.', 'content-core');
                    }
                    break;
                case 'number':
                    if ($value !== null && $value !== '') {
                        $value = (float)$value;
                        if (isset($field['min']) && $value < $field['min']) {
                            $this->errors[$name] = sprintf(__('Must be at least %s.', 'content-core'), $field['min']);
                        }
                        if (isset($field['max']) && $value > $field['max']) {
                            $this->errors[$name] = sprintf(__('Must be at most %s.', 'content-core'), $field['max']);
                        }
                    }
                    break;
                case 'date':
                    if (!empty($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                        $this->errors[$name] = __('Invalid date format (YYYY-MM-DD).', 'content-core');
                    }
                    break;
                case 'time':
                    if (!empty($value) && !preg_match('/^\d{2}:\d{2}$/', $value)) {
                        $this->errors[$name] = __('Invalid time format (HH:MM).', 'content-core');
                    }
                    break;
                case 'consent':
                    if (!empty($field['required']) && empty($value)) {
                        $this->errors[$name] = __('You must consent to proceed.', 'content-core');
                    }
                    $value = (bool)$value;
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field($value);
                    break;
                case 'tel':
                default:
                    $value = sanitize_text_field($value);
                    break;
            }

            if ($value !== null) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    private function handle_file_upload(array $field, string $name): array
    {
        if (!isset($_FILES[$name])) {
            if (!empty($field['required'])) {
                $this->errors[$name] = sprintf(__('%s is required.', 'content-core'), $field['label']);
            }
            return [];
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $files = $_FILES[$name];
        $is_multiple = !empty($field['multiple']);
        $uploaded_ids = [];

        // Normalize $_FILES array if multiple
        $files_to_process = [];
        if (is_array($files['name'])) {
            if (!$is_multiple && count($files['name']) > 1) {
                $this->errors[$name] = __('Only one file allowed.', 'content-core');
                return [];
            }
            for ($i = 0; $i < count($files['name']); $i++) {
                $files_to_process[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
            }
        }
        else {
            $files_to_process[] = $files;
        }

        foreach ($files_to_process as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK)
                continue;

            // 1. Size Check
            $max_size = ($field['max_size_mb'] ?? 5) * 1024 * 1024;
            if ($file['size'] > $max_size) {
                $this->errors[$name] = sprintf(__('File too large. Max %s MB allowed.', 'content-core'), $field['max_size_mb']);
                continue;
            }

            // 2. Type Check
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed = $field['allowed_types'] ?? ['pdf', 'jpg', 'png'];
            if (!in_array(strtolower($ext), $allowed)) {
                $this->errors[$name] = sprintf(__('Invalid file type. Allowed: %s.', 'content-core'), implode(', ', $allowed));
                continue;
            }

            // 3. Upload
            $upload_overrides = ['test_form' => false];
            $movefile = wp_handle_upload($file, $upload_overrides);

            if ($movefile && !isset($movefile['error'])) {
                $attachment_id = wp_insert_attachment([
                    'guid' => $movefile['url'],
                    'post_mime_type' => $movefile['type'],
                    'post_title' => preg_replace('/\.[^.]+$/', '', basename($movefile['file'])),
                    'post_status' => 'inherit',
                ], $movefile['file']);

                if (!is_wp_error($attachment_id)) {
                    wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $movefile['file']));
                    $uploaded_ids[] = $attachment_id;
                    $this->attachments[] = $movefile['file']; // For mail attachment
                }
            }
            else {
                $this->errors[$name] = $movefile['error'] ?? __('Upload failed.', 'content-core');
            }
        }

        return $uploaded_ids;
    }

    private function store_entry(array $data): int
    {
        $id = wp_insert_post([
            'post_type' => 'cc_form_entry',
            'post_title' => sprintf(__('Submission: %s', 'content-core'), $this->form->post_title),
            'post_status' => 'publish',
        ]);

        if ($id) {
            update_post_meta($id, 'cc_entry_form_id', $this->form->ID);
            update_post_meta($id, 'cc_entry_data', $data);
            update_post_meta($id, 'cc_entry_ip', $_SERVER['REMOTE_ADDR'] ?? '');
            update_post_meta($id, 'cc_entry_lang', get_locale());
        }

        return $id;
    }

    private function send_notification(array $data, int $entry_id): void
    {
        $to = $this->settings['recipient_email'] ?? get_option('admin_email');
        $sender_email = $this->settings['sender_email'] ?? get_option('admin_email');

        // Extract key info for headers/subject
        $info = [
            'name' => '',
            'email' => '',
            'phone' => ''
        ];

        foreach ($this->fields as $field) {
            $n = $field['name'];
            $t = $field['type'];
            $v = $data[$n] ?? '';

            if (is_array($v))
                continue;

            if (empty($info['name']) && ($n === 'name' || $n === 'full_name')) {
                $info['name'] = $v;
            }
            if (empty($info['email']) && ($t === 'email' || $n === 'email')) {
                $info['email'] = $v;
            }
            if (empty($info['phone']) && ($t === 'tel' || $n === 'phone' || $n === 'tel')) {
                $info['phone'] = $v;
            }
        }

        // 1. Subject Template
        $subject = $this->settings['subject_template'] ?? 'New Form Submission';
        $subject = str_replace(
        ['{form_title}', '{name}', '{email}'],
        [$this->form->post_title, $info['name'], $info['email']],
            $subject
        );

        // 2. Headers
        $headers = [];
        $from_name = !empty($info['name']) ? $info['name'] : $this->form->post_title;
        // Sanitize name to prevent header injection
        $from_name = str_replace(["\r", "\n", '"', '<', '>'], '', $from_name);

        $headers[] = sprintf('From: "%s" <%s>', $from_name, $sender_email);

        if (!empty($info['email']) && is_email($info['email'])) {
            $headers[] = 'Reply-To: ' . sanitize_email($info['email']);
        }

        // 3. Message Body
        $body = "";
        $header_line = array_filter([$info['name'], $info['email'], $info['phone']]);
        if (!empty($header_line)) {
            $body .= implode(', ', $header_line) . "\n";
            $body .= str_repeat('-', 30) . "\n\n";
        }

        $body .= sprintf(__("Form: %s", 'content-core'), $this->form->post_title) . "\n\n";

        foreach ($this->fields as $field) {
            $val = $data[$field['name']] ?? '';
            if (is_array($val)) {
                if ($field['type'] === 'file') {
                    $urls = array_map('wp_get_attachment_url', $val);
                    $val = implode(', ', $urls);
                }
                else {
                    $val = implode(', ', $val);
                }
            }
            $body .= "{$field['label']}: {$val}\n";
        }

        wp_mail($to, $subject, $body, $headers, $this->attachments);
    }

    private function cleanup_attachments(): void
    {
    // Actually, we keep them as attachments in the media library.
    // If we didn't want to keep them, we would delete here.
    // But the requirements say "Store attachment ID in cc_form_entry", which implies keeping.
    }
}
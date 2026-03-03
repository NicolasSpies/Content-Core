<?php
namespace ContentCore\Modules\Settings\Rest;

use ContentCore\Modules\Settings\SettingsModule;
use ContentCore\Modules\RestApi\BaseRestController;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class SettingsRestController extends BaseRestController
{
    private $settings_module;
    private $registry;
    protected $namespace = 'content-core/v1';
    protected $rest_base = 'settings';

    public function __construct(SettingsModule $settings_module)
    {
        $this->settings_module = $settings_module;
        // In CC, properties are often accessed directly or via proxy
        $this->registry = $settings_module->get_registry();
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<module>[a-zA-Z0-9_\-]+)', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE . ', ' . \WP_REST_Server::EDITABLE, // Standardize on POST/PUT
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);
    }

    public function check_permissions(WP_REST_Request $request): bool
    {
        $module = (string) ($request['module'] ?? '');
        if ($module === 'site-profile') {
            return current_user_can('edit_posts');
        }
        return $this->check_admin_permissions($request);
    }

    public function get_item($request): WP_REST_Response
    {
        $module = $request['module'];
        if ($module === 'site') {
            $images = $this->registry->get('cc_site_images');
            $images = $this->hydrate_image_urls($images);

            return new WP_REST_Response([
                'seo' => $this->registry->get(SettingsModule::SEO_KEY),
                'images' => $images,
                'cookie' => $this->registry->get(SettingsModule::COOKIE_KEY),
                'branding' => $this->registry->get('cc_branding_settings')
            ]);
        }

        if ($module === 'visibility') {
            return new WP_REST_Response([
                'menu' => $this->registry->get(SettingsModule::OPTION_KEY),
                'admin_bar' => $this->registry->get(SettingsModule::ADMIN_BAR_KEY),
                'order' => get_option(SettingsModule::ORDER_KEY, [])
            ]);
        }

        if ($module === 'site-profile') {
            $site_mod = \ContentCore\Plugin::get_instance()->get_module('site_options');
            if (!($site_mod instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule)) {
                return new WP_REST_Response(['message' => 'Site Profile module not active.'], 500);
            }

            return new WP_REST_Response([
                'schema' => $site_mod->get_schema(),
                'values' => $site_mod->get_options(),
            ]);
        }

        $key = $this->get_option_key($module);
        if (!$key) {
            return new WP_REST_Response(['message' => 'Invalid module'], 404);
        }

        return new WP_REST_Response($this->registry->get($key));
    }

    public function update_item($request): WP_REST_Response
    {
        $module = $request['module'];
        $params = $request->get_json_params();
        $is_reset = !empty($params['reset']);

        // Log the request details
        \ContentCore\Logger::debug(sprintf('[CC Settings REST] update_item: module=%s, user_id=%d, is_reset=%d', $module, get_current_user_id(), $is_reset));
        \ContentCore\Logger::debug('[CC Settings REST] Params: ' . print_r($params, true));

        if (empty($params) && !$is_reset) {
            return new WP_REST_Response(['message' => 'No data provided'], 400);
        }

        if ($module === 'site') {
            if ($is_reset) {
                update_option(SettingsModule::SEO_KEY, $this->registry->get_defaults(SettingsModule::SEO_KEY));
                update_option('cc_site_images', $this->registry->get_defaults('cc_site_images'));
                update_option(SettingsModule::COOKIE_KEY, $this->registry->get_defaults(SettingsModule::COOKIE_KEY));
                return $this->get_item($request);
            }

            $success = true;
            $errors = [];

            if (isset($params['seo'])) {
                $saved = $this->registry->save(SettingsModule::SEO_KEY, $params['seo']);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save SEO settings';
                    \ContentCore\Logger::error('[CC Settings REST] SEO save failed');
                }
            }
            if (isset($params['images'])) {
                $saved = $this->registry->save('cc_site_images', $params['images']);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save site images';
                    \ContentCore\Logger::error('[CC Settings REST] Site Images save failed');
                }
            }
            if (isset($params['cookie'])) {
                $saved = $this->registry->save(SettingsModule::COOKIE_KEY, $params['cookie']);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save cookie banner settings';
                    \ContentCore\Logger::error('[CC Settings REST] Cookie save failed');
                }
            }

            if (isset($params['branding'])) {
                $saved = $this->registry->save('cc_branding_settings', $params['branding']);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save branding settings';
                    \ContentCore\Logger::error('[CC Settings REST] Branding save failed');
                }
            }

            if (!$success) {
                return new \WP_REST_Response([
                    'code' => 'save_failure',
                    'message' => 'Partial or total failure saving site settings',
                    'data' => [
                        'status' => 500,
                        'errors' => $errors
                    ]
                ], 500);
            }

            return $this->get_item($request);
        }

        if ($module === 'visibility') {
            \ContentCore\Logger::debug('[CC Settings REST] Handling visibility module save');
            if ($is_reset) {
                update_option(SettingsModule::OPTION_KEY, $this->registry->get_defaults(SettingsModule::OPTION_KEY));
                update_option(SettingsModule::ADMIN_BAR_KEY, $this->registry->get_defaults(SettingsModule::ADMIN_BAR_KEY));
                delete_option(SettingsModule::ORDER_KEY);
                return $this->get_item($request);
            }

            $success = true;
            $errors = [];

            if (isset($params['menu'])) {
                $menu_data = $params['menu'];
                if (isset($menu_data['admin']) && is_array($menu_data['admin'])) {
                    $menu_data['admin']['options-general.php'] = true;
                    $menu_data['admin']['plugins.php'] = true;
                    $menu_data['admin']['content-core'] = true;
                }
                $saved = $this->registry->save(SettingsModule::OPTION_KEY, $menu_data);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save menu visibility settings';
                }
            }
            if (isset($params['admin_bar'])) {
                $saved = $this->registry->save(SettingsModule::ADMIN_BAR_KEY, $params['admin_bar']);
                if (!$saved) {
                    $success = false;
                    $errors[] = 'Failed to save admin bar visibility settings';
                }
            }
            if (isset($params['order'])) {
                $saved = update_option(SettingsModule::ORDER_KEY, $params['order']);
                // Handle unchanged order correctly
                if (!$saved && get_option(SettingsModule::ORDER_KEY) !== $params['order']) {
                    $success = false;
                    $errors[] = 'Failed to save item display order';
                }
            }

            if (!$success) {
                return new \WP_REST_Response([
                    'code' => 'visibility_save_failure',
                    'message' => 'Failed to save visibility settings',
                    'data' => [
                        'status' => 500,
                        'errors' => $errors
                    ]
                ], 500);
            }

            return $this->get_item($request);
        }

        if ($module === 'redirect') {
            \ContentCore\Logger::debug('[CC Settings REST] Handling redirect module save');
            $success = true;
            $errors = [];
            if (isset($params['admin_bar'])) {
                $saved = $this->registry->save(SettingsModule::ADMIN_BAR_KEY, $params['admin_bar']);
                if (!$saved) {
                    $errors[] = 'Failed to save admin bar visibility';
                }
                $success = $success && $saved;
                unset($params['admin_bar']);
            }
            if (!empty($params)) {
                $saved = $this->registry->save(SettingsModule::REDIRECT_KEY, $params);
                if (!$saved) {
                    $errors[] = 'Failed to save redirect configuration';
                }
                $success = $success && $saved;
            }

            if (!$success) {
                return new \WP_REST_Response([
                    'code' => 'redirect_save_failure',
                    'message' => 'Failed to save redirect settings',
                    'data' => [
                        'status' => 500,
                        'errors' => $errors
                    ]
                ], 500);
            }

            return new \WP_REST_Response([
                'redirect' => $this->registry->get(SettingsModule::REDIRECT_KEY),
                'admin_bar' => $this->registry->get(SettingsModule::ADMIN_BAR_KEY)
            ]);
        }

        if ($module === 'site-profile') {
            $site_mod = \ContentCore\Plugin::get_instance()->get_module('site_options');
            if (!($site_mod instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule)) {
                return new WP_REST_Response(['message' => 'Site Profile module not active.'], 500);
            }

            $schema = $site_mod->get_schema();
            $existing = $site_mod->get_options();
            $incoming = isset($params['values']) && is_array($params['values']) ? $params['values'] : [];
            $sanitized = $this->sanitize_site_profile_values($schema, $existing, $incoming);
            update_option(\ContentCore\Modules\SiteOptions\SiteOptionsModule::DATA_OPTION, $sanitized);

            return $this->get_item($request);
        }

        $key = $this->get_option_key($module);
        if (!$key) {
            \ContentCore\Logger::error(sprintf('[CC Settings REST] Invalid module requested for save: %s', $module));
            return new \WP_REST_Response(['message' => 'Invalid module'], 404);
        }

        if ($is_reset) {
            update_option($key, $this->registry->get_defaults($key));
            if ($key === 'cc_languages_settings') {
                set_transient('cc_flush_multilingual_rewrites', 1, 300);
            }
            return $this->get_item($request);
        }

        \ContentCore\Logger::debug(sprintf('[CC Settings REST] Generic save for module: %s, key: %s', $module, $key));

        // Deep merge is problematic for numeric arrays like 'languages'
        if ($key === 'cc_languages_settings') {
            $defaults = $this->registry->get_defaults($key);
            $current = $this->registry->get($key);
            $update_params = is_array($params) ? $params : [];
            // Shallow merge the new top-level keys to replace numeric arrays entirely
            $merged = array_merge((array) $defaults, (array) $current, (array) $update_params);
            $merged = $this->normalize_languages_payload($merged);
            $sanitized = $this->registry->sanitize($key, $merged);
            $success = update_option($key, $sanitized);
            // Handle unchanged data
            if (!$success && get_option($key) !== $sanitized) {
                return new \WP_REST_Response([
                    'code' => 'language_save_failure',
                    'message' => 'Failed to save language settings',
                    'data' => ['status' => 500]
                ], 500);
            }
            set_transient('cc_flush_multilingual_rewrites', 1, 300);
        } else {
            $success = $this->registry->save($key, (array) $params);
            if (!$success) {
                return new \WP_REST_Response([
                    'code' => 'generic_save_failure',
                    'message' => sprintf('Failed to save %s settings', $module),
                    'data' => ['status' => 500]
                ], 500);
            }
        }

        return $this->get_item($request);
    }

    private function get_option_key(string $module_slug): ?string
    {
        $map = [
            'visibility' => SettingsModule::OPTION_KEY,
            'media' => SettingsModule::MEDIA_KEY,
            'redirect' => SettingsModule::REDIRECT_KEY,
            'seo' => SettingsModule::SEO_KEY,
            'cookie' => SettingsModule::COOKIE_KEY,
            'multilingual' => 'cc_languages_settings',
            'branding' => 'cc_branding_settings',
        ];

        return $map[$module_slug] ?? null;
    }

    /**
     * Enrich image-id settings with *_url helper keys for React preview rendering.
     */
    private function hydrate_image_urls(array $images): array
    {
        $hydrated = $images;
        foreach ($images as $key => $value) {
            if (!is_string($key) || substr($key, -3) !== '_id') {
                continue;
            }

            $id = absint($value);
            $url_key = $key . '_url';
            $hydrated[$url_key] = $id ? (wp_get_attachment_image_url($id, 'full') ?: '') : '';
        }

        return $hydrated;
    }

    private function normalize_languages_payload(array $settings): array
    {
        if (!isset($settings['languages']) || !is_array($settings['languages'])) {
            return $settings;
        }

        $languages = $settings['languages'];
        $is_associative = array_keys($languages) !== range(0, count($languages) - 1);
        if (!$is_associative) {
            return $settings;
        }

        $normalized = [];
        foreach ($languages as $code => $entry) {
            $normalized_code = sanitize_key((string) $code);
            if ($normalized_code === '') {
                continue;
            }

            $row = [
                'code' => $normalized_code,
                'label' => strtoupper($normalized_code),
                'flag_id' => 0,
            ];

            if (is_array($entry)) {
                if (!empty($entry['code'])) {
                    $row['code'] = sanitize_key((string) $entry['code']);
                }
                if (!empty($entry['label'])) {
                    $row['label'] = sanitize_text_field((string) $entry['label']);
                }
                if (isset($entry['flag_id'])) {
                    $row['flag_id'] = absint($entry['flag_id']);
                }
            } elseif (is_string($entry) && $entry !== '') {
                $row['label'] = sanitize_text_field($entry);
            }

            $normalized[] = $row;
        }

        $settings['languages'] = $normalized;
        return $settings;
    }

    private function sanitize_site_profile_values(array $schema, array $existing, array $incoming): array
    {
        $sanitized = [];
        foreach ($schema as $section) {
            $fields = isset($section['fields']) && is_array($section['fields']) ? $section['fields'] : [];
            foreach ($fields as $id => $field) {
                if (isset($field['client_editable']) && !$field['client_editable']) {
                    if (isset($existing[$id])) {
                        $sanitized[$id] = $existing[$id];
                    }
                    continue;
                }

                if (!array_key_exists($id, $incoming)) {
                    if (isset($existing[$id])) {
                        $sanitized[$id] = $existing[$id];
                    }
                    continue;
                }

                $val = $incoming[$id];
                $type = isset($field['type']) ? (string) $field['type'] : 'text';
                switch ($type) {
                    case 'email':
                        $sanitized[$id] = sanitize_email((string) $val);
                        break;
                    case 'url':
                        $sanitized[$id] = esc_url_raw((string) $val);
                        break;
                    case 'textarea':
                        $sanitized[$id] = sanitize_textarea_field((string) $val);
                        break;
                    case 'image':
                        $sanitized[$id] = max(0, (int) $val);
                        break;
                    default:
                        $sanitized[$id] = sanitize_text_field((string) $val);
                        break;
                }
            }
        }

        return $sanitized;
    }
}

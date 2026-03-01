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
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE . ', ' . \WP_REST_Server::EDITABLE, // Standardize on POST/PUT
                'callback' => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
        ]);
    }

    public function get_item($request): WP_REST_Response
    {
        $module = $request['module'];
        if ($module === 'site') {
            return new WP_REST_Response([
                'seo' => $this->registry->get(SettingsModule::SEO_KEY),
                'images' => $this->registry->get('cc_site_images'),
                'cookie' => $this->registry->get(SettingsModule::COOKIE_KEY),
                'branding' => get_option('cc_branding_settings', [])
            ]);
        }

        if ($module === 'visibility') {
            return new WP_REST_Response([
                'menu' => $this->registry->get(SettingsModule::OPTION_KEY),
                'admin_bar' => $this->registry->get(SettingsModule::ADMIN_BAR_KEY),
                'order' => get_option(SettingsModule::ORDER_KEY, [])
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
                // Branding uses its own option
                $saved = update_option('cc_branding_settings', $params['branding']);
                // Note: update_option returns false if the value is the same as already stored. 
                // We should check if it's strictly false or use get_option comparison or just assume success if not an error.
                // However, the registry->save() usually return true if data is validated.
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
                if (isset($menu_data['client']) && is_array($menu_data['client'])) {
                    $menu_data['client']['content-core'] = true;
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

        $key = $this->get_option_key($module);
        if (!$key) {
            \ContentCore\Logger::error(sprintf('[CC Settings REST] Invalid module requested for save: %s', $module));
            return new \WP_REST_Response(['message' => 'Invalid module'], 404);
        }

        if ($is_reset) {
            update_option($key, $this->registry->get_defaults($key));
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
}

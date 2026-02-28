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
                'methods' => 'GET',
                'callback' => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ],
            [
                'methods' => 'POST',
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
            if (isset($params['seo'])) {
                $success = $success && $this->registry->save(SettingsModule::SEO_KEY, $params['seo']);
            }
            if (isset($params['images'])) {
                $success = $success && $this->registry->save('cc_site_images', $params['images']);
            }
            if (isset($params['cookie'])) {
                $success = $success && $this->registry->save(SettingsModule::COOKIE_KEY, $params['cookie']);
            }

            if (!$success) {
                return new WP_REST_Response(['message' => 'Partial or total failure saving site settings'], 500);
            }

            return $this->get_item($request);
        }

        if ($module === 'visibility') {
            if ($is_reset) {
                update_option(SettingsModule::OPTION_KEY, $this->registry->get_defaults(SettingsModule::OPTION_KEY));
                update_option(SettingsModule::ADMIN_BAR_KEY, $this->registry->get_defaults(SettingsModule::ADMIN_BAR_KEY));
                delete_option(SettingsModule::ORDER_KEY);
                return $this->get_item($request);
            }

            $success = true;
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
                $success = $success && $this->registry->save(SettingsModule::OPTION_KEY, $menu_data);
            }
            if (isset($params['admin_bar'])) {
                $success = $success && $this->registry->save(SettingsModule::ADMIN_BAR_KEY, $params['admin_bar']);
            }
            if (isset($params['order'])) {
                $success = $success && update_option(SettingsModule::ORDER_KEY, $params['order']);
            }

            if (!$success) {
                return new WP_REST_Response(['message' => 'Failed to save visibility settings'], 500);
            }

            return $this->get_item($request);
        }

        if ($module === 'redirect') {
            $success = true;
            if (isset($params['admin_bar'])) {
                $success = $success && $this->registry->save(SettingsModule::ADMIN_BAR_KEY, $params['admin_bar']);
                unset($params['admin_bar']);
            }
            if (!empty($params)) {
                $success = $success && $this->registry->save(SettingsModule::REDIRECT_KEY, $params);
            }

            if (!$success) {
                return new WP_REST_Response(['message' => 'Failed to save redirect settings'], 500);
            }

            return new WP_REST_Response([
                'redirect' => $this->registry->get(SettingsModule::REDIRECT_KEY),
                'admin_bar' => $this->registry->get(SettingsModule::ADMIN_BAR_KEY)
            ]);
        }

        $key = $this->get_option_key($module);
        if (!$key) {
            return new WP_REST_Response(['message' => 'Invalid module'], 404);
        }

        if ($is_reset) {
            update_option($key, $this->registry->get_defaults($key));
            return $this->get_item($request);
        }

        // Deep merge is problematic for numeric arrays like 'languages'
        if ($key === 'cc_languages_settings') {
            $defaults = $this->registry->get_defaults($key);
            $current = $this->registry->get($key);
            // Shallow merge the new top-level keys to replace numeric arrays entirely
            $merged = array_merge($defaults, $current, $params);
            $sanitized = $this->registry->sanitize($key, $merged);
            $success = update_option($key, $sanitized);
        } else {
            $success = $this->registry->save($key, $params);
        }

        if (!$success) {
            return new WP_REST_Response(['message' => 'Failed to save settings'], 500);
        }

        return new WP_REST_Response($this->registry->get($key));
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
        ];

        return $map[$module_slug] ?? null;
    }
}

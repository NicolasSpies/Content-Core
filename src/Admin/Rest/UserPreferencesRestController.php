<?php
namespace ContentCore\Admin\Rest;

use ContentCore\Modules\RestApi\BaseRestController;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller for managing user-specific admin preferences.
 */
class UserPreferencesRestController extends BaseRestController
{
    protected $namespace = 'content-core/v1';
    protected $rest_base = 'user-preferences';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/menu-state', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [$this, 'save_menu_state'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'slug' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'state' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/dark-mode', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [$this, 'save_dark_mode'],
            'permission_callback' => function (): bool {
                return is_user_logged_in();
            },
            'args' => [
                'enabled' => [
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Save the collapsed/expanded state of a menu section.
     */
    public function save_menu_state(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $slug = $request->get_param('slug');
        $state = $request->get_param('state');

        $meta_key = 'cc_menu_state';
        $current_states = get_user_meta($user_id, $meta_key, true) ?: [];

        if (!is_array($current_states)) {
            $current_states = [];
        }

        $current_states[$slug] = $state;
        update_user_meta($user_id, $meta_key, $current_states);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Save dark mode preference for the current user.
     */
    public function save_dark_mode(WP_REST_Request $request): WP_REST_Response
    {
        $user_id = get_current_user_id();
        $enabled_raw = $request->get_param('enabled');
        $enabled = false;

        if (is_bool($enabled_raw)) {
            $enabled = $enabled_raw;
        } else {
            $value = strtolower(trim((string) $enabled_raw));
            $enabled = in_array($value, ['1', 'true', 'yes', 'on'], true);
        }

        update_user_meta($user_id, 'cc_dark_mode', $enabled ? '1' : '0');

        return new WP_REST_Response([
            'success' => true,
            'enabled' => $enabled,
        ], 200);
    }
}

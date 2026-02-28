<?php
namespace ContentCore\Modules\RestApi;

use WP_REST_Controller;
use WP_REST_Request;

/**
 * Base REST Controller for Content Core.
 * Provides standard capability checks to DRY up individual controllers.
 */
abstract class BaseRestController extends WP_REST_Controller
{
    /**
     * Standard permission check for admin-level operations.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function check_admin_permissions(WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }
}

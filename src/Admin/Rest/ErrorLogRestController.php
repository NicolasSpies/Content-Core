<?php
namespace ContentCore\Admin\Rest;

use ContentCore\Modules\RestApi\BaseRestController;
use ContentCore\Admin\ErrorLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for Error Log management.
 *
 * Namespace: content-core/v1
 * Routes:
 *   POST /tools/error-log/clear      — clear all entries
 *   POST /tools/error-log/clear-old  — clear entries older than 24 hours
 */
class ErrorLogRestController extends BaseRestController
{
    private ErrorLogger $logger;

    public function __construct(ErrorLogger $logger, string $namespace)
    {
        $this->logger = $logger;
        $this->namespace = $namespace;
        $this->rest_base = 'tools/error-log';
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base . '/clear', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'clear_log'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/clear-old', [
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'clear_old_log'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'export_log'],
                'permission_callback' => [$this, 'check_admin_permissions'],
            ]
        ]);
    }

    public function clear_log(WP_REST_Request $request): WP_REST_Response
    {
        $this->logger->clear();
        return rest_ensure_response([
            'success' => true,
            'message' => __('Error log cleared.', 'content-core'),
        ]);
    }

    public function clear_old_log(WP_REST_Request $request): WP_REST_Response
    {
        $cutoff = function_exists('current_time') ? (int) current_time('timestamp') - 86400 : time() - 86400;
        $this->logger->clear_before($cutoff);

        return rest_ensure_response([
            'success' => true,
            'message' => __('Resolved log entries (older than 24h) have been cleared.', 'content-core'),
        ]);
    }

    public function export_log(\WP_REST_Request $request): \WP_REST_Response
    {
        $entries = $this->logger->get_entries();
        return rest_ensure_response($entries);
    }
}

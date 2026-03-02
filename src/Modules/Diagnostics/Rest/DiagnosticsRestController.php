<?php
namespace ContentCore\Modules\Diagnostics\Rest;

use ContentCore\Modules\RestApi\BaseRestController;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckRegistry;
use WP_REST_Request;
use WP_REST_Response;

class DiagnosticsRestController extends BaseRestController
{
    /** @var HealthCheckRegistry */
    private $registry;
    protected $namespace = 'content-core/v1';
    protected $rest_base = 'diagnostics';

    public function __construct(HealthCheckRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => 'GET',
            'callback' => function () {
                return ['module' => 'diagnostics', 'active' => true];
            },
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/run', [
            'methods' => 'POST',
            'callback' => [$this, 'run_checks'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/clear-resolved', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_resolved'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/clear-all', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_all'],
            'permission_callback' => [$this, 'check_admin_permissions'],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/fix', [
            'methods' => 'POST',
            'callback' => [$this, 'apply_fix'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'issue_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'check_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'context' => [
                    'required' => false,
                    'type' => 'object', // Passed via JSON body
                ]
            ]
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/fix-preview', [
            'methods' => 'POST',
            'callback' => [$this, 'get_fix_preview'],
            'permission_callback' => [$this, 'check_admin_permissions'],
            'args' => [
                'issue_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'check_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ],
                'context' => [
                    'required' => false,
                    'type' => 'object',
                ]
            ]
        ]);
    }

    public function run_checks(WP_REST_Request $request): WP_REST_Response
    {
        // This acts as both trigging the scan and fetching the log.
        // It's manually clicked via the UI.
        try {
            $log = $this->registry->run_all_checks();
            return new WP_REST_Response([
                'success' => true,
                'log' => array_values($log) // array_values normalizes JSON object to array
            ]);
        } catch (\Throwable $e) {
            \ContentCore\Logger::error('Health check failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Internal error during scan. ' . $e->getMessage()
            ], 500);
        }
    }

    public function clear_resolved(WP_REST_Request $request): WP_REST_Response
    {
        $cleared = $this->registry->clear_resolved();
        return new WP_REST_Response([
            'success' => true,
            'cleared_count' => $cleared,
            'log' => array_values($this->registry->get_log())
        ]);
    }

    public function clear_all(WP_REST_Request $request): WP_REST_Response
    {
        $count = $this->registry->clear_all();
        return new WP_REST_Response([
            'success' => true,
            'cleared_count' => $count,
            'log' => []
        ]);
    }

    public function get_fix_preview(WP_REST_Request $request): WP_REST_Response
    {
        $check_id = $request->get_param('check_id');
        $issue_id = $request->get_param('issue_id');
        $context = $request->get_param('context');

        $check = $this->registry->get_check($check_id);
        if (!$check) {
            return new WP_REST_Response(['message' => 'Invalid check ID'], 404);
        }

        try {
            $preview = $check->get_fix_preview($issue_id, $context);
            return new WP_REST_Response([
                'success' => true,
                'preview' => $preview
            ]);
        } catch (\Throwable $e) {
            return new WP_REST_Response(['message' => $e->getMessage()], 400);
        }
    }

    public function apply_fix(WP_REST_Request $request): WP_REST_Response
    {
        $check_id = $request->get_param('check_id');
        $issue_id = $request->get_param('issue_id');
        $context = $request->get_param('context'); // Safely pass context arrays to fix

        $check = $this->registry->get_check($check_id);
        if (!$check) {
            return new WP_REST_Response(['message' => 'Invalid check ID'], 404);
        }

        try {
            $result = $check->apply_fix($issue_id, $context);
            if (is_wp_error($result)) {
                return new WP_REST_Response(['message' => $result->get_error_message()], 400);
            }
            if ($result === false) {
                return new WP_REST_Response(['message' => 'Fix failed.'], 400);
            }

            // Re-run the scan to verify it worked and update log state
            $log = $this->registry->run_all_checks();
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Fix applied successfully.',
                'log' => array_values($log)
            ]);

        } catch (\Throwable $e) {
            \ContentCore\Logger::error('Health fix failed: ' . $e->getMessage());
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Fix execution failed: ' . $e->getMessage()
            ], 500);
        }
    }
}

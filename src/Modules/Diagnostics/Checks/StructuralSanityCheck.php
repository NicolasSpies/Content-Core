<?php
namespace ContentCore\Modules\Diagnostics\Checks;

use ContentCore\Modules\Diagnostics\Engine\HealthCheckInterface;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckResult;
use ContentCore\Plugin;

class StructuralSanityCheck implements HealthCheckInterface
{
    public function get_id(): string
    {
        return 'structural_sanity';
    }

    public function get_name(): string
    {
        return __('Structural PHP Sanity', 'content-core');
    }

    public function get_category(): string
    {
        return 'structural';
    }

    public function run_check(): array
    {
        $results = [];

        // 1. Check Boot Failures
        $plugin = Plugin::get_instance();
        $missing = $plugin->get_missing_modules();

        if (!empty($missing)) {
            foreach ($missing as $module_err) {
                // Ensure unique ID by hashing the error slightly
                $hash = substr(md5($module_err), 0, 8);
                $results[] = new HealthCheckResult(
                    'module_boot_failure_' . $hash,
                    'critical',
                    sprintf(__('Module boot failure detected: %s. Functionality is impaired.', 'content-core'), esc_html($module_err)),
                    false
                );
            }
        }

        // 2. Check for Hook Duplications
        // Basic heuristic: check if cc_filter_post_link is registered multiple times
        global $wp_filter;

        $critical_hooks = [
            'post_link' => 'cc_filter_post_link',
            'get_terms_args' => 'apply_cc_term_order'
        ];

        foreach ($critical_hooks as $hook_name => $expected_callback) {
            if (isset($wp_filter[$hook_name])) {
                $count = 0;
                foreach ($wp_filter[$hook_name] as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function']) && count($callback['function']) === 2) {
                            $func_name = is_string($callback['function'][1]) ? $callback['function'][1] : '';
                            if ($func_name === $expected_callback) {
                                $count++;
                            }
                        }
                    }
                }

                if ($count > 1) {
                    $results[] = new HealthCheckResult(
                        'duplicate_hook_' . $hook_name,
                        'warning',
                        sprintf(__('The critical hook %s->%s is registered %d times. Check for multiple class instantiations.', 'content-core'), $hook_name, $expected_callback, $count),
                        false
                    );
                }
            }
        }

        return $results;
    }

    public function get_fix_preview(string $issue_id, $context_data = null): ?array
    {
        return null; // Structural issues are strictly manual debugging fixes
    }

    public function apply_fix(string $issue_id, $context_data = null)
    {
        return new \WP_Error('invalid_fix', 'Structural core anomalies cannot be auto-fixed.');
    }
}

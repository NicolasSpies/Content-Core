<?php
namespace ContentCore\Modules\Diagnostics\Checks;

use ContentCore\Modules\Diagnostics\Engine\HealthCheckInterface;
use ContentCore\Modules\Diagnostics\Engine\HealthCheckResult;

class ThemeInjectionCheck implements HealthCheckInterface
{
    public function get_id(): string
    {
        return 'theme_injection';
    }

    public function get_name(): string
    {
        return __('Theme Script Injection', 'content-core');
    }

    public function get_category(): string
    {
        return 'structural';
    }

    public function run_check(): array
    {
        $results = [];

        $theme = wp_get_theme();
        if (!$theme || !$theme->exists()) {
            return $results;
        }

        $header_path = $theme->get_stylesheet_directory() . '/header.php';
        $footer_path = $theme->get_stylesheet_directory() . '/footer.php';

        // Also check parent theme if child theme doesn't override header/footer
        if (!file_exists($header_path) && $theme->parent()) {
            $header_path = $theme->parent()->get_stylesheet_directory() . '/header.php';
        }
        if (!file_exists($footer_path) && $theme->parent()) {
            $footer_path = $theme->parent()->get_stylesheet_directory() . '/footer.php';
        }

        if (file_exists($header_path)) {
            $content = file_get_contents($header_path);
            if (strpos($content, 'wp_head') === false) {
                $results[] = new HealthCheckResult(
                    'theme_missing_wp_head',
                    'critical',
                    __('The active theme is missing a call to wp_head() in header.php. Content Core JS configuration injection will fail or be invalid.', 'content-core'),
                    false
                );
            }
        }

        if (file_exists($footer_path)) {
            $content = file_get_contents($footer_path);
            if (strpos($content, 'wp_footer') === false) {
                $results[] = new HealthCheckResult(
                    'theme_missing_wp_footer',
                    'critical',
                    __('The active theme is missing a call to wp_footer() in footer.php. Dependent JS configuration injection may be invalid or missing entirely.', 'content-core'),
                    false
                );
            }
        }

        return $results;
    }

    public function get_fix_preview(string $issue_id, $context_data = null): ?array
    {
        return null;
    }

    public function apply_fix(string $issue_id, $context_data = null)
    {
        return new \WP_Error('invalid_fix', 'Manual theme edit required.');
    }
}

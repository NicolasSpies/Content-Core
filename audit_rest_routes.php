<?php
/**
 * Diagnostic tool to audit REST routes at runtime.
 */
define('WP_USE_THEMES', false);
require_once(__DIR__ . '/../../../wp-load.php');

if (!current_user_can('manage_options')) {
    exit('Unauthorized');
}

$is_subdir = isset($_GET['subdir']) && $_GET['subdir'] === '1';
if ($is_subdir) {
    // Attempt to spoof a subdirectory install for the duration of this request
    // Note: This won't change how WP was loaded, but can affect how rest_url() resolves if filtered
    add_filter('rest_url', function ($url) {
        return str_replace(home_url(), home_url() . '/wp', $url);
    });
}

$server = rest_get_server();
$routes = $server->get_routes();

$audit_data = [];

foreach ($routes as $route => $handlers) {
    if (strpos($route, 'content-core') === false && strpos($route, 'cc/') === false) {
        continue;
    }

    foreach ($handlers as $handler) {
        $callback = $handler['callback'] ?? 'N/A';
        $callback_class = 'N/A';
        $file = 'N/A';

        if (is_array($callback)) {
            $callback_class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            try {
                $reflection = new ReflectionMethod($callback_class, $callback[1]);
                $file = $reflection->getFileName();
            } catch (Exception $e) {
            }
        }

        $audit_data[] = [
            'route' => $route,
            'methods' => implode(', ', (array) $handler['methods']),
            'namespace' => $handler['namespace'] ?? 'N/A',
            'callback_class' => $callback_class,
            'registration_file' => str_replace(ABSPATH, '', $file),
            'environment' => $is_subdir ? 'Subdirectory (/wp)' : 'Root'
        ];
    }
}

header('Content-Type: application/json');
echo json_encode($audit_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

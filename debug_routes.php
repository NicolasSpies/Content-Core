<?php
/**
 * Diagnostic tool to inspect REST routes at runtime.
 * Place this in the WordPress root or call it via a hook.
 */

// If called directly, we need to load WordPress. 
// Assuming this is in wp-content/plugins/Content-Core/
$wp_load = __DIR__ . '/../../../wp-load.php';
if (file_exists($wp_load)) {
    require_once($wp_load);
} else {
    // Fallback if not in standard location (e.g. desktop level)
    exit('Could not find wp-load.php');
}

if (!function_exists('rest_get_server')) {
    exit('REST API not initialized.');
}

$server = rest_get_server();
$routes = $server->get_routes();

$cc_routes = [];
foreach ($routes as $route => $handlers) {
    if (strpos($route, 'content-core') !== false) {
        $cc_routes[$route] = [];
        foreach ($handlers as $handler) {
            $cc_routes[$route][] = [
                'methods' => $handler['methods'],
                'callback' => is_array($handler['callback']) ? get_class($handler['callback'][0]) . '::' . $handler['callback'][1] : 'function',
                'permission_callback' => is_array($handler['permission_callback'] ?? '') ? get_class(($handler['permission_callback'][0] ?? null)) . '::' . ($handler['permission_callback'][1] ?? '') : 'function',
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'total_routes' => count($routes),
    'cc_routes' => $cc_routes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

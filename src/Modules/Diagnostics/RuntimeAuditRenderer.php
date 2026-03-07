<?php
namespace ContentCore\Modules\Diagnostics;

/**
 * Renders the refined Runtime Audit diagnostic tool.
 * Strictly admin-only, does not depend on REST functionality for data collection.
 */
class RuntimeAuditRenderer
{
    /**
     * Render the global audit data for the Diagnostics tab.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $namespaces = [];
        $routes_info = [];

        if (function_exists('rest_get_server')) {
            $server = rest_get_server();
            if ($server) {
                $namespaces = (array) $server->get_namespaces();
                $all_routes = (array) $server->get_routes();
                foreach ($all_routes as $route => $handlers) {
                    if (strpos($route, '/content-core/v1') === 0) {
                        $methods = [];
                        $has_permission_callback = false;
                        foreach ((array) $handlers as $handler) {
                            if (!empty($handler['methods']) && is_array($handler['methods'])) {
                                $methods = array_merge((array) $methods, array_keys((array) $handler['methods']));
                            }
                            if (!empty($handler['permission_callback'])) {
                                $has_permission_callback = true;
                            }
                        }
                        $routes_info[$route] = [
                            'methods' => array_unique((array) $methods),
                            'permission_callback' => $has_permission_callback
                        ];
                    }
                }
            }
        }

        // Active Redirect rules relevant to wp-json and wp-admin
        $redirect_settings = get_option('cc_redirect_settings', []);
        $redirect_interference = [];
        if (!empty($redirect_settings['custom_redirects'])) {
            foreach ($redirect_settings['custom_redirects'] as $rule) {
                $from = $rule['from_path'] ?? '';
                if (stripos($from, 'wp-json') !== false || stripos($from, 'wp-admin') !== false || stripos($from, 'content-core') !== false) {
                    $redirect_interference[] = $rule;
                }
            }
        }

        ?>
        <div class="cc-grid">
            <!-- REST & MU Plugins Card -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-rest-api"></span>
                        <?php _e('REST & MU Registry', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <div class="cc-data-group">
                        <span class="cc-data-label"><?php _e('REST Namespaces', 'content-core'); ?></span>
                        <div class="cc-data-scroll">
                            <?php
                            if (empty($namespaces)) {
                                echo '<em>' . __('No namespaces found.', 'content-core') . '</em>';
                            } else {
                                foreach ($namespaces as $ns) {
                                    echo '<div>' . esc_html($ns) . '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div class="cc-divider"></div>

                    <div class="cc-data-group">
                        <span class="cc-data-label"><?php _e('Active MU Plugins', 'content-core'); ?></span>
                        <div>
                            <?php
                            $mu_plugins = get_mu_plugins();
                            if (empty($mu_plugins)) {
                                echo '<div class="cc-help">' . __('None detected', 'content-core') . '</div>';
                            } else {
                                foreach ($mu_plugins as $file => $data) {
                                    echo '<div>';
                                    echo '<code>' . esc_html($file) . '</code>';
                                    echo '<span class="cc-help">' . esc_html($data['Name'] ?? 'No Name') . '</span>';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Redirects Card -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-randomize"></span>
                        <?php _e('Redirect Interference', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <?php if (empty($redirect_interference)): ?>
                        <div>
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><?php _e('No rules targeting system paths.', 'content-core'); ?></span>
                        </div>
                    <?php else: ?>
                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>From</th>
                                    <th>To</th>
                                    <th>Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($redirect_interference as $rule): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($rule['from_path']); ?></code></td>
                                        <td><code><?php echo esc_html($rule['to_path'] ?? '-'); ?></code></td>
                                        <td><?php echo esc_html($rule['status_code'] ?? '301'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="cc-help">
                        <?php _e('Monitors rules that might conflict with /wp-json or /wp-admin paths.', 'content-core'); ?>
                    </p>
                </div>
            </div>

            <!-- Routes Card -->
            <div class="cc-card cc-grid-full">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-visibility"></span>
                        <?php _e('Content Core v1 Route Registry', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <?php if (empty($routes_info)): ?>
                        <div>
                            <span class="dashicons dashicons-warning"></span>
                            <h3><?php _e('CRITICAL: No CC routes found.', 'content-core'); ?></h3>
                            <p class="cc-help">
                                <?php _e('The WP Registry does not contain any /content-core/v1 routes.', 'content-core'); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="cc-table cc-table-flush">
                            <thead>
                                <tr>
                                    <th><?php _e('Route', 'content-core'); ?></th>
                                    <th><?php _e('Methods', 'content-core'); ?></th>
                                    <th><?php _e('Permission Check', 'content-core'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($routes_info as $route => $info): ?>
                                    <tr>
                                        <td><strong><code><?php echo esc_html($route); ?></code></strong></td>
                                        <td><code><?php echo esc_html(implode(', ', $info['methods'])); ?></code></td>
                                        <td>
                                            <?php if ($info['permission_callback']): ?>
                                                <span class="cc-status-pill cc-status-healthy">Present</span>
                                            <?php else: ?>
                                                <span class="cc-status-pill cc-status-warning">Missing</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the per-page audit footer block.
     */
    public static function render_footer(): void
    {
        $screen = get_current_screen();
        $page_slug = sanitize_text_field($_GET['page'] ?? '');
        global $wp_scripts, $wp_styles;

        // Determine expected context
        $expected_namespace = 'content-core/v1';
        $is_cc_page = (strpos($screen->id, 'cc-') !== false || strpos($screen->id, 'content-core') !== false);

        if (!$is_cc_page && empty($_GET['cc_debug'])) {
            return;
        }

        ?>
        <div id="cc-runtime-audit-footer" class="cc-page">
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-visibility"></span>
                        <?php _e('Page-Level Audit Report', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-healthy">
                        <?php echo esc_html($page_slug); ?>
                    </span>
                </div>
                <div class="cc-card-body">
                    <div class="cc-grid">
                        <!-- Column 1: Context & Assets -->
                        <div>
                            <h3 class="cc-section-title"><?php _e('UI & Asset Context', 'content-core'); ?></h3>
                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Screen Context', 'content-core'); ?></span>
                                <div>
                                    <code>ID: <?php echo esc_html($screen->id); ?></code>
                                    <code>Namespace: <?php echo esc_html($expected_namespace); ?></code>
                                </div>
                            </div>

                            <div class="cc-divider"></div>

                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Enqueued Scripts', 'content-core'); ?></span>
                                <div class="cc-data-scroll">
                                    <?php
                                    foreach ($wp_scripts->queue as $handle) {
                                        $data = $wp_scripts->get_data($handle, 'data');
                                        $is_localized = !empty($data);
                                        echo '<div>';
                                        echo '<strong>' . esc_html($handle) . '</strong>' . ($is_localized ? ' <span>[Localized]</span>' : '');

                                        if (in_array($handle, ['cc-settings-js', 'cc-site-settings-app', 'cc-terms-manager', 'cc-admin-js', 'wp-api-fetch'])) {
                                            echo '<div>';
                                            echo '<pre>' . esc_html(substr($data, 0, 500)) . (strlen($data) > 500 ? '...' : '') . '</pre>';
                                            echo '</div>';
                                        }
                                        echo '</div>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- Column 2: Registry Verification -->
                        <div>
                            <h3 class="cc-section-title"><?php _e('Registry Verification', 'content-core'); ?></h3>
                            <?php
                            $route_found = false;
                            $matching_routes = [];
                            if (function_exists('rest_get_server')) {
                                $server = rest_get_server();
                                $all_routes = (array) $server->get_routes();
                                foreach ($all_routes as $route => $handlers) {
                                    if (strpos($route, '/' . $expected_namespace) === 0) {
                                        $route_found = true;
                                        $methods = [];
                                        foreach ((array) $handlers as $h) {
                                            if (!empty($h['methods']) && is_array($h['methods'])) {
                                                $methods = array_merge((array) $methods, array_keys((array) $h['methods']));
                                            }
                                        }
                                        $matching_routes[$route] = array_unique((array) $methods);
                                    }
                                }
                            }
                            ?>

                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Registry Status', 'content-core'); ?></span>
                                <?php if ($route_found): ?>
                                    <div>
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <span><?php _e('Namespace discovered', 'content-core'); ?></span>
                                    </div>
                                    <div class="cc-data-scroll">
                                        <?php foreach ($matching_routes as $r => $m): ?>
                                            <div>
                                                <code><?php echo esc_html($r); ?></code>
                                                <span>(<?php echo esc_html(implode(', ', $m)); ?>)</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div>
                                        <span class="dashicons dashicons-warning"></span>
                                        <div>
                                            <span><?php _e('Namespace NOT discovered', 'content-core'); ?></span>
                                            <span><?php _e('REST operations will likely fail.', 'content-core'); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="cc-divider"></div>

                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Diagnostic Indicators', 'content-core'); ?></span>
                                <div>
                                    <div>
                                        <span><?php _e('REST Init Fired', 'content-core'); ?></span>
                                        <span
                                            class="cc-status-pill <?php echo did_action('rest_api_init') ? 'cc-status-healthy' : 'cc-status-warning'; ?>">
                                            <?php echo did_action('rest_api_init') ? 'Yes' : 'No'; ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span><?php _e('Admin Capability', 'content-core'); ?></span>
                                        <span
                                            class="cc-status-pill <?php echo current_user_can('manage_options') ? 'cc-status-healthy' : 'cc-status-error'; ?>">
                                            <?php echo current_user_can('manage_options') ? 'OK' : 'Denied'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="cc-card-footer">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cc-diagnostics&tab=runtime-audit')); ?>"
                        class="cc-button-secondary">
                        <?php _e('Go to Full System Registry', 'content-core'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}


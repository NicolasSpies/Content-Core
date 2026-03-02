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
                        <div class="cc-data-scroll" style="max-height: 160px; overflow-y: auto; background: var(--cc-bg-soft); padding: 12px; border-radius: 6px; font-family: monospace; font-size: 11px; margin-top: 8px;">
                            <?php
                            if (empty($namespaces)) {
                                echo '<em>' . __('No namespaces found.', 'content-core') . '</em>';
                            } else {
                                foreach ($namespaces as $ns) {
                                    echo '<div style="margin-bottom:4px;">' . esc_html($ns) . '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div class="cc-divider"></div>

                    <div class="cc-data-group">
                        <span class="cc-data-label"><?php _e('Active MU Plugins', 'content-core'); ?></span>
                        <div style="margin-top: 8px;">
                            <?php
                            $mu_plugins = get_mu_plugins();
                            if (empty($mu_plugins)) {
                                echo '<div class="cc-help">' . __('None detected', 'content-core') . '</div>';
                            } else {
                                foreach ($mu_plugins as $file => $data) {
                                    echo '<div style="font-size:11px; margin-bottom:6px; display:flex; flex-direction:column;">';
                                    echo '<code style="font-weight:700;">' . esc_html($file) . '</code>';
                                    echo '<span class="cc-help" style="margin:0;">' . esc_html($data['Name'] ?? 'No Name') . '</span>';
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
                        <div style="display:flex; align-items:center; gap:8px; color:var(--cc-success);">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span style="font-size:13px; font-weight:600;"><?php _e('No rules targeting system paths.', 'content-core'); ?></span>
                        </div>
                    <?php else: ?>
                        <table class="cc-table">
                            <thead>
                                <tr>
                                    <th>From</th>
                                    <th>To</th>
                                    <th style="width:50px;">Code</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($redirect_interference as $rule): ?>
                                    <tr>
                                        <td><code><?php echo esc_html($rule['from_path']); ?></code></td>
                                        <td><code><?php echo esc_html($rule['to_path'] ?? '-'); ?></code></td>
                                        <td style="text-align:center;"><?php echo esc_html($rule['status_code'] ?? '301'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <p class="cc-help" style="margin-top:16px;">
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
                <div class="cc-card-body" style="padding:0;">
                    <?php if (empty($routes_info)): ?>
                        <div style="padding:40px; text-align:center;">
                            <span class="dashicons dashicons-warning" style="font-size:48px; width:48px; height:48px; color:var(--cc-error); opacity:0.3; margin-bottom:16px; display:block; margin-left:auto; margin-right:auto;"></span>
                            <h3 style="color:var(--cc-error); margin:0;"><?php _e('CRITICAL: No CC routes found.', 'content-core'); ?></h3>
                            <p class="cc-help"><?php _e('The WP Registry does not contain any /content-core/v1 routes.', 'content-core'); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="cc-table cc-table-flush">
                            <thead>
                                <tr>
                                    <th style="width:40%;"><?php _e('Route', 'content-core'); ?></th>
                                    <th><?php _e('Methods', 'content-core'); ?></th>
                                    <th style="width:180px; text-align:center;"><?php _e('Permission Check', 'content-core'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($routes_info as $route => $info): ?>
                                    <tr>
                                        <td><strong><code><?php echo esc_html($route); ?></code></strong></td>
                                        <td><code><?php echo esc_html(implode(', ', $info['methods'])); ?></code></td>
                                        <td style="text-align:center;">
                                            <?php if ($info['permission_callback']): ?>
                                                <span class="cc-status-pill cc-status-healthy" style="font-size:9px;">PRESENT</span>
                                            <?php else: ?>
                                                <span class="cc-status-pill cc-status-warning" style="font-size:9px;">MISSING</span>
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
        <div id="cc-runtime-audit-footer" class="cc-page" style="margin-top: 40px; padding: 0 20px 40px 180px;">
            <div class="cc-card" style="border: 2px solid var(--cc-accent-color); box-shadow: var(--cc-shadow-lg);">
                <div class="cc-card-header" style="background: rgba(var(--cc-accent-color-rgb), 0.05);">
                    <h2 style="color: var(--cc-accent-color);">
                        <span class="dashicons dashicons-visibility" style="color: inherit;"></span>
                        <?php _e('Page-Level Audit Report', 'content-core'); ?>
                    </h2>
                    <span class="cc-status-pill cc-status-healthy" style="opacity: 0.8;">
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
                                <div style="font-size: 11px; margin-top: 4px;">
                                    <code style="display:block; margin-bottom:4px;">ID: <?php echo esc_html($screen->id); ?></code>
                                    <code>Namespace: <?php echo esc_html($expected_namespace); ?></code>
                                </div>
                            </div>

                            <div class="cc-divider"></div>

                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Enqueued Scripts', 'content-core'); ?></span>
                                <div class="cc-data-scroll" style="height: 180px; overflow-y: auto; background: var(--cc-bg-soft); padding: 12px; border-radius: 6px; font-family: monospace; font-size: 10px; margin-top: 8px;">
                                    <?php
                                    foreach ($wp_scripts->queue as $handle) {
                                        $data = $wp_scripts->get_data($handle, 'data');
                                        $is_localized = !empty($data);
                                        echo '<div style="margin-bottom:6px; padding-bottom:4px; border-bottom:1px solid rgba(0,0,0,0.05);">';
                                        echo '<strong>' . esc_html($handle) . '</strong>' . ($is_localized ? ' <span style="color:var(--cc-success); font-weight:800;">[Localized]</span>' : '');
                                        
                                        if (in_array($handle, ['cc-settings-js', 'cc-site-settings-app', 'cc-terms-manager', 'cc-admin-js', 'wp-api-fetch'])) {
                                            echo '<div style="margin-top:4px; padding:6px; background:#fff; border-left:2px solid var(--cc-accent-color); overflow:hidden; text-overflow:ellipsis;">';
                                            echo '<pre style="margin:0; white-space:pre-wrap; font-size:9px;">' . esc_html(substr($data, 0, 500)) . (strlen($data) > 500 ? '...' : '') . '</pre>';
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
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 8px; color: var(--cc-success);">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <span style="font-weight: 700; font-size: 13px;"><?php _e('Namespace discovered', 'content-core'); ?></span>
                                    </div>
                                    <div class="cc-data-scroll" style="max-height: 200px; overflow-y: auto; background: var(--cc-bg-soft); padding: 12px; border-radius: 6px; font-family: monospace; font-size: 10px; margin-top: 12px;">
                                        <?php foreach ($matching_routes as $r => $m): ?>
                                            <div style="margin-bottom:4px;">
                                                <code style="color:var(--cc-accent-color); font-weight:700;"><?php echo esc_html($r); ?></code>
                                                <span style="opacity:0.6;">(<?php echo esc_html(implode(', ', $m)); ?>)</span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div style="margin-top: 8px; display: flex; align-items: center; gap: 8px; color: var(--cc-error); padding: 12px; background: rgba(var(--cc-error-rgb), 0.05); border-radius: 6px;">
                                        <span class="dashicons dashicons-warning"></span>
                                        <div>
                                            <span style="font-weight: 700; font-size: 13px; display: block;"><?php _e('Namespace NOT discovered', 'content-core'); ?></span>
                                            <span style="font-size: 11px;"><?php _e('REST operations will likely fail.', 'content-core'); ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="cc-divider"></div>
                            
                            <div class="cc-data-group">
                                <span class="cc-data-label"><?php _e('Diagnostic Indicators', 'content-core'); ?></span>
                                <div style="margin-top: 8px; font-size: 11px; display: flex; flex-direction: column; gap: 6px;">
                                    <div style="display:flex; justify-content:space-between;">
                                        <span><?php _e('REST Init Fired', 'content-core'); ?></span>
                                        <span class="cc-status-pill <?php echo did_action('rest_api_init') ? 'cc-status-healthy' : 'cc-status-warning'; ?>" style="font-size:8px;">
                                            <?php echo did_action('rest_api_init') ? 'YES' : 'NO'; ?>
                                        </span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between;">
                                        <span><?php _e('Admin Capability', 'content-core'); ?></span>
                                        <span class="cc-status-pill <?php echo current_user_can('manage_options') ? 'cc-status-healthy' : 'cc-status-error'; ?>" style="font-size:8px;">
                                            <?php echo current_user_can('manage_options') ? 'OK' : 'DENIED'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="cc-card-footer" style="padding: 16px 24px; background: var(--cc-bg-soft); border-top: 1px solid var(--cc-border); text-align: right;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=cc-diagnostics&tab=runtime-audit')); ?>" class="cc-button-secondary">
                        <?php _e('Go to Full System Registry', 'content-core'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
}


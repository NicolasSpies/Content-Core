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
                $namespaces = $server->get_namespaces();
                $all_routes = $server->get_routes();
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
        <div class="cc-card cc-card-full">
            <h2 style="margin-bottom:20px; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px;">
                <span class="dashicons dashicons-visibility"></span>
                <?php _e('Global Runtime Audit (System Registry)', 'content-core'); ?>
            </h2>

            <div class="cc-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <!-- Section 1: REST Namespaces & MU Plugins -->
                <div>
                    <div class="cc-data-group">
                        <span class="cc-data-label"><?php _e('REST Namespaces (from /wp-json)', 'content-core'); ?></span>
                        <div class="cc-data-value" style="max-height: 200px; overflow-y: auto; font-size: 11px;">
                            <?php
                            if (empty($namespaces)) {
                                echo '<em>' . __('No namespaces found.', 'content-core') . '</em>';
                            } else {
                                foreach ($namespaces as $ns) {
                                    echo '<code>' . esc_html($ns) . '</code><br>';
                                }
                            }
                            ?>
                        </div>
                    </div>

                    <div class="cc-data-group" style="margin-top:20px;">
                        <span class="cc-data-label"><?php _e('Active MU Plugins', 'content-core'); ?></span>
                        <div class="cc-data-value" style="font-size: 11px;">
                            <?php
                            $mu_plugins = get_mu_plugins();
                            if (empty($mu_plugins)) {
                                echo '<em>' . __('None detected', 'content-core') . '</em>';
                            } else {
                                foreach ($mu_plugins as $file => $data) {
                                    echo '<code>' . esc_html($file) . '</code> (' . esc_html($data['Name'] ?? 'No Name') . ')<br>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Redirect Interference -->
                <div>
                    <div class="cc-data-group">
                        <span
                            class="cc-data-label"><?php _e('Redirect Module Rules (Sensitive Paths)', 'content-core'); ?></span>
                        <div class="cc-data-value" style="font-size: 11px;">
                            <?php if (empty($redirect_interference)): ?>
                                <span
                                    style="color:green;"><?php _e('No rules detected targeting REST/Admin paths.', 'content-core'); ?></span>
                            <?php else: ?>
                                <table class="widefat striped" style="margin-top:10px;">
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
                        </div>
                    </div>
                </div>
            </div>

            <hr style="margin: 32px 0; border: 0; border-top: 1px solid var(--cc-border-light);">

            <div class="cc-data-group">
                <span class="cc-data-label"><?php _e('Content Core v1 Route Registry', 'content-core'); ?></span>
                <div class="cc-data-value" style="max-height: 400px; overflow-y: auto; font-size: 11px;">
                    <?php if (empty($routes_info)): ?>
                        <div style="padding:15px; background:#fff8f8; border-left:4px solid #d63638; color:#d63638;">
                            <strong><?php _e('CRITICAL: No CC routes found in WP Registry.', 'content-core'); ?></strong>
                        </div>
                    <?php else: ?>
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width:50%;">Route</th>
                                    <th>Methods</th>
                                    <th>Permission Callback</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($routes_info as $route => $info): ?>
                                    <tr>
                                        <td><strong><code><?php echo esc_html($route); ?></code></strong></td>
                                        <td><code><?php echo esc_html(implode(', ', $info['methods'])); ?></code></td>
                                        <td>
                                            <?php echo $info['permission_callback'] ?
                                                '<span style="color:green;">Present</span>' :
                                                '<span style="color:red; font-weight:700;">MISSING</span>'; ?>
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

        ?>
        <div id="cc-runtime-audit-footer"
            style="margin: 20px 20px 20px 160px; padding: 24px; background: #fff; border: 2px solid #2271b1; box-shadow: 0 4px 12px rgba(0,0,0,0.1); clear: both; position: relative; z-index: 99999;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0; font-size:18px; color:#2271b1; display:flex; align-items:center; gap:10px;">
                    <span class="dashicons dashicons-visibility"></span>
                    <?php _e('Page-Level Audit Report', 'content-core'); ?>
                </h3>
                <span style="font-size:11px; color:#646970;">Target: <?php echo esc_html($page_slug); ?></span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                <!-- Column 1: Context & Scripts -->
                <div>
                    <h4 style="margin:0 0 10px 0; font-size:13px; text-transform:uppercase; color:#646970;">
                        <?php _e('UI & Asset Context', 'content-core'); ?>
                    </h4>
                    <div style="font-size:12px; line-height:1.6;">
                        <strong>Screen ID:</strong> <code><?php echo esc_html($screen->id); ?></code><br>
                        <strong>Slug:</strong> <code><?php echo esc_html($page_slug); ?></code><br>
                        <strong>REST Base Expected:</strong> <code><?php echo esc_html($expected_namespace); ?></code>
                    </div>

                    <div style="margin-top:15px;">
                        <strong>Enqueued Scripts:</strong>
                        <div
                            style="max-height:150px; overflow-y:auto; background:#f6f7f7; padding:10px; border:1px solid #dcdcde; margin-top:5px; font-size:11px;">
                            <?php
                            foreach ($wp_scripts->queue as $handle) {
                                $data = $wp_scripts->get_data($handle, 'data');
                                $is_localized = !empty($data);
                                echo '<code>' . esc_html($handle) . '</code>' . ($is_localized ? ' <span style="color:green; font-weight:700;">[Localized]</span>' : '') . '<br>';

                                if (in_array($handle, ['cc-settings-js', 'cc-site-settings-app', 'cc-terms-manager', 'cc-admin-js', 'wp-api-fetch'])) {
                                    echo '<div style="margin:5px 0 10px 10px; padding:8px; background:#fff; border-left:3px solid #2271b1;">';
                                    echo '<strong>' . __('Localization Payload:', 'content-core') . '</strong><br>';
                                    echo '<pre style="white-space:pre-wrap; font-size:10px; color:#1d2327;">' . esc_html($data) . '</pre>';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Column 2: Registry Verification -->
                <div>
                    <h4 style="margin:0 0 10px 0; font-size:13px; text-transform:uppercase; color:#646970;">
                        <?php _e('Registry Verification', 'content-core'); ?>
                    </h4>
                    <div style="font-size:12px;">
                        <?php
                        $route_found = false;
                        $matching_routes = [];
                        if (function_exists('rest_get_server')) {
                            $server = rest_get_server();
                            $all_routes = $server->get_routes();
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

                        <strong>Registry Status for <code><?php echo esc_html($expected_namespace); ?></code>:</strong><br>
                        <?php if ($route_found): ?>
                            <span style="color:green; font-weight:700;">[OK] Namespace found in registry.</span>
                            <div style="margin-top:10px; background:#f0f6fb; padding:10px; border-left:4px solid #72aee6;">
                                <strong>Matching Routes:</strong><br>
                                <?php foreach ($matching_routes as $r => $m): ?>
                                    <code
                                        style="display:block; margin-top:3px;"><?php echo esc_html($r); ?> (<?php echo esc_html(implode(', ', $m)); ?>)</code>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="padding:10px; background:#fff8f8; border:1px solid #d63638; color:#d63638; margin-top:5px;">
                                <strong>[CRITICAL] Namespace NOT found in registry on this page load.</strong><br>
                                <span style="font-size:11px;">The JS app will fail to fetch data.</span>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top:15px; padding:10px; background:#f0f0f1; font-size:11px; color:#50575e;">
                            <strong>Diagnostic Notes:</strong><br>
                            - Check if <code>rest_api_init</code> has fired:
                            <?php echo did_action('rest_api_init') ? '<span style="color:green;">YES</span>' : '<span style="color:orange;">NO (Normal if not yet routed)</span>'; ?><br>
                            - Nonce verification:
                            <?php echo current_user_can('manage_options') ? '<span style="color:green;">Capable</span>' : '<span style="color:red;">Not Capable</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px; border-top:1px solid #dcdcde; padding-top:20px; text-align:right;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=cc-diagnostics&tab=runtime-audit')); ?>"
                    class="button"><?php _e('View Full System Registry', 'content-core'); ?></a>
            </div>
        </div>
        <?php
    }
}

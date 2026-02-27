<?php
namespace ContentCore\Admin;

/**
 * Error Log admin screen.
 *
 * Registered as:  Content Core → Diagnostics → Error Log
 * Slug:           cc-error-log
 *
 * Features:
 * - Paginated table with severity, time, message, file:line, screen
 * - Filter by severity + date range
 * - Clear log action
 * - Export as JSON
 * - NOT exposed via REST
 */
class ErrorLogScreen
{
    private ErrorLogger $logger;
    const PAGE_SIZE = 25;

    public function __construct(ErrorLogger $logger)
    {
        $this->logger = $logger;
    }

    public function init(): void
    {
        add_action('admin_post_cc_clear_error_log', [$this, 'handle_clear']);
        add_action('admin_post_cc_clear_old_error_log', [$this, 'handle_clear_old']);
        add_action('admin_post_cc_export_error_log', [$this, 'handle_export']);
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    public function handle_clear(): void
    {
        check_admin_referer('cc_error_log_action');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'content-core'));
        }
        $this->logger->clear();
        wp_safe_redirect(add_query_arg(['page' => 'cc-diagnostics', 'tab' => 'error-log', 'cc_msg' => 'cleared'], admin_url('admin.php')));
        exit;
    }

    /**
     * Clear only entries older than 24 hours.
     * Used from the dashboard "Clear resolved errors" button.
     */
    public function handle_clear_old(): void
    {
        check_admin_referer('cc_error_log_action');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'content-core'));
        }

        $cutoff = function_exists('current_time') ? (int) current_time('timestamp') - 86400 : time() - 86400;
        $this->logger->clear_before($cutoff);

        // Redirect back to the dashboard with a success notice
        wp_safe_redirect(add_query_arg(
            ['page' => 'content-core', 'cc_msg' => 'old_cleared'],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_export(): void
    {
        check_admin_referer('cc_error_log_action');
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'content-core'));
        }
        $entries = $this->logger->get_entries();
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="cc-error-log-' . date('Y-m-d') . '.json"');
        echo wp_json_encode($entries, JSON_PRETTY_PRINT);
        exit;
    }

    // -------------------------------------------------------------------------
    // Screen renderer
    // -------------------------------------------------------------------------

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $all_entries = $this->logger->get_entries(); // newest first

        // ---- Filters ----
        $filter_severity = sanitize_text_field($_GET['cc_severity'] ?? '');
        $filter_days = (int) ($_GET['cc_days'] ?? 0);
        $page = max(1, (int) ($_GET['paged'] ?? 1));

        $filtered = $all_entries;

        if ($filter_severity && in_array($filter_severity, ErrorLogger::SEVERITIES, true)) {
            $filtered = array_values(array_filter($filtered, fn($e) => ($e['severity'] ?? '') === $filter_severity));
        }

        if ($filter_days > 0) {
            $since = time() - ($filter_days * 86400);
            $filtered = array_values(array_filter($filtered, fn($e) => ($e['timestamp'] ?? 0) >= $since));
        }

        $total_filtered = count($filtered);
        $total_pages = max(1, (int) ceil($total_filtered / self::PAGE_SIZE));
        $page = min($page, $total_pages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $paged_entries = array_slice($filtered, $offset, self::PAGE_SIZE);

        $severity_colors = [
            'fatal' => '#d63638',
            'error' => '#d63638',
            'warning' => '#dba617',
            'notice' => '#2271b1',
            'deprecated' => '#8c8f94',
        ];

        $base_url = add_query_arg([
            'page' => 'cc-error-log',
            'cc_severity' => $filter_severity ?: false,
            'cc_days' => $filter_days ?: false,
        ], admin_url('admin.php'));

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1>
                    <?php _e('Error Log', 'content-core'); ?>
                </h1>
                <div style="font-size:13px; color:var(--cc-text-muted);">
                    <?php printf(
                        esc_html__('%d total entries captured', 'content-core'),
                        count($all_entries)
                    ); ?>
                </div>
            </div>

            <?php if (isset($_GET['cc_msg']) && $_GET['cc_msg'] === 'cleared'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>
                        <?php _e('Error log cleared.', 'content-core'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="cc-dashboard-grid">
                <!-- Toolbar: filters + actions -->
                <div class="cc-card cc-card-full" style="padding:20px 28px;">
                    <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; justify-content:space-between;">
                        <!-- Filters -->
                        <form method="get" action="<?php echo admin_url('admin.php'); ?>"
                            style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                            <input type="hidden" name="page" value="cc-error-log">

                            <div>
                                <label
                                    style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--cc-text-muted); margin-bottom:6px;">
                                    <?php _e('Severity', 'content-core'); ?>
                                </label>
                                <select name="cc_severity" style="height:34px; min-width:130px;">
                                    <option value="">
                                        <?php _e('All severities', 'content-core'); ?>
                                    </option>
                                    <?php foreach (ErrorLogger::SEVERITIES as $s): ?>
                                        <option value="<?php echo esc_attr($s); ?>" <?php selected($filter_severity, $s); ?>>
                                            <?php echo esc_html(ucfirst($s)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label
                                    style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--cc-text-muted); margin-bottom:6px;">
                                    <?php _e('Date Range', 'content-core'); ?>
                                </label>
                                <select name="cc_days" style="height:34px; min-width:140px;">
                                    <option value="0">
                                        <?php _e('All time', 'content-core'); ?>
                                    </option>
                                    <option value="1" <?php selected($filter_days, 1); ?>>
                                        <?php _e('Last 24 hours', 'content-core'); ?>
                                    </option>
                                    <option value="7" <?php selected($filter_days, 7); ?>>
                                        <?php _e('Last 7 days', 'content-core'); ?>
                                    </option>
                                    <option value="30" <?php selected($filter_days, 30); ?>>
                                        <?php _e('Last 30 days', 'content-core'); ?>
                                    </option>
                                </select>
                            </div>

                            <button type="submit" class="button button-primary" style="height:34px;">
                                <?php _e('Filter', 'content-core'); ?>
                            </button>

                            <?php if ($filter_severity || $filter_days > 0): ?>
                                <a href="<?php echo esc_url(add_query_arg('page', 'cc-error-log', admin_url('admin.php'))); ?>"
                                    class="button" style="height:34px; line-height:32px;">
                                    <?php _e('Reset', 'content-core'); ?>
                                </a>
                            <?php endif; ?>
                        </form>

                        <!-- Actions -->
                        <div style="display:flex; gap:10px;">
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                <input type="hidden" name="action" value="cc_export_error_log">
                                <?php wp_nonce_field('cc_error_log_action'); ?>
                                <button type="submit" class="button button-secondary">
                                    <span class="dashicons dashicons-download"
                                        style="margin-top:4px; margin-right:4px; font-size:16px;"></span>
                                    <?php _e('Export JSON', 'content-core'); ?>
                                </button>
                            </form>

                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>"
                                onsubmit="return confirm('<?php echo esc_js(__('Clear all log entries? This cannot be undone.', 'content-core')); ?>')">
                                <input type="hidden" name="action" value="cc_clear_error_log">
                                <?php wp_nonce_field('cc_error_log_action'); ?>
                                <button type="submit" class="button" style="color:#d63638; border-color:#d63638;">
                                    <span class="dashicons dashicons-trash"
                                        style="margin-top:4px; margin-right:4px; font-size:16px;"></span>
                                    <?php _e('Clear Log', 'content-core'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="cc-card cc-card-full" style="padding:0; overflow:hidden;">
                    <?php if (empty($paged_entries)): ?>
                        <div style="padding:48px 28px; text-align:center; color:var(--cc-text-muted);">
                            <span class="dashicons dashicons-yes-alt"
                                style="font-size:40px; width:40px; height:40px; color:#00a32a; display:block; margin:0 auto 12px;"></span>
                            <strong style="font-size:15px; display:block; margin-bottom:6px;">
                                <?php _e('No errors logged', 'content-core'); ?>
                            </strong>
                            <span style="font-size:13px;">
                                <?php _e('Content Core has not detected any errors matching your filter.', 'content-core'); ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <table class="widefat fixed striped" style="border:0; border-radius:0;">
                            <thead>
                                <tr>
                                    <th style="width:80px;">
                                        <?php _e('Severity', 'content-core'); ?>
                                    </th>
                                    <th style="width:155px;">
                                        <?php _e('Timestamp', 'content-core'); ?>
                                    </th>
                                    <th>
                                        <?php _e('Message', 'content-core'); ?>
                                    </th>
                                    <th style="width:220px;">
                                        <?php _e('File : Line', 'content-core'); ?>
                                    </th>
                                    <th style="width:140px;">
                                        <?php _e('Screen', 'content-core'); ?>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paged_entries as $entry):
                                    $sev = $entry['severity'] ?? 'notice';
                                    $color = $severity_colors[$sev] ?? '#646970';
                                    $time = isset($entry['timestamp']) ? date('Y-m-d H:i:s', (int) $entry['timestamp']) : '—';
                                    $trace = $entry['trace'] ?? null;
                                    $uid = 'cc-trace-' . md5($entry['message'] . $entry['timestamp']);
                                    ?>
                                    <tr>
                                        <td>
                                            <span
                                                style="display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; background:<?php echo esc_attr($color); ?>22; color:<?php echo esc_attr($color); ?>; border:1px solid <?php echo esc_attr($color); ?>44;">
                                                <?php echo esc_html(strtoupper($sev)); ?>
                                            </span>
                                        </td>
                                        <td style="font-size:12px; color:var(--cc-text-muted);">
                                            <?php echo esc_html($time); ?>
                                        </td>
                                        <td style="font-size:13px; word-break:break-word;">
                                            <?php echo esc_html($entry['message'] ?? ''); ?>
                                            <?php if ($trace): ?>
                                                <br>
                                                <a href="#"
                                                    onclick="document.getElementById('<?php echo esc_attr($uid); ?>').style.display = document.getElementById('<?php echo esc_attr($uid); ?>').style.display === 'none' ? 'block' : 'none'; return false;"
                                                    style="font-size:11px; color:var(--cc-text-muted);">
                                                    <?php _e('[stack trace]', 'content-core'); ?>
                                                </a>
                                                <pre id="<?php echo esc_attr($uid); ?>"
                                                    style="display:none; font-size:10px; white-space:pre-wrap; background:var(--cc-bg-soft); padding:8px; margin-top:6px; border-radius:4px; border:1px solid var(--cc-border);"><?php echo esc_html($trace); ?></pre>
                                            <?php endif; ?>
                                        </td>
                                        <td
                                            style="font-size:12px; font-family:monospace; word-break:break-word; color:var(--cc-text-muted);">
                                            <?php echo esc_html($entry['file'] ?? ''); ?>
                                            <?php if ($entry['line'] ?? 0): ?>
                                                <strong style="color:var(--cc-text);">:
                                                    <?php echo (int) $entry['line']; ?>
                                                </strong>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:12px; color:var(--cc-text-muted);">
                                            <?php echo esc_html($entry['screen'] ?? ''); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div
                                style="padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--cc-border);">
                                <span style="font-size:13px; color:var(--cc-text-muted);">
                                    <?php printf(
                                        esc_html__('Page %1$d of %2$d (%3$d entries)', 'content-core'),
                                        $page,
                                        $total_pages,
                                        $total_filtered
                                    ); ?>
                                </span>
                                <div style="display:flex; gap:6px;">
                                    <?php if ($page > 1): ?>
                                        <a href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base_url)); ?>"
                                            class="button button-small">←
                                            <?php _e('Prev', 'content-core'); ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($page < $total_pages): ?>
                                        <a href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base_url)); ?>"
                                            class="button button-small">
                                            <?php _e('Next', 'content-core'); ?> →
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Inline renderer (for embedding as a tab inside another page)
    // -------------------------------------------------------------------------

    /**
     * Render just the content — no outer <div class="wrap"> or page header.
     * Used when this screen is embedded as a tab inside render_diagnostics_page.
     */
    public function render_inline(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $all_entries = $this->logger->get_entries(); // newest first

        // ---- Filters ----
        $filter_severity = sanitize_text_field($_GET['cc_severity'] ?? '');
        $filter_days = (int) ($_GET['cc_days'] ?? 0);
        $page_num = max(1, (int) ($_GET['paged'] ?? 1));

        $filtered = $all_entries;

        if ($filter_severity && in_array($filter_severity, ErrorLogger::SEVERITIES, true)) {
            $filtered = array_values(array_filter($filtered, fn($e) => ($e['severity'] ?? '') === $filter_severity));
        }

        if ($filter_days > 0) {
            $since = time() - ($filter_days * 86400);
            $filtered = array_values(array_filter($filtered, fn($e) => ($e['timestamp'] ?? 0) >= $since));
        }

        $total_filtered = count($filtered);
        $total_pages = max(1, (int) ceil($total_filtered / self::PAGE_SIZE));
        $page_num = min($page_num, $total_pages);
        $offset = ($page_num - 1) * self::PAGE_SIZE;
        $paged_entries = array_slice($filtered, $offset, self::PAGE_SIZE);

        $severity_colors = [
            'fatal' => '#d63638',
            'error' => '#d63638',
            'warning' => '#dba617',
            'notice' => '#2271b1',
            'deprecated' => '#8c8f94',
        ];

        // Base URL preserves diagnostics page + tab + current filters
        $base_url = add_query_arg([
            'page' => 'cc-diagnostics',
            'tab' => 'error-log',
            'cc_severity' => $filter_severity ?: false,
            'cc_days' => $filter_days ?: false,
        ], admin_url('admin.php'));

        ?>

        <?php if (isset($_GET['cc_msg']) && $_GET['cc_msg'] === 'cleared'): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Error log cleared.', 'content-core'); ?></p>
            </div>
        <?php endif; ?>

        <div class="cc-dashboard-grid">
            <!-- Toolbar: filters + actions -->
            <div class="cc-card cc-card-full" style="padding:20px 28px;">
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; justify-content:space-between;">
                    <!-- Filters -->
                    <form method="get" action="<?php echo admin_url('admin.php'); ?>"
                        style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="cc-diagnostics">
                        <input type="hidden" name="tab" value="error-log">

                        <div>
                            <label
                                style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--cc-text-muted); margin-bottom:6px;">
                                <?php _e('Severity', 'content-core'); ?>
                            </label>
                            <select name="cc_severity" style="height:34px; min-width:130px;">
                                <option value=""><?php _e('All severities', 'content-core'); ?></option>
                                <?php foreach (ErrorLogger::SEVERITIES as $s): ?>
                                    <option value="<?php echo esc_attr($s); ?>" <?php selected($filter_severity, $s); ?>>
                                        <?php echo esc_html(ucfirst($s)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label
                                style="display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--cc-text-muted); margin-bottom:6px;">
                                <?php _e('Date Range', 'content-core'); ?>
                            </label>
                            <select name="cc_days" style="height:34px; min-width:140px;">
                                <option value="0"><?php _e('All time', 'content-core'); ?></option>
                                <option value="1" <?php selected($filter_days, 1); ?>>
                                    <?php _e('Last 24 hours', 'content-core'); ?>
                                </option>
                                <option value="7" <?php selected($filter_days, 7); ?>>
                                    <?php _e('Last 7 days', 'content-core'); ?>
                                </option>
                                <option value="30" <?php selected($filter_days, 30); ?>>
                                    <?php _e('Last 30 days', 'content-core'); ?>
                                </option>
                            </select>
                        </div>

                        <button type="submit" class="button button-primary" style="height:34px;">
                            <?php _e('Filter', 'content-core'); ?>
                        </button>

                        <?php if ($filter_severity || $filter_days > 0): ?>
                            <a href="<?php echo esc_url(add_query_arg(['page' => 'cc-diagnostics', 'tab' => 'error-log'], admin_url('admin.php'))); ?>"
                                class="button" style="height:34px; line-height:32px;">
                                <?php _e('Reset', 'content-core'); ?>
                            </a>
                        <?php endif; ?>
                    </form>

                    <!-- Actions -->
                    <div style="display:flex; gap:10px;">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="cc_export_error_log">
                            <?php wp_nonce_field('cc_error_log_action'); ?>
                            <button type="submit" class="button button-secondary">
                                <span class="dashicons dashicons-download"
                                    style="margin-top:4px; margin-right:4px; font-size:16px;"></span>
                                <?php _e('Export JSON', 'content-core'); ?>
                            </button>
                        </form>

                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>"
                            onsubmit="return confirm('<?php echo esc_js(__('Clear all log entries? This cannot be undone.', 'content-core')); ?>')">
                            <input type="hidden" name="action" value="cc_clear_error_log">
                            <?php wp_nonce_field('cc_error_log_action'); ?>
                            <button type="submit" class="button" style="color:#d63638; border-color:#d63638;">
                                <span class="dashicons dashicons-trash"
                                    style="margin-top:4px; margin-right:4px; font-size:16px;"></span>
                                <?php _e('Clear Log', 'content-core'); ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="cc-card cc-card-full" style="padding:0; overflow:hidden;">
                <?php if (empty($paged_entries)): ?>
                    <div style="padding:48px 28px; text-align:center; color:var(--cc-text-muted);">
                        <span class="dashicons dashicons-yes-alt"
                            style="font-size:40px; width:40px; height:40px; color:#00a32a; display:block; margin:0 auto 12px;"></span>
                        <strong style="font-size:15px; display:block; margin-bottom:6px;">
                            <?php _e('No errors logged', 'content-core'); ?>
                        </strong>
                        <span style="font-size:13px;">
                            <?php _e('Content Core has not detected any errors matching your filter.', 'content-core'); ?>
                        </span>
                    </div>
                <?php else: ?>
                    <table class="widefat fixed striped" style="border:0; border-radius:0;">
                        <thead>
                            <tr>
                                <th style="width:80px;"><?php _e('Severity', 'content-core'); ?></th>
                                <th style="width:155px;"><?php _e('Timestamp', 'content-core'); ?></th>
                                <th><?php _e('Message', 'content-core'); ?></th>
                                <th style="width:220px;"><?php _e('File : Line', 'content-core'); ?></th>
                                <th style="width:140px;"><?php _e('Screen', 'content-core'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($paged_entries as $entry):
                                $sev = $entry['severity'] ?? 'notice';
                                $color = $severity_colors[$sev] ?? '#646970';
                                $time = isset($entry['timestamp']) ? date('Y-m-d H:i:s', (int) $entry['timestamp']) : '—';
                                $trace = $entry['trace'] ?? null;
                                $uid = 'cc-trace-' . md5(($entry['message'] ?? '') . ($entry['timestamp'] ?? ''));
                                ?>
                                <tr>
                                    <td>
                                        <span
                                            style="display:inline-block; padding:2px 7px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; background:<?php echo esc_attr($color); ?>22; color:<?php echo esc_attr($color); ?>; border:1px solid <?php echo esc_attr($color); ?>44;">
                                            <?php echo esc_html(strtoupper($sev)); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px; color:var(--cc-text-muted);"><?php echo esc_html($time); ?></td>
                                    <td style="font-size:13px; word-break:break-word;">
                                        <?php echo esc_html($entry['message'] ?? ''); ?>
                                        <?php if ($trace): ?>
                                            <br>
                                            <a href="#"
                                                onclick="document.getElementById('<?php echo esc_attr($uid); ?>').style.display = document.getElementById('<?php echo esc_attr($uid); ?>').style.display === 'none' ? 'block' : 'none'; return false;"
                                                style="font-size:11px; color:var(--cc-text-muted);">
                                                <?php _e('[stack trace]', 'content-core'); ?>
                                            </a>
                                            <pre id="<?php echo esc_attr($uid); ?>"
                                                style="display:none; font-size:10px; white-space:pre-wrap; background:var(--cc-bg-soft); padding:8px; margin-top:6px; border-radius:4px; border:1px solid var(--cc-border);"><?php echo esc_html($trace); ?></pre>
                                        <?php endif; ?>
                                    </td>
                                    <td
                                        style="font-size:12px; font-family:monospace; word-break:break-word; color:var(--cc-text-muted);">
                                        <?php echo esc_html($entry['file'] ?? ''); ?>
                                        <?php if ($entry['line'] ?? 0): ?>
                                            <strong style="color:var(--cc-text);">: <?php echo (int) $entry['line']; ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:12px; color:var(--cc-text-muted);">
                                        <?php echo esc_html($entry['screen'] ?? ''); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div
                            style="padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid var(--cc-border);">
                            <span style="font-size:13px; color:var(--cc-text-muted);">
                                <?php printf(
                                    esc_html__('Page %1$d of %2$d (%3$d entries)', 'content-core'),
                                    $page_num,
                                    $total_pages,
                                    $total_filtered
                                ); ?>
                            </span>
                            <div style="display:flex; gap:6px;">
                                <?php if ($page_num > 1): ?>
                                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num - 1, $base_url)); ?>"
                                        class="button button-small">← <?php _e('Prev', 'content-core'); ?></a>
                                <?php endif; ?>
                                <?php if ($page_num < $total_pages): ?>
                                    <a href="<?php echo esc_url(add_query_arg('paged', $page_num + 1, $base_url)); ?>"
                                        class="button button-small"><?php _e('Next', 'content-core'); ?> →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

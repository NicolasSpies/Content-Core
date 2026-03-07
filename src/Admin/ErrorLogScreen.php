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
        add_action('rest_api_init', function () {
            $ns = \ContentCore\Plugin::get_instance()->get_rest_namespace();
            $controller = new \ContentCore\Admin\Rest\ErrorLogRestController($this->logger, $ns);
            $controller->register_routes();
        });
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
            $filtered = array_values(array_filter($filtered, function ($e) use ($filter_severity) {
                return ($e['severity'] ?? '') === $filter_severity;
            }));
        }

        if ($filter_days > 0) {
            $since = time() - ($filter_days * 86400);
            $filtered = array_values(array_filter($filtered, function ($e) use ($since) {
                return ($e['timestamp'] ?? 0) >= $since;
            }));
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
        <div class="cc-page">
            <div class="cc-header">
                <div>
                    <h1><?php _e('Error Log', 'content-core'); ?></h1>
                    <p class="cc-header-desc">
                        <?php printf(esc_html__('%d total entries captured', 'content-core'), count($all_entries)); ?>
                    </p>
                </div>
            </div>

            <?php if (isset($_GET['cc_msg']) && $_GET['cc_msg'] === 'cleared'): ?>
                <div class="cc-notice cc-status-healthy">
                    <p><?php _e('Error log cleared.', 'content-core'); ?></p>
                </div>
            <?php endif; ?>

            <div class="cc-grid">
                <!-- Toolbar: filters + actions -->
                <div class="cc-card cc-grid-full">
                    <div class="cc-card-body">
                        <div>
                            <!-- Filters -->
                            <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                                <input type="hidden" name="page" value="cc-error-log">

                                <div class="cc-field">
                                    <label class="cc-field-label">
                                        <?php _e('Severity', 'content-core'); ?>
                                    </label>
                                    <select name="cc_severity" class="cc-select">
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

                                <div class="cc-field">
                                    <label class="cc-field-label">
                                        <?php _e('Date Range', 'content-core'); ?>
                                    </label>
                                    <select name="cc_days" class="cc-select">
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

                                <button type="submit" class="cc-button-primary">
                                    <?php _e('Filter', 'content-core'); ?>
                                </button>

                                <?php if ($filter_severity || $filter_days > 0): ?>
                                    <a href="<?php echo esc_url(add_query_arg('page', 'cc-error-log', admin_url('admin.php'))); ?>"
                                        class="cc-button-secondary">
                                        <?php _e('Reset', 'content-core'); ?>
                                    </a>
                                <?php endif; ?>
                            </form>

                            <!-- Actions -->
                            <div>
                                <button type="button" class="cc-button-secondary"
                                    onclick="fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/export')); ?>', { headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(res => res.blob()).then(blob => { const url = window.URL.createObjectURL(blob); const a = document.createElement('a'); a.style.display = 'none'; a.href = url; a.download = 'cc-error-log-' + new Date().toISOString().slice(0, 10) + '.json'; document.body.appendChild(a); a.click(); window.URL.revokeObjectURL(url); });">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php _e('Export JSON', 'content-core'); ?>
                                </button>

                                <?php
                                $cutoff = function_exists('current_time') ? (int) current_time('timestamp') - 86400 : time() - 86400;
                                $has_active = false;
                                $has_resolved = false;
                                foreach ($all_entries as $entry) {
                                    if (($entry['timestamp'] ?? 0) >= $cutoff) {
                                        $has_active = true;
                                    } else {
                                        $has_resolved = true;
                                    }
                                }
                                ?>
                                <button type="button" class="cc-button-secondary"
                                    onclick="if(confirm('<?php echo esc_js(__('Clear resolved log entries (older than 24h)?', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear-old')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(async (res) => { window.location.href = window.location.href.split('&cc_msg=')[0] + '&cc_msg=cleared'; }); }"
                                    <?php echo !$has_resolved ? 'disabled' : ''; ?>>
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php _e('Clear Resolved', 'content-core'); ?>
                                </button>

                                <?php if ($filter_severity || $filter_days > 0): ?>
                                    <button type="button" class="cc-button-secondary"
                                        onclick="if(confirm('<?php echo esc_js(__('PERMANENTLY DELETE ALL entries matching current filters? This cannot be undone.', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear-filtered')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>', 'Content-Type': 'application/json' }, body: JSON.stringify({ severity: '<?php echo esc_js($filter_severity); ?>', days: <?php echo (int) $filter_days; ?> }) }).then(async (res) => { window.location.reload(); }); }">
                                        <span class="dashicons dashicons-filter"></span>
                                        <?php _e('Hard Delete Filtered', 'content-core'); ?>
                                    </button>
                                <?php endif; ?>

                                <button type="button" class="cc-button-secondary"
                                    onclick="if(confirm('<?php echo esc_js(__('PERMANENTLY DELETE ALL log entries? This cannot be undone.', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(async (res) => { window.location.href = window.location.href.split('&cc_msg=')[0] + '&cc_msg=cleared'; }); }"
                                    <?php echo empty($all_entries) ? 'disabled' : ''; ?>>
                                    <span class="dashicons dashicons-dismiss"></span>
                                    <?php _e('Hard Delete All', 'content-core'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Table -->
                <div class="cc-card cc-grid-full">
                    <?php if (empty($paged_entries)): ?>
                        <div class="cc-card-body">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <h2>
                                <?php _e('No errors logged', 'content-core'); ?>
                            </h2>
                            <p class="cc-help">
                                <?php _e('Content Core has not detected any errors matching your filter.', 'content-core'); ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div>
                            <table class="cc-table">
                                <thead>
                                    <tr>
                                        <th>
                                            <?php _e('Severity', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('Timestamp', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('Message', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('File : Line', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('Screen', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('Actions', 'content-core'); ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($paged_entries as $entry):
                                        $sev = $entry['severity'] ?? 'notice';
                                        $color = $severity_colors[$sev] ?? '#646970';
                                        $time = isset($entry['timestamp']) ? date('Y-m-d H:i:s', (int) $entry['timestamp']) : '—';
                                        $uid = 'cc-trace-' . md5(($entry['message'] ?? '') . ($entry['timestamp'] ?? ''));
                                        $is_active = isset($entry['timestamp']) && $entry['timestamp'] >= $cutoff;
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="cc-status-pill">
                                                    <?php echo esc_html(ucfirst($sev)); ?>
                                                </span>
                                                <div>
                                                    <?php echo $is_active ? __('ACTIVE', 'content-core') : __('RESOLVED', 'content-core'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo esc_html($time); ?>
                                            </td>
                                            <td>
                                                <div>
                                                    <?php echo esc_html($entry['message'] ?? ''); ?>
                                                </div>
                                                <?php if (!empty($entry['trace'])): ?>
                                                    <a href="#"
                                                        onclick="document.getElementById('<?php echo esc_attr($uid); ?>').style.display = document.getElementById('<?php echo esc_attr($uid); ?>').style.display === 'none' ? 'block' : 'none'; return false;">
                                                        <?php _e('[stack trace]', 'content-core'); ?>
                                                    </a>
                                                    <pre id="<?php echo esc_attr($uid); ?>"
                                                        class="cc-code-block"><?php echo esc_html($entry['trace']); ?></pre>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo esc_html($entry['file'] ?? ''); ?>:<strong>
                                                    <?php echo (int) ($entry['line'] ?? 0); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo esc_html($entry['screen'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <button type="button" class="cc-button-secondary cc-button-sm" onclick="if(confirm('<?php echo esc_js(__('Delete this entry?', 'content-core')); ?>')) { 
                                                        const btn = this;
                                                        btn.disabled = true;
                                                        fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/delete')); ?>', { 
                                                            method: 'POST', 
                                                            headers: { 
                                                                'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>',
                                                                'Content-Type': 'application/json'
                                                            },
                                                            body: JSON.stringify({
                                                                timestamp: <?php echo (int) ($entry['timestamp'] ?? 0); ?>,
                                                                message: <?php echo json_encode($entry['message'] ?? ''); ?>,
                                                                file: <?php echo json_encode($entry['file'] ?? ''); ?>
                                                            })
                                                        }).then(res => res.json()).then(data => {
                                                            if(data.success) {
                                                                btn.closest('tr').style.opacity = '0.3';
                                                                btn.closest('tr').style.pointerEvents = 'none';
                                                            } else {
                                                                btn.disabled = false;
                                                                alert(data.message || 'Error deleting entry');
                                                            }
                                                        }); 
                                                    }">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="cc-card cc-grid-full">
                        <span>
                            <?php printf(
                                esc_html__('Page %1$d of %2$d (%3$d entries)', 'content-core'),
                                $page,
                                $total_pages,
                                $total_filtered
                            ); ?>
                        </span>
                        <div>
                            <?php if ($page > 1): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $page - 1, $base_url)); ?>"
                                    class="cc-button-secondary">←
                                    <?php _e('Prev', 'content-core'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="<?php echo esc_url(add_query_arg('paged', $page + 1, $base_url)); ?>"
                                    class="cc-button-secondary">
                                    <?php _e('Next', 'content-core'); ?> →
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
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
            $filtered = array_values(array_filter($filtered, function ($e) use ($filter_severity) {
                return ($e['severity'] ?? '') === $filter_severity;
            }));
        }

        if ($filter_days > 0) {
            $since = time() - ($filter_days * 86400);
            $filtered = array_values(array_filter($filtered, function ($e) use ($since) {
                return ($e['timestamp'] ?? 0) >= $since;
            }));
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

        $cutoff = function_exists('current_time') ? (int) current_time('timestamp') - 86400 : time() - 86400;

        ?>

        <?php if (isset($_GET['cc_msg']) && $_GET['cc_msg'] === 'cleared'): ?>
            <div class="cc-notice cc-status-healthy">
                <p><?php _e('Error log cleared.', 'content-core'); ?></p>
            </div>
        <?php endif; ?>

        <div class="cc-grid">
            <!-- Toolbar: filters + actions -->
            <div class="cc-card cc-grid-full">
                <div class="cc-card-body">
                    <div>
                        <!-- Filters -->
                        <form method="get" action="<?php echo admin_url('admin.php'); ?>">
                            <input type="hidden" name="page" value="cc-diagnostics">
                            <input type="hidden" name="tab" value="error-log">

                            <div class="cc-field">
                                <label class="cc-field-label">
                                    <?php _e('Severity', 'content-core'); ?>
                                </label>
                                <select name="cc_severity" class="cc-select">
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

                            <div class="cc-field">
                                <label class="cc-field-label">
                                    <?php _e('Date Range', 'content-core'); ?>
                                </label>
                                <select name="cc_days" class="cc-select">
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

                            <button type="submit" class="cc-button-primary">
                                <?php _e('Filter', 'content-core'); ?>
                            </button>
                        </form>

                        <!-- Actions -->
                        <div>
                            <button type="button" class="cc-button-secondary"
                                onclick="fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/export')); ?>', { headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(res => res.blob()).then(blob => { const url = window.URL.createObjectURL(blob); const a = document.createElement('a'); a.style.display = 'none'; a.href = url; a.download = 'cc-error-log-' + new Date().toISOString().slice(0, 10) + '.json'; document.body.appendChild(a); a.click(); window.URL.revokeObjectURL(url); });">
                                <span class="dashicons dashicons-download"></span>
                                <?php _e('Export JSON', 'content-core'); ?>
                            </button>
                            <?php if ($filter_severity || $filter_days > 0): ?>
                                <button type="button" class="cc-button-secondary"
                                    onclick="if(confirm('<?php echo esc_js(__('PERMANENTLY DELETE ALL entries matching current filters? This cannot be undone.', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear-filtered')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>', 'Content-Type': 'application/json' }, body: JSON.stringify({ severity: '<?php echo esc_js($filter_severity); ?>', days: <?php echo (int) $filter_days; ?> }) }).then(async (res) => { window.location.reload(); }); }">
                                    <span class="dashicons dashicons-filter"></span>
                                    <?php _e('Hard Delete Filtered', 'content-core'); ?>
                                </button>
                            <?php endif; ?>

                            <button type="button" class="cc-button-secondary"
                                onclick="if(confirm('<?php echo esc_js(__('Clear resolved log entries (older than 24h)?', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear-old')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(async (res) => { window.location.reload(); }); }">
                                <span class="dashicons dashicons-trash"></span>
                                <?php _e('Clear Resolved', 'content-core'); ?>
                            </button>
                            <button type="button" class="cc-button-secondary"
                                onclick="if(confirm('<?php echo esc_js(__('PERMANENTLY DELETE ALL log entries? This cannot be undone.', 'content-core')); ?>')) { fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/clear')); ?>', { method: 'POST', headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' } }).then(async (res) => { window.location.reload(); }); }"
                                <?php echo empty($all_entries) ? 'disabled' : ''; ?>>
                                <span class="dashicons dashicons-dismiss"></span>
                                <?php _e('Hard Delete All', 'content-core'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cc-card cc-grid-full">
                <div>
                    <table class="cc-table">
                        <thead>
                            <tr>
                                <th>
                                    <?php _e('Severity', 'content-core'); ?>
                                </th>
                                <th>
                                    <?php _e('Timestamp', 'content-core'); ?>
                                </th>
                                <th>
                                    <?php _e('Message', 'content-core'); ?>
                                </th>
                                <th>
                                    <?php _e('File : Line', 'content-core'); ?>
                                </th>
                                <th>
                                    <?php _e('Screen', 'content-core'); ?>
                                </th>
                                <th>
                                    <?php _e('Actions', 'content-core'); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($paged_entries)): ?>
                                <tr>
                                    <td colspan="5">
                                        <?php _e('No errors found.', 'content-core'); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($paged_entries as $entry):
                                    $sev = $entry['severity'] ?? 'notice';
                                    $color = $severity_colors[$sev] ?? '#646970';
                                    $time = isset($entry['timestamp']) ? date('Y-m-d H:i:s', (int) $entry['timestamp']) : '—';
                                    $uid = 'cc-trace-inline-' . md5(($entry['message'] ?? '') . ($entry['timestamp'] ?? ''));
                                    $is_active = isset($entry['timestamp']) && $entry['timestamp'] >= $cutoff;
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="cc-status-pill">
                                                <?php echo esc_html(ucfirst($sev)); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo esc_html($time); ?>
                                        </td>
                                        <td>
                                            <div>
                                                <?php echo esc_html($entry['message'] ?? ''); ?>
                                            </div>
                                            <?php if (!empty($entry['trace'])): ?>
                                                <a href="#"
                                                    onclick="document.getElementById('<?php echo esc_attr($uid); ?>').style.display = document.getElementById('<?php echo esc_attr($uid); ?>').style.display === 'none' ? 'block' : 'none'; return false;">
                                                    <?php _e('[stack trace]', 'content-core'); ?>
                                                </a>
                                                <pre id="<?php echo esc_attr($uid); ?>"
                                                    class="cc-code-block"><?php echo esc_html($entry['trace']); ?></pre>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo esc_html($entry['file'] ?? ''); ?>:<strong>
                                                <?php echo (int) ($entry['line'] ?? 0); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php echo esc_html($entry['screen'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <button type="button" class="cc-button-secondary cc-button-sm" onclick="if(confirm('<?php echo esc_js(__('Delete this entry?', 'content-core')); ?>')) { 
                                                    const btn = this;
                                                    btn.disabled = true;
                                                    fetch('<?php echo esc_url(rest_url(\ContentCore\Plugin::get_instance()->get_rest_namespace() . '/tools/error-log/delete')); ?>', { 
                                                        method: 'POST', 
                                                        headers: { 
                                                            'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>',
                                                            'Content-Type': 'application/json'
                                                        },
                                                        body: JSON.stringify({
                                                            timestamp: <?php echo (int) ($entry['timestamp'] ?? 0); ?>,
                                                            message: <?php echo json_encode($entry['message'] ?? ''); ?>,
                                                            file: <?php echo json_encode($entry['file'] ?? ''); ?>
                                                        })
                                                    }).then(res => res.json()).then(data => {
                                                        if(data.success) {
                                                            btn.closest('tr').style.opacity = '0.3';
                                                            btn.closest('tr').style.pointerEvents = 'none';
                                                        } else {
                                                            btn.disabled = false;
                                                            alert(data.message || 'Error deleting entry');
                                                        }
                                                    }); 
                                                }">
                                                <span class="dashicons dashicons-trash"></span>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="cc-card cc-grid-full">
                    <span>
                        <?php printf(
                            esc_html__('Page %1$d of %2$d', 'content-core'),
                            $page_num,
                            $total_pages
                        ); ?>
                    </span>
                    <div>
                        <?php if ($page_num > 1): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $page_num - 1, $base_url)); ?>"
                                class="cc-button-secondary">←
                                <?php _e('Prev', 'content-core'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($page_num < $total_pages): ?>
                            <a href="<?php echo esc_url(add_query_arg('paged', $page_num + 1, $base_url)); ?>"
                                class="cc-button-secondary">
                                <?php _e('Next', 'content-core'); ?> →
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

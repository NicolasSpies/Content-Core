<?php
namespace ContentCore\Admin;

/**
 * Class AdminMenu
 *
 * Centralizes the admin navigation for Content Core.
 * Ensures a consistent hierarchy and order of submenus.
 */
class AdminMenu
{
    /**
     * Initialize the admin menu hooks
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_post_cc_flush_rewrite_rules', [$this, 'handle_flush_rewrite_rules']);
        add_action('admin_post_cc_clear_expired_transients', [$this, 'handle_clear_expired_transients']);
        add_action('admin_post_cc_clear_all_transients', [$this, 'handle_clear_all_transients']);
        add_action('admin_post_cc_clear_plugin_caches', [$this, 'handle_clear_plugin_caches']);
        add_action('admin_post_cc_flush_object_cache', [$this, 'handle_flush_object_cache']);

        add_filter('admin_footer_text', '__return_empty_string');
        add_filter('update_footer', '__return_empty_string', 11);
    }

    /**
     * Enqueue modern assets
     */
    public function enqueue_admin_assets($hook): void
    {
        // Only enqueue the dashboard styles on Content Core pages
        if (strpos($hook, 'content-core') === false && strpos($hook, 'cc_') === false) {
            return;
        }

        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_script('cc-admin-js');
        wp_enqueue_script('jquery-ui-sortable');
    }

    /**
     * Register the main menu and submenus
     */
    public function register_menu(): void
    {
        // Add the top-level "Parent" item
        add_menu_page(
            __('Content Core', 'content-core'),
            __('Content Core', 'content-core'),
            'manage_options',
            'content-core',
            [$this, 'render_main_dashboard'],
            'dashicons-layout',
            30
        );

        $plugin = \ContentCore\Plugin::get_instance();

        // Submenu: Field Groups (Standard WP route for the CPT)
        if ($plugin->is_module_active('custom_fields')) {
            add_submenu_page(
                'content-core',
                __('Field Groups', 'content-core'),
                __('Field Groups', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_field_group'
            );
        }

        // Submenu: Post Types (CPT Builder)
        if ($plugin->is_module_active('content_types')) {
            add_submenu_page(
                'content-core',
                __('Post Types', 'content-core'),
                __('Post Types', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_post_type_def'
            );

            // Submenu: Taxonomies (Taxonomy Builder)
            add_submenu_page(
                'content-core',
                __('Taxonomies', 'content-core'),
                __('Taxonomies', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_taxonomy_def'
            );
        }
 
        // Submenu: Language Mapping (Management UI)
        if ($plugin->is_module_active('language_mapping')) {
            add_submenu_page(
                'content-core',
                __('Language Mapping', 'content-core'),
                __('Language Mapping', 'content-core'),
                'manage_options',
                'content-core-language-mapping',
                function() {
                    $plugin = \ContentCore\Plugin::get_instance();
                    $module = $plugin->get_module('language_mapping');
                    if ($module instanceof \ContentCore\Modules\LanguageMapping\LanguageMappingModule) {
                        $admin = new \ContentCore\Modules\LanguageMapping\Admin\LanguageMappingAdmin($module);
                        $admin->render_page();
                    } else {
                        error_log('Language Mapping module not found or invalid: ' . gettype($module));
                        if (is_admin()) {
                            echo '<div class="wrap"><h1>Language Mapping</h1><p>Module integration error. Please check logs.</p></div>';
                        }
                    }
                }
            );
        }

        // Submenu: API (Information & Documentation)
        if ($plugin->is_module_active('rest_api')) {
            add_submenu_page(
                'content-core',
                __('REST API', 'content-core'),
                __('REST API', 'content-core'),
                'manage_options',
                'cc-api-info',
                [$this, 'render_api_page']
            );
        }


        // Rename the first submenu item (which defaults to the same name as the top-level)
        global $submenu;
        if (isset($submenu['content-core'])) {
            $submenu['content-core'][0][0] = __('Dashboard', 'content-core');
        }
    }

    /**
     * Render the main Dashboard
     */
    public function render_main_dashboard(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $cache_service = new CacheService();
        $snapshot = $cache_service->get_snapshot();

        $format_bytes = function($bytes) {
            if ($bytes <= 0) return '0 B';
            $base = log($bytes, 1024);
            $suffixes = ['B', 'KB', 'MB', 'GB', 'TB'];
            return round(pow(1024, $base - floor($base)), 2) . ' ' . $suffixes[floor($base)];
        };

        $last_expired = $cache_service->get_last_action_info('expired_transients');
        $last_all = $cache_service->get_last_action_info('all_transients');
        $last_cc = $cache_service->get_last_action_info('cc_caches');
        $last_obj = $cache_service->get_last_action_info('object_cache');

        $object_cache_active = $snapshot['object_cache']['enabled'];
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php _e('Content Core Dashboard', 'content-core'); ?></h1>
            </div>


            <?php 
            settings_errors('cc_dashboard'); 

            if (isset($_GET['cc_action'])) {
                $bytes = isset($_GET['cc_bytes']) ? (int)$_GET['cc_bytes'] : 0;
                $count = isset($_GET['cc_count']) ? (int)$_GET['cc_count'] : 0;
                $msg = '';
                
                switch ($_GET['cc_action']) {
                    case 'expired_cleared':
                        $msg = sprintf(__('Cleared %d expired transients (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'all_cleared':
                        $msg = sprintf(__('Cleared ALL transients: %d items removed (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'cc_cleared':
                        $msg = sprintf(__('Cleared Content Core caches: %d items removed (%s).', 'content-core'), $count, $format_bytes($bytes));
                        break;
                    case 'obj_flushed':
                        $msg = __('Object cache flushed successfully.', 'content-core');
                        break;
                }

                if ($msg) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
                }
            }
            ?>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div class="cc-card">
                    <h2><?php _e('Headless API', 'content-core'); ?></h2>
                    <p><?php _e('Your API is active at:', 'content-core'); ?></p>
                    <input type="text" class="regular-text" style="width: 100%; font-family: monospace; background: var(--cc-bg-soft); border: 1px solid var(--cc-border); padding: 8px;" value="<?php echo esc_url(rest_url('content-core/v1')); ?>" readonly>
                </div>

                <div class="cc-card">
                    <h2><?php _e('Cache Management', 'content-core'); ?></h2>
                    <table class="cc-mini-table" style="width: 100%; margin-bottom: 15px;">
                        <tr>
                            <td><?php _e('Expired Transients (temporary cache entries)', 'content-core'); ?>:</td>
                            <td align="right"><strong><?php echo (int)$snapshot['expired']['count']; ?></strong> (<?php echo $format_bytes($snapshot['expired']['bytes']); ?>)</td>
                        </tr>
                        <tr>
                            <td><?php _e('Total Transients', 'content-core'); ?>:</td>
                            <td align="right"><strong><?php echo (int)$snapshot['transients']['count']; ?></strong> (<?php echo $format_bytes($snapshot['transients']['bytes']); ?>)</td>
                        </tr>
                        <tr>
                            <td><?php _e('Content Core Caches', 'content-core'); ?>:</td>
                            <td align="right"><strong><?php echo (int)$snapshot['cc_cache']['count']; ?></strong> (<?php echo $format_bytes($snapshot['cc_cache']['bytes']); ?>)</td>
                        </tr>
                        <tr>
                            <td><?php _e('Object Cache', 'content-core'); ?>:</td>
                            <td align="right">
                                <?php if ($snapshot['object_cache']['enabled']) : ?>
                                    <span style="color: #008a20; font-weight: bold;"><?php _e('Active', 'content-core'); ?></span>
                                <?php else : ?>
                                    <span style="color: #646970; font-weight: bold;"><?php _e('Inactive', 'content-core'); ?></span>
                                <?php endif; ?>
                                <span style="opacity: 0.6; font-size: 11px;">(<?php echo $snapshot['object_cache']['dropin'] ? 'Drop-in found' : 'No drop-in'; ?>)</span>
                            </td>
                        </tr>
                    </table>

                    <style>
                        .cc-cache-section { margin-bottom: 20px; }
                        .cc-cache-section-header { 
                            cursor: pointer; 
                            padding: 10px 12px; 
                            background: #f0f0f1; 
                            border-radius: 4px; 
                            margin-bottom: 10px;
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                        }
                        .cc-cache-section-header:hover { background: #e0e0e3; }
                        .cc-cache-section-header .dashicons { transition: transform 0.2s; }
                        .cc-cache-section.collapsed .cc-cache-section-header .dashicons { transform: rotate(-90deg); }
                        .cc-cache-section.collapsed .cc-cache-section-content { display: none; }
                        .cc-cache-danger-btn {
                            width: 100%;
                            border: 1px solid #d63638 !important;
                            background: transparent !important;
                            color: #d63638 !important;
                            border-radius: 4px;
                        }
                        .cc-cache-danger-btn:hover {
                            background: #d63638 !important;
                            color: #fff !important;
                        }
                        .cc-cache-danger-btn:disabled {
                            opacity: 0.5;
                            cursor: not-allowed;
                        }
                        .cc-cache-safe-btn {
                            width: 100%;
                            border-radius: 4px;
                        }
                        .cc-cache-help-text {
                            font-size: 11px;
                            color: #646970;
                            margin-top: 4px;
                            display: block;
                        }
                        .cc-last-cleared {
                            font-size: 11px;
                            color: #646970;
                            margin-top: 6px;
                        }
                    </style>

                    <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        var toggleLinks = document.querySelectorAll('.cc-cache-toggle');
                        toggleLinks.forEach(function(link) {
                            link.addEventListener('click', function(e) {
                                e.preventDefault();
                                var section = this.closest('.cc-cache-section');
                                section.classList.toggle('collapsed');
                            });
                        });

                        var confirmCheckbox = document.getElementById('cc_confirm_all_transients');
                        var dangerousBtn = document.getElementById('cc_clear_all_btn');
                        if (confirmCheckbox && dangerousBtn) {
                            confirmCheckbox.addEventListener('change', function() {
                                dangerousBtn.disabled = !this.checked;
                            });
                            dangerousBtn.disabled = true;
                        }

                        var dangerousForm = document.getElementById('cc_dangerous_form');
                        if (dangerousForm) {
                            dangerousForm.addEventListener('submit', function(e) {
                                if (!confirm('<?php echo esc_js(__('This will delete ALL transients from the database. Continue?', 'content-core')); ?>')) {
                                    e.preventDefault();
                                    return false;
                                }
                            });
                        }
                    });
                    </script>

                    <!-- Safe Cache Actions -->
                    <div class="cc-cache-section">
                        <div class="cc-cache-section-header" style="background: #e6f4ea; cursor: default;">
                            <strong><?php _e('Safe Cache Actions', 'content-core'); ?></strong>
                        </div>
                        <div class="cc-cache-section-content">
                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-bottom: 12px;">
                                <input type="hidden" name="action" value="cc_clear_plugin_caches">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <button type="submit" class="button button-primary cc-cache-safe-btn"><?php _e('Clear CC Caches', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Deletes only Content Core cache entries.', 'content-core'); ?></span>
                                <?php if ($last_cc): ?>
                                <div class="cc-last-cleared"><?php echo esc_html(sprintf(__('Last cleared: %s', 'content-core'), $last_cc['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>

                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                                <input type="hidden" name="action" value="cc_clear_expired_transients">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <button type="submit" class="button button-secondary cc-cache-safe-btn" style="background: #fff;"><?php _e('Clear Expired Transients', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Deletes only expired entries. Active ones remain untouched.', 'content-core'); ?></span>
                                <?php if ($last_expired): ?>
                                <div class="cc-last-cleared"><?php echo esc_html(sprintf(__('Last cleared: %s', 'content-core'), $last_expired['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                        <div class="cc-cache-section-content">
                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-bottom: 12px;">
                                <input type="hidden" name="action" value="cc_clear_plugin_caches">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <button type="submit" class="button button-primary cc-cache-safe-btn"><?php _e('Clear CC Caches', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Deletes only Content Core cache entries.', 'content-core'); ?></span>
                                <?php if ($last_cc): ?>
                                <div class="cc-last-cleared"><?php echo esc_html(sprintf(__('Last cleared: %s', 'content-core'), $last_cc['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>

                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                                <input type="hidden" name="action" value="cc_clear_expired_transients">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <button type="submit" class="button button-secondary cc-cache-safe-btn" style="background: #fff;"><?php _e('Clear Expired Transients', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Deletes only expired entries. Active ones remain untouched.', 'content-core'); ?></span>
                                <?php if ($last_expired): ?>
                                <div class="cc-last-cphp echo esc_htmlleared"><?(sprintf(__('Last cleared: %s', 'content-core'), $last_expired['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>

                    <!-- Advanced / Dangerous Actions -->
                    <div class="cc-cache-section collapsed">
                        <div class="cc-cache-section-header" onclick="document.querySelectorAll('.cc-cache-section')[1].classList.toggle('collapsed');">
                            <strong><?php _e('Advanced / Dangerous Actions', 'content-core'); ?></strong>
                            <span class="dashicons dashicons-arrow-down"></span>
                        </div>
                        <div class="cc-cache-section-content">
                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" id="cc_dangerous_form" style="margin-bottom: 12px;">
                                <input type="hidden" name="action" value="cc_clear_all_transients">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <div style="margin-bottom: 8px;">
                                    <label style="font-size: 12px; display: block; color: #d63638; cursor: pointer;">
                                        <input type="checkbox" id="cc_confirm_all_transients"> 
                                        <?php _e('I confirm this will delete ALL WordPress transients, including other plugins.', 'content-core'); ?>
                                    </label>
                                </div>
                                <button type="submit" id="cc_clear_all_btn" class="button cc-cache-danger-btn" disabled><?php _e('Clear ALL Transients', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Deletes all transient cache entries from WordPress.', 'content-core'); ?></span>
                                <?php if ($last_all): ?>
                                <div class="cc-last-cleared"><?php echo esc_html(sprintf(__('Last cleared: %s', 'content-core'), $last_all['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>

                            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                                <input type="hidden" name="action" value="cc_flush_object_cache">
                                <?php wp_nonce_field('cc_cache_nonce'); ?>
                                <button type="submit" class="button cc-cache-danger-btn" <?php echo $object_cache_active ? '' : 'disabled'; ?>><?php _e('Flush Object Cache', 'content-core'); ?></button>
                                <span class="cc-cache-help-text"><?php _e('Flushes external object cache if available.', 'content-core'); ?></span>
                                <?php if ($last_obj): ?>
                                <div class="cc-last-cleared"><?php echo esc_html(sprintf(__('Last cleared: %s', 'content-core'), $last_obj['timestamp'])); ?></div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Moved from Tools -->
            <div class="cc-card" style="margin-top: 24px;">
                <h2><?php _e('System Maintenance', 'content-core'); ?></h2>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                    <input type="hidden" name="action" value="cc_flush_rewrite_rules">
                    <?php wp_nonce_field('cc_flush_rules_nonce'); ?>
                    <p><?php _e('Flush your rewrite rules if you experience 404 errors after adding new Post Types or Taxonomies.', 'content-core'); ?></p>
                    <input type="submit" class="button button-primary" value="<?php _e('Flush Rewrite Rules', 'content-core'); ?>">
                </form>
            </div>

            <div class="cc-card" style="margin-top: 24px;">
                <h2><?php _e('Admin UI Health', 'content-core'); ?></h2>
                <p><?php _e('Diagnostic information for Content Core admin interfaces. Use this to verify that layouts are loading correctly.', 'content-core'); ?></p>
                
                <h3><?php _e('Current Screen Info', 'content-core'); ?></h3>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 10px; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 200px;"><?php _e('Property', 'content-core'); ?></th>
                            <th><?php _e('Value', 'content-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $screen = get_current_screen();
                        global $hook_suffix;
                        $screen_data = [
                            'Screen ID'   => $screen ? $screen->id : 'N/A',
                            'Base'        => $screen ? $screen->base : 'N/A',
                            'Post Type'   => $screen ? $screen->post_type : 'N/A',
                            'Hook Suffix' => $hook_suffix ?: 'N/A',
                        ];
                        foreach ($screen_data as $prop => $val) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($prop); ?></strong></td>
                            <td><code><?php echo esc_html($val); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <h3><?php _e('Asset Enqueue Status', 'content-core'); ?></h3>
                <p style="font-size: 12px; color: #646970; margin-bottom: 10px;">
                    <em><?php _e('Note: Post-edit specific assets (cc-post-edit, cc-metabox-ui) are intentionally not enqueued on the Dashboard. They should show as "Registered" here if the files exist.', 'content-core'); ?></em>
                </p>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 200px;"><?php _e('Handle', 'content-core'); ?></th>
                            <th><?php _e('Details', 'content-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?php _e('Plugin Version', 'content-core'); ?></strong></td>
                            <td><code><?php echo esc_html($plugin->get_version()); ?></code></td>
                        </tr>
                        <?php 
                        $asset_map = [
                            'cc-admin-modern' => ['type' => 'style', 'path' => 'assets/css/admin.css'],
                            'cc-post-edit'    => ['type' => 'style', 'path' => 'assets/css/post-edit.css'],
                            'cc-metabox-ui'   => ['type' => 'style', 'path' => 'assets/css/metabox-ui.css'],
                            'cc-admin-js'     => ['type' => 'script', 'path' => 'assets/js/admin.js', 'handle' => 'cc-admin-js']
                        ];
                        
                        foreach ($asset_map as $label => $info) : 
                            $handle = $info['handle'] ?? $label;
                            $type = $info['type'];
                            $file_path = CONTENT_CORE_PLUGIN_DIR . $info['path'];
                            $exists = file_exists($file_path);
                            
                            $is_registered = ($type === 'style') ? wp_styles()->query($handle) : wp_scripts()->query($handle);
                            $is_enqueued = ($type === 'style') ? wp_style_is($handle, 'enqueued') : wp_script_is($handle, 'enqueued');
                            
                            if (!$is_registered) {
                                $status = __('Missing', 'content-core');
                                $style = 'color: #d63638;';
                            } elseif ($is_enqueued) {
                                $status = __('Enqueued', 'content-core');
                                $style = 'color: #008a20;';
                            } else {
                                $status = __('Registered', 'content-core');
                                $style = 'color: #2271b1;';
                            }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($label); ?> (<?php echo strtoupper($type); ?>)</strong></td>
                            <td>
                                <span style="<?php echo $style; ?> font-weight: bold;"><?php echo esc_html($status); ?></span>
                                <?php if ($is_registered) : 
                                    $obj = ($type === 'style') ? wp_styles()->registered[$handle] : wp_scripts()->registered[$handle];
                                    $src = $obj->src ?? 'N/A';
                                    $ver = $obj->ver ?? 'N/A';
                                ?>
                                    <div style="font-size: 11px; margin-top: 4px; opacity: 0.8;">
                                        <strong>Src:</strong> <code><?php echo esc_html($src); ?></code><br>
                                        <strong>Ver:</strong> <code><?php echo esc_html($ver); ?></code><br>
                                        <strong>File Exists:</strong> <code><?php echo $exists ? 'true' : 'false'; ?></code>
                                    </div>
                                <?php else: ?>
                                    <div style="font-size: 11px; margin-top: 4px; opacity: 0.8;">
                                        <strong>File Exists:</strong> <code><?php echo $exists ? 'true' : 'false'; ?></code>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <tr id="cc-js-health-check">
                            <td><strong><?php _e('JS Runtime Status', 'content-core'); ?></strong></td>
                            <td id="cc-js-status-val"><?php _e('Checking...', 'content-core'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                var $status = $('#cc-js-status-val');
                if (window.ContentCoreHealth && window.ContentCoreHealth.fieldGroupAdminLoaded) {
                    $status.html('<span style="color: #008a20;">' + <?php echo wp_json_encode(__('Healthy (Builder JS Localized)', 'content-core')); ?> + '</span>');
                } else {
                    // Check if admin.js is working by setting a generic flag
                    if (typeof jQuery !== 'undefined') {
                        $status.html('<span style="color: #008a20;">' + <?php echo wp_json_encode(__('Healthy (jQuery Active)', 'content-core')); ?> + '</span>');
                    } else {
                        $status.html('<span style="color: #d63638;">' + <?php echo wp_json_encode(__('Error: JS Runtime Failure', 'content-core')); ?> + '</span>');
                    }
                }
            });
        </script>
        <?php
    }

    /**
     * Render the REST API Info page
     */
    public function render_api_page(): void
    {
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php _e('REST API Reference', 'content-core'); ?></h1>
            </div>

            <div class="cc-card">
                <h2><?php _e('Introduction', 'content-core'); ?></h2>
                <p><?php _e('Content Core provides dedicated, high-performance REST API endpoints for your headless application. All responses return clean, production-ready JSON.', 'content-core'); ?></p>
            </div>

            <div class="cc-card">
                <h2><?php _e('Endpoints', 'content-core'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 300px;"><?php _e('Endpoint', 'content-core'); ?></th>
                            <th><?php _e('Description', 'content-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/content-core/v1/post/{type}/{id}</code></td>
                            <td><?php _e('Get a single post by ID and type, including all custom fields.', 'content-core'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/content-core/v1/posts/{type}</code></td>
                            <td><?php _e('Query multiple posts of a specific type. Supports pagination.', 'content-core'); ?></td>
                        </tr>
                        <tr>
                            <td><code>/content-core/v1/options/{slug}</code></td>
                            <td><?php _e('Get all custom fields for a specific options page.', 'content-core'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="cc-card">
                <h2><?php _e('Global Custom Fields Object', 'content-core'); ?></h2>
                <p><?php _e('Content Core also attaches a "customFields" object to standard WordPress REST API post responses for easy integration.', 'content-core'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Handle clear expired transients via admin_post
     */
    public function handle_clear_expired_transients(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_expired_transients();

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=expired_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle clear ALL transients via admin_post
     */
    public function handle_clear_all_transients(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_all_transients();

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=all_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle clear plugin caches via admin_post
     */
    public function handle_clear_plugin_caches(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $service = new CacheService();
        $res = $service->clear_content_core_caches();

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=cc_cleared&cc_count=' . $res['count'] . '&cc_bytes=' . $res['bytes']));
        exit;
    }

    /**
     * Handle flush object cache via admin_post
     */
    public function handle_flush_object_cache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_cache_nonce');

        $cache_service = new CacheService();
        $cache_service->update_last_action('object_cache', 0, 0);

        wp_cache_flush();

        wp_safe_redirect(admin_url('admin.php?page=content-core&cc_action=obj_flushed'));
        exit;
    }

    /**
     * Handle rewrite rules flushing via admin_post
     */
    public function handle_flush_rewrite_rules(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'content-core'));
        }

        check_admin_referer('cc_flush_rules_nonce');

        flush_rewrite_rules();

        add_settings_error('cc_dashboard', 'flushed', __('Rewrite rules flushed successfully.', 'content-core'), 'updated');
        set_transient('settings_errors', get_settings_errors(), 30);

        wp_safe_redirect(admin_url('admin.php?page=content-core&settings-updated=true'));
        exit;
    }
}

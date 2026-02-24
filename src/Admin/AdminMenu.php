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

        // Submenu: Options Pages
        if ($plugin->is_module_active('options_pages')) {
            add_submenu_page(
                'content-core',
                __('Options Pages', 'content-core'),
                __('Options Pages', 'content-core'),
                'manage_options',
                'edit.php?post_type=cc_options_page'
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

        // Submenu: Tools
        add_submenu_page(
            'content-core',
            __('Tools', 'content-core'),
            __('Tools', 'content-core'),
            'manage_options',
            'cc-tools',
            [$this, 'render_tools_page']
        );

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
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php _e('Content Core Dashboard', 'content-core'); ?></h1>
            </div>

            <div class="cc-card">
                <h2><?php _e('Welcome to Content Core', 'content-core'); ?></h2>
                <p><?php _e('Content Core is your modular headless CMS framework for WordPress. Use the menu on the left to manage your field groups, post types, and taxonomies.', 'content-core'); ?></p>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div class="cc-card">
                    <h2><?php _e('Quick Links', 'content-core'); ?></h2>
                    <ul style="margin:0; padding-left: 20px;">
                        <?php if ($plugin->is_module_active('custom_fields')) : ?>
                            <li style="margin-bottom: 8px;"><a href="<?php echo admin_url('post-new.php?post_type=cc_field_group'); ?>"><?php _e('Create a Field Group', 'content-core'); ?></a></li>
                        <?php endif; ?>
                        
                        <?php if ($plugin->is_module_active('content_types')) : ?>
                            <li style="margin-bottom: 8px;"><a href="<?php echo admin_url('post-new.php?post_type=cc_post_type_def'); ?>"><?php _e('Define a New Post Type', 'content-core'); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php if ($plugin->is_module_active('rest_api')) : ?>
                    <div class="cc-card">
                        <h2><?php _e('Headless API', 'content-core'); ?></h2>
                        <p><?php _e('Your API is active at:', 'content-core'); ?></p>
                        <code style="background: var(--cc-bg-soft); padding: 8px 12px; border-radius: 4px; display: block; border: 1px solid var(--cc-border);">
                            <?php echo esc_url(get_rest_url(null, 'content-core/v1')); ?>
                        </code>
                    </div>
                <?php endif; ?>
            </div>
        </div>
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
     * Render the Tools page
     */
    public function render_tools_page(): void
    {
        $plugin = \ContentCore\Plugin::get_instance();
        if (isset($_POST['cc_flush_rules']) && check_admin_referer('cc_flush_rules_nonce')) {
            flush_rewrite_rules();
            add_settings_error('cc_tools', 'flushed', __('Rewrite rules flushed successfully.', 'content-core'), 'updated');
        }

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1><?php _e('Content Core Tools', 'content-core'); ?></h1>
            </div>
            
            <?php settings_errors('cc_tools'); ?>

            <div class="cc-card">
                <h2><?php _e('System Maintenance', 'content-core'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('cc_flush_rules_nonce'); ?>
                    <p><?php _e('Flush your rewrite rules if you experience 404 errors after adding new Post Types or Taxonomies.', 'content-core'); ?></p>
                    <input type="submit" name="cc_flush_rules" class="button button-primary" value="<?php _e('Flush Rewrite Rules', 'content-core'); ?>">
                </form>
            </div>

            <div class="cc-card">
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
                    <em><?php _e('Note: Post-edit specific assets (cc-post-edit, cc-metabox-ui) are intentionally not enqueued on this Tools page. They should show as "Registered" here if the files exist.', 'content-core'); ?></em>
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
        </div>
        <?php
    }
}

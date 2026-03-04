<?php
namespace ContentCore\Admin;

/**
 * Class ApiReferenceRenderer
 *
 * Encapsulates the rendering logic for the REST API reference page.
 */
class ApiReferenceRenderer
{
    /**
     * Render the REST API Info page
     */
    public function render(): void
    {
        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header">
                <h1>
                    <?php _e('REST API Reference', 'content-core'); ?>
                </h1>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Introduction', 'content-core'); ?>
                </h2>
                <p>
                    <?php _e('Content Core provides dedicated, high-performance REST API endpoints for your headless application. All responses return clean, production-ready JSON.', 'content-core'); ?>
                </p>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Endpoints', 'content-core'); ?>
                </h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>
                                <?php _e('Endpoint', 'content-core'); ?>
                            </th>
                            <th>
                                <?php _e('Description', 'content-core'); ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/post/{type}/{id}</code>
                            </td>
                            <td>
                                <?php _e('Get a single post by ID and type, including all custom fields.', 'content-core'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/posts/{type}</code>
                            </td>
                            <td>
                                <?php _e('Query multiple posts of a specific type. Supports pagination.', 'content-core'); ?>
                            </td>
                        </tr>
                        <tr>
                            <td><code>/<?php echo \ContentCore\Plugin::get_instance()->get_rest_namespace(); ?>/options/{slug}</code>
                            </td>
                            <td>
                                <?php _e('Get all custom fields for a specific options page.', 'content-core'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="cc-card">
                <h2>
                    <?php _e('Global Custom Fields Object', 'content-core'); ?>
                </h2>
                <p>
                    <?php _e('Content Core also attaches a "customFields" object to standard WordPress REST API post responses for easy integration.', 'content-core'); ?>
                </p>
            </div>
        </div>
        <?php
    }
}

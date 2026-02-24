<?php
namespace ContentCore\Modules\OptionsPages;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\OptionsPages\Data\OptionsPagePostType;

class OptionsPagesModule implements ModuleInterface
{

    /**
     * Initialize the Options Pages module
     *
     * @return void
     */
    public function init(): void
    {
        // Register the underlying Custom Post Type that stores the definitions
        add_action('init', [$this, 'register_post_type']);

        // Register Admin Hook
        if (is_admin()) {
            $admin = new \ContentCore\Modules\OptionsPages\Admin\OptionsPageAdmin();
            $admin->register();
        }

        // Register REST API Route
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register the CPT
     */
    public function register_post_type(): void
    {
        $post_type = new OptionsPagePostType();
        $post_type->register();
    }

    /**
     * Register the custom REST route for Options Pages
     */
    public function register_rest_routes(): void
    {
        register_rest_route('content-core/v1', '/options/(?P<slug>[a-zA-Z0-9-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_options_page_rest_response'],
            'permission_callback' => [$this, 'check_options_page_rest_permissions'],
        ]);
    }

    /**
     * Check permissions for reading the options page via REST
     */
    public function check_options_page_rest_permissions(\WP_REST_Request $request)
    {
        $slug = $request->get_param('slug');

        // Find the options page post
        $page = get_page_by_path($slug, OBJECT, OptionsPagePostType::POST_TYPE);

        if (!$page || $page->post_status !== 'publish') {
            return new \WP_Error('not_found', 'Options page not found.', ['status' => 404]);
        }

        // If it's private, require edit_posts capability as basic protection.
        // Currently, our cc_options_page definition doesn't have a specific public/private meta toggle yet,
        // but we can default to public read-only for headless, or require auth if requested.
        // For standard headless delivery, we'll allow public GET.
        return true;
    }

    /**
     * Return the formatted fields for the options page
     */
    public function get_options_page_rest_response(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = $request->get_param('slug');
        $assignment_key = 'cc_option_page_' . $slug;

        $fields = \ContentCore\Modules\CustomFields\Data\FieldRegistry::get_fields_for_post_type($assignment_key);
        $output = [];

        if (empty($fields)) {
            return rest_ensure_response((object)$output);
        }

        // Instantiate RestApiModule to reuse the exact identical formatter
        // Note: format_value_for_rest needs to be public in RestApiModule.php. We will change that next.
        $rest_formatter = new \ContentCore\Modules\RestApi\RestApiModule();

        foreach ($fields as $name => $schema) {
            $default = $schema['default_value'] ?? null;
            $option_key = 'cc_option_' . $slug . '_' . $name;

            // Fetch from wp_options
            $raw_value = get_option($option_key, '');

            if ('' === $raw_value) {
                // If it doesn't exist in the DB, get_option returns the default value parameter we passed ('').
                // We fallback to schema default.
                $raw_value = ('' !== $default) ? $default : null;
            }

            $output[$name] = $rest_formatter->format_value_for_rest($raw_value, $schema);
        }

        return rest_ensure_response((object)$output);
    }
}
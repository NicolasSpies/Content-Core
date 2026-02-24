<?php
namespace ContentCore\Modules\RestApi;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\CustomFields\Data\FieldRegistry;

class RestApiModule implements ModuleInterface
{

    /**
     * Initialize the REST API module
     *
     * @return void
     */
    public function init(): void
    {
        // Add customFields to standard WP routes
        add_action('rest_api_init', [$this, 'register_rest_fields']);

        // Register dedicated v1 endpoints
        add_action('rest_api_init', [$this, 'register_v1_routes']);
    }

    /**
     * Register the dedicated content-core/v1 routes
     */
    public function register_v1_routes(): void
    {
        $namespace = 'content-core/v1';

        // Single post endpoint
        register_rest_route($namespace, '/post/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_single_post'],
            'permission_callback' => [$this, 'check_read_permission'],
            'args' => [
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);

        // List endpoint
        register_rest_route($namespace, '/posts/(?P<type>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_posts_list'],
            'permission_callback' => [$this, 'check_read_permission'],
            'args' => [
                'per_page' => [
                    'default' => 10,
                    'type' => 'integer',
                ],
                'page' => [
                    'default' => 1,
                    'type' => 'integer',
                ],
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);

        // Options page endpoint
        register_rest_route($namespace, '/options/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_options'],
            'permission_callback' => [$this, 'check_options_permission'],
            'args' => [
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                ],
            ],
        ]);
    }

    /**
     * Register the `customFields` object for all supported REST post types
     */
    public function register_rest_fields(): void
    {
        $post_types = get_post_types(['show_in_rest' => true], 'names');

        foreach ($post_types as $post_type) {
            // Do not expose customFields on our internal field group CPT just in case it ever gets show_in_rest=true
            if ('cc_field_group' === $post_type) {
                continue;
            }

            register_rest_field(
                $post_type,
                'customFields',
            [
                'get_callback' => [$this, 'get_custom_fields_value'],
                'update_callback' => null, // Phase 2 is read-only for headless consumption
                'schema' => [
                    'description' => __('Custom Fields assigned via Content Core', 'content-core'),
                    'type' => 'object',
                    'context' => ['view', 'edit'],
                ],
            ]
            );
        }
    }

    /**
     * Callback for v1 single post endpoint
     */
    public function get_v1_single_post(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = $request->get_param('id');

        if (!is_numeric($id) || $id <= 0) {
            return new \WP_Error(
                'rest_post_invalid_id',
                __('Invalid post ID.', 'content-core'),
            ['status' => 404]
                );
        }

        $type = $request->get_param('type');

        $post = get_post($id);
        if (!$post || $post->post_type !== $type) {
            return new \WP_REST_Response(['message' => 'Post not found'], 404);
        }

        $data = $this->prepare_post_v1_data($post, $request);
        return new \WP_REST_Response($data, 200);
    }

    /**
     * Callback for v1 posts list endpoint
     */
    public function get_v1_posts_list(\WP_REST_Request $request): \WP_REST_Response
    {
        $type = $request->get_param('type');
        $per_page = $request->get_param('per_page');
        $page = $request->get_param('page');

        $args = [
            'post_type' => $type,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'post_status' => 'publish',
        ];

        $query = new \WP_Query($args);
        $results = [];

        foreach ($query->posts as $post) {
            $results[] = $this->prepare_post_v1_data($post, $request);
        }

        $response = new \WP_REST_Response($results, 200);
        $response->header('X-WP-Total', $query->found_posts);
        $response->header('X-WP-TotalPages', $query->max_num_pages);

        return $response;
    }

    /**
     * Callback for v1 options endpoint
     */
    public function get_v1_options(\WP_REST_Request $request): \WP_REST_Response
    {
        $slug = $request->get_param('slug');

        // Build context for options page
        $context = [
            'options_page' => $slug,
        ];

        $fields = FieldRegistry::get_fields_for_context($context);
        $custom_fields = [];

        foreach ($fields as $name => $schema) {
            $value = get_option($name, null);
            $default = $schema['default_value'] ?? null;

            if (null === $value || '' === $value) {
                if (!$request->get_param('includeEmptyFields')) {
                    continue;
                }
                $value = ('' !== $default) ? $default : null;
            }

            $custom_fields[$name] = $this->format_value_for_v1($value, $schema, $request);
        }

        $data = [
            'contentCoreVersion' => 'v1',
            'slug' => $slug,
            'customFields' => (object)$custom_fields,
        ];

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Helper to prepare post data for v1 response
     */
    private function prepare_post_v1_data(\WP_Post $post, \WP_REST_Request $request): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'date' => $post->post_date_gmt,
            'contentCoreVersion' => 'v1',
            'customFields' => $this->get_custom_fields_value_internal($post, $request),
        ];
    }

    /**
     * Permission check for reading posts
     */
    public function check_read_permission(\WP_REST_Request $request): bool
    {
        $id = $request->get_param('id');
        if ($id) {
            $post_type = get_post_type($id);
            $post_type_obj = get_post_type_object($post_type);
            if (!$post_type_obj || !current_user_can($post_type_obj->cap->read_post, $id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Permission check for options pages
     */
    public function check_options_permission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * internal helper for legacy and v1 logic
     */
    private function get_custom_fields_value_internal(\WP_Post $post, \WP_REST_Request $request): object
    {
        $post_id = $post->ID;
        $post_type = $post->post_type;

        $output = [];

        $context = [
            'post_id' => $post_id,
            'post_type' => $post_type,
            'page_template' => get_post_meta($post_id, '_wp_page_template', true),
            'taxonomy_terms' => FieldRegistry::get_context_taxonomy_terms($post_id),
        ];

        $fields = FieldRegistry::get_fields_for_context($context);

        foreach ($fields as $name => $schema) {
            $raw_value = get_post_meta($post_id, $name, true);
            $default = $schema['default_value'] ?? null;

            if ('' === $raw_value && !metadata_exists('post', $post_id, $name)) {
                if (!$request->get_param('includeEmptyFields') && !is_a($request, '\WP_REST_Request')) {
                // Legacy check (if not a v1 request, we might want to be more inclusive or follow old rules)
                }
                $raw_value = ('' !== $default) ? $default : null;
            }

            if (null === $raw_value && !$request->get_param('includeEmptyFields')) {
                continue;
            }

            $output[$name] = $this->format_value_for_v1($raw_value, $schema, $request);
        }

        return (object)$output;
    }

    /**
     * Legacy callback to retrieve and format the custom fields for a specific post
     */
    public function get_custom_fields_value(array $post_obj): object
    {
        // Guard for invalid objects (e.g. Gutenberg global-styles preloading)
        if (!isset($post_obj['id']) || !is_numeric($post_obj['id'])) {
            return (object)[];
        }

        $post_id = absint($post_obj['id']);
        if ($post_id <= 0) {
            return (object)[];
        }

        $post = get_post($post_id);
        if (!$post) {
            return (object)[];
        }

        // Mock a request for default behavior
        $request = new \WP_REST_Request('GET', '/');

        return $this->get_custom_fields_value_internal($post, $request);
    }

    /**
     * Format values for v1 API, ensuring strict contracts and parameter support.
     */
    public function format_value_for_v1($value, array $schema, \WP_REST_Request $request)
    {
        $type = $schema['type'] ?? 'text';

        if (null === $value || '' === $value) {
            if ('boolean' === $type)
                return false;
            if ('repeater' === $type)
                return [];
            return null;
        }

        switch ($type) {
            case 'boolean':
                return !empty($value);

            case 'number':
                if (is_numeric($value)) {
                    return $value + 0;
                }
                return null;

            case 'image':
            case 'file':
                return $this->format_media_v1($value, $request);

            case 'gallery':
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                $ids = is_array($value) ? $value : [];
                $formatted_gallery = [];
                foreach ($ids as $id) {
                    $media = $this->format_media_v1($id, $request);
                    if ($media) {
                        $formatted_gallery[] = $media;
                    }
                }
                return $formatted_gallery;

            case 'repeater':
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                if (!is_array($value) || empty($value)) {
                    return [];
                }

                $formatted_rows = [];
                $sub_fields = $schema['sub_fields'] ?? [];
                $sub_schemas = [];
                foreach ($sub_fields as $sub) {
                    $sub_schemas[$sub['name']] = $sub;
                }

                foreach ($value as $rowWrapper) {
                    if (!is_array($rowWrapper))
                        continue;
                    $formatted_row = [];
                    foreach ($sub_schemas as $sub_name => $sub_schema) {
                        $sub_val = $rowWrapper[$sub_name] ?? null;
                        $formatted_row[$sub_name] = $this->format_value_for_v1($sub_val, $sub_schema, $request);
                    }
                    $formatted_rows[] = $formatted_row;
                }
                return $formatted_rows;

            case 'group':
                if (is_string($value)) {
                    $value = json_decode($value, true);
                }
                if (!is_array($value)) {
                    return null;
                }

                $sub_fields = $schema['sub_fields'] ?? [];
                $formatted_group = [];
                foreach ($sub_fields as $sub) {
                    $sub_name = $sub['name'] ?? '';
                    $sub_val = $value[$sub_name] ?? null;
                    $formatted_group[$sub_name] = $this->format_value_for_v1($sub_val, $sub, $request);
                }
                return (object)$formatted_group;

            default:
                return (string)$value;
        }
    }

    /**
     * Format media objects for v1 API
     */
    private function format_media_v1($id, \WP_REST_Request $request)
    {
        $attachment_id = absint($id);
        if (!$attachment_id)
            return null;

        $url = wp_get_attachment_url($attachment_id);
        if (!$url)
            return null;

        $mode = $request->get_param('includeMedia') ?: 'basic';

        $media = [
            'id' => $attachment_id,
            'url' => $url,
            'mime_type' => get_post_mime_type($attachment_id) ?: '',
        ];

        if ('full' === $mode) {
            $meta = wp_get_attachment_metadata($attachment_id);
            $media['alt'] = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
            $media['title'] = get_the_title($attachment_id);
            $media['sizes'] = $this->prepare_media_sizes($attachment_id, $meta);
        }

        return $media;
    }

    /**
     * Prepare available image sizes for full media mode
     */
    private function prepare_media_sizes(int $id, $meta): array
    {
        $sizes = [];
        if (empty($meta['sizes']))
            return $sizes;

        foreach ($meta['sizes'] as $size_name => $size_data) {
            $src = wp_get_attachment_image_src($id, $size_name);
            if ($src) {
                $sizes[$size_name] = [
                    'url' => $src[0],
                    'width' => $src[1],
                    'height' => $src[2],
                ];
            }
        }
        return $sizes;
    }

    /**
     * Legacy shim to maintain compatibility with wp/v2 extension
     */
    public function format_value_for_rest($value, array $schema)
    {
        // For standard WP REST fields, we simulate a default request
        $request = new \WP_REST_Request('GET', '/');
        return $this->format_value_for_v1($value, $schema, $request);
    }
}
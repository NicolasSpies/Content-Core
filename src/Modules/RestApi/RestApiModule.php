<?php
namespace ContentCore\Modules\RestApi;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\CustomFields\Data\FieldRegistry;

class RestApiModule implements ModuleInterface
{
    /**
     * Internal diagnostics storage
     */
    private static $diagnostics = [
        'route_count' => 0,
        'namespace_registered' => false,
        'last_error' => null,
    ];

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

        // Record diagnostics at the end of registration
        add_action('rest_api_init', [$this, 'record_diagnostics'], 999);
    }

    /**
     * Register the dedicated content-core/v1 routes
     */
    public function register_v1_routes(): void
    {
        $namespace = \ContentCore\Plugin::REST_NAMESPACE . '/' . \ContentCore\Plugin::REST_VERSION;

        // Single post endpoint
        register_rest_route($namespace, '/post/(?P<type>[a-zA-Z0-9_-]+)/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_single_post'],
            'permission_callback' => [$this, 'check_read_permission'],
            'args' => [
                'type' => [
                    'sanitize_callback' => 'sanitize_key',
                ],
                'id' => [
                    'sanitize_callback' => 'absint',
                ],
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Global SEO endpoint
        register_rest_route($namespace, '/seo', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_seo'],
            'permission_callback' => '__return_true', // Open to everyone
        ]);

        // List endpoint
        register_rest_route($namespace, '/posts/(?P<type>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_posts_list'],
            'permission_callback' => [$this, 'check_read_permission'],
            'args' => [
                'type' => [
                    'sanitize_callback' => 'sanitize_key',
                ],
                'per_page' => [
                    'default' => 10,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'default' => 1,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Options page endpoint
        register_rest_route($namespace, '/options/(?P<slug>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_options'],
            'permission_callback' => [$this, 'check_options_permission'],
            'args' => [
                'slug' => [
                    'sanitize_callback' => 'sanitize_key',
                ],
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'includeEmptyFields' => [
                    'default' => false,
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean',
                ],
            ],
        ]);

        // Cookie banner endpoint
        register_rest_route($namespace, '/cookies', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_cookies'],
            'permission_callback' => '__return_true', // Open to everyone
            'args' => [
                'lang' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        // Site options endpoint (legacy – business options by language)
        register_rest_route($namespace, '/options/site', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_v1_site_options'],
            'permission_callback' => '__return_true', // Open to everyone
            'args' => [
                'lang' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'includeMedia' => [
                    'default' => 'basic',
                    'enum' => ['basic', 'full'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // ── Admin Site Settings — unified GET / POST ─────────────────────────
        register_rest_route($namespace, '/settings/site', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_admin_site_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'update_admin_site_settings'],
                'permission_callback' => [$this, 'check_admin_permission'],
            ],
        ]);

        if (class_exists('\\ContentCore\\Admin\\Rest\\ErrorLogRestController') && isset($GLOBALS['cc_error_logger'])) {
            $error_rest = new \ContentCore\Admin\Rest\ErrorLogRestController($GLOBALS['cc_error_logger'], $namespace);
            $error_rest->register_routes();
        }
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

        if ($slug === 'site-options') {
            return $this->get_v1_site_options($request);
        }

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
            'customFields' => (object) $custom_fields,
        ];

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Callback for v1 global SEO endpoint
     */
    public function get_v1_seo(\WP_REST_Request $request): \WP_REST_Response
    {
        $seo_settings = get_option(\ContentCore\Modules\Settings\SettingsModule::SEO_KEY, []);

        $title = $seo_settings['site_title'] ?? '';
        $description = $seo_settings['default_description'] ?? '';

        $image_url = function_exists('cc_get_default_og_image_url') ? cc_get_default_og_image_url() : null;

        $data = [
            'site_title' => $title,
            'default_description' => $description,
            'default_og_image_url' => $image_url,
        ];

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Public endpoint to retrieve cookie banner settings
     */
    public function get_v1_cookies(\WP_REST_Request $request): \WP_REST_Response
    {
        $cookie_defaults = [
            'enabled' => false,
            'policyUrl' => '',
            'bannerTitle' => __('Cookie Consent', 'content-core'),
            'bannerText' => __('We use cookies to improve experience.', 'content-core'),
            'labels' => [
                'acceptAll' => __('Accept All', 'content-core'),
                'rejectAll' => __('Reject All', 'content-core'),
                'save' => __('Save Settings', 'content-core'),
                'settings' => __('Preferences', 'content-core'),
            ],
            'categories' => [
                'analytics' => false,
                'marketing' => false,
                'preferences' => false,
            ],
            'integrations' => [
                'ga4MeasurementId' => '',
                'gtmContainerId' => '',
                'metaPixelId' => '',
            ],
            'behavior' => [
                'regionMode' => 'eu_only',
                'storage' => 'localStorage',
                'ttlDays' => 365,
            ]
        ];

        $saved_settings = get_option(\ContentCore\Modules\Settings\SettingsModule::COOKIE_KEY, []);
        $data = array_replace_recursive($cookie_defaults, $saved_settings);

        // Ensure types
        $data['enabled'] = (bool) $data['enabled'];
        $data['categories']['analytics'] = (bool) $data['categories']['analytics'];
        $data['categories']['marketing'] = (bool) $data['categories']['marketing'];
        $data['categories']['preferences'] = (bool) $data['categories']['preferences'];
        $data['behavior']['ttlDays'] = (int) $data['behavior']['ttlDays'];

        return new \WP_REST_Response($data, 200);
    }

    /**
     * Helper to prepare post data for v1 response
     */
    private function prepare_post_v1_data(\WP_Post $post, \WP_REST_Request $request): array
    {
        $post_id = $post->ID;

        // ── Fetch Global SEO Defaults ──
        $site_seo = get_option(\ContentCore\Modules\Settings\SettingsModule::SEO_KEY, []);
        $global_title = $site_seo['site_title'] ?? get_bloginfo('name');
        $global_desc = $site_seo['default_description'] ?? '';

        // ── Fetch Post-level Meta ──
        $meta_title = get_post_meta($post_id, 'cc_seo_title', true);
        $meta_desc = get_post_meta($post_id, 'cc_seo_description', true);
        $meta_img_id = get_post_meta($post_id, 'cc_seo_og_image_id', true);
        $noindex = get_post_meta($post_id, 'cc_seo_noindex', true);

        // ── Compute Title ──
        $seo_title = !empty($meta_title) ? $meta_title : $post->post_title . ' | ' . $global_title;

        // ── Compute Description ──
        $seo_desc = !empty($meta_desc) ? $meta_desc : $global_desc;

        // ── Compute OG Image URL ──
        $og_image_url = null;
        if (!empty($meta_img_id)) {
            $og_image_url = wp_get_attachment_url(absint($meta_img_id)) ?: null;
        } elseif (function_exists('cc_get_default_og_image_url')) {
            $og_image_url = cc_get_default_og_image_url() ?: null;
        }

        // ── Compute Robots ──
        $robots = !empty($noindex) ? 'noindex,nofollow' : 'index,follow';

        return [
            'id' => $post_id,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'type' => $post->post_type,
            'date' => $post->post_date_gmt,
            'contentCoreVersion' => 'v1',
            'seo' => [
                'title' => $seo_title,
                'description' => $seo_desc,
                'og_image_url' => $og_image_url,
                'robots' => $robots,
            ],
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
            $post = get_post($id);
            if (!$post) {
                return false;
            }
            $post_type_obj = get_post_type_object($post->post_type);
            if (!$post_type_obj || !current_user_can($post_type_obj->cap->read_post, $id)) {
                return false;
            }
        } else {
            // For list/index routes, check if the post type exists and is public
            $type = $request->get_param('type');
            if ($type) {
                $post_type_obj = get_post_type_object($type);
                if (!$post_type_obj || (!$post_type_obj->public && !current_user_can($post_type_obj->cap->edit_posts))) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Permission check for options pages
     */
    public function check_options_permission(\WP_REST_Request $request): bool
    {
        $slug = sanitize_key($request->get_param('slug'));
        if ($slug === 'site-options') {
            return true;
        }

        // Check if the options page actually exists to avoid probing
        $page = get_page_by_path($slug, OBJECT, 'cc_options_page');
        if (!$page || $page->post_status !== 'publish') {
            return false;
        }

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

        $collected_ids = [];
        $raw_values = [];

        foreach ($fields as $name => $schema) {
            $raw_value = get_post_meta($post_id, $name, true);
            $default = $schema['default_value'] ?? null;

            if ('' === $raw_value && !metadata_exists('post', $post_id, $name)) {
                if (!$request->get_param('includeEmptyFields') && !is_a($request, '\WP_REST_Request')) {
                    // Legacy check
                }
                $raw_value = ('' !== $default) ? $default : null;
            }

            if (null === $raw_value && !$request->get_param('includeEmptyFields')) {
                continue;
            }

            $raw_values[$name] = $raw_value;
            $this->collect_media_ids($raw_value, $schema, $collected_ids);
        }

        $this->prime_media_caches($collected_ids);

        foreach ($raw_values as $name => $raw_value) {
            $schema = $fields[$name];
            $output[$name] = $this->format_value_for_v1($raw_value, $schema, $request);
        }

        return (object) $output;
    }

    /**
     * Recursively collect all media ID values based on schema.
     */
    private function collect_media_ids($value, array $schema, array &$ids): void
    {
        $type = $schema['type'] ?? 'text';
        if (null === $value || '' === $value)
            return;

        switch ($type) {
            case 'image':
            case 'file':
                if (is_numeric($value)) {
                    $ids[] = absint($value);
                }
                break;
            case 'gallery':
                if (is_string($value))
                    $value = json_decode($value, true);
                if (is_array($value)) {
                    foreach ($value as $id) {
                        if (is_numeric($id))
                            $ids[] = absint($id);
                    }
                }
                break;
            case 'repeater':
                if (is_string($value))
                    $value = json_decode($value, true);
                if (is_array($value)) {
                    $sub_fields = $schema['sub_fields'] ?? [];
                    $sub_schemas = [];
                    foreach ($sub_fields as $sub) {
                        $sub_schemas[$sub['name']] = $sub;
                    }
                    foreach ($value as $row) {
                        if (is_array($row)) {
                            foreach ($sub_schemas as $sub_name => $sub_schema) {
                                $this->collect_media_ids($row[$sub_name] ?? null, $sub_schema, $ids);
                            }
                        }
                    }
                }
                break;
            case 'group':
                if (is_string($value))
                    $value = json_decode($value, true);
                if (is_array($value)) {
                    $sub_fields = $schema['sub_fields'] ?? [];
                    foreach ($sub_fields as $sub) {
                        $sub_name = $sub['name'] ?? '';
                        $this->collect_media_ids($value[$sub_name] ?? null, $sub, $ids);
                    }
                }
                break;
        }
    }

    /**
     * Pre-fetch all media items into the WP Object Cache via native batch functions.
     */
    private function prime_media_caches(array $ids): void
    {
        $ids = array_unique(array_filter($ids));
        if (empty($ids))
            return;

        _prime_post_caches($ids, false, true);
        if (function_exists('update_meta_cache')) {
            update_meta_cache('post', $ids);
        }
    }


    /**
     * Legacy callback to retrieve and format the custom fields for a specific post
     */
    public function get_custom_fields_value(array $post_obj): object
    {
        // Guard for invalid objects (e.g. Gutenberg global-styles preloading)
        if (!isset($post_obj['id']) || !is_numeric($post_obj['id'])) {
            return (object) [];
        }

        $post_id = absint($post_obj['id']);
        if ($post_id <= 0) {
            return (object) [];
        }

        $post = get_post($post_id);
        if (!$post) {
            return (object) [];
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
                return (object) $formatted_group;

            default:
                return (string) $value;
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

    // ─── Admin Site Settings — unified key: cc_site_settings ──────────────

    /**
     * Permission check: only administrators.
     */
    public function check_admin_permission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * GET /content-core/v1/settings/site
     * Returns the full cc_site_settings option, enriching image IDs with resolved URLs.
     */
    public function get_admin_site_settings(\WP_REST_Request $request): \WP_REST_Response
    {
        $defaults = [
            'seo' => [
                'title' => '',
                'description' => '',
            ],
            'images' => [
                'social_icon_id' => 0,
                'og_default_id' => 0,
            ],
            'cookie' => [
                'enabled' => false,
                'bannerTitle' => '',
                'bannerText' => '',
                'policyUrl' => '',
                'labels' => [
                    'acceptAll' => 'Accept All',
                    'rejectAll' => 'Reject All',
                    'save' => 'Save Settings',
                    'settings' => 'Preferences',
                ],
                'categories' => [
                    'analytics' => false,
                    'marketing' => false,
                    'preferences' => false,
                ],
                'integrations' => [
                    'ga4MeasurementId' => '',
                    'gtmContainerId' => '',
                    'metaPixelId' => '',
                ],
                'behavior' => [
                    'regionMode' => 'eu_only',
                    'storage' => 'localStorage',
                    'ttlDays' => 365,
                ],
            ],
        ];

        $saved = get_option('cc_site_settings', []);
        if (!is_array($saved))
            $saved = [];

        $data = array_replace_recursive($defaults, $saved);

        // Resolve image IDs → preview URLs (for React display only — not persisted)
        $image_keys = ['social_icon_id', 'og_default_id'];
        foreach ($image_keys as $key) {
            $id = absint($data['images'][$key] ?? 0);
            if ($id > 0) {
                $url = wp_get_attachment_image_url($id, 'medium') ?: wp_get_attachment_url($id);
                $data['images'][$key . '_url'] = $url ?: '';
            } else {
                $data['images'][$key . '_url'] = '';
            }
        }

        // Cast booleans
        $data['cookie']['enabled'] = (bool) $data['cookie']['enabled'];
        $data['cookie']['categories']['analytics'] = (bool) $data['cookie']['categories']['analytics'];
        $data['cookie']['categories']['marketing'] = (bool) $data['cookie']['categories']['marketing'];
        $data['cookie']['categories']['preferences'] = (bool) $data['cookie']['categories']['preferences'];
        $data['cookie']['behavior']['ttlDays'] = (int) $data['cookie']['behavior']['ttlDays'];

        // Add branding settings
        $branding_defaults = [
            'enabled' => false,
            'exclude_admins' => true,
            'login_logo' => '',
            'login_bg_color' => '#f0f0f1',
            'login_btn_color' => '#2271b1',
            'login_logo_link_url' => '',
            'admin_bar_logo' => '',
            'admin_bar_logo_url' => '',
            'admin_bar_logo_link_url' => '',
            'use_site_icon_for_admin_bar' => false,
            'custom_primary_color' => '',
            'custom_accent_color' => '',
            'remove_wp_mentions' => false,
            'custom_footer_text' => '',
        ];
        $branding_saved = get_option('cc_branding_settings', []);
        if (!is_array($branding_saved))
            $branding_saved = [];
        $data['branding'] = array_merge($branding_defaults, $branding_saved);

        // Resolve branding image URLs
        $branding_img_keys = ['login_logo', 'admin_bar_logo'];
        foreach ($branding_img_keys as $key) {
            $val = $data['branding'][$key] ?? '';
            if (is_numeric($val) && (int) $val > 0) {
                // Resolved URL for frontend picker preview
                $url = wp_get_attachment_image_url($val, 'medium') ?: wp_get_attachment_url($val);
                $data['branding'][$key . '_url'] = $url ?: '';
            } elseif ($val && is_string($val)) {
                // Legacy support for pure URL strings
                $data['branding'][$key . '_url'] = $val;
            } else {
                $data['branding'][$key . '_url'] = '';
            }
        }

        // Add site icon (favicon) URL for reference
        $site_icon_id = get_option('site_icon');
        $data['branding']['site_icon_url'] = $site_icon_id ? wp_get_attachment_image_url($site_icon_id, 'full') : '';

        return new \WP_REST_Response($data, 200);
    }

    /**
     * POST /content-core/v1/settings/site
     * Accepts partial or full cc_site_settings and persists to DB.
     */
    public function update_admin_site_settings(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new \WP_REST_Response(['message' => 'Invalid JSON body.'], 400);
        }

        // Load existing saved options
        $existing = get_option('cc_site_settings', []);
        if (!is_array($existing))
            $existing = [];

        // ── SEO ──
        if (isset($body['seo']) && is_array($body['seo'])) {
            $seo = $body['seo'];
            $existing['seo'] = [
                'title' => sanitize_text_field($seo['title'] ?? ''),
                'description' => sanitize_textarea_field($seo['description'] ?? ''),
            ];
        }

        // ── Images ── store IDs only, validate as attachment IDs
        if (isset($body['images']) && is_array($body['images'])) {
            $imgs = $body['images'];
            $image_keys = ['social_icon_id', 'og_default_id'];
            $saved_imgs = $existing['images'] ?? [];
            foreach ($image_keys as $key) {
                if (array_key_exists($key, $imgs)) {
                    $id = absint($imgs[$key]);
                    // Accept 0 (remove) or a valid attachment
                    if ($id === 0 || get_post_type($id) === 'attachment') {
                        $saved_imgs[$key] = $id;
                    }
                }
            }
            $existing['images'] = $saved_imgs;
        }

        // ── Cookie ──
        if (isset($body['cookie']) && is_array($body['cookie'])) {
            $cookie = $body['cookie'];
            $prev_cookie = $existing['cookie'] ?? [];
            $existing['cookie'] = array_replace_recursive($prev_cookie, [
                'enabled' => !empty($cookie['enabled']),
                'bannerTitle' => sanitize_text_field($cookie['bannerTitle'] ?? ''),
                'bannerText' => sanitize_textarea_field($cookie['bannerText'] ?? ''),
                'policyUrl' => esc_url_raw($cookie['policyUrl'] ?? ''),
                'labels' => [
                    'acceptAll' => sanitize_text_field($cookie['labels']['acceptAll'] ?? ''),
                    'rejectAll' => sanitize_text_field($cookie['labels']['rejectAll'] ?? ''),
                    'save' => sanitize_text_field($cookie['labels']['save'] ?? ''),
                    'settings' => sanitize_text_field($cookie['labels']['settings'] ?? ''),
                ],
                'categories' => [
                    'analytics' => !empty($cookie['categories']['analytics']),
                    'marketing' => !empty($cookie['categories']['marketing']),
                    'preferences' => !empty($cookie['categories']['preferences']),
                ],
                'integrations' => [
                    'ga4MeasurementId' => sanitize_text_field($cookie['integrations']['ga4MeasurementId'] ?? ''),
                    'gtmContainerId' => sanitize_text_field($cookie['integrations']['gtmContainerId'] ?? ''),
                    'metaPixelId' => sanitize_text_field($cookie['integrations']['metaPixelId'] ?? ''),
                ],
                'behavior' => [
                    'regionMode' => in_array($cookie['behavior']['regionMode'] ?? 'eu_only', ['eu_only', 'global'], true)
                        ? $cookie['behavior']['regionMode'] : 'eu_only',
                    'storage' => in_array($cookie['behavior']['storage'] ?? 'localStorage', ['localStorage', 'cookie'], true)
                        ? $cookie['behavior']['storage'] : 'localStorage',
                    'ttlDays' => max(1, min(3650, absint($cookie['behavior']['ttlDays'] ?? 365))),
                ],
            ]);
        }

        // ── Branding ──
        if (isset($body['branding']) && is_array($body['branding'])) {
            $branding = $body['branding'];
            $existing_branding = get_option('cc_branding_settings', []);
            if (!is_array($existing_branding))
                $existing_branding = [];

            // Intelligent media sanitization: allow IDs or URLs
            $sanitize_media = function ($val) {
                if (is_numeric($val) && (int) $val > 0)
                    return absint($val);
                if (is_string($val) && !empty($val))
                    return esc_url_raw($val);
                return 0;
            };

            // Mapping of fields to their sanitization functions
            $fields = [
                'enabled' => function ($v) {
                    return !empty($v); },
                'exclude_admins' => function ($v) {
                    return !empty($v); },
                'login_logo' => $sanitize_media,
                'login_bg_color' => 'sanitize_hex_color',
                'login_btn_color' => 'sanitize_hex_color',
                'login_logo_url' => 'esc_url_raw',
                'login_logo_link_url' => 'esc_url_raw',
                'admin_bar_logo' => $sanitize_media,
                'admin_bar_logo_url' => 'esc_url_raw',
                'admin_bar_logo_link_url' => 'esc_url_raw',
                'use_site_icon_for_admin_bar' => function ($v) {
                    return !empty($v); },
                'custom_primary_color' => 'sanitize_hex_color',
                'custom_accent_color' => 'sanitize_hex_color',
                'remove_wp_mentions' => function ($v) {
                    return !empty($v); },
                'custom_footer_text' => 'wp_kses_post',
            ];

            foreach ($fields as $field => $sanitizer) {
                if (array_key_exists($field, $branding)) {
                    $val = $branding[$field];
                    if (is_callable($sanitizer)) {
                        $existing_branding[$field] = $sanitizer($val);
                    } elseif (function_exists($sanitizer)) {
                        $existing_branding[$field] = $sanitizer($val);
                    }
                }
            }

            update_option('cc_branding_settings', $existing_branding);
        }

        update_option('cc_site_settings', $existing);

        // Return the freshly saved state (including resolved URLs)
        return $this->get_admin_site_settings($request);
    }


    public function get_v1_site_options(\WP_REST_Request $request): \WP_REST_Response
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml = $plugin->get_module('multilingual');
        $site_mod = $plugin->get_module('site_options');

        $requested_lang = $request->get_param('lang');
        $default_lang = 'de';
        $fallback_lang = '';
        $headless_fallback_enabled = false;

        if ($ml instanceof \ContentCore\Modules\Multilingual\MultilingualModule) {
            $ml_settings = $ml->get_settings();
            $default_lang = $ml_settings['default_lang'] ?? 'de';
            $fallback_lang = $ml_settings['fallback_lang'] ?? '';
            $headless_fallback_enabled = !empty($ml_settings['enable_headless_fallback']);
        }

        if (empty($requested_lang)) {
            $requested_lang = $default_lang;
        }

        // 1. Initial attempt: Requested Language
        $lang_to_check = $requested_lang;
        $options = $site_mod ? $site_mod->get_options($lang_to_check) : [];
        $resolved_lang = $lang_to_check;

        // 2. Fallback attempt if enabled
        if ($headless_fallback_enabled && empty($options)) {
            // Try Fallback Language
            if (!empty($fallback_lang) && $fallback_lang !== $requested_lang) {
                $lang_to_check = $fallback_lang;
                $options = $site_mod ? $site_mod->get_options($lang_to_check) : [];
                if (!empty($options)) {
                    $resolved_lang = $lang_to_check;
                }
            }

            // Try Default Language if still empty
            if (empty($options) && $default_lang !== $requested_lang && $default_lang !== $fallback_lang) {
                $lang_to_check = $default_lang;
                $options = $site_mod ? $site_mod->get_options($lang_to_check) : [];
                if (!empty($options)) {
                    $resolved_lang = $lang_to_check;
                }
            }
        }

        $schema = $site_mod ? $site_mod->get_localized_schema($resolved_lang) : [];
        $is_fallback = $resolved_lang !== $requested_lang;

        // Filter options and format schema based on client_visible
        $filtered_options = [];
        $filtered_schema = [];
        $collected_ids = [];

        // Pre-fetch all media in one pass
        foreach ($schema as $section_id => $section) {
            foreach ($section['fields'] as $field_id => $field) {
                if (!isset($field['client_visible']) || $field['client_visible']) {
                    $val = $options[$field_id] ?? null;
                    $this->collect_media_ids($val, $field, $collected_ids);
                }
            }
        }
        $this->prime_media_caches($collected_ids);

        foreach ($schema as $section_id => $section) {
            $visible_fields = [];
            foreach ($section['fields'] as $field_id => $field) {
                if (!isset($field['client_visible']) || $field['client_visible']) {
                    $visible_fields[$field_id] = $field;

                    // Add value to filtered_options
                    $val = $options[$field_id] ?? null;

                    // Logic to resolve logo ID to URL / Media object
                    if ($field['type'] === 'image' && !empty($val)) {
                        $media = $this->format_media_v1($val, $request);
                        $filtered_options[$field_id] = $media;
                    } else {
                        $filtered_options[$field_id] = $val;
                    }
                }
            }
            if (!empty($visible_fields)) {
                $section['fields'] = $visible_fields;
                $filtered_schema[$section_id] = $section;
            }
        }

        return new \WP_REST_Response([
            'language' => $resolved_lang, // Backward compatibility
            'requestedLanguage' => $requested_lang,
            'resolvedLanguage' => $resolved_lang,
            'isFallback' => $is_fallback,
            'schema' => (object) $filtered_schema,
            'data' => (object) $filtered_options,
        ], 200);
    }

    /**
     * Record internal diagnostics for the REST server.
     * Hooks into rest_api_init at a very late priority.
     */
    public function record_diagnostics(): void
    {
        if (!function_exists('rest_get_server')) {
            return;
        }

        $server = rest_get_server();
        $namespace = \ContentCore\Plugin::REST_NAMESPACE . '/' . \ContentCore\Plugin::REST_VERSION;
        $ns_with_slash = '/' . ltrim($namespace, '/');

        // 1. Check if namespace is registered
        $namespaces = $server->get_namespaces();
        self::$diagnostics['namespace_registered'] = in_array($namespace, $namespaces, true);

        // 2. Count routes in our namespace
        $routes = $server->get_routes();
        if (!is_array($routes)) {
            $routes = [];
        }

        $count = 0;
        foreach ($routes as $route => $handlers) {
            if (strpos($route, $ns_with_slash) === 0) {
                $count++;
            }
        }
        self::$diagnostics['route_count'] = $count;
    }

    /**
     * Get the diagnostic route count
     */
    public static function get_diagnostic_route_count(): int
    {
        return self::$diagnostics['route_count'];
    }

    /**
     * Get the diagnostic namespace status
     */
    public static function is_diagnostic_namespace_registered(): bool
    {
        return self::$diagnostics['namespace_registered'];
    }

    /**
     * Safely perform discovery of REST routes.
     * Triggers rest_api_init if it hasn't run yet.
     */
    public static function perform_safe_discovery(): void
    {
        if (!function_exists('rest_get_server')) {
            return;
        }

        try {
            if (!did_action('rest_api_init')) {
                do_action('rest_api_init');
            }

            $module = \ContentCore\Plugin::get_instance()->get_module('rest_api');
            if ($module instanceof self) {
                $module->record_diagnostics();
            }
        } catch (\Throwable $e) {
            self::$diagnostics['last_error'] = $e->getMessage();
        }
    }

    /**
     * Get the last discovery error
     */
    public static function get_last_error(): ?string
    {
        return self::$diagnostics['last_error'];
    }

    /**
     * Get all registered routes for our namespace safely
     */
    public static function get_registered_routes(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }

        try {
            $server = rest_get_server();
            $namespace = \ContentCore\Plugin::REST_NAMESPACE . '/' . \ContentCore\Plugin::REST_VERSION;
            $ns_with_slash = '/' . ltrim($namespace, '/');

            $all_routes = $server->get_routes();
            $our_routes = [];

            foreach ($all_routes as $route => $handlers) {
                if (0 === strpos($route, $ns_with_slash)) {
                    $our_routes[] = $route;
                }
            }

            return $our_routes;
        } catch (\Throwable $e) {
            self::$diagnostics['last_error'] = $e->getMessage();
            return [];
        }
    }
}
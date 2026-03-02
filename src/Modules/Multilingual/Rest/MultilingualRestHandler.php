<?php
namespace ContentCore\Modules\Multilingual\Rest;

use ContentCore\Modules\Multilingual\MultilingualModule;

class MultilingualRestHandler
{
    private $module;

    public function __construct(MultilingualModule $module)
    {
        $this->module = $module;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        add_filter('rest_post_query', [$this, 'filter_rest_query'], 10, 2);
        add_filter('rest_page_query', [$this, 'filter_rest_query'], 10, 2);

        // Add support for custom post types registered by Content Core
        $post_types = get_post_types(['public' => true, 'show_in_rest' => true]);
        foreach ($post_types as $post_type) {
            if ($post_type === 'post' || $post_type === 'page')
                continue;
            add_filter("rest_{$post_type}_query", [$this, 'filter_rest_query'], 10, 2);
        }

        add_action('rest_api_init', function () {
            $namespaces = [
                \ContentCore\Plugin::get_instance()->get_rest_namespace(),
                'cc/v1' // Backward compatibility alias
            ];

            foreach ($namespaces as $ns) {
                // Base index is centrally handled by RestApiModule

                register_rest_route($ns, '/sitemap', [
                    'methods' => 'GET',
                    'callback' => [$this, 'get_sitemap'],
                    'permission_callback' => '__return_true',
                ]);
            }
        });

        add_filter('rest_request_after_callbacks', [$this, 'handle_rest_fallback'], 10, 3);
    }

    public function register_rest_fields(): void
    {
        $post_types = get_post_types(['public' => true, 'show_in_rest' => true]);

        foreach ($post_types as $post_type) {
            register_rest_field($post_type, 'multilingual', [
                'get_callback' => [$this, 'get_multilingual_data'],
                'schema' => [
                    'description' => __('Multilingual data', 'content-core'),
                    'type' => 'object',
                    'properties' => [
                        'language' => ['type' => 'string'],
                        'group' => ['type' => 'string'],
                        'translations' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'language' => ['type' => 'string'],
                                    'status' => ['type' => 'string'],
                                    'url' => ['type' => 'string'],
                                ]
                            ]
                        ]
                    ]
                ]
            ]);

            register_rest_field($post_type, 'cc_language', [
                'get_callback' => function ($data) {
                    return get_post_meta($data['id'], '_cc_language', true) ?: $this->module->get_settings()['default_lang'];
                },
                'schema' => ['type' => 'string']
            ]);

            register_rest_field($post_type, 'cc_translation_group', [
                'get_callback' => function ($data) {
                    return get_post_meta($data['id'], '_cc_translation_group', true);
                },
                'schema' => ['type' => 'string']
            ]);

            register_rest_field($post_type, 'cc_permalink', [
                'get_callback' => function ($data) {
                    return get_permalink($data['id']);
                },
                'schema' => ['type' => 'string']
            ]);

            register_rest_field($post_type, 'cc_alternates', [
                'get_callback' => [$this, 'get_cc_alternates'],
                'schema' => ['type' => 'object']
            ]);

            register_rest_field($post_type, 'cc_seo', [
                'get_callback' => [$this, 'get_cc_seo'],
                'schema' => ['type' => 'object']
            ]);
        }

        // Taxonomies
        $taxonomies = get_taxonomies(['public' => true, 'show_in_rest' => true]);
        foreach ($taxonomies as $taxonomy) {
            register_rest_field($taxonomy, 'cc_language', [
                'get_callback' => function ($data) {
                    return get_term_meta($data['id'], '_cc_language', true) ?: $this->module->get_settings()['default_lang'];
                },
                'schema' => ['type' => 'string']
            ]);

            register_rest_field($taxonomy, 'cc_permalink', [
                'get_callback' => function ($data) {
                    $link = get_term_link((int) $data['id']);
                    return is_wp_error($link) ? '' : $link;
                },
                'schema' => ['type' => 'string']
            ]);

            register_rest_field($taxonomy, 'cc_seo', [
                'get_callback' => [$this, 'get_term_cc_seo'],
                'schema' => ['type' => 'object']
            ]);
        }
    }

    public function get_cc_alternates(array $post_data): array
    {
        $post_id = $post_data['id'];
        $group_id = get_post_meta($post_id, '_cc_translation_group', true);
        $alternates = [];

        if ($group_id) {
            $translations = $this->module->get_translation_manager()->get_translations($group_id);
            foreach ($translations as $lang => $tid) {
                // Return only published translations
                if (get_post_status($tid) === 'publish') {
                    $alternates[$lang] = get_permalink($tid);
                }
            }
        }

        return $alternates;
    }

    public function get_cc_seo(array $post_data): ?array
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enable_rest_seo'])) {
            return null;
        }

        $post_id = $post_data['id'];
        $alternates = $this->get_cc_alternates($post_data);
        $default_lang = $settings['default_lang'];

        return [
            'canonical' => get_permalink($post_id),
            'alternates' => $alternates,
            'x_default' => $alternates[$default_lang] ?? get_permalink($post_id),
        ];
    }

    public function get_term_cc_seo(array $term_data): ?array
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enable_rest_seo'])) {
            return null;
        }

        $term_id = (int) $term_data['id'];
        $current_lang = get_term_meta($term_id, '_cc_language', true) ?: $settings['default_lang'];
        $link = get_term_link($term_id);
        if (is_wp_error($link))
            $link = '';

        return [
            'canonical' => $link,
            'alternates' => [$current_lang => $link],
            'x_default' => $link,
        ];
    }

    public function get_multilingual_data(array $post_data): array
    {
        $post_id = $post_data['id'];
        $settings = $this->module->get_settings();
        $lang = get_post_meta($post_id, '_cc_language', true) ?: $settings['default_lang'];
        $group_id = get_post_meta($post_id, '_cc_translation_group', true);

        $translations_data = [];
        if ($group_id) {
            $translations = $this->module->get_translation_manager()->get_translations($group_id);
            foreach ($translations as $l => $tid) {
                if ($tid === $post_id)
                    continue;
                $tpost = get_post($tid);
                $translations_data[] = [
                    'id' => $tid,
                    'language' => $l,
                    'status' => $tpost->post_status,
                    'url' => get_permalink($tid),
                ];
            }
        }

        return [
            'language' => $lang,
            'group' => $group_id,
            'translations' => $translations_data,
        ];
    }

    public function filter_rest_query(array $args, \WP_REST_Request $request): array
    {
        $lang = $request->get_param('lang');
        if (!$lang) {
            return $args;
        }

        $args['meta_query'] = $args['meta_query'] ?? [];
        $args['meta_query'][] = [
            'key' => '_cc_language',
            'value' => sanitize_text_field($lang),
            'compare' => '=',
        ];

        return $args;
    }

    public function handle_rest_fallback($response, $handler, $request)
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enable_headless_fallback'])) {
            return $response;
        }

        $lang = $request->get_param('lang');
        if (!$lang || $lang === $settings['default_lang']) {
            return $response;
        }

        if ($response instanceof \WP_REST_Response && $response->get_status() === 404) {
            $route = $request->get_route();
            if (preg_match('/^\/wp\/v2\/(posts|pages|cc_[^\/]+)\/(?P<id>\d+)$/', $route, $matches)) {
                $post_id = (int) $matches['id'];
                $post = get_post($post_id);
                if ($post && $post->post_status === 'publish') {
                    $post_type_obj = get_post_type_object($post->post_type);
                    if ($post_type_obj && $post_type_obj->show_in_rest) {
                        $controller_class = $post_type_obj->rest_controller_class ?: \WP_REST_Posts_Controller::class;
                        if (class_exists($controller_class)) {
                            $controller = new $controller_class($post->post_type);
                            $fallback_response = $controller->prepare_item_for_response($post, $request);
                            if ($fallback_response instanceof \WP_REST_Response) {
                                $data = $fallback_response->get_data();
                                $data['cc_fallback_active'] = true;
                                $data['cc_requested_lang'] = $lang;
                                $fallback_response->set_data($data);
                                return $fallback_response;
                            }
                        }
                    }
                }
            }
        }
        return $response;
    }

    public function get_sitemap(): \WP_REST_Response
    {
        $settings = $this->module->get_settings();
        if (empty($settings['enable_sitemap_endpoint'])) {
            return new \WP_REST_Response(['error' => 'Sitemap endpoint is disabled.'], 403);
        }

        $cache_key = 'cc_rest_sitemap';
        $sitemap = get_transient($cache_key);
        if ($sitemap !== false) {
            return new \WP_REST_Response($sitemap);
        }

        $sitemap = [];
        $default_lang = $settings['default_lang'];
        $post_types = get_post_types(['public' => true], 'names');
        foreach ($post_types as $post_type) {
            if ($post_type === 'attachment')
                continue;
            $posts = get_posts(['post_type' => $post_type, 'posts_per_page' => -1, 'post_status' => 'publish']);
            foreach ($posts as $post) {
                if (!is_object($post))
                    continue;
                $lang = get_post_meta($post->ID, '_cc_language', true) ?: $default_lang;
                $sitemap[] = [
                    'url' => get_permalink($post->ID),
                    'lastmod' => get_the_modified_date('c', $post),
                    'lang' => $lang,
                    'type' => $post_type,
                    'id' => $post->ID
                ];
            }
        }

        $taxonomies = get_taxonomies(['public' => true], 'names');
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
            if (is_wp_error($terms))
                continue;
            foreach ($terms as $term) {
                if (!is_object($term))
                    continue;
                $lang = get_term_meta($term->term_id, '_cc_language', true) ?: $default_lang;
                $sitemap[] = [
                    'url' => get_term_link((int) $term->term_id),
                    'lang' => $lang,
                    'type' => 'taxonomy:' . $taxonomy,
                    'id' => $term->term_id
                ];
            }
        }
        set_transient($cache_key, $sitemap, 3600);
        return new \WP_REST_Response($sitemap);
    }
}

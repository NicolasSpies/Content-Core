<?php
namespace ContentCore\Modules\Forms\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use ContentCore\Modules\Forms\Handlers\FormSubmissionHandler;

class FormRestController
{
    private string $namespace;

    public function __construct()
    {
        $this->namespace = \ContentCore\Plugin::REST_NAMESPACE . '/' . \ContentCore\Plugin::REST_VERSION;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/forms/(?P<slug>[a-zA-Z0-9-_]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_form'],
            'permission_callback' => '__return_true', // Publicly readable for frontend consumption
            'args' => [
                'slug' => [
                    'sanitize_callback' => 'sanitize_key',
                ],
                'lang' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_key',
                    'default' => ''
                ]
            ]
        ]);

        register_rest_route($this->namespace, '/forms/(?P<slug>[a-zA-Z0-9-_]+)/submit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'submit_form'],
            'permission_callback' => '__return_true', // Protected by internal CSRF/Turnstile in the handler
            'args' => [
                'slug' => [
                    'sanitize_callback' => 'sanitize_key',
                ]
            ]
        ]);
    }

    public function get_form(WP_REST_Request $request): WP_REST_Response
    {
        $slug = $request->get_param('slug');
        $lang = $request->get_param('lang');

        // Find form by slug
        $args = [
            'name' => $slug,
            'post_type' => 'cc_form',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ];

        // Handle language filtering if provided
        if (!empty($lang)) {
            $args['meta_query'] = [
                [
                    'key' => '_cc_language',
                    'value' => $lang
                ]
            ];
        }

        $posts = get_posts($args);

        if (empty($posts)) {
            // Fallback to default language if requested lang failed
            if (!empty($lang)) {
                unset($args['meta_query']);
                $posts = get_posts($args);
            }
        }

        if (empty($posts)) {
            return new WP_REST_Response(['message' => 'Form not found'], 404);
        }

        $post = $posts[0];
        $fields = get_post_meta($post->ID, 'cc_form_fields', true) ?: [];
        $settings = get_post_meta($post->ID, 'cc_form_settings', true) ?: [];

        // Prune settings for frontend (don't expose emails)
        $public_settings = [
            'enable_honeypot' => !empty($settings['enable_honeypot']),
            'enable_turnstile' => !empty($settings['enable_turnstile'])
        ];

        return new WP_REST_Response([
            'id' => $post->ID,
            'title' => $post->post_title,
            'id_str' => $post->post_name,
            'fields' => $fields,
            'settings' => $public_settings
        ], 200);
    }

    public function submit_form(WP_REST_Request $request): WP_REST_Response
    {
        $slug = $request->get_param('slug');

        // Find form
        $posts = get_posts([
            'name' => $slug,
            'post_type' => 'cc_form',
            'posts_per_page' => 1,
            'post_status' => 'publish'
        ]);

        if (empty($posts)) {
            return new WP_REST_Response(['message' => 'Form not found'], 404);
        }

        $post = $posts[0];
        $handler = new FormSubmissionHandler($post);
        return $handler->handle($request);
    }
}
<?php
namespace ContentCore\Modules\ContentTypes\Data;

class PostTypeDefinition
{
    public const POST_TYPE = 'cc_post_type_def';

    /**
     * Register the cc_post_type_def Custom Post Type
     */
    public function register(): void
    {
        $labels = [
            'name' => _x('Post Types', 'Post type general name', 'content-core'),
            'singular_name' => _x('Post Type', 'Post type singular name', 'content-core'),
            'menu_name' => _x('Post Types', 'Admin Menu text', 'content-core'),
            'add_new' => __('Add New', 'content-core'),
            'add_new_item' => __('Add New Post Type', 'content-core'),
            'edit_item' => __('Edit Post Type', 'content-core'),
            'all_items' => __('Post Types', 'content-core'),
            'not_found' => __('No post types found.', 'content-core'),
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Manually handled in AdminMenu
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'supports' => ['title'],
            'show_in_rest' => false,
            'capabilities' => [
                'edit_post' => 'manage_options',
                'read_post' => 'manage_options',
                'delete_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
            ],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}
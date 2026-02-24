<?php
namespace ContentCore\Modules\OptionsPages\Data;

class OptionsPagePostType
{

    public const POST_TYPE = 'cc_options_page';

    /**
     * Register the cc_options_page Custom Post Type
     */
    public function register(): void
    {
        $labels = [
            'name' => _x('Options Pages', 'Post type general name', 'content-core'),
            'singular_name' => _x('Options Page', 'Post type singular name', 'content-core'),
            'menu_name' => _x('Options Pages', 'Admin Menu text', 'content-core'),
            'name_admin_bar' => _x('Options Page', 'Add New on Toolbar', 'content-core'),
            'add_new' => __('Add New', 'content-core'),
            'add_new_item' => __('Add New Options Page', 'content-core'),
            'new_item' => __('New Options Page', 'content-core'),
            'edit_item' => __('Edit Options Page', 'content-core'),
            'view_item' => __('View Options Page', 'content-core'),
            'all_items' => __('Options Pages', 'content-core'),
            'search_items' => __('Search Options Pages', 'content-core'),
            'parent_item_colon' => __('Parent Options Pages:', 'content-core'),
            'not_found' => __('No options pages found.', 'content-core'),
            'not_found_in_trash' => __('No options pages found in Trash.', 'content-core'),
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => false, // Manually handled in AdminMenu
            'query_var' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => null,
            'supports' => ['title'],
            'show_in_rest' => false, // We will build a manual REST route, not expose the configuration object itself natively
            // Only allow administrators to view/edit option pages configurators by default
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
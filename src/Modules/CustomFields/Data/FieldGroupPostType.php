<?php
namespace ContentCore\Modules\CustomFields\Data;

class FieldGroupPostType
{

    public const POST_TYPE = 'cc_field_group';

    /**
     * Register the Custom Post Type
     */
    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
    }

    /**
     * Callback to register the post type
     */
    public function register_post_type(): void
    {
        $labels = [
            'name' => _x('Field Groups', 'Post Type General Name', 'content-core'),
            'singular_name' => _x('Field Group', 'Post Type Singular Name', 'content-core'),
            'menu_name' => __('Field Groups', 'content-core'),
            'name_admin_bar' => __('Field Group', 'content-core'),
            'archives' => __('Field Group Archives', 'content-core'),
            'attributes' => __('Field Group Attributes', 'content-core'),
            'parent_item_colon' => __('Parent Field Group:', 'content-core'),
            'all_items' => __('All Field Groups', 'content-core'),
            'add_new_item' => __('Add New Field Group', 'content-core'),
            'add_new' => __('Add New', 'content-core'),
            'new_item' => __('New Field Group', 'content-core'),
            'edit_item' => __('Edit Field Group', 'content-core'),
            'update_item' => __('Update Field Group', 'content-core'),
            'view_item' => __('View Field Group', 'content-core'),
            'view_items' => __('View Field Groups', 'content-core'),
            'search_items' => __('Search Field Group', 'content-core'),
            'not_found' => __('Not found', 'content-core'),
            'not_found_in_trash' => __('Not found in Trash', 'content-core'),
        ];

        $args = [
            'label' => __('Field Group', 'content-core'),
            'labels' => $labels,
            'supports' => ['title'], // Explicitly only support title, no editor
            'hierarchical' => false,
            'public' => false, // Internal CPT
            'show_ui' => true,
            'show_in_menu' => false, // Manually handled in AdminMenu
            'menu_position' => 5,
            'show_in_admin_bar' => false,
            'show_in_nav_menus' => false,
            'can_export' => true,
            'has_archive' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'rewrite' => false,
            'capabilities' => [
                'edit_post' => 'manage_options',
                'read_post' => 'manage_options',
                'delete_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
            ],
            'show_in_rest' => false, // No block editor
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}
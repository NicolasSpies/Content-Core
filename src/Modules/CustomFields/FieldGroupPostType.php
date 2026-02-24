<?php

namespace Agency\HeadlessFramework\Modules\CustomFields;

class FieldGroupPostType
{

    const POST_TYPE = 'hwf_field_group';

    public function init()
    {
        add_action('init', [$this, 'register_post_type']);
    }

    public function register_post_type()
    {
        $args = [
            'labels' => [
                'name' => 'Field Groups',
                'singular_name' => 'Field Group',
                'add_new_item' => 'Add New Field Group',
                'edit_item' => 'Edit Field Group',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'hwf-dashboard', // Show under Headless Framework menu
            'supports' => ['title'],
            'hierarchical' => false,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}
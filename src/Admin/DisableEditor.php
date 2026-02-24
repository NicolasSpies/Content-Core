<?php
namespace ContentCore\Admin;

/**
 * Class DisableEditor
 *
 * Removes 'editor' support from all post types globally.
 * Content is intended to be managed via Content Core fields only.
 */
class DisableEditor
{
    /**
     * Initialize the editor disablement logic
     */
    public function init(): void
    {
        // Only run in admin or REST (to ensure post type features are consistently removed)
        if (!is_admin() && (!defined('REST_REQUEST') || !REST_REQUEST)) {
            return;
        }

        /**
         * Remove editor support for all post types on late init.
         * Priority 100 ensures we catch most custom post types.
         */
        add_action('init', [$this, 'remove_editor_globally'], 100);

        /**
         * Catch post types registered after init or dynamically.
         */
        add_action('registered_post_type', [$this, 'remove_editor_on_registration'], 10, 2);
    }

    /**
     * Iterate over all post types and remove 'editor' support.
     */
    public function remove_editor_globally(): void
    {
        $post_types = get_post_types();
        foreach ($post_types as $post_type) {
            $this->remove_editor_from_post_type($post_type);
        }
    }

    /**
     * Action hook for when a post type is registered.
     */
    public function remove_editor_on_registration(string $post_type): void
    {
        $this->remove_editor_from_post_type($post_type);
    }

    /**
     * Remove 'editor' support for a specific post type.
     */
    private function remove_editor_from_post_type(string $post_type): void
    {
        // Never remove title support
        if (post_type_supports($post_type, 'editor')) {
            remove_post_type_support($post_type, 'editor');
        }
    }
}
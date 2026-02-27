<?php
namespace ContentCore\Modules\ContentTypes;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\ContentTypes\Data\PostTypeDefinition;
use ContentCore\Modules\ContentTypes\Data\TaxonomyDefinition;

class ContentTypesModule implements ModuleInterface
{
    /**
     * Initialize the module
     */
    public function init(): void
    {
        // Register internal CPTs that store definitions
        add_action('init', [$this, 'register_internal_post_types']);

        // Register the dynamic CPTs and Taxonomies from stored definitions
        add_action('init', [$this, 'register_dynamic_content_types'], 20);

        // Conditional flush of rewrite rules
        add_action('init', [$this, 'maybe_flush_rewrite_rules'], 999);

        if (is_admin()) {
            $this->init_admin();
        }
    }

    /**
     * Flush rewrite rules if the flag is set
     */
    public function maybe_flush_rewrite_rules(): void
    {
        if (get_option('cc_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_option('cc_flush_rewrite_rules');
        }
    }

    /**
     * Register internal CPTs for storing definitions
     */
    public function register_internal_post_types(): void
    {
        $pt_def = new PostTypeDefinition();
        $pt_def->register();

        $tax_def = new TaxonomyDefinition();
        $tax_def->register();
    }

    /**
     * Fetch all published definitions and register them natively
     */
    public function register_dynamic_content_types(): void
    {
        // 1. Register Taxonomies first
        $tax_defs = get_posts([
            'post_type' => TaxonomyDefinition::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($tax_defs as $def_post) {
            $this->register_single_taxonomy($def_post);
        }

        // 2. Register Post Types
        $pt_defs = get_posts([
            'post_type' => PostTypeDefinition::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($pt_defs as $def_post) {
            $this->register_single_post_type($def_post);
        }
    }

    /**
     * Register a single Dynamic Post Type
     */
    private function register_single_post_type(\WP_Post $post): void
    {
        $slug = get_post_meta($post->ID, '_cc_pt_slug', true);
        if (!$slug)
            return;

        $singular = get_post_meta($post->ID, '_cc_pt_singular', true) ?: $post->post_title;
        $plural = get_post_meta($post->ID, '_cc_pt_plural', true) ?: $post->post_title;
        $public = get_post_meta($post->ID, '_cc_pt_public', true) !== '0';
        $has_archive = get_post_meta($post->ID, '_cc_pt_has_archive', true) === '1';
        $supports = get_post_meta($post->ID, '_cc_pt_supports', true);
        if (!is_array($supports)) {
            $supports = ['title', 'editor', 'thumbnail'];
        }

        $args = [
            'label' => $plural,
            'labels' => [
                'name' => $plural,
                'singular_name' => $singular,
            ],
            'public' => $public,
            'has_archive' => $has_archive,
            'supports' => $supports,
            'show_in_rest' => true,
        ];

        register_post_type($slug, $args);
    }

    /**
     * Register a single Dynamic Taxonomy
     */
    private function register_single_taxonomy(\WP_Post $post): void
    {
        $slug = get_post_meta($post->ID, '_cc_tax_slug', true);
        if (!$slug)
            return;

        $label = get_post_meta($post->ID, '_cc_tax_label', true) ?: $post->post_title;
        $hierarchical = get_post_meta($post->ID, '_cc_tax_hierarchical', true) === '1';
        $object_types = get_post_meta($post->ID, '_cc_tax_object_types', true);
        if (!is_array($object_types)) {
            $object_types = [];
        }

        // Derive a reasonable singular name: stored singular or fall back to label.
        $singular = get_post_meta($post->ID, '_cc_tax_singular', true) ?: $label;

        $args = [
            'label' => $label,
            'labels' => [
                'name' => $label,
                'singular_name' => $singular,
                'menu_name' => $label,
                'all_items' => sprintf(__('All %s', 'content-core'), $label),
                // Translators: %s = singular term label, e.g. "Referenz"
                'add_new_item' => sprintf(__('Add %s', 'content-core'), $singular),
                'new_item_name' => sprintf(__('New %s name', 'content-core'), $singular),
                'most_used' => sprintf(__('Most Used %s', 'content-core'), $label),
                'search_items' => sprintf(__('Search %s', 'content-core'), $label),
                'not_found' => sprintf(__('No %s found.', 'content-core'), $label),
                'back_to_items' => sprintf(__('Back to %s', 'content-core'), $label),
                'popular_items' => null, // null = suppress for hierarchical
            ],
            'hierarchical' => $hierarchical,
            'show_in_rest' => true,
        ];

        register_taxonomy($slug, $object_types, $args);
    }

    /**
     * Initialize Admin UI
     */
    private function init_admin(): void
    {
        $admin = new Admin\ContentTypesAdmin();
        $admin->register();
    }
}
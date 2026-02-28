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

        // Cache invalidation hooks
        add_action('save_post', [$this, 'flush_dynamic_content_types_cache']);
        add_action('deleted_post', [$this, 'flush_dynamic_content_types_cache']);
        add_action('trashed_post', [$this, 'flush_dynamic_content_types_cache']);

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
     * Clear transients when definitions change.
     */
    public function flush_dynamic_content_types_cache($post_id = null): void
    {
        if ($post_id) {
            $post = get_post($post_id);
            if (!$post || !in_array($post->post_type, [PostTypeDefinition::POST_TYPE, TaxonomyDefinition::POST_TYPE], true)) {
                return;
            }
        }
        delete_transient('cc_dynamic_taxonomies');
        delete_transient('cc_dynamic_post_types');
    }

    /**
     * Fetch all published definitions and register them natively
     */
    public function register_dynamic_content_types(): void
    {
        // 1. Taxonomies
        $tax_args_list = get_transient('cc_dynamic_taxonomies');
        if ($tax_args_list === false) {
            $tax_args_list = $this->build_taxonomy_args();
            set_transient('cc_dynamic_taxonomies', $tax_args_list);
        }

        foreach ($tax_args_list as $slug => $data) {
            register_taxonomy($slug, $data['object_types'], $data['args']);
        }

        // 2. Post Types
        $pt_args_list = get_transient('cc_dynamic_post_types');
        if ($pt_args_list === false) {
            $pt_args_list = $this->build_post_type_args();
            set_transient('cc_dynamic_post_types', $pt_args_list);
        }

        foreach ($pt_args_list as $slug => $args) {
            register_post_type($slug, $args);
        }
    }

    private function build_taxonomy_args(): array
    {
        $defs = get_posts([
            'post_type' => TaxonomyDefinition::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $list = [];
        foreach ($defs as $post) {
            $slug = get_post_meta($post->ID, '_cc_tax_slug', true);
            if (!$slug)
                continue;

            $label = get_post_meta($post->ID, '_cc_tax_label', true) ?: $post->post_title;
            $hierarchical = get_post_meta($post->ID, '_cc_tax_hierarchical', true) === '1';
            $object_types = get_post_meta($post->ID, '_cc_tax_object_types', true);
            if (!is_array($object_types)) {
                $object_types = [];
            }
            $singular = get_post_meta($post->ID, '_cc_tax_singular', true) ?: $label;

            $list[$slug] = [
                'object_types' => $object_types,
                'args' => [
                    'label' => $label,
                    'labels' => [
                        'name' => $label,
                        'singular_name' => $singular,
                        'menu_name' => $label,
                        // Translators: %s = singular term label
                        'all_items' => sprintf(__('All %s', 'content-core'), $label),
                        'add_new_item' => sprintf(__('Add %s', 'content-core'), $singular),
                        'new_item_name' => sprintf(__('New %s name', 'content-core'), $singular),
                        'most_used' => sprintf(__('Most Used %s', 'content-core'), $label),
                        'search_items' => sprintf(__('Search %s', 'content-core'), $label),
                        'not_found' => sprintf(__('No %s found.', 'content-core'), $label),
                        'back_to_items' => sprintf(__('Back to %s', 'content-core'), $label),
                        'popular_items' => null,
                    ],
                    'hierarchical' => $hierarchical,
                    'show_in_rest' => true,
                ]
            ];
        }
        return $list;
    }

    private function build_post_type_args(): array
    {
        $defs = get_posts([
            'post_type' => PostTypeDefinition::POST_TYPE,
            'posts_per_page' => -1,
            'post_status' => 'publish',
        ]);

        $list = [];
        foreach ($defs as $post) {
            $slug = get_post_meta($post->ID, '_cc_pt_slug', true);
            if (!$slug)
                continue;

            $singular = get_post_meta($post->ID, '_cc_pt_singular', true) ?: $post->post_title;
            $plural = get_post_meta($post->ID, '_cc_pt_plural', true) ?: $post->post_title;
            $public = get_post_meta($post->ID, '_cc_pt_public', true) !== '0';
            $has_archive = get_post_meta($post->ID, '_cc_pt_has_archive', true) === '1';
            $supports = get_post_meta($post->ID, '_cc_pt_supports', true);
            if (!is_array($supports)) {
                $supports = ['title', 'editor', 'thumbnail'];
            }

            $list[$slug] = [
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
        }
        return $list;
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
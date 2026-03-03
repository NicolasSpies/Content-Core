<?php
namespace ContentCore\Modules\ContentTypes;

use ContentCore\Modules\ModuleInterface;
use ContentCore\Modules\ContentTypes\Data\PostTypeDefinition;
use ContentCore\Modules\ContentTypes\Data\TaxonomyDefinition;

class ContentTypesModule implements ModuleInterface
{
    private const DYNAMIC_TAXONOMIES_TRANSIENT = 'cc_dynamic_taxonomies_v2';
    private const DYNAMIC_POST_TYPES_TRANSIENT = 'cc_dynamic_post_types_v2';

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
            add_filter('enter_title_here', [$this, 'filter_dynamic_post_type_title_placeholder'], 10, 2);
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
        delete_transient(self::DYNAMIC_TAXONOMIES_TRANSIENT);
        delete_transient(self::DYNAMIC_POST_TYPES_TRANSIENT);
    }

    /**
     * Fetch all published definitions and register them natively
     */
    public function register_dynamic_content_types(): void
    {
        // 1. Taxonomies
        $tax_args_list = get_transient(self::DYNAMIC_TAXONOMIES_TRANSIENT);
        if ($tax_args_list === false) {
            $tax_args_list = $this->build_taxonomy_args();
            set_transient(self::DYNAMIC_TAXONOMIES_TRANSIENT, $tax_args_list);
        }

        foreach ($tax_args_list as $slug => $data) {
            register_taxonomy($slug, $data['object_types'], $data['args']);
        }

        // 2. Post Types
        $pt_args_list = get_transient(self::DYNAMIC_POST_TYPES_TRANSIENT);
        if ($pt_args_list === false) {
            $pt_args_list = $this->build_post_type_args();
            set_transient(self::DYNAMIC_POST_TYPES_TRANSIENT, $pt_args_list);
        }

        foreach ($pt_args_list as $slug => $args) {
            // Hard safety: always expose custom content types in admin sidebar.
            $args['show_ui'] = true;
            $args['show_in_menu'] = true;
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
            $menu_icon = get_post_meta($post->ID, '_cc_pt_menu_icon', true);
            if (!is_array($supports)) {
                $supports = ['title', 'editor', 'thumbnail'];
            }
            if (!is_string($menu_icon) || strpos($menu_icon, 'dashicons-') !== 0) {
                $menu_icon = 'dashicons-media-document';
            }

            $list[$slug] = [
                'label' => $plural,
                'labels' => [
                    'name' => $plural,
                    'singular_name' => $singular,
                    'menu_name' => $plural,
                    'add_new' => $this->get_localized_add_new_short_label(),
                    'add_new_item' => $this->get_localized_add_new_item_label($singular),
                    'edit_item' => $this->get_localized_edit_item_label($singular),
                    'new_item' => $this->get_localized_new_item_label($singular),
                    'view_item' => $this->get_localized_view_item_label($singular),
                    'all_items' => $this->get_localized_all_items_label($plural),
                    'search_items' => $this->get_localized_search_items_label($plural),
                    'not_found' => $this->get_localized_not_found_label($plural),
                    'not_found_in_trash' => $this->get_localized_not_found_in_trash_label($plural),
                    'name_admin_bar' => $singular,
                ],
                'public' => $public,
                'show_ui' => true,
                'show_in_menu' => true,
                'has_archive' => $has_archive,
                'supports' => $supports,
                'menu_icon' => $menu_icon,
                'show_in_rest' => true,
            ];
        }
        return $list;
    }

    /**
     * Keep title placeholder identical to the post type "add new item" label.
     */
    public function filter_dynamic_post_type_title_placeholder(string $text, $post): string
    {
        if (!($post instanceof \WP_Post)) {
            return $text;
        }

        $post_type = sanitize_key((string) $post->post_type);
        if ($post_type === '' || in_array($post_type, ['post', 'page'], true)) {
            return $text;
        }
        if (strpos($post_type, 'cc_') === 0 || strpos($post_type, 'wp_') === 0) {
            return $text;
        }

        $obj = get_post_type_object($post_type);
        if (!$obj || empty($obj->labels) || !is_object($obj->labels)) {
            return $text;
        }

        $add_new_item = trim((string) ($obj->labels->add_new_item ?? ''));
        if ($add_new_item !== '') {
            return $add_new_item;
        }

        $singular = trim((string) ($obj->labels->singular_name ?? ''));
        if ($singular === '') {
            return $text;
        }

        return $singular;
    }

    private function get_localized_add_new_short_label(): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return 'Neu hinzufügen';
            case 'fr':
                return 'Ajouter';
            case 'it':
                return 'Aggiungi';
            default:
                return 'Add New';
        }
    }

    private function get_localized_add_new_item_label(string $singular): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return $singular . ' hinzufügen';
            case 'fr':
                return 'Ajouter ' . $singular;
            case 'it':
                return 'Aggiungi ' . $singular;
            default:
                return 'Add ' . $singular;
        }
    }

    private function get_localized_edit_item_label(string $singular): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return $singular . ' bearbeiten';
            case 'fr':
                return 'Modifier ' . $singular;
            case 'it':
                return 'Modifica ' . $singular;
            default:
                return 'Edit ' . $singular;
        }
    }

    private function get_localized_new_item_label(string $singular): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return 'Neuer ' . $singular;
            case 'fr':
                return 'Nouveau ' . $singular;
            case 'it':
                return 'Nuovo ' . $singular;
            default:
                return 'New ' . $singular;
        }
    }

    private function get_localized_view_item_label(string $singular): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return $singular . ' ansehen';
            case 'fr':
                return 'Voir ' . $singular;
            case 'it':
                return 'Visualizza ' . $singular;
            default:
                return 'View ' . $singular;
        }
    }

    private function get_localized_all_items_label(string $plural): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return 'Alle ' . $plural;
            case 'fr':
                return 'Tous les ' . $plural;
            case 'it':
                return 'Tutti i ' . $plural;
            default:
                return 'All ' . $plural;
        }
    }

    private function get_localized_search_items_label(string $plural): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return $plural . ' durchsuchen';
            case 'fr':
                return 'Rechercher ' . $plural;
            case 'it':
                return 'Cerca ' . $plural;
            default:
                return 'Search ' . $plural;
        }
    }

    private function get_localized_not_found_label(string $plural): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return 'Keine ' . $plural . ' gefunden.';
            case 'fr':
                return 'Aucun ' . $plural . ' trouvé.';
            case 'it':
                return 'Nessun ' . $plural . ' trovato.';
            default:
                return 'No ' . $plural . ' found.';
        }
    }

    private function get_localized_not_found_in_trash_label(string $plural): string
    {
        $lang = substr(strtolower((string) determine_locale()), 0, 2);
        switch ($lang) {
            case 'de':
                return 'Keine ' . $plural . ' im Papierkorb gefunden.';
            case 'fr':
                return 'Aucun ' . $plural . ' trouvé dans la corbeille.';
            case 'it':
                return 'Nessun ' . $plural . ' trovato nel cestino.';
            default:
                return 'No ' . $plural . ' found in Trash.';
        }
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

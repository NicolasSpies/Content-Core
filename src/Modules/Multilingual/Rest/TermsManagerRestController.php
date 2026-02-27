<?php
namespace ContentCore\Modules\Multilingual\Rest;

use ContentCore\Modules\Multilingual\MultilingualModule;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * REST controller for Manage Multilingual Terms.
 *
 * Namespace: content-core/v1
 * Routes:
 *   GET  /terms-manager/groups        — list all groups for a taxonomy
 *   POST /terms-manager/create        — create a new term + assign meta
 *   POST /terms-manager/translate     — create a translation and link to group
 *   POST /terms-manager/rename        — rename a term
 *   POST /terms-manager/remove        — delete a single term
 *   POST /terms-manager/delete-group  — delete all terms in a group
 *   POST /terms-manager/reorder       — update cc_order for terms
 */
class TermsManagerRestController
{
    private MultilingualModule $module;
    private string $ns;

    public function __construct(MultilingualModule $module, string $namespace)
    {
        $this->module = $module;
        $this->ns = $namespace;
    }

    public function register_routes(): void
    {
        $base = '/terms-manager';

        register_rest_route($this->ns, $base . '/all-taxonomy-groups', [
            'methods' => 'GET',
            'callback' => [$this, 'get_all_taxonomy_groups'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route($this->ns, $base . '/create', [
            'methods' => 'POST',
            'callback' => [$this, 'create_term'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'name' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'lang' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route($this->ns, $base . '/translate', [
            'methods' => 'POST',
            'callback' => [$this, 'create_translation'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'name' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'lang' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'group_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->ns, $base . '/rename', [
            'methods' => 'POST',
            'callback' => [$this, 'rename_term'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'term_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'name' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->ns, $base . '/remove', [
            'methods' => 'POST',
            'callback' => [$this, 'remove_term'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'term_id' => ['required' => true, 'sanitize_callback' => 'absint'],
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
            ],
        ]);

        register_rest_route($this->ns, $base . '/delete-group', [
            'methods' => 'POST',
            'callback' => [$this, 'delete_group'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'group_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($this->ns, $base . '/reorder', [
            'methods' => 'POST',
            'callback' => [$this, 'reorder_terms'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'taxonomy' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                'order' => ['required' => true],
            ],
        ]);
    }

    public function check_permission(): bool
    {
        return current_user_can('manage_options') || current_user_can('manage_categories');
    }

    // -------------------------------------------------------------------------
    // GET /all-taxonomy-groups
    // -------------------------------------------------------------------------

    public function get_all_taxonomy_groups(\WP_REST_Request $request): \WP_REST_Response
    {
        // Get all public taxonomies that have a UI
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $response_data = [];

        foreach ($taxonomies as $tax) {
            if (!$tax->show_ui) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $tax->name,
                'hide_empty' => false,
                'number' => 0,
            ]);

            if (is_wp_error($terms)) {
                continue;
            }

            $groups = [];
            $ungrouped = [];

            foreach ($terms as $term) {
                $raw_lang = get_term_meta($term->term_id, '_cc_language', true) ?: ($this->module->get_settings()['default_lang'] ?? 'de');
                $lang = strtolower(trim($raw_lang));
                $group_id = get_term_meta($term->term_id, '_cc_translation_group', true);
                $order = (int) (get_term_meta($term->term_id, 'cc_order', true) ?: 0);

                $td = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'lang' => $lang,
                    'order' => $order,
                ];

                if ($group_id) {
                    if (!isset($groups[$group_id])) {
                        $groups[$group_id] = [
                            'group_id' => $group_id,
                            'group_order' => $order,
                            'translations' => [],
                        ];
                    }
                    $groups[$group_id]['translations'][$lang] = $td;
                    if ($order < $groups[$group_id]['group_order']) {
                        $groups[$group_id]['group_order'] = $order;
                    }
                } else {
                    $ungrouped[] = [
                        'group_id' => null,
                        'group_order' => $order,
                        'translations' => [$lang => $td],
                    ];
                }
            }

            $all = array_values($groups);
            foreach ($ungrouped as $u) {
                $all[] = $u;
            }
            usort($all, fn($a, $b) => $a['group_order'] <=> $b['group_order']);

            $response_data[] = [
                'taxonomy' => $tax->name,
                'label' => $tax->label ?: $tax->name,
                'groups' => $all
            ];
        }

        return new \WP_REST_Response($response_data, 200);
    }

    // -------------------------------------------------------------------------
    // POST /create
    // -------------------------------------------------------------------------

    public function create_term(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = $request->get_param('taxonomy');
        $name = $request->get_param('name');
        $lang = strtolower(trim($request->get_param('lang')));

        if (!taxonomy_exists($taxonomy)) {
            return new WP_REST_Response(['error' => 'Invalid taxonomy.'], 400);
        }

        $result = wp_insert_term($name, $taxonomy);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 422);
        }

        $term_id = $result['term_id'];
        $group_id = wp_generate_uuid4();
        $order = $this->next_order($taxonomy, $lang);

        update_term_meta($term_id, '_cc_language', $lang);
        update_term_meta($term_id, '_cc_translation_group', $group_id);
        update_term_meta($term_id, 'cc_order', $order);

        return new WP_REST_Response([
            'term_id' => $term_id,
            'name' => $name,
            'lang' => $lang,
            'group_id' => $group_id,
            'order' => $order,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // POST /translate
    // -------------------------------------------------------------------------

    public function create_translation(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = $request->get_param('taxonomy');
        $name = $request->get_param('name');
        $lang = strtolower(trim($request->get_param('lang')));
        $group_id = $request->get_param('group_id');

        if (!taxonomy_exists($taxonomy)) {
            return new WP_REST_Response(['error' => 'Invalid taxonomy.'], 400);
        }

        $result = wp_insert_term($name, $taxonomy);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 422);
        }

        $term_id = $result['term_id'];
        $order = $this->next_order($taxonomy, $lang);

        update_term_meta($term_id, '_cc_language', $lang);
        update_term_meta($term_id, '_cc_translation_group', $group_id);
        update_term_meta($term_id, 'cc_order', $order);

        return new WP_REST_Response([
            'term_id' => $term_id,
            'name' => $name,
            'lang' => $lang,
            'group_id' => $group_id,
            'order' => $order,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // POST /rename
    // -------------------------------------------------------------------------

    public function rename_term(WP_REST_Request $request): WP_REST_Response
    {
        $term_id = $request->get_param('term_id');
        $taxonomy = $request->get_param('taxonomy');
        $name = $request->get_param('name');

        $result = wp_update_term($term_id, $taxonomy, [
            'name' => $name,
            'slug' => sanitize_title($name)
        ]);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 422);
        }

        return new WP_REST_Response(['term_id' => $term_id, 'name' => $name], 200);
    }

    // -------------------------------------------------------------------------
    // POST /remove
    // -------------------------------------------------------------------------

    public function remove_term(WP_REST_Request $request): WP_REST_Response
    {
        $term_id = $request->get_param('term_id');
        $taxonomy = $request->get_param('taxonomy');

        delete_term_meta($term_id, '_cc_language');
        delete_term_meta($term_id, '_cc_translation_group');
        delete_term_meta($term_id, 'cc_order');

        $result = wp_delete_term($term_id, $taxonomy);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 422);
        }

        return new WP_REST_Response(['deleted' => $term_id], 200);
    }

    // -------------------------------------------------------------------------
    // POST /delete-group
    // -------------------------------------------------------------------------

    public function delete_group(WP_REST_Request $request): WP_REST_Response
    {
        $taxonomy = $request->get_param('taxonomy');
        $group_id = $request->get_param('group_id');

        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_cc_translation_group',
                    'value' => $group_id,
                ]
            ],
        ]);

        if (is_wp_error($terms)) {
            return new WP_REST_Response(['error' => $terms->get_error_message()], 500);
        }

        $deleted = [];
        $errors = [];

        foreach ($terms as $term) {
            delete_term_meta($term->term_id, '_cc_language');
            delete_term_meta($term->term_id, '_cc_translation_group');
            delete_term_meta($term->term_id, 'cc_order');

            $result = wp_delete_term($term->term_id, $taxonomy);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $deleted[] = $term->term_id;
            }
        }

        if (!empty($errors) && empty($deleted)) {
            return new WP_REST_Response(['error' => implode('; ', $errors)], 422);
        }

        return new WP_REST_Response(['deleted' => $deleted, 'errors' => $errors], 200);
    }

    // -------------------------------------------------------------------------
    // POST /reorder
    // -------------------------------------------------------------------------

    public function reorder_terms(WP_REST_Request $request): WP_REST_Response
    {
        $order = $request->get_param('order'); // [ { term_id: int, order: int }, ... ]

        if (!is_array($order)) {
            return new WP_REST_Response(['error' => 'Invalid order data.'], 400);
        }

        foreach ($order as $item) {
            $term_id = absint($item['term_id'] ?? 0);
            $position = absint($item['order'] ?? 0);
            if ($term_id) {
                update_term_meta($term_id, 'cc_order', $position);
            }
        }

        return new WP_REST_Response(['updated' => count($order)], 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function next_order(string $taxonomy, string $lang): int
    {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_cc_language',
                    'value' => $lang,
                ]
            ],
        ]);

        if (is_wp_error($terms) || empty($terms)) {
            return 0;
        }

        $max = 0;
        foreach ($terms as $t) {
            $o = (int) (get_term_meta($t->term_id, 'cc_order', true) ?: 0);
            if ($o > $max) {
                $max = $o;
            }
        }

        return $max + 1;
    }
}

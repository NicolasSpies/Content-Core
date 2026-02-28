<?php
namespace ContentCore\Modules\LanguageMapping\Admin;

use ContentCore\Modules\LanguageMapping\LanguageMappingModule;
use ContentCore\Plugin;

class LanguageMappingAdmin
{
    private LanguageMappingModule $module;

    public function __construct(LanguageMappingModule $module)
    {
        $this->module = $module;
    }

    public function register(): void
    {
        // Use admin-post for reliable POST handling
        add_action('admin_post_cc_lm_action', [$this, 'handle_actions']);
    }

    /**
     * Helper for consistent error logging
     */
    private function log_error(string $message, array $context = []): void
    {
        \ContentCore\Logger::error('[LanguageMapping] ' . $message, $context);
    }

    public function handle_actions(): void
    {
        // 1. Core Security Gates
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'content-core'));
        }

        check_admin_referer('cc_lang_mapping_action', 'cc_lang_mapping_nonce');

        $action = sanitize_key($_POST['cc_action'] ?? '');

        try {
            switch ($action) {
                case 'create_term':
                    $this->handle_create_term();
                    break;
                case 'create_group_from_term':
                    $this->handle_create_group_from_term();
                    break;
                case 'create_empty_group':
                    $this->handle_create_empty_group();
                    break;
                case 'link_existing':
                    $this->handle_link_existing();
                    break;
                case 'unlink_term':
                    $this->handle_unlink_term();
                    break;
                case 'update_meta':
                    $this->handle_update_meta();
                    break;
                default:
                    throw new \Exception(__('Invalid action specified.', 'content-core'));
            }
        } catch (\Throwable $e) {
            $this->log_error($e->getMessage(), ['action' => $action]);
            set_transient('cc_mapping_error', $e->getMessage(), 30);
        }

        // Redirect back with current filters
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = admin_url('admin.php?page=content-core-language-mapping');
        }

        wp_safe_redirect($redirect_url);
        exit;
    }

    private function handle_create_term(): void
    {
        $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
        $name = sanitize_text_field($_POST['term_name'] ?? '');
        $slug = sanitize_title($_POST['term_slug'] ?? '');
        $lang = sanitize_key($_POST['cc_lang'] ?? '');
        $group_id = sanitize_text_field($_POST['cc_tr_group'] ?? '');

        if (!$taxonomy || !$lang || !$group_id) {
            throw new \Exception(__('Missing required mapping metadata.', 'content-core'));
        }

        // Default name if empty
        if (empty($name)) {
            $name = sprintf(__('New %s Term', 'content-core'), strtoupper($lang));
        }

        // Validate: Language uniqueness in group
        $this->ensure_language_unique_in_group($group_id, $taxonomy, $lang);

        $term_args = [];
        if (!empty($slug)) {
            $term_args['slug'] = $slug;
        }

        $result = wp_insert_term($name, $taxonomy, $term_args);
        if (is_wp_error($result)) {
            throw new \Exception(sprintf(__('Failed to create term: %s', 'content-core'), $result->get_error_message()));
        }

        $term_id = (int) $result['term_id'];
        update_term_meta($term_id, '_cc_language', $lang);
        update_term_meta($term_id, '_cc_tr_group', $group_id);

        $this->remove_empty_group($group_id);

        set_transient('cc_mapping_success', __('Term created and linked successfully.', 'content-core'), 30);
    }

    private function handle_create_group_from_term(): void
    {
        $term_id = absint($_POST['term_id'] ?? 0);
        if (!$term_id) {
            throw new \Exception(__('Invalid term ID.', 'content-core'));
        }

        $group_id = uniqid('cc_tr_', true);
        $updated = update_term_meta($term_id, '_cc_tr_group', $group_id);

        if (!$updated) {
            throw new \Exception(__('Failed to assign group ID to term.', 'content-core'));
        }

        set_transient('cc_mapping_success', __('New translation group created from term.', 'content-core'), 30);
    }

    private function handle_create_empty_group(): void
    {
        $group_id = uniqid('cc_tr_', true);
        $empty_groups = get_option('cc_empty_mapping_groups', []);
        $empty_groups[] = $group_id;
        update_option('cc_empty_mapping_groups', array_unique($empty_groups));
        set_transient('cc_mapping_success', __('New empty group row added.', 'content-core'), 30);
    }

    private function handle_link_existing(): void
    {
        $term_id = absint($_POST['term_id'] ?? 0);
        $group_id = sanitize_text_field($_POST['cc_tr_group'] ?? '');
        $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');

        if (!$term_id || !$group_id || !$taxonomy) {
            throw new \Exception(__('Missing linking parameters.', 'content-core'));
        }

        $term_lang = get_term_meta($term_id, '_cc_language', true);
        if (!$term_lang) {
            throw new \Exception(__('Target term must have a language assigned before it can be linked.', 'content-core'));
        }

        // Data model enforcement
        $this->ensure_language_unique_in_group($group_id, $taxonomy, $term_lang);

        update_term_meta($term_id, '_cc_tr_group', $group_id);
        $this->remove_empty_group($group_id);

        set_transient('cc_mapping_success', __('Term successfully linked to group.', 'content-core'), 30);
    }

    private function handle_unlink_term(): void
    {
        $term_id = absint($_POST['term_id'] ?? 0);
        if (!$term_id)
            return;

        delete_term_meta($term_id, '_cc_tr_group');
        set_transient('cc_mapping_success', __('Term unlinked from group.', 'content-core'), 30);
    }

    private function handle_update_meta(): void
    {
        $term_id = absint($_POST['term_id'] ?? 0);
        $lang = sanitize_key($_POST['cc_lang'] ?? '');
        if ($term_id && $lang) {
            update_term_meta($term_id, '_cc_language', $lang);
            set_transient('cc_mapping_success', __('Term language updated.', 'content-core'), 30);
        }
    }

    private function ensure_language_unique_in_group(string $group_id, string $taxonomy, string $lang): void
    {
        $existing = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_cc_tr_group',
                    'value' => $group_id
                ],
                [
                    'key' => '_cc_language',
                    'value' => $lang
                ]
            ]
        ]);

        if (!empty($existing) && !is_wp_error($existing)) {
            throw new \Exception(sprintf(__('Group already contains a term for %s.', 'content-core'), strtoupper($lang)));
        }
    }

    private function remove_empty_group(string $group_id): void
    {
        $empty_groups = get_option('cc_empty_mapping_groups', []);
        $empty_groups = array_diff($empty_groups, [$group_id]);
        update_option('cc_empty_mapping_groups', $empty_groups);
    }

    public function render_page(): void
    {
        try {
            $this->do_render_page();
        } catch (\Throwable $e) {
            $this->log_error('Render error: ' . $e->getMessage());
            if (is_admin()) {
                echo '<div class="wrap"><h1>' . __('Language Mapping', 'content-core') . '</h1>';
                echo '<div class="notice notice-error"><p>' . __('A fatal error occurred while rendering the management interface. Details have been logged.', 'content-core') . '</p></div></div>';
            }
        }
    }

    private function do_render_page(): void
    {
        $tab = sanitize_key($_GET['tab'] ?? 'groups');
        $taxonomy = sanitize_key($_GET['taxonomy'] ?? '');
        $status_filter = sanitize_key($_GET['status'] ?? 'all');

        $cc_taxonomies = $this->module->get_cc_taxonomies();
        if (empty($taxonomy) && !empty($cc_taxonomies)) {
            $taxonomy = $cc_taxonomies[0]->name;
        }

        $plugin = Plugin::get_instance();
        $ml_module = $plugin->get_module('multilingual');
        $languages = [];
        if ($ml_module) {
            $settings = get_option('cc_languages_settings', []);
            $languages = $settings['languages'] ?? [];
        }

        ?>
        <div class="wrap content-core-admin">
            <div class="cc-header"
                style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px;">
                <div>
                    <h1>
                        <?php _e('Language Mapping', 'content-core'); ?>
                    </h1>
                    <p style="color: #646970; margin-top: 4px;">
                        <?php _e('Manage translation groups and term relationships.', 'content-core'); ?>
                    </p>
                    <div class="notice notice-info inline" style="margin:8px 0 0 0; padding:6px 12px;">
                        <p style="margin:0; font-size:12px;">
                            <?php _e('This is a technical diagnostic view.', 'content-core'); ?>
                            <?php printf(
                                '<a href="%s">%s</a>',
                                esc_url(admin_url('admin.php?page=cc-manage-terms')),
                                esc_html__('Use Manage Terms for content editing.', 'content-core')
                            ); ?>
                        </p>
                    </div>
                </div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="cc_lm_action">
                    <?php wp_nonce_field('cc_lang_mapping_action', 'cc_lang_mapping_nonce'); ?>
                    <input type="hidden" name="cc_action" value="create_empty_group">
                    <button type="submit" class="button button-primary">
                        <?php _e('New Group', 'content-core'); ?>
                    </button>
                </form>
            </div>

            <?php
            $success = get_transient('cc_mapping_success');
            $error = get_transient('cc_mapping_error');
            if ($success) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($success) . '</p></div>';
                delete_transient('cc_mapping_success');
            }
            if ($error) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($error) . '</p></div>';
                delete_transient('cc_mapping_error');
            }
            ?>

            <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
                <a href="?page=content-core-language-mapping&tab=groups&taxonomy=<?php echo esc_attr($taxonomy); ?>"
                    class="nav-tab <?php echo $tab === 'groups' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Translation Groups', 'content-core'); ?>
                </a>
                <a href="?page=content-core-language-mapping&tab=diagnostics"
                    class="nav-tab <?php echo $tab === 'diagnostics' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Diagnostics', 'content-core'); ?>
                </a>
            </h2>

            <?php if ($tab === 'groups'): ?>
                <div class="cc-card">
                    <div style="display: flex; gap: 16px; margin-bottom: 24px; align-items: flex-end;">
                        <div>
                            <label style="display: block; margin-bottom: 4px; font-weight: 600;">
                                <?php _e('Taxonomy', 'content-core'); ?>
                            </label>
                            <select
                                onchange="window.location.href='?page=content-core-language-mapping&tab=groups&taxonomy=' + this.value;">
                                <?php foreach ($cc_taxonomies as $tax): ?>
                                    <option value="<?php echo esc_attr($tax->name); ?>" <?php selected($taxonomy, $tax->name); ?>>
                                        <?php echo esc_html($tax->label); ?>
                                    </option>
                                    <?php
                                endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 4px; font-weight: 600;">
                                <?php _e('Status Filter', 'content-core'); ?>
                            </label>
                            <select
                                onchange="window.location.href='?page=content-core-language-mapping&tab=groups&taxonomy=<?php echo esc_attr($taxonomy); ?>&status=' + this.value;">
                                <option value="all" <?php selected($status_filter, 'all'); ?>>
                                    <?php _e('All Groups', 'content-core'); ?>
                                </option>
                                <?php foreach ($languages as $l): ?>
                                    <option value="missing_<?php echo esc_attr($l['code']); ?>" <?php
                                       selected($status_filter, 'missing_' . $l['code']); ?>>
                                        <?php printf(__('Missing %s', 'content-core'), strtoupper($l['code'])); ?>
                                    </option>
                                    <?php
                                endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php $this->render_groups_table($taxonomy, $languages, $status_filter); ?>
                </div>
                <?php
            else: ?>
                <?php $this->render_diagnostics($cc_taxonomies, $languages); ?>
                <?php
            endif; ?>
        </div>

        <!-- Modal: Create Term -->
        <div id="cc-create-term-modal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
            <div class="cc-card" style="width: 400px; padding: 24px;">
                <h2 style="margin-top:0;">
                    <?php _e('Create Translation', 'content-core'); ?>
                </h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="cc_lm_action">
                    <?php wp_nonce_field('cc_lang_mapping_action', 'cc_lang_mapping_nonce'); ?>
                    <input type="hidden" name="cc_action" value="create_term">
                    <input type="hidden" name="cc_tr_group" id="modal-group-id" value="">
                    <input type="hidden" name="cc_lang" id="modal-lang-code" value="">
                    <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy); ?>">

                    <div style="margin-bottom: 15px;">
                        <label style="display:block; margin-bottom:5px;">
                            <?php _e('Name (leave empty for default)', 'content-core'); ?>
                        </label>
                        <input type="text" name="term_name" placeholder="<?php _e('e.g. My Category (FR)', 'content-core'); ?>"
                            style="width:100%;">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom:5px;">
                            <?php _e('Slug (optional)', 'content-core'); ?>
                        </label>
                        <input type="text" name="term_slug" style="width:100%;">
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" class="button"
                            onclick="document.getElementById('cc-create-term-modal').style.display='none'">
                            <?php _e('Cancel', 'content-core'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <?php _e('Create Term', 'content-core'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: Link Existing -->
        <div id="cc-link-modal"
            style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
            <div class="cc-card" style="width: 400px; padding: 24px;">
                <h2 style="margin-top:0;">
                    <?php _e('Link Existing Term', 'content-core'); ?>
                </h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="cc_lm_action">
                    <?php wp_nonce_field('cc_lang_mapping_action', 'cc_lang_mapping_nonce'); ?>
                    <input type="hidden" name="cc_action" value="link_existing">
                    <input type="hidden" name="cc_tr_group" id="link-modal-group-id" value="">
                    <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy); ?>">

                    <div style="margin-bottom: 20px;">
                        <label style="display:block; margin-bottom:5px;" id="link-modal-label">
                            <?php _e('Select Term', 'content-core'); ?>
                        </label>
                        <select name="term_id" id="link-modal-select" style="width:100%;" required>
                            <!-- Populated by JS -->
                        </select>
                        <p style="font-size: 11px; color: #646970; margin-top: 5px;">
                            <?php _e('Showing terms from this taxonomy with the required language and no assigned group.', 'content-core'); ?>
                        </p>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 12px;">
                        <button type="button" class="button"
                            onclick="document.getElementById('cc-link-modal').style.display='none'">
                            <?php _e('Cancel', 'content-core'); ?>
                        </button>
                        <button type="submit" class="button button-primary">
                            <?php _e('Link Term', 'content-core'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            /**
             * Term Candidate Data for "Link Existing"
             * Pre-rendered as JSON to avoid AJAX complexity while ensuring accuracy
             */
            const cc_lm_candidates = <?php
            $candidates = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_cc_language', 'compare' => 'EXISTS'],
                    ['key' => '_cc_tr_group', 'compare' => 'NOT EXISTS']
                ]
            ]);
            $data = [];
            if (!is_wp_error($candidates)) {
                foreach ($candidates as $c) {
                    $l = get_term_meta($c->term_id, '_cc_language', true);
                    if ($l) {
                        $data[$l][] = [
                            'id' => $c->term_id,
                            'name' => $c->name
                        ];
                    }
                }
            }
            echo json_encode($data);
            ?>;

            function openCreateModal(group, lang) {
                document.getElementById('modal-group-id').value = group;
                document.getElementById('modal-lang-code').value = lang;
                document.getElementById('cc-create-term-modal').style.display = 'flex';
            }

            function openLinkModal(group, lang, langLabel) {
                const select = document.getElementById('link-modal-select');
                const label = document.getElementById('link-modal-label');

                select.innerHTML = '';
                label.innerText = '<?php _e('Select Term', 'content-core'); ?> (' + langLabel + ')';

                const langCandidates = cc_lm_candidates[lang] || [];

                if (langCandidates.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = "";
                    opt.text = "<?php _e('No eligible terms found.', 'content-core'); ?>";
                    opt.disabled = true;
                    select.appendChild(opt);
                } else {
                    langCandidates.forEach(c => {
                        const opt = document.createElement('option');
                        opt.value = c.id;
                        opt.text = c.name;
                        select.appendChild(opt);
                    });
                }

                document.getElementById('link-modal-group-id').value = group;
                document.getElementById('cc-link-modal').style.display = 'flex';
            }
        </script>
        <?php
    }

    private function render_groups_table(string $taxonomy, array $languages, string $status_filter): void
    {
        $all_terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        if (is_wp_error($all_terms)) {
            echo '<div class="notice notice-error"><p>' . $all_terms->get_error_message() . '</p></div>';
            return;
        }

        $groups = [];

        foreach ($all_terms as $term) {
            $group_id = get_term_meta($term->term_id, '_cc_tr_group', true);
            if (!$group_id)
                continue;

            if (!isset($groups[$group_id])) {
                $groups[$group_id] = [
                    'id' => $group_id,
                    'terms' => []
                ];
            }
            $lang = get_term_meta($term->term_id, '_cc_language', true);
            if ($lang) {
                $groups[$group_id]['terms'][$lang] = $term;
            }
        }

        $empty_groups = get_option('cc_empty_mapping_groups', []);
        foreach ($empty_groups as $egid) {
            if (!isset($groups[$egid])) {
                $groups[$egid] = ['id' => $egid, 'terms' => []];
            }
        }

        if (strpos($status_filter, 'missing_') === 0) {
            $mlang = str_replace('missing_', '', $status_filter);
            $groups = array_filter($groups, function ($g) use ($mlang) {
                return !isset($g['terms'][$mlang]);
            });
        }

        $unlinked = array_filter($all_terms, function ($t) {
            return !get_term_meta($t->term_id, '_cc_tr_group', true);
        });

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 140px;">
                        <?php _e('Group ID / Taxonomy', 'content-core'); ?>
                    </th>
                    <?php foreach ($languages as $l): ?>
                        <th>
                            <?php echo strtoupper($l['code']); ?>
                        </th>
                        <?php
                    endforeach; ?>
                    <th style="width: 100px;">
                        <?php _e('Row Source', 'content-core'); ?>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="<?php echo count($languages) + 2; ?>">
                            <?php _e('No translation groups found.', 'content-core'); ?>
                        </td>
                    </tr>
                    <?php
                endif; ?>

                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td>
                            <code
                                style="font-size: 10px; display: block; margin-bottom: 4px;"><?php echo esc_html($g['id']); ?></code>
                            <span style="font-size: 11px; color: #646970;">
                                <?php echo esc_html($taxonomy); ?>
                            </span>
                        </td>
                        <?php foreach ($languages as $l):
                            $term = $g['terms'][$l['code']] ?? null;
                            ?>
                            <td>
                                <?php if ($term): ?>
                                    <div style="margin-bottom: 4px;">
                                        <strong>
                                            <?php echo esc_html($term->name); ?>
                                        </strong><br>
                                        <code style="font-size: 10px;"><?php echo esc_html($term->slug); ?></code>
                                    </div>
                                    <div class="row-actions" style="visibility: visible;">
                                        <span class="edit"><a href="<?php echo get_edit_term_link($term->term_id, $taxonomy); ?>">
                                                <?php _e('Edit', 'content-core'); ?>
                                            </a> | </span>
                                        <span class="trash">
                                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;">
                                                <input type="hidden" name="action" value="cc_lm_action">
                                                <?php wp_nonce_field('cc_lang_mapping_action', 'cc_lang_mapping_nonce'); ?>
                                                <input type="hidden" name="cc_action" value="unlink_term">
                                                <input type="hidden" name="term_id" value="<?php echo $term->term_id; ?>">
                                                <button type="submit" class="button-link-delete"
                                                    style="padding:0; min-height:0; line-height:inherit;"
                                                    onclick="return confirm('<?php _e('Unlink this term?', 'content-core'); ?>')">
                                                    <?php _e('Unlink', 'content-core'); ?>
                                                </button>
                                            </form>
                                        </span>
                                    </div>
                                    <?php
                                else: ?>
                                    <div style="display: flex; gap: 4px;">
                                        <button type="button" class="button button-small"
                                            onclick="openCreateModal('<?php echo $g['id']; ?>', '<?php echo $l['code']; ?>')">
                                            <?php _e('Create', 'content-core'); ?>
                                        </button>
                                        <button type="button" class="button button-small"
                                            onclick="openLinkModal('<?php echo $g['id']; ?>', '<?php echo $l['code']; ?>', '<?php echo esc_js($l['label']); ?>')">
                                            <?php _e('Link', 'content-core'); ?>
                                        </button>
                                    </div>
                                    <?php
                                endif; ?>
                            </td>
                            <?php
                        endforeach; ?>
                        <td>
                            <span style="font-size: 11px; color: #646970;">
                                <?php echo count($g['terms']) > 0 ? __('Grouped', 'content-core') : __('Empty Group', 'content-core'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php
                endforeach; ?>

                <?php if (!empty($unlinked)): ?>
                    <tr class="unlinked-header">
                        <td colspan="<?php echo count($languages) + 2; ?>"
                            style="background: #f6f7f7; font-weight: 600; padding: 12px; border-top: 2px solid #ccd0d4;">
                            <?php _e('Unlinked Terms (Candidates for new Groups)', 'content-core'); ?>
                        </td>
                    </tr>
                    <?php foreach ($unlinked as $ut):
                        $ulang = get_term_meta($ut->term_id, '_cc_language', true);
                        ?>
                        <tr>
                            <td>-</td>
                            <?php foreach ($languages as $l): ?>
                                <td>
                                    <?php if ($ulang === $l['code']): ?>
                                        <strong>
                                            <?php echo esc_html($ut->name); ?>
                                        </strong><br>
                                        <code style="font-size: 10px;"><?php echo esc_html($ut->slug); ?></code>
                                        <?php
                                    else: ?>
                                        <span style="color: #a0a5aa;">-</span>
                                        <?php
                                    endif; ?>
                                </td>
                                <?php
                            endforeach; ?>
                            <td>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                                    <input type="hidden" name="action" value="cc_lm_action">
                                    <?php wp_nonce_field('cc_lang_mapping_action', 'cc_lang_mapping_nonce'); ?>
                                    <input type="hidden" name="cc_action" value="create_group_from_term">
                                    <input type="hidden" name="term_id" value="<?php echo $ut->term_id; ?>">
                                    <button type="submit" class="button button-small">
                                        <?php _e('New Group', 'content-core'); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php
                    endforeach; ?>
                    <?php
                endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_diagnostics(array $taxonomies, array $languages): void
    {
        $stats = [
            'terms_missing_lang' => [],
            'terms_missing_group' => [],
            'duplicate_groups' => []
        ];

        foreach ($taxonomies as $tax) {
            $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
            if (is_wp_error($terms))
                continue;

            $groups = [];

            foreach ($terms as $term) {
                $lang = get_term_meta($term->term_id, '_cc_language', true);
                $group = get_term_meta($term->term_id, '_cc_tr_group', true);

                if (!$lang)
                    $stats['terms_missing_lang'][] = $term;
                if (!$group)
                    $stats['terms_missing_group'][] = $term;

                if ($group && $lang) {
                    if (isset($groups[$group][$lang])) {
                        $stats['duplicate_groups'][] = [
                            'group' => $group,
                            'lang' => $lang,
                            'term1' => $groups[$group][$lang],
                            'term2' => $term
                        ];
                    }
                    $groups[$group][$lang] = $term;
                }
            }
        }

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
            <div class="cc-card">
                <h3>
                    <?php _e('Overview', 'content-core'); ?>
                </h3>
                <ul style="margin:0; padding:0; list-style:none;">
                    <li style="padding:10px 0; border-bottom:1px solid #f0f0f1; display:flex; justify-content:space-between;">
                        <span>
                            <?php _e('Missing Language', 'content-core'); ?>
                        </span>
                        <strong style="color:<?php echo count($stats['terms_missing_lang']) ? '#d32f2f' : '#2e7d32'; ?>">
                            <?php echo count($stats['terms_missing_lang']); ?>
                        </strong>
                    </li>
                    <li style="padding:10px 0; border-bottom:1px solid #f0f0f1; display:flex; justify-content:space-between;">
                        <span>
                            <?php _e('Missing Group ID', 'content-core'); ?>
                        </span>
                        <strong>
                            <?php echo count($stats['terms_missing_group']); ?>
                        </strong>
                    </li>
                    <li style="padding:10px 0; display:flex; justify-content:space-between;">
                        <span>
                            <?php _e('Duplicate Mappings', 'content-core'); ?>
                        </span>
                        <strong style="color:<?php echo count($stats['duplicate_groups']) ? '#d32f2f' : '#2e7d32'; ?>">
                            <?php echo count($stats['duplicate_groups']); ?>
                        </strong>
                    </li>
                </ul>
            </div>

            <?php if (!empty($stats['duplicate_groups'])): ?>
                <div class="cc-card" style="border-left: 4px solid #d32f2f;">
                    <h3 style="color: #d32f2f;">
                        <?php _e('Duplicate Languages in Groups', 'content-core'); ?>
                    </h3>
                    <?php foreach ($stats['duplicate_groups'] as $dup): ?>
                        <div style="padding:8px; border-bottom:1px solid #f0f0f1; font-size:12px;">
                            <strong>
                                <?php echo strtoupper($dup['lang']); ?>
                            </strong> - <code><?php echo esc_html($dup['group']); ?></code><br>
                            1.
                            <?php echo esc_html($dup['term1']->name); ?><br>
                            2.
                            <?php echo esc_html($dup['term2']->name); ?>
                        </div>
                        <?php
                    endforeach; ?>
                </div>
                <?php
            endif; ?>
        </div>
        <?php
    }
}
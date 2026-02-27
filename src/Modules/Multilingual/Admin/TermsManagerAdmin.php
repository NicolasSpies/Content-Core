<?php
namespace ContentCore\Modules\Multilingual\Admin;

use ContentCore\Modules\Multilingual\MultilingualModule;

/**
 * Manage Multilingual Terms — admin page renderer.
 *
 * Renders the UI shell and enqueues the JS that consumes the REST API.
 * All data operations go through /wp-json/content-core/v1/terms-manager/*.
 *
 * No AJAX handlers. No wp_die. No redirects. No legacy logic.
 */
class TermsManagerAdmin
{
    private MultilingualModule $module;

    public function __construct(MultilingualModule $module)
    {
        $this->module = $module;
    }

    // -------------------------------------------------------------------------
    // Enqueue assets (only on our page)
    // -------------------------------------------------------------------------

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'content-core_page_cc-manage-terms') {
            return;
        }

        $plugin_url = plugin_dir_url(CONTENT_CORE_PLUGIN_FILE);
        $version = CONTENT_CORE_VERSION;

        wp_enqueue_script(
            'cc-terms-manager',
            $plugin_url . 'assets/js/terms-manager.js',
            ['wp-api-fetch', 'wp-api', 'jquery'],
            $version,
            true
        );

        wp_localize_script('cc-terms-manager', 'ccTermsManager', [
            'restBase' => rest_url('content-core/v1/terms-manager'),
            'nonce' => wp_create_nonce('wp_rest'),
            'settings' => $this->module->get_settings(),
            'languages' => $this->get_active_languages(),
            'default' => $this->module->get_settings()['default_lang'] ?? 'de',
        ]);

        wp_enqueue_style(
            'cc-terms-manager',
            $plugin_url . 'assets/css/terms-manager.css',
            [],
            $version
        );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public function render_page(): void
    {
        $settings = $this->module->get_settings();
        $languages = $this->get_active_languages();
        $default = $settings['default_lang'] ?? 'de';

        // Build taxonomy list
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        ?>
        <div class="wrap cc-terms-manager" id="cc-terms-manager">
            <h1><?php _e('Manage Multilingual Terms', 'content-core'); ?></h1>

            <div id="cc-tm-notice" class="cc-tm-notice" style="display:none;"></div>

            <!-- ── Toolbar ─────────────────────────────────────────────────── -->
            <div class="cc-tm-toolbar">
                <label for="cc-tm-taxonomy"><?php _e('Taxonomy', 'content-core'); ?></label>
                <select id="cc-tm-taxonomy">
                    <?php foreach ($taxonomies as $tax): ?>
                        <?php if (!$tax->show_ui)
                            continue; ?>
                        <option value="<?php echo esc_attr($tax->name); ?>">
                            <?php echo esc_html($tax->label ?: $tax->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>



                <div class="cc-tm-create-wrap">
                    <input type="text" id="cc-tm-new-name" placeholder="<?php esc_attr_e('New term name…', 'content-core'); ?>">
                    <button id="cc-tm-create-btn" class="button button-primary">
                        <?php _e('Create Term', 'content-core'); ?>
                    </button>
                </div>
            </div>

            <!-- ── Table ───────────────────────────────────────────────────── -->
            <div id="cc-tm-table-wrap">
                <table class="cc-tm-table widefat" id="cc-tm-table">
                    <thead>
                        <tr>
                            <th class="cc-tm-drag-col"></th>
                            <th><?php _e('Group', 'content-core'); ?></th>
                            <?php foreach ($languages as $code => $label): ?>
                                <th class="cc-tm-lang-col" data-lang="<?php echo esc_attr($code); ?>">
                                    <?php echo esc_html(strtoupper($code)); ?>
                                </th>
                            <?php endforeach; ?>
                            <th><?php _e('Actions', 'content-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cc-tm-tbody">
                        <tr class="cc-tm-loading">
                            <td colspan="<?php echo 3 + count($languages); ?>">
                                <?php _e('Loading…', 'content-core'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ── Delete-group confirm modal ──────────────────────────────── -->
            <div id="cc-tm-modal" class="cc-tm-modal" style="display:none;" role="dialog" aria-modal="true">
                <div class="cc-tm-modal-box">
                    <h2><?php _e('Delete Translation Group', 'content-core'); ?></h2>
                    <p id="cc-tm-modal-msg"></p>
                    <div class="cc-tm-modal-actions">
                        <button id="cc-tm-modal-cancel" class="button">
                            <?php _e('Cancel', 'content-core'); ?>
                        </button>
                        <button id="cc-tm-modal-confirm" class="button button-primary button-danger">
                            <?php _e('Delete', 'content-core'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Helper: active languages
    // -------------------------------------------------------------------------

    public function get_active_languages(): array
    {
        $settings = $this->module->get_settings();
        $default = $settings['default_lang'] ?? 'de';
        $languages = $settings['languages'] ?? [];

        // Build ordered map: default first, then rest alphabetically
        $map = [];

        // Default lang first
        $map[$default] = $this->lang_label($default);

        foreach ($languages as $lang) {
            $code = is_array($lang) ? ($lang['code'] ?? '') : (string) $lang;
            $code = strtolower(trim($code));
            if ($code && $code !== $default) {
                $label = is_array($lang) ? ($lang['label'] ?? $code) : $code;
                $map[$code] = $this->lang_label($code, $label);
            }
        }

        return $map;
    }

    private function lang_label(string $code, string $fallback = ''): string
    {
        $known = [
            'de' => 'Deutsch',
            'en' => 'English',
            'fr' => 'Français',
            'it' => 'Italiano',
            'es' => 'Español',
            'pt' => 'Português',
            'nl' => 'Nederlands',
            'pl' => 'Polski',
            'ru' => 'Русский',
            'zh' => '中文',
            'ja' => '日本語',
            'ar' => 'العربية',
        ];

        return $known[$code] ?? ($fallback ?: strtoupper($code));
    }
}

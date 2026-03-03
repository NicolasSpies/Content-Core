<?php
namespace ContentCore\Admin;

/**
 * Replaces the default WordPress dashboard widgets with a fixed client dashboard.
 */
class StandardDashboardCustomizer
{
    private const HEADER_OPTION_KEY = 'cc_dashboard_header';

    public function init(): void
    {
        add_action('wp_dashboard_setup', [$this, 'replace_default_dashboard'], 99);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('current_screen', [$this, 'customize_dashboard_screen']);
        add_filter('screen_options_show_screen', [$this, 'hide_screen_options_on_dashboard'], 10, 2);
        add_filter('admin_footer_text', [$this, 'hide_admin_footer_text_on_dashboard'], 1001);
        add_filter('update_footer', [$this, 'hide_admin_footer_text_on_dashboard'], 1001);
        add_filter('get_user_option_screen_layout_dashboard', [$this, 'force_single_column']);
    }

    public function force_single_column($value)
    {
        global $pagenow;
        if ($pagenow === 'index.php') {
            return 1;
        }
        return $value;
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'index.php' || !$this->should_apply()) {
            return;
        }

        wp_enqueue_style('cc-admin-modern');
        wp_enqueue_media();

        $accent = '#2271b1';
        $settings = $this->get_branding_settings();
        if (!empty($settings['custom_accent_color']) && is_string($settings['custom_accent_color'])) {
            $accent = $settings['custom_accent_color'];
        }
        wp_add_inline_style('cc-admin-modern', sprintf(':root{--cc-accent-color:%s;}', esc_attr($accent)));
        wp_add_inline_style('cc-admin-modern', $this->get_inline_css());
        wp_add_inline_script('jquery-core', $this->get_inline_js());
    }

    public function replace_default_dashboard(): void
    {
        if (!$this->should_apply()) {
            return;
        }

        remove_action('welcome_panel', 'wp_welcome_panel');

        $ids = [
            'dashboard_right_now',
            'dashboard_activity',
            'dashboard_site_health',
            'dashboard_quick_press',
            'dashboard_primary',
            'dashboard_secondary',
            'dashboard_recent_comments',
            'dashboard_recent_drafts',
            'dashboard_incoming_links',
            'dashboard_plugins',
        ];

        foreach (['normal', 'side'] as $context) {
            foreach ($ids as $id) {
                remove_meta_box($id, 'dashboard', $context);
            }
        }

        wp_add_dashboard_widget(
            'cc_client_dashboard_widget',
            $this->t('content_workspace'),
            [$this, 'render_widget']
        );
    }

    public function customize_dashboard_screen($screen): void
    {
        if (!$screen || !isset($screen->id) || (string) $screen->id !== 'dashboard') {
            return;
        }

        if (method_exists($screen, 'remove_help_tabs')) {
            $screen->remove_help_tabs();
        }
        if (method_exists($screen, 'set_help_sidebar')) {
            $screen->set_help_sidebar('');
        }
    }

    public function hide_screen_options_on_dashboard($show, $screen)
    {
        if ($screen && isset($screen->id) && (string) $screen->id === 'dashboard') {
            return false;
        }
        return $show;
    }

    public function hide_admin_footer_text_on_dashboard($text)
    {
        global $pagenow;
        if ($pagenow === 'index.php') {
            return '';
        }
        return $text;
    }

    public function render_widget(): void
    {
        $notice = $this->maybe_save_header_settings();
        $content_counts = $this->collect_content_counts();
        $health_report = (new CacheService())->get_consolidated_health_report();
        $translation_rows = $this->collect_translation_rows($health_report);

        $branding_settings = $this->get_branding_settings();
        $brand_logo_url = $this->resolve_brand_logo_url($branding_settings);
        $header = $this->get_header_settings();
        $can_edit_header = current_user_can('edit_pages');
        ?>
        <div class="cc-wp-dashboard">
            <?php if ($notice !== ''): ?>
                <div class="cc-wp-notice"><?php echo esc_html($notice); ?></div>
            <?php endif; ?>
            <div class="cc-wp-dashboard-hero">
                <div class="cc-wp-profile-header">
                    <?php if ($header['cover_url'] !== ''): ?>
                        <div class="cc-wp-profile-cover" style="background-image:url('<?php echo esc_url($header['cover_url']); ?>');"></div>
                    <?php else: ?>
                        <div class="cc-wp-profile-cover cc-wp-profile-cover-fallback"></div>
                    <?php endif; ?>
                    <?php if ($can_edit_header): ?>
                        <details class="cc-wp-profile-edit cc-wp-profile-edit-icon">
                            <summary aria-label="<?php echo esc_attr($this->t('edit_header')); ?>" title="<?php echo esc_attr($this->t('edit_header')); ?>">
                                <span class="dashicons dashicons-edit cc-wp-edit-icon-open"></span>
                                <span class="dashicons dashicons-no-alt cc-wp-edit-icon-close"></span>
                            </summary>
                            <form method="post">
                                <input type="hidden" name="cc_dashboard_action" value="save_header">
                                <?php wp_nonce_field('cc_dashboard_save_header', 'cc_dashboard_nonce'); ?>
                                <input type="hidden" name="cc_dashboard_header_cover" value="<?php echo esc_attr($header['cover_url']); ?>" class="js-cc-cover-url">
                                <input type="hidden" name="cc_dashboard_header_cover_id" value="<?php echo (int) $header['cover_id']; ?>" class="js-cc-cover-id">
                                <label>
                                    <?php echo esc_html($this->t('header_cover_library')); ?>
                                    <div class="cc-wp-cover-actions">
                                        <button type="button" class="button js-cc-cover-select"><?php echo esc_html($this->t('choose_from_library')); ?></button>
                                    </div>
                                </label>
                                <button type="submit" class="button button-primary"><?php echo esc_html($this->t('save')); ?></button>
                            </form>
                        </details>
                    <?php endif; ?>
                    <div class="cc-wp-profile-row">
                        <div class="cc-wp-profile-brand">
                            <?php if ($brand_logo_url !== ''): ?>
                                <img src="<?php echo esc_url($brand_logo_url); ?>" alt="<?php echo esc_attr($this->t('brand_logo_alt')); ?>"
                                    class="cc-wp-dashboard-logo">
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cc-wp-card cc-wp-card-wide">
                <h3><?php echo esc_html($this->t('shortcuts')); ?></h3>
                <?php $shortcuts = $this->get_shortcuts(); ?>
                <?php if (empty($shortcuts)): ?>
                    <p class="cc-wp-empty"><?php echo esc_html($this->t('no_shortcuts')); ?></p>
                <?php else: ?>
                    <div class="cc-wp-shortcuts">
                        <?php foreach ($shortcuts as $shortcut): ?>
                            <a href="<?php echo esc_url((string) $shortcut['url']); ?>" class="cc-wp-shortcut">
                                <span class="dashicons <?php echo esc_attr((string) ($shortcut['icon'] ?? 'dashicons-arrow-right-alt2')); ?>"></span>
                                <span><?php echo esc_html((string) $shortcut['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cc-wp-dashboard-grid">
                <div class="cc-wp-card cc-wp-card-status">
                    <h3><?php echo esc_html($this->t('content_status')); ?></h3>
                    <?php if (empty($content_counts['custom_types'])): ?>
                        <p class="cc-wp-empty"><?php echo esc_html($this->t('no_custom_types')); ?></p>
                    <?php else: ?>
                        <div class="cc-wp-kpis">
                            <?php foreach ($content_counts['custom_types'] as $type): ?>
                                <div class="cc-wp-kpi-row">
                                    <div class="cc-wp-kpi-main">
                                        <span><?php echo esc_html((string) ($type['label'] ?? '')); ?></span>
                                        <strong><?php echo (int) ($type['published'] ?? 0); ?></strong>
                                    </div>
                                    <?php if (!empty($type['create_url'])): ?>
                                        <a
                                            class="cc-wp-kpi-add"
                                            href="<?php echo esc_url((string) $type['create_url']); ?>"
                                            title="<?php echo esc_attr(sprintf('%s: %s', $this->t('create_new'), (string) ($type['label'] ?? ''))); ?>"
                                            aria-label="<?php echo esc_attr(sprintf('%s: %s', $this->t('create_new'), (string) ($type['label'] ?? ''))); ?>"
                                        >+</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="cc-wp-card cc-wp-card-translations">
                    <h3><?php echo esc_html($this->t('translation_status')); ?></h3>
                    <?php if (empty($translation_rows)): ?>
                        <p class="cc-wp-empty"><?php echo esc_html($this->t('multilingual_inactive')); ?></p>
                    <?php else: ?>
                        <div class="cc-wp-translation-list">
                            <?php foreach ($translation_rows as $row): ?>
                                <details class="cc-wp-translation-item">
                                    <summary class="cc-wp-translation-summary">
                                        <div class="cc-wp-translation-lang">
                                            <span class="cc-wp-lang-flag"><?php echo wp_kses($row['flag_html'], $this->get_allowed_flag_html_tags()); ?></span>
                                            <strong><?php echo esc_html($row['code']); ?></strong>
                                        </div>
                                        <div class="cc-wp-translation-cell">
                                            <span class="cc-status-pill cc-status-<?php echo esc_attr($row['status']); ?>">
                                                <?php echo esc_html(strtoupper($this->t((string) $row['label_key']))); ?>
                                            </span>
                                        </div>
                                        <div class="cc-wp-translation-progress">
                                            <small><?php echo esc_html((string) $row['progress_percent']); ?></small>
                                        </div>
                                        <span class="cc-wp-translation-toggle dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                                    </summary>
                                    <div class="cc-wp-translation-details">
                                        <?php $pages_url = $this->get_translation_overview_url('page', (string) ($row['code'] ?? '')); ?>
                                        <?php if ($pages_url !== ''): ?>
                                            <a class="cc-wp-translation-detail cc-wp-translation-detail-link" href="<?php echo esc_url($pages_url); ?>">
                                                <span><?php echo esc_html($this->t('pages')); ?></span>
                                                <div class="cc-wp-translation-cell">
                                                    <span class="cc-status-pill cc-status-<?php echo esc_attr($row['pages_status']); ?>">
                                                        <?php echo esc_html(strtoupper($this->t((string) $row['pages_label_key']))); ?>
                                                    </span>
                                                    <small><?php echo esc_html($row['pages_progress']); ?></small>
                                                </div>
                                            </a>
                                        <?php else: ?>
                                            <div class="cc-wp-translation-detail">
                                                <span><?php echo esc_html($this->t('pages')); ?></span>
                                                <div class="cc-wp-translation-cell">
                                                    <span class="cc-status-pill cc-status-<?php echo esc_attr($row['pages_status']); ?>">
                                                        <?php echo esc_html(strtoupper($this->t((string) $row['pages_label_key']))); ?>
                                                    </span>
                                                    <small><?php echo esc_html($row['pages_progress']); ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <?php foreach ((array) ($row['type_breakdown'] ?? []) as $type_row): ?>
                                            <?php $type_url = $this->get_translation_overview_url((string) ($type_row['slug'] ?? ''), (string) ($row['code'] ?? '')); ?>
                                            <?php if ($type_url !== ''): ?>
                                                <a class="cc-wp-translation-detail cc-wp-translation-detail-link" href="<?php echo esc_url($type_url); ?>">
                                                    <span><?php echo esc_html((string) ($type_row['label'] ?? '')); ?></span>
                                                    <div class="cc-wp-translation-cell">
                                                        <span class="cc-status-pill cc-status-<?php echo esc_attr((string) ($type_row['status'] ?? 'healthy')); ?>">
                                                            <?php echo esc_html(strtoupper($this->t((string) ($type_row['label_key'] ?? 'complete')))); ?>
                                                        </span>
                                                        <small><?php echo esc_html((string) ($type_row['progress'] ?? '0 / 0')); ?></small>
                                                    </div>
                                                </a>
                                            <?php else: ?>
                                                <div class="cc-wp-translation-detail">
                                                    <span><?php echo esc_html((string) ($type_row['label'] ?? '')); ?></span>
                                                    <div class="cc-wp-translation-cell">
                                                        <span class="cc-status-pill cc-status-<?php echo esc_attr((string) ($type_row['status'] ?? 'healthy')); ?>">
                                                            <?php echo esc_html(strtoupper($this->t((string) ($type_row['label_key'] ?? 'complete')))); ?>
                                                        </span>
                                                        <small><?php echo esc_html((string) ($type_row['progress'] ?? '0 / 0')); ?></small>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
    }

    private function should_apply(): bool
    {
        if (!is_admin()) {
            return false;
        }
        global $pagenow;
        return $pagenow === 'index.php';
    }

    private function get_branding_settings(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $branding_module = $plugin->get_module('branding');
        if (!($branding_module instanceof \ContentCore\Modules\Branding\BrandingModule)) {
            return [];
        }
        return $branding_module->get_settings();
    }

    private function resolve_brand_logo_url(array $settings): string
    {
        $logo = $settings['login_logo'] ?? '';
        if (is_numeric($logo) && (int) $logo > 0) {
            return (string) (wp_get_attachment_image_url((int) $logo, 'full') ?: wp_get_attachment_url((int) $logo) ?: '');
        }
        if (is_string($logo) && $logo !== '') {
            return esc_url_raw($logo);
        }
        if (!empty($settings['login_logo_url']) && is_string($settings['login_logo_url'])) {
            return esc_url_raw($settings['login_logo_url']);
        }
        return '';
    }

    private function collect_content_counts(): array
    {
        $custom_type_objects = get_post_types(['show_ui' => true], 'objects');
        $custom_types = [];
        if (is_array($custom_type_objects)) {
            foreach ($custom_type_objects as $post_type => $obj) {
                $slug = sanitize_key((string) $post_type);
                if ($this->is_excluded_post_type($slug)) {
                    continue;
                }
                $custom_types[$slug] = $obj;
            }
        }

        $posts = wp_count_posts('post');
        $pages = wp_count_posts('page');
        $custom_published = 0;
        $custom_drafts = 0;
        $custom_type_rows = [];
        foreach ($custom_types as $slug => $obj) {
            $count = wp_count_posts($slug);
            if ($count) {
                $custom_published += (int) ($count->publish ?? 0);
                $custom_drafts += (int) ($count->draft ?? 0);
                $label = '';
                if (isset($obj->labels) && is_object($obj->labels) && !empty($obj->labels->name)) {
                    $label = (string) $obj->labels->name;
                } elseif (!empty($obj->label)) {
                    $label = (string) $obj->label;
                } else {
                    $label = strtoupper($slug);
                }

                $custom_type_rows[] = [
                    'slug' => $slug,
                    'label' => $label,
                    'published' => (int) ($count->publish ?? 0),
                    'menu_slug' => 'edit.php?post_type=' . $slug,
                    'create_url' => current_user_can((string) (($obj->cap->create_posts ?? null) ?: 'edit_posts'))
                        ? admin_url('post-new.php?post_type=' . $slug)
                        : '',
                ];
            }
        }

        $custom_type_rows = $this->sort_items_by_visibility_order($custom_type_rows, 'menu_slug');

        return [
            'pages_published' => (int) ($pages->publish ?? 0),
            'posts_published' => (int) ($posts->publish ?? 0),
            'custom_published' => $custom_published,
            'drafts_total' => (int) ($pages->draft ?? 0) + (int) ($posts->draft ?? 0) + $custom_drafts,
            'custom_types' => $custom_type_rows,
        ];
    }

    private function collect_translation_rows(array $health_report): array
    {
        $subsystems = (array) ($health_report['subsystems'] ?? []);
        $ml = (array) ($subsystems['multilingual']['data'] ?? []);
        $default_lang = strtoupper((string) ($ml['default_lang'] ?? ''));
        $flag_map = $this->get_language_flag_html_map();
        $enabled = array_values(array_filter(array_map(function ($code) {
            return strtoupper((string) $code);
        }, (array) ($ml['enabled_languages'] ?? []))));
        $coverage = (array) ($ml['coverage_by_language'] ?? []);

        $rows = [];
        foreach ($enabled as $code) {
            $item = (array) ($coverage[strtolower($code)] ?? []);
            $state = (string) ($item['status'] ?? 'healthy');
            $by_type = (array) ($item['by_type'] ?? []);

            $pages = (array) ($by_type['page'] ?? []);
            $pages_translated = (int) ($pages['translated'] ?? 0);
            $pages_total = (int) ($pages['total'] ?? 0);
            [$pages_label_key, $pages_status] = $this->status_from_counts($pages_translated, $pages_total);

            $types_total = 0;
            $types_translated = 0;
            $type_breakdown = [];
            foreach ($by_type as $post_type => $data) {
                $type_slug = sanitize_key((string) $post_type);
                if ($type_slug === 'page' || $type_slug === 'post' || $this->is_excluded_post_type($type_slug)) {
                    continue;
                }
                $type_total = (int) (($data['total'] ?? 0));
                $type_translated = (int) (($data['translated'] ?? 0));
                $types_total += $type_total;
                $types_translated += $type_translated;

                [$type_label_key, $type_status] = $this->status_from_counts($type_translated, $type_total);
                $type_obj = get_post_type_object($type_slug);
                $type_label = '';
                if ($type_obj && isset($type_obj->labels) && is_object($type_obj->labels) && !empty($type_obj->labels->name)) {
                    $type_label = (string) $type_obj->labels->name;
                } elseif ($type_obj && !empty($type_obj->label)) {
                    $type_label = (string) $type_obj->label;
                } else {
                    $type_label = strtoupper($type_slug);
                }

                $type_breakdown[] = [
                    'slug' => $type_slug,
                    'label' => $type_label,
                    'label_key' => $type_label_key,
                    'status' => $type_status,
                    'progress' => $this->format_progress($type_translated, $type_total),
                ];
            }

            usort($type_breakdown, function (array $a, array $b): int {
                return strcmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
            });

            [$types_label_key, $types_status] = $this->status_from_counts($types_translated, $types_total);

            $rows[] = [
                'code' => $code,
                'is_default' => ($code === $default_lang),
                'flag_html' => $flag_map[strtolower($code)] ?? '<span>' . esc_html($code) . '</span>',
                'status' => $state === 'critical' ? 'error' : ($state === 'warning' ? 'warning' : 'healthy'),
                'label_key' => $state === 'critical' ? 'missing' : ($state === 'warning' ? 'partial' : 'complete'),
                'progress' => $this->format_progress((int) ($item['translated'] ?? 0), (int) ($item['total'] ?? 0)),
                'progress_percent' => $this->format_progress_percent((int) ($item['translated'] ?? 0), (int) ($item['total'] ?? 0)),
                'pages_status' => $pages_status,
                'pages_label_key' => $pages_label_key,
                'pages_progress' => $this->format_progress($pages_translated, $pages_total),
                'types_status' => $types_status,
                'types_label_key' => $types_label_key,
                'types_progress' => $this->format_progress($types_translated, $types_total),
                'type_breakdown' => $type_breakdown,
            ];
        }

        $language_settings = get_option('cc_languages_settings', []);
        $language_order = [];
        if (is_array($language_settings) && !empty($language_settings['languages']) && is_array($language_settings['languages'])) {
            foreach ($language_settings['languages'] as $idx => $language) {
                if (!is_array($language) || empty($language['code'])) {
                    continue;
                }
                $code = strtoupper(sanitize_key((string) $language['code']));
                if ($code !== '' && !isset($language_order[$code])) {
                    $language_order[$code] = (int) $idx;
                }
            }
        }

        foreach ($rows as $idx => &$row) {
            $code = strtoupper((string) ($row['code'] ?? ''));
            $row['_sort_position'] = $language_order[$code] ?? 999999;
            $row['_original_index'] = (int) $idx;
        }
        unset($row);

        usort($rows, function (array $a, array $b): int {
            $a_pos = (int) ($a['_sort_position'] ?? 999999);
            $b_pos = (int) ($b['_sort_position'] ?? 999999);
            if ($a_pos === $b_pos) {
                return ((int) ($a['_original_index'] ?? 0)) <=> ((int) ($b['_original_index'] ?? 0));
            }
            return $a_pos <=> $b_pos;
        });

        foreach ($rows as &$row) {
            unset($row['_sort_position'], $row['_original_index']);
        }
        unset($row);

        return $rows;
    }

    private function status_from_counts(int $translated, int $total): array
    {
        if ($total <= 0) {
            return ['complete', 'healthy'];
        }
        if ($translated <= 0) {
            return ['missing', 'error'];
        }
        if ($translated < $total) {
            return ['partial', 'warning'];
        }
        return ['complete', 'healthy'];
    }

    private function get_language_flag_html_map(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $ml_module = $plugin->get_module('multilingual');
        if (!($ml_module instanceof \ContentCore\Modules\Multilingual\MultilingualModule) || !$ml_module->is_active()) {
            return [];
        }

        $settings = $ml_module->get_settings();
        $map = [];
        foreach ((array) ($settings['languages'] ?? []) as $language) {
            if (!is_array($language) || empty($language['code'])) {
                continue;
            }
            $code = sanitize_key((string) $language['code']);
            if ($code === '') {
                continue;
            }
            $flag_id = isset($language['flag_id']) ? absint($language['flag_id']) : 0;
            $map[$code] = $ml_module->get_flag_html($code, $flag_id);
        }

        return $map;
    }

    private function get_allowed_flag_html_tags(): array
    {
        return [
            'img' => [
                'src' => true,
                'alt' => true,
                'width' => true,
                'height' => true,
                'class' => true,
                'style' => true,
            ],
            'svg' => [
                'xmlns' => true,
                'viewBox' => true,
                'width' => true,
                'height' => true,
                'role' => true,
                'aria-label' => true,
            ],
            'rect' => [
                'x' => true,
                'y' => true,
                'width' => true,
                'height' => true,
                'fill' => true,
            ],
            'polygon' => [
                'points' => true,
                'fill' => true,
            ],
            'span' => [
                'class' => true,
                'style' => true,
            ],
        ];
    }

    private function format_progress(int $translated, int $total): string
    {
        if ($total <= 0) {
            return '0 / 0';
        }
        $percent = (int) round(($translated / $total) * 100);
        return sprintf('%d / %d' . "\u{00A0}\u{00A0}" . '(%d%%)', $translated, $total, $percent);
    }

    private function format_progress_percent(int $translated, int $total): string
    {
        if ($total <= 0) {
            return '0%';
        }
        $percent = (int) round(($translated / $total) * 100);
        return sprintf('%d%%', $percent);
    }

    private function build_todo_items(array $content_counts, array $translation_rows): array
    {
        $items = [];

        if ((int) ($content_counts['drafts_total'] ?? 0) > 0) {
            $items[] = [
                'level' => 'warning',
                'text' => sprintf($this->t('drafts_waiting'), (int) $content_counts['drafts_total']),
                'url' => admin_url('edit.php?post_status=draft'),
            ];
        }

        $translation_missing = 0;
        foreach ($translation_rows as $row) {
            if (($row['label_key'] ?? '') !== 'complete') {
                $translation_missing++;
            }
        }
        if ($translation_missing > 0) {
            $items[] = [
                'level' => 'warning',
                'text' => sprintf($this->t('languages_incomplete'), $translation_missing),
                'url' => admin_url('admin.php?page=cc-multilingual'),
            ];
        }

        if (empty($items)) {
            $items[] = [
                'level' => 'success',
                'text' => $this->t('no_urgent_tasks'),
                'url' => '',
            ];
        }

        return $items;
    }

    private function build_settings_completeness_scan(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $settings_module = $plugin->get_module('settings');
        if (!($settings_module instanceof \ContentCore\Modules\Settings\SettingsModule)) {
            return [];
        }

        $registry = $settings_module->get_registry();
        $seo = (array) $registry->get(\ContentCore\Modules\Settings\SettingsModule::SEO_KEY);
        $images = (array) $registry->get('cc_site_images');
        $cookie = (array) $registry->get(\ContentCore\Modules\Settings\SettingsModule::COOKIE_KEY);

        $scan = [
            [
                'label' => $this->t('seo'),
                'url' => admin_url('admin.php?page=cc-seo'),
                'missing' => $this->collect_missing($seo, ['title' => 'title', 'description' => 'description']),
            ],
            [
                'label' => $this->t('cookie_banner'),
                'url' => admin_url('admin.php?page=cc-cookie-banner'),
                'missing' => !empty($cookie['enabled']) ? $this->collect_missing($cookie, [
                    'bannerTitle' => 'banner title',
                    'bannerText' => 'banner text',
                ]) : [],
            ],
            [
                'label' => $this->t('site_options'),
                'url' => admin_url('admin.php?page=cc-site-options'),
                'missing' => $this->collect_site_options_missing(),
            ],
        ];

        foreach ($scan as &$row) {
            $row['status'] = empty($row['missing']) ? 'success' : 'missing';
        }
        if ((int) ($images['social_icon_id'] ?? 0) <= 0 && isset($scan[0])) {
            $scan[0]['missing'][] = 'favicon';
            $scan[0]['status'] = 'missing';
        }
        return $scan;
    }

    private function collect_missing(array $data, array $requirements, bool $numeric_as_required = false): array
    {
        $missing = [];
        foreach ($requirements as $path => $label) {
            $value = $this->get_path_value($data, $path);
            if ($this->is_missing_value($value, $numeric_as_required)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    private function collect_site_options_missing(): array
    {
        $plugin = \ContentCore\Plugin::get_instance();
        $site_options_module = $plugin->get_module('site_options');
        if (!($site_options_module instanceof \ContentCore\Modules\SiteOptions\SiteOptionsModule)) {
            return ['module inactive'];
        }

        $options = $site_options_module->get_options();
        return empty($options) ? ['site profile'] : [];
    }

    private function get_path_value(array $data, string $path)
    {
        $parts = explode('.', $path);
        $current = $data;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private function is_missing_value($value, bool $numeric_as_required = false): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if ($numeric_as_required && (is_int($value) || is_float($value))) {
            return (int) $value <= 0;
        }

        return false;
    }

    private function maybe_save_header_settings(): string
    {
        if (!isset($_POST['cc_dashboard_action']) || $_POST['cc_dashboard_action'] !== 'save_header') {
            return '';
        }

        if (!current_user_can('edit_pages')) {
            return $this->t('save_failed');
        }

        check_admin_referer('cc_dashboard_save_header', 'cc_dashboard_nonce');

        $current = $this->get_header_settings();
        $title = (string) ($current['title'] ?? $this->get_default_header_title());
        $subtitle = (string) ($current['subtitle'] ?? '');
        $cover_url = isset($_POST['cc_dashboard_header_cover']) ? esc_url_raw(wp_unslash((string) $_POST['cc_dashboard_header_cover'])) : '';
        $cover_id = isset($_POST['cc_dashboard_header_cover_id']) ? absint(wp_unslash((string) $_POST['cc_dashboard_header_cover_id'])) : 0;

        $payload = [
            'title' => $title !== '' ? $title : $this->get_default_header_title(),
            'subtitle' => $subtitle,
            'cover_url' => $cover_url,
            'cover_id' => $cover_id,
        ];

        update_option(self::HEADER_OPTION_KEY, $payload, false);
        return $this->t('saved_successfully');
    }

    private function get_header_settings(): array
    {
        $saved = get_option(self::HEADER_OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $title = isset($saved['title']) ? sanitize_text_field((string) $saved['title']) : $this->get_default_header_title();
        $subtitle = isset($saved['subtitle']) ? sanitize_text_field((string) $saved['subtitle']) : '';
        $cover_id = isset($saved['cover_id']) ? absint($saved['cover_id']) : 0;
        $cover_url = '';
        if ($cover_id > 0) {
            $cover_url = (string) (wp_get_attachment_image_url($cover_id, 'full') ?: wp_get_attachment_url($cover_id) ?: '');
        }
        if ($cover_url === '') {
            $cover_url = isset($saved['cover_url']) ? esc_url_raw((string) $saved['cover_url']) : '';
        }

        return [
            'title' => $title !== '' ? $title : $this->get_default_header_title(),
            'subtitle' => $subtitle,
            'cover_url' => $cover_url,
            'cover_id' => $cover_id,
        ];
    }

    private function get_default_header_title(): string
    {
        $name = get_bloginfo('name');
        if (!is_string($name) || trim($name) === '') {
            return $this->t('content_workspace');
        }
        return $name;
    }

    private function get_shortcuts(): array
    {
        $links = [];

        if (current_user_can('edit_pages')) {
            $links[] = [
                'label' => $this->t('pages'),
                'url' => admin_url('edit.php?post_type=page'),
                'icon' => 'dashicons-admin-page',
                'menu_slug' => 'edit.php?post_type=page',
            ];
        }

        $custom_types = get_post_types(['show_ui' => true], 'objects');
        if (is_array($custom_types)) {
            foreach ($custom_types as $post_type => $obj) {
                $slug = sanitize_key((string) $post_type);
                if ($this->is_excluded_post_type($slug)) {
                    continue;
                }
                if (!current_user_can('edit_posts')) {
                    continue;
                }

                $label = '';
                if (isset($obj->labels) && is_object($obj->labels) && !empty($obj->labels->name)) {
                    $label = (string) $obj->labels->name;
                }
                if ($label === '' && !empty($obj->label)) {
                    $label = (string) $obj->label;
                }
                if ($label === '') {
                    $label = strtoupper($slug);
                }

                $icon = 'dashicons-admin-post';
                $menu_icon = isset($obj->menu_icon) ? (string) $obj->menu_icon : '';
                if (strpos($menu_icon, 'dashicons-') === 0) {
                    $icon = $menu_icon;
                }

                $links[] = [
                    'label' => $label,
                    'url' => admin_url('edit.php?post_type=' . rawurlencode($slug)),
                    'icon' => $icon,
                    'menu_slug' => 'edit.php?post_type=' . $slug,
                ];
            }
        }

        if (current_user_can('upload_files')) {
            $links[] = [
                'label' => $this->t('media_library'),
                'url' => admin_url('upload.php'),
                'icon' => 'dashicons-format-image',
                'menu_slug' => 'upload.php',
            ];
        }
        if (current_user_can('edit_posts')) {
            $links[] = [
                'label' => $this->t('site_options_shortcut'),
                'url' => admin_url('admin.php?page=cc-site-options'),
                'icon' => 'dashicons-admin-generic',
                'menu_slug' => 'cc-site-options',
            ];
        }

        return $this->sort_shortcuts_by_visibility_order($links);
    }

    private function sort_shortcuts_by_visibility_order(array $links): array
    {
        return $this->sort_items_by_visibility_order($links, 'menu_slug');
    }

    private function sort_items_by_visibility_order(array $items, string $slug_key): array
    {
        $order_option = get_option(\ContentCore\Modules\Settings\SettingsModule::ORDER_KEY, []);
        if (!is_array($order_option)) {
            return $items;
        }

        $role_key = current_user_can('manage_options') ? 'admin' : 'client';
        $ordered_slugs = $order_option[$role_key] ?? [];
        if (!is_array($ordered_slugs) || empty($ordered_slugs)) {
            return $items;
        }

        $order_map = [];
        foreach ($ordered_slugs as $idx => $slug) {
            $clean = sanitize_text_field((string) $slug);
            $normalized = $this->normalize_menu_slug($clean);
            if ($clean !== '' && !isset($order_map[$clean])) {
                $order_map[$clean] = (int) $idx;
            }
            if ($normalized !== '' && !isset($order_map[$normalized])) {
                $order_map[$normalized] = (int) $idx;
            }
        }

        foreach ($items as $idx => &$item) {
            $slug = (string) ($item[$slug_key] ?? '');
            $normalized = $this->normalize_menu_slug($slug);
            $item['_original_index'] = (int) $idx;
            $item['_sort_position'] = $order_map[$normalized] ?? $order_map[$slug] ?? 999999;
        }
        unset($item);

        usort($items, function (array $a, array $b): int {
            $a_pos = (int) ($a['_sort_position'] ?? 999999);
            $b_pos = (int) ($b['_sort_position'] ?? 999999);
            if ($a_pos === $b_pos) {
                return ((int) ($a['_original_index'] ?? 0)) <=> ((int) ($b['_original_index'] ?? 0));
            }
            return $a_pos <=> $b_pos;
        });

        foreach ($items as &$item) {
            unset($item['_original_index'], $item['_sort_position']);
        }
        unset($item);

        return $items;
    }

    private function normalize_menu_slug(string $slug): string
    {
        $slug = trim(html_entity_decode(rawurldecode($slug)));
        if ($slug === '') {
            return '';
        }

        if (strpos($slug, 'admin.php?page=') === 0) {
            $page = (string) substr($slug, strlen('admin.php?page='));
            return trim($page);
        }

        return $slug;
    }

    private function get_translation_overview_url(string $post_type, string $language_code): string
    {
        $type = sanitize_key($post_type);
        if ($type === '' || !post_type_exists($type)) {
            return '';
        }

        $url = admin_url('edit.php');
        if ($type !== 'post') {
            $url = add_query_arg('post_type', $type, $url);
        }

        $lang = sanitize_key(strtolower($language_code));
        if ($lang !== '') {
            $url = add_query_arg('lang', $lang, $url);
        }

        return (string) $url;
    }

    private function is_excluded_post_type(string $slug): bool
    {
        if ($slug === '') {
            return true;
        }
        if (in_array($slug, ['post', 'page', 'attachment', 'nav_menu_item', 'wp_navigation', 'wp_template', 'wp_template_part'], true)) {
            return true;
        }
        if (strpos($slug, 'cc_') === 0 || strpos($slug, 'wp_') === 0) {
            return true;
        }
        return false;
    }

    private function get_inline_js(): string
    {
        return "
        (function($){
            var ccCoverFrame = null;

            $(document).on('click', '.js-cc-cover-select', function(e){
                e.preventDefault();

                if (typeof wp === 'undefined' || !wp.media) {
                    return;
                }

                if (ccCoverFrame) {
                    ccCoverFrame.open();
                    return;
                }

                ccCoverFrame = wp.media({
                    title: '" . esc_js($this->t('choose_from_library')) . "',
                    button: { text: '" . esc_js($this->t('use_image')) . "' },
                    library: { type: 'image' },
                    multiple: false
                });

                ccCoverFrame.on('select', function(){
                    var selection = ccCoverFrame.state().get('selection').first();
                    if (!selection) {
                        return;
                    }

                    var attachment = selection.toJSON();
                    var wrap = $('.cc-wp-profile-edit').first();
                    wrap.find('.js-cc-cover-url').val(attachment.url || '');
                    wrap.find('.js-cc-cover-id').val(attachment.id || 0);

                    // Apply preview immediately so the change is visible before refresh.
                    if (attachment.url) {
                        var cover = $('.cc-wp-profile-cover').first();
                        cover.css('background-image', 'url(' + attachment.url + ')');
                        cover.removeClass('cc-wp-profile-cover-fallback');
                    }

                    // Persist immediately after selection for reliable behavior on all installs.
                    var form = wrap.find('form').first();
                    if (form.length && form.get(0) && typeof form.get(0).requestSubmit === 'function') {
                        form.get(0).requestSubmit();
                    } else if (form.length) {
                        form.trigger('submit');
                    }
                });

                ccCoverFrame.open();
            });
        })(jQuery);
        ";
    }

    private function get_inline_css(): string
    {
        return '
        #wpbody-content #dashboard-widgets .postbox-container{width:100%!important;float:none!important;}
        #wpbody-content #dashboard-widgets.columns-2 .postbox-container,
        #wpbody-content #dashboard-widgets.columns-3 .postbox-container,
        #wpbody-content #dashboard-widgets.columns-4 .postbox-container{width:100%!important;}
        body.index-php #dashboard-widgets .meta-box-sortables{min-height:0!important;}
        body.index-php #normal-sortables,
        body.index-php #dashboard-widgets .postbox-container{min-height:0!important;}
        #dashboard-widgets-wrap .postbox#cc_client_dashboard_widget{border:0;box-shadow:none;background:transparent;}
        #cc_client_dashboard_widget .postbox-header{display:none;}
        #cc_client_dashboard_widget .inside{margin:0;padding:0;}
        body.index-php #wpwrap{min-height:0!important;height:auto!important;}
        body.index-php #wpbody{min-height:0!important;height:auto!important;}
        body.index-php #wpcontent{padding-bottom:0!important;min-height:0!important;height:auto!important;}
        body.index-php #wpbody-content{padding-bottom:0!important;min-height:0!important;height:auto!important;margin-bottom:0!important;}
        body.index-php #dashboard-widgets-wrap,
        body.index-php #dashboard-widgets{margin-bottom:0!important;padding-bottom:0!important;}
        body.index-php #wpfooter{display:none!important;}
        body.index-php #wpbody-content > .wrap > h1,
        body.index-php #wpbody-content > .wrap > h1.wp-heading-inline,
        body.index-php #screen-meta-links{display:none!important;}
        .cc-wp-dashboard{display:flex;flex-direction:column;gap:16px;padding:2px 0 10px 0;}
        .cc-wp-notice{padding:10px 12px;border:1px solid #7ec989;background:rgba(0,150,40,.07);color:#0a7a2d;border-radius:8px;font-weight:600;}
        .cc-wp-dashboard-hero{padding:0;background:transparent;border:0;box-shadow:none;}
        .cc-wp-profile-header{position:relative;background:var(--cc-bg-card);border:1px solid var(--cc-border);border-radius:var(--cc-radius);overflow:hidden;box-shadow:var(--cc-shadow);}
        .cc-wp-profile-cover{height:220px;background-size:cover;background-position:center center;background-repeat:no-repeat;}
        .cc-wp-profile-cover-fallback{background:linear-gradient(135deg, rgba(0,0,0,.03), rgba(0,0,0,.08));}
        .cc-wp-profile-row{position:absolute;left:18px;right:18px;bottom:10px;display:flex;align-items:flex-end;justify-content:flex-start;gap:14px;padding:0;margin:0;pointer-events:none;}
        .cc-wp-profile-brand{display:flex;align-items:flex-end;gap:10px;}
        .cc-wp-dashboard-logo{width:92px;height:92px;object-fit:contain;border-radius:50%;padding:10px;background:#fff;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,.12);pointer-events:auto;}
        .cc-wp-profile-text{display:flex;flex-direction:column;gap:2px;padding-bottom:6px;}
        .cc-wp-profile-text strong{font-size:20px;line-height:1.2;}
        .cc-wp-profile-text span{font-size:13px;color:var(--cc-text-muted);}
        .cc-wp-profile-edit summary{cursor:pointer;list-style:none;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-accent-color);}
        .cc-wp-profile-edit summary::-webkit-details-marker{display:none;}
        .cc-wp-profile-edit-icon{position:absolute;top:12px;right:12px;z-index:5;}
        .cc-wp-profile-edit-icon summary{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:999px;background:rgba(255,255,255,.9);border:1px solid var(--cc-border);box-shadow:0 1px 4px rgba(0,0,0,.08);color:var(--cc-text);}
        .cc-wp-profile-edit-icon summary .dashicons{font-size:16px;width:16px;height:16px;}
        .cc-wp-profile-edit-icon .cc-wp-edit-icon-close{display:none;}
        .cc-wp-profile-edit-icon[open] .cc-wp-edit-icon-open{display:none;}
        .cc-wp-profile-edit-icon[open] .cc-wp-edit-icon-close{display:block;}
        .cc-wp-profile-edit-icon[open]{background:var(--cc-bg-card);border:1px solid var(--cc-border-light);border-radius:10px;padding:8px;max-width:calc(100vw - 48px);width:max-content;}
        .cc-wp-profile-edit form{display:flex;flex-direction:column;align-items:flex-start;gap:8px;margin-top:6px;}
        .cc-wp-profile-edit label{display:grid;gap:4px;font-size:12px;font-weight:600;color:var(--cc-text-muted);}
        .cc-wp-profile-edit input{width:100%;}
        .cc-wp-cover-actions{display:flex;gap:8px;align-items:center;flex-wrap:nowrap;width:max-content;}
        .cc-wp-profile-edit form .button{width:auto;min-width:0;align-self:flex-start;}
        .cc-wp-dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;align-items:start;}
        .cc-wp-card{background:var(--cc-bg-card);border:1px solid var(--cc-border);border-radius:var(--cc-radius);box-shadow:var(--cc-shadow);padding:18px;}
        .cc-wp-card h3{margin:0 0 14px 0;font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
        .cc-wp-card-wide{grid-column:1 / -1;}
        .cc-wp-card-status{grid-column:span 1;align-self:start;}
        .cc-wp-card-translations{grid-column:span 3;}
        .cc-wp-empty{margin:0;padding:14px;background:var(--cc-bg-soft);border-radius:8px;color:var(--cc-text-muted);}
        .cc-wp-kpis{display:grid;grid-template-columns:1fr;gap:10px;}
        .cc-wp-kpi-row{display:flex;align-items:stretch;gap:8px;}
        .cc-wp-kpi-main{flex:1;min-width:0;background:var(--cc-bg-soft);border:1px solid var(--cc-border-light);border-radius:8px;padding:12px;display:flex;justify-content:space-between;align-items:baseline;gap:10px;}
        .cc-wp-kpi-main span{font-size:12px;color:var(--cc-text-muted);}
        .cc-wp-kpi-main strong{font-size:24px;line-height:1;font-weight:800;}
        .cc-wp-kpi-add{width:48px;min-width:48px;border-radius:8px;border:1px solid var(--cc-accent-color);background:var(--cc-accent-color);color:#fff;text-decoration:none;font-weight:800;font-size:24px;line-height:1;display:flex;align-items:center;justify-content:center;transition:filter .12s ease, transform .12s ease, box-shadow .12s ease;}
        .cc-wp-kpi-add:hover,
        .cc-wp-kpi-add:focus-visible{color:#fff;filter:brightness(1.04);transform:translateY(-1px);box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;outline:none;}
        .cc-wp-kpi-add:active{transform:translateY(0);filter:brightness(0.98);}
        .cc-wp-shortcuts{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;width:100%;}
        .cc-wp-shortcut{display:flex;align-items:center;justify-content:center;gap:8px;min-height:52px;padding:10px 14px;background:var(--cc-bg-soft);border:1px solid var(--cc-border-light);border-radius:10px;color:var(--cc-text);text-decoration:none;font-weight:700;width:100%;box-sizing:border-box;cursor:pointer;-webkit-tap-highlight-color:rgba(34,113,177,.18);transition:border-color .12s ease, background-color .12s ease, color .12s ease, box-shadow .12s ease, transform .12s ease;}
        .cc-wp-shortcut .dashicons{font-size:16px;width:16px;height:16px;color:inherit;}
        .cc-wp-shortcut:hover,
        .cc-wp-shortcut:focus-visible{border-color:var(--cc-accent-color);background:#fff;color:var(--cc-accent-color);box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;transform:translateY(-1px);}
        .cc-wp-shortcut:active{background:#fff;color:var(--cc-accent-color);border-color:var(--cc-accent-color);box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;transform:translateY(0);}
        .cc-wp-shortcut:focus-visible{outline:2px solid var(--cc-accent-color);outline-offset:2px;}
        .cc-wp-translation-list{display:flex;flex-direction:column;gap:10px;}
        .cc-wp-translation-item{background:var(--cc-bg-soft);border:1px solid var(--cc-border-light);border-radius:10px;overflow:hidden;}
        .cc-wp-translation-item summary{list-style:none;cursor:pointer;}
        .cc-wp-translation-item summary::-webkit-details-marker{display:none;}
        .cc-wp-translation-summary{display:grid;grid-template-columns:minmax(200px,1fr) minmax(220px,1fr) minmax(160px,auto) 24px;gap:10px;align-items:center;padding:12px 14px;}
        .cc-wp-translation-item[open] .cc-wp-translation-summary{border-bottom:1px solid var(--cc-border-light);}
        .cc-wp-translation-toggle{transition:transform .18s ease;color:var(--cc-text-muted);}
        .cc-wp-translation-item[open] .cc-wp-translation-toggle{transform:rotate(180deg);}
        .cc-wp-translation-progress{display:flex;justify-content:flex-end;}
        .cc-wp-translation-progress small{font-size:12px;color:var(--cc-text-muted);}
        .cc-wp-translation-details{display:grid;grid-template-columns:1fr;gap:10px;padding:12px 14px 14px;}
        .cc-wp-translation-detail{background:var(--cc-bg-card);border:1px solid var(--cc-border-light);border-radius:8px;padding:8px 12px;display:grid;grid-template-columns:minmax(200px,1fr) minmax(220px,1fr) minmax(160px,auto);gap:10px;align-items:center;}
        .cc-wp-translation-detail-link{text-decoration:none;color:var(--cc-text);cursor:pointer;-webkit-tap-highlight-color:rgba(34,113,177,.18);transition:border-color .12s ease, background-color .12s ease, box-shadow .12s ease, transform .12s ease;}
        .cc-wp-translation-detail-link:hover,
        .cc-wp-translation-detail-link:focus-visible{border-color:var(--cc-accent-color);background:#fff;box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;transform:translateY(-1px);}
        .cc-wp-translation-detail-link:active{border-color:var(--cc-accent-color);background:#fff;box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;transform:translateY(0);}
        .cc-wp-translation-detail-link:hover > span{text-decoration:none;color:var(--cc-accent-color);}
        .cc-wp-translation-detail-link:focus-visible{outline:2px solid var(--cc-accent-color);outline-offset:2px;}
        .cc-wp-translation-detail > span{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--cc-text-muted);font-weight:700;grid-column:1;}
        .cc-wp-translation-lang{display:flex;align-items:center;gap:8px;}
        .cc-wp-lang-flag{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;background:#f5f7f8;border:1px solid var(--cc-border-light);}
        .cc-wp-lang-flag img,.cc-wp-lang-flag svg{width:18px;height:12px;display:block;object-fit:contain;}
        .cc-wp-translation-cell{display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .cc-wp-translation-summary .cc-wp-translation-cell{justify-content:center;}
        .cc-wp-translation-cell small{font-size:12px;color:var(--cc-text-muted);}
        .cc-wp-translation-detail .cc-wp-translation-cell{display:contents;}
        .cc-wp-translation-detail .cc-wp-translation-cell .cc-status-pill{grid-column:2;justify-self:center;}
        .cc-wp-translation-detail .cc-wp-translation-cell small{grid-column:3;justify-self:end;text-align:right;white-space:nowrap;}
        .cc-wp-dashboard-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;background:var(--cc-bg-soft);border:1px solid var(--cc-border);border-radius:var(--cc-radius);}
        .cc-wp-dashboard-footer-logo{max-width:130px;max-height:30px;width:auto;height:auto;object-fit:contain;}
        .cc-wp-dashboard-footer div{font-size:12px;color:var(--cc-text-muted);}
        @media (hover:none){
            .cc-wp-shortcut:active,
            .cc-wp-translation-detail-link:active{border-color:var(--cc-accent-color);background:#fff;color:var(--cc-accent-color);box-shadow:0 0 0 2px rgba(34,113,177,.18) inset;}
        }
        @media (max-width: 1400px){.cc-wp-shortcuts{grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}.cc-wp-dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr));}.cc-wp-card-status,.cc-wp-card-translations{grid-column:span 2;}}
        @media (max-width: 1100px){.cc-wp-shortcuts{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;}.cc-wp-dashboard-grid{grid-template-columns:1fr;}.cc-wp-card-wide,.cc-wp-card-status,.cc-wp-card-translations{grid-column:auto;}.cc-wp-translation-summary{grid-template-columns:1fr;}.cc-wp-translation-progress{justify-content:flex-start;}.cc-wp-translation-detail{grid-template-columns:1fr;}.cc-wp-translation-detail .cc-wp-translation-cell{display:flex;}.cc-wp-translation-detail .cc-wp-translation-cell .cc-status-pill{justify-self:auto;}.cc-wp-translation-detail .cc-wp-translation-cell small{justify-self:auto;text-align:left;}}
        @media (max-width: 782px){.cc-wp-profile-cover{height:170px;}.cc-wp-profile-row{left:12px;right:12px;bottom:8px;align-items:flex-start;flex-direction:column;}.cc-wp-dashboard-logo{width:78px;height:78px;}.cc-wp-profile-brand{align-items:center;}.cc-wp-dashboard-footer{flex-direction:column;align-items:flex-start;}}
        ';
    }

    private function t(string $key): string
    {
        $lang = $this->get_default_language();
        $map = [
            'en' => [
                'content_workspace' => 'Content Workspace',
                'brand_logo_alt' => 'Brand Logo',
                'today_todo' => 'Today To Do',
                'no_open_items' => 'No open items.',
                'content_status' => 'Content Status',
                'pages' => 'Pages',
                'posts' => 'Posts',
                'custom_types' => 'Custom Types',
                'drafts' => 'Drafts',
                'translation_status' => 'Translation Status',
                'language' => 'Language',
                'overall' => 'Overall',
                'post_types' => 'Post Types',
                'shortcuts' => 'Shortcuts',
                'edit_header' => 'Edit Header',
                'header_title' => 'Title',
                'header_subtitle' => 'Subtitle',
                'header_cover_url' => 'Cover Image URL',
                'header_cover_library' => 'Media Library',
                'choose_from_library' => 'Choose from library',
                'remove_cover' => 'Remove cover',
                'use_image' => 'Use image',
                'save' => 'Save',
                'saved_successfully' => 'Saved successfully.',
                'save_failed' => 'Save failed.',
                'media_library' => 'Media Library',
                'multilingual_shortcut' => 'Languages',
                'site_options_shortcut' => 'Site Profile',
                'multilingual_inactive' => 'Multilingual is not active.',
                'default' => 'Default',
                'warning' => 'Warning',
                'success' => 'Success',
                'complete' => 'Complete',
                'partial' => 'Partial',
                'missing' => 'Missing',
                'no_shortcuts' => 'No shortcuts available.',
                'no_custom_types' => 'No custom post types found.',
                'create_new' => 'Create new',
                'powered_by' => 'Powered by Content Core',
                'drafts_waiting' => '%d drafts waiting for publication',
                'languages_incomplete' => '%d languages have incomplete translations',
                'no_urgent_tasks' => 'No urgent tasks today',
                'seo' => 'SEO',
                'site_images' => 'Site Images',
                'cookie_banner' => 'Cookie Banner',
                'site_options' => 'Site Profile',
            ],
            'de' => [
                'content_workspace' => 'Content Workspace',
                'brand_logo_alt' => 'Markenlogo',
                'today_todo' => 'Heute zu tun',
                'no_open_items' => 'Keine offenen Punkte.',
                'content_status' => 'Content-Status',
                'pages' => 'Seiten',
                'posts' => 'Beiträge',
                'custom_types' => 'Custom Types',
                'drafts' => 'Entwürfe',
                'translation_status' => 'Übersetzungsstatus',
                'language' => 'Sprache',
                'overall' => 'Gesamt',
                'post_types' => 'Inhaltstypen',
                'shortcuts' => 'Schnellzugriff',
                'edit_header' => 'Header bearbeiten',
                'header_title' => 'Titel',
                'header_subtitle' => 'Untertitel',
                'header_cover_url' => 'Cover-Bild-URL',
                'header_cover_library' => 'Mediathek',
                'choose_from_library' => 'Aus Mediathek wählen',
                'remove_cover' => 'Cover entfernen',
                'use_image' => 'Bild verwenden',
                'save' => 'Speichern',
                'saved_successfully' => 'Erfolgreich gespeichert.',
                'save_failed' => 'Speichern fehlgeschlagen.',
                'media_library' => 'Mediathek',
                'multilingual_shortcut' => 'Sprachen',
                'site_options_shortcut' => 'Site Profile',
                'multilingual_inactive' => 'Mehrsprachigkeit ist nicht aktiv.',
                'default' => 'Standard',
                'warning' => 'Warnung',
                'success' => 'Erfolg',
                'complete' => 'Vollständig',
                'partial' => 'Teilweise',
                'missing' => 'Fehlt',
                'no_shortcuts' => 'Keine Schnellzugriffe verfügbar.',
                'no_custom_types' => 'Keine eigenen Post Types gefunden.',
                'create_new' => 'Neu erstellen',
                'powered_by' => 'Powered by Content Core',
                'drafts_waiting' => '%d Entwürfe warten auf Veröffentlichung',
                'languages_incomplete' => '%d Sprachen haben unvollständige Übersetzungen',
                'no_urgent_tasks' => 'Keine dringenden Aufgaben heute',
                'seo' => 'SEO',
                'site_images' => 'Site Images',
                'cookie_banner' => 'Cookie Banner',
                'site_options' => 'Site Profile',
            ],
            'fr' => [
                'content_workspace' => 'Espace de contenu',
                'brand_logo_alt' => 'Logo de marque',
                'today_todo' => 'À faire aujourd\'hui',
                'no_open_items' => 'Aucun élément en attente.',
                'content_status' => 'État du contenu',
                'pages' => 'Pages',
                'posts' => 'Articles',
                'custom_types' => 'Types personnalisés',
                'drafts' => 'Brouillons',
                'translation_status' => 'Statut des traductions',
                'language' => 'Langue',
                'overall' => 'Global',
                'post_types' => 'Types de contenu',
                'shortcuts' => 'Raccourcis',
                'edit_header' => 'Modifier en-tête',
                'header_title' => 'Titre',
                'header_subtitle' => 'Sous-titre',
                'header_cover_url' => 'URL image de couverture',
                'header_cover_library' => 'Médiathèque',
                'choose_from_library' => 'Choisir dans la médiathèque',
                'remove_cover' => 'Supprimer la couverture',
                'use_image' => 'Utiliser l\'image',
                'save' => 'Enregistrer',
                'saved_successfully' => 'Enregistré avec succès.',
                'save_failed' => 'Échec de l\'enregistrement.',
                'media_library' => 'Médiathèque',
                'multilingual_shortcut' => 'Langues',
                'site_options_shortcut' => 'Site Profile',
                'multilingual_inactive' => 'Le multilingue n\'est pas actif.',
                'default' => 'Par défaut',
                'warning' => 'Alerte',
                'success' => 'Succès',
                'complete' => 'Complet',
                'partial' => 'Partiel',
                'missing' => 'Manquant',
                'no_shortcuts' => 'Aucun raccourci disponible.',
                'no_custom_types' => 'Aucun type de contenu personnalisé trouvé.',
                'create_new' => 'Créer',
                'powered_by' => 'Powered by Content Core',
                'drafts_waiting' => '%d brouillons en attente de publication',
                'languages_incomplete' => '%d langues ont des traductions incomplètes',
                'no_urgent_tasks' => 'Aucune tâche urgente aujourd\'hui',
                'seo' => 'SEO',
                'site_images' => 'Images du site',
                'cookie_banner' => 'Bannière cookies',
                'site_options' => 'Site Profile',
            ],
            'it' => [
                'content_workspace' => 'Workspace contenuti',
                'brand_logo_alt' => 'Logo del brand',
                'today_todo' => 'Da fare oggi',
                'no_open_items' => 'Nessun elemento aperto.',
                'content_status' => 'Stato contenuti',
                'pages' => 'Pagine',
                'posts' => 'Post',
                'custom_types' => 'Tipi personalizzati',
                'drafts' => 'Bozze',
                'translation_status' => 'Stato traduzioni',
                'language' => 'Lingua',
                'overall' => 'Totale',
                'post_types' => 'Tipi di contenuto',
                'shortcuts' => 'Scorciatoie',
                'edit_header' => 'Modifica intestazione',
                'header_title' => 'Titolo',
                'header_subtitle' => 'Sottotitolo',
                'header_cover_url' => 'URL immagine copertina',
                'header_cover_library' => 'Libreria media',
                'choose_from_library' => 'Scegli dalla libreria',
                'remove_cover' => 'Rimuovi copertina',
                'use_image' => 'Usa immagine',
                'save' => 'Salva',
                'saved_successfully' => 'Salvato correttamente.',
                'save_failed' => 'Salvataggio non riuscito.',
                'media_library' => 'Libreria media',
                'multilingual_shortcut' => 'Lingue',
                'site_options_shortcut' => 'Site Profile',
                'multilingual_inactive' => 'Il multilingua non è attivo.',
                'default' => 'Predefinita',
                'warning' => 'Avviso',
                'success' => 'Successo',
                'complete' => 'Completo',
                'partial' => 'Parziale',
                'missing' => 'Mancante',
                'no_shortcuts' => 'Nessuna scorciatoia disponibile.',
                'no_custom_types' => 'Nessun tipo di contenuto personalizzato trovato.',
                'create_new' => 'Crea nuovo',
                'powered_by' => 'Powered by Content Core',
                'drafts_waiting' => '%d bozze in attesa di pubblicazione',
                'languages_incomplete' => '%d lingue hanno traduzioni incomplete',
                'no_urgent_tasks' => 'Nessuna attività urgente oggi',
                'seo' => 'SEO',
                'site_images' => 'Immagini sito',
                'cookie_banner' => 'Banner cookie',
                'site_options' => 'Site Profile',
            ],
        ];

        $fallback_lang = isset($map[$lang]) ? $lang : 'en';
        return $map[$fallback_lang][$key] ?? $map['en'][$key] ?? $key;
    }

    private function get_default_language(): string
    {
        $settings = get_option('cc_languages_settings', []);
        $default = is_array($settings) ? (string) ($settings['default_lang'] ?? '') : '';
        $default = strtolower(sanitize_key($default));
        return $default !== '' ? $default : 'en';
    }
}

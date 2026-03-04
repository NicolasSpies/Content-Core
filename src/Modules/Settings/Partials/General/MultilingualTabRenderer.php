<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Multilingual\MultilingualModule;

/**
 * Renders the Multilingual Tab in General Settings.
 */
class MultilingualTabRenderer
{
    /**
     * Render the tab content.
     */
    public static function render(\ContentCore\Modules\Settings\SettingsModule $module): void
    {
        $ml_settings = $module->get_registry()->get('cc_languages_settings');
        $catalog = \ContentCore\Modules\Multilingual\MultilingualModule::get_language_catalog();

        $ml_instance = \ContentCore\Plugin::get_instance()->get_module('multilingual');
        if (!$ml_instance) {
            $ml_instance = new \ContentCore\Modules\Multilingual\MultilingualModule();
        }
        ?>
        <div id="cc-settings-multilingual">

            <!-- ═══ Active Languages ═══ -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-translation"></span>
                        <?php _e('Active Languages', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Configure languages for your site. One post is created per language.', 'content-core'); ?>
                    </p>

                    <div class="cc-table-wrap">
                        <table class="cc-table cc-table-flush" id="cc-ml-languages-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th><?php _e('Flag', 'content-core'); ?></th>
                                    <th><?php _e('Code', 'content-core'); ?></th>
                                    <th><?php _e('Label', 'content-core'); ?></th>
                                    <th><?php _e('Custom Flag', 'content-core'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ml_settings['languages'] as $index => $lang): ?>
                                    <tr data-index="<?php echo $index; ?>" data-code="<?php echo esc_attr($lang['code']); ?>">
                                        <td>
                                            <span class="dashicons dashicons-menu cc-drag-handle cc-ml-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'content-core'); ?>"></span>
                                        </td>
                                        <td class="flag-col">
                                            <?php echo $ml_instance->get_flag_html($lang['code'], $lang['flag_id'] ?? 0); ?>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($lang['code']); ?></code>
                                            <input type="hidden" name="cc_languages[languages][<?php echo $index; ?>][code]"
                                                value="<?php echo esc_attr($lang['code']); ?>" class="language-code">
                                        </td>
                                        <td>
                                            <div><?php echo esc_html($lang['label']); ?>
                                            </div>
                                            <input type="hidden" name="cc_languages[languages][<?php echo $index; ?>][label]"
                                                value="<?php echo esc_attr($lang['label']); ?>" class="language-label">
                                        </td>
                                        <td>
                                            <div>
                                                <input type="hidden" name="cc_languages[languages][<?php echo $index; ?>][flag_id]"
                                                    value="<?php echo esc_attr($lang['flag_id'] ?? 0); ?>" class="flag-id-input">
                                                <button type="button"
                                                    class="cc-button-secondary button-small select-custom-flag"><?php _e('Select', 'content-core'); ?></button>
                                                <?php if (!empty($lang['flag_id'])): ?>
                                                    <button type="button"
                                                        class="cc-button-secondary button-small remove-custom-flag"><span
                                                            class="dashicons dashicons-no-alt"></span></button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button type="button" class="cc-button-secondary button-small remove-row"
                                               ><span
                                                    class="dashicons dashicons-trash"></span></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <select id="cc-ml-add-selector">
                            <option value=""><?php _e('Select a language...', 'content-core'); ?></option>
                            <?php foreach ($catalog as $code => $data): ?>
                                <option value="<?php echo esc_attr($code); ?>" data-label="<?php echo esc_attr($data['label']); ?>">
                                    <?php echo esc_html($data['label']); ?> (<?php echo esc_html($code); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="cc-button-secondary add-language-row">
                            <span class="dashicons dashicons-plus-alt2"
                               ></span>
                            <?php _e('Add Language', 'content-core'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ Configuration ═══ -->
            <div class="cc-card">
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php _e('Multilingual Behavior', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <div class="cc-grid">
                        <!-- Enable Multilingual -->
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Enable Multilingual', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[enabled]" value="0">
                                    <input type="checkbox" name="cc_languages[enabled]" value="1" <?php checked($ml_settings['enabled']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                            </div>
                        </div>

                        <!-- Default Language -->
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Default Language', 'content-core'); ?></label>
                            <select name="cc_languages[default_lang]" id="cc-default-lang-select">
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($ml_settings['default_lang'], $lang['code']); ?>>
                                        <?php echo esc_html($lang['label']); ?> (<?php echo esc_html($lang['code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="cc-help"><?php _e('The primary language for your content.', 'content-core'); ?></span>
                        </div>

                        <!-- Fallback Logic -->
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Content Fallback', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[fallback_enabled]" value="0">
                                    <input type="checkbox" name="cc_languages[fallback_enabled]" id="cc-ml-fallback-toggle"
                                        value="1" <?php checked($ml_settings['fallback_enabled']); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Serve fallback content if translation is missing.', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Fallback Language', 'content-core'); ?></label>
                            <select name="cc_languages[fallback_lang]" id="cc-fallback-lang-select" <?php disabled(!$ml_settings['fallback_enabled']); ?>>
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php selected($ml_settings['fallback_lang'], $lang['code']); ?>>
                                        <?php echo esc_html($lang['label']); ?> (<?php echo esc_html($lang['code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Permalinks & SEO -->
                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Localized Permalinks', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[permalink_enabled]" value="0">
                                    <input type="checkbox" name="cc_languages[permalink_enabled]" id="cc-ml-permalink-toggle"
                                        value="1" <?php checked(!empty($ml_settings['permalink_enabled'])); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Enable prefixes for non-default languages (e.g. /fr/).', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('REST SEO Signals', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[enable_rest_seo]" value="0">
                                    <input type="checkbox" name="cc_languages[enable_rest_seo]" value="1" <?php checked(!empty($ml_settings['enable_rest_seo'])); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Expose hreflang and canonicals in REST API.', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Headless Fallback', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[enable_headless_fallback]" value="0">
                                    <input type="checkbox" name="cc_languages[enable_headless_fallback]" value="1" <?php checked(!empty($ml_settings['enable_headless_fallback'])); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Return default language if requested translation is empty.', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field">
                            <label class="cc-field-label"><?php _e('Sitemap Endpoint', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[enable_sitemap_endpoint]" value="0">
                                    <input type="checkbox" name="cc_languages[enable_sitemap_endpoint]" value="1" <?php checked(!empty($ml_settings['enable_sitemap_endpoint'])); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Enable /wp-json/cc/v1/sitemap endpoint.', 'content-core'); ?></span>
                            </div>
                        </div>

                        <div class="cc-field cc-grid-full">
                            <label class="cc-field-label"><?php _e('Multilingual Taxonomies', 'content-core'); ?></label>
                            <div class="cc-toggle-wrap">
                                <label class="cc-toggle">
                                    <input type="hidden" name="cc_languages[enable_localized_taxonomies]" value="0">
                                    <input type="checkbox" name="cc_languages[enable_localized_taxonomies]"
                                        id="cc-ml-tax-toggle" value="1" <?php checked(!empty($ml_settings['enable_localized_taxonomies'])); ?>>
                                    <span class="cc-slider"></span>
                                </label>
                                <span
                                    class="cc-help"><?php _e('Enable language assignment for Categories and Custom Taxonomies.', 'content-core'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═══ Localized Permalinks ═══ -->
            <div id="cc-ml-permalink-config" class="cc-card"
               >
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php _e('Localized Post Type Bases', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Define the URL segment (base) for each post type per language.', 'content-core'); ?>
                    </p>

                    <div class="cc-table-wrap">
                        <table class="cc-table cc-table-flush">
                            <thead>
                                <tr>
                                    <th><?php _e('Post Type', 'content-core'); ?></th>
                                    <?php foreach ($ml_settings['languages'] as $lang): ?>
                                        <th><?php echo esc_html($lang['label']); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $public_pts = get_post_types(['public' => true], 'objects');
                                foreach ($public_pts as $pt):
                                    if (!$pt instanceof \WP_Post_Type || $pt->name === 'attachment')
                                        continue;
                                    $default_base = $pt->rewrite['slug'] ?? $pt->name;
                                    if ($pt->name === 'page' || $pt->name === 'post')
                                        $default_base = '-';
                                    ?>
                                    <tr>
                                        <td>
                                            <div><?php echo esc_html($pt->label); ?></div>
                                            <code><?php echo esc_html($pt->name); ?></code>
                                        </td>
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <td>
                                                <input type="text"
                                                    name="cc_languages[permalink_bases][<?php echo esc_attr($pt->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                    value="<?php echo esc_attr($ml_settings['permalink_bases'][$pt->name][$lang['code']] ?? ''); ?>"
                                                    placeholder="<?php echo esc_attr($default_base); ?>"
                                                   >
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═══ Localized Taxonomy Bases ═══ -->
            <div id="cc-ml-tax-config" class="cc-card"
               >
                <div class="cc-card-header">
                    <h2>
                        <span class="dashicons dashicons-category"></span>
                        <?php _e('Localized Taxonomy Bases', 'content-core'); ?>
                    </h2>
                </div>
                <div class="cc-card-body">
                    <p class="cc-page-description">
                        <?php _e('Define the URL segment (base) for each taxonomy per language.', 'content-core'); ?>
                    </p>

                    <div class="cc-table-wrap">
                        <table class="cc-table cc-table-flush">
                            <thead>
                                <tr>
                                    <th><?php _e('Taxonomy', 'content-core'); ?></th>
                                    <?php foreach ($ml_settings['languages'] as $lang): ?>
                                        <th><?php echo esc_html($lang['label']); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $public_taxes = get_taxonomies(['public' => true], 'objects');
                                foreach ($public_taxes as $tax):
                                    if (!$tax instanceof \WP_Taxonomy || $tax->name === 'post_tag' || $tax->name === 'post_format')
                                        continue;
                                    $default_base = $tax->rewrite['slug'] ?? $tax->name;
                                    ?>
                                    <tr>
                                        <td>
                                            <div><?php echo esc_html($tax->label); ?></div>
                                            <code><?php echo esc_html($tax->name); ?></code>
                                        </td>
                                        <?php foreach ($ml_settings['languages'] as $lang): ?>
                                            <td>
                                                <input type="text"
                                                    name="cc_languages[taxonomy_bases][<?php echo esc_attr($tax->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                    value="<?php echo esc_attr($ml_settings['taxonomy_bases'][$tax->name][$lang['code']] ?? ''); ?>"
                                                    placeholder="<?php echo esc_attr($default_base); ?>"
                                                   >
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Template Script -->
            <script type="text/template" id="cc-ml-row-template">
                        <tr data-index="{index}" data-code="{code}">
                            <td>
                                <span class="dashicons dashicons-menu cc-drag-handle cc-ml-drag-handle" title="<?php esc_attr_e('Drag to reorder', 'content-core'); ?>"></span>
                            </td>
                            <td class="flag-col">{flag}</td>
                            <td>
                                <code>{code}</code>
                                <input type="hidden" name="cc_languages[languages][{index}][code]" value="{code}" class="language-code">
                            </td>
                            <td>
                                <div>{label}</div>
                                <input type="hidden" name="cc_languages[languages][{index}][label]" value="{label}" class="language-label">
                            </td>
                            <td>
                                <div>
                                    <input type="hidden" name="cc_languages[languages][{index}][flag_id]" value="0" class="flag-id-input">
                                    <button type="button" class="cc-button-secondary button-small select-custom-flag"><?php _e('Select', 'content-core'); ?></button>
                                    <button type="button" class="cc-button-secondary button-small remove-custom-flag"><span class="dashicons dashicons-no-alt"></span></button>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="cc-button-secondary button-small remove-row"><span class="dashicons dashicons-trash"></span></button>
                            </td>
                        </tr>
                    </script>
        </div>
        <?php
    }
}

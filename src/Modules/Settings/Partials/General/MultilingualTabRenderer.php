<?php
namespace ContentCore\Modules\Settings\Partials\General;

use ContentCore\Modules\Multilingual\MultilingualModule;

/**
 * Renders the Multilingual Tab in General Settings.
 */
class MultilingualTabRenderer {
    /**
     * Render the tab content.
     */
    public static function render(\ContentCore\Modules\Settings\SettingsModule $module): void {
        $ml_settings = $module->get_registry()->get('cc_languages_settings');
        $catalog = \ContentCore\Modules\Multilingual\MultilingualModule::get_language_catalog();
        
        // Ensure $ml_instance is available for methods like get_flag_html
        $ml_instance = \ContentCore\Plugin::get_instance()->get_module('multilingual');
        if (!$ml_instance) {
             $ml_instance = new \ContentCore\Modules\Multilingual\MultilingualModule();
        }
        ?>
        <div id="cc-tab-multilingual" class="cc-tab-content">

            <!-- ═══ Languages / Multilingual ═══ -->
            <div class="cc-card" style="margin-bottom: 24px;">
                <h2 style="margin-top: 0;">
                    <?php _e('Multilingual Settings', 'content-core'); ?>
                </h2>
                <p style="color: #646970;">
                    <?php _e('Configure languages and translation behavior. One post is created per language.', 'content-core'); ?>
                </p>

                <table class="form-table" style="margin-top: 16px;">
                    <tr>
                        <th scope="row">
                            <?php _e('Enable Multilingual', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[enabled]" value="0">
                                <input type="checkbox" name="cc_languages[enabled]" value="1" <?php
                                checked($ml_settings['enabled']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Active Languages', 'content-core'); ?>
                        </th>
                        <td>
                            <table class="widefat fixed striped" id="cc-ml-languages-table"
                                style="margin-bottom: 12px;">
                                <thead>
                                    <tr>
                                        <th style="width: 50px;">
                                            <?php _e('Flag', 'content-core'); ?>
                                        </th>
                                        <th style="width: 80px;">
                                            <?php _e('Code', 'content-core'); ?>
                                        </th>
                                        <th>
                                            <?php _e('Label', 'content-core'); ?>
                                        </th>
                                        <th style="width: 150px;">
                                            <?php _e('Custom Flag', 'content-core'); ?>
                                        </th>
                                        <th style="width: 50px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ml_settings['languages'] as $index => $lang): ?>
                                        <tr data-index="<?php echo $index; ?>"
                                            data-code="<?php echo esc_attr($lang['code']); ?>">
                                            <td class="flag-col" style="vertical-align: middle;">
                                                <?php echo $ml_instance->get_flag_html($lang['code'], $lang['flag_id'] ?? 0); ?>
                                            </td>
                                            <td>
                                                <code
                                                    class="language-code-display"><?php echo esc_html($lang['code']); ?></code>
                                                <input type="hidden"
                                                    name="cc_languages[languages][<?php echo $index; ?>][code]"
                                                    value="<?php echo esc_attr($lang['code']); ?>" class="language-code">
                                            </td>
                                            <td>
                                                <span style="font-weight: 500; font-size: 13px;">
                                                    <?php echo esc_html($lang['label']); ?>
                                                </span>
                                                <input type="hidden"
                                                    name="cc_languages[languages][<?php echo $index; ?>][label]"
                                                    value="<?php echo esc_attr($lang['label']); ?>" class="language-label">
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 4px; align-items: center;">
                                                    <input type="hidden"
                                                        name="cc_languages[languages][<?php echo $index; ?>][flag_id]"
                                                        value="<?php echo esc_attr($lang['flag_id']); ?>"
                                                        class="flag-id-input">
                                                    <button type="button" class="button button-small select-custom-flag">
                                                        <?php _e('Select', 'content-core'); ?>
                                                    </button>
                                                    <button type="button" class="button button-small remove-custom-flag"
                                                        style="<?php echo empty($lang['flag_id']) ? 'display:none;' : ''; ?>"><span
                                                            class="dashicons dashicons-no-alt"
                                                            style="margin-top: 2px;"></span></button>
                                                </div>
                                            </td>
                                            <td style="text-align: right;">
                                                <button type="button" class="button button-link-delete remove-row"><span
                                                        class="dashicons dashicons-no-alt"
                                                        style="margin-top: 4px;"></span></button>
                                            </td>
                                        </tr>
                                        <?php
                                    endforeach; ?>
                                </tbody>
                            </table>

                            <div style="display: flex; gap: 8px; align-items: center;">
                                <select id="cc-ml-add-selector" class="regular-text" style="width: auto;">
                                    <option value="">
                                        <?php _e('Select a language...', 'content-core'); ?>
                                    </option>
                                    <?php foreach ($catalog as $code => $data): ?>
                                        <option value="<?php echo esc_attr($code); ?>"
                                            data-label="<?php echo esc_attr($data['label']); ?>">
                                            <?php echo esc_html($data['label']); ?> (
                                            <?php echo esc_html($code); ?>)
                                        </option>
                                        <?php
                                    endforeach; ?>
                                </select>
                                <button type="button" class="button add-language-row">
                                    <?php _e('Add Language', 'content-core'); ?>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Default Language', 'content-core'); ?>
                        </th>
                        <td>
                            <select name="cc_languages[default_lang]" id="cc-default-lang-select" class="regular-text">
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php
                                       selected($ml_settings['default_lang'], $lang['code']); ?>>
                                        <?php echo esc_html($lang['label']); ?> (
                                        <?php echo esc_html($lang['code']); ?>)
                                    </option>
                                    <?php
                                endforeach; ?>
                            </select>
                            <p class="description">
                                <?php _e('The primary language for your content.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Content Fallback', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[fallback_enabled]" value="0">
                                <input type="checkbox" name="cc_languages[fallback_enabled]" id="cc-ml-fallback-toggle"
                                    value="1" <?php checked($ml_settings['fallback_enabled']); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('If a translation is missing, return the fallback language in REST API.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Fallback Language', 'content-core'); ?>
                        </th>
                        <td>
                            <select name="cc_languages[fallback_lang]" id="cc-fallback-lang-select" class="regular-text"
                                <?php disabled(!$ml_settings['fallback_enabled']); ?>>
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <option value="<?php echo esc_attr($lang['code']); ?>" <?php
                                       selected($ml_settings['fallback_lang'], $lang['code']); ?>>
                                        <?php echo esc_html($lang['label']); ?> (
                                        <?php echo esc_html($lang['code']); ?>)
                                    </option>
                                    <?php
                                endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Localized Permalinks', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[permalink_enabled]" value="0">
                                <input type="checkbox" name="cc_languages[permalink_enabled]"
                                    id="cc-ml-permalink-toggle" value="1" <?php
                                    checked(!empty($ml_settings['permalink_enabled'])); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Enable prefixes for non-default languages and translated post type bases (e.g. /fr/references/slug).', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('REST SEO Signals', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[enable_rest_seo]" value="0">
                                <input type="checkbox" name="cc_languages[enable_rest_seo]" value="1" <?php
                                checked(!empty($ml_settings['enable_rest_seo'])); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Expose canonical, alternates (hreflang), and x-default in REST API responses.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Headless Fallback', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[enable_headless_fallback]" value="0">
                                <input type="checkbox" name="cc_languages[enable_headless_fallback]" value="1" <?php
                                checked(!empty($ml_settings['enable_headless_fallback'])); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Return default language content if requested translation is missing in REST.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Multilingual Taxonomies', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[enable_localized_taxonomies]" value="0">
                                <input type="checkbox" name="cc_languages[enable_localized_taxonomies]"
                                    id="cc-ml-tax-toggle" value="1" <?php
                                    checked(!empty($ml_settings['enable_localized_taxonomies'])); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Enable language assignment and localized permalinks for Categories and Custom Taxonomies.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <?php _e('Sitemap Endpoint', 'content-core'); ?>
                        </th>
                        <td>
                            <label class="cc-toggle">
                                <input type="hidden" name="cc_languages[enable_sitemap_endpoint]" value="0">
                                <input type="checkbox" name="cc_languages[enable_sitemap_endpoint]" value="1" <?php
                                checked(!empty($ml_settings['enable_sitemap_endpoint'])); ?>>
                                <span class="cc-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <?php _e('Expose a sitemap-ready dataset at /wp-json/cc/v1/sitemap.', 'content-core'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <div id="cc-ml-permalink-config"
                    style="<?php echo empty($ml_settings['permalink_enabled']) ? 'display:none;' : ''; ?>; margin-top: 20px;">
                    <h3 style="margin-bottom: 12px;">
                        <?php _e('Localized Bases', 'content-core'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 16px;">
                        <?php _e('Define the URL segment (base) for each post type per language. Leave empty to use the default post type slug.', 'content-core'); ?>
                    </p>

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>
                                    <?php _e('Post Type', 'content-core'); ?>
                                </th>
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <th style="width: 150px;">
                                        <?php echo esc_html($lang['label']); ?> (
                                        <?php echo esc_html(strtoupper($lang['code'])); ?>)
                                    </th>
                                    <?php
                                endforeach; ?>
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
                                    $default_base = '';
                                ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo esc_html($pt->label); ?>
                                        </strong><br>
                                        <code style="font-size: 11px;"><?php echo esc_html($pt->name); ?></code>
                                    </td>
                                    <?php foreach ($ml_settings['languages'] as $lang): ?>
                                        <td>
                                            <input type="text"
                                                name="cc_languages[permalink_bases][<?php echo esc_attr($pt->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                value="<?php echo esc_attr($ml_settings['permalink_bases'][$pt->name][$lang['code']] ?? ''); ?>"
                                                placeholder="<?php echo esc_attr($default_base); ?>" class="regular-text"
                                                style="width: 100%;">
                                        </td>
                                        <?php
                                    endforeach; ?>
                                </tr>
                                <?php
                            endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div id="cc-ml-tax-config"
                    style="<?php echo empty($ml_settings['enable_localized_taxonomies']) ? 'display:none;' : ''; ?>; margin-top: 30px;">
                    <h3 style="margin-bottom: 12px;">
                        <?php _e('Localized Taxonomy Bases', 'content-core'); ?>
                    </h3>
                    <p class="description" style="margin-bottom: 16px;">
                        <?php _e('Define the URL segment (base) for each taxonomy per language.', 'content-core'); ?>
                    </p>

                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th>
                                    <?php _e('Taxonomy', 'content-core'); ?>
                                </th>
                                <?php foreach ($ml_settings['languages'] as $lang): ?>
                                    <th style="width: 150px;">
                                        <?php echo esc_html($lang['label']); ?> (
                                        <?php echo esc_html(strtoupper($lang['code'])); ?>)
                                    </th>
                                    <?php
                                endforeach; ?>
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
                                        <strong>
                                            <?php echo esc_html($tax->label); ?>
                                        </strong><br>
                                        <code style="font-size: 11px;"><?php echo esc_html($tax->name); ?></code>
                                    </td>
                                    <?php foreach ($ml_settings['languages'] as $lang): ?>
                                        <td>
                                            <input type="text"
                                                name="cc_languages[taxonomy_bases][<?php echo esc_attr($tax->name); ?>][<?php echo esc_attr($lang['code']); ?>]"
                                                value="<?php echo esc_attr($ml_settings['taxonomy_bases'][$tax->name][$lang['code']] ?? ''); ?>"
                                                placeholder="<?php echo esc_attr($default_base); ?>" class="regular-text"
                                                style="width: 100%;">
                                        </td>
                                        <?php
                                    endforeach; ?>
                                </tr>
                                <?php
                            endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <script type="text/template" id="cc-ml-row-template">
                    <tr data-index="{index}" data-code="{code}">
                        <td class="flag-col" style="vertical-align: middle;">{flag}</td>
                        <td>
                            <code class="language-code-display">{code}</code>
                            <input type="hidden" name="cc_languages[languages][{index}][code]" value="{code}" class="language-code">
                        </td>
                        <td>
                            <span style="font-weight: 500; font-size: 13px;">{label}</span>
                            <input type="hidden" name="cc_languages[languages][{index}][label]" value="{label}" class="language-label">
                        </td>
                        <td>
                            <div style="display: flex; gap: 4px; align-items: center;">
                                <input type="hidden" name="cc_languages[languages][{index}][flag_id]" value="0" class="flag-id-input">
                                <button type="button" class="button button-small select-custom-flag"><?php _e('Select', 'content-core'); ?></button>
                                <button type="button" class="button button-small remove-custom-flag" style="display:none;"><span class="dashicons dashicons-no-alt" style="margin-top: 2px;"></span></button>
                            </div>
                        </td>
                        <td style="text-align: right;">
                            <button type="button" class="button button-link-delete remove-row"><span class="dashicons dashicons-no-alt" style="margin-top: 4px;"></span></button>
                        </td>
                    </tr>
                </script>
            </div>
        </div>
        <?php
    }
}

jQuery(function ($) {
    if (
        $('.cc-settings-single-page').length === 0 &&
        $('.cc-settings-tabs').length === 0 &&
        $('.wrap.content-core-admin').length === 0
    ) {
        return;
    }

    var $table = $('#cc-ml-languages-table tbody');
    var template = $('#cc-ml-row-template').html();
    var catalog = (typeof CC_SETTINGS !== 'undefined') ? CC_SETTINGS.catalog : {};
    var query = new URLSearchParams(window.location.search);

    function showToast(message, type) {
        if (window.CCToast && typeof window.CCToast.show === 'function') {
            window.CCToast.show(message, type);
        }
    }

    // Keep existing PHP save flow and notices, add matching modern toast feedback on success.
    if (query.get('cc_action') === 'settings_saved') {
        showToast('Settings saved successfully.', 'success');
    }

    function updateSelects() {
        var $defaultSelect = $('#cc-default-lang-select');
        var $fallbackSelect = $('#cc-fallback-lang-select');
        var currentDefault = $defaultSelect.val();
        var currentFallback = $fallbackSelect.val();

        $defaultSelect.empty();
        $fallbackSelect.empty();

        $table.find('tr').each(function () {
            var code = $(this).find('.language-code').val();
            var label = $(this).find('.language-label').val() || code;
            if (code) {
                $defaultSelect.append($('<option>', { value: code, text: label + ' (' + code + ')' }));
                $fallbackSelect.append($('<option>', { value: code, text: label + ' (' + code + ')' }));
            }
        });

        $defaultSelect.val(currentDefault);
        $fallbackSelect.val(currentFallback);
    }

    function reindexLanguageRows() {
        $table.find('tr').each(function (idx) {
            $(this).attr('data-index', idx);
            $(this).find('[name]').each(function () {
                if (this.name) {
                    this.name = this.name.replace(/cc_languages\[languages\]\[\d+\]/, 'cc_languages[languages][' + idx + ']');
                }
            });
        });
    }

    $('.add-language-row').on('click', function () {
        var $selector = $('#cc-ml-add-selector');
        var code = $selector.val();
        if (!code) return;

        if ($table.find('tr[data-code="' + code + '"]').length) {
            alert(CC_SETTINGS.strings.langAdded);
            return;
        }

        var index = $table.find('tr').length;
        var langData = catalog[code];
        var row = template
            .replace(/{index}/g, index)
            .replace(/{code}/g, code)
            .replace(/{label}/g, langData.label)
            .replace(/{flag}/g, langData.flag);
        $table.append(row);
        $selector.val('');
        reindexLanguageRows();
        updateSelects();
    });

    $table.on('click', '.remove-row', function () {
        if (confirm(CC_SETTINGS.strings.confirmRemoveLang)) {
            $(this).closest('tr').remove();
            reindexLanguageRows();
            updateSelects();
        }
    });

    if ($.fn.sortable && $table.length) {
        $table.sortable({
            handle: '.cc-ml-drag-handle',
            items: 'tr',
            axis: 'y',
            helper: function (e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function (index) {
                    $(this).width($originals.eq(index).outerWidth());
                });
                return $helper;
            },
            placeholder: 'ui-sortable-placeholder',
            update: function () {
                reindexLanguageRows();
                updateSelects();
            }
        });
    }

    $('#cc-ml-fallback-toggle').on('change', function () {
        $('#cc-fallback-lang-select').prop('disabled', !$(this).is(':checked'));
    });

    $('#cc-ml-permalink-toggle').on('change', function () {
        $('#cc-ml-permalink-config').toggle($(this).is(':checked'));
    });

    $('#cc-ml-tax-toggle').on('change', function () {
        $('#cc-ml-tax-config').toggle($(this).is(':checked'));
    });

    var mediaFrame;
    $table.on('click', '.select-custom-flag', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var $input = $row.find('.flag-id-input');
        var $removeBtn = $row.find('.remove-custom-flag');
        mediaFrame = wp.media({
            title: CC_SETTINGS.strings.selectFlag,
            button: { text: CC_SETTINGS.strings.useImage },
            multiple: false
        });

        mediaFrame.on('select', function () {
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            $input.val(attachment.id);
            $removeBtn.show();
            var flagCol = $row.find('.flag-col');
            flagCol.html('<img src="' + attachment.url + '" class="cc-media-thumb-small" />');
        });

        mediaFrame.open();
    });

    $table.on('click', '.remove-custom-flag', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var code = $row.data('code');
        $row.find('.flag-id-input').val('0');
        $btn.hide();
        if (catalog[code]) {
            $row.find('.flag-col').html(catalog[code].flag);
        }
    });

    // ── Tabs Logic ──
    var $tabs = $('.cc-settings-tabs');
    if ($tabs.length) {
        $tabs.show();
        $('body').addClass('js');

        var pageSlug = new URLSearchParams(window.location.search).get('page');
        var storageKey = 'cc_active_tab_' + pageSlug;

        var defaultTab = (pageSlug === 'cc-site-settings') ? 'multilingual' : 'menu';
        var activeTab = localStorage.getItem(storageKey) || defaultTab;

        if ($tabs.find('[data-tab="' + activeTab + '"]').length === 0) {
            activeTab = defaultTab;
        }

        switchTab(activeTab);

        $tabs.on('click', 'a', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            switchTab(tab);
            localStorage.setItem(storageKey, tab);
        });

        function switchTab(tabId) {
            $tabs.find('.nav-tab').removeClass('nav-tab-active');
            $tabs.find('[data-tab="' + tabId + '"]').addClass('nav-tab-active');
            $('.cc-tab-content').hide().removeClass('active');

            if (tabId === 'menu') {
                $('#cc-tab-menu').show().addClass('active');
            } else {
                $('#cc-tab-' + tabId).show().addClass('active');
            }
        }
    }

    // ── SEO Media Uploader ──
    var seoMediaFrame;
    $('#cc-seo-image-button').on('click', function (e) {
        e.preventDefault();
        if (seoMediaFrame) {
            seoMediaFrame.open();
            return;
        }
        seoMediaFrame = wp.media({
            title: CC_SETTINGS.strings.selectOGImage,
            button: { text: CC_SETTINGS.strings.useImage },
            multiple: false
        });
        seoMediaFrame.on('select', function () {
            var attachment = seoMediaFrame.state().get('selection').first().toJSON();
            $('#cc-seo-image-id').val(attachment.id);
            var imgUrl = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
            $('#cc-seo-image-preview').html('<img src="' + imgUrl + '" class="cc-seo-preview-image" />').show();
            $('#cc-seo-image-remove').show();
        });
        seoMediaFrame.open();
    });

    $('#cc-seo-image-remove').on('click', function (e) {
        e.preventDefault();
        $('#cc-seo-image-id').val('');
        $('#cc-seo-image-preview').hide().html('');
        $(this).hide();
    });

    // ── Ordering Logic ──
    function serializeVisibilityOrder() {
        var order = [];
        $('.cc-visibility-sortable tbody tr[data-slug]').each(function () {
            var slug = $(this).data('slug');
            if (slug) order.push(slug);
        });
        $('#cc-core-order-admin-input').val(JSON.stringify(order));
        $('#cc-core-order-client-input').val(JSON.stringify(order));
    }

    if ($.fn.sortable) {
        $('.cc-visibility-sortable tbody').sortable({
            handle: '.cc-drag-handle',
            items: 'tr[data-slug]',
            placeholder: 'ui-sortable-placeholder',
            update: function (event, ui) {
                serializeVisibilityOrder();
            }
        });
        serializeVisibilityOrder();
    }

    // ── REST Saving Implementation ──
    const currentPage = new URLSearchParams(window.location.search).get('page');
    const phpSettingsPages = ['cc-visibility', 'cc-media', 'cc-redirect', 'cc-multilingual', 'cc-branding'];
    const isPhpSettingsPage = phpSettingsPages.indexOf(currentPage) !== -1;
    const $form = isPhpSettingsPage
        ? $('.wrap.content-core-admin form').first()
        : $('.cc-settings-tabs, .cc-settings-single-page form').last().closest('form');
    if ($form.length && typeof CC_SETTINGS !== 'undefined') {
        if (isPhpSettingsPage) {
            // Keep native PHP submit path for these pages.
            return;
        }

        if (!CC_SETTINGS.restUrl || !CC_SETTINGS.nonce) {
            $form.before('<div class="notice notice-error cc-notice-inline"><p><strong>Content Core config missing.</strong> Assets not localized. Please check admin enqueue + caching plugins.</p></div>');
            return;
        }
        $form.on('submit', function (e) {
            const $btn = $(document.activeElement);
            const isReset = $btn.attr('name') === 'cc_reset_menu';

            e.preventDefault();

            let $submitBtn = isReset ? $btn : $form.find('button[type="submit"].button-primary, button[type="submit"].cc-button-primary').first();
            if (!$submitBtn.length) {
                $submitBtn = $btn.length ? $btn : $form.find('button[type="submit"]').first();
            }
            const originalText = $submitBtn.text();
            $submitBtn.prop('disabled', true).text(isReset ? 'Resetting...' : 'Saving...');

            // Identify which module we are saving
            const pageSlug = currentPage;
            let moduleKey = '';
            if (pageSlug === 'cc-visibility') moduleKey = 'visibility';
            else if (pageSlug === 'cc-media') moduleKey = 'media';
            else if (pageSlug === 'cc-redirect') moduleKey = 'redirect';
            else if (pageSlug === 'cc-multilingual') moduleKey = 'multilingual';

            if (!moduleKey) {
                $form.off('submit').submit(); // Fallback
                return;
            }

            const data = {};
            const formData = $form.serializeArray();

            // Mapping config for visibility
            if (moduleKey === 'visibility') {
                data.menu = { admin: {}, client: {} };
                data.admin_bar = {};

                formData.forEach(item => {
                    let match;
                    if ((match = item.name.match(/^cc_menu_admin\[(.+)\]$/))) {
                        data.menu.admin[match[1]] = item.value === '1';
                    } else if ((match = item.name.match(/^cc_menu_client\[(.+)\]$/))) {
                        data.menu.client[match[1]] = item.value === '1';
                    } else if ((match = item.name.match(/^cc_admin_bar\[(.+)\]$/))) {
                        data.admin_bar[match[1]] = item.value === '1';
                    } else if (item.name === 'content_core_admin_menu_order') {
                        try { data.order = JSON.parse(item.value); } catch (e) { data.order = item.value; }
                    }
                });

                // Handle unchecked checkboxes
                $form.find('input[type="checkbox"]').each(function () {
                    let match;
                    if (!this.checked) {
                        if ((match = this.name.match(/^cc_menu_admin\[(.+)\]$/))) {
                            data.menu.admin[match[1]] = false;
                        } else if ((match = this.name.match(/^cc_menu_client\[(.+)\]$/))) {
                            data.menu.client[match[1]] = false;
                        } else if ((match = this.name.match(/^cc_admin_bar\[(.+)\]$/))) {
                            data.admin_bar[match[1]] = false;
                        }
                    } else {
                        // Ensure checked values are also set (already done in serializeArray usually, but for consistency)
                        if ((match = this.name.match(/^cc_menu_admin\[(.+)\]$/))) {
                            data.menu.admin[match[1]] = true;
                        } else if ((match = this.name.match(/^cc_menu_client\[(.+)\]$/))) {
                            data.menu.client[match[1]] = true;
                        } else if ((match = this.name.match(/^cc_admin_bar\[(.+)\]$/))) {
                            data.admin_bar[match[1]] = true;
                        }
                    }
                });
            } else {
                // Generic mapping for other modules (media, redirect, multilingual)
                const prefixMap = {
                    'media': 'cc_media_settings',
                    'redirect': 'cc_redirect_settings',
                    'multilingual': 'cc_languages'
                };
                const prefix = prefixMap[moduleKey];

                if (moduleKey === 'multilingual') {
                    data.languages = [];
                    data.permalink_bases = {};
                    data.taxonomy_bases = {};
                }

                formData.forEach(item => {
                    if (moduleKey === 'redirect' && item.name.startsWith('cc_admin_bar_link[')) {
                        const match = item.name.match(/^cc_admin_bar_link\[(.+)\]$/);
                        if (match) {
                            if (!data.admin_bar) data.admin_bar = {};
                            data.admin_bar[match[1]] = item.value;
                        }
                        return;
                    }

                    const match = item.name.match(new RegExp('^' + prefix + '\\[(.+)\\]$'));
                    if (match) {
                        const fullKey = match[1];
                        const parts = fullKey.split('][').map(p => p.replace(/\]$/, '').replace(/^\[/, ''));

                        let target = data;
                        for (let i = 0; i < parts.length; i++) {
                            const part = parts[i];
                            if (i === parts.length - 1) {
                                if (part === '') {
                                    if (!Array.isArray(target)) target = [];
                                    target.push(item.value);
                                } else {
                                    target[part] = item.value;
                                }
                            } else {
                                if (!target[part]) target[part] = (parts[i + 1] === '' ? [] : {});
                                target = target[part];
                            }
                        }
                    }
                });

                // Checkboxes for generic modules
                $form.find('input[type="checkbox"]').each(function () {
                    const name = this.name;

                    if (moduleKey === 'redirect' && name.startsWith('cc_admin_bar_link[')) {
                        const match = name.match(/^cc_admin_bar_link\[(.+)\]$/);
                        if (match) {
                            if (!data.admin_bar) data.admin_bar = {};
                            data.admin_bar[match[1]] = this.checked;
                        }
                    } else {
                        const match = name.match(new RegExp('^' + prefix + '\\[(.+)\\]$'));
                        if (match) {
                            const fullKey = match[1];
                            const parts = fullKey.split('][').map(p => p.replace(/\]$/, '').replace(/^\[/, ''));

                            let target = data;
                            for (let i = 0; i < parts.length; i++) {
                                const part = parts[i];
                                if (i === parts.length - 1) {
                                    target[part] = this.checked;
                                } else {
                                    if (!target[part]) target[part] = (parts[i + 1] === '' ? [] : {});
                                    target = target[part];
                                }
                            }
                        }
                    }
                });
            }

            // REST controller expects flat module payloads, not option-key wrapped payloads.
            const dataToSave = data;

            wp.apiFetch({
                path: `content-core/v1/settings/${moduleKey}`,
                method: 'POST',
                data: isReset ? { reset: true } : dataToSave,
                headers: { 'X-WP-Nonce': CC_SETTINGS.nonce }
            }).then(() => {
                if (isReset) {
                    window.location.reload();
                    return;
                }
                if (isPhpSettingsPage) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('cc_action', 'settings_saved');
                    window.location.href = url.toString();
                    return;
                }
                showToast('Settings saved successfully.', 'success');
            }).catch((err) => {
                console.error('Save error:', err);
                showToast('Error saving settings: ' + (err.message || 'Unknown error'), 'error');
            }).finally(() => {
                $submitBtn.prop('disabled', false).text(originalText);
            });
        });
    }
});

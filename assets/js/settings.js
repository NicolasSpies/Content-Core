jQuery(function ($) {
    if ($('.cc-settings-tabs').length === 0) return;

    var $table = $('#cc-ml-languages-table tbody');
    var template = $('#cc-ml-row-template').html();
    var catalog = (typeof CC_SETTINGS !== 'undefined') ? CC_SETTINGS.catalog : {};

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
        updateSelects();
    });

    $table.on('click', '.remove-row', function () {
        if (confirm(CC_SETTINGS.strings.confirmRemoveLang)) {
            $(this).closest('tr').remove();
            $table.find('tr').each(function (idx) {
                $(this).attr('data-index', idx);
                $(this).find('[name]').each(function () {
                    this.name = this.name.replace(/cc_languages\[languages\]\[\d+\]/, 'cc_languages[languages][' + idx + ']');
                });
            });
            updateSelects();
        }
    });

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
            flagCol.html('<img src="' + attachment.url + '" style="width:18px; height:12px; object-fit:cover; vertical-align:middle; border-radius:1px; margin-right:4px;" />');
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
            $('#cc-seo-image-preview').html('<img src="' + imgUrl + '" style="max-width: 150px; height: auto; border: 1px solid #ddd; padding: 3px; border-radius: 4px;" />').show();
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
});

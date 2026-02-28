jQuery(function ($) {
    var $schemaEditor = $('#cc-schema-editor');
    if (!$schemaEditor.length) return;

    var config = window.ccSchemaEditorConfig || { languages: [], strings: {} };
    var languages = config.languages;
    var i18n = config.strings;

    function generateId() {
        return 'cc_' + Math.random().toString(36).substr(2, 9);
    }

    $schemaEditor.on('click', '.cc-add-section', function () {
        var sectionId = generateId();

        var html = '<div class="cc-schema-section cc-card" style="background: #f8f9fa; margin-bottom: 20px; border: 1px solid #dcdcde;" data-id="' + sectionId + '">' +
            '<div style="display: flex; gap: 15px; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #dcdcde; padding-bottom: 15px;">' +
            '<span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab;"></span>' +
            '<div style="flex-grow: 1;">' +
            '<input type="text" name="cc_site_options_schema[' + sectionId + '][title]" value="" class="large-text" style="font-weight: 600;" placeholder="' + i18n.sectionTitle + '">' +
            '</div>' +
            '<button type="button" class="button button-link-delete cc-remove-section"><span class="dashicons dashicons-trash"></span></button>' +
            '</div>' +
            '<div class="cc-schema-fields" style="padding-left: 20px;">' +
            '<button type="button" class="button button-secondary cc-add-field" data-section="' + sectionId + '">' + i18n.addField + '</button>' +
            '</div>' +
            '</div>';

        $(this).before(html);
    });

    $schemaEditor.on('click', '.cc-remove-section', function () {
        if (confirm(i18n.confirmRemoveSection)) {
            $(this).closest('.cc-schema-section').remove();
        }
    });

    $schemaEditor.on('click', '.cc-add-field', function () {
        var sectionId = $(this).data('section');
        var fieldId = generateId();
        var addBtn = $(this);

        var html = '<div class="cc-schema-field" data-id="' + fieldId + '" style="display: flex; gap: 10px; align-items: flex-start; margin-bottom: 15px; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 4px;">' +
            '<span class="dashicons dashicons-menu" style="color: #a0a5aa; cursor: grab; margin-top: 8px;"></span>' +
            '<div style="flex-grow: 1;">' +
            '<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">' +
            '<div>' +
            '<label style="display: block; font-size: 11px; margin-bottom: 3px;">' + i18n.stableKey + '</label>' +
            '<input type="text" value="' + fieldId + '" class="regular-text" style="width: 100%; font-family: monospace;" readonly disabled>' +
            '<input type="hidden" name="dummy" value="just so we have the key implied by the name structure">' +
            '</div>' +
            '<div>' +
            '<label style="display: block; font-size: 11px; margin-bottom: 3px;">' + i18n.type + '</label>' +
            '<select name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][type]" style="width: 100%;">' +
            '<option value="text">Text</option>' +
            '<option value="email">Email</option>' +
            '<option value="url">URL</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="image">Image/Logo</option>' +
            '</select>' +
            '</div>' +
            '<div style="display: flex; gap: 15px; align-items: center; padding-top: 20px;">' +
            '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_visible]" value="1" checked> ' + i18n.visible + '</label>' +
            '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_editable]" value="1" checked> ' + i18n.editable + '</label>' +
            '</div>' +
            '</div>' +
            '<div style="display: grid; grid-template-columns: repeat(' + (languages.length || 1) + ', 1fr); gap: 10px;">';

        languages.forEach(function (lang) {
            html += '<div>' +
                '<label style="display: block; font-size: 11px; margin-bottom: 3px;">' + lang.label + ' ' + i18n.label + '</label>' +
                '<input type="text" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][label][' + lang.code + ']" value="" style="width: 100%;">' +
                '</div>';
        });

        html += '</div></div>' +
            '<button type="button" class="button button-link-delete cc-remove-field" style="margin-top: 5px;"><span class="dashicons dashicons-no-alt"></span></button>' +
            '</div>';

        addBtn.before(html);
    });

    $schemaEditor.on('click', '.cc-remove-field', function () {
        if (confirm(i18n.confirmRemoveField)) {
            $(this).closest('.cc-schema-field').remove();
        }
    });

    // ── Schema Reordering ──
    if ($.fn.sortable) {
        $schemaEditor.sortable({
            items: '.cc-schema-section',
            handle: '.dashicons-menu',
            placeholder: 'ui-sortable-placeholder',
            axis: 'y'
        });

        $schemaEditor.on('mouseenter', '.cc-schema-fields', function () {
            if (!$(this).data('sortable-init')) {
                $(this).sortable({
                    items: '.cc-schema-field',
                    handle: '.dashicons-menu',
                    placeholder: 'ui-sortable-placeholder',
                    axis: 'y'
                });
                $(this).data('sortable-init', true);
            }
        });
    }
});

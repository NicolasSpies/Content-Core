jQuery(function ($) {
    var $schemaEditor = $('#cc-schema-editor');
    if (!$schemaEditor.length) return;

    var config = window.ccSchemaEditorConfig || { languages: [], strings: {} };
    var languages = config.languages;
    var i18n = config.strings;
    var singleLabel = !!config.singleLabel;

    function generateId() {
        return 'cc_' + Math.random().toString(36).substr(2, 9);
    }

    $schemaEditor.on('click', '.cc-add-section', function () {
        var sectionId = generateId();

        var html = '<div class="cc-schema-section cc-card" data-id="' + sectionId + '">' +
            '<div class="cc-schema-section__header">' +
            '<span class="dashicons dashicons-menu" aria-hidden="true"></span>' +
            '<div class="cc-schema-section__title-wrap">' +
            '<input type="text" name="cc_site_options_schema[' + sectionId + '][title]" value="" class="large-text" placeholder="' + i18n.sectionTitle + '">' +
            '</div>' +
            '<button type="button" class="button button-link-delete cc-remove-section"><span class="dashicons dashicons-trash"></span></button>' +
            '</div>' +
            '<div class="cc-schema-fields">' +
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

        var html = '<div class="cc-schema-field" data-id="' + fieldId + '">' +
            '<span class="dashicons dashicons-menu" aria-hidden="true"></span>' +
            '<div class="cc-grow">' +
            '<div class="cc-schema-grid-3">' +
            '<div>' +
            '<label class="cc-schema-field-label">' + i18n.stableKey + '</label>' +
            '<input type="text" value="' + fieldId + '" class="regular-text cc-input-mono" readonly disabled>' +
            '<input type="hidden" name="dummy" value="just so we have the key implied by the name structure">' +
            '</div>' +
            '<div>' +
            '<label class="cc-schema-field-label">' + i18n.type + '</label>' +
            '<select name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][type]">' +
            '<option value="text">Text</option>' +
            '<option value="email">Email</option>' +
            '<option value="url">URL</option>' +
            '<option value="textarea">Textarea</option>' +
            '<option value="image">Image/Logo</option>' +
            '</select>' +
            '</div>' +
            '<div class="cc-schema-flags">' +
            '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_visible]" value="1" checked> ' + i18n.visible + '</label>' +
            '<label><input type="checkbox" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][client_editable]" value="1" checked> ' + i18n.editable + '</label>' +
            '</div>' +
            '</div>' +
            '<div class="cc-schema-label-grid">';

        if (singleLabel || !languages.length) {
            html += '<div>' +
                '<label class="cc-schema-field-label">' + i18n.label + '</label>' +
                '<input type="text" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][label]" value="">' +
                '</div>';
        } else {
            languages.forEach(function (lang) {
                html += '<div>' +
                    '<label class="cc-schema-field-label">' + lang.label + ' ' + i18n.label + '</label>' +
                    '<input type="text" name="cc_site_options_schema[' + sectionId + '][fields][' + fieldId + '][label][' + lang.code + ']" value="">' +
                    '</div>';
            });
        }

        html += '</div></div>' +
            '<button type="button" class="button button-link-delete cc-remove-field cc-btn-top-gap"><span class="dashicons dashicons-no-alt"></span></button>' +
            '</div>';

        addBtn.before(html);
    });

    $schemaEditor.on('click', '.cc-remove-field', function () {
        if (confirm(i18n.confirmRemoveField)) {
            $(this).closest('.cc-schema-field').remove();
        }
    });

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

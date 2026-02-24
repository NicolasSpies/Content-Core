/**
 * Content Core - Admin JavaScript
 * Handles field interactions: Repeaters, Media, Galleries.
 */

jQuery(document).ready(function ($) {
    var fileFrame;

    // --- Media Uploader Logic ---
    $(document).on('click', '[data-cc-action="upload-media"], .cc-media-upload-btn', function (e) {
        e.preventDefault();
        var button = $(this);
        var wrapper = button.closest('.cc-media-uploader');
        var type = wrapper.data('type');

        if (typeof wp === 'undefined' || !wp.media) {
            console.error('Content Core: wp.media is not defined. Ensure wp_enqueue_media() was called.');
            return;
        }

        fileFrame = wp.media({
            title: 'Select Media',
            button: { text: 'Use this media' },
            multiple: false,
            library: { type: 'image' === type ? 'image' : '' }
        }).on('select', function () {
            var attachment = fileFrame.state().get('selection').first().toJSON();
            wrapper.find('.cc-media-id-input').val(attachment.id);

            var previewHtml = '';
            if ('image' === type) {
                var url = attachment.url;
                if (attachment.sizes) {
                    if (attachment.sizes.large) url = attachment.sizes.large.url;
                    else if (attachment.sizes.medium) url = attachment.sizes.medium.url;
                    else if (attachment.sizes.thumbnail) url = attachment.sizes.thumbnail.url;
                }
                previewHtml = '<img src="' + url + '" />';
            } else {
                previewHtml = '<div class="cc-media-filename">' + attachment.filename + '</div>';
            }

            wrapper.find('.cc-media-preview').html(previewHtml);
            wrapper.find('.cc-media-remove-btn').show();
        }).open();
    });

    $(document).on('click', '[data-cc-action="remove-media"], .cc-media-remove-btn', function (e) {
        e.preventDefault();
        var wrapper = $(this).closest('.cc-media-uploader');
        wrapper.find('.cc-media-id-input').val('');
        wrapper.find('.cc-media-preview').empty();
        $(this).hide();
    });

    // --- Repeater Logic ---
    function ccReindexRepeater(container) {
        container.find('> .cc-repeater-rows > .cc-repeater-row').each(function (index) {
            var row = $(this);
            row.attr('data-index', index);
            row.find('.cc-repeater-row-index').text(index + 1);

            row.find('input, select, textarea').each(function () {
                var input = $(this);
                var name = input.attr('name');
                if (name) {
                    var newName = name.replace(/\[\d+\]/, '[' + index + ']');
                    input.attr('name', newName);
                }
                var id = input.attr('id');
                if (id) {
                    var newId = id.replace(/_\d+_/, '_' + index + '_');
                    input.attr('id', newId);
                }
            });
        });
    }

    $(document).on('click', '[data-cc-action="add-repeater-row"], .cc-add-repeater-row-btn', function (e) {
        e.preventDefault();
        var container = $(this).closest('.cc-repeater-container');
        var rowsWrap = container.find('> .cc-repeater-rows');
        var parentName = container.data('name');
        var subFields = [];
        try {
            subFields = container.data('sub-fields') || [];
        } catch (e) {
            console.error('Content Core: Failed to parse sub-fields', e);
        }
        var fieldType = container.data('field-type') || 'meta'; // 'meta' or 'options'
        var newIndex = rowsWrap.find('> .cc-repeater-row').length;

        var html = '<div class="cc-repeater-row" data-index="' + newIndex + '">';
        html += '<div class="cc-repeater-row-header">';
        html += '<span class="cc-repeater-row-handle dashicons dashicons-menu"></span>';
        html += '<span class="cc-repeater-row-index">' + (newIndex + 1) + '</span>';
        html += '<span class="cc-repeater-row-title">Row ' + (newIndex + 1) + '</span>';
        html += '<div class="cc-repeater-row-actions">';
        html += '<button type="button" class="cc-repeater-row-toggle" title="Toggle Row"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
        html += '<button type="button" class="cc-repeater-row-remove" title="Remove Row"><span class="dashicons dashicons-trash"></span></button>';
        html += '</div></div>';
        html += '<div class="cc-repeater-row-content">';
        html += '<table class="form-table content-core-form-table nested-repeater-table"><tbody>';

        subFields.forEach(function (sub) {
            var inputNameBase = (fieldType === 'options') ? 'cc_options' : 'cc_meta';
            var fName = inputNameBase + '[' + parentName + '][' + newIndex + '][' + sub.name + ']';
            var fId = 'cc_field_' + parentName + '_' + newIndex + '_' + sub.name;

            html += '<tr class="content-core-field-row content-core-field-type-' + sub.type + '">';
            html += '<th scope="row"><label for="' + fId + '">' + sub.label + '</label></th>';
            html += '<td>';

            if (sub.type === 'textarea') {
                html += '<textarea id="' + fId + '" name="' + fName + '" class="cc-input-full" rows="3"></textarea>';
            } else if (sub.type === 'number') {
                html += '<input type="number" id="' + fId + '" name="' + fName + '" class="cc-input-full">';
            } else if (sub.type === 'email') {
                html += '<input type="email" id="' + fId + '" name="' + fName + '" class="cc-input-full">';
            } else if (sub.type === 'url') {
                html += '<input type="url" id="' + fId + '" name="' + fName + '" class="cc-input-full">';
            } else if (sub.type === 'boolean') {
                html += '<input type="hidden" name="' + fName + '" value="0">';
                html += '<label><input type="checkbox" id="' + fId + '" name="' + fName + '" value="1"> Yes</label>';
            } else if (sub.type === 'image' || sub.type === 'file') {
                var bt = sub.type === 'image' ? 'Select Image' : 'Select File';
                html += '<div class="cc-media-uploader" data-type="' + sub.type + '">';
                html += '<input type="hidden" name="' + fName + '" class="cc-media-id-input">';
                html += '<div class="cc-media-preview"></div>';
                html += '<div class="cc-media-actions" style="margin-top:10px;">';
                html += '<button type="button" class="button cc-media-upload-btn">' + bt + '</button> ';
                html += '<button type="button" class="button cc-media-remove-btn" style="display:none;">Remove</button>';
                html += '</div></div>';
            } else {
                html += '<input type="text" id="' + fId + '" name="' + fName + '" class="cc-input-full">';
            }
            html += '</td></tr>';
        });

        html += '</tbody></table></div></div>';

        var $row = $(html);
        rowsWrap.append($row);
        $row.find('.cc-repeater-row-content').hide().slideDown();
        $row.addClass('is-open');
        $row.find('.cc-repeater-row-toggle span').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
    });

    $(document).on('click', '[data-cc-action="remove-repeater-row"], .cc-repeater-row-remove', function (e) {
        e.preventDefault();
        var row = $(this).closest('.cc-repeater-row');
        var container = row.closest('.cc-repeater-container');
        if (confirm('Remove this row?')) {
            row.slideUp(function () {
                $(this).remove();
                ccReindexRepeater(container);
            });
        }
    });

    $(document).on('click', '[data-cc-action="toggle-repeater-row"], .cc-repeater-row-toggle, .cc-repeater-row-title', function (e) {
        e.preventDefault();
        var row = $(this).closest('.cc-repeater-row');
        row.toggleClass('is-open');
        row.find('> .cc-repeater-row-content').slideToggle();

        var icon = row.find('.cc-repeater-row-toggle span');
        if (row.hasClass('is-open')) {
            icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
        } else {
            icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
        }
    });

    if ($.fn.sortable) {
        $('.cc-repeater-rows').sortable({
            handle: '.cc-repeater-row-handle',
            items: '> .cc-repeater-row',
            placeholder: 'cc-repeater-placeholder',
            update: function () {
                ccReindexRepeater($(this).closest('.cc-repeater-container'));
            }
        });
    }

    // --- Gallery Logic ---
    $(document).on('click', '[data-cc-action="add-gallery-item"], .cc-gallery-add-btn', function (e) {
        e.preventDefault();
        var wrapper = $(this).closest('.cc-gallery-container');
        var input = wrapper.find('.cc-gallery-input');
        var list = wrapper.find('.cc-gallery-list');
        var ids = [];
        try {
            ids = JSON.parse(input.val() || '[]');
        } catch (e) {
            console.error('Content Core: Failed to parse gallery data', e);
        }

        if (typeof wp === 'undefined' || !wp.media) {
            console.error('Content Core: wp.media is not defined.');
            return;
        }

        var galleryFrame = wp.media({
            title: 'Add to Gallery',
            multiple: true,
            library: { type: 'image' }
        }).on('select', function () {
            var selection = galleryFrame.state().get('selection');
            selection.each(function (attachment) {
                attachment = attachment.toJSON();
                if (ids.indexOf(attachment.id) === -1) {
                    ids.push(attachment.id);
                    var thumb = attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
                    list.append('<div class="cc-gallery-item" data-id="' + attachment.id + '"><img src="' + thumb + '"><button type="button" class="cc-gallery-remove" data-cc-action="remove-gallery-item">&times;</button></div>');
                }
            });
            input.val(JSON.stringify(ids));
        }).open();
    });

    $(document).on('click', '[data-cc-action="remove-gallery-item"], .cc-gallery-remove', function () {
        var item = $(this).closest('.cc-gallery-item');
        var id = item.data('id');
        var wrapper = $(this).closest('.cc-gallery-container');
        var input = wrapper.find('.cc-gallery-input');
        var ids = [];
        try {
            ids = JSON.parse(input.val() || '[]');
        } catch (e) {
            console.error('Content Core: Failed to parse gallery data', e);
        }
        ids = ids.filter(function (v) { return v !== id; });
        input.val(JSON.stringify(ids));
        item.remove();
    });

    if ($.fn.sortable) {
        $('.cc-gallery-list').sortable({
            update: function () {
                var wrapper = $(this).closest('.cc-gallery-container');
                var input = wrapper.find('.cc-gallery-input');
                var ids = [];
                wrapper.find('.cc-gallery-item').each(function () {
                    ids.push($(this).data('id'));
                });
                input.val(JSON.stringify(ids));
            }
        });
    }

    /**
     * Section Collapse Logic
     */
    function initSections() {
        $('.cc-section').each(function () {
            var $section = $(this);
            var sectionId = $section.data('section-id');
            var isCollapsible = $section.hasClass('is-collapsible');

            if (isCollapsible) {
                var savedState = localStorage.getItem('cc_section_' + sectionId);
                if (savedState === 'collapsed') {
                    $section.removeClass('is-open');
                    $section.find('> .cc-section-body').hide();
                } else if (savedState === 'expanded') {
                    $section.addClass('is-open');
                    $section.find('> .cc-section-body').show();
                } else if ($section.hasClass('default-open')) {
                    $section.addClass('is-open');
                    $section.find('> .cc-section-body').show();
                } else {
                    // Default to collapsed if no saved state and not default-open
                    $section.removeClass('is-open');
                    $section.find('> .cc-section-body').hide();
                }
            } else {
                $section.addClass('is-open');
                $section.find('> .cc-section-body').show();
            }
        });
    }

    $(document).on('click', '.cc-section.is-collapsible .cc-section-header', function () {
        var $section = $(this).closest('.cc-section');
        var sectionId = $section.data('section-id');
        $section.toggleClass('is-open');
        var isOpen = $section.hasClass('is-open');
        try {
            localStorage.setItem('cc_section_' + sectionId, isOpen ? 'expanded' : 'collapsed');
        } catch (e) {
            console.warn('Content Core: Could not save section state to localStorage.');
        }

        $section.find('> .cc-section-body').slideToggle();
    });

    initSections();

});

/**
 * Content Core - Admin JavaScript
 * Handles field interactions: Repeaters, Media, Galleries.
 */

console.log("CC DEBUG LOADED", document.body.className);

jQuery(document).ready(function ($) {
    // 1. Annihilate any rogue split-arrows natively injected by WordPress/Polylang
    if ($('.page-title-action').length) {
        $('.page-title-action').nextUntil('.wp-header-end, hr, form, .notice, table, .subsubsub, #screen-meta-links, .wp-list-table, h2').remove();
    }

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
                html += '<div class="cc-media-actions">';
                html += '<button type="button" class="button cc-media-upload-btn">' + bt + '</button> ';
                html += '<button type="button" class="button cc-media-remove-btn hidden">Remove</button>';
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

    /**
     * Sidebar Menu Collapsible Logic
     */
    function initSidebarCollapse() {
        var $menu = $('#toplevel_page_content-core');
        if (!$menu.length) return;

        var $submenu = $menu.find('.wp-submenu');
        var $headerLinks = $submenu.find('a[href*="-root"]');
        if (!$headerLinks.length) return;

        $submenu.addClass('cc-menu-groups-ready');
        $submenu.find('li').removeClass('cc-menu-dashboard cc-menu-group-header cc-menu-group-item');

        // Dashboard row is the first explicit content-core link.
        var $dashboardLink = $submenu.find('a[href*="page=content-core"]').first();
        if ($dashboardLink.length) {
            $dashboardLink.parent().addClass('cc-menu-dashboard');
        }

        $headerLinks.each(function () {
            var $header = $(this);
            var $headerLi = $header.parent();
            var href = $header.attr('href') || '';
            var slug = '';
            var pagePos = href.indexOf('page=');
            if (pagePos !== -1) {
                slug = href.substring(pagePos + 5).split('&')[0];
            }

            $headerLi.addClass('cc-menu-group-header');
            $header.addClass('cc-menu-header-toggle');

            var $items = $();
            var $cursor = $headerLi.next();
            while ($cursor.length && !$cursor.find('a[href*="-root"]').length) {
                $cursor.addClass('cc-menu-group-item');
                $items = $items.add($cursor);
                $cursor = $cursor.next();
            }

            var hasCurrentItem = $items.filter('.current').length > 0 || $items.find('a.current').length > 0;
            var isCollapsed = false;

            if (slug.indexOf('cc-system-root') !== -1) {
                isCollapsed = true;
            }
            if (slug.indexOf('cc-structure-root') !== -1 || slug.indexOf('cc-settings-root') !== -1) {
                isCollapsed = false;
            }
            if (window.ccAdmin && ccAdmin.menuState && typeof ccAdmin.menuState[slug] !== 'undefined') {
                isCollapsed = ccAdmin.menuState[slug] === 'collapsed';
            }
            if (hasCurrentItem) {
                isCollapsed = false;
            }

            applySectionState($header, $items, isCollapsed);

            $header.off('click.ccMenuToggle').on('click.ccMenuToggle', function (e) {
                e.preventDefault();
                e.stopPropagation();

                var nextCollapsed = !$header.hasClass('is-collapsed');
                applySectionState($header, $items, nextCollapsed);

                if (window.wp && wp.apiFetch) {
                    wp.apiFetch({
                        path: 'content-core/v1/user-preferences/menu-state',
                        method: 'POST',
                        data: {
                            slug: slug,
                            state: nextCollapsed ? 'collapsed' : 'expanded'
                        }
                    }).catch(function (err) {
                        console.warn('Content Core: Failed to persist menu state.', err);
                    });
                }
            });
        });

        function applySectionState($header, $items, collapsed) {
            $header.toggleClass('is-collapsed', collapsed);
            $header.attr('aria-expanded', collapsed ? 'false' : 'true');
            $items.attr('hidden', collapsed ? 'hidden' : null);
        }
    }

    function initSidebarSectionLabels() {
        return;
    }

    function initTopLevelMenuToggle() {
        var $adminMenu = $('#adminmenu');
        if (!$adminMenu.length) return;

        $adminMenu.off('click.ccTopLevelToggle', '> li.menu-top > a.menu-top');
        $adminMenu.on('click.ccTopLevelToggle', '> li.menu-top > a.menu-top', function (e) {
            var $link = $(this);
            var $item = $link.parent('li.menu-top');
            if (!$item.length) return;

            // Menus intentionally flattened by CSS should keep normal click behavior.
            if (
                $item.is('#menu-dashboard') ||
                $item.is('#menu-pages') ||
                $item.is('#menu-posts') ||
                $item.is('#menu-media') ||
                $item.is('#menu-plugins') ||
                $item.is('#menu-users') ||
                $item.is('#toplevel_page_cc-site-options') ||
                $item.is('[id^="menu-posts-"]')
            ) {
                return;
            }

            var $submenu = $item.children('.wp-submenu');
            if (!$submenu.length) return;

            e.preventDefault();
            e.stopPropagation();

            var willCollapse = !$item.hasClass('cc-submenu-collapsed');
            if (willCollapse) {
                $item.addClass('cc-submenu-collapsed').removeClass('opensub');
                $link.attr('aria-expanded', 'false');
                return;
            }

            // Accordion behavior: open one, collapse siblings with submenus.
            $item
                .siblings('li.menu-top')
                .has('.wp-submenu')
                .addClass('cc-submenu-collapsed')
                .removeClass('opensub')
                .children('a.menu-top')
                .attr('aria-expanded', 'false');

            $item.removeClass('cc-submenu-collapsed').addClass('opensub');
            $link.attr('aria-expanded', 'true');
        });
    }

    function initSidebarCollapseToggleButton() {
        var $customToggle = $('#cc-sidebar-collapse-toggle');
        if (!$customToggle.length) return;

        $customToggle.off('click.ccSidebarCollapse').on('click.ccSidebarCollapse', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $nativeToggle = $('#collapse-button');
            if ($nativeToggle.length) {
                $nativeToggle.trigger('click');
                return;
            }

            $('body').toggleClass('folded');
        });
    }

    function initDarkModeToggle() {
        var $toggle = $('#cc-sidebar-account .cc-sidebar-account__switch');
        if (!$toggle.length) return;

        var body = document.body;
        var isOn = body.classList.contains('cc-admin-theme-dark') || !!(window.ccAdmin && window.ccAdmin.darkMode);

        function applyState(next) {
            isOn = !!next;
            body.classList.toggle('cc-admin-theme-dark', isOn);
            $toggle.toggleClass('is-on', isOn);
            $toggle.attr('aria-pressed', isOn ? 'true' : 'false');
        }

        applyState(isOn);

        $toggle.off('click.ccDarkMode').on('click.ccDarkMode', function (e) {
            e.preventDefault();
            var next = !isOn;
            applyState(next);

            if (!window.ccAdmin || !ccAdmin.restUrl || !ccAdmin.nonce) return;
            $.ajax({
                url: String(ccAdmin.restUrl).replace(/\/$/, '') + '/user-preferences/dark-mode',
                method: 'POST',
                data: JSON.stringify({ enabled: next }),
                contentType: 'application/json',
                beforeSend: function (xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ccAdmin.nonce);
                }
            }).fail(function () {
                applyState(!next);
            });
        });
    }

    function initMediaLibraryInspector() {
        var body = document.body;
        if (!body || !body.classList.contains('upload-php')) return;
        body.classList.remove('cc-media-has-selection');
        body.classList.remove('cc-media-bulk-mode');

        var scheduled = false;
        var rafId = 0;

        var updateSelectionState = function () {
            scheduled = false;
            rafId = 0;

            var selected = document.querySelectorAll(
                '.media-frame .attachments-browser .attachments .attachment.selected, ' +
                '.media-frame .attachments-browser .attachments .attachment.details, ' +
                '.media-frame .attachments-browser .attachments .attachment[aria-checked="true"], ' +
                '.media-frame .attachments-browser .attachments .attachment[aria-selected="true"]'
            );

            var isBulkMode = !!document.querySelector(
                '.media-frame.mode-select, ' +
                '.media-frame .attachments-browser.mode-select, ' +
                '.media-frame .media-toolbar .select-mode-toggle-button.active'
            );

            var hasSelection = !isBulkMode && selected.length > 0;

            body.classList.toggle('cc-media-has-selection', hasSelection);
            body.classList.toggle('cc-media-bulk-mode', isBulkMode);
        };

        var scheduleUpdate = function () {
            if (scheduled) return;
            scheduled = true;
            rafId = window.requestAnimationFrame(updateSelectionState);
        };

        scheduleUpdate();
        document.addEventListener('click', scheduleUpdate, true);
        document.addEventListener('keyup', scheduleUpdate, true);

        var root = document.querySelector('.media-frame') || document.documentElement;
        var observer = new MutationObserver(scheduleUpdate);
        observer.observe(root, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'aria-selected', 'aria-checked']
        });

        window.addEventListener('beforeunload', function () {
            if (rafId) {
                window.cancelAnimationFrame(rafId);
            }
            observer.disconnect();
        });
    }

    initSidebarCollapse();
    initSidebarSectionLabels();
    initTopLevelMenuToggle();
    initSidebarCollapseToggleButton();
    initDarkModeToggle();
    initMediaLibraryInspector();

    /**
     * Unified List Topbar
     */
    function initUnifiedListTopbar() {
        var $listTable = $('.wp-list-table');
        if (!$listTable.length) {
            $('.wrap').addClass('cc-ready');
            return;
        }
        if ($('.cc-list-topbar').length) {
            $('.wrap').addClass('cc-ready');
            return; // Dedupe
        }

        var $wrap = $listTable.closest('.wrap');
        if (!$wrap.length) return;

        var $topbar = $('<div class="cc-list-topbar"></div>');
        var $left = $('<div class="cc-list-topbar-left"></div>');
        var $right = $('<div class="cc-list-topbar-right"></div>');

        var $subsubsub = $wrap.find('.subsubsub').first();
        if ($subsubsub.length) {
            $left.append($subsubsub);
        }

        // Setup Filter button with a native Screen Options dropdown proxy
        var $nativeScreenOptions = $('#adv-settings');
        if ($nativeScreenOptions.length) {
            var $filterWrap = $('<div class="cc-filter-wrap"></div>');
            var $filterBtn = $('<button type="button" class="button cc-filter-trigger">Filter</button>');
            var $filterDropdown = $('<div class="cc-filter-dropdown" style="display: none;"></div>');

            var $columns = $nativeScreenOptions.find('.metabox-prefs').clone();

            if ($columns.length) {
                // Remove IDs to avoid conflict with native hidden DOM
                $columns.find('input').removeAttr('id');
                $columns.find('label').removeAttr('for');

                $filterDropdown.append($columns);

                // Sync click events back to native
                $filterDropdown.on('change', 'input[type="checkbox"]', function () {
                    var $nativeCheckbox = $nativeScreenOptions.find('input[name="' + $(this).attr('name') + '"]');
                    if ($nativeCheckbox.length) {
                        $nativeCheckbox.prop('checked', $(this).prop('checked')).trigger('change');
                        // Some WP events listen to standard click
                        $nativeCheckbox.trigger('click');
                    }
                });

                // Sync initial state
                $filterDropdown.find('input[type="checkbox"]').each(function () {
                    var $nativeCheckbox = $nativeScreenOptions.find('input[name="' + $(this).attr('name') + '"]');
                    if ($nativeCheckbox.length) {
                        $(this).prop('checked', $nativeCheckbox.prop('checked'));
                    }
                });

                $filterBtn.on('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    $filterDropdown.toggle();
                });

                $(document).on('click', function (e) {
                    if (!$(e.target).closest('.cc-filter-wrap').length) {
                        $filterDropdown.hide();
                    }
                });

                $filterWrap.append($filterBtn).append($filterDropdown);
                $right.append($filterWrap);
            }
        }

        var $searchBox = $wrap.find('.search-box, p.search-box').first();
        if ($searchBox.length) {
            // Remove submit buttons explicitly
            $searchBox.find('input[type="submit"], button[type="submit"], #search-submit').remove();

            // Re-enforce enter submits form natively
            $searchBox.find('input[type="search"], input[name="s"]').on('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    $(this).closest('form').submit();
                }
            });

            $right.append($searchBox); // Then append Search
        }

        // Ensure "Search References" or similar wrongly injected buttons are completely removed from DOM
        $('input[value="Search References"], button:contains("Search References")').remove();

        $topbar.append($left).append($right);
        $listTable.before($topbar);

        // Reveal safely without massive shift
        requestAnimationFrame(function () {
            $wrap.addClass('cc-ready');
        });
    }

    /**
     * List Table Cleanup and Unified Delete Action
     */
    function initUnifiedListTableDelete() {
        var $listTable = $('.wp-list-table');
        if (!$listTable.length) return;

        function injectDeleteColumn() {
            $('.wp-list-table').each(function () {
                var $table = $(this);
                if ($table.data('cc-delete-injected')) return;
                $table.data('cc-delete-injected', true);

                // Add header/footer columns
                $table.find('thead tr, tfoot tr').each(function () {
                    $(this).append('<th scope="col" class="cc-delete-column manage-column"></th>');
                });

                // Add row delete action
                $table.find('tbody tr').each(function () {
                    var $row = $(this);

                    if ($row.hasClass('no-items')) {
                        var colSpan = parseInt($row.find('td').attr('colspan') || 1, 10);
                        $row.find('td').attr('colspan', colSpan + 1);
                        return;
                    }

                    // WordPress uses 'trash' for posts/pages, 'delete' for plugins/users/terms
                    var deleteUrl = $row.find('.row-actions .trash a, .row-actions .delete a').attr('href');

                    var $td = $('<td class="cc-delete-column"></td>');
                    if (deleteUrl) {
                        var $btn = $('<button type="button" class="cc-delete-row-btn" title="Delete"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg></button>');
                        $btn.data('delete-url', deleteUrl);
                        $td.append($btn);
                    }
                    $row.append($td);
                });
            });
        }

        injectDeleteColumn();

        // Safe re-init for ajax contexts
        $(document).ajaxComplete(function (event, xhr, settings) {
            clearTimeout(window.ccDeleteInitTimer);
            window.ccDeleteInitTimer = setTimeout(function () {
                initUnifiedListTopbar();
                injectDeleteColumn();
            }, 50);
        });

        $(document).on('click', '.cc-delete-row-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var url = $btn.data('delete-url');

            if (confirm('Are you sure you want to delete this item?')) {
                var $row = $btn.closest('tr');
                $row.css({ 'opacity': '0.5', 'pointer-events': 'none' });

                $.get(url, function () {
                    // Success or follow-up redirect completed
                    $row.fadeOut(300, function () {
                        $(this).remove();
                    });
                }).fail(function () {
                    // Fallback to regular navigation
                    window.location.href = url;
                });
            }
        });
    }

    /**
     * List Table Date Formatting
     */
    function initListDateFormatter() {
        var $listTable = $('.wp-list-table');
        if (!$listTable.length) return;

        function formatDates($ctx) {
            $ctx.find('td.column-date, td.date').each(function () {
                var $cell = $(this);

                // Keep .post-state span (like User Status or Draft) but we'll hide it via CSS.
                // We just want to find the text that looks like a date.

                var dateFormatted = false;

                // 1. Try <abbr title="YYYY/MM/DD">
                var $abbr = $cell.find('abbr');
                if ($abbr.length) {
                    var title = $abbr.attr('title') || '';
                    var match = title.match(/(\d{4})[\/\-](\d{2})[\/\-](\d{2})/);
                    if (match) {
                        $abbr.text(match[3] + '.' + match[2] + '.' + match[1]);
                        dateFormatted = true;
                    }
                }

                // 2. Try <time> tag
                if (!dateFormatted) {
                    var $time = $cell.find('time');
                    if ($time.length) {
                        var datetime = $time.attr('datetime') || $time.text() || '';
                        var match = datetime.match(/(\d{4})[\/\-](\d{2})[\/\-](\d{2})/);
                        if (match) {
                            $time.text(match[3] + '.' + match[2] + '.' + match[1]);
                            dateFormatted = true;
                        }
                    }
                }

                // 3. Fallback to raw text replacement
                if (!dateFormatted) {
                    var html = $cell.html();
                    // Strip the "Last Modified" or "Published" strings and breaks
                    html = html.replace(/^(Published|Last Modified|Veröffentlicht|Zuletzt geändert|Veröffentlichen)[^<]*<br\s*\/?>\s*/i, '');
                    // Strip times like " at 11:54 pm" or " um 15:00"
                    html = html.replace(/\s+(at|um)\s+\d{1,2}:\d{2}\s*(am|pm|Uhr)?/i, '');
                    // Ensure the slash-based YYYY/MM/DD gets cleaned up if it hasn't successfully
                    var newHtml = html.replace(/(\d{4})[\/\-](\d{2})[\/\-](\d{2})/g, function (fullMatch, y, m, d) {
                        return d + '.' + m + '.' + y;
                    });
                    $cell.html(newHtml);
                }
            });
        }

        // Initial run
        formatDates($listTable);

        // Setup MutationObserver for row updates
        var tbody = $listTable.find('tbody').get(0);
        if (tbody) {
            var observer = new MutationObserver(function (mutations) {
                var hasNewRows = mutations.some(function (m) {
                    return m.addedNodes.length > 0;
                });
                if (hasNewRows) {
                    formatDates($(tbody));
                }
            });
            observer.observe(tbody, { childList: true, subtree: true });
        }
    }

    initUnifiedListTopbar();
    initUnifiedListTableDelete();
    initListDateFormatter();
});

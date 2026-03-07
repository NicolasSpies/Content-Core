/**
 * Content Core - Admin JavaScript
 * Handles field interactions: Repeaters, Media, Galleries.
 */

/* ── Blank-page tripwire (dev log only, zero DOM edits) ─────────────────────
 * Fires once after 500ms. If .wrap has no visible height while cc-admin-theme
 * is active, logs a diagnostic. Enabled only when WP_DEBUG is on (ccAdmin.debug).
 */
document.addEventListener('DOMContentLoaded', function () {
    if (typeof ccAdmin === 'undefined' || !ccAdmin.debug) { return; }
    setTimeout(function () {
        if (!document.body.classList.contains('cc-admin-theme')) { return; }
        var wrap = document.querySelector('#wpbody-content .wrap');
        if (!wrap) { return; }
        var h = wrap.getBoundingClientRect().height;
        if (h < 1) {
            var screenId = (typeof ccAdmin !== 'undefined' && ccAdmin.screenId) ? ccAdmin.screenId : 'unknown';
            var wpbodyContent = document.getElementById('wpbody-content');
            var wpcontent = document.getElementById('wpcontent');
            console.error(
                '[CC] Blank-page tripwire: .wrap has zero height on screen:', screenId,
                '\n  .wrap computed display:', getComputedStyle(wrap).display,
                '\n  #wpbody-content computed display:', wpbodyContent ? getComputedStyle(wpbodyContent).display : 'not found',
                '\n  #wpcontent computed display:', wpcontent ? getComputedStyle(wpcontent).display : 'not found'
            );
        }
    }, 500);
});

// Hard-remove native WP admin footer on CC screens.
document.addEventListener('DOMContentLoaded', function () {
    if (!document.body.classList.contains('cc-admin-theme')) { return; }

    function ccRemoveFooter() {
        var footer = document.getElementById('wpfooter');
        if (footer && footer.parentNode) {
            footer.parentNode.removeChild(footer);
        }

        var wpbodyContent = document.getElementById('wpbody-content');
        var wpcontent = document.getElementById('wpcontent');
        var wpbody = document.getElementById('wpbody');

        [wpbodyContent, wpcontent, wpbody].forEach(function (el) {
            if (!el) { return; }
            el.style.setProperty('padding-bottom', '0', 'important');
            el.style.setProperty('margin-bottom', '0', 'important');
            el.style.setProperty('border-bottom', '0', 'important');
            el.style.setProperty('box-shadow', 'none', 'important');
        });
    }

    ccRemoveFooter();
    window.requestAnimationFrame(ccRemoveFooter);
    window.setTimeout(ccRemoveFooter, 150);
});


jQuery(document).ready(function ($) {
    if ($('body').hasClass('cc-admin-theme')) {
        $('body').removeClass('folded');
    }

    function ccParseRgb(input) {
        if (!input) { return null; }
        var m = String(input).match(/rgba?\(([^)]+)\)/i);
        if (!m) { return null; }
        var parts = m[1].split(',').map(function (v) { return parseFloat(v.trim()); });
        if (parts.length < 3) { return null; }
        return {
            r: Math.max(0, Math.min(255, parts[0] || 0)),
            g: Math.max(0, Math.min(255, parts[1] || 0)),
            b: Math.max(0, Math.min(255, parts[2] || 0)),
            a: parts.length > 3 ? Math.max(0, Math.min(1, parts[3])) : 1
        };
    }

    function ccChannelToLinear(channel) {
        var srgb = channel / 255;
        return srgb <= 0.03928 ? srgb / 12.92 : Math.pow((srgb + 0.055) / 1.055, 2.4);
    }

    function ccLuminance(rgb) {
        return (0.2126 * ccChannelToLinear(rgb.r))
            + (0.7152 * ccChannelToLinear(rgb.g))
            + (0.0722 * ccChannelToLinear(rgb.b));
    }

    function ccContrastRatio(l1, l2) {
        var lighter = Math.max(l1, l2);
        var darker = Math.min(l1, l2);
        return (lighter + 0.05) / (darker + 0.05);
    }

    function ccApplyAutoButtonContrast(root) {
        if (!document.body.classList.contains('cc-admin-theme')) { return; }

        var scope = root && root.querySelectorAll ? root : document;
        var selector = [
            '.cc-button-primary',
            '.page-title-action',
            '.add-new-h2',
            '.wp-core-ui .button-primary',
            'button.button-primary',
            'input.button-primary[type="submit"]'
        ].join(', ');

        var buttons = scope.querySelectorAll(selector);
        var fgLight = '#ffffff';
        var fgDark = '#0f141d';
        var lLight = ccLuminance({ r: 255, g: 255, b: 255 });
        var lDark = ccLuminance({ r: 15, g: 20, b: 29 });

        buttons.forEach(function (btn) {
            var style = window.getComputedStyle(btn);
            var bg = ccParseRgb(style.backgroundColor);
            if (!bg || bg.a < 0.08) { return; }

            var bgLum = ccLuminance(bg);
            var cLight = ccContrastRatio(bgLum, lLight);
            var cDark = ccContrastRatio(bgLum, lDark);
            var chosen = cLight >= cDark ? fgLight : fgDark;

            btn.style.setProperty('color', chosen, 'important');
            btn.style.setProperty('--cc-auto-contrast-fg', chosen);
            btn.querySelectorAll('.dashicons, svg').forEach(function (icon) {
                icon.style.setProperty('color', chosen, 'important');
                icon.style.setProperty('fill', 'currentColor');
                icon.style.setProperty('stroke', 'currentColor');
            });
        });
    }

    ccApplyAutoButtonContrast(document);

    var ccContrastRafPending = false;
    var ccContrastObserver = new MutationObserver(function () {
        if (ccContrastRafPending) { return; }
        ccContrastRafPending = true;
        window.requestAnimationFrame(function () {
            ccContrastRafPending = false;
            ccApplyAutoButtonContrast(document);
        });
    });

    ccContrastObserver.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style']
    });

    function ccNormalizeRowActions() {
        var links = document.querySelectorAll('.wp-list-table .row-actions a');
        links.forEach(function (link) {
            var label = 'Action';
            if (link.closest('.trash') || link.closest('.delete') || link.classList.contains('submitdelete')) {
                label = 'Delete';
            } else if (link.closest('.untrash') || link.closest('.restore')) {
                label = 'Restore';
            } else if (link.closest('.view')) {
                label = 'View';
            } else if (link.closest('.edit') || link.closest('.inline')) {
                label = 'Edit';
            } else if (link.closest('.activate')) {
                label = 'Activate';
            } else if (link.closest('.deactivate')) {
                label = 'Deactivate';
            } else if (link.closest('.approve')) {
                label = 'Approve';
            } else if (link.closest('.unapprove')) {
                label = 'Unapprove';
            } else if (link.closest('.spam')) {
                label = 'Spam';
            }

            link.setAttribute('aria-label', label);
            link.textContent = '';
        });
    }

    function ccInstallRightRowActions() {
        if (!document.body.classList.contains('cc-list-table-screen')) { return; }

        var rows = document.querySelectorAll('.wp-list-table tbody tr');
        rows.forEach(function (row) {
            var cells = row.querySelectorAll('td, th');
            var lastCell = null;
            for (var j = cells.length - 1; j >= 0; j--) {
                if (!cells[j].classList.contains('hidden') && cells[j].style.display !== 'none') {
                    lastCell = cells[j];
                    break;
                }
            }
            if (!lastCell) { return; }
            lastCell.classList.add('cc-last-visible-cell');

            var isPlugins = document.body.classList.contains('plugins-php');

            var actionMap = [
                { selector: '.row-actions .activate a', type: 'activate', label: 'Activate' },
                { selector: '.row-actions .deactivate a', type: 'deactivate', label: 'Deactivate' },
                { selector: '.row-actions .approve a', type: 'approve', label: 'Approve' },
                { selector: '.row-actions .unapprove a', type: 'unapprove', label: 'Unapprove' },
                { selector: '.row-actions .spam a', type: 'spam', label: 'Spam', confirm: true },
                { selector: '.row-actions .untrash a, .row-actions .restore a', type: 'restore', label: 'Restore' },
                { selector: '.row-actions .trash a', type: 'delete', label: 'Trash', confirm: true }
            ];

            if (!isPlugins) {
                actionMap.push({ selector: '.row-actions .delete a, .row-actions .submitdelete', type: 'delete', label: 'Delete' });
            }

            var foundLinks = [];
            var seenTypes = new Set();
            for (var i = 0; i < actionMap.length; i++) {
                if (foundLinks.length >= 5) break; // Max 5 icons visible
                var el = row.querySelector(actionMap[i].selector);
                if (el) {
                    if (seenTypes.has(actionMap[i].type)) { continue; }
                    seenTypes.add(actionMap[i].type);
                    foundLinks.push({ el: el, type: actionMap[i].type, label: actionMap[i].label, confirm: actionMap[i].confirm });
                }
            }

            // Keep only one zone per row and ensure it is attached to the current last visible cell.
            var existingZones = Array.prototype.slice.call(row.querySelectorAll('.cc-row-action-zone'));
            existingZones.forEach(function (z) {
                if (z.parentElement !== lastCell) {
                    z.remove();
                }
            });

            var zone = lastCell.querySelector('.cc-row-action-zone');
            if (foundLinks.length === 0) {
                if (zone) { zone.remove(); }
                existingZones.forEach(function (z) { z.remove(); });
                return;
            }

            if (!zone) {
                zone = document.createElement('span');
                zone.className = 'cc-row-action-zone';
                lastCell.appendChild(zone);
            }

            zone.innerHTML = '';

            foundLinks.forEach(function (found) {
                var clone = found.el.cloneNode(true);
                clone.textContent = '';
                clone.classList.remove('submitdelete'); // Remove unneeded legacy classes from clone
                clone.classList.add('cc-row-action-link', 'cc-action-' + found.type);
                clone.setAttribute('aria-label', found.label);
                if (found.confirm) {
                    clone.dataset.ccConfirmBound = '1';
                }
                zone.appendChild(clone);
            });
        });
    }

    function ccProcessListTableActions() {
        var allowedScreens = [
            'edit-php',
            'users-php',
            'plugins-php',
            'edit-comments-php',
            'upload-php'
        ];

        var isAllowedScreen = allowedScreens.some(function (cls) {
            return document.body.classList.contains(cls);
        });

        if (!isAllowedScreen || !document.querySelector('.wp-list-table') || !document.querySelector('.row-actions')) {
            return;
        }

        ccNormalizeRowActions();
        ccInstallRightRowActions();

        // Reveal row actions after processing to prevent layout jump / double-render
        document.body.classList.add('cc-row-actions-ready');
    }

    if (document.body.classList.contains('cc-list-table-screen')) {
        ccProcessListTableActions();
    }

    // ── Place custom list header below title and directly above list form ───
    (function () {
        if (!document.body.classList.contains('cc-list-table-screen')) { return; }
        var template = document.getElementById('cc-list-header-template');
        var title = document.querySelector('#wpbody-content > .wrap > .wp-heading-inline, #wpbody-content > .wrap > h1');
        if (!title) { return; }

        var wrap = title.closest('.wrap');
        if (!wrap) { return; }

        var targetFormSelector = ':scope > form#posts-filter, :scope > form#users-filter, :scope > form#plugins-filter, :scope > form#comments-form';
        var target = wrap.querySelector(targetFormSelector);
        var resolvedTargetSelector = target ? 'named-form' : '';

        if (!target) {
            var directChildren = Array.prototype.slice.call(wrap.children || []);
            var directForms = directChildren.filter(function (node) {
                return node && node.nodeType === 1 && node.nodeName === 'FORM';
            });
            target = directForms.find(function (formEl) {
                return !!formEl.querySelector('.wp-list-table, table.wp-list-table');
            }) || directForms[0] || null;
            if (target) {
                resolvedTargetSelector = 'direct-form';
            }
        }

        if (!target) {
            target = wrap.querySelector(':scope > .wp-list-table, :scope form .wp-list-table');
            if (target) {
                resolvedTargetSelector = '.wp-list-table';
            }
        }
        if (!target) { return; }

        var headers = Array.prototype.slice.call(document.querySelectorAll('.cc-list-header-row'));
        var header = headers.find(function (row) { return row.closest('.wrap') === wrap; }) || headers[0] || null;
        var insertedFromTemplate = false;

        if (!header && template && ('content' in template)) {
            header = template.content.firstElementChild ? template.content.firstElementChild.cloneNode(true) : null;
            insertedFromTemplate = !!header;
        }
        if (!header) { return; }

        header.setAttribute('data-cc-inserted', '1');

        var leftMount = header.querySelector('.cc-list-header-left');
        if (leftMount) {
            var nativeViews = wrap.querySelector(':scope > ul.subsubsub');
            var existingViews = leftMount.querySelector('.subsubsub');
            var chosenViews = nativeViews || existingViews || null;

            if (chosenViews && chosenViews.parentElement !== leftMount) {
                leftMount.insertBefore(chosenViews, leftMount.firstChild);
            }

            // Keep exactly one filter-pill list in the header-left zone.
            Array.prototype.slice.call(leftMount.querySelectorAll('.subsubsub')).forEach(function (views) {
                if (views !== chosenViews) {
                    views.remove();
                }
            });

            // Remove legacy leftovers (headings/spans) that can inflate toolbar height.
            Array.prototype.slice.call(leftMount.children).forEach(function (child) {
                if (child !== chosenViews) {
                    child.remove();
                }
            });
        }

        if (header.parentElement !== wrap || header.nextElementSibling !== target) {
            wrap.insertBefore(header, target);
        }

        // Keep one canonical row only.
        headers = Array.prototype.slice.call(document.querySelectorAll('.cc-list-header-row'));
        headers.forEach(function (row) {
            if (row !== header) {
                row.remove();
            }
        });

        if (!window.__ccListHeaderPlacementDebugLogged) {
            window.__ccListHeaderPlacementDebugLogged = true;
            var parentClasses = header.parentElement && header.parentElement.classList
                ? Array.prototype.slice.call(header.parentElement.classList).join(' ')
                : '';
            console.log('[CC:list-header] target-form-selector=' + targetFormSelector
                + ' resolved-target=' + resolvedTargetSelector
                + ' target-node=' + (target.matches('form') ? 'form' : '.wp-list-table')
                + ' target-id=' + (target.id || '(none)')
                + ' parent-classes=' + parentClasses
                + ' inserted-from-template=' + (insertedFromTemplate ? '1' : '0')
                + ' node=' + header.outerHTML.slice(0, 120));
        }
    })();

    // ── Ensure active list-view tab is always detectable for styling ─────────
    (function () {
        if (!document.body.classList.contains('cc-list-table-screen')) { return; }

        var items = Array.prototype.slice.call(
            document.querySelectorAll('.cc-list-header-left .subsubsub > li')
        );
        if (!items.length) { return; }

        var links = items.map(function (li) {
            return li.querySelector('a');
        }).filter(function (a) { return !!a; });

        var hasNativeActive = links.some(function (a) {
            return a.classList.contains('current')
                || a.getAttribute('aria-current') === 'page'
                || !!(a.parentElement && a.parentElement.classList.contains('current'));
        });

        if (hasNativeActive) {
            links.forEach(function (a) {
                if (
                    a.classList.contains('current')
                    || a.getAttribute('aria-current') === 'page'
                    || !!(a.parentElement && a.parentElement.classList.contains('current'))
                ) {
                    a.classList.add('cc-active-view');
                    if (a.parentElement) {
                        a.parentElement.classList.add('cc-active-view');
                    }
                }
            });
            return;
        }

        var currentUrl = new URL(window.location.href);
        var currentStatus = (currentUrl.searchParams.get('post_status') || 'all').toLowerCase();

        var matched = false;
        links.forEach(function (a) {
            try {
                var u = new URL(a.href, window.location.origin);
                var linkStatus = (u.searchParams.get('post_status') || 'all').toLowerCase();
                if (linkStatus === currentStatus) {
                    a.classList.add('cc-active-view');
                    if (a.parentElement) {
                        a.parentElement.classList.add('cc-active-view');
                    }
                    matched = true;
                }
            } catch (e) { }
        });

        if (!matched && items[0]) {
            items[0].classList.add('cc-active-view');
            var firstLink = items[0].querySelector('a');
            if (firstLink) {
                firstLink.classList.add('cc-active-view');
            }
        }
    })();

    // ── Columns Dropdown Toggle (PHP injected button) ───────────────────────
    (function () {
        if (!document.body.classList.contains('cc-list-table-screen')) { return; }

        var btn = document.getElementById('cc-filter-btn');
        var screenMeta = document.getElementById('screen-meta');
        var nativeLink = document.getElementById('show-settings-link');

        if (!btn || !screenMeta) { return; }

        function submitScreenOptions() {
            var form = screenMeta.querySelector('form');
            if (!form) { return false; }

            var submitBtn = form.querySelector('input[type="submit"], button[type="submit"], .button-primary');
            if (submitBtn && typeof submitBtn.click === 'function') {
                submitBtn.click();
                return true;
            }

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return true;
            }

            if (typeof form.submit === 'function') {
                form.submit();
                return true;
            }

            return false;
        }

        btn.addEventListener('click', function () {
            var wasOpen = screenMeta.classList.contains('cc-is-open');

            // Clicking "Columns" while open behaves like "Apply".
            if (wasOpen && submitScreenOptions()) {
                return;
            }

            if (nativeLink) {
                nativeLink.click();
            }

            setTimeout(function () {
                var isOpen = false;

                if (nativeLink && nativeLink.classList.contains('screen-meta-active')) {
                    isOpen = true;
                } else {
                    var display = screenMeta.style.display || getComputedStyle(screenMeta).display;
                    if (display === 'block') { isOpen = true; }
                }

                // Fallback: if native screen-options state is unavailable, toggle our own panel state.
                if (!nativeLink) {
                    isOpen = !wasOpen;
                }

                var nextState = isOpen ? 'true' : 'false';
                btn.setAttribute('aria-expanded', nextState);

                if (isOpen) {
                    screenMeta.classList.add('cc-is-open');
                } else {
                    screenMeta.classList.remove('cc-is-open');
                }
            }, 50);
        });
    })();

    // ── Row Action Navigation Lock (delete/restore/spam) ───────────────────────
    document.addEventListener('click', function (event) {
        var link = event.target.closest('.wp-list-table .row-actions .trash a, .wp-list-table .row-actions .delete a, .wp-list-table .row-actions .submitdelete, .wp-list-table .row-actions .spam a, .wp-list-table .cc-row-action-zone .cc-row-action-link');
        if (!link) { return; }

        var href = link.getAttribute('href');
        if (!href) { return; }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) { return; }

        var requiresConfirm = link.dataset.ccConfirmBound === '1' || link.closest('.row-actions .trash a') || link.closest('.row-actions .spam a');
        if (requiresConfirm) {
            var actionText = link.classList.contains('cc-action-spam') || link.closest('.spam a') ? 'mark this as spam' : 'move this to trash';
            if (!window.confirm('Are you sure you want to ' + actionText + '?')) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
        }

        // Force deterministic navigation so conflicting legacy handlers cannot block row actions.
        event.preventDefault();
        event.stopPropagation();
        window.location.assign(href);
    }, true);

    var fileFrame;

    // --- Media Uploader Logic ---
    $(document).on('click', '.cc-media-upload-btn', function (e) {
        e.preventDefault();
        var button = $(this);
        var wrapper = button.closest('.cc-media-uploader');
        var type = wrapper.data('type');

        if (typeof wp === 'undefined' || !wp.media) {
            console.error('Content Core: wp.media is not defined.');
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

            // Trigger a refresh/re-render of the preview wrapper via AJAX or just manual DOM swap
            // For now, let's manually build the preview to match the PHP output
            var previewWrapper = wrapper.find('.cc-image-preview-wrapper');
            var html = '';

            if ('image' === type) {
                var url = attachment.url;
                if (attachment.sizes) {
                    if (attachment.sizes.large) url = attachment.sizes.large.url;
                    else if (attachment.sizes.medium) url = attachment.sizes.medium.url;
                    else if (attachment.sizes.thumbnail) url = attachment.sizes.thumbnail.url;
                }
                html = '<img src="' + url + '" class="cc-image-preview" />';
                html += '<div class="cc-media-actions">';
                html += '<button type="button" class="button cc-media-upload-btn">Replace</button>';
                html += '<button type="button" class="button cc-media-remove-btn">Remove</button>';
                html += '</div>';
            } else {
                var ext = attachment.filename.split('.').pop().toUpperCase();
                var size = attachment.filesizeHumanReadable || '';
                html = '<div class="cc-file-card">';
                html += '<div class="cc-file-icon"><span class="dashicons dashicons-media-document"></span></div>';
                html += '<div class="cc-file-info">';
                html += '<span class="cc-file-name">' + attachment.filename + '</span>';
                html += '<span class="cc-file-meta">' + ext + (size ? ' &bull; ' + size : '') + '</span>';
                html += '</div>';
                html += '<div class="cc-file-actions">';
                html += '<button type="button" class="button cc-media-upload-btn">Replace</button>';
                html += '<button type="button" class="button cc-media-remove-btn">Remove</button>';
                html += '</div>';
                html += '</div>';
            }

            previewWrapper.html(html);
        }).open();
    });

    $(document).on('click', '.cc-media-remove-btn', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var wrapper = $(this).closest('.cc-media-uploader');
        var type = wrapper.data('type');
        wrapper.find('.cc-media-id-input').val('');

        // Restore placeholder
        var placeholderHtml = '<div class="cc-media-placeholder">';
        placeholderHtml += '<i class="dashicons dashicons-' + ('image' === type ? 'format-image' : 'media-default') + '"></i>';
        placeholderHtml += '<span>' + ('image' === type ? 'Select Image' : 'Select File') + '</span>';
        placeholderHtml += '<button type="button" class="button button-secondary">Choose</button>';
        placeholderHtml += '</div>';

        wrapper.find('.cc-image-preview-wrapper').html(placeholderHtml);
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
    function ccGalleryAddTileHtml() {
        return '' +
            '<button type="button" class="cc-gallery-add cc-gallery-add-btn" aria-label="Add images">' +
            '<i class="dashicons dashicons-plus"></i>' +
            '<span>Add</span>' +
            '</button>';
    }

    function ccEnsureGalleryAddTile($wrapper) {
        if (!$wrapper || !$wrapper.length) {
            return;
        }

        var $grid = $wrapper.find('.cc-gallery-grid').first();
        if (!$grid.length) {
            return;
        }

        if (!$grid.find('.cc-gallery-add-btn').length) {
            $grid.append(ccGalleryAddTileHtml());
        }
    }

    $('.cc-gallery-container').each(function () {
        ccEnsureGalleryAddTile($(this));
    });

    $(document).on('click', '.cc-gallery-add-btn', function (e) {
        e.preventDefault();
        var wrapper = $(this).closest('.cc-gallery-container');
        var input = wrapper.find('.cc-gallery-input');
        var grid = wrapper.find('.cc-gallery-grid');
        ccEnsureGalleryAddTile(wrapper);
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
                    var itemHtml = '<div class="cc-gallery-item" data-id="' + attachment.id + '">';
                    itemHtml += '<img src="' + thumb + '">';
                    itemHtml += '<button type="button" class="cc-gallery-remove"><span class="dashicons dashicons-no-alt"></span></button>';
                    itemHtml += '</div>';
                    // Insert before the "Add" tile
                    grid.find('.cc-gallery-add-btn').first().before(itemHtml);
                }
            });
            input.val(JSON.stringify(ids));
        }).open();
    });

    $(document).on('click', '.cc-gallery-remove', function () {
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
        ccEnsureGalleryAddTile(wrapper);
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
        $('.cc-editor-section').each(function () {
            var $section = $(this);
            var sectionId = $section.data('section-id');
            var isCollapsible = $section.hasClass('is-collapsible');

            if (isCollapsible) {
                var savedState = localStorage.getItem('cc_section_' + sectionId);
                if (savedState === 'collapsed') {
                    $section.removeClass('is-open');
                } else if (savedState === 'expanded') {
                    $section.addClass('is-open');
                }
            } else {
                $section.addClass('is-open');
            }
        });
    }

    $(document).on('click', '.cc-editor-section.is-collapsible .cc-editor-section-header', function () {
        var $section = $(this).closest('.cc-editor-section');
        var sectionId = $section.data('section-id');
        $section.toggleClass('is-open');
        var isOpen = $section.hasClass('is-open');

        localStorage.setItem('cc_section_' + sectionId, isOpen ? 'expanded' : 'collapsed');
    });

    initSections();

    /**
     * Editor Header Actions
     */
    $(document).on('click', '.cc-editor-publish-trigger', function (e) {
        e.preventDefault();
        ccSetEditorSaveStatus('saving', 'Saving...');
        $('#publish').trigger('click');
    });

    $(document).on('click', '.cc-editor-preview-trigger', function (e) {
        e.preventDefault();
        $('#post-preview').trigger('click');
    });

    function initHeaderLanguageSync() {
        var $headerSelect = $('[data-cc-header-language-select]');
        if (!$headerSelect.length) return;

        var $languageField = $('#cc-language-box [name="cc_language"]');
        if ($languageField.length) {
            var currentValue = String($languageField.first().val() || '');
            if (currentValue !== '') {
                $headerSelect.val(currentValue);
            }
        }

        $headerSelect.off('change.ccHeaderLanguage').on('change.ccHeaderLanguage', function () {
            var $selected = $(this).find('option:selected');
            var targetUrl = String($selected.attr('data-target') || '').trim();

            if (targetUrl !== '') {
                var currentNoHash = String(window.location.href || '').split('#')[0];
                var targetNoHash = targetUrl.split('#')[0];
                if (targetNoHash !== '' && targetNoHash !== currentNoHash) {
                    window.location.assign(targetUrl);
                    return;
                }
            }

            var nextValue = String($(this).val() || '');
            if ($languageField.length) {
                $languageField.val(nextValue).trigger('change');
            }
        });
    }

    function ccSetEditorSaveStatus(state, label) {
        var statusContainer = $('.cc-editor-save-status');
        if (!statusContainer.length) return;

        var classesToRemove = 'is-saved is-saving is-error is-failed is-draft is-warning';
        statusContainer.removeClass(classesToRemove);
        statusContainer.addClass('is-' + state);
        statusContainer.text(label);
    }

    function updateEditorSaveStatus() {
        var statusContainer = $('.cc-editor-save-status');
        if (!statusContainer.length) return;

        var statusText = ($('#post-status-display').text() || '').trim().toLowerCase();
        var hasVisibleError = $('#poststuff .notice-error:visible, #poststuff .error:visible, #poststuff .form-invalid:visible').length > 0;
        var hasSuccessNotice = $('#wpbody-content .notice-success, #wpbody-content .updated, #wpbody-content #message.updated').length > 0;
        var isSaving = $('#publishing-action .spinner.is-active, #save-action .spinner.is-active, #ajax-loading').is(':visible');
        var isNewDraft = $('body').hasClass('post-new-php') && ($('#post_ID').val() === '0' || statusText.indexOf('auto') !== -1);
        var isPublished = statusText.indexOf('publish') !== -1 || statusText.indexOf('veröffentlich') !== -1;

        if (hasVisibleError) {
            ccSetEditorSaveStatus('failed', 'Failed');
            return;
        }

        if (isSaving) {
            ccSetEditorSaveStatus('saving', 'Saving...');
            return;
        }

        if (statusText.indexOf('scheduled') !== -1 || statusText.indexOf('geplant') !== -1) {
            ccSetEditorSaveStatus('warning', 'Scheduled');
            return;
        }

        if (statusText.indexOf('pending') !== -1) {
            ccSetEditorSaveStatus('warning', 'Pending review');
            return;
        }

        if (statusText.indexOf('draft') !== -1 || statusText.indexOf('entwurf') !== -1 || isNewDraft) {
            ccSetEditorSaveStatus('draft', 'Draft');
            return;
        }

        if (isPublished) {
            ccSetEditorSaveStatus('saved', 'Published');
            return;
        }

        if (hasSuccessNotice) {
            ccSetEditorSaveStatus('saved', 'Saved');
            return;
        }

        ccSetEditorSaveStatus('saved', 'Saved');
    }

    // Monitor changes to sync button labels or saved states
    var editorObserver = new MutationObserver(function () {
        updateEditorSaveStatus();

        // Update Publish button label if WP changes it (e.g. Schedule -> Update)
        var wpBtn = $('#publish');
        var ccBtn = $('.cc-editor-publish-trigger');
        if (wpBtn.length && ccBtn.length) {
            var label = wpBtn.val() || wpBtn.text();
            if (label && ccBtn.text() !== label) {
                ccBtn.text(label);
            }
        }
    });

    var publishActions = document.getElementById('publish-actions');
    if (publishActions) {
        editorObserver.observe(publishActions, { childList: true, subtree: true, attributes: true });
    }

    updateEditorSaveStatus();
    initHeaderLanguageSync();
    $(window).on('load', updateEditorSaveStatus);

    /**
     * Disable WP metabox reordering for classic CC edit screens.
     * Keep collapse/open behavior intact; only drag-reorder is blocked.
     */
    function ccDisableMetaboxReorder() {
        var $body = $('body');
        if (!$body.hasClass('cc-post-edit-screen') || $body.hasClass('block-editor-page')) {
            return;
        }

        if (!$.fn.sortable) {
            return;
        }

        $('.meta-box-sortables').each(function () {
            var $sortable = $(this);
            if (!$sortable.hasClass('ui-sortable')) {
                return;
            }

            try {
                $sortable.sortable('option', 'disabled', true);
            } catch (err) {
                // Ignore if sortable is not initialized yet.
            }
        });
    }

    ccDisableMetaboxReorder();
    $(window).on('load', function () {
        ccDisableMetaboxReorder();
        window.setTimeout(ccDisableMetaboxReorder, 200);
    });

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

    function initUploadModalArrows() {
        if (!document.body.classList.contains('upload-php')) return;
        var transparencyCheckToken = 0;

        function enforceFieldOrder(modal) {
            if (!modal) return;
            var info = modal.querySelector('.attachment-details .attachment-info');
            if (!info) return;

            var titleSetting = info.querySelector('.setting[data-setting="title"]');
            var altSetting = info.querySelector('.setting[data-setting="alt"], .setting.alt-text');
            if (!titleSetting || !altSetting) return;

            var titlePos = titleSetting.compareDocumentPosition(altSetting);
            if (titlePos & Node.DOCUMENT_POSITION_PRECEDING) {
                titleSetting.parentNode.insertBefore(titleSetting, altSetting);
            }
        }

        function getModal() {
            var modals = Array.prototype.slice.call(document.querySelectorAll('body.upload-php .media-modal, .media-modal'));
            for (var i = 0; i < modals.length; i += 1) {
                var modal = modals[i];
                var style = window.getComputedStyle(modal);
                if (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0') {
                    return modal;
                }
            }
            return modals.length ? modals[0] : null;
        }

        function enforceModalCenter(modal) {
            if (!modal) return;
            var isCompactViewport = window.matchMedia('(max-width: 1100px)').matches;

            modal.style.setProperty('position', 'fixed', 'important');
            modal.style.setProperty('top', '50%', 'important');
            modal.style.setProperty('left', '50%', 'important');
            modal.style.setProperty('right', 'auto', 'important');
            modal.style.setProperty('bottom', 'auto', 'important');
            modal.style.setProperty('transform', 'translate(-50%, -50%)', 'important');
            modal.style.setProperty('margin', '0', 'important');
            modal.style.setProperty('width', isCompactViewport ? 'min(960px, 92vw)' : 'min(1240px, 88vw)', 'important');
            modal.style.setProperty('max-width', isCompactViewport ? 'min(960px, 92vw)' : 'min(1240px, 88vw)', 'important');
            modal.style.setProperty('min-width', isCompactViewport ? '0' : '760px', 'important');
            modal.style.setProperty('height', isCompactViewport ? 'auto' : 'min(740px, calc(100vh - 72px))', 'important');
            modal.style.setProperty('max-height', isCompactViewport ? '88vh' : 'calc(100vh - 72px)', 'important');
            modal.style.setProperty('border-radius', '18px', 'important');
            modal.style.setProperty('overflow', 'hidden', 'important');

            var content = modal.querySelector('.media-modal-content');
            if (content) {
                content.style.setProperty('width', '100%', 'important');
                content.style.setProperty('height', '100%', 'important');
                content.style.setProperty('max-height', '100%', 'important');
            }
        }

        function getAttachments() {
            return Array.prototype.slice.call(document.querySelectorAll('#wpbody-content .media-frame .attachments .attachment'));
        }

        function getSelectedIndex(items) {
            for (var i = 0; i < items.length; i += 1) {
                var el = items[i];
                if (
                    el.classList.contains('selected') ||
                    el.classList.contains('details') ||
                    el.getAttribute('aria-selected') === 'true' ||
                    el.getAttribute('aria-checked') === 'true'
                ) {
                    return i;
                }
            }
            return -1;
        }

        function getWpMediaMeta() {
            if (!window.wp || !wp.media || !wp.media.frame || !wp.media.frame.state) return null;
            var state = wp.media.frame.state();
            if (!state || !state.get) return null;
            var selection = state.get('selection');
            var library = state.get('library');
            if (!selection || !library || !library.length) return null;

            var current = selection.first ? selection.first() : null;
            var index = current ? library.indexOf(current) : -1;
            if (index < 0) index = 0;

            return {
                selection: selection,
                library: library,
                count: library.length,
                index: index
            };
        }

        function isAlphaCandidate(modal, mediaMeta) {
            var current = mediaMeta && mediaMeta.selection && mediaMeta.selection.first ? mediaMeta.selection.first() : null;
            if (current && current.get) {
                var subtype = String(current.get('subtype') || '').toLowerCase();
                var mime = String(current.get('mime') || '').toLowerCase();
                var filename = String(current.get('filename') || current.get('name') || '').toLowerCase();
                if (
                    subtype === 'png' ||
                    subtype === 'webp' ||
                    subtype === 'avif' ||
                    subtype === 'gif' ||
                    mime === 'image/png' ||
                    mime === 'image/webp' ||
                    mime === 'image/avif' ||
                    mime === 'image/gif' ||
                    /\.png$/.test(filename) ||
                    /\.webp$/.test(filename) ||
                    /\.avif$/.test(filename) ||
                    /\.gif$/.test(filename)
                ) {
                    return true;
                }
            }

            var img = modal ? modal.querySelector('.attachment-details .attachment-media-view img') : null;
            if (!img) return false;
            var src = String(img.getAttribute('src') || '').toLowerCase();
            return (
                src.indexOf('.png') !== -1 ||
                src.indexOf('.webp') !== -1 ||
                src.indexOf('.avif') !== -1 ||
                src.indexOf('.gif') !== -1
            );
        }

        function hasTransparentPixel(img) {
            if (!img || !img.naturalWidth || !img.naturalHeight) return false;
            try {
                var sampleW = Math.min(96, img.naturalWidth);
                var sampleH = Math.min(96, img.naturalHeight);
                var canvas = document.createElement('canvas');
                canvas.width = sampleW;
                canvas.height = sampleH;
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                if (!ctx) return false;

                ctx.drawImage(img, 0, 0, sampleW, sampleH);
                var data = ctx.getImageData(0, 0, sampleW, sampleH).data;
                for (var i = 3; i < data.length; i += 4) {
                    if (data[i] < 250) return true;
                }
            } catch (err) {
                return false;
            }
            return false;
        }

        function syncTransparency(modal, mediaMeta) {
            if (!modal) return;
            modal.classList.remove('cc-media-is-transparent-png');
            modal.classList.remove('cc-media-has-alpha');
            if (!isAlphaCandidate(modal, mediaMeta)) return;

            var img = modal.querySelector('.attachment-details .attachment-media-view img');
            if (!img) return;

            var token = ++transparencyCheckToken;
            var apply = function () {
                if (token !== transparencyCheckToken) return;
                var hasAlpha = hasTransparentPixel(img);
                modal.classList.toggle('cc-media-is-transparent-png', hasAlpha);
                modal.classList.toggle('cc-media-has-alpha', hasAlpha);
            };

            if (img.complete && img.naturalWidth > 0 && img.naturalHeight > 0) {
                apply();
            } else {
                img.addEventListener('load', apply, { once: true });
            }
        }

        function styleNavButton(btn, rightPx) {
            if (!btn) return;
            btn.style.setProperty('position', 'absolute', 'important');
            btn.style.setProperty('top', '16px', 'important');
            btn.style.setProperty('right', rightPx + 'px', 'important');
            btn.style.setProperty('left', 'auto', 'important');
            btn.style.setProperty('width', '32px', 'important');
            btn.style.setProperty('height', '32px', 'important');
            btn.style.setProperty('min-width', '32px', 'important');
            btn.style.setProperty('min-height', '32px', 'important');
            btn.style.setProperty('border-radius', '999px', 'important');
            btn.style.setProperty('display', 'inline-flex', 'important');
            btn.style.setProperty('align-items', 'center', 'important');
            btn.style.setProperty('justify-content', 'center', 'important');
            btn.style.setProperty('z-index', '160005', 'important');
            btn.style.setProperty('visibility', 'visible', 'important');
            btn.style.setProperty('overflow', 'hidden', 'important');
        }

        function syncArrows() {
            var modal = getModal();
            if (!modal) return;

            enforceModalCenter(modal);
            enforceFieldOrder(modal);

            var mediaMeta = getWpMediaMeta();
            syncTransparency(modal, mediaMeta);
            var items = getAttachments();
            var domCount = items.length;
            var mediaCount = mediaMeta ? mediaMeta.count : 0;
            var count = Math.max(mediaCount, domCount);
            var hasMany = count > 1;

            // Remove legacy injected custom buttons.
            modal.querySelectorAll('button.cc-media-nav-btn').forEach(function (btn) {
                if (!btn.classList.contains('left') && !btn.classList.contains('right')) {
                    btn.remove();
                }
            });

            var prev = modal.querySelector('button.media-modal-close.left, button.media-modal-prev');
            var next = modal.querySelector('button.media-modal-close.right, button.media-modal-next');
            var close = modal.querySelector('.media-modal-close:not(.left):not(.right)');

            styleNavButton(prev, 108);
            styleNavButton(next, 66);

            if (close) {
                close.style.setProperty('top', '16px', 'important');
                close.style.setProperty('right', '18px', 'important');
            }

            if (prev) prev.style.display = hasMany ? 'inline-flex' : 'none';
            if (next) next.style.display = hasMany ? 'inline-flex' : 'none';
        }

        var rafScheduled = false;
        function scheduleSync() {
            if (rafScheduled) return;
            rafScheduled = true;
            window.requestAnimationFrame(function () {
                rafScheduled = false;
                syncArrows();
            });
        }

        var observer = new MutationObserver(scheduleSync);
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'aria-selected', 'aria-checked']
        });

        document.addEventListener('click', function (e) {
            if (
                e.target.closest('.attachments .attachment') ||
                e.target.closest('.media-modal-close.left') ||
                e.target.closest('.media-modal-close.right') ||
                e.target.closest('.media-modal-prev') ||
                e.target.closest('.media-modal-next')
            ) {
                window.setTimeout(scheduleSync, 20);
            }
        }, true);

        scheduleSync();
        window.setTimeout(scheduleSync, 200);
        window.setTimeout(scheduleSync, 500);
    }

    initSidebarCollapse();
    initSidebarSectionLabels();
    initTopLevelMenuToggle();
    initDarkModeToggle();
    initUploadModalArrows();

    // initUnifiedListTopbar() & initUnifiedListTableDelete() removed per new scoped CSS architecture.

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

    initListDateFormatter();
});

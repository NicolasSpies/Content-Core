/**
 * Content Core — Manage Multilingual Terms
 * REST-based, no admin-ajax.
 *
 * Globals injected via wp_localize_script:
 *   ccTermsManager.restBase   — e.g. https://site.local/wp-json/content-core/v1/terms-manager
 *   ccTermsManager.nonce      — WP REST nonce
 *   ccTermsManager.languages  — { code: label } ordered map
 *   ccTermsManager.default    — default language code
 */
(function ($) {
    'use strict';

    const cfg = window.ccTermsManager || {};
    const REST = cfg.restBase || '';
    const LANGS = cfg.languages || {};
    const DEFAULT = cfg.default || 'de';
    const LANG_KEYS = Object.keys(LANGS); // ordered: default first

    let pendingDelete = null; // { groupId, groupLabel, taxonomy }

    // ── Fetch helper ─────────────────────────────────────────────────────────

    function apiFetch(path, method, body) {
        const opts = {
            method: method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce,
            },
            credentials: 'same-origin',
        };
        if (body) {
            opts.body = JSON.stringify(body);
        }
        return fetch(REST + path, opts).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok) {
                    return Promise.reject(data.error || data.message || 'Server error ' + res.status);
                }
                return data;
            });
        });
    }

    // ── Notice helper ─────────────────────────────────────────────────────────

    function showNotice(msg, type) {
        const $n = $('#cc-tm-notice');
        $n.text(msg)
            .removeClass('notice-success notice-error cc-tm-notice-inline')
            .addClass(type === 'error' ? 'notice-error' : 'notice-success')
            .show();
        clearTimeout($n.data('timer'));
        $n.data('timer', setTimeout(function () { $n.fadeOut(); }, 4000));
    }

    // ── Accordion State ───────────────────────────────────────────────────────

    const STORAGE_KEY = 'cc-tm-open-taxonomy';

    function getOpenTaxonomy() {
        return localStorage.getItem(STORAGE_KEY) || null;
    }

    function setOpenTaxonomy(tax) {
        if (tax) {
            localStorage.setItem(STORAGE_KEY, tax);
        } else {
            localStorage.removeItem(STORAGE_KEY);
        }
    }

    // ── Data loading & rendering ──────────────────────────────────────────────

    function loadAllTaxonomies() {
        const $container = $('#cc-tm-accordions');
        $container.html('<p>Loading taxonomies...</p>');

        apiFetch('/all-taxonomy-groups')
            .then(function (taxes) {
                renderAccordions(taxes);
            })
            .catch(function (err) {
                $container.html('<p class="cc-tm-error">Error loading taxonomies: ' + escHtml(String(err)) + '</p>');
            });
    }

    function renderAccordions(taxes) {
        const $container = $('#cc-tm-accordions');
        $container.empty();

        if (!taxes || taxes.length === 0) {
            $container.html('<p>No translatable taxonomies found.</p>');
            return;
        }

        let openTax = getOpenTaxonomy();
        // If nothing stored, open first one
        if (!openTax && taxes.length > 0) {
            openTax = taxes[0].taxonomy;
            setOpenTaxonomy(openTax);
        }

        taxes.forEach(function (taxObj) {
            const tax = taxObj.taxonomy;
            const label = taxObj.label;
            const groups = taxObj.groups;

            const isOpen = (tax === openTax);
            const style = isOpen ? '' : 'display:none;';
            const iconClass = isOpen ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2';
            const expandedClass = isOpen ? 'cc-tm-accordion-open' : '';

            let theadHtml = '<tr><th class="cc-tm-drag-col"></th><th>Group</th>';
            LANG_KEYS.forEach(function (code) {
                theadHtml += '<th class="cc-tm-lang-col">' + escHtml(code.toUpperCase()) + '</th>';
            });
            theadHtml += '<th>Actions</th></tr>';

            let tbodyHtml = '';
            if (!groups || groups.length === 0) {
                tbodyHtml = '<tr><td colspan="' + (3 + LANG_KEYS.length) + '">' +
                    '<em>No terms found. Create a term above to get started.</em></td></tr>';
            } else {
                groups.forEach(function (group) {
                    tbodyHtml += buildRowHtml(group, tax);
                });
            }

            const html = `
                <div class="cc-tm-accordion ${expandedClass}" data-taxonomy="${escAttr(tax)}">
                    <div class="cc-tm-accordion-header">
                        <h3 class="cc-tm-accordion-title">${escHtml(label)} <span class="cc-tm-tax-slug">(${escHtml(tax)})</span></h3>
                        <span class="cc-tm-accordion-icon dashicons ${iconClass}"></span>
                    </div>
                    <div class="cc-tm-accordion-body" style="${style}">
                        <div class="cc-tm-toolbar-inner">
                            <input type="text" class="cc-tm-new-name" placeholder="New term name…">
                            <button class="cc-tm-create-btn button button-primary">Create Term</button>
                        </div>
                        <div class="cc-tm-table-wrap">
                            <table class="cc-tm-table widefat">
                                <thead>${theadHtml}</thead>
                                <tbody class="cc-tm-tbody">${tbodyHtml}</tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
            $container.append(html);
        });

        initSortable();
    }

    function buildRowHtml(group, taxonomy) {
        const gid = group.group_id || '';
        const trans = group.translations || {};

        const defTerm = trans[DEFAULT];
        const anyTerm = Object.values(trans)[0];
        const label = defTerm ? defTerm.name : (anyTerm ? anyTerm.name : '—');

        let cols = '';

        LANG_KEYS.forEach(function (lang) {
            const t = trans[lang];
            if (t) {
                cols += '<td class="cc-tm-cell cc-tm-has-term" data-lang="' + escAttr(lang) + '">' +
                    '<span class="cc-tm-term-name">' +
                    escHtml(t.name) +
                    '</span>' +
                    ' <button class="cc-tm-rename-btn button button-small" ' +
                    'data-term-id="' + t.id + '" ' +
                    'data-name="' + escAttr(t.name) + '" ' +
                    'data-taxonomy="' + escAttr(taxonomy) + '">' +
                    'Edit' +
                    '</button>' +
                    ' <button class="cc-tm-remove-btn button button-small cc-btn-danger" ' +
                    'data-term-id="' + t.id + '" ' +
                    'data-taxonomy="' + escAttr(taxonomy) + '">' +
                    'Remove' +
                    '</button>' +
                    '</td>';
            } else {
                cols += '<td class="cc-tm-cell cc-tm-missing" data-lang="' + escAttr(lang) + '">' +
                    '<button class="cc-tm-add-translation-btn button button-small" ' +
                    'data-lang="' + escAttr(lang) + '" ' +
                    'data-group-id="' + escAttr(gid) + '" ' +
                    'data-taxonomy="' + escAttr(taxonomy) + '">' +
                    '+' + lang.toUpperCase() +
                    '</button>' +
                    '</td>';
            }
        });

        return '<tr class="cc-tm-row" data-group-id="' + escAttr(gid) + '">' +
            '<td class="cc-tm-drag-handle"><span class="dashicons dashicons-menu"></span></td>' +
            '<td class="cc-tm-group-label">' + escHtml(label) + '</td>' +
            cols +
            '<td class="cc-tm-actions">' +
            '<button class="cc-tm-delete-group-btn button cc-btn-destructive" ' +
            'data-group-id="' + escAttr(gid) + '" ' +
            'data-group-label="' + escAttr(label) + '" ' +
            'data-taxonomy="' + escAttr(taxonomy) + '">' +
            'Delete Group' +
            '</button>' +
            '</td>' +
            '</tr>';
    }

    // ── Sortable ──────────────────────────────────────────────────────────────

    function initSortable() {
        const $tbodies = $('.cc-tm-tbody');
        if (!$tbodies.sortable) return;

        $tbodies.sortable({
            handle: '.cc-tm-drag-handle',
            axis: 'y',
            cursor: 'grabbing',
            update: function (e, ui) {
                const $acc = ui.item.closest('.cc-tm-accordion');
                const taxonomy = $acc.data('taxonomy');
                saveOrder($acc, taxonomy);
            },
        });
    }

    function saveOrder($acc, taxonomy) {
        const orderData = [];
        $acc.find('tr.cc-tm-row').each(function (i) {
            const gid = $(this).data('group-id');
            $(this).find('[data-term-id]').each(function () {
                orderData.push({ term_id: $(this).data('term-id'), order: i });
            });
        });

        if (!orderData.length) return;

        apiFetch('/reorder', 'POST', { taxonomy: taxonomy, order: orderData })
            .catch(function (err) {
                showNotice('Reorder failed: ' + err, 'error');
            });
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    function handleCreate($btn) {
        const $acc = $btn.closest('.cc-tm-accordion');
        const taxonomy = $acc.data('taxonomy');
        const $input = $acc.find('.cc-tm-new-name');
        const name = $.trim($input.val());

        if (!name) {
            showNotice('Please enter a term name.', 'error');
            return;
        }

        $btn.prop('disabled', true).text('Creating…');

        apiFetch('/create', 'POST', { taxonomy: taxonomy, name: name, lang: DEFAULT })
            .then(function () {
                $input.val('');
                showNotice('Term "' + escHtml(name) + '" created.', 'success');
                loadAllTaxonomies();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
            })
            .finally(function () {
                $btn.prop('disabled', false).text('Create Term');
            });
    }

    function handleAddTranslation($btn) {
        const lang = $btn.data('lang');
        const groupId = $btn.data('group-id');
        const taxonomy = $btn.data('taxonomy');

        const name = window.prompt(
            'Enter translation name for ' + lang.toUpperCase() + ':',
            ''
        );
        if (!name || !name.trim()) return;

        $btn.prop('disabled', true).text('Adding…');

        apiFetch('/translate', 'POST', {
            taxonomy: taxonomy,
            name: name.trim(),
            lang: lang,
            group_id: groupId,
        })
            .then(function () {
                showNotice('Translation added.', 'success');
                loadAllTaxonomies();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('+' + lang.toUpperCase());
            });
    }

    function handleRename($btn) {
        const termId = $btn.data('term-id');
        const taxonomy = $btn.data('taxonomy');
        const current = $btn.data('name');

        const name = window.prompt('Rename term:', current);
        if (!name || !name.trim() || name.trim() === current) return;

        $btn.prop('disabled', true).text('Saving…');

        apiFetch('/rename', 'POST', {
            term_id: termId,
            taxonomy: taxonomy,
            name: name.trim(),
        })
            .then(function () {
                showNotice('Term renamed.', 'success');
                loadAllTaxonomies();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('Edit');
            });
    }

    function handleRemove($btn) {
        const termId = $btn.data('term-id');
        const taxonomy = $btn.data('taxonomy');

        if (!window.confirm('Remove this translation? This cannot be undone.')) return;

        $btn.prop('disabled', true).text('Removing…');

        apiFetch('/remove', 'POST', { term_id: termId, taxonomy: taxonomy })
            .then(function () {
                showNotice('Translation removed.', 'success');
                loadAllTaxonomies();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('Remove');
            });
    }

    function handleDeleteGroup($btn) {
        pendingDelete = {
            groupId: $btn.data('group-id'),
            groupLabel: $btn.data('group-label'),
            taxonomy: $btn.data('taxonomy'),
        };

        $('#cc-tm-modal-msg').text(
            'This will permanently delete all translations in group "' +
            pendingDelete.groupLabel + '". Are you sure?'
        );
        $('#cc-tm-modal').show();
    }

    function confirmDeleteGroup() {
        if (!pendingDelete) return;

        const $confirm = $('#cc-tm-modal-confirm').prop('disabled', true).text('Deleting…');

        apiFetch('/delete-group', 'POST', {
            taxonomy: pendingDelete.taxonomy,
            group_id: pendingDelete.groupId,
        })
            .then(function (data) {
                closeModal();
                showNotice('Group deleted (' + (data.deleted || []).length + ' term(s) removed).', 'success');
                loadAllTaxonomies();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                closeModal();
            })
            .finally(function () {
                pendingDelete = null;
                $confirm.prop('disabled', false).text('Delete');
            });
    }

    function closeModal() {
        $('#cc-tm-modal').hide();
        pendingDelete = null;
    }

    // ── Utils ─────────────────────────────────────────────────────────────────

    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escAttr(s) {
        return escHtml(s);
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    $(function () {
        loadAllTaxonomies();

        // Accordion toggle logic
        $('#cc-tm-accordions').on('click', '.cc-tm-accordion-header', function () {
            const $acc = $(this).closest('.cc-tm-accordion');
            const tax = $acc.data('taxonomy');
            const $body = $acc.find('.cc-tm-accordion-body');
            const $icon = $(this).find('.cc-tm-accordion-icon');

            if ($body.is(':visible')) {
                // close it
                $body.slideUp(200);
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $acc.removeClass('cc-tm-accordion-open');
                if (getOpenTaxonomy() === tax) setOpenTaxonomy(null);
            } else {
                // open it (close others)
                $('.cc-tm-accordion-body').slideUp(200);
                $('.cc-tm-accordion-icon').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $('.cc-tm-accordion').removeClass('cc-tm-accordion-open');

                $body.slideDown(200);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $acc.addClass('cc-tm-accordion-open');
                setOpenTaxonomy(tax);
            }
        });

        // Create
        $('#cc-tm-accordions').on('click', '.cc-tm-create-btn', function () {
            handleCreate($(this));
        });
        $('#cc-tm-accordions').on('keydown', '.cc-tm-new-name', function (e) {
            if (e.key === 'Enter') handleCreate($(this).next('.cc-tm-create-btn'));
        });

        // Add translation
        $('#cc-tm-accordions').on('click', '.cc-tm-add-translation-btn', function () {
            handleAddTranslation($(this));
        });

        // Rename
        $('#cc-tm-accordions').on('click', '.cc-tm-rename-btn', function () {
            handleRename($(this));
        });

        // Remove
        $('#cc-tm-accordions').on('click', '.cc-tm-remove-btn', function () {
            handleRemove($(this));
        });

        // Delete group
        $('#cc-tm-accordions').on('click', '.cc-tm-delete-group-btn', function () {
            handleDeleteGroup($(this));
        });

        // Modal buttons
        $('#cc-tm-modal-confirm').on('click', confirmDeleteGroup);
        $('#cc-tm-modal-cancel').on('click', closeModal);
        $('#cc-tm-modal').on('click', function (e) {
            if ($(e.target).is('#cc-tm-modal')) closeModal();
        });
    });

})(jQuery);

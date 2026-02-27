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

    let currentTaxonomy = '';
    let pendingDelete = null; // { groupId, groupLabel }

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
            .removeClass('notice-success notice-error')
            .addClass(type === 'error' ? 'notice-error' : 'notice-success')
            .show();
        clearTimeout($n.data('timer'));
        $n.data('timer', setTimeout(function () { $n.fadeOut(); }, 4000));
    }

    // ── Table rendering ───────────────────────────────────────────────────────

    function loadGroups() {
        const $tbody = $('#cc-tm-tbody');
        $tbody.html('<tr class="cc-tm-loading"><td colspan="' + (3 + LANG_KEYS.length) + '">Loading…</td></tr>');

        apiFetch('/groups?taxonomy=' + encodeURIComponent(currentTaxonomy))
            .then(function (groups) {
                renderTable(groups);
            })
            .catch(function (err) {
                $tbody.html('<tr><td colspan="' + (3 + LANG_KEYS.length) + '" class="cc-tm-error">Error: ' + escHtml(String(err)) + '</td></tr>');
            });
    }

    function renderTable(groups) {
        const $tbody = $('#cc-tm-tbody');
        $tbody.empty();

        if (!groups || groups.length === 0) {
            $tbody.html('<tr><td colspan="' + (3 + LANG_KEYS.length) + '">' +
                '<em>No terms found. Create a term above to get started.</em></td></tr>');
            return;
        }

        groups.forEach(function (group) {
            $tbody.append(buildRow(group));
        });

        initSortable();
    }

    function buildRow(group) {
        const gid = group.group_id || '';
        const trans = group.translations || {};

        // Group label = name of the term in the default language, or first available
        const defTerm = trans[DEFAULT];
        const anyTerm = Object.values(trans)[0];
        const label = defTerm ? defTerm.name : (anyTerm ? anyTerm.name : '—');

        let cols = '';

        LANG_KEYS.forEach(function (lang) {
            const t = trans[lang];
            if (t) {
                cols += '<td class="cc-tm-cell cc-tm-has-term" data-lang="' + escAttr(lang) + '">' +
                    '<span class="cc-tm-term-name" ' +
                    'data-term-id="' + t.id + '" ' +
                    'data-taxonomy="' + escAttr(currentTaxonomy) + '" ' +
                    'data-lang="' + escAttr(lang) + '">' +
                    escHtml(t.name) +
                    '</span>' +
                    ' <button class="cc-tm-rename-btn button button-small" ' +
                    'data-term-id="' + t.id + '" ' +
                    'data-name="' + escAttr(t.name) + '" ' +
                    'data-taxonomy="' + escAttr(currentTaxonomy) + '">' +
                    'Edit' +
                    '</button>' +
                    ' <button class="cc-tm-remove-btn button button-small cc-btn-danger" ' +
                    'data-term-id="' + t.id + '" ' +
                    'data-taxonomy="' + escAttr(currentTaxonomy) + '">' +
                    'Remove' +
                    '</button>' +
                    '</td>';
            } else {
                cols += '<td class="cc-tm-cell cc-tm-missing" data-lang="' + escAttr(lang) + '">' +
                    '<button class="cc-tm-add-translation-btn button button-small" ' +
                    'data-lang="' + escAttr(lang) + '" ' +
                    'data-group-id="' + escAttr(gid) + '" ' +
                    'data-taxonomy="' + escAttr(currentTaxonomy) + '">' +
                    '+' + lang.toUpperCase() +
                    '</button>' +
                    '</td>';
            }
        });

        const $tr = $('<tr class="cc-tm-row" ' +
            'data-group-id="' + escAttr(gid) + '">' +
            '<td class="cc-tm-drag-handle"><span class="dashicons dashicons-menu"></span></td>' +
            '<td class="cc-tm-group-label">' + escHtml(label) + '</td>' +
            cols +
            '<td class="cc-tm-actions">' +
            '<button class="cc-tm-delete-group-btn button cc-btn-destructive" ' +
            'data-group-id="' + escAttr(gid) + '" ' +
            'data-group-label="' + escAttr(label) + '" ' +
            'data-taxonomy="' + escAttr(currentTaxonomy) + '">' +
            'Delete Group' +
            '</button>' +
            '</td>' +
            '</tr>');

        return $tr;
    }

    // ── Sortable ──────────────────────────────────────────────────────────────

    function initSortable() {
        const $tbody = $('#cc-tm-tbody');
        if (!$tbody.sortable) return;

        $tbody.sortable({
            handle: '.cc-tm-drag-handle',
            axis: 'y',
            cursor: 'grabbing',
            update: function () {
                saveOrder();
            },
        });
    }

    function saveOrder() {
        const orderData = [];
        $('#cc-tm-tbody tr.cc-tm-row').each(function (i) {
            const gid = $(this).data('group-id');
            // Collect all term IDs in this row
            $(this).find('[data-term-id]').each(function () {
                orderData.push({ term_id: $(this).data('term-id'), order: i });
            });
        });

        if (!orderData.length) return;

        apiFetch('/reorder', 'POST', { taxonomy: currentTaxonomy, order: orderData })
            .catch(function (err) {
                showNotice('Reorder failed: ' + err, 'error');
            });
    }

    // ── Create term ───────────────────────────────────────────────────────────

    function handleCreate() {
        const name = $.trim($('#cc-tm-new-name').val());
        const lang = DEFAULT;

        if (!name) {
            showNotice('Please enter a term name.', 'error');
            return;
        }

        const $btn = $('#cc-tm-create-btn').prop('disabled', true).text('Creating…');

        apiFetch('/create', 'POST', { taxonomy: currentTaxonomy, name: name, lang: lang })
            .then(function () {
                $('#cc-tm-new-name').val('');
                showNotice('Term "' + escHtml(name) + '" created.', 'success');
                loadGroups();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
            })
            .finally(function () {
                $btn.prop('disabled', false).text('Create Term');
            });
    }

    // ── Add translation ───────────────────────────────────────────────────────

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
                loadGroups();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('+' + lang.toUpperCase());
            });
    }

    // ── Inline rename ─────────────────────────────────────────────────────────

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
                loadGroups();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('Edit');
            });
    }

    // ── Remove single term ────────────────────────────────────────────────────

    function handleRemove($btn) {
        const termId = $btn.data('term-id');
        const taxonomy = $btn.data('taxonomy');

        if (!window.confirm('Remove this translation? This cannot be undone.')) return;

        $btn.prop('disabled', true).text('Removing…');

        apiFetch('/remove', 'POST', { term_id: termId, taxonomy: taxonomy })
            .then(function () {
                showNotice('Translation removed.', 'success');
                loadGroups();
            })
            .catch(function (err) {
                showNotice('Error: ' + err, 'error');
                $btn.prop('disabled', false).text('Remove');
            });
    }

    // ── Delete group ──────────────────────────────────────────────────────────

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
                loadGroups();
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
        // Set initial taxonomy
        currentTaxonomy = $('#cc-tm-taxonomy').val() || '';
        if (currentTaxonomy) loadGroups();

        // Taxonomy change
        $('#cc-tm-taxonomy').on('change', function () {
            currentTaxonomy = $(this).val();
            loadGroups();
        });

        // Create
        $('#cc-tm-create-btn').on('click', handleCreate);
        $('#cc-tm-new-name').on('keydown', function (e) {
            if (e.key === 'Enter') handleCreate();
        });

        // Delegated: add translation
        $('#cc-tm-tbody').on('click', '.cc-tm-add-translation-btn', function () {
            handleAddTranslation($(this));
        });

        // Delegated: rename
        $('#cc-tm-tbody').on('click', '.cc-tm-rename-btn', function () {
            handleRename($(this));
        });

        // Delegated: remove
        $('#cc-tm-tbody').on('click', '.cc-tm-remove-btn', function () {
            handleRemove($(this));
        });

        // Delegated: delete group
        $('#cc-tm-tbody').on('click', '.cc-tm-delete-group-btn', function () {
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

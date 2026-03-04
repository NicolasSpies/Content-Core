/**
 * Content Core — Site Settings React Application
 * Uses: wp.element (React), wp.apiFetch, wp.i18n, wp.media
 * No external dependencies.
 */
(function () {
    'use strict';

    const { createElement: el, useState, useEffect, useCallback, useRef, Fragment } = wp.element;
    const apiFetch = wp.apiFetch;
    const __ = (wp.i18n && wp.i18n.__) ? wp.i18n.__ : function (s) { return s; };

    const config = window.CC_SITE_SETTINGS;
    if (!config || !config.restBase || !config.nonce) {
        document.addEventListener('DOMContentLoaded', function () {
            const root = document.getElementById('cc-site-settings-react-root');
            if (root) {
                root.innerHTML = '<div class="notice notice-error cc-notice-inline"><p><strong>Content Core config missing.</strong> Assets not localized. Please check admin enqueue + caching plugins.</p></div>';
            }
        });
        return;
    }

    const nonce = config.nonce || '';
    const restBase = config.restBase; // Full URL from wp_localize_script (e.g., .../settings/site)
    const siteUrl = config.siteUrl || '';
    const siteOptionsUrl = config.siteOptionsUrl || '';
    const siteProfileRestBase = config.siteProfileRestBase || '';
    const appMode = config.mode || 'full';

    // ─── Utility ────────────────────────────────────────────────────────────

    function saveSettings(data) {
        return apiFetch({
            url: restBase,
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            data: data,
        });
    }

    function loadSiteProfile() {
        return apiFetch({
            url: siteProfileRestBase,
            headers: { 'X-WP-Nonce': nonce },
        });
    }

    function saveSiteProfile(values) {
        return apiFetch({
            url: siteProfileRestBase,
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            data: { values: values },
        });
    }

    function useSettings() {
        const [settings, setSettings] = useState(null);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState(null);

        const load = useCallback(function () {
            setLoading(true);
            setError(null);
            if (appMode === 'site-profile') {
                setSettings({});
                setLoading(false);
                return;
            }

            apiFetch({ url: restBase, headers: { 'X-WP-Nonce': nonce } })
                .then(function (data) {
                    setSettings(data);
                    setLoading(false);
                })
                .catch(function (err) {
                    setError(err.message || __('Failed to load settings.', 'content-core'));
                    setLoading(false);
                });
        }, [nonce]);

        useEffect(load, [load]);

        return { settings, setSettings, loading, error, reload: load };
    }

    // ─── Image Picker ───────────────────────────────────────────────────────

    function ImagePicker({ label, hint, imageId, imageUrl, onChange, onRemove, exactWidth, exactHeight, compact }) {
        function openMedia() {
            if (!wp.media) return;
            const frame = wp.media({
                title: __('Select Image', 'content-core'),
                button: { text: __('Use this image', 'content-core') },
                multiple: false,
                library: { type: 'image' }
            });
            frame.on('select', function () {
                const att = frame.state().get('selection').first().toJSON();

                // Validate dimensions if required
                if (exactWidth && exactHeight) {
                    let w = att.width || (att.sizes && att.sizes.full ? att.sizes.full.width : 0);
                    let h = att.height || (att.sizes && att.sizes.full ? att.sizes.full.height : 0);

                    if (w && h && (w !== exactWidth || h !== exactHeight)) {
                        alert('Error: Image must be exactly ' + exactWidth + 'x' + exactHeight + ' px. Selected image is ' + w + 'x' + h + ' px.');
                        return;
                    }
                }

                const url = att.sizes && att.sizes.large
                    ? att.sizes.large.url
                    : att.url;
                onChange(att.id, url);
            });
            frame.open();
        }

        return el('div', {
            className: 'cc-card',
            style: { marginBottom: compact ? '12px' : '24px' }
        },
            el('div', { className: 'cc-card-body', style: { display: 'flex', alignItems: 'flex-start', gap: compact ? '14px' : '24px', flexWrap: 'wrap', padding: compact ? '16px' : undefined } },
                // Preview area
                el('div', {
                    style: {
                        width: compact ? '112px' : '160px',
                        height: compact ? '76px' : '110px',
                        border: '1px solid var(--cc-border)',
                        borderRadius: '6px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        background: 'var(--cc-bg-soft)',
                        flexShrink: 0,
                        overflow: 'hidden',
                    }
                },
                    imageUrl
                        ? el('img', {
                            src: imageUrl,
                            style: { maxWidth: '100%', maxHeight: '100%', display: 'block', objectFit: 'contain' }
                        })
                        : el('span', { style: { color: 'var(--cc-text-muted)', fontSize: '11px', fontWeight: 600, textTransform: 'uppercase' } },
                            __('No image', 'content-core')
                        )
                ),
                // Info + actions
                el('div', { style: { flex: 1, minWidth: compact ? '180px' : '250px' } },
                    el('div', { className: 'cc-field-label', style: { marginBottom: '4px' } }, label),
                    hint && el('div', { className: 'cc-help', style: { marginBottom: compact ? '8px' : '16px' } }, hint),
                    el('div', { style: { display: 'flex', gap: '8px' } },
                        el('button', {
                            type: 'button',
                            className: 'cc-button-secondary',
                            onClick: openMedia,
                            style: { padding: compact ? '5px 10px' : '6px 14px', fontSize: compact ? '12px' : undefined }
                        }, imageId ? __('Replace', 'content-core') : __('Upload', 'content-core')),
	                        !!imageId && el('button', {
	                            type: 'button',
	                            className: 'cc-button-secondary',
	                            onClick: onRemove,
	                            style: { padding: compact ? '5px 10px' : '6px 14px', fontSize: compact ? '12px' : undefined, color: 'var(--cc-error)' }
	                        }, __('Remove', 'content-core'))
	                    )
	                )
	            )
	        );
    }

    // ─── SEO Tab ────────────────────────────────────────────────────────────

    function SeoTab({ settings, onChange }) {
        const seo = settings.seo || {};
        const images = settings.images || {};
        const seoContext = settings.seo_context || {};
        const multilingual = settings.multilingual || {};
        const pageList = Array.isArray(seoContext.pages) ? seoContext.pages : [];
        const postTypes = Array.isArray(seoContext.post_types) ? seoContext.post_types : [];
        const postsByType = (seoContext.posts_by_type && typeof seoContext.posts_by_type === 'object') ? seoContext.posts_by_type : {};
        const baseTokens = Array.isArray(seoContext.tokens) ? seoContext.tokens : [];
        const siteImageTokens = Array.isArray(seoContext.site_image_tokens) ? seoContext.site_image_tokens : [];
        const languageRows = Array.isArray(multilingual.languages) ? multilingual.languages : [];
        const fallbackLang = (multilingual.default_lang || (languageRows[0] && languageRows[0].code) || 'de');
        const defaultLang = String(fallbackLang || 'de');
        const orderedLanguages = (function () {
            const clean = languageRows
                .filter(function (l) { return l && l.code; })
                .map(function (l) {
                    return { code: String(l.code), label: String(l.label || String(l.code).toUpperCase()) };
                });
            if (!clean.length) {
                return [{ code: defaultLang, label: defaultLang.toUpperCase() }];
            }
            const idx = clean.findIndex(function (l) { return l.code === defaultLang; });
            if (idx <= 0) return clean;
            const first = clean[idx];
            clean.splice(idx, 1);
            clean.unshift(first);
            return clean;
        })();
        const [postTypeLangBySlug, setPostTypeLangBySlug] = useState({});
        const [previewPostByType, setPreviewPostByType] = useState({});
        const [pageLangByGroup, setPageLangByGroup] = useState({});
        const [activeTokenTarget, setActiveTokenTarget] = useState(null);

        const baseGlobal = Object.assign({
            title: seo.title || config.defaultTitle || '',
            description: seo.description || config.defaultDesc || '',
            title_template: seo.title_template || '{page} {site}',
            robots: 'index,follow',
            canonical_url: '',
            og_image_id: 0,
            og_image_url: ''
        }, seo.global || {});

        const globalByLang = Object.assign({}, seo.global_by_lang || {});
        const pageOverridesByLang = Object.assign({}, seo.page_overrides_by_lang || {});
        const postTypeTemplatesByLang = Object.assign({}, seo.post_type_templates_by_lang || {});

        const activeSeoLang = defaultLang;
        const defaultGlobalLayer = Object.assign({}, baseGlobal, (globalByLang[defaultLang] || {}));
        const activeGlobalLayerRaw = Object.assign({}, globalByLang[activeSeoLang] || {});
        const global = Object.assign({}, defaultGlobalLayer);
        Object.keys(activeGlobalLayerRaw).forEach(function (key) {
            const value = activeGlobalLayerRaw[key];
            if (value !== null && value !== undefined && String(value) !== '') {
                global[key] = value;
            }
        });

        const defaultPageOverrides = Object.assign({}, seo.page_overrides || {}, (pageOverridesByLang[defaultLang] || {}));
        const activePageOverridesRaw = Object.assign({}, pageOverridesByLang[activeSeoLang] || {});
        const pageOverrides = Object.assign({}, defaultPageOverrides);
        Object.keys(activePageOverridesRaw).forEach(function (pageId) {
            const fallbackRow = Object.assign({}, defaultPageOverrides[pageId] || {});
            const rawRow = Object.assign({}, activePageOverridesRaw[pageId] || {});
            const resolved = Object.assign({}, fallbackRow);
            Object.keys(rawRow).forEach(function (field) {
                const value = rawRow[field];
                if (field === 'og_image_id') {
                    if (Number(value) > 0) resolved[field] = Number(value);
                    return;
                }
                if (field === 'og_image_url') {
                    if (String(value || '') !== '') resolved[field] = value;
                    return;
                }
                if (value !== null && value !== undefined && String(value) !== '') {
                    resolved[field] = value;
                }
            });
            pageOverrides[pageId] = resolved;
        });

        const defaultPostTypeTemplates = Object.assign({}, seo.post_type_templates || {}, (postTypeTemplatesByLang[defaultLang] || {}));
        const activePostTypeTemplatesRaw = Object.assign({}, postTypeTemplatesByLang[activeSeoLang] || {});
        const postTypeTemplates = Object.assign({}, defaultPostTypeTemplates);
        Object.keys(activePostTypeTemplatesRaw).forEach(function (postTypeSlug) {
            const fallbackRow = Object.assign({}, defaultPostTypeTemplates[postTypeSlug] || {});
            const rawRow = Object.assign({}, activePostTypeTemplatesRaw[postTypeSlug] || {});
            const resolved = Object.assign({}, fallbackRow);
            Object.keys(rawRow).forEach(function (field) {
                const value = rawRow[field];
                if (field === 'og_image_id') {
                    if (Number(value) > 0) resolved[field] = Number(value);
                    return;
                }
                if (field === 'og_image_url') {
                    if (String(value || '') !== '') resolved[field] = value;
                    return;
                }
                if (value !== null && value !== undefined && String(value) !== '') {
                    resolved[field] = value;
                }
            });
            postTypeTemplates[postTypeSlug] = resolved;
        });
        const previewUrl = siteUrl || '';

        function updateSeo(nextSeo) {
            const normalizedGlobal = Object.assign({}, nextSeo.global || {});
            nextSeo.title = normalizedGlobal.title || '';
            nextSeo.description = normalizedGlobal.description || '';
            nextSeo.title_template = normalizedGlobal.title_template || '{page} {site}';
            onChange({ seo: nextSeo });
        }

        function updateImagesMany(values) {
            onChange({ images: Object.assign({}, images, values || {}) });
        }

        function updateGlobal(field, value) {
            const currentRaw = Object.assign({}, globalByLang[activeSeoLang] || {});
            const nextGlobal = Object.assign({}, currentRaw, { [field]: value });
            const nextGlobalByLang = Object.assign({}, globalByLang, { [activeSeoLang]: nextGlobal });
            const next = Object.assign({}, seo, {
                global_by_lang: nextGlobalByLang
            });
            if (activeSeoLang === defaultLang) {
                next.global = Object.assign({}, global, { [field]: value });
            }
            updateSeo(next);
        }

        function updateGlobalMany(values) {
            const currentRaw = Object.assign({}, globalByLang[activeSeoLang] || {});
            const nextGlobal = Object.assign({}, currentRaw, values || {});
            const nextGlobalByLang = Object.assign({}, globalByLang, { [activeSeoLang]: nextGlobal });
            const next = Object.assign({}, seo, {
                global_by_lang: nextGlobalByLang
            });
            if (activeSeoLang === defaultLang) {
                next.global = Object.assign({}, global, values || {});
            }
            updateSeo(next);
        }

        function updatePageOverride(pageId, field, value, langCode) {
            const effectiveLang = String(langCode || defaultLang);
            const currentRawRows = Object.assign({}, pageOverridesByLang[effectiveLang] || {});
            const current = Object.assign({}, currentRawRows[String(pageId)] || {});
            if (typeof field === 'object' && field !== null) {
                Object.keys(field).forEach(function (k) {
                    current[k] = field[k];
                });
            } else {
                current[field] = value;
            }
            const nextLangRows = Object.assign({}, currentRawRows, { [String(pageId)]: current });
            const next = Object.assign({}, seo, {
                page_overrides_by_lang: Object.assign({}, pageOverridesByLang, { [effectiveLang]: nextLangRows })
            });
            if (effectiveLang === defaultLang) {
                next.page_overrides = nextLangRows;
            }
            updateSeo(next);
        }

        function updateTemplate(postType, field, value, langCode) {
            const effectiveLang = String(langCode || defaultLang);
            const currentRawRows = Object.assign({}, postTypeTemplatesByLang[effectiveLang] || {});
            const current = Object.assign({}, currentRawRows[postType] || {});
            if (typeof field === 'object' && field !== null) {
                Object.keys(field).forEach(function (k) {
                    current[k] = field[k];
                });
            } else {
                current[field] = value;
            }
            const nextLangRows = Object.assign({}, currentRawRows, { [postType]: current });
            const next = Object.assign({}, seo, {
                post_type_templates_by_lang: Object.assign({}, postTypeTemplatesByLang, { [effectiveLang]: nextLangRows })
            });
            if (effectiveLang === defaultLang) {
                next.post_type_templates = nextLangRows;
            }
            updateSeo(next);
        }

        function insertToken(existing, token) {
            const safe = String(existing || '');
            const wrapped = '{' + token + '}';
            if (safe.indexOf(wrapped) >= 0) return safe;
            return (safe ? safe + ' ' : '') + wrapped;
        }

        function focusTokenTarget(scope, field, meta) {
            setActiveTokenTarget(Object.assign({ scope: scope, field: field }, meta || {}));
        }

        function applyTokenToActiveField(token, fallbackTarget) {
            const target = activeTokenTarget || fallbackTarget || null;
            if (!target || !target.scope || !target.field || !token) return;

            if (target.scope === 'global') {
                const current = String(global[target.field] || '');
                updateGlobal(target.field, insertToken(current, token));
                return;
            }

            if (target.scope === 'page') {
                const pageId = String(target.pageId || '');
                if (!pageId) return;
                const lang = String(target.lang || defaultLang);
                const rawRows = Object.assign({}, pageOverridesByLang[lang] || {});
                const rawRow = Object.assign({}, rawRows[pageId] || {});
                const fallbackRow = target.defaultPageId
                    ? Object.assign({}, defaultPageOverrides[String(target.defaultPageId)] || {})
                    : {};
                const resolvedRow = Object.assign({}, fallbackRow, rawRow);
                const current = String(resolvedRow[target.field] || '');
                updatePageOverride(pageId, target.field, insertToken(current, token), lang);
                return;
            }

            if (target.scope === 'posttype') {
                const slug = String(target.slug || '');
                if (!slug) return;
                const lang = String(target.lang || defaultLang);
                const defaultTpl = Object.assign({}, defaultPostTypeTemplates[slug] || {});
                const langTpl = Object.assign({}, ((postTypeTemplatesByLang[lang] || {})[slug] || {}));
                const resolvedTpl = Object.assign({}, defaultTpl, langTpl);
                const current = String(resolvedTpl[target.field] || '');
                updateTemplate(slug, target.field, insertToken(current, token), lang);
            }
        }

        function templateToPreview(template, context) {
            const tpl = String(template || '');
            return tpl
                .replace(/\{title\}/g, context.title || '')
                .replace(/\{page\}/g, context.page || context.title || '')
                .replace(/\{site\}/g, global.title || config.defaultTitle || '')
                .replace(/\{separator\}/g, '')
                .replace(/\{excerpt\}/g, context.excerpt || '')
                .replace(/\{slug\}/g, context.slug || '')
                .replace(/\{date\}/g, context.date || '')
                .replace(/\{author\}/g, context.author || '')
                .replace(/\s{2,}/g, ' ')
                .trim();
        }

        function decodeEntities(text) {
            const raw = String(text || '');
            if (!raw) return '';
            if (typeof window === 'undefined' || !window.document) {
                return raw.replace(/&amp;#(\d+);/g, function (_, dec) {
                    return String.fromCharCode(parseInt(dec, 10));
                }).replace(/&#(\d+);/g, function (_, dec) {
                    return String.fromCharCode(parseInt(dec, 10));
                });
            }
            const textarea = window.document.createElement('textarea');
            let value = raw;
            for (let i = 0; i < 3; i++) {
                textarea.innerHTML = value;
                const decoded = textarea.value;
                if (decoded === value) break;
                value = decoded;
            }
            return value.replace(/\u00A0/g, ' ');
        }

        function cleanTitle(text) {
            return decodeEntities(text || '').trim();
        }

        function normalizeTokenId(token) {
            const raw = String(token || '').trim();
            if (raw.length > 2 && raw.charAt(0) === '{' && raw.charAt(raw.length - 1) === '}') {
                return raw.slice(1, -1);
            }
            return raw;
        }

        const allPages = pageList.filter(function (p) {
            return !!(p && typeof p === 'object');
        });
        const defaultPagesByGroup = {};
        pageList.forEach(function (page) {
            if (!page || typeof page !== 'object') return;
            const pageLang = page.language ? String(page.language) : defaultLang;
            const group = page.translation_group ? String(page.translation_group) : ('page-' + String(page.id || ''));
            if (pageLang !== defaultLang) return;
            defaultPagesByGroup[group] = String(page.id || '');
        });

        const pageGroups = (function () {
            const groups = {};
            allPages.forEach(function (page) {
                if (!page || typeof page !== 'object') return;
                const groupId = page.translation_group ? String(page.translation_group) : ('page-' + String(page.id || ''));
                if (!groups[groupId]) {
                    groups[groupId] = { key: groupId, rows: [] };
                }
                groups[groupId].rows.push(page);
            });
            return Object.keys(groups).map(function (key) {
                const group = groups[key];
                group.rows.sort(function (a, b) {
                    const aLang = String((a && a.language) || defaultLang);
                    const bLang = String((b && b.language) || defaultLang);
                    const ai = orderedLanguages.findIndex(function (l) { return String(l.code) === aLang; });
                    const bi = orderedLanguages.findIndex(function (l) { return String(l.code) === bLang; });
                    const aPos = ai >= 0 ? ai : 999;
                    const bPos = bi >= 0 ? bi : 999;
                    return aPos - bPos;
                });
                group.defaultRow = group.rows.find(function (r) {
                    return String((r && r.language) || defaultLang) === defaultLang;
                }) || group.rows[0] || null;
                return group;
            });
        })();

        const filteredPageList = pageGroups
            .filter(function (group) {
                return !!(group && group.defaultRow);
            })
            .map(function (group) {
                return Object.assign({}, group.defaultRow, { __group: group });
            });

        const referencePage = filteredPageList.find(function (p) { return !!(p && p.is_front_page); })
            || filteredPageList[0]
            || pageList.find(function (p) { return !!(p && p.is_front_page); })
            || pageList[0]
            || null;
        const referencePageTitle = cleanTitle(referencePage && referencePage.title ? referencePage.title : __('Example Page', 'content-core'));
        const referencePageSlug = referencePage && referencePage.is_front_page
            ? ''
            : ((referencePage && referencePage.slug) ? referencePage.slug : 'example-page');

        const globalPreviewTitle = global.title_template
            .replace(/\{page\}/g, referencePageTitle)
            .replace(/\{title\}/g, referencePageTitle)
            .replace(/\{site\}/g, global.title || config.defaultTitle || '')
            .replace(/\{separator\}/g, '')
            .replace(/\{excerpt\}/g, __('Example excerpt', 'content-core'))
            .replace(/\{slug\}/g, referencePageSlug)
            .replace(/\{date\}/g, '2026-03-03')
            .replace(/\{author\}/g, 'Admin')
            .replace(/\s{2,}/g, ' ')
            .trim();

        const globalPreviewDesc = templateToPreview((global.description || config.defaultDesc || ''), {
            title: referencePageTitle,
            page: referencePageTitle,
            excerpt: __('Example excerpt', 'content-core'),
            slug: referencePageSlug,
            date: '2026-03-03',
            author: 'Admin'
        });
        const globalPreviewCanonical = global.canonical_url || previewUrl || '';
        const globalPreviewOg = global.og_image_url || '';
        const langOrderIndex = {};
        orderedLanguages.forEach(function (lang, idx) {
            langOrderIndex[String(lang.code)] = idx;
        });
        function langLabel(code) {
            const needle = String(code || '');
            const row = orderedLanguages.find(function (l) { return String(l.code) === needle; });
            return row ? (row.label || needle.toUpperCase()) : needle.toUpperCase();
        }
        function resolveGlobalForLang(langCode) {
            const lang = String(langCode || defaultLang);
            const defaults = Object.assign({}, baseGlobal, (globalByLang[defaultLang] || {}));
            const raw = Object.assign({}, globalByLang[lang] || {});
            const resolved = Object.assign({}, defaults);
            Object.keys(raw).forEach(function (key) {
                const value = raw[key];
                if (value !== null && value !== undefined && String(value) !== '') {
                    resolved[key] = value;
                }
            });
            return resolved;
        }
        function applyTemplateWithGlobal(template, context, globalRow) {
            const tpl = String(template || '');
            const sourceGlobal = globalRow || {};
            return tpl
                .replace(/\{title\}/g, context.title || '')
                .replace(/\{page\}/g, context.page || context.title || '')
                .replace(/\{site\}/g, sourceGlobal.title || config.defaultTitle || '')
                .replace(/\{separator\}/g, '')
                .replace(/\{excerpt\}/g, context.excerpt || '')
                .replace(/\{slug\}/g, context.slug || '')
                .replace(/\{date\}/g, context.date || '')
                .replace(/\{author\}/g, context.author || '')
                .replace(/\s{2,}/g, ' ')
                .trim();
        }
        function resolvePageRowForLang(page) {
            const pageLang = page && page.language ? String(page.language) : defaultLang;
            const groupId = page && page.translation_group ? String(page.translation_group) : '';
            const defaultLinkedPageId = groupId && defaultPagesByGroup[groupId] ? defaultPagesByGroup[groupId] : '';
            const linkedDefaultRow = defaultLinkedPageId
                ? Object.assign({}, defaultPageOverrides[defaultLinkedPageId] || {})
                : {};
            const langRows = Object.assign({}, pageOverridesByLang[pageLang] || {});
            const langRow = Object.assign({}, langRows[String(page.id)] || {});
            return Object.assign({ title: '', description: '', robots: '', canonical_url: '', og_image_id: 0, og_image_url: '' }, linkedDefaultRow, langRow);
        }
        function resolvePostTypeTemplateForLang(postTypeSlug, langCode) {
            const lang = String(langCode || defaultLang);
            const defaults = Object.assign({}, defaultPostTypeTemplates[postTypeSlug] || {});
            const raw = Object.assign({}, ((postTypeTemplatesByLang[lang] || {})[postTypeSlug] || {}));
            const resolved = Object.assign({}, defaults);
            Object.keys(raw).forEach(function (key) {
                const value = raw[key];
                if (key === 'og_image_id') {
                    if (Number(value) > 0) resolved[key] = Number(value);
                    return;
                }
                if (key === 'og_image_url') {
                    if (String(value || '') !== '') resolved[key] = value;
                    return;
                }
                if (value !== null && value !== undefined && String(value) !== '') {
                    resolved[key] = value;
                }
            });
            return Object.assign({
                title_template: '',
                description_template: '',
                robots: '',
                canonical_template: '',
                og_image_id: 0,
                og_image_url: ''
            }, resolved);
        }
        const pageOverviewGroups = (function () {
            const groups = {};
            filteredPageList.forEach(function (page) {
                if (!page || typeof page !== 'object') return;
                const groupId = page.translation_group ? String(page.translation_group) : ('page-' + String(page.id));
                if (!groups[groupId]) {
                    groups[groupId] = { key: groupId, title: cleanTitle(page.title) || __('Page', 'content-core'), rows: [] };
                }
                groups[groupId].rows.push(page);
            });
            return Object.keys(groups).map(function (key) {
                const group = groups[key];
                group.rows.sort(function (a, b) {
                    const ai = langOrderIndex[String((a && a.language) || defaultLang)] ?? 999;
                    const bi = langOrderIndex[String((b && b.language) || defaultLang)] ?? 999;
                    return ai - bi;
                });
                const defaultRow = group.rows.find(function (row) {
                    return String((row && row.language) || defaultLang) === defaultLang;
                });
                if (defaultRow && defaultRow.title) {
                    group.title = cleanTitle(defaultRow.title);
                }
                return group;
            });
        })();
        const postTypeOverviewGroups = postTypes.map(function (pt) {
            const rows = Array.isArray(postsByType[pt.slug]) ? postsByType[pt.slug] : [];
            const groups = {};
            rows.forEach(function (entry) {
                const groupId = entry.translation_group ? String(entry.translation_group) : ('entry-' + String(entry.id));
                if (!groups[groupId]) {
                    groups[groupId] = { key: groupId, title: cleanTitle(entry.title) || (pt.singular || pt.label || pt.slug), rows: [] };
                }
                groups[groupId].rows.push(entry);
            });
            const list = Object.keys(groups).map(function (groupKey) {
                const group = groups[groupKey];
                group.rows.sort(function (a, b) {
                    const ai = langOrderIndex[String((a && a.language) || defaultLang)] ?? 999;
                    const bi = langOrderIndex[String((b && b.language) || defaultLang)] ?? 999;
                    return ai - bi;
                });
                const defaultRow = group.rows.find(function (row) {
                    return String((row && row.language) || defaultLang) === defaultLang;
                });
                if (defaultRow && defaultRow.title) {
                    group.title = cleanTitle(defaultRow.title);
                }
                return group;
            });
            return { slug: pt.slug, label: pt.label, entries: list };
        });

        return el('div', { className: 'cc-grid' },
            el('div', { className: 'cc-card cc-grid-full' },
                el('div', { className: 'cc-card-header' }, el('h2', { className: 'cc-seo-card-title' }, __('Global SEO', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-seo-edit-layout' },
                        el('div', null,
                            el('div', { className: 'cc-options-grid' },
                                el('div', { className: 'cc-option-row' },
                                    el('label', null, __('Site Title', 'content-core')),
                                    el('input', {
                                        type: 'text',
                                        value: global.title || '',
                                        placeholder: config.defaultTitle || '',
                                        onChange: function (e) { updateGlobal('title', e.target.value); }
                                    })
                                ),
                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                    el('label', null, __('Global Title Template', 'content-core')),
                                    el('input', {
                                        type: 'text',
                                        value: global.title_template || '',
                                        onFocus: function () { focusTokenTarget('global', 'title_template'); },
                                        onChange: function (e) { updateGlobal('title_template', e.target.value); }
                                    }),
                                    el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '5px', marginTop: '6px' } },
                                        baseTokens.map(function (token) {
                                            const tokenId = token && token.token ? token.token : '';
                                            const tokenLabel = token && token.label ? token.label : tokenId;
                                            if (!tokenId) return null;
                                            return el('button', {
                                                key: 'global-token-' + tokenId,
                                                type: 'button',
                                                className: 'cc-button-secondary cc-seo-token-button',
                                                onClick: function () {
                                                    applyTokenToActiveField(tokenId, { scope: 'global', field: 'title_template' });
                                                }
                                            }, '{' + tokenId + '} ' + tokenLabel);
                                        })
                                    )
                                ),
                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                    el('label', null, __('Default Meta Description', 'content-core')),
                                    el('textarea', {
                                        rows: 3,
                                        value: global.description || '',
                                        placeholder: config.defaultDesc || '',
                                        onFocus: function () { focusTokenTarget('global', 'description'); },
                                        onChange: function (e) { updateGlobal('description', e.target.value); }
                                    })
                                ),
                                el('div', { className: 'cc-option-row' },
                                    el('label', null, __('Default Robots', 'content-core')),
                                    el('select', {
                                        value: global.robots || 'index,follow',
                                        onChange: function (e) { updateGlobal('robots', e.target.value); }
                                    },
                                        el('option', { value: 'index,follow' }, 'index,follow'),
                                        el('option', { value: 'noindex,nofollow' }, 'noindex,nofollow'),
                                        el('option', { value: 'index,nofollow' }, 'index,nofollow'),
                                        el('option', { value: 'noindex,follow' }, 'noindex,follow')
                                    )
                                ),
                                el('div', { className: 'cc-option-row' },
                                    el('label', null, __('Canonical URL (Global Fallback)', 'content-core')),
                                    el('input', {
                                        type: 'url',
                                        value: global.canonical_url || '',
                                        onFocus: function () { focusTokenTarget('global', 'canonical_url'); },
                                        onChange: function (e) { updateGlobal('canonical_url', e.target.value); }
                                    })
                                )
                            ),
                            el('div', { style: { marginTop: '10px' } },
                                el(ImagePicker, {
                                    label: __('Favicon (Site Icon)', 'content-core'),
                                    hint: __('Used for browser tabs and as fallback icon in branding.', 'content-core'),
                                    imageId: images.social_icon_id || 0,
                                    imageUrl: images.social_icon_id_url || '',
                                    compact: true,
                                    onChange: function (id, url) {
                                        updateImagesMany({ social_icon_id: id, social_icon_id_url: url });
                                    },
                                    onRemove: function () {
                                        updateImagesMany({ social_icon_id: 0, social_icon_id_url: '' });
                                    }
                                }),
                                el(ImagePicker, {
                                    label: __('Global OG Image', 'content-core'),
                                    hint: __('Fallback OG image when no page or post type image is defined.', 'content-core'),
                                    imageId: global.og_image_id || 0,
                                    imageUrl: global.og_image_url || '',
                                    compact: true,
                                    onChange: function (id, url) {
                                        updateGlobalMany({ og_image_id: id, og_image_url: url });
                                    },
                                    onRemove: function () {
                                        updateGlobalMany({ og_image_id: 0, og_image_url: '' });
                                    }
                                })
                            )
                        ),
                        el('div', { className: 'cc-seo-preview-col' },
                            el('div', {
                        className: 'cc-seo-page-preview-split',
                        style: {
                            display: 'grid',
                            gridTemplateColumns: '1fr',
                            gap: '12px',
                            marginTop: '14px',
                            background: 'rgba(var(--cc-accent-rgb), 0.08)',
                            border: '1px solid rgba(var(--cc-accent-rgb), 0.28)',
                            borderRadius: '10px',
                            padding: '12px'
                        }
                            },
                        el('div', {
                            style: {
                                border: '1px solid var(--cc-border-light)',
                                borderRadius: '8px',
                                background: '#fff',
                                padding: '12px'
                            }
                        },
                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Search Preview', 'content-core')),
                            el('div', { style: { color: '#1a0dab', fontSize: '18px', fontWeight: 500, marginBottom: '4px', lineHeight: 1.35 } }, globalPreviewTitle || ''),
                            el('div', { style: { color: '#4d5156', fontSize: '13px', lineHeight: 1.4, marginBottom: '6px' } }, globalPreviewDesc || ''),
                            el('div', { style: { color: '#202124', fontSize: '12px' } }, globalPreviewCanonical || '')
                        ),
                        el('div', {
                            style: {
                                border: '1px solid var(--cc-border-light)',
                                borderRadius: '8px',
                                background: '#fff',
                                padding: '12px'
                            }
                        },
                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Social Preview', 'content-core')),
                            el('div', {
                                style: {
                                    border: '1px solid var(--cc-border-light)',
                                    borderRadius: '8px',
                                    overflow: 'hidden',
                                    background: '#fff'
                                }
                            },
                                        globalPreviewOg
                                            ? el('img', {
                                                src: globalPreviewOg,
                                                alt: '',
                                                style: {
                                                    width: '100%',
                                                    height: 'auto',
                                                    aspectRatio: '1200 / 630',
                                                    objectFit: 'cover',
                                                    display: 'block'
                                                }
                                            })
                                            : el('div', {
                                                style: {
                                                    width: '100%',
                                                    aspectRatio: '1200 / 630',
                                                    background: 'var(--cc-bg-soft)',
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    color: 'var(--cc-text-muted)',
                                            fontSize: '12px',
                                            fontWeight: 700
                                        }
                                    }, __('No OG image', 'content-core')),
                                el('div', { style: { padding: '10px 12px 12px' } },
                                    el('div', { style: { color: '#111', fontSize: '16px', fontWeight: 700, marginBottom: '6px', lineHeight: 1.35 } }, globalPreviewTitle || ''),
                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '13px', lineHeight: 1.45, marginBottom: '8px' } }, globalPreviewDesc || ''),
                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '12px' } }, globalPreviewCanonical || '')
                                )
                            )
                        )
                        )
                    )
                )
            )),

            el('div', { className: 'cc-card cc-grid-full' },
                el('div', { className: 'cc-card-header', style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' } },
                    el('h2', { className: 'cc-seo-card-title' }, __('Page SEO Overrides', 'content-core')),
                    el('button', {
                        type: 'button',
                        className: 'cc-button-secondary',
                        onClick: function () {
                            const next = Object.assign({}, seo, {
                                page_overrides_by_lang: {}
                            });
                            next.page_overrides = {};
                            updateSeo(next);
                        }
                    }, __('Set All Pages to Default', 'content-core'))
                ),
                el('div', { className: 'cc-card-body cc-seo-list-body' },
                    filteredPageList.length === 0
                        ? el('p', { className: 'cc-help' }, __('No pages found.', 'content-core'))
                        : filteredPageList.map(function (page) {
                            const pageGroup = page && page.__group ? page.__group : { key: ('page-' + String(page.id || '')), rows: [page], defaultRow: page };
                            const groupId = String(pageGroup.key || '');
                            const selectedPageLang = String(pageLangByGroup[groupId] || defaultLang);
                            const availableRows = Array.isArray(pageGroup.rows) ? pageGroup.rows : [page];
                            const activePage = availableRows.find(function (r) {
                                return String((r && r.language) || defaultLang) === selectedPageLang;
                            }) || pageGroup.defaultRow || availableRows[0] || page;
                            const id = String(activePage.id);
                            const pageLang = activePage.language ? String(activePage.language) : defaultLang;
                            const defaultLinkedPageId = groupId && defaultPagesByGroup[groupId] ? defaultPagesByGroup[groupId] : String((pageGroup.defaultRow && pageGroup.defaultRow.id) || '');
                            const linkedDefaultRow = defaultLinkedPageId
                                ? Object.assign({}, defaultPageOverrides[defaultLinkedPageId] || {})
                                : {};
                            const currentLangRows = Object.assign({}, pageOverridesByLang[pageLang] || {});
                            const currentLangRow = Object.assign({}, currentLangRows[id] || {});
                            const resolvedRow = Object.assign({}, linkedDefaultRow, currentLangRow);
                            const row = Object.assign({ title: '', description: '', robots: '', canonical_url: '', og_image_id: 0, og_image_url: '' }, resolvedRow);
                            const pageTitleText = cleanTitle(activePage && activePage.title ? activePage.title : __('Page', 'content-core'));
                            const pageSlug = activePage && typeof activePage.slug === 'string' ? activePage.slug : '';
                            const pageIsFront = !!(activePage && activePage.is_front_page);
                            const pagePreviewContext = {
                                title: pageTitleText,
                                page: pageTitleText,
                                excerpt: __('Example excerpt', 'content-core'),
                                slug: pageIsFront ? '' : (pageSlug || ('page-' + activePage.id)),
                                date: '2026-03-03',
                                author: 'Admin'
                            };
                            const globalPageTitleFallback = templateToPreview(global.title_template, pagePreviewContext);
                            const globalPageDescFallback = global.description || '';
                            const globalPageCanonicalFallback = global.canonical_url
                                || (pageIsFront
                                    ? (previewUrl || '')
                                    : (activePage && activePage.permalink ? activePage.permalink : (previewUrl ? (previewUrl + '/' + (pageSlug || ('page-' + activePage.id))) : '')));
                            const pagePreviewTitle = row.title || globalPageTitleFallback;
                            const pagePreviewDesc = templateToPreview((row.description || globalPageDescFallback || ''), pagePreviewContext);
                            const pagePreviewCanonical = row.canonical_url
                                || global.canonical_url
                                || (pageIsFront
                                    ? (previewUrl || '')
                                    : (activePage && activePage.permalink ? activePage.permalink : (previewUrl ? (previewUrl + '/' + (pageSlug || ('page-' + activePage.id))) : '')));
                            const pagePreviewOg = row.og_image_url || global.og_image_url || '';
                            return el('details', { key: 'page-group-' + groupId, className: 'cc-settings-panel cc-seo-compact-panel cc-seo-section-block' },
                                el('summary', { className: 'cc-seo-section-summary' },
                                    el('span', { className: 'cc-seo-section-title' }, cleanTitle((pageGroup.defaultRow && pageGroup.defaultRow.title) || pageTitleText)),
                                    el('button', {
                                        type: 'button',
                                        className: 'cc-button-secondary',
                                        style: { padding: '4px 8px', fontSize: '11px', lineHeight: 1.2 },
                                        onClick: function (e) {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            const nextOverrides = Object.assign({}, pageOverridesByLang[pageLang] || {});
                                            delete nextOverrides[id];
                                            const next = Object.assign({}, seo, {
                                                page_overrides_by_lang: Object.assign({}, pageOverridesByLang, { [pageLang]: nextOverrides })
                                            });
                                            if (pageLang === defaultLang) {
                                                next.page_overrides = nextOverrides;
                                            }
                                            updateSeo(next);
                                        }
                                    }, __('Use Global Default', 'content-core'))
                                ),
                                el('div', { className: 'cc-seo-compact-body' },
                                    el('div', { className: 'cc-seo-edit-layout' },
                                        el('div', null,
                                            el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '8px' } },
                                                el('strong', { style: { marginRight: '2px', fontSize: '12px' } }, __('Language', 'content-core')),
                                                availableRows.map(function (langRow) {
                                                    const langCode = String((langRow && langRow.language) || defaultLang);
                                                    const isActive = langCode === pageLang;
                                                    return el('button', {
                                                        key: 'page-lang-' + groupId + '-' + String(langRow.id),
                                                        type: 'button',
                                                        className: 'cc-button-secondary',
                                                        onClick: function () {
                                                            setPageLangByGroup(function (prev) {
                                                                return Object.assign({}, prev || {}, { [groupId]: langCode });
                                                            });
                                                        },
                                                        style: isActive ? {
                                                            borderColor: 'var(--cc-accent-color)',
                                                            color: 'var(--cc-accent-color)',
                                                            boxShadow: 'inset 0 0 0 1px var(--cc-accent-color)',
                                                            background: 'rgba(var(--cc-accent-rgb), 0.08)',
                                                            padding: '3px 9px',
                                                            fontSize: '11px'
                                                        } : { padding: '3px 9px', fontSize: '11px' }
                                                    }, (langLabel(langCode) || langCode).toUpperCase());
                                                })
                                            ),
                                            el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '5px', marginBottom: '8px' } },
                                                baseTokens.map(function (token) {
                                                    const tokenId = token && token.token ? token.token : '';
                                                    const tokenLabel = token && token.label ? token.label : tokenId;
                                                    if (!tokenId) return null;
                                                    return el('button', {
                                                        key: 'page-' + id + '-token-' + tokenId,
                                                        type: 'button',
                                                        className: 'cc-button-secondary cc-seo-token-button',
                                                        onClick: function () {
                                                            applyTokenToActiveField(tokenId, {
                                                                scope: 'page',
                                                                pageId: id,
                                                                lang: pageLang,
                                                                defaultPageId: defaultLinkedPageId || '',
                                                                field: 'title'
                                                            });
                                                        }
                                                    }, '{' + tokenId + '} ' + tokenLabel);
                                                })
                                            ),
                                            el('div', { className: 'cc-options-grid' },
                                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                                    el('label', null, __('SEO Title Override', 'content-core')),
                                                    el('input', {
                                                        type: 'text',
                                                        value: row.title || globalPageTitleFallback,
                                                        onFocus: function () {
                                                            focusTokenTarget('page', 'title', {
                                                                pageId: id,
                                                                lang: pageLang,
                                                                defaultPageId: defaultLinkedPageId || ''
                                                            });
                                                        },
                                                        onChange: function (e) { updatePageOverride(id, 'title', e.target.value, pageLang); }
                                                    })
                                                ),
                                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                                    el('label', null, __('Meta Description Override', 'content-core')),
                                                    el('textarea', {
                                                        rows: 2,
                                                        value: row.description || globalPageDescFallback,
                                                        onFocus: function () {
                                                            focusTokenTarget('page', 'description', {
                                                                pageId: id,
                                                                lang: pageLang,
                                                                defaultPageId: defaultLinkedPageId || ''
                                                            });
                                                        },
                                                        onChange: function (e) { updatePageOverride(id, 'description', e.target.value, pageLang); }
                                                    })
                                                ),
                                                el('div', { className: 'cc-option-row' },
                                                    el('label', null, __('Robots Override', 'content-core')),
                                                    el('select', {
                                                        value: row.robots || '',
                                                        onChange: function (e) { updatePageOverride(id, 'robots', e.target.value, pageLang); }
                                                    },
                                                        el('option', { value: '' }, __('Use Global', 'content-core')),
                                                        el('option', { value: 'index,follow' }, 'index,follow'),
                                                        el('option', { value: 'noindex,nofollow' }, 'noindex,nofollow'),
                                                        el('option', { value: 'index,nofollow' }, 'index,nofollow'),
                                                        el('option', { value: 'noindex,follow' }, 'noindex,follow')
                                                    )
                                                ),
                                                el('div', { className: 'cc-option-row' },
                                                    el('label', null, __('Canonical URL Override', 'content-core')),
                                                    el('input', {
                                                        type: 'url',
                                                        value: row.canonical_url || globalPageCanonicalFallback,
                                                        onFocus: function () {
                                                            focusTokenTarget('page', 'canonical_url', {
                                                                pageId: id,
                                                                lang: pageLang,
                                                                defaultPageId: defaultLinkedPageId || ''
                                                            });
                                                        },
                                                        onChange: function (e) { updatePageOverride(id, 'canonical_url', e.target.value, pageLang); }
                                                    })
                                                )
                                            ),
                                            el('div', { style: { marginTop: '10px' } },
                                                el(ImagePicker, {
                                                    label: __('Page OG Image Override', 'content-core'),
                                                    hint: __('Optional. Leave empty to use post type or global OG image.', 'content-core'),
                                                    imageId: row.og_image_id || 0,
                                                    imageUrl: row.og_image_url || '',
                                                    compact: true,
                                                    onChange: function (imgId, url) { updatePageOverride(id, { og_image_id: imgId, og_image_url: url }, null, pageLang); },
                                                    onRemove: function () { updatePageOverride(id, { og_image_id: 0, og_image_url: '' }, null, pageLang); }
                                                })
                                            )
                                        ),
                                        el('div', { className: 'cc-seo-preview-col' },
                                            el('div', {
                                        className: 'cc-seo-page-preview-split',
                                        style: {
                                            display: 'grid',
                                            gridTemplateColumns: '1fr',
                                            gap: '12px',
                                            marginTop: '10px',
                                            background: 'rgba(var(--cc-accent-rgb), 0.08)',
                                            border: '1px solid rgba(var(--cc-accent-rgb), 0.28)',
                                            borderRadius: '10px',
                                            padding: '12px'
                                        }
                                            },
                                        el('div', {
                                            style: {
                                                border: '1px solid var(--cc-border-light)',
                                                borderRadius: '8px',
                                                background: '#fff',
                                                padding: '12px'
                                            }
                                        },
                                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Search Preview', 'content-core')),
                                            el('div', { style: { color: '#1a0dab', fontSize: '18px', fontWeight: 500, marginBottom: '4px', lineHeight: 1.35 } }, pagePreviewTitle || ''),
                                            el('div', { style: { color: '#4d5156', fontSize: '13px', lineHeight: 1.4, marginBottom: '6px' } }, pagePreviewDesc || ''),
                                            el('div', { style: { color: '#202124', fontSize: '12px' } }, pagePreviewCanonical || '')
                                        ),
                                        el('div', {
                                            style: {
                                                border: '1px solid var(--cc-border-light)',
                                                borderRadius: '8px',
                                                background: '#fff',
                                                padding: '12px'
                                            }
                                        },
                                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Social Preview', 'content-core')),
                                            el('div', {
                                                style: {
                                                    border: '1px solid var(--cc-border-light)',
                                                    borderRadius: '8px',
                                                    overflow: 'hidden',
                                                    background: '#fff'
                                                }
                                            },
                                                pagePreviewOg
                                                    ? el('img', {
                                                        src: pagePreviewOg,
                                                        alt: '',
                                                        style: {
                                                            width: '100%',
                                                            height: 'auto',
                                                            aspectRatio: '1200 / 630',
                                                            objectFit: 'cover',
                                                            display: 'block'
                                                        }
                                                    })
                                                    : el('div', {
                                                        style: {
                                                            width: '100%',
                                                            aspectRatio: '1200 / 630',
                                                            background: 'var(--cc-bg-soft)',
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            color: 'var(--cc-text-muted)',
                                                            fontSize: '12px',
                                                            fontWeight: 700
                                                        }
                                                    }, __('No OG image', 'content-core')),
                                                el('div', { style: { padding: '10px 12px 12px' } },
                                                    el('div', { style: { color: '#111', fontSize: '16px', fontWeight: 700, marginBottom: '6px', lineHeight: 1.35 } }, pagePreviewTitle || '—'),
                                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '13px', lineHeight: 1.45, marginBottom: '8px' } }, pagePreviewDesc || '—'),
                                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '12px' } }, pagePreviewCanonical || '—')
                                                )
                                            )
                                        )
                                        )
                                    )
                                )
                            )
                            );
                        })
                )
            ),

            el('div', { className: 'cc-card cc-grid-full' },
                el('div', { className: 'cc-card-header', style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '12px' } },
                    el('h2', { className: 'cc-seo-card-title' }, __('Post Type SEO Templates', 'content-core')),
                    el('button', {
                        type: 'button',
                        className: 'cc-button-secondary',
                        onClick: function () {
                            const next = Object.assign({}, seo, {
                                post_type_templates_by_lang: {}
                            });
                            next.post_type_templates = {};
                            updateSeo(next);
                        }
                    }, __('Set All Post Types to Default', 'content-core'))
                ),
                el('div', { className: 'cc-card-body cc-seo-list-body' },
                    postTypes.length === 0
                        ? el('p', { className: 'cc-help' }, __('No post types found.', 'content-core'))
                        : postTypes.map(function (pt) {
                            const slug = pt.slug;
                            const selectedLang = postTypeLangBySlug[slug] || defaultLang;
                            const defaultTpl = Object.assign({}, defaultPostTypeTemplates[slug] || {});
                            const langTpl = Object.assign({}, ((postTypeTemplatesByLang[selectedLang] || {})[slug] || {}));
                            const tpl = Object.assign({
                                title_template: '',
                                description_template: '',
                                robots: '',
                                canonical_template: '',
                                og_image_id: 0,
                                og_image_url: ''
                            }, defaultTpl, langTpl);
                            const postRows = Array.isArray(postsByType[slug]) ? postsByType[slug] : [];
                            const langPostRows = postRows.filter(function (row) {
                                const rowLang = row && row.language ? String(row.language) : defaultLang;
                                return rowLang === selectedLang;
                            });
                            const previewPostId = String(previewPostByType[slug] || '');
                            const selectedPreviewPost = langPostRows.find(function (row) { return String(row.id) === previewPostId; }) || langPostRows[0] || null;
                            const selectedPreviewIndex = selectedPreviewPost
                                ? langPostRows.findIndex(function (row) { return String(row.id) === String(selectedPreviewPost.id); })
                                : -1;
                            const sampleCtx = {
                                title: selectedPreviewPost ? cleanTitle(selectedPreviewPost.title) : cleanTitle(pt.singular || pt.label || pt.slug),
                                page: selectedPreviewPost ? cleanTitle(selectedPreviewPost.title) : cleanTitle(pt.singular || pt.label || pt.slug),
                                excerpt: selectedPreviewPost ? (selectedPreviewPost.excerpt || '') : __('Example excerpt', 'content-core'),
                                slug: selectedPreviewPost ? (selectedPreviewPost.slug || (pt.slug + '-example')) : (pt.slug + '-example'),
                                date: selectedPreviewPost ? (selectedPreviewPost.date || '2026-03-03') : '2026-03-03',
                                author: selectedPreviewPost ? (selectedPreviewPost.author || 'Admin') : 'Admin'
                            };
                            const postTypePreviewTitle = tpl.title_template ? templateToPreview(tpl.title_template, sampleCtx) : templateToPreview(global.title_template, sampleCtx);
                            const postTypePreviewDesc = tpl.description_template
                                ? templateToPreview(tpl.description_template, sampleCtx)
                                : templateToPreview((global.description || ''), sampleCtx);
                            const postTypePreviewCanonical = tpl.canonical_template
                                ? templateToPreview(tpl.canonical_template, sampleCtx)
                                : (selectedPreviewPost && selectedPreviewPost.permalink ? selectedPreviewPost.permalink : (global.canonical_url || (previewUrl ? (previewUrl + '/' + sampleCtx.slug) : '')));
                            const normalizedOgToken = normalizeTokenId(tpl.og_image_token || '');
                            let tokenPreviewOg = '';
                            if (normalizedOgToken && selectedPreviewPost && selectedPreviewPost.token_image_urls && typeof selectedPreviewPost.token_image_urls === 'object') {
                                tokenPreviewOg = String(selectedPreviewPost.token_image_urls[normalizedOgToken] || '');
                            }
                            const postTypePreviewOg = tokenPreviewOg || tpl.og_image_url || global.og_image_url || '';

                            const tokenButtons = []
                                .concat(baseTokens || [])
                                .concat(Array.isArray(pt.fields) ? pt.fields : []);

                            return el('details', { key: slug, className: 'cc-settings-panel cc-seo-compact-panel cc-seo-section-block' },
                                el('summary', { className: 'cc-seo-section-summary' },
                                    el('span', { className: 'cc-seo-section-title' }, cleanTitle(pt.label)),
                                    el('button', {
                                        type: 'button',
                                        className: 'cc-button-secondary',
                                        style: { padding: '4px 8px', fontSize: '11px', lineHeight: 1.2 },
                                        onClick: function (e) {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            const scoped = Object.assign({}, postTypeTemplatesByLang[selectedLang] || {});
                                            delete scoped[slug];
                                            const next = Object.assign({}, seo, {
                                                post_type_templates_by_lang: Object.assign({}, postTypeTemplatesByLang, { [selectedLang]: scoped })
                                            });
                                            if (selectedLang === defaultLang) {
                                                next.post_type_templates = scoped;
                                            }
                                            updateSeo(next);
                                        }
                                    }, __('Use Global Default', 'content-core'))
                                ),
                                el('div', { className: 'cc-seo-compact-body' },
                                    el('div', { className: 'cc-seo-edit-layout' },
                                        el('div', null,
                                            el('div', { style: { display: 'flex', alignItems: 'center', gap: '8px', flexWrap: 'wrap', marginBottom: '8px' } },
                                                el('strong', { style: { marginRight: '2px', fontSize: '12px' } }, __('Language', 'content-core')),
                                                orderedLanguages.map(function (lang) {
                                                    const isActive = lang.code === selectedLang;
                                                    return el('button', {
                                                        key: slug + '-lang-' + lang.code,
                                                        type: 'button',
                                                        className: 'cc-button-secondary',
                                                        onClick: function () {
                                                            setPostTypeLangBySlug(function (prev) {
                                                                return Object.assign({}, prev || {}, { [slug]: lang.code });
                                                            });
                                                        },
                                                        style: isActive ? {
                                                            borderColor: 'var(--cc-accent-color)',
                                                            color: 'var(--cc-accent-color)',
                                                            boxShadow: 'inset 0 0 0 1px var(--cc-accent-color)',
                                                            background: 'rgba(var(--cc-accent-rgb), 0.08)',
                                                            padding: '3px 9px',
                                                            fontSize: '11px'
                                                        } : { padding: '3px 9px', fontSize: '11px' }
                                                    }, (lang.label || lang.code).toUpperCase());
                                                })
                                            ),
                                            el('p', { className: 'cc-help', style: { marginTop: 0, marginBottom: '6px' } }, __('Token builder from existing fields of this post type.', 'content-core')),
                                            el('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '10px', marginBottom: '8px', flexWrap: 'wrap' } },
                                                el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '5px', flex: 1 } },
                                                tokenButtons.map(function (token) {
                                                    const tokenId = token && token.token ? token.token : '';
                                                    const tokenLabel = token && token.label ? token.label : tokenId;
                                                    if (!tokenId) return null;
                                                    return el('button', {
                                                        key: slug + '-' + tokenId,
                                                        type: 'button',
                                                        className: 'cc-button-secondary cc-seo-token-button',
                                                        onClick: function () {
                                                            applyTokenToActiveField(tokenId, {
                                                                scope: 'posttype',
                                                                slug: slug,
                                                                lang: selectedLang,
                                                                field: 'title_template'
                                                            });
                                                        }
                                                    }, '{' + tokenId + '} ' + tokenLabel);
                                                })
                                                )
                                            ),
                                            el('div', { className: 'cc-options-grid' },
                                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                                    el('label', null, __('Title Template', 'content-core')),
                                                    el('input', {
                                                        type: 'text',
                                                        value: tpl.title_template || '',
                                                        onFocus: function () {
                                                            focusTokenTarget('posttype', 'title_template', { slug: slug, lang: selectedLang });
                                                        },
                                                        onChange: function (e) { updateTemplate(slug, 'title_template', e.target.value, selectedLang); }
                                                    })
                                                ),
                                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                                    el('label', null, __('Description Template', 'content-core')),
                                                    el('textarea', {
                                                        rows: 2,
                                                        value: tpl.description_template || '',
                                                        onFocus: function () {
                                                            focusTokenTarget('posttype', 'description_template', { slug: slug, lang: selectedLang });
                                                        },
                                                        onChange: function (e) { updateTemplate(slug, 'description_template', e.target.value, selectedLang); }
                                                    })
                                                ),
                                                el('div', { className: 'cc-option-row' },
                                                    el('label', null, __('Robots', 'content-core')),
                                                    el('select', {
                                                        value: tpl.robots || '',
                                                        onChange: function (e) { updateTemplate(slug, 'robots', e.target.value, selectedLang); }
                                                    },
                                                        el('option', { value: '' }, __('Use Global', 'content-core')),
                                                        el('option', { value: 'index,follow' }, 'index,follow'),
                                                        el('option', { value: 'noindex,nofollow' }, 'noindex,nofollow'),
                                                        el('option', { value: 'index,nofollow' }, 'index,nofollow'),
                                                        el('option', { value: 'noindex,follow' }, 'noindex,follow')
                                                    )
                                                ),
                                                el('div', { className: 'cc-option-row' },
                                                    el('label', null, __('Canonical Template', 'content-core')),
                                                    el('input', {
                                                        type: 'text',
                                                        value: tpl.canonical_template || '',
                                                        placeholder: '{site}/{slug}',
                                                        onFocus: function () {
                                                            focusTokenTarget('posttype', 'canonical_template', { slug: slug, lang: selectedLang });
                                                        },
                                                        onChange: function (e) { updateTemplate(slug, 'canonical_template', e.target.value, selectedLang); }
                                                    })
                                                ),
                                                el('div', { className: 'cc-option-row cc-option-row-full' },
                                                    el('label', null, __('OG Image Token Source', 'content-core')),
                                                    el('select', {
                                                        value: tpl.og_image_token || '',
                                                        onChange: function (e) { updateTemplate(slug, 'og_image_token', e.target.value, selectedLang); }
                                                    },
                                                        el('option', { value: '' }, __('None (use selected fallback image)', 'content-core')),
                                                        el('optgroup', { label: __('Post Type Fields', 'content-core') },
                                                            (Array.isArray(pt.fields) ? pt.fields : []).map(function (fieldToken) {
                                                                const tokenId = fieldToken && fieldToken.token ? fieldToken.token : '';
                                                                if (!tokenId) return null;
                                                                return el('option', { key: slug + '-field-' + tokenId, value: tokenId }, '{' + tokenId + '} ' + (fieldToken.label || tokenId));
                                                            })
                                                        ),
                                                        el('optgroup', { label: __('Site Images', 'content-core') },
                                                            siteImageTokens.map(function (imageToken) {
                                                                const tokenId = imageToken && imageToken.token ? imageToken.token : '';
                                                                if (!tokenId) return null;
                                                                return el('option', { key: slug + '-img-' + tokenId, value: tokenId }, '{' + tokenId + '} ' + (imageToken.label || tokenId));
                                                            })
                                                        )
                                                    ),
                                                    el('p', { className: 'cc-help' }, __('Uses an image ID token as OG source before template fallback image.', 'content-core'))
                                                )
                                            ),
                                            el(ImagePicker, {
                                                label: __('Template OG Image', 'content-core'),
                                                hint: __('Optional fallback for this post type.', 'content-core'),
                                                imageId: tpl.og_image_id || 0,
                                                imageUrl: tpl.og_image_url || '',
                                                compact: true,
                                                onChange: function (imgId, url) { updateTemplate(slug, { og_image_id: imgId, og_image_url: url }, null, selectedLang); },
                                                onRemove: function () { updateTemplate(slug, { og_image_id: 0, og_image_url: '' }, null, selectedLang); }
                                            })
                                        ),
                                        el('div', { className: 'cc-seo-preview-col' },
                                            el('div', {
                                                style: {
                                                    display: 'flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'space-between',
                                                    gap: '8px',
                                                    marginBottom: '8px',
                                                    padding: '6px 10px',
                                                    border: '1px solid var(--cc-border-light)',
                                                    borderRadius: '8px',
                                                    background: 'var(--cc-bg-soft)'
                                                }
                                            },
                                                el('div', { style: { fontSize: '11px', fontWeight: 700, color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.05em', minWidth: '82px' } }, __('Preview Entry', 'content-core')),
                                                el('button', {
                                                    type: 'button',
                                                    className: 'cc-button-secondary',
                                                    disabled: langPostRows.length <= 1,
                                                    style: { padding: '2px 8px', fontSize: '13px', lineHeight: 1.1, minHeight: '24px' },
                                                    onClick: function () {
                                                        if (langPostRows.length <= 1) return;
                                                        const currentIndex = selectedPreviewIndex >= 0 ? selectedPreviewIndex : 0;
                                                        const nextIndex = currentIndex <= 0 ? langPostRows.length - 1 : currentIndex - 1;
                                                        const nextRow = langPostRows[nextIndex] || null;
                                                        setPreviewPostByType(function (prev) {
                                                            return Object.assign({}, prev || {}, { [slug]: nextRow ? String(nextRow.id) : '' });
                                                        });
                                                    }
                                                }, '‹'),
                                                el('div', {
                                                    style: {
                                                        flex: 1,
                                                        minWidth: 0,
                                                        fontSize: '12px',
                                                        fontWeight: 600,
                                                        color: 'var(--cc-text)',
                                                        whiteSpace: 'nowrap',
                                                        overflow: 'hidden',
                                                        textOverflow: 'ellipsis',
                                                        textAlign: 'center'
                                                    }
                                                }, selectedPreviewPost ? cleanTitle(selectedPreviewPost.title) : __('No entry available', 'content-core')),
                                                el('button', {
                                                    type: 'button',
                                                    className: 'cc-button-secondary',
                                                    disabled: langPostRows.length <= 1,
                                                    style: { padding: '2px 8px', fontSize: '13px', lineHeight: 1.1, minHeight: '24px' },
                                                    onClick: function () {
                                                        if (langPostRows.length <= 1) return;
                                                        const currentIndex = selectedPreviewIndex >= 0 ? selectedPreviewIndex : 0;
                                                        const nextIndex = currentIndex >= (langPostRows.length - 1) ? 0 : currentIndex + 1;
                                                        const nextRow = langPostRows[nextIndex] || null;
                                                        setPreviewPostByType(function (prev) {
                                                            return Object.assign({}, prev || {}, { [slug]: nextRow ? String(nextRow.id) : '' });
                                                        });
                                                    }
                                                }, '›')
                                            ),
                                            el('div', {
                                        className: 'cc-seo-page-preview-split',
                                        style: {
                                            display: 'grid',
                                            gridTemplateColumns: '1fr',
                                            gap: '12px',
                                            marginTop: '10px',
                                            background: 'rgba(var(--cc-accent-rgb), 0.08)',
                                            border: '1px solid rgba(var(--cc-accent-rgb), 0.28)',
                                            borderRadius: '10px',
                                            padding: '12px'
                                        }
                                            },
                                        el('div', {
                                            style: {
                                                border: '1px solid var(--cc-border-light)',
                                                borderRadius: '8px',
                                                background: '#fff',
                                                padding: '12px'
                                            }
                                        },
                                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Search Preview', 'content-core')),
                                            el('div', { style: { color: '#1a0dab', fontSize: '18px', fontWeight: 500, marginBottom: '4px', lineHeight: 1.35 } }, postTypePreviewTitle || ''),
                                            el('div', { style: { color: '#4d5156', fontSize: '13px', lineHeight: 1.4, marginBottom: '6px' } }, postTypePreviewDesc || ''),
                                            el('div', { style: { color: '#202124', fontSize: '12px' } }, postTypePreviewCanonical || '')
                                        ),
                                        el('div', {
                                            style: {
                                                border: '1px solid var(--cc-border-light)',
                                                borderRadius: '8px',
                                                background: '#fff',
                                                padding: '12px'
                                            }
                                        },
                                            el('div', { style: { fontSize: '12px', fontWeight: 700, marginBottom: '8px', color: 'var(--cc-text-muted)', textTransform: 'uppercase', letterSpacing: '.04em' } }, __('Social Preview', 'content-core')),
                                            el('div', {
                                                style: {
                                                    border: '1px solid var(--cc-border-light)',
                                                    borderRadius: '8px',
                                                    overflow: 'hidden',
                                                    background: '#fff'
                                                }
                                            },
                                                postTypePreviewOg
                                                    ? el('img', {
                                                        src: postTypePreviewOg,
                                                        alt: '',
                                                        style: {
                                                            width: '100%',
                                                            height: 'auto',
                                                            aspectRatio: '1200 / 630',
                                                            objectFit: 'cover',
                                                            display: 'block'
                                                        }
                                                    })
                                                    : el('div', {
                                                        style: {
                                                            width: '100%',
                                                            aspectRatio: '1200 / 630',
                                                            background: 'var(--cc-bg-soft)',
                                                            display: 'flex',
                                                            alignItems: 'center',
                                                            justifyContent: 'center',
                                                            color: 'var(--cc-text-muted)',
                                                            fontSize: '12px',
                                                            fontWeight: 700
                                                        }
                                                    }, __('No OG image', 'content-core')),
                                                el('div', { style: { padding: '10px 12px 12px' } },
                                                    el('div', { style: { color: '#111', fontSize: '16px', fontWeight: 700, marginBottom: '6px', lineHeight: 1.35 } }, postTypePreviewTitle || '—'),
                                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '13px', lineHeight: 1.45, marginBottom: '8px' } }, postTypePreviewDesc || '—'),
                                                    el('div', { style: { color: 'var(--cc-text-muted)', fontSize: '12px' } }, postTypePreviewCanonical || '—')
                                                )
                                            )
                                        )
                                        )
                                    )
                                )
                            )
                            );
                        })
                )
            ),
            el('div', { className: 'cc-card cc-grid-full' },
                el('div', { className: 'cc-card-header' }, el('h2', { className: 'cc-seo-card-title' }, __('SEO Global Overview', 'content-core'))),
                el('div', { className: 'cc-card-body cc-seo-list-body' },
                    el('details', { className: 'cc-settings-panel cc-seo-compact-panel cc-seo-section-block' },
                        el('summary', { className: 'cc-seo-section-summary' },
                            el('span', { className: 'cc-seo-section-title' }, __('Pages', 'content-core'))
                        ),
                        el('div', { className: 'cc-seo-compact-body' },
                            pageOverviewGroups.length === 0
                                ? el('p', { className: 'cc-help' }, __('No pages found.', 'content-core'))
                                : el('div', { className: 'cc-seo-overview-groups' },
                                    pageOverviewGroups.map(function (group) {
                                        return el('div', { key: 'overview-page-' + group.key, className: 'cc-seo-overview-group-card' },
                                            el('div', { className: 'cc-seo-overview-group-title' }, group.title),
                                            el('div', { className: 'cc-seo-overview-grid' },
                                                group.rows.map(function (page) {
                                                    const pageLang = String((page && page.language) || defaultLang);
                                                    const langGlobal = resolveGlobalForLang(pageLang);
                                                    const row = resolvePageRowForLang(page);
                                                    const context = {
                                                            title: cleanTitle(page.title) || '',
                                                            page: cleanTitle(page.title) || '',
                                                        excerpt: __('Example excerpt', 'content-core'),
                                                        slug: page.is_front_page ? '' : (page.slug || ('page-' + page.id)),
                                                        date: '2026-03-03',
                                                        author: 'Admin'
                                                    };
                                                    const previewTitle = row.title || applyTemplateWithGlobal(langGlobal.title_template || '{page} {site}', context, langGlobal);
                                                    const previewDesc = row.description || langGlobal.description || '';
                                                    const previewCanonical = row.canonical_url || langGlobal.canonical_url || page.permalink || '';
                                                    return el('div', { key: 'overview-page-row-' + page.id, className: 'cc-seo-overview-preview-card' },
                                                        el('div', { className: 'cc-seo-overview-lang' }, langLabel(pageLang)),
                                                        el('div', { className: 'cc-seo-overview-title' }, previewTitle || ''),
                                                        el('div', { className: 'cc-seo-overview-desc' }, previewDesc || ''),
                                                        el('div', { className: 'cc-seo-overview-url' }, previewCanonical || '')
                                                    );
                                                })
                                            )
                                        );
                                    })
                                )
                        )
                    ),
                    el('details', { className: 'cc-settings-panel cc-seo-compact-panel cc-seo-section-block' },
                        el('summary', { className: 'cc-seo-section-summary' },
                            el('span', { className: 'cc-seo-section-title' }, __('Post Types', 'content-core'))
                        ),
                        el('div', { className: 'cc-seo-compact-body' },
                            postTypeOverviewGroups.length === 0
                                ? el('p', { className: 'cc-help' }, __('No post types found.', 'content-core'))
                                : el('div', { className: 'cc-seo-overview-groups' },
                                    postTypeOverviewGroups.map(function (group) {
                                        return el('div', { key: 'overview-pt-' + group.slug, className: 'cc-seo-overview-group-card' },
                                                    el('div', { className: 'cc-seo-overview-group-title' }, cleanTitle(group.label)),
                                            el('div', { className: 'cc-seo-overview-grid' },
                                                group.entries.map(function (entry) {
                                                    const previewRows = (entry.rows || []).map(function (postRow) {
                                                        const postLang = String((postRow && postRow.language) || defaultLang);
                                                        const langGlobal = resolveGlobalForLang(postLang);
                                                        const template = resolvePostTypeTemplateForLang(group.slug, postLang);
                                                        const ctx = {
                                                                    title: cleanTitle(postRow.title) || '',
                                                                    page: cleanTitle(postRow.title) || '',
                                                            excerpt: postRow.excerpt || '',
                                                            slug: postRow.slug || '',
                                                            date: postRow.date || '',
                                                            author: postRow.author || ''
                                                        };
                                                        const previewTitle = template.title_template
                                                            ? applyTemplateWithGlobal(template.title_template, ctx, langGlobal)
                                                            : applyTemplateWithGlobal(langGlobal.title_template || '{page} {site}', ctx, langGlobal);
                                                                const previewDesc = template.description_template
                                                                    ? applyTemplateWithGlobal(template.description_template, ctx, langGlobal)
                                                                    : applyTemplateWithGlobal((langGlobal.description || ''), ctx, langGlobal);
                                                        const previewCanonical = template.canonical_template
                                                            ? applyTemplateWithGlobal(template.canonical_template, ctx, langGlobal)
                                                            : (postRow.permalink || langGlobal.canonical_url || '');
                                                        return el('div', { key: 'overview-pt-lang-' + postRow.id, className: 'cc-seo-overview-preview-card' },
                                                            el('div', { className: 'cc-seo-overview-lang' }, langLabel(postLang)),
                                                            el('div', { className: 'cc-seo-overview-title' }, previewTitle || ''),
                                                            el('div', { className: 'cc-seo-overview-desc' }, previewDesc || ''),
                                                            el('div', { className: 'cc-seo-overview-url' }, previewCanonical || '')
                                                        );
                                                    });
                                                    return el('div', { key: 'overview-pt-entry-' + group.slug + '-' + entry.key, className: 'cc-seo-overview-entry' },
                                                        el('div', { className: 'cc-seo-overview-entry-title' }, cleanTitle(entry.title)),
                                                        el('div', { className: 'cc-seo-overview-grid' }, previewRows)
                                                    );
                                                })
                                            )
                                        );
                                    })
                                )
                        )
                    )
                )
            )
        );
    }

    // ─── Site Images Tab ────────────────────────────────────────────────────

    function SiteImagesTab({ settings, onChange }) {
        const images = settings.images || {};

        function handleImageChange(key) {
            return function (id, url) {
                onChange({
                    images: {
                        ...images,
                        [key]: id,
                        [key + '_url']: url,
                    }
                });
            };
        }

        function handleImageRemove(key) {
            return function () {
                const updated = { ...images };
                delete updated[key];
                delete updated[key + '_url'];
                onChange({ images: updated });
            };
        }

        return el('div', { className: 'cc-grid' },
            el('div', { className: 'cc-grid-full' },
                el(ImagePicker, {
                    label: __('Favicon (Site Icon)', 'content-core'),
                    hint: __('Recommended 64×64 px. Used as the browser favicon and mobile touch icon.', 'content-core'),
                    imageId: images.social_icon_id || 0,
                    imageUrl: images.social_icon_id_url || '',
                    exactWidth: 64,
                    exactHeight: 64,
                    onChange: handleImageChange('social_icon_id'),
                    onRemove: handleImageRemove('social_icon_id'),
                }),

                el(ImagePicker, {
                    label: __('Social Sharing Image', 'content-core'),
                    hint: __('1200×630 px recommended. This image appears when your site is shared on social media (Facebook, LinkedIn, etc.).', 'content-core'),
                    imageId: images.og_default_id || 0,
                    imageUrl: images.og_default_id_url || '',
                    exactWidth: 1200,
                    exactHeight: 630,
                    onChange: handleImageChange('og_default_id'),
                    onRemove: handleImageRemove('og_default_id'),
                })
            )
        );
    }

    // ─── Cookie Banner Tab ──────────────────────────────────────────────────

    function CookieTab({ settings, onChange }) {
        const cookie = settings.cookie || {};

        function set(field, value) {
            onChange({ cookie: { ...cookie, [field]: value } });
        }
        function setNested(parent, field, value) {
            onChange({ cookie: { ...cookie, [parent]: { ...cookie[parent], [field]: value } } });
        }

        const labels = cookie.labels || {};
        const integrations = cookie.integrations || {};
        const behavior = cookie.behavior || {};
        const categories = cookie.categories || {};

        return el('div', { className: 'cc-grid' },
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('Consent Banner', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-field' },
                        el('div', { className: 'cc-toggle-wrap' },
                            el('label', { className: 'cc-toggle' },
                                el('input', {
                                    type: 'checkbox',
                                    checked: !!cookie.enabled,
                                    onChange: function (e) { set('enabled', e.target.checked); }
                                }),
                                el('span', { className: 'cc-slider' })
                            ),
                            el('label', { className: 'cc-field-label', style: { margin: 0 } }, __('Enable Cookie Consent Banner', 'content-core'))
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Banner Title', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'text',
                                value: cookie.bannerTitle || '',
                                onChange: function (e) { set('bannerTitle', e.target.value); }
                            })
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Banner Text', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('textarea', {
                                rows: 3,
                                value: cookie.bannerText || '',
                                onChange: function (e) { set('bannerText', e.target.value); }
                            })
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Privacy Policy URL', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'url',
                                value: cookie.policyUrl || '',
                                onChange: function (e) { set('policyUrl', e.target.value); }
                            })
                        )
                    )
                )
            ),
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('Integrations & Labels', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Accept Button Label', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'text',
                                value: labels.acceptAll || '',
                                onChange: function (e) { setNested('labels', 'acceptAll', e.target.value); }
                            })
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Reject Button Label', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'text',
                                value: labels.rejectAll || '',
                                onChange: function (e) { setNested('labels', 'rejectAll', e.target.value); }
                            })
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Google Analytics (GA4) ID', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'text',
                                placeholder: 'G-XXXXXXXXXX',
                                value: integrations.ga4MeasurementId || '',
                                onChange: function (e) { setNested('integrations', 'ga4MeasurementId', e.target.value); }
                            })
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Active Categories', 'content-core')),
                        ['analytics', 'marketing', 'preferences'].map(function (cat) {
                            return el('label', { key: cat, style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px', fontSize: '13px' } },
                                el('input', {
                                    type: 'checkbox',
                                    checked: !!categories[cat],
                                    onChange: function (e) { setNested('categories', cat, e.target.checked); }
                                }),
                                cat.charAt(0).toUpperCase() + cat.slice(1)
                            );
                        })
                    )
                )
            )
        );
    }

    // ─── Multilingual Tab (read-only info, editing remains in PHP) ──────────

    function MultilingualNote() {
        return el('div', { className: 'cc-settings-card' },
            el('h2', { className: 'cc-card-title' }, __('Multilingual', 'content-core')),
            el('div', {
                style: {
                    background: '#f0f6fc',
                    border: '1px solid #0d6efd',
                    borderRadius: '6px',
                    padding: '16px',
                    fontSize: '14px',
                    color: '#0d1b2a',
                }
            },
                el('strong', null, __('Note: ', 'content-core')),
                __('Multilingual configuration (languages, flags, permalinks) is managed via the form below. This React shell handles SEO, Images, and Cookie Banner.', 'content-core')
            )
        );
    }

    // ─── Site Options Tab ────────────────────────────────────────────────────

    function SiteOptionsTab({ profileState, onProfileChange }) {
        if (!profileState || profileState.loading) {
            return el('div', { style: { padding: '24px' } },
                el('span', { className: 'spinner is-active', style: { float: 'none', marginRight: '8px' } }),
                __('Loading site profile…', 'content-core')
            );
        }

        if (profileState.error) {
            return el('div', { className: 'notice notice-error', style: { padding: '12px 16px' } },
                el('p', null, profileState.error)
            );
        }

        const schema = profileState.schema || {};
        const values = profileState.values || {};
        const sections = Object.keys(schema);

        return el('div', {
            className: 'cc-site-profile-sections'
        },
            sections.map(function (sectionId) {
                const section = schema[sectionId] || {};
                const fields = section.fields || {};
                const fieldIds = Object.keys(fields);

                return el('div', { key: sectionId, className: 'cc-card' },
                    el('div', { className: 'cc-card-header' },
                        el('h2', null, section.title || sectionId)
                    ),
                    el('div', { className: 'cc-card-body' },
                        el('div', {
                            className: 'cc-options-grid'
                        },
                            fieldIds.map(function (fieldId) {
                                const field = fields[fieldId] || {};
                                const type = field.type || 'text';
                                const label = field.label || fieldId;
                                const current = values[fieldId] || '';
                                const editable = field.client_editable !== false;
                                const commonProps = {
                                    value: current,
                                    disabled: !editable,
                                    onChange: function (e) {
                                        onProfileChange(fieldId, e.target.value);
                                    }
                                };

                                return el('div', {
                                    key: fieldId,
                                    className: 'cc-option-row' + (type === 'textarea' ? ' cc-option-row-full' : '')
                                },
                                    el('label', null, label),
                                    type === 'textarea'
                                        ? el('textarea', Object.assign({ rows: 4 }, commonProps))
                                        : el('input', Object.assign({ type: (type === 'email' || type === 'url') ? type : 'text' }, commonProps))
                                );
                            })
                        )
                    )
                );
            })
        );
    }

    // ─── Branding Tab ────────────────────────────────────────────────────────

    function BrandingTab({ settings, onChange }) {
        const branding = settings.branding || {};

        function set(fieldOrUpdates, value) {
            let updates = {};
            if (typeof fieldOrUpdates === 'string') {
                updates[fieldOrUpdates] = value;
            } else {
                updates = fieldOrUpdates;
            }
            onChange({ branding: { ...branding, ...updates } });
        }

        return el('div', { className: 'cc-grid' },
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('General & Admin Branding', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-field' },
                        el('div', { className: 'cc-toggle-wrap', style: { marginBottom: '12px' } },
                            el('label', { className: 'cc-toggle' },
                                el('input', {
                                    type: 'checkbox',
                                    checked: !!branding.enabled,
                                    onChange: function (e) { set('enabled', e.target.checked); }
                                }),
                                el('span', { className: 'cc-slider' })
                            ),
                            el('label', { className: 'cc-field-label', style: { margin: 0 } }, __('Activate Branding Overrides', 'content-core'))
                        ),
                        el('label', { style: { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '8px', fontSize: '13px' } },
                            el('input', {
                                type: 'checkbox',
                                checked: !!branding.exclude_admins,
                                onChange: function (e) { set('exclude_admins', e.target.checked); }
                            }),
                            __('Do not apply branding to administrator roles', 'content-core')
                        ),
                        el('label', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px' } },
                            el('input', {
                                type: 'checkbox',
                                checked: !!branding.remove_wp_mentions,
                                onChange: function (e) { set('remove_wp_mentions', e.target.checked); }
                            }),
                            __('Hide default WordPress logos and mentions', 'content-core')
                        )
                    ),

                    el('hr', { style: { margin: '24px 0', border: 'none', borderTop: '1px solid var(--cc-border-header)' } }),

                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Login Background Color', 'content-core')),
                        el('div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
                            el('input', {
                                type: 'color',
                                value: branding.login_bg_color || branding.custom_primary_color || '#1e1e1e',
                                onChange: function (e) {
                                    set('login_bg_color', e.target.value);
                                },
                                style: { width: '44px', height: '44px', padding: '4px', border: '1px solid var(--cc-border)', borderRadius: '4px' }
                            }),
                            el('code', { style: { fontSize: '11px' } }, branding.login_bg_color || branding.custom_primary_color || '#1e1e1e')
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Accent Color', 'content-core')),
                        el('div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
                            el('input', {
                                type: 'color',
                                value: branding.custom_accent_color || branding.login_btn_color || '#2271b1',
                                onChange: function (e) {
                                    set({
                                        custom_accent_color: e.target.value,
                                        login_btn_color: e.target.value
                                    });
                                },
                                style: { width: '44px', height: '44px', padding: '4px', border: '1px solid var(--cc-border)', borderRadius: '4px' }
                            }),
                            el('code', { style: { fontSize: '11px' } }, branding.custom_accent_color || branding.login_btn_color || '#2271b1')
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Admin Footer Text', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('textarea', {
                                rows: 2,
                                value: branding.custom_footer_text || '',
                                onChange: function (e) { set('custom_footer_text', e.target.value); }
                            })
                        )
                    )
                )
            ),
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('Logos & Login Branding', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Admin Bar Logo', 'content-core')),
                        el('div', { style: { marginBottom: '12px' } },
                            el('label', { style: { display: 'flex', alignItems: 'center', gap: '8px', fontSize: '13px' } },
                                el('input', {
                                    type: 'checkbox',
                                    checked: !!branding.use_site_icon_for_admin_bar,
                                    onChange: function (e) { set('use_site_icon_for_admin_bar', e.target.checked); }
                                }),
                                __('Use Site Icon (Favicon) in Admin Bar', 'content-core')
                            )
                        ),
                        !branding.use_site_icon_for_admin_bar && el(ImagePicker, {
                            imageId: branding.admin_bar_logo || 0,
                            imageUrl: branding.admin_bar_logo_url || '',
                            onChange: function (id, url) {
                                set({ admin_bar_logo: id, admin_bar_logo_url: url });
                            },
                            onRemove: function () {
                                set({ admin_bar_logo: 0, admin_bar_logo_url: '' });
                            }
                        })
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Login Screen Logo', 'content-core')),
                        el(ImagePicker, {
                            imageId: branding.login_logo || 0,
                            imageUrl: branding.login_logo_url || '',
                            onChange: function (id, url) {
                                set({ login_logo: id, login_logo_url: url });
                            },
                            onRemove: function () {
                                set({ login_logo: 0, login_logo_url: '' });
                            }
                        })
                    )
                )
            )
        );
    }

    // ─── Main App ───────────────────────────────────────────────────────────

    function SiteSettingsApp() {
        const { settings, setSettings, loading, error } = useSettings();
        const [localSettings, setLocalSettings] = useState(null);
        const [saving, setSaving] = useState(false);
        const [activeTab, setActiveTab] = useState(appMode === 'site-profile' ? 'site-options' : (config.activeTab || 'seo'));
        const [profileState, setProfileState] = useState({ loading: true, error: null, schema: {}, values: {} });

        // Sync local state when settings load
        useEffect(function () {
            if (settings) {
                setLocalSettings(settings);
            }
        }, [settings]);

        useEffect(function () {
            if (!siteProfileRestBase) {
                setProfileState({ loading: false, error: __('Site Profile REST route missing.', 'content-core'), schema: {}, values: {} });
                return;
            }
            loadSiteProfile()
                .then(function (data) {
                    setProfileState({
                        loading: false,
                        error: null,
                        schema: data.schema || {},
                        values: data.values || {}
                    });
                })
                .catch(function (err) {
                    setProfileState({
                        loading: false,
                        error: err && err.message ? err.message : __('Failed to load Site Profile.', 'content-core'),
                        schema: {},
                        values: {}
                    });
                });
        }, []);

        function handleProfileChange(fieldId, value) {
            setProfileState(function (prev) {
                return Object.assign({}, prev, {
                    values: Object.assign({}, prev.values || {}, { [fieldId]: value })
                });
            });
        }

        function handleChange(partial) {
            setLocalSettings(function (prev) {
                return Object.assign({}, prev, partial);
            });
        }

        function handleSave() {
            setSaving(true);
            const payload = {
                seo: localSettings.seo || {},
                images: localSettings.images || {},
                cookie: localSettings.cookie || {},
                branding: localSettings.branding || {},
            };

            // Strip preview-only _url fields from images before saving (backend resolves them)
            const cleanImages = {};
            Object.keys(payload.images).forEach(function (k) {
                if (!k.endsWith('_url')) cleanImages[k] = payload.images[k];
            });
            payload.images = cleanImages;

            saveSettings(payload)
                .then(function (newData) {
                    setSaving(false);
                    if (newData) {
                        setSettings(newData);
                        setLocalSettings(newData);
                    }
                    if (window.CCToast && typeof window.CCToast.show === 'function') {
                        window.CCToast.show(__('Settings saved successfully.', 'content-core'), 'success');
                    }
                })
                .catch(function (err) {
                    setSaving(false);
                    let errMsg = __('Save failed.', 'content-core');
                    if (err && err.message) {
                        errMsg = err.message;
                        if (err.code) errMsg += ' (' + err.code + ')';
                    }
                    if (window.CCToast && typeof window.CCToast.show === 'function') {
                        window.CCToast.show(errMsg, 'error');
                    }
                });
        }

        const tabs = appMode === 'site-profile'
            ? [{ id: 'site-options', label: __('Site Profile', 'content-core') }]
            : [
                { id: 'seo', label: __('SEO', 'content-core') },
                { id: 'cookie', label: __('Cookie Banner', 'content-core') },
                { id: 'branding', label: __('Branding', 'content-core') },
                { id: 'site-options', label: __('Site Profile', 'content-core') },
            ];

        if (loading) {
            return el('div', { style: { padding: '40px', textAlign: 'center', color: '#646970' } },
                el('span', { className: 'spinner is-active', style: { float: 'none', margin: '0 8px 0 0' } }),
                __('Loading settings…', 'content-core')
            );
        }

        if (error) {
            return el('div', { className: 'notice notice-error', style: { padding: '12px 16px' } },
                el('p', null, error)
            );
        }

        if (!localSettings && appMode !== 'site-profile') return null;

        return el(Fragment, null,
            // Content
            activeTab === 'seo' && el(SeoTab, { settings: localSettings, onChange: handleChange }),
            activeTab === 'cookie' && el(CookieTab, { settings: localSettings, onChange: handleChange }),
            activeTab === 'branding' && el(BrandingTab, { settings: localSettings, onChange: handleChange }),
            activeTab === 'site-options' && el(SiteOptionsTab, { profileState: profileState, onProfileChange: handleProfileChange }),

            // Footer / Actions
            activeTab !== 'site-options' && appMode !== 'site-profile' && el('div', {
                className: 'cc-form-actions',
                style: {
                    display: 'flex',
                    alignItems: 'center',
                    gap: '16px',
                    marginTop: '40px',
                    padding: '24px',
                    background: 'var(--cc-bg-card)',
                    border: '1px solid var(--cc-border)',
                    borderRadius: 'var(--cc-radius)',
                    boxShadow: 'var(--cc-shadow)'
                }
            },
                el('button', {
                    className: 'cc-button-primary',
                    disabled: saving,
                    onClick: handleSave,
                },
                    el('span', { className: 'dashicons dashicons-saved', style: { fontSize: '18px', width: '18px', height: '18px' } }),
                    saving ? __('Saving...', 'content-core') : __('Save Settings', 'content-core')
                ),
                saving && el('span', { className: 'spinner is-active', style: { float: 'none', marginLeft: '10px' } })
            ),

            activeTab === 'site-options' && el('div', {
                className: 'cc-form-actions',
                style: {
                    display: 'flex',
                    alignItems: 'center',
                    gap: '16px',
                    marginTop: '24px',
                    padding: '20px',
                    background: 'var(--cc-bg-card)',
                    border: '1px solid var(--cc-border)',
                    borderRadius: 'var(--cc-radius)',
                    boxShadow: 'var(--cc-shadow)'
                }
            },
                el('button', {
                    className: 'cc-button-primary',
                    disabled: saving,
                    onClick: function () {
                        setSaving(true);
                        saveSiteProfile(profileState.values || {})
                            .then(function (data) {
                                setSaving(false);
                                setProfileState({
                                    loading: false,
                                    error: null,
                                    schema: data.schema || {},
                                    values: data.values || {}
                                });
                                if (window.CCToast && typeof window.CCToast.show === 'function') {
                                    window.CCToast.show(__('Settings saved successfully.', 'content-core'), 'success');
                                }
                            })
                            .catch(function (err) {
                                setSaving(false);
                                if (window.CCToast && typeof window.CCToast.show === 'function') {
                                    window.CCToast.show((err && err.message) ? err.message : __('Save failed.', 'content-core'), 'error');
                                }
                            });
                    },
                },
                    el('span', { className: 'dashicons dashicons-saved', style: { fontSize: '18px', width: '18px', height: '18px' } }),
                    saving ? __('Saving...', 'content-core') : __('Save Site Profile', 'content-core')
                ),
                saving && el('span', { className: 'spinner is-active', style: { float: 'none', marginLeft: '10px' } })
            )
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        const root = document.getElementById('cc-site-settings-react-root');
        if (root) {
            wp.element.render(el(SiteSettingsApp), root);
        }
    });

})();

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
    const config = window.CC_SITE_SETTINGS || {};
    const nonce = config.nonce || '';
    const restBase = config.restBase || '/wp-json/content-core/v1/settings/site';
    const siteUrl = config.siteUrl || '';
    const siteOptionsUrl = config.siteOptionsUrl || '';

    // ─── Utility ────────────────────────────────────────────────────────────

    function saveSettings(data) {
        return apiFetch({
            url: restBase,
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            data: data,
        });
    }

    function useSettings() {
        const [settings, setSettings] = useState(null);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState(null);

        const load = useCallback(function () {
            setLoading(true);
            setError(null);
            apiFetch({ url: restBase, headers: { 'X-WP-Nonce': nonce } })
                .then(function (data) {
                    setSettings(data);
                    setLoading(false);
                })
                .catch(function (err) {
                    setError(err.message || __('Failed to load settings.', 'content-core'));
                    setLoading(false);
                });
        }, []);

        useEffect(load, [load]);

        return { settings, setSettings, loading, error, reload: load };
    }

    // ─── Toast Notification ─────────────────────────────────────────────────

    function Toast({ message, type, onDismiss }) {
        const bg = type === 'success' ? '#00a32a' : '#d63638';
        return el('div', {
            style: {
                position: 'fixed',
                top: '60px',
                right: '20px',
                zIndex: 99999,
                background: bg,
                color: '#fff',
                padding: '12px 20px',
                borderRadius: '6px',
                boxShadow: '0 4px 20px rgba(0,0,0,0.25)',
                fontSize: '14px',
                fontWeight: 500,
                maxWidth: '400px',
                display: 'flex',
                alignItems: 'center',
                gap: '10px',
            }
        },
            el('span', null, message),
            el('button', {
                onClick: onDismiss,
                style: {
                    background: 'none', border: 'none', color: '#fff',
                    cursor: 'pointer', fontSize: '18px', lineHeight: 1,
                    padding: 0, marginLeft: 'auto',
                }
            }, '×')
        );
    }

    // ─── Image Picker ───────────────────────────────────────────────────────

    function ImagePicker({ label, hint, imageId, imageUrl, onChange, onRemove }) {
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
                const url = att.sizes && att.sizes.large
                    ? att.sizes.large.url
                    : att.url;
                onChange(att.id, url);
            });
            frame.open();
        }

        return el('div', {
            style: {
                border: '1px solid #dcdcde',
                borderRadius: '8px',
                padding: '20px',
                background: '#fff',
                marginBottom: '16px',
            }
        },
            el('div', { style: { display: 'flex', alignItems: 'flex-start', gap: '20px', flexWrap: 'wrap' } },
                // Preview area
                el('div', {
                    style: {
                        width: '140px',
                        minHeight: '100px',
                        border: '2px dashed #dcdcde',
                        borderRadius: '6px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        background: '#f6f7f7',
                        flexShrink: 0,
                        overflow: 'hidden',
                    }
                },
                    imageUrl
                        ? el('img', {
                            src: imageUrl,
                            style: { maxWidth: '100%', maxHeight: '100px', display: 'block', objectFit: 'contain' }
                        })
                        : el('span', { style: { color: '#a7aaad', fontSize: '12px', textAlign: 'center', padding: '8px' } },
                            __('No image', 'content-core')
                        )
                ),
                // Info + actions
                el('div', { style: { flex: 1 } },
                    el('div', { style: { fontWeight: 600, fontSize: '14px', marginBottom: '4px' } }, label),
                    hint && el('div', { style: { fontSize: '12px', color: '#646970', marginBottom: '12px' } }, hint),
                    el('div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
                        el('button', {
                            type: 'button',
                            className: 'button',
                            onClick: openMedia,
                        }, imageId ? __('Replace', 'content-core') : __('Upload', 'content-core')),
                        imageId && el('button', {
                            type: 'button',
                            className: 'button',
                            onClick: onRemove,
                        }, __('Remove', 'content-core'))
                    )
                )
            )
        );
    }

    // ─── SEO Tab ────────────────────────────────────────────────────────────

    function SeoTab({ settings, onChange }) {
        const seo = settings.seo || {};
        const siteTitle = seo.title || '';
        const siteDesc = seo.description || '';

        const previewTitle = siteTitle || config.defaultTitle || '';
        const previewDesc = siteDesc || config.defaultDesc || '';
        const previewUrl = siteUrl || '';

        function handleChange(field, value) {
            onChange({ seo: { ...seo, [field]: value } });
        }

        return el('div', null,
            el('div', { className: 'cc-settings-card' },
                el('h2', { className: 'cc-card-title' }, __('Search Engine Optimisation', 'content-core')),
                el('p', { className: 'cc-card-desc' }, __('Default global SEO values. Individual pages can override these.', 'content-core')),
                el('table', { className: 'form-table' },
                    el('tbody', null,
                        el('tr', null,
                            el('th', { scope: 'row' }, __('Site Title', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    value: siteTitle,
                                    placeholder: config.defaultTitle || '',
                                    onChange: function (e) { handleChange('title', e.target.value); },
                                }),
                                el('p', { className: 'description' }, __('Used in page title templates.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', { scope: 'row' }, __('Default Meta Description', 'content-core')),
                            el('td', null,
                                el('textarea', {
                                    className: 'large-text',
                                    rows: 4,
                                    value: siteDesc,
                                    placeholder: config.defaultDesc || '',
                                    onChange: function (e) { handleChange('description', e.target.value); },
                                }),
                                el('p', { className: 'description' }, __('Fallback description when a page has none.', 'content-core'))
                            )
                        )
                    )
                )
            ),

            // Live SEO Preview
            (previewTitle || previewDesc) && el('div', { className: 'cc-settings-card' },
                el('h3', { style: { margin: '0 0 12px', fontSize: '14px', fontWeight: 600 } }, __('Search Result Preview', 'content-core')),
                el('div', {
                    style: {
                        border: '1px solid #e8eaed',
                        borderRadius: '8px',
                        padding: '16px 20px',
                        background: '#fff',
                        maxWidth: '600px',
                        fontFamily: 'arial, sans-serif',
                    }
                },
                    el('div', { style: { fontSize: '12px', color: '#4d5156', marginBottom: '4px' } }, previewUrl),
                    el('div', { style: { fontSize: '20px', color: '#1a0dab', marginBottom: '4px', lineHeight: 1.3 } }, previewTitle || __('(Site Title)', 'content-core')),
                    el('div', { style: { fontSize: '14px', color: '#4d5156', lineHeight: 1.5 } }, previewDesc || __('(Meta Description)', 'content-core'))
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

        return el('div', null,
            el('div', { className: 'cc-settings-card' },
                el('h2', { className: 'cc-card-title' }, __('Site Images', 'content-core')),
                el('p', { className: 'cc-card-desc' }, __('Manage site-wide images. IDs are stored; URLs are resolved on output.', 'content-core')),

                el(ImagePicker, {
                    label: __('Social Icon', 'content-core'),
                    hint: __('64×64 — Used as site favicon and touch icon.', 'content-core'),
                    imageId: images.social_icon_id || 0,
                    imageUrl: images.social_icon_id_url || '',
                    onChange: handleImageChange('social_icon_id'),
                    onRemove: handleImageRemove('social_icon_id'),
                }),

                el(ImagePicker, {
                    label: __('Social Preview (OG Image)', 'content-core'),
                    hint: __('1200×630 — Default Open Graph image for social sharing.', 'content-core'),
                    imageId: images.og_default_id || 0,
                    imageUrl: images.og_default_id_url || '',
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

        return el('div', null,
            el('div', { className: 'cc-settings-card' },
                el('h2', { className: 'cc-card-title' }, __('Cookie Banner', 'content-core')),
                el('p', { className: 'cc-card-desc' }, __('Configure the consent banner shown to visitors.', 'content-core')),
                el('table', { className: 'form-table' },
                    el('tbody', null,
                        el('tr', null,
                            el('th', null, __('Enable Banner', 'content-core')),
                            el('td', null,
                                el('label', null,
                                    el('input', {
                                        type: 'checkbox',
                                        checked: !!cookie.enabled,
                                        onChange: function (e) { set('enabled', e.target.checked); }
                                    }),
                                    ' ', __('Show cookie consent banner to visitors', 'content-core')
                                )
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Banner Title', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    value: cookie.bannerTitle || '',
                                    onChange: function (e) { set('bannerTitle', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Banner Text', 'content-core')),
                            el('td', null,
                                el('textarea', {
                                    className: 'large-text',
                                    rows: 3,
                                    value: cookie.bannerText || '',
                                    onChange: function (e) { set('bannerText', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Privacy Policy URL', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'url',
                                    className: 'regular-text',
                                    value: cookie.policyUrl || '',
                                    onChange: function (e) { set('policyUrl', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Accept All Label', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    value: labels.acceptAll || '',
                                    onChange: function (e) { setNested('labels', 'acceptAll', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Reject All Label', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    value: labels.rejectAll || '',
                                    onChange: function (e) { setNested('labels', 'rejectAll', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('GA4 Measurement ID', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    placeholder: 'G-XXXXXXXXXX',
                                    value: integrations.ga4MeasurementId || '',
                                    onChange: function (e) { setNested('integrations', 'ga4MeasurementId', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('GTM Container ID', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'text',
                                    className: 'regular-text',
                                    placeholder: 'GTM-XXXXXXX',
                                    value: integrations.gtmContainerId || '',
                                    onChange: function (e) { setNested('integrations', 'gtmContainerId', e.target.value); }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Categories', 'content-core')),
                            el('td', null,
                                ['analytics', 'marketing', 'preferences'].map(function (cat) {
                                    return el('label', { key: cat, style: { display: 'block', marginBottom: '4px' } },
                                        el('input', {
                                            type: 'checkbox',
                                            checked: !!categories[cat],
                                            onChange: function (e) { setNested('categories', cat, e.target.checked); }
                                        }),
                                        ' ', cat.charAt(0).toUpperCase() + cat.slice(1)
                                    );
                                })
                            )
                        )
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

    function SiteOptionsTab() {
        return el('div', null,
            el('div', { className: 'cc-settings-card' },
                el('h2', { className: 'cc-card-title' }, __('Site Options', 'content-core')),
                el('p', { className: 'cc-card-desc' },
                    __('Site Options contain structured content fields (address, phone, email, social links, etc.) that are managed per language. Use the dedicated Site Options page to edit these values.', 'content-core')
                ),
                el('a', {
                    href: siteOptionsUrl,
                    className: 'button button-primary',
                    style: { textDecoration: 'none' },
                }, __('Go to Site Options →', 'content-core'))
            )
        );
    }

    // ─── Main App ───────────────────────────────────────────────────────────

    function SiteSettingsApp() {
        const { settings, setSettings, loading, error } = useSettings();
        const [localSettings, setLocalSettings] = useState(null);
        const [saving, setSaving] = useState(false);
        const [toast, setToast] = useState(null);
        const [activeTab, setActiveTab] = useState('seo');

        // Sync local state when settings load
        useEffect(function () {
            if (settings) {
                setLocalSettings(settings);
            }
        }, [settings]);

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
            };

            // Strip preview-only _url fields from images before saving
            const cleanImages = {};
            Object.keys(payload.images).forEach(function (k) {
                if (!k.endsWith('_url')) cleanImages[k] = payload.images[k];
            });
            payload.images = cleanImages;

            saveSettings(payload)
                .then(function () {
                    setSaving(false);
                    setToast({ message: __('Settings saved.', 'content-core'), type: 'success' });
                    setTimeout(function () { setToast(null); }, 4000);
                })
                .catch(function (err) {
                    setSaving(false);
                    setToast({
                        message: (err && err.message) ? err.message : __('Save failed.', 'content-core'),
                        type: 'error'
                    });
                    setTimeout(function () { setToast(null); }, 6000);
                });
        }

        const tabs = [
            { id: 'seo', label: __('SEO', 'content-core') },
            { id: 'images', label: __('Site Images', 'content-core') },
            { id: 'cookie', label: __('Cookie Banner', 'content-core') },
            { id: 'site-options', label: __('Site Options', 'content-core') },
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

        if (!localSettings) return null;

        return el(Fragment, null,
            // Toast
            toast && el(Toast, {
                message: toast.message,
                type: toast.type,
                onDismiss: function () { setToast(null); }
            }),

            // Tab nav
            el('nav', { className: 'cc-react-tabs', style: { marginBottom: '20px' } },
                tabs.map(function (tab) {
                    return el('button', {
                        key: tab.id,
                        type: 'button',
                        className: 'nav-tab' + (activeTab === tab.id ? ' nav-tab-active' : ''),
                        onClick: function () { setActiveTab(tab.id); },
                    }, tab.label);
                })
            ),

            // Tab content
            activeTab === 'seo' && el(SeoTab, {
                settings: localSettings,
                onChange: handleChange,
            }),
            activeTab === 'images' && el(SiteImagesTab, {
                settings: localSettings,
                onChange: handleChange,
            }),
            activeTab === 'cookie' && el(CookieTab, {
                settings: localSettings,
                onChange: handleChange,
            }),
            activeTab === 'site-options' && el(SiteOptionsTab),

            // Save button
            el('div', { className: 'cc-react-save-row' },
                el('button', {
                    type: 'button',
                    className: 'button button-primary button-large',
                    disabled: saving,
                    onClick: handleSave,
                },
                    saving
                        ? el(Fragment, null, el('span', { className: 'spinner is-active', style: { float: 'none', marginRight: '6px', verticalAlign: 'middle' } }), __('Saving…', 'content-core'))
                        : __('Save Settings', 'content-core')
                )
            )
        );
    }

    // ─── Mount ──────────────────────────────────────────────────────────────

    function mount() {
        const root = document.getElementById('cc-site-settings-react-root');
        if (!root) return;

        // Inject CSS
        const style = document.createElement('style');
        style.textContent = `
            #cc-site-settings-react-root {
                max-width: 900px;
            }
            .cc-settings-card {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 8px;
                padding: 24px;
                margin-bottom: 20px;
            }
            .cc-card-title {
                margin: 0 0 6px;
                font-size: 15px;
                font-weight: 600;
            }
            .cc-card-desc {
                color: #646970;
                margin: 0 0 20px;
                font-size: 13px;
            }
            .cc-react-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 0;
                border-bottom: 1px solid #c3c4c7;
                margin-bottom: 20px !important;
            }
            .cc-react-tabs .nav-tab {
                background: none;
                cursor: pointer;
                border-radius: 0;
                margin: 0;
            }
            .cc-react-save-row {
                margin-top: 24px;
                padding-top: 20px;
                border-top: 1px solid #dcdcde;
            }
        `;
        document.head.appendChild(style);

        wp.element.render(el(SiteSettingsApp), root);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }

})();

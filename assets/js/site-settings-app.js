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
    if (!config || !config.restBase) {
        document.addEventListener('DOMContentLoaded', function () {
            const root = document.getElementById('cc-site-settings-root');
            if (root) {
                root.innerHTML = '<div class="notice notice-error"><p><strong>Content Core Error:</strong> JS Configuration object (CC_SITE_SETTINGS) is missing or incomplete. The React application cannot mount. Please check if your theme properly calls wp_head() and wp_footer(), or if a caching/optimization plugin is blocking script localization.</p></div>';
            }
        });
        return;
    }

    const nonce = config.nonce || '';
    const restBase = config.restBase;
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

    function ImagePicker({ label, hint, imageId, imageUrl, onChange, onRemove, exactWidth, exactHeight }) {
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
                        !!imageId && el('button', {
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
                el('p', { className: 'cc-card-desc' }, __('Manage site-wide images. IDs are stored; URLs are resolved on output.', 'content-core')),

                el(ImagePicker, {
                    label: __('Favicon', 'content-core'),
                    hint: __('64×64 — Exactly 64x64 px. Used as site favicon and touch icon.', 'content-core'),
                    imageId: images.social_icon_id || 0,
                    imageUrl: images.social_icon_id_url || '',
                    exactWidth: 64,
                    exactHeight: 64,
                    onChange: handleImageChange('social_icon_id'),
                    onRemove: handleImageRemove('social_icon_id'),
                }),

                el(ImagePicker, {
                    label: __('Social Preview (OG Image)', 'content-core'),
                    hint: __('1200×630 — Exactly 1200x630 px. Default Open Graph image for social sharing.', 'content-core'),
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

        return el('div', null,
            el('div', { className: 'cc-settings-card' },
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

        return el('div', null,
            el('div', { className: 'cc-settings-card' },
                el('p', { className: 'cc-card-desc' }, __('Configure white-label branding for the client environment. These settings affect the login screen and the WordPress admin interface.', 'content-core')),
                el('table', { className: 'form-table' },
                    el('tbody', null,
                        el('tr', null,
                            el('th', null, __('General Settings', 'content-core')),
                            el('td', null,
                                el('div', { style: { display: 'flex', flexDirection: 'column', gap: '8px' } },
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: !!branding.enabled,
                                            onChange: function (e) { set('enabled', e.target.checked); }
                                        }),
                                        ' ', __('Activate branding overrides', 'content-core')
                                    ),
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: !!branding.exclude_admins,
                                            onChange: function (e) { set('exclude_admins', e.target.checked); }
                                        }),
                                        ' ', __('Do not apply branding to administrator roles', 'content-core')
                                    ),
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: !!branding.remove_wp_mentions,
                                            onChange: function (e) { set('remove_wp_mentions', e.target.checked); }
                                        }),
                                        ' ', __('Hide default WordPress logos and mentions', 'content-core')
                                    )
                                )
                            )
                        ),

                        // --- Admin Branding ---
                        el('tr', null,
                            el('th', { colSpan: 2, style: { padding: '20px 0 10px', borderBottom: '1px solid #eee' } },
                                el('strong', null, __('WordPress Admin Interface', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Admin Bar Color', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'color',
                                    value: branding.custom_primary_color || '#1e1e1e',
                                    onChange: function (e) { set('custom_primary_color', e.target.value); },
                                    style: { verticalAlign: 'middle', marginRight: '8px' }
                                }),
                                el('span', { className: 'description' }, __('Main background color for the top admin bar.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Admin Accent Color', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'color',
                                    value: branding.custom_accent_color || '#2271b1',
                                    onChange: function (e) { set('custom_accent_color', e.target.value); },
                                    style: { verticalAlign: 'middle', marginRight: '8px' }
                                }),
                                el('p', { className: 'description' }, __('Used for primary buttons and active menu states.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Admin Bar Logo', 'content-core')),
                            el('td', null,
                                el('div', { style: { marginBottom: '10px' } },
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: !!branding.use_site_icon_for_admin_bar,
                                            onChange: function (e) { set('use_site_icon_for_admin_bar', e.target.checked); }
                                        }),
                                        ' ', __('Use Site Icon (Favicon)', 'content-core')
                                    )
                                ),
                                !branding.use_site_icon_for_admin_bar && el(ImagePicker, {
                                    imageId: branding.admin_bar_logo || 0,
                                    imageUrl: branding.admin_bar_logo_url || '',
                                    onChange: function (id, url) {
                                        set({
                                            admin_bar_logo: id,
                                            admin_bar_logo_url: url
                                        });
                                    },
                                    onRemove: function () {
                                        set({
                                            admin_bar_logo: 0,
                                            admin_bar_logo_url: ''
                                        });
                                    }
                                }),
                                branding.use_site_icon_for_admin_bar && branding.site_icon_url && el('img', {
                                    src: branding.site_icon_url,
                                    style: { maxHeight: '32px', display: 'block', background: '#f0f0f1', padding: '4px', borderRadius: '4px' }
                                }),
                                el('p', { className: 'description' }, __('Small square image or SVG for the top left corner.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Admin Bar Logo Link', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'url',
                                    className: 'regular-text',
                                    value: branding.admin_bar_logo_link_url || '',
                                    placeholder: 'https://...',
                                    onChange: function (e) { set('admin_bar_logo_link_url', e.target.value); }
                                }),
                                el('p', { className: 'description' }, __('Optional URL for the admin bar logo link.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Admin Footer Text', 'content-core')),
                            el('td', null,
                                el('textarea', {
                                    className: 'large-text',
                                    rows: 2,
                                    value: branding.custom_footer_text || '',
                                    onChange: function (e) { set('custom_footer_text', e.target.value); }
                                }),
                                el('p', { className: 'description' }, __('Replaces the default "Thank you for creating with WordPress".', 'content-core'))
                            )
                        ),

                        // --- Login Branding ---
                        el('tr', null,
                            el('th', { colSpan: 2, style: { padding: '20px 0 10px', borderBottom: '1px solid #eee' } },
                                el('strong', null, __('Login Screen', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Login Screen Background', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'color',
                                    value: branding.login_bg_color || '#f1f1f1',
                                    onChange: function (e) { set('login_bg_color', e.target.value); },
                                    style: { verticalAlign: 'middle', marginRight: '8px' }
                                })
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Login Screen Accent Color', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'color',
                                    value: branding.login_btn_color || '#2271b1',
                                    onChange: function (e) { set('login_btn_color', e.target.value); },
                                    style: { verticalAlign: 'middle', marginRight: '8px' }
                                }),
                                el('p', { className: 'description' }, __('Primary color for the login button and theme accents.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Login Screen Logo', 'content-core')),
                            el('td', null,
                                el(ImagePicker, {
                                    imageId: branding.login_logo || 0,
                                    imageUrl: branding.login_logo_url || '',
                                    onChange: function (id, url) {
                                        set({
                                            login_logo: id,
                                            login_logo_url: url
                                        });
                                    },
                                    onRemove: function () {
                                        set({
                                            login_logo: 0,
                                            login_logo_url: ''
                                        });
                                    }
                                }),
                                el('p', { className: 'description' }, __('Client logo displayed on the login screen.', 'content-core'))
                            )
                        ),
                        el('tr', null,
                            el('th', null, __('Login Screen Logo Link', 'content-core')),
                            el('td', null,
                                el('input', {
                                    type: 'url',
                                    className: 'regular-text',
                                    value: branding.login_logo_link_url || '',
                                    placeholder: 'https://...',
                                    onChange: function (e) { set('login_logo_link_url', e.target.value); }
                                }),
                                el('p', { className: 'description' }, __('Optional link for the login logo.', 'content-core'))
                            )
                        )
                    )
                )
            )
        );
    }

    function SiteOptionsTab() {
        return el('div', { className: 'cc-settings-card' },
            el('p', null, __('Site Options management will be here.', 'content-core'))
        );
    }

    // ─── Main App ───────────────────────────────────────────────────────────

    function SiteSettingsApp() {
        const { settings, setSettings, loading, error } = useSettings();
        const [localSettings, setLocalSettings] = useState(null);
        const [saving, setSaving] = useState(false);
        const [toast, setToast] = useState(null);
        const [activeTab, setActiveTab] = useState(config.activeTab || 'seo');

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
                branding: localSettings.branding || {},
            };

            // Strip preview-only _url fields from images & branding before saving
            const cleanImages = {};
            Object.keys(payload.images).forEach(function (k) {
                if (!k.endsWith('_url')) cleanImages[k] = payload.images[k];
            });
            payload.images = cleanImages;

            payload.images = cleanImages;

            // We keep branding URLs in the payload as they are handled safely by the backend
            // and act as useful fallbacks/hints for SVGs and legacy data.
            payload.branding = localSettings.branding || {};

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
            { id: 'branding', label: __('Branding', 'content-core') },
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
            activeTab === 'branding' && el(BrandingTab, {
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

    // ─── Diagnostics App ──────────────────────────────────────────────────────

    function DiagnosticsApp() {
        const [log, setLog] = useState(null);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState(null);
        const [runningFix, setRunningFix] = useState(null);
        const diagnosticsRestBase = config.diagnosticsRestBase;

        const runChecks = useCallback(function () {
            setLoading(true);
            setError(null);
            apiFetch({
                url: diagnosticsRestBase + '/run',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
            }).then(function (res) {
                setLog(res.log || []);
                setLoading(false);
            }).catch(function (err) {
                setError(err.message || __('Failed to run diagnostics.', 'content-core'));
                setLoading(false);
            });
        }, [diagnosticsRestBase]);

        useEffect(function () {
            runChecks();
        }, [runChecks]);

        const applyFix = function (issue) {
            if (!confirm(__('Are you sure you want to attempt this fix? It is recommended to have a backup.', 'content-core'))) return;
            setRunningFix(issue.issue_id);
            apiFetch({
                url: diagnosticsRestBase + '/fix',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
                data: {
                    check_id: issue.check_id,
                    issue_id: issue.issue_id,
                    context: issue.context
                }
            }).then(function (res) {
                setLog(res.log || []);
                setRunningFix(null);
                alert(__('Fix applied successfully.', 'content-core'));
            }).catch(function (err) {
                setRunningFix(null);
                alert((err && err.message) ? err.message : __('Fix failed.', 'content-core'));
            });
        };

        const clearResolved = function () {
            if (!confirm(__('Clear resolved entries from the log?', 'content-core'))) return;
            apiFetch({
                url: diagnosticsRestBase + '/clear-resolved',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
            }).then(function (res) {
                setLog(res.log || []);
            }).catch(function (err) {
                alert((err && err.message) ? err.message : __('Failed to clear.', 'content-core'));
            });
        };

        const clearAll = function () {
            if (!confirm(__('Are you sure you want to delete ALL log entries?', 'content-core'))) return;
            apiFetch({
                url: diagnosticsRestBase + '/clear-all',
                method: 'POST',
                headers: { 'X-WP-Nonce': nonce },
            }).then(function (res) {
                setLog(res.log || []);
            }).catch(function (err) {
                alert((err && err.message) ? err.message : __('Failed to clear all.', 'content-core'));
            });
        };

        if (error) {
            return el('div', { className: 'notice notice-error cc-settings-card', style: { padding: '24px' } },
                el('p', null, error),
                el('button', { className: 'button', onClick: runChecks }, __('Retry', 'content-core'))
            );
        }

        if (loading && !log) {
            return el('div', { style: { padding: '40px', textAlign: 'center', color: '#646970' } },
                el('span', { className: 'spinner is-active', style: { float: 'none', margin: '0 8px 0 0' } }),
                __('Running Integrity Checks…', 'content-core')
            );
        }

        const activeIssues = (log || []).filter(function (i) { return i.status === 'active'; });
        const resolvedIssues = (log || []).filter(function (i) { return i.status === 'resolved'; });

        return el('div', { className: 'cc-diagnostics-container' },
            el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '20px' } },
                el('h2', { style: { margin: '0 0 10px', fontSize: '18px', fontWeight: 600 } }, __('Health Self-Test', 'content-core')),
                el('div', { style: { display: 'flex', gap: '8px' } },
                    el('button', { className: 'button', style: { color: '#d63638', borderColor: '#d63638' }, onClick: clearAll, disabled: (!log || log.length === 0) }, __('Clear All', 'content-core')),
                    el('button', { className: 'button', onClick: clearResolved, disabled: resolvedIssues.length === 0 }, __('Clear Resolved', 'content-core')),
                    el('button', { className: 'button button-primary', onClick: runChecks, disabled: loading },
                        loading ? __('Running…', 'content-core') : __('Run Checks Again', 'content-core')
                    )
                )
            ),

            activeIssues.length === 0
                ? el('div', { className: 'notice notice-success inline', style: { margin: '0 0 20px', padding: '12px', display: 'flex', alignItems: 'center', gap: '8px', borderLeftColor: '#00a32a', background: '#fff' } },
                    el('span', { className: 'dashicons dashicons-yes-alt', style: { color: '#00a32a' } }),
                    el('span', { style: { fontWeight: 600 } }, __('No active issues detected. System integrity is solid.', 'content-core'))
                )
                : el('div', { className: 'notice notice-warning inline', style: { margin: '0 0 20px', padding: '12px', display: 'flex', alignItems: 'center', gap: '8px', borderLeftColor: '#dba617', background: '#fff' } },
                    el('span', { className: 'dashicons dashicons-warning', style: { color: '#dba617' } }),
                    el('span', { style: { fontWeight: 600 } }, activeIssues.length + ' ' + __('active issue(s) detected.', 'content-core'))
                ),

            activeIssues.length > 0 && el('div', { className: 'cc-settings-card', style: { marginBottom: '20px' } },
                el('h3', { className: 'cc-card-title', style: { borderBottom: '1px solid #dcdcde', paddingBottom: '10px', marginBottom: '16px' } }, __('Active Issues', 'content-core')),
                activeIssues.map(function (issue) {
                    return el('div', { key: issue.issue_id, style: { background: '#f6f7f7', border: '1px solid #c3c4c7', borderRadius: '6px', padding: '16px', marginBottom: '12px' } },
                        el('div', { style: { display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' } },
                            el('div', null,
                                el('div', { style: { fontWeight: 600, fontSize: '14px', marginBottom: '6px' } }, issue.message),
                                el('div', { style: { fontSize: '12px', color: '#646970', fontFamily: 'monospace' } },
                                    'Check: ', issue.check_id
                                )
                            ),
                            issue.can_fix && el('button', {
                                className: 'button button-secondary',
                                disabled: runningFix === issue.issue_id,
                                onClick: function () { applyFix(issue); }
                            }, runningFix === issue.issue_id ? __('Fixing…', 'content-core') : __('Fix', 'content-core'))
                        )
                    );
                })
            ),

            resolvedIssues.length > 0 && el('div', { className: 'cc-settings-card' },
                el('h3', { className: 'cc-card-title', style: { borderBottom: '1px solid #dcdcde', paddingBottom: '10px', marginBottom: '16px' } }, __('Recently Resolved', 'content-core')),
                resolvedIssues.map(function (issue) {
                    return el('div', { key: issue.issue_id, style: { padding: '8px 0', borderBottom: '1px solid #f0f0f1', fontSize: '13px', color: '#646970', display: 'flex', alignItems: 'center' } },
                        el('span', { className: 'dashicons dashicons-yes', style: { color: '#00a32a', fontSize: '16px', width: '16px', height: '16px', marginRight: '6px' } }),
                        el('span', null, issue.message)
                    );
                })
            )
        );
    }

    // ─── Mount ──────────────────────────────────────────────────────────────

    function mount() {
        const settingsRoot = document.getElementById('cc-site-settings-react-root');
        const diagnosticsRoot = document.getElementById('cc-diagnostics-react-root');
        if (!settingsRoot && !diagnosticsRoot) return;

        // Inject CSS
        const style = document.createElement('style');
        style.textContent = `
            #cc-site-settings-react-root {
                max-width: 900px;
            }
            .cc-settings-card {
                background: #fff;
                border: 1px solid #eef0f2;
                border-radius: 12px;
                padding: 24px;
                margin-bottom: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            }
            .cc-settings-card .form-table th {
                width: 240px;
                font-weight: 500;
                color: #1e293b;
            }
            .cc-settings-card input[type=text], 
            .cc-settings-card input[type=url], 
            .cc-settings-card textarea {
                border-color: #cbd5e1;
                border-radius: 6px;
                box-shadow: none;
            }
            .cc-settings-card input:focus, 
            .cc-settings-card textarea:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 2px rgba(34, 113, 177, 0.1);
                outline: none;
            }
            #cc-site-settings-react-root .nav-tab-active {
                border-bottom: 2px solid #2271b1 !important;
                background: none !important;
            }
        `;
        document.head.appendChild(style);

        if (settingsRoot) {
            wp.element.render(el(SiteSettingsApp), settingsRoot);
        }
        if (diagnosticsRoot) {
            wp.element.render(el(DiagnosticsApp), diagnosticsRoot);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }

})();

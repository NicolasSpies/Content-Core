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
                root.innerHTML = '<div class="notice notice-error" style="margin: 20px 0;"><p><strong>Content Core config missing.</strong> Assets not localized. Please check admin enqueue + caching plugins.</p></div>';
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
            className: 'cc-card',
            style: { marginBottom: '24px' }
        },
            el('div', { className: 'cc-card-body', style: { display: 'flex', alignItems: 'flex-start', gap: '24px', flexWrap: 'wrap' } },
                // Preview area
                el('div', {
                    style: {
                        width: '160px',
                        height: '110px',
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
                el('div', { style: { flex: 1, minWidth: '250px' } },
                    el('div', { className: 'cc-field-label', style: { marginBottom: '4px' } }, label),
                    hint && el('div', { className: 'cc-help', style: { marginBottom: '16px' } }, hint),
                    el('div', { style: { display: 'flex', gap: '8px' } },
                        el('button', {
                            type: 'button',
                            className: 'cc-button-secondary',
                            onClick: openMedia,
                            style: { padding: '6px 14px' }
                        }, imageId ? __('Replace', 'content-core') : __('Upload', 'content-core')),
                        !!imageId && el('button', {
                            type: 'button',
                            className: 'cc-button-secondary',
                            onClick: onRemove,
                            style: { padding: '6px 14px', color: 'var(--cc-error)' }
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

        return el('div', { className: 'cc-grid' },
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('Global SEO', 'content-core'))),
                el('div', { className: 'cc-card-body' },
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Site Title', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('input', {
                                type: 'text',
                                value: siteTitle,
                                placeholder: config.defaultTitle || '',
                                onChange: function (e) { handleChange('title', e.target.value); },
                            })
                        ),
                        el('p', { className: 'cc-help' }, __('Global title suffix or fallback. Used in page title templates.', 'content-core'))
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Default Meta Description', 'content-core')),
                        el('div', { className: 'cc-field-input' },
                            el('textarea', {
                                rows: 4,
                                value: siteDesc,
                                placeholder: config.defaultDesc || '',
                                onChange: function (e) { handleChange('description', e.target.value); },
                            })
                        ),
                        el('p', { className: 'cc-help' }, __('Fallback description when a page has no specific SEO text provided.', 'content-core'))
                    )
                )
            ),

            // Live SEO Preview
            el('div', { className: 'cc-card' },
                el('div', { className: 'cc-card-header' }, el('h2', null, __('Search Preview', 'content-core'))),
                el('div', { className: 'cc-card-body', style: { background: 'var(--cc-bg-soft)' } },
                    el('div', {
                        style: {
                            border: '1px solid var(--cc-border)',
                            borderRadius: '8px',
                            padding: '24px',
                            background: '#fff',
                            fontFamily: 'arial, sans-serif',
                            boxShadow: 'var(--cc-shadow)',
                        }
                    },
                        el('div', { style: { fontSize: '13px', color: '#1a0dab', marginBottom: '4px', letterSpacing: 'normal' } }, previewUrl),
                        el('div', { style: { fontSize: '20px', color: '#1a0dab', marginBottom: '4px', lineHeight: 1.3, textDecoration: 'none' } }, previewTitle || __('(Site Title)', 'content-core')),
                        el('div', { style: { fontSize: '14px', color: '#4d5156', lineHeight: 1.5 } }, previewDesc || __('(Meta Description)', 'content-core'))
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
                        el('label', { className: 'cc-field-label' }, __('Admin Bar Background', 'content-core')),
                        el('div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
                            el('input', {
                                type: 'color',
                                value: branding.custom_primary_color || '#1e1e1e',
                                onChange: function (e) { set('custom_primary_color', e.target.value); },
                                style: { width: '44px', height: '44px', padding: '4px', border: '1px solid var(--cc-border)', borderRadius: '4px' }
                            }),
                            el('code', { style: { fontSize: '11px' } }, branding.custom_primary_color || '#1e1e1e')
                        )
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Admin Accent Color', 'content-core')),
                        el('div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
                            el('input', {
                                type: 'color',
                                value: branding.custom_accent_color || '#2271b1',
                                onChange: function (e) { set('custom_accent_color', e.target.value); },
                                style: { width: '44px', height: '44px', padding: '4px', border: '1px solid var(--cc-border)', borderRadius: '4px' }
                            }),
                            el('code', { style: { fontSize: '11px' } }, branding.custom_accent_color || '#2271b1')
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
                    ),
                    el('div', { className: 'cc-field' },
                        el('label', { className: 'cc-field-label' }, __('Login Screen Accent Color', 'content-core')),
                        el('input', {
                            type: 'color',
                            value: branding.login_btn_color || '#2271b1',
                            onChange: function (e) { set('login_btn_color', e.target.value); },
                            style: { width: '44px', height: '44px', padding: '4px', border: '1px solid var(--cc-border)', borderRadius: '4px' }
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
                { id: 'images', label: __('Site Images', 'content-core') },
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
            activeTab === 'images' && el(SiteImagesTab, { settings: localSettings, onChange: handleChange }),
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

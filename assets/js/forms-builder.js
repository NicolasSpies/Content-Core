(function ($) {
    'use strict';

    $(document).ready(function () {
        const $container = $('#cc-form-field-builder');
        if (!$container.length) return;

        const $input = $('#cc_form_fields_json');
        const $typeSelect = $('#cc-add-field-type');
        const $addBtn = $('#cc-add-field-btn');

        let fields = [];

        try {
            fields = JSON.parse($input.val() || '[]');
        } catch (e) {
            fields = [];
        }

        // --- Core Functions ---

        function render() {
            $container.empty();

            if (fields.length === 0) {
                $container.append('<p style="padding: 20px; color: #646970; text-align: center; border: 1px dashed #ccd0d4; background: #fff;">Keine Felder hinzugefügt. Wählen Sie oben einen Typ und klicken Sie auf Hinzufügen.</p>');
            }

            fields.forEach((field, index) => {
                const $fieldItem = $(`
                    <div class="cc-field-item card" data-index="${index}" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px; margin-bottom: 15px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <div class="cc-field-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1;">
                            <div>
                                <span class="cc-field-type-badge" style="background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; text-transform: uppercase;">${field.type}</span>
                                <strong style="margin-left: 10px;">${field.label || '(Unbenannt)'}</strong>
                            </div>
                            <div class="cc-field-actions">
                                <button type="button" class="button-link cc-move-up" title="Nach oben" ${index === 0 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-up-alt2"></span></button>
                                <button type="button" class="button-link cc-move-down" title="Nach unten" ${index === fields.length - 1 ? 'disabled' : ''}><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                                <button type="button" class="button-link cc-remove-field" style="color: #d63638; margin-left: 10px;">Löschen</button>
                            </div>
                        </div>
                        
                        <div class="cc-field-body" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="cc-field-input-group">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Label</label>
                                <input type="text" class="cc-f-label widefat" value="${field.label || ''}">
                            </div>
                            <div class="cc-field-input-group">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Name (Slug)</label>
                                <input type="text" class="cc-f-name widefat" value="${field.name || ''}" placeholder="auto-generiert">
                            </div>
                            
                            ${field.type !== 'consent' ? `
                            <div class="cc-field-input-group">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Platzhalter</label>
                                <input type="text" class="cc-f-placeholder widefat" value="${field.placeholder || ''}">
                            </div>
                            ` : ''}

                            <div class="cc-field-input-group" style="display: flex; align-items: flex-end; padding-bottom: 5px;">
                                <label>
                                    <input type="checkbox" class="cc-f-required" ${field.required ? 'checked' : ''} ${field.type === 'consent' ? 'disabled' : ''}> <strong>Pflichtfeld</strong>
                                </label>
                            </div>
                        </div>

                        ${renderAdvancedConfig(field, index)}
                    </div>
                `);

                $container.append($fieldItem);
            });
        }

        function renderAdvancedConfig(field, index) {
            let html = '';

            if (['select', 'radio', 'multiple'].includes(field.type)) {
                html = renderOptionsEditor(field, index);
            } else if (field.type === 'number') {
                html = `
                    <div class="cc-advanced-config" style="margin-top: 15px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                        <div class="cc-field-input-group">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Min</label>
                            <input type="number" class="cc-f-min widefat" value="${field.min || ''}">
                        </div>
                        <div class="cc-field-input-group">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Max</label>
                            <input type="number" class="cc-f-max widefat" value="${field.max || ''}">
                        </div>
                        <div class="cc-field-input-group">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Schrittweite</label>
                            <input type="number" step="any" class="cc-f-step widefat" value="${field.step || ''}">
                        </div>
                    </div>
                `;
            } else if (field.type === 'file') {
                const allowedStr = (field.allowed_types || ['pdf', 'jpg', 'png']).join(',');
                html = `
                    <div class="cc-advanced-config" style="margin-top: 15px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="cc-field-input-group">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Max. Größe (MB)</label>
                                <input type="number" class="cc-f-max-size widefat" value="${field.max_size_mb || 5}">
                            </div>
                            <div class="cc-field-input-group">
                                <label style="display: block; font-weight: 600; margin-bottom: 5px;">Erlaubte Typen (kommagetrennt)</label>
                                <input type="text" class="cc-f-allowed-types widefat" value="${allowedStr}">
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label>
                                <input type="checkbox" class="cc-f-multiple" ${field.multiple ? 'checked' : ''}> Mehrfacher Upload erlauben
                            </label>
                        </div>
                    </div>
                `;
            } else if (field.type === 'consent') {
                html = `
                    <div class="cc-advanced-config" style="margin-top: 15px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px;">
                        <div class="cc-field-input-group" style="margin-bottom: 10px;">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Einwilligungstext (HTML erlaubt)</label>
                            <textarea class="cc-f-consent-text widefat" rows="2">${field.consent_text || ''}</textarea>
                        </div>
                        <div class="cc-field-input-group">
                            <label style="display: block; font-weight: 600; margin-bottom: 5px;">Link zur Datenschutzerklärung (URL)</label>
                            <input type="url" class="cc-f-consent-url widefat" value="${field.consent_link_url || ''}">
                        </div>
                    </div>
                `;
            }

            return html;
        }

        function renderOptionsEditor(field, index) {
            const ops = field.options || [];
            let html = `
                <div class="cc-options-editor" style="margin-top: 20px; padding: 15px; background: #f6f7f7; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 10px;">Optionen</label>
                    <div class="cc-options-list">
            `;

            ops.forEach((o, i) => {
                html += `
                    <div class="cc-option-row" style="display: flex; gap: 10px; margin-bottom: 8px;" data-opt-index="${i}">
                        <input type="text" class="cc-f-opt-label widefat" value="${o.label || ''}" placeholder="Label">
                        <input type="text" class="cc-f-opt-value widefat" value="${o.value || ''}" placeholder="Wert">
                        <button type="button" class="button cc-remove-option" style="color: #d63638;">&times;</button>
                    </div>
                `;
            });

            html += `
                    </div>
                    <button type="button" class="button button-small cc-add-option">+ Option hinzufügen</button>
                </div>
            `;
            return html;
        }

        function sync() {
            $input.val(JSON.stringify(fields));
        }

        function slugify(text) {
            return text.toString().toLowerCase()
                .replace(/\s+/g, '_')           // Replace spaces with _
                .replace(/[^\w\-]+/g, '')       // Remove all non-word chars
                .replace(/\-\-+/g, '_')         // Replace multiple - with single _
                .replace(/^-+/, '')             // Trim - from start of text
                .replace(/-+$/, '');            // Trim - from end of text
        }

        function ensureUniqueName(name, currentIndex) {
            let finalName = name || 'field_' + (currentIndex + 1);
            let suffix = 2;

            while (fields.some((f, i) => i !== currentIndex && f.name === finalName)) {
                finalName = name + '_' + suffix;
                suffix++;
            }
            return finalName;
        }

        // --- Event Listeners ---

        $addBtn.on('click', function () {
            const type = $typeSelect.val();
            const newField = {
                type: type,
                name: '',
                label: '',
                placeholder: '',
                required: false
            };

            if (['select', 'radio', 'multiple'].includes(type)) {
                newField.options = [{ label: 'Option 1', value: 'option_1' }];
            } else if (type === 'file') {
                newField.max_size_mb = 5;
                newField.allowed_types = ['pdf', 'jpg', 'png'];
                newField.multiple = false;
            } else if (type === 'consent') {
                newField.required = true;
                newField.label = 'Datenschutz';
                newField.consent_text = 'Ich stimme der Datenschutzerklärung zu.';
            }

            fields.push(newField);
            render();
            sync();
        });

        $container.on('click', '.cc-remove-field', function () {
            if (!confirm('Dieses Feld wirklich löschen?')) return;
            const index = $(this).closest('.cc-field-item').data('index');
            fields.splice(index, 1);
            render();
            sync();
        });

        $container.on('input', '.cc-f-label', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            const val = $(this).val();
            fields[index].label = val;

            // Auto-slugify name if name is empty
            const $nameInput = $(this).closest('.cc-field-item').find('.cc-f-name');
            if (fields[index].name === '') {
                let slug = slugify(val);
                if (slug) {
                    slug = ensureUniqueName(slug, index);
                    fields[index].name = slug;
                    $nameInput.val(slug);
                }
            }

            $(this).closest('.cc-field-item').find('.cc-field-header strong').text(val || '(Unbenannt)');
            sync();
        });

        $container.on('blur', '.cc-f-name', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            let val = slugify($(this).val());
            val = ensureUniqueName(val, index);
            fields[index].name = val;
            $(this).val(val);
            sync();
        });

        $container.on('input', '.cc-f-placeholder', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].placeholder = $(this).val();
            sync();
        });

        $container.on('change', '.cc-f-required', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].required = $(this).is(':checked');
            sync();
        });

        // --- Advanced Config Event Listeners ---

        $container.on('input', '.cc-f-min', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].min = $(this).val();
            sync();
        });

        $container.on('input', '.cc-f-max', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].max = $(this).val();
            sync();
        });

        $container.on('input', '.cc-f-step', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].step = $(this).val();
            sync();
        });

        $container.on('input', '.cc-f-max-size', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].max_size_mb = $(this).val();
            sync();
        });

        $container.on('input', '.cc-f-allowed-types', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].allowed_types = $(this).val().split(',').map(s => s.trim().replace('.', '')).filter(s => s !== '');
            sync();
        });

        $container.on('change', '.cc-f-multiple', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].multiple = $(this).is(':checked');
            sync();
        });

        $container.on('input', '.cc-f-consent-text', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].consent_text = $(this).val();
            sync();
        });

        $container.on('input', '.cc-f-consent-url', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            fields[index].consent_link_url = $(this).val();
            sync();
        });

        // --- Options Editor Events ---

        $container.on('click', '.cc-add-option', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            if (!fields[index].options) fields[index].options = [];
            fields[index].options.push({ label: '', value: '' });
            render();
            sync();
        });

        $container.on('click', '.cc-remove-option', function () {
            const fieldIndex = $(this).closest('.cc-field-item').data('index');
            const optIndex = $(this).closest('.cc-option-row').data('opt-index');
            fields[fieldIndex].options.splice(optIndex, 1);
            render();
            sync();
        });

        $container.on('input', '.cc-f-opt-label', function () {
            const fieldIndex = $(this).closest('.cc-field-item').data('index');
            const optIndex = $(this).closest('.cc-option-row').data('opt-index');
            const val = $(this).val();
            fields[fieldIndex].options[optIndex].label = val;

            // Auto-value from label
            const $valInput = $(this).siblings('.cc-f-opt-value');
            if (fields[fieldIndex].options[optIndex].value === '') {
                const slug = slugify(val);
                fields[fieldIndex].options[optIndex].value = slug;
                $valInput.val(slug);
            }
            sync();
        });

        $container.on('input', '.cc-f-opt-value', function () {
            const fieldIndex = $(this).closest('.cc-field-item').data('index');
            const optIndex = $(this).closest('.cc-option-row').data('opt-index');
            fields[fieldIndex].options[optIndex].value = slugify($(this).val());
            sync();
        });

        // --- Sorting ---

        $container.on('click', '.cc-move-up', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            if (index > 0) {
                const temp = fields[index];
                fields[index] = fields[index - 1];
                fields[index - 1] = temp;
                render();
                sync();
            }
        });

        $container.on('click', '.cc-move-down', function () {
            const index = $(this).closest('.cc-field-item').data('index');
            if (index < fields.length - 1) {
                const temp = fields[index];
                fields[index] = fields[index + 1];
                fields[index + 1] = temp;
                render();
                sync();
            }
        });

        // Initial Render
        render();
    });

})(jQuery);

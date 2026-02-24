<?php

namespace Agency\HeadlessFramework\Modules\CustomFields;

class FieldGroupAdmin {

	public function init() {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
	}

	public function enqueue_scripts( $hook ) {
		global $post;
		if ( $hook === 'post-new.php' || $hook === 'post.php' ) {
			if ( $post && $post->post_type === FieldGroupPostType::POST_TYPE ) {
				// Inline script for simplicity instead of an external file for V1
			}
		}
	}

	public function add_meta_boxes() {
		add_meta_box(
			'hwf_field_group_location',
			'Location Rules',
			[ $this, 'render_location_meta_box' ],
			FieldGroupPostType::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'hwf_field_group_fields',
			'Fields',
			[ $this, 'render_fields_meta_box' ],
			FieldGroupPostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_location_meta_box( $post ) {
		$locations = get_post_meta( $post->ID, '_hwf_location_post_types', true );
		if ( ! is_array( $locations ) ) {
			$locations = [];
		}

		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$options_pages = get_option( \Agency\HeadlessFramework\Modules\OptionsPages\OptionsPageAdmin::REGISTRY_KEY, [] );
		
		wp_nonce_field( 'hwf_save_field_group', 'hwf_field_group_nonce' );

		echo '<p>Show this field group if Post Type is equal to:</p>';
		echo '<ul>';
		foreach ( $post_types as $pt ) {
			// Don't allow assigning to the field group itself
			if ( $pt->name === FieldGroupPostType::POST_TYPE ) continue;

			$checked = in_array( $pt->name, $locations, true ) ? 'checked' : '';
			echo sprintf(
				'<li><label><input type="checkbox" name="hwf_location_post_types[]" value="%s" %s> %s</label></li>',
				esc_attr( $pt->name ),
				$checked,
				esc_html( $pt->labels->name )
			);
		}
		echo '</ul>';

		echo '<p style="margin-top: 15px;">Or if Options Page is equal to:</p>';
		echo '<ul>';
		foreach ( $options_pages as $slug => $page ) {
			$val = '__options_page_' . $slug;
			$checked = in_array( $val, $locations, true ) ? 'checked' : '';
			echo sprintf(
				'<li><label><input type="checkbox" name="hwf_location_post_types[]" value="%s" %s> %s</label></li>',
				esc_attr( $val ),
				$checked,
				esc_html( $page['title'] )
			);
		}
		echo '</ul>';
	}

	public function render_fields_meta_box( $post ) {
		$fields_json = get_post_meta( $post->ID, '_hwf_fields_config', true );
		if ( empty( $fields_json ) ) {
			$fields_json = '[]';
		}
		?>
		<div id="hwf-fields-builder">
			<div id="hwf-fields-list"></div>
			<div style="margin-top: 15px;">
				<button type="button" class="button button-primary" id="hwf-add-field-btn">+ Add Field</button>
			</div>
			<!-- Hidden input to store JSON -->
			<textarea name="_hwf_fields_config" id="_hwf_fields_config" style="display:none; width:100%; height:200px;"><?php echo esc_textarea( $fields_json ); ?></textarea>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const fieldsList = document.getElementById('hwf-fields-list');
			const addFieldBtn = document.getElementById('hwf-add-field-btn');
			const hiddenConfig = document.getElementById('_hwf_fields_config');
			
			let fields = [];
			try {
				fields = JSON.parse(hiddenConfig.value);
			} catch (e) {
				fields = [];
			}

			const fieldTypes = {
				'text': 'Text',
				'textarea': 'Textarea',
				'number': 'Number',
				'email': 'Email',
				'url': 'URL',
				'boolean': 'True / False',
				'image': 'Image',
				'file': 'File',
				'section': 'Section (UI Divider)'
			};

			function generateId() {
				return 'field_' + Math.random().toString(36).substr(2, 9);
			}

			function renderFields() {
				fieldsList.innerHTML = '';
				fields.forEach((field, index) => {
					const fieldEl = document.createElement('div');
					fieldEl.className = 'hwf-field-item postbox';
					fieldEl.style.marginBottom = '10px';
					
					const isSection = field.type === 'section';
					const bgColor = isSection ? '#f0f0f1' : '#fff';

					let html = `
						<div class="hwf-field-header" style="background:${bgColor}; padding:10px; cursor:move; border-bottom:1px solid #ccd0d4; display:flex; justify-content:space-between;">
							<strong>${field.label || 'New Field'}</strong> <span style="color:#666;">(${field.name || 'field_name'}) - ${fieldTypes[field.type] || 'Text'}</span>
							<div>
								<button type="button" class="button-link hwf-toggle-field" data-index="${index}">Edit</button> |
								<button type="button" class="button-link-delete hwf-delete-field" style="color:#a00;" data-index="${index}">Delete</button>
							</div>
						</div>
						<div class="hwf-field-body inside" style="display:none; margin:0; padding:15px;">
							<div style="display:flex; gap:15px; flex-wrap:wrap;">
								<div style="flex:1; min-width:200px;">
									<label><strong>Field Label</strong></label>
									<input type="text" class="widefat hwf-input-label" data-index="${index}" value="${escapeHtml(field.label)}" placeholder="e.g. Hero Title">
								</div>
								<div style="flex:1; min-width:200px;">
									<label><strong>Field Name</strong></label>
									<input type="text" class="widefat hwf-input-name" data-index="${index}" value="${escapeHtml(field.name)}" placeholder="e.g. hero_title">
									<p class="description">Must be unique, letters/numbers/underscores only.</p>
								</div>
								<div style="flex:1; min-width:200px;">
									<label><strong>Field Type</strong></label>
									<select class="widefat hwf-input-type" data-index="${index}">
					`;
					
					for (const [key, val] of Object.entries(fieldTypes)) {
						const selected = field.type === key ? 'selected' : '';
						html += `<option value="${key}" ${selected}>${val}</option>`;
					}

					html += `
									</select>
								</div>
							</div>
							${ !isSection ? `
							<div style="margin-top:15px;">
								<label><strong>Instructions</strong></label>
								<textarea class="widefat hwf-input-instructions" data-index="${index}" rows="2">${escapeHtml(field.instructions || '')}</textarea>
							</div>
							` : ''}
						</div>
					`;
					
					fieldEl.innerHTML = html;
					fieldsList.appendChild(fieldEl);
				});
				updateHiddenConfig();
			}

			function escapeHtml(unsafe) {
				return (unsafe||'').toString()
					 .replace(/&/g, "&amp;")
					 .replace(/</g, "&lt;")
					 .replace(/>/g, "&gt;")
					 .replace(/"/g, "&quot;")
					 .replace(/'/g, "&#039;");
			}

			function slugify(text) {
				return text.toString().toLowerCase()
					.replace(/\s+/g, '_')
					.replace(/[^\w\-]+/g, '')
					.replace(/\-\-+/g, '_')
					.replace(/^-+/, '')
					.replace(/-+$/, '');
			}

			function updateHiddenConfig() {
				hiddenConfig.value = JSON.stringify(fields);
			}

			addFieldBtn.addEventListener('click', function() {
				fields.push({
					id: generateId(),
					label: '',
					name: '',
					type: 'text',
					instructions: ''
				});
				renderFields();
				// Auto expand the newly added field
				const items = document.querySelectorAll('.hwf-field-body');
				if(items.length > 0) items[items.length - 1].style.display = 'block';
			});

			fieldsList.addEventListener('input', function(e) {
				const target = e.target;
				const index = parseInt(target.getAttribute('data-index'), 10);
				if (isNaN(index)) return;

				if (target.classList.contains('hwf-input-label')) {
					fields[index].label = target.value;
					// Auto slugify if name is empty
					if (!fields[index].name) {
						fields[index].name = slugify(target.value);
						const nameInput = document.querySelector('.hwf-input-name[data-index="'+index+'"]');
						if (nameInput) nameInput.value = fields[index].name;
					}
				} else if (target.classList.contains('hwf-input-name')) {
					fields[index].name = slugify(target.value);
					target.value = fields[index].name; // Force UI update to slug format
				} else if (target.classList.contains('hwf-input-type')) {
					fields[index].type = target.value;
					renderFields(); // Re-render to show/hide specific type settings if needed
					return;
				} else if (target.classList.contains('hwf-input-instructions')) {
					fields[index].instructions = target.value;
				}
				
				updateHiddenConfig();
				
				// Update header live
				const header = document.querySelector('.hwf-toggle-field[data-index="'+index+'"]').closest('.hwf-field-header');
				header.querySelector('strong').innerText = fields[index].label || 'New Field';
				header.querySelector('span').innerText = '(' + (fields[index].name || 'field_name') + ') - ' + fieldTypes[fields[index].type];
			});

			fieldsList.addEventListener('click', function(e) {
				if (e.target.classList.contains('hwf-toggle-field')) {
					const body = e.target.closest('.hwf-field-item').querySelector('.hwf-field-body');
					body.style.display = body.style.display === 'none' ? 'block' : 'none';
				} else if (e.target.classList.contains('hwf-delete-field')) {
					if (confirm('Delete this field?')) {
						const index = parseInt(e.target.getAttribute('data-index'), 10);
						fields.splice(index, 1);
						renderFields();
					}
				}
			});

			renderFields();

			// For drag and drop sorting V2, we would implement SortableJS here. 
			// For V1 MVP, simple append is sufficient.
		});
		</script>
		<?php
	}

	public function save_meta_boxes( $post_id ) {
		if ( ! isset( $_POST['hwf_field_group_nonce'] ) || ! wp_verify_nonce( $_POST['hwf_field_group_nonce'], 'hwf_save_field_group' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save Location Rules
		$locations = isset( $_POST['hwf_location_post_types'] ) ? array_map( 'sanitize_text_field', $_POST['hwf_location_post_types'] ) : [];
		update_post_meta( $post_id, '_hwf_location_post_types', $locations );

		// Save Fields Config
		if ( isset( $_POST['_hwf_fields_config'] ) ) {
			// In production, we should validate the JSON matches the schema, 
			// but for now we decode/encode to ensure it's valid JSON
			$fields = json_decode( wp_unslash( $_POST['_hwf_fields_config'] ), true );
			if ( is_array( $fields ) ) {
				// Sanitize the config structure
				$sanitized_fields = [];
				foreach ( $fields as $field ) {
					$sanitized_fields[] = [
						'id'           => sanitize_text_field( $field['id'] ?? '' ),
						'label'        => sanitize_text_field( $field['label'] ?? '' ),
						'name'         => sanitize_title_with_dashes( str_replace('_', '-', $field['name'] ?? '') ),
						'type'         => sanitize_text_field( $field['type'] ?? 'text' ),
						'instructions' => sanitize_textarea_field( $field['instructions'] ?? '' ),
					];
					// Note: sanitize_title_with_dashes forces hyphens, but we want underscores for field names.
					// Let's manually sanitize.
					$sanitized_fields[count($sanitized_fields)-1]['name'] = preg_replace('/[^a-z0-9_]/', '', strtolower($field['name'] ?? ''));
				}
				update_post_meta( $post_id, '_hwf_fields_config', wp_json_encode( $sanitized_fields ) );
			}
		}
	}
}

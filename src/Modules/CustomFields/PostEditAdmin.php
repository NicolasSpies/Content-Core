<?php

namespace Agency\HeadlessFramework\Modules\CustomFields;

class PostEditAdmin {

	public function init() {
		add_action( 'add_meta_boxes', [ $this, 'register_dynamic_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_dynamic_meta_boxes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_uploader' ] );
	}

	public function enqueue_media_uploader() {
		wp_enqueue_media();
		// We enqueue a tiny script inline in the render method to bind the media uploader
	}

	public function register_dynamic_meta_boxes( $post_type ) {
		// Find all field groups that apply to this post type
		$groups = $this->get_field_groups_for_post_type( $post_type );

		foreach ( $groups as $group ) {
			add_meta_box(
				'hwf_group_' . $group->ID,
				esc_html( $group->post_title ),
				[ $this, 'render_field_group' ],
				$post_type,
				'normal',
				'high',
				[ 'group_id' => $group->ID ]
			);
		}
	}

	private function get_field_groups_for_post_type( $post_type ) {
		// A simple query to find field groups where "_hwf_location_post_types" contains $post_type
		// Since we stored it as an array, WP serializes it. We use a LIKE query.
		$args = [
			'post_type'      => FieldGroupPostType::POST_TYPE,
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'     => '_hwf_location_post_types',
					'value'   => '"' . $post_type . '"',
					'compare' => 'LIKE'
				]
			]
		];

		$query = new \WP_Query( $args );
		return $query->posts;
	}

	public function render_field_group( $post, $metabox ) {
		$group_id = $metabox['args']['group_id'];
		$fields_json = get_post_meta( $group_id, '_hwf_fields_config', true );
		$fields = json_decode( $fields_json, true );

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			echo '<p>No fields defined for this group.</p>';
			return;
		}

		wp_nonce_field( 'hwf_save_post_data', 'hwf_post_data_nonce' );

		echo '<div class="hwf-fields-container">';
		
		foreach ( $fields as $field ) {
			$this->render_individual_field( $field, $post->ID );
		}

		echo '</div>';
		$this->render_inline_js();
	}

	private function render_individual_field( $field, $post_id ) {
		$name = $field['name'];
		$type = $field['type'];
		$label = $field['label'];
		$instructions = $field['instructions'] ?? '';
		
		$value = get_post_meta( $post_id, $name, true );

		// Render Section
		if ( $type === 'section' ) {
			echo '<hr style="margin: 30px 0 10px;">';
			echo '<h2 style="margin: 0 0 10px; font-size: 1.2em;">' . esc_html( $label ) . '</h2>';
			if ( $instructions ) {
				echo '<p class="description">' . esc_html( $instructions ) . '</p>';
			}
			return;
		}

		echo '<div class="hwf-field-row" style="margin-bottom: 20px;">';
		echo '<label for="' . esc_attr( $name ) . '" style="display:block; font-weight:bold; margin-bottom:5px;">' . esc_html( $label ) . '</label>';

		switch ( $type ) {
			case 'textarea':
				echo '<textarea name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" class="large-text" rows="4">' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'number':
				echo '<input type="number" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
				break;
			case 'boolean':
				$checked = ! empty( $value ) ? 'checked' : '';
				echo '<input type="hidden" name="hwf_fields[' . esc_attr( $name ) . ']" value="0">';
				echo '<label><input type="checkbox" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="1" ' . $checked . '> Yes</label>';
				break;
			case 'image':
			case 'file':
				$btn_label = $type === 'image' ? 'Select Image' : 'Select File';
				$preview = '';
				if ( $value && $type === 'image' ) {
					$img = wp_get_attachment_image_url( $value, 'thumbnail' );
					if ( $img ) {
						$preview = '<br><img src="' . esc_url( $img ) . '" style="max-width:150px; margin-top:10px; display:block;" />';
					}
				} else if ( $value && $type === 'file' ) {
					$url = wp_get_attachment_url( $value );
					$preview = '<br><a href="' . esc_url( $url ) . '" target="_blank" style="display:block; margin-top:10px;">View File</a>';
				}

				echo '<input type="hidden" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
				echo '<button type="button" class="button hwf-media-upload" data-target="' . esc_attr( $name ) . '" data-type="' . esc_attr( $type ) . '">' . $btn_label . '</button>';
				echo '<button type="button" class="button hwf-media-remove" data-target="' . esc_attr( $name ) . '" style="margin-left:5px;' . ( empty( $value ) ? 'display:none;' : '' ) . '">Remove</button>';
				echo '<div class="hwf-media-preview" id="preview_' . esc_attr( $name ) . '">' . $preview . '</div>';
				break;
			case 'email':
				echo '<input type="email" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
				break;
			case 'url':
				echo '<input type="url" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="' . esc_url( $value ) . '" class="regular-text">';
				break;
			case 'text':
			default:
				echo '<input type="text" name="hwf_fields[' . esc_attr( $name ) . ']" id="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" class="regular-text">';
				break;
		}

		if ( $instructions ) {
			echo '<p class="description">' . esc_html( $instructions ) . '</p>';
		}
		
		// Hidden map storage so the REST api can decode type later if needed
		echo '<input type="hidden" name="hwf_field_types[' . esc_attr( $name ) . ']" value="' . esc_attr( $type ) . '">';

		echo '</div>';
	}

	private function render_inline_js() {
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			if (typeof wp === 'undefined' || !wp.media) return;

			document.body.addEventListener('click', function(e) {
				if (e.target.classList.contains('hwf-media-upload')) {
					e.preventDefault();
					const button = e.target;
					const targetId = button.getAttribute('data-target');
					const type = button.getAttribute('data-type');
					const input = document.getElementById(targetId);
					const preview = document.getElementById('preview_' + targetId);
					const removeBtn = document.querySelector('.hwf-media-remove[data-target="' + targetId + '"]');

					const frame = wp.media({
						title: type === 'image' ? 'Select Image' : 'Select File',
						button: { text: 'Use this media' },
						multiple: false,
						library: type === 'image' ? { type: 'image' } : {}
					});

					frame.on('select', function() {
						const attachment = frame.state().get('selection').first().toJSON();
						input.value = attachment.id;
						removeBtn.style.display = 'inline-block';
						if (type === 'image') {
							const url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
							preview.innerHTML = '<br><img src="' + url + '" style="max-width:150px; margin-top:10px; display:block;" />';
						} else {
							preview.innerHTML = '<br><a href="' + attachment.url + '" target="_blank" style="display:block; margin-top:10px;">View File</a>';
						}
					});

					frame.open();
				} else if (e.target.classList.contains('hwf-media-remove')) {
					e.preventDefault();
					const button = e.target;
					const targetId = button.getAttribute('data-target');
					document.getElementById(targetId).value = '';
					document.getElementById('preview_' + targetId).innerHTML = '';
					button.style.display = 'none';
				}
			});
		});
		</script>
		<?php
	}

	public function save_dynamic_meta_boxes( $post_id ) {
		if ( ! isset( $_POST['hwf_post_data_nonce'] ) || ! wp_verify_nonce( $_POST['hwf_post_data_nonce'], 'hwf_save_post_data' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['hwf_fields'] ) && is_array( $_POST['hwf_fields'] ) ) {
			foreach ( $_POST['hwf_fields'] as $key => $value ) {
				$type = $_POST['hwf_field_types'][$key] ?? 'text';
				
				// Basic sanitization based on type
				if ( $type === 'url' ) {
					$value = esc_url_raw( $value );
				} elseif ( $type === 'email' ) {
					$value = sanitize_email( $value );
				} elseif ( $type === 'number' || $type === 'image' || $type === 'file' ) {
					$value = absint( $value );
				} elseif ( $type === 'textarea' ) {
					$value = sanitize_textarea_field( $value ); // or wp_kses_post if HTML is allowed
				} else {
					$value = sanitize_text_field( $value );
				}

				update_post_meta( $post_id, $key, $value );
				
				// Also save the reference type to the hidden meta so REST API knows the type natively
				update_post_meta( $post_id, '_hwf_field_reference_' . $key, $type );
			}
		}
	}
}

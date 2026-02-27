<?php
namespace ContentCore\Modules\CustomFields\Admin;

use ContentCore\Modules\CustomFields\Data\FieldGroupPostType;

class FieldGroupAdmin
{

	/**
	 * Register hooks for the Field Group Admin
	 */
	public function register(): void
	{
		add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
		add_action('save_post_' . FieldGroupPostType::POST_TYPE, [$this, 'save_field_group'], 10, 2);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_footer', [$this, 'render_builder_js']);
	}

	/**
	 * Add meta boxes for Assignments and Field Definitions
	 */
	public function add_meta_boxes(): void
	{
		add_meta_box(
			'cc_assignment_meta_box',
			__('Assignment', 'content-core'),
			[$this, 'render_assignment_meta_box'],
			FieldGroupPostType::POST_TYPE,
			'side',
			'high'
		);

		add_meta_box(
			'cc_fields_meta_box',
			__('Field Definitions', 'content-core'),
			[$this, 'render_fields_meta_box'],
			FieldGroupPostType::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the Assignment meta box
	 */
	public function render_assignment_meta_box(\WP_Post $post): void
	{
		wp_nonce_field('save_cc_assignment', 'cc_assignment_nonce');

		$rules = get_post_meta($post->ID, '_cc_assignment_rules', true);
		if (!is_array($rules)) {
			$rules = [];
		}

		echo '<div id="cc-assignment-builder-app">';
		echo '<input type="hidden" name="cc_assignment_data" id="cc_assignment_data" value="' . esc_attr(wp_json_encode($rules)) . '">';
		echo '<div id="cc-assignment-groups-container"></div>';
		echo '<button type="button" class="button" data-cc-action="add-rule-group" id="cc-add-rule-group-btn">' . esc_html__('+ Add Rule Group', 'content-core') . '</button>';
		echo '</div>';

		add_action('admin_footer', [$this, 'render_assignment_templates']);
	}

	/**
	 * Render the Assignment Builder templates
	 */
	public function render_assignment_templates(): void
	{
		$post_types = get_post_types(['public' => true], 'objects');
		$templates = wp_get_theme()->get_page_templates();
		$taxonomies = get_taxonomies(['public' => true], 'objects');

		// Options Pages for the "Post Type" selector (prefixed)
		$options_pages = get_posts([
			'post_type' => \ContentCore\Modules\OptionsPages\Data\OptionsPagePostType::POST_TYPE,
			'posts_per_page' => -1,
			'post_status' => 'publish',
		]);
		?>
		<script type="text/html" id="tmpl-cc-rule-group">
							<div class="cc-rule-group">
								<div class="cc-rule-group-header">
									<strong><?php esc_html_e('Rule Group', 'content-core'); ?></strong>
									<button type="button" class="cc-remove-rule-group-btn cc-remove-btn-subtle" data-cc-action="remove-rule-group" title="<?php esc_attr_e('Remove Group', 'content-core'); ?>">&times;</button>
								</div>
								<div class="cc-rule-group-content">
									<div class="cc-rules-container"></div>
								</div>
							</div>
						</script>

		<script type="text/html" id="tmpl-cc-rule-row">
							<div class="cc-rule-row">
								<select class="cc-rule-type">
									<option value="post_type" <# if(data.type==='post_type') print('selected'); #>><?php esc_html_e('Post Type', 'content-core'); ?></option>
									<option value="page" <# if(data.type==='page') print('selected'); #>><?php esc_html_e('Specific Page', 'content-core'); ?></option>
									<option value="page_template" <# if(data.type==='page_template') print('selected'); #>><?php esc_html_e('Page Template', 'content-core'); ?></option>
									<option value="taxonomy_term" <# if(data.type==='taxonomy_term') print('selected'); #>><?php esc_html_e('Taxonomy Term', 'content-core'); ?></option>
								</select>
								<div class="cc-rule-value-wrap" style="flex-grow:1;">
									<!-- Value inputs will be swapped via JS based on type -->
								</div>
							</div>
						</script>

		<!-- Rule Value Templates -->
		<script type="text/html" id="tmpl-cc-rule-value-post_type">
									<select class="cc-rule-value" style="width:100%;">
										<?php foreach ($post_types as $pt):
											if ($pt->name === FieldGroupPostType::POST_TYPE)
												continue; ?>
														<option value="<?php echo esc_attr($pt->name); ?>" <# if(data.value==='<?php echo $pt->name; ?>') print('selected'); #>><?php echo esc_html($pt->label); ?></option>
													<?php
										endforeach; ?>
										<?php if (!empty($options_pages)): ?>
														<optgroup label="<?php esc_attr_e('Options Pages', 'content-core'); ?>">
															<?php foreach ($options_pages as $op):
																$key = 'cc_option_page_' . $op->post_name; ?>
																			<option value="<?php echo esc_attr($key); ?>" <# if(data.value==='<?php echo $key; ?>') print('selected'); #>><?php echo esc_html($op->post_title); ?></option>
																		<?php
															endforeach; ?>
														</optgroup>
													<?php
										endif; ?>
									</select>
								</script>

		<script type="text/html" id="tmpl-cc-rule-value-page">
									<select class="cc-rule-value" style="width:100%;">
										<?php
										$pages = get_pages(['sort_column' => 'post_title']);
										foreach ($pages as $p):
											?>
														<option value="<?php echo esc_attr($p->ID); ?>" <# if(data.value==='<?php echo $p->ID; ?>') print('selected'); #>><?php echo esc_html($p->post_title); ?></option>
													<?php
										endforeach; ?>
									</select>
								</script>

		<script type="text/html" id="tmpl-cc-rule-value-page_template">
									<select class="cc-rule-value" style="width:100%;">
										<option value="default"><?php esc_html_e('Default Template', 'content-core'); ?></option>
										<?php foreach ($templates as $label => $file): ?>
														<option value="<?php echo esc_attr($file); ?>" <# if(data.value==='<?php echo $file; ?>') print('selected'); #>><?php echo esc_html($label); ?></option>
													<?php
										endforeach; ?>
									</select>
								</script>

		<script type="text/html" id="tmpl-cc-rule-value-taxonomy_term">
									<div style="display:flex; gap:5px;">
										<select class="cc-rule-taxonomy" style="width:50%;">
											<?php foreach ($taxonomies as $tax): ?>
															<option value="<?php echo esc_attr($tax->name); ?>" <# if(data.taxonomy==='<?php echo $tax->name; ?>') print('selected'); #>><?php echo esc_html($tax->label); ?></option>
														<?php
											endforeach; ?>
										</select>
										<select class="cc-rule-value" style="width:50%;">
											<# 
											var currentTax = data.taxonomy || '<?php echo current(array_keys($taxonomies)); ?>';
											var terms = ccTaxTerms[currentTax] || [];
											#>
											<# terms.forEach(function(term) { #>
												<option value="{{term.id}}" <# if(data.value == term.id) print('selected'); #>>{{term.name}}</option>
											<# }); #>
										</select>
									</div>
								</script>
		<?php
	}

	/**
	 * Render the Fields meta box
	 */
	public function render_fields_meta_box(\WP_Post $post): void
	{
		wp_nonce_field('save_cc_fields', 'cc_fields_nonce');

		$fields = get_post_meta($post->ID, '_cc_fields', true);
		if (!is_array($fields)) {
			$fields = [];
		} else {
			// Guarantee the editor frontend receives the normalized tree structure
			$fields = \ContentCore\Modules\CustomFields\Data\FieldRegistry::normalize_field_tree($fields);
		}

		// Inject Vue or vanilla JS simple builder UI here.
		// For Phase 1, we will render a simple DOM-based JS builder to avoid build steps.
		echo '<div id="cc-fields-builder-app">';
		echo '<input type="hidden" name="cc_fields_data" id="cc_fields_data" value="' . esc_attr(wp_json_encode($fields)) . '">';
		echo '<div id="cc-fields-container"></div>';
		echo '<button type="button" class="button button-primary" data-cc-action="add-field" id="cc-add-field-btn">' . esc_html__('+ Add Field', 'content-core') . '</button>';
		echo '</div>';

		add_action('admin_footer', [$this, 'render_field_template']);
	}

	/**
	 * Template for a new field row
	 */
	public function render_field_template(): void
	{
		?>
		<script type="text/html" id="tmpl-cc-field-row">
							<div class="cc-field-definition cc-field-row cc-card" data-key="{{data.key}}" style="margin-bottom: 15px; border: 1px solid #ccd0d4; background: #fff; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
								<div class="cc-field-definition-header cc-field-header" style="padding: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; background: #fafafa;">
									<div style="display: flex; align-items: center; gap: 10px;">
										<span class="dashicons dashicons-menu cc-drag-handle" style="color: #a0a5aa; cursor: grab;" title="<?php esc_attr_e('Drag to reorder', 'content-core'); ?>"></span>
										<strong>
											<span class="cc-field-label-display" style="font-size: 14px;">{{data.label || '<?php esc_html_e('New Field', 'content-core'); ?>'}}</span> 
											<small class="cc-field-name-display" style="color: #646970; font-weight: normal; margin-left: 8px;">{{data.name ? '(' + data.name + ')' : ''}}</small>
										</strong>
									</div>
									<div class="cc-field-actions" style="display: flex; align-items: center; gap: 10px;">
										<span class="cc-field-type-display" style="color: #646970; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; background: #f0f0f1; padding: 3px 6px; border-radius: 3px;">{{data.type}}</span>
										<button type="button" class="button button-small cc-remove-field-btn" data-cc-action="remove-field" style="color: #d63638; border-color: transparent; background: transparent;"><?php esc_html_e('Remove', 'content-core'); ?></button>
									</div>
								</div>
								<div class="cc-field-definition-content cc-field-settings" style="padding: 15px;">
									<table class="form-table" style="margin-top: 0;">
												<tr class="cc-setting-label">
													<th><label>Field Label</label></th>
													<td><input type="text" class="regular-text cc-input-label" value="{{data.label}}"></td>
												</tr>
												<tr class="cc-setting-name">
													<th><label>Field Name</label></th>
													<td><input type="text" class="regular-text cc-input-name" value="{{data.name}}"><br><small>Single word, no spaces. Underscores and dashes allowed.</small></td>
												</tr>
												<tr class="cc-setting-description">
													<th><label>Description</label></th>
													<td><textarea class="large-text cc-input-description" rows="2">{{data.description}}</textarea><br><small>Optional. Displayed below the field or section title.</small></td>
												</tr>
												<tr class="cc-setting-type">
													<td>
														<select class="cc-input-type">
															<optgroup label="Layout">
																<option value="section" <# if(data.type==='section') print('selected'); #>>Section</option>
															</optgroup>
															<optgroup label="Basic">
																<option value="text" <# if(data.type==='text') print('selected'); #>>Text</option>
																<option value="textarea" <# if(data.type==='textarea') print('selected'); #>>Textarea</option>
																<option value="number" <# if(data.type==='number') print('selected'); #>>Number</option>
																<option value="email" <# if(data.type==='email') print('selected'); #>>Email</option>
																<option value="url" <# if(data.type==='url') print('selected'); #>>URL</option>
																<option value="boolean" <# if(data.type==='boolean') print('selected'); #>>Boolean (True/False)</option>
															</optgroup>
															<optgroup label="Content">
																<option value="image" <# if(data.type==='image') print('selected'); #>>Image</option>
																<option value="file" <# if(data.type==='file') print('selected'); #>>File</option>
																<option value="gallery" <# if(data.type==='gallery') print('selected'); #>>Gallery</option>
															</optgroup>
															<optgroup label="Structure">
																<option value="repeater" <# if(data.type==='repeater') print('selected'); #>>Repeater</option>
																<option value="group" <# if(data.type==='group') print('selected'); #>>Group</option>
															</optgroup>
														</select>
													</td>
												</tr>
												<tr class="cc-setting-default">
													<th><label>Default Value</label></th>
													<td><input type="text" class="regular-text cc-input-default" value="{{data.default_value}}"></td>
												</tr>
												<tr class="cc-setting-required">
													<th><label>Required?</label></th>
													<td><input type="checkbox" class="cc-input-required" value="1" <# if(data.required) print('checked'); #>> Yes</td>
												</tr>
												<tr class="cc-setting-section" style="display:none;">
													<th><label>Collapsible?</label></th>
													<td>
														<label><input type="checkbox" class="cc-input-collapsible" value="1" <# if(data.collapsible) print('checked'); #>> Allow this section to be collapsed</label>
													</td>
												</tr>
												<tr class="cc-setting-section-default-state" style="display:none;">
													<th><label>Default State</label></th>
													<td>
														<select class="cc-input-default-state">
															<option value="expanded" <# if(data.default_state==='expanded') print('selected'); #>>Expanded</option>
															<option value="collapsed" <# if(data.default_state==='collapsed') print('selected'); #>>Collapsed</option>
														</select>
													</td>
												</tr>
											</table>
					
											<!-- Sub-fields Builder -->
											<div class="cc-sub-fields-wrap cc-card" style="<# if(data.type!=='section' && data.type!=='repeater' && data.type!=='group') print('display:none;'); #> margin-top: 20px; background: var(--cc-bg-soft);">
												<h4 style="margin-top: 0; padding: 15px 15px 0 15px;"><?php esc_html_e('Child Fields', 'content-core'); ?></h4>
						
												<div class="cc-sub-fields-container inner-sortable-list" style="min-height: 60px; padding: 15px; border: 2px dashed #c3c4c7; background: #f6f7f7; margin: 15px;">
													<!-- Children injected here by renderNode logic -->
												</div>

												<div style="padding: 0 15px 15px 15px;">
													<button type="button" class="button cc-add-inner-field-btn" data-cc-action="add-inner-field">+ Add Field Here</button>
												</div>
											</div>

											<input type="hidden" class="cc-input-key" value="{{data.key}}">
										</div>
									</div>
								</script>
		<?php
	}

	/**
	 * Save the meta box data
	 */
	public function save_field_group(int $post_id, \WP_Post $post): void
	{
		// Verify nonces
		if (!isset($_POST['cc_assignment_nonce'], $_POST['cc_fields_nonce'])) {
			return;
		}
		if (
			!wp_verify_nonce($_POST['cc_assignment_nonce'], 'save_cc_assignment') ||
			!wp_verify_nonce($_POST['cc_fields_nonce'], 'save_cc_fields')
		) {
			return;
		}

		// Prevent saving during autosave
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		// Check permissions
		if (!current_user_can('manage_options')) {
			return;
		}

		// Save Assignments
		if (isset($_POST['cc_assignment_data'])) {
			$assignment_json = stripslashes($_POST['cc_assignment_data']);
			$rule_groups = json_decode($assignment_json, true);

			if (is_array($rule_groups)) {
				$sanitized_groups = [];
				foreach ($rule_groups as $group) {
					if (!isset($group['rules']) || !is_array($group['rules']))
						continue;

					$sanitized_rules = [];
					foreach ($group['rules'] as $rule) {
						$sanitized_rules[] = [
							'type' => sanitize_text_field($rule['type'] ?? 'post_type'),
							'taxonomy' => sanitize_text_field($rule['taxonomy'] ?? ''),
							'value' => sanitize_text_field($rule['value'] ?? ''),
							'operator' => '==' // Reserved for future use
						];
					}
					if (!empty($sanitized_rules)) {
						$sanitized_groups[] = ['rules' => $sanitized_rules];
					}
				}
				update_post_meta($post_id, '_cc_assignment_rules', $sanitized_groups);
			} else {
				delete_post_meta($post_id, '_cc_assignment_rules');
			}
		}

		// Save Fields
		if (isset($_POST['cc_fields_data'])) {
			$fields_json = stripslashes($_POST['cc_fields_data']);
			$fields = json_decode($fields_json, true);

			if (is_array($fields)) {
				update_post_meta($post_id, '_cc_fields', $fields);
			} else {
				update_post_meta($post_id, '_cc_fields', []);
			}
		}
	}

	/**
	 * Enqueue admin scripts for the builder
	 */
	public function enqueue_scripts($hook): void
	{
		if ('post.php' !== $hook && 'post-new.php' !== $hook) {
			return;
		}

		$post_type = get_post_type();
		if (FieldGroupPostType::POST_TYPE !== $post_type) {
			return;
		}

		if (WP_DEBUG) {
			error_log(sprintf('[Content Core] Enqueueing builder assets on hook: %s, screen: %s', $hook, get_current_screen()->id));
		}

		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-sortable');
		wp_enqueue_script('wp-util');

		// Enqueue modern assets
		wp_enqueue_style(
			'cc-admin-modern',
			plugins_url('assets/css/admin.css', dirname(__DIR__, 4)),
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'cc-admin-modern',
			plugins_url('assets/js/admin.js', dirname(__DIR__, 4)),
			['jquery', 'wp-util', 'jquery-ui-sortable'],
			'1.0.0',
			true
		);

		// Pass taxonomy terms to JS for Assignment Builder
		$taxonomies = get_object_taxonomies(get_post_types(['public' => true]), 'objects');
		$tax_data = [];
		foreach ($taxonomies as $tax) {
			$terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => false]);
			$tax_data[$tax->name] = array_map(function ($t) {
				return ['id' => $t->term_id, 'name' => $t->name];
			}, is_wp_error($terms) ? [] : $terms);
		}

		wp_localize_script(
			'cc-admin-modern',
			'ccTaxTerms',
			$tax_data
		);
	}

	/**
	 * Render the JavaScript for the Field and Assignment Builders
	 */
	public function render_builder_js(): void
	{
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || $screen->post_type !== FieldGroupPostType::POST_TYPE || !in_array($screen->base, ['post', 'post-new'], true)) {
			return;
		}
		?>
		<script>
			jQuery(document).ready(function ($) {
				// --- Assignment Builder Logic ---
				var assignmentContainer = $('#cc-assignment-groups-container');
				var assignmentData = $('#cc_assignment_data').val();
				var ruleGroups = assignmentData ? JSON.parse(assignmentData) : [];
				var groupTemplate = wp.template('cc-rule-group');
				var ruleTemplate = wp.template('cc-rule-row');

				function renderAssignments() {
					assignmentContainer.empty();
					ruleGroups.forEach(function (group, gIndex) {
						var $group = $(groupTemplate(group));
						$group.data('index', gIndex);
						var ruleList = $group.find('.cc-rules-container');

						if (group.rules) {
							group.rules.forEach(function (rule, rIndex) {
								var $rule = $(ruleTemplate($.extend({}, rule, { isFirst: rIndex === 0 })));
								$rule.data('index', rIndex);

								// Load value selector
								var valTmpl = wp.template('cc-rule-value-' + rule.type);
								$rule.find('.cc-rule-value-wrap').html(valTmpl(rule));

								ruleList.append($rule);
							});
						}
						assignmentContainer.append($group);
					});
					updateHiddenAssignments();
				}

				function updateHiddenAssignments() {
					var newGroups = [];
					assignmentContainer.find('.cc-rule-group').each(function () {
						var rules = [];
						$(this).find('.cc-rule-row').each(function () {
							var $r = $(this);
							var type = $r.find('.cc-rule-type').val();
							var rule = { type: type, value: $r.find('.cc-rule-value').val() };
							if (type === 'taxonomy_term') {
								rule.taxonomy = $r.find('.cc-rule-taxonomy').val();
							}
							rules.push(rule);
						});
						if (rules.length) newGroups.push({ rules: rules });
					});
					ruleGroups = newGroups;
					$('#cc_assignment_data').val(JSON.stringify(ruleGroups));
				}

				$('#cc-add-rule-group-btn').on('click', function () {
					ruleGroups.push({ rules: [{ type: 'post_type', value: 'post' }] });
					renderAssignments();
				});

				assignmentContainer.on('click', '.cc-remove-rule-group-btn', function () {
					var idx = $(this).closest('.cc-rule-group').data('index');
					ruleGroups.splice(idx, 1);
					renderAssignments();
				});

				assignmentContainer.on('change', '.cc-rule-type', function () {
					var $row = $(this).closest('.cc-rule-row');
					var type = $(this).val();
					var valTmpl = wp.template('cc-rule-value-' + type);
					$row.find('.cc-rule-value-wrap').html(valTmpl({ type: type, value: '' }));
					updateHiddenAssignments();
				});

				assignmentContainer.on('change', '.cc-rule-taxonomy', function () {
					var tax = $(this).val();
					var $row = $(this).closest('.cc-rule-row');
					var $val = $row.find('.cc-rule-value');
					$val.empty();
					var terms = ccTaxTerms[tax] || [];
					terms.forEach(function (term) {
						$val.append($('<option>', { value: term.id, text: term.name }));
					});
					updateHiddenAssignments();
				});

				assignmentContainer.on('change', '.cc-rule-value', function () {
					updateHiddenAssignments();
				});

				renderAssignments();

				// --- Field Builder Logic ---
				var fieldsData = $('#cc_fields_data').val();
				var fields = fieldsData ? JSON.parse(fieldsData) : [];
				var container = $('#cc-fields-container');
				var template = wp.template('cc-field-row');

				function renderFields() {
					container.empty();
					fields.forEach(function (field, index) {
						container.append(renderNode(field));
					});
					updateHiddenData();
					initSortable();
				}

				function renderNode(field) {
					if (!field.key) {
						field.key = 'field_' + Math.random().toString(36).substr(2, 9);
					}
					var html = template(field);
					var $row = $(html);
					$row.data('key', field.key);
					updateFieldUI($row, field.type);
					$row.find('.cc-field-settings').hide();

					if (field.sub_fields && field.sub_fields.length > 0) {
						var $subContainer = $row.find('.cc-sub-fields-container').first();
						field.sub_fields.forEach(function (subField) {
							$subContainer.append(renderNode(subField));
						});
					}

					return $row;
				}

				function updateFieldUI($row, type) {
					if (type === 'section') {
						$row.find('.cc-setting-name, .cc-setting-default, .cc-setting-required').hide();
						$row.find('.cc-setting-section').show();
						if ($row.find('.cc-input-collapsible').is(':checked')) {
							$row.find('.cc-setting-section-default-state').show();
						} else {
							$row.find('.cc-setting-section-default-state').hide();
						}
						$row.addClass('cc-is-section');
					} else {
						$row.removeClass('cc-is-section');
					}

					if (type === 'ui_section') {
						$row.find('.cc-setting-name, .cc-setting-default, .cc-setting-required, .cc-setting-section').hide();
					} else if (type === 'gallery') {
						$row.find('.cc-setting-default, .cc-setting-section').hide();
						$row.find('.cc-setting-name, .cc-setting-required').show();
					} else if (type !== 'section') {
						$row.find('.cc-setting-section').hide();
						$row.find('.cc-setting-name, .cc-input-name, .cc-setting-default, .cc-setting-required').show();
					}

					if (type === 'section' || type === 'repeater' || type === 'group') {
						$row.find('.cc-sub-fields-wrap').show();
					} else {
						$row.find('.cc-sub-fields-wrap').hide();
					}
				}

				function initSortable() {
					if ($('#cc-fields-container, .inner-sortable-list').data('ui-sortable')) {
						$('#cc-fields-container, .inner-sortable-list').sortable('destroy');
					}
					$('#cc-fields-container, .inner-sortable-list').sortable({
						handle: '.cc-drag-handle',
						items: '> .cc-field-row',
						connectWith: '#cc-fields-container, .inner-sortable-list',
						update: function (event, ui) {
							updateHiddenData();
						}
					});
				}

				function extractFieldsData($list) {
					var extractedFields = [];
					$list.children('.cc-field-row').each(function () {
						var row = $(this);
						var key = row.data('key');
						var type = row.find('> .cc-field-settings .cc-input-type').val();

						var field = {
							key: key,
							label: row.find('> .cc-field-settings .cc-input-label').val(),
							name: row.find('> .cc-field-settings .cc-input-name').val(),
							type: type,
							default_value: row.find('> .cc-field-settings .cc-input-default').val(),
							required: row.find('> .cc-field-settings .cc-input-required').is(':checked'),
							description: row.find('> .cc-field-settings .cc-input-description').val() || '',
							style: row.find('> .cc-field-settings .cc-input-style').val() || 'default',
							collapsible: row.find('> .cc-field-settings .cc-input-collapsible').is(':checked'),
							default_state: row.find('> .cc-field-settings .cc-input-default-state').val() || 'expanded'
						};

						if (type === 'section' || type === 'repeater' || type === 'group') {
							var $innerList = row.find('> .cc-field-settings .cc-sub-fields-container');
							field.sub_fields = extractFieldsData($innerList);
						}

						extractedFields.push(field);
					});
					return extractedFields;
				}

				function updateHiddenData() {
					fields = extractFieldsData(container);
					$('#cc_fields_data').val(JSON.stringify(fields));
				}

				$('#cc-add-field-btn').on('click', function (e) {
					e.preventDefault();
					var fieldData = {
						key: 'field_' + Math.random().toString(36).substr(2, 9),
						label: 'New Section',
						name: 'section_' + Math.floor(Math.random() * 1000),
						type: 'section',
						default_value: '',
						required: false,
						description: '',
						style: 'default',
						collapsible: false,
						default_state: 'expanded',
						sub_fields: []
					};
					var $newNode = renderNode(fieldData);
					container.append($newNode);
					$newNode.find('> .cc-field-settings').show();
					updateHiddenData();
					initSortable();
				});

				container.on('click', '.cc-add-inner-field-btn', function (e) {
					e.preventDefault();
					var $targetList = $(this).closest('.cc-sub-fields-wrap').find('.cc-sub-fields-container').first();
					var fieldData = {
						key: 'field_' + Math.random().toString(36).substr(2, 9),
						label: 'New Field',
						name: 'new_field_' + Math.floor(Math.random() * 1000),
						type: 'text',
						default_value: '',
						required: false,
						description: '',
						style: 'default',
						collapsible: false,
						default_state: 'expanded',
						sub_fields: []
					};
					var $newNode = renderNode(fieldData);
					$targetList.append($newNode);
					$newNode.find('> .cc-field-settings').show();
					updateHiddenData();
					initSortable();
				});

				container.on('click', '.cc-remove-field-btn', function (e) {
					e.stopPropagation();
					if (confirm('Are you sure you want to remove this field?')) {
						$(this).closest('.cc-field-row').remove();
						updateHiddenData();
					}
				});

				container.on('click', '.cc-field-header', function (e) {
					if ($(e.target).closest('.cc-remove-field-btn, input, select, .cc-drag-handle, button').length) return;
					$(this).closest('.cc-field-row').find('> .cc-field-settings').slideToggle();
				});

				container.on('change keyup', 'input, select, textarea', function () {
					var row = $(this).closest('.cc-field-row');

					if ($(this).hasClass('cc-input-label')) {
						row.find('> .cc-field-header .cc-field-label-display').text($(this).val());
					}
					if ($(this).hasClass('cc-input-name')) {
						row.find('> .cc-field-header .cc-field-name-display').text('(' + $(this).val() + ')');
					}
					if ($(this).hasClass('cc-input-type')) {
						row.find('> .cc-field-header .cc-field-type-display').text($(this).val());
						updateFieldUI(row, $(this).val());
					}

					if ($(this).hasClass('cc-input-collapsible')) {
						if ($(this).is(':checked')) {
							row.find('> .cc-field-settings .cc-setting-section-default-state').show();
						} else {
							row.find('> .cc-field-settings .cc-setting-section-default-state').hide();
						}
					}

					updateHiddenData();
				});

				renderFields();
			});
		</script>
		<?php
	}
}
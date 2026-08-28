<?php
/**
 * Public API for themes.
 *
 *   bac_get_form( $id )              — one cached form definition (array|null)
 *   bac_get_forms()                  — list of active forms
 *   bac_render_form( $id, $args )    — echo a ready-to-submit form
 *   bac_ac_field_name( $field )      — map an AC field definition to a form name
 *   bac_get_form_list_id( $form )    — resolve the target list for a form
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch a cached ActiveCampaign form definition.
 *
 * @param int $form_id ActiveCampaign form ID.
 * @return array|null
 */
function bac_get_form( $form_id ) {
	return BAC_Forms_Table::get( $form_id );
}

/**
 * List active (synced) forms.
 *
 * @return array[]
 */
function bac_get_forms() {
	return BAC_Forms_Table::all_active();
}

/**
 * Map an ActiveCampaign field definition to the form field "name" the
 * plugin's submit handler expects.
 *
 * @param array $field One entry from a form's fields_data (cfields).
 * @return string Empty for display-only field types.
 */
function bac_ac_field_name( $field ) {
	$type = isset( $field['type'] ) ? $field['type'] : '';

	switch ( $type ) {
		case 'firstname':
			return 'first_name';
		case 'lastname':
			return 'last_name';
		case 'email':
			return 'email';
		case 'phone':
			return 'phone';
		case 'header':
		case 'html':
			return '';
		default:
			return ! empty( $field['id'] ) ? 'field[' . absint( $field['id'] ) . ']' : '';
	}
}

/**
 * Work out which ActiveCampaign list a form subscribes contacts to.
 *
 * Looks through the form's stored action data for a list reference. Returns
 * 0 when none can be determined (the submit handler then reports a
 * configuration error rather than guessing).
 *
 * @param array $form Form array from bac_get_form().
 * @return int
 */
function bac_get_form_list_id( $form ) {
	$action = isset( $form['action_data'] ) && is_array( $form['action_data'] ) ? $form['action_data'] : array();

	// Common shapes seen in ActiveCampaign form definitions.
	if ( ! empty( $action['lists'] ) && is_array( $action['lists'] ) ) {
		$first = reset( $action['lists'] );
		if ( is_array( $first ) && isset( $first['id'] ) ) {
			return absint( $first['id'] );
		}
		return absint( $first );
	}

	if ( ! empty( $action['list'] ) ) {
		return absint( is_array( $action['list'] ) ? ( $action['list']['id'] ?? 0 ) : $action['list'] );
	}

	// Fall back to a scan for anything that looks like a subscribe action.
	foreach ( $action as $entry ) {
		if ( is_array( $entry ) ) {
			if ( ! empty( $entry['list'] ) ) {
				return absint( is_array( $entry['list'] ) ? ( $entry['list']['id'] ?? 0 ) : $entry['list'] );
			}
			if ( ! empty( $entry['lists'] ) && is_array( $entry['lists'] ) ) {
				$first = reset( $entry['lists'] );
				return absint( is_array( $first ) ? ( $first['id'] ?? 0 ) : $first );
			}
		}
	}

	return 0;
}

/**
 * Render an ActiveCampaign form.
 *
 * @param int   $form_id ActiveCampaign form ID.
 * @param array $args {
 *     Optional.
 *
 *     @type string $class        Extra class on the wrapping <div>.
 *     @type string $submit_label Override the submit button label.
 *     @type int    $list_id      Fallback list ID if the form definition has none.
 * }
 * @return void Echoes markup. Silent (with a debug-log note) on failure.
 */
function bac_render_form( $form_id, $args = array() ) {
	$form_id = absint( $form_id );
	if ( ! $form_id ) {
		return;
	}

	$form = bac_get_form( $form_id );

	if ( ! $form || empty( $form['fields_data'] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Bonsai ActiveCampaign: no synced data for form ' . $form_id . ' (run a sync in Settings > ActiveCampaign).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return;
	}

	$args = wp_parse_args(
		$args,
		array(
			'class'        => '',
			'submit_label' => '',
			'list_id'      => 0,
		)
	);

	wp_enqueue_script( 'bac-form' );
	wp_enqueue_style( 'bac-form' );

	$instance_id  = wp_unique_id( 'bac-form-' );
	$button_text  = $args['submit_label'] ? $args['submit_label'] : ( $form['button_text'] ?: __( 'Submit', 'bonsai-active-campaign' ) );
	$thanks_text  = $form['thanks'] ?: __( 'Thanks — we\'ll be in touch soon.', 'bonsai-active-campaign' );
	$list_id      = bac_get_form_list_id( $form );
	if ( ! $list_id ) {
		$list_id = absint( $args['list_id'] );
	}

	$arrow = '<svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false" aria-hidden="true"><circle cx="18" cy="18" r="17" stroke="currentColor" stroke-width="1.5"/><path d="M11 18h14M20 13l5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	$wrap_class = 'active-campaign-module__box bac-form';
	if ( $args['class'] ) {
		$wrap_class .= ' ' . sanitize_html_class( $args['class'] );
	}
	?>
	<div class="<?php echo esc_attr( $wrap_class ); ?>" data-bac-instance="<?php echo esc_attr( $instance_id ); ?>">

		<div class="active-campaign-module__thanks bac-form__thanks" data-bac-thanks hidden>
			<?php echo wp_kses_post( wpautop( $thanks_text ) ); ?>
		</div>

		<p class="active-campaign-module__error bac-form__error" data-bac-error role="alert" hidden></p>

		<form class="active-campaign-module__form bac-form__form" data-bac-form novalidate>
			<?php
			wp_nonce_field( 'bac_submit_' . $form_id, 'bac_nonce' );
			?>
			<input type="hidden" name="bac_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
			<input type="hidden" name="bac_list_id" value="<?php echo esc_attr( $list_id ); ?>" />

			<?php
			foreach ( $form['fields_data'] as $field ) :
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				if ( 'header' === $type ) :
					?>
					<h2 class="active-campaign-module__heading"><?php echo esc_html( $field['header'] ?? '' ); ?></h2>
					<?php
					continue;
				endif;

				if ( 'html' === $type ) :
					?>
					<div class="active-campaign-module__html content-block"><?php echo wp_kses_post( $field['html'] ?? '' ); ?></div>
					<?php
					continue;
				endif;

				$name = bac_ac_field_name( $field );
				if ( ! $name ) {
					continue;
				}

				$field_id = $instance_id . '-' . sanitize_html_class( (string) ( $field['id'] ?? $type ) );
				$label    = $field['label'] ?? $field['header'] ?? $field['title'] ?? '';
				$required = ! empty( $field['isRequired'] ) || ! empty( $field['required'] ) || '1' === (string) ( $field['isrequired'] ?? '0' );

				if ( 'hidden' === $type ) :
					?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $field['defval'] ?? $field['defaultValue'] ?? '' ); ?>" />
					<?php
					continue;
				endif;

				if ( in_array( $type, array( 'dropdown', 'listbox' ), true ) ) :
					$options = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					?>
					<label class="active-campaign-module__label-sr" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="active-campaign-module__input active-campaign-module__select" <?php echo $required ? 'required' : ''; ?>>
						<option value=""><?php echo esc_html( $label ?: __( 'Please select…', 'bonsai-active-campaign' ) ); ?></option>
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( is_array( $option ) ? ( $option['value'] ?? '' ) : $option ); ?>"><?php echo esc_html( is_array( $option ) ? ( $option['text'] ?? $option['value'] ?? '' ) : $option ); ?></option>
						<?php endforeach; ?>
					</select>

				<?php elseif ( in_array( $type, array( 'checkbox', 'radio' ), true ) ) :
					$options    = ! empty( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
					$input_type = 'checkbox' === $type ? 'checkbox' : 'radio';
					$group_name = 'checkbox' === $type ? $name . '[]' : $name;
					?>
					<fieldset class="active-campaign-module__group">
						<legend class="active-campaign-module__legend"><?php echo esc_html( $label ); ?></legend>
						<?php
						foreach ( $options as $i => $option ) :
							$option_id    = $field_id . '-' . $i;
							$option_value = is_array( $option ) ? ( $option['value'] ?? '' ) : $option;
							$option_text  = is_array( $option ) ? ( $option['text'] ?? $option_value ) : $option;
							?>
							<label class="active-campaign-module__option" for="<?php echo esc_attr( $option_id ); ?>">
								<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $option_id ); ?>" name="<?php echo esc_attr( $group_name ); ?>" value="<?php echo esc_attr( $option_value ); ?>" <?php echo $required ? 'required' : ''; ?> />
								<?php echo esc_html( $option_text ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>

				<?php elseif ( 'textarea' === $type ) : ?>
					<label class="active-campaign-module__label-sr" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
					<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="active-campaign-module__input active-campaign-module__textarea" placeholder="<?php echo esc_attr( $field['default_text'] ?? $label ); ?>" <?php echo $required ? 'required' : ''; ?>></textarea>

				<?php else :
					$input_types = array(
						'email'  => 'email',
						'phone'  => 'tel',
						'url'    => 'url',
						'number' => 'number',
						'date'   => 'date',
					);
					$input_type  = $input_types[ $type ] ?? 'text';
					?>
					<label class="active-campaign-module__label-sr" for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="<?php echo esc_attr( $input_type ); ?>" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="active-campaign-module__input" placeholder="<?php echo esc_attr( $field['default_text'] ?? $label ); ?>" <?php echo $required ? 'required' : ''; ?> />
				<?php endif; ?>

			<?php endforeach; ?>

			<button type="submit" class="active-campaign-module__submit bac-form__submit">
				<?php echo esc_html( $button_text ); ?>
				<span class="active-campaign-module__submit-icon" aria-hidden="true"><?php echo $arrow; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?></span>
			</button>
		</form>
	</div>
	<?php
}

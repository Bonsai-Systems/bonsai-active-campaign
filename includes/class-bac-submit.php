<?php
/**
 * Front-end asset registration + the AJAX submit handler.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles a rendered ActiveCampaign form's submission via the API.
 */
class BAC_Submit {

	/**
	 * Register assets and AJAX endpoints.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_bac_submit_form', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_bac_submit_form', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Register (not enqueue) the front-end script/style. bac_render_form()
	 * enqueues them only on pages that actually output a form.
	 */
	public static function register_assets() {
		wp_register_script(
			'bac-form',
			BAC_URL . 'assets/js/bac-form.js',
			array( 'jquery' ),
			BAC_VERSION,
			true
		);

		wp_localize_script(
			'bac-form',
			'bacForm',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'generic' => __( 'Sorry, we couldn\'t send that. Please try again.', 'bonsai-active-campaign' ),
				),
			)
		);

		wp_register_style(
			'bac-form',
			BAC_URL . 'assets/css/bac-form.css',
			array(),
			BAC_VERSION
		);
	}

	/**
	 * AJAX: create/update the contact and add them to the form's list.
	 */
	public static function handle() {
		try {
			$form_id = isset( $_POST['bac_form_id'] ) ? absint( $_POST['bac_form_id'] ) : 0;

			if ( ! $form_id ) {
				wp_send_json_error( array( 'message' => __( 'Missing form reference.', 'bonsai-active-campaign' ) ), 400 );
			}

			$nonce = isset( $_POST['bac_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['bac_nonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'bac_submit_' . $form_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Your session expired. Please reload the page and try again.', 'bonsai-active-campaign' ) ), 403 );
			}

			$form = bac_get_form( $form_id );
			if ( ! $form || empty( $form['fields_data'] ) ) {
				wp_send_json_error( array( 'message' => __( 'This form is not available right now.', 'bonsai-active-campaign' ) ), 404 );
			}

			$api = BAC_Api::from_settings();
			if ( ! $api ) {
				wp_send_json_error( array( 'message' => __( 'This form is not available right now.', 'bonsai-active-campaign' ) ), 500 );
			}

			$submission = self::collect_submission( $form );

			if ( empty( $submission['contact']['email'] ) || ! is_email( $submission['contact']['email'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'bonsai-active-campaign' ) ), 400 );
			}

			// 1. Create or update the contact.
			$contact_result = $api->sync_contact( $submission['contact'] );
			if ( ! $contact_result['success'] || empty( $contact_result['data']['contact']['id'] ) ) {
				self::log( 'contact/sync failed for form ' . $form_id . ': ' . ( $contact_result['error'] ?? 'no contact id' ) );
				wp_send_json_error( array( 'message' => __( 'Sorry, we couldn\'t send that. Please try again.', 'bonsai-active-campaign' ) ), 502 );
			}

			$contact_id = (int) $contact_result['data']['contact']['id'];

			// 2. Add the contact to the form's list.
			$list_id = bac_get_form_list_id( $form );
			if ( ! $list_id && isset( $_POST['bac_list_id'] ) ) {
				$list_id = absint( $_POST['bac_list_id'] );
			}

			if ( $list_id ) {
				$list_result = $api->add_contact_to_list( $list_id, $contact_id );
				if ( ! $list_result['success'] ) {
					self::log( 'contactLists failed for form ' . $form_id . ' (list ' . $list_id . '): ' . $list_result['error'] );
					// The contact was still saved — don't fail the visitor over the list step.
				}
			} else {
				self::log( 'No list resolved for form ' . $form_id . ' — contact saved but not subscribed.' );
			}

			/**
			 * Fires after a successful ActiveCampaign form submission.
			 *
			 * @param int   $form_id    ActiveCampaign form ID.
			 * @param int   $contact_id ActiveCampaign contact ID.
			 * @param array $submission Normalised submission data.
			 */
			do_action( 'bac_form_submitted', $form_id, $contact_id, $submission );

			wp_send_json_success(
				array(
					'message' => wp_kses_post( wpautop( $form['thanks'] ?: __( 'Thanks — we\'ll be in touch soon.', 'bonsai-active-campaign' ) ) ),
				)
			);

		} catch ( Exception $e ) {
			self::log( 'Exception: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Sorry, something went wrong. Please try again.', 'bonsai-active-campaign' ) ), 500 );
		}
	}

	/**
	 * Turn $_POST into a normalised ActiveCampaign contact payload, using the
	 * form definition as the whitelist of accepted fields.
	 *
	 * @param array $form Form array from bac_get_form().
	 * @return array array( 'contact' => array, 'raw' => array )
	 */
	private static function collect_submission( $form ) {
		$contact = array( 'fieldValues' => array() );
		$raw     = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle() before this runs.
		$post = wp_unslash( $_POST );

		$standard_map = array(
			'email'      => 'email',
			'first_name' => 'firstName',
			'last_name'  => 'lastName',
			'phone'      => 'phone',
		);

		foreach ( $form['fields_data'] as $field ) {
			$name = bac_ac_field_name( $field );
			if ( ! $name ) {
				continue;
			}

			// Custom field: field[123].
			if ( preg_match( '/^field\[(\d+)\]$/', $name, $m ) ) {
				$key = 'field_' . $m[1];
				if ( ! isset( $post[ $key ] ) && isset( $post[ 'field' ][ $m[1] ] ) ) {
					$value = $post['field'][ $m[1] ];
				} else {
					$value = $post[ $key ] ?? ( $post[ 'field' ][ $m[1] ] ?? '' );
				}

				// Checkboxes arrive as arrays; ActiveCampaign wants "||a||b||".
				if ( is_array( $value ) ) {
					$value = '||' . implode( '||', array_map( 'sanitize_text_field', $value ) ) . '||';
				} else {
					$value = sanitize_text_field( $value );
				}

				if ( '' !== $value ) {
					$contact['fieldValues'][] = array(
						'field' => (int) $m[1],
						'value' => $value,
					);
					$raw[ $name ] = $value;
				}
				continue;
			}

			// Standard field.
			if ( isset( $standard_map[ $name ] ) ) {
				$value = isset( $post[ $name ] ) ? sanitize_text_field( $post[ $name ] ) : '';
				if ( 'email' === $name ) {
					$value = sanitize_email( $value );
				}
				if ( '' !== $value ) {
					$contact[ $standard_map[ $name ] ] = $value;
					$raw[ $name ]                      = $value;
				}
			}
		}

		return array( 'contact' => $contact, 'raw' => $raw );
	}

	/**
	 * Debug logger.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Bonsai ActiveCampaign: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

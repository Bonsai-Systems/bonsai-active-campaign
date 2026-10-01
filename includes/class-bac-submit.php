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

			/**
			 * Filter whether to submit through ActiveCampaign's form endpoint
			 * (proc.php) before falling back to the API.
			 *
			 * @param bool $use     Default true.
			 * @param int  $form_id ActiveCampaign form ID.
			 */
			$use_form_post = apply_filters( 'bac_use_form_post', true, $form_id );

			// 1. Preferred: a real ActiveCampaign form submission, so "Submits a form"
			//    automations and the form's own actions (tags, lists, opt-in) run.
			$posted = $use_form_post
				? BAC_Form_Post::submit( $form_id, $submission['proc'] )
				: array( 'success' => false, 'error' => 'disabled by bac_use_form_post filter' );

			$redirect = '';

			if ( $posted['success'] ) {
				// proc.php doesn't return the contact, so look it up for the action hook.
				$contact_id = $api->find_contact_id_by_email( $submission['contact']['email'] );

				/**
				 * Filter the URL the visitor is sent to after submitting. Defaults to the
				 * form's "redirect to URL" setting in ActiveCampaign; return '' to show
				 * the inline thanks message instead.
				 *
				 * @param string $redirect URL from ActiveCampaign, or '' if the form shows a message.
				 * @param int    $form_id  ActiveCampaign form ID.
				 */
				$redirect = (string) apply_filters( 'bac_form_redirect', $posted['redirect'] ?? '', $form_id );
			} else {
				// 2. Fallback: API v3, so the lead isn't lost. Form automations won't fire.
				self::log( 'proc.php submission failed for form ' . $form_id . ' (' . $posted['error'] . '); falling back to API — "Submits a form" automations will not run for this contact.' );

				$list_id = bac_get_form_list_id( $form );
				if ( ! $list_id && isset( $_POST['bac_list_id'] ) ) {
					$list_id = absint( $_POST['bac_list_id'] );
				}

				$contact_id = self::submit_via_api( $api, $form_id, $submission['contact'], $list_id );
				if ( ! $contact_id ) {
					wp_send_json_error( array( 'message' => __( 'Sorry, we couldn\'t send that. Please try again.', 'bonsai-active-campaign' ) ), 502 );
				}
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
					'message'  => wp_kses_post( wpautop( $form['thanks'] ?: __( 'Thanks — we\'ll be in touch soon.', 'bonsai-active-campaign' ) ) ),
					'redirect' => $redirect ? esc_url_raw( $redirect, array( 'http', 'https' ) ) : '',
				)
			);

		} catch ( Exception $e ) {
			self::log( 'Exception: ' . $e->getMessage() );
			wp_send_json_error( array( 'message' => __( 'Sorry, something went wrong. Please try again.', 'bonsai-active-campaign' ) ), 500 );
		}
	}

	/**
	 * API v3 fallback: create/update the contact and subscribe them to the list.
	 *
	 * @param BAC_Api $api     API client.
	 * @param int     $form_id ActiveCampaign form ID (for logging).
	 * @param array   $contact contact/sync payload.
	 * @param int     $list_id List to subscribe to (0 = none).
	 * @return int Contact ID, or 0 on failure.
	 */
	private static function submit_via_api( $api, $form_id, $contact, $list_id ) {
		$contact_result = $api->sync_contact( $contact );
		if ( ! $contact_result['success'] || empty( $contact_result['data']['contact']['id'] ) ) {
			self::log( 'contact/sync failed for form ' . $form_id . ': ' . ( $contact_result['error'] ?? 'no contact id' ) );
			return 0;
		}

		$contact_id = (int) $contact_result['data']['contact']['id'];

		if ( $list_id ) {
			$list_result = $api->add_contact_to_list( $list_id, $contact_id );
			if ( ! $list_result['success'] ) {
				self::log( 'contactLists failed for form ' . $form_id . ' (list ' . $list_id . '): ' . $list_result['error'] );
				// The contact was still saved — don't fail the visitor over the list step.
			}
		} else {
			self::log( 'No list resolved for form ' . $form_id . ' — contact saved but not subscribed.' );
		}

		return $contact_id;
	}

	/**
	 * Turn $_POST into normalised ActiveCampaign payloads, using the form
	 * definition as the whitelist of accepted fields.
	 *
	 * @param array $form Form array from bac_get_form().
	 * @return array array( 'contact' => API contact/sync payload, 'proc' => proc.php payload, 'raw' => array )
	 */
	private static function collect_submission( $form ) {
		$contact = array( 'fieldValues' => array() );
		$proc    = array();
		$raw     = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in handle() before this runs.
		$post = wp_unslash( $_POST );

		// Our field name => array( API v3 contact key, proc.php field name ).
		$standard_map = array(
			'email'      => array( 'email', 'email' ),
			'first_name' => array( 'firstName', 'firstname' ),
			'last_name'  => array( 'lastName', 'lastname' ),
			'phone'      => array( 'phone', 'phone' ),
		);

		foreach ( $form['fields_data'] as $field ) {
			$name = bac_ac_field_name( $field );
			if ( ! $name ) {
				continue;
			}

			// Custom field: field[123].
			if ( preg_match( '/^field\[(\d+)\]$/', $name, $m ) ) {
				$field_id = (int) $m[1];
				$key      = 'field_' . $m[1];
				if ( ! isset( $post[ $key ] ) && isset( $post[ 'field' ][ $m[1] ] ) ) {
					$value = $post['field'][ $m[1] ];
				} else {
					$value = $post[ $key ] ?? ( $post[ 'field' ][ $m[1] ] ?? '' );
				}

				if ( is_array( $value ) ) {
					$values = array_values( array_filter( array_map( 'sanitize_text_field', $value ), 'strlen' ) );
					// API v3 wants checkboxes as "||a||b||".
					$value = $values ? '||' . implode( '||', $values ) . '||' : '';
				} else {
					$values = null;
					$value  = sanitize_text_field( $value );
				}

				// proc.php: checkboxes are field[N][] led by the "~|" marker, exactly as
				// AC's own embed sends them (the marker alone means "none ticked").
				if ( 'checkbox' === ( $field['type'] ?? '' ) ) {
					$proc['field'][ $field_id ] = array_merge( array( '~|' ), $values ? $values : array() );
				} elseif ( '' !== $value ) {
					$proc['field'][ $field_id ] = $value;
				}

				if ( '' !== $value ) {
					$contact['fieldValues'][] = array(
						'field' => $field_id,
						'value' => $value,
					);
					$raw[ $name ] = $value;
				}
				continue;
			}

			// Standard field.
			if ( isset( $standard_map[ $name ] ) ) {
				$value = isset( $post[ $name ] ) && ! is_array( $post[ $name ] ) ? sanitize_text_field( $post[ $name ] ) : '';
				if ( 'email' === $name ) {
					$value = sanitize_email( $value );
				}
				if ( '' !== $value ) {
					$contact[ $standard_map[ $name ][0] ] = $value;
					$proc[ $standard_map[ $name ][1] ]    = $value;
					$raw[ $name ]                         = $value;
				}
			}
		}

		return array( 'contact' => $contact, 'proc' => $proc, 'raw' => $raw );
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

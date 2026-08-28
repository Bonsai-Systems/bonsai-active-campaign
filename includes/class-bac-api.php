<?php
/**
 * Thin ActiveCampaign API v3 client.
 *
 * All requests go through wp_remote_*, are wrapped, and return a consistent
 * shape: array( 'success' => bool, 'status' => int, 'data' => array|null, 'error' => string|null ).
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * ActiveCampaign API v3 wrapper.
 */
class BAC_Api {

	/**
	 * Base API URL, e.g. https://youraccount.api-us1.com/api/3
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * API key from ActiveCampaign > Settings > Developer.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * @param string $base_url Full API URL including /api/3.
	 * @param string $api_key  API key.
	 */
	public function __construct( $base_url, $api_key ) {
		$this->base_url = self::normalize_base_url( $base_url );
		$this->api_key  = trim( (string) $api_key );
	}

	/**
	 * Normalise whatever the user pasted into a base URL ending in /api/3.
	 *
	 * Accepts the bare account URL ("https://acct.api-us1.com"), one that
	 * already includes the path, and stray trailing slashes.
	 *
	 * @param string $base_url Raw URL from settings.
	 * @return string
	 */
	public static function normalize_base_url( $base_url ) {
		$url = untrailingslashit( trim( (string) $base_url ) );

		if ( '' === $url ) {
			return '';
		}

		// Strip any existing /api or /api/3 (with or without trailing slash), then re-add.
		$url = preg_replace( '#/api(/3)?/?$#i', '', $url );

		return untrailingslashit( $url ) . '/api/3';
	}

	/**
	 * Build a client from stored settings, or null if not configured.
	 *
	 * @return BAC_Api|null
	 */
	public static function from_settings() {
		$settings = get_option( BAC_OPTION, array() );
		$url      = isset( $settings['api_url'] ) ? $settings['api_url'] : '';
		$key      = isset( $settings['api_key'] ) ? $settings['api_key'] : '';

		if ( ! $url || ! $key ) {
			return null;
		}

		return new self( $url, $key );
	}

	/**
	 * Perform an API request.
	 *
	 * @param string     $method   HTTP method.
	 * @param string     $endpoint Endpoint relative to the base URL.
	 * @param array|null $body     Optional body, JSON-encoded.
	 * @return array
	 */
	public function request( $method, $endpoint, $body = null ) {
		if ( ! $this->base_url || ! $this->api_key ) {
			return $this->fail( 0, __( 'ActiveCampaign API URL or key is not set.', 'bonsai-active-campaign' ) );
		}

		$url = $this->base_url . '/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 20,
			'headers' => array(
				'Api-Token' => $this->api_key,
				'Accept'    => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Request error (' . $endpoint . '): ' . $response->get_error_message() );
			return $this->fail( 0, $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( null === $data && '' !== trim( (string) $raw ) ) {
			$this->log( 'Invalid JSON (' . $endpoint . ', HTTP ' . $status . ')' );
			return $this->fail( $status, __( 'ActiveCampaign returned an invalid response.', 'bonsai-active-campaign' ) );
		}

		if ( $status < 200 || $status >= 300 ) {
			$message = $this->extract_error( $data, sprintf( /* translators: %d: HTTP status code. */ __( 'ActiveCampaign returned HTTP %d.', 'bonsai-active-campaign' ), $status ) );
			$this->log( 'API error (' . $endpoint . '): ' . $message );
			return $this->fail( $status, $message, $data );
		}

		return array(
			'success' => true,
			'status'  => $status,
			'data'    => is_array( $data ) ? $data : array(),
			'error'   => null,
		);
	}

	/**
	 * GET /users/me — used by the "Test connection" button.
	 *
	 * @return array
	 */
	public function test_connection() {
		return $this->request( 'GET', 'users/me' );
	}

	/**
	 * Fetch every form (handles pagination).
	 *
	 * @return array array( 'success' => bool, 'forms' => array[], 'error' => string|null )
	 */
	public function get_all_forms() {
		$all    = array();
		$offset = 0;
		$limit  = 100;

		do {
			$result = $this->request( 'GET', 'forms?' . http_build_query( array( 'limit' => $limit, 'offset' => $offset ) ) );

			if ( ! $result['success'] ) {
				return array( 'success' => false, 'forms' => array(), 'error' => $result['error'] );
			}

			$forms = isset( $result['data']['forms'] ) && is_array( $result['data']['forms'] ) ? $result['data']['forms'] : array();
			$count = count( $forms );

			foreach ( $forms as $form ) {
				$all[] = $form;
			}

			$offset += $count;
		} while ( $count === $limit );

		return array( 'success' => true, 'forms' => $all, 'error' => null );
	}

	/**
	 * Create or update a contact by email.
	 *
	 * @param array $contact ActiveCampaign contact payload (email, firstName, ...).
	 * @return array
	 */
	public function sync_contact( array $contact ) {
		return $this->request( 'POST', 'contact/sync', array( 'contact' => $contact ) );
	}

	/**
	 * Add a contact to a list (status 1 = subscribed).
	 *
	 * @param int $list_id    List ID.
	 * @param int $contact_id Contact ID.
	 * @return array
	 */
	public function add_contact_to_list( $list_id, $contact_id ) {
		return $this->request(
			'POST',
			'contactLists',
			array(
				'contactList' => array(
					'list'    => (int) $list_id,
					'contact' => (int) $contact_id,
					'status'  => 1,
				),
			)
		);
	}

	/**
	 * Pull a human-readable error out of an ActiveCampaign error body.
	 *
	 * @param mixed  $data    Decoded body.
	 * @param string $default Fallback message.
	 * @return string
	 */
	private function extract_error( $data, $default ) {
		if ( ! is_array( $data ) ) {
			return $default;
		}
		if ( ! empty( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}
		if ( ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$messages = array();
			foreach ( $data['errors'] as $error ) {
				if ( is_string( $error ) ) {
					$messages[] = $error;
				} elseif ( is_array( $error ) && isset( $error['title'] ) ) {
					$messages[] = $error['title'];
				}
			}
			if ( $messages ) {
				return implode( '; ', $messages );
			}
		}
		return $default;
	}

	/**
	 * Build a failure result.
	 *
	 * @param int         $status HTTP status.
	 * @param string      $error  Message.
	 * @param array|null  $data   Optional decoded body.
	 * @return array
	 */
	private function fail( $status, $error, $data = null ) {
		return array(
			'success' => false,
			'status'  => (int) $status,
			'data'    => $data,
			'error'   => $error,
		);
	}

	/**
	 * Log to the PHP error log when WP_DEBUG is on.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Bonsai ActiveCampaign: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

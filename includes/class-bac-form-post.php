<?php
/**
 * Submits a form through ActiveCampaign's own form endpoint (proc.php).
 *
 * Why: automations with a "Submits a form" start trigger, and the form's own
 * actions (tags, lists, double opt-in), only run when ActiveCampaign records a
 * real form submission. Creating the contact via the API v3 does not count as
 * one. This class posts server-side to the same proc.php endpoint the
 * ActiveCampaign embed / landing pages use, so the submission behaves exactly
 * as if it came from ActiveCampaign's own form.
 *
 * proc.php needs per-form hidden values (u, or, …) that the API doesn't
 * return, so they are read from the form's public embed script
 * (https://{account}.activehosted.com/f/embed.php?id={form_id}) and cached.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Server-side proc.php submission.
 */
class BAC_Form_Post {

	/**
	 * Hidden inputs from the embed that proc.php needs. Field inputs
	 * (field[N] etc.) are supplied by our own form, not copied from the embed.
	 */
	const HIDDEN_KEYS = array( 'u', 'f', 's', 'c', 'm', 'act', 'v', 'or' );

	/**
	 * How long to cache a form's embed params.
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Work out the account's form host (acct.activehosted.com) from the API URL
	 * saved in settings (acct.api-us1.com).
	 *
	 * @return string Host, or empty string if it can't be determined.
	 */
	public static function account_host() {
		$settings = get_option( BAC_OPTION, array() );
		$api_host = wp_parse_url( isset( $settings['api_url'] ) ? $settings['api_url'] : '', PHP_URL_HOST );
		$host     = '';

		if ( $api_host ) {
			$parts = explode( '.', $api_host );
			if ( ! empty( $parts[0] ) ) {
				$host = $parts[0] . '.activehosted.com';
			}
		}

		/**
		 * Filter the ActiveCampaign form host used for embed.php / proc.php.
		 *
		 * @param string $host     Derived host, e.g. acct.activehosted.com.
		 * @param string $api_host Host of the configured API URL.
		 */
		return (string) apply_filters( 'bac_form_host', $host, $api_host );
	}

	/**
	 * Get (and cache) the proc.php action URL and hidden params for a form.
	 *
	 * @param int  $form_id ActiveCampaign form ID.
	 * @param bool $refresh Skip the cache.
	 * @return array|null array( 'action' => string, 'hidden' => array ) or null on failure.
	 */
	public static function get_params( $form_id, $refresh = false ) {
		$form_id   = absint( $form_id );
		$cache_key = 'bac_embed_' . $form_id;

		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['action'] ) ) {
				return $cached;
			}
		}

		$host = self::account_host();
		if ( ! $form_id || ! $host ) {
			self::log( 'Cannot resolve form host for form ' . $form_id . ' (check the API URL in settings).' );
			return null;
		}

		$response = wp_remote_get(
			'https://' . $host . '/f/embed.php?' . http_build_query( array( 'id' => $form_id ) ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'embed.php request failed for form ' . $form_id . ': ' . $response->get_error_message() );
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::log( 'embed.php returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for form ' . $form_id );
			return null;
		}

		$params = self::parse_embed( wp_remote_retrieve_body( $response ), $host );
		if ( ! $params ) {
			self::log( 'Could not read proc.php params from embed for form ' . $form_id );
			return null;
		}

		set_transient( $cache_key, $params, self::CACHE_TTL );

		return $params;
	}

	/**
	 * Pull the form action and hidden inputs out of the embed script.
	 *
	 * The embed is JavaScript containing the form HTML as an escaped string
	 * (\" and \/), so unescape before matching.
	 *
	 * @param string $body Embed script body.
	 * @param string $host Expected host — the action must point at it.
	 * @return array|null
	 */
	public static function parse_embed( $body, $host ) {
		$html = str_replace( array( '\\"', '\\/' ), array( '"', '/' ), (string) $body );

		if ( ! preg_match( '#<form[^>]*\saction="([^"]+)"#i', $html, $m ) ) {
			return null;
		}

		$action = html_entity_decode( $m[1] );
		$parsed = wp_parse_url( $action );

		// Only ever post to the account's own proc.php over https.
		if (
			empty( $parsed['scheme'] ) || 'https' !== $parsed['scheme']
			|| empty( $parsed['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $host )
			|| empty( $parsed['path'] ) || '/proc.php' !== $parsed['path']
		) {
			return null;
		}

		$hidden = array();
		if ( preg_match_all( '#<input[^>]*type="hidden"[^>]*>#i', $html, $inputs ) ) {
			foreach ( $inputs[0] as $input ) {
				if ( ! preg_match( '#\sname="([^"]+)"#i', $input, $name ) ) {
					continue;
				}
				if ( ! in_array( $name[1], self::HIDDEN_KEYS, true ) ) {
					continue;
				}
				$value               = preg_match( '#\svalue="([^"]*)"#i', $input, $val ) ? html_entity_decode( $val[1] ) : '';
				$hidden[ $name[1] ] = $value;
			}
		}

		// u and f identify the form; without them proc.php can't attribute the submission.
		if ( empty( $hidden['u'] ) || empty( $hidden['f'] ) ) {
			return null;
		}

		return array(
			'action' => 'https://' . $parsed['host'] . '/proc.php',
			'hidden' => $hidden,
		);
	}

	/**
	 * Post a submission to proc.php.
	 *
	 * @param int   $form_id ActiveCampaign form ID.
	 * @param array $fields  proc.php field payload (email, firstname, field => [ id => value ], …).
	 * @return array array( 'success' => bool, 'error' => string|null )
	 */
	public static function submit( $form_id, array $fields ) {
		$params = self::get_params( $form_id );
		if ( ! $params ) {
			return array( 'success' => false, 'error' => 'embed params unavailable' );
		}

		$body = array_merge( $params['hidden'], $fields );

		$response = wp_remote_post(
			$params['action'] . '?jsonp=true',
			array(
				'timeout' => 20,
				'body'    => $body,
				'headers' => array(
					// proc.php uses the referer as the submission's source page.
					'Referer' => wp_get_referer() ? wp_get_referer() : home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		// jsonp=true answers with JS calling _show_thank_you(...) or _show_error(...).
		if ( $status >= 200 && $status < 300 && false !== strpos( $raw, '_show_thank_you' ) ) {
			return array( 'success' => true, 'error' => null );
		}

		// Cached u/or may be stale if the form was edited; drop the cache so the next try re-reads them.
		delete_transient( 'bac_embed_' . absint( $form_id ) );

		$error = 'HTTP ' . $status;
		if ( preg_match( '#_show_error\(\s*[^,]*,\s*([\'"])(.*?)\1#s', $raw, $m ) ) {
			$error .= ': ' . wp_strip_all_tags( stripslashes( $m[2] ) );
		}

		self::log_response( $form_id, $response, $raw );

		return array( 'success' => false, 'error' => $error );
	}

	/**
	 * Log what proc.php actually sent back when the response wasn't recognised,
	 * so unexpected formats (HTML, JSON, a followed redirect) can be diagnosed.
	 *
	 * @param int    $form_id  ActiveCampaign form ID.
	 * @param array  $response wp_remote_post() response.
	 * @param string $raw      Response body.
	 */
	private static function log_response( $form_id, $response, $raw ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		// Final URL differs from proc.php when WP followed a redirect (e.g. to the form's thank-you page).
		$final_url = '';
		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
			$final_url = (string) $response['http_response']->get_response_object()->url;
		}

		// Keep visitor email addresses out of the log (plain and URL-encoded). Mask before truncating.
		$email_pattern = '/[^\s@"\'<>=&?\/]+(@|%40)[^\s@"\'<>&]+\.[a-z]{2,}/i';
		$snippet       = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		$snippet       = substr( preg_replace( $email_pattern, '[email]', $snippet ), 0, 500 );
		$final_url     = preg_replace( $email_pattern, '[email]', $final_url );

		self::log(
			sprintf(
				'proc.php response for form %d — content-type: %s; final URL: %s; body: %s',
				absint( $form_id ),
				$type ? $type : '(none)',
				$final_url ? $final_url : '(unknown)',
				'' !== $snippet ? $snippet : '(empty)'
			)
		);
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

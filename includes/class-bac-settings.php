<?php
/**
 * Settings screen: Settings > ActiveCampaign.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the options page, the Settings API fields, and the
 * "Test connection" / "Sync now" admin-post actions.
 */
class BAC_Settings {

	const PAGE_SLUG = 'bonsai-active-campaign';

	/**
	 * Hook everything.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		// Keep the cron in step with the configured interval.
		add_action( 'admin_init', array( 'BAC_Sync', 'schedule' ) );
		add_action( 'admin_post_bac_test_connection', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_bac_sync_now', array( __CLASS__, 'handle_sync' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BAC_FILE ), array( __CLASS__, 'action_link' ) );
	}

	/**
	 * Add "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bonsai-active-campaign' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the options page.
	 */
	public static function add_page() {
		add_options_page(
			__( 'ActiveCampaign', 'bonsai-active-campaign' ),
			__( 'ActiveCampaign', 'bonsai-active-campaign' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the setting, section and fields.
	 */
	public static function register() {
		register_setting(
			'bac_settings_group',
			BAC_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			'bac_main',
			__( 'ActiveCampaign account', 'bonsai-active-campaign' ),
			static function () {
				echo '<p>' . esc_html__( 'From ActiveCampaign: Settings > Developer > API Access. Paste the full API URL and key exactly as shown there.', 'bonsai-active-campaign' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_url',
			__( 'API URL', 'bonsai-active-campaign' ),
			array( __CLASS__, 'field_api_url' ),
			self::PAGE_SLUG,
			'bac_main'
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'bonsai-active-campaign' ),
			array( __CLASS__, 'field_api_key' ),
			self::PAGE_SLUG,
			'bac_main'
		);

		add_settings_field(
			'sync_interval',
			__( 'Sync frequency', 'bonsai-active-campaign' ),
			array( __CLASS__, 'field_sync_interval' ),
			self::PAGE_SLUG,
			'bac_main'
		);
	}

	/**
	 * Sanitise submitted settings. Keeps the stored key if the field is
	 * left blank (so it isn't wiped when re-saving).
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = get_option( BAC_OPTION, array() );
		$clean   = array();

		$url = isset( $input['api_url'] ) ? esc_url_raw( trim( $input['api_url'] ) ) : '';
		// Normalise to the "<account>.api-us1.com/api/3" form the API client needs,
		// whether the client pasted the bare account URL or included the path.
		$clean['api_url'] = $url ? BAC_Api::normalize_base_url( $url ) : '';

		$key = isset( $input['api_key'] ) ? trim( wp_unslash( $input['api_key'] ) ) : '';
		if ( '' === $key && ! empty( $current['api_key'] ) ) {
			$clean['api_key'] = $current['api_key'];
		} else {
			$clean['api_key'] = preg_replace( '/[^A-Za-z0-9]/', '', $key );
		}

		$allowed                = array( 'bac_15min', 'hourly', 'twicedaily', 'daily' );
		$interval               = isset( $input['sync_interval'] ) ? sanitize_text_field( $input['sync_interval'] ) : 'bac_15min';
		$clean['sync_interval'] = in_array( $interval, $allowed, true ) ? $interval : 'bac_15min';

		return $clean;
	}

	/**
	 * API URL field.
	 */
	public static function field_api_url() {
		$settings = get_option( BAC_OPTION, array() );
		$value    = isset( $settings['api_url'] ) ? $settings['api_url'] : '';
		printf(
			'<input type="url" class="regular-text" name="%s[api_url]" value="%s" placeholder="https://youraccount.api-us1.com/api/3" />',
			esc_attr( BAC_OPTION ),
			esc_attr( $value )
		);
	}

	/**
	 * API key field (masked once stored).
	 */
	public static function field_api_key() {
		$settings = get_option( BAC_OPTION, array() );
		$has_key  = ! empty( $settings['api_key'] );
		printf(
			'<input type="password" class="regular-text" name="%1$s[api_key]" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( BAC_OPTION ),
			$has_key ? esc_attr__( '•••••••• (leave blank to keep current key)', 'bonsai-active-campaign' ) : ''
		);
	}

	/**
	 * Sync interval field.
	 */
	public static function field_sync_interval() {
		$settings = get_option( BAC_OPTION, array() );
		$current  = isset( $settings['sync_interval'] ) ? $settings['sync_interval'] : 'bac_15min';
		$choices  = array(
			'bac_15min'  => __( 'Every 15 minutes', 'bonsai-active-campaign' ),
			'hourly'     => __( 'Hourly', 'bonsai-active-campaign' ),
			'twicedaily' => __( 'Twice daily', 'bonsai-active-campaign' ),
			'daily'      => __( 'Daily', 'bonsai-active-campaign' ),
		);
		echo '<select name="' . esc_attr( BAC_OPTION ) . '[sync_interval]">';
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Render the whole page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$last  = get_option( BAC_Sync::STATUS_OPTION, array() );
		$forms = bac_get_forms();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bonsai ActiveCampaign', 'bonsai-active-campaign' ); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'bac_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection & sync', 'bonsai-active-campaign' ); ?></h2>

			<p>
				<?php
				$test_url = wp_nonce_url( admin_url( 'admin-post.php?action=bac_test_connection' ), 'bac_test_connection' );
				$sync_url = wp_nonce_url( admin_url( 'admin-post.php?action=bac_sync_now' ), 'bac_sync_now' );
				?>
				<a href="<?php echo esc_url( $test_url ); ?>" class="button"><?php esc_html_e( 'Test connection', 'bonsai-active-campaign' ); ?></a>
				<a href="<?php echo esc_url( $sync_url ); ?>" class="button button-primary"><?php esc_html_e( 'Sync forms now', 'bonsai-active-campaign' ); ?></a>
			</p>

			<?php if ( ! empty( $last ) ) : ?>
				<p>
					<strong><?php esc_html_e( 'Last sync:', 'bonsai-active-campaign' ); ?></strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date/time, 2: outcome */
							__( '%1$s — %2$s', 'bonsai-active-campaign' ),
							$last['time'],
							$last['success'] ? __( 'success', 'bonsai-active-campaign' ) : __( 'failed', 'bonsai-active-campaign' )
						)
					);
					if ( ! empty( $last['error'] ) ) {
						echo ' (' . esc_html( $last['error'] ) . ')';
					}
					if ( ! empty( $last['stats'] ) ) {
						echo '<br />';
						echo esc_html(
							sprintf(
								/* translators: %d values: found, inserted, updated, unchanged, deactivated */
								__( 'Found %1$d · inserted %2$d · updated %3$d · unchanged %4$d · deactivated %5$d', 'bonsai-active-campaign' ),
								$last['stats']['found'],
								$last['stats']['inserted'],
								$last['stats']['updated'],
								$last['stats']['unchanged'],
								$last['stats']['deactivated']
							)
						);
					}
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Synced forms', 'bonsai-active-campaign' ); ?></h2>
			<?php if ( $forms ) : ?>
				<p><?php esc_html_e( 'Use the ID in the ActiveCampaign Form ID field on a module.', 'bonsai-active-campaign' ); ?></p>
				<table class="widefat striped" style="max-width:640px">
					<thead><tr>
						<th><?php esc_html_e( 'ID', 'bonsai-active-campaign' ); ?></th>
						<th><?php esc_html_e( 'Name', 'bonsai-active-campaign' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $forms as $form ) : ?>
							<tr>
								<td><?php echo esc_html( $form['ac_form_id'] ); ?></td>
								<td><?php echo esc_html( $form['name'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No forms synced yet. Save your API details, then click “Sync forms now”.', 'bonsai-active-campaign' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the "Test connection" button.
	 */
	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bonsai-active-campaign' ) );
		}
		check_admin_referer( 'bac_test_connection' );

		$api = BAC_Api::from_settings();
		if ( ! $api ) {
			self::redirect_with_notice( 'error', __( 'Enter and save your API URL and key first.', 'bonsai-active-campaign' ) );
		}

		$result = $api->test_connection();
		if ( $result['success'] ) {
			$name = $result['data']['user']['username'] ?? $result['data']['user']['email'] ?? '';
			self::redirect_with_notice(
				'success',
				$name
					? sprintf( /* translators: %s: ActiveCampaign username */ __( 'Connected to ActiveCampaign as %s.', 'bonsai-active-campaign' ), $name )
					: __( 'Connection successful.', 'bonsai-active-campaign' )
			);
		}

		self::redirect_with_notice( 'error', sprintf( /* translators: %s: error message */ __( 'Connection failed: %s', 'bonsai-active-campaign' ), $result['error'] ) );
	}

	/**
	 * Handle the "Sync forms now" button.
	 */
	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bonsai-active-campaign' ) );
		}
		check_admin_referer( 'bac_sync_now' );

		$result = BAC_Sync::run();

		if ( $result['success'] ) {
			self::redirect_with_notice(
				'success',
				sprintf(
					/* translators: 1: forms found, 2: inserted, 3: updated */
					__( 'Sync complete: %1$d forms found (%2$d new, %3$d updated).', 'bonsai-active-campaign' ),
					$result['stats']['found'],
					$result['stats']['inserted'],
					$result['stats']['updated']
				)
			);
		}

		self::redirect_with_notice( 'error', sprintf( /* translators: %s: error message */ __( 'Sync failed: %s', 'bonsai-active-campaign' ), $result['error'] ) );
	}

	/**
	 * Stash a notice in a transient and bounce back to the settings page.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 */
	private static function redirect_with_notice( $type, $message ) {
		set_transient( 'bac_admin_notice', array( 'type' => $type, 'message' => $message ), 30 );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Print the stashed notice.
	 */
	public static function admin_notices() {
		$notice = get_transient( 'bac_admin_notice' );
		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}
		delete_transient( 'bac_admin_notice' );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}
}

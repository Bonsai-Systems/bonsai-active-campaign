<?php
/**
 * Form synchronisation: WP-Cron schedule + the sync routine itself.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps {prefix}bac_forms in step with the ActiveCampaign account.
 */
class BAC_Sync {

	/**
	 * Option holding the last sync outcome (for the settings screen).
	 */
	const STATUS_OPTION = 'bac_last_sync';

	/**
	 * Hook the cron callback and custom schedule.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( BAC_CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Register a 15-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		if ( ! isset( $schedules['bac_15min'] ) ) {
			$schedules['bac_15min'] = array(
				'interval' => 15 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 15 minutes (Bonsai ActiveCampaign)', 'bonsai-active-campaign' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure the sync event is scheduled with the configured interval.
	 */
	public static function schedule() {
		$settings = get_option( BAC_OPTION, array() );
		$interval = isset( $settings['sync_interval'] ) && $settings['sync_interval'] ? $settings['sync_interval'] : 'bac_15min';

		$next = wp_next_scheduled( BAC_CRON_HOOK );

		// Reschedule if missing or the interval changed.
		if ( $next ) {
			$event = wp_get_scheduled_event( BAC_CRON_HOOK );
			if ( $event && $event->schedule === $interval ) {
				return;
			}
			wp_unschedule_event( $next, BAC_CRON_HOOK );
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, BAC_CRON_HOOK );
	}

	/**
	 * Remove the scheduled event.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( BAC_CRON_HOOK );
	}

	/**
	 * Run a full sync.
	 *
	 * @return array array( 'success' => bool, 'stats' => array, 'error' => string|null )
	 */
	public static function run() {
		$stats = array( 'found' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0 );

		$api = BAC_Api::from_settings();
		if ( ! $api ) {
			return self::finish( false, $stats, __( 'ActiveCampaign is not configured.', 'bonsai-active-campaign' ) );
		}

		$result = $api->get_all_forms();
		if ( ! $result['success'] ) {
			return self::finish( false, $stats, $result['error'] );
		}

		$forms        = $result['forms'];
		$stats['found'] = count( $forms );
		$keep_ids     = array();

		foreach ( $forms as $form ) {
			if ( ! is_array( $form ) || empty( $form['id'] ) ) {
				continue;
			}
			$keep_ids[] = (int) $form['id'];

			$action = BAC_Forms_Table::store( $form );
			if ( isset( $stats[ $action ] ) ) {
				$stats[ $action ]++;
			}
		}

		$stats['deactivated'] = BAC_Forms_Table::deactivate_missing( $keep_ids );

		return self::finish( true, $stats, null );
	}

	/**
	 * Persist the outcome and return it.
	 *
	 * @param bool        $success Whether the sync succeeded.
	 * @param array       $stats   Counters.
	 * @param string|null $error   Error message.
	 * @return array
	 */
	private static function finish( $success, $stats, $error ) {
		update_option(
			self::STATUS_OPTION,
			array(
				'time'    => current_time( 'mysql' ),
				'success' => (bool) $success,
				'stats'   => $stats,
				'error'   => $error,
			),
			false
		);

		return array( 'success' => (bool) $success, 'stats' => $stats, 'error' => $error );
	}
}

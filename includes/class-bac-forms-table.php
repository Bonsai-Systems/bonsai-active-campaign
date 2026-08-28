<?php
/**
 * Local cache of ActiveCampaign form definitions.
 *
 * Mirrors the essential columns of the original standalone "ac_forms" table
 * so a form can be rendered without contacting ActiveCampaign on page load.
 *
 * @package Bonsai_ActiveCampaign
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install, upgrade and read/write helpers for {prefix}bac_forms.
 */
class BAC_Forms_Table {

	/**
	 * Bump when the schema changes.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bac_forms';
	}

	/**
	 * Create or update the table via dbDelta().
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ac_form_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(500) NOT NULL DEFAULT '',
			button_text VARCHAR(500) DEFAULT NULL,
			thanks LONGTEXT DEFAULT NULL,
			fields_data LONGTEXT DEFAULT NULL,
			action_data LONGTEXT DEFAULT NULL,
			raw_json LONGTEXT NOT NULL,
			raw_hash CHAR(64) NOT NULL DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			synced_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ac_form_id (ac_form_id),
			KEY is_active (is_active)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( BAC_DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run install() when the stored schema version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( BAC_DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Get one active form as an associative array, JSON columns decoded.
	 *
	 * @param int $ac_form_id ActiveCampaign form ID.
	 * @return array|null
	 */
	public static function get( $ac_form_id ) {
		global $wpdb;

		$ac_form_id = absint( $ac_form_id );
		if ( ! $ac_form_id ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT * FROM {$table} WHERE ac_form_id = %d AND is_active = 1 LIMIT 1",
				$ac_form_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$row['fields_data'] = $row['fields_data'] ? json_decode( $row['fields_data'], true ) : array();
		$row['action_data'] = $row['action_data'] ? json_decode( $row['action_data'], true ) : array();
		$row['raw_form']    = $row['raw_json'] ? json_decode( $row['raw_json'], true ) : array();
		unset( $row['raw_json'] );

		return $row;
	}

	/**
	 * Get a lightweight list of active forms (id + name), ordered by name.
	 *
	 * @return array[] Each: [ 'ac_form_id' => int, 'name' => string, 'synced_at' => string ].
	 */
	public static function all_active() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- read-only, no user input.
		$rows = $wpdb->get_results( "SELECT ac_form_id, name, synced_at FROM {$table} WHERE is_active = 1 ORDER BY name ASC, ac_form_id ASC", ARRAY_A );

		return $rows ?: array();
	}

	/**
	 * Insert or update one form from an ActiveCampaign API "form" object.
	 *
	 * @param array $form Raw form object from the ActiveCampaign API.
	 * @return string One of: inserted, updated, unchanged, error.
	 */
	public static function store( array $form ) {
		global $wpdb;

		$ac_form_id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
		if ( ! $ac_form_id ) {
			return 'error';
		}

		$raw_json = wp_json_encode( $form );
		$raw_hash = hash( 'sha256', (string) wp_json_encode( self::recursive_ksort( $form ) ) );
		$table    = self::table_name();

		$existing = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
				"SELECT id, raw_hash, is_active FROM {$table} WHERE ac_form_id = %d LIMIT 1",
				$ac_form_id
			),
			ARRAY_A
		);

		$data = array(
			'ac_form_id'   => $ac_form_id,
			'name'         => isset( $form['name'] ) ? (string) $form['name'] : '',
			'button_text'  => isset( $form['button'] ) ? (string) $form['button'] : null,
			'thanks'       => isset( $form['thanks'] ) ? (string) $form['thanks'] : null,
			'fields_data'  => isset( $form['cfields'] ) ? wp_json_encode( $form['cfields'] ) : null,
			'action_data'  => isset( $form['actiondata'] ) ? wp_json_encode( $form['actiondata'] ) : null,
			'raw_json'     => $raw_json,
			'raw_hash'     => $raw_hash,
			'is_active'    => 1,
			'synced_at'    => current_time( 'mysql' ),
		);

		if ( ! $existing ) {
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'inserted';
		}

		if ( $existing['raw_hash'] === $raw_hash && (int) $existing['is_active'] === 1 ) {
			$wpdb->update( $table, array( 'synced_at' => $data['synced_at'] ), array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'unchanged';
		}

		$wpdb->update( $table, $data, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 'updated';
	}

	/**
	 * Mark every form NOT in $keep_ids inactive. Callers must only pass the
	 * result of a fully successful ActiveCampaign fetch.
	 *
	 * @param int[] $keep_ids ActiveCampaign form IDs that still exist.
	 * @return int Rows deactivated.
	 */
	public static function deactivate_missing( array $keep_ids ) {
		global $wpdb;

		$table = self::table_name();

		$keep_ids = array_values( array_unique( array_filter( array_map( 'absint', $keep_ids ) ) ) );

		if ( empty( $keep_ids ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->query( "UPDATE {$table} SET is_active = 0 WHERE is_active = 1" );
		}

		$placeholders = implode( ',', array_fill( 0, count( $keep_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$sql = $wpdb->prepare(
			"UPDATE {$table} SET is_active = 0 WHERE is_active = 1 AND ac_form_id NOT IN ({$placeholders})",
			$keep_ids
		);

		return (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Recursively ksort associative arrays so harmless key-ordering
	 * differences don't force a needless DB update.
	 *
	 * @param mixed $value Value to normalise.
	 * @return mixed
	 */
	private static function recursive_ksort( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::recursive_ksort( $v );
		}
		$keys = array_keys( $value );
		if ( $keys !== range( 0, count( $keys ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * Drop the table (used by uninstall.php).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}

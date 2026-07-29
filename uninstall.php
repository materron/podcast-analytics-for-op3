<?php
/**
 * Fired when the plugin is deleted from WP admin.
 *
 * Removes every trace of the plugin: options, transients, user meta, scheduled
 * events and the custom tables created for private-podcast tracking and for
 * the GeoLite2 IP ranges. On Multisite the cleanup runs once per site.
 *
 * @package Podcast_Analytics_For_OP3
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes all plugin data from the current site.
 */
function op3pa_uninstall_site(): void {
	global $wpdb;

	$options = [
		'op3pa_podcasts',
		'op3pa_bearer_token',
		'op3pa_maxmind_license_key',
		'op3pa_migrated_v2',
		'op3pa_migration_checked',
		'op3pa_db_version',
		'op3pa_rewrite_flushed_v3',
		'op3pa_geoip_last_update',
		'op3pa_alerts_email',
		'op3pa_alerts_types',
		'op3pa_alerts_podcasts',
		'op3pa_alerts_threshold',
		'op3pa_alerts_min_baseline',
		'op3pa_alerts_last_check',
		'op3pa_alerts_log',
	];

	foreach ( $options as $op3pa_option ) {
		delete_option( $op3pa_option );
	}

	// Transient keys are built dynamically (podcast index, period, hashes), so
	// they are matched by prefix rather than listed one by one.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall; no cache to prime.
	$op3pa_transients = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_op3pa_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_op3pa_' ) . '%'
		)
	);

	foreach ( $op3pa_transients as $op3pa_transient ) {
		$op3pa_name = preg_replace( '/^_transient_(timeout_)?/', '', $op3pa_transient );
		delete_transient( $op3pa_name );
	}

	delete_metadata( 'user', 0, 'op3pa_vozcaster_promo_dismissed', '', true );

	wp_clear_scheduled_hook( 'op3pa_geoip_refresh' );
	wp_clear_scheduled_hook( 'op3pa_alerts_check' );

	// Custom tables. Table names are built from $wpdb->prefix plus a fixed
	// suffix, never from user input; DDL cannot use $wpdb->prepare() placeholders.
	foreach ( [ 'op3pa_downloads', 'op3pa_geoip_ranges', 'op3pa_geoip_ranges_staging', 'op3pa_geoip_ranges_old' ] as $op3pa_suffix ) {
		$op3pa_table = $wpdb->prefix . $op3pa_suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see above.
		$wpdb->query( "DROP TABLE IF EXISTS {$op3pa_table}" );
	}
}

if ( is_multisite() ) {
	$op3pa_site_ids = get_sites(
		[
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		]
	);

	foreach ( $op3pa_site_ids as $op3pa_site_id ) {
		switch_to_blog( $op3pa_site_id );
		op3pa_uninstall_site();
		restore_current_blog();
	}
} else {
	op3pa_uninstall_site();
}

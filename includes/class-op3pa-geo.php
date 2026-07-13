<?php
/**
 * IP-to-country lookup for private podcast downloads, using MaxMind's free
 * GeoLite2 Country CSV data.
 *
 * Deliberately avoids the binary .mmdb format and any Composer dependency:
 * the CSV is imported into our own indexed MySQL table (~450k IPv4 ranges),
 * and lookups are a single indexed query — much faster than loading the
 * whole dataset into PHP memory on every request (tested: ~1.5s per request
 * with a var_export'd PHP array vs. sub-millisecond with an indexed query).
 *
 * Only used for private podcasts — public podcasts already get country/region
 * per-row from the OP3 API (see OP3PA_Api), no GeoLite2 needed there.
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Geo {

	private const OPTION_LAST_UPDATE = 'op3pa_geoip_last_update';
	private const CRON_HOOK          = 'op3pa_geoip_refresh';
	private const DOWNLOAD_URL       = 'https://download.maxmind.com/app/geoip_download';
	private const BATCH_SIZE         = 500;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'refresh_database' ] );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) && self::has_license_key() ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * @return string Fully-qualified ranges table name (the live table).
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'op3pa_geoip_ranges';
	}

	/**
	 * @return bool Whether a MaxMind license key is configured.
	 */
	public static function has_license_key(): bool {
		return ! empty( get_option( OP3PA_OPTION_MAXMIND_KEY, '' ) );
	}

	/**
	 * @return string
	 */
	public static function get_license_key(): string {
		return (string) get_option( OP3PA_OPTION_MAXMIND_KEY, '' );
	}

	/**
	 * @return int|null Unix timestamp of the last successful database refresh, or null.
	 */
	public static function get_last_update(): ?int {
		$ts = get_option( self::OPTION_LAST_UPDATE, 0 );
		return $ts ? (int) $ts : null;
	}

	/**
	 * @return string Directory where temporary download/extraction files live.
	 */
	private static function get_tmp_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'op3pa-geoip/';
	}

	/**
	 * Resolves a country code for an IPv4 address via a single indexed query.
	 * Returns null if no database is available, the key isn't configured, or
	 * the IP isn't found (IPv6 is not supported in this lightweight lookup).
	 *
	 * @param string $ip Raw IP address.
	 * @return string|null Two-letter ISO country code, or null.
	 */
	public static function lookup_country( string $ip ): ?string {
		if ( empty( $ip ) || ! str_contains( $ip, '.' ) ) {
			return null; // Only IPv4 is supported by this lookup.
		}

		$ip_long = ip2long( $ip );
		if ( false === $ip_long ) {
			return null;
		}

		global $wpdb;
		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, name is a fixed constant (not user input), value is prepared via %d; a country lookup on every download request must not add a cache-invalidation layer.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT country_code, end_ip FROM {$table} WHERE start_ip <= %d ORDER BY start_ip DESC LIMIT 1",
				$ip_long
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! $row || (int) $row->end_ip < $ip_long ) {
			return null;
		}

		return $row->country_code;
	}

	/**
	 * Downloads the latest GeoLite2 Country CSV data and rebuilds the ranges table.
	 * Builds into a staging table and atomically swaps it in, so lookups never
	 * see an empty/partial table while a refresh is running.
	 *
	 * Safe to call repeatedly (e.g. from cron); does nothing without a license key.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh_database(): true|WP_Error {
		$license_key = self::get_license_key();
		if ( empty( $license_key ) ) {
			return new WP_Error( 'op3pa_no_license_key', __( 'No MaxMind license key configured.', 'podcast-analytics-for-op3' ) );
		}

		$url = add_query_arg(
			[
				'edition_id'  => 'GeoLite2-Country-CSV',
				'license_key' => $license_key,
				'suffix'      => 'zip',
			],
			self::DOWNLOAD_URL
		);

		$tmp_zip = download_url( $url, 120 );
		if ( is_wp_error( $tmp_zip ) ) {
			return $tmp_zip;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		$extract_dir = self::get_tmp_dir() . 'tmp-extract/';
		wp_mkdir_p( $extract_dir );

		$unzip_result = unzip_file( $tmp_zip, $extract_dir );
		wp_delete_file( $tmp_zip );

		if ( is_wp_error( $unzip_result ) ) {
			self::rrmdir( $extract_dir );
			return $unzip_result;
		}

		// The zip contains a single versioned subfolder, e.g. GeoLite2-Country-CSV_20260101/.
		$subfolders = glob( $extract_dir . 'GeoLite2-Country-CSV_*', GLOB_ONLYDIR );
		$csv_dir    = $subfolders[0] ?? $extract_dir;

		$blocks_file    = $csv_dir . '/GeoLite2-Country-Blocks-IPv4.csv';
		$locations_file = $csv_dir . '/GeoLite2-Country-Locations-en.csv';

		if ( ! file_exists( $blocks_file ) || ! file_exists( $locations_file ) ) {
			self::rrmdir( $extract_dir );
			return new WP_Error( 'op3pa_geoip_bad_zip', __( 'GeoLite2 CSV files not found in downloaded archive.', 'podcast-analytics-for-op3' ) );
		}

		$geoname_to_country = self::parse_locations_csv( $locations_file );
		$result              = self::import_blocks_csv( $blocks_file, $geoname_to_country );

		self::rrmdir( $extract_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		update_option( self::OPTION_LAST_UPDATE, time() );

		return true;
	}

	/**
	 * Parses GeoLite2-Country-Locations-en.csv into a geoname_id => country_code map.
	 *
	 * @param string $file Path to the locations CSV.
	 * @return array
	 */
	private static function parse_locations_csv( string $file ): array {
		$map    = [];
		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large local CSV row-by-row; WP_Filesystem has no streaming CSV reader.
		if ( ! $handle ) {
			return $map;
		}

		$header      = fgetcsv( $handle );
		$geoname_col = array_search( 'geoname_id', $header, true );
		$country_col = array_search( 'country_iso_code', $header, true );

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( isset( $row[ $geoname_col ], $row[ $country_col ] ) && '' !== $row[ $country_col ] ) {
				$map[ $row[ $geoname_col ] ] = $row[ $country_col ];
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.

		return $map;
	}

	/**
	 * Parses GeoLite2-Country-Blocks-IPv4.csv and bulk-imports it into a fresh
	 * staging table, then atomically swaps it in to replace the live table.
	 *
	 * @param string $file                Path to the blocks CSV.
	 * @param array  $geoname_to_country  Map of geoname_id => country_code.
	 * @return true|WP_Error
	 */
	private static function import_blocks_csv( string $file, array $geoname_to_country ): true|WP_Error {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$staging_table    = self::table() . '_staging';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed constant (not user input); DDL can't use $wpdb->prepare() placeholders.
		$wpdb->query( "DROP TABLE IF EXISTS {$staging_table}" );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above; disabled for this whole statement since the violations land on several of its lines.
		$wpdb->query(
			"CREATE TABLE {$staging_table} (
				start_ip BIGINT UNSIGNED NOT NULL,
				end_ip BIGINT UNSIGNED NOT NULL,
				country_code CHAR(2) NOT NULL,
				PRIMARY KEY (start_ip)
			) {$charset_collate}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a large local CSV row-by-row; WP_Filesystem has no streaming CSV reader.
		if ( ! $handle ) {
			return new WP_Error( 'op3pa_geoip_read_error', __( 'Could not open GeoLite2 blocks CSV.', 'podcast-analytics-for-op3' ) );
		}

		$header      = fgetcsv( $handle );
		$network_col = array_search( 'network', $header, true );
		$geoname_col = array_search( 'geoname_id', $header, true );
		$repr_col    = array_search( 'represented_country_geoname_id', $header, true );

		$batch       = [];
		$total_rows  = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			$network = $row[ $network_col ] ?? '';
			$geoname = $row[ $geoname_col ] ?? '';
			if ( empty( $geoname ) && false !== $repr_col ) {
				$geoname = $row[ $repr_col ] ?? '';
			}
			$country = $geoname_to_country[ $geoname ] ?? null;
			if ( empty( $network ) || empty( $country ) ) {
				continue;
			}

			[ $start, $end ] = self::cidr_to_range( $network );
			if ( null === $start ) {
				continue;
			}

			$batch[] = $wpdb->prepare( '(%d, %d, %s)', $start, $end, $country );
			$total_rows++;

			if ( count( $batch ) >= self::BATCH_SIZE ) {
				self::flush_batch( $staging_table, $batch );
				$batch = [];
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen() above.

		if ( ! empty( $batch ) ) {
			self::flush_batch( $staging_table, $batch );
		}

		if ( 0 === $total_rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a fixed constant (not user input); DDL can't use $wpdb->prepare() placeholders.
			$wpdb->query( "DROP TABLE IF EXISTS {$staging_table}" );
			return new WP_Error( 'op3pa_geoip_empty', __( 'GeoLite2 data parsed to zero ranges.', 'podcast-analytics-for-op3' ) );
		}

		// Atomic swap: staging becomes live, old live table is dropped.
		$live_table = self::table();
		$old_table  = $live_table . '_old';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
		$wpdb->query( "DROP TABLE IF EXISTS {$old_table}" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; value is prepared via %s.
		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $live_table ) );
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( "RENAME TABLE {$live_table} TO {$old_table}, {$staging_table} TO {$live_table}" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( "DROP TABLE IF EXISTS {$old_table}" );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
			$wpdb->query( "RENAME TABLE {$staging_table} TO {$live_table}" );
		}

		return true;
	}

	/**
	 * Inserts a batch of prepared value tuples into a table.
	 *
	 * @param string $table Table name.
	 * @param array  $batch Array of prepared "(%d, %d, %s)" value strings.
	 */
	private static function flush_batch( string $table, array $batch ): void {
		global $wpdb;
		$sql = "INSERT INTO {$table} (start_ip, end_ip, country_code) VALUES " . implode( ',', $batch );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- each "(%d, %d, %s)" tuple was already built with $wpdb->prepare() individually; table name is a fixed constant.
		$wpdb->query( $sql );
	}

	/**
	 * Converts an IPv4 CIDR block (e.g. "1.2.3.0/24") to [start_long, end_long].
	 *
	 * @param string $cidr CIDR notation.
	 * @return array{0: int|null, 1: int|null}
	 */
	private static function cidr_to_range( string $cidr ): array {
		if ( ! str_contains( $cidr, '/' ) || str_contains( $cidr, ':' ) ) {
			return [ null, null ]; // Skip IPv6 blocks.
		}
		[ $ip, $prefix ] = explode( '/', $cidr );
		$ip_long = ip2long( $ip );
		$prefix  = (int) $prefix;
		if ( false === $ip_long ) {
			return [ null, null ];
		}
		$mask  = -1 << ( 32 - $prefix );
		$start = $ip_long & $mask;
		$end   = $start | ~$mask & 0xFFFFFFFF;
		return [ $start, $end ];
	}

	/**
	 * Recursively deletes a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? self::rrmdir( $path ) : wp_delete_file( $path );
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

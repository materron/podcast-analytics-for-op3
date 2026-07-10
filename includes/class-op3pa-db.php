<?php
/**
 * Database layer for privately-tracked podcast downloads (v3.0).
 *
 * Used only for podcasts marked as private, since OP3 cannot serve
 * authenticated/restricted feeds. Public podcasts keep using the OP3 API.
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_DB {

	private const DB_VERSION_OPTION = 'op3pa_db_version';
	private const DB_VERSION        = '1.0';

	/**
	 * Returns the fully-qualified downloads table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'op3pa_downloads';
	}

	/**
	 * Creates or upgrades the downloads table. Safe to call on every load;
	 * dbDelta() only runs when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			podcast_index SMALLINT UNSIGNED NOT NULL,
			episode_id VARCHAR(191) NOT NULL,
			downloaded_at DATETIME NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			country_code CHAR(2) NULL,
			region VARCHAR(100) NULL,
			app_name VARCHAR(100) NULL,
			referer VARCHAR(255) NULL,
			PRIMARY KEY  (id),
			KEY podcast_episode (podcast_index, episode_id),
			KEY downloaded_at (downloaded_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Records a single download event.
	 *
	 * @param int    $podcast_index Podcast index.
	 * @param string $episode_id    Stable identifier for the episode (e.g. post ID or slug).
	 * @param array  $meta          Optional keys: country_code, region, app_name, referer.
	 */
	public static function record_download( int $podcast_index, string $episode_id, array $meta = [] ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			[
				'podcast_index' => $podcast_index,
				'episode_id'    => $episode_id,
				'downloaded_at' => current_time( 'mysql', true ),
				'ip_hash'       => self::hash_ip( self::get_client_ip() ),
				'country_code'  => $meta['country_code'] ?? null,
				'region'        => $meta['region'] ?? null,
				'app_name'      => $meta['app_name'] ?? null,
				'referer'       => $meta['referer'] ?? null,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Converts a period (days-back or explicit range) into a [since, until] pair
	 * of MySQL datetime strings. $until is null when using a rolling days-back window.
	 *
	 * @param int|array $period Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array{0: string, 1: string|null}
	 */
	private static function period_to_range( int|array $period ): array {
		if ( is_array( $period ) ) {
			$since = gmdate( 'Y-m-d 00:00:00', strtotime( $period['start'] ) );
			$until = ! empty( $period['end'] ) ? gmdate( 'Y-m-d 23:59:59', strtotime( $period['end'] ) ) : null;
			return [ $since, $until ];
		}
		return [ gmdate( 'Y-m-d H:i:s', time() - $period * DAY_IN_SECONDS ), null ];
	}

	/**
	 * Returns per-episode download counts for a podcast within a period.
	 *
	 * @param int       $podcast_index Podcast index.
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array List of ['episode_id' => string, 'downloads' => int].
	 */
	public static function get_episode_counts( int $podcast_index, int|array $period ): array {
		global $wpdb;
		$table = self::table();

		[ $since, $until ] = self::period_to_range( $period );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed constant, values are prepared.
		if ( null !== $until ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT episode_id, COUNT(*) as downloads
					 FROM {$table}
					 WHERE podcast_index = %d AND downloaded_at BETWEEN %s AND %s
					 GROUP BY episode_id
					 ORDER BY downloads DESC",
					$podcast_index,
					$since,
					$until
				),
				ARRAY_A
			);
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT episode_id, COUNT(*) as downloads
				 FROM {$table}
				 WHERE podcast_index = %d AND downloaded_at >= %s
				 GROUP BY episode_id
				 ORDER BY downloads DESC",
				$podcast_index,
				$since
			),
			ARRAY_A
		);
	}

	/**
	 * Returns per-episode download counts for a private podcast, shaped exactly
	 * like OP3PA_Api::get_download_counts() so the admin UI can render both
	 * public and private podcasts with the same table markup.
	 *
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_index Podcast index.
	 * @return array ['rows' => [ ['episodeTitle'=>, 'episodePubdate'=>, 'episodeUrl'=>, 'downloads'=>], ... ]]
	 */
	public static function get_download_counts( int|array $period, int $podcast_index ): array {
		$counts = self::get_episode_counts( $podcast_index, $period );
		$titles = self::resolve_episode_titles( wp_list_pluck( $counts, 'episode_id' ) );

		$rows = [];
		foreach ( $counts as $row ) {
			$episode_id = $row['episode_id'];
			$meta       = $titles[ $episode_id ] ?? null;

			$rows[] = [
				'episodeTitle'   => $meta['title'] ?? $episode_id,
				'episodeUrl'     => $meta['url'] ?? '',
				'episodePubdate' => $meta['pubdate'] ?? '',
				'downloads'      => (int) $row['downloads'],
			];
		}

		return [ 'rows' => $rows ];
	}

	/**
	 * Returns aggregated download counts across multiple private podcasts,
	 * shaped exactly like OP3PA_Api::get_network_counts().
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include (private ones only).
	 * @return array ['rows' => [ ['index'=>,'name'=>,'color'=>,'downloads'=>,'episodes'=>[...]] ], 'total' => int]
	 */
	public static function get_network_counts( int|array $period, array $indexes ): array {
		$rows  = [];
		$total = 0;

		foreach ( $indexes as $i ) {
			$podcast = op3pa_get_podcast( $i );
			$result  = self::get_download_counts( $period, $i );
			$sum     = array_sum( array_column( $result['rows'], 'downloads' ) );
			$total  += $sum;

			$rows[] = [
				'index'     => $i,
				'name'      => $podcast['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				),
				'color'     => ! empty( $podcast['color'] ) ? $podcast['color'] : '#0066cc',
				'downloads' => $sum,
				'episodes'  => $result['rows'],
			];
		}

		usort( $rows, fn( $a, $b ) => $b['downloads'] <=> $a['downloads'] );

		return compact( 'rows', 'total' );
	}

	/**
	 * Returns app breakdown for a private podcast within a period, shaped like
	 * OP3PA_Api::get_app_breakdown() so both can be combined in the same report.
	 *
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_index Podcast index.
	 * @return array List of ['name'=>, 'downloads'=>], sorted descending.
	 */
	public static function get_app_breakdown( int|array $period, int $podcast_index ): array {
		global $wpdb;
		$table = self::table();
		[ $since, $until ] = self::period_to_range( $period );

		$where_date = null !== $until
			? $wpdb->prepare( 'downloaded_at BETWEEN %s AND %s', $since, $until )
			: $wpdb->prepare( 'downloaded_at >= %s', $since );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed constant, $where_date was prepared above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(app_name, %s) as name, COUNT(*) as downloads
				 FROM {$table}
				 WHERE podcast_index = %d AND {$where_date}
				 GROUP BY name
				 ORDER BY downloads DESC",
				__( 'Unknown', 'podcast-analytics-for-op3' ),
				$podcast_index
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => [ 'name' => $row['name'], 'downloads' => (int) $row['downloads'] ],
			$rows
		);
	}

	/**
	 * Returns country breakdown for a private podcast within a period, shaped
	 * like OP3PA_Api::get_country_breakdown(). Requires MaxMind GeoLite2 to be
	 * configured (see OP3PA_Geo) — downloads recorded before that returns no
	 * country_code and are excluded here (grouped as NULL, filtered out).
	 *
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_index Podcast index.
	 * @return array List of ['code'=>ISO2, 'downloads'=>], sorted descending.
	 */
	public static function get_country_breakdown( int|array $period, int $podcast_index ): array {
		global $wpdb;
		$table = self::table();
		[ $since, $until ] = self::period_to_range( $period );

		$where_date = null !== $until
			? $wpdb->prepare( 'downloaded_at BETWEEN %s AND %s', $since, $until )
			: $wpdb->prepare( 'downloaded_at >= %s', $since );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed constant, $where_date was prepared above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country_code as code, COUNT(*) as downloads
				 FROM {$table}
				 WHERE podcast_index = %d AND country_code IS NOT NULL AND {$where_date}
				 GROUP BY code
				 ORDER BY downloads DESC",
				$podcast_index
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => [ 'code' => $row['code'], 'downloads' => (int) $row['downloads'] ],
			$rows
		);
	}

	/**
	 * Returns downloads grouped by hour-of-day (0-23) and weekday (0=Sun..6=Sat)
	 * for a private podcast, converted to the site's configured timezone.
	 * Shaped like OP3PA_Api::get_time_distribution().
	 *
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_index Podcast index.
	 * @return array ['by_hour' => [0..23 => count], 'by_weekday' => [0..6 => count]]
	 */
	public static function get_time_distribution( int|array $period, int $podcast_index ): array {
		global $wpdb;
		$table = self::table();
		[ $since, $until ] = self::period_to_range( $period );

		$where_date = null !== $until
			? $wpdb->prepare( 'downloaded_at BETWEEN %s AND %s', $since, $until )
			: $wpdb->prepare( 'downloaded_at >= %s', $since );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed constant, $where_date was prepared above.
		$timestamps = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT downloaded_at FROM {$table} WHERE podcast_index = %d AND {$where_date}",
				$podcast_index
			)
		);

		$tz         = wp_timezone();
		$by_hour    = array_fill( 0, 24, 0 );
		$by_weekday = array_fill( 0, 7, 0 );

		foreach ( $timestamps as $ts ) {
			try {
				$dt = new DateTime( $ts, new DateTimeZone( 'UTC' ) );
				$dt->setTimezone( $tz );
			} catch ( Exception $e ) {
				continue;
			}
			$by_hour[ (int) $dt->format( 'G' ) ]++;
			$by_weekday[ (int) $dt->format( 'w' ) ]++;
		}

		return [ 'by_hour' => $by_hour, 'by_weekday' => $by_weekday ];
	}

	/**
	 * Returns the count of unique listeners (distinct IP hash) for a private
	 * podcast within a period. The hash rotates daily for privacy, so this is
	 * exact for a 1-day period but may overcount for longer ones (the same
	 * listener across different days gets a different hash each day).
	 *
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_index Podcast index.
	 * @return int
	 */
	public static function get_unique_listeners( int|array $period, int $podcast_index ): int {
		global $wpdb;
		$table = self::table();
		[ $since, $until ] = self::period_to_range( $period );

		$where_date = null !== $until
			? $wpdb->prepare( 'downloaded_at BETWEEN %s AND %s', $since, $until )
			: $wpdb->prepare( 'downloaded_at >= %s', $since );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a fixed constant, $where_date was prepared above.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT ip_hash) FROM {$table} WHERE podcast_index = %d AND {$where_date}",
				$podcast_index
			)
		);
	}

	/**
	 * Resolves episode titles/URLs/dates by matching the tracked filename-derived
	 * episode_id against this site's own posts (via their PowerPress 'enclosure'
	 * postmeta), since we're tracking our own site's episodes, not an external feed.
	 *
	 * @param array $episode_ids Filename-derived episode identifiers (lowercase, no extension).
	 * @return array Map of episode_id => ['title'=>, 'url'=>, 'pubdate'=>].
	 */
	private static function resolve_episode_titles( array $episode_ids ): array {
		if ( empty( $episode_ids ) ) {
			return [];
		}

		global $wpdb;
		$wanted = array_flip( $episode_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are fixed constants.
		$posts = $wpdb->get_results(
			"SELECT p.ID, p.post_title, p.post_date, pm.meta_value
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'enclosure'
			 WHERE p.post_status IN ('publish', 'draft', 'private', 'future')"
		);

		$map = [];
		foreach ( $posts as $post ) {
			$enclosure_url = strtok( (string) $post->meta_value, "\n" );
			$filename      = strtolower( pathinfo( (string) wp_parse_url( $enclosure_url, PHP_URL_PATH ), PATHINFO_FILENAME ) );

			if ( isset( $wanted[ $filename ] ) ) {
				$map[ $filename ] = [
					'title'   => $post->post_title,
					'url'     => get_permalink( $post->ID ),
					'pubdate' => $post->post_date,
				];
			}
		}

		return $map;
	}

	/**
	 * Returns the client's real IP address, respecting common reverse-proxy headers.
	 * Public so OP3PA_Tracker can reuse it for the GeoLite2 lookup (which needs
	 * the raw IP once, before it gets hashed here and discarded).
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// X-Forwarded-For can be a comma-separated list; first entry is the original client.
				return trim( explode( ',', $ip )[0] );
			}
		}
		return '';
	}

	/**
	 * Hashes an IP with a salt that rotates daily, so downloads can be deduplicated
	 * as "unique listeners" without ever storing or retaining the raw IP.
	 *
	 * @param string $ip Raw IP address.
	 * @return string SHA-256 hex hash.
	 */
	private static function hash_ip( string $ip ): string {
		$daily_salt = wp_salt( 'auth' ) . gmdate( 'Y-m-d' );
		return hash( 'sha256', $ip . $daily_salt );
	}
}

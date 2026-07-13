<?php
/**
 * OP3 API client.
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Api {

	private const API_BASE  = 'https://op3.dev/api/1/';
	private const CACHE_TTL = HOUR_IN_SECONDS;
	private const TIMEOUT   = 15;

	/** Maximum pages to fetch to avoid infinite loops. */
	private const MAX_PAGES = 20;

	/**
	 * Fetches download counts for a single show, grouped by episode.
	 * Paginates through all results using continuationToken.
	 *
	 * @param int|array $period    Days back (1, 7, 30...), or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error
	 */
	public static function get_download_counts( int|array $period, int $podcast_i = 0 ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		if ( empty( $podcast['show_uuid'] ) ) {
			return new WP_Error( 'op3pa_no_uuid', __( 'No Show UUID configured.', 'podcast-analytics-for-op3' ) );
		}

		$cache_key = 'op3pa_downloads_' . $podcast_i . '_' . self::period_cache_suffix( $period );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_rows = self::get_raw_rows( $period, $podcast_i );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}

		$episode_titles = self::get_episode_titles( $podcast['show_uuid'] );
		$normalised     = self::normalise_rows( $all_rows, $episode_titles );
		set_transient( $cache_key, $normalised, self::CACHE_TTL );
		return $normalised;
	}

	/**
	 * Returns app/device breakdown for a public podcast within a period,
	 * built from the same raw rows used for episode counts (no extra API calls).
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error List of ['name'=>, 'downloads'=>], sorted descending.
	 */
	public static function get_app_breakdown( int|array $period, int $podcast_i ): array|WP_Error {
		$cache_key = 'op3pa_apps_' . $podcast_i . '_' . self::period_cache_suffix( $period );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_rows = self::get_raw_rows( $period, $podcast_i );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}

		$counts = [];
		foreach ( $all_rows as $row ) {
			$name             = $row['agentName'] ?? __( 'Unknown', 'podcast-analytics-for-op3' );
			$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
		}
		arsort( $counts );

		$result = [];
		foreach ( $counts as $name => $count ) {
			$result[] = [ 'name' => $name, 'downloads' => $count ];
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Returns country breakdown for a public podcast within a period, built
	 * from the same raw rows used for episode counts (no extra API calls).
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error List of ['code'=>ISO2, 'downloads'=>], sorted descending.
	 */
	public static function get_country_breakdown( int|array $period, int $podcast_i ): array|WP_Error {
		$cache_key = 'op3pa_countries_' . $podcast_i . '_' . self::period_cache_suffix( $period );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_rows = self::get_raw_rows( $period, $podcast_i );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}

		$counts = [];
		foreach ( $all_rows as $row ) {
			$code = strtoupper( (string) ( $row['countryCode'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}
			$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
		}
		arsort( $counts );

		$result = [];
		foreach ( $counts as $code => $count ) {
			$result[] = [ 'code' => $code, 'downloads' => $count ];
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Returns downloads grouped by hour-of-day (0-23) and by weekday (0=Sun..6=Sat),
	 * converted to the site's configured timezone so it reflects the publisher's
	 * own clock, not UTC.
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error ['by_hour' => [0..23 => count], 'by_weekday' => [0..6 => count]]
	 */
	public static function get_time_distribution( int|array $period, int $podcast_i ): array|WP_Error {
		$cache_key = 'op3pa_time_' . $podcast_i . '_' . self::period_cache_suffix( $period );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_rows = self::get_raw_rows( $period, $podcast_i );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}

		$tz        = wp_timezone();
		$by_hour   = array_fill( 0, 24, 0 );
		$by_weekday = array_fill( 0, 7, 0 );

		foreach ( $all_rows as $row ) {
			$time = $row['time'] ?? '';
			if ( ! $time ) {
				continue;
			}
			try {
				$dt = new DateTime( $time, new DateTimeZone( 'UTC' ) );
				$dt->setTimezone( $tz );
			} catch ( Exception $e ) {
				continue;
			}
			$by_hour[ (int) $dt->format( 'G' ) ]++;
			$by_weekday[ (int) $dt->format( 'w' ) ]++;
		}

		$result = [ 'by_hour' => $by_hour, 'by_weekday' => $by_weekday ];
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Returns the count of unique listeners (distinct audienceId) for a public
	 * podcast within a period. OP3's audienceId is a stable rotating-salt hash,
	 * accurate across the whole period (unlike our own per-day IP hash for
	 * private podcasts — see OP3PA_DB::get_unique_listeners()).
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return int|WP_Error
	 */
	public static function get_unique_listeners( int|array $period, int $podcast_i ): int|WP_Error {
		$ids = self::get_audience_ids( $period, $podcast_i );
		return is_wp_error( $ids ) ? $ids : count( $ids );
	}

	/**
	 * Returns the set of distinct listener identifiers (audienceId) for a
	 * public podcast within a period. Used both for the unique-listener count
	 * and for cross-podcast audience-overlap analysis.
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error List of distinct audienceId strings.
	 */
	public static function get_audience_ids( int|array $period, int $podcast_i ): array|WP_Error {
		$all_rows = self::get_raw_rows( $period, $podcast_i );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}
		$ids = array_filter( array_column( $all_rows, 'audienceId' ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Fetches and caches raw (unaggregated) download rows for a podcast/period,
	 * paginating through OP3's continuationToken. Shared by any aggregation
	 * (episode counts, app breakdown, geo, time-of-day...) to avoid duplicate
	 * API calls for the same podcast/period.
	 *
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param int       $podcast_i Podcast index.
	 * @return array|WP_Error
	 */
	private static function get_raw_rows( int|array $period, int $podcast_i ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		if ( empty( $podcast['show_uuid'] ) ) {
			return new WP_Error( 'op3pa_no_uuid', __( 'No Show UUID configured.', 'podcast-analytics-for-op3' ) );
		}

		$cache_key = 'op3pa_raw_' . $podcast_i . '_' . self::period_cache_suffix( $period );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$all_rows     = [];
		$continuation = null;
		$pages        = 0;
		$base_params  = array_merge(
			[
				'limit'  => 1000,
				'format' => 'json',
				'bots'   => 'exclude',
			],
			self::period_to_api_params( $period )
		);

		do {
			$params = $base_params;
			if ( $continuation ) {
				$params['continuationToken'] = $continuation;
			}
			$url      = add_query_arg( $params, self::API_BASE . 'downloads/show/' . rawurlencode( $podcast['show_uuid'] ) );
			$response = self::request( $url );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$all_rows     = array_merge( $all_rows, $response['rows'] ?? [] );
			$continuation = $response['continuationToken'] ?? null;
			$pages++;
		} while ( $continuation && $pages < self::MAX_PAGES );

		set_transient( $cache_key, $all_rows, self::CACHE_TTL );
		return $all_rows;
	}

	/**
	 * Converts a period (days-back or explicit range) into OP3 API query params.
	 *
	 * @param int|array $period Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array
	 */
	private static function period_to_api_params( int|array $period ): array {
		if ( is_array( $period ) ) {
			$params = [ 'start' => $period['start'] ];
			if ( ! empty( $period['end'] ) ) {
				$params['end'] = $period['end'];
			}
			return $params;
		}
		return [ 'start' => '-' . $period . 'd' ];
	}

	/**
	 * Builds a stable cache-key suffix for a period.
	 *
	 * @param int|array $period Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return string
	 */
	private static function period_cache_suffix( int|array $period ): string {
		if ( is_array( $period ) ) {
			return 'range_' . md5( ( $period['start'] ?? '' ) . '|' . ( $period['end'] ?? '' ) );
		}
		return $period . 'd';
	}

	/**
	 * Fetches and aggregates download counts across multiple shows (network view).
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array|WP_Error Array with 'rows' (per show totals) and 'total'.
	 */
	public static function get_network_counts( int|array $period = 30, array $indexes = [] ): array|WP_Error {
		$active = op3pa_get_active_podcasts();
		if ( empty( $active ) ) {
			return new WP_Error( 'op3pa_no_podcasts', __( 'No active podcasts configured.', 'podcast-analytics-for-op3' ) );
		}

		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$sort_key  = implode( '-', array_keys( $active ) );
		$cache_key = 'op3pa_network_' . self::period_cache_suffix( $period ) . '_' . md5( $sort_key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows  = [];
		$total = 0;

		foreach ( $active as $i => $podcast ) {
			$result = self::get_download_counts( $period, $i );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$show_total = array_sum( array_column( $result['rows'], 'downloads' ) );
			$total     += $show_total;
			$rows[]     = [
				'index'     => $i,
				'name'      => $podcast['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				),
				'show_uuid' => $podcast['show_uuid'],
				'color'     => ! empty( $podcast['color'] ) ? $podcast['color'] : '#0066cc',
				'downloads' => $show_total,
				'episodes'  => $result['rows'],
			];
		}

		usort( $rows, fn( $a, $b ) => $b['downloads'] <=> $a['downloads'] );

		$result = compact( 'rows', 'total' );
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Returns the OP3 public stats page URL for a given podcast index.
	 *
	 * @param int $podcast_i Podcast index.
	 * @return string
	 */
	public static function get_stats_page_url( int $podcast_i = 0 ): string {
		$podcast = op3pa_get_podcast( $podcast_i );
		if ( empty( $podcast['show_uuid'] ) ) {
			return 'https://op3.dev';
		}
		return 'https://op3.dev/show/' . rawurlencode( $podcast['show_uuid'] );
	}

	/**
	 * Clears cached transients for a given podcast index or all podcasts.
	 *
	 * @param int|null $podcast_i Podcast index, or null to clear all.
	 */
	public static function clear_cache( ?int $podcast_i = null ): void {
		$days = [ '1d', '7d', '30d' ];

		if ( null === $podcast_i ) {
			foreach ( array_keys( op3pa_get_podcasts() ) as $i ) {
				foreach ( $days as $d ) {
					delete_transient( 'op3pa_downloads_' . $i . '_' . $d );
					delete_transient( 'op3pa_raw_' . $i . '_' . $d );
					delete_transient( 'op3pa_apps_' . $i . '_' . $d );
					delete_transient( 'op3pa_countries_' . $i . '_' . $d );
					delete_transient( 'op3pa_time_' . $i . '_' . $d );
				}
			}
		} else {
			foreach ( $days as $d ) {
				delete_transient( 'op3pa_downloads_' . $podcast_i . '_' . $d );
				delete_transient( 'op3pa_raw_' . $podcast_i . '_' . $d );
				delete_transient( 'op3pa_apps_' . $podcast_i . '_' . $d );
				delete_transient( 'op3pa_countries_' . $podcast_i . '_' . $d );
				delete_transient( 'op3pa_time_' . $podcast_i . '_' . $d );
			}
		}

		// Range-based caches use an md5 hash suffix (can't be enumerated by day), so
		// clear them by LIKE pattern instead — along with network/episode-title caches.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk pattern-delete of transients by LIKE; no core WP function clears transients by prefix, and every value here is passed through $wpdb->prepare().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s
				 OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_op3pa_network_' ) . '%',
				$wpdb->esc_like( '_transient_op3pa_ep_titles_' ) . '%',
				$wpdb->esc_like( '_transient_op3pa_downloads_' ) . '%_range_%',
				$wpdb->esc_like( '_transient_op3pa_raw_' ) . '%_range_%',
				$wpdb->esc_like( '_transient_op3pa_apps_' ) . '%_range_%',
				$wpdb->esc_like( '_transient_op3pa_countries_' ) . '%_range_%',
				$wpdb->esc_like( '_transient_op3pa_time_' ) . '%_range_%'
			)
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetches episode titles from the show info endpoint.
	 * Returns a map of episode id (lowercase hex) → title.
	 *
	 * @param string $show_uuid OP3 show UUID.
	 * @return array Map of episode id → title.
	 */
	private static function get_episode_titles( string $show_uuid ): array {
		$cache_key = 'op3pa_ep_titles_' . md5( $show_uuid );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = self::API_BASE . 'shows/' . rawurlencode( $show_uuid ) . '?episodes=include';
		$response = self::request( $url );

		if ( is_wp_error( $response ) || empty( $response['episodes'] ) ) {
			set_transient( $cache_key, [], self::CACHE_TTL );
			return [];
		}

		$map = [];
		foreach ( $response['episodes'] as $ep ) {
			if ( empty( $ep['id'] ) || empty( $ep['title'] ) ) {
				continue;
			}
			$entry = [
				'title'   => $ep['title'],
				'pubdate' => ! empty( $ep['pubdate'] ) ? substr( $ep['pubdate'], 0, 10 ) : '',
			];
			$map[ strtolower( $ep['id'] ) ] = $entry;
			if ( ! empty( $ep['itemGuid'] ) ) {
				$map[ strtolower( $ep['itemGuid'] ) ] = $entry;
			}
		}

		set_transient( $cache_key, $map, self::CACHE_TTL );
		return $map;
	}

	/**
	 * Aggregates raw OP3 download rows by episode URL.
	 * Uses episodeId field (present in download rows) to look up real titles.
	 * Falls back to itemGuid filename match, then bare filename.
	 *
	 * @param array $raw_rows       Raw rows from OP3 API.
	 * @param array $episode_titles Map of episode id / itemGuid → title.
	 * @return array Normalised array with 'rows' key.
	 */
	private static function normalise_rows( array $raw_rows, array $episode_titles = [] ): array {
		$by_episode = [];
		foreach ( $raw_rows as $row ) {
			$url_key = $row['url'] ?? '';
			if ( ! $url_key ) {
				continue;
			}
			if ( ! isset( $by_episode[ $url_key ] ) ) {
				$display_url = preg_replace( '#^https://op3\.dev/e[^/]*/(?:https?/)?#', 'https://', $url_key );
				$filename    = pathinfo( (string) wp_parse_url( $display_url, PHP_URL_PATH ), PATHINFO_FILENAME );

				$title = basename( (string) wp_parse_url( $display_url, PHP_URL_PATH ) ) ?: $display_url;

				if ( ! empty( $episode_titles ) ) {
					$ep_id = strtolower( $row['episodeId'] ?? '' );
					$entry = null;
					if ( $ep_id && isset( $episode_titles[ $ep_id ] ) ) {
						$entry = $episode_titles[ $ep_id ];
					} elseif ( $filename && isset( $episode_titles[ strtolower( $filename ) ] ) ) {
						$entry = $episode_titles[ strtolower( $filename ) ];
					}
					if ( $entry ) {
						$title   = $entry['title'];
						$pubdate = $entry['pubdate'];
					}
				}

				$by_episode[ $url_key ] = [
					'episodeTitle'   => $title,
					'episodePubdate' => $pubdate ?? '',
					'episodeUrl'     => $display_url,
					'downloads'      => 0,
				];
			}
			$by_episode[ $url_key ]['downloads']++;
		}

		$rows = array_values( $by_episode );
		usort( $rows, fn( $a, $b ) => $b['downloads'] <=> $a['downloads'] );
		return [ 'rows' => $rows ];
	}

	/**
	 * Makes an authenticated GET request to the OP3 API.
	 *
	 * @param string $url Full URL.
	 * @return array|WP_Error
	 */
	private static function request( string $url ): array|WP_Error {
		$token = op3pa_get_token();

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::TIMEOUT,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'User-Agent'    => 'Podcast Analytics for OP3 WordPress Plugin/' . OP3PA_VERSION,
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 401 === $code ) {
			return new WP_Error( 'op3pa_unauthorized', __( 'Invalid bearer token. Check your OP3 settings.', 'podcast-analytics-for-op3' ) );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'op3pa_not_found', __( 'Show not found. Check your Show UUID.', 'podcast-analytics-for-op3' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'op3pa_api_error',
				/* translators: %d: HTTP status code */
				sprintf( __( 'OP3 API returned status %d.', 'podcast-analytics-for-op3' ), $code )
			);
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'op3pa_parse_error', __( 'Could not parse OP3 API response.', 'podcast-analytics-for-op3' ) );
		}

		return $data;
	}
}

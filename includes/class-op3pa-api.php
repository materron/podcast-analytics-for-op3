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
	 * @param int $days      1, 7, or 30.
	 * @param int $podcast_i Podcast index.
	 * @return array|WP_Error
	 */
	public static function get_download_counts( int $days = 30, int $podcast_i = 0 ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		if ( empty( $podcast['show_uuid'] ) ) {
			return new WP_Error( 'op3pa_no_uuid', __( 'No Show UUID configured.', 'podcast-analytics-for-op3' ) );
		}

		$cache_key = 'op3pa_downloads_' . $podcast_i . '_' . $days . 'd';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Paginate through all results.
		$all_rows          = [];
		$continuation      = null;
		$pages             = 0;
		$base_params       = [
			'start'  => '-' . $days . 'd',
			'limit'  => 1000,
			'format' => 'json',
			'bots'   => 'exclude',
		];

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

		$episode_titles = self::get_episode_titles( $podcast['show_uuid'] );
		$normalised     = self::normalise_rows( $all_rows, $episode_titles );
		set_transient( $cache_key, $normalised, self::CACHE_TTL );
		return $normalised;
	}

	/**
	 * Fetches and aggregates download counts across multiple shows (network view).
	 *
	 * @param int   $days    1, 7, or 30.
	 * @param array $indexes Podcast indexes to include. Empty = all active.
	 * @return array|WP_Error Array with 'rows' (per show totals) and 'total'.
	 */
	public static function get_network_counts( int $days = 30, array $indexes = [] ): array|WP_Error {
		$active = op3pa_get_active_podcasts();
		if ( empty( $active ) ) {
			return new WP_Error( 'op3pa_no_podcasts', __( 'No active podcasts configured.', 'podcast-analytics-for-op3' ) );
		}

		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$sort_key  = implode( '-', array_keys( $active ) );
		$cache_key = 'op3pa_network_' . $days . 'd_' . md5( $sort_key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows  = [];
		$total = 0;

		foreach ( $active as $i => $podcast ) {
			$result = self::get_download_counts( $days, $i );
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
				}
			}
		} else {
			foreach ( $days as $d ) {
				delete_transient( 'op3pa_downloads_' . $podcast_i . '_' . $d );
			}
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_op3pa_network_' ) . '%',
				$wpdb->esc_like( '_transient_op3pa_ep_titles_' ) . '%'
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

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

	/**
	 * Fetches download counts for the configured show grouped by episode.
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

		$start = '-' . $days . 'd';
		$url   = add_query_arg(
			[
				'start'  => $start,
				'limit'  => 1000,
				'format' => 'json',
				'bots'   => 'exclude',
			],
			self::API_BASE . 'downloads/show/' . rawurlencode( $podcast['show_uuid'] )
		);

		$response = self::request( $url, $podcast );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Aggregate raw download rows by episode URL.
		$by_episode = [];
		foreach ( $response['rows'] ?? [] as $row ) {
			$url_key = $row['url'] ?? '';
			if ( ! $url_key ) {
				continue;
			}
			if ( ! isset( $by_episode[ $url_key ] ) ) {
				$display_url = preg_replace( '#^https://op3\.dev/e[^/]*/(?:https?/)?#', 'https://', $url_key );
				$filename    = basename( (string) wp_parse_url( $display_url, PHP_URL_PATH ) );

				$by_episode[ $url_key ] = [
					'episodeTitle' => $filename ?: $display_url,
					'episodeUrl'   => $display_url,
					'downloads'    => 0,
				];
			}
			$by_episode[ $url_key ]['downloads']++;
		}

		$rows = array_values( $by_episode );
		usort( $rows, fn( $a, $b ) => $b['downloads'] <=> $a['downloads'] );

		$normalised = [ 'rows' => $rows ];
		set_transient( $cache_key, $normalised, self::CACHE_TTL );
		return $normalised;
	}

	/**
	 * Fetches show info + episode list.
	 *
	 * @param int $podcast_i Podcast index.
	 * @return array|WP_Error
	 */
	public static function get_show( int $podcast_i = 0 ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		if ( empty( $podcast['show_uuid'] ) ) {
			return new WP_Error( 'op3pa_no_uuid', __( 'No Show UUID configured.', 'podcast-analytics-for-op3' ) );
		}

		$cache_key = 'op3pa_show_' . $podcast_i;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = self::API_BASE . 'shows/' . rawurlencode( $podcast['show_uuid'] ) . '?episodes=include';
		$response = self::request( $url, $podcast );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		set_transient( $cache_key, $response, self::CACHE_TTL );
		return $response;
	}

	/**
	 * Returns the OP3 public stats page URL for the configured show.
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
	 * Makes an authenticated GET request to the OP3 API.
	 *
	 * @param string $url     Full URL.
	 * @param array  $podcast Podcast settings array (needs 'api_key').
	 * @return array|WP_Error
	 */
	private static function request( string $url, array $podcast ): array|WP_Error {
		$token = ! empty( $podcast['api_key'] ) ? $podcast['api_key'] : '';

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

	/**
	 * Clears all cached transients for a given podcast index.
	 *
	 * @param int $podcast_i Podcast index.
	 */
	public static function clear_cache( int $podcast_i = 0 ): void {
		delete_transient( 'op3pa_downloads_' . $podcast_i . '_1d' );
		delete_transient( 'op3pa_downloads_' . $podcast_i . '_7d' );
		delete_transient( 'op3pa_downloads_' . $podcast_i . '_30d' );
		delete_transient( 'op3pa_show_' . $podcast_i );
	}
}

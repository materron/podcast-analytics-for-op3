<?php
/**
 * Self-hosted download-tracking redirect endpoint for private podcasts.
 *
 * OP3 cannot serve authenticated/restricted feeds (it's an open data service
 * by design), so private podcasts get their enclosures rewritten to point here
 * instead of op3.dev. This endpoint logs the download, then 302-redirects to
 * the real audio file — mirroring how OP3's own prefix redirect works.
 *
 * URL shape: /op3-dl/{podcast_index}/{episode_id}/{original_url_without_scheme}
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Tracker {

	private const REWRITE_FLUSHED_OPTION = 'op3pa_rewrite_flushed_v3';

	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'add_rewrite_rule' ] );
		add_filter( 'query_vars', [ __CLASS__, 'register_query_vars' ] );
		add_action( 'template_redirect', [ __CLASS__, 'maybe_handle_request' ], 0 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );
	}

	/**
	 * Registers the rewrite rule mapping our pretty URL to internal query vars.
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^op3-dl/([0-9]+)/([^/]+)/(https?/.+)$',
			'index.php?op3pa_track=1&op3pa_podcast=$matches[1]&op3pa_episode=$matches[2]&op3pa_url=$matches[3]',
			'top'
		);
	}

	/**
	 * @param array $vars Existing public query vars.
	 * @return array
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'op3pa_track';
		$vars[] = 'op3pa_podcast';
		$vars[] = 'op3pa_episode';
		$vars[] = 'op3pa_url';
		return $vars;
	}

	/**
	 * Flushes rewrite rules once after the tracker is introduced (v3.0 upgrade),
	 * so the new rule takes effect without requiring a manual permalinks resave.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( get_option( self::REWRITE_FLUSHED_OPTION ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_FLUSHED_OPTION, true );
	}

	/**
	 * Handles the tracked download request: logs it, then redirects to the real file.
	 */
	public static function maybe_handle_request(): void {
		if ( '1' !== get_query_var( 'op3pa_track' ) ) {
			return;
		}

		$podcast_index = absint( get_query_var( 'op3pa_podcast' ) );
		$episode_id    = sanitize_text_field( get_query_var( 'op3pa_episode' ) );
		$encoded_url   = get_query_var( 'op3pa_url' );

		$target_url = self::decode_target_url( $encoded_url );

		if ( empty( $target_url ) || empty( $episode_id ) ) {
			status_header( 400 );
			exit;
		}

		OP3PA_DB::record_download( $podcast_index, $episode_id, [
			'app_name' => self::detect_app( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
			'referer'  => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : null,
		] );

		wp_redirect( $target_url, 302 ); // phpcs:ignore WordPress.Security.SafeRedirect -- target is the site's own known audio URL.
		exit;
	}

	/**
	 * Rebuilds the full original URL from the rewrite rule's captured path segment.
	 * The scheme separator ":" is stripped by rewrite rules (single slash after
	 * http/https survives, the colon does not), so it's restored here.
	 *
	 * @param string $encoded Captured "https/host/path..." segment.
	 * @return string Full URL, or '' if malformed.
	 */
	private static function decode_target_url( string $encoded ): string {
		if ( ! preg_match( '#^(https?)/(.+)$#', $encoded, $m ) ) {
			return '';
		}
		$url = $m[1] . '://' . $m[2];
		return esc_url_raw( $url );
	}

	/**
	 * Best-effort detection of the podcast app from its User-Agent string.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return string|null App name, or null if unrecognised.
	 */
	private static function detect_app( string $user_agent ): ?string {
		if ( empty( $user_agent ) ) {
			return null;
		}

		$patterns = [
			'Overcast'          => '/Overcast/i',
			'Pocket Casts'      => '/Pocket ?Casts/i',
			'Spotify'           => '/Spotify/i',
			'Apple Podcasts'    => '/AppleCoreMedia|Podcasts\//i',
			'Google Podcasts'   => '/GooglePodcasts|Google-Podcast/i',
			'Podcast Addict'    => '/Podcast ?Addict/i',
			'AntennaPod'        => '/AntennaPod/i',
			'Castro'            => '/Castro/i',
			'Downcast'          => '/Downcast/i',
			'iVoox'             => '/iVoox/i',
			'PocketCasts Web'   => '/pocketcasts\.com/i',
			'Amazon Music'      => '/Amazon ?Music/i',
			'YouTube Music'     => '/YouTube ?Music/i',
		];

		foreach ( $patterns as $name => $pattern ) {
			if ( preg_match( $pattern, $user_agent ) ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * Builds the tracked download URL for a given podcast/episode/original URL.
	 *
	 * @param int    $podcast_index Podcast index.
	 * @param string $episode_id    Stable episode identifier.
	 * @param string $original_url  Real audio file URL.
	 * @return string
	 */
	public static function build_tracked_url( int $podcast_index, string $episode_id, string $original_url ): string {
		$without_scheme = preg_replace( '#^(https?)://#', '$1/', $original_url );
		return home_url( '/op3-dl/' . $podcast_index . '/' . rawurlencode( $episode_id ) . '/' . $without_scheme );
	}
}

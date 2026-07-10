<?php
/**
 * Handles rewriting audio enclosure URLs in podcast RSS feeds.
 *
 * Public podcasts get the OP3 prefix (https://op3.dev/e/...) for open analytics.
 * Private podcasts (restricted feeds OP3 cannot access) get rewritten to our own
 * self-hosted tracking endpoint instead (see OP3PA_Tracker).
 *
 * When a podcast has a 'feed_slug' configured, rewriting is scoped strictly to
 * the matching feed (WordPress's /feed/{slug}/ custom feed, exposed via the
 * 'feed' query var) — this is required for private podcasts, so their tracking
 * URLs never leak into other feeds and vice versa. Podcasts without a feed_slug
 * fall back to the original site-wide behaviour for backward compatibility.
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Feed {

	private const OP3_PREFIX      = 'https://op3.dev/e/';
	private const AUDIO_EXTENSIONS = 'mp3|m4a|ogg|oga|opus|aac|wav|flac';

	public static function init(): void {
		$podcasts = op3pa_get_podcasts();
		if ( ! empty( $podcasts ) && empty( self::get_prefixable_podcasts() ) && empty( self::get_scoped_podcasts() ) ) {
			return;
		}

		add_action( 'template_redirect', [ __CLASS__, 'maybe_hook_feed' ], 1 );
	}

	/**
	 * Returns podcasts without a feed_slug that should receive the site-wide
	 * OP3 prefix fallback (legacy behaviour, not private).
	 *
	 * @return array
	 */
	private static function get_prefixable_podcasts(): array {
		return array_filter(
			op3pa_get_podcasts(),
			static fn( $p ) => empty( $p['private'] ) && empty( $p['feed_slug'] )
		);
	}

	/**
	 * Returns podcasts that have a feed_slug configured, keyed by slug.
	 * These are matched exactly to the current feed request.
	 *
	 * @return array
	 */
	private static function get_scoped_podcasts(): array {
		$scoped = [];
		foreach ( op3pa_get_podcasts() as $i => $podcast ) {
			if ( ! empty( $podcast['feed_slug'] ) ) {
				$podcast['index']              = $i;
				$scoped[ $podcast['feed_slug'] ] = $podcast;
			}
		}
		return $scoped;
	}

	/**
	 * Hook into the feed only when WordPress is about to serve a feed.
	 */
	public static function maybe_hook_feed(): void {
		if ( ! is_feed() ) {
			return;
		}
		ob_start();
		add_action( 'shutdown', [ __CLASS__, 'flush_feed_buffer' ], 0 );
	}

	/**
	 * Closes the buffer explicitly, rewrites feed XML and sends it.
	 */
	public static function flush_feed_buffer(): void {
		$feed_xml = ob_get_clean();
		if ( false === $feed_xml ) {
			return;
		}
		$current_feed_slug = (string) get_query_var( 'feed' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- feed XML, not HTML.
		echo self::rewrite_feed( $feed_xml, $current_feed_slug );
	}

	/**
	 * Rewrites audio URLs in the feed XML.
	 *
	 * @param string $feed_xml           Full RSS XML output.
	 * @param string $current_feed_slug  The 'feed' query var for this request (e.g. "premiumpp"), or '' for the default feed.
	 * @return string Modified XML.
	 */
	public static function rewrite_feed( string $feed_xml, string $current_feed_slug = '' ): string {
		$scoped = self::get_scoped_podcasts();

		// A podcast explicitly claims this feed slug: handle it exclusively (public or private).
		if ( '' !== $current_feed_slug && isset( $scoped[ $current_feed_slug ] ) ) {
			$podcast = $scoped[ $current_feed_slug ];
			return ! empty( $podcast['private'] )
				? self::rewrite_to_tracker( $feed_xml, (int) $podcast['index'] )
				: self::rewrite_to_op3( $feed_xml, $podcast['guid'] ?? '' );
		}

		// This feed isn't claimed by any scoped podcast: legacy site-wide fallback.
		$prefixable = self::get_prefixable_podcasts();
		$podcasts   = op3pa_get_podcasts();
		if ( ! empty( $podcasts ) && empty( $prefixable ) ) {
			return $feed_xml;
		}
		$first = ! empty( $prefixable ) ? reset( $prefixable ) : [];
		return self::rewrite_to_op3( $feed_xml, $first['guid'] ?? '' );
	}

	/**
	 * Rewrites enclosure URLs with the public OP3 prefix.
	 *
	 * @param string $feed_xml Feed XML.
	 * @param string $guid     Podcast GUID for OP3 attribution (optional).
	 * @return string
	 */
	private static function rewrite_to_op3( string $feed_xml, string $guid ): string {
		$prefix     = self::OP3_PREFIX;
		$ext        = self::AUDIO_EXTENSIONS;
		$guid_param = ! empty( $guid ) ? '?_from=' . rawurlencode( $guid ) : '';

		return preg_replace_callback(
			'/(url=["\'])(https?:\/\/)([^\s"\']+?\.(' . $ext . ')(\?[^"\']*)?)(["\'"])/i',
			function ( array $m ) use ( $prefix, $guid_param ): string {
				if ( str_contains( $m[2] . $m[3], 'op3.dev/e/' ) ) {
					return $m[0];
				}
				$without_protocol = preg_replace( '#^https?://#', '', $m[2] . $m[3] );
				return $m[1] . $prefix . $without_protocol . $guid_param . $m[6];
			},
			$feed_xml
		);
	}

	/**
	 * Rewrites enclosure URLs to our own tracking endpoint (private podcasts).
	 *
	 * @param string $feed_xml      Feed XML.
	 * @param int    $podcast_index Podcast index, embedded in the tracked URL.
	 * @return string
	 */
	private static function rewrite_to_tracker( string $feed_xml, int $podcast_index ): string {
		$ext = self::AUDIO_EXTENSIONS;

		return preg_replace_callback(
			'/(url=["\'])(https?:\/\/)([^\s"\']+?\.(' . $ext . ')(\?[^"\']*)?)(["\'"])/i',
			function ( array $m ) use ( $podcast_index ): string {
				$original_url = $m[2] . $m[3];
				if ( str_contains( $original_url, '/op3-dl/' ) ) {
					return $m[0];
				}
				$episode_id = self::derive_episode_id( $original_url );
				$tracked    = OP3PA_Tracker::build_tracked_url( $podcast_index, $episode_id, $original_url );
				return $m[1] . $tracked . $m[6];
			},
			$feed_xml
		);
	}

	/**
	 * Derives a stable episode identifier from the audio filename.
	 *
	 * @param string $url Original audio URL.
	 * @return string
	 */
	private static function derive_episode_id( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return strtolower( pathinfo( $path, PATHINFO_FILENAME ) );
	}

	/**
	 * Given a plain audio URL, returns the OP3-prefixed version.
	 *
	 * @param string $url Original audio URL.
	 * @return string Prefixed URL.
	 */
	public static function prefix_url( string $url ): string {
		if ( empty( $url ) || str_contains( $url, 'op3.dev/e/' ) ) {
			return $url;
		}
		$without_protocol = preg_replace( '#^https?://#', '', $url );
		return self::OP3_PREFIX . $without_protocol;
	}
}

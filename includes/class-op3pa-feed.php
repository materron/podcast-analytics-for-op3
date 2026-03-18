<?php
/**
 * Handles adding the OP3 prefix to all audio enclosure URLs in the RSS feed.
 *
 * Strategy: buffer the entire RSS2 feed output, then apply a regex replacement
 * on every audio URL found in <enclosure>, <media:content>, and <itunes:*> tags.
 * This approach is plugin-agnostic and works with PowerPress, Seriously Simple
 * Podcasting, Podlove, or plain WordPress.
 *
 * @package OP3_Podcast_Analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Feed {

	private const OP3_PREFIX = 'https://op3.dev/e/';

	/** Audio extensions we want to prefix. */
	private const AUDIO_EXTENSIONS = 'mp3|m4a|ogg|oga|opus|aac|wav|flac';

	public static function init(): void {
		$podcast = op3pa_get_podcast();

		if ( empty( $podcast['enabled'] ) ) {
			return;
		}

		// Buffer the entire feed output via template_redirect for maximum compatibility.
		add_action( 'template_redirect', [ __CLASS__, 'maybe_hook_feed' ], 1 );
	}

	/**
	 * Hook into the feed only when WordPress is about to serve a podcast feed.
	 */
	public static function maybe_hook_feed(): void {
		if ( ! is_feed() ) {
			return;
		}
		ob_start( [ __CLASS__, 'rewrite_feed' ] );
	}

	/**
	 * Output buffer callback: receives the full RSS XML and rewrites audio URLs.
	 *
	 * @param string $feed_xml Full RSS XML output.
	 * @return string Modified XML.
	 */
	public static function rewrite_feed( string $feed_xml ): string {
		$podcast = op3pa_get_podcast();
		if ( empty( $podcast['enabled'] ) ) {
			return $feed_xml;
		}

		$ext     = self::AUDIO_EXTENSIONS;
		$prefix  = self::OP3_PREFIX;

		// Optional: append podcast GUID parameter for faster OP3 attribution.
		$guid_param = '';
		if ( ! empty( $podcast['guid'] ) ) {
			$guid_param = '?_from=' . rawurlencode( $podcast['guid'] );
		}

		/**
		 * Match audio URLs in:
		 *   <enclosure url="https://..." />
		 *   <media:content url="https://..." />
		 *   <itunes:image href="..." /> — excluded intentionally (images, not audio)
		 *   Any other attribute containing an https?:// audio URL.
		 *
		 * The regex captures:
		 *   Group 1: everything up to and including the opening quote of the URL value
		 *   Group 2: https:// or http://
		 *   Group 3: the rest of the URL (host + path)
		 *   Group 4: file extension
		 *   Group 5: optional query string
		 *   Group 6: the closing quote
		 */
		$feed_xml = preg_replace_callback(
			'/(url=["\'])(https?:\/\/)([^\s"\']+?\.(' . $ext . ')(\?[^"\']*)?)(["\'"])/i',
			function ( array $m ) use ( $prefix, $guid_param ): string {
				// Avoid double-prefixing if OP3 is already present.
				if ( str_contains( $m[2] . $m[3], 'op3.dev/e/' ) ) {
					return $m[0];
				}

				$original_url = $m[2] . $m[3];  // https://host/path.mp3(?query)
				// Strip protocol: https://host/path → host/path
				$without_protocol = preg_replace( '#^https?://#', '', $original_url );

				return $m[1] . $prefix . $without_protocol . $guid_param . $m[6];
			},
			$feed_xml
		);

		return $feed_xml;
	}

	/**
	 * Given a plain audio URL, returns the OP3-prefixed version.
	 * Useful for testing or for display in the admin.
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

<?php
/**
 * Download spike/drop alerts, checked daily via cron, delivered by email to a
 * dedicated address (no fallback to the site admin email — must be configured
 * explicitly in OP3 Analytics → Alertas).
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Alerts {

	public const OPTION_EMAIL        = 'op3pa_alerts_email';
	public const OPTION_TYPES        = 'op3pa_alerts_types';
	public const OPTION_THRESHOLD    = 'op3pa_alerts_threshold';
	public const OPTION_MIN_BASELINE = 'op3pa_alerts_min_baseline';
	public const OPTION_PODCASTS     = 'op3pa_alerts_podcasts';
	public const OPTION_LOG          = 'op3pa_alerts_log';
	public const OPTION_LAST_CHECK   = 'op3pa_alerts_last_check';

	private const CRON_HOOK             = 'op3pa_alerts_check';
	private const DEFAULT_THRESHOLD     = 50;
	private const DEFAULT_MIN_BASELINE  = 5;
	private const MIN_EPISODE_AGE_DAYS  = 14;
	private const LOG_MAX_ENTRIES       = 50;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );

		if ( self::is_enabled() ) {
			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time(), 'daily', self::CRON_HOOK );
			}
		} else {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * @return bool Whether alerts are fully configured (email set, at least one type enabled).
	 */
	public static function is_enabled(): bool {
		return ! empty( self::get_email() ) && ! empty( self::get_enabled_types() );
	}

	/**
	 * @return string
	 */
	public static function get_email(): string {
		return (string) get_option( self::OPTION_EMAIL, '' );
	}

	/**
	 * @return array List of enabled types: 'spike', 'drop'.
	 */
	public static function get_enabled_types(): array {
		return (array) get_option( self::OPTION_TYPES, [] );
	}

	/**
	 * @return int Percentage change threshold (e.g. 50 = ±50%).
	 */
	public static function get_threshold(): int {
		$threshold = (int) get_option( self::OPTION_THRESHOLD, self::DEFAULT_THRESHOLD );
		return $threshold > 0 ? $threshold : self::DEFAULT_THRESHOLD;
	}

	/**
	 * @return int Minimum previous-week download count for an episode to be
	 *             considered at all — filters out noisy percentage swings on
	 *             tiny numbers (e.g. 1 → 2 downloads reads as "+100%").
	 */
	public static function get_min_baseline(): int {
		$min = (int) get_option( self::OPTION_MIN_BASELINE, self::DEFAULT_MIN_BASELINE );
		return $min > 0 ? $min : self::DEFAULT_MIN_BASELINE;
	}

	/**
	 * @return array Podcast indexes to monitor. Empty option (not yet saved) = all active podcasts.
	 */
	public static function get_monitored_podcasts(): array {
		$saved = get_option( self::OPTION_PODCASTS, null );
		if ( null === $saved ) {
			return array_keys( op3pa_get_active_all_podcasts() );
		}
		return array_map( 'absint', (array) $saved );
	}

	/**
	 * @return int|null Unix timestamp of the last check, or null if never run.
	 */
	public static function get_last_check(): ?int {
		$ts = get_option( self::OPTION_LAST_CHECK, 0 );
		return $ts ? (int) $ts : null;
	}

	/**
	 * @return array Most recent triggered alerts, newest first.
	 */
	public static function get_log(): array {
		return (array) get_option( self::OPTION_LOG, [] );
	}

	/**
	 * Runs the spike/drop check across all monitored podcasts and emails a
	 * single consolidated report if anything triggered. Safe to call manually
	 * (e.g. a "check now" button) as well as from cron.
	 *
	 * @return array Newly triggered alerts from this run.
	 */
	public static function run_check(): array {
		update_option( self::OPTION_LAST_CHECK, time() );

		if ( ! self::is_enabled() ) {
			return [];
		}

		$types      = self::get_enabled_types();
		$threshold  = self::get_threshold();
		$triggered  = [];

		foreach ( self::get_monitored_podcasts() as $i ) {
			$podcast = op3pa_get_podcast( $i );
			if ( empty( $podcast['name'] ) ) {
				continue;
			}

			$current  = self::get_episode_downloads( $i, 0 );
			$previous = self::get_episode_downloads( $i, 7 );
			if ( is_wp_error( $current ) || is_wp_error( $previous ) ) {
				continue;
			}
			$previous_by_url = array_column( $previous, 'downloads', 'episodeUrl' );

			foreach ( $current as $ep ) {
				$url = $ep['episodeUrl'] ?? '';
				if ( ! $url || ! self::is_episode_old_enough( $ep['episodePubdate'] ?? '' ) ) {
					continue;
				}
				$prev_count = $previous_by_url[ $url ] ?? 0;
				if ( $prev_count < self::get_min_baseline() ) {
					continue; // Avoid noisy percentage swings on tiny numbers (e.g. 1 → 2 reads as "+100%").
				}
				$curr_count = (int) ( $ep['downloads'] ?? 0 );
				$pct_change = round( ( $curr_count - $prev_count ) / $prev_count * 100 );

				if ( in_array( 'spike', $types, true ) && $pct_change >= $threshold ) {
					$triggered[] = self::build_alert( 'spike', $podcast['name'], $ep, $prev_count, $curr_count, $pct_change );
				} elseif ( in_array( 'drop', $types, true ) && $pct_change <= -$threshold ) {
					$triggered[] = self::build_alert( 'drop', $podcast['name'], $ep, $prev_count, $curr_count, $pct_change );
				}
			}
		}

		if ( ! empty( $triggered ) ) {
			self::send_email( $triggered );
			self::append_to_log( $triggered );
		}

		return $triggered;
	}

	/**
	 * @param string $type        'spike' or 'drop'.
	 * @param string $podcast_name
	 * @param array  $episode     Row with episodeTitle/episodeUrl.
	 * @param int    $prev_count
	 * @param int    $curr_count
	 * @param float  $pct_change
	 * @return array
	 */
	private static function build_alert( string $type, string $podcast_name, array $episode, int $prev_count, int $curr_count, float $pct_change ): array {
		return [
			'time'          => time(),
			'type'          => $type,
			'podcast'       => $podcast_name,
			'episode_title' => $episode['episodeTitle'] ?? '',
			'episode_url'   => $episode['episodeUrl'] ?? '',
			'prev_count'    => $prev_count,
			'curr_count'    => $curr_count,
			'pct_change'    => $pct_change,
		];
	}

	/**
	 * Returns per-episode download counts for a podcast over a trailing 7-day
	 * window, offset back by $offset_days (0 = last 7 days, 7 = the 7 days before that).
	 *
	 * @param int $podcast_i    Podcast index.
	 * @param int $offset_days  0 or 7.
	 * @return array|WP_Error List of episode rows.
	 */
	private static function get_episode_downloads( int $podcast_i, int $offset_days ): array|WP_Error {
		$end   = gmdate( 'Y-m-d', strtotime( "-{$offset_days} days" ) );
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $offset_days + 7 ) . ' days' ) );
		$period = [ 'start' => $start, 'end' => $end ];

		$podcast = op3pa_get_podcast( $podcast_i );
		$result  = ! empty( $podcast['private'] )
			? OP3PA_DB::get_download_counts( $period, $podcast_i )
			: OP3PA_Api::get_download_counts( $period, $podcast_i );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result['rows'] ?? [];
	}

	/**
	 * Episodes younger than MIN_EPISODE_AGE_DAYS are excluded: their natural
	 * launch-week download curve would otherwise look like a constant "spike"
	 * (high initial downloads) or "drop" (steep natural decay), neither of
	 * which is the kind of unusual movement this feature is meant to catch.
	 *
	 * @param string $pubdate Episode publish date.
	 * @return bool
	 */
	private static function is_episode_old_enough( string $pubdate ): bool {
		if ( ! $pubdate ) {
			return false;
		}
		$ts = strtotime( $pubdate );
		if ( ! $ts ) {
			return false;
		}
		return $ts <= ( time() - self::MIN_EPISODE_AGE_DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Sends one consolidated email listing all alerts triggered in this run.
	 *
	 * @param array $alerts
	 */
	private static function send_email( array $alerts ): void {
		$to = self::get_email();
		if ( ! is_email( $to ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Alertas de descargas de podcast', 'podcast-analytics-for-op3' ), $site_name );

		$lines = [];
		foreach ( $alerts as $alert ) {
			$emoji = 'spike' === $alert['type'] ? '📈' : '📉';
			$lines[] = sprintf(
				'%s %s — %s: %s%% (%d → %d descargas/semana). %s',
				$emoji,
				$alert['podcast'],
				$alert['episode_title'],
				( $alert['pct_change'] > 0 ? '+' : '' ) . $alert['pct_change'],
				$alert['prev_count'],
				$alert['curr_count'],
				$alert['episode_url']
			);
		}
		$body = implode( "\n\n", $lines );

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Prepends the newly triggered alerts to the stored log, capped to
	 * LOG_MAX_ENTRIES.
	 *
	 * @param array $alerts
	 */
	private static function append_to_log( array $alerts ): void {
		$log = array_merge( array_reverse( $alerts ), self::get_log() );
		$log = array_slice( $log, 0, self::LOG_MAX_ENTRIES );
		update_option( self::OPTION_LOG, $log );
	}
}

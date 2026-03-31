<?php
/**
 * Admin UI: settings page, statistics subpage, and dashboard widget.
 *
 * @package Podcast_Analytics_For_OP3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OP3PA_Admin {

	private const MENU_SLUG    = 'op3-podcast-analytics';
	private const NONCE_KEY    = 'op3pa_settings_nonce';
	private const NONCE_ACTION = 'op3pa_save_settings';

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup',    [ __CLASS__, 'register_dashboard_widget' ] );
		add_action( 'wp_ajax_op3pa_refresh_stats', [ __CLASS__, 'ajax_refresh_stats' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public static function register_menu(): void {
		add_menu_page(
			__( 'Podcast Analytics for OP3', 'podcast-analytics-for-op3' ),
			__( 'OP3 Analytics', 'podcast-analytics-for-op3' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_settings_page' ],
			'dashicons-chart-bar',
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'podcast-analytics-for-op3' ),
			__( 'Settings', 'podcast-analytics-for-op3' ),
			'manage_options',
			self::MENU_SLUG,
			[ __CLASS__, 'render_settings_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Statistics', 'podcast-analytics-for-op3' ),
			__( 'Statistics', 'podcast-analytics-for-op3' ),
			'manage_options',
			self::MENU_SLUG . '-stats',
			[ __CLASS__, 'render_stats_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_assets( string $hook ): void {
		$pages = [
			'toplevel_page_' . self::MENU_SLUG,
			'op3-analytics_page_' . self::MENU_SLUG . '-stats',
			'dashboard',
		];

		if ( ! in_array( $hook, $pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'op3pa-admin',
			OP3PA_URL . 'admin/css/admin.css',
			[],
			OP3PA_VERSION
		);

		wp_enqueue_script(
			'op3pa-admin',
			OP3PA_URL . 'admin/js/admin.js',
			[ 'jquery' ],
			OP3PA_VERSION,
			true
		);

		wp_localize_script( 'op3pa-admin', 'op3paData', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'op3pa_ajax' ),
			'strings' => [
				'refreshing' => __( 'Refreshing…', 'podcast-analytics-for-op3' ),
				'error'      => __( 'Could not load stats. Try again later.', 'podcast-analytics-for-op3' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public static function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_KEY ] ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_KEY );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'podcast-analytics-for-op3' ) );
		}

		$data = [
			'enabled'   => isset( $_POST['op3pa_enabled'] ),
			'api_key'   => sanitize_text_field( wp_unslash( $_POST['op3pa_api_key']   ?? '' ) ),
			'guid'      => sanitize_text_field( wp_unslash( $_POST['op3pa_guid']      ?? '' ) ),
			'show_uuid' => sanitize_text_field( wp_unslash( $_POST['op3pa_show_uuid'] ?? '' ) ),
		];

		op3pa_save_podcast( $data );
		OP3PA_Api::clear_cache();

		add_action( 'admin_notices', [ __CLASS__, 'notice_saved' ] );
	}

	public static function notice_saved(): void {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'OP3 settings saved.', 'podcast-analytics-for-op3' )
			. '</p></div>';
	}

	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$podcast  = op3pa_get_podcast();
		$feed_url = get_feed_link( 'podcast' ) ?: get_feed_link();
		?>
		<div class="wrap op3pa-wrap">
			<h1>
				<span class="op3pa-logo">OP3</span>
				<?php esc_html_e( 'Podcast Analytics — Settings', 'podcast-analytics-for-op3' ); ?>
			</h1>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_KEY ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable OP3 prefix', 'podcast-analytics-for-op3' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="op3pa_enabled" value="1"
									<?php checked( $podcast['enabled'] ); ?> />
								<?php esc_html_e( 'Add the OP3 prefix to all audio URLs in the RSS feed', 'podcast-analytics-for-op3' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, audio enclosures in your feed will be rewritten from https://yoursite.com/audio.mp3 to https://op3.dev/e/yoursite.com/audio.mp3.', 'podcast-analytics-for-op3' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="op3pa_api_key"><?php esc_html_e( 'OP3 Bearer Token', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="password" id="op3pa_api_key" name="op3pa_api_key"
								value="<?php echo esc_attr( $podcast['api_key'] ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to op3.dev */
									esc_html__( 'Get your bearer token at %s (different from the API Key — use the token shown after clicking «Regenerate token»).', 'podcast-analytics-for-op3' ),
									'<a href="https://op3.dev/api/keys" target="_blank" rel="noopener">op3.dev/api/keys</a>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="op3pa_show_uuid"><?php esc_html_e( 'Show UUID', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="text" id="op3pa_show_uuid" name="op3pa_show_uuid"
								value="<?php echo esc_attr( $podcast['show_uuid'] ); ?>"
								class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
							<p class="description">
								<?php esc_html_e( 'The UUID that OP3 assigns to your show. You can find it in your public stats URL: https://op3.dev/show/{uuid}.', 'podcast-analytics-for-op3' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="op3pa_guid"><?php esc_html_e( 'Podcast GUID (optional)', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="text" id="op3pa_guid" name="op3pa_guid"
								value="<?php echo esc_attr( $podcast['guid'] ); ?>"
								class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
							<p class="description">
								<?php esc_html_e( 'The <podcast:guid> of your show (from your RSS feed). Including it speeds up attribution in OP3 — useful but not required.', 'podcast-analytics-for-op3' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'podcast-analytics-for-op3' ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Feed Check', 'podcast-analytics-for-op3' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: feed URL */
					esc_html__( 'Your feed URL: %s', 'podcast-analytics-for-op3' ),
					'<a href="' . esc_url( $feed_url ) . '" target="_blank" rel="noopener">' . esc_html( $feed_url ) . '</a>'
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Open your feed after saving and verify that audio URLs start with https://op3.dev/e/.', 'podcast-analytics-for-op3' ); ?>
			</p>

			<?php if ( ! empty( $podcast['show_uuid'] ) ) : ?>
			<p>
				<a href="<?php echo esc_url( OP3PA_Api::get_stats_page_url() ); ?>" target="_blank" rel="noopener" class="button button-secondary">
					<?php esc_html_e( '↗ View public stats page on OP3', 'podcast-analytics-for-op3' ); ?>
				</a>
			</p>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Statistics page
	// -------------------------------------------------------------------------

	public static function render_stats_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$podcast = op3pa_get_podcast();
		?>
		<div class="wrap op3pa-wrap">
			<h1>
				<span class="op3pa-logo">OP3</span>
				<?php esc_html_e( 'Podcast Analytics — Statistics', 'podcast-analytics-for-op3' ); ?>
			</h1>

			<?php if ( empty( $podcast['show_uuid'] ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: link to settings page */
							esc_html__( 'Please configure your Show UUID in the %s first.', 'podcast-analytics-for-op3' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
							. esc_html__( 'Settings page', 'podcast-analytics-for-op3' ) . '</a>'
						);
						?>
					</p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<div class="op3pa-stats-header">
				<div class="op3pa-period-tabs">
					<button class="op3pa-period-btn active" data-days="30">
						<?php esc_html_e( 'Last 30 days', 'podcast-analytics-for-op3' ); ?>
					</button>
					<button class="op3pa-period-btn" data-days="7">
						<?php esc_html_e( 'Last 7 days', 'podcast-analytics-for-op3' ); ?>
					</button>
					<button class="op3pa-period-btn" data-days="1">
						<?php esc_html_e( 'Last 24h', 'podcast-analytics-for-op3' ); ?>
					</button>
				</div>
				<button id="op3pa-refresh" class="button button-secondary">
					⟳ <?php esc_html_e( 'Refresh', 'podcast-analytics-for-op3' ); ?>
				</button>
				<a href="<?php echo esc_url( OP3PA_Api::get_stats_page_url() ); ?>"
					target="_blank" rel="noopener" class="button button-secondary">
					↗ <?php esc_html_e( 'Full stats on OP3', 'podcast-analytics-for-op3' ); ?>
				</a>
			</div>

			<div id="op3pa-stats-container" data-days="30">
				<div class="op3pa-loading"><?php esc_html_e( 'Loading stats…', 'podcast-analytics-for-op3' ); ?></div>
			</div>
		</div>
		<?php
		self::render_stats_table( 30 );
	}

	/**
	 * Renders the stats table.
	 *
	 * @param int $days Period in days.
	 */
	private static function render_stats_table( int $days ): void {
		$result = OP3PA_Api::get_download_counts( $days );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p>'
				. esc_html( $result->get_error_message() )
				. '</p></div>';
			return;
		}

		$rows  = $result['rows'] ?? [];
		$total = 0;
		?>
		<div id="op3pa-stats-table-wrap">
			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No download data available yet.', 'podcast-analytics-for-op3' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped op3pa-table">
					<thead>
						<tr>
							<th class="column-episode"><?php esc_html_e( 'Episode', 'podcast-analytics-for-op3' ); ?></th>
							<th class="column-downloads"><?php esc_html_e( 'Downloads', 'podcast-analytics-for-op3' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) :
							$count    = isset( $row['downloads'] ) ? (int) $row['downloads'] : 0;
							$total   += $count;
							$ep_title = $row['episodeTitle'] ?? $row['episodeUrl'] ?? __( '(unknown)', 'podcast-analytics-for-op3' );
							$ep_url   = $row['episodeUrl'] ?? '';
						?>
							<tr>
								<td class="column-episode">
									<?php if ( $ep_url ) : ?>
										<a href="<?php echo esc_url( $ep_url ); ?>" target="_blank" rel="noopener">
											<?php echo esc_html( $ep_title ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $ep_title ); ?>
									<?php endif; ?>
								</td>
								<td class="column-downloads">
									<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
									<div class="op3pa-bar" style="width:<?php echo esc_attr( self::bar_width( $count, $rows ) ); ?>%"></div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Total', 'podcast-analytics-for-op3' ); ?></th>
							<th><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></th>
						</tr>
					</tfoot>
				</table>
				<p class="op3pa-cache-note">
					<?php
					printf(
						/* translators: %s: link to full OP3 stats */
						esc_html__( 'Data cached for 1 hour. %s', 'podcast-analytics-for-op3' ),
						'<a href="' . esc_url( OP3PA_Api::get_stats_page_url() ) . '" target="_blank" rel="noopener">'
						. esc_html__( 'View detailed breakdown on OP3 →', 'podcast-analytics-for-op3' )
						. '</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Returns bar width percentage relative to the episode with most downloads.
	 *
	 * @param int   $count Current episode downloads.
	 * @param array $rows  All rows.
	 * @return int
	 */
	private static function bar_width( int $count, array $rows ): int {
		$max = max( array_column( $rows, 'downloads' ) ?: [ 1 ] );
		return $max > 0 ? (int) round( ( $count / $max ) * 100 ) : 0;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public static function ajax_refresh_stats(): void {
		check_ajax_referer( 'op3pa_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'podcast-analytics-for-op3' ) ], 403 );
		}

		$days = absint( $_POST['days'] ?? 30 );
		if ( ! in_array( $days, [ 1, 7, 30 ], true ) ) {
			$days = 30;
		}

		OP3PA_Api::clear_cache();

		ob_start();
		self::render_stats_table( $days );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	// -------------------------------------------------------------------------
	// Dashboard widget
	// -------------------------------------------------------------------------

	public static function register_dashboard_widget(): void {
		$podcast = op3pa_get_podcast();
		if ( empty( $podcast['enabled'] ) && empty( $podcast['show_uuid'] ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'op3pa_dashboard_widget',
			__( 'OP3 Podcast Downloads', 'podcast-analytics-for-op3' ),
			[ __CLASS__, 'render_dashboard_widget' ]
		);
	}

	public static function render_dashboard_widget(): void {
		$podcast = op3pa_get_podcast();

		if ( empty( $podcast['show_uuid'] ) ) {
			echo '<p>';
			printf(
				/* translators: %s: settings link */
				esc_html__( 'Configure your Show UUID in the %s.', 'podcast-analytics-for-op3' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
				. esc_html__( 'OP3 settings', 'podcast-analytics-for-op3' ) . '</a>'
			);
			echo '</p>';
			return;
		}

		$result = OP3PA_Api::get_download_counts( 7 );

		if ( is_wp_error( $result ) ) {
			echo '<p class="op3pa-error">' . esc_html( $result->get_error_message() ) . '</p>';
			return;
		}

		$rows  = array_slice( $result['rows'] ?? [], 0, 5 );
		$total = array_sum( array_column( $result['rows'] ?? [], 'downloads' ) );
		?>
		<div class="op3pa-widget">
			<div class="op3pa-widget-total">
				<span class="op3pa-widget-number"><?php echo esc_html( number_format_i18n( (int) $total ) ); ?></span>
				<span class="op3pa-widget-label"><?php esc_html_e( 'downloads in the last 7 days', 'podcast-analytics-for-op3' ); ?></span>
			</div>

			<?php if ( ! empty( $rows ) ) : ?>
			<table class="op3pa-widget-table">
				<tbody>
					<?php foreach ( $rows as $row ) :
						$count = (int) ( $row['downloads'] ?? 0 );
						$title = $row['episodeTitle'] ?? $row['episodeUrl'] ?? '—';
					?>
					<tr>
						<td class="op3pa-wt-title"><?php echo esc_html( $title ); ?></td>
						<td class="op3pa-wt-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<p class="op3pa-widget-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-stats' ) ); ?>">
					<?php esc_html_e( 'View all stats →', 'podcast-analytics-for-op3' ); ?>
				</a>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( OP3PA_Api::get_stats_page_url() ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'OP3 dashboard ↗', 'podcast-analytics-for-op3' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

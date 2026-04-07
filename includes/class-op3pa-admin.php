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
		add_action( 'wp_ajax_op3pa_refresh_stats',   [ __CLASS__, 'ajax_refresh_stats' ] );
		add_action( 'wp_ajax_op3pa_refresh_network', [ __CLASS__, 'ajax_refresh_network' ] );
		add_action( 'admin_notices',         [ __CLASS__, 'maybe_show_migration_notice' ] );
	}

	/**
	 * Shows a notice if the bearer token is missing after a migration from v1.x.
	 */
	public static function maybe_show_migration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! get_option( 'op3pa_migrated_v2' ) ) {
			return;
		}
		if ( ! empty( op3pa_get_token() ) ) {
			return;
		}
		// Token is missing — show a gentle notice.
		$settings_url = admin_url( 'admin.php?page=op3-podcast-analytics' );
		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			/* translators: %s: settings page link */
			esc_html__( 'Podcast Analytics for OP3 has been updated to v2.0. Your podcast settings have been migrated automatically, but please verify your Bearer Token in the %s.', 'podcast-analytics-for-op3' ),
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'OP3 Settings page', 'podcast-analytics-for-op3' ) . '</a>'
		);
		echo '</p></div>';
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

		$active   = op3pa_get_active_podcasts();
		$podcasts = [];
		foreach ( $active as $i => $p ) {
			$podcasts[] = [
				'index' => $i,
				'name'  => $p['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				),
				'color' => ! empty( $p['color'] ) ? $p['color'] : '#0066cc',
			];
		}

		wp_localize_script( 'op3pa-admin', 'op3paData', [
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'op3pa_ajax' ),
			'podcasts' => $podcasts,
			'strings'  => [
				'refreshing' => __( 'Refreshing…', 'podcast-analytics-for-op3' ),
				'error'      => __( 'Could not load stats. Try again later.', 'podcast-analytics-for-op3' ),
				'network'    => __( 'Network total', 'podcast-analytics-for-op3' ),
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

		// Save global bearer token.
		update_option(
			OP3PA_OPTION_TOKEN,
			sanitize_text_field( wp_unslash( $_POST['op3pa_token'] ?? '' ) )
		);

		// Save podcasts list.
		$raw_podcasts = $_POST['op3pa_podcasts'] ?? [];
		$podcasts     = [];

		if ( is_array( $raw_podcasts ) ) {
			foreach ( $raw_podcasts as $raw ) {
				if ( empty( $raw['name'] ) && empty( $raw['show_uuid'] ) ) {
					continue; // Skip completely empty rows.
				}
				$podcasts[] = [
					'name'      => sanitize_text_field( wp_unslash( $raw['name']      ?? '' ) ),
					'show_uuid' => sanitize_text_field( wp_unslash( $raw['show_uuid'] ?? '' ) ),
					'guid'      => sanitize_text_field( wp_unslash( $raw['guid']      ?? '' ) ),
					'color'     => sanitize_hex_color( $raw['color'] ?? '' ) ?: '#0066cc',
					'private'   => ! empty( $raw['private'] ),
					'enabled'   => true,
				];
			}
		}

		update_option( OP3PA_OPTION, $podcasts );
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

		$token    = op3pa_get_token();
		$podcasts = op3pa_get_podcasts();

		// Ensure at least one empty row for new installs.
		if ( empty( $podcasts ) ) {
			$podcasts = [ [ 'name' => '', 'show_uuid' => '', 'guid' => '', 'private' => false, 'enabled' => true ] ];
		}
		?>
		<div class="wrap op3pa-wrap">
			<h1>
				<span class="op3pa-logo">OP3</span>
				<?php esc_html_e( 'Podcast Analytics — Settings', 'podcast-analytics-for-op3' ); ?>
			</h1>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_KEY ); ?>

				<h2><?php esc_html_e( 'Ajustes globales', 'podcast-analytics-for-op3' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="op3pa_token"><?php esc_html_e( 'Bearer token de OP3', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="password" id="op3pa_token" name="op3pa_token"
								value="<?php echo esc_attr( $token ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to op3.dev */
									esc_html__( 'Obtén tu bearer token en %s. Importante: el bearer token NO es la API Key — para verlo haz clic en «Regenerate token» y cópialo desde ahí. Un mismo token sirve para todos tus podcasts.', 'podcast-analytics-for-op3' ),
									'<a href="https://op3.dev/api/keys" target="_blank" rel="noopener">op3.dev/api/keys</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Podcasts', 'podcast-analytics-for-op3' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Añade una fila por cada podcast. El Show UUID lo encontrarás en la URL de tu página de estadísticas en OP3 (https://op3.dev/show/{uuid}). El Podcast GUID es opcional y acelera la atribución. Los podcasts marcados como privados no tendrán el prefijo OP3 y no aparecerán en las estadísticas.', 'podcast-analytics-for-op3' ); ?>
				</p>

				<table class="wp-list-table widefat fixed op3pa-podcasts-table" id="op3pa-podcasts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Show UUID', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Podcast GUID (opcional)', 'podcast-analytics-for-op3' ); ?></th>
							<th class="col-color"><?php esc_html_e( 'Color', 'podcast-analytics-for-op3' ); ?></th>
							<th class="col-private"><?php esc_html_e( 'Privado', 'podcast-analytics-for-op3' ); ?></th>
							<th class="col-remove"></th>
						</tr>
					</thead>
					<tbody id="op3pa-podcasts-body">
						<?php foreach ( $podcasts as $i => $podcast ) : ?>
							<?php self::render_podcast_row( $i, $podcast ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" id="op3pa-add-podcast" class="button button-secondary">
						+ <?php esc_html_e( 'Añadir podcast', 'podcast-analytics-for-op3' ); ?>
					</button>
				</p>

				<?php submit_button( __( 'Save Settings', 'podcast-analytics-for-op3' ) ); ?>
			</form>
		</div>

		<script>
		/* Template row for JS cloning */
		document.getElementById('op3pa-add-podcast').addEventListener('click', function() {
			var tbody = document.getElementById('op3pa-podcasts-body');
			var index = tbody.querySelectorAll('tr').length;
			var tpl   = <?php echo wp_json_encode( self::get_row_template() ); ?>;
			var html  = tpl.replace(/__INDEX__/g, index);
			tbody.insertAdjacentHTML('beforeend', html);
		});
		document.getElementById('op3pa-podcasts-body').addEventListener('click', function(e) {
			if ( e.target.classList.contains('op3pa-remove-row') ) {
				e.target.closest('tr').remove();
			}
		});
		</script>
		<?php
	}

	/**
	 * Renders a single podcast row in the settings table.
	 *
	 * @param int   $i       Row index.
	 * @param array $podcast Podcast data.
	 */
	private static function render_podcast_row( int $i, array $podcast ): void {
		$color = ! empty( $podcast['color'] ) ? $podcast['color'] : '#0066cc';
		?>
		<tr class="op3pa-podcast-row">
			<td>
				<input type="text" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][name]"
					value="<?php echo esc_attr( $podcast['name'] ?? '' ); ?>"
					class="regular-text" placeholder="<?php esc_attr_e( 'Mi Podcast', 'podcast-analytics-for-op3' ); ?>" />
			</td>
			<td>
				<input type="text" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][show_uuid]"
					value="<?php echo esc_attr( $podcast['show_uuid'] ?? '' ); ?>"
					class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
			</td>
			<td>
				<input type="text" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][guid]"
					value="<?php echo esc_attr( $podcast['guid'] ?? '' ); ?>"
					class="regular-text" placeholder="<?php esc_attr_e( 'opcional', 'podcast-analytics-for-op3' ); ?>" />
			</td>
			<td class="col-color">
				<input type="color" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][color]"
					value="<?php echo esc_attr( $color ); ?>" />
			</td>
			<td class="col-private">
				<input type="checkbox" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][private]" value="1"
					<?php checked( ! empty( $podcast['private'] ) ); ?> />
			</td>
			<td class="col-remove">
				<button type="button" class="button-link op3pa-remove-row" aria-label="<?php esc_attr_e( 'Eliminar', 'podcast-analytics-for-op3' ); ?>">✕</button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Returns an HTML template string for a new podcast row (uses __INDEX__ placeholder).
	 *
	 * @return string
	 */
	private static function get_row_template(): string {
		ob_start();
		?>
		<tr class="op3pa-podcast-row">
			<td><input type="text" name="op3pa_podcasts[__INDEX__][name]" class="regular-text" placeholder="<?php esc_attr_e( 'Mi Podcast', 'podcast-analytics-for-op3' ); ?>" /></td>
			<td><input type="text" name="op3pa_podcasts[__INDEX__][show_uuid]" class="regular-text" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" /></td>
			<td><input type="text" name="op3pa_podcasts[__INDEX__][guid]" class="regular-text" placeholder="<?php esc_attr_e( 'opcional', 'podcast-analytics-for-op3' ); ?>" /></td>
			<td class="col-color"><input type="color" name="op3pa_podcasts[__INDEX__][color]" value="#0066cc" /></td>
			<td class="col-private"><input type="checkbox" name="op3pa_podcasts[__INDEX__][private]" value="1" /></td>
			<td class="col-remove"><button type="button" class="button-link op3pa-remove-row" aria-label="<?php esc_attr_e( 'Eliminar', 'podcast-analytics-for-op3' ); ?>">✕</button></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Statistics page
	// -------------------------------------------------------------------------

	public static function render_stats_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active = op3pa_get_active_podcasts();
		?>
		<div class="wrap op3pa-wrap" id="op3pa-stats-wrap">
			<h1>
				<span class="op3pa-logo">OP3</span>
				<?php esc_html_e( 'Podcast Analytics — Statistics', 'podcast-analytics-for-op3' ); ?>
			</h1>

			<?php if ( empty( $active ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %s: settings page link */
							esc_html__( 'No active podcasts configured. Go to the %s to add your podcasts.', 'podcast-analytics-for-op3' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
							. esc_html__( 'Settings page', 'podcast-analytics-for-op3' ) . '</a>'
						);
						?>
					</p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<!-- Controls -->
			<div class="op3pa-stats-header no-print">
				<!-- Podcast selector -->
				<div class="op3pa-podcast-selector">
					<?php if ( count( $active ) > 1 ) : ?>
					<label class="op3pa-selector-item">
						<input type="checkbox" class="op3pa-podcast-check" value="network" checked />
						<strong><?php esc_html_e( 'All (network)', 'podcast-analytics-for-op3' ); ?></strong>
					</label>
					<?php endif; ?>
					<?php foreach ( $active as $i => $p ) : ?>
					<label class="op3pa-selector-item">
						<input type="checkbox" class="op3pa-podcast-check" value="<?php echo esc_attr( $i ); ?>" checked />
						<?php echo esc_html( $p['name'] ?: sprintf( __( 'Podcast %d', 'podcast-analytics-for-op3' ), $i + 1 ) ); ?>
					</label>
					<?php endforeach; ?>
				</div>

				<!-- Period -->
				<div class="op3pa-period-tabs">
					<button class="op3pa-period-btn active" data-days="30"><?php esc_html_e( 'Last 30 days', 'podcast-analytics-for-op3' ); ?></button>
					<button class="op3pa-period-btn" data-days="7"><?php esc_html_e( 'Last 7 days', 'podcast-analytics-for-op3' ); ?></button>
					<button class="op3pa-period-btn" data-days="1"><?php esc_html_e( 'Last 24h', 'podcast-analytics-for-op3' ); ?></button>
				</div>

				<button id="op3pa-refresh" class="button button-secondary">
					⟳ <?php esc_html_e( 'Refresh', 'podcast-analytics-for-op3' ); ?>
				</button>
				<button onclick="window.print()" class="button button-secondary">
					⎙ <?php esc_html_e( 'Print / PDF', 'podcast-analytics-for-op3' ); ?>
				</button>
			</div>

			<div id="op3pa-stats-container">
				<?php
				// Render initial view inside the container so AJAX replaces it correctly.
				if ( count( $active ) > 1 ) {
					self::render_network_table( 30 );
				} else {
					$first_index = array_key_first( $active );
					self::render_single_table( 30, $first_index );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the network (multi-podcast aggregate) stats table.
	 *
	 * @param int   $days    Period in days.
	 * @param array $indexes Podcast indexes to include. Empty = all.
	 */
	private static function render_network_table( int $days, array $indexes = [] ): void {
		$result = OP3PA_Api::get_network_counts( $days, $indexes );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}

		$rows  = $result['rows']  ?? [];
		$total = $result['total'] ?? 0;
		?>
		<div id="op3pa-stats-table-wrap">

			<!-- Header: total + all podcast links -->
			<div class="op3pa-network-header">
				<div class="op3pa-network-total">
					<span class="op3pa-net-number"><?php echo esc_html( number_format_i18n( (int) $total ) ); ?></span>
					<span class="op3pa-net-label"><?php esc_html_e( 'descargas totales', 'podcast-analytics-for-op3' ); ?></span>
				</div>
				<div class="op3pa-network-links no-print">
					<?php foreach ( $rows as $row ) : ?>
					<a href="<?php echo esc_url( OP3PA_Api::get_stats_page_url( (int) $row['index'] ) ); ?>" target="_blank" rel="noopener" class="op3pa-network-link">
						<?php echo esc_html( $row['name'] ); ?> ↗OP3
					</a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No download data available yet.', 'podcast-analytics-for-op3' ); ?></p>
			<?php else : ?>

				<!-- Network ranking summary -->
				<table class="wp-list-table widefat fixed striped op3pa-table op3pa-network-table">
					<thead>
						<tr>
							<th class="column-podcast"><?php esc_html_e( 'Podcast', 'podcast-analytics-for-op3' ); ?></th>
							<th class="column-downloads"><?php esc_html_e( 'Descargas', 'podcast-analytics-for-op3' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td class="column-podcast"><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
							<td class="column-downloads">
								<strong><?php echo esc_html( number_format_i18n( (int) $row['downloads'] ) ); ?></strong>
								<div class="op3pa-bar" style="width:<?php echo esc_attr( self::bar_pct( (int) $row['downloads'], $total ) ); ?>%"></div>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php esc_html_e( 'Total', 'podcast-analytics-for-op3' ); ?></th>
							<th><strong><?php echo esc_html( number_format_i18n( (int) $total ) ); ?></strong></th>
						</tr>
					</tfoot>
				</table>

				<!-- Mixed episode table: all podcasts together, sorted by downloads -->
				<?php
				// Merge all episodes from all shows into one list with podcast name attached.
				$all_episodes = [];
				foreach ( $rows as $row ) {
					foreach ( $row['episodes'] as $ep ) {
						$all_episodes[] = array_merge( $ep, [
							'podcast_name'  => $row['name'],
							'podcast_color' => $row['color'] ?? '#0066cc',
						] );
					}
				}
				usort( $all_episodes, fn( $a, $b ) => $b['downloads'] <=> $a['downloads'] );
				$ep_total = array_sum( array_column( $all_episodes, 'downloads' ) );
				$ep_max   = max( array_column( $all_episodes, 'downloads' ) ?: [ 1 ] );
				?>
				<h3 class="op3pa-show-heading"><?php esc_html_e( 'Episodios', 'podcast-analytics-for-op3' ); ?></h3>
				<table class="wp-list-table widefat fixed striped op3pa-table">
					<thead>
						<tr>
							<th class="column-podcast-tag"><?php esc_html_e( 'Podcast', 'podcast-analytics-for-op3' ); ?></th>
							<th class="column-episode"><?php esc_html_e( 'Episodio', 'podcast-analytics-for-op3' ); ?></th>
							<th class="column-pubdate"><?php esc_html_e( 'Fecha', 'podcast-analytics-for-op3' ); ?></th>
							<th class="column-downloads"><?php esc_html_e( 'Descargas', 'podcast-analytics-for-op3' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_episodes as $ep ) :
							$count    = (int) ( $ep['downloads'] ?? 0 );
							$ep_title = $ep['episodeTitle'] ?? $ep['episodeUrl'] ?? __( '(unknown)', 'podcast-analytics-for-op3' );
							$ep_url   = $ep['episodeUrl'] ?? '';
							$pubdate  = $ep['episodePubdate'] ?? '';
							$color    = $ep['podcast_color'] ?? '#0066cc';
							if ( $pubdate ) {
								$ts      = strtotime( $pubdate );
								$pubdate = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $pubdate;
							}
							// Compute readable text color (white or dark) based on background luminance.
							list( $r, $g, $b ) = sscanf( $color, '#%02x%02x%02x' );
							$luminance  = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
							$text_color = $luminance > 0.5 ? '#1d2327' : '#ffffff';
						?>
						<tr>
							<td class="column-podcast-tag">
								<span class="op3pa-podcast-tag" style="background:<?php echo esc_attr( $color ); ?>;color:<?php echo esc_attr( $text_color ); ?>"><?php echo esc_html( $ep['podcast_name'] ); ?></span>
							</td>
							<td class="column-episode">
								<?php if ( $ep_url ) : ?>
									<a href="<?php echo esc_url( $ep_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ep_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $ep_title ); ?>
								<?php endif; ?>
							</td>
							<td class="column-pubdate"><?php echo esc_html( $pubdate ); ?></td>
							<td class="column-downloads">
								<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
								<div class="op3pa-bar" style="width:<?php echo esc_attr( $ep_max > 0 ? (int) round( $count / $ep_max * 100 ) : 0 ); ?>%"></div>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th></th>
							<th><?php esc_html_e( 'Total', 'podcast-analytics-for-op3' ); ?></th>
							<th></th>
							<th><strong><?php echo esc_html( number_format_i18n( $ep_total ) ); ?></strong></th>
						</tr>
					</tfoot>
				</table>

				<p class="op3pa-cache-note no-print">
					<?php esc_html_e( 'Datos en caché durante 1 hora.', 'podcast-analytics-for-op3' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders a single podcast stats table.
	 *
	 * @param int $days      Period in days.
	 * @param int $podcast_i Podcast index.
	 */
	private static function render_single_table( int $days, int $podcast_i ): void {
		$result = OP3PA_Api::get_download_counts( $days, $podcast_i );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}

		$rows  = $result['rows'] ?? [];
		$total = array_sum( array_column( $rows, 'downloads' ) );
		$podcast = op3pa_get_podcast( $podcast_i );
		?>
		<div id="op3pa-stats-table-wrap">
			<?php if ( ! empty( $podcast['name'] ) ) : ?>
				<h3 class="op3pa-show-heading">
					<?php echo esc_html( $podcast['name'] ); ?>
					<?php if ( ! empty( $podcast['show_uuid'] ) ) : ?>
					<a class="op3pa-show-op3-link no-print" href="<?php echo esc_url( OP3PA_Api::get_stats_page_url( $podcast_i ) ); ?>" target="_blank" rel="noopener">↗ OP3</a>
					<?php endif; ?>
				</h3>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<p><?php esc_html_e( 'No download data available yet.', 'podcast-analytics-for-op3' ); ?></p>
			<?php else : ?>
				<?php self::render_episodes_table( $rows, $total ); ?>
				<p class="op3pa-cache-note no-print">
					<?php
					printf(
						/* translators: %s: link to OP3 */
						esc_html__( 'Data cached for 1 hour. %s', 'podcast-analytics-for-op3' ),
						'<a href="' . esc_url( OP3PA_Api::get_stats_page_url( $podcast_i ) ) . '" target="_blank" rel="noopener">'
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
	 * Renders the episodes table (shared by single and network views).
	 *
	 * @param array $rows  Episode rows.
	 * @param int   $total Total downloads for bar scaling.
	 */
	private static function render_episodes_table( array $rows, int $total ): void {
		?>
		<table class="wp-list-table widefat fixed striped op3pa-table">
			<thead>
				<tr>
					<th class="column-episode"><?php esc_html_e( 'Episodio', 'podcast-analytics-for-op3' ); ?></th>
					<th class="column-pubdate"><?php esc_html_e( 'Fecha', 'podcast-analytics-for-op3' ); ?></th>
					<th class="column-downloads"><?php esc_html_e( 'Descargas', 'podcast-analytics-for-op3' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$max = max( array_column( $rows, 'downloads' ) ?: [ 1 ] );
				foreach ( $rows as $row ) :
					$count    = (int) ( $row['downloads'] ?? 0 );
					$ep_title = $row['episodeTitle'] ?? $row['episodeUrl'] ?? __( '(unknown)', 'podcast-analytics-for-op3' );
					$ep_url   = $row['episodeUrl'] ?? '';
					$pubdate  = $row['episodePubdate'] ?? '';
					// Format date to locale.
					if ( $pubdate ) {
						$ts      = strtotime( $pubdate );
						$pubdate = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $pubdate;
					}
				?>
				<tr>
					<td class="column-episode">
						<?php if ( $ep_url ) : ?>
							<a href="<?php echo esc_url( $ep_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $ep_title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $ep_title ); ?>
						<?php endif; ?>
					</td>
					<td class="column-pubdate"><?php echo esc_html( $pubdate ); ?></td>
					<td class="column-downloads">
						<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
						<div class="op3pa-bar" style="width:<?php echo esc_attr( $max > 0 ? (int) round( $count / $max * 100 ) : 0 ); ?>%"></div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th><?php esc_html_e( 'Total', 'podcast-analytics-for-op3' ); ?></th>
					<th></th>
					<th><strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong></th>
				</tr>
			</tfoot>
		</table>
		<?php
	}

	/** Percentage of total, for bar width. */
	private static function bar_pct( int $value, int $total ): int {
		return $total > 0 ? (int) round( $value / $total * 100 ) : 0;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public static function ajax_refresh_stats(): void {
		check_ajax_referer( 'op3pa_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'podcast-analytics-for-op3' ) ], 403 );
		}

		$days      = absint( $_POST['days'] ?? 30 );
		$indexes   = array_map( 'absint', (array) ( $_POST['indexes'] ?? [] ) );
		$is_network = in_array( 'network', (array) ( $_POST['indexes'] ?? [] ), true );

		if ( ! in_array( $days, [ 1, 7, 30 ], true ) ) {
			$days = 30;
		}

		OP3PA_Api::clear_cache();

		ob_start();
		if ( $is_network || count( $indexes ) > 1 ) {
			self::render_network_table( $days, $is_network ? [] : $indexes );
		} elseif ( ! empty( $indexes ) ) {
			self::render_single_table( $days, $indexes[0] );
		} else {
			self::render_network_table( $days );
		}
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	public static function ajax_refresh_network(): void {
		self::ajax_refresh_stats();
	}

	// -------------------------------------------------------------------------
	// Dashboard widget
	// -------------------------------------------------------------------------

	public static function register_dashboard_widget(): void {
		$active = op3pa_get_active_podcasts();
		if ( empty( $active ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'op3pa_dashboard_widget',
			__( 'OP3 Podcast Downloads', 'podcast-analytics-for-op3' ),
			[ __CLASS__, 'render_dashboard_widget' ]
		);
	}

	public static function render_dashboard_widget(): void {
		$active = op3pa_get_active_podcasts();

		if ( empty( $active ) ) {
			printf(
				'<p>%s</p>',
				sprintf(
					/* translators: %s: settings link */
					esc_html__( 'Configure your podcasts in the %s.', 'podcast-analytics-for-op3' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">'
					. esc_html__( 'OP3 settings', 'podcast-analytics-for-op3' ) . '</a>'
				)
			);
			return;
		}

		$result = OP3PA_Api::get_network_counts( 7 );
		if ( is_wp_error( $result ) ) {
			echo '<p class="op3pa-error">' . esc_html( $result->get_error_message() ) . '</p>';
			return;
		}

		$rows  = $result['rows']  ?? [];
		$total = $result['total'] ?? 0;

		// Widget: if multiple podcasts, show network summary + per-podcast totals.
		// If single podcast, show episode list.
		$is_multi = count( $active ) > 1;
		?>
		<div class="op3pa-widget">
			<div class="op3pa-widget-total">
				<span class="op3pa-widget-number"><?php echo esc_html( number_format_i18n( (int) $total ) ); ?></span>
				<span class="op3pa-widget-label"><?php esc_html_e( 'downloads in the last 7 days', 'podcast-analytics-for-op3' ); ?></span>
			</div>

			<?php if ( $is_multi ) : ?>
				<!-- Multi-podcast: show per-show totals with pagination -->
				<div class="op3pa-widget-slides" data-current="0">
					<?php foreach ( $rows as $idx => $row ) : ?>
					<div class="op3pa-widget-slide" <?php echo $idx > 0 ? 'style="display:none"' : ''; ?>>
						<p class="op3pa-slide-title"><?php echo esc_html( $row['name'] ); ?></p>
						<table class="op3pa-widget-table">
							<tbody>
								<?php foreach ( array_slice( $row['episodes'], 0, 4 ) as $ep ) : ?>
								<tr>
									<td class="op3pa-wt-title"><?php echo esc_html( $ep['episodeTitle'] ?? '—' ); ?></td>
									<td class="op3pa-wt-count"><?php echo esc_html( number_format_i18n( (int) $ep['downloads'] ) ); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<?php endforeach; ?>
				</div>
				<?php if ( count( $rows ) > 1 ) : ?>
				<div class="op3pa-widget-nav">
					<button type="button" class="op3pa-nav-prev button-link">‹</button>
					<span class="op3pa-nav-dots">
						<?php foreach ( $rows as $idx => $row ) : ?>
						<span class="op3pa-nav-dot <?php echo 0 === $idx ? 'active' : ''; ?>"></span>
						<?php endforeach; ?>
					</span>
					<button type="button" class="op3pa-nav-next button-link">›</button>
				</div>
				<?php endif; ?>

			<?php else : ?>
				<!-- Single podcast: show episode list -->
				<?php $single = reset( $rows ); ?>
				<table class="op3pa-widget-table">
					<tbody>
						<?php foreach ( array_slice( $single['episodes'] ?? [], 0, 5 ) as $ep ) : ?>
						<tr>
							<td class="op3pa-wt-title"><?php echo esc_html( $ep['episodeTitle'] ?? '—' ); ?></td>
							<td class="op3pa-wt-count"><?php echo esc_html( number_format_i18n( (int) $ep['downloads'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="op3pa-widget-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-stats' ) ); ?>">
					<?php esc_html_e( 'View all stats →', 'podcast-analytics-for-op3' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

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

	private const MENU_SLUG           = 'op3-podcast-analytics';
	private const NONCE_KEY           = 'op3pa_settings_nonce';
	private const NONCE_ACTION        = 'op3pa_save_settings';
	private const ALERTS_NONCE_KEY    = 'op3pa_alerts_nonce';
	private const ALERTS_NONCE_ACTION = 'op3pa_save_alerts';

	public static function init(): void {
		add_action( 'admin_menu',            [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_init',            [ __CLASS__, 'handle_alerts_save' ] );
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

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Alertas', 'podcast-analytics-for-op3' ),
			__( 'Alertas', 'podcast-analytics-for-op3' ),
			'manage_options',
			self::MENU_SLUG . '-alerts',
			[ __CLASS__, 'render_alerts_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function enqueue_assets( string $hook ): void {
		$pages = [
			'toplevel_page_' . self::MENU_SLUG,
			'op3-analytics_page_' . self::MENU_SLUG . '-stats',
			'op3-analytics_page_' . self::MENU_SLUG . '-alerts',
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

		$active   = op3pa_get_active_all_podcasts();
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

		// Save MaxMind license key (used for private podcast geolocation).
		$new_license_key = sanitize_text_field( wp_unslash( $_POST['op3pa_maxmind_key'] ?? '' ) );
		$had_license_key = OP3PA_Geo::has_license_key();
		update_option( OP3PA_OPTION_MAXMIND_KEY, $new_license_key );
		if ( ! empty( $new_license_key ) && ! $had_license_key ) {
			// First time a key is set: trigger the initial database download in the background.
			wp_schedule_single_event( time(), 'op3pa_geoip_refresh' );
		}

		// Save podcasts list. Each field is individually sanitized per-row in the loop below;
		// the array itself has no single sanitizer function that applies to it as a whole.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see loop below.
		$raw_podcasts = wp_unslash( $_POST['op3pa_podcasts'] ?? [] );
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
					'feed_slug' => sanitize_title( wp_unslash( $raw['feed_slug']  ?? '' ) ),
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
			$podcasts = [ [ 'name' => '', 'show_uuid' => '', 'guid' => '', 'feed_slug' => '', 'private' => false, 'enabled' => true ] ];
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
					<tr>
						<th scope="row">
							<label for="op3pa_maxmind_key"><?php esc_html_e( 'MaxMind license key (opcional)', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="password" id="op3pa_maxmind_key" name="op3pa_maxmind_key"
								value="<?php echo esc_attr( OP3PA_Geo::get_license_key() ); ?>"
								class="regular-text" autocomplete="off" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to MaxMind signup */
									esc_html__( 'Solo necesario para podcasts privados: activa el país/región del oyente en sus estadísticas. Gratis en %s.', 'podcast-analytics-for-op3' ),
									'<a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">maxmind.com/en/geolite2/signup</a>'
								);
								?>
								<?php if ( OP3PA_Geo::has_license_key() ) : ?>
									<?php if ( OP3PA_Geo::get_last_update() ) : ?>
										<br><?php
										printf(
											/* translators: %s: relative time e.g. "2 hours" */
											esc_html__( 'Base de datos de países actualizada por última vez: hace %s', 'podcast-analytics-for-op3' ),
											esc_html( human_time_diff( OP3PA_Geo::get_last_update() ) )
										);
										?>
									<?php else : ?>
										<br><?php esc_html_e( 'La descarga inicial de la base de datos está en curso (puede tardar unos minutos).', 'podcast-analytics-for-op3' ); ?>
									<?php endif; ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Podcasts', 'podcast-analytics-for-op3' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Añade una fila por cada podcast. El Show UUID lo encontrarás en la URL de tu página de estadísticas en OP3 (https://op3.dev/show/{uuid}). El Podcast GUID es opcional y acelera la atribución. El Feed slug (ej. "premiumpp" para /feed/premiumpp/) es obligatorio para podcasts privados, y opcional para públicos con más de un canal. Los podcasts marcados como privados no tendrán el prefijo OP3, sino un endpoint propio de seguimiento, y sus estadísticas se calculan desde tu propia base de datos.', 'podcast-analytics-for-op3' ); ?>
				</p>

				<table class="wp-list-table widefat fixed op3pa-podcasts-table" id="op3pa-podcasts-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nombre', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Show UUID', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Podcast GUID (opcional)', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Feed slug', 'podcast-analytics-for-op3' ); ?></th>
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
			op3paUpdateRowPrivacy( tbody.lastElementChild );
		});
		document.getElementById('op3pa-podcasts-body').addEventListener('click', function(e) {
			if ( e.target.classList.contains('op3pa-remove-row') ) {
				e.target.closest('tr').remove();
			}
		});

		/* Toggle UUID/GUID vs Feed slug depending on the "Privado" checkbox. */
		function op3paUpdateRowPrivacy( row ) {
			var isPrivate = row.querySelector('.col-private input[type="checkbox"]').checked;
			var uuid = row.querySelector('input[name$="[show_uuid]"]');
			var guid = row.querySelector('input[name$="[guid]"]');
			var slug = row.querySelector('input[name$="[feed_slug]"]');
			[ uuid, guid ].forEach( function( el ) { el.classList.toggle( 'op3pa-field-disabled', isPrivate ); } );
			slug.classList.toggle( 'op3pa-field-disabled', ! isPrivate );
		}
		document.querySelectorAll( '#op3pa-podcasts-body .op3pa-podcast-row' ).forEach( op3paUpdateRowPrivacy );
		document.getElementById('op3pa-podcasts-body').addEventListener('change', function(e) {
			if ( e.target.matches('.col-private input[type="checkbox"]') ) {
				op3paUpdateRowPrivacy( e.target.closest('tr') );
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
			<td>
				<input type="text" name="op3pa_podcasts[<?php echo esc_attr( $i ); ?>][feed_slug]"
					value="<?php echo esc_attr( $podcast['feed_slug'] ?? '' ); ?>"
					class="regular-text" placeholder="<?php esc_attr_e( 'ej: mi-canal-privado', 'podcast-analytics-for-op3' ); ?>" />
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
			<td><input type="text" name="op3pa_podcasts[__INDEX__][feed_slug]" class="regular-text" placeholder="<?php esc_attr_e( 'ej: mi-canal-privado', 'podcast-analytics-for-op3' ); ?>" /></td>
			<td class="col-color"><input type="color" name="op3pa_podcasts[__INDEX__][color]" value="#0066cc" /></td>
			<td class="col-private"><input type="checkbox" name="op3pa_podcasts[__INDEX__][private]" value="1" /></td>
			<td class="col-remove"><button type="button" class="button-link op3pa-remove-row" aria-label="<?php esc_attr_e( 'Eliminar', 'podcast-analytics-for-op3' ); ?>">✕</button></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Alerts page
	// -------------------------------------------------------------------------

	/**
	 * Saves the Alertas settings form.
	 */
	public static function handle_alerts_save(): void {
		if ( ! isset( $_POST[ self::ALERTS_NONCE_KEY ] ) ) {
			return;
		}
		check_admin_referer( self::ALERTS_NONCE_ACTION, self::ALERTS_NONCE_KEY );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'podcast-analytics-for-op3' ) );
		}

		$email = sanitize_email( wp_unslash( $_POST['op3pa_alerts_email'] ?? '' ) );
		update_option( OP3PA_Alerts::OPTION_EMAIL, $email );

		$types = array_intersect(
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by array_intersect() against the fixed whitelist below, not a string sanitizer function.
			wp_unslash( (array) ( $_POST['op3pa_alerts_types'] ?? [] ) ),
			[ 'spike', 'drop' ]
		);
		update_option( OP3PA_Alerts::OPTION_TYPES, array_values( $types ) );

		$threshold = absint( $_POST['op3pa_alerts_threshold'] ?? 50 );
		update_option( OP3PA_Alerts::OPTION_THRESHOLD, $threshold > 0 ? $threshold : 50 );

		$min_baseline = absint( $_POST['op3pa_alerts_min_baseline'] ?? 5 );
		update_option( OP3PA_Alerts::OPTION_MIN_BASELINE, $min_baseline > 0 ? $min_baseline : 5 );

		$podcasts = array_map( 'absint', (array) ( $_POST['op3pa_alerts_podcasts'] ?? [] ) );
		update_option( OP3PA_Alerts::OPTION_PODCASTS, $podcasts );

		// Re-evaluate the cron schedule immediately after saving.
		OP3PA_Alerts::init();

		if ( isset( $_POST['op3pa_alerts_check_now'] ) ) {
			OP3PA_Alerts::run_check();
			add_action( 'admin_notices', [ __CLASS__, 'notice_alerts_checked' ] );
		} else {
			add_action( 'admin_notices', [ __CLASS__, 'notice_saved' ] );
		}
	}

	public static function notice_alerts_checked(): void {
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Ajustes guardados y comprobación de alertas ejecutada.', 'podcast-analytics-for-op3' )
			. '</p></div>';
	}

	/**
	 * Renders the Alertas settings page.
	 */
	public static function render_alerts_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$email          = OP3PA_Alerts::get_email();
		$enabled_types  = OP3PA_Alerts::get_enabled_types();
		$threshold      = OP3PA_Alerts::get_threshold();
		$min_baseline   = OP3PA_Alerts::get_min_baseline();
		$monitored      = OP3PA_Alerts::get_monitored_podcasts();
		$active         = op3pa_get_active_all_podcasts();
		$last_check     = OP3PA_Alerts::get_last_check();
		$log            = OP3PA_Alerts::get_log();
		?>
		<div class="wrap op3pa-wrap">
			<h1>
				<span class="op3pa-logo">OP3</span>
				<?php esc_html_e( 'Podcast Analytics — Alertas', 'podcast-analytics-for-op3' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Recibe un aviso por email cuando un episodio (con más de 14 días publicado) tenga un pico o una caída de descargas inusual respecto a la semana anterior. Se comprueba una vez al día.', 'podcast-analytics-for-op3' ); ?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( self::ALERTS_NONCE_ACTION, self::ALERTS_NONCE_KEY ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="op3pa_alerts_email"><?php esc_html_e( 'Correo de destino', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="email" id="op3pa_alerts_email" name="op3pa_alerts_email"
								value="<?php echo esc_attr( $email ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Obligatorio para que se envíen alertas. No hay un correo por defecto.', 'podcast-analytics-for-op3' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tipo de alerta', 'podcast-analytics-for-op3' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input type="checkbox" name="op3pa_alerts_types[]" value="spike" <?php checked( in_array( 'spike', $enabled_types, true ) ); ?> />
								<?php esc_html_e( 'Pico de descargas en un episodio', 'podcast-analytics-for-op3' ); ?>
							</label>
							<label style="display:block;">
								<input type="checkbox" name="op3pa_alerts_types[]" value="drop" <?php checked( in_array( 'drop', $enabled_types, true ) ); ?> />
								<?php esc_html_e( 'Caída de descargas en un episodio', 'podcast-analytics-for-op3' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="op3pa_alerts_threshold"><?php esc_html_e( 'Umbral', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							±<input type="number" id="op3pa_alerts_threshold" name="op3pa_alerts_threshold"
								value="<?php echo esc_attr( $threshold ); ?>" min="1" max="500" style="width:80px;" />%
							<p class="description"><?php esc_html_e( 'Variación mínima (comparando esta semana con la anterior, por episodio) para disparar una alerta.', 'podcast-analytics-for-op3' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="op3pa_alerts_min_baseline"><?php esc_html_e( 'Mínimo de descargas', 'podcast-analytics-for-op3' ); ?></label>
						</th>
						<td>
							<input type="number" id="op3pa_alerts_min_baseline" name="op3pa_alerts_min_baseline"
								value="<?php echo esc_attr( $min_baseline ); ?>" min="1" max="1000" style="width:80px;" />
							<p class="description"><?php esc_html_e( 'La semana anterior del episodio debe tener al menos este número de descargas para considerarse. Evita avisos por cambios triviales (ej. pasar de 1 a 2 descargas ya es "+100%").', 'podcast-analytics-for-op3' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Podcasts monitorizados', 'podcast-analytics-for-op3' ); ?></th>
						<td>
							<?php if ( empty( $active ) ) : ?>
								<p class="description"><?php esc_html_e( 'No hay podcasts activos configurados.', 'podcast-analytics-for-op3' ); ?></p>
							<?php else : ?>
								<?php foreach ( $active as $i => $podcast ) : ?>
									<label style="display:block;margin-bottom:4px;">
										<input type="checkbox" name="op3pa_alerts_podcasts[]" value="<?php echo esc_attr( $i ); ?>" <?php checked( in_array( $i, $monitored, true ) ); ?> />
										<?php
										/* translators: %d: podcast number */
										echo esc_html( $podcast['name'] ?: sprintf( __( 'Podcast %d', 'podcast-analytics-for-op3' ), $i + 1 ) );
										?>
										<?php if ( ! empty( $podcast['private'] ) ) : ?> 🔒<?php endif; ?>
									</label>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Guardar ajustes', 'podcast-analytics-for-op3' ), 'primary', 'submit', false ); ?>
				<?php submit_button( __( 'Guardar y comprobar ahora', 'podcast-analytics-for-op3' ), 'secondary', 'op3pa_alerts_check_now', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Estado', 'podcast-analytics-for-op3' ); ?></h2>
			<p>
				<?php if ( $last_check ) : ?>
					<?php
					printf(
						/* translators: %s: relative time */
						esc_html__( 'Última comprobación: hace %s.', 'podcast-analytics-for-op3' ),
						esc_html( human_time_diff( $last_check ) )
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'Aún no se ha ejecutado ninguna comprobación.', 'podcast-analytics-for-op3' ); ?>
				<?php endif; ?>
			</p>

			<h2><?php esc_html_e( 'Historial reciente', 'podcast-analytics-for-op3' ); ?></h2>
			<?php if ( empty( $log ) ) : ?>
				<p class="description"><?php esc_html_e( 'No se ha disparado ninguna alerta todavía.', 'podcast-analytics-for-op3' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped op3pa-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Fecha', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Podcast', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Episodio', 'podcast-analytics-for-op3' ); ?></th>
							<th><?php esc_html_e( 'Cambio', 'podcast-analytics-for-op3' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $entry['time'] ?? 0 ) ); ?></td>
								<td><?php echo 'spike' === ( $entry['type'] ?? '' ) ? '📈 ' . esc_html__( 'Pico', 'podcast-analytics-for-op3' ) : '📉 ' . esc_html__( 'Caída', 'podcast-analytics-for-op3' ); ?></td>
								<td><?php echo esc_html( $entry['podcast'] ?? '' ); ?></td>
								<td>
									<?php if ( ! empty( $entry['episode_url'] ) ) : ?>
										<a href="<?php echo esc_url( $entry['episode_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $entry['episode_title'] ?? '' ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $entry['episode_title'] ?? '' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ( ( $entry['pct_change'] ?? 0 ) > 0 ? '+' : '' ) . ( $entry['pct_change'] ?? 0 ) ); ?>%</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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

		$active = op3pa_get_active_all_podcasts();
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
						<?php
						/* translators: %d: podcast number */
						echo esc_html( $p['name'] ?: sprintf( __( 'Podcast %d', 'podcast-analytics-for-op3' ), $i + 1 ) );
						?>
					</label>
					<?php endforeach; ?>
				</div>

				<!-- Period -->
				<div class="op3pa-period-tabs">
					<button class="op3pa-period-btn active" data-days="1"><?php esc_html_e( 'Last 24h', 'podcast-analytics-for-op3' ); ?></button>
					<button class="op3pa-period-btn" data-days="7"><?php esc_html_e( 'Last 7 days', 'podcast-analytics-for-op3' ); ?></button>
					<button class="op3pa-period-btn" data-days="30"><?php esc_html_e( 'Last 30 days', 'podcast-analytics-for-op3' ); ?></button>
				</div>

				<div class="op3pa-date-range">
					<label>
						<?php esc_html_e( 'Desde', 'podcast-analytics-for-op3' ); ?>
						<input type="date" id="op3pa-range-start" />
					</label>
					<label>
						<?php esc_html_e( 'Hasta', 'podcast-analytics-for-op3' ); ?>
						<input type="date" id="op3pa-range-end" />
					</label>
					<button type="button" id="op3pa-range-apply" class="button button-secondary">
						<?php esc_html_e( 'Aplicar rango', 'podcast-analytics-for-op3' ); ?>
					</button>
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
					self::render_network_table( 1 );
				} else {
					$first_index = array_key_first( $active );
					self::render_single_table( 1, $first_index );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Returns episode counts for a single podcast, routed to the OP3 API for
	 * public podcasts or to the local database for private (self-tracked) ones.
	 *
	 * @param int $podcast_i Podcast index.
	 * @param int $days      Period in days.
	 * @return array|WP_Error
	 */
	private static function get_counts_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_download_counts( $period, $podcast_i )
			: OP3PA_Api::get_download_counts( $period, $podcast_i );
	}

	/**
	 * Aggregates counts across public and private podcasts alike, routing each
	 * to the correct backend. Shaped like OP3PA_Api::get_network_counts().
	 *
	 * @param int   $days    Period in days.
	 * @param array $indexes Podcast indexes to include. Empty = all active (public + private).
	 * @return array ['rows' => [...], 'total' => int]
	 */
	private static function get_combined_network( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$rows  = [];
		$total = 0;

		foreach ( $active as $i => $podcast ) {
			$result = self::get_counts_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$sum    = array_sum( array_column( $result['rows'], 'downloads' ) );
			$total += $sum;

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
	 * Returns app/device breakdown for a single podcast, routed to the OP3 API
	 * for public podcasts or to the local database for private ones.
	 *
	 * @param int $podcast_i Podcast index.
	 * @param int $days      Period in days.
	 * @return array|WP_Error List of ['name'=>, 'downloads'=>], sorted descending.
	 */
	private static function get_app_breakdown_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_app_breakdown( $period, $podcast_i )
			: OP3PA_Api::get_app_breakdown( $period, $podcast_i );
	}

	/**
	 * Aggregates app/device breakdown across multiple podcasts (public + private).
	 *
	 * @param int   $days    Period in days.
	 * @param array $indexes Podcast indexes to include. Empty = all active.
	 * @return array List of ['name'=>, 'downloads'=>], sorted descending.
	 */
	private static function get_combined_app_breakdown( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$counts = [];
		foreach ( array_keys( $active ) as $i ) {
			$result = self::get_app_breakdown_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( $result as $row ) {
				$counts[ $row['name'] ] = ( $counts[ $row['name'] ] ?? 0 ) + $row['downloads'];
			}
		}

		arsort( $counts );

		$rows = [];
		foreach ( $counts as $name => $downloads ) {
			$rows[] = [ 'name' => $name, 'downloads' => $downloads ];
		}
		return $rows;
	}

	/**
	 * Renders the "Apps and devices" breakdown as a horizontal bar list.
	 *
	 * @param array $apps List of ['name'=>, 'downloads'=>].
	 */
	private static function render_app_breakdown( array|WP_Error $apps ): void {
		if ( is_wp_error( $apps ) || empty( $apps ) ) {
			return;
		}
		$total = array_sum( array_column( $apps, 'downloads' ) );
		$max   = max( array_column( $apps, 'downloads' ) );
		?>
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'Apps y dispositivos', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Aplicaciones y dispositivos usados para descargar los episodios, en el periodo seleccionado.', 'podcast-analytics-for-op3' ) ); ?>
		<table class="wp-list-table widefat fixed striped op3pa-table op3pa-apps-table">
			<tbody>
				<?php foreach ( array_slice( $apps, 0, 10 ) as $app ) : ?>
					<?php $pct = $total > 0 ? round( $app['downloads'] / $total * 100, 1 ) : 0; ?>
					<tr>
						<td class="column-app-name"><?php echo esc_html( $app['name'] ); ?></td>
						<td class="column-app-downloads">
							<strong><?php echo esc_html( number_format_i18n( $app['downloads'] ) ); ?></strong>
							<span class="op3pa-app-pct"><?php echo esc_html( $pct ); ?>%</span>
							<div class="op3pa-bar" style="width:<?php echo esc_attr( $max > 0 ? (int) round( $app['downloads'] / $max * 100 ) : 0 ); ?>%"></div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** Number of apps shown as rows in the side-by-side comparison, ranked by combined downloads. */
	private const APP_COMPARISON_MAX_APPS = 8;

	/**
	 * Builds the data for a side-by-side app/device comparison across the
	 * selected podcasts: one column per podcast, one row per app. Only
	 * meaningful with 2+ podcasts, so returns empty for a single one.
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array Empty, or ['podcasts'=>[['index','name','color','total']], 'apps'=>[name,...], 'matrix'=>[name=>[index=>downloads]]].
	 */
	private static function get_app_comparison( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}
		if ( count( $active ) < 2 ) {
			return [];
		}

		$podcasts      = [];
		$totals_by_app = [];
		$matrix        = [];

		foreach ( $active as $i => $podcast ) {
			$breakdown = self::get_app_breakdown_for_podcast( $i, $period );
			if ( is_wp_error( $breakdown ) ) {
				continue;
			}
			$by_name    = array_column( $breakdown, 'downloads', 'name' );
			$podcasts[] = [
				'index' => $i,
				'name'  => $podcast['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				),
				'color' => ! empty( $podcast['color'] ) ? $podcast['color'] : '#0066cc',
				'total' => array_sum( $by_name ),
			];
			foreach ( $by_name as $name => $downloads ) {
				$totals_by_app[ $name ] = ( $totals_by_app[ $name ] ?? 0 ) + $downloads;
				$matrix[ $name ][ $i ]  = $downloads;
			}
		}

		if ( count( $podcasts ) < 2 || empty( $totals_by_app ) ) {
			return [];
		}

		arsort( $totals_by_app );
		$apps = array_slice( array_keys( $totals_by_app ), 0, self::APP_COMPARISON_MAX_APPS );

		return [
			'podcasts' => $podcasts,
			'apps'     => $apps,
			'matrix'   => $matrix,
		];
	}

	/**
	 * Renders the side-by-side app/device comparison table: one column per
	 * podcast, one row per app, each cell showing downloads and % of that
	 * podcast's total.
	 *
	 * @param array $data ['podcasts'=>..., 'apps'=>..., 'matrix'=>...], or empty to skip.
	 */
	private static function render_app_comparison( array $data ): void {
		if ( empty( $data['podcasts'] ) || empty( $data['apps'] ) ) {
			return;
		}
		?>
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'Comparativa de apps entre podcasts', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Reparto de apps y dispositivos de cada podcast, uno al lado del otro, para comparar hábitos de escucha entre shows.', 'podcast-analytics-for-op3' ) ); ?>
		<div class="op3pa-overlap-matrix-wrap">
			<table class="wp-list-table widefat fixed striped op3pa-table op3pa-overlap-matrix op3pa-app-comparison-matrix">
				<thead>
					<tr>
						<th></th>
						<?php foreach ( $data['podcasts'] as $p ) : ?>
							<?php
							list( $r, $g, $b ) = sscanf( $p['color'], '#%02x%02x%02x' );
							$luminance  = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
							$text_color = $luminance > 0.5 ? '#1d2327' : '#ffffff';
							?>
							<th>
								<span class="op3pa-podcast-tag" style="background:<?php echo esc_attr( $p['color'] ); ?>;color:<?php echo esc_attr( $text_color ); ?>"><?php echo esc_html( $p['name'] ); ?></span>
							</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['apps'] as $app_name ) : ?>
						<tr>
							<td class="op3pa-app-comparison-name"><?php echo esc_html( $app_name ); ?></td>
							<?php foreach ( $data['podcasts'] as $p ) : ?>
								<?php
								$downloads = $data['matrix'][ $app_name ][ $p['index'] ] ?? 0;
								$pct       = $p['total'] > 0 ? round( $downloads / $p['total'] * 100, 1 ) : 0;
								?>
								<td>
									<?php if ( $downloads > 0 ) : ?>
										<strong><?php echo esc_html( number_format_i18n( $downloads ) ); ?></strong>
										<span class="op3pa-app-pct"><?php echo esc_html( $pct ); ?>%</span>
									<?php else : ?>
										<span class="op3pa-overlap-na">—</span>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Returns country breakdown for a single podcast, routed to the OP3 API
	 * for public podcasts or to the local database for private ones.
	 *
	 * @param int       $podcast_i Podcast index.
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array|WP_Error List of ['code'=>ISO2, 'downloads'=>], sorted descending.
	 */
	private static function get_country_breakdown_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_country_breakdown( $period, $podcast_i )
			: OP3PA_Api::get_country_breakdown( $period, $podcast_i );
	}

	/**
	 * Aggregates country breakdown across multiple podcasts (public + private).
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array List of ['code'=>ISO2, 'downloads'=>], sorted descending.
	 */
	private static function get_combined_country_breakdown( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$counts = [];
		foreach ( array_keys( $active ) as $i ) {
			$result = self::get_country_breakdown_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( $result as $row ) {
				$counts[ $row['code'] ] = ( $counts[ $row['code'] ] ?? 0 ) + $row['downloads'];
			}
		}

		arsort( $counts );

		$rows = [];
		foreach ( $counts as $code => $downloads ) {
			$rows[] = [ 'code' => $code, 'downloads' => $downloads ];
		}
		return $rows;
	}

	/**
	 * Renders the "Country" breakdown: a choropleth world map colored by
	 * download volume, plus a ranked list below it.
	 *
	 * @param array $countries List of ['code'=>ISO2, 'downloads'=>].
	 */
	private static function render_country_breakdown( array|WP_Error $countries ): void {
		if ( is_wp_error( $countries ) || empty( $countries ) ) {
			return;
		}
		$total = array_sum( array_column( $countries, 'downloads' ) );
		$max   = max( array_column( $countries, 'downloads' ) );
		$by_code = array_column( $countries, 'downloads', 'code' );
		?>
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'País', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Descargas agrupadas por país de origen, según la IP del oyente.', 'podcast-analytics-for-op3' ) ); ?>
		<div class="op3pa-country-map">
			<?php echo self::render_country_map_svg( $by_code, $max ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG markup built and escaped internally. ?>
		</div>
		<table class="wp-list-table widefat fixed striped op3pa-table op3pa-apps-table">
			<tbody>
				<?php foreach ( array_slice( $countries, 0, 10 ) as $c ) : ?>
					<?php $pct = $total > 0 ? round( $c['downloads'] / $total * 100, 1 ) : 0; ?>
					<tr>
						<td class="column-app-name"><?php echo esc_html( op3pa_country_name( $c['code'] ) ); ?></td>
						<td class="column-app-downloads">
							<strong><?php echo esc_html( number_format_i18n( $c['downloads'] ) ); ?></strong>
							<span class="op3pa-app-pct"><?php echo esc_html( $pct ); ?>%</span>
							<div class="op3pa-bar" style="width:<?php echo esc_attr( $max > 0 ? (int) round( $c['downloads'] / $max * 100 ) : 0 ); ?>%"></div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns downloads by hour-of-day / weekday for a single podcast, routed
	 * to the OP3 API for public podcasts or the local database for private ones.
	 *
	 * @param int       $podcast_i Podcast index.
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array|WP_Error ['by_hour'=>[0..23=>count], 'by_weekday'=>[0..6=>count]]
	 */
	private static function get_time_distribution_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_time_distribution( $period, $podcast_i )
			: OP3PA_Api::get_time_distribution( $period, $podcast_i );
	}

	/**
	 * Aggregates hour/weekday download distribution across multiple podcasts.
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array ['by_hour'=>[0..23=>count], 'by_weekday'=>[0..6=>count]]
	 */
	private static function get_combined_time_distribution( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$by_hour    = array_fill( 0, 24, 0 );
		$by_weekday = array_fill( 0, 7, 0 );

		foreach ( array_keys( $active ) as $i ) {
			$result = self::get_time_distribution_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( $result['by_hour'] as $h => $count ) {
				$by_hour[ $h ] += $count;
			}
			foreach ( $result['by_weekday'] as $w => $count ) {
				$by_weekday[ $w ] += $count;
			}
		}

		return [ 'by_hour' => $by_hour, 'by_weekday' => $by_weekday ];
	}

	/**
	 * Renders "Downloads by hour" and "Downloads by weekday" as two compact
	 * CSS bar charts side by side.
	 *
	 * @param array $distribution ['by_hour'=>[0..23=>count], 'by_weekday'=>[0..6=>count]]
	 */
	private static function render_time_distribution( array $distribution ): void {
		$by_hour    = $distribution['by_hour']    ?? [];
		$by_weekday = $distribution['by_weekday'] ?? [];
		if ( empty( array_filter( $by_hour ) ) && empty( array_filter( $by_weekday ) ) ) {
			return;
		}

		$weekday_labels = [
			__( 'Dom', 'podcast-analytics-for-op3' ),
			__( 'Lun', 'podcast-analytics-for-op3' ),
			__( 'Mar', 'podcast-analytics-for-op3' ),
			__( 'Mié', 'podcast-analytics-for-op3' ),
			__( 'Jue', 'podcast-analytics-for-op3' ),
			__( 'Vie', 'podcast-analytics-for-op3' ),
			__( 'Sáb', 'podcast-analytics-for-op3' ),
		];

		$hour_max    = max( array_merge( $by_hour, [ 1 ] ) );
		$weekday_max = max( array_merge( $by_weekday, [ 1 ] ) );
		?>
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'Mejor hora y día', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Cuándo escucha tu audiencia: hora del día y día de la semana con más descargas, útil para decidir cuándo publicar.', 'podcast-analytics-for-op3' ) ); ?>
		<div class="op3pa-time-charts">
			<div class="op3pa-time-chart">
				<p class="op3pa-time-chart-label"><?php esc_html_e( 'Por hora del día', 'podcast-analytics-for-op3' ); ?></p>
				<div class="op3pa-hbar-chart">
					<?php foreach ( $by_hour as $hour => $count ) : ?>
						<div class="op3pa-hbar" data-op3pa-tooltip="<?php echo esc_attr( sprintf( '<strong>%02d:00</strong>: %s', $hour, number_format_i18n( $count ) ) ); ?>">
							<div class="op3pa-hbar-fill" style="height:<?php echo esc_attr( $hour_max > 0 ? (int) round( $count / $hour_max * 100 ) : 0 ); ?>%"></div>
							<span class="op3pa-hbar-label"><?php echo esc_html( 0 === $hour % 6 ? sprintf( '%02d', $hour ) : '' ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="op3pa-time-chart">
				<p class="op3pa-time-chart-label"><?php esc_html_e( 'Por día de la semana', 'podcast-analytics-for-op3' ); ?></p>
				<div class="op3pa-hbar-chart op3pa-hbar-chart-week">
					<?php foreach ( $by_weekday as $w => $count ) : ?>
						<div class="op3pa-hbar" data-op3pa-tooltip="<?php echo esc_attr( '<strong>' . $weekday_labels[ $w ] . '</strong>: ' . number_format_i18n( $count ) ); ?>">
							<div class="op3pa-hbar-fill" style="height:<?php echo esc_attr( $weekday_max > 0 ? (int) round( $count / $weekday_max * 100 ) : 0 ); ?>%"></div>
							<span class="op3pa-hbar-label"><?php echo esc_html( $weekday_labels[ $w ] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/** Minimum previous-period downloads for an episode to enter the growth ranking — avoids noisy swings like 1→2 reading as "+100%". */
	private const GROWTH_MIN_BASELINE = 3;

	/**
	 * Returns the previous period of equal length immediately preceding the
	 * given one, e.g. period = last 7 days → previous = the 7 days before that.
	 *
	 * @param int|array $period Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array ['start'=>'Y-m-d','end'=>'Y-m-d']
	 */
	private static function previous_period_range( int|array $period ): array {
		if ( is_array( $period ) ) {
			$start = $period['start'];
			$end   = $period['end'] ?? gmdate( 'Y-m-d' );
		} else {
			$end   = gmdate( 'Y-m-d' );
			$start = gmdate( 'Y-m-d', strtotime( "-{$period} days" ) );
		}

		$days       = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
		$prev_end   = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( $days - 1 ) . ' days' ) );

		return [
			'start' => $prev_start,
			'end'   => $prev_end,
		];
	}

	/**
	 * Diffs two sets of episode-count rows (current vs. previous period) by
	 * episode URL, filtering out episodes too small to produce a meaningful
	 * percentage.
	 *
	 * @param array $current_rows
	 * @param array $previous_rows
	 * @return array List of ['episodeTitle','episodeUrl','episodePubdate','prev','curr','pct'].
	 */
	private static function diff_episode_counts( array $current_rows, array $previous_rows ): array {
		$prev_by_url = array_column( $previous_rows, 'downloads', 'episodeUrl' );

		$result = [];
		foreach ( $current_rows as $row ) {
			$url = $row['episodeUrl'] ?? '';
			if ( ! $url ) {
				continue;
			}
			$prev = (int) ( $prev_by_url[ $url ] ?? 0 );
			if ( $prev < self::GROWTH_MIN_BASELINE ) {
				continue;
			}
			$curr = (int) ( $row['downloads'] ?? 0 );

			$result[] = [
				'episodeTitle'   => $row['episodeTitle'] ?? '',
				'episodeUrl'     => $url,
				'episodePubdate' => $row['episodePubdate'] ?? '',
				'prev'           => $prev,
				'curr'           => $curr,
				'pct'            => round( ( $curr - $prev ) / $prev * 100 ),
			];
		}
		return $result;
	}

	/**
	 * Returns the growth ranking (current period vs. the equal-length period
	 * before it) for a single podcast, routed to the OP3 API for public
	 * podcasts or the local database for private ones.
	 *
	 * @param int       $podcast_i Podcast index.
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array|WP_Error List of ['episodeTitle','episodeUrl','episodePubdate','prev','curr','pct'].
	 */
	private static function get_growth_ranking_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$current = self::get_counts_for_podcast( $podcast_i, $period );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$previous = self::get_counts_for_podcast( $podcast_i, self::previous_period_range( $period ) );
		if ( is_wp_error( $previous ) ) {
			return $previous;
		}
		return self::diff_episode_counts( $current['rows'] ?? [], $previous['rows'] ?? [] );
	}

	/**
	 * Aggregates the growth ranking across multiple podcasts, tagging each row
	 * with its podcast name/color for display in the network view.
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array
	 */
	private static function get_combined_growth_ranking( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$all = [];
		foreach ( $active as $i => $podcast ) {
			$result = self::get_growth_ranking_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			foreach ( $result as $row ) {
				$row['podcast_name']  = $podcast['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				);
				$row['podcast_color'] = ! empty( $podcast['color'] ) ? $podcast['color'] : '#0066cc';
				$all[]                = $row;
			}
		}
		return $all;
	}

	/**
	 * Renders the growth ranking as two short lists: episodes climbing fastest
	 * and episodes falling fastest, comparing the current period against the
	 * equal-length period right before it.
	 *
	 * @param array|WP_Error $ranking List of ['episodeTitle','episodeUrl','episodePubdate','prev','curr','pct', optionally 'podcast_name'/'podcast_color'].
	 */
	private static function render_growth_ranking( array|WP_Error $ranking ): void {
		if ( is_wp_error( $ranking ) || empty( $ranking ) ) {
			return;
		}
		$growing = array_values( array_filter( $ranking, fn( $r ) => $r['pct'] > 0 ) );
		usort( $growing, fn( $a, $b ) => $b['pct'] <=> $a['pct'] );
		$growing = array_slice( $growing, 0, 10 );

		$declining = array_values( array_filter( $ranking, fn( $r ) => $r['pct'] < 0 ) );
		usort( $declining, fn( $a, $b ) => $a['pct'] <=> $b['pct'] );
		$declining = array_slice( $declining, 0, 10 );

		if ( empty( $growing ) && empty( $declining ) ) {
			return;
		}
		?>
		<h3 class="op3pa-show-heading">
			<?php esc_html_e( 'Ranking de crecimiento', 'podcast-analytics-for-op3' ); ?>
			<span class="op3pa-overlap-note"><?php esc_html_e( 'vs. periodo anterior equivalente', 'podcast-analytics-for-op3' ); ?></span>
		</h3>
		<?php self::render_section_intro( __( 'Episodios que más han subido o bajado en descargas respecto al periodo anterior de la misma duración.', 'podcast-analytics-for-op3' ) ); ?>
		<div class="op3pa-growth-lists">
			<?php if ( ! empty( $growing ) ) : ?>
				<div class="op3pa-growth-col">
					<p class="op3pa-time-chart-label">📈 <?php esc_html_e( 'En alza', 'podcast-analytics-for-op3' ); ?></p>
					<?php self::render_growth_table( $growing ); ?>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $declining ) ) : ?>
				<div class="op3pa-growth-col">
					<p class="op3pa-time-chart-label">📉 <?php esc_html_e( 'En caída', 'podcast-analytics-for-op3' ); ?></p>
					<?php self::render_growth_table( $declining ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders one growth-ranking table (either the "climbing" or "falling" half).
	 *
	 * @param array $rows
	 */
	private static function render_growth_table( array $rows ): void {
		$show_podcast = isset( $rows[0]['podcast_name'] );
		?>
		<table class="wp-list-table widefat fixed striped op3pa-table op3pa-growth-table">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php
					if ( $show_podcast ) {
						list( $r, $g, $b ) = sscanf( $row['podcast_color'], '#%02x%02x%02x' );
						$luminance  = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
						$text_color = $luminance > 0.5 ? '#1d2327' : '#ffffff';
					}
					?>
					<tr>
						<?php if ( $show_podcast ) : ?>
							<td class="column-podcast-tag">
								<span class="op3pa-podcast-tag" style="background:<?php echo esc_attr( $row['podcast_color'] ); ?>;color:<?php echo esc_attr( $text_color ); ?>"><?php echo esc_html( $row['podcast_name'] ); ?></span>
							</td>
						<?php endif; ?>
						<td class="column-episode">
							<?php if ( ! empty( $row['episodeUrl'] ) ) : ?>
								<a href="<?php echo esc_url( $row['episodeUrl'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $row['episodeTitle'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row['episodeTitle'] ); ?>
							<?php endif; ?>
						</td>
						<td class="column-growth-counts">
							<?php echo esc_html( number_format_i18n( $row['prev'] ) ); ?> → <?php echo esc_html( number_format_i18n( $row['curr'] ) ); ?>
						</td>
						<td class="column-growth-pct">
							<span class="op3pa-growth-pct <?php echo esc_attr( $row['pct'] >= 0 ? 'op3pa-growth-up' : 'op3pa-growth-down' ); ?>">
								<?php echo esc_html( ( $row['pct'] > 0 ? '+' : '' ) . $row['pct'] ); ?>%
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns unique-listener count for a single podcast, routed to the OP3 API
	 * for public podcasts or the local database for private ones.
	 *
	 * @param int       $podcast_i Podcast index.
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return int|WP_Error
	 */
	private static function get_unique_listeners_for_podcast( int $podcast_i, int|array $period ): int|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_unique_listeners( $period, $podcast_i )
			: OP3PA_Api::get_unique_listeners( $period, $podcast_i );
	}

	/**
	 * Sums unique-listener counts across multiple podcasts (not cross-podcast
	 * deduplicated — a listener of two shows counts once per show).
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array ['total' => int, 'has_private' => bool]
	 */
	private static function get_combined_unique_listeners( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$total       = 0;
		$has_private = false;

		foreach ( $active as $i => $podcast ) {
			$result = self::get_unique_listeners_for_podcast( $i, $period );
			if ( is_wp_error( $result ) ) {
				continue;
			}
			$total += $result;
			if ( ! empty( $podcast['private'] ) ) {
				$has_private = true;
			}
		}

		return [ 'total' => $total, 'has_private' => $has_private ];
	}

	/**
	 * Returns the set of distinct listener identifiers for a single podcast,
	 * routed to the OP3 API for public podcasts or the local database for
	 * private ones.
	 *
	 * @param int       $podcast_i Podcast index.
	 * @param int|array $period    Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array|WP_Error List of distinct listener identifiers.
	 */
	private static function get_audience_ids_for_podcast( int $podcast_i, int|array $period ): array|WP_Error {
		$podcast = op3pa_get_podcast( $podcast_i );
		return ! empty( $podcast['private'] )
			? OP3PA_DB::get_audience_ids( $period, $podcast_i )
			: OP3PA_Api::get_audience_ids( $period, $podcast_i );
	}

	/**
	 * Builds a pairwise audience-overlap matrix across a homogeneous group of
	 * podcasts (all public, or all private — never mixed, since identifiers
	 * aren't comparable across groups: OP3's audienceId vs. our own IP hash
	 * use different, incompatible schemes).
	 *
	 * @param array     $group_indexes Podcast indexes, all with the same 'private' status.
	 * @param int|array $period        Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @return array|null Null if fewer than 2 podcasts have data. Otherwise:
	 *                     [
	 *                       'podcasts' => [ index => ['name'=>, 'total'=>] ],
	 *                       'overlap'  => [ index_a => [ index_b => shared_count ] ],
	 *                       'pairs'    => [ [ 'a'=>index, 'b'=>index, 'shared'=>int, 'pct_a'=>float, 'pct_b'=>float ] ],
	 *                     ]
	 */
	private static function get_audience_overlap_for_group( array $group_indexes, int|array $period ): ?array {
		if ( count( $group_indexes ) < 2 ) {
			return null;
		}

		$id_sets  = [];
		$podcasts = [];
		foreach ( $group_indexes as $i ) {
			$podcast = op3pa_get_podcast( $i );
			$ids     = self::get_audience_ids_for_podcast( $i, $period );
			if ( is_wp_error( $ids ) || empty( $ids ) ) {
				continue;
			}
			$id_sets[ $i ]  = array_flip( $ids ); // Flipped for fast isset() lookups during intersection.
			$podcasts[ $i ] = [
				'name'  => $podcast['name'] ?: sprintf(
					/* translators: %d: podcast number */
					__( 'Podcast %d', 'podcast-analytics-for-op3' ),
					$i + 1
				),
				'total' => count( $ids ),
			];
		}

		if ( count( $id_sets ) < 2 ) {
			return null;
		}

		$overlap      = [];
		$pairs        = [];
		$indexes_list = array_keys( $id_sets );

		foreach ( $indexes_list as $a ) {
			foreach ( $indexes_list as $b ) {
				if ( $a === $b ) {
					continue;
				}
				$overlap[ $a ][ $b ] = count( array_intersect_key( $id_sets[ $a ], $id_sets[ $b ] ) );
			}
		}

		foreach ( $indexes_list as $a ) {
			foreach ( $indexes_list as $b ) {
				if ( $b <= $a || 0 === $overlap[ $a ][ $b ] ) {
					continue; // Each unordered pair only once, skip zero-overlap pairs.
				}
				$shared    = $overlap[ $a ][ $b ];
				$pairs[]   = [
					'a'      => $a,
					'b'      => $b,
					'shared' => $shared,
					'pct_a'  => $podcasts[ $a ]['total'] > 0 ? round( $shared / $podcasts[ $a ]['total'] * 100, 1 ) : 0,
					'pct_b'  => $podcasts[ $b ]['total'] > 0 ? round( $shared / $podcasts[ $b ]['total'] * 100, 1 ) : 0,
				];
			}
		}
		usort( $pairs, fn( $x, $y ) => $y['shared'] <=> $x['shared'] );

		return [ 'podcasts' => $podcasts, 'overlap' => $overlap, 'pairs' => $pairs ];
	}

	/**
	 * Splits the given (or all active) podcasts into public/private groups and
	 * computes the audience-overlap matrix separately for each — they're never
	 * mixed together, since their listener identifiers aren't comparable.
	 *
	 * @param int|array $period  Days back, or ['start'=>'Y-m-d','end'=>'Y-m-d'].
	 * @param array     $indexes Podcast indexes to include. Empty = all active.
	 * @return array ['public' => ?array, 'private' => ?array] — see get_audience_overlap_for_group().
	 */
	private static function get_audience_overlap( int|array $period, array $indexes = [] ): array {
		$active = op3pa_get_active_all_podcasts();
		if ( ! empty( $indexes ) ) {
			$active = array_intersect_key( $active, array_flip( $indexes ) );
		}

		$public_indexes  = [];
		$private_indexes = [];
		foreach ( $active as $i => $podcast ) {
			if ( ! empty( $podcast['private'] ) ) {
				$private_indexes[] = $i;
			} else {
				$public_indexes[] = $i;
			}
		}

		return [
			'public'  => self::get_audience_overlap_for_group( $public_indexes, $period ),
			'private' => self::get_audience_overlap_for_group( $private_indexes, $period ),
		];
	}

	/**
	 * Renders the audience-overlap matrix and ranked pair list. Public and
	 * private podcasts are always shown as separate sub-reports (never mixed
	 * in the same matrix), each only if it has 2+ comparable podcasts with data.
	 *
	 * @param array $groups Result of get_audience_overlap(): ['public'=>?array, 'private'=>?array].
	 */
	private static function render_audience_overlap( array $groups ): void {
		if ( empty( $groups['public'] ) && empty( $groups['private'] ) ) {
			return;
		}
		$show_subheadings = ! empty( $groups['public'] ) && ! empty( $groups['private'] );
		?>
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'Solapamiento de audiencia', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Qué porcentaje de oyentes escuchan más de uno de tus podcasts. Solo comparable entre podcasts del mismo tipo (públicos entre sí, privados entre sí).', 'podcast-analytics-for-op3' ) ); ?>
		<?php
		if ( ! empty( $groups['public'] ) ) {
			if ( $show_subheadings ) {
				echo '<p class="op3pa-overlap-group-label">' . esc_html__( 'Podcasts públicos', 'podcast-analytics-for-op3' ) . '</p>';
			}
			self::render_audience_overlap_group( $groups['public'] );
		}
		if ( ! empty( $groups['private'] ) ) {
			if ( $show_subheadings ) {
				echo '<p class="op3pa-overlap-group-label">' . esc_html__( 'Podcasts privados', 'podcast-analytics-for-op3' ) . '</p>';
			}
			self::render_audience_overlap_group( $groups['private'] );
		}
	}

	/**
	 * Renders a single overlap matrix + ranked pair list for one homogeneous
	 * group (all-public or all-private).
	 *
	 * @param array $data Result of get_audience_overlap_for_group().
	 */
	private static function render_audience_overlap_group( array $data ): void {
		$podcasts     = $data['podcasts'];
		$overlap      = $data['overlap'];
		$indexes_list = array_keys( $podcasts );
		?>
		<div class="op3pa-overlap-matrix-wrap">
			<table class="wp-list-table widefat fixed striped op3pa-table op3pa-overlap-matrix">
				<thead>
					<tr>
						<th></th>
						<?php foreach ( $indexes_list as $i ) : ?>
							<th><?php echo esc_html( $podcasts[ $i ]['name'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $indexes_list as $a ) : ?>
						<tr>
							<th><?php echo esc_html( $podcasts[ $a ]['name'] ); ?></th>
							<?php foreach ( $indexes_list as $b ) : ?>
								<?php if ( $a === $b ) : ?>
									<td class="op3pa-overlap-diag"><?php echo esc_html( number_format_i18n( $podcasts[ $a ]['total'] ) ); ?></td>
								<?php else : ?>
									<td><?php echo esc_html( number_format_i18n( $overlap[ $a ][ $b ] ) ); ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php if ( empty( $data['pairs'] ) ) : ?>
			<p class="op3pa-overlap-none"><?php esc_html_e( 'Sin oyentes compartidos en este periodo.', 'podcast-analytics-for-op3' ); ?></p>
		<?php else : ?>
			<ul class="op3pa-overlap-pairs">
				<?php foreach ( $data['pairs'] as $pair ) : ?>
					<li>
						<strong><?php echo esc_html( $podcasts[ $pair['a'] ]['name'] ); ?></strong>
						↔
						<strong><?php echo esc_html( $podcasts[ $pair['b'] ]['name'] ); ?></strong>:
						<?php
						printf(
							/* translators: 1: shared listener count 2: percentage of podcast A's audience 3: podcast A name 4: percentage of podcast B's audience 5: podcast B name */
							esc_html__( '%1$s oyentes compartidos (%2$s%% de %3$s, %4$s%% de %5$s)', 'podcast-analytics-for-op3' ),
							'<strong>' . esc_html( number_format_i18n( $pair['shared'] ) ) . '</strong>',
							esc_html( $pair['pct_a'] ),
							esc_html( $podcasts[ $pair['a'] ]['name'] ),
							esc_html( $pair['pct_b'] ),
							esc_html( $podcasts[ $pair['b'] ]['name'] )
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Loads the bundled world map SVG and colors each country by its share of
	 * downloads relative to the busiest country (linear scale, brand blue).
	 * Countries with no data get a neutral gray fill.
	 *
	 * @param array $by_code Map of ISO2 code => downloads.
	 * @param int   $max     Highest download count, for scaling.
	 * @return string Sanitised inline SVG markup, or '' if the map file is missing.
	 */
	private static function render_country_map_svg( array $by_code, int $max ): string {
		$svg_file = OP3PA_DIR . 'admin/img/world-map.svg';
		if ( ! file_exists( $svg_file ) ) {
			return '';
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadXML( file_get_contents( $svg_file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a bundled plugin asset.
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		foreach ( $xpath->query( '//*[@id]' ) as $node ) {
			$code = strtoupper( $node->getAttribute( 'id' ) );
			if ( isset( $by_code[ $code ] ) && $max > 0 ) {
				$ratio = $by_code[ $code ] / $max;
				$fill  = self::blend_color( '#cfe3f7', '#0052a3', $ratio );
			} else {
				$fill = '#e2e4e7';
			}
			$node->setAttribute( 'style', 'fill:' . $fill . ';stroke:#ffffff;stroke-width:0.5' );

			// Data attributes read by admin.js to show a floating tooltip on hover.
			// Only set for countries with actual data, so empty ones stay silent.
			if ( isset( $by_code[ $code ] ) ) {
				$node->setAttribute( 'data-op3pa-country', op3pa_country_name( $code ) );
				$node->setAttribute( 'data-op3pa-downloads', number_format_i18n( $by_code[ $code ] ) );
			}
		}

		$svg_element = $dom->getElementsByTagName( 'svg' )->item( 0 );
		if ( $svg_element ) {
			$svg_element->setAttribute( 'width', '100%' );
			$svg_element->removeAttribute( 'height' );

			// Remove the source file's own <title> ("Simple World Map") — browsers
			// show it as a native tooltip on hover, which fights with our own one.
			// local-name() is used because the SVG's default xmlns namespace would
			// otherwise make an unprefixed 'title' XPath match nothing.
			foreach ( iterator_to_array( $xpath->query( './*[local-name()="title"]', $svg_element ) ) as $title_node ) {
				$svg_element->removeChild( $title_node );
			}
		}

		return $dom->saveXML( $svg_element );
	}

	/**
	 * Linearly blends two hex colors.
	 *
	 * @param string $from  Hex color at ratio 0 (e.g. "#cfe3f7").
	 * @param string $to    Hex color at ratio 1.
	 * @param float  $ratio 0..1.
	 * @return string Hex color.
	 */
	private static function blend_color( string $from, string $to, float $ratio ): string {
		$ratio = max( 0, min( 1, $ratio ) );
		sscanf( $from, '#%02x%02x%02x', $r1, $g1, $b1 );
		sscanf( $to, '#%02x%02x%02x', $r2, $g2, $b2 );
		$r = (int) round( $r1 + ( $r2 - $r1 ) * $ratio );
		$g = (int) round( $g1 + ( $g2 - $g1 ) * $ratio );
		$b = (int) round( $b1 + ( $b2 - $b1 ) * $ratio );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Renders the network (multi-podcast aggregate) stats table.
	 *
	 * @param int   $days    Period in days.
	 * @param array $indexes Podcast indexes to include. Empty = all.
	 */
	private static function render_network_table( int|array $period, array $indexes = [] ): void {
		$result = self::get_combined_network( $period, $indexes );

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
			return;
		}

		$rows  = $result['rows']  ?? [];
		$total = $result['total'] ?? 0;
		?>
		<div id="op3pa-stats-table-wrap">

			<!-- Header: total + all podcast links -->
			<?php $unique = self::get_combined_unique_listeners( $period, $indexes ); ?>
			<div class="op3pa-network-header">
				<div class="op3pa-network-total">
					<span class="op3pa-net-number"><?php echo esc_html( number_format_i18n( (int) $total ) ); ?></span>
					<span class="op3pa-net-label"><?php esc_html_e( 'descargas totales', 'podcast-analytics-for-op3' ); ?></span>
				</div>
				<?php if ( $unique['total'] > 0 ) : ?>
				<div class="op3pa-network-total op3pa-network-total-secondary">
					<span class="op3pa-net-number"><?php echo esc_html( number_format_i18n( $unique['total'] ) ); ?></span>
					<span class="op3pa-net-label">
						<?php esc_html_e( 'oyentes únicos', 'podcast-analytics-for-op3' ); ?>
						<?php if ( $unique['has_private'] ) : ?>
							<span title="<?php esc_attr_e( 'Aproximado para podcasts privados en periodos superiores a 24h.', 'podcast-analytics-for-op3' ); ?>">*</span>
						<?php endif; ?>
					</span>
				</div>
				<?php endif; ?>
				<div class="op3pa-network-links no-print">
					<?php foreach ( $rows as $row ) : ?>
						<?php $row_podcast = op3pa_get_podcast( (int) $row['index'] ); ?>
						<?php if ( ! empty( $row_podcast['private'] ) ) : ?>
						<span class="op3pa-network-link op3pa-network-link-private">
							<?php echo esc_html( $row['name'] ); ?> 🔒
						</span>
						<?php else : ?>
						<a href="<?php echo esc_url( OP3PA_Api::get_stats_page_url( (int) $row['index'] ) ); ?>" target="_blank" rel="noopener" class="op3pa-network-link">
							<?php echo esc_html( $row['name'] ); ?> ↗OP3
						</a>
						<?php endif; ?>
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
				<?php self::render_section_intro( __( 'Descargas por episodio de todos los podcasts seleccionados, ordenadas de mayor a menor.', 'podcast-analytics-for-op3' ) ); ?>
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
						<?php foreach ( $all_episodes as $i => $ep ) :
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
						<tr<?php echo $i >= 10 ? ' class="op3pa-row-extra" style="display:none"' : ''; ?>>
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
						<?php if ( count( $all_episodes ) > 10 ) : ?>
						<tr class="op3pa-show-more-row no-print">
							<td colspan="4">
								<button type="button" class="button button-secondary op3pa-show-more-btn">
									<?php
									printf(
										/* translators: %d: number of remaining episodes */
										esc_html__( 'Ver todos (%d más)', 'podcast-analytics-for-op3' ),
										count( $all_episodes ) - 10
									);
									?>
								</button>
							</td>
						</tr>
						<?php endif; ?>
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

				<?php self::render_app_breakdown( self::get_combined_app_breakdown( $period, $indexes ) ); ?>
				<?php self::render_app_comparison( self::get_app_comparison( $period, $indexes ) ); ?>
				<?php self::render_country_breakdown( self::get_combined_country_breakdown( $period, $indexes ) ); ?>
				<?php self::render_time_distribution( self::get_combined_time_distribution( $period, $indexes ) ); ?>
				<?php self::render_growth_ranking( self::get_combined_growth_ranking( $period, $indexes ) ); ?>
				<?php self::render_audience_overlap( self::get_audience_overlap( $period, $indexes ) ); ?>

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
	private static function render_single_table( int|array $period, int $podcast_i ): void {
		$result = self::get_counts_for_podcast( $podcast_i, $period );

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
				<?php
				$unique = self::get_unique_listeners_for_podcast( $podcast_i, $period );
				if ( ! is_wp_error( $unique ) && $unique > 0 ) :
					?>
					<p class="op3pa-unique-listeners">
						<strong><?php echo esc_html( number_format_i18n( $unique ) ); ?></strong>
						<?php esc_html_e( 'oyentes únicos', 'podcast-analytics-for-op3' ); ?>
						<?php if ( ! empty( $podcast['private'] ) ) : ?>
							<span title="<?php esc_attr_e( 'Aproximado en periodos superiores a 24h.', 'podcast-analytics-for-op3' ); ?>">*</span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
				<?php self::render_episodes_table( $rows, $total ); ?>
				<?php self::render_app_breakdown( self::get_app_breakdown_for_podcast( $podcast_i, $period ) ); ?>
				<?php self::render_country_breakdown( self::get_country_breakdown_for_podcast( $podcast_i, $period ) ); ?>
				<?php self::render_time_distribution( self::get_time_distribution_for_podcast( $podcast_i, $period ) ); ?>
				<?php self::render_growth_ranking( self::get_growth_ranking_for_podcast( $podcast_i, $period ) ); ?>
				<?php if ( ! empty( $podcast['private'] ) ) : ?>
					<p class="op3pa-cache-note no-print">
						<?php esc_html_e( 'Live data from your own tracking endpoint (not cached).', 'podcast-analytics-for-op3' ); ?>
					</p>
				<?php else : ?>
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
		<h3 class="op3pa-show-heading"><?php esc_html_e( 'Episodios', 'podcast-analytics-for-op3' ); ?></h3>
		<?php self::render_section_intro( __( 'Descargas por episodio en el periodo seleccionado, ordenadas de mayor a menor.', 'podcast-analytics-for-op3' ) ); ?>
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
				foreach ( $rows as $i => $row ) :
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
				<tr<?php echo $i >= 10 ? ' class="op3pa-row-extra" style="display:none"' : ''; ?>>
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
				<?php if ( count( $rows ) > 10 ) : ?>
				<tr class="op3pa-show-more-row no-print">
					<td colspan="3">
						<button type="button" class="button button-secondary op3pa-show-more-btn">
							<?php
							printf(
								/* translators: %d: number of remaining episodes */
								esc_html__( 'Ver todos (%d más)', 'podcast-analytics-for-op3' ),
								count( $rows ) - 10
							);
							?>
						</button>
					</td>
				</tr>
				<?php endif; ?>
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

	/**
	 * Prints a short plain-language explanation of a report section. Hidden on
	 * screen (the charts speak for themselves there) but shown when printing
	 * or exporting to PDF, so a reader without access to the interactive admin
	 * page still understands what each section means.
	 *
	 * @param string $text
	 */
	private static function render_section_intro( string $text ): void {
		echo '<p class="op3pa-report-explainer">' . esc_html( $text ) . '</p>';
	}

	/** Percentage of total, for bar width. */
	private static function bar_pct( int $value, int $total ): int {
		return $total > 0 ? (int) round( $value / $total * 100 ) : 0;
	}

	/**
	 * Parses the period from the AJAX request: either a fixed days-back window
	 * (1, 7, or 30), or an explicit ['start'=>,'end'=>] custom date range.
	 *
	 * @return int|array
	 */
	private static function parse_requested_period(): int|array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only caller is ajax_refresh_stats(), which already calls check_ajax_referer() before this runs.
		$start = sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$end = sanitize_text_field( wp_unslash( $_POST['end'] ?? '' ) );

		if ( $start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			$period = [ 'start' => $start ];
			if ( $end && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) && $end >= $start ) {
				$period['end'] = $end;
			}
			return $period;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
		$days = absint( $_POST['days'] ?? 1 );
		return in_array( $days, [ 1, 7, 30 ], true ) ? $days : 1;
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	public static function ajax_refresh_stats(): void {
		check_ajax_referer( 'op3pa_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Access denied.', 'podcast-analytics-for-op3' ) ], 403 );
		}

		$indexes    = array_map( 'absint', (array) ( $_POST['indexes'] ?? [] ) );
		$is_network = in_array( 'network', (array) ( $_POST['indexes'] ?? [] ), true );
		$period     = self::parse_requested_period();

		OP3PA_Api::clear_cache();

		ob_start();
		if ( $is_network || count( $indexes ) > 1 ) {
			self::render_network_table( $period, $is_network ? [] : $indexes );
		} elseif ( ! empty( $indexes ) ) {
			self::render_single_table( $period, $indexes[0] );
		} else {
			self::render_network_table( $period );
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
		$active = op3pa_get_active_all_podcasts();
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
		$active = op3pa_get_active_all_podcasts();

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

		$result = self::get_combined_network( 7 );
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

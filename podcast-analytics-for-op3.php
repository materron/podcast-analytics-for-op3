<?php
/**
 * Plugin Name: Podcast Analytics for OP3
 * Plugin URI:  https://github.com/materron/podcast-analytics-for-op3
 * Description: Adds the OP3 prefix to your podcast feed enclosures and shows download statistics in the WordPress dashboard. Supports multiple podcasts and network-wide stats.
 * Version:     2.6.2
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author:      Miguel Ángel Terrón Bote
 * Author URI:  https://potencia.pro
 * Text Domain: podcast-analytics-for-op3
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OP3PA_VERSION', '2.6.2' );
define( 'OP3PA_DIR', plugin_dir_path( __FILE__ ) );
define( 'OP3PA_URL', plugin_dir_url( __FILE__ ) );
define( 'OP3PA_OPTION',              'op3pa_podcasts' );
define( 'OP3PA_OPTION_TOKEN',        'op3pa_bearer_token' );
define( 'OP3PA_OPTION_MAXMIND_KEY',  'op3pa_maxmind_license_key' );

require_once OP3PA_DIR . 'includes/class-op3pa-feed.php';
require_once OP3PA_DIR . 'includes/class-op3pa-api.php';
require_once OP3PA_DIR . 'includes/class-op3pa-admin.php';
require_once OP3PA_DIR . 'includes/class-op3pa-db.php';
require_once OP3PA_DIR . 'includes/class-op3pa-tracker.php';
require_once OP3PA_DIR . 'includes/class-op3pa-geo.php';
require_once OP3PA_DIR . 'includes/class-op3pa-alerts.php';

/**
 * Adds a "weekly" interval for the GeoLite2 database refresh cron event.
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function op3pa_add_weekly_cron_schedule( array $schedules ): array {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'podcast-analytics-for-op3' ),
		];
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'op3pa_add_weekly_cron_schedule' );

/**
 * Returns the global bearer token.
 *
 * @return string
 */
function op3pa_get_token(): string {
	return (string) get_option( OP3PA_OPTION_TOKEN, '' );
}

/**
 * Returns all configured podcasts.
 *
 * @return array
 */
function op3pa_get_podcasts(): array {
	return (array) get_option( OP3PA_OPTION, [] );
}

/**
 * Returns a single podcast by index, with defaults applied.
 *
 * @param int $index Podcast index.
 * @return array
 */
function op3pa_get_podcast( int $index = 0 ): array {
	$podcasts = op3pa_get_podcasts();
	$defaults = [
		'name'      => '',
		'enabled'   => false,
		'private'   => false,
		'guid'      => '',
		'show_uuid' => '',
		'feed_slug' => '',
	];
	return wp_parse_args( $podcasts[ $index ] ?? [], $defaults );
}

/**
 * Returns only podcasts that are active (enabled, not private, with a show_uuid).
 *
 * @return array  Keys are original indexes.
 */
function op3pa_get_active_podcasts(): array {
	$active = [];
	foreach ( op3pa_get_podcasts() as $i => $podcast ) {
		$p = wp_parse_args( $podcast, [
			'name'      => '',
			'enabled'   => false,
			'private'   => false,
			'show_uuid' => '',
		] );
		if ( ! empty( $p['show_uuid'] ) && empty( $p['private'] ) ) {
			$active[ $i ] = $p;
		}
	}
	return $active;
}

/**
 * Returns only podcasts that are private and self-tracked (feed_slug configured).
 * Their stats come from the local database instead of the OP3 API.
 *
 * @return array  Keys are original indexes.
 */
function op3pa_get_active_private_podcasts(): array {
	$active = [];
	foreach ( op3pa_get_podcasts() as $i => $podcast ) {
		$p = wp_parse_args( $podcast, [
			'name'      => '',
			'enabled'   => false,
			'private'   => false,
			'feed_slug' => '',
		] );
		if ( ! empty( $p['private'] ) && ! empty( $p['feed_slug'] ) ) {
			$active[ $i ] = $p;
		}
	}
	return $active;
}

/**
 * Returns all active podcasts, public and private, keyed by their original index.
 *
 * @return array
 */
function op3pa_get_active_all_podcasts(): array {
	return op3pa_get_active_podcasts() + op3pa_get_active_private_podcasts();
}

/**
 * Returns a human-readable country name for an ISO 3166-1 alpha-2 code.
 * Covers the countries present in the bundled world map (admin/img/world-map.svg).
 *
 * @param string $code Two-letter uppercase country code.
 * @return string
 */
function op3pa_country_name( string $code ): string {
	static $names = null;
	if ( null === $names ) {
		$names = [
			'AF' => 'Afganistán', 'AL' => 'Albania', 'DZ' => 'Argelia', 'AO' => 'Angola',
			'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia', 'AT' => 'Austria',
			'AZ' => 'Azerbaiyán', 'BS' => 'Bahamas', 'BD' => 'Bangladés', 'BY' => 'Bielorrusia',
			'BE' => 'Bélgica', 'BZ' => 'Belice', 'BJ' => 'Benín', 'BT' => 'Bután',
			'BO' => 'Bolivia', 'BA' => 'Bosnia y Herzegovina', 'BW' => 'Botsuana', 'BR' => 'Brasil',
			'BN' => 'Brunéi', 'BG' => 'Bulgaria', 'BF' => 'Burkina Faso', 'BI' => 'Burundi',
			'KH' => 'Camboya', 'CM' => 'Camerún', 'CA' => 'Canadá', 'CF' => 'República Centroafricana',
			'TD' => 'Chad', 'CL' => 'Chile', 'CN' => 'China', 'CO' => 'Colombia',
			'CG' => 'Congo', 'CR' => 'Costa Rica', 'CI' => 'Costa de Marfil', 'HR' => 'Croacia',
			'CU' => 'Cuba', 'CY' => 'Chipre', 'CZ' => 'República Checa', 'CD' => 'RD del Congo',
			'DK' => 'Dinamarca', 'DJ' => 'Yibuti', 'DO' => 'República Dominicana', 'EC' => 'Ecuador',
			'EG' => 'Egipto', 'SV' => 'El Salvador', 'GQ' => 'Guinea Ecuatorial', 'ER' => 'Eritrea',
			'EE' => 'Estonia', 'ET' => 'Etiopía', 'FJ' => 'Fiyi', 'FI' => 'Finlandia',
			'FR' => 'Francia', 'GA' => 'Gabón', 'GM' => 'Gambia', 'GE' => 'Georgia',
			'DE' => 'Alemania', 'GH' => 'Ghana', 'GR' => 'Grecia', 'GL' => 'Groenlandia',
			'GT' => 'Guatemala', 'GN' => 'Guinea', 'GW' => 'Guinea-Bisáu', 'GY' => 'Guyana',
			'HT' => 'Haití', 'HN' => 'Honduras', 'HU' => 'Hungría', 'IS' => 'Islandia',
			'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Irán', 'IQ' => 'Irak',
			'IE' => 'Irlanda', 'IL' => 'Israel', 'IT' => 'Italia', 'JM' => 'Jamaica',
			'JP' => 'Japón', 'JO' => 'Jordania', 'KZ' => 'Kazajistán', 'KE' => 'Kenia',
			'KW' => 'Kuwait', 'KG' => 'Kirguistán', 'LA' => 'Laos', 'LV' => 'Letonia',
			'LB' => 'Líbano', 'LS' => 'Lesoto', 'LR' => 'Liberia', 'LY' => 'Libia',
			'LT' => 'Lituania', 'LU' => 'Luxemburgo', 'MK' => 'Macedonia del Norte', 'MG' => 'Madagascar',
			'MW' => 'Malaui', 'MY' => 'Malasia', 'ML' => 'Malí', 'MT' => 'Malta',
			'MR' => 'Mauritania', 'MX' => 'México', 'MD' => 'Moldavia', 'MN' => 'Mongolia',
			'ME' => 'Montenegro', 'MA' => 'Marruecos', 'MZ' => 'Mozambique', 'MM' => 'Birmania',
			'NA' => 'Namibia', 'NP' => 'Nepal', 'NL' => 'Países Bajos', 'NZ' => 'Nueva Zelanda',
			'NI' => 'Nicaragua', 'NE' => 'Níger', 'NG' => 'Nigeria', 'KP' => 'Corea del Norte',
			'NO' => 'Noruega', 'OM' => 'Omán', 'PK' => 'Pakistán', 'PS' => 'Palestina',
			'PA' => 'Panamá', 'PG' => 'Papúa Nueva Guinea', 'PY' => 'Paraguay', 'PE' => 'Perú',
			'PH' => 'Filipinas', 'PL' => 'Polonia', 'PT' => 'Portugal', 'PR' => 'Puerto Rico',
			'QA' => 'Catar', 'RO' => 'Rumanía', 'RU' => 'Rusia', 'RW' => 'Ruanda',
			'SA' => 'Arabia Saudí', 'SN' => 'Senegal', 'RS' => 'Serbia', 'SL' => 'Sierra Leona',
			'SG' => 'Singapur', 'SK' => 'Eslovaquia', 'SI' => 'Eslovenia', 'SB' => 'Islas Salomón',
			'SO' => 'Somalia', 'ZA' => 'Sudáfrica', 'KR' => 'Corea del Sur', 'SS' => 'Sudán del Sur',
			'ES' => 'España', 'LK' => 'Sri Lanka', 'SD' => 'Sudán', 'SR' => 'Surinam',
			'SZ' => 'Esuatini', 'SE' => 'Suecia', 'CH' => 'Suiza', 'SY' => 'Siria',
			'TW' => 'Taiwán', 'TJ' => 'Tayikistán', 'TZ' => 'Tanzania', 'TH' => 'Tailandia',
			'TL' => 'Timor Oriental', 'TG' => 'Togo', 'TT' => 'Trinidad y Tobago', 'TN' => 'Túnez',
			'TR' => 'Turquía', 'TM' => 'Turkmenistán', 'UG' => 'Uganda', 'UA' => 'Ucrania',
			'AE' => 'Emiratos Árabes Unidos', 'GB' => 'Reino Unido', 'US' => 'Estados Unidos', 'UY' => 'Uruguay',
			'UZ' => 'Uzbekistán', 'VU' => 'Vanuatu', 'VE' => 'Venezuela', 'VN' => 'Vietnam',
			'YE' => 'Yemen', 'ZM' => 'Zambia', 'ZW' => 'Zimbabue',
		];
	}
	return $names[ strtoupper( $code ) ] ?? strtoupper( $code );
}

// Bootstrap.
add_action( 'plugins_loaded', [ 'OP3PA_Feed', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Admin', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Tracker', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_DB', 'maybe_upgrade' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Geo', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Alerts', 'init' ] );
add_action( 'plugins_loaded', 'op3pa_maybe_migrate' );

register_activation_hook( __FILE__, 'op3pa_activate' );
register_deactivation_hook( __FILE__, 'op3pa_deactivate' );

function op3pa_activate(): void {
	if ( ! get_option( OP3PA_OPTION ) ) {
		add_option( OP3PA_OPTION, [] );
	}
	op3pa_maybe_migrate();
	OP3PA_DB::maybe_upgrade();
}

/**
 * Migrates data from v1.x format to v2.0 format.
 *
 * v1.x stored api_key inside each podcast entry.
 * v2.0 uses a global bearer token and a cleaner podcast structure.
 *
 * Runs once and stores a flag to avoid repeating.
 */
function op3pa_maybe_migrate(): void {
	if ( get_option( 'op3pa_migrated_v2' ) ) {
		return;
	}

	$podcasts = (array) get_option( OP3PA_OPTION, [] );

	// Check if old format: first podcast has 'api_key' field.
	if ( empty( $podcasts ) || ! isset( $podcasts[0]['api_key'] ) ) {
		// Nothing to migrate, mark as done.
		update_option( 'op3pa_migrated_v2', true );
		return;
	}

	// Extract global bearer token from first podcast.
	$api_key = $podcasts[0]['api_key'] ?? '';
	if ( ! empty( $api_key ) && empty( get_option( OP3PA_OPTION_TOKEN ) ) ) {
		update_option( OP3PA_OPTION_TOKEN, $api_key );
	}

	// Convert all podcasts to new format.
	$new_podcasts = [];
	foreach ( $podcasts as $i => $old ) {
		$new_podcasts[] = [
			'name'      => $old['name']      ?? sprintf(
				/* translators: %d: podcast number */
				__( 'Podcast %d', 'podcast-analytics-for-op3' ),
				$i + 1
			),
			'show_uuid' => $old['show_uuid'] ?? '',
			'guid'      => $old['guid']      ?? '',
			'private'   => ! empty( $old['private'] ),
			'enabled'   => true,
		];
	}

	update_option( OP3PA_OPTION, $new_podcasts );
	update_option( 'op3pa_migrated_v2', true );
}

function op3pa_deactivate(): void {
	wp_clear_scheduled_hook( 'op3pa_geoip_refresh' );
	wp_clear_scheduled_hook( 'op3pa_alerts_check' );
	for ( $op3pa_i = 0; $op3pa_i < 20; $op3pa_i++ ) {
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_1d' );
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_7d' );
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_30d' );
	}
	delete_transient( 'op3pa_network_1d' );
	delete_transient( 'op3pa_network_7d' );
	delete_transient( 'op3pa_network_30d' );
}


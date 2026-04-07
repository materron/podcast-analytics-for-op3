<?php
/**
 * Plugin Name: Podcast Analytics for OP3
 * Plugin URI:  https://github.com/materron/podcast-analytics-for-op3
 * Description: Adds the OP3 prefix to your podcast feed enclosures and shows download statistics in the WordPress dashboard. Supports multiple podcasts and network-wide stats.
 * Version:     2.0.6
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

define( 'OP3PA_VERSION', '2.0.6' );
define( 'OP3PA_DIR', plugin_dir_path( __FILE__ ) );
define( 'OP3PA_URL', plugin_dir_url( __FILE__ ) );
define( 'OP3PA_OPTION',       'op3pa_podcasts' );
define( 'OP3PA_OPTION_TOKEN', 'op3pa_bearer_token' );

require_once OP3PA_DIR . 'includes/class-op3pa-feed.php';
require_once OP3PA_DIR . 'includes/class-op3pa-api.php';
require_once OP3PA_DIR . 'includes/class-op3pa-admin.php';

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

// Bootstrap.
add_action( 'plugins_loaded', [ 'OP3PA_Feed', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Admin', 'init' ] );
add_action( 'plugins_loaded', 'op3pa_maybe_migrate' );

register_activation_hook( __FILE__, 'op3pa_activate' );
register_deactivation_hook( __FILE__, 'op3pa_deactivate' );

function op3pa_activate(): void {
	if ( ! get_option( OP3PA_OPTION ) ) {
		add_option( OP3PA_OPTION, [] );
	}
	op3pa_maybe_migrate();
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
			'name'      => $old['name']      ?? sprintf( __( 'Podcast %d', 'podcast-analytics-for-op3' ), $i + 1 ),
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
	for ( $op3pa_i = 0; $op3pa_i < 20; $op3pa_i++ ) {
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_1d' );
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_7d' );
		delete_transient( 'op3pa_downloads_' . $op3pa_i . '_30d' );
	}
	delete_transient( 'op3pa_network_1d' );
	delete_transient( 'op3pa_network_7d' );
	delete_transient( 'op3pa_network_30d' );
}


<?php
/**
 * Plugin Name: OP3 Podcast Analytics
 * Description: Adds the OP3 prefix to your podcast feed enclosures and shows download statistics in the WordPress dashboard.
 * Version:     1.0.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author:      Miguel Ángel Terrón
 * Text Domain: op3-podcast-analytics
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OP3PA_VERSION', '1.0.0' );
define( 'OP3PA_DIR', plugin_dir_path( __FILE__ ) );
define( 'OP3PA_URL', plugin_dir_url( __FILE__ ) );
define( 'OP3PA_OPTION', 'op3pa_podcasts' );

require_once OP3PA_DIR . 'includes/class-op3pa-feed.php';
require_once OP3PA_DIR . 'includes/class-op3pa-api.php';
require_once OP3PA_DIR . 'includes/class-op3pa-admin.php';

/**
 * Returns the settings for podcast index 0 (single-podcast MVP).
 * When multi-podcast support is added, callers only need to pass the index.
 *
 * @param int $index Podcast index.
 * @return array
 */
function op3pa_get_podcast( int $index = 0 ): array {
	$podcasts = get_option( OP3PA_OPTION, [] );
	$defaults = [
		'enabled'  => false,
		'api_key'  => '',
		'guid'     => '',
		'feed_url' => '',
		'show_uuid' => '',
	];
	return wp_parse_args( $podcasts[ $index ] ?? [], $defaults );
}

/**
 * Saves settings for podcast index 0.
 *
 * @param array $data Data to save.
 * @param int   $index Podcast index.
 */
function op3pa_save_podcast( array $data, int $index = 0 ): void {
	$podcasts          = get_option( OP3PA_OPTION, [] );
	$podcasts[ $index ] = $data;
	update_option( OP3PA_OPTION, $podcasts );
}

// Bootstrap
add_action( 'plugins_loaded', [ 'OP3PA_Feed', 'init' ] );
add_action( 'plugins_loaded', [ 'OP3PA_Admin', 'init' ] );

// Activation / deactivation
register_activation_hook( __FILE__, 'op3pa_activate' );
register_deactivation_hook( __FILE__, 'op3pa_deactivate' );

function op3pa_activate(): void {
	if ( ! get_option( OP3PA_OPTION ) ) {
		add_option( OP3PA_OPTION, [] );
	}
}

function op3pa_deactivate(): void {
	// Delete cached stats transients
	delete_transient( 'op3pa_stats_0' );
}

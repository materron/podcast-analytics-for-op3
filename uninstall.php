<?php
/**
 * Fired when the plugin is uninstalled (deleted from WP admin).
 *
 * @package OP3_Podcast_Analytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'op3pa_podcasts' );

// Remove cached transients.
for ( $op3pa_i = 0; $op3pa_i < 10; $op3pa_i++ ) {
	delete_transient( 'op3pa_downloads_' . $op3pa_i . '_1d' );
	delete_transient( 'op3pa_downloads_' . $op3pa_i . '_7d' );
	delete_transient( 'op3pa_downloads_' . $op3pa_i . '_30d' );
	delete_transient( 'op3pa_show_' . $op3pa_i );
}

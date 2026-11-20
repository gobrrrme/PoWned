<?php
/**
 * Uninstall: remove settings, secret and the early loader.
 *
 * @package Powned
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'powned_settings' );
delete_option( 'powned_secret' );
delete_option( 'powned_blocks' );
delete_option( 'powned_last_block' );
delete_option( 'powned_stats' );
wp_clear_scheduled_hook( 'powned_rollup' );
$powned_loader = WPMU_PLUGIN_DIR . '/powned-early.php';
if ( is_file( $powned_loader ) ) {
	unlink( $powned_loader ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

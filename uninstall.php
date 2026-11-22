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
delete_option( 'powned_loader_version' );
global $wpdb;
foreach ( array( 'powned_used_', 'powned_rate_' ) as $powned_prefix ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $powned_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB
}
delete_metadata( 'user', 0, 'powned_dismissed_cache', '', true );
delete_metadata( 'user', 0, 'powned_dismissed_proxy', '', true );
delete_metadata( 'user', 0, 'powned_dismissed_proxy_headers', '', true );
wp_clear_scheduled_hook( 'powned_rollup' );
delete_transient( 'powned_lockdown_log' );
delete_transient( 'powned_lockdown_auto' );
$powned_loader = WPMU_PLUGIN_DIR . '/powned-early.php';
if ( is_file( $powned_loader ) ) {
	unlink( $powned_loader ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

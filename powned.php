<?php
/**
 * Plugin Name:       PoWned
 * Plugin URI:        https://github.com/gobrrrme/PoWned
 * Description:       Protects expensive pages (by default WooCommerce filter URLs) with a small Bitcoin-style proof of work solved in the visitor's browser. Stops crawlers that hammer thousands of URL combinations from rotating IPs, without third-party services, tracking or CAPTCHAs. Difficulty adjusts to server load.
 * Version:           0.4.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            GoBrrr
 * Author URI:        https://www.gobrrr.me
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       powned
 * Domain Path:       /languages
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'POWNED_VERSION' ) ) {
	define( 'POWNED_VERSION', '0.4.2' );
}
if ( ! defined( 'POWNED_FILE' ) ) {
	define( 'POWNED_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/class-powned.php';
require_once __DIR__ . '/includes/class-powned-loader.php';
require_once __DIR__ . '/includes/class-powned-actions.php';
require_once __DIR__ . '/includes/class-powned-lockdown.php';

// Check right away (unless the early loader in mu-plugins already did).
Powned::maybe_challenge();

register_activation_hook( __FILE__, array( 'Powned_Loader', 'activate' ) );

// Checkout protection (option): order submission and Store API orders need a solved block.
add_action( 'woocommerce_checkout_process', array( 'Powned', 'guard_checkout_submit' ), 1 );
add_filter( 'rest_pre_dispatch', array( 'Powned', 'guard_store_api' ), 10, 3 );
// Coupons, login, forms; background miner on the pages where these actions happen.
add_action( 'plugins_loaded', array( 'Powned_Actions', 'init' ) );
// Full lockdown (option): every page view needs a block.
add_action( 'plugins_loaded', array( 'Powned_Lockdown', 'init' ) );

// Hourly: move the per-day counters from the object cache into the statistics.
add_action( Powned::ROLLUP_HOOK, array( 'Powned', 'rollup' ) );
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( Powned::ROLLUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', Powned::ROLLUP_HOOK );
		}
	}
);
register_deactivation_hook( __FILE__, array( 'Powned_Loader', 'deactivate' ) );

if ( is_admin() ) {
	add_action( 'admin_init', array( 'Powned_Loader', 'maybe_update' ) );
	require_once __DIR__ . '/includes/class-powned-settings.php';
	Powned_Settings::init();
}

/**
 * Preview of the challenge page for administrators: /?powned_preview=1
 */
add_action(
	'init',
	static function () {
		if ( isset( $_GET['powned_preview'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Powned::render_challenge( true );
		}
	}
);

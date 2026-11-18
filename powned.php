<?php
/**
 * Plugin Name:       PoWned
 * Plugin URI:        https://github.com/gobrrr/powned
 * Description:       Protects expensive pages (by default WooCommerce filter URLs) with a small Bitcoin-style proof of work solved in the visitor's browser. Stops crawlers that hammer thousands of URL combinations from rotating IPs, without third-party services, tracking or CAPTCHAs. Difficulty adjusts to server load.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Druck Hoch Drei e.U. (GoBrrr)
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
	define( 'POWNED_VERSION', '0.1.0' );
}
if ( ! defined( 'POWNED_FILE' ) ) {
	define( 'POWNED_FILE', __FILE__ );
}

require_once __DIR__ . '/includes/class-powned.php';
require_once __DIR__ . '/includes/class-powned-loader.php';

// Check right away (unless the early loader in mu-plugins already did).
Powned::maybe_challenge();

register_activation_hook( __FILE__, array( 'Powned_Loader', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Powned_Loader', 'deactivate' ) );

if ( is_admin() ) {
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

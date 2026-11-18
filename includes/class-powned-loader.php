<?php
/**
 * Early loader: a tiny file in mu-plugins runs the check before other plugins load, so a
 * crawler request costs almost nothing. Optional, switched in the settings.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install, remove and describe the early loader.
 */
final class Powned_Loader {

	const FILE = 'powned-early.php';

	/**
	 * Path of the loader file.
	 */
	public static function path() {
		return WPMU_PLUGIN_DIR . '/' . self::FILE;
	}

	/**
	 * Is the loader installed?
	 */
	public static function installed() {
		return is_file( self::path() );
	}

	/**
	 * Write the loader into mu-plugins.
	 *
	 * @return bool
	 */
	public static function install() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}
		$plugin = plugin_basename( POWNED_FILE );
		$code   = "<?php\n/**\n * Plugin Name: PoWned (early check)\n * Description: Runs the PoWned check before other plugins load. Managed by the PoWned plugin, removed when it is deactivated.\n */\n\ndefined( 'ABSPATH' ) || exit;\n\n"
			. '$powned_core = WP_PLUGIN_DIR . ' . var_export( '/' . dirname( $plugin ) . '/includes/class-powned.php', true ) . ";\n"
			. '$powned_active = in_array( ' . var_export( $plugin, true ) . ", (array) get_option( 'active_plugins', array() ), true );\n"
			. "if ( is_multisite() && ! \$powned_active ) {\n"
			. "\t\$sw = (array) get_site_option( 'active_sitewide_plugins', array() );\n"
			. "\t\$powned_active = isset( \$sw[" . var_export( $plugin, true ) . "] );\n"
			. "}\n"
			. "if ( is_readable( \$powned_core ) && \$powned_active ) {\n"
			. "\trequire_once \$powned_core;\n\tPowned::maybe_challenge();\n}\nunset( \$powned_core, \$powned_active );\n";
		return false !== @file_put_contents( self::path(), $code ); // phpcs:ignore
	}

	/**
	 * Remove the loader.
	 */
	public static function remove() {
		if ( self::installed() ) {
			@unlink( self::path() ); // phpcs:ignore
		}
	}

	/**
	 * Activation: secret and default settings; loader if early mode is on.
	 */
	public static function activate() {
		Powned::secret();
		if ( false === get_option( Powned::OPTION ) ) {
			add_option( Powned::OPTION, Powned::defaults() );
		}
		$s = Powned::settings();
		if ( ! empty( $s['early'] ) ) {
			self::install();
		}
	}

	/**
	 * Deactivation: the loader must not outlive the plugin.
	 */
	public static function deactivate() {
		self::remove();
	}
}

<?php
/**
 * Action blocks (since 0.5): every protected action costs a block of its own.
 *
 * A browsing block (version 1) opens pages for hours. An action block (version 2) is harder,
 * lives 30 minutes and works once. Whether it is spent is decided in the database with one
 * atomic insert, so parallel requests and an evicted object cache cannot spend it twice. Only
 * valid proof of work gets that far, so junk requests cause no database writes.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single-use blocks and the limit per network.
 */
final class Powned_Tickets {

	const COOKIE      = 'powned_action';
	const TTL         = 1800;
	const USED_PREFIX = 'powned_used_';
	const RATE_PREFIX = 'powned_rate_';

	/**
	 * Difficulty of action blocks. Load does not raise it: a customer at the checkout should
	 * not wait longer because bots keep the server busy.
	 */
	public static function bits() {
		$s = Powned::settings();
		return min( 28, (int) $s['base_bits'] + max( 0, (int) $s['action_extra'] ) );
	}

	/**
	 * New signed challenge for one action.
	 *
	 * @return array{challenge:string,mac:string,bits:int}
	 */
	public static function challenge() {
		$bits      = self::bits();
		$challenge = '2.' . ( time() + self::TTL ) . '.' . Powned::client_key() . '.' . $bits . '.' . bin2hex( random_bytes( 6 ) ) . '.';
		return array(
			'challenge' => $challenge,
			'mac'       => Powned::mac( $challenge ),
			'bits'      => $bits,
		);
	}

	/**
	 * The action block of this request, valid and not spent.
	 *
	 * @return array|null
	 */
	public static function current() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $raw ) {
			return null;
		}
		$parts = explode( '_', $raw, 2 );
		$block = Powned::check_block( $parts[0] );
		if ( ! $block || 2 !== $block['version'] || $block['bits'] < self::bits() ) {
			return null;
		}
		global $wpdb;
		$spent = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", self::USED_PREFIX . $block['salt'] ) ); // phpcs:ignore WordPress.DB
		if ( $spent ) {
			return null;
		}
		$block['ms'] = isset( $parts[1] ) ? (int) $parts[1] : 0;
		return $block;
	}

	/**
	 * Could this request do the action? Spends nothing.
	 *
	 * @param string $bucket order, coupon, login, reset or form.
	 * @return string '' if yes, otherwise 'block' or 'limit'.
	 */
	public static function check( $bucket ) {
		if ( self::limited( $bucket ) ) {
			return 'limit';
		}
		if ( ! self::current() ) {
			self::forget();
			return 'block';
		}
		return '';
	}

	/**
	 * Spend the block for one action.
	 *
	 * @param string $bucket order, coupon, login, reset or form.
	 * @return string '' if spent, otherwise 'block' or 'limit'.
	 */
	public static function spend( $bucket ) {
		if ( self::limited( $bucket ) ) {
			return 'limit';
		}
		$block = self::current();
		self::forget(); // Spent or useless either way: the browser mines a new one.
		if ( ! $block ) {
			return 'block';
		}
		global $wpdb;
		$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::USED_PREFIX . $block['salt'], (string) $block['expiry'] ) ); // phpcs:ignore WordPress.DB
		if ( 1 !== (int) $won ) {
			return 'block';
		}
		Powned::count_block( $block, $block['ms'] );
		$max = self::limit();
		if ( $max ) {
			$name = self::rate_name( $bucket );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST( option_value AS UNSIGNED ) + 1", $name ) ); // phpcs:ignore WordPress.DB
			// Parallel requests that all passed the first look: count again after the increment.
			if ( self::used( $name ) > $max ) {
				return 'limit';
			}
		}
		return '';
	}

	/**
	 * Actions per network and hour, 0 = no limit.
	 */
	private static function limit() {
		return max( 0, (int) Powned::settings()['action_limit'] );
	}

	/**
	 * Limit reached?
	 *
	 * @param string $bucket Kind of action.
	 */
	private static function limited( $bucket ) {
		$max = self::limit();
		return $max && self::used( self::rate_name( $bucket ) ) >= $max;
	}

	/**
	 * Counter value, read directly (it changes outside the options cache).
	 *
	 * @param string $name Option name.
	 */
	private static function used( $name ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Counter of a network and hour. Always the network, whatever blocks are bound to. Only a
	 * keyed hash is stored, for at most two hours.
	 *
	 * @param string $bucket Kind of action.
	 */
	private static function rate_name( $bucket ) {
		$net = substr( hash_hmac( 'sha256', 'rate|' . $bucket . '|' . Powned::network(), Powned::secret() ), 0, 16 );
		return self::RATE_PREFIX . gmdate( 'YmdH' ) . '_' . $net;
	}

	/**
	 * Delete the cookie, here and in the browser.
	 */
	private static function forget() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return;
		}
		unset( $_COOKIE[ self::COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', time() - 3600, '/' );
		}
	}

	/**
	 * Hourly: remove expired markers and counters of past hours.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST( option_value AS UNSIGNED ) < %d", $wpdb->esc_like( self::USED_PREFIX ) . '%', time() ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s", $wpdb->esc_like( self::RATE_PREFIX ) . '%', self::RATE_PREFIX . gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}
}

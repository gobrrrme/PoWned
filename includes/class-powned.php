<?php
/**
 * Core: which requests are protected, cookie check, difficulty, challenge page.
 *
 * Everything here works before plugins and pluggable functions are loaded, so the check can
 * also run from the early loader in mu-plugins (see Powned_Loader).
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'POWNED_FILE' ) ) {
	define( 'POWNED_FILE', dirname( __DIR__ ) . '/powned.php' );
}
if ( ! defined( 'POWNED_VERSION' ) ) {
	define( 'POWNED_VERSION', '0.5.0' );
}
require_once __DIR__ . '/class-powned-tickets.php';

/**
 * Proof-of-work gate.
 */
final class Powned {

	const OPTION        = 'powned_settings';
	const SECRET_OPTION = 'powned_secret';
	const COOKIE        = 'powned';
	const NEW_COOKIE    = 'powned_new';
	const STATS_GROUP       = 'powned';
	const BLOCKS_OPTION     = 'powned_blocks';
	const LAST_BLOCK_OPTION = 'powned_last_block';
	const STATS_OPTION      = 'powned_stats';
	const STATS_DAYS        = 30;
	const ROLLUP_HOOK       = 'powned_rollup';

	/**
	 * Default settings. The query parameters cover WooCommerce's layered navigation.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'early'          => 1,
			'checkout'       => 0,
			'guard_coupon'   => 0,
			'guard_login'    => 0,
			'guard_forms'    => 0,
			'guard_reset'    => 0,
			'action_extra'   => 2,
			'action_limit'   => 10,
			'stealth'        => 0,
			'lockdown'       => 'off',
			'lockdown_hours' => 6,
			'lockdown_since' => 0,
			'lockdown_load'  => 2.0,
			'lockdown_crawlers' => 1,
			'lockdown_agents'   => '',
			'params'         => "filter_*\nquery_type_*\nmin_price\nmax_price\nrating_filter\ninstock\non_sale",
			'paths'          => '',
			'exclude'        => "/wp-admin/*\n/wp-login.php\n/wp-json/*\n/wp-cron.php\n/xmlrpc.php",
			'base_bits'      => 18,
			'adjust'         => 1,
			'max_extra'      => 4,
			'load_threshold' => 0.75,
			'ttl_hours'      => 24,
			'binding'        => 'network',
			'status'         => 403,
			'lang_prefixes'  => '',
			'brand_color'    => '#f7931a',
			'logo_url'       => '',
			'title'          => '',
			'text'           => '',
		);
	}

	/**
	 * Settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		static $s = null;
		if ( null === $s ) {
			$s = array_merge( self::defaults(), (array) get_option( self::OPTION, array() ) );
		}
		return $s;
	}

	/**
	 * Run the gate for the current request: let it through, or send the challenge and exit.
	 */
	public static function maybe_challenge() {
		if ( defined( 'POWNED_CHECKED' ) ) {
			return;
		}
		define( 'POWNED_CHECKED', true );
		$s = self::settings();
		if ( empty( $s['enabled'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( isset( $_GET['powned_challenge'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::send_challenge_json();
		}
		if ( ! self::is_protected() ) {
			// A freshly mined block (background miner): take it over on the next request that
			// reaches PHP, so it becomes HttpOnly and is counted.
			if ( ! empty( $_COOKIE[ self::NEW_COOKIE ] ) ) {
				self::valid_cookie();
			}
			return;
		}
		if ( self::valid_cookie() ) {
			self::count( 'passed' );
			return;
		}
		self::count( 'issued' );
		self::render_challenge();
	}

	/**
	 * Does the current request match the protected parameters or paths (and no exclusion)?
	 */
	public static function is_protected() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}
		$s    = self::settings();
		$path = self::request_path();
		foreach ( self::lines( $s['exclude'] ) as $pattern ) {
			if ( self::wildcard( $pattern, $path ) ) {
				return false;
			}
		}
		foreach ( self::lines( $s['paths'] ) as $pattern ) {
			if ( self::wildcard( $pattern, $path ) ) {
				return true;
			}
		}
		if ( ! empty( $s['checkout'] ) && self::is_checkout_path( $path ) ) {
			return true;
		}
		$params = self::lines( $s['params'] );
		if ( $params ) {
			foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification
				foreach ( $params as $pattern ) {
					if ( self::wildcard( $pattern, (string) $key ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Slug of the WooCommerce checkout page (read directly, also works before plugins load).
	 */
	public static function checkout_slug() {
		static $slug = null;
		if ( null === $slug ) {
			global $wpdb;
			$id   = (int) get_option( 'woocommerce_checkout_page_id' );
			$slug = $id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_name FROM {$wpdb->posts} WHERE ID = %d", $id ) ) : ''; // phpcs:ignore WordPress.DB
		}
		return $slug;
	}

	/**
	 * Is this the checkout page (also with a language prefix like /de/checkout/ and endpoints
	 * like order-pay)? The thank-you page after payment is left alone.
	 *
	 * @param string $path Request path.
	 */
	public static function is_checkout_path( $path ) {
		$slug = self::checkout_slug();
		if ( '' === $slug || ! preg_match( '#(^|/)' . preg_quote( $slug, '#' ) . '(/|$)#i', $path ) ) {
			return false;
		}
		return ! preg_match( '#/order-received(/|$)#i', $path );
	}

	/**
	 * Checkout protection, order submission: the classic checkout (form and wc-ajax) only takes
	 * an order with a valid solved block. Hooked to woocommerce_checkout_process.
	 */
	public static function guard_checkout_submit() {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['checkout'] ) ) {
			return;
		}
		// Only looked at here, so a typo does not cost a block. Spent when the order is created.
		Powned_Actions::$in_checkout = true;
		$why = Powned_Actions::check( 'order' );
		if ( '' !== $why ) {
			wc_add_notice( Powned_Actions::checkout_message( $why ), 'error' );
		}
	}

	/**
	 * Checkout protection, Store API (block checkout, headless): placing an order needs a valid
	 * solved block. The browser has it from opening the checkout page.
	 *
	 * @param mixed           $result  Result so far.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function guard_store_api( $result, $server, $request ) {
		// All methods: a GET on the checkout route already creates a draft order.
		$s = self::settings();
		if ( empty( $s['enabled'] ) || is_wp_error( $result ) ) {
			return $result;
		}
		// Routes match case-insensitively in WordPress, so here too.
		$route = strtolower( (string) $request->get_route() );
		$post  = 'POST' === strtoupper( (string) $request->get_method() );
		if ( ! empty( $s['checkout'] ) && preg_match( '#^/wc/store(/v\d+)?/checkout(/|$)#', $route ) ) {
			// POST places the order and spends the block; drafts and field updates only need one.
			$why = $post ? Powned_Actions::spend( 'order' ) : Powned_Actions::check( 'order' );
		} elseif ( ! empty( $s['checkout'] ) && preg_match( '#^/wc/store(/v\d+)?/batch(/|$)#', $route ) ) {
			$why = Powned_Actions::check( 'order' ); // Each request inside comes through here again.
		} elseif ( $post && ! empty( $s['guard_coupon'] ) && preg_match( '#^/wc/store(/v\d+)?/cart/(apply-coupon|coupons)(/|$)#', $route ) ) {
			$why = Powned_Actions::spend( 'coupon' );
		} else {
			return $result;
		}
		if ( '' === $why ) {
			return $result;
		}
		return new WP_Error( 'powned_required', Powned_Actions::message( $why ), array( 'status' => 'limit' === $why ? 429 : 403 ) );
	}

	/**
	 * Path of the request without query string.
	 */
	public static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) strtok( $uri, '?' );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Non-empty trimmed lines of a textarea setting.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $text ) ) ) );
	}

	/**
	 * Match with * as wildcard, case-insensitive, whole string.
	 *
	 * @param string $pattern Pattern.
	 * @param string $subject Subject.
	 */
	public static function wildcard( $pattern, $subject ) {
		$re = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
		return (bool) preg_match( $re, $subject );
	}

	/**
	 * Signing secret (created on activation, or on first use).
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! $secret ) {
			$secret = bin2hex( random_bytes( 32 ) );
			add_option( self::SECRET_OPTION, $secret, '', true );
		}
		return (string) $secret;
	}

	/**
	 * Keyed hash of what the solved block is bound to: the network (/24, /64), the exact IP,
	 * or nothing. The cookie never contains the IP itself.
	 */
	public static function client_key() {
		$binding = self::settings()['binding'];
		$ip      = self::client_ip();
		$bin     = 'none' === $binding ? false : @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			$net = 'any';
		} elseif ( 'ip' === $binding ) {
			$net = bin2hex( $bin );
		} else {
			$net = self::network();
		}
		return substr( hash_hmac( 'sha256', 'client|' . $binding . '|' . $net, self::secret() ), 0, 12 );
	}

	/**
	 * Network of the visitor: /24 for IPv4, /64 for IPv6.
	 */
	public static function network() {
		$bin = @inet_pton( self::client_ip() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false === $bin ? 'any' : bin2hex( substr( $bin, 0, 4 === strlen( $bin ) ? 3 : 8 ) );
	}

	/**
	 * Address of the visitor. REMOTE_ADDR by default. Behind your own reverse proxy, return the
	 * real client address with the filter powned_client_ip (from a mu-plugin that loads before
	 * powned-early.php, e.g. 00-powned-proxy.php). Never trust a forwarded header without a
	 * proxy in front that sets it, anyone can send one.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( function_exists( 'apply_filters' ) ) {
			$ip = (string) apply_filters( 'powned_client_ip', $ip );
		}
		return $ip;
	}

	/**
	 * CPU cores (Linux), cached per request.
	 */
	private static function cores() {
		static $cores = null;
		if ( null === $cores ) {
			$info  = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
			$cores = $info ? max( 1, preg_match_all( '/^processor\s*:/m', $info ) ) : 1;
		}
		return $cores;
	}

	/**
	 * Current difficulty: base bits plus extra bits under load. One extra bit when the load
	 * per core reaches the threshold, one more each time it doubles.
	 *
	 * @return array{bits:int,extra:int,load:float}
	 */
	public static function difficulty() {
		$s     = self::settings();
		$load  = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[0] ?? 0 ) : 0.0;
		$ratio = $load / self::cores();
		$th    = max( 0.1, (float) $s['load_threshold'] );
		$extra = 0;
		if ( ! empty( $s['adjust'] ) && $ratio >= $th ) {
			$extra = min( (int) $s['max_extra'], 1 + (int) floor( log( $ratio / $th, 2 ) ) );
		}
		return array(
			'bits'  => (int) $s['base_bits'] + $extra,
			'extra' => $extra,
			'load'  => $ratio,
		);
	}

	/**
	 * Leading zero bits of a binary string.
	 *
	 * @param string $bin Raw bytes.
	 */
	public static function zero_bits( $bin ) {
		$bits = 0;
		$len  = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$byte = ord( $bin[ $i ] );
			if ( 0 === $byte ) {
				$bits += 8;
				continue;
			}
			for ( $m = 0x80; $m && ! ( $byte & $m ); $m >>= 1 ) {
				$bits++;
			}
			break;
		}
		return $bits;
	}

	/**
	 * Double SHA-256, as in Bitcoin.
	 *
	 * @param string $data Data.
	 */
	public static function hash256( $data ) {
		return hash( 'sha256', hash( 'sha256', $data, true ), true );
	}

	/**
	 * Signature of a challenge.
	 *
	 * @param string $challenge "1.<expiry>.<client>.<bits>.<salt>."
	 */
	public static function mac( $challenge ) {
		return substr( hash_hmac( 'sha256', 'challenge|' . $challenge, self::secret() ), 0, 16 );
	}

	/**
	 * New signed challenge. Short enough that challenge + nonce fits one SHA-256 block.
	 *
	 * @return array{challenge:string,mac:string,bits:int,extra:int}
	 */
	public static function new_challenge() {
		$d         = self::difficulty();
		$expiry    = time() + max( 1, (int) self::settings()['ttl_hours'] ) * HOUR_IN_SECONDS;
		$challenge = '1.' . $expiry . '.' . self::client_key() . '.' . $d['bits'] . '.' . bin2hex( random_bytes( 6 ) ) . '.';
		return array(
			'challenge' => $challenge,
			'mac'       => self::mac( $challenge ),
			'bits'      => $d['bits'],
			'extra'     => $d['extra'],
		);
	}

	/**
	 * The solved block, if it is valid for this client.
	 *
	 * The browser stores a freshly solved block in "<COOKIE>_new" (with the mining time). The
	 * server takes it over: it sets the block as HttpOnly cookie "<COOKIE>", so scripts on the
	 * page cannot read it later, counts it once and deletes the fresh cookie.
	 *
	 * @return array{expiry:int,salt:string}|null
	 */
	public static function valid_cookie() {
		$fresh = isset( $_COOKIE[ self::NEW_COOKIE ] ) ? (string) $_COOKIE[ self::NEW_COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $fresh ) {
			unset( $_COOKIE[ self::NEW_COOKIE ] );
			$parts = explode( '_', $fresh, 2 );
			$block = self::check_block( $parts[0] );
			if ( ! headers_sent() ) {
				setcookie( self::NEW_COOKIE, '', time() - 3600, '/' );
			}
			if ( $block ) {
				$_COOKIE[ self::COOKIE ] = $parts[0];
				if ( ! headers_sent() ) {
					setcookie(
						self::COOKIE,
						$parts[0],
						array(
							'expires'  => $block['expiry'],
							'path'     => '/',
							'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
							'httponly' => true,
							'samesite' => 'Lax',
						)
					);
				}
				self::count_block( $block, isset( $parts[1] ) ? (int) $parts[1] : 0 );
				return $block;
			}
		}
		$raw   = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$block = self::check_block( $raw );
		if ( $block || empty( $_COOKIE[ Powned_Tickets::COOKIE ] ) ) {
			return $block;
		}
		// Whoever mined an action block (cart, product page) may browse with it: no second
		// challenge when the checkout opens. This does not spend it.
		$raw   = explode( '_', (string) $_COOKIE[ Powned_Tickets::COOKIE ], 2 )[0]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$block = self::check_block( $raw );
		if ( $block ) {
			$_COOKIE[ self::COOKIE ] = $raw;
			if ( ! headers_sent() ) {
				setcookie(
					self::COOKIE,
					$raw,
					array(
						'expires'  => $block['expiry'],
						'path'     => '/',
						'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}
		return $block;
	}

	/**
	 * Check a solved block. Format: <version>.<expiry>.<client>.<bits>.<salt>.<nonce>.<mac>
	 * Version 1 is a browsing block, version 2 an action block. The signature covers the version.
	 *
	 * @param string $raw Block.
	 * @return array{expiry:int,salt:string,version:int,bits:int,solved:int}|null
	 */
	public static function check_block( $raw ) {
		if ( ! preg_match( '/^([12])\.(\d{10})\.([0-9a-f]{12})\.(\d{1,2})\.([0-9a-f]{12})\.(\d{1,12})\.([0-9a-f]{16})$/', $raw, $m ) ) {
			return null;
		}
		list( , $version, $expiry, $client, $bits, $salt, $nonce, $mac ) = $m;
		$s   = self::settings();
		$now = time();
		$ttl = '2' === $version ? Powned_Tickets::TTL : max( 1, (int) $s['ttl_hours'] ) * HOUR_IN_SECONDS;
		if ( (int) $expiry < $now || (int) $expiry > $now + $ttl + 600 || (int) $bits < (int) $s['base_bits'] ) {
			return null;
		}
		if ( ! hash_equals( self::client_key(), $client ) ) {
			return null;
		}
		$challenge = "$version.$expiry.$client.$bits.$salt.";
		if ( ! hash_equals( self::mac( $challenge ), $mac ) ) {
			return null;
		}
		if ( self::zero_bits( self::hash256( $challenge . $nonce ) ) < (int) $bits ) {
			return null;
		}
		return array(
			'expiry'  => (int) $expiry,
			'salt'    => $salt,
			'version' => (int) $version,
			'bits'    => (int) $bits,
			'solved'  => (int) $expiry - $ttl,
		);
	}

	/**
	 * Count a freshly mined block once. Each block counts at most once (remembered by its random
	 * salt for 15 minutes) and only while it is fresh, so resending it can neither inflate the
	 * counter nor cause database writes.
	 *
	 * @param array{expiry:int,salt:string} $block Valid block.
	 * @param int                           $ms    Mining time measured by the browser.
	 */
	public static function count_block( $block, $ms ) {
		// Action blocks are counted when they are spent, up to their whole lifetime later.
		$fresh = 2 === $block['version'] ? Powned_Tickets::TTL : 15 * MINUTE_IN_SECONDS;
		if ( $block['solved'] < time() - $fresh ) {
			return;
		}
		$key = 'block_' . $block['salt'];
		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $key, 1, self::STATS_GROUP, 15 * MINUTE_IN_SECONDS ) ) {
				return;
			}
		} elseif ( get_transient( 'powned_' . $key ) ) {
			return;
		} else {
			set_transient( 'powned_' . $key, 1, 15 * MINUTE_IN_SECONDS );
		}
		$timed = $ms > 1 && $ms < 10 * MINUTE_IN_SECONDS * 1000;
		// With a persistent object cache: count in the cache only, the hourly rollup writes the
		// totals. A flood of solved blocks then causes no database writes at all.
		if ( wp_using_ext_object_cache() ) {
			self::count( 'blocks' );
			if ( $timed ) {
				self::count( 'timed' );
				self::count( 'ms', $ms );
			}
			if ( false === wp_cache_incr( 'blocks_pending', 1, self::STATS_GROUP ) ) {
				wp_cache_set( 'blocks_pending', 1, self::STATS_GROUP, 3 * DAY_IN_SECONDS );
			}
			wp_cache_set( 'last_block', time(), self::STATS_GROUP, 3 * DAY_IN_SECONDS );
			return;
		}
		self::add_blocks( 1 );
		update_option( self::LAST_BLOCK_OPTION, time(), false );
		self::record_block( $timed ? $ms : 0 );
	}

	/**
	 * Add to the total block counter in one query (CAST keeps it explicit under strict SQL modes).
	 *
	 * @param int $n Blocks.
	 */
	private static function add_blocks( $n ) {
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST( option_value AS UNSIGNED ) + %d WHERE option_name = %s", $n, self::BLOCKS_OPTION ) ); // phpcs:ignore WordPress.DB
		if ( ! $updated ) {
			add_option( self::BLOCKS_OPTION, $n, '', false );
		}
		wp_cache_delete( self::BLOCKS_OPTION, 'options' );
	}

	/**
	 * Daily statistics, last STATS_DAYS days (UTC). Only counts per day, nothing per visitor.
	 * Keys: Ymd => issued (challenge pages), passed (requests with a solved block), blocks
	 * (blocks mined), timed (blocks with a measured mining time), ms (sum of mining times).
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function stats_raw() {
		$stats = get_option( self::STATS_OPTION, array() );
		return is_array( $stats ) ? $stats : array();
	}

	/**
	 * Save daily statistics, keeping only the last STATS_DAYS days.
	 *
	 * @param array $stats Stats.
	 */
	private static function stats_save( $stats ) {
		krsort( $stats );
		update_option( self::STATS_OPTION, array_slice( $stats, 0, self::STATS_DAYS + 1, true ), false );
	}

	/**
	 * Add a mined block to today's statistics. Blocks are rare, so this database write only
	 * happens when someone actually solved a challenge.
	 *
	 * @param int $ms Mining time in milliseconds (0 or 1 = unknown).
	 */
	public static function record_block( $ms ) {
		$day           = gmdate( 'Ymd' );
		$stats         = self::stats_raw();
		$row           = $stats[ $day ] ?? array();
		$row['blocks'] = (int) ( $row['blocks'] ?? 0 ) + 1;
		if ( $ms > 1 && $ms < 10 * MINUTE_IN_SECONDS * 1000 ) {
			$row['timed'] = (int) ( $row['timed'] ?? 0 ) + 1;
			$row['ms']    = (int) ( $row['ms'] ?? 0 ) + $ms;
		}
		$stats[ $day ] = $row;
		self::stats_save( $stats );
	}

	/**
	 * Copy the per-day counters for challenges and let-through requests from the persistent
	 * object cache into the statistics (hourly by WP-Cron, and when the settings page opens).
	 * Values only ever go up, so an evicted cache key cannot erase a day.
	 */
	public static function rollup() {
		Powned_Tickets::cleanup();
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}
		$stats   = self::stats_raw();
		$changed = false;
		foreach ( array( gmdate( 'Ymd' ), gmdate( 'Ymd', time() - DAY_IN_SECONDS ) ) as $day ) {
			foreach ( array( 'issued', 'passed', 'blocks', 'timed', 'ms' ) as $what ) {
				$value = (int) wp_cache_get( $what . '_' . $day, self::STATS_GROUP );
				if ( $value > (int) ( $stats[ $day ][ $what ] ?? 0 ) ) {
					$stats[ $day ][ $what ] = $value;
					$changed                = true;
				}
			}
		}
		if ( $changed ) {
			self::stats_save( $stats );
		}
		// Blocks counted in the cache since the last rollup: add them to the total.
		$pending = (int) wp_cache_get( 'blocks_pending', self::STATS_GROUP );
		if ( $pending > 0 ) {
			wp_cache_decr( 'blocks_pending', $pending, self::STATS_GROUP );
			self::add_blocks( $pending );
		}
		$last = (int) wp_cache_get( 'last_block', self::STATS_GROUP );
		if ( $last > (int) get_option( self::LAST_BLOCK_OPTION, 0 ) ) {
			update_option( self::LAST_BLOCK_OPTION, $last, false );
		}
	}

	/**
	 * The last STATS_DAYS days, oldest first, with zeros for days without data.
	 *
	 * @return array<string,array{issued:int,passed:int,blocks:int,timed:int,ms:int}>
	 */
	public static function stats() {
		$raw = self::stats_raw();
		$out = array();
		for ( $i = self::STATS_DAYS - 1; $i >= 0; $i-- ) {
			$day         = gmdate( 'Ymd', time() - $i * DAY_IN_SECONDS );
			$row         = $raw[ $day ] ?? array();
			$out[ $day ] = array(
				'issued' => (int) ( $row['issued'] ?? 0 ),
				'passed' => (int) ( $row['passed'] ?? 0 ),
				'blocks' => (int) ( $row['blocks'] ?? 0 ),
				'timed'  => (int) ( $row['timed'] ?? 0 ),
				'ms'     => (int) ( $row['ms'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Number of blocks mined so far (read directly, the counter changes outside the cache).
	 */
	public static function blocks() {
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::BLOCKS_OPTION ) ); // phpcs:ignore WordPress.DB
		// Plus the blocks still waiting in the object cache for the hourly rollup.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			$total += max( 0, (int) wp_cache_get( 'blocks_pending', self::STATS_GROUP ) );
		}
		return $total;
	}

	/**
	 * Count an event per day (optionally by more than one) in a persistent object cache (Redis, Memcached). Without one,
	 * nothing is stored: no database writes on every request.
	 *
	 * @param string $what issued|passed.
	 */
	public static function count( $what, $by = 1 ) {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}
		$key = $what . '_' . gmdate( 'Ymd' );
		if ( false === wp_cache_incr( $key, (int) $by, self::STATS_GROUP ) ) {
			wp_cache_set( $key, (int) $by, self::STATS_GROUP, 3 * DAY_IN_SECONDS );
		}
	}

	/**
	 * Language of the challenge page: from the path prefixes setting ("/de/=de"), otherwise
	 * the site language.
	 */
	public static function language() {
		$path = self::request_path();
		foreach ( self::lines( self::settings()['lang_prefixes'] ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( 2 === count( $parts ) && '' !== $parts[0] && 0 === stripos( $path, $parts[0] ) ) {
				return strtolower( substr( $parts[1], 0, 2 ) );
			}
		}
		return strtolower( substr( (string) get_locale(), 0, 2 ) );
	}

	/**
	 * Texts of the challenge page. English and German built in, so it also works before
	 * translations are loaded. Filter: powned_strings.
	 *
	 * @param string $lang Two-letter language.
	 * @return array<string,string>
	 */
	public static function strings( $lang ) {
		$all = array(
			'en' => array(
				'title'   => 'Mining requested page',
				'block'   => 'Block #%s',
				'text'    => 'Proof of work against bots: double SHA-256, like Bitcoin.',
				'diff'    => 'Difficulty',
				'adj'     => 'adjusted to server load',
				'nonce'   => 'Nonce',
				'rate'    => 'Hashrate',
				'hash'    => 'Hash',
				'mining'  => 'Mining …',
				'found'   => 'Block found',
				'nocook'  => 'Your browser did not accept the cookie. Please allow cookies for this site and reload.',
				'nojs'    => 'This page needs JavaScript.',
				'back'    => 'Go back',
				'cookie'  => 'Cookie %1$s, %2$d h, strictly necessary.',
				'checkout' => 'The anti-bot check for this order had expired and is being renewed. If the order is not placed automatically in a few seconds, please place it again.',
				'action'   => 'The anti-bot check is missing or has expired. Please reload the page and try again (JavaScript needed).',
				'loading'  => 'Loading …',
				'limit'    => 'Too many attempts from your network in the last hour. Please try again later.',
			),
			'de' => array(
				'title'   => 'Angefragte Seite wird gemined',
				'block'   => 'Block #%s',
				'text'    => 'Proof of Work gegen Bots: doppeltes SHA-256, wie bei Bitcoin.',
				'diff'    => 'Difficulty',
				'adj'     => 'angepasst an Serverlast',
				'nonce'   => 'Nonce',
				'rate'    => 'Hashrate',
				'hash'    => 'Hash',
				'mining'  => 'Mining …',
				'found'   => 'Block gefunden',
				'nocook'  => 'Dein Browser hat das Cookie nicht angenommen. Bitte erlaube Cookies für diese Seite und lade neu.',
				'nojs'    => 'Diese Seite benötigt JavaScript.',
				'back'    => 'Zurück',
				'cookie'  => 'Cookie %1$s, %2$d h, technisch notwendig.',
				'checkout' => 'Die Prüfung gegen Bots für diese Bestellung war abgelaufen und wird erneuert. Falls die Bestellung nicht in ein paar Sekunden automatisch abgeschickt wird, schicke sie bitte noch einmal ab.',
				'action'   => 'Die Prüfung gegen Bots fehlt oder ist abgelaufen. Bitte lade die Seite neu und versuche es noch einmal (JavaScript nötig).',
				'loading'  => 'Wird geladen …',
				'limit'    => 'Zu viele Versuche aus deinem Netz in der letzten Stunde. Bitte versuche es später noch einmal.',
			),
		);
		$t = $all[ $lang ] ?? $all['en'];
		$s = self::settings();
		if ( '' !== trim( (string) $s['title'] ) ) {
			$t['title'] = (string) $s['title'];
		}
		if ( '' !== trim( (string) $s['text'] ) ) {
			$t['text'] = (string) $s['text'];
		}
		return function_exists( 'apply_filters' ) ? (array) apply_filters( 'powned_strings', $t, $lang ) : $t;
	}

	/**
	 * Challenge for the background miner, as JSON. Stateless and cheap (one HMAC), never cached.
	 */
	public static function send_challenge_json() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		$action = ! empty( $_GET['action'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$fresh  = ! empty( $_GET['fresh'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $action ) {
			$have = $fresh ? null : Powned_Tickets::current();
			// Nearly expired ones are replaced, so they last until the form is sent.
			if ( $have && $have['expiry'] > time() + 180 ) {
				echo wp_json_encode( array( 'valid' => true, 'left' => $have['expiry'] - time() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				exit;
			}
			$c   = Powned_Tickets::challenge();
			$ttl = Powned_Tickets::TTL;
		} else {
			// Already holding a valid block (HttpOnly, so the page cannot see it): nothing to mine.
			if ( ! $fresh && self::valid_cookie() ) {
				echo '{"valid":true}';
				exit;
			}
			$c   = self::new_challenge();
			$ttl = (int) self::settings()['ttl_hours'] * HOUR_IN_SECONDS;
		}
		self::count( 'issued' );
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'challenge' => $c['challenge'],
				'mac'       => $c['mac'],
				'bits'      => $c['bits'],
				'ttl'       => $ttl,
				'secure'    => is_ssl(),
			)
		);
		exit;
	}

	/**
	 * Content-Security-Policy of the challenge page: no inline script or style, nothing loaded
	 * from elsewhere. Plugin assets on another origin (CDN or static domain) and a logo from
	 * another origin are added automatically. Filter: powned_csp (array of directives).
	 *
	 * @param string $base Asset URL.
	 * @param string $logo Logo URL.
	 */
	public static function challenge_csp( $base, $logo ) {
		$origin = static function ( $url ) {
			$p = wp_parse_url( (string) $url );
			if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
				return '';
			}
			return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
		};
		$self   = $origin( home_url( '/' ) );
		$assets = $origin( $base );
		$extra  = ( '' !== $assets && $assets !== $self ) ? ' ' . $assets : '';
		$img    = "'self' https: data:";
		$logo_o = $origin( $logo );
		if ( '' !== $logo_o && $logo_o !== $self && 0 === strpos( $logo_o, 'http:' ) ) {
			$img .= ' ' . $logo_o;
		}
		$csp = array(
			'default-src'     => "'none'",
			'script-src'      => "'self'" . $extra,
			'style-src'       => "'self'" . $extra,
			'img-src'         => $img,
			// Browsers only start workers from the page's own origin; with assets on a CDN the
			// miner falls back to mining on the page.
			'worker-src'      => "'self'" . $extra,
			'connect-src'     => "'none'",
			'base-uri'        => "'none'",
			'form-action'     => "'none'",
			'frame-ancestors' => "'none'",
		);
		if ( function_exists( 'apply_filters' ) ) {
			$csp = (array) apply_filters( 'powned_csp', $csp, $base, $logo );
		}
		$out = array();
		foreach ( $csp as $directive => $value ) {
			$out[] = trim( $directive . ' ' . $value );
		}
		return implode( '; ', $out );
	}

	/**
	 * Send the challenge page and stop.
	 *
	 * @param bool     $preview Admin preview (status 200, nothing counted).
	 * @param int|null $status  HTTP status instead of the configured one (lockdown: 503).
	 */
	public static function render_challenge( $preview = false, $status = null ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		$s      = self::settings();
		$c      = self::new_challenge();
		$lang   = self::language();
		$t      = self::strings( $lang );
		$height = self::blocks() + 1;
		$ver    = POWNED_VERSION;
		$base   = plugins_url( 'assets/', POWNED_FILE );
		$logo   = '' !== (string) $s['logo_url'] ? (string) $s['logo_url'] : ( function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 96 ) : '' );
		// "Go back" only to this site, never to a foreign referrer.
		$back       = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$back_parts = wp_parse_url( $back );
		// Same site AND http(s) only: a javascript: or data: URL whose host happens to match
		// would otherwise land in the "Go back" link.
		if ( '' === $back || ! is_array( $back_parts ) || ! isset( $back_parts['scheme'] ) || ! in_array( strtolower( $back_parts['scheme'] ), array( 'http', 'https' ), true )
			|| ( $back_parts['host'] ?? null ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			$back = home_url( '/' );
		}
		$data = array(
			'challenge' => $c['challenge'],
			'mac'       => $c['mac'],
			'bits'      => $c['bits'],
			'cookie'    => self::COOKIE,
			'ttl'       => (int) $s['ttl_hours'] * HOUR_IN_SECONDS,
			'secure'    => is_ssl() ? 1 : 0,
			'worker'    => $base . 'pw-core.js?ver=' . $ver,
			'brand'     => preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $s['brand_color'] ) ? (string) $s['brand_color'] : '#f7931a',
			'found'     => $t['found'],
			'nocookie'  => $t['nocook'],
			'preview'   => $preview ? 1 : 0,
		);
		if ( ! headers_sent() ) {
			$code = $preview ? 200 : max( 200, min( 599, (int) ( $status ?? $s['status'] ) ) );
			http_response_code( $code );
			if ( 503 === $code || 429 === $code ) {
				header( 'Retry-After: ' . ( class_exists( 'Powned_Lockdown' ) ? Powned_Lockdown::RETRY_AFTER : 600 ) );
			}
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Content-Security-Policy: ' . self::challenge_csp( $base, $logo ) );
		}
		include dirname( POWNED_FILE ) . '/templates/challenge.php';
		exit;
	}
}

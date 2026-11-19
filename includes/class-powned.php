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
	define( 'POWNED_VERSION', '0.1.0' );
}

/**
 * Proof-of-work gate.
 */
final class Powned {

	const OPTION        = 'powned_settings';
	const SECRET_OPTION = 'powned_secret';
	const COOKIE        = 'powned';
	const STATS_GROUP       = 'powned';
	const BLOCKS_OPTION     = 'powned_blocks';
	const LAST_BLOCK_OPTION = 'powned_last_block';

	/**
	 * Default settings. The query parameters cover WooCommerce's layered navigation.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'early'          => 1,
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
		if ( empty( $s['enabled'] ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ! self::is_protected() ) {
			return;
		}
		$block = self::valid_cookie();
		if ( $block ) {
			self::count( 'passed' );
			self::maybe_count_block( $block );
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
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$bin     = 'none' === $binding ? false : @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $bin ) {
			$net = 'any';
		} elseif ( 'ip' === $binding ) {
			$net = bin2hex( $bin );
		} else {
			$net = bin2hex( substr( $bin, 0, 4 === strlen( $bin ) ? 3 : 8 ) );
		}
		return substr( hash_hmac( 'sha256', 'client|' . $binding . '|' . $net, self::secret() ), 0, 12 );
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
	 * The solved block from the cookie, if it is valid for this client.
	 * Format: 1.<expiry>.<client>.<bits>.<salt>.<nonce>.<mac>
	 *
	 * @return array{expiry:int,salt:string}|null
	 */
	public static function valid_cookie() {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '/^1\.(\d{10})\.([0-9a-f]{12})\.(\d{1,2})\.([0-9a-f]{12})\.(\d{1,12})\.([0-9a-f]{16})$/', $raw, $m ) ) {
			return null;
		}
		list( , $expiry, $client, $bits, $salt, $nonce, $mac ) = $m;
		$s   = self::settings();
		$now = time();
		if ( (int) $expiry < $now || (int) $expiry > $now + (int) $s['ttl_hours'] * HOUR_IN_SECONDS + 600 || (int) $bits < (int) $s['base_bits'] ) {
			return null;
		}
		if ( ! hash_equals( self::client_key(), $client ) ) {
			return null;
		}
		$challenge = "1.$expiry.$client.$bits.$salt.";
		if ( ! hash_equals( self::mac( $challenge ), $mac ) ) {
			return null;
		}
		if ( self::zero_bits( self::hash256( $challenge . $nonce ) ) < (int) $bits ) {
			return null;
		}
		return array(
			'expiry' => (int) $expiry,
			'salt'   => $salt,
		);
	}

	/**
	 * Count a freshly mined block once. The miner sets the short-lived cookie
	 * "<COOKIE>_new" next to the solved block; the server deletes it again. Each block counts
	 * at most once (remembered by its random salt for 15 minutes) and only while it is fresh,
	 * so resending the marker can neither inflate the counter nor cause database writes.
	 *
	 * @param array{expiry:int,salt:string} $block Valid block from the cookie.
	 */
	public static function maybe_count_block( $block ) {
		$marker = self::COOKIE . '_new';
		if ( empty( $_COOKIE[ $marker ] ) ) {
			return;
		}
		if ( ! headers_sent() ) {
			setcookie( $marker, '', time() - 3600, '/' );
		}
		$solved = $block['expiry'] - (int) self::settings()['ttl_hours'] * HOUR_IN_SECONDS;
		if ( $solved < time() - 15 * MINUTE_IN_SECONDS ) {
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
		global $wpdb;
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", self::BLOCKS_OPTION ) ); // phpcs:ignore WordPress.DB
		if ( ! $updated ) {
			add_option( self::BLOCKS_OPTION, 1, '', false );
		}
		update_option( self::LAST_BLOCK_OPTION, time(), false );
		wp_cache_delete( self::BLOCKS_OPTION, 'options' );
	}

	/**
	 * Number of blocks mined so far (read directly, the counter changes outside the cache).
	 */
	public static function blocks() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::BLOCKS_OPTION ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count an event per day in a persistent object cache (Redis, Memcached). Without one,
	 * nothing is stored: no database writes on every request.
	 *
	 * @param string $what issued|passed.
	 */
	public static function count( $what ) {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return;
		}
		$key = $what . '_' . gmdate( 'Ymd' );
		if ( false === wp_cache_incr( $key, 1, self::STATS_GROUP ) ) {
			wp_cache_set( $key, 1, self::STATS_GROUP, 3 * DAY_IN_SECONDS );
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
	 * Send the challenge page and stop.
	 *
	 * @param bool $preview Admin preview (status 200, nothing counted).
	 */
	public static function render_challenge( $preview = false ) {
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
		$back = isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$back_parts = wp_parse_url( $back );
		// Same site AND http(s) only: a javascript:/data: URL whose host happens
		// to match would otherwise land in the "Go back" href.
		if ( '' === $back || ! isset( $back_parts['scheme'] ) || ! in_array( strtolower( $back_parts['scheme'] ), array( 'http', 'https' ), true )
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
			'worker'    => $base . 'worker.js?ver=' . $ver,
			'brand'     => preg_match( '/^#[0-9a-f]{3,8}$/i', (string) $s['brand_color'] ) ? (string) $s['brand_color'] : '#f7931a',
			'found'     => $t['found'],
			'nocookie'  => $t['nocook'],
			'preview'   => $preview ? 1 : 0,
		);
		if ( ! headers_sent() ) {
			http_response_code( $preview ? 200 : max( 200, min( 599, (int) $s['status'] ) ) );
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: no-store, max-age=0' );
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		include dirname( POWNED_FILE ) . '/templates/challenge.php';
		exit;
	}
}

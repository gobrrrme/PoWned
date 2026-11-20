<?php
/**
 * Full lockdown: every page needs a solved block, for sites under heavy attack.
 *
 * Checked at the very end of template_redirect, right before the theme renders a page. Anything
 * that answers earlier (payment webhooks and callbacks via ?wc-api=, the REST API, admin-ajax,
 * wc-ajax, sitemaps, plugins that handle their own URLs on init or parse_request) is never
 * touched, whatever the payment provider. Challenges are sent with "503 Service Unavailable"
 * and Retry-After, so search engines treat the lockdown as temporary.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lockdown gate.
 */
final class Powned_Lockdown {

	const LOG_KEY      = 'powned_lockdown_log';
	const AUTO_KEY     = 'powned_lockdown_auto';
	const RETRY_AFTER  = 600;
	const DNS_PER_MIN  = 20;
	const CRAWLERS     = array(
		'googlebot'      => array( '.googlebot.com', '.google.com', '.googleusercontent.com' ),
		'google-inspectiontool' => array( '.googlebot.com', '.google.com' ),
		'bingbot'        => array( '.search.msn.com' ),
		'applebot'       => array( '.applebot.apple.com' ),
		'duckduckbot'    => array( '.duckduckgo.com' ),
		'yandexbot'      => array( '.yandex.ru', '.yandex.net', '.yandex.com' ),
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( 'off' !== self::mode() ) {
			add_action( 'template_redirect', array( __CLASS__, 'gate' ), PHP_INT_MAX );
		}
	}

	/**
	 * Configured mode: off, log, on, auto.
	 */
	public static function mode() {
		$m = (string) Powned::settings()['lockdown'];
		return in_array( $m, array( 'log', 'on', 'auto' ), true ) && ! empty( Powned::settings()['enabled'] ) ? $m : 'off';
	}

	/**
	 * Is the lockdown enforcing right now? Manual mode ends by itself after the set hours;
	 * automatic mode is on while the server is overloaded and 15 minutes after.
	 */
	public static function active() {
		$s = Powned::settings();
		switch ( self::mode() ) {
			case 'on':
				$hours = (int) $s['lockdown_hours'];
				return 0 === $hours || (int) $s['lockdown_since'] + $hours * HOUR_IN_SECONDS > time();
			case 'auto':
				return self::auto_active();
		}
		return false;
	}

	/**
	 * Remaining seconds of a manual lockdown (0 = no limit), for the admin notice.
	 */
	public static function remaining() {
		$s = Powned::settings();
		return (int) $s['lockdown_hours'] ? max( 0, (int) $s['lockdown_since'] + (int) $s['lockdown_hours'] * HOUR_IN_SECONDS - time() ) : 0;
	}

	/**
	 * Automatic mode: 5-minute load per core above the threshold switches on for 15 minutes,
	 * renewed while it stays high.
	 */
	public static function auto_active() {
		$load = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[1] ?? 0 ) : 0.0;
		$info = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore
		$cores = $info ? max( 1, preg_match_all( '/^processor\s*:/m', $info ) ) : 1;
		if ( $load / $cores >= (float) Powned::settings()['lockdown_load'] ) {
			set_transient( self::AUTO_KEY, time(), 15 * MINUTE_IN_SECONDS );
			return true;
		}
		return (bool) get_transient( self::AUTO_KEY );
	}

	/**
	 * The gate.
	 */
	public static function gate() {
		$mode = self::mode();
		if ( 'log' !== $mode && ! self::active() ) {
			return;
		}
		if ( ! self::applies() ) {
			return;
		}
		if ( Powned::valid_cookie() ) {
			Powned::count( 'passed' );
			return;
		}
		if ( 'log' === $mode ) {
			self::log( Powned::request_path() );
			return;
		}
		Powned::count( 'issued' );
		Powned::render_challenge( false, 503 );
	}

	/**
	 * Does the lockdown apply to this request? Only page views by people in a browser.
	 */
	public static function applies() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ( 'GET' !== $method && 'HEAD' !== $method ) || is_user_logged_in() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_favicon() || is_trackback() || is_embed() || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			return false;
		}
		// Explicit machine requests (JSON, XML) are not page views.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $accept && false === strpos( $accept, 'text/html' ) && false === strpos( $accept, '*/*' ) ) {
			return false;
		}
		$s    = Powned::settings();
		$path = Powned::request_path();
		// Thank-you page after payment, also when WooCommerce does not recognise the order yet.
		if ( preg_match( '#/order-received(/|$)#i', $path ) ) {
			return false;
		}
		foreach ( Powned::lines( $s['exclude'] ) as $pattern ) {
			if ( Powned::wildcard( $pattern, $path ) ) {
				return false;
			}
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( Powned::lines( $s['lockdown_agents'] ) as $needle ) {
			if ( '' !== $ua && false !== stripos( $ua, $needle ) ) {
				return false;
			}
		}
		if ( ! empty( $s['lockdown_crawlers'] ) && self::verified_crawler( $ua ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Is this a real search engine crawler? The user agent claims it, reverse DNS and the
	 * matching forward lookup prove it. Results are cached for a day; new lookups are capped
	 * per minute so fake crawlers cannot tie up the server with DNS queries.
	 *
	 * @param string $ua User agent.
	 */
	public static function verified_crawler( $ua ) {
		$suffixes = null;
		foreach ( self::CRAWLERS as $name => $domains ) {
			if ( false !== stripos( $ua, $name ) ) {
				$suffixes = $domains;
				break;
			}
		}
		// Reverse DNS needs the address the request really comes from, not a filtered one.
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = Powned::client_ip() ?: $ip;
		if ( ! $suffixes || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$key    = 'powned_crawler_' . substr( md5( $ip ), 0, 16 );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$minute = 'powned_dns_' . gmdate( 'YmdHi' );
		$used   = (int) get_transient( $minute );
		if ( $used >= self::DNS_PER_MIN ) {
			return false;
		}
		set_transient( $minute, $used + 1, 2 * MINUTE_IN_SECONDS );
		$ok   = false;
		$host = strtolower( (string) @gethostbyaddr( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		foreach ( $suffixes as $suffix ) {
			if ( '' !== $host && $host !== $ip && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				$type    = false !== strpos( $ip, ':' ) ? DNS_AAAA : DNS_A;
				$records = (array) @dns_get_record( $host, $type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				foreach ( $records as $r ) {
					if ( inet_pton( (string) ( $r['ip'] ?? $r['ipv6'] ?? '' ) ) === inet_pton( $ip ) ) {
						$ok = true;
					}
				}
				break;
			}
		}
		set_transient( $key, $ok ? 'yes' : 'no', DAY_IN_SECONDS );
		return $ok;
	}

	/**
	 * Log mode: count the paths the lockdown would have challenged (paths only, nothing about
	 * visitors). At most 50 different paths are kept.
	 *
	 * @param string $path Path.
	 */
	public static function log( $path ) {
		$log = get_transient( self::LOG_KEY );
		$log = is_array( $log ) ? $log : array();
		$path = substr( $path, 0, 200 );
		if ( isset( $log[ $path ] ) ) {
			$log[ $path ]++;
		} elseif ( count( $log ) < 50 ) {
			$log[ $path ] = 1;
		} else {
			$log['(other)'] = ( $log['(other)'] ?? 0 ) + 1;
		}
		set_transient( self::LOG_KEY, $log, 7 * DAY_IN_SECONDS );
	}
}

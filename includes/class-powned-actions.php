<?php
/**
 * Protected actions: checkout (including express payment buttons), coupons, login and forms
 * need a solved block. The background miner solves it on the pages where these actions
 * happen, so customers do not see a challenge page.
 *
 * Only actions a person performs in the browser are checked. Payment webhooks, gateway
 * callbacks and APIs are never touched, whatever the payment provider.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

/**
 * Action guards and background miner.
 */
final class Powned_Actions {

	const HANDLE = 'powned-background';

	/**
	 * Mine as soon as the page loads (shop pages), not only when a form is used.
	 *
	 * @var bool
	 */
	private static $eager = false;

	/**
	 * Forms the background miner holds until the block is solved.
	 */
	const FORMS = 'form.checkout, form.checkout_coupon, form.woocommerce-cart-form, form.woocommerce-form-login, form.login, #loginform, form.wpcf7-form, #commentform';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		$s = Powned::settings();
		if ( empty( $s['enabled'] ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ), 20 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
		if ( ! empty( $s['guard_coupon'] ) ) {
			add_action( 'wc_ajax_apply_coupon', array( __CLASS__, 'guard_coupon_ajax' ), 1 );
			add_action( 'wp_loaded', array( __CLASS__, 'guard_coupon_form' ), 19 );
		}
		if ( ! empty( $s['guard_login'] ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'wp_authenticate', array( __CLASS__, 'guard_login_early' ), 1, 2 );
			add_filter( 'authenticate', array( __CLASS__, 'guard_login' ), 99 );
		}
		if ( ! empty( $s['guard_forms'] ) ) {
			add_filter( 'wpcf7_form_elements', array( __CLASS__, 'enqueue_filter' ) );
			add_filter( 'wpcf7_spam', array( __CLASS__, 'guard_cf7' ), 5, 2 );
			add_action( 'comment_form_before', array( __CLASS__, 'enqueue' ) );
			add_filter( 'pre_comment_approved', array( __CLASS__, 'guard_comment' ), 1 );
		}
	}

	/**
	 * Does the current request carry a valid block? Logged-in users always pass.
	 */
	public static function passes() {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return true;
		}
		if ( Powned::valid_cookie() ) {
			Powned::count( 'passed' );
			return true;
		}
		Powned::count( 'refused' );
		return false;
	}

	/**
	 * Language for messages: Ajax and REST requests take it from the page they came from.
	 */
	public static function language() {
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( (string) $_SERVER['HTTP_REFERER'], PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $ref ) {
			foreach ( Powned::lines( Powned::settings()['lang_prefixes'] ) as $line ) {
				$parts = array_map( 'trim', explode( '=', $line, 2 ) );
				if ( 2 === count( $parts ) && '' !== $parts[0] && 0 === stripos( $ref, $parts[0] ) ) {
					return strtolower( substr( $parts[1], 0, 2 ) );
				}
			}
		}
		return Powned::language();
	}

	/**
	 * Message for refused actions.
	 */
	private static function message() {
		return Powned::strings( self::language() )['action'];
	}

	/**
	 * Load the background miner where a protected action can happen.
	 */
	public static function maybe_enqueue() {
		if ( is_user_logged_in() || ! function_exists( 'WC' ) ) {
			return;
		}
		$s        = Powned::settings();
		$received = function_exists( 'is_order_received_page' ) && is_order_received_page();
		$shop     = is_cart() || ( is_checkout() && ! $received );
		if ( ( ! empty( $s['checkout'] ) && ( $shop || is_product() ) )
			|| ( ! empty( $s['guard_coupon'] ) && $shop )
			|| ( ! empty( $s['guard_login'] ) && is_account_page() ) ) {
			self::$eager = $shop || is_product();
			self::enqueue();
		}
	}

	/**
	 * Enqueue the background miner (in the footer, so it also works when called late).
	 */
	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_register_script( self::HANDLE, plugins_url( 'assets/pw-bg.js', POWNED_FILE ), array(), POWNED_VERSION, true );
		}
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Enqueue from a filter (Contact Form 7 renders a form), passing the value through.
	 *
	 * @param mixed $value Filtered value.
	 * @return mixed
	 */
	public static function enqueue_filter( $value ) {
		self::enqueue();
		return $value;
	}

	/**
	 * Configuration as data attributes, so no inline script is needed (strict CSP).
	 *
	 * @param string $tag    Script tag.
	 * @param string $handle Handle.
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$attrs = array(
			'endpoint' => add_query_arg( 'powned_challenge', '1', home_url( '/' ) ),
			'worker'   => plugins_url( 'assets/pw-core.js', POWNED_FILE ) . '?ver=' . POWNED_VERSION,
			'cookie'   => Powned::COOKIE,
			'min'      => (int) Powned::settings()['base_bits'],
			'forms'    => self::FORMS,
			'eager'    => self::$eager ? 1 : 0,
		);
		$data = '';
		foreach ( $attrs as $k => $v ) {
			$data .= ' data-' . $k . '="' . esc_attr( (string) $v ) . '"';
		}
		return str_replace( '<script ', '<script' . $data . ' ', $tag );
	}

	/**
	 * Coupons via Ajax (cart and checkout page): refuse before WooCommerce looks the code up.
	 */
	public static function guard_coupon_ajax() {
		if ( self::passes() ) {
			return;
		}
		wc_add_notice( self::message(), 'error' );
		wc_print_notices();
		wp_die();
	}

	/**
	 * Coupons via the cart form without JavaScript.
	 */
	public static function guard_coupon_form() {
		if ( empty( $_POST['apply_coupon'] ) || empty( $_POST['coupon_code'] ) || self::passes() ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		unset( $_POST['coupon_code'], $_REQUEST['coupon_code'] ); // phpcs:ignore WordPress.Security.NonceVerification
		wc_add_notice( self::message(), 'error' );
	}

	/**
	 * Is this a login through a form (wp-login.php or the WooCommerce account form)? XML-RPC,
	 * REST application passwords and other programmatic logins are left alone.
	 */
	private static function is_form_login() {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		return isset( $_POST['pwd'] ) || isset( $_POST['woocommerce-login-nonce'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Refused login: blank the password before WordPress checks it, so bots cannot make the
	 * server compute password hashes.
	 *
	 * @var bool
	 */
	private static $login_refused = false;

	/**
	 * Before the password check.
	 *
	 * @param string $username Username (by reference).
	 * @param string $password Password (by reference).
	 */
	public static function guard_login_early( &$username, &$password ) {
		if ( '' === (string) $username || ! self::is_form_login() || Powned::valid_cookie() ) {
			return;
		}
		self::$login_refused = true;
		Powned::count( 'refused' );
		$password = '';
	}

	/**
	 * Replace the error of a refused login.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function guard_login( $user ) {
		return self::$login_refused ? new WP_Error( 'powned_required', esc_html( self::message() ) ) : $user;
	}

	/**
	 * Contact Form 7: a submission without a block counts as spam.
	 *
	 * @param bool   $spam       Spam so far.
	 * @param object $submission Submission.
	 */
	public static function guard_cf7( $spam, $submission = null ) {
		if ( $spam || self::passes() ) {
			return $spam;
		}
		if ( $submission && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'powned', 'reason' => 'No solved PoWned block.' ) );
		}
		return true;
	}

	/**
	 * Comments: refused without a block.
	 *
	 * @param int|string|WP_Error $approved Approval status.
	 * @return int|string|WP_Error
	 */
	public static function guard_comment( $approved ) {
		if ( is_wp_error( $approved ) || self::passes() ) {
			return $approved;
		}
		return new WP_Error( 'powned_required', esc_html( self::message() ), 403 );
	}
}

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
	 * The checkout submission is being validated in this request: only then creating an order
	 * spends a block (not when a gateway or the admin creates one).
	 *
	 * @var bool
	 */
	public static $in_checkout = false;

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
		if ( ! empty( $s['checkout'] ) ) {
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'guard_order_creation' ), PHP_INT_MAX );
			add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'guard_pay' ), 1 );
			add_action( 'woocommerce_paypal_payments_approve_order_request_started', array( __CLASS__, 'guard_paypal_express' ), 1 );
		}
		if ( ! empty( $s['guard_reset'] ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'lostpassword_post', array( __CLASS__, 'guard_reset' ), 1 );
		}
		if ( ! empty( $s['guard_coupon'] ) ) {
			// WooCommerce registers the same handler for wc-ajax and for admin-ajax.
			foreach ( array( 'wc_ajax_apply_coupon', 'wp_ajax_woocommerce_apply_coupon', 'wp_ajax_nopriv_woocommerce_apply_coupon' ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'guard_coupon_ajax' ), 1 );
			}
			add_action( 'wp_loaded', array( __CLASS__, 'guard_coupon_form' ), 19 );
		}
		if ( ! empty( $s['guard_login'] ) ) {
			add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'wp_authenticate', array( __CLASS__, 'guard_login_early' ), 1, 2 );
			add_filter( 'authenticate', array( __CLASS__, 'guard_login' ), 99 );
		}
		if ( ! empty( $s['guard_forms'] ) ) {
			add_filter( 'wpcf7_form_elements', array( __CLASS__, 'enqueue_filter' ) );
			// Last, so what another spam check catches does not cost a block.
			add_filter( 'wpcf7_spam', array( __CLASS__, 'guard_cf7' ), PHP_INT_MAX, 2 );
			add_action( 'comment_form_before', array( __CLASS__, 'enqueue' ) );
			add_filter( 'pre_comment_approved', array( __CLASS__, 'guard_comment' ), 1 );
		}
	}

	/**
	 * Who does not need a block: logged-in users and WP-CLI.
	 */
	private static function exempt() {
		return ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() );
	}

	/**
	 * Could this request do the action? Spends nothing.
	 *
	 * @param string $bucket Kind of action.
	 * @return string '' if yes, otherwise 'block' or 'limit'.
	 */
	public static function check( $bucket ) {
		return self::result( self::exempt() ? '' : Powned_Tickets::check( $bucket ) );
	}

	/**
	 * Spend a block for the action.
	 *
	 * @param string $bucket Kind of action.
	 * @return string '' if spent, otherwise 'block' or 'limit'.
	 */
	public static function spend( $bucket ) {
		$why = self::result( self::exempt() ? '' : Powned_Tickets::spend( $bucket ) );
		if ( '' === $why ) {
			Powned::count( 'passed' );
		}
		return $why;
	}

	/**
	 * Count refusals.
	 *
	 * @param string $why Result.
	 */
	private static function result( $why ) {
		if ( '' !== $why ) {
			Powned::count( 'refused' );
		}
		return $why;
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
	public static function message( $why = 'block' ) {
		return Powned::strings( self::language() )[ 'limit' === $why ? 'limit' : 'action' ];
	}

	/**
	 * Refusal at the checkout. A missing block carries the marker that makes the browser mine
	 * and send again; a reached limit does not.
	 *
	 * @param string $why 'block' or 'limit'.
	 */
	public static function checkout_message( $why ) {
		if ( 'limit' === $why ) {
			return esc_html( self::message( $why ) );
		}
		return '<span class="powned-retry"></span>' . esc_html( Powned::strings( self::language() )['checkout'] );
	}

	/**
	 * Right before the checkout saves an order: spend the block. WooCommerce turns the exception
	 * into a checkout error and saves nothing.
	 *
	 * @throws Exception Without a block.
	 */
	public static function guard_order_creation() {
		if ( ! self::$in_checkout ) {
			return;
		}
		$why = self::spend( 'order' );
		if ( '' !== $why ) {
			throw new Exception( self::checkout_message( $why ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * WooCommerce PayPal Payments can create and pay an order when an express payment is
	 * approved, without the checkout. It also accepts PayPal orders nobody has approved yet,
	 * so this spends a block too. When refused, PayPal's script continues on the checkout and
	 * nothing has been charged.
	 *
	 * @param array $data Request data.
	 * @throws RuntimeException Without a block (the endpoint answers it as a JSON error).
	 */
	public static function guard_paypal_express( $data ) {
		if ( ! is_array( $data ) || empty( $data['should_create_wc_order'] ) ) {
			return;
		}
		$why = self::spend( 'order' );
		if ( '' !== $why ) {
			throw new RuntimeException( esc_html( self::message( $why ) ) );
		}
	}

	/**
	 * "Pay for order" page: every payment attempt spends a block. Redirects back, because
	 * WooCommerce carries on after a notice.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function guard_pay( $order ) {
		$why = self::spend( 'order' );
		if ( '' === $why ) {
			return;
		}
		wc_add_notice( esc_html( self::message( $why ) ), 'error' );
		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Password reset (wp-login.php and WooCommerce): spends a block, whether the account exists
	 * or not.
	 *
	 * @param WP_Error $errors Errors.
	 */
	public static function guard_reset( $errors ) {
		$why = self::spend( 'reset' );
		if ( '' !== $why && $errors instanceof WP_Error ) {
			$errors->add( 'powned_required', esc_html( self::message( $why ) ) );
		}
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
		$eager    = ( ! empty( $s['checkout'] ) && ( $shop || is_product() ) ) || ( ! empty( $s['guard_coupon'] ) && $shop );
		if ( $eager || ( ( ! empty( $s['guard_login'] ) || ! empty( $s['guard_reset'] ) ) && is_account_page() ) ) {
			self::$eager = $eager;
			self::enqueue();
		}
	}

	/**
	 * Forms the miner holds until the block is ready: only those whose action is protected.
	 */
	private static function forms() {
		$s    = Powned::settings();
		$list = array();
		if ( ! empty( $s['checkout'] ) ) {
			$list[] = 'form.checkout, form#order_review';
		}
		if ( ! empty( $s['guard_coupon'] ) ) {
			$list[] = 'form.checkout_coupon, form.woocommerce-cart-form';
		}
		if ( ! empty( $s['guard_login'] ) ) {
			$list[] = 'form.woocommerce-form-login, form.login, #loginform';
		}
		if ( ! empty( $s['guard_reset'] ) ) {
			$list[] = '#lostpasswordform, form.lost_reset_password';
		}
		if ( ! empty( $s['guard_forms'] ) ) {
			$list[] = 'form.wpcf7-form, #commentform';
		}
		return implode( ', ', $list );
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
			'cookie'   => Powned_Tickets::COOKIE,
			'forms'    => self::forms(),
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
		$why = self::spend( 'coupon' );
		if ( '' === $why ) {
			return;
		}
		wc_add_notice( self::message( $why ), 'error' );
		wc_print_notices();
		wp_die();
	}

	/**
	 * Coupons via the cart form without JavaScript.
	 */
	public static function guard_coupon_form() {
		if ( empty( $_POST['apply_coupon'] ) || empty( $_POST['coupon_code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$why = self::spend( 'coupon' );
		if ( '' === $why ) {
			return;
		}
		unset( $_POST['coupon_code'], $_REQUEST['coupon_code'] ); // phpcs:ignore WordPress.Security.NonceVerification
		wc_add_notice( self::message( $why ), 'error' );
	}

	/**
	 * Is this a login through a form (wp-login.php or the WooCommerce account form)? XML-RPC,
	 * REST application passwords and other programmatic logins are left alone.
	 */
	private static function is_form_login() {
		if ( ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		// The same fields WooCommerce looks at: its nonce may also arrive as _wpnonce or in the URL.
		return isset( $_POST['pwd'] ) || isset( $_POST['login'], $_POST['username'], $_POST['password'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Refused login: blank the password before WordPress checks it, so bots cannot make the
	 * server compute password hashes.
	 *
	 * @var bool
	 */
	private static $login_refused = '';

	/**
	 * Before the password check.
	 *
	 * @param string $username Username (by reference).
	 * @param string $password Password (by reference).
	 */
	public static function guard_login_early( &$username, &$password ) {
		if ( '' === (string) $username || ! self::is_form_login() ) {
			return;
		}
		// Not exempt when logged in: someone signing in again guesses like everyone else.
		$why = self::result( Powned_Tickets::spend( 'login' ) );
		if ( '' === $why ) {
			return;
		}
		self::$login_refused = $why;
		$password            = '';
	}

	/**
	 * Replace the error of a refused login.
	 *
	 * @param WP_User|WP_Error|null $user Result so far.
	 * @return WP_User|WP_Error|null
	 */
	public static function guard_login( $user ) {
		return '' !== self::$login_refused ? new WP_Error( 'powned_required', esc_html( self::message( self::$login_refused ) ) ) : $user;
	}

	/**
	 * Contact Form 7: a submission without a block counts as spam.
	 *
	 * @param bool   $spam       Spam so far.
	 * @param object $submission Submission.
	 */
	public static function guard_cf7( $spam, $submission = null ) {
		if ( $spam ) {
			return $spam;
		}
		$why = self::spend( 'form' );
		if ( '' === $why ) {
			return $spam;
		}
		if ( $submission && method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log( array( 'agent' => 'powned', 'reason' => 'limit' === $why ? 'Too many submissions from this network.' : 'No unspent PoWned block.' ) );
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
		if ( is_wp_error( $approved ) ) {
			return $approved;
		}
		$why = self::spend( 'form' );
		if ( '' === $why ) {
			return $approved;
		}
		return new WP_Error( 'powned_required', esc_html( self::message( $why ) ), 'limit' === $why ? 429 : 403 );
	}
}

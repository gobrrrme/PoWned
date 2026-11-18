<?php
/**
 * Settings page: Settings → PoWned.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings.
 */
final class Powned_Settings {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( POWNED_FILE ), array( __CLASS__, 'action_link' ) );
		add_action( 'update_option_' . Powned::OPTION, array( __CLASS__, 'sync_loader' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice_loader' ) );
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		add_options_page( __( 'PoWned', 'powned' ), __( 'PoWned', 'powned' ), 'manage_options', 'powned', array( __CLASS__, 'page' ) );
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=powned' ) ) . '">' . esc_html__( 'Settings', 'powned' ) . '</a>' );
		return $links;
	}

	/**
	 * Register the option.
	 */
	public static function register() {
		register_setting(
			'powned',
			Powned::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Powned::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings.
	 *
	 * @param mixed $in Input.
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d   = Powned::defaults();
		$in  = is_array( $in ) ? $in : array();
		$txt = static function ( $v ) {
			return implode( "\n", Powned::lines( sanitize_textarea_field( (string) $v ) ) );
		};
		$out = array(
			'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
			'early'          => empty( $in['early'] ) ? 0 : 1,
			'params'         => $txt( $in['params'] ?? '' ),
			'paths'          => $txt( $in['paths'] ?? '' ),
			'exclude'        => $txt( $in['exclude'] ?? '' ),
			'base_bits'      => max( 8, min( 28, (int) ( $in['base_bits'] ?? $d['base_bits'] ) ) ),
			'adjust'         => empty( $in['adjust'] ) ? 0 : 1,
			'max_extra'      => max( 0, min( 8, (int) ( $in['max_extra'] ?? $d['max_extra'] ) ) ),
			'load_threshold' => max( 0.1, min( 10, (float) str_replace( ',', '.', (string) ( $in['load_threshold'] ?? $d['load_threshold'] ) ) ) ),
			'ttl_hours'      => max( 1, min( 720, (int) ( $in['ttl_hours'] ?? $d['ttl_hours'] ) ) ),
			'binding'        => in_array( $in['binding'] ?? '', array( 'network', 'ip', 'none' ), true ) ? $in['binding'] : $d['binding'],
			'status'         => in_array( (int) ( $in['status'] ?? 0 ), array( 200, 403, 429, 503 ), true ) ? (int) $in['status'] : $d['status'],
			'lang_prefixes'  => $txt( $in['lang_prefixes'] ?? '' ),
			'brand_color'    => sanitize_hex_color( (string) ( $in['brand_color'] ?? '' ) ) ?: $d['brand_color'],
			'logo_url'       => esc_url_raw( (string) ( $in['logo_url'] ?? '' ) ),
			'title'          => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			'text'           => sanitize_textarea_field( (string) ( $in['text'] ?? '' ) ),
		);
		// Never protect the admin area or the login: an administrator must always get in.
		foreach ( array( '/wp-admin/*', '/wp-login.php' ) as $must ) {
			if ( ! in_array( $must, Powned::lines( $out['exclude'] ), true ) ) {
				$out['exclude'] = trim( $out['exclude'] . "\n" . $must );
			}
		}
		return $out;
	}

	/**
	 * Install or remove the early loader when the setting changes.
	 *
	 * @param array $old Old value.
	 * @param array $new New value.
	 */
	public static function sync_loader( $old, $new ) {
		if ( ! empty( $new['early'] ) ) {
			Powned_Loader::install();
		} else {
			Powned_Loader::remove();
		}
	}

	/**
	 * Warn when the deployment shape defeats the gate: early loader unwritable,
	 * page-cache drop-ins, and reverse proxies (detected by headers or a private
	 * REMOTE_ADDR; the headers are never trusted, only used for detection).
	 * Every notice is dismissible per admin user.
	 */
	public static function notice_loader() {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_powned' !== $screen->id ) {
			return;
		}
		$s = Powned::settings();
		if ( ! empty( $s['early'] ) && ! Powned_Loader::installed() && ! Powned_Loader::install() ) {
			/* translators: %s: directory */
			self::notice( 'loader', sprintf( __( 'Early check is on, but the loader could not be written to %s. The check still runs, just a little later during loading.', 'powned' ), WPMU_PLUGIN_DIR ) );
		}
		// Page-cache drop-ins load before WordPress (and before this plugin), so a
		// cached copy of a protected URL is served without the challenge.
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			self::notice( 'cache', __( 'A page cache is active (WP_CACHE). Cached pages are served before the proof-of-work check runs: check your cache plugin’s query-string handling and never cache the protected URLs (LiteSpeed, for one, caches query-string URLs by default).', 'powned' ) );
		}
		// Behind a reverse proxy REMOTE_ADDR is the proxy for every visitor, so the
		// network binding collapses into one shared key.
		if ( 'none' !== $s['binding'] && isset( $_SERVER['REMOTE_ADDR'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
			&& false === filter_var( $_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			self::notice( 'proxy', __( 'PHP sees a private/reserved REMOTE_ADDR, which usually means a reverse proxy is in front. All visitors then share one network key, so a single solved block unlocks the site for everyone. Have the proxy pass the client IP (and have PHP trust it), or set "Bound to" to "Not bound".', 'powned' ) );
		}
		// Detection only: forwarded headers that differ from REMOTE_ADDR mean a
		// proxy is in front even when its address is public (Cloudflare, Fastly,
		// Sucuri). The headers are never trusted for the gate.
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		$cf  = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended
		$ra  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$xff_first = '' !== $xff ? trim( (string) strtok( $xff, ',' ) ) : '';
		if ( ( '' !== $xff_first && $xff_first !== $ra ) || ( '' !== $cf && $cf !== $ra ) ) {
			self::notice( 'proxy_headers', __( 'Forwarded-IP headers (X-Forwarded-For / CF-Connecting-IP) differ from REMOTE_ADDR: a reverse proxy or CDN is in front. Every visitor then shares the proxy’s network key, so one solved block unlocks the site for everyone. This plugin never reads those headers (they are spoofable); configure the proxy to pass the client IP and mark PHP as trusting it, or set "Bound to" to "Not bound".', 'powned' ) );
		}
	}

	/**
	 * One dismissible warning notice per admin user. Dismissal is per user and
	 * per key, protected by a nonce, and forgotten if the condition returns
	 * with a different text.
	 */
	private static function notice( $key, $message ) {
		$user = get_current_user_id();
		if ( isset( $_GET['powned_dismiss'] ) && isset( $_GET['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			&& wp_verify_nonce( sanitize_text_field( (string) $_GET['_wpnonce'] ), 'powned_dismiss_' . $key ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			&& sanitize_key( (string) $_GET['powned_dismiss'] ) === $key ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			update_user_meta( $user, 'powned_dismissed_' . $key, time() );
		}
		if ( get_user_meta( $user, 'powned_dismissed_' . $key, true ) ) {
			return;
		}
		$url = wp_nonce_url( add_query_arg( 'powned_dismiss', $key, admin_url( 'options-general.php?page=powned' ) ), 'powned_dismiss_' . $key );
		echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html( $message ) . '</p>';
		echo '<p><a class="button-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss this notice', 'powned' ) . '</a></p></div>';
	}

	/**
	 * Rough solving time for a difficulty.
	 *
	 * @param int $bits Bits.
	 * @param int $rate Hashes per second.
	 */
	private static function eta( $bits, $rate ) {
		$secs = pow( 2, $bits ) / $rate;
		if ( $secs < 0.1 ) {
			return '< 0.1 s';
		}
		if ( $secs < 1 ) {
			return sprintf( '%.1f s', $secs );
		}
		return $secs < 120 ? sprintf( '%d s', round( $secs ) ) : sprintf( '%d min', round( $secs / 60 ) );
	}

	/**
	 * Page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Powned::settings();
		$o    = Powned::OPTION;
		$diff = Powned::difficulty();
		$name = static function ( $k ) use ( $o ) {
			return esc_attr( $o . '[' . $k . ']' );
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PoWned', 'powned' ); ?></h1>
			<p style="max-width:72ch"><?php esc_html_e( 'Visitors of protected URLs first solve a small proof of work in their browser: double SHA-256 below a target, like Bitcoin mining. Humans wait about a second once; crawlers that rotate IPs pay that for every single request. No third-party service, no tracking, nothing stored on the server.', 'powned' ); ?></p>

			<div class="card" style="max-width:none">
				<h2 class="title"><?php esc_html_e( 'Status', 'powned' ); ?></h2>
				<p>
					<?php
					/* translators: 1: load per CPU core, 2: difficulty bits, 3: extra bits */
					echo esc_html( sprintf( __( 'Load per CPU core: %1$s · current difficulty: %2$d bits (%3$s)', 'powned' ), number_format_i18n( $diff['load'], 2 ), $diff['bits'], $diff['extra'] ? '+' . $diff['extra'] . ' ' . __( 'for load', 'powned' ) : __( 'base', 'powned' ) ) );
					?>
					<br>
					<?php
					/* translators: 1: time on a phone, 2: time on a desktop */
					echo esc_html( sprintf( __( 'Expected solving time now: about %1$s on a phone, %2$s on a desktop.', 'powned' ), self::eta( $diff['bits'], 400000 ), self::eta( $diff['bits'], 2000000 ) ) );
					?>
					<br>
					<?php
					echo esc_html( Powned_Loader::installed() ? __( 'Early check: active (runs before other plugins load).', 'powned' ) : __( 'Early check: off (runs when this plugin loads).', 'powned' ) );
					?>
				</p>
				<?php if ( wp_using_ext_object_cache() ) : ?>
					<p>
						<?php
						$day = gmdate( 'Ymd' );
						/* translators: 1: challenges shown, 2: requests let through */
						echo esc_html( sprintf( __( 'Today (UTC): %1$s challenges shown, %2$s requests let through with a solved block.', 'powned' ), number_format_i18n( (int) wp_cache_get( 'issued_' . $day, Powned::STATS_GROUP ) ), number_format_i18n( (int) wp_cache_get( 'passed_' . $day, Powned::STATS_GROUP ) ) ) );
						?>
					</p>
				<?php endif; ?>
				<p class="powned-blocks" style="font-size:1.6em;font-weight:600;margin:.8em 0 .2em;font-variant-numeric:tabular-nums">
					<?php
					/* translators: %s: number of mined blocks */
					echo esc_html( sprintf( __( '%s blocks mined', 'powned' ), number_format_i18n( Powned::blocks() ) ) );
					?>
				</p>
				<p class="description" style="margin-top:0">
					<?php
					$last = (int) get_option( Powned::LAST_BLOCK_OPTION, 0 );
					echo esc_html(
						$last
							/* translators: %s: time since the last block, e.g. "5 minutes" */
							? sprintf( __( 'Every solved challenge counts once. Last block: %s ago.', 'powned' ), human_time_diff( $last ) )
							: __( 'Every solved challenge counts once. No block mined yet.', 'powned' )
					);
					?>
				</p>
				<p><a class="button" href="<?php echo esc_url( add_query_arg( 'powned_preview', '1', home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the challenge page', 'powned' ); ?></a></p>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'powned' ); ?>
				<h2><?php esc_html_e( 'Protection', 'powned' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Active', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'enabled' ); // phpcs:ignore ?>" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Protect the URLs below', 'powned' ); ?></label></td></tr>
					<tr><th scope="row"><label for="pg-params"><?php esc_html_e( 'Query parameters', 'powned' ); ?></label></th>
						<td><textarea id="pg-params" class="large-text code" rows="7" name="<?php echo $name( 'params' ); // phpcs:ignore ?>"><?php echo esc_textarea( $s['params'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, * as wildcard. A URL with any of these parameters is protected. The defaults cover WooCommerce filters (attributes, price, rating, stock, sale).', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><label for="pg-paths"><?php esc_html_e( 'Paths', 'powned' ); ?></label></th>
						<td><textarea id="pg-paths" class="large-text code" rows="4" name="<?php echo $name( 'paths' ); // phpcs:ignore ?>" placeholder="/search/*&#10;/shop/page/*"><?php echo esc_textarea( $s['paths'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Protect whole pages by path, one per line, * as wildcard (e.g. /shop/* for everything below /shop/). Leave empty to protect by parameters only.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><label for="pg-exclude"><?php esc_html_e( 'Never protect', 'powned' ); ?></label></th>
						<td><textarea id="pg-exclude" class="large-text code" rows="5" name="<?php echo $name( 'exclude' ); // phpcs:ignore ?>"><?php echo esc_textarea( $s['exclude'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Paths that are always let through, one per line. The admin area and login are always excluded.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Early check', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'early' ); // phpcs:ignore ?>" value="1" <?php checked( $s['early'] ); ?>> <?php esc_html_e( 'Run the check before other plugins load (adds a small file to mu-plugins)', 'powned' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended: a crawler request then costs almost no server time.', 'powned' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( 'Difficulty', 'powned' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="pg-bits"><?php esc_html_e( 'Base difficulty', 'powned' ); ?></label></th>
						<td><select id="pg-bits" name="<?php echo $name( 'base_bits' ); // phpcs:ignore ?>">
							<?php for ( $b = 8; $b <= 28; $b++ ) : ?>
								<option value="<?php echo (int) $b; ?>" <?php selected( (int) $s['base_bits'], $b ); ?>>
									<?php
									/* translators: 1: bits, 2: time on a phone, 3: time on a desktop */
									echo esc_html( sprintf( __( '%1$d bits (phone ~%2$s, desktop ~%3$s)', 'powned' ), $b, self::eta( $b, 400000 ), self::eta( $b, 2000000 ) ) );
									?>
								</option>
							<?php endfor; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Leading zero bits the double SHA-256 hash needs. Each extra bit doubles the work. Times are rough averages.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Difficulty adjustment', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'adjust' ); // phpcs:ignore ?>" value="1" <?php checked( $s['adjust'] ); ?>> <?php esc_html_e( 'Raise the difficulty when the server is busy', 'powned' ); ?></label>
						<p>
							<label><?php esc_html_e( 'from a load per CPU core of', 'powned' ); ?> <input type="text" inputmode="decimal" size="4" name="<?php echo $name( 'load_threshold' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['load_threshold'] ); ?>"></label>,
							<label><?php esc_html_e( 'at most', 'powned' ); ?> <input type="number" min="0" max="8" class="small-text" name="<?php echo $name( 'max_extra' ); // phpcs:ignore ?>" value="<?php echo (int) $s['max_extra']; ?>"> <?php esc_html_e( 'extra bits', 'powned' ); ?></label>
						</p>
						<p class="description"><?php esc_html_e( 'One extra bit at the threshold, one more each time the load doubles. Already solved blocks stay valid.', 'powned' ); ?></p></td></tr>
				</table>

				<h2><?php esc_html_e( 'Access', 'powned' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="pg-ttl"><?php esc_html_e( 'Valid for', 'powned' ); ?></label></th>
						<td><input id="pg-ttl" type="number" min="1" max="720" class="small-text" name="<?php echo $name( 'ttl_hours' ); // phpcs:ignore ?>" value="<?php echo (int) $s['ttl_hours']; ?>"> <?php esc_html_e( 'hours', 'powned' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Bound to', 'powned' ); ?></th>
						<td><fieldset>
							<label><input type="radio" name="<?php echo $name( 'binding' ); // phpcs:ignore ?>" value="network" <?php checked( $s['binding'], 'network' ); ?>> <?php esc_html_e( 'Network (/24 for IPv4, /64 for IPv6), recommended', 'powned' ); ?></label><br>
							<label><input type="radio" name="<?php echo $name( 'binding' ); // phpcs:ignore ?>" value="ip" <?php checked( $s['binding'], 'ip' ); ?>> <?php esc_html_e( 'Exact IP address (strictest)', 'powned' ); ?></label><br>
							<label><input type="radio" name="<?php echo $name( 'binding' ); // phpcs:ignore ?>" value="none" <?php checked( $s['binding'], 'none' ); ?>> <?php esc_html_e( 'Not bound (a solved block can be shared, weakest)', 'powned' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'The cookie only holds a keyed hash of the network, never the IP address.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><label for="pg-status"><?php esc_html_e( 'HTTP status of the challenge', 'powned' ); ?></label></th>
						<td><select id="pg-status" name="<?php echo $name( 'status' ); // phpcs:ignore ?>">
							<?php foreach ( array( 403, 429, 503, 200 ) as $code ) : ?>
								<option value="<?php echo (int) $code; ?>" <?php selected( (int) $s['status'], $code ); ?>><?php echo (int) $code; ?></option>
							<?php endforeach; ?>
						</select></td></tr>
				</table>

				<h2><?php esc_html_e( 'Appearance', 'powned' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="pg-color"><?php esc_html_e( 'Accent color', 'powned' ); ?></label></th>
						<td><input id="pg-color" type="color" name="<?php echo $name( 'brand_color' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['brand_color'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="pg-logo"><?php esc_html_e( 'Logo URL', 'powned' ); ?></label></th>
						<td><input id="pg-logo" type="url" class="regular-text" name="<?php echo $name( 'logo_url' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['logo_url'] ); ?>" placeholder="<?php esc_attr_e( 'Empty: the site icon', 'powned' ); ?>"></td></tr>
					<tr><th scope="row"><label for="pg-title"><?php esc_html_e( 'Own heading', 'powned' ); ?></label></th>
						<td><input id="pg-title" type="text" class="regular-text" name="<?php echo $name( 'title' ); // phpcs:ignore ?>" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php esc_attr_e( 'Empty: built-in text in the visitor’s language', 'powned' ); ?>"></td></tr>
					<tr><th scope="row"><label for="pg-text"><?php esc_html_e( 'Own explanation', 'powned' ); ?></label></th>
						<td><textarea id="pg-text" class="large-text" rows="3" name="<?php echo $name( 'text' ); // phpcs:ignore ?>" placeholder="<?php esc_attr_e( 'Empty: built-in text in the visitor’s language', 'powned' ); ?>"><?php echo esc_textarea( $s['text'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="pg-lang"><?php esc_html_e( 'Language by path', 'powned' ); ?></label></th>
						<td><textarea id="pg-lang" class="large-text code" rows="2" name="<?php echo $name( 'lang_prefixes' ); // phpcs:ignore ?>" placeholder="/de/=de"><?php echo esc_textarea( $s['lang_prefixes'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'For multilingual sites with language prefixes, one per line: path prefix = language code. Built in: English (en) and German (de). Otherwise the site language is used.', 'powned' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

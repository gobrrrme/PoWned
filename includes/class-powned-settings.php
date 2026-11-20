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
		add_action( 'admin_notices', array( __CLASS__, 'notice_lockdown' ) );
		add_action( 'admin_post_powned_rotate_secret', array( __CLASS__, 'rotate_secret' ) );
	}

	/**
	 * Menu entry: own item in the admin menu, right below Settings.
	 */
	public static function menu() {
		add_menu_page( __( 'PoWned', 'powned' ), __( 'PoWned', 'powned' ), 'manage_options', 'powned', array( __CLASS__, 'page' ), self::icon(), 81 );
	}

	/**
	 * Menu icon: a block of tiles, one still being mined. WordPress colours it to match the
	 * admin colour scheme.
	 */
	private static function icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2 2h7v7H2zM11 2h7v7h-7zM2 11h7v7H2z"/><path fill="black" fill-opacity=".45" d="M11 11h7v7h-7z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_link( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=powned' ) ) . '">' . esc_html__( 'Settings', 'powned' ) . '</a>' );
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
			'checkout'       => empty( $in['checkout'] ) ? 0 : 1,
			'guard_coupon'   => empty( $in['guard_coupon'] ) ? 0 : 1,
			'guard_login'    => empty( $in['guard_login'] ) ? 0 : 1,
			'guard_forms'    => empty( $in['guard_forms'] ) ? 0 : 1,
			'stealth'        => empty( $in['stealth'] ) ? 0 : 1,
			'lockdown'       => in_array( $in['lockdown'] ?? '', array( 'off', 'log', 'on', 'auto' ), true ) ? $in['lockdown'] : 'off',
			'lockdown_hours' => max( 0, min( 168, (int) ( $in['lockdown_hours'] ?? $d['lockdown_hours'] ) ) ),
			'lockdown_since' => 0,
			'lockdown_load'  => max( 0.5, min( 20, (float) str_replace( ',', '.', (string) ( $in['lockdown_load'] ?? $d['lockdown_load'] ) ) ) ),
			'lockdown_crawlers' => empty( $in['lockdown_crawlers'] ) ? 0 : 1,
			'lockdown_agents'   => $txt( $in['lockdown_agents'] ?? '' ),
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
		// Manual lockdown: the countdown starts when it is switched on (or its duration changes).
		$old = Powned::settings();
		if ( 'on' === $out['lockdown'] ) {
			$keep = 'on' === $old['lockdown'] && (int) $old['lockdown_hours'] === $out['lockdown_hours'] && Powned_Lockdown::active();
			$out['lockdown_since'] = $keep ? (int) $old['lockdown_since'] : time();
		}
		if ( 'log' === $out['lockdown'] && 'log' !== $old['lockdown'] ) {
			delete_transient( Powned_Lockdown::LOG_KEY );
		}
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
	 * New signing secret: every solved block and open challenge becomes invalid, visitors mine
	 * once more. For when the secret may have leaked (backup, database copy).
	 */
	public static function rotate_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'powned' ), 403 );
		}
		check_admin_referer( 'powned_rotate_secret' );
		update_option( Powned::SECRET_OPTION, bin2hex( random_bytes( 32 ) ), true );
		add_settings_error( 'powned', 'powned_rotated', __( 'New secret created. All solved blocks are invalid now, visitors mine once more.', 'powned' ), 'success' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( admin_url( 'admin.php?page=powned&settings-updated=1' ) );
		exit;
	}

	/**
	 * Lockdown reminder on every admin page while it is enforcing (or logging).
	 */
	public static function notice_lockdown() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mode = Powned_Lockdown::mode();
		$link = ' <a href="' . esc_url( admin_url( 'admin.php?page=powned#powned-lockdown' ) ) . '">' . esc_html__( 'Lockdown settings', 'powned' ) . '</a>';
		if ( 'log' === $mode ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'PoWned lockdown is in log-only mode: nothing is blocked, the paths it would challenge are being counted.', 'powned' ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		if ( ! Powned_Lockdown::active() ) {
			return;
		}
		if ( 'auto' === $mode ) {
			$msg = __( 'PoWned lockdown is active: the server is under heavy load, every page view needs a solved block. It switches off by itself 15 minutes after the load drops.', 'powned' );
		} elseif ( Powned_Lockdown::remaining() ) {
			/* translators: %s: time left, e.g. "2 hours" */
			$msg = sprintf( __( 'PoWned lockdown is active: every page view needs a solved block. Search engines, link previews and price comparison sites only see the challenge page. Switches off by itself in %s.', 'powned' ), human_time_diff( time(), time() + Powned_Lockdown::remaining() ) );
		} else {
			$msg = __( 'PoWned lockdown is active without a time limit: every page view needs a solved block. Search engines, link previews and price comparison sites only see the challenge page. Remember to switch it off.', 'powned' );
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $msg ) . '</strong>' . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Warn when the setup defeats the gate: early loader not writable, a page cache in front of
	 * WordPress, or a reverse proxy (private REMOTE_ADDR, or forwarded headers that differ from
	 * it; those headers are only used to detect the proxy, never trusted). Every notice can be
	 * dismissed per admin user. Thanks to mptfire.
	 */
	public static function notice_loader() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_powned' !== $screen->id ) {
			return;
		}
		$s = Powned::settings();
		if ( ! empty( $s['early'] ) && ! Powned_Loader::installed() && ! Powned_Loader::install() ) {
			/* translators: %s: directory */
			self::notice( 'loader', sprintf( __( 'Early check is on, but the loader could not be written to %s. The check still runs, just a little later during loading.', 'powned' ), WPMU_PLUGIN_DIR ), false );
		}
		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			self::notice( 'cache', __( 'A page cache is active (WP_CACHE). Cached pages are served before PoWned runs. Make sure your cache plugin never caches the protected URLs (for example URLs with query strings; LiteSpeed caches them by default).', 'powned' ) );
		}
		$ra = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = Powned::client_ip();
		if ( 'none' !== $s['binding'] && '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			self::notice( 'proxy', __( 'PHP sees a private or reserved visitor address, which usually means a reverse proxy is in front. All visitors then share one network, and one solved block unlocks the site for everyone. Pass the real client address with the powned_client_ip filter (see the README), or set "Bound to" to "Not bound".', 'powned' ) );
		}
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? trim( (string) strtok( (string) $_SERVER['HTTP_X_FORWARDED_FOR'], ',' ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cf  = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $ip === $ra && ( ( '' !== $xff && $xff !== $ra ) || ( '' !== $cf && $cf !== $ra ) ) ) {
			self::notice( 'proxy_headers', __( 'Forwarded address headers (X-Forwarded-For, CF-Connecting-IP) differ from the visitor address: a reverse proxy or CDN is in front. All visitors then share the proxy\'s network. PoWned never trusts those headers on its own, they are easy to fake. Pass the real client address with the powned_client_ip filter (see the README), or set "Bound to" to "Not bound".', 'powned' ) );
		}
	}

	/**
	 * A warning notice, dismissible per admin user (nonce protected).
	 *
	 * @param string $key         Notice key.
	 * @param string $message     Text.
	 * @param bool   $dismissible Can it be dismissed?
	 */
	private static function notice( $key, $message, $dismissible = true ) {
		$user = get_current_user_id();
		if ( $dismissible && isset( $_GET['powned_dismiss'], $_GET['_wpnonce'] ) && sanitize_key( (string) $_GET['powned_dismiss'] ) === $key // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			&& wp_verify_nonce( sanitize_text_field( (string) $_GET['_wpnonce'] ), 'powned_dismiss_' . $key ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			update_user_meta( $user, 'powned_dismissed_' . $key, time() );
		}
		if ( $dismissible && get_user_meta( $user, 'powned_dismissed_' . $key, true ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p>';
		if ( $dismissible ) {
			$url = wp_nonce_url( add_query_arg( 'powned_dismiss', $key, admin_url( 'admin.php?page=powned' ) ), 'powned_dismiss_' . $key );
			echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss this notice', 'powned' ) . '</a></p>';
		}
		echo '</div>';
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
	 * Small column chart (server-rendered SVG, no script) of one daily value.
	 *
	 * @param array  $stats Output of Powned::stats().
	 * @param string $key   Value key.
	 * @param string $label Accessible label.
	 * @param string $color Bar color.
	 * @return string SVG.
	 */
	private static function chart( $stats, $key, $label, $color ) {
		$w   = 600;
		$h   = 90;
		$n   = count( $stats );
		$max = max( 1, max( array_column( $stats, $key ) ) );
		$bw  = $w / $n;
		$svg = '<svg viewBox="0 0 ' . $w . ' ' . ( $h + 16 ) . '" role="img" aria-label="' . esc_attr( $label ) . '" style="width:100%;max-width:640px;height:auto;display:block">';
		$i   = 0;
		foreach ( $stats as $day => $row ) {
			$v    = (int) $row[ $key ];
			$bh   = $v ? max( 2, $h * $v / $max ) : 0;
			$date = gmdate( 'd.m.', strtotime( $day ) );
			$svg .= '<rect x="' . round( $i * $bw + 1, 1 ) . '" y="' . round( $h - $bh, 1 ) . '" width="' . round( $bw - 2, 1 ) . '" height="' . round( $bh, 1 ) . '" rx="2" fill="' . esc_attr( $color ) . '"><title>' . esc_html( $date . ': ' . number_format_i18n( $v ) ) . '</title></rect>';
			// A label every 7 days, counted back from today; edge labels aligned inside.
			if ( 0 === ( $n - 1 - $i ) % 7 ) {
				$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
				$x      = 0 === $i ? 0 : ( $n - 1 === $i ? $w : $i * $bw + $bw / 2 );
				$svg   .= '<text x="' . round( $x, 1 ) . '" y="' . ( $h + 13 ) . '" font-size="10" text-anchor="' . $anchor . '" fill="#646970">' . esc_html( $date ) . '</text>';
			}
			$i++;
		}
		$svg .= '<line x1="0" x2="' . $w . '" y1="' . $h . '" y2="' . $h . '" stroke="#c3c4c7"/>';
		return $svg . '</svg>';
	}

	/**
	 * Card with the statistics of the last 30 days.
	 */
	private static function render_stats() {
		Powned::rollup();
		$st     = Powned::stats();
		$issued = array_sum( array_column( $st, 'issued' ) );
		$passed = array_sum( array_column( $st, 'passed' ) );
		$blocks = array_sum( array_column( $st, 'blocks' ) );
		$timed  = array_sum( array_column( $st, 'timed' ) );
		$ms     = array_sum( array_column( $st, 'ms' ) );
		$cache  = wp_using_ext_object_cache();
		$brand  = (string) Powned::settings()['brand_color'];
		$cell   = 'padding:4px 24px 4px 0;font-variant-numeric:tabular-nums';
		?>
		<div class="card" style="max-width:none">
			<h2 class="title"><?php esc_html_e( 'Last 30 days', 'powned' ); ?></h2>
			<table role="presentation" style="margin-bottom:12px">
				<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Blocks mined', 'powned' ); ?></td><th style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( number_format_i18n( $blocks ) ); ?></th></tr>
				<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Average mining time', 'powned' ); ?></td><th style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( $timed ? number_format_i18n( $ms / $timed / 1000, 1 ) . ' s' : '–' ); ?></th></tr>
				<?php if ( $cache ) : ?>
					<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Challenges shown', 'powned' ); ?></td><th style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( number_format_i18n( $issued ) ); ?></th></tr>
					<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Requests let through', 'powned' ); ?></td><th style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( number_format_i18n( $passed ) ); ?></th></tr>
					<tr><td style="<?php echo esc_attr( $cell ); ?>"><?php esc_html_e( 'Solve rate (blocks per challenge)', 'powned' ); ?></td><th style="<?php echo esc_attr( $cell ); ?>;text-align:right"><?php echo esc_html( $issued ? number_format_i18n( 100 * $blocks / $issued, 1 ) . ' %' : '–' ); ?></th></tr>
				<?php endif; ?>
			</table>
			<?php if ( $cache ) : ?>
				<p style="margin:12px 0 2px"><strong><?php esc_html_e( 'Challenges shown per day', 'powned' ); ?></strong></p>
				<?php echo self::chart( $st, 'issued', __( 'Challenges shown per day', 'powned' ), '#8c8f94' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php endif; ?>
			<p style="margin:12px 0 2px"><strong><?php esc_html_e( 'Blocks mined per day', 'powned' ); ?></strong></p>
			<?php echo self::chart( $st, 'blocks', __( 'Blocks mined per day', 'powned' ), $brand ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="description">
				<?php
				echo esc_html(
					$cache
						? __( 'A low solve rate means mostly bots got the challenge and gave up. Only daily totals are stored, nothing per visitor. Days in UTC.', 'powned' )
						: __( 'Blocks and mining time are always counted. Challenges shown and requests let through need a persistent object cache (Redis, Memcached), so that a flood of requests never turns into database writes. Days in UTC.', 'powned' )
				);
				?>
			</p>
		</div>
		<?php
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
			<?php settings_errors(); ?>
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
				<p><a class="button" href="<?php echo esc_url( add_query_arg( 'powned_preview', '1', home_url( '/' ) ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the challenge page', 'powned' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=powned_rotate_secret' ), 'powned_rotate_secret' ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Create a new secret? All solved blocks become invalid and every visitor mines once more. Do this if a backup or copy of your database may have leaked.', 'powned' ) ) ); ?>);"><?php esc_html_e( 'Rotate secret', 'powned' ); ?></a></p>
			</div>

			<?php self::render_stats(); ?>

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
					<tr><th scope="row"><?php esc_html_e( 'Protected actions', 'powned' ); ?></th>
						<td><fieldset>
							<label><input type="checkbox" name="<?php echo $name( 'checkout' ); // phpcs:ignore ?>" value="1" <?php checked( $s['checkout'] ); ?>> <?php esc_html_e( 'Checkout and express payment buttons', 'powned' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo $name( 'guard_coupon' ); // phpcs:ignore ?>" value="1" <?php checked( $s['guard_coupon'] ); ?>> <?php esc_html_e( 'Coupon codes', 'powned' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo $name( 'guard_login' ); // phpcs:ignore ?>" value="1" <?php checked( $s['guard_login'] ); ?>> <?php esc_html_e( 'Login forms (wp-login.php and the WooCommerce account page)', 'powned' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo $name( 'guard_forms' ); // phpcs:ignore ?>" value="1" <?php checked( $s['guard_forms'] ); ?>> <?php esc_html_e( 'Contact Form 7 and comments', 'powned' ); ?></label>
						</fieldset>
						<p class="description"><?php esc_html_e( 'These actions are only accepted with a solved block. On the pages where they happen (cart, checkout, product pages, login, forms) the browser mines in the background, and forms wait the moment it takes. No challenge page, no puzzle. Visitors without JavaScript cannot use protected actions. Logged-in users always pass. Payment webhooks and gateway callbacks are never checked, whatever the payment provider.', 'powned' ); ?></p>
						<p class="description"><?php esc_html_e( 'Checkout also shows the challenge page when someone opens the checkout without a block.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Stealth mode', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'stealth' ); // phpcs:ignore ?>" value="1" <?php checked( $s['stealth'] ); ?>> <?php esc_html_e( 'Challenge page shows only a small spinner instead of the block', 'powned' ); ?></label>
						<p class="description"><?php esc_html_e( 'The spinner appears after 0.6 seconds, so quick solves look like a normal page load.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Early check', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'early' ); // phpcs:ignore ?>" value="1" <?php checked( $s['early'] ); ?>> <?php esc_html_e( 'Run the check before other plugins load (adds a small file to mu-plugins)', 'powned' ); ?></label>
						<p class="description"><?php esc_html_e( 'Recommended: a crawler request then costs almost no server time.', 'powned' ); ?></p></td></tr>
				</table>

				<h2 id="powned-lockdown"><?php esc_html_e( 'Full lockdown', 'powned' ); ?></h2>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'For emergencies only. While the lockdown is active, every page view needs a solved block. Before you switch it on:', 'powned' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:20px">
						<li><?php esc_html_e( 'Search engines only see the challenge page. It is sent as "503, try again later", so a few hours do no harm, but days can cost rankings or pages in the index. Keep "Let search engines through" on.', 'powned' ); ?></li>
						<li><?php esc_html_e( 'Link previews in messengers and social networks, price comparison sites, product feeds (Google Merchant and similar) and uptime monitors cannot read your pages. Feeds may report errors and ads may be paused.', 'powned' ); ?></li>
						<li><?php esc_html_e( 'Visitors without JavaScript or with cookies blocked cannot open the site at all. On old phones, a raised difficulty can take several seconds.', 'powned' ); ?></li>
						<li><?php esc_html_e( 'Every new visitor waits a moment on the first page.', 'powned' ); ?></li>
						<li><?php esc_html_e( 'Pages your cache serves before WordPress loads are not checked (cheap anyway). The lockdown slows down bots that drive a real browser, it does not stop them, and it does not help against network-level DDoS.', 'powned' ); ?></li>
						<li><?php esc_html_e( 'Not affected: payment webhooks and callbacks of any provider, the REST API, Ajax, feeds, sitemaps, robots.txt, the thank-you page, logged-in users. Try "Log only" first to see which pages would be challenged.', 'powned' ); ?></li>
					</ul></div>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="pg-lockdown"><?php esc_html_e( 'Mode', 'powned' ); ?></label></th>
						<td><select id="pg-lockdown" name="<?php echo $name( 'lockdown' ); // phpcs:ignore ?>" data-was="<?php echo esc_attr( $s['lockdown'] ); ?>">
							<option value="off" <?php selected( $s['lockdown'], 'off' ); ?>><?php esc_html_e( 'Off', 'powned' ); ?></option>
							<option value="log" <?php selected( $s['lockdown'], 'log' ); ?>><?php esc_html_e( 'Log only (blocks nothing, shows what would be challenged)', 'powned' ); ?></option>
							<option value="auto" <?php selected( $s['lockdown'], 'auto' ); ?>><?php esc_html_e( 'Automatic (switches on under heavy load)', 'powned' ); ?></option>
							<option value="on" <?php selected( $s['lockdown'], 'on' ); ?>><?php esc_html_e( 'On', 'powned' ); ?></option>
						</select>
						<p class="description"><?php
						if ( 'off' !== Powned_Lockdown::mode() && 'log' !== Powned_Lockdown::mode() ) {
							echo esc_html( Powned_Lockdown::active() ? __( 'Status: active right now.', 'powned' ) : __( 'Status: not active right now.', 'powned' ) );
						}
						?></p></td></tr>
					<tr><th scope="row"><label for="pg-ld-hours"><?php esc_html_e( 'Switch off after', 'powned' ); ?></label></th>
						<td><input id="pg-ld-hours" type="number" min="0" max="168" class="small-text" name="<?php echo $name( 'lockdown_hours' ); // phpcs:ignore ?>" value="<?php echo (int) $s['lockdown_hours']; ?>"> <?php esc_html_e( 'hours (mode "On"; 0 = never, not recommended)', 'powned' ); ?></td></tr>
					<tr><th scope="row"><label for="pg-ld-load"><?php esc_html_e( 'Automatic at load', 'powned' ); ?></label></th>
						<td><input id="pg-ld-load" type="number" min="0.5" max="20" step="0.1" class="small-text" name="<?php echo $name( 'lockdown_load' ); // phpcs:ignore ?>" value="<?php echo esc_attr( (string) $s['lockdown_load'] ); ?>"> <?php esc_html_e( 'per CPU core, 5-minute average', 'powned' ); ?>
						<p class="description"><?php esc_html_e( 'Mode "Automatic": switches on when the load stays above this value and off again 15 minutes after it drops. 2.0 means twice as much work waiting as the server has cores.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Search engines', 'powned' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo $name( 'lockdown_crawlers' ); // phpcs:ignore ?>" value="1" <?php checked( $s['lockdown_crawlers'] ); ?>> <?php esc_html_e( 'Let search engines through (Google, Bing, Apple, DuckDuckGo, Yandex)', 'powned' ); ?></label>
						<p class="description"><?php esc_html_e( 'Verified by reverse DNS, so bots that only pretend to be Googlebot are not let through. Results are cached for a day.', 'powned' ); ?></p></td></tr>
					<tr><th scope="row"><label for="pg-ld-agents"><?php esc_html_e( 'Let through by user agent', 'powned' ); ?></label></th>
						<td><textarea id="pg-ld-agents" class="large-text code" rows="3" name="<?php echo $name( 'lockdown_agents' ); // phpcs:ignore ?>" placeholder="UptimeRobot&#10;Mastodon"><?php echo esc_textarea( $s['lockdown_agents'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, part of the user agent, for monitoring or feeds you rely on. User agents are easy to fake, so keep this list short. Paths are excluded in "Never protect" above.', 'powned' ); ?></p></td></tr>
					<?php
					$log = get_transient( Powned_Lockdown::LOG_KEY );
					if ( is_array( $log ) && $log ) :
						arsort( $log );
						?>
					<tr><th scope="row"><?php esc_html_e( 'Log', 'powned' ); ?></th>
						<td><p class="description"><?php esc_html_e( 'Pages the lockdown would have challenged (requests without a solved block), most frequent first. Look for pages that machines must reach, and add them to "Never protect".', 'powned' ); ?></p>
						<table class="widefat striped" style="max-width:640px"><tbody>
						<?php foreach ( array_slice( $log, 0, 25, true ) as $path => $n ) : ?>
							<tr><td><code><?php echo esc_html( $path ); ?></code></td><td style="text-align:right"><?php echo esc_html( number_format_i18n( $n ) ); ?></td></tr>
						<?php endforeach; ?>
						</tbody></table></td></tr>
					<?php endif; ?>
				</table>
				<script>
				document.getElementById('pg-lockdown').form.addEventListener('submit', function (e) {
					var sel = document.getElementById('pg-lockdown'), v = sel.value;
					if ((v === 'on' || v === 'auto') && v !== sel.dataset.was && !window.confirm(<?php echo wp_json_encode( __( "Switch on the full lockdown?\n\nWhile it is active, search engines, link previews, price comparison sites and visitors without JavaScript only see the challenge page. Payment webhooks keep working.", 'powned' ) ); ?>)) {
						e.preventDefault();
					}
				});
				</script>

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

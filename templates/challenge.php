<?php
/**
 * Challenge page: a block that fills while the browser mines. Standalone HTML (no theme), no
 * inline scripts or styles, so it also works under a strict Content-Security-Policy.
 * Configuration travels in data attributes.
 *
 * Available: $t (strings), $data (miner config), $base (assets URL), $ver, $logo, $back, $c,
 * $lang, $s, $height. Stealth mode ($s['stealth']) shows only a small spinner.
 *
 * @package Powned
 */

defined( 'ABSPATH' ) || exit;

$powned_e = static function ( $v ) {
	return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
};
$powned_attrs = '';
foreach ( $data as $k => $v ) {
	$powned_attrs .= ' data-' . $k . '="' . $powned_e( $v ) . '"';
}
$powned_height = function_exists( 'number_format_i18n' ) ? number_format_i18n( $height ) : number_format( $height );
?><!doctype html>
<html lang="<?php echo $powned_e( $lang ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo $powned_e( ! empty( $s['stealth'] ) ? $t['loading'] : $t['title'] ); ?></title>
<link rel="stylesheet" href="<?php echo $powned_e( $base . 'pw.css?ver=' . $ver ); ?>">
</head>
<body>
<main id="powned"<?php echo ! empty( $s['stealth'] ) ? ' class="pw-stealth"' : ''; ?><?php echo $powned_attrs; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
	<div class="pw-spin" aria-hidden="true"></div>
	<div class="pw-block" id="pw-block" aria-hidden="true">
		<?php echo str_repeat( '<i></i>', 64 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<svg class="pw-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
	</div>
	<h1><?php echo $powned_e( $t['title'] ); ?></h1>
	<p class="pw-height"><?php echo $powned_e( sprintf( $t['block'], $powned_height ) ); ?></p>
	<p class="pw-status" id="pw-status" role="status" aria-live="polite"><?php echo $powned_e( $t['mining'] ); ?></p>
	<div class="pw-details">
		<span><?php echo (int) $c['bits']; ?> bits<?php if ( $c['extra'] ) : ?> (+<?php echo (int) $c['extra']; ?> <?php echo $powned_e( $t['adj'] ); ?>)<?php endif; ?></span>
		<span id="pw-rate">– kH/s</span>
		<span><?php echo $powned_e( $t['nonce'] ); ?> <b id="pw-nonce">0</b></span>
		<span class="pw-hash" id="pw-hash">&nbsp;</span>
	</div>
	<noscript><p class="pw-foot"><?php echo $powned_e( $t['nojs'] ); ?> <a href="<?php echo $powned_e( $back ); ?>"><?php echo $powned_e( $t['back'] ); ?></a></p></noscript>
	<p class="pw-foot">
		<?php if ( $logo ) : ?><img src="<?php echo $powned_e( $logo ); ?>" alt="" width="16" height="16"><?php endif; ?>
		<?php echo $powned_e( $t['text'] . ' ' . sprintf( $t['cookie'], Powned::COOKIE, (int) $s['ttl_hours'] ) ); ?>
	</p>
</main>
<script src="<?php echo $powned_e( $base . 'pw-core.js?ver=' . $ver ); ?>"></script>
<script src="<?php echo $powned_e( $base . 'pw-ui.js?ver=' . $ver ); ?>"></script>
</body>
</html>

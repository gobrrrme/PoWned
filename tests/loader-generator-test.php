<?php
/**
 * Standalone test for the generated mu-plugin (PR audit/fix#3):
 *  1. generate the loader (single-site active)  -> php -l must pass
 *  2. generate the loader (network activated)   -> php -l must pass
 *  3. execute the generated file, inactive      -> clean no-op (no fatal)
 *  4. execute network-active with WP stubs      -> gate renders the challenge
 *
 * Run: php tests/loader-generator-test.php
 */
error_reporting( E_ALL );

$ROOT = dirname( __DIR__ );   // plugin folder = repo root (contains src/)
$WP   = dirname( $ROOT );     // fake wp-content parent
define( 'ABSPATH', $ROOT . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WP_PLUGIN_DIR', $WP );
define( 'POWNED_FILE', $WP . '/src/powned.php' );
define( 'POWNED_VERSION', '0.1.0' );
$GLOBALS['mu'] = sys_get_temp_dir() . '/powned-test-mu-' . getmypid();
define( 'WPMU_PLUGIN_DIR', $GLOBALS['mu'] );
@mkdir( WPMU_PLUGIN_DIR, 0755, true );
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0755, true ); }
function plugin_basename( $f ) { return 'src/powned.php'; }
function is_multisite() { return false; }

require dirname( __DIR__ ) . '/includes/class-powned-loader.php';

$fail = 0;
function check( $label, $cond ) {
	global $fail;
	echo ( $cond ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) $fail++;
}
function lint( $path, $label ) {
	$out = shell_exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1' );
	$ok  = str_contains( (string) $out, 'No syntax errors' );
	check( "$label: " . preg_replace( '/\s+/', ' ', trim( (string) $out ) ), $ok );
	return $ok;
}

$GLOBALS['active_plugins'] = array( 'src/powned.php' );

check( 'install() wrote the loader (single-site)', Powned_Loader::install() && is_file( Powned_Loader::path() ) );
lint( Powned_Loader::path(), 'php -l generated loader (single-site)' );
$gen_single = file_get_contents( Powned_Loader::path() );

$GLOBALS['sitewide'] = array( 'src/powned.php' => time() );
check( 'install() regenerated the loader (network-active)', Powned_Loader::install() );
lint( Powned_Loader::path(), 'php -l generated loader (network-active)' );
$gen_multi = file_get_contents( Powned_Loader::path() );
check( 'network variant guards via $sw (no isset((array) fatal))', str_contains( $gen_multi, "isset( \$sw['src/powned.php'] )" ) );
$gen_multi_body = preg_replace( '/^<\?php\s*/', '', $gen_multi );
$gen_single_body = preg_replace( '/^<\?php\s*/', '', $gen_single );

// Child stub header: real values, no placeholders.
$stub_header = "<?php\n"
	. "define( 'ABSPATH', " . var_export( $ROOT . '/', true ) . " );\n"
	. "define( 'WP_PLUGIN_DIR', " . var_export( $WP, true ) . " );\n"
	. "define( 'POWNED_VERSION', '0.1.0' );\n"
	. "define( 'HOUR_IN_SECONDS', 3600 );\n"
	. "define( 'MINUTE_IN_SECONDS', 60 );\n"
	. "define( 'DAY_IN_SECONDS', 86400 );\n"
	. "\$_SERVER['REMOTE_ADDR'] = '203.0.113.10';\n"
	. "\$_SERVER['REQUEST_URI'] = '/shop/?filter_color=red';\n"
	. "\$_SERVER['REQUEST_METHOD'] = 'GET';\n"
	. "\$_GET = array( 'filter_color' => 'red' );\n"
	. "function get_option( \$n, \$d = false ) { return \$GLOBALS['o'][ \$n ] ?? \$d; }\n"
	. "function get_site_option( \$n, \$d = false ) { return \$GLOBALS['sw'][ \$n ] ?? \$d; }\n"
	. "function add_option( \$n, \$v, \$d = '', \$a = true ) { \$GLOBALS['o'][ \$n ] ??= \$v; return true; }\n"
	. "function is_ssl() { return true; }\n"
	. "function apply_filters( \$t, \$v ) { return \$v; }\n"
	. "function get_locale() { return 'en_US'; }\n"
	. "function wp_parse_url( \$u, \$c = -1 ) { return parse_url( \$u, \$c ); }\n"
	. "function home_url( \$p = '' ) { return 'https://shop.example' . \$p; }\n"
	. "function plugins_url( \$p = '', \$f = '' ) { return 'https://shop.example/wp-content/plugins/powned/' . \$p; }\n"
	. "function get_site_icon_url( \$s = 512 ) { return ''; }\n"
	. "function wp_using_ext_object_cache() { return false; }\n"
	. "function get_transient( \$k ) { return false; }\n"
	. "function set_transient( \$k, \$v, \$e = 0 ) { return true; }\n"
	. "function wp_cache_add( ...\$a ) { return true; }\n"
	. "function wp_cache_set( ...\$a ) { return true; }\n"
	. "function wp_cache_delete( ...\$a ) { return true; }\n"
	. "class FakeWdb { public \$options = 'wp_options';\n"
	. "\tpublic function prepare( \$q, ...\$a ) { return \$q; }\n"
	. "\tpublic function query( \$q ) { return 1; }\n"
	. "\tpublic function get_var( \$q ) { return '0'; } }\n"
	. "\$GLOBALS['wpdb'] = new FakeWdb();\n"
	. "\$GLOBALS['o'] = array( 'powned_settings' => null, 'powned_secret' => 'aa55', 'active_plugins' => array() );\n"
	. "\$GLOBALS['sw'] = array( 'active_sitewide_plugins' => array( 'src/powned.php' => 1 ) );\n";

// 3. inactive: multisite off -> the sitewide branch is skipped -> silent no-op
$child = WPMU_PLUGIN_DIR . '/child-inactive.php';
file_put_contents( $child, $stub_header . "function is_multisite() { return false; }\n" . $gen_single_body );
$out = shell_exec( 'php -d error_reporting=E_ERROR ' . escapeshellarg( $child ) . ' 2>&1' );
check( 'execute (inactive): clean no-op, no fatal', '' === trim( (string) $out ) );

// 4. network-active: gate must render the challenge
$child = WPMU_PLUGIN_DIR . '/child-active.php';
file_put_contents( $child, $stub_header . "function is_multisite() { return true; }\n" . $gen_multi_body );
$out2 = shell_exec( 'php -d error_reporting=E_ERROR ' . escapeshellarg( $child ) . ' 2>&1' );
check( 'execute (network-active): gate rendered the challenge', str_contains( (string) $out2, 'Mining requested page' ) );
check( 'execute (network-active): no fatal', ! str_contains( (string) $out2, 'Fatal error' ) );
if ( ! str_contains( (string) $out2, 'Mining requested page' ) ) {
	echo "--- active child output ---\n" . substr( (string) $out2, 0, 600 ) . "\n--- generated (head) ---\n" . substr( $gen_multi_body, 0, 200 ) . "\n";
}

// cleanup (leave generated files in WPMU_PLUGIN_DIR on failure for inspection)
@unlink( WPMU_PLUGIN_DIR . '/child-inactive.php' );
if ( $fail === 0 ) {
	@unlink( WPMU_PLUGIN_DIR . '/child-active.php' );
	@unlink( Powned_Loader::path() );
	@rmdir( WPMU_PLUGIN_DIR );
}

echo $fail ? "\n$fail FAILURES\n" : "\nall loader-generator checks passed\n";
exit( $fail ? 1 : 0 );

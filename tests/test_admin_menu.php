<?php
// Sol menüde üst düzey "Yazıyı Seslendir" öğesi olarak kaydedildiğini (Araçlar altında değil) doğrular.
require __DIR__ . '/wp_stubs.php';

$GLOBALS['__menu'] = array();
$GLOBALS['__mgmt'] = 0;
function add_menu_page( $title, $menu, $cap, $slug, $cb, $icon, $pos ) {
	$GLOBALS['__menu'] = compact( 'title', 'menu', 'cap', 'slug', 'icon', 'pos' );
	return 'toplevel_page_' . $slug;
}
function add_management_page() {
	++$GLOBALS['__mgmt'];
	return 'tools_page_x';
}
function admin_url( $p = '' ) {
	return 'https://example.org/wp-admin/' . $p;
}
function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}

require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-admin.php';

$fail = 0;
$n    = 0;
function t( $name, $got, $want = true ) {
	global $fail, $n;
	++$n;
	if ( $got !== $want ) {
		++$fail;
		echo "FAIL: $name\n  got:  " . var_export( $got, true ) . "\n  want: " . var_export( $want, true ) . "\n";
	}
}

YS_Admin::menu();
$m = $GLOBALS['__menu'];
t( 'üst düzey menü eklendi', $m['slug'], 'yaziyi-seslendir' );
t( 'menü adı', $m['menu'], 'Yazıyı Seslendir' );
t( 'yetki edit_posts', $m['cap'], 'edit_posts' );
t( 'ikon', $m['icon'], 'dashicons-controls-volumeon' );
t( 'konum Yorumlar sonrası', $m['pos'], 26 );
t( 'Araçlar altına eklenmedi', $GLOBALS['__mgmt'], 0 );
t( 'sayfa adresi admin.php', YS_Admin::page_url( array( 'ys_start' => 5 ) ), 'https://example.org/wp-admin/admin.php?page=yaziyi-seslendir&ys_start=5' );

// Eklenti başlığı: WordPress 7.0, PHP 8.5.
$header = (string) file_get_contents( dirname( __DIR__ ) . '/yaziyi-seslendir.php' );
t( 'WordPress 7.0 şartı', 1 === preg_match( '/^\s*\*\s*Requires at least:\s*7\.0\s*$/m', $header ), true );
t( 'PHP 8.5 şartı', 1 === preg_match( '/^\s*\*\s*Requires PHP:\s*8\.5\s*$/m', $header ), true );
t( 'sürüm sabiti başlıkla aynı', 1 === preg_match( '/Version:\s*(\S+)/', $header, $a ) && false !== strpos( $header, "define( 'YS_VERSION', '" . $a[1] . "' )" ), true );

echo $fail ? "$fail / $n FAILED\n" : "OK ($n assertions): " . basename( __FILE__ ) . "\n";
exit( $fail ? 1 : 0 );

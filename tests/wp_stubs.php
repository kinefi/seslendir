<?php
// WordPress dışında eklentinin saf mantığını sınamak için asgari WordPress taklitleri.
define( 'ABSPATH', '/tmp/' );
define( 'YS_VERSION', 'test' );
define( 'YS_FORMAT', 1 );
define( 'YS_MAX_CHARS', 60000 );

$GLOBALS['__opts'] = array();

function add_action() {}
function add_filter() {}
function add_shortcode() {}
function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}
function register_setting() {}
function wp_parse_args( $a, $d ) {
	return array_merge( $d, (array) $a );
}
function wp_json_encode( $v, $f = 0 ) {
	return json_encode( $v, $f );
}
function sanitize_key( $s ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}
function post_type_exists( $t ) {
	return in_array( $t, array( 'post', 'page', 'urun' ), true );
}
function get_post_types() {
	return array(
		'post'       => 'post',
		'page'       => 'page',
		'attachment' => 'attachment',
	);
}
function wp_convert_hr_to_bytes( $v ) {
	$v = strtolower( trim( (string) $v ) );
	$n = (int) $v;
	switch ( substr( $v, -1 ) ) {
		case 'g':
			$n *= 1024;
			// no break.
		case 'm':
			$n *= 1024;
			// no break.
		case 'k':
			$n *= 1024;
	}
	return $n;
}

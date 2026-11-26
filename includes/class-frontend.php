<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ön yüz: hazır sesi çalan, JavaScript'siz hafif oynatıcı.
 * Yalnızca sesi olan ve içeriği değişmemiş yazılarda görünür; başka hiçbir sayfaya dokunmaz.
 */
class YS_Frontend {

	private static $style_printed = false;
	private static $shortcode_used = array();

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'prepend' ), 20 );
		add_shortcode( 'seslendir', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'yaziyi_seslendir', array( __CLASS__, 'shortcode' ) ); // Eski ad: geriye dönük uyumluluk.
	}

	public static function prepend( $content ) {
		if ( ! YS_Settings::get( 'autoinsert' ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post || isset( self::$shortcode_used[ $post->ID ] ) ) {
			return $content;
		}
		return self::player( $post ) . $content;
	}

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'seslendir' );
		$post = $atts['id'] ? get_post( (int) $atts['id'] ) : get_post();
		if ( ! $post ) {
			return '';
		}
		self::$shortcode_used[ $post->ID ] = true;
		return self::player( $post );
	}

	/** Oynatıcı HTML'i; ses yoksa ya da yazı sonradan değiştiyse boş döner. */
	public static function player( $post ) {
		if ( ! in_array( $post->post_type, YS_Settings::post_types(), true ) || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return '';
		}
		$att = YS_Storage::attachment_id( $post->ID );
		if ( ! $att ) {
			return '';
		}
		$sig = (string) get_post_meta( $post->ID, YS_Storage::META_SIG, true );
		if ( ! hash_equals( $sig, YS_Storage::src_sig( $post ) ) ) {
			return ''; // Yazı sesten sonra değişmiş: eski sesi göstermeyiz.
		}
		$url = wp_get_attachment_url( $att );
		if ( ! $url ) {
			return '';
		}

		$out = '';
		if ( ! self::$style_printed ) {
			self::$style_printed = true;
			$out                .= '<style>.ys-player{margin:0 0 1.5em;padding:.75em 1em;border:1px solid rgba(128,128,128,.35);border-radius:8px}.ys-player .ys-label{display:block;margin-bottom:.4em;font-weight:600}.ys-player audio{display:block;width:100%}</style>';
		}
		$label = (string) YS_Settings::get( 'label' );
		$out  .= '<div class="ys-player" role="group" aria-label="' . esc_attr( $label ) . '">';
		$out  .= '<span class="ys-label">' . esc_html( $label ) . '</span>';
		$out  .= '<audio controls preload="none" src="' . esc_url( $url ) . '"></audio>';
		$out  .= '</div>';
		return $out;
	}
}

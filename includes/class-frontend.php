<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ön yüz: hazır sesi çalan, JavaScript'siz hafif oynatıcı.
 * Yalnızca sesi olan ve içeriği değişmemiş yazılarda görünür; başka hiçbir sayfaya dokunmaz.
 */
class YS_Frontend {

	private static $style_printed = false;
	private static $shortcode_used = array();
	private static $auto_used = array();

	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'prepend' ), 20 );
		add_shortcode( 'seslendir', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'yaziyi_seslendir', array( __CLASS__, 'shortcode' ) ); // Eski ad: geriye dönük uyumluluk.
	}

	public static function prepend( $content ) {
		if ( ! YS_Settings::get( 'autoinsert' ) || ! is_singular() ) {
			return $content;
		}
		$post = get_post();
		// Ana yazı mı? in_the_loop()/is_main_query() bazı tema ve sayfa oluşturucularda yanlış döner; kimlik karşılaştırması güvenilirdir.
		if ( ! $post || (int) $post->ID !== (int) get_queried_object_id() || isset( self::$shortcode_used[ $post->ID ] ) || isset( self::$auto_used[ $post->ID ] ) ) {
			return $content;
		}
		self::$auto_used[ $post->ID ] = true;
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

	/** Oynatıcı gösterilmediğinde sayfa kaynağına neden yazılır (yalnızca sesi olan yazılarda). */
	private static function why( $post, $reason ) {
		return '<!-- seslendir: oynatici gizli (' . esc_html( $reason ) . ') -->';
	}

	/** Oynatıcı HTML'i; ses yoksa ya da yazı sonradan değiştiyse boş (yalnızca açıklayıcı bir yorum) döner. */
	public static function player( $post ) {
		$att = YS_Storage::attachment_id( $post->ID );
		if ( ! $att ) {
			// Kayıt var ama dosya yoksa nedenini yazalım; hiç ses üretilmemiş yazılarda sessiz kalırız.
			return get_post_meta( $post->ID, YS_Storage::META_ATT, true ) ? self::why( $post, 'ses dosyasi bulunamadi (silinmis ya da tasinmis olabilir)' ) : '';
		}
		if ( ! in_array( $post->post_type, YS_Settings::post_types(), true ) ) {
			return self::why( $post, 'yazi turu ayarlarda secili degil: ' . $post->post_type );
		}
		if ( 'publish' !== $post->post_status ) {
			return self::why( $post, 'yazi yayimlanmamis: ' . $post->post_status );
		}
		if ( post_password_required( $post ) ) {
			return self::why( $post, 'parola korumali' );
		}
		$stale = false;
		$sig   = (string) get_post_meta( $post->ID, YS_Storage::META_SIG, true );
		if ( ! hash_equals( $sig, YS_Storage::src_sig( $post ) ) ) {
			// Ham içerik değişmiş olabilir ama okunacak metin aynıdır: yönetim ekranındaki ölçütle denetler.
			if ( 'ok' === YS_Storage::state( $post ) ) {
				update_post_meta( $post->ID, YS_Storage::META_SIG, YS_Storage::src_sig( $post ) );
			} else {
				$stale = true; // Yazı sesten sonra güncellenmiş: sesi gizlemeyiz, yalnızca not düşeriz.
			}
		}
		$url = wp_get_attachment_url( $att );
		if ( ! $url ) {
			return self::why( $post, 'ses dosyasinin adresi alinamadi' );
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
		if ( $stale ) {
			$out .= '<span class="ys-note" style="display:block;margin-top:.4em;font-size:.85em;opacity:.75">' . esc_html( (string) apply_filters( 'ys_stale_note', 'Yazı bu ses kaydından sonra güncellendi; kayıt yazının son halini yansıtmayabilir.' ) ) . '</span>';
		}
		$out  .= '</div>';
		return $out;
	}
}

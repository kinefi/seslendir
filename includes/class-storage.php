<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ses dosyalarının Medya Kitaplığı'nda saklanması ve durum hesabı.
 */
class YS_Storage {

	const META_ATT  = '_ys_att_id';
	const META_HASH = '_ys_hash';
	const META_DUR  = '_ys_duration';
	const META_SIG  = '_ys_src_sig';
	const META_TIME = '_ys_time';
	const META_FOR  = '_ys_audio_for';

	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
	}

	/* ---------- Saf yardımcılar (WordPress gerekmez) ---------- */

	/** Baştaki bayt işaretlerinden biçimi bulur. Yalnızca wav ve mp3 kabul edilir. */
	public static function detect_format( $b ) {
		$b = (string) $b;
		if ( strlen( $b ) >= 44 && 'RIFF' === substr( $b, 0, 4 ) && 'WAVE' === substr( $b, 8, 4 ) && 'fmt ' === substr( $b, 12, 4 ) ) {
			return 'wav';
		}
		if ( strlen( $b ) >= 4 ) {
			if ( 'ID3' === substr( $b, 0, 3 ) ) {
				return 'mp3';
			}
			$w = unpack( 'n', substr( $b, 0, 2 ) );
			if ( $w && ( $w[1] & 0xFFE0 ) === 0xFFE0 ) {
				return 'mp3';
			}
		}
		return null;
	}

	/** PCM WAV süresi (sn). PCM dışındaysa null. */
	public static function wav_duration( $b ) {
		$b = (string) $b;
		if ( strlen( $b ) < 44 ) {
			return null;
		}
		$h = unpack( 'vfmt/vch/Vsr/Vbr', substr( $b, 20, 12 ) );
		if ( ! $h || 1 !== $h['fmt'] || $h['ch'] < 1 || $h['ch'] > 2 || $h['br'] < 1 ) {
			return null;
		}
		return round( ( strlen( $b ) - 44 ) / $h['br'], 2 );
	}

	/** Ses metni + ses ayarları imzası. Değişirse ses "eski" olur. */
	public static function hash_for( array $chunks ) {
		return sha1(
			wp_json_encode(
				array(
					YS_FORMAT,
					$chunks,
					(float) YS_Settings::get( 'speed' ),
					(int) YS_Settings::get( 'speaker' ),
					YS_Settings::model() . '|' . (string) YS_Settings::get( 'voice_tag' ),
				)
			)
		);
	}

	/** Ön yüzde ucuz karşılaştırma için başlık+içerik imzası. */
	public static function src_sig( $post ) {
		return md5( $post->post_title . "\n" . $post->post_content );
	}

	/* ---------- Durum ---------- */

	public static function attachment_id( $post_id ) {
		$att = (int) get_post_meta( $post_id, self::META_ATT, true );
		if ( ! $att ) {
			return 0;
		}
		$file = get_attached_file( $att );
		return ( $file && is_file( $file ) ) ? $att : 0;
	}

	/** @return string none|ok|stale */
	public static function state( $post ) {
		if ( ! self::attachment_id( $post->ID ) ) {
			return 'none';
		}
		$chunks = YS_Normalizer_TR::chunks_for_post( $post, (bool) YS_Settings::get( 'read_title' ) );
		$stored = (string) get_post_meta( $post->ID, self::META_HASH, true );
		return hash_equals( $stored, self::hash_for( $chunks ) ) ? 'ok' : 'stale';
	}

	public static function describe( $post, $state = null ) {
		$state = null === $state ? self::state( $post ) : $state;
		$att   = self::attachment_id( $post->ID );
		$file  = $att ? get_attached_file( $att ) : '';
		return array(
			'id'       => (int) $post->ID,
			'title'    => html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' ),
			'edit'     => get_edit_post_link( $post->ID, 'raw' ),
			'view'     => get_permalink( $post ),
			'date'     => get_the_date( 'Y-m-d', $post ),
			'state'    => $state,
			'url'      => $att ? wp_get_attachment_url( $att ) : '',
			'duration' => $att ? (float) get_post_meta( $post->ID, self::META_DUR, true ) : 0,
			'size'     => ( $file && is_file( $file ) ) ? (int) filesize( $file ) : 0,
			'time'     => $att ? (int) get_post_meta( $post->ID, self::META_TIME, true ) : 0,
		);
	}

	/* ---------- Kaydet / sil ---------- */

	/**
	 * @return array|WP_Error
	 */
	public static function save_audio( $post, $bytes, $hash, $client_duration ) {
		$fmt = self::detect_format( $bytes );
		if ( ! $fmt ) {
			return new WP_Error( 'ys_bad_format', 'Desteklenmeyen ses biçimi. Yalnızca WAV ve MP3 kabul edilir.', array( 'status' => 415 ) );
		}
		$dur = 'wav' === $fmt ? self::wav_duration( $bytes ) : (float) $client_duration;
		if ( null === $dur || $dur < 0.5 || $dur > 86400 ) {
			return new WP_Error( 'ys_bad_audio', 'Ses dosyası geçersiz görünüyor.', array( 'status' => 422 ) );
		}

		self::delete_audio( $post->ID );

		$name = sprintf( 'yazi-%d-%s.%s', $post->ID, substr( $hash, 0, 8 ), $fmt );
		$up   = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'ys_upload', 'Dosya kaydedilemedi: ' . $up['error'], array( 'status' => 500 ) );
		}

		$att = wp_insert_attachment(
			array(
				'post_mime_type' => 'wav' === $fmt ? 'audio/wav' : 'audio/mpeg',
				'post_title'     => 'Seslendirme: ' . wp_strip_all_tags( get_the_title( $post ) ),
				'post_status'    => 'inherit',
				'post_parent'    => $post->ID,
			),
			$up['file'],
			$post->ID,
			true
		);
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $up['file'] );
			return $att;
		}

		update_post_meta( $att, self::META_FOR, $post->ID );
		update_post_meta( $post->ID, self::META_ATT, $att );
		update_post_meta( $post->ID, self::META_HASH, $hash );
		update_post_meta( $post->ID, self::META_DUR, $dur );
		update_post_meta( $post->ID, self::META_SIG, self::src_sig( $post ) );
		update_post_meta( $post->ID, self::META_TIME, time() );

		return self::describe( $post, 'ok' );
	}

	public static function delete_audio( $post_id ) {
		$att = (int) get_post_meta( $post_id, self::META_ATT, true );
		foreach ( array( self::META_ATT, self::META_HASH, self::META_DUR, self::META_SIG, self::META_TIME ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
		if ( $att && get_post( $att ) && (int) get_post_meta( $att, self::META_FOR, true ) === (int) $post_id ) {
			wp_delete_attachment( $att, true );
		}
	}

	public static function on_delete_post( $post_id ) {
		if ( get_post_meta( $post_id, self::META_ATT, true ) ) {
			self::delete_audio( $post_id );
		}
	}

	/** Medya Kitaplığı'ndan elle silinen sesin yazıdaki izini temizler. */
	public static function on_delete_attachment( $att_id ) {
		$post_id = (int) get_post_meta( $att_id, self::META_FOR, true );
		if ( $post_id && (int) get_post_meta( $post_id, self::META_ATT, true ) === (int) $att_id ) {
			foreach ( array( self::META_ATT, self::META_HASH, self::META_DUR, self::META_SIG, self::META_TIME ) as $key ) {
				delete_post_meta( $post_id, $key );
			}
		}
	}
}

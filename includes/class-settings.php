<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ayarlar ve ses motoru dosyalarının konumu.
 */
class YS_Settings {

	const OPT   = 'ys_settings';
	const GROUP = 'ys_settings_group';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function defaults() {
		return array(
			'post_types'  => array( 'post' ),
			'read_title'  => 1,
			'speed'       => 1.0,
			'speaker'     => 0,
			'autoinsert'  => 1,
			'show_column' => 1,
			'confirm'     => 1, // Tek yazı üretiminde kaydetmeden önce dinletip onay iste.
			'label'       => 'Yazıyı Seslendir',
			'max_mb'      => 40,
			'voice_tag'   => 'fettah-1',
		);
	}

	public static function all() {
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Etkin yazı türleri (yalnızca gerçekten kayıtlı olanlar).
	 */
	public static function post_types() {
		$types = (array) self::get( 'post_types' );
		$out   = array();
		foreach ( $types as $type ) {
			if ( post_type_exists( $type ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$def = self::defaults();
		$out = array();

		$out['post_types'] = array();
		if ( ! empty( $in['post_types'] ) && is_array( $in['post_types'] ) ) {
			$public = get_post_types( array( 'public' => true ) );
			foreach ( $in['post_types'] as $type ) {
				$type = sanitize_key( $type );
				if ( 'attachment' !== $type && isset( $public[ $type ] ) ) {
					$out['post_types'][] = $type;
				}
			}
		}

		foreach ( array( 'read_title', 'autoinsert', 'show_column', 'confirm' ) as $flag ) {
			$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
		}

		$speed        = isset( $in['speed'] ) ? (float) str_replace( ',', '.', (string) $in['speed'] ) : $def['speed'];
		$out['speed'] = round( min( 1.6, max( 0.6, $speed ) ), 2 );

		$out['speaker'] = isset( $in['speaker'] ) ? max( 0, (int) $in['speaker'] ) : 0;
		$out['max_mb']  = isset( $in['max_mb'] ) ? min( 500, max( 1, (int) $in['max_mb'] ) ) : $def['max_mb'];

		$label        = isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : '';
		$out['label'] = '' === $label ? $def['label'] : mb_substr( $label, 0, 60 );

		$tag              = isset( $in['voice_tag'] ) ? preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $in['voice_tag'] ) : '';
		$out['voice_tag'] = '' === $tag ? $def['voice_tag'] : substr( $tag, 0, 40 );

		return $out;
	}

	/* ---------- Ses motoru dosyaları ---------- */

	/** Desteklenen motor türleri. */
	const ENGINE_KINDS = array( 'sherpa', 'piper-ort' );

	/**
	 * Etkin motor türü: "sherpa" (sherpa-onnx WASM) ya da "piper-ort" (ONNX Runtime Web + Piper).
	 * wp-config.php içinde YS_ENGINE_KIND ile ya da ys_engine_kind süzgeciyle değiştirilir.
	 */
	public static function engine_kind() {
		$kind = defined( 'YS_ENGINE_KIND' ) ? (string) constant( 'YS_ENGINE_KIND' ) : 'sherpa';
		$kind = (string) apply_filters( 'ys_engine_kind', $kind );
		return in_array( $kind, self::ENGINE_KINDS, true ) ? $kind : 'sherpa';
	}

	/** Zorunlu motor dosyaları (etkin motor türüne göre). */
	public static function engine_required( $kind = null ) {
		$kind    = null === $kind ? self::engine_kind() : $kind;
		$phonem  = array(
			'piper-phonemize.js',
			'piper-phonemize-wasm-main.js',
			'piper-phonemize-wasm-main.wasm',
			'piper-phonemize-wasm-main.data',
		);
		if ( 'piper-ort' === $kind ) {
			return array_merge(
				array(
					'ort.wasm.min.js',
					'ort-wasm-simd-threaded.js',
					'ort-wasm-simd-threaded.wasm',
					'voice.onnx',
					'voice.onnx.json',
				),
				$phonem
			);
		}
		return array_merge(
			array(
				'sherpa-onnx-tts.js',
				'sherpa-onnx-wasm-main-tts.js',
				'sherpa-onnx-wasm-main-tts.wasm',
				'sherpa-onnx-wasm-main-tts.data',
			),
			$phonem
		);
	}

	/** İsteğe bağlı: MP3 kodlayıcı (lamejs). */
	public static function engine_optional() {
		return array( 'lame.min.js' );
	}

	public static function engine_allowed_names() {
		return array_merge( self::engine_required(), self::engine_optional() );
	}

	public static function engine_dir() {
		$u = wp_upload_dir();
		return rtrim( $u['basedir'], '/\\' ) . '/yaziyi-seslendir-motor';
	}

	public static function engine_url() {
		$u = wp_upload_dir();
		return rtrim( $u['baseurl'], '/' ) . '/yaziyi-seslendir-motor/';
	}

	/**
	 * @return array{ready:bool,files:array,lame:bool}
	 */
	public static function engine_status() {
		$dir   = self::engine_dir();
		$files = array();
		$ready = true;
		foreach ( self::engine_allowed_names() as $name ) {
			$path           = $dir . '/' . $name;
			$size           = is_file( $path ) ? (int) filesize( $path ) : 0;
			$files[ $name ] = $size;
			if ( 0 === $size && in_array( $name, self::engine_required(), true ) ) {
				$ready = false;
			}
		}
		return array(
			'ready' => $ready,
			'files' => $files,
			'lame'  => $files['lame.min.js'] > 0,
		);
	}

	/**
	 * Tek bir yüklemede kabul edilen en büyük bayt sayısı (ayar ve PHP post_max_size).
	 */
	public static function max_bytes() {
		$setting = (int) self::get( 'max_mb' ) * 1024 * 1024;
		$post    = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		if ( $post > 0 ) {
			$setting = min( $setting, max( 1024 * 1024, $post - 1024 * 1024 ) );
		}
		return $setting;
	}
}

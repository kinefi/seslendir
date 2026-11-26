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
			'label'       => 'Yazıyı dinleyin',
			'max_mb'      => 40,
			'model'       => 'piper-sherpa',
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

		$out['model'] = YS_Models::valid_id( isset( $in['model'] ) ? (string) $in['model'] : $def['model'] );

		$tag              = isset( $in['voice_tag'] ) ? preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $in['voice_tag'] ) : '';
		$out['voice_tag'] = '' === $tag ? $def['voice_tag'] : substr( $tag, 0, 40 );

		return $out;
	}

	/* ---------- Ses motoru dosyaları ---------- */

	/** Seçili model kimliği (Ayarlar → Ses modeli). ys_model süzgeci ile geçersiz kılınabilir. */
	public static function model() {
		return YS_Models::valid_id( (string) apply_filters( 'ys_model', (string) self::get( 'model' ) ) );
	}

	/** Seçili modelin motor türü: "sherpa", "piper-ort" ya da "okur". */
	public static function engine_kind() {
		return YS_Models::kind( self::model() );
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
		if ( 'okur' === $kind ) {
			// Okur TTS: fonemleştirici gerekmez; metin Okur'un kendi Rust/WASM ön yüzünden geçer.
			return array(
				'ort.wasm.min.js',
				'ort-wasm-simd-threaded.js',
				'ort-wasm-simd-threaded.wasm',
				'okur-config.json',
				'okur-frontend.wasm',
				'okur-text.onnx',
				'okur-sample.onnx',
				'okur-decoder.onnx',
			);
		}
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

	public static function engine_allowed_names( $kind = null ) {
		return array_merge( self::engine_required( $kind ), self::engine_optional() );
	}

	/** Motor klasörünün kökü: uploads/seslendir-motor. Her modelin kendi alt klasörü vardır. */
	public static function engine_root() {
		$u = wp_upload_dir();
		return rtrim( $u['basedir'], '/\\' ) . '/seslendir-motor';
	}

	public static function engine_dir( $model = null ) {
		return self::engine_root() . '/' . YS_Models::valid_id( null === $model ? self::model() : $model );
	}

	public static function engine_url( $model = null ) {
		$u = wp_upload_dir();
		return rtrim( $u['baseurl'], '/' ) . '/seslendir-motor/' . YS_Models::valid_id( null === $model ? self::model() : $model ) . '/';
	}

	/**
	 * @return array{ready:bool,files:array,lame:bool}
	 */
	public static function engine_status( $model = null ) {
		$model    = YS_Models::valid_id( null === $model ? self::model() : $model );
		$kind     = YS_Models::kind( $model );
		$dir      = self::engine_dir( $model );
		$required = self::engine_required( $kind );
		$files    = array();
		$ready    = true;
		foreach ( self::engine_allowed_names( $kind ) as $name ) {
			$path           = $dir . '/' . $name;
			$size           = is_file( $path ) ? (int) filesize( $path ) : 0;
			$files[ $name ] = $size;
			if ( 0 === $size && in_array( $name, $required, true ) ) {
				$ready = false;
			}
		}
		return array(
			'ready' => $ready,
			'files' => $files,
			'lame'  => ( $files['lame.min.js'] ?? 0 ) > 0,
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

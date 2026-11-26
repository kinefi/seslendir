<?php
defined( 'ABSPATH' ) || exit;

/**
 * Desteklenen ses modelleri. Ayarlar sayfasında bu liste gösterilir; Kurulum sekmesi seçili modelin paketini indirir.
 * Her modelin kendi motor klasörü ve kendi GitHub sürümü (etiketi) vardır; .github/workflows/build-engines.yml hepsini üretir.
 *
 * kind: "sherpa" (sherpa-onnx WASM), "piper-ort" (ONNX Runtime Web + Piper) ya da "okur" (ONNX Runtime Web + Okur).
 */
class YS_Models {

	const DEFAULT_ID = 'piper-sherpa';
	const REPO       = 'kinefi/seslendir';

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		return array(
			'piper-sherpa' => array(
				'label'       => 'Piper · fettah (sherpa-onnx)',
				'kind'        => 'sherpa',
				'tag'         => 'motor-piper-sherpa',
				'size'        => '≈ 92 MB',
				'description' => 'Piper Türkçe "fettah" sesi, sherpa-onnx (WebAssembly) ile çalışır. Derleme ve tarayıcı duman testi geçmiş, en çok denenen seçenek.',
				'license'     => 'Piper MIT lisanslıdır; ses verisinin lisansını model sayfasından kontrol edin.',
				'url'         => 'https://huggingface.co/csukuangfj/vits-piper-tr_TR-fettah-medium',
			),
			'piper-ort'    => array(
				'label'       => 'Piper · fettah (ONNX Runtime)',
				'kind'        => 'piper-ort',
				'tag'         => 'motor-piper-ort',
				'size'        => '≈ 95 MB',
				'description' => 'Aynı Piper sesi, ONNX Runtime Web ile. Derleme gerektirmez; sherpa-onnx yoluna alternatiftir.',
				'license'     => 'Piper MIT lisanslıdır; ses verisinin lisansını model sayfasından kontrol edin.',
				'url'         => 'https://huggingface.co/csukuangfj/vits-piper-tr_TR-fettah-medium',
			),
			'okur'         => array(
				'label'       => 'Okur (ONNX Runtime)',
				'kind'        => 'okur',
				'tag'         => 'motor-okur',
				'size'        => '≈ 40 MB',
				'description' => 'Okur: küçük, açık lisanslı Türkçe model (tek ses, 48 kHz). Tek iş parçacığıyla çalıştığı için Piper\'dan yavaş olabilir.',
				'license'     => 'Kod Apache-2.0, ağırlıklar CC BY 4.0 (atıf gerekir: okur, Ahmet Hakan Beşel; Mozilla Common Voice, ISSAI Turkish Speech Corpus, MediaSpeech, Google FLEURS). Sesi gerçek bir kişiyi taklit etmek ya da insan sesi gibi sunmak için kullanmayın.',
				'url'         => 'https://huggingface.co/ahakan/okur-tts',
			),
		);
	}

	/** @return string[] */
	public static function ids() {
		return array_keys( self::all() );
	}

	/** Kayıtlı bir model kimliği değilse varsayılanı döndürür. */
	public static function valid_id( $id ) {
		$id = is_string( $id ) ? $id : '';
		return isset( self::all()[ $id ] ) ? $id : self::DEFAULT_ID;
	}

	/** @return array<string,mixed> */
	public static function get( $id ) {
		return self::all()[ self::valid_id( $id ) ];
	}

	public static function kind( $id ) {
		return (string) self::get( $id )['kind'];
	}

	/** Modelin varsayılan manifest adresi (GitHub sürümü). */
	public static function manifest_url( $id ) {
		return 'https://github.com/' . self::REPO . '/releases/download/' . self::get( $id )['tag'] . '/engine-manifest.json';
	}
}

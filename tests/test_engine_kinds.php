<?php
// Motor türleri: "sherpa" (varsayılan) ve "piper-ort". Dosya listeleri, manifest adresi, manifest türü uyumu.
require __DIR__ . '/wp_stubs.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-engine.php';

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
function manifest_for( $kind, $engine_field = true ) {
	$files = array();
	foreach ( YS_Settings::engine_required( $kind ) as $name ) {
		$files[] = array(
			'name'   => $name,
			'size'   => 1000,
			'sha256' => str_repeat( 'b', 64 ),
			'url'    => 'https://github.com/kinefi/seslendir/releases/download/x/' . $name,
		);
	}
	$m = array(
		'version' => 1,
		'voice'   => 'v',
		'files'   => $files,
	);
	if ( $engine_field ) {
		$m['engine'] = $kind;
	}
	return $m;
}

// Varsayılan: sherpa.
t( 'varsayılan tür', YS_Settings::engine_kind(), 'sherpa' );
t( 'sherpa: sherpa dosyası zorunlu', in_array( 'sherpa-onnx-wasm-main-tts.wasm', YS_Settings::engine_required(), true ) );
t( 'sherpa: ort dosyası zorunlu değil', in_array( 'ort.wasm.min.js', YS_Settings::engine_required(), true ), false );
t( 'sherpa: varsayılan manifest engine-1', false !== strpos( YS_Engine::manifest_url(), '/engine-1/' ) );
t( 'sherpa manifesti (engine alanı ile)', YS_Engine::validate_manifest( manifest_for( 'sherpa' ) )['ok'] );
t( 'sherpa manifesti (engine alanı olmadan)', YS_Engine::validate_manifest( manifest_for( 'sherpa', false ) )['ok'] );
$r = YS_Engine::validate_manifest( manifest_for( 'piper-ort' ) );
t( 'sherpa iken ort manifesti reddedilir', $r['ok'], false );

// Geçersiz tür -> sherpa.
$GLOBALS['ys_test_filters']['ys_engine_kind'] = 'bilinmeyen';
t( 'bilinmeyen tür sherpa sayılır', YS_Settings::engine_kind(), 'sherpa' );

// piper-ort.
$GLOBALS['ys_test_filters']['ys_engine_kind'] = 'piper-ort';
t( 'ort türü', YS_Settings::engine_kind(), 'piper-ort' );
$req = YS_Settings::engine_required();
foreach ( array( 'ort.wasm.min.js', 'ort-wasm-simd-threaded.js', 'ort-wasm-simd-threaded.wasm', 'voice.onnx', 'voice.onnx.json', 'piper-phonemize-wasm-main.data' ) as $f ) {
	t( "ort: $f zorunlu", in_array( $f, $req, true ) );
}
t( 'ort: sherpa dosyası zorunlu değil', in_array( 'sherpa-onnx-tts.js', $req, true ), false );
t( 'ort: dosya sayısı', count( $req ), 9 );
t( 'ort: izinli adlar', count( YS_Settings::engine_allowed_names() ), 10 );
t( 'ort: varsayılan manifest engine-2', false !== strpos( YS_Engine::manifest_url(), '/engine-2/' ) );
t( 'ort manifesti geçerli', YS_Engine::validate_manifest( manifest_for( 'piper-ort' ) )['ok'] );
$r = YS_Engine::validate_manifest( manifest_for( 'sherpa' ) );
t( 'ort iken sherpa manifesti reddedilir', $r['ok'], false );
$bad = manifest_for( 'piper-ort' );
array_pop( $bad['files'] );
t( 'ort: eksik zorunlu dosya reddedilir', YS_Engine::validate_manifest( $bad )['ok'], false );

$GLOBALS['ys_test_filters']['ys_engine_kind'] = 'sherpa';
$m = manifest_for( 'sherpa' );
$m['files'][] = array(
	'name'   => 'ort.wasm.min.js',
	'size'   => 10,
	'sha256' => str_repeat( 'c', 64 ),
	'url'    => 'https://github.com/kinefi/seslendir/releases/download/x/ort.wasm.min.js',
);
$r = YS_Engine::validate_manifest( $m );
t( 'izinsiz ad reddedilir', $r['ok'], false );
t( 'hata mesajı dosya adını söyler', false !== strpos( $r['error'], 'ort.wasm.min.js' ) );

// okur.
$GLOBALS['ys_test_filters']['ys_engine_kind'] = 'okur';
t( 'okur türü', YS_Settings::engine_kind(), 'okur' );
$req = YS_Settings::engine_required();
foreach ( array( 'okur-frontend.wasm', 'okur-text.onnx', 'okur-sample.onnx', 'okur-decoder.onnx', 'okur-config.json', 'ort.wasm.min.js' ) as $f ) {
	t( "okur: $f zorunlu", in_array( $f, $req, true ) );
}
t( 'okur: fonemleştirici gerekmez', in_array( 'piper-phonemize.js', $req, true ), false );
t( 'okur: dosya sayısı', count( $req ), 8 );
t( 'okur: varsayılan manifest engine-3', false !== strpos( YS_Engine::manifest_url(), '/engine-3/' ) );
t( 'okur manifesti geçerli', YS_Engine::validate_manifest( manifest_for( 'okur' ) )['ok'] );
t( 'okur iken piper-ort manifesti reddedilir', YS_Engine::validate_manifest( manifest_for( 'piper-ort' ) )['ok'], false );
$GLOBALS['ys_test_filters']['ys_engine_kind'] = 'sherpa';
t( 'sherpa iken okur manifesti reddedilir', YS_Engine::validate_manifest( manifest_for( 'okur' ) )['ok'], false );

echo $fail ? "$fail / $n FAILED\n" : "OK ($n assertions): " . basename( __FILE__ ) . "\n";
exit( $fail ? 1 : 0 );

<?php
// Otomatik motor indirmenin saf mantığı: manifest doğrulama, adres izni, Range başlığı, indirme planı.
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

$base = 'https://github.com/kinefi/seslendir/releases/download/engine-1/';
function good_file( $name, $size = 1000 ) {
	global $base;
	return array(
		'name'   => $name,
		'size'   => $size,
		'sha256' => str_repeat( 'a', 64 ),
		'url'    => $base . $name,
	);
}
function good_manifest() {
	$files = array();
	foreach ( YS_Settings::engine_required() as $name ) {
		$files[] = good_file( $name );
	}
	return array(
		'version' => 1,
		'voice'   => 'tr_TR-fettah-medium',
		'files'   => $files,
	);
}

// ---- Adres izni ----
t( 'github izinli', YS_Engine::host_allowed( 'https://github.com/a/b/releases/download/x/y.js' ) );
t( 'huggingface izinli', YS_Engine::host_allowed( 'https://huggingface.co/a/b/resolve/main/x' ) );
t( 'http reddedilir', YS_Engine::host_allowed( 'http://github.com/a' ), false );
t( 'başka alan adı reddedilir', YS_Engine::host_allowed( 'https://evil.example/x' ), false );
t( 'alt alan adı hilesi reddedilir', YS_Engine::host_allowed( 'https://github.com.evil.example/x' ), false );
t( 'kullanıcı bilgisi reddedilir', YS_Engine::host_allowed( 'https://github.com@evil.example/x' ), false );
t( 'port reddedilir', YS_Engine::host_allowed( 'https://github.com:8443/x' ), false );
t( 'şema yok reddedilir', YS_Engine::host_allowed( '//github.com/x' ), false );
t( 'boş reddedilir', YS_Engine::host_allowed( '' ), false );
t( 'yerel dosya reddedilir', YS_Engine::host_allowed( 'file:///etc/passwd' ), false );

// ---- Manifest ----
$ok = YS_Engine::validate_manifest( good_manifest() );
t( 'geçerli manifest', $ok['ok'] );
t( 'dosya sayısı', count( $ok['files'] ), 8 );
t( 'toplam boyut', $ok['total'], 8000 );
t( 'ses adı', $ok['voice'], 'tr_TR-fettah-medium' );

$m          = good_manifest();
$m['files'][] = good_file( 'lame.min.js', 500 );
t( 'isteğe bağlı lame kabul', YS_Engine::validate_manifest( $m )['ok'] );

$m = good_manifest();
array_pop( $m['files'] );
t( 'zorunlu dosya eksik', YS_Engine::validate_manifest( $m )['ok'], false );

$m                    = good_manifest();
$m['files'][0]['name'] = '../evil.php';
t( 'yol geçişi reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                    = good_manifest();
$m['files'][0]['name'] = 'shell.php';
t( 'izinsiz ad reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                      = good_manifest();
$m['files'][1]['sha256'] = 'xyz';
t( 'kötü sha reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                      = good_manifest();
$m['files'][1]['sha256'] = strtoupper( str_repeat( 'a', 64 ) );
t( 'büyük harf sha reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                  = good_manifest();
$m['files'][2]['url'] = 'https://evil.example/x.wasm';
t( 'kötü adres reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                   = good_manifest();
$m['files'][2]['size'] = '1000';
t( 'metin boyut reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                   = good_manifest();
$m['files'][2]['size'] = 0;
t( 'sıfır boyut reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m                   = good_manifest();
$m['files'][2]['size'] = YS_Engine::MAX_FILE + 1;
t( 'dev dosya reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m            = good_manifest();
$m['files'][] = $m['files'][0];
t( 'tekrar reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

$m            = good_manifest();
$m['version'] = 2;
t( 'sürüm 2 reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );
t( 'dizi olmayan reddedilir', YS_Engine::validate_manifest( 'x' )['ok'], false );
t( 'null reddedilir', YS_Engine::validate_manifest( null )['ok'], false );

$m = good_manifest();
foreach ( $m['files'] as $i => $f ) {
	$m['files'][ $i ]['size'] = YS_Engine::MAX_FILE;
}
t( 'toplam sınır aşımı reddedilir', YS_Engine::validate_manifest( $m )['ok'], false );

// ---- Content-Range ----
t( 'range normal', YS_Engine::parse_content_range( 'bytes 0-6291455/76543210' ), array( 0, 6291455, 76543210 ) );
t( 'range bilinmeyen toplam', YS_Engine::parse_content_range( 'bytes 10-19/*' ), array( 10, 19, null ) );
t( 'range bozuk', YS_Engine::parse_content_range( 'items 0-1/2' ), null );
t( 'range boş', YS_Engine::parse_content_range( '' ), null );

// ---- İndirme planı ----
$files = array( good_file( 'a.js', 100 ), good_file( 'b.wasm', 200 ), good_file( 'c.data', 300 ) );
$p     = YS_Engine::plan( $files, array() );
t( 'plan: hiçbiri yok', array( $p['done'], $p['next']['name'], $p['next']['start'], $p['total_done'], $p['total_size'] ), array( false, 'a.js', 0, 0, 600 ) );

$p = YS_Engine::plan(
	$files,
	array(
		'a.js'   => array(
			'final' => 100,
			'part'  => 0,
		),
		'b.wasm' => array(
			'final' => 0,
			'part'  => 50,
		),
	)
);
t( 'plan: yarım kalan devam eder', array( $p['next']['name'], $p['next']['start'], $p['next']['reset'], $p['total_done'] ), array( 'b.wasm', 50, false, 150 ) );

$p = YS_Engine::plan(
	$files,
	array(
		'a.js'   => array(
			'final' => 100,
			'part'  => 0,
		),
		'b.wasm' => array(
			'final' => 200,
			'part'  => 0,
		),
		'c.data' => array(
			'final' => 300,
			'part'  => 0,
		),
	)
);
t( 'plan: hepsi tamam', array( $p['done'], $p['next'], $p['total_done'] ), array( true, null, 600 ) );

$p = YS_Engine::plan(
	$files,
	array(
		'a.js' => array(
			'final' => 0,
			'part'  => 999,
		),
	)
);
t( 'plan: şişmiş parça sıfırlanır', array( $p['next']['name'], $p['next']['start'], $p['next']['reset'] ), array( 'a.js', 0, true ) );

$p = YS_Engine::plan(
	$files,
	array(
		'a.js' => array(
			'final' => 0,
			'part'  => 100,
		),
	)
);
t( 'plan: tam boyutlu doğrulanmamış parça sıfırlanır', $p['next']['reset'], true );

$p = YS_Engine::plan(
	$files,
	array(
		'a.js' => array(
			'final' => 90,
			'part'  => 0,
		),
	)
);
t( 'plan: boyutu uymayan final yeniden indirilir', array( $p['next']['name'], $p['next']['start'] ), array( 'a.js', 0 ) );

// ---- Python manifest betiği <-> PHP doğrulayıcı uyumu ----
$py = trim( (string) shell_exec( 'command -v python3 2>/dev/null' ) );
if ( '' === $py ) {
	echo "SKIP: python3 yok, manifest betiği uyum testi atlandı.\n";
} else {
	$tmp = sys_get_temp_dir() . '/ys_manifest_' . bin2hex( random_bytes( 4 ) );
	mkdir( $tmp );
	foreach ( YS_Settings::engine_required() as $name ) {
		file_put_contents( $tmp . '/' . $name, random_bytes( 3000 ) );
	}
	file_put_contents( $tmp . '/lame.min.js', 'x' . random_bytes( 100 ) );
	file_put_contents( $tmp . '/gereksiz.txt', 'yok sayılmalı' );
	$cmd = sprintf( '%s %s %s %s 2>&1', escapeshellarg( $py ), escapeshellarg( dirname( __DIR__ ) . '/tools/make-engine-manifest.py' ), escapeshellarg( $tmp ), escapeshellarg( $base ) );
	shell_exec( $cmd );
	$data = json_decode( (string) @file_get_contents( $tmp . '/engine-manifest.json' ), true );
	$res  = YS_Engine::validate_manifest( $data );
	t( 'betik çıktısı PHP doğrulayıcıdan geçer', $res['ok'], true );
	t( 'betik: 9 dosya (gereksiz yok sayıldı)', count( $res['files'] ), 9 );
	$sha_ok = true;
	foreach ( $res['files'] as $f ) {
		if ( hash_file( 'sha256', $tmp . '/' . $f['name'] ) !== $f['sha256'] || filesize( $tmp . '/' . $f['name'] ) !== $f['size'] ) {
			$sha_ok = false;
		}
	}
	t( 'betik: boyut ve sha256 doğru', $sha_ok, true );
	// Zorunlu dosya silinince betik hata vermeli.
	unlink( $tmp . '/sherpa-onnx-wasm-main-tts.wasm' );
	@unlink( $tmp . '/engine-manifest.json' );
	shell_exec( $cmd );
	t( 'betik: eksik zorunlu dosyada manifest üretmez', is_file( $tmp . '/engine-manifest.json' ), false );
	array_map( 'unlink', glob( $tmp . '/*' ) );
	rmdir( $tmp );
}

echo $fail ? "$fail / $n FAILED\n" : "OK ($n assertions): " . basename( __FILE__ ) . "\n";
exit( $fail ? 1 : 0 );

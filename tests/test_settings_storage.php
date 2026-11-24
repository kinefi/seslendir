<?php
// Ayar temizleme, ses biçimi doğrulama ve imza (hash) testleri.
require __DIR__ . '/wp_stubs.php';
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-normalizer-tr.php';
require dirname( __DIR__ ) . '/includes/class-storage.php';

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

// ---- Ayar temizleme ----
$s = YS_Settings::sanitize(
	array(
		'post_types' => array( 'post', 'attachment', 'olmayan', 'page' ),
		'speed'      => '9',
		'speaker'    => '-4',
		'max_mb'     => '99999',
		'label'      => '<b>Dinle</b>',
		'voice_tag'  => 'Ses 1/../x',
	)
);
t( 'tür süzgeci', $s['post_types'], array( 'post', 'page' ) );
t( 'hız üst sınır', $s['speed'], 1.6 );
t( 'hız virgül', YS_Settings::sanitize( array( 'speed' => '0,8' ) )['speed'], 0.8 );
t( 'hız alt sınır', YS_Settings::sanitize( array( 'speed' => '0.1' ) )['speed'], 0.6 );
t( 'negatif konuşmacı', $s['speaker'], 0 );
t( 'boyut üst sınır', $s['max_mb'], 500 );
t( 'etiket etiketsiz', $s['label'], 'Dinle' );
t( 'ses etiketi temiz', $s['voice_tag'], 'Ses1..x' );
t( 'kutular kapalı', array( $s['read_title'], $s['autoinsert'], $s['show_column'], $s['confirm'] ), array( 0, 0, 0, 0 ) );
$e = YS_Settings::sanitize( array() );
t( 'boş etiket varsayılan', $e['label'], 'Yazıyı Seslendir' );
t( 'varsayılan tür yok sayılmaz', YS_Settings::defaults()['post_types'], array( 'post' ) );

update_option( YS_Settings::OPT, array( 'post_types' => array( 'post', 'yok' ) ) );
t( 'yalnızca kayıtlı türler', YS_Settings::post_types(), array( 'post' ) );
t( 'eksik ayar varsayılanı alır', YS_Settings::get( 'confirm' ), 1 );

// ---- Motor dosyaları ----
t( 'zorunlu dosya sayısı', count( YS_Settings::engine_required() ), 4 );
t( 'lame isteğe bağlı', in_array( 'lame.min.js', YS_Settings::engine_optional(), true ), true );
t( 'izinli adlar birleşik', count( YS_Settings::engine_allowed_names() ), 5 );

// ---- Ses biçimi doğrulama ----
function wav_bytes( $seconds, $rate = 22050 ) {
	$data = str_repeat( "\x00\x00", (int) ( $rate * $seconds ) );
	return 'RIFF' . pack( 'V', 36 + strlen( $data ) ) . 'WAVE' . 'fmt ' . pack( 'VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16 ) . 'data' . pack( 'V', strlen( $data ) ) . $data;
}
$wav = wav_bytes( 2 );
t( 'wav tanınır', YS_Storage::detect_format( $wav ), 'wav' );
t( 'wav süresi', YS_Storage::wav_duration( $wav ), 2.0 );
t( 'mp3 ID3', YS_Storage::detect_format( 'ID3' . str_repeat( "\x00", 20 ) ), 'mp3' );
t( 'mp3 çerçeve', YS_Storage::detect_format( "\xFF\xFB\x90\x00" . str_repeat( "\x00", 20 ) ), 'mp3' );
t( 'html reddedilir', YS_Storage::detect_format( '<?php system($_GET[0]); ?>' . str_repeat( ' ', 60 ) ), null );
t( 'png reddedilir', YS_Storage::detect_format( "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 60 ) ), null );
t( 'kısa veri reddedilir', YS_Storage::detect_format( 'RIFF' ), null );
t( 'boş reddedilir', YS_Storage::detect_format( '' ), null );
$bad       = $wav;
$bad[20]   = "\x03"; // PCM değil (IEEE float).
t( 'PCM dışı wav süresiz', YS_Storage::wav_duration( $bad ), null );
t( 'çok kısa wav süresiz', YS_Storage::wav_duration( 'RIFF' ), null );

// ---- İmza (hash) ----
$chunks = array(
	array(
		't' => 'Merhaba dünya.',
		'p' => 650,
	),
);
$h1     = YS_Storage::hash_for( $chunks );
t( 'hash kararlı', YS_Storage::hash_for( $chunks ), $h1 );
t( 'hash 40 karakter', strlen( $h1 ), 40 );
$chunks[0]['t'] = 'Merhaba Türkiye.';
t( 'metin değişince hash değişir', YS_Storage::hash_for( $chunks ) !== $h1, true );
$chunks[0]['t'] = 'Merhaba dünya.';
update_option( YS_Settings::OPT, array( 'speed' => 1.2 ) );
t( 'hız değişince hash değişir', YS_Storage::hash_for( $chunks ) !== $h1, true );
update_option( YS_Settings::OPT, array( 'voice_tag' => 'baska' ) );
t( 'ses etiketi değişince hash değişir', YS_Storage::hash_for( $chunks ) !== $h1, true );

$p1               = new stdClass();
$p1->post_title   = 'A';
$p1->post_content = 'B';
$p2               = clone $p1;
$p2->post_content = 'C';
t( 'kaynak imzası içeriğe duyarlı', YS_Storage::src_sig( $p1 ) !== YS_Storage::src_sig( $p2 ), true );

echo $fail ? "$fail / $n FAILED\n" : "OK ($n assertions): " . basename( __FILE__ ) . "\n";
exit( $fail ? 1 : 0 );

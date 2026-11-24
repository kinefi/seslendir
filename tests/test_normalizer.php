<?php
// Türkçe metin normalleştirici testleri (WordPress gerekmez).
define( 'ABSPATH', '/tmp/' );
require dirname( __DIR__ ) . '/includes/class-normalizer-tr.php';

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
function nz( $s ) {
	return YS_Normalizer_TR::normalize( $s );
}

// ---- Sayılar ----
t( 'sıfır', YS_Normalizer_TR::int_words( '0' ), 'sıfır' );
t( 'bir', YS_Normalizer_TR::int_words( '1' ), 'bir' );
t( 'on bir', YS_Normalizer_TR::int_words( '11' ), 'on bir' );
t( 'yüz', YS_Normalizer_TR::int_words( '100' ), 'yüz' );
t( 'yüz yirmi üç', YS_Normalizer_TR::int_words( '123' ), 'yüz yirmi üç' );
t( 'bin (bir bin değil)', YS_Normalizer_TR::int_words( '1000' ), 'bin' );
t( 'bin bir', YS_Normalizer_TR::int_words( '1001' ), 'bin bir' );
t( 'iki bin yirmi altı', YS_Normalizer_TR::int_words( '2026' ), 'iki bin yirmi altı' );
t( 'bir milyon', YS_Normalizer_TR::int_words( '1000000' ), 'bir milyon' );
t( 'bir milyon bin', YS_Normalizer_TR::int_words( '1001000' ), 'bir milyon bin' );
t( 'milyar', YS_Normalizer_TR::int_words( '3000000000' ), 'üç milyar' );
t( 'uzun sayı rakam rakam', YS_Normalizer_TR::int_words( str_repeat( '9', 19 ) ), implode( ' ', array_fill( 0, 19, 'dokuz' ) ) );

t( 'ondalık', nz( '3,5' ), 'üç virgül beş' );
t( 'ondalık sıfırlı', nz( '3,05' ), 'üç virgül sıfır beş' );
t( 'nokta binlik', nz( '1.250' ), 'bin iki yüz elli' );
t( 'nokta binlik + ondalık', nz( '1.250,5' ), 'bin iki yüz elli virgül beş' );
t( 'baştaki sıfır rakam rakam', nz( '0532' ), 'sıfır beş üç iki' );
t( 'ek korunur', nz( '2026\'da' ), 'iki bin yirmi altıda' );
t( 'ek korunur 2', nz( '15’i' ), 'on beşi' );
t( 'cümle içinde', nz( 'Toplam 3 kişi geldi.' ), 'Toplam üç kişi geldi.' );
t( 'harfe yapışık', nz( 'A4 kağıt' ), 'A dört kağıt' );
t( 'harf sonrası ek yok', nz( 'Covid-19 salgını' ), 'Covid on dokuz salgını' );

// ---- Tarih / saat ----
t( 'tarih noktalı', nz( '14.10.2026' ), 'on dört Ekim iki bin yirmi altı' );
t( 'tarih eğik', nz( '1/2/2025' ), 'bir Şubat iki bin yirmi beş' );
t( 'tarih ISO', nz( '2026-11-24' ), 'yirmi dört Kasım iki bin yirmi altı' );
t( 'geçersiz tarih bozulmaz', nz( '31.13.2026' ) !== '', true );
t( 'saat', nz( '15:30' ), 'on beş otuz' );
t( 'saat tam', nz( '09:00' ), 'dokuz' );
t( 'saat sıfırlı dakika', nz( '09:05' ), 'dokuz sıfır beş' );

// ---- Para / yüzde / birim ----
t( 'TL sonda', nz( '1.250,50 TL' ), 'bin iki yüz elli lira elli kuruş' );
t( 'lira sembolü başta', nz( '₺100' ), 'yüz lira' );
t( 'lira sembolü ek', nz( '₺100\'ü ödedi' ), 'yüz lira ödedi' );
t( 'dolar', nz( '$5' ), 'beş dolar' );
t( 'avro', nz( '2 €' ), 'iki avro' );
t( 'yüzde başta', nz( '%15' ), 'yüzde on beş' );
t( 'yüzde sonda', nz( '15%' ), 'yüzde on beş' );
t( 'yüzde ek', nz( '%15\'i' ), 'yüzde on beşi' );
t( 'yüzde ondalık', nz( '%2,5' ), 'yüzde iki virgül beş' );
t( 'km', nz( '5 km yol' ), 'beş kilometre yol' );
t( 'kg', nz( '70kg' ), 'yetmiş kilogram' );
t( 'derece', nz( '25°C' ), 'yirmi beş derece' );
t( 'aralık', nz( '10-12 yaş' ), 'on ila on iki yaş' );
t( 'telefon aralık sanılmaz', nz( '0212-555-1234' ) !== '' && false === strpos( nz( '0212-555-1234' ), 'ila' ), true );

// ---- Kısaltma / sembol ----
t( 'vb.', nz( 'kitap, defter vb. şeyler' ), 'kitap, defter ve benzeri şeyler' );
t( 'dr.', nz( 'Dr. Ahmet' ), 'doktor Ahmet' );
t( 'prof.', nz( 'Prof. Ayşe' ), 'profesör Ayşe' );
t( 'kesme harf arası', nz( 'Atatürk\'ün sözü' ), 'Atatürkün sözü' );
t( 'URL', nz( 'bkz. https://example.org/x?y=1 sitesi' ), 'bakınız bağlantı sitesi' );
t( 'e-posta', nz( 'yaz: ali@example.org' ), 'yaz: e posta adresi' );
t( 've işareti', nz( 'Ali & Veli' ), 'Ali ve Veli' );
t( 'emoji', nz( 'Merhaba 😀 dünya' ), 'Merhaba dünya' );
t( 'parantez', nz( 'Ankara (başkent) güzel' ), 'Ankara, başkent, güzel' );
t( 'tire', nz( 'Ali - Veli' ), 'Ali, Veli' );

// ---- Cümle bölme ----
$s = YS_Normalizer_TR::split_sentences( 'Ben geldim. Sen gittin! A. Yılmaz da geldi. Neden?' );
t( 'cümle sayısı', count( $s ), 4 );
t( 'baş harf bölünmez', $s[2], 'A. Yılmaz da geldi.' );

$long = trim( str_repeat( 'merhaba dünya, bu bir deneme cümlesidir ', 20 ) );
$pcs  = YS_Normalizer_TR::split_long( $long, 240 );
t( 'uzun cümle bölünür', count( $pcs ) > 1, true );
$ok = true;
foreach ( $pcs as $p ) {
	if ( mb_strlen( $p ) > 240 ) {
		$ok = false;
	}
}
t( 'parçalar sınırı aşmaz', $ok, true );

// ---- Yazıdan parça üretimi ----
$post               = new stdClass();
$post->post_title   = 'Güzel &amp; Yararlı Bir Gün';
$post->post_content = "<!-- wp:paragraph -->\n<p>Bugün <strong>14.10.2026</strong> tarihinde 3 kişi geldi.</p>\n<!-- /wp:paragraph -->\n<h2>Alt Başlık</h2>\n<pre>kod();</pre>\n<p>[galeri id=3]İkinci paragraf.</p>\n<script>alert(1)</script>";
$ch                 = YS_Normalizer_TR::chunks_for_post( $post, true );
t( 'parça sayısı', count( $ch ), 4 );
t( 'başlık ilk', $ch[0]['t'], 'Güzel ve Yararlı Bir Gün.' );
t( 'başlık duraklaması', $ch[0]['p'], 900 );
t( 'paragraf tarih', $ch[1]['t'], 'Bugün on dört Ekim iki bin yirmi altı tarihinde üç kişi geldi.' );
t( 'paragraf duraklaması', $ch[1]['p'], 650 );
t( 'alt başlık', $ch[2]['t'], 'Alt Başlık.' );
t( 'kısa kod temizlenir, pre/script atılır', $ch[3]['t'], 'İkinci paragraf.' );
$ch2 = YS_Normalizer_TR::chunks_for_post( $post, false );
t( 'başlıksız', count( $ch2 ), 3 );
t( 'toplam karakter', YS_Normalizer_TR::total_chars( $ch ) > 50, true );

echo $fail ? "$fail / $n FAILED\n" : "OK ($n assertions): " . basename( __FILE__ ) . "\n";
exit( $fail ? 1 : 0 );

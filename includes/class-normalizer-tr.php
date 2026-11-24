<?php
defined( 'ABSPATH' ) || exit;

/**
 * Türkçe metin ön işleme: sayı, tarih, saat, para, yüzde, kısaltma ve sembolleri
 * sesli okunabilir kelimelere çevirir; yazıyı cümle parçalarına böler.
 * WordPress'e bağımlı değildir (chunks_for_post hariç yalnızca $post alanlarını okur).
 */
class YS_Normalizer_TR {

	const MAX_CHUNK = 240;
	const NUM       = '\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?';

	private static $ones   = array( 'sıfır', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz' );
	private static $tens   = array( '', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan' );
	private static $scales = array( '', 'bin', 'milyon', 'milyar', 'trilyon', 'katrilyon' );
	private static $months = array(
		1  => 'Ocak',
		2  => 'Şubat',
		3  => 'Mart',
		4  => 'Nisan',
		5  => 'Mayıs',
		6  => 'Haziran',
		7  => 'Temmuz',
		8  => 'Ağustos',
		9  => 'Eylül',
		10 => 'Ekim',
		11 => 'Kasım',
		12 => 'Aralık',
	);

	private static $units = array(
		'km' => 'kilometre',
		'kg' => 'kilogram',
		'cm' => 'santimetre',
		'mm' => 'milimetre',
		'ml' => 'mililitre',
		'gr' => 'gram',
		'GB' => 'gigabayt',
		'MB' => 'megabayt',
		'KB' => 'kilobayt',
		'TB' => 'terabayt',
		'°C' => 'derece',
	);

	private static $abbr = array(
		'vb.'   => 've benzeri',
		'vs.'   => 'vesaire',
		'vd.'   => 've diğerleri',
		'örn.'  => 'örneğin',
		'dr.'   => 'doktor',
		'prof.' => 'profesör',
		'doç.'  => 'doçent',
		'sn.'   => 'sayın',
		'av.'   => 'avukat',
		'bkz.'  => 'bakınız',
		'yy.'   => 'yüzyıl',
		'sf.'   => 'sayfa',
		'tel.'  => 'telefon',
		'mah.'  => 'mahallesi',
		'cad.'  => 'caddesi',
		'sok.'  => 'sokağı',
		'no.'   => 'numara',
		'ltd.'  => 'limited',
		'şti.'  => 'şirketi',
	);

	private static $money = array(
		'₺'   => array( 'lira', 'kuruş' ),
		'TL'  => array( 'lira', 'kuruş' ),
		'TRY' => array( 'lira', 'kuruş' ),
		'$'   => array( 'dolar', 'sent' ),
		'USD' => array( 'dolar', 'sent' ),
		'€'   => array( 'avro', 'sent' ),
		'EUR' => array( 'avro', 'sent' ),
		'£'   => array( 'sterlin', 'peni' ),
		'GBP' => array( 'sterlin', 'peni' ),
	);

	/* ---------- Sayılar ---------- */

	/**
	 * Rakam dizisini (yalnızca 0-9) kelimeye çevirir: "2026" => "iki bin yirmi altı".
	 */
	public static function int_words( $digits ) {
		$digits = ltrim( (string) $digits, '0' );
		if ( '' === $digits ) {
			return self::$ones[0];
		}
		if ( strlen( $digits ) > 18 ) {
			return self::spell_digits( $digits );
		}
		$n      = (int) $digits;
		$groups = array();
		while ( $n > 0 ) {
			$groups[] = $n % 1000;
			$n        = intdiv( $n, 1000 );
		}
		$parts = array();
		for ( $i = count( $groups ) - 1; $i >= 0; $i-- ) {
			$g = $groups[ $i ];
			if ( 0 === $g ) {
				continue;
			}
			$words = self::three( $g );
			if ( 1 === $i && 1 === $g ) {
				$words = ''; // "bir bin" değil, "bin".
			}
			$parts[] = trim( $words . ' ' . self::$scales[ $i ] );
		}
		return implode( ' ', $parts );
	}

	private static function three( $g ) {
		$h = intdiv( $g, 100 );
		$t = intdiv( $g % 100, 10 );
		$o = $g % 10;
		$w = array();
		if ( $h > 0 ) {
			$w[] = 1 === $h ? 'yüz' : self::$ones[ $h ] . ' yüz';
		}
		if ( $t > 0 ) {
			$w[] = self::$tens[ $t ];
		}
		if ( $o > 0 ) {
			$w[] = self::$ones[ $o ];
		}
		return implode( ' ', $w );
	}

	/** Rakamları tek tek okur: "0532" => "sıfır beş üç iki". */
	public static function spell_digits( $digits ) {
		$out = array();
		foreach ( str_split( preg_replace( '/\D/', '', (string) $digits ) ) as $d ) {
			$out[] = self::$ones[ (int) $d ];
		}
		return implode( ' ', $out );
	}

	/**
	 * "1.250,5" gibi Türkçe yazımlı sayıyı kelimeye çevirir.
	 */
	public static function number_words( $raw ) {
		$int  = (string) $raw;
		$frac = null;
		if ( false !== strpos( $int, ',' ) ) {
			list( $int, $frac ) = explode( ',', $int, 2 );
		}
		$int = str_replace( '.', '', $int );
		if ( null === $frac && strlen( $int ) > 1 && '0' === $int[0] ) {
			return self::spell_digits( $int );
		}
		if ( strlen( $int ) > 15 ) {
			return self::spell_digits( $int );
		}
		$out = self::int_words( $int );
		if ( null !== $frac && '' !== $frac ) {
			$out .= ' virgül ' . self::frac_words( $frac );
		}
		return $out;
	}

	private static function frac_words( $frac ) {
		$zeros = strlen( $frac ) - strlen( ltrim( $frac, '0' ) );
		$rest  = ltrim( $frac, '0' );
		$out   = array();
		for ( $i = 0; $i < $zeros; $i++ ) {
			$out[] = self::$ones[0];
		}
		if ( '' !== $rest ) {
			$out[] = self::int_words( $rest );
		}
		return implode( ' ', $out );
	}

	private static function money_words( $amount, $symbol ) {
		if ( ! isset( self::$money[ $symbol ] ) ) {
			return $amount;
		}
		$int  = $amount;
		$frac = '';
		if ( false !== strpos( $amount, ',' ) ) {
			list( $int, $frac ) = explode( ',', $amount, 2 );
		}
		$int = str_replace( '.', '', $int );
		$out = self::number_words( $int ) . ' ' . self::$money[ $symbol ][0];
		if ( '' !== $frac ) {
			$cents = (int) substr( str_pad( $frac, 2, '0' ), 0, 2 );
			if ( $cents > 0 ) {
				$out .= ' ' . self::int_words( (string) $cents ) . ' ' . self::$money[ $symbol ][1];
			}
		}
		return $out;
	}

	/* ---------- Metin normalleştirme ---------- */

	public static function normalize( $text ) {
		$t = (string) $text;
		$t = str_replace( array( "\xC2\xA0", "\xE2\x80\x8B", "\xC2\xAD" ), ' ', $t );

		// Bağlantı ve e-posta.
		$t = preg_replace( '~\b(?:https?://|www\.)\S+~iu', ' bağlantı ', $t );
		$t = preg_replace( '~[\w.+-]+@[\w-]+\.[\w.-]+~u', ' e-posta adresi ', $t );

		// Tarihler.
		$t = preg_replace_callback(
			'~(?<![\d.,/-])(\d{1,2})[./](\d{1,2})[./](\d{4})(?!\d)~u',
			function ( $m ) {
				return self::date_words( (int) $m[1], (int) $m[2], $m[3], $m[0] );
			},
			$t
		);
		$t = preg_replace_callback(
			'~(?<![\d.,/-])(\d{4})-(\d{2})-(\d{2})(?!\d)~u',
			function ( $m ) {
				return self::date_words( (int) $m[3], (int) $m[2], $m[1], $m[0] );
			},
			$t
		);

		// Saat.
		$t = preg_replace_callback(
			'~(?<![\d.,:])([01]?\d|2[0-3]):([0-5]\d)(?![\d:])~u',
			function ( $m ) {
				$h = self::int_words( (string) (int) $m[1] );
				if ( '00' === $m[2] ) {
					return $h;
				}
				$min = '0' === $m[2][0] ? self::$ones[0] . ' ' . self::$ones[ (int) $m[2][1] ] : self::int_words( (string) (int) $m[2] );
				return $h . ' ' . $min;
			},
			$t
		);

		// Aralık: 10-12 => on ila on iki.
		$t = preg_replace( '~(?<![\d.,/-])([1-9]\d{0,3})\s?[–-]\s?([1-9]\d{0,3})(?![\d.,/-])~u', '$1 ila $2', $t );

		// Para.
		$num = self::NUM;
		$t   = preg_replace_callback(
			'~(₺|\$|€|£)\s?(' . $num . ')(?:[\'’]\p{L}+)?~u',
			function ( $m ) {
				return ' ' . self::money_words( $m[2], $m[1] ) . ' ';
			},
			$t
		);
		$t   = preg_replace_callback(
			'~(' . $num . ')\s?(₺|\$|€|£|TL|TRY|USD|EUR|GBP)(?![\p{L}\d])(?:[\'’]\p{L}+)?~u',
			function ( $m ) {
				return ' ' . self::money_words( $m[1], $m[2] ) . ' ';
			},
			$t
		);

		// Yüzde.
		$t = preg_replace_callback(
			'~%\s?(' . $num . ')(?:[\'’](\p{L}+))?~u',
			function ( $m ) {
				return ' yüzde ' . self::number_words( $m[1] ) . ( isset( $m[2] ) ? $m[2] : '' ) . ' ';
			},
			$t
		);
		$t = preg_replace_callback(
			'~(' . $num . ')\s?%~u',
			function ( $m ) {
				return ' yüzde ' . self::number_words( $m[1] ) . ' ';
			},
			$t
		);

		// Birimler (rakamın hemen ardından).
		$t = preg_replace_callback(
			'~(?<=\d)\s?(km|kg|cm|mm|ml|gr|GB|MB|KB|TB|°C)(?![\p{L}\d])~u',
			function ( $m ) {
				return ' ' . self::$units[ $m[1] ] . ' ';
			},
			$t
		);

		// Kısaltmalar.
		foreach ( self::$abbr as $short => $long ) {
			$t = preg_replace( '~(?<![\p{L}\p{N}])' . preg_quote( $short, '~' ) . '~iu', $long, $t );
		}

		// Harfe yapışık rakamı ayır: "A4" => "A 4".
		$t = preg_replace( '~(?<=\p{L})(?=\d)~u', ' ', $t );

		// Kalan sayılar (+ kesme işaretli ek: 2026'da => iki bin yirmi altıda).
		$t = preg_replace_callback(
			'~(?<![\p{L}\d])(' . $num . ')(?:[\'’](\p{L}+))?~u',
			function ( $m ) {
				return self::number_words( $m[1] ) . ( isset( $m[2] ) ? $m[2] : '' ) . ' ';
			},
			$t
		);

		return self::clean_symbols( $t );
	}

	private static function date_words( $day, $month, $year, $original ) {
		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return $original;
		}
		return self::int_words( (string) $day ) . ' ' . self::$months[ $month ] . ' ' . self::int_words( ltrim( $year, '0' ) );
	}

	private static function clean_symbols( $t ) {
		// Emoji ve süs karakterleri.
		$t = preg_replace( '~[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]~u', '', $t );

		$t = str_replace( array( '&', '+', '=' ), array( ' ve ', ' artı ', ' eşittir ' ), $t );

		// Harfler arasındaki kesme işareti ekin yutulması için kaldırılır: Atatürk'ün => Atatürkün.
		$t = preg_replace( '~(?<=\p{L})[\'’](?=\p{L})~u', '', $t );
		$t = preg_replace( '~["“”„«»‘’\']~u', '', $t );

		// Tireler ve ayraçlar.
		$t = preg_replace( '~\s[–—-]\s|[–—]~u', ', ', $t );
		$t = str_replace( '-', ' ', $t );
		$t = preg_replace( '~[()\[\]{}]~u', ', ', $t );
		$t = preg_replace( '%[#*_^~|\\\\<>/]%u', ' ', $t );
		$t = str_replace( '…', '.', $t );

		// Noktalama düzeni.
		$t = preg_replace( '~\s+~u', ' ', $t );
		$t = preg_replace( '~\s+([.,;:!?])~u', '$1', $t );
		$t = preg_replace( '~,(\s*,)+~u', ',', $t );
		$t = preg_replace( '~,\s*([.!?])~u', '$1', $t );
		$t = preg_replace( '~^[\s,;:]+~u', '', $t );

		return trim( $t );
	}

	/* ---------- Parçalama ---------- */

	/** Paragrafı cümlelere böler (A. Yılmaz gibi baş harfleri bölmez). */
	public static function split_sentences( $para ) {
		$parts = preg_split( '~(?<=[!?])\s+|(?<=[^\s\p{Lu}]\.)\s+~u', trim( (string) $para ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $parts ) ? $parts : array();
	}

	/** Uzun cümleyi virgül/boşluk sınırlarından MAX_CHUNK'a göre böler. */
	public static function split_long( $s, $max = self::MAX_CHUNK ) {
		$s   = trim( $s );
		$out = array();
		while ( mb_strlen( $s ) > $max ) {
			$cut  = 0;
			$head = mb_substr( $s, 0, $max );
			foreach ( array( ', ', '; ', ': ', ' ' ) as $sep ) {
				$p = mb_strrpos( $head, $sep );
				if ( false !== $p && $p > $max * 0.4 ) {
					$cut = $p + mb_strlen( rtrim( $sep ) );
					break;
				}
			}
			if ( 0 === $cut ) {
				$cut = $max;
			}
			$out[] = trim( mb_substr( $s, 0, $cut ) );
			$s     = trim( mb_substr( $s, $cut ) );
		}
		if ( '' !== $s ) {
			$out[] = $s;
		}
		return $out;
	}

	private static function finish( $s ) {
		$s = trim( $s );
		return ( '' !== $s && ! preg_match( '~[.!?:;,]$~u', $s ) ) ? $s . '.' : $s;
	}

	/**
	 * Düz metni (paragraflar satırlarla ayrılmış) {t, p} parçalarına çevirir.
	 * Satır başındaki \x01 işareti başlık demektir.
	 *
	 * @return array<int,array{t:string,p:int}>
	 */
	public static function chunks_from_lines( array $lines ) {
		$chunks = array();
		foreach ( $lines as $line ) {
			$heading = ( 0 === strpos( $line, "\x01" ) );
			$line    = ltrim( $line, "\x01" );
			$norm    = self::normalize( $line );
			if ( ! preg_match( '~[\p{L}\p{N}]~u', $norm ) ) {
				continue;
			}
			$line_chunks = array();
			foreach ( self::split_sentences( $norm ) as $sentence ) {
				foreach ( self::split_long( $sentence ) as $piece ) {
					$piece = self::finish( $piece );
					if ( preg_match( '~[\p{L}\p{N}]~u', $piece ) ) {
						$line_chunks[] = array(
							't' => $piece,
							'p' => 280,
						);
					}
				}
			}
			if ( $line_chunks ) {
				$line_chunks[ count( $line_chunks ) - 1 ]['p'] = $heading ? 900 : 650;
				$chunks                                        = array_merge( $chunks, $line_chunks );
			}
		}
		return $chunks;
	}

	/** Yazı HTML'ini okunacak satırlara indirger. */
	public static function html_to_lines( $html ) {
		$c = (string) $html;
		$c = preg_replace( '~<!--.*?-->~s', '', $c );
		$c = preg_replace( '~<(script|style|pre|code|figure|table|svg|iframe|noscript|audio|video)\b[^>]*>.*?</\1>~is', ' ', $c );
		$c = preg_replace( '~<h[1-6]\b[^>]*>~i', "\n\x01", $c );
		$c = preg_replace( '~</h[1-6]>~i', "\n", $c );
		$c = preg_replace( '~</(p|li|blockquote|div|ul|ol|tr|section|article)>|<br\s*/?>~i', "\n", $c );
		$c = preg_replace( '~\[/?[a-z_][a-z0-9_-]*(?:\s[^\]]*)?\]~i', '', $c );
		$c = strip_tags( $c );
		$c = html_entity_decode( $c, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$l = preg_split( '~\R+~u', $c, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $l ) ? array_values( array_filter( array_map( 'trim', $l ), 'strlen' ) ) : array();
	}

	/**
	 * @param object $post WP_Post benzeri (post_title, post_content).
	 */
	public static function chunks_for_post( $post, $read_title = true ) {
		$lines = array();
		if ( $read_title ) {
			$title = trim( html_entity_decode( strip_tags( (string) $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $title ) {
				$lines[] = "\x01" . $title;
			}
		}
		return self::chunks_from_lines( array_merge( $lines, self::html_to_lines( $post->post_content ) ) );
	}

	public static function total_chars( array $chunks ) {
		$n = 0;
		foreach ( $chunks as $c ) {
			$n += mb_strlen( $c['t'] );
		}
		return $n;
	}
}

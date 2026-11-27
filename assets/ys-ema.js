/*
 * Seslendir: EMA Lightning (https://huggingface.co/canberkkkkkk/ema-lightning, Apache-2.0) için saf yardımcılar.
 * ONNX dönüşümü ve tarayıcı çıkarımı: https://github.com/ozcancelik/ema-lightning-web (Apache-2.0); bu dosya onun
 * tts.js dosyasındaki metin ön yüzü, parçalama, kare zaman çizelgesi ve gürültü kodunun düz JavaScript karşılığıdır.
 * DOM'a bağlı değildir ve Node'da test edilir. Çıkarım ys-engine.js içinde ONNX Runtime Web ile yapılır.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.YSEma = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var FIRST_WINDOW = 25;
	var WINDOW = 100;
	var CONTEXT = 8;
	var MAX_WORD_FRAMES = 250;
	var MAX_FRAMES = 3000;
	var LETTERS_PER_SECOND = 18;
	var MAX_SECONDS = 10;
	var MAX_LETTERS = 250;
	var SENTENCE_PAUSE = 0.25;
	var CLAUSE_PAUSE = 0.12;

	var TURKISH = new Set( Array.from( 'çğıöşüÇĞİÖŞÜ' ) );
	var TYPOGRAPHY = {
		'’': "'", '‘': "'", 'ʼ': "'", '´': "'", '`': "'", '“': '"', '”': '"', '„': '"',
		'«': '"', '»': '"', '–': '-', '—': '-', '−': '-', '…': '...'
	};
	// eslint-disable-next-line no-control-regex
	var UNSAFE = /[\u0000-\u0008\u000b-\u001f\u007f-\u009f؜‎‏‪-‮⁦-⁩]/g;

	/** Metni modelin alfabesine indirger: küçük harf, Türkçe harfler korunur, bilinmeyenler boşluk olur. */
	function alphabet( text, vocab ) {
		text = String( text ).replace( UNSAFE, ' ' );
		text = Array.from( text ).map( function ( ch ) {
			return Object.prototype.hasOwnProperty.call( TYPOGRAPHY, ch ) ? TYPOGRAPHY[ ch ] : ch;
		} ).join( '' );
		text = text.replace( /İ/g, 'i' ).replace( /I/g, 'ı' ).toLocaleLowerCase( 'tr' );
		var out = '';
		Array.from( text ).forEach( function ( ch ) {
			if ( ! TURKISH.has( ch ) ) {
				ch = ch.normalize( 'NFKD' ).replace( /\p{M}/gu, '' );
			}
			out += ch && Array.from( ch ).every( function ( c ) {
				return vocab.has( c );
			} ) ? ch : ' ';
		} );
		return out.replace( /\s+/g, ' ' ).trim();
	}

	function matchEnds( head, pattern ) {
		var re = new RegExp( pattern.source, 'g' );
		var ends = [];
		var m;
		while ( ( m = re.exec( head ) ) !== null ) {
			ends.push( m.index + m[ 0 ].length );
			if ( m[ 0 ].length === 0 ) {
				re.lastIndex++;
			}
		}
		return ends;
	}

	var CUTS = [
		[ /[.!?]+["')]*(?= )/, SENTENCE_PAUSE ],
		[ /[,;:](?= )/, CLAUSE_PAUSE ],
		[ /\S(?= )/, CLAUSE_PAUSE ]
	];

	function finish( piece ) {
		if ( /[.!?]$/.test( piece.replace( /["')]+$/, '' ) ) ) {
			return piece;
		}
		return piece.replace( /[,;:\- ]+$/, '' ) + '.';
	}

	/** Alfabeye indirgenmiş metni ~10 sn'lik parçalara böler. Dönüş: [[parça, sonrasındaki duraklama sn], ...]. */
	function chunk( text, speed ) {
		speed = speed > 0.1 ? speed : 1;
		var limit = Math.floor( Math.min( MAX_LETTERS, LETTERS_PER_SECOND * MAX_SECONDS * speed ) );
		var pieces = [];
		var rest = String( text ).trim();
		while ( rest ) {
			var cut = rest.length;
			var pause = 0;
			if ( rest.length > limit ) {
				cut = limit;
				var head = rest.slice( 0, limit + 1 );
				for ( var i = 0; i < CUTS.length; i++ ) {
					var ends = matchEnds( head, CUTS[ i ][ 0 ] );
					if ( ends.length ) {
						cut = ends[ ends.length - 1 ];
						pause = CUTS[ i ][ 1 ];
						break;
					}
				}
			}
			var piece = rest.slice( 0, cut ).trim();
			rest = rest.slice( cut ).trim();
			if ( /\p{L}/u.test( piece ) ) {
				pieces.push( [ finish( piece ), pause ] );
			}
		}
		if ( pieces.length ) {
			pieces[ pieces.length - 1 ][ 1 ] = 0;
		}
		return pieces;
	}

	/** Tohumlu Gauss gürültüsü (Float32Array). */
	function gaussian( seed, n ) {
		var a = seed >>> 0;
		function uniform() {
			a = ( a + 0x6d2b79f5 ) >>> 0;
			var t = a;
			t = Math.imul( t ^ ( t >>> 15 ), t | 1 );
			t ^= t + Math.imul( t ^ ( t >>> 7 ), t | 61 );
			return ( ( t ^ ( t >>> 14 ) ) >>> 0 ) / 4294967296;
		}
		var out = new Float32Array( n );
		for ( var i = 0; i < n; i += 2 ) {
			var r = Math.sqrt( -2 * Math.log( 1 - uniform() ) );
			var th = 2 * Math.PI * uniform();
			out[ i ] = r * Math.cos( th );
			if ( i + 1 < n ) {
				out[ i + 1 ] = r * Math.sin( th );
			}
		}
		return out;
	}

	/** Her harfin kelime numarası (cw) ve kelimenin ilk harfinin konumu (wstart). */
	function words( text ) {
		var starts = [];
		var i;
		for ( i = 0; i < text.length; i++ ) {
			if ( text[ i ] !== ' ' && ( i === 0 || text[ i - 1 ] === ' ' ) ) {
				starts.push( i );
			}
		}
		if ( ! starts.length ) {
			starts.push( 0 );
		}
		var bounds = [ 0 ].concat( starts.slice( 1 ), [ text.length ] );
		var cw = [];
		var wstart = [];
		for ( var w = 0; w + 1 < bounds.length; w++ ) {
			for ( i = bounds[ w ]; i < bounds[ w + 1 ]; i++ ) {
				cw.push( w );
				wstart.push( bounds[ w ] );
			}
		}
		return { cw: cw, wstart: wstart };
	}

	/** Süreler → kare çizelgesi (T, fw) ve harf/kare konumları (cg, fg). */
	function plan( cw, wstart, dur, speed ) {
		speed = speed > 0.1 ? speed : 1;
		var L = cw.length;
		var nWords = cw[ L - 1 ] + 1;
		var d = Float32Array.from( dur, function ( x ) {
			return x / speed;
		} );
		var sums = new Float64Array( nWords );
		var i;
		for ( i = 0; i < L; i++ ) {
			sums[ cw[ i ] ] += d[ i ];
		}
		var counts = Array.from( sums, function ( s ) {
			return Math.min( MAX_WORD_FRAMES, Math.max( 1, Math.round( s ) ) );
		} );
		var T = Math.min( counts.reduce( function ( a, b ) {
			return a + b;
		}, 0 ), MAX_FRAMES );
		var fw = new Int32Array( T );
		var fp = new Float32Array( T );
		var w;
		var f = 0;
		for ( w = 0; w < nWords; w++ ) {
			for ( var j = 0; j < counts[ w ] && f < T; j++, f++ ) {
				fw[ f ] = w;
				fp[ f ] = j / counts[ w ];
			}
		}
		var c = Float32Array.from( d, function ( x ) {
			return Math.max( x, 1e-4 );
		} );
		var done = new Float32Array( L );
		var total = new Float32Array( nWords );
		var acc = 0;
		for ( i = 0; i < L; i++ ) {
			acc += c[ i ];
			done[ i ] = acc;
			total[ cw[ i ] ] += c[ i ];
		}
		function before( k ) {
			return done[ k ] - c[ k ];
		}
		var wlen = new Float32Array( nWords );
		cw.forEach( function ( x ) {
			wlen[ x ] += 1;
		} );
		var woff = new Float32Array( nWords );
		for ( w = 1; w < nWords; w++ ) {
			woff[ w ] = woff[ w - 1 ] + Math.max( wlen[ w - 1 ], 1 );
		}
		var cg = new Float32Array( L );
		var fg = new Float32Array( T );
		for ( i = 0; i < L; i++ ) {
			var cp = Math.min( 1, Math.max( 0, ( done[ i ] - before( wstart[ i ] ) - 0.5 * c[ i ] ) / Math.max( total[ cw[ i ] ], 1e-8 ) ) );
			cg[ i ] = woff[ cw[ i ] ] + cp * Math.max( wlen[ cw[ i ] ], 1 );
		}
		for ( f = 0; f < T; f++ ) {
			fg[ f ] = woff[ fw[ f ] ] + fp[ f ] * Math.max( wlen[ fw[ f ] ], 1 );
		}
		return { T: T, fw: fw, cg: cg, fg: fg };
	}

	/** Çözücü pencereleri: ilk pencere 1 sn, sonrakiler 4 sn. */
	function windows( frames, first ) {
		var spans = [];
		for ( var s = 0; s < frames; ) {
			var e = Math.min( frames, s + ( s === 0 ? first : WINDOW ) );
			spans.push( [ s, e ] );
			s = e;
		}
		return spans;
	}

	return {
		FIRST_WINDOW: FIRST_WINDOW, WINDOW: WINDOW, CONTEXT: CONTEXT,
		alphabet: alphabet, chunk: chunk, gaussian: gaussian, words: words, plan: plan, windows: windows
	};
} ) );

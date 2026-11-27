/*
 * Seslendir: Zerre-TTS (https://huggingface.co/erkint/zerre-tts, Apache-2.0) için saf yardımcılar.
 * https://github.com/erkinemreta1/zerre-tts içindeki web/public/phonemize-tr.js ve worker.js dosyalarının düz
 * JavaScript karşılığıdır: sözlük + kural tabanlı Türkçe fonemleştirme, cümle bölme. Node'da test edilir.
 * Çıkarım ys-engine.js içinde ONNX Runtime Web ile yapılır.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.YSZerre = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var VOWEL_PHONE = new Set( [ 'a', 'e', 'i', 'o', 'u', 'y', 'ø', 'œ', 'ɯ' ] );
	var BACK = new Set( [ 'a', 'ı', 'o', 'u' ] );
	var BASE = {
		a: 'a', b: 'b', c: 'dʒ', 'ç': 'tʃ', d: 'd', e: 'e', f: 'f', g: 'g', h: 'h', 'ı': 'ɯ', i: 'i', j: 'ʒ', k: 'k',
		l: 'l', m: 'm', n: 'n', o: 'o', 'ö': 'œ', p: 'p', r: 'ɾ', s: 's', 'ş': 'ʃ', t: 't', u: 'u', 'ü': 'y', v: 'v', y: 'j', z: 'z'
	};

	/** Sözlükte olmayan kelime için kural tabanlı fonemler ve vurgu (son ünlüde). */
	function fallbackPhones( word ) {
		var phones = [];
		var stress = [];
		var chars = Array.from( word );
		for ( var index = 0; index < chars.length; index++ ) {
			var letter = chars[ index ];
			if ( letter === 'ğ' ) {
				if ( phones.length && /[aeıioöuü]/.test( chars[ index - 1 ] ) ) {
					phones[ phones.length - 1 ] += 'ː';
				}
				continue;
			}
			if ( ! Object.prototype.hasOwnProperty.call( BASE, letter ) ) {
				continue;
			}
			var following = chars[ index + 1 ];
			var front = following && /[eiöü]/.test( following );
			var phone = BASE[ letter ];
			if ( letter === 'k' && front ) {
				phone = 'c';
			}
			if ( letter === 'g' && front ) {
				phone = 'ɟ';
			}
			if ( letter === 'l' && chars[ index - 1 ] && BACK.has( chars[ index - 1 ] ) ) {
				phone = 'ɫ';
			}
			phones.push( phone );
			stress.push( 0 );
		}
		for ( var i = phones.length - 1; i >= 0; i-- ) {
			if ( VOWEL_PHONE.has( phones[ i ].replace( 'ː', '' ) ) ) {
				stress[ i ] = 1;
				break;
			}
		}
		return { phones: phones, stress: stress };
	}

	/** Cümleyi model girdilerine çevirir. Dönüş: { ids, stress }. */
	function phonemize( text, lexicon, symbols ) {
		var index = new Map( symbols.map( function ( symbol, position ) {
			return [ symbol, position ];
		} ) );
		var ids = [];
		var stress = [];
		function push( symbol, level ) {
			var id = index.get( symbol );
			if ( id === undefined ) {
				return;
			}
			ids.push( id );
			stress.push( level || 0 );
		}
		push( '<bos>', 0 );
		var lastWasWord = false;
		( text.match( /[\p{L}]+|[.,!?;:—…]/gu ) || [] ).forEach( function ( token ) {
			if ( /^[\p{L}]+$/u.test( token ) ) {
				if ( lastWasWord ) {
					push( '<sp>', 0 );
				}
				var key = token.toLocaleLowerCase( 'tr' );
				var entry = Object.prototype.hasOwnProperty.call( lexicon.words, key ) ? lexicon.words[ key ] : null;
				var phones;
				var levels;
				if ( entry ) {
					phones = entry[ 0 ].split( ' ' ).filter( Boolean );
					levels = entry[ 1 ].split( '' ).map( Number );
				} else {
					var fb = fallbackPhones( key );
					phones = fb.phones;
					levels = fb.stress;
				}
				phones.forEach( function ( phone, position ) {
					push( phone, levels[ position ] );
				} );
				lastWasWord = true;
			} else {
				push( token, 0 );
				lastWasWord = false;
			}
		} );
		push( '<eos>', 0 );
		return { ids: ids, stress: stress };
	}

	/**
	 * Metni cümlelere böler; modelin en fazla maxTokens girdisi var, uzun cümleler virgül/noktalı virgülden,
	 * o da yetmezse kelime gruplarından bölünür.
	 */
	function sentences( text, maxWords ) {
		maxWords = maxWords || 40;
		var out = [];
		String( text ).replace( /\s+/g, ' ' ).trim().split( /(?<=[.!?…])\s+/ ).filter( Boolean ).forEach( function ( s ) {
			if ( s.split( ' ' ).length <= maxWords ) {
				out.push( s );
				return;
			}
			s.split( /(?<=[,;:])\s+/ ).forEach( function ( clause ) {
				var w = clause.split( ' ' );
				for ( var i = 0; i < w.length; i += maxWords ) {
					var part = w.slice( i, i + maxWords ).join( ' ' );
					out.push( /[.!?…,;:]$/.test( part ) ? part : part + ',' );
				}
			} );
		} );
		return out.filter( function ( s ) {
			return /\p{L}/u.test( s );
		} );
	}

	/** Tepe değeri sınırla (tarayıcı demosundaki limitPeak). */
	function limitPeak( samples ) {
		var loudest = 0;
		var i;
		for ( i = 0; i < samples.length; i++ ) {
			loudest = Math.max( loudest, Math.abs( samples[ i ] ) );
		}
		if ( loudest > 0.99 ) {
			for ( i = 0; i < samples.length; i++ ) {
				samples[ i ] *= 0.99 / loudest;
			}
		}
		return samples;
	}

	return { fallbackPhones: fallbackPhones, phonemize: phonemize, sentences: sentences, limitPeak: limitPeak };
} ) );

/*
 * Yazıyı Seslendir: Okur TTS (https://github.com/ahmethakanbesel/okur-tts, Apache-2.0; ağırlıklar CC BY 4.0) için
 * saf yardımcılar. Okur'un web/src/core/{chunks,noise,timeline}.ts dosyalarının düz JavaScript karşılığıdır;
 * DOM'a bağlı değildir ve Node'da test edilir. Çıkarım ys-engine.js içinde ONNX Runtime Web ile yapılır.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.YSOkur = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var MAX_LETTERS = 360;

	function splitAfter( text, marks ) {
		var parts = [];
		var start = 0;
		var re = new RegExp( marks.source, 'g' );
		var m;
		while ( ( m = re.exec( text ) ) !== null ) {
			var end = m.index + m[ 0 ].length;
			parts.push( text.slice( start, end ).trim() );
			start = end;
			if ( m[ 0 ].length === 0 ) {
				re.lastIndex++;
			}
		}
		parts.push( text.slice( start ).trim() );
		return parts.filter( function ( p ) {
			return p.length > 0;
		} );
	}

	function pack( pieces, limit ) {
		var out = [];
		pieces.forEach( function ( piece ) {
			var last = out.length ? out[ out.length - 1 ] : undefined;
			if ( last !== undefined && last.length + 1 + piece.length <= limit && last.length < 40 ) {
				out[ out.length - 1 ] = last + ' ' + piece;
			} else {
				out.push( piece );
			}
		} );
		return out;
	}

	/** Harfleri cümle boyutunda parçalara böler; kelimenin ortasından asla kesmez. */
	function chunk( letters, limit ) {
		limit = limit || MAX_LETTERS;
		var out = [];
		pack( splitAfter( letters, /[.!?]+ / ), limit ).forEach( function ( sentence ) {
			if ( sentence.length <= limit ) {
				out.push( sentence );
				return;
			}
			pack( splitAfter( sentence, /[;:,] / ), limit ).forEach( function ( clause ) {
				if ( clause.length <= limit ) {
					out.push( clause );
					return;
				}
				var rest = clause;
				while ( rest.length > limit ) {
					var cut = rest.lastIndexOf( ' ', limit );
					var at = cut > 0 ? cut : limit;
					out.push( rest.slice( 0, at ).trim() );
					rest = rest.slice( at ).trim();
				}
				if ( rest ) {
					out.push( rest );
				}
			} );
		} );
		return out;
	}

	/** Tohumlu standart normal sayılar: mulberry32 + Box–Muller (Okur ile birebir aynı). */
	function gaussianNoise( seed, count ) {
		var state = seed >>> 0;
		function uniform() {
			state = ( state + 0x6d2b79f5 ) >>> 0;
			var z = state;
			z = Math.imul( z ^ ( z >>> 15 ), z | 1 );
			z ^= z + Math.imul( z ^ ( z >>> 7 ), z | 61 );
			return ( ( z ^ ( z >>> 14 ) ) >>> 0 ) / 4294967296;
		}
		var out = new Float32Array( count );
		for ( var i = 0; i < count; i += 2 ) {
			var u1 = 1 - uniform();
			var u2 = uniform();
			var r = Math.sqrt( -2 * Math.log( u1 ) );
			out[ i ] = r * Math.cos( 2 * Math.PI * u2 );
			if ( i + 1 < count ) {
				out[ i + 1 ] = r * Math.sin( 2 * Math.PI * u2 );
			}
		}
		return out;
	}

	/** Her harfin kelimesi ve o kelimenin ilk harfi. Kelime, ardındaki boşluk ve noktalamayı da sahiplenir. */
	function words( letters ) {
		var chars = Array.from( letters );
		var starts = [];
		chars.forEach( function ( ch, i ) {
			if ( ch !== ' ' && ( i === 0 || chars[ i - 1 ] === ' ' ) ) {
				starts.push( i );
			}
		} );
		var bounds = [ 0 ].concat( starts.slice( 1 ), [ chars.length ] );
		var cw = new Int32Array( chars.length );
		var wstart = new Int32Array( chars.length );
		for ( var w = 0; w + 1 < bounds.length; w++ ) {
			cw.fill( w, bounds[ w ], bounds[ w + 1 ] );
			wstart.fill( bounds[ w ], bounds[ w ], bounds[ w + 1 ] );
		}
		return { cw: cw, wstart: wstart, nWords: Math.max( 1, bounds.length - 1 ) };
	}

	/** torch.round: yarımlar çift komşuya gider. */
	function roundHalfEven( x ) {
		var r = Math.round( x );
		return Math.abs( x - Math.trunc( x ) ) === 0.5 && r % 2 !== 0 ? r - 1 : r;
	}

	/** Kelime başına tam kare sayısı; toplam süreden sapmasın diye çalışan toplam üzerinden yuvarlanır. */
	function wordFrames( dur, cw, nWords ) {
		var perWord = new Float64Array( nWords );
		for ( var i = 0; i < cw.length; i++ ) {
			perWord[ cw[ i ] ] += dur[ i ];
		}
		var counts = new Int32Array( nWords );
		var total = 0;
		var previous = 0;
		for ( var w = 0; w < nWords; w++ ) {
			total += perWord[ w ];
			var end = roundHalfEven( total );
			counts[ w ] = Math.max( 0, end - previous );
			previous = end;
		}
		return counts;
	}

	/** Her karenin kelimesi ve kelime içindeki konumu ([0, 1)). */
	function frameTimeline( counts ) {
		var frames = 0;
		counts.forEach( function ( c ) {
			frames += c;
		} );
		var fw = new Int32Array( frames );
		var fp = new Float32Array( frames );
		var f = 0;
		counts.forEach( function ( count, w ) {
			for ( var i = 0; i < count; i++, f++ ) {
				fw[ f ] = w;
				fp[ f ] = i / count;
			}
		} );
		return { fw: fw, fp: fp };
	}

	return {
		chunk: chunk,
		gaussianNoise: gaussianNoise,
		words: words,
		wordFrames: wordFrames,
		frameTimeline: frameTimeline
	};
} ) );

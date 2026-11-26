/*
 * Yazıyı Seslendir: Piper (VITS) modeli için saf yardımcılar (DOM'a bağlı değil, Node'da test edilir).
 * piper-phonemize'ın verdiği fonem kodlarını model girdisine çevirir. Çıkarım ONNX Runtime Web ile
 * ys-engine.js içinde yapılır.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.YSPiper = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function first( map, key ) {
		var v = map && Object.prototype.hasOwnProperty.call( map, key ) ? map[ key ] : null;
		return Array.isArray( v ) ? v : null;
	}

	/**
	 * Piper'ın phonemes_to_ids kuralı: BOS, sonra her fonem için (kimlikler + PAD), en sonda EOS.
	 * Haritada olmayan fonemler atlanır. codepoints: bir cümlenin fonem kod noktaları.
	 */
	function idsFor( map, codepoints ) {
		var bos = first( map, '^' );
		var eos = first( map, '$' );
		var pad = first( map, '_' );
		var ids = [];
		var i;
		if ( bos ) {
			ids = ids.concat( bos );
		}
		for ( i = 0; i < codepoints.length; i++ ) {
			var ch = String.fromCodePoint( codepoints[ i ] );
			var m = first( map, ch );
			if ( m ) {
				ids = ids.concat( m );
				if ( pad ) {
					ids = ids.concat( pad );
				}
			}
		}
		if ( eos ) {
			ids = ids.concat( eos );
		}
		return ids;
	}

	/** [noise_scale, length_scale, noise_w]; hız arttıkça length_scale azalır. */
	function scales( cfg, speed ) {
		var inf = ( cfg && cfg.inference ) || {};
		var sp = speed > 0.1 ? speed : 1;
		return [
			typeof inf.noise_scale === 'number' ? inf.noise_scale : 0.667,
			( typeof inf.length_scale === 'number' ? inf.length_scale : 1 ) / sp,
			typeof inf.noise_w === 'number' ? inf.noise_w : 0.8
		];
	}

	/** Cümle örneklerini aralarına kısa sessizlik koyarak birleştirir. */
	function joinSentences( parts, gap ) {
		var total = 0;
		var i;
		for ( i = 0; i < parts.length; i++ ) {
			total += parts[ i ].length + ( i ? gap : 0 );
		}
		var out = new Float32Array( total );
		var pos = 0;
		for ( i = 0; i < parts.length; i++ ) {
			if ( i ) {
				pos += gap;
			}
			out.set( parts[ i ], pos );
			pos += parts[ i ].length;
		}
		return out;
	}

	return { idsFor: idsFor, scales: scales, joinSentences: joinSentences };
} ) );

/*
 * Seslendir: saf ses işlevleri (DOM'a bağlı değil, Node'da test edilir).
 * Float32 örnekler -> Int16, sessizlik ekleme, WAV başlığı, isteğe bağlı MP3.
 */
( function ( root, factory ) {
	if ( typeof module === 'object' && module.exports ) {
		module.exports = factory();
	} else {
		root.YSAudio = factory();
	}
}( typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function floatToInt16( f32 ) {
		var out = new Int16Array( f32.length );
		for ( var i = 0; i < f32.length; i++ ) {
			var s = Math.max( -1, Math.min( 1, f32[ i ] ) );
			out[ i ] = s < 0 ? Math.round( s * 0x8000 ) : Math.round( s * 0x7FFF );
		}
		return out;
	}

	function silence( sampleRate, ms ) {
		return new Int16Array( Math.max( 0, Math.round( sampleRate * ms / 1000 ) ) );
	}

	function concat( parts ) {
		var total = 0, i;
		for ( i = 0; i < parts.length; i++ ) {
			total += parts[ i ].length;
		}
		var out = new Int16Array( total ), pos = 0;
		for ( i = 0; i < parts.length; i++ ) {
			out.set( parts[ i ], pos );
			pos += parts[ i ].length;
		}
		return out;
	}

	/** 16-bit mono PCM WAV (44 baytlık başlık). */
	function buildWav( int16, sampleRate ) {
		var dataLen = int16.length * 2;
		var buf = new ArrayBuffer( 44 + dataLen );
		var v = new DataView( buf );
		function str( off, s ) {
			for ( var i = 0; i < s.length; i++ ) {
				v.setUint8( off + i, s.charCodeAt( i ) );
			}
		}
		str( 0, 'RIFF' );
		v.setUint32( 4, 36 + dataLen, true );
		str( 8, 'WAVE' );
		str( 12, 'fmt ' );
		v.setUint32( 16, 16, true );
		v.setUint16( 20, 1, true );
		v.setUint16( 22, 1, true );
		v.setUint32( 24, sampleRate, true );
		v.setUint32( 28, sampleRate * 2, true );
		v.setUint16( 32, 2, true );
		v.setUint16( 34, 16, true );
		str( 36, 'data' );
		v.setUint32( 40, dataLen, true );
		var dst = new Int16Array( buf, 44, int16.length );
		dst.set( int16 );
		return new Blob( [ buf ], { type: 'audio/wav' } );
	}

	/** lamejs yüklüyse MP3 (mono, kbps). */
	function encodeMp3( int16, sampleRate, lame, kbps ) {
		var enc = new lame.Mp3Encoder( 1, sampleRate, kbps || 64 );
		var out = [], i, buf;
		for ( i = 0; i < int16.length; i += 1152 ) {
			buf = enc.encodeBuffer( int16.subarray( i, i + 1152 ) );
			if ( buf.length ) {
				out.push( new Uint8Array( buf ) );
			}
		}
		buf = enc.flush();
		if ( buf.length ) {
			out.push( new Uint8Array( buf ) );
		}
		return new Blob( out, { type: 'audio/mpeg' } );
	}

	/** lamejs varsa MP3, yoksa WAV döndürür: { blob, ext }. */
	function encode( int16, sampleRate, lame ) {
		if ( lame && lame.Mp3Encoder ) {
			try {
				return { blob: encodeMp3( int16, sampleRate, lame, 64 ), ext: 'mp3' };
			} catch ( e ) {
				// MP3 kodlanamazsa WAV'a düş.
			}
		}
		return { blob: buildWav( int16, sampleRate ), ext: 'wav' };
	}

	/** Önizleme için ilk parçaları (en çok maxChars karakter) alır. */
	function takeHead( chunks, maxChars ) {
		var out = [], n = 0;
		for ( var i = 0; i < chunks.length; i++ ) {
			if ( out.length && n + chunks[ i ].t.length > maxChars ) {
				break;
			}
			out.push( chunks[ i ] );
			n += chunks[ i ].t.length;
		}
		return out;
	}

	function formatDuration( sec ) {
		sec = Math.max( 0, Math.round( sec || 0 ) );
		var m = Math.floor( sec / 60 ), s = sec % 60;
		return m + ':' + ( s < 10 ? '0' : '' ) + s;
	}

	function formatBytes( n ) {
		if ( n < 1024 ) {
			return n + ' B';
		}
		if ( n < 1048576 ) {
			return ( n / 1024 ).toFixed( 0 ) + ' KB';
		}
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}

	return {
		floatToInt16: floatToInt16,
		silence: silence,
		concat: concat,
		buildWav: buildWav,
		encodeMp3: encodeMp3,
		encode: encode,
		takeHead: takeHead,
		formatDuration: formatDuration,
		formatBytes: formatBytes
	};
} ) );

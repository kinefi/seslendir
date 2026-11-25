/*
 * Yazıyı Seslendir: yerel ses motorunu (sherpa-onnx WASM) yükler.
 * Hem yönetim sayfası hem de CI'daki duman testi (tools/engine-smoke.mjs) bu dosyayı kullanır;
 * böylece testte çalışan yükleme biçimi, sitede çalışanla aynıdır.
 *
 * YSEngine.load( { base, ver, lame, timeout } ) -> Promise<tts>
 *   base : motor dosyalarının adresi (sonu "/" ile biter)
 *   lame : true ise isteğe bağlı lame.min.js de yüklenir (window.lamejs)
 */
( function ( root ) {
	'use strict';

	function loadScript( src ) {
		return new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = src;
			s.async = false;
			s.onload = resolve;
			s.onerror = function () {
				reject( new Error( 'Motor dosyası yüklenemedi: ' + src.split( '?' )[ 0 ].split( '/' ).pop() ) );
			};
			document.head.appendChild( s );
		} );
	}

	function load( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve, reject ) {
			var base = opts.base;
			var v = '?v=' + encodeURIComponent( opts.ver || '0' );
			var timer = setTimeout( function () {
				reject( new Error( 'Motor zaman aşımına uğradı. Dosyaları ve bağlantınızı kontrol edin.' ) );
			}, opts.timeout || 600000 );

			root.Module = {
				locateFile: function ( p ) {
					return base + p + v;
				},
				print: function () {},
				printErr: function () {},
				onRuntimeInitialized: function () {
					clearTimeout( timer );
					try {
						resolve( root.createOfflineTts( root.Module ) );
					} catch ( e ) {
						reject( e );
					}
				}
			};

			var chain = loadScript( base + 'sherpa-onnx-tts.js' + v ).then( function () {
				return loadScript( base + 'sherpa-onnx-wasm-main-tts.js' + v );
			} );
			if ( opts.lame ) {
				chain = chain.then( function () {
					return loadScript( base + 'lame.min.js' + v ).catch( function () {} );
				} );
			}
			chain.catch( function ( e ) {
				clearTimeout( timer );
				reject( e );
			} );
		} );
	}

	root.YSEngine = { load: load };
}( typeof window !== 'undefined' ? window : this ) );

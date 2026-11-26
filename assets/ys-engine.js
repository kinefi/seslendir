/*
 * Yazıyı Seslendir: yerel ses motorunu yükler.
 *   - sherpa-onnx (WASM) sesi üretir (ana iş parçacığı),
 *   - piper-phonemize (WASM, espeak-ng) metni fonemlere çevirir (ys-phonemize-worker.js).
 * Hem yönetim sayfası hem de CI'daki duman testi (tools/engine-smoke.mjs) bu dosyayı kullanır;
 * böylece testte çalışan yükleme biçimi, sitede çalışanla aynıdır.
 *
 * YSEngine.load( { base, worker, ver, lame, voice, timeout } ) -> Promise<engine>
 *   base   : motor dosyalarının adresi (sonu "/" ile biter)
 *   worker : ys-phonemize-worker.js adresi
 *   voice  : espeak-ng ses adı (varsayılan "tr")
 *   lame   : true ise isteğe bağlı lame.min.js de yüklenir (window.lamejs)
 *
 * engine.sampleRate, engine.numSpeakers, engine.generate( { text, sid, speed } ) -> Promise<{ samples, sampleRate }>
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

	/** Fonemleştirici worker'ı: hazır olunca { phonemize(text) } döner. */
	function startPhonemizer( opts, base, v ) {
		return new Promise( function ( resolve, reject ) {
			var worker;
			try {
				worker = new Worker( opts.worker + v );
			} catch ( e ) {
				reject( new Error( 'Fonemleştirici başlatılamadı: ' + e.message ) );
				return;
			}
			var seq = 0;
			var pending = {};
			var voice = opts.voice || 'tr';
			var isReady = false;

			worker.onerror = function ( ev ) {
				var msg = 'Fonemleştirici hatası' + ( ev && ev.message ? ': ' + ev.message : '.' );
				if ( ! isReady ) {
					reject( new Error( msg ) );
				}
				Object.keys( pending ).forEach( function ( k ) {
					pending[ k ].reject( new Error( msg ) );
					delete pending[ k ];
				} );
			};
			worker.onmessage = function ( ev ) {
				var d = ev.data || {};
				if ( 'ready' === d.type ) {
					isReady = true;
					resolve( {
						phonemize: function ( text ) {
							return new Promise( function ( res, rej ) {
								var id = ++seq;
								pending[ id ] = { resolve: res, reject: rej };
								worker.postMessage( { type: 'phonemize', id: id, text: text, voice: voice } );
							} );
						}
					} );
				} else if ( 'error' === d.type ) {
					reject( new Error( d.message || 'Fonemleştirici hatası.' ) );
				} else if ( 'result' === d.type && pending[ d.id ] ) {
					pending[ d.id ].resolve( d.sentences );
					delete pending[ d.id ];
				} else if ( 'fail' === d.type && pending[ d.id ] ) {
					pending[ d.id ].reject( new Error( d.message || 'Fonemleştirme başarısız.' ) );
					delete pending[ d.id ];
				}
			};
			worker.postMessage( { type: 'init', base: base, v: v } );
		} );
	}

	/** sherpa-onnx: ana iş parçacığında, global "Module" ile. */
	function startSherpa( base, v ) {
		return new Promise( function ( resolve, reject ) {
			root.Module = {
				locateFile: function ( p ) {
					return base + p + v;
				},
				print: function () {},
				printErr: function () {},
				onRuntimeInitialized: function () {
					try {
						resolve( root.createOfflineTts( root.Module ) );
					} catch ( e ) {
						reject( e );
					}
				}
			};
			loadScript( base + 'sherpa-onnx-tts.js' + v ).then( function () {
				return loadScript( base + 'sherpa-onnx-wasm-main-tts.js' + v );
			} ).catch( reject );
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

			var jobs = [ startSherpa( base, v ), startPhonemizer( opts, base, v ) ];
			if ( opts.lame ) {
				jobs.push( loadScript( base + 'lame.min.js' + v ).catch( function () {} ) );
			}

			Promise.all( jobs ).then( function ( r ) {
				clearTimeout( timer );
				var tts = r[ 0 ];
				var ph = r[ 1 ];
				resolve( {
					sampleRate: tts.sampleRate,
					numSpeakers: tts.numSpeakers,
					generate: async function ( cfg ) {
						var sentences = await ph.phonemize( cfg.text );
						if ( ! sentences || ! sentences.length ) {
							return { samples: new Float32Array( 0 ), sampleRate: tts.sampleRate };
						}
						var audio = tts.generateWithConfig( '', {
							phonemeCodepoints: sentences,
							sid: cfg.sid || 0,
							speed: cfg.speed || 1.0
						} );
						return {
							samples: audio.samples,
							sampleRate: audio.sampleRate || tts.sampleRate
						};
					}
				} );
			}, function ( e ) {
				clearTimeout( timer );
				reject( e );
			} );
		} );
	}

	root.YSEngine = { load: load };
}( typeof window !== 'undefined' ? window : this ) );

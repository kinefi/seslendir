/*
 * Yazıyı Seslendir: yerel ses motorunu yükler.
 *   - sherpa-onnx (WASM) sesi üretir (ana iş parçacığı),
 *   - piper-phonemize (WASM, espeak-ng) metni fonemlere çevirir (ys-phonemize-worker.js).
 * Hem yönetim sayfası hem de CI'daki duman testi (tools/engine-smoke.mjs) bu dosyayı kullanır;
 * böylece testte çalışan yükleme biçimi, sitede çalışanla aynıdır.
 *
 * YSEngine.load( { kind, base, worker, ver, lame, voice, timeout } ) -> Promise<engine>
 *   kind   : "sherpa" (varsayılan) ya da "piper-ort" (ONNX Runtime Web + Piper; YSPiper gerekir) ya da "okur" (YSOkur + YSPiper gerekir)
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

	/** ONNX Runtime Web'i yükler ve ayarlar (Piper ve Okur yolları ortak kullanır). */
	async function setupOrt( base, v ) {
		await loadScript( base + 'ort.wasm.min.js' + v );
		var ort = root.ort;
		if ( ! ort || ! ort.InferenceSession ) {
			throw new Error( 'ONNX Runtime yüklenemedi.' );
		}
		// Tek iş parçacığı: SharedArrayBuffer/COOP-COEP gerektirmez. .mjs yerine .js adı, bazı
		// sunucuların .mjs için yanlış MIME türü göndermesine karşı.
		ort.env.wasm.numThreads = 1;
		ort.env.wasm.proxy = false;
		ort.env.wasm.wasmPaths = {
			mjs: base + 'ort-wasm-simd-threaded.js' + v,
			wasm: base + 'ort-wasm-simd-threaded.wasm' + v
		};
		return ort;
	}

	async function fetchBytes( url ) {
		var r = await fetch( url );
		if ( ! r.ok ) {
			throw new Error( 'Dosya okunamadı: ' + url.split( '?' )[ 0 ].split( '/' ).pop() + ' (' + r.status + ')' );
		}
		return new Uint8Array( await r.arrayBuffer() );
	}

	/** ONNX Runtime Web + Piper modeli (kind: "piper-ort"). Dönüş: { sampleRate, numSpeakers, run(ids, sid, speed) }. */
	async function startOrt( base, v ) {
		var ort = await setupOrt( base, v );
		var responses = await Promise.all( [ fetch( base + 'voice.onnx.json' + v ), fetch( base + 'voice.onnx' + v ) ] );
		if ( ! responses[ 0 ].ok || ! responses[ 1 ].ok ) {
			throw new Error( 'Ses modeli dosyaları okunamadı.' );
		}
		var cfg = await responses[ 0 ].json();
		var model = new Uint8Array( await responses[ 1 ].arrayBuffer() );
		var session = await ort.InferenceSession.create( model, { executionProviders: [ 'wasm' ], graphOptimizationLevel: 'all' } );
		var map = cfg.phoneme_id_map || {};
		var rate = ( cfg.audio && cfg.audio.sample_rate ) || 22050;
		var hasSid = session.inputNames.indexOf( 'sid' ) !== -1;

		return {
			sampleRate: rate,
			numSpeakers: cfg.num_speakers || 1,
			run: async function ( codepoints, sid, speed ) {
				var ids = root.YSPiper.idsFor( map, codepoints );
				if ( ids.length < 3 ) {
					return new Float32Array( 0 );
				}
				var feeds = {
					input: new ort.Tensor( 'int64', BigInt64Array.from( ids, BigInt ), [ 1, ids.length ] ),
					input_lengths: new ort.Tensor( 'int64', BigInt64Array.from( [ BigInt( ids.length ) ] ), [ 1 ] ),
					scales: new ort.Tensor( 'float32', Float32Array.from( root.YSPiper.scales( cfg, speed ) ), [ 3 ] )
				};
				if ( hasSid ) {
					feeds.sid = new ort.Tensor( 'int64', BigInt64Array.from( [ BigInt( sid || 0 ) ] ), [ 1 ] );
				}
				var out = await session.run( feeds );
				var tensor = out[ session.outputNames[ 0 ] ];
				return new Float32Array( tensor.data );
			}
		};
	}

	/** Okur TTS (kind: "okur"): harf kimlikleri → text/sample/decoder grafikleri. Dönüş: { sampleRate, speak(text, speed) }. */
	async function startOkur( base, v ) {
		var ort = await setupOrt( base, v );
		var O = root.YSOkur;
		var cfg = JSON.parse( new TextDecoder().decode( await fetchBytes( base + 'okur-config.json' + v ) ) );
		var got = await Promise.all( [
			fetchBytes( base + 'okur-frontend.wasm' + v ),
			fetchBytes( base + 'okur-text.onnx' + v ),
			fetchBytes( base + 'okur-sample.onnx' + v ),
			fetchBytes( base + 'okur-decoder.onnx' + v )
		] );
		var opts = { executionProviders: [ 'wasm' ], graphOptimizationLevel: 'all' };
		var made = await Promise.all( [
			WebAssembly.instantiate( got[ 0 ], {} ),
			ort.InferenceSession.create( got[ 1 ], opts ),
			ort.InferenceSession.create( got[ 2 ], opts ),
			ort.InferenceSession.create( got[ 3 ], opts )
		] );
		var wasm = made[ 0 ].instance.exports;
		var sessions = { text: made[ 1 ], sample: made[ 2 ], decoder: made[ 3 ] };
		var ids = new Map( cfg.symbols.map( function ( sym, i ) {
			return [ sym, i ];
		} ) );
		var enc = new TextEncoder();
		var dec = new TextDecoder();
		var i64 = function ( a ) {
			return BigInt64Array.from( a, function ( x ) {
				return BigInt( x );
			} );
		};
		var PAUSE = new Set( [ ' ', ',', '.', ';', ':', '!', '?', '"', '(', ')' ] );

		// Rust ön yüzü (frontend-rs): UTF-8 metin → modelin harfleri. C ABI: alloc/dealloc/letters.
		function letters( text ) {
			var bytes = enc.encode( text );
			var input = wasm.alloc( bytes.length );
			new Uint8Array( wasm.memory.buffer, input, bytes.length ).set( bytes );
			var output = wasm.letters( input, bytes.length );
			wasm.dealloc( input, bytes.length );
			var length = new DataView( wasm.memory.buffer ).getUint32( output, true );
			var result = dec.decode( new Uint8Array( wasm.memory.buffer, output + 4, length ) );
			wasm.dealloc( output, length + 4 );
			return result;
		}

		async function one( piece, speed, seed ) {
			var chars = Array.from( piece );
			var n = chars.length;
			var text = await sessions.text.run( { ids: new ort.Tensor( 'int64', i64( chars.map( function ( c ) {
				return ids.has( c ) ? ids.get( c ) : 1;
			} ) ), [ 1, n ] ) } );
			var dur = Float32Array.from( text.dur.data, function ( d ) {
				return d / speed;
			} );
			var w = O.words( piece );
			var tl = O.frameTimeline( O.wordFrames( dur, w.cw, w.nWords ) );
			var t = tl.fw.length;
			if ( ! t ) {
				return new Float32Array( 0 );
			}
			var steps = cfg.times.length;
			var c = cfg.latent_dim;
			var sampled = await sessions.sample.run( {
				h: text.h,
				dur: new ort.Tensor( 'float32', dur, [ 1, n ] ),
				cw: new ort.Tensor( 'int64', i64( w.cw ), [ 1, n ] ),
				wstart: new ort.Tensor( 'int64', i64( w.wstart ), [ 1, n ] ),
				fw: new ort.Tensor( 'int64', i64( tl.fw ), [ 1, t ] ),
				fp: new ort.Tensor( 'float32', tl.fp, [ 1, t ] ),
				noise: new ort.Tensor( 'float32', O.gaussianNoise( seed, steps * t * c ), [ steps, 1, t, c ] )
			} );
			var decoded = await sessions.decoder.run( { z: sampled.z } );
			return Float32Array.from( decoded.audio.data );
		}

		return {
			sampleRate: cfg.sample_rate || 48000,
			numSpeakers: 1,
			speak: async function ( text, speed ) {
				var pieces = O.chunk( letters( text ) );
				var parts = [];
				for ( var k = 0; k < pieces.length; k++ ) {
					// Tohum parça sırasına bağlı: aynı metin her seferinde aynı sesi verir.
					var pcm = await one( pieces[ k ], speed > 0.1 ? speed : 1, k );
					if ( pcm.length ) {
						parts.push( pcm );
					}
				}
				return root.YSPiper.joinSentences( parts, Math.round( ( cfg.sample_rate || 48000 ) * 0.08 ) );
			}
		};
	}

	function load( opts ) {
		opts = opts || {};
		return new Promise( function ( resolve, reject ) {
			var base = opts.base;
			var v = '?v=' + encodeURIComponent( opts.ver || '0' );
			var timer = setTimeout( function () {
				reject( new Error( 'Motor zaman aşımına uğradı. Dosyaları ve bağlantınızı kontrol edin.' ) );
			}, opts.timeout || 600000 );

			var isOrt = 'piper-ort' === opts.kind;
			var isOkur = 'okur' === opts.kind;
			var jobs;
			if ( isOkur ) {
				jobs = [ startOkur( base, v ), Promise.resolve( null ) ];
			} else {
				jobs = [ isOrt ? startOrt( base, v ) : startSherpa( base, v ), startPhonemizer( opts, base, v ) ];
			}
			if ( opts.lame ) {
				jobs.push( loadScript( base + 'lame.min.js' + v ).catch( function () {} ) );
			}

			Promise.all( jobs ).then( function ( r ) {
				clearTimeout( timer );
				var tts = r[ 0 ];
				var ph = r[ 1 ];
				if ( isOkur ) {
					resolve( {
						sampleRate: tts.sampleRate,
						numSpeakers: 1,
						generate: async function ( cfg ) {
							var pcm = await tts.speak( cfg.text, cfg.speed || 1.0 );
							return { samples: pcm, sampleRate: tts.sampleRate };
						}
					} );
					return;
				}
				if ( isOrt ) {
					resolve( {
						sampleRate: tts.sampleRate,
						numSpeakers: tts.numSpeakers,
						generate: async function ( cfg ) {
							var sentences = await ph.phonemize( cfg.text );
							var parts = [];
							for ( var i = 0; i < ( sentences || [] ).length; i++ ) {
								var pcm = await tts.run( sentences[ i ], cfg.sid || 0, cfg.speed || 1.0 );
								if ( pcm.length ) {
									parts.push( pcm );
								}
							}
							// Cümleler arası ~150 ms.
							var gap = Math.round( tts.sampleRate * 0.15 );
							return { samples: root.YSPiper.joinSentences( parts, gap ), sampleRate: tts.sampleRate };
						}
					} );
					return;
				}
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

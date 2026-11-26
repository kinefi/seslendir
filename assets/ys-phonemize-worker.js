/*
 * Seslendir: metni fonemlere çeviren Web Worker (piper-phonemize WASM, espeak-ng).
 * sherpa-onnx artık metni kendisi fonemleştirmiyor; hazır fonem kodlarını bekliyor. Bu iş,
 * ana iş parçacığındaki sherpa modülüyle "Module" adını paylaşmamak için ayrı bir worker'da yapılır.
 *
 * İletiler:
 *   ana -> worker : { type:'init', base, v }  |  { type:'phonemize', id, text, voice }
 *   worker -> ana : { type:'ready' } | { type:'error', message } | { type:'result', id, sentences } | { type:'fail', id, message }
 */
'use strict';

var ysBase = '';
var ysVer = '';

self.Module = {
	locateFile: function ( p ) {
		return ysBase + p + ysVer;
	},
	print: function () {},
	printErr: function () {},
	onRuntimeInitialized: function () {
		try {
			var ret = self.piperPhonemizeInitialize();
			if ( ret >= 0 ) {
				self.postMessage( { type: 'ready' } );
			} else {
				self.postMessage( { type: 'error', message: 'espeak-ng başlatılamadı.' } );
			}
		} catch ( e ) {
			self.postMessage( { type: 'error', message: String( e && e.message ? e.message : e ) } );
		}
	}
};

/**
 * Motor klasörünün adresini doğrular: yalnızca bu worker ile aynı kökende ve yalnızca harf/rakam/_ - . / içeren,
 * ".." barındırmayan, "/" ile biten bir yol kabul edilir. Dönüş değeri, güvenilir kök + doğrulanmış yoldan kurulur;
 * ham girdi hiçbir zaman doğrudan importScripts'e verilmez. Geçersizse null.
 */
function safeBase( raw ) {
	var url;
	try {
		url = new URL( String( raw ), self.location.href );
	} catch ( err ) {
		return null;
	}
	if ( url.origin !== self.location.origin ) {
		return null;
	}
	var path = url.pathname;
	if ( ! /^\/[A-Za-z0-9_\-./]*\/$/.test( path ) || path.indexOf( '..' ) !== -1 ) {
		return null;
	}
	return self.location.origin + path;
}

/** "?v=1.3.0" biçimindeki sürüm eki; başka her şey boş sayılır. */
function safeVer( raw ) {
	var v = String( raw || '' );
	return /^\?v=[A-Za-z0-9._-]{1,40}$/.test( v ) ? v : '';
}

var ysStarted = false;

self.onmessage = function ( e ) {
	// Bu worker'a yalnızca onu oluşturan sayfa ileti gönderebilir (origin dedicated worker'larda boştur);
	// başka bir köken görünürse yok sayılır.
	if ( e.origin && e.origin !== self.location.origin ) {
		return;
	}
	var d = e.data || {};
	if ( 'init' === d.type ) {
		if ( ysStarted ) {
			return;
		}
		var base = safeBase( d.base );
		if ( null === base ) {
			self.postMessage( { type: 'error', message: 'Motor klasörü bu siteyle aynı kökende olmalıdır.' } );
			return;
		}
		ysStarted = true;
		ysBase = base;
		ysVer = safeVer( d.v );
		try {
			self.importScripts( ysBase + 'piper-phonemize.js' + ysVer, ysBase + 'piper-phonemize-wasm-main.js' + ysVer );
		} catch ( err ) {
			self.postMessage( { type: 'error', message: 'Fonemleştirici dosyaları yüklenemedi (piper-phonemize-*).' } );
		}
		return;
	}
	if ( 'phonemize' === d.type ) {
		try {
			var out = self.piperPhonemize( String( d.text || '' ), d.voice || 'tr' );
			var sentences = [];
			if ( out ) {
				for ( var i = 0; i < out.length; i++ ) {
					// Boş cümleleri C API kabul etmez.
					if ( out[ i ].length > 0 ) {
						sentences.push( out[ i ] );
					}
				}
			}
			self.postMessage( { type: 'result', id: d.id, sentences: sentences } );
		} catch ( err2 ) {
			self.postMessage( { type: 'fail', id: d.id, message: String( err2 && err2.message ? err2.message : err2 ) } );
		}
	}
};

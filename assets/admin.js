/*
 * Seslendir: yönetim sayfası.
 * Liste, durum, toplu üretim, önizleme ve kilitleyici üretim penceresi.
 * Sesler bu tarayıcıda, yerel WASM motoruyla üretilir; sunucuya yalnızca hazır dosya gider.
 */
( function () {
	'use strict';

	var Y = window.YS || {};
	var A = window.YSAudio;
	var root = document.getElementById( 'ys-app' );
	if ( ! root || ! A || ! window.YSEngine ) {
		return;
	}

	/* ---------- Yardımcılar ---------- */

	function h( tag, attrs ) {
		var el = document.createElement( tag );
		var k, i, c;
		for ( k in ( attrs || {} ) ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) {
				continue;
			}
			if ( k === 'class' ) {
				el.className = v;
			} else if ( k === 'text' ) {
				el.textContent = v;
			} else if ( k.slice( 0, 2 ) === 'on' ) {
				el.addEventListener( k.slice( 2 ), v );
			} else {
				el.setAttribute( k, v === true ? '' : v );
			}
		}
		for ( i = 2; i < arguments.length; i++ ) {
			c = arguments[ i ];
			if ( c === null || c === undefined || c === false ) {
				continue;
			}
			el.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		}
		return el;
	}

	function api( method, path, opts ) {
		opts = opts || {};
		var url = Y.rest + path;
		if ( opts.params ) {
			var q = [];
			Object.keys( opts.params ).forEach( function ( k ) {
				q.push( encodeURIComponent( k ) + '=' + encodeURIComponent( opts.params[ k ] ) );
			} );
			url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + q.join( '&' );
		}
		var headers = { 'X-WP-Nonce': Y.nonce };
		if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/octet-stream';
		}
		return fetch( url, { method: method, headers: headers, body: opts.body || undefined, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text().then( function ( txt ) {
					var data = null;
					try {
						data = txt ? JSON.parse( txt ) : null;
					} catch ( e ) {
						data = null;
					}
					if ( ! r.ok ) {
						var msg = ( data && data.message ) ? data.message : 'Sunucu hatası (' + r.status + ').';
						if ( r.status === 413 && ! ( data && data.message ) ) {
							msg = 'Ses dosyası sunucunun yükleme sınırını aşıyor. Ayarlardan sınırı kontrol edin ya da MP3 kodlayıcıyı (lame.min.js) ekleyin.';
						}
						throw new Error( msg + ' [' + method + ' ' + path + ' → ' + r.status + ']' );
					}
					return data;
				} );
			} );
	}

	function tick() {
		return new Promise( function ( r ) { setTimeout( r, 0 ); } );
	}

	/* ---------- Kilitleyici pencere ---------- */

	var modal = ( function () {
		var ov, dlg, titleEl, curEl, bar, body, actions, locked = false, wake = null;

		function onBeforeUnload( e ) {
			if ( locked ) {
				e.preventDefault();
				e.returnValue = '';
			}
		}

		function onKey( e ) {
			if ( ! ov ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				e.stopPropagation();
			}
			if ( e.key === 'Tab' ) {
				var f = dlg.querySelectorAll( 'button:not([disabled]), audio' );
				if ( ! f.length ) {
					e.preventDefault();
					return;
				}
				var first = f[ 0 ], last = f[ f.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		}

		function build() {
			titleEl = h( 'h2', { id: 'ys-modal-title' } );
			curEl = h( 'p', { 'class': 'ys-current', 'aria-live': 'polite' } );
			bar = h( 'progress', { 'class': 'ys-bar', max: '100', value: '0' } );
			body = h( 'div', { 'class': 'ys-modal-body' } );
			actions = h( 'div', { 'class': 'ys-actions' } );
			dlg = h( 'div', { 'class': 'ys-dialog', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'ys-modal-title', tabindex: '-1' }, titleEl, curEl, bar, body, actions );
			ov = h( 'div', { 'class': 'ys-overlay' }, dlg );
			document.body.appendChild( ov );
			document.body.classList.add( 'ys-lock' );
			document.addEventListener( 'keydown', onKey, true );
			window.addEventListener( 'beforeunload', onBeforeUnload );
			if ( navigator.wakeLock && navigator.wakeLock.request ) {
				navigator.wakeLock.request( 'screen' ).then( function ( l ) { wake = l; }, function () {} );
			}
		}

		return {
			open: function ( title ) {
				if ( ! ov ) {
					build();
				}
				titleEl.textContent = title;
				this.set( '', 0 );
				this.body( null );
				this.actions( [] );
				this.lock( true );
				dlg.focus();
			},
			set: function ( text, pct ) {
				curEl.textContent = text;
				if ( typeof pct === 'number' ) {
					bar.value = Math.max( 0, Math.min( 100, pct ) );
				}
			},
			body: function ( node ) {
				body.textContent = '';
				if ( node ) {
					body.appendChild( node );
				}
			},
			actions: function ( list ) {
				actions.textContent = '';
				list.forEach( function ( a ) {
					actions.appendChild( h( 'button', { type: 'button', 'class': 'button ' + ( a.primary ? 'button-primary' : '' ), onclick: a.onClick, text: a.label } ) );
				} );
				var b = actions.querySelector( 'button' );
				if ( b ) {
					b.focus();
				}
			},
			lock: function ( v ) {
				locked = v;
			},
			close: function () {
				locked = false;
				if ( ov ) {
					document.removeEventListener( 'keydown', onKey, true );
					window.removeEventListener( 'beforeunload', onBeforeUnload );
					ov.parentNode.removeChild( ov );
					ov = null;
					document.body.classList.remove( 'ys-lock' );
					if ( wake && wake.release ) {
						wake.release().catch( function () {} );
						wake = null;
					}
				}
			}
		};
	}() );

	/* ---------- Motor (yerel WASM) ---------- */

	var enginePromise = null;

	function getEngine() {
		if ( ! enginePromise ) {
			enginePromise = window.YSEngine.load( { kind: Y.kind, base: Y.engineUrl, worker: Y.workerUrl, ver: Y.ver, lame: Y.lame } );
			enginePromise.catch( function () { enginePromise = null; } );
		}
		return enginePromise;
	}

	/* ---------- Üretim ---------- */

	var cancelled = false;

	async function synth( tts, chunks, speed, sid, onProgress ) {
		var sr = tts.sampleRate || 22050;
		var parts = [];
		for ( var i = 0; i < chunks.length; i++ ) {
			if ( cancelled ) {
				throw new Error( 'cancelled' );
			}
			var audio = await tts.generate( { text: chunks[ i ].t, sid: sid, speed: speed } );
			if ( audio && audio.samples && audio.samples.length ) {
				sr = audio.sampleRate || sr;
				parts.push( A.floatToInt16( audio.samples ) );
			}
			parts.push( A.silence( sr, chunks[ i ].p || 280 ) );
			onProgress( ( i + 1 ) / chunks.length, i + 1, chunks.length );
			await tick();
		}
		if ( ! parts.length ) {
			throw new Error( 'Motor ses üretmedi.' );
		}
		return { pcm: A.concat( parts ), rate: sr };
	}

	function previewNode( chunks, blob ) {
		var url = URL.createObjectURL( blob );
		var text = chunks.map( function ( c ) { return c.t; } ).join( '\n' );
		return h( 'div', { 'class': 'ys-preview' },
			h( 'audio', { controls: true, src: url, autoplay: true } ),
			h( 'details', { open: true },
				h( 'summary', { text: 'Okunan metin (sayılar ve kısaltmalar açılmış hâli)' } ),
				h( 'pre', { 'class': 'ys-text', text: text } )
			)
		);
	}

	function waitChoice( node, primary, secondary ) {
		return new Promise( function ( resolve ) {
			modal.body( node );
			modal.actions( [
				{ label: primary, primary: true, onClick: function () { resolve( true ); } },
				{ label: secondary, onClick: function () { resolve( false ); } }
			] );
		} );
	}

	/**
	 * Tek yazı: metin al -> üret -> (önizleme/onay) -> yükle.
	 * mode: 'save' | 'preview'. Dönüş: 'saved' | 'discarded' | 'previewed'.
	 */
	async function processPost( id, mode, label, base, span, askConfirm ) {
		function pct( f ) { return base + span * f; }
		modal.set( label + ': metin hazırlanıyor…', pct( 0.02 ) );
		var r = await api( 'GET', 'post/' + id + '/text' );
		var chunks = mode === 'preview' ? A.takeHead( r.chunks, 450 ) : r.chunks;

		modal.set( label + ': motor yükleniyor (ilk seferde birkaç dakika sürebilir)…', pct( 0.05 ) );
		var tts = await getEngine();

		var out = await synth( tts, chunks, r.speed, r.speaker, function ( f, i, n ) {
			modal.set( label + ': seslendiriliyor (' + i + ' / ' + n + ' cümle)', pct( 0.1 + 0.8 * f ) );
		} );

		if ( mode === 'preview' ) {
			await waitChoice( previewNode( chunks, A.buildWav( out.pcm, out.rate ) ), 'Kapat', 'Kapat' );
			return 'previewed';
		}

		modal.set( label + ': ses dosyası hazırlanıyor…', pct( 0.92 ) );
		await tick();
		var enc = A.encode( out.pcm, out.rate, window.lamejs );
		if ( enc.blob.size > Y.maxBytes ) {
			throw new Error( 'Ses dosyası ' + A.formatBytes( enc.blob.size ) + '; sınır ' + A.formatBytes( Y.maxBytes ) +
				'. ' + ( enc.ext === 'wav' ? 'MP3 kodlayıcıyı (lame.min.js) ekleyerek dosyayı küçültebilirsiniz.' : 'Sınırı Ayarlar sekmesinden yükseltin.' ) );
		}

		if ( askConfirm ) {
			var ok = await waitChoice( previewNode( chunks, enc.blob ), 'Kaydet', 'Vazgeç' );
			if ( ! ok ) {
				return 'discarded';
			}
			modal.body( null );
			modal.actions( [] );
		}

		modal.set( label + ': kaydediliyor…', pct( 0.96 ) );
		await api( 'POST', 'post/' + id + '/audio', {
			params: { hash: r.hash, duration: ( out.pcm.length / out.rate ).toFixed( 2 ) },
			body: enc.blob
		} );
		return 'saved';
	}

	async function run( items, mode ) {
		if ( ! Y.engineReady ) {
			window.alert( 'Ses motoru kurulu değil. Kurulum sekmesine bakın.' );
			return;
		}
		cancelled = false;
		var failed = [], saved = 0, discarded = 0;
		var preview = mode === 'preview';
		modal.open( preview ? 'Önizleme' : ( items.length > 1 ? 'Yazılar seslendiriliyor' : 'Yazı seslendiriliyor' ) );
		var cancelBtn = { label: 'İptal', onClick: function () {
			if ( window.confirm( 'Üretim durdurulsun mu? Tamamlanan sesler korunur.' ) ) {
				cancelled = true;
			}
		} };
		modal.actions( [ cancelBtn ] );

		for ( var i = 0; i < items.length; i++ ) {
			if ( cancelled ) {
				break;
			}
			var it = items[ i ];
			var label = 'Yazı ' + ( i + 1 ) + ' / ' + items.length + ' – ' + it.title;
			try {
				var res = await processPost( it.id, mode, label, ( i / items.length ) * 100, 100 / items.length, ! preview && items.length === 1 && Y.confirm );
				if ( res === 'saved' ) {
					saved++;
				} else if ( res === 'discarded' ) {
					discarded++;
				}
				modal.lock( true );
				modal.body( null );
				modal.actions( [ cancelBtn ] );
			} catch ( e ) {
				if ( e && e.message === 'cancelled' ) {
					break;
				}
				failed.push( { title: it.title, msg: e && e.message ? e.message : String( e ) } );
				if ( /Motor|WASM|createOfflineTts|Module/i.test( failed[ failed.length - 1 ].msg ) ) {
					break; // Motor sorunu: kalan yazıları denemenin anlamı yok.
				}
			}
		}

		modal.lock( false );
		modal.set( '', 100 );
		var summary = h( 'div', { 'class': 'ys-summary' } );
		if ( ! preview ) {
			summary.appendChild( h( 'p', { text: saved + ' yazı seslendirildi' + ( discarded ? ', ' + discarded + ' vazgeçildi' : '' ) + ( failed.length ? ', ' + failed.length + ' hata' : '' ) + ( cancelled ? ' (iptal edildi)' : '' ) + '.' } ) );
		}
		if ( failed.length ) {
			var ul = h( 'ul', { 'class': 'ys-errors' } );
			failed.forEach( function ( f ) { ul.appendChild( h( 'li', { text: f.title + ': ' + f.msg } ) ); } );
			summary.appendChild( ul );
		}
		modal.body( summary.childNodes.length ? summary : null );
		await new Promise( function ( resolve ) {
			modal.actions( [ { label: 'Tamam', primary: true, onClick: resolve } ] );
		} );
		modal.close();
		load();
	}

	/* ---------- Liste ---------- */

	var state = { page: 1, status: 'all', search: '', selected: {}, data: null, error: '' };
	var tableHost = h( 'div', { 'class': 'ys-table-host' } );
	var summaryHost = h( 'p', { 'class': 'ys-summary-line' } );
	var pagerHost = h( 'div', { 'class': 'tablenav-pages ys-pager' } );
	var bulkBtn, allBtn, staleBtn;

	var STATE_LABEL = { ok: '🔊 Var', stale: '⚠️ Eski', none: '➕ Yok' };

	function load() {
		state.error = '';
		api( 'GET', 'posts', { params: { page: state.page, status: state.status, search: state.search } } )
			.then( function ( d ) {
				state.data = d;
				state.selected = {};
				render();
			}, function ( e ) {
				state.error = e.message;
				render();
			} );
	}

	function selectedItems() {
		var out = [];
		( state.data ? state.data.items : [] ).forEach( function ( it ) {
			if ( state.selected[ it.id ] ) {
				out.push( { id: it.id, title: it.title } );
			}
		} );
		return out;
	}

	function queueAll( mode ) {
		api( 'GET', 'queue', { params: { mode: mode } } ).then( function ( d ) {
			if ( ! d.items.length ) {
				window.alert( 'Seslendirilecek yazı yok.' );
				return;
			}
			if ( window.confirm( d.items.length + ' yazı sırayla seslendirilecek. Bu sırada sekmeyi kapatmayın. Devam edilsin mi?' ) ) {
				run( d.items, 'save' );
			}
		}, function ( e ) { window.alert( e.message ); } );
	}

	function rowActions( it ) {
		var box = h( 'span', { 'class': 'ys-row-actions' } );
		var dis = ! Y.engineReady;
		box.appendChild( h( 'button', { type: 'button', 'class': 'button button-small', disabled: dis, onclick: function () { run( [ { id: it.id, title: it.title } ], 'save' ); }, text: it.state === 'none' ? 'Üret' : 'Yeniden üret' } ) );
		box.appendChild( h( 'button', { type: 'button', 'class': 'button button-small', disabled: dis, onclick: function () { run( [ { id: it.id, title: it.title } ], 'preview' ); }, text: 'Önizle' } ) );
		if ( it.state !== 'none' ) {
			box.appendChild( h( 'button', { type: 'button', 'class': 'button button-small', onclick: function ( e ) {
				var cell = e.target.closest( 'td' );
				var ex = cell.querySelector( 'audio' );
				if ( ex ) {
					ex.parentNode.removeChild( ex );
					return;
				}
				cell.appendChild( h( 'audio', { controls: true, autoplay: true, preload: 'none', src: it.url } ) );
			}, text: 'Dinle' } ) );
			box.appendChild( h( 'button', { type: 'button', 'class': 'button button-small button-link-delete', onclick: function () {
				if ( window.confirm( '"' + it.title + '" için kayıtlı ses silinsin mi?' ) ) {
					api( 'DELETE', 'post/' + it.id + '/audio' ).then( load, function ( e ) { window.alert( e.message ); } );
				}
			}, text: 'Sil' } ) );
		}
		return box;
	}

	function render() {
		var d = state.data;
		tableHost.textContent = '';
		pagerHost.textContent = '';
		if ( state.error ) {
			tableHost.appendChild( h( 'div', { 'class': 'notice notice-error inline' }, h( 'p', { text: state.error } ) ) );
			return;
		}
		if ( ! d ) {
			tableHost.appendChild( h( 'p', { text: 'Yükleniyor…' } ) );
			return;
		}
		summaryHost.textContent = d.summary.total + ' yayımlanmış yazı: ' + d.summary.with + ' tanesinde ses var, ' + ( d.summary.total - d.summary.with ) + ' tanesinde yok.';

		if ( ! d.items.length ) {
			tableHost.appendChild( h( 'p', { text: d.summary.total ? 'Bu süzgece uyan yazı yok.' : 'Seslendirilecek yazı yok. Ayarlar sekmesinden içerik türlerini kontrol edin.' } ) );
			return;
		}

		var allBox = h( 'input', { type: 'checkbox', 'aria-label': 'Hepsini seç', onchange: function ( e ) {
			d.items.forEach( function ( it ) { state.selected[ it.id ] = e.target.checked; } );
			render();
		} } );
		var head = h( 'tr', null, h( 'td', { 'class': 'check-column' }, allBox ), h( 'th', { text: 'Başlık' } ), h( 'th', { text: 'Tarih' } ), h( 'th', { text: 'Ses' } ), h( 'th', { text: 'Süre / boyut' } ), h( 'th', { text: 'İşlem' } ) );
		var tbody = h( 'tbody' );
		d.items.forEach( function ( it ) {
			var cb = h( 'input', { type: 'checkbox', 'aria-label': it.title, onchange: function ( e ) { state.selected[ it.id ] = e.target.checked; updateBulk(); } } );
			cb.checked = !! state.selected[ it.id ];
			tbody.appendChild( h( 'tr', null,
				h( 'th', { 'class': 'check-column', scope: 'row' }, cb ),
				h( 'td', null, h( 'a', { href: it.edit || '#', text: it.title } ) ),
				h( 'td', { text: it.date } ),
				h( 'td', { 'class': 'ys-state ys-' + it.state, text: STATE_LABEL[ it.state ] } ),
				h( 'td', { text: it.state === 'none' ? '—' : A.formatDuration( it.duration ) + ' · ' + A.formatBytes( it.size ) } ),
				h( 'td', null, rowActions( it ) )
			) );
		} );
		tableHost.appendChild( h( 'table', { 'class': 'widefat striped ys-table' }, h( 'thead', null, head ), tbody ) );

		if ( d.pages > 1 ) {
			pagerHost.appendChild( h( 'span', { 'class': 'displaying-num', text: d.total + ' yazı' } ) );
			pagerHost.appendChild( h( 'button', { type: 'button', 'class': 'button', disabled: state.page <= 1, onclick: function () { state.page--; load(); }, text: '‹ Önceki' } ) );
			pagerHost.appendChild( h( 'span', { 'class': 'paging-input', text: ' ' + state.page + ' / ' + d.pages + ' ' } ) );
			pagerHost.appendChild( h( 'button', { type: 'button', 'class': 'button', disabled: state.page >= d.pages, onclick: function () { state.page++; load(); }, text: 'Sonraki ›' } ) );
		}
		updateBulk();
	}

	function updateBulk() {
		if ( bulkBtn ) {
			var n = selectedItems().length;
			bulkBtn.disabled = ! n || ! Y.engineReady;
			bulkBtn.textContent = n ? 'Seçilenleri seslendir (' + n + ')' : 'Seçilenleri seslendir';
		}
	}

	function buildToolbar() {
		var search = h( 'input', { type: 'search', placeholder: 'Başlıkta ara…', 'aria-label': 'Ara' } );
		var sel = h( 'select', { 'aria-label': 'Durum' },
			h( 'option', { value: 'all', text: 'Tüm durumlar' } ),
			h( 'option', { value: 'none', text: 'Sesi olmayanlar' } ),
			h( 'option', { value: 'stale', text: 'Eski olanlar' } ),
			h( 'option', { value: 'ok', text: 'Güncel olanlar' } )
		);
		function apply() {
			state.search = search.value.trim();
			state.status = sel.value;
			state.page = 1;
			load();
		}
		search.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); apply(); } } );
		sel.addEventListener( 'change', apply );

		bulkBtn = h( 'button', { type: 'button', 'class': 'button', onclick: function () { run( selectedItems(), 'save' ); }, text: 'Seçilenleri seslendir' } );
		allBtn = h( 'button', { type: 'button', 'class': 'button button-primary', disabled: ! Y.engineReady, onclick: function () { queueAll( 'missing' ); }, text: 'Eksik olanların hepsini seslendir' } );
		staleBtn = h( 'button', { type: 'button', 'class': 'button', disabled: ! Y.engineReady, onclick: function () { queueAll( 'stale' ); }, text: 'Eski olanları yenile' } );

		return h( 'div', { 'class': 'ys-toolbar' }, search, sel, h( 'button', { type: 'button', 'class': 'button', onclick: apply, text: 'Süz' } ), h( 'span', { 'class': 'ys-spacer' } ), bulkBtn, staleBtn, allBtn );
	}

	/* ---------- Başlat ---------- */

	if ( ! Y.engineReady ) {
		var msg = 'Ses motoru kurulu değil, bu yüzden ses üretilemiyor.';
		var box = h( 'div', { 'class': 'notice notice-warning inline' }, h( 'p', null, msg + ' ', Y.canSetup ? h( 'a', { href: Y.setupUrl, text: 'Kurulum sekmesine gidin.' } ) : 'Site yöneticisinden motoru kurmasını isteyin.' ) );
		root.appendChild( box );
	}
	root.appendChild( summaryHost );
	root.appendChild( buildToolbar() );
	root.appendChild( tableHost );
	root.appendChild( pagerHost );
	render();
	load();

	if ( Y.startId && Y.engineReady ) {
		if ( window.history && history.replaceState ) {
			history.replaceState( null, '', window.location.href.replace( /([?&])ys_start=\d+&?/, '$1' ).replace( /[?&]$/, '' ) );
		}
		api( 'GET', 'post/' + Y.startId + '/text' ).then( function ( d ) {
			run( [ { id: d.id, title: d.title } ], 'save' );
		}, function ( e ) { window.alert( e.message ); } );
	}
}() );

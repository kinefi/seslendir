/*
 * Seslendir: Kurulum sekmesi, otomatik motor indirme.
 * Sunucu, paketi küçük parçalarla indirir; bu betik "bitti" yanıtı gelene kadar adım ister.
 */
( function () {
	'use strict';

	var Y = window.YSSetup || {};
	var host = document.getElementById( 'ys-setup-auto' );
	if ( ! host ) {
		return;
	}

	var running = false;
	var cancelled = false;

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

	function mb( n ) {
		return ( n / 1048576 ).toFixed( 1 ) + ' MB';
	}

	function api( method, path ) {
		var url = Y.rest + path;
		return fetch( url, { method: method, headers: { 'X-WP-Nonce': Y.nonce }, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.text().then( function ( txt ) {
					var data = null;
					try {
						data = txt ? JSON.parse( txt ) : null;
					} catch ( e ) {
						data = null;
					}
					if ( ! r.ok ) {
						var err = new Error( ( data && data.message ) ? data.message : 'Sunucu hatası (' + r.status + ').' );
						err.status = r.status;
						throw err;
					}
					return data;
				} );
			} );
	}

	function sleep( ms ) {
		return new Promise( function ( r ) { setTimeout( r, ms ); } );
	}

	var info = h( 'p', { 'class': 'ys-setup-info', 'aria-live': 'polite' } );
	var bar = h( 'progress', { max: '100', value: '0', 'class': 'ys-bar', hidden: true } );
	var err = h( 'p', { 'class': 'ys-setup-error', role: 'alert' } );
	var mode = 'check'; // 'check' = bilgiyi yeniden al, 'run' = indirmeyi başlat.
	var go = h( 'button', { type: 'button', 'class': 'button button-primary', disabled: true, text: 'Kontrol ediliyor…', onclick: onGo } );
	var stop = h( 'button', { type: 'button', 'class': 'button', hidden: true, onclick: stopRun, text: 'Durdur' } );
	var reset = h( 'button', { type: 'button', 'class': 'button button-link-delete', hidden: true, onclick: doReset, text: 'Yarım dosyaları sıfırla' } );

	host.textContent = '';
	host.appendChild( info );
	host.appendChild( bar );
	host.appendChild( err );
	host.appendChild( h( 'p', null, go, ' ', stop, ' ', reset ) );

	function onGo() {
		if ( mode === 'check' ) {
			go.disabled = true;
			loadStatus( true );
		} else {
			start();
		}
	}

	function onBeforeUnload( e ) {
		if ( running ) {
			e.preventDefault();
			e.returnValue = '';
		}
	}

	function showProgress( p ) {
		if ( ! p ) {
			return;
		}
		var pct = p.total_size ? ( p.total_done / p.total_size ) * 100 : 0;
		bar.hidden = false;
		bar.value = pct;
		info.textContent = p.done
			? 'Tüm dosyalar indirildi ve doğrulandı.'
			: 'İndiriliyor: ' + p.file + ' (dosya ' + ( p.index + 1 ) + ' / ' + p.count + ') · ' + mb( p.total_done ) + ' / ' + mb( p.total_size );
	}

	function setIdle( labelText ) {
		running = false;
		go.disabled = false;
		go.textContent = labelText;
		stop.hidden = true;
		window.removeEventListener( 'beforeunload', onBeforeUnload );
	}

	function loadStatus( refresh ) {
		err.textContent = '';
		return api( 'GET', 'engine/status' + ( refresh ? '?refresh=1' : '' ) ).then( function ( s ) {
			if ( ! s.manifest ) {
				info.textContent = s.ready ? 'Motor kurulu.' : 'Motor kurulu değil.';
				err.textContent = s.error || '';
				mode = 'check';
				go.disabled = false;
				go.textContent = 'Yeniden dene';
				return;
			}
			mode = 'run';
			var m = s.manifest;
			var hasPartial = m.progress.total_done > 0 && ! m.progress.done;
			info.textContent = ( s.ready ? 'Motor kurulu. ' : '' ) + 'Paket: ' + ( m.voice || 'Türkçe ses' ) + ', ' + m.count + ' dosya, ' + mb( m.total ) + '.' +
				( hasPartial ? ' Yarım kalan indirme var; devam edilecek.' : '' );
			if ( hasPartial ) {
				showProgress( m.progress );
				reset.hidden = false;
			}
			go.disabled = false;
			go.textContent = s.ready ? 'Yeniden indir' : ( hasPartial ? 'İndirmeye devam et' : 'Otomatik indir ve kur' );
		}, function ( e ) {
			info.textContent = '';
			err.textContent = e.message;
			mode = 'check';
			go.disabled = false;
			go.textContent = 'Yeniden dene';
		} );
	}

	async function start() {
		if ( running ) {
			return;
		}
		running = true;
		cancelled = false;
		err.textContent = '';
		go.disabled = true;
		go.textContent = 'İndiriliyor…';
		stop.hidden = false;
		reset.hidden = true;
		window.addEventListener( 'beforeunload', onBeforeUnload );

		var failures = 0;
		while ( ! cancelled ) {
			try {
				var p = await api( 'POST', 'engine/step' );
				failures = 0;
				showProgress( p );
				if ( p.done ) {
					info.textContent = 'Kurulum tamamlandı. Sayfa yenileniyor…';
					running = false;
					window.removeEventListener( 'beforeunload', onBeforeUnload );
					setTimeout( function () { window.location.reload(); }, 800 );
					return;
				}
			} catch ( e ) {
				// Anlık ağ/sunucu sorunlarında birkaç kez otomatik dene; kalıcı hatada dur.
				failures++;
				if ( e.status === 409 ) {
					await sleep( 3000 );
					continue;
				}
				if ( failures >= 3 || ( e.status && e.status < 500 && e.status !== 502 ) ) {
					err.textContent = e.message;
					setIdle( 'İndirmeye devam et' );
					reset.hidden = false;
					return;
				}
				await sleep( 2000 * failures );
			}
		}
		info.textContent = 'Durduruldu. Kaldığınız yerden devam edebilirsiniz.';
		setIdle( 'İndirmeye devam et' );
		reset.hidden = false;
	}

	function stopRun() {
		cancelled = true;
		stop.disabled = true;
	}

	function doReset() {
		if ( ! window.confirm( 'Yarım kalan indirilen parçalar silinsin mi? Tamamlanmış dosyalara dokunulmaz.' ) ) {
			return;
		}
		api( 'POST', 'engine/cancel' ).then( function () {
			bar.hidden = true;
			reset.hidden = true;
			loadStatus( true );
		}, function ( e ) { err.textContent = e.message; } );
	}

	loadStatus( false );
}() );

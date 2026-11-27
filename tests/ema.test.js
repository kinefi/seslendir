'use strict';
// assets/ys-ema.js için testler. Beklenen değerler, ema-lightning-web/tts.js referans uygulamasının çıktısıdır
// (alphabet, chunk, words, plan, gaussian ve windows ile birebir karşılaştırıldı).
const assert = require( 'assert' );
const E = require( '../assets/ys-ema.js' );

let n = 0;
function t( name, fn ) {
	try {
		fn();
		n++;
	} catch ( e ) {
		console.error( 'FAIL: ' + name + '\n  ' + e.message );
		process.exitCode = 1;
	}
}

const vocab = new Set( Array.from( ' !"%&\'(),-./:;?abcdefghijklmnopqrstuvwxyzçöüğış' + 'ı' ) );

t( 'alphabet: büyük/küçük harf, tipografi, noktalama', () => {
	assert.strictEqual(
		E.alphabet( 'İSTANBUL’da ÇOK güzel… “Merhaba” — dünya! ğüşiöç ıI', vocab ),
		'istanbul\'da çok güzel... "merhaba" - dünya! ğüşiöç ıı'
	);
} );
t( 'alphabet: bilinmeyen karakterler boşluk olur, boşluklar tekleşir', () => {
	assert.strictEqual( E.alphabet( 'a  \u0007 b 😀 c', vocab ), 'a b c' );
	assert.strictEqual( E.alphabet( 'Café', vocab ), 'cafe' );
} );
t( 'chunk: kısa metin tek parça, sonuna nokta eklenir, duraklama yok', () => {
	assert.deepStrictEqual( E.chunk( 'merhaba dünya', 1 ), [ [ 'merhaba dünya.', 0 ] ] );
} );
t( 'chunk: uzun metin cümle sınırından bölünür', () => {
	const text = 'a b, c; d: e. '.repeat( 40 ).trim();
	const parts = E.chunk( text, 1 );
	assert.ok( parts.length > 1 );
	assert.strictEqual( parts[ 0 ][ 1 ], 0.25 );
	assert.strictEqual( parts[ parts.length - 1 ][ 1 ], 0 );
	parts.forEach( ( p ) => assert.ok( p[ 0 ].length <= 251 ) );
} );
t( 'chunk: harf içermeyen parçalar atılır', () => {
	assert.deepStrictEqual( E.chunk( '... ,,, !!!', 1 ), [] );
} );
t( 'words: harf başına kelime numarası ve kelime başı', () => {
	const w = E.words( 'bir iki' );
	assert.deepStrictEqual( w.cw, [ 0, 0, 0, 0, 1, 1, 1 ] );
	assert.deepStrictEqual( w.wstart, [ 0, 0, 0, 0, 4, 4, 4 ] );
} );
t( 'plan: kare sayısı ve konumlar', () => {
	const txt = 'merhaba dünya bugün hava';
	const w = E.words( txt );
	const dur = Float32Array.from( { length: txt.length }, ( _, i ) => 0.4 + ( ( i * 7 ) % 5 ) * 0.35 );
	const p = E.plan( w.cw, w.wstart, dur, 1 );
	assert.strictEqual( p.T, 26 );
	assert.strictEqual( p.fw.length, 26 );
	assert.strictEqual( p.cg.length, txt.length );
	assert.strictEqual( p.fw[ 0 ], 0 );
	assert.strictEqual( p.fw[ 25 ], 3 );
	// Daha hızlı konuşma daha az kare üretir.
	assert.ok( E.plan( w.cw, w.wstart, dur, 1.4 ).T < p.T );
} );
t( 'gaussian: tohumlu ve tekrarlanabilir', () => {
	const g = Array.from( E.gaussian( 7, 4 ), ( x ) => +x.toFixed( 5 ) );
	assert.deepStrictEqual( g, [ 0.14197, 0.05824, -0.86425, -2.60568 ] );
	assert.deepStrictEqual( Array.from( E.gaussian( 7, 4 ) ), Array.from( E.gaussian( 7, 4 ) ) );
	assert.strictEqual( E.gaussian( 1, 5 ).length, 5 );
} );
t( 'windows: ilk pencere kısa, sonrakiler uzun, boşluk yok', () => {
	assert.deepStrictEqual( E.windows( 260, 25 ), [ [ 0, 25 ], [ 25, 125 ], [ 125, 225 ], [ 225, 260 ] ] );
	assert.deepStrictEqual( E.windows( 10, 25 ), [ [ 0, 10 ] ] );
	assert.deepStrictEqual( E.windows( 0, 25 ), [] );
} );

console.log( n + ' EMA tests passed' );

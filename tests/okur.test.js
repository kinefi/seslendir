'use strict';
// assets/ys-okur.js için testler (Okur'un web/test/pipeline.test.ts örnekleri ve sabit özellikler).
const assert = require( 'assert' );
const O = require( '../assets/ys-okur.js' );

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

t( 'chunk: cümleler bütün, kısalar birleşik', () => {
	assert.deepStrictEqual(
		O.chunk( 'bir. iki üç dört beş altı yedi sekiz dokuz on on bir on iki on üç on dört on beş. üç!' ),
		[ 'bir. iki üç dört beş altı yedi sekiz dokuz on on bir on iki on üç on dört on beş.', 'üç!' ]
	);
} );
t( 'chunk: uzun metin boşluktan, sınırın altında bölünür', () => {
	const text = Array.from( { length: 200 }, () => 'kelime' ).join( ' ' );
	const pieces = O.chunk( text, 100 );
	assert.ok( pieces.every( ( p ) => p.length <= 100 && ! p.startsWith( ' ' ) ) );
	assert.strictEqual( pieces.join( ' ' ), text );
} );
t( 'chunk: boş ve tek parça', () => {
	assert.deepStrictEqual( O.chunk( '' ), [] );
	assert.deepStrictEqual( O.chunk( 'merhaba dünya.' ), [ 'merhaba dünya.' ] );
} );
t( 'words: kelime ve başlangıç', () => {
	const w = O.words( 'ab cd. e' );
	assert.deepStrictEqual( Array.from( w.cw ), [ 0, 0, 0, 1, 1, 1, 1, 2 ] );
	assert.deepStrictEqual( Array.from( w.wstart ), [ 0, 0, 0, 3, 3, 3, 3, 7 ] );
	assert.strictEqual( w.nWords, 3 );
} );
t( 'words: boş metin en az bir kelime sayılır', () => {
	assert.strictEqual( O.words( '' ).nWords, 1 );
} );
t( 'wordFrames: çalışan toplam yuvarlama, toplam kayması yok', () => {
	const dur = Float32Array.from( [ 1.4, 1.4, 1.4, 1.4, 1.4 ] );
	const cw = Int32Array.from( [ 0, 0, 1, 1, 2 ] );
	const c = O.wordFrames( dur, cw, 3 );
	assert.deepStrictEqual( Array.from( c ), [ 3, 3, 1 ] );
	assert.strictEqual( c[ 0 ] + c[ 1 ] + c[ 2 ], Math.round( 7 ) );
} );
t( 'wordFrames: yarımlar çifte gider', () => {
	assert.deepStrictEqual( Array.from( O.wordFrames( Float32Array.from( [ 2.5 ] ), Int32Array.from( [ 0 ] ), 1 ) ), [ 2 ] );
	assert.deepStrictEqual( Array.from( O.wordFrames( Float32Array.from( [ 3.5 ] ), Int32Array.from( [ 0 ] ), 1 ) ), [ 4 ] );
} );
t( 'frameTimeline', () => {
	const { fw, fp } = O.frameTimeline( Int32Array.from( [ 2, 0, 3 ] ) );
	assert.deepStrictEqual( Array.from( fw ), [ 0, 0, 2, 2, 2 ] );
	assert.deepStrictEqual( Array.from( fp ), [ 0, 0.5, 0, 1 / 3, 2 / 3 ].map( Math.fround ) );
} );
t( 'gaussianNoise: belirleyici, doğru uzunluk, N(0,1) benzeri', () => {
	const a = O.gaussianNoise( 0, 20001 );
	const b = O.gaussianNoise( 0, 20001 );
	assert.strictEqual( a.length, 20001 );
	assert.deepStrictEqual( Array.from( a.subarray( 0, 50 ) ), Array.from( b.subarray( 0, 50 ) ) );
	let sum = 0;
	let sq = 0;
	a.forEach( ( x ) => {
		sum += x;
		sq += x * x;
	} );
	const mean = sum / a.length;
	assert.ok( Math.abs( mean ) < 0.05, 'ortalama ' + mean );
	assert.ok( Math.abs( sq / a.length - 1 ) < 0.05, 'varyans ' + sq / a.length );
	assert.notDeepStrictEqual( Array.from( O.gaussianNoise( 1, 8 ) ), Array.from( O.gaussianNoise( 0, 8 ) ) );
} );

console.log( 'OK (' + n + ' tests): okur.test.js' );

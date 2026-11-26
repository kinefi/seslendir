'use strict';
// assets/ys-piper.js için testler: node tests/piper.test.js
const assert = require( 'assert' );
const P = require( '../assets/ys-piper.js' );

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

const map = { '_': [ 0 ], '^': [ 1 ], '$': [ 2 ], ' ': [ 3 ], 'a': [ 14 ], 'ɛ': [ 20 ], 'b': [ 15, 99 ] };
const cp = ( s ) => Array.from( s ).map( ( c ) => c.codePointAt( 0 ) );

t( 'BOS, fonem+PAD, EOS', () => {
	assert.deepStrictEqual( P.idsFor( map, cp( 'a ɛ' ) ), [ 1, 14, 0, 3, 0, 20, 0, 2 ] );
} );
t( 'çok kimlikli fonem', () => {
	assert.deepStrictEqual( P.idsFor( map, cp( 'b' ) ), [ 1, 15, 99, 0, 2 ] );
} );
t( 'bilinmeyen fonem atlanır', () => {
	assert.deepStrictEqual( P.idsFor( map, cp( 'aXa' ) ), [ 1, 14, 0, 14, 0, 2 ] );
} );
t( 'boş cümle yalnız BOS+EOS', () => {
	assert.deepStrictEqual( P.idsFor( map, [] ), [ 1, 2 ] );
} );
t( 'prototip anahtarları harita sayılmaz', () => {
	assert.deepStrictEqual( P.idsFor( map, cp( 'constructor'.slice( 0, 0 ) ) ), [ 1, 2 ] );
	assert.deepStrictEqual( P.idsFor( {}, cp( 'a' ) ), [] );
} );
t( 'scales varsayılan ve hız', () => {
	assert.deepStrictEqual( P.scales( {}, 1 ), [ 0.667, 1, 0.8 ] );
	const s = P.scales( { inference: { noise_scale: 0.5, length_scale: 1.2, noise_w: 0.9 } }, 2 );
	assert.deepStrictEqual( s, [ 0.5, 0.6, 0.9 ] );
	assert.strictEqual( P.scales( {}, 0 )[ 1 ], 1 );
} );
t( 'joinSentences aralık koyar', () => {
	const o = P.joinSentences( [ new Float32Array( [ 1, 1 ] ), new Float32Array( [ 2 ] ) ], 3 );
	assert.deepStrictEqual( Array.from( o ), [ 1, 1, 0, 0, 0, 2 ] );
	assert.strictEqual( P.joinSentences( [], 3 ).length, 0 );
} );

console.log( 'OK (' + n + ' tests): piper.test.js' );

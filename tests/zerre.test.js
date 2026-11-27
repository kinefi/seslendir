'use strict';
// assets/ys-zerre.js için testler (Zerre-TTS web/public/phonemize-tr.js davranışı).
const assert = require( 'assert' );
const Z = require( '../assets/ys-zerre.js' );

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

const symbols = [ '<pad>', '<bos>', '<eos>', '<sp>', '<unk>', ',', '.', '?', '!', 'a', 'b', 'd', 'j', 'n', 'ø', 'y', 'm', 'e', 'ɾ' ];
const lexicon = { words: { dünya: [ 'd ø n j a', '00001' ], a: [ 'a', '1' ] } };
const name = ( ids ) => ids.map( ( i ) => symbols[ i ] );

t( 'phonemize: sözlükteki kelime, vurgu, boşluk ve noktalama', () => {
	const lex = { words: { dünya: [ 'd y n j a', '00001' ], bu: [ 'b', '1' ] } };
	const syms = symbols.concat( [ 'u' ] );
	const p = Z.phonemize( 'Bu dünya.', lex, syms );
	assert.deepStrictEqual( p.ids.map( ( i ) => syms[ i ] ), [ '<bos>', 'b', '<sp>', 'd', 'y', 'n', 'j', 'a', '.', '<eos>' ] );
	assert.deepStrictEqual( p.stress, [ 0, 1, 0, 0, 0, 0, 0, 1, 0, 0 ] );
} );
t( 'phonemize: sözlükte olmayan kelime kurallarla çevrilir, son ünlü vurgulu', () => {
	const fb = Z.fallbackPhones( 'dünya' );
	assert.deepStrictEqual( fb.phones, [ 'd', 'y', 'n', 'j', 'a' ] );
	assert.deepStrictEqual( fb.stress, [ 0, 0, 0, 0, 1 ] );
	assert.deepStrictEqual( Z.fallbackPhones( 'kedi' ).phones, [ 'c', 'e', 'd', 'i' ] );
	assert.deepStrictEqual( Z.fallbackPhones( 'dağ' ).phones, [ 'd', 'aː' ] );
} );
t( 'phonemize: bilinmeyen simgeler atlanır, her zaman bos/eos', () => {
	const p = Z.phonemize( 'a 123 ?', lexicon, symbols );
	assert.deepStrictEqual( name( p.ids ), [ '<bos>', 'a', '?', '<eos>' ] );
} );
t( 'phonemize: Prototip anahtarları sözlük sayılmaz', () => {
	const p = Z.phonemize( 'constructor', lexicon, symbols );
	assert.ok( p.ids.length >= 2 );
} );
t( 'sentences: cümlelere bölünür', () => {
	assert.deepStrictEqual( Z.sentences( 'Bir. İki!  Üç? Dört…' ), [ 'Bir.', 'İki!', 'Üç?', 'Dört…' ] );
} );
t( 'sentences: çok uzun cümle kelime gruplarına bölünür', () => {
	const long = ( 'kelime '.repeat( 100 ) ).trim() + '.';
	const parts = Z.sentences( long, 40 );
	assert.ok( parts.length >= 3 );
	parts.forEach( ( p ) => assert.ok( p.split( ' ' ).length <= 40 ) );
} );
t( 'sentences: harfsiz parçalar atılır', () => {
	assert.deepStrictEqual( Z.sentences( '... 123 !!!' ), [] );
} );
t( 'limitPeak: yalnızca 0.99 üstünü ölçekler', () => {
	const a = Z.limitPeak( Float32Array.from( [ 0.5, -0.5 ] ) );
	assert.deepStrictEqual( Array.from( a ), [ 0.5, -0.5 ] );
	const b = Z.limitPeak( Float32Array.from( [ 2, -1 ] ) );
	assert.ok( Math.abs( b[ 0 ] - 0.99 ) < 1e-6 && Math.abs( b[ 1 ] + 0.495 ) < 1e-6 );
} );

console.log( n + ' Zerre tests passed' );

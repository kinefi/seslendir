'use strict';
// assets/ys-audio.js için testler: node tests/audio.test.js
const assert = require( 'assert' );
const A = require( '../assets/ys-audio.js' );

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

t( 'floatToInt16 sınırlar', () => {
	const o = A.floatToInt16( new Float32Array( [ 0, 1, -1, 2, -2, 0.5 ] ) );
	assert.deepStrictEqual( Array.from( o ), [ 0, 32767, -32768, 32767, -32768, 16384 ] );
} );

t( 'silence uzunluğu', () => {
	assert.strictEqual( A.silence( 22050, 1000 ).length, 22050 );
	assert.strictEqual( A.silence( 22050, 0 ).length, 0 );
	assert.strictEqual( A.silence( 22050, -5 ).length, 0 );
} );

t( 'concat', () => {
	const o = A.concat( [ new Int16Array( [ 1, 2 ] ), new Int16Array( [] ), new Int16Array( [ 3 ] ) ] );
	assert.deepStrictEqual( Array.from( o ), [ 1, 2, 3 ] );
} );

t( 'WAV başlığı', async () => {
	const pcm = new Int16Array( 22050 ).fill( 1000 );
	const blob = A.buildWav( pcm, 22050 );
	assert.strictEqual( blob.type, 'audio/wav' );
	assert.strictEqual( blob.size, 44 + 22050 * 2 );
	const buf = Buffer.from( await blob.arrayBuffer() );
	assert.strictEqual( buf.toString( 'ascii', 0, 4 ), 'RIFF' );
	assert.strictEqual( buf.toString( 'ascii', 8, 12 ), 'WAVE' );
	assert.strictEqual( buf.toString( 'ascii', 12, 16 ), 'fmt ' );
	assert.strictEqual( buf.readUInt16LE( 20 ), 1 ); // PCM
	assert.strictEqual( buf.readUInt16LE( 22 ), 1 ); // mono
	assert.strictEqual( buf.readUInt32LE( 24 ), 22050 );
	assert.strictEqual( buf.readUInt32LE( 28 ), 44100 );
	assert.strictEqual( buf.readUInt16LE( 34 ), 16 );
	assert.strictEqual( buf.toString( 'ascii', 36, 40 ), 'data' );
	assert.strictEqual( buf.readUInt32LE( 40 ), 44100 );
	assert.strictEqual( buf.readUInt32LE( 4 ), 36 + 44100 );
	assert.strictEqual( buf.readInt16LE( 44 ), 1000 );
} );

t( 'encode: lame yoksa WAV', () => {
	const r = A.encode( new Int16Array( 100 ), 22050, null );
	assert.strictEqual( r.ext, 'wav' );
} );

t( 'encode: lame varsa MP3', () => {
	const fake = { Mp3Encoder: function () {
		this.encodeBuffer = () => new Int8Array( [ 1, 2, 3 ] );
		this.flush = () => new Int8Array( [ 4 ] );
	} };
	const r = A.encode( new Int16Array( 2304 ), 22050, fake );
	assert.strictEqual( r.ext, 'mp3' );
	assert.strictEqual( r.blob.type, 'audio/mpeg' );
	assert.strictEqual( r.blob.size, 3 * 2 + 1 );
} );

t( 'encode: lame hata verirse WAV', () => {
	const bad = { Mp3Encoder: function () { throw new Error( 'x' ); } };
	assert.strictEqual( A.encode( new Int16Array( 10 ), 22050, bad ).ext, 'wav' );
} );

t( 'takeHead', () => {
	const ch = [ { t: 'a'.repeat( 200 ), p: 1 }, { t: 'b'.repeat( 200 ), p: 1 }, { t: 'c'.repeat( 200 ), p: 1 } ];
	assert.strictEqual( A.takeHead( ch, 450 ).length, 2 );
	assert.strictEqual( A.takeHead( ch, 10 ).length, 1 ); // en az bir parça
	assert.strictEqual( A.takeHead( [], 450 ).length, 0 );
} );

t( 'formatDuration', () => {
	assert.strictEqual( A.formatDuration( 0 ), '0:00' );
	assert.strictEqual( A.formatDuration( 61 ), '1:01' );
	assert.strictEqual( A.formatDuration( 3599.6 ), '60:00' );
	assert.strictEqual( A.formatDuration( null ), '0:00' );
} );

t( 'formatBytes', () => {
	assert.strictEqual( A.formatBytes( 512 ), '512 B' );
	assert.strictEqual( A.formatBytes( 2048 ), '2 KB' );
	assert.strictEqual( A.formatBytes( 5 * 1048576 ), '5.0 MB' );
} );

setImmediate( () => {
	if ( process.exitCode ) {
		console.error( 'Bazı JS testleri başarısız.' );
	} else {
		console.log( 'OK (' + n + ' tests): audio.test.js' );
	}
} );

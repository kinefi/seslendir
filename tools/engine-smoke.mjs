// CI duman testi: motor paketini başsız Chromium'da, eklentinin kullandığı yükleyiciyle açar,
// Türkçe bir cümle seslendirir ve gerçekten ses üretildiğini doğrular.
//   node tools/engine-smoke.mjs <paket-klasörü>
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = path.dirname( fileURLToPath( import.meta.url ) );
const engineDir = path.resolve( process.argv[ 2 ] || 'dist' );
const types = { '.js': 'text/javascript', '.wasm': 'application/wasm', '.html': 'text/html; charset=utf-8', '.data': 'application/octet-stream' };

function resolveFile( url ) {
	const p = decodeURIComponent( url.split( '?' )[ 0 ] );
	if ( p === '/smoke.html' ) return path.join( here, 'smoke.html' );
	if ( p === '/assets/ys-engine.js' ) return path.join( here, '..', 'assets', 'ys-engine.js' );
	if ( p.startsWith( '/engine/' ) ) {
		const f = path.join( engineDir, path.basename( p ) );
		return f;
	}
	return null;
}

const server = http.createServer( ( req, res ) => {
	const f = resolveFile( req.url );
	if ( ! f || ! fs.existsSync( f ) ) {
		res.writeHead( 404 );
		res.end( 'not found' );
		return;
	}
	res.writeHead( 200, { 'Content-Type': types[ path.extname( f ) ] || 'application/octet-stream' } );
	fs.createReadStream( f ).pipe( res );
} );

await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
const port = server.address().port;

const browser = await chromium.launch();
const page = await browser.newPage();
page.on( 'console', ( m ) => console.log( '[browser]', m.text() ) );
page.on( 'pageerror', ( e ) => console.log( '[pageerror]', e.message ) );
await page.goto( `http://127.0.0.1:${ port }/smoke.html` );

let result = null;
try {
	await page.waitForFunction( () => window.__smoke !== null, null, { timeout: 300000 } );
	result = await page.evaluate( () => window.__smoke );
} finally {
	await browser.close();
	server.close();
}

console.log( 'Sonuç:', JSON.stringify( result ) );
if ( ! result || ! result.ok ) {
	console.error( 'DUMAN TESTİ BAŞARISIZ: ' + ( result && result.error ) );
	process.exit( 1 );
}
if ( ! ( result.sampleRate >= 8000 ) || ! ( result.samples > result.sampleRate * 1.5 ) || ! ( result.peak > 0.02 ) ) {
	console.error( 'DUMAN TESTİ BAŞARISIZ: ses çok kısa ya da sessiz.' );
	process.exit( 1 );
}
console.log( 'Duman testi geçti: ' + ( result.samples / result.sampleRate ).toFixed( 1 ) + ' sn ses üretildi.' );

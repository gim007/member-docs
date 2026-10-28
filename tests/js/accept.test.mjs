/**
 * Tests for assets/js/accept.js using Node's test runner and jsdom.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM, VirtualConsole } from 'jsdom';

const script = readFileSync( new URL( '../../assets/js/accept.js', import.meta.url ), 'utf8' );

const markup = `
	<div class="member-docs member-docs--pending">
		<div class="member-docs__body"><p>Terms</p></div>
		<form class="member-docs__accept" data-endpoint="https://example.test/wp-json/member-docs/v1/templates/terms/accept" data-version-id="7">
			<button type="submit">I accept</button>
		</form>
		<p class="member-docs__status" role="status" aria-live="polite"></p>
	</div>`;

function setup( respond ) {
	// jsdom does not implement navigation; a reload() call surfaces as a "navigation" error.
	const navigations = [];
	const virtualConsole = new VirtualConsole();
	virtualConsole.on( 'jsdomError', ( error ) => navigations.push( error.message ) );

	const dom = new JSDOM( markup, { runScripts: 'outside-only', url: 'https://example.test/terms/', virtualConsole } );
	const { window } = dom;
	const calls = [];

	window.memberDocsAccept = {
		nonce: 'abc123',
		i18n: {
			saving: 'Saving…',
			accepted: 'You accepted this document on %s.',
			download: 'Download PDF',
			updated: 'Updated, reloading…',
			error: 'Generic error',
		},
	};
	window.fetch = async ( url, options ) => {
		calls.push( { url, options } );
		const [ status, body ] = respond();
		return { status, ok: status >= 200 && status < 300, json: async () => body };
	};
	window.eval( script );

	const form = window.document.querySelector( 'form' );
	form.dispatchEvent( new window.Event( 'submit', { bubbles: true, cancelable: true } ) );

	return {
		window,
		calls,
		wasReloaded: () => navigations.some( ( message ) => message.includes( 'navigation' ) ),
		status: () => window.document.querySelector( '.member-docs__status' ),
		settle: () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) ),
	};
}

test( 'posts the version id with the REST nonce', async () => {
	const env = setup( () => [ 201, { accepted_at_display: 'May 1, 2026', download_url: null } ] );
	await env.settle();

	assert.equal( env.calls.length, 1 );
	assert.equal( env.calls[ 0 ].url, 'https://example.test/wp-json/member-docs/v1/templates/terms/accept' );
	assert.equal( env.calls[ 0 ].options.method, 'POST' );
	assert.equal( env.calls[ 0 ].options.headers[ 'X-WP-Nonce' ], 'abc123' );
	assert.deepEqual( JSON.parse( env.calls[ 0 ].options.body ), { version_id: 7 } );
} );

test( 'replaces the button with the confirmation and download link', async () => {
	const env = setup( () => [ 201, { accepted_at_display: 'May 1, 2026', download_url: 'https://example.test/pdf?_wpnonce=x' } ] );
	await env.settle();

	const doc = env.window.document;
	assert.equal( doc.querySelector( 'form' ), null );
	assert.equal( doc.querySelector( '.member-docs__confirmation' ).textContent, 'You accepted this document on May 1, 2026.' );
	assert.equal( doc.querySelector( 'a' ).href, 'https://example.test/pdf?_wpnonce=x' );
	assert.equal( doc.activeElement, doc.querySelector( '.member-docs__confirmation' ) );
	assert.ok( doc.querySelector( '.member-docs--accepted' ) );
} );

test( 'reloads the page when the version is outdated (409)', async () => {
	const env = setup( () => [ 409, { code: 'member_docs_stale_version' } ] );
	await env.settle();

	assert.ok( env.wasReloaded() );
	assert.equal( env.status().textContent, 'Updated, reloading…' );
} );

test( 'announces errors in the live region and re-enables the button', async () => {
	const env = setup( () => [ 500, { message: 'Server said no.' } ] );
	await env.settle();

	assert.equal( env.status().textContent, 'Server said no.' );
	assert.ok( env.status().classList.contains( 'member-docs__status--error' ) );
	assert.equal( env.window.document.querySelector( 'button' ).disabled, false );
	assert.equal( env.wasReloaded(), false );
} );

test( 'does not inject response data as HTML', async () => {
	const env = setup( () => [ 201, { accepted_at_display: '<img src=x onerror=alert(1)>', download_url: null } ] );
	await env.settle();

	assert.equal( env.window.document.querySelector( 'img' ), null );
} );

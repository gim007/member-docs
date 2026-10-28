/**
 * Member Documents: submits "I accept" through the REST API.
 *
 * Expects the localized `memberDocsAccept` object ({ nonce, i18n }) and one
 * `.member-docs__accept` form per document, carrying the endpoint and the ID of
 * the version the member is looking at.
 */
( function () {
	'use strict';

	const config = window.memberDocsAccept || { nonce: '', i18n: {} };

	function setStatus( container, message, isError ) {
		const status = container.querySelector( '.member-docs__status' );
		status.textContent = message;
		status.classList.toggle( 'member-docs__status--error', Boolean( isError ) );
	}

	function showAccepted( container, acceptance ) {
		const message = document.createElement( 'p' );
		message.className = 'member-docs__confirmation';
		message.tabIndex = -1;
		message.textContent = config.i18n.accepted.replace( '%s', acceptance.accepted_at_display );

		container.querySelector( '.member-docs__accept' ).replaceWith( message );

		if ( acceptance.download_url ) {
			const link = document.createElement( 'a' );
			link.href = acceptance.download_url;
			link.textContent = config.i18n.download;

			const paragraph = document.createElement( 'p' );
			paragraph.appendChild( link );
			message.after( paragraph );
		}

		container.classList.replace( 'member-docs--pending', 'member-docs--accepted' );
		setStatus( container, '' );
		message.focus();
	}

	async function accept( form ) {
		const container = form.closest( '.member-docs' );
		const button = form.querySelector( 'button' );

		button.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		setStatus( container, config.i18n.saving );

		try {
			const response = await window.fetch( form.dataset.endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( { version_id: Number( form.dataset.versionId ) } ),
			} );
			const data = await response.json().catch( () => ( {} ) );

			if ( 409 === response.status ) {
				setStatus( container, config.i18n.updated );
				window.location.reload();
				return;
			}

			if ( ! response.ok ) {
				throw new Error( data.message || config.i18n.error );
			}

			showAccepted( container, data );
		} catch ( error ) {
			setStatus( container, error.message || config.i18n.error, true );
			button.disabled = false;
		} finally {
			form.removeAttribute( 'aria-busy' );
		}
	}

	document.addEventListener( 'submit', function ( event ) {
		const form = event.target.closest( '.member-docs__accept' );
		if ( form ) {
			event.preventDefault();
			accept( form );
		}
	} );
} )();

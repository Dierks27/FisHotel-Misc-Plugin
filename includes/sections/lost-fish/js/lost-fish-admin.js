/**
 * Lost Fish admin — one behaviour: a button that carries
 * data-fh-lf-confirm asks before its form is sent, and a form that was
 * sent cannot be sent a second time by a second click.
 *
 * Nothing here decides anything: every form posts to admin-post.php with
 * its nonce, and the server refuses a second send on its own.
 *
 * @package FisHotel\Misc\Sections\Lost_Fish
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target && event.target.closest ? event.target.closest( '[data-fh-lf-confirm]' ) : null;
		if ( ! button ) {
			return;
		}
		var question = button.getAttribute( 'data-fh-lf-confirm' );
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.closest || ! form.closest( '.fh-lf-wrap' ) ) {
			return;
		}
		if ( form.getAttribute( 'data-fh-lf-sent' ) ) {
			event.preventDefault();
			return;
		}
		form.setAttribute( 'data-fh-lf-sent', '1' );
	} );

	// A page that comes back from the browser's history can be used again.
	window.addEventListener( 'pageshow', function () {
		var sent = document.querySelectorAll( '[data-fh-lf-sent]' );
		for ( var i = 0; i < sent.length; i++ ) {
			sent[ i ].removeAttribute( 'data-fh-lf-sent' );
		}
	} );
}() );

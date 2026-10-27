/* Settings > AI CLI Connector: copy the connection details once, and confirm before a key is revoked. */
( function () {
	'use strict';

	var text = window.bulksemeKeys || {};
	var field = document.getElementById( 'bulkseme-new-key' );
	var button = document.getElementById( 'bulkseme-copy-key' );

	function done() {
		field.value = text.cleared || 'Copied.';
		button.disabled = true;
		button.textContent = text.copied || 'Copied';
	}

	// Clear the key only when it really reached the clipboard. If the browser
	// refused, leave it selected so it can be copied by hand.
	function legacyCopy() {
		var copied = false;
		try {
			field.select();
			copied = document.execCommand( 'copy' );
		} catch ( e ) {}
		if ( copied ) {
			done();
		} else {
			field.focus();
			field.select();
			button.textContent = text.manual || 'Press Ctrl+C to copy';
		}
	}

	if ( field && button ) {
		field.addEventListener( 'click', function () {
			field.select();
		} );
		button.addEventListener( 'click', function () {
			var value = field.value;
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( value ).then( done, legacyCopy );
			} else {
				legacyCopy();
			}
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( '.bulkseme-revoke-form' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.confirm( text.confirm || 'Revoke this key?' ) ) {
				event.preventDefault();
			}
		} );
	} );
}() );

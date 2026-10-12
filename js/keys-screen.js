/* Tools > Agent API Keys: copy a new key once, and confirm before a key is revoked. */
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

	function legacyCopy() {
		try {
			field.select();
			document.execCommand( 'copy' );
		} catch ( e ) {}
		done();
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

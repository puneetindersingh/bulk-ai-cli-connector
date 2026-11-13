/* Settings > AI CLI Connector: pick a setup, copy it once, check the site can be reached, and confirm before a key is revoked. */
( function () {
	'use strict';

	var text = window.bulksemeKeys || {};
	var fields = document.querySelectorAll( '.bulkseme-secret' );
	var buttons = document.querySelectorAll( '.bulkseme-copy' );

	// The key is shown once: after one confirmed copy every setup that holds it is cleared.
	function done( button ) {
		Array.prototype.forEach.call( fields, function ( f ) {
			f.value = text.cleared || 'Copied.';
			f.defaultValue = f.value; // the key also sits in the textarea's original text

		} );
		Array.prototype.forEach.call( buttons, function ( b ) {
			b.disabled = true;
		} );
		button.textContent = text.copied || 'Copied';
	}

	// Clear the key only when it really reached the clipboard. If the browser
	// refused, leave it selected so it can be copied by hand.
	function legacyCopy( field, button ) {
		var copied = false;
		try {
			field.select();
			copied = document.execCommand( 'copy' );
		} catch ( e ) {}
		if ( copied ) {
			done( button );
		} else {
			field.focus();
			field.select();
			button.textContent = text.manual || 'Press Ctrl+C to copy';
		}
	}

	Array.prototype.forEach.call( buttons, function ( button ) {
		var panel = button.closest( '.bulkseme-setup' );
		var field = panel ? panel.querySelector( '.bulkseme-secret' ) : null;
		if ( ! field ) {
			return;
		}
		field.addEventListener( 'click', function () {
			field.select();
		} );
		button.addEventListener( 'click', function () {
			var value = field.value;
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( value ).then( function () {
					done( button );
				}, function () {
					legacyCopy( field, button );
				} );
			} else {
				legacyCopy( field, button );
			}
		} );
	} );

	// Tabs: one setup shown at a time.
	var tabs = document.querySelectorAll( '[data-bulkseme-tab]' );
	Array.prototype.forEach.call( tabs, function ( tab ) {
		tab.addEventListener( 'click', function () {
			var want = tab.getAttribute( 'data-bulkseme-tab' );
			Array.prototype.forEach.call( tabs, function ( t ) {
				var on = t === tab;
				t.classList.toggle( 'button-primary', on );
				t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			} );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-bulkseme-panel]' ), function ( p ) {
				p.hidden = p.getAttribute( 'data-bulkseme-panel' ) !== want;
			} );
		} );
	} );

	// Reachability: ask the MCP address without signing in, as an AI tool first
	// does. A 401 from this plugin means the address works; anything else means
	// something stands in the way.
	var test = document.getElementById( 'bulkseme-test' );
	var out = document.getElementById( 'bulkseme-test-result' );
	if ( test && out && text.mcpUrl && window.fetch ) {
		test.addEventListener( 'click', function () {
			test.disabled = true;
			out.textContent = text.testing || 'Checking...';
			window.fetch( text.mcpUrl, {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json' },
				body: '{"jsonrpc":"2.0","id":1,"method":"ping"}'
			} ).then( function ( r ) {
				return r.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					var ok = r.status === 401 && body && body.code === 'rest_not_logged_in';
					if ( ok ) {
						out.textContent = text.testOk || 'Reachable.';
						out.style.color = '#00a32a';
					} else if ( r.status === 404 ) {
						out.textContent = text.test404 || 'Not found.';
						out.style.color = '#d63638';
					} else {
						out.textContent = ( text.testBlocked || 'Blocked: HTTP %s.' ).replace( '%s', String( r.status ) );
						out.style.color = '#d63638';
					}
				} );
			} ).catch( function () {
				out.textContent = text.testFailed || 'No answer.';
				out.style.color = '#d63638';
			} ).then( function () {
				test.disabled = false;
			} );
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

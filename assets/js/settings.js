/* global SR_ADMIN */
( function () {
	'use strict';

	var btn = document.getElementById( 'sr-copy-shortcode' );
	var field = document.getElementById( 'sr-shortcode-field' );
	var confirmEl = document.getElementById( 'sr-copy-confirm' );

	if ( btn && field ) {
		btn.addEventListener( 'click', function () {
			field.select();

			var done = function () {
				if ( ! confirmEl ) {
					return;
				}
				confirmEl.hidden = false;
				setTimeout( function () {
					confirmEl.hidden = true;
				}, 1500 );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( field.value ).then( done );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} );
	}

	document.querySelectorAll( '.sr-delete-session' ).forEach( function ( delBtn ) {
		delBtn.addEventListener( 'click', function () {
			if ( typeof SR_ADMIN === 'undefined' ) {
				return;
			}
			if ( ! window.confirm( SR_ADMIN.confirmDelete ) ) {
				return;
			}

			var sessionId = delBtn.getAttribute( 'data-session-id' );
			var row = delBtn.closest( 'tr' );
			delBtn.disabled = true;

			var body = new URLSearchParams( {
				action: 'sr_admin_delete_session',
				nonce: SR_ADMIN.nonce,
				session_id: sessionId,
			} );

			fetch( SR_ADMIN.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: body.toString(),
			} )
				.then( function ( res ) {
					return res.json();
				} )
				.then( function ( json ) {
					if ( ! json.success ) {
						throw new Error( ( json.data && json.data.message ) || SR_ADMIN.genericError );
					}
					if ( row ) {
						row.remove();
					}
				} )
				.catch( function ( err ) {
					window.alert( err.message );
					delBtn.disabled = false;
				} );
		} );
	} );
} )();

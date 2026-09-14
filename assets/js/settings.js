( function () {
	'use strict';

	var btn = document.getElementById( 'sr-copy-shortcode' );
	var field = document.getElementById( 'sr-shortcode-field' );
	var confirmEl = document.getElementById( 'sr-copy-confirm' );
	if ( ! btn || ! field ) {
		return;
	}

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
} )();

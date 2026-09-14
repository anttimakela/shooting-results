/* global SR */
( function () {
	'use strict';

	var root = document.getElementById( 'sr-app' );
	if ( ! root || typeof SR === 'undefined' ) {
		return;
	}

	var state = null; // { session, shooters, rounds }
	var currentRoundId = null;
	var keypadTarget = null; // { entryId, shotIndex, shooterName }

	function t( key ) {
		return ( SR.i18n && SR.i18n[ key ] ) || key;
	}

	function ajaxUrl() {
		// Force the current page's protocol, in case the site's configured
		// scheme (what admin_url() used server-side) doesn't match how this
		// page actually loaded — e.g. behind a local-dev SSL proxy — which
		// would otherwise make this request look cross-origin/mixed-content
		// even though it's really the same site.
		return SR.ajaxUrl.replace( /^https?:/, window.location.protocol );
	}

	function request( action, data ) {
		var body = new URLSearchParams( Object.assign( { action: action, nonce: SR.nonce, post_id: SR.postId }, data || {} ) );
		return fetch( ajaxUrl(), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
				// Some server-level firewall rules only recognize a request
				// as legitimate AJAX (vs. a direct/blocked PHP hit) when
				// this header is present — jQuery.ajax() sends it
				// automatically, but fetch() does not unless asked to.
				'X-Requested-With': 'XMLHttpRequest',
			},
			body: body.toString(),
		} )
			.then( function ( res ) {
				// Read as text first (not res.json() directly): a rejected
				// request can come back as plain "-1"/"0" (WordPress's own
				// check_ajax_referer()/wp_die() failure format) rather than
				// JSON, and surfacing that raw body is far more diagnostic
				// than a generic message when something's blocking the
				// request before it reaches our code (caching, security
				// rules, an expired nonce on a cached page, etc).
				return res.text().then( function ( text ) {
					var json = null;
					try {
						json = JSON.parse( text );
					} catch ( e ) {
						json = null;
					}
					if ( ! res.ok || ! json || ! json.success ) {
						var detail = ( json && json.data && json.data.message ) || ( text && text.trim() ) || t( 'genericError' );
						throw new Error( detail + ' (HTTP ' + res.status + ')' );
					}
					return json.data;
				} );
			} );
	}

	function setUrlSession( sessionId ) {
		var url = new URL( window.location.href );
		if ( sessionId ) {
			url.searchParams.set( 'session', sessionId );
		} else {
			url.searchParams.delete( 'session' );
		}
		window.history.replaceState( {}, '', url.toString() );
	}

	function showAutosave( saved ) {
		var el = document.getElementById( 'sr-autosave' );
		if ( ! el ) {
			return;
		}
		el.classList.toggle( 'is-saved', !! saved );
		el.textContent = saved ? t( 'saved' ) : t( 'saving' );
	}

	function showError( message ) {
		root.innerHTML = '<div class="sr-card"><p class="sr-error">' + escapeHtml( message ) + '</p></div>';
	}

	/* ---------- List view ---------- */

	function loadList() {
		request( 'sr_list_sessions', {} )
			.then( function ( data ) {
				renderList( data.sessions );
			} )
			.catch( function ( err ) {
				showError( err.message );
			} );
	}

	function renderList( sessions ) {
		setUrlSession( null );
		var draftSession = sessions.find( function ( s ) {
			return 'draft' === s.status;
		} );

		var html = '';
		if ( draftSession ) {
			html += '<div class="sr-row">';
			html += '<button class="sr-btn sr-btn-primary sr-btn-lg" id="sr-continue-session" data-session-id="' + draftSession.id + '">' +
				t( 'continueSession' ) + ' &rarr;</button>';
			html += '</div>';
		}
		html += '<div class="sr-row">';
		html += '<button class="sr-btn sr-btn-accent sr-btn-lg" id="sr-new-session">' + t( 'recordResults' ) + '</button>';
		html += '</div>';
		html += '<div class="sr-card">';
		html += '<h2>' + t( 'sessions' ) + '</h2>';
		if ( ! sessions.length ) {
			html += '<p>' + t( 'noSessions' ) + '</p>';
		} else {
			sessions.forEach( function ( s ) {
				var badge = 'sent' === s.status
					? '<span class="sr-badge sr-badge-sent">' + t( 'sent' ) + '</span>'
					: '<span class="sr-badge sr-badge-draft">' + t( 'draft' ) + '</span>';
				html += '<div class="sr-session-list-item" data-session-id="' + s.id + '">' +
					'<span>' + escapeHtml( s.created_at ) + ' &middot; ' + s.shots_per_round + ' ' + t( 'shotsPerRound' ) + '</span>' +
					badge +
					'</div>';
			} );
		}
		html += '</div>';
		root.innerHTML = html;

		document.getElementById( 'sr-new-session' ).addEventListener( 'click', showNewSessionPrompt );
		if ( draftSession ) {
			document.getElementById( 'sr-continue-session' ).addEventListener( 'click', function () {
				openSession( draftSession.id );
			} );
		}
		root.querySelectorAll( '.sr-session-list-item' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				openSession( parseInt( el.getAttribute( 'data-session-id' ), 10 ) );
			} );
		} );
	}

	function showNewSessionPrompt() {
		var presets = [ 5, 10, 15, 20, 25 ];
		var html = '<div class="sr-modal-backdrop" id="sr-new-modal">';
		html += '<div class="sr-modal">';
		html += '<p class="sr-modal-title">' + t( 'newSession' ) + '</p>';
		html += '<p class="sr-modal-subtitle">' + t( 'chooseShots' ) + '</p>';
		html += '<div class="sr-row" style="justify-content:center;flex-wrap:wrap;margin-bottom:14px;">';
		presets.forEach( function ( n ) {
			html += '<button type="button" class="sr-btn sr-preset-btn" data-shots="' + n + '">' + n + '</button>';
		} );
		html += '</div>';
		html += '<div class="sr-row" style="justify-content:center;">';
		html += '<input type="number" min="1" max="200" id="sr-custom-shots" class="sr-input" placeholder="' + t( 'custom' ) + '" style="width:100px;text-align:center;">';
		html += '</div>';
		html += '<div class="sr-row" style="justify-content:center;margin-top:18px;">';
		html += '<button class="sr-btn" id="sr-cancel-new">' + t( 'cancel' ) + '</button>';
		html += '<button class="sr-btn sr-btn-primary sr-btn-lg" id="sr-start-session" disabled>' + t( 'start' ) + '</button>';
		html += '</div>';
		html += '</div></div>';

		var wrap = document.createElement( 'div' );
		wrap.innerHTML = html;
		document.body.appendChild( wrap );

		var chosen = 0;
		var startBtn = document.getElementById( 'sr-start-session' );

		wrap.querySelectorAll( '.sr-preset-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				chosen = parseInt( btn.getAttribute( 'data-shots' ), 10 );
				wrap.querySelectorAll( '.sr-preset-btn' ).forEach( function ( b ) {
					b.classList.remove( 'is-active' );
				} );
				btn.classList.add( 'is-active' );
				document.getElementById( 'sr-custom-shots' ).value = '';
				startBtn.disabled = false;
			} );
		} );
		document.getElementById( 'sr-custom-shots' ).addEventListener( 'input', function ( e ) {
			var v = parseInt( e.target.value, 10 );
			chosen = v > 0 ? v : 0;
			wrap.querySelectorAll( '.sr-preset-btn' ).forEach( function ( b ) {
				b.classList.remove( 'is-active' );
			} );
			startBtn.disabled = ! ( chosen > 0 );
		} );
		document.getElementById( 'sr-cancel-new' ).addEventListener( 'click', function () {
			wrap.remove();
		} );
		startBtn.addEventListener( 'click', function () {
			request( 'sr_create_session', { shots_per_round: chosen } ).then( function ( data ) {
				wrap.remove();
				applyState( data );
			} );
		} );
	}

	/* ---------- Session/table view ---------- */

	function openSession( sessionId ) {
		request( 'sr_get_session', { session_id: sessionId } )
			.then( applyState )
			.catch( function () {
				// Most likely a stale ?session= link (or one shared before
				// the session was created) — fall back to the list instead
				// of leaving the page blank.
				loadList();
			} );
	}

	function applyState( data ) {
		state = data;
		currentRoundId = state.rounds.length ? state.rounds[ state.rounds.length - 1 ].round_id : null;
		setUrlSession( state.session.id );
		renderSession();
	}

	function shooterName( id ) {
		var s = state.shooters.find( function ( x ) {
			return x.id === id;
		} );
		return s ? s.name : '?';
	}

	function renderSession() {
		var session = state.session;
		var round = state.rounds.find( function ( r ) {
			return r.round_id === currentRoundId;
		} ) || state.rounds[ 0 ];

		var html = '';
		html += '<div class="sr-row" style="justify-content:space-between;">';
		html += '<button class="sr-btn" id="sr-back-to-list">&larr; ' + t( 'backToList' ) + '</button>';
		html += '<span id="sr-autosave" class="sr-autosave-indicator is-saved">' + t( 'saved' ) + '</span>';
		html += '</div>';

		html += '<div class="sr-card">';
		html += '<h2>' + session.shots_per_round + ' ' + t( 'shotsPerRound' ) + '</h2>';

		html += '<div class="sr-round-tabs">';
		state.rounds.forEach( function ( r ) {
			html += '<button class="sr-round-tab' + ( r.round_id === round.round_id ? ' is-active' : '' ) + '" data-round-id="' + r.round_id + '">' +
				t( 'round' ) + ' ' + r.round_number + '</button>';
		} );
		html += '</div>';

		var isLatestRound = round.round_id === state.rounds[ state.rounds.length - 1 ].round_id;

		html += '<div class="sr-table-scroll"><table class="sr-table"><thead><tr>';
		html += '<th style="text-align:left;">' + t( 'shooter' ) + '</th>';
		for ( var i = 1; i <= session.shots_per_round; i++ ) {
			html += '<th>' + i + '</th>';
		}
		html += '<th>' + t( 'total' ) + '</th></tr></thead><tbody>';

		var sortedEntries = round.entries.slice().sort( function ( a, b ) {
			var sa = state.shooters.find( function ( s ) { return s.id === a.shooter_id; } );
			var sb = state.shooters.find( function ( s ) { return s.id === b.shooter_id; } );
			return ( sa ? sa.sort_order : 0 ) - ( sb ? sb.sort_order : 0 );
		} );

		sortedEntries.forEach( function ( entry ) {
			var total = entry.shots.reduce( function ( sum, v ) { return sum + ( v === null ? 0 : v ); }, 0 );
			html += '<tr>';
			html += '<td class="sr-name-cell">' + escapeHtml( shooterName( entry.shooter_id ) );
			if ( isLatestRound ) {
				html += '<button class="sr-remove-shooter" data-shooter-id="' + entry.shooter_id + '" title="' + t( 'removeShooter' ) + '">&#128465;</button>';
			}
			html += '</td>';
			entry.shots.forEach( function ( shot, idx ) {
				html += '<td><button class="sr-shot-cell" data-entry-id="' + entry.entry_id + '" data-shot-index="' + idx + '" data-shooter="' + escapeHtml( shooterName( entry.shooter_id ) ) + '">' +
					( shot === null ? '–' : shot ) + '</button></td>';
			} );
			html += '<td class="sr-total-cell">' + total + '</td>';
			html += '</tr>';
		} );
		html += '</tbody></table></div>';

		if ( isLatestRound ) {
			html += '<div class="sr-row" style="margin-top:16px;">';
			html += '<input type="text" id="sr-new-shooter-name" class="sr-input" placeholder="' + t( 'shooterName' ) + '" style="flex:1;min-width:200px;">';
			html += '<button class="sr-btn sr-btn-primary" id="sr-add-shooter">' + t( 'addShooter' ) + '</button>';
			html += '</div>';
		}

		html += '<div class="sr-row" style="margin-top:16px;">';
		html += '<button class="sr-btn" id="sr-new-round">' + t( 'startNewRound' ) + '</button>';
		html += '<button class="sr-btn sr-btn-accent" id="sr-toggle-email">' + t( 'sendReport' ) + '</button>';
		html += '</div>';

		html += '<div id="sr-email-row" class="sr-row sr-hidden" style="margin-top:10px;">';
		html += '<input type="email" id="sr-report-email" class="sr-input" placeholder="' + t( 'emailPlaceholder' ) + '" style="flex:1;min-width:220px;" value="' + escapeHtml( session.report_email || '' ) + '">';
		html += '<button class="sr-btn sr-btn-primary" id="sr-send-email">' + t( 'send' ) + '</button>';
		html += '</div>';

		if ( session.report_sent_at ) {
			html += '<p class="sr-autosave-indicator" style="margin-top:10px;">' + t( 'lastSent' ) + ' ' + escapeHtml( session.report_sent_at ) + ' &rarr; ' + escapeHtml( session.report_email ) + '</p>';
		}

		html += '</div>';

		root.innerHTML = html;
		bindSessionEvents( isLatestRound );
	}

	function bindSessionEvents( isLatestRound ) {
		document.getElementById( 'sr-back-to-list' ).addEventListener( 'click', loadList );

		root.querySelectorAll( '.sr-round-tab' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				currentRoundId = parseInt( btn.getAttribute( 'data-round-id' ), 10 );
				renderSession();
			} );
		} );

		root.querySelectorAll( '.sr-shot-cell' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				openKeypad( {
					entryId: parseInt( btn.getAttribute( 'data-entry-id' ), 10 ),
					shotIndex: parseInt( btn.getAttribute( 'data-shot-index' ), 10 ),
					shooterName: btn.getAttribute( 'data-shooter' ),
					currentValue: '–' === btn.textContent ? null : parseInt( btn.textContent, 10 ),
				} );
			} );
		} );

		if ( isLatestRound ) {
			root.querySelectorAll( '.sr-remove-shooter' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( ! window.confirm( t( 'confirmRemoveShooter' ) ) ) {
						return;
					}
					request( 'sr_remove_shooter', {
						shooter_id: btn.getAttribute( 'data-shooter-id' ),
						round_id: currentRoundId,
						session_id: state.session.id,
					} ).then( applyState );
				} );
			} );

			document.getElementById( 'sr-add-shooter' ).addEventListener( 'click', addShooter );
			document.getElementById( 'sr-new-shooter-name' ).addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key ) {
					addShooter();
				}
			} );
		}

		document.getElementById( 'sr-new-round' ).addEventListener( 'click', function () {
			request( 'sr_start_new_round', { session_id: state.session.id } ).then( function ( data ) {
				state = data;
				currentRoundId = state.rounds[ state.rounds.length - 1 ].round_id;
				renderSession();
			} );
		} );

		document.getElementById( 'sr-toggle-email' ).addEventListener( 'click', function () {
			document.getElementById( 'sr-email-row' ).classList.toggle( 'sr-hidden' );
		} );

		document.getElementById( 'sr-send-email' ).addEventListener( 'click', function () {
			var email = document.getElementById( 'sr-report-email' ).value.trim();
			if ( ! email ) {
				return;
			}
			var btn = document.getElementById( 'sr-send-email' );
			btn.disabled = true;
			request( 'sr_send_report', { session_id: state.session.id, email: email } )
				.then( function ( data ) {
					applyState( data );
					window.alert( t( 'reportSent' ) );
				} )
				.catch( function ( err ) {
					window.alert( err.message );
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	}

	function addShooter() {
		var input = document.getElementById( 'sr-new-shooter-name' );
		var name = input.value.trim();
		if ( ! name ) {
			return;
		}
		request( 'sr_add_shooter', {
			session_id: state.session.id,
			round_id: currentRoundId,
			name: name,
		} ).then( function ( data ) {
			applyState( data );
		} );
	}

	/* ---------- Keypad modal ---------- */

	function openKeypad( target ) {
		keypadTarget = target;
		var html = '<div class="sr-modal-backdrop" id="sr-keypad-modal">';
		html += '<div class="sr-modal">';
		html += '<p class="sr-modal-title">' + escapeHtml( target.shooterName ) + '</p>';
		html += '<p class="sr-modal-subtitle">' + t( 'shot' ) + ' ' + ( target.shotIndex + 1 ) + '</p>';
		html += '<div class="sr-keypad-grid">';
		for ( var v = 0; v <= 10; v++ ) {
			html += '<button type="button" class="sr-key' + ( v === target.currentValue ? ' is-active' : '' ) + '" data-value="' + v + '">' + v + '</button>';
		}
		html += '<button type="button" class="sr-key sr-key-clear" data-value="">' + t( 'clear' ) + '</button>';
		html += '</div></div></div>';

		var wrap = document.createElement( 'div' );
		wrap.innerHTML = html;
		document.body.appendChild( wrap );

		wrap.querySelector( '.sr-modal-backdrop' ).addEventListener( 'click', function ( e ) {
			if ( e.target === e.currentTarget ) {
				wrap.remove();
			}
		} );

		wrap.querySelectorAll( '.sr-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var raw = btn.getAttribute( 'data-value' );
				saveShot( target.entryId, target.shotIndex, raw );
				wrap.remove();
			} );
		} );
	}

	function saveShot( entryId, shotIndex, value ) {
		showAutosave( false );
		request( 'sr_set_shot', { entry_id: entryId, shot_index: shotIndex, value: value } )
			.then( function ( data ) {
				// Update local state so a round-tab switch doesn't need a refetch.
				state.rounds.forEach( function ( r ) {
					r.entries.forEach( function ( entry ) {
						if ( entry.entry_id === data.entry_id ) {
							entry.shots = data.shots;
						}
					} );
				} );
				showAutosave( true );
				renderSession();
			} )
			.catch( function ( err ) {
				showAutosave( true );
				window.alert( err.message );
			} );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = null === str || undefined === str ? '' : String( str );
		return div.innerHTML;
	}

	/* ---------- Boot ---------- */

	if ( SR.initialSessionId ) {
		openSession( SR.initialSessionId );
	} else {
		loadList();
	}
} )();

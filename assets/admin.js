/* Pload admin: view routing, AJAX actions, live progress and small helpers. */
( function () {
	'use strict';

	var cfg = window.ploadAdmin || {};
	var app = document.querySelector( '.pload-app' );
	if ( ! app ) {
		return;
	}
	var VIEWS = [ 'overview', 'settings', 'tools' ];

	/* ---- Views ------------------------------------------------------- */

	function currentView() {
		var v = ( location.hash || '' ).slice( 1 ).split( '/' )[ 0 ];
		return VIEWS.indexOf( v ) > -1 ? v : 'overview';
	}

	function show( view, scroll ) {
		app.querySelectorAll( '[data-pload-view]' ).forEach( function ( el ) {
			el.hidden = el.getAttribute( 'data-pload-view' ) !== view;
		} );
		app.querySelectorAll( '[data-pload-nav]' ).forEach( function ( a ) {
			if ( a.getAttribute( 'data-pload-nav' ) === view ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
		app.removeAttribute( 'data-pload-boot' );
		app.setAttribute( 'data-pload-ready', '' );
		if ( scroll ) {
			window.scrollTo( 0, 0 );
		}
	}

	show( currentView(), false );
	window.addEventListener( 'hashchange', function () {
		show( currentView(), true );
	} );

	var cameBackFailed = /[?&]pload_failed=/.test( location.search );

	// One-time URL flags should not survive a refresh.
	if ( window.history && history.replaceState && /[?&](saved|pload_failed)=/.test( location.search ) ) {
		var clean = location.href.replace( /([?&])(saved|pload_failed)=[^&#]*&?/g, '$1' ).replace( /[?&](#|$)/, '$1' );
		history.replaceState( null, '', clean );
	}

	/* Reload on a view; a plain hash change would not reload. */
	function reloadTo( base, view ) {
		var target = base + ( view ? '#' + view : '' );
		if ( target.split( '#' )[ 0 ] === location.href.split( '#' )[ 0 ] ) {
			if ( view ) {
				location.hash = view;
			}
			location.reload();
		} else {
			location.href = target;
		}
	}

	/* ---- Conditional fields and range value ---------------------------- */

	function syncShowIf() {
		app.querySelectorAll( '[data-show-if]' ).forEach( function ( el ) {
			var rule = el.getAttribute( 'data-show-if' ).split( ':' );
			var form = el.closest( 'form' );
			var checked = form ? form.querySelector( 'input[name="' + rule[ 0 ] + '"]:checked' ) : null;
			el.hidden = ! checked || checked.value !== rule[ 1 ];
		} );
	}
	app.addEventListener( 'change', syncShowIf );
	syncShowIf();

	app.querySelectorAll( '[data-pload-range]' ).forEach( function ( input ) {
		var out = app.querySelector( 'output[for="' + input.id + '"]' );
		input.addEventListener( 'input', function () {
			if ( out ) {
				out.textContent = input.value;
			}
		} );
	} );

	/* ---- Show WebP switch saves at once ------------------------------- */

	var deliver = document.getElementById( 'pload-deliver-on' );
	if ( deliver ) {
		deliver.addEventListener( 'change', function () {
			deliver.closest( 'form' ).submit();
		} );
	}

	/* ---- Confirm and copy --------------------------------------------- */

	app.querySelectorAll( '[data-pload-confirm]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			var form = btn.closest( 'form' );
			if ( form && ! form.checkValidity() ) {
				return; // Let the browser show the required checkbox.
			}
			if ( ! window.confirm( btn.getAttribute( 'data-pload-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );

	app.querySelectorAll( '[data-pload-copy]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var field = document.getElementById( btn.getAttribute( 'data-pload-copy' ) );
			if ( ! field ) {
				return;
			}
			var label = btn.querySelector( 'span' );
			var before = label ? label.textContent : '';
			var done = function () {
				if ( label ) {
					label.textContent = cfg.copied;
					setTimeout( function () {
						label.textContent = before;
					}, 1800 );
				}
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( field.value ).then( done );
			} else {
				field.select();
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( err ) {}
			}
		} );
	} );

	/* ---- AJAX actions -------------------------------------------------
	 * Server test, disk estimate and single steps run over AJAX, so a
	 * fatal error on a weak host can never break this page. Whatever
	 * happens, the page reloads and shows the saved result.
	 */

	function runAjaxForm( form ) {
		var view = form.getAttribute( 'data-pload-return' ) || '';
		app.querySelectorAll( 'button[type=submit]' ).forEach( function ( b ) {
			b.disabled = true;
		} );
		form.classList.add( 'pload-busy' );
		var note = document.createElement( 'span' );
		note.className = 'pload-busy-note';
		note.setAttribute( 'role', 'status' );
		note.textContent = cfg.running;
		form.appendChild( note );

		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: new FormData( form ) } )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( j ) {
				reloadTo( j && j.success ? cfg.url : cfg.failed, view );
			} )
			.catch( function () {
				reloadTo( cfg.failed, view );
			} );
	}

	app.querySelectorAll( '.pload-ajax' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			runAjaxForm( form );
		} );
	} );

	// First visit: the server test and the disk estimate start by themselves.
	// Only once per browser session, and never right after a failure,
	// so a broken host can not end up in a reload loop.
	var auto = app.querySelector( '.pload-autorun' );
	if ( auto && ! auto.closest( '[hidden]' ) && ! cameBackFailed ) {
		var key = 'pload_autorun_' + ( auto.querySelector( 'input[name=action]' ) || {} ).value;
		var seen = true;
		try {
			seen = !! sessionStorage.getItem( key );
			sessionStorage.setItem( key, '1' );
		} catch ( e ) {}
		if ( ! seen ) {
			setTimeout( function () {
				runAjaxForm( auto );
			}, 400 );
		}
	}

	/* ---- Live progress -------------------------------------------------- */

	var live = app.querySelector( '[data-pload-live]' );

	function post( action ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_wpnonce', cfg.nonce );
		return fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function refresh() {
		if ( ! live || document.hidden ) {
			return;
		}
		post( 'pload_overview' ).then( function ( j ) {
			if ( j && j.success && j.data && j.data.html ) {
				live.innerHTML = j.data.html;
			}
		} ).catch( function () {} );
	}

	if ( live ) {
		setInterval( refresh, 30000 );
	}

	/* ---- Keep working while the page is open -------------------------- */

	var keep = document.getElementById( 'pload-auto' );
	if ( keep ) {
		var box = document.getElementById( 'pload-auto-on' );
		var out = document.getElementById( 'pload-auto-status' );
		try {
			box.checked = localStorage.getItem( 'pload_auto' ) !== '0';
		} catch ( e ) {}
		box.addEventListener( 'change', function () {
			try {
				localStorage.setItem( 'pload_auto', box.checked ? '1' : '0' );
			} catch ( e ) {}
		} );

		var step = function () {
			if ( ! box.checked ) {
				return;
			}
			var at = new Date().toLocaleTimeString();
			post( 'pload_auto' )
				.then( function ( j ) {
					var d = ( j && j.data ) || {};
					if ( d.files ) {
						out.textContent = cfg.autoDone.replace( '%1$s', d.files ).replace( '%2$s', d.secs ).replace( '%3$s', at );
						refresh();
					} else if ( 'recent' === d.reason ) {
						out.textContent = cfg.autoRecent.replace( '%s', at );
					} else if ( 'idle' === d.reason || 'empty' === d.reason || 'finished' === d.reason ) {
						out.textContent = cfg.autoIdle;
						box.checked = false;
						refresh();
					} else {
						out.textContent = cfg.autoOther.replace( '%s', at );
					}
				} )
				.catch( function () {
					out.textContent = cfg.autoError.replace( '%s', at );
				} );
		};
		setInterval( step, 60000 );
		if ( '1' === keep.getAttribute( 'data-stalled' ) ) {
			setTimeout( step, 3000 );
		}

		// Background tabs get paused by the browser: catch up on return.
		var hiddenAt = 0;
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				hiddenAt = Date.now();
			} else if ( hiddenAt && Date.now() - hiddenAt > 60000 ) {
				out.textContent = cfg.autoBack;
				step();
			}
		} );
	}
}() );

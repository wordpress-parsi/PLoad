/* Pload buttons in the media library column and in attachment details. */
( function () {
	'use strict';
	var cfg = window.ploadMedia || {};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '.pload-mc-btn' ) : null;
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var cell = btn.closest( '.pload-mc' );
		if ( ! cell || cell.classList.contains( 'is-busy' ) ) {
			return;
		}
		var msg = cell.querySelector( '.pload-mc-msg' );
		cell.classList.add( 'is-busy' );
		cell.querySelectorAll( '.pload-mc-btn' ).forEach( function ( b ) {
			b.disabled = true;
		} );
		if ( msg ) {
			msg.textContent = cfg.working;
		}

		var body = new FormData();
		body.append( 'action', 'pload_media' );
		body.append( '_wpnonce', cfg.nonce );
		body.append( 'id', cell.getAttribute( 'data-id' ) );
		body.append( 'op', btn.getAttribute( 'data-op' ) );

		fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( 'failed' );
				}
				var wrap = document.createElement( 'div' );
				wrap.innerHTML = j.data.html;
				var fresh = wrap.firstElementChild;
				cell.replaceWith( fresh );
				var m = fresh.querySelector ? fresh.querySelector( '.pload-mc-msg' ) : null;
				if ( m && j.data.msg ) {
					m.textContent = j.data.msg;
				}
			} )
			.catch( function () {
				cell.classList.remove( 'is-busy' );
				cell.querySelectorAll( '.pload-mc-btn' ).forEach( function ( b ) {
					b.disabled = false;
				} );
				if ( msg ) {
					msg.textContent = cfg.failed;
				}
			} );
	} );
}() );

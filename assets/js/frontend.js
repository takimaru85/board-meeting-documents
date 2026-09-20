/**
 * Board Meeting Documents – frontend accordion.
 *
 * Progressive enhancement, no jQuery. The markup is rendered by PHP with the
 * correct aria-expanded / hidden state; this script only toggles it and adds
 * keyboard navigation between year headers (Up/Down/Home/End).
 *
 * Exposed as window.BMDAccordion.init() so content injected later via AJAX
 * can be initialised again safely (already-bound headers are skipped).
 *
 * @package BoardMeetingDocuments
 */
( function () {
	'use strict';

	var MINUS = '−'; // "−"
	var PLUS = '+';

	/**
	 * Applies the open/closed state to a header + panel pair.
	 *
	 * @param {HTMLElement} button .bmd-year-header
	 * @param {boolean}     open   Whether the panel should be open.
	 */
	function setState( button, open ) {
		var panelId = button.getAttribute( 'aria-controls' );
		var panel = panelId ? document.getElementById( panelId ) : null;
		var toggle = button.querySelector( '.bmd-toggle' );
		var container = button.closest( '.bmd-year, .bmd-section' );

		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

		if ( panel ) {
			if ( open ) {
				panel.removeAttribute( 'hidden' );
			} else {
				panel.setAttribute( 'hidden', '' );
			}
		}

		if ( toggle ) {
			toggle.textContent = open ? MINUS : PLUS;
		}

		if ( container ) {
			container.classList.toggle( 'bmd-open', open );
			container.classList.toggle( 'bmd-year--open', open ); // Back-compat class.
		}
	}

	/**
	 * Toggles a header.
	 *
	 * @param {HTMLElement} button .bmd-year-header
	 */
	function toggle( button ) {
		var isOpen = button.getAttribute( 'aria-expanded' ) === 'true';
		setState( button, ! isOpen );
	}

	/**
	 * Moves focus between year headers inside the same wrapper.
	 *
	 * @param {HTMLElement} button Current header.
	 * @param {string}      key    Pressed key.
	 * @return {boolean} True when the key was handled.
	 */
	function handleKey( button, key ) {
		var wrapper = button.closest( '.bmd-wrapper' );
		if ( ! wrapper ) {
			return false;
		}

		var headers = Array.prototype.slice.call( wrapper.querySelectorAll( '.bmd-year-header, .bmd-section-header' ) );
		var index = headers.indexOf( button );
		if ( index === -1 || headers.length < 2 ) {
			return false;
		}

		var next = null;

		switch ( key ) {
			case 'ArrowDown':
				next = headers[ ( index + 1 ) % headers.length ];
				break;
			case 'ArrowUp':
				next = headers[ ( index - 1 + headers.length ) % headers.length ];
				break;
			case 'Home':
				next = headers[ 0 ];
				break;
			case 'End':
				next = headers[ headers.length - 1 ];
				break;
			default:
				return false;
		}

		if ( next ) {
			next.focus();
		}
		return true;
	}

	/**
	 * Binds one header. Idempotent.
	 *
	 * @param {HTMLElement} button .bmd-year-header
	 */
	function bind( button ) {
		if ( button.getAttribute( 'data-bmd-bound' ) === '1' ) {
			return;
		}
		button.setAttribute( 'data-bmd-bound', '1' );

		// Sync the visual state with whatever PHP rendered (in case a cached
		// page and the script disagree).
		setState( button, button.getAttribute( 'aria-expanded' ) === 'true' );

		button.addEventListener( 'click', function () {
			toggle( button );
		} );

		button.addEventListener( 'keydown', function ( event ) {
			if ( handleKey( button, event.key ) ) {
				event.preventDefault();
			}
		} );
	}

	/**
	 * Initialises every accordion on the page.
	 */
	function init() {
		var headers = document.querySelectorAll( '.bmd-wrapper .bmd-year-header, .bmd-wrapper .bmd-section-header' );
		Array.prototype.forEach.call( headers, bind );

		// Mark wrappers as JS-enabled for CSS hooks.
		var wrappers = document.querySelectorAll( '.bmd-wrapper' );
		Array.prototype.forEach.call( wrappers, function ( wrapper ) {
			wrapper.classList.add( 'bmd-js' );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.BMDAccordion = { init: init };
} )();

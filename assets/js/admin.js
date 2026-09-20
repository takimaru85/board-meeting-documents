/**
 * Board Meeting Documents – admin script.
 *
 * Handles the repeatable document rows (add / remove), the Media Library
 * picker restricted to PDFs, and a client-side "date is required" check
 * before publishing. Plain JavaScript; wp.media provides its own dependencies.
 *
 * @package BoardMeetingDocuments
 */
( function () {
	'use strict';

	var cfg = window.bmdAdmin || {};
	var i18n = cfg.i18n || {};
	var rowCounter = 0;

	/**
	 * Runs fn when the DOM is ready.
	 *
	 * @param {Function} fn Callback.
	 */
	function ready( fn ) {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/**
	 * Toggles the "has-rows" state that shows/hides the empty message.
	 *
	 * @param {HTMLElement} section .bmd-documents container.
	 */
	function updateEmptyState( section ) {
		var rows = section.querySelectorAll( '.bmd-doc-rows .bmd-doc-row' );
		section.classList.toggle( 'has-rows', rows.length > 0 );
	}

	/**
	 * Appends a new empty row built from the section's template.
	 *
	 * @param {HTMLElement} section .bmd-documents container.
	 * @return {HTMLElement|null} The new row.
	 */
	function addRow( section ) {
		var template = section.querySelector( '.bmd-row-template' );
		var tbody = section.querySelector( '.bmd-doc-rows' );

		if ( ! template || ! tbody ) {
			return null;
		}

		rowCounter += 1;
		var index = 'new' + Date.now() + '_' + rowCounter;
		var html = template.innerHTML.replace( /__INDEX__/g, index );

		var temp = document.createElement( 'tbody' );
		temp.innerHTML = html.trim();

		var row = temp.querySelector( '.bmd-doc-row' );
		if ( ! row ) {
			return null;
		}

		tbody.appendChild( row );
		updateEmptyState( section );

		return row;
	}

	/**
	 * Writes a selected attachment into a row.
	 *
	 * @param {HTMLElement} row        .bmd-doc-row element.
	 * @param {Object}      attachment wp.media attachment JSON.
	 */
	function setRowAttachment( row, attachment ) {
		var idInput = row.querySelector( '.bmd-doc-id' );
		var link = row.querySelector( '.bmd-doc-filename-link' );
		var button = row.querySelector( '.bmd-select-pdf' );

		if ( idInput ) {
			idInput.value = String( attachment.id );
		}

		if ( link ) {
			link.href = attachment.url || '';
			link.textContent = attachment.filename || attachment.title || ( 'PDF #' + attachment.id );
		}

		if ( button ) {
			button.textContent = i18n.changePdf || 'Change PDF';
		}

		row.classList.add( 'has-file' );
	}

	/**
	 * Opens the Media Library restricted to PDFs and assigns the choice to the row.
	 *
	 * @param {HTMLElement} row .bmd-doc-row element.
	 */
	function openMediaFrame( row ) {
		if ( ! window.wp || ! window.wp.media ) {
			window.alert( 'The WordPress Media Library could not be loaded.' );
			return;
		}

		var frame = window.wp.media( {
			title: i18n.frameTitle || 'Select or upload a PDF',
			button: { text: i18n.frameButton || 'Use this PDF' },
			library: { type: 'application/pdf' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' ).first();
			if ( ! selection ) {
				return;
			}

			var attachment = selection.toJSON();

			// The library is filtered to PDFs, but the upload tab can still
			// hand back another type - reject anything that is not a PDF.
			if ( attachment.mime !== 'application/pdf' ) {
				window.alert( i18n.notPdf || 'Only PDF files can be used.' );
				return;
			}

			setRowAttachment( row, attachment );

			// Move focus to the label so the editor can keep typing.
			var label = row.querySelector( '.bmd-doc-label-input' );
			if ( label ) {
				label.focus();
			}
		} );

		frame.open();
	}

	/**
	 * Wires up one .bmd-documents section.
	 *
	 * @param {HTMLElement} section .bmd-documents container.
	 */
	function initSection( section ) {
		var addButton = section.querySelector( '.bmd-add-row' );

		if ( addButton ) {
			addButton.addEventListener( 'click', function () {
				var row = addRow( section );
				if ( row ) {
					openMediaFrame( row );
				}
			} );
		}

		// Event delegation so rows added later work without re-binding.
		section.addEventListener( 'click', function ( event ) {
			var target = event.target;
			if ( ! ( target instanceof Element ) ) {
				return;
			}

			var selectButton = target.closest( '.bmd-select-pdf' );
			if ( selectButton && section.contains( selectButton ) ) {
				event.preventDefault();
				var row = selectButton.closest( '.bmd-doc-row' );
				if ( row ) {
					openMediaFrame( row );
				}
				return;
			}

			var moveUp = target.closest( '.bmd-move-up' );
			var moveDown = target.closest( '.bmd-move-down' );
			if ( ( moveUp || moveDown ) && section.contains( moveUp || moveDown ) ) {
				event.preventDefault();
				var moveRow = ( moveUp || moveDown ).closest( '.bmd-doc-row' );
				if ( moveRow ) {
					if ( moveUp && moveRow.previousElementSibling ) {
						moveRow.parentNode.insertBefore( moveRow, moveRow.previousElementSibling );
					} else if ( moveDown && moveRow.nextElementSibling ) {
						moveRow.parentNode.insertBefore( moveRow.nextElementSibling, moveRow );
					}
					( moveUp || moveDown ).focus();
				}
				return;
			}

			var removeButton = target.closest( '.bmd-remove-row' );
			if ( removeButton && section.contains( removeButton ) ) {
				event.preventDefault();
				var removeRow = removeButton.closest( '.bmd-doc-row' );
				if ( removeRow ) {
					removeRow.parentNode.removeChild( removeRow );
					updateEmptyState( section );
					if ( addButton ) {
						addButton.focus();
					}
				}
			}
		} );

		updateEmptyState( section );
	}

	/**
	 * Blocks Publish/Update when the meeting date is empty. Save Draft is
	 * still allowed. The server enforces the same rule as a fallback.
	 */
	function initDateValidation() {
		var publishButton = document.getElementById( 'publish' );
		var dateInput = document.getElementById( 'bmd_meeting_date' );
		var errorEl = document.getElementById( 'bmd_meeting_date_error' );

		if ( ! publishButton || ! dateInput ) {
			return;
		}

		publishButton.addEventListener( 'click', function ( event ) {
			if ( dateInput.value.trim() !== '' ) {
				if ( errorEl ) {
					errorEl.hidden = true;
				}
				dateInput.classList.remove( 'bmd-input-error' );
				return;
			}

			event.preventDefault();
			event.stopImmediatePropagation();

			// Re-enable the button that core's post.js may have just disabled.
			publishButton.classList.remove( 'disabled' );
			publishButton.disabled = false;

			if ( errorEl ) {
				errorEl.hidden = false;
			}
			dateInput.classList.add( 'bmd-input-error' );
			dateInput.setAttribute( 'aria-invalid', 'true' );
			dateInput.focus();
			if ( typeof dateInput.scrollIntoView === 'function' ) {
				dateInput.scrollIntoView( { block: 'center', behavior: 'smooth' } );
			}
		}, true ); // Capture phase so we run before core's submit handlers.

		dateInput.addEventListener( 'input', function () {
			if ( dateInput.value.trim() !== '' ) {
				if ( errorEl ) {
					errorEl.hidden = true;
				}
				dateInput.classList.remove( 'bmd-input-error' );
				dateInput.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	ready( function () {
		var sections = document.querySelectorAll( '.bmd-documents' );
		Array.prototype.forEach.call( sections, initSection );
		initDateValidation();
	} );
} )();

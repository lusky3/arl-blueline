/**
 * The hero-photograph picker in Appearance -> Blueline.
 *
 * Clones the <template> the PHP renderer emits rather than building markup of
 * its own, so a row added here and a row rendered on page load are the same
 * thing by construction -- there is one definition of a row, in one place, and
 * the two cannot drift as the field grows fields.
 *
 * Progressive, not required: with this file absent the existing rows still
 * render, still submit, and their alignment selects still work. Only the "add"
 * button goes inert, because opening the media library genuinely needs JS.
 */

// Scoped to this IIFE: these are classic scripts, so a top-level `const ROOT` or
// `function init()` would live in wp-admin's one shared global scope and collide
// with another script's (a second `const ROOT` is a SyntaxError that kills the
// whole file).
( () => {
	const ROOT = '[data-bl-photos]';

	/**
	 * Renumber every row's input names.
	 *
	 * The names carry an index (`field[0][id]`, `field[1][align]`) and PHP reads
	 * them back as an array, so after any add or remove the indices have to be
	 * contiguous again -- a gap makes the posted array sparse, and a duplicate
	 * silently drops a row on submit.
	 *
	 * @param {Element} root The field wrapper.
	 * @return {void}
	 */
	function renumber( root ) {
		const base = root.getAttribute( 'data-bl-photos-name' );
		const items = root.querySelectorAll( '[data-bl-photos-item]' );

		items.forEach( ( item, index ) => {
			const id = item.querySelector( '[data-bl-photos-id]' );
			const align = item.querySelector( '[data-bl-photos-align]' );

			if ( id ) {
				id.name = `${ base }[${ index }][id]`;
			}

			if ( align ) {
				align.name = `${ base }[${ index }][align]`;
			}
		} );

		const empty = root.querySelector( '[data-bl-photos-empty]' );

		if ( empty ) {
			// The empty state is a real statement about behaviour -- an empty list
			// means the theme's own photographs are used -- so it has to appear
			// the moment the last row goes, not only on reload.
			empty.hidden = items.length > 0;
		}

		const add = root.querySelector( '[data-bl-photos-add]' );
		const max = parseInt(
			root.getAttribute( 'data-bl-photos-max' ) || '12',
			10
		);

		if ( add ) {
			add.disabled = items.length >= max;
		}
	}

	/**
	 * The item focus moves to once $item is removed: the next one, else the
	 * previous one, else none (the caller falls back to the Add button), so
	 * focus never drops to the top of the page.
	 *
	 * @param {Array}  items Items in order, $item included.
	 * @param {Object} item  The item being removed.
	 * @return {Object|null} Neighbour item.
	 */
	function blPhotoNeighbour( items, item ) {
		const index = items.indexOf( item );

		return items[ index + 1 ] || items[ index - 1 ] || null;
	}

	/**
	 * Append a row for one chosen attachment.
	 *
	 * @param {Element} root       The field wrapper.
	 * @param {Object}  attachment A wp.media attachment model's attributes.
	 * @return {void}
	 */
	function addRow( root, attachment ) {
		const list = root.querySelector( '[data-bl-photos-list]' );
		const template = root.querySelector( '[data-bl-photos-template]' );

		if ( ! list || ! template ) {
			return;
		}

		// Already chosen: the sanitizer drops duplicates anyway, but letting one
		// appear here would show the admin a list the save then silently changes.
		const existing = [
			...root.querySelectorAll( '[data-bl-photos-id]' ),
		].map( ( input ) => parseInt( input.value, 10 ) );

		if ( existing.includes( attachment.id ) ) {
			return;
		}

		const row = template.content.firstElementChild.cloneNode( true );
		const img = row.querySelector( '.bl-photos__thumb' );
		const id = row.querySelector( '[data-bl-photos-id]' );

		if ( id ) {
			id.value = attachment.id;
		}

		// "Remove" and "Alignment" name the photograph they act on, as the
		// server-rendered rows do.
		const title = attachment.title || attachment.filename || '';
		const removeName = row.querySelector( '[data-bl-photos-remove-name]' );
		const alignName = row.querySelector( '[data-bl-photos-align-name]' );

		if ( removeName && title ) {
			removeName.textContent = ` ${ title }`;
		}

		if ( alignName && title ) {
			alignName.textContent = `Alignment for ${ title }`;
		}

		if ( img ) {
			// wp.media hands back every generated size; the thumbnail is the one
			// this row is sized for, and full-size here would pull a multi-megabyte
			// original into an admin screen for a 60px box.
			const sizes = attachment.sizes || {};
			const thumb = sizes.thumbnail || sizes.medium || null;

			img.src = thumb ? thumb.url : attachment.url;
			img.alt = '';
		}

		list.appendChild( row );
		renumber( root );

		return row;
	}

	/**
	 * Add a row per chosen photograph when the media frame reports its
	 * selection, then move focus to the first new row's alignment, the one
	 * thing left to set.
	 *
	 * WordPress closes the frame (and the modal hands focus back to the Add
	 * button) before it fires `select`, so the move is made here, deferred
	 * past that hand-back, not in a `close` handler.
	 *
	 * @param {Object}   frame         wp.media frame.
	 * @param {Function} addAttachment Adds a row for attachment attributes;
	 *                                 returns it, or nothing for a duplicate.
	 * @param {Function} defer         Runs a function after the current task.
	 * @return {void}
	 */
	function blPhotoBindFrame( frame, addAttachment, defer ) {
		frame.on( 'select', () => {
			let first = null;

			frame
				.state()
				.get( 'selection' )
				.forEach( ( model ) => {
					first = first || addAttachment( model.toJSON() ) || null;
				} );

			const select = first
				? first.querySelector( '[data-bl-photos-align]' )
				: null;

			if ( select ) {
				defer( () => select.focus() );
			}
		} );
	}

	function initPhotoField( root ) {
		const add = root.querySelector( '[data-bl-photos-add]' );

		if ( ! add || ! window.wp || ! window.wp.media ) {
			return;
		}

		let frame = null;

		add.addEventListener( 'click', () => {
			if ( ! frame ) {
				frame = window.wp.media( {
					title: add.textContent.trim(),
					library: { type: 'image' },
					multiple: 'add',
					button: { text: add.textContent.trim() },
				} );

				blPhotoBindFrame(
					frame,
					( attachment ) => addRow( root, attachment ),
					( fn ) => window.setTimeout( fn, 0 )
				);
			}

			frame.open();
		} );

		root.addEventListener( 'click', ( event ) => {
			const remove = event.target.closest( '[data-bl-photos-remove]' );

			if ( ! remove ) {
				return;
			}

			const item = remove.closest( '[data-bl-photos-item]' );

			if ( ! item ) {
				return;
			}

			const neighbour = blPhotoNeighbour(
				Array.from( root.querySelectorAll( '[data-bl-photos-item]' ) ),
				item
			);

			item.remove();
			renumber( root );

			// Removing the focused button would drop focus to <body>.
			const target = neighbour
				? neighbour.querySelector( '[data-bl-photos-remove]' )
				: add;

			if ( target ) {
				target.focus();
			}
		} );

		renumber( root );
	}

	function init() {
		document.querySelectorAll( ROOT ).forEach( initPhotoField );
	}

	if ( 'undefined' !== typeof document ) {
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}

	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = { blPhotoNeighbour, blPhotoBindFrame };
	}
} )();

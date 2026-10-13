/**
 * Player Profile's photo pencil-icon: selecting a file uploads it straight
 * away, matching the "click the pencil, pick a photo, it's done" pattern
 * most people already know from other sites.
 *
 * Native, no-JS-required baseline underneath this: the file input itself
 * still works and the form still submits normally (a <label for> already
 * opens the file picker with no JS at all) -- this only removes the extra
 * step of finding and pressing a separate submit button. A visible
 * <noscript> button is the fallback for a visitor without JS, since the
 * only other way to submit here (the tiny pencil circle) is a label for
 * the file input, not a submit control itself.
 *
 * Because the upload starts on selection, with no further confirmation, this
 * file also does what an instant upload owes the person:
 *
 *   - says so up front (a visible hint, tied to the input with
 *     aria-describedby) and says "Uploading photo..." in a live region the
 *     moment it starts, rather than leaving a silent multi-second wait;
 *   - checks the size BEFORE sending. The server refuses anything over 2 MB,
 *     and a phone photo is usually 3-12 MB, so a photo that is too big is
 *     shrunk in the browser (canvas, about 1600px on the long edge, re-encoded
 *     as JPEG) and sent in that form. Only when that cannot be done is the
 *     "too large" message shown inline, with no round trip.
 *
 * The 2 MB figure mirrors BLUELINE_PLAYER_PHOTO_MAX_BYTES in blueline-core's
 * includes/player-photo/player-photo.php, which stays the real enforcement.
 *
 * Vanilla ES2017+, no dependencies.
 */

const MAX_BYTES = 2 * 1024 * 1024;

// Long-edge sizes and JPEG qualities tried, largest and best first. 1600px is
// plenty for the 96px avatar (and any retina use of it); the later, smaller
// steps only run for a photo that is still too heavy at that size.
const EDGES = [ 1600, 1200, 800 ];
const QUALITIES = [ 0.85, 0.7 ];

const MESSAGES = {
	hint: 'JPG, PNG, GIF or WebP, up to 2 MB. Your photo uploads as soon as you choose it; larger JPG, PNG and WebP photos are resized for you.',
	resizing: 'Resizing photo…',
	uploading: 'Uploading photo…',
	unreadable:
		'That file could not be read as a photo. Choose a JPG, PNG, GIF or WebP image.',
	cannotShrink:
		'That photo could not be made smaller than 2 MB. Choose a smaller one.',
};

/**
 * @param {number} bytes Size in bytes.
 * @return {string} e.g. "2.9 MB".
 */
function formatMegabytes( bytes ) {
	return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
}

/**
 * The size an image should be drawn at: its long edge capped, never enlarged.
 *
 * @param {number} width  Source width.
 * @param {number} height Source height.
 * @param {number} edge   Longest side allowed.
 * @return {{width: number, height: number}} Target size.
 */
function fitWithin( width, height, edge ) {
	const scale = Math.min( 1, edge / Math.max( width, height ) );

	return {
		width: Math.max( 1, Math.round( width * scale ) ),
		height: Math.max( 1, Math.round( height * scale ) ),
	};
}

/**
 * A shrunken photo's file name: same stem, .jpg (the server checks that the
 * extension agrees with the bytes).
 *
 * @param {string} name Original file name.
 * @return {string} New name.
 */
function jpegName( name ) {
	return (
		( ( name || 'photo' ).replace( /\.[^.]*$/, '' ) || 'photo' ) + '.jpg'
	);
}

/**
 * Re-encode a too-big photo until it fits.
 *
 * @param {File}   file Original file.
 * @param {Object} deps decode( file ), encode( image, w, h, quality ), makeFile( blob, name ).
 * @return {Promise<{file: File}|{error: string}>} The smaller file, or why not.
 */
async function prepareFile( file, deps ) {
	// Redrawing a GIF would flatten its animation, so it is never resized.
	if ( 'image/gif' === file.type ) {
		return { error: 'too-large' };
	}

	let image;
	try {
		image = await deps.decode( file );
	} catch {
		return { error: 'unreadable' };
	}

	for ( const edge of EDGES ) {
		const { width, height } = fitWithin( image.width, image.height, edge );

		for ( const quality of QUALITIES ) {
			const blob = await deps.encode( image, width, height, quality );

			if ( blob && blob.size <= MAX_BYTES ) {
				return { file: deps.makeFile( blob, jpegName( file.name ) ) };
			}
		}
	}

	return { error: 'cannot-shrink' };
}

/**
 * Decide what to do with a freshly chosen photo, and report it through $ui.
 *
 * @param {File}   file The chosen file.
 * @param {Object} ui   setStatus( text, isError ), setBusy( bool ), replaceFile( file ), submit().
 * @param {Object} deps See prepareFile().
 * @return {Promise<string>} 'submitted' or 'rejected'.
 */
async function handleSelection( file, ui, deps ) {
	ui.setBusy( true );

	if ( file.size > MAX_BYTES ) {
		ui.setStatus( MESSAGES.resizing );

		const result = await prepareFile( file, deps );

		if ( result.error ) {
			const text = {
				'too-large': `That photo is too large (${ formatMegabytes( file.size ) }). Use a photo under 2 MB; JPG, PNG and WebP photos are resized for you.`,
				'cannot-shrink': MESSAGES.cannotShrink,
				unreadable: MESSAGES.unreadable,
			}[ result.error ];

			ui.setStatus( text, true );
			ui.setBusy( false );

			return 'rejected';
		}

		ui.replaceFile( result.file );
	}

	ui.setStatus( MESSAGES.uploading );
	ui.submit();

	return 'submitted';
}

/**
 * Browser implementations of the deps prepareFile() takes.
 *
 * @return {Object} Real decode/encode/makeFile.
 */
function browserDeps() {
	return {
		// createImageBitmap applies the photo's EXIF orientation by default.
		decode: ( file ) => window.createImageBitmap( file ),
		encode: ( image, width, height, quality ) =>
			new Promise( ( resolve ) => {
				const canvas = document.createElement( 'canvas' );
				canvas.width = width;
				canvas.height = height;

				const context = canvas.getContext( '2d' );
				if ( ! context ) {
					resolve( null );
					return;
				}

				// JPEG has no alpha: a transparent PNG would go black.
				context.fillStyle = '#fff';
				context.fillRect( 0, 0, width, height );
				context.drawImage( image, 0, 0, width, height );
				canvas.toBlob( resolve, 'image/jpeg', quality );
			} ),
		makeFile: ( blob, name ) =>
			new window.File( [ blob ], name, { type: 'image/jpeg' } ),
	};
}

function initPlayerPhotoUpload() {
	const input = document.querySelector( '.bl-player-profile__photo-input' );

	if ( ! input ) {
		return;
	}

	const card = input.closest( '.bl-player-profile__bio' );
	const host =
		( card && card.querySelector( '.bl-player-profile__identity' ) ) ||
		input.form;

	const hint = document.createElement( 'p' );
	hint.className = 'bl-player-profile__photo-hint';
	hint.id = 'bl-player-photo-hint';
	hint.textContent = MESSAGES.hint;

	// Present from the start and filled in later, so a screen reader that
	// only announces changes to an existing live region does announce them.
	const status = document.createElement( 'p' );
	status.className = 'bl-player-profile__photo-status';
	status.setAttribute( 'role', 'status' );

	host.append( hint, status );
	input.setAttribute( 'aria-describedby', hint.id );

	const ui = {
		setStatus( text, isError ) {
			status.textContent = text;
			status.classList.toggle(
				'bl-player-profile__photo-status--error',
				Boolean( isError )
			);
		},
		setBusy( busy ) {
			( card || input.form ).setAttribute(
				'aria-busy',
				busy ? 'true' : 'false'
			);
			input.form.classList.toggle( 'is-busy', busy );
		},
		replaceFile( file ) {
			const transfer = new window.DataTransfer();
			transfer.items.add( file );
			input.files = transfer.files;
		},
		submit() {
			input.form.submit();
		},
	};

	input.addEventListener( 'change', () => {
		if ( ! input.files || ! input.files.length ) {
			return;
		}

		// Forget a refused file so choosing it again re-checks it.
		const forget = () => {
			input.value = '';
		};

		handleSelection( input.files[ 0 ], ui, browserDeps() )
			.then( ( outcome ) => {
				if ( 'rejected' === outcome ) {
					forget();
				}
			} )
			.catch( () => {
				ui.setStatus( MESSAGES.unreadable, true );
				ui.setBusy( false );
				forget();
			} );
	} );
}

if ( typeof document !== 'undefined' ) {
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initPlayerPhotoUpload );
	} else {
		initPlayerPhotoUpload();
	}
}

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = {
		MAX_BYTES,
		fitWithin,
		formatMegabytes,
		prepareFile,
		handleSelection,
	};
}

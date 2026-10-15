const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

const css = ( name ) =>
	path.resolve( __dirname, `assets/src/css/${ name }.css` );
const js = ( name ) => path.resolve( __dirname, `assets/src/js/${ name }.js` );

module.exports = {
	...defaults,
	entry: {
		// The core bundle: what every page needs (assets/src/js/index.js).
		index: path.resolve( __dirname, 'assets/src/js/index.js' ),
		editor: path.resolve( __dirname, 'assets/src/js/editor.js' ),
		// Feature bundles, each enqueued only where its markup can render
		// (blueline_theme_bundles(), inc/enqueue.php). A bundle's stylesheet
		// and script share one entry, so one *.asset.php versions both.
		announcement: [ css( 'announcement' ), js( 'announcement' ) ],
		'floating-next-game': [
			css( 'floating-next-game' ),
			js( 'floating-next-game' ),
		],
		'sponsors-page': css( 'sponsors-page' ),
		sportspress: [
			css( 'sportspress' ),
			js( 'venue-map' ),
			js( 'player-selector' ),
			js( 'team-name-tooltip' ),
		],
		sponsors: js( 'sponsors' ),
		occasions: [ css( 'occasions' ), js( 'occasion-effects-toggle' ) ],
		homepage: [ css( 'homepage' ), js( 'standings-tabs' ) ],
		woocommerce: [
			css( 'woocommerce' ),
			js( 'account' ),
			js( 'woocommerce-account-form-errors' ),
			js( 'woocommerce' ),
			js( 'rules-modal' ),
		],
		forms: [ css( 'forms' ), js( 'contact-form-fields' ) ],
		account: [
			css( 'account' ),
			js( 'account-next-game' ),
			js( 'preferences-widget-reset' ),
			js( 'player-photo-upload' ),
		],
		'quotes-llama-alt': js( 'quotes-llama-alt' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'assets/dist' ),
	},
	module: {
		...defaults.module,
		// Font files are preloaded by handle from inc/enqueue.php, which needs
		// a stable, predictable filename — drop the default content hash.
		rules: defaults.module.rules.map( ( rule ) => {
			if ( rule.test && /woff/.test( rule.test.toString() ) ) {
				return {
					...rule,
					generator: { filename: 'fonts/[name][ext]' },
				};
			}
			return rule;
		} ),
	},
};

const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaults,
	entry: {
		index: path.resolve( __dirname, 'assets/src/js/index.js' ),
		editor: path.resolve( __dirname, 'assets/src/js/editor.js' ),
		// PERF-10: per-template stylesheets, enqueued only where their selectors can match (inc/enqueue.php).
		occasions: path.resolve( __dirname, 'assets/src/css/occasions.css' ),
		homepage: path.resolve( __dirname, 'assets/src/css/homepage.css' ),
		woocommerce: path.resolve( __dirname, 'assets/src/css/woocommerce.css' ),
		forms: path.resolve( __dirname, 'assets/src/css/forms.css' ),
		account: path.resolve( __dirname, 'assets/src/css/account.css' ),
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

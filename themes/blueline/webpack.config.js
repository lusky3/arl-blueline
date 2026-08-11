const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaults,
	entry: {
		index: path.resolve( __dirname, 'assets/src/js/index.js' ),
		editor: path.resolve( __dirname, 'assets/src/js/editor.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'assets/dist' ),
	},
};

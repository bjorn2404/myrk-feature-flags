const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin/flags': './assets/js/admin/flags/index.js',
		'admin/groups': './assets/js/admin/groups/index.js',
	},
};

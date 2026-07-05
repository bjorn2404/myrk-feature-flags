const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin/flags': './assets/js/admin/flags/index.js',
		'admin/groups': './assets/js/admin/groups/index.js',
		'admin/audit': './assets/js/admin/audit/index.js',
		'admin/incidents': './assets/js/admin/incidents/index.js',
		'admin/scheduled': './assets/js/admin/scheduled/index.js',
		'admin/cleanup': './assets/js/admin/cleanup/index.js',
	},
};

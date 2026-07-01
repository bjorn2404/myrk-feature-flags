/**
 * Myrk client-side flag helper.
 *
 * window.myrkFlags is populated by PHP (via FrontendBridge) before this script
 * runs, so all values are synchronous — no request needed.
 *
 * Usage:
 *   if ( myrkIsEnabled( 'my_flag' ) ) { ... }
 */
( function ( flags ) {
	window.myrkFlags = flags;

	/**
	 * Return true when the named flag is enabled for the current user.
	 *
	 * @param {string}  key      Flag key registered with Myrk::register().
	 * @param {boolean} fallback Returned when the key is not in myrkFlags.
	 * @returns {boolean}
	 */
	window.myrkIsEnabled = function ( key, fallback ) {
		if ( Object.prototype.hasOwnProperty.call( window.myrkFlags, key ) ) {
			return window.myrkFlags[ key ];
		}
		return typeof fallback !== 'undefined' ? fallback : false;
	};
} )( window.myrkFlags || {} );

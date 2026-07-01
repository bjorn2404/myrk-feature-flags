/**
 * Entry point for the Myrk flags admin screen bundle.
 * Mounts the correct screen into #myrk-flags-root based on editMode.
 */
import { createRoot } from '@wordpress/element';
import { FlagsScreen } from './FlagsScreen';
import { EditScreen } from './EditScreen';

const rootEl = document.getElementById( 'myrk-flags-root' );
if ( rootEl ) {
	const editMode = window.myrkAdminFlags?.editMode ?? false;
	const App      = editMode ? EditScreen : FlagsScreen;
	createRoot( rootEl ).render( <App /> );
}

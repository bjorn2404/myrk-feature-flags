/**
 * Entry point for the Myrk groups admin screen bundle.
 */
import { createRoot } from '@wordpress/element';
import { GroupsScreen } from './GroupsScreen';

const rootEl = document.getElementById( 'myrk-groups-root' );
if ( rootEl ) {
	createRoot( rootEl ).render( <GroupsScreen /> );
}

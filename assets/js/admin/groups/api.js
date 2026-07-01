/**
 * API helpers for the groups screen.
 */
import apiFetch from '@wordpress/api-fetch';

const { restUrl, nonce } = window.myrkAdminGroups ?? {};

apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

const REST_BASE = ( restUrl ?? '' ).replace( /\/$/, '' );

/** @returns {Promise<Array>} */
export async function fetchGroups() {
	return apiFetch( { url: REST_BASE + '/groups' } );
}

/**
 * @param {{ name: string, description?: string, external_ref?: string, external_ref_url?: string }} data
 * @returns {Promise<object>}
 */
export async function createGroup( data ) {
	return apiFetch( { url: REST_BASE + '/groups', method: 'POST', data } );
}

/**
 * @param {number} id
 * @param {{ name?: string, description?: string, external_ref?: string, external_ref_url?: string }} changes
 * @returns {Promise<object>}
 */
export async function updateGroup( id, changes ) {
	return apiFetch( { url: `${ REST_BASE }/groups/${ id }`, method: 'PATCH', data: changes } );
}

/**
 * @param {number} id
 * @returns {Promise<void>}
 */
export async function deleteGroup( id ) {
	return apiFetch( { url: `${ REST_BASE }/groups/${ id }`, method: 'DELETE' } );
}

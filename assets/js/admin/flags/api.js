/**
 * API helpers — thin wrappers around @wordpress/api-fetch.
 * All functions return Promises. Errors reject with the WP_Error shape.
 *
 * We use `url` (not `path`) in every apiFetch call so that WordPress's own
 * root-URL middleware cannot overwrite our already-correct absolute URL.
 * Using `path` would leave the `path` key in the options object, and the WP
 * middleware (which runs after ours in the LIFO chain) would re-construct the
 * URL from its own /wp-json/ root, producing e.g. /wp-json/flags instead of
 * /wp-json/myrk/v1/flags.
 */
import apiFetch from '@wordpress/api-fetch';

const { restUrl, nonce } = window.myrkAdminFlags ?? {};

// Authenticate every request with the WP REST nonce.
apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );

// REST_BASE = e.g. "http://myrk.test/wp-json/myrk/v1" (no trailing slash)
const REST_BASE = ( restUrl ?? '' ).replace( /\/$/, '' );

// -------------------------------------------------------------------------
// Flags
// -------------------------------------------------------------------------

/**
 * @param {{ env?: string, status?: string, per_page?: number, page?: number }} params
 * @return {Promise<Array>} Array of flag objects.
 */
export async function fetchFlags( params = {} ) {
	const query = new URLSearchParams();
	Object.entries( params ).forEach(
		( [ k, v ] ) => v !== undefined && query.set( k, v )
	);
	const qs = query.toString();
	return apiFetch( { url: REST_BASE + '/flags' + ( qs ? '?' + qs : '' ) } );
}

/**
 * @param {string} flagKey
 * @return {Promise<object>} The flag data.
 */
export async function fetchFlag( flagKey ) {
	return apiFetch( { url: `${ REST_BASE }/flags/${ flagKey }` } );
}

/**
 * @param {{ flag_key: string, label: string, description?: string, default_state?: boolean, rewind_strategy?: string }} data
 * @return {Promise<object>} The created flag.
 */
export async function createFlag( data ) {
	return apiFetch( {
		url: REST_BASE + '/flags',
		method: 'POST',
		data,
	} );
}

/**
 * @param {string}                                                                                      flagKey
 * @param {{ label?: string, description?: string, rewind_strategy?: string, default_state?: boolean }} changes
 * @return {Promise<object>} The updated flag.
 */
export async function updateFlag( flagKey, changes ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }`,
		method: 'PATCH',
		data: changes,
	} );
}

/**
 * @param {string} flagKey
 * @return {Promise<void>}
 */
export async function deleteFlag( flagKey ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }?confirm=true`,
		method: 'DELETE',
	} );
}

// -------------------------------------------------------------------------
// Targets
// -------------------------------------------------------------------------

/**
 * @param {string} flagKey
 * @param {string} env
 * @return {Promise<Array>}
 */
export async function fetchTargets( flagKey, env ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }/targets?env=${ encodeURIComponent( env ) }`,
	} );
}

/**
 * @param {string} flagKey
 * @param {{ env: string, type: string, operator: string, value: string, enabled?: boolean }} data
 * @return {Promise<object>}
 */
export async function createTarget( flagKey, data ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }/targets`,
		method: 'POST',
		data,
	} );
}

/**
 * @param {string} flagKey
 * @param {number} id
 * @param {{ enabled?: boolean }} changes
 * @return {Promise<object>}
 */
export async function updateTarget( flagKey, id, changes ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }/targets/${ id }`,
		method: 'PATCH',
		data: changes,
	} );
}

/**
 * @param {string} flagKey
 * @param {number} id
 * @return {Promise<void>}
 */
export async function deleteTarget( flagKey, id ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }/targets/${ id }`,
		method: 'DELETE',
	} );
}

// -------------------------------------------------------------------------
// Environment state
// -------------------------------------------------------------------------

/**
 * @param {string}                                                                                                 flagKey
 * @param {string}                                                                                                 env
 * @param {{ status?: string, percentage?: number, anonymous_strategy?: string, note?: string, git_sha?: string }} changes
 * @return {Promise<object>} The updated environment state.
 */
export async function updateEnvState( flagKey, env, changes ) {
	return apiFetch( {
		url: `${ REST_BASE }/flags/${ flagKey }/environments/${ env }`,
		method: 'PATCH',
		data: changes,
	} );
}

// -------------------------------------------------------------------------
// Convenience wrappers
// -------------------------------------------------------------------------

export async function enableFlag( flagKey, env, percentage = 100 ) {
	return updateEnvState( flagKey, env, { status: 'enabled', percentage } );
}

export async function disableFlag( flagKey, env ) {
	return updateEnvState( flagKey, env, {
		status: 'disabled',
		percentage: 0,
	} );
}

export async function setPercentage( flagKey, env, percentage ) {
	return updateEnvState( flagKey, env, { percentage } );
}

// -------------------------------------------------------------------------
// Groups
// -------------------------------------------------------------------------

/** @return {Promise<Array>} Array of group objects. */
export async function fetchGroups() {
	return apiFetch( { url: REST_BASE + '/groups' } );
}

/**
 * @param {{ name: string, description?: string, external_ref?: string, external_ref_url?: string }} data
 * @return {Promise<object>} The created group.
 */
export async function createGroup( data ) {
	return apiFetch( { url: REST_BASE + '/groups', method: 'POST', data } );
}

/**
 * @param {number}                                                                                    id
 * @param {{ name?: string, description?: string, external_ref?: string, external_ref_url?: string }} changes
 * @return {Promise<object>} The updated group.
 */
export async function updateGroup( id, changes ) {
	return apiFetch( {
		url: `${ REST_BASE }/groups/${ id }`,
		method: 'PATCH',
		data: changes,
	} );
}

/**
 * @param {number} id
 * @return {Promise<void>}
 */
export async function deleteGroup( id ) {
	return apiFetch( {
		url: `${ REST_BASE }/groups/${ id }`,
		method: 'DELETE',
	} );
}

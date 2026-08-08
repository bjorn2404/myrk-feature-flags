/**
 * DataViews flags list screen.
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { check, closeSmall, pencil, trash } from '@wordpress/icons';
import {
	fetchFlags,
	fetchGroups,
	enableFlag,
	disableFlag,
	deleteFlag,
} from './api';

const { currentEnv, editUrl } = window.myrkAdminFlags ?? {};
const env = currentEnv ?? 'production';

const defaultView = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'flag_key', direction: 'asc' },
	search: '',
	filters: [],
	hiddenFields: [ 'is_stale_raw', 'tags' ],
	fields: [
		'label',
		'group',
		'status',
		'percentage',
		'targets',
		'last_changed',
		'stale_badge',
		'registered',
	],
	titleField: 'flag_key',
};

export function FlagsScreen() {
	const [ view, setView ] = useState( defaultView );
	const [ flags, setFlags ] = useState( [] );
	const [ groups, setGroups ] = useState( [] );
	const [ groupFilter, setGroupFilter ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );

	const loadFlags = useCallback( () => {
		setLoading( true );
		fetchFlags( { env, per_page: 100 } )
			.then( ( data ) => {
				setFlags( Array.isArray( data ) ? data : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( {
					type: 'error',
					message:
						err?.message ?? __( 'Failed to load flags.', 'myrk' ),
				} );
				setLoading( false );
			} );
	}, [] );

	useEffect( () => {
		loadFlags();
	}, [ loadFlags ] );

	useEffect( () => {
		fetchGroups()
			.then( setGroups )
			.catch( () => {} );
	}, [] );

	// -------------------------------------------------------------------------
	// Group pre-filter + derived tag elements
	// -------------------------------------------------------------------------

	const visibleFlags = useMemo( () => {
		if ( ! groupFilter ) {
			return flags;
		}
		return flags.filter( ( f ) => f.group_name === groupFilter );
	}, [ flags, groupFilter ] );

	const allTags = useMemo( () => {
		const set = new Set();
		flags.forEach( ( f ) => {
			if ( f.tags ) {
				f.tags.split( ',' ).forEach( ( t ) => {
					const trimmed = t.trim();
					if ( trimmed ) {
						set.add( trimmed );
					}
				} );
			}
		} );
		return [ ...set ].sort();
	}, [ flags ] );

	// -------------------------------------------------------------------------
	// Field definitions
	// -------------------------------------------------------------------------

	const fields = useMemo(
		() => [
			{
				id: 'flag_key',
				label: __( 'Flag Key', 'myrk' ),
				enableSorting: true,
				getValue: ( { item } ) => item.flag_key,
				render: ( { item } ) => (
					<code className="myrk-flag-key">{ item.flag_key }</code>
				),
			},
			{
				id: 'label',
				label: __( 'Label', 'myrk' ),
				enableSorting: true,
				getValue: ( { item } ) => item.label,
			},
			{
				id: 'group',
				label: __( 'Group', 'myrk' ),
				getValue: ( { item } ) => item.group_name ?? '',
				render: ( { item } ) =>
					item.group_name ? (
						<span className="myrk-badge myrk-badge--group">
							{ item.group_name }
						</span>
					) : (
						<span className="myrk-muted">—</span>
					),
			},
			{
				id: 'tags',
				label: __( 'Tags', 'myrk' ),
				getValue: ( { item } ) =>
					item.tags
						? item.tags
								.split( ',' )
								.map( ( t ) => t.trim() )
								.filter( Boolean )
						: [],
				render: ( { item } ) => {
					const tags = item.tags
						? item.tags
								.split( ',' )
								.map( ( t ) => t.trim() )
								.filter( Boolean )
						: [];
					if ( ! tags.length ) {
						return <span className="myrk-muted">—</span>;
					}
					return (
						<span className="myrk-tags">
							{ tags.map( ( t ) => (
								<span key={ t } className="myrk-tag">
									{ t }
								</span>
							) ) }
						</span>
					);
				},
				filterBy: {
					operators: [ 'isAny' ],
					elements: allTags.map( ( t ) => ( {
						value: t,
						label: t,
					} ) ),
				},
			},
			{
				id: 'status',
				label: __( 'Status', 'myrk' ),
				getValue: ( { item } ) => item.environment?.status ?? 'unknown',
				render: ( { item } ) => {
					const status = item.environment?.status;
					if ( ! status ) {
						return (
							<span className="myrk-badge myrk-badge--unknown">
								{ __( 'No state', 'myrk' ) }
							</span>
						);
					}
					return (
						<span
							className={ `myrk-badge myrk-badge--${ status }` }
						>
							{ status === 'enabled'
								? __( 'Enabled', 'myrk' )
								: __( 'Disabled', 'myrk' ) }
						</span>
					);
				},
				filterBy: {
					operators: [ 'is' ],
					elements: [
						{ value: 'enabled', label: __( 'Enabled', 'myrk' ) },
						{ value: 'disabled', label: __( 'Disabled', 'myrk' ) },
					],
				},
			},
			{
				id: 'percentage',
				label: __( 'Rollout', 'myrk' ),
				getValue: ( { item } ) => item.environment?.percentage ?? 0,
				render: ( { item } ) => {
					const pct = item.environment?.percentage ?? 0;
					const barClass =
						pct === 100
							? 'myrk-rollout__bar--full'
							: 'myrk-rollout__bar';
					return (
						<div className="myrk-rollout">
							<div className="myrk-rollout__track">
								<div
									className={ barClass }
									style={ { width: `${ pct }%` } }
								/>
							</div>
							<span className="myrk-rollout__label">
								{ pct }%
							</span>
						</div>
					);
				},
				enableSorting: true,
			},
			{
				id: 'targets',
				label: __( 'Targets', 'myrk' ),
				getValue: ( { item } ) => ( item.targets ?? [] ).length,
				render: ( { item } ) => {
					const count = ( item.targets ?? [] ).length;
					return count > 0 ? (
						<span>{ count }</span>
					) : (
						<span className="myrk-muted">—</span>
					);
				},
			},
			{
				id: 'last_changed',
				label: __( 'Last Changed', 'myrk' ),
				getValue: ( { item } ) => item.environment?.updated_at ?? '',
				render: ( { item } ) => {
					const ts = item.environment?.updated_at;
					if ( ! ts ) {
						return <span className="myrk-muted">—</span>;
					}
					return (
						<span title={ ts }>{ formatRelativeDate( ts ) }</span>
					);
				},
				enableSorting: true,
			},
			{
				id: 'stale_badge',
				label: __( 'Stale', 'myrk' ),
				getValue: ( { item } ) => ( item.is_stale ? 'stale' : '' ),
				render: ( { item } ) =>
					item.is_stale ? (
						<span className="myrk-badge myrk-badge--stale">
							{ __( 'Stale', 'myrk' ) }
						</span>
					) : null,
			},
			{
				id: 'registered',
				label: __( 'In Code', 'myrk' ),
				getValue: ( { item } ) =>
					item.is_registered ? 'yes' : 'orphaned',
				render: ( { item } ) =>
					item.is_registered ? null : (
						<span className="myrk-badge myrk-badge--orphaned">
							{ __( 'Orphaned', 'myrk' ) }
						</span>
					),
			},
		],
		[ allTags ]
	);

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	const actions = useMemo(
		() => [
			{
				id: 'enable',
				label: __( 'Enable (100%)', 'myrk' ),
				isPrimary: true,
				icon: check,
				isEligible: ( item ) =>
					item.environment?.status !== 'enabled' ||
					item.environment?.percentage < 100,
				callback: async ( items ) => {
					const item = items[ 0 ];
					try {
						await enableFlag( item.flag_key, env, 100 );
						setNotice( {
							type: 'success',
							message: `${ item.flag_key } enabled at 100%`,
						} );
						loadFlags();
					} catch ( err ) {
						setNotice( {
							type: 'error',
							message:
								err?.message ??
								__( 'Failed to enable flag.', 'myrk' ),
						} );
					}
				},
			},
			{
				id: 'disable',
				label: __( 'Disable', 'myrk' ),
				isPrimary: true,
				icon: closeSmall,
				isEligible: ( item ) => item.environment?.status !== 'disabled',
				callback: async ( items ) => {
					const item = items[ 0 ];
					try {
						await disableFlag( item.flag_key, env );
						setNotice( {
							type: 'success',
							message: `${ item.flag_key } disabled`,
						} );
						loadFlags();
					} catch ( err ) {
						setNotice( {
							type: 'error',
							message:
								err?.message ??
								__( 'Failed to disable flag.', 'myrk' ),
						} );
					}
				},
			},
			{
				id: 'edit',
				label: __( 'Edit', 'myrk' ),
				icon: pencil,
				callback: ( items ) => {
					const item = items[ 0 ];
					window.location.href = `${ editUrl }&flag_key=${ item.flag_key }`;
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'myrk' ),
				icon: trash,
				isDestructive: true,
				isEligible: ( item ) => ! item.is_registered,
				callback: async ( items ) => {
					const item = items[ 0 ];
					if (
						// eslint-disable-next-line no-alert
						! window.confirm(
							sprintf(
								/* translators: %s: flag key */
								__(
									'Permanently delete "%s" and all its state? This cannot be undone.',
									'myrk'
								),
								item.flag_key
							)
						)
					) {
						return;
					}
					try {
						await deleteFlag( item.flag_key );
						setFlags( ( prev ) =>
							prev.filter( ( f ) => f.flag_key !== item.flag_key )
						);
						setNotice( {
							type: 'success',
							message: sprintf(
								/* translators: %s: flag key */
								__( '"%s" deleted.', 'myrk' ),
								item.flag_key
							),
						} );
						loadFlags();
					} catch ( err ) {
						setNotice( {
							type: 'error',
							message:
								err?.message ??
								__( 'Failed to delete flag.', 'myrk' ),
						} );
					}
				},
			},
		],
		[ loadFlags ]
	);

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( visibleFlags, view, fields ),
		[ visibleFlags, view, fields ]
	);

	// -------------------------------------------------------------------------
	// Group filter header element (passed to DataViews header prop)
	// -------------------------------------------------------------------------

	const groupHeader = useMemo( () => {
		if ( ! groups.length ) {
			return null;
		}
		const options = [
			{ label: __( 'All groups', 'myrk' ), value: '' },
			...groups.map( ( g ) => ( { label: g.name, value: g.name } ) ),
		];
		return (
			<SelectControl
				value={ groupFilter }
				options={ options }
				onChange={ ( val ) => {
					setGroupFilter( val );
					setView( ( prev ) => ( { ...prev, page: 1 } ) );
				} }
				__nextHasNoMarginBottom
				className="myrk-group-filter"
			/>
		);
		// groupFilter and groups are deps; setView is stable
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ groups, groupFilter ] );

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	return (
		<div className="myrk-screen">
			<div className="myrk-brand-mark">
				<span className="myrk-brand-mark__rune">ᛗ</span>
				<span className="myrk-brand-mark__wordmark">myrk</span>
			</div>
			<h1 className="wp-heading-inline">
				{ __( 'Feature Flags', 'myrk' ) }
			</h1>
			<hr className="wp-header-end" />
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }
			<EnvIndicator env={ env } />
			{ flags.length === 0 && ! loading ? (
				<div className="myrk-table-card">
					<EmptyState />
				</div>
			) : (
				<div className="myrk-table-card">
					<DataViews
						data={ data }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						isLoading={ loading }
						defaultLayouts={ { table: {} } }
						header={ groupHeader }
					/>
				</div>
			) }
		</div>
	);
}

// -------------------------------------------------------------------------
// Sub-components
// -------------------------------------------------------------------------

function EmptyState() {
	return (
		<div className="myrk-empty-state">
			<span className="myrk-empty-state__rune">ᛗ</span>
			<h2 className="myrk-empty-state__heading">
				{ __( 'Flags are defined in code', 'myrk' ) }
			</h2>
			<p className="myrk-empty-state__description">
				{ __(
					'Unlike SaaS feature flag tools, Myrk treats your codebase as the source of truth. Register flags in your plugin or theme — they appear here automatically.',
					'myrk'
				) }
			</p>
			<pre className="myrk-empty-state__snippet">
				{ [
					'use Myrk\\Myrk; // add once to the top of your file',
					'',
					"Myrk::register( 'my_flag', [ 'label' => 'My Flag' ] );",
					'',
					'// Evaluate in PHP',
					"if ( myrk_is_enabled( 'my_flag' ) ) { ... }",
					'',
					'// Evaluate in JS (window.myrkFlags is auto-populated)',
					"if ( myrkIsEnabled( 'my_flag' ) ) { ... }",
				].join( '\n' ) }
			</pre>
		</div>
	);
}

function EnvIndicator( { env: envName } ) {
	return (
		<div className="myrk-env-indicator">
			<span className="myrk-env-indicator__label">
				{ __( 'Environment', 'myrk' ) }
			</span>
			<span className="myrk-env-indicator__value">{ envName }</span>
		</div>
	);
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function sprintf( fmt, ...args ) {
	return fmt.replace( /%s/g, () => args.shift() );
}

function formatRelativeDate( isoString ) {
	if ( ! isoString ) {
		return '—';
	}
	const date = new Date( isoString );
	const now = new Date();
	const diff = Math.floor( ( now - date ) / 1000 );

	if ( diff < 60 ) {
		return __( 'just now', 'myrk' );
	}
	if ( diff < 3600 ) {
		return `${ Math.floor( diff / 60 ) }m ago`;
	}
	if ( diff < 86400 ) {
		return `${ Math.floor( diff / 3600 ) }h ago`;
	}
	if ( diff < 2592000 ) {
		return `${ Math.floor( diff / 86400 ) }d ago`;
	}

	return date.toLocaleDateString();
}

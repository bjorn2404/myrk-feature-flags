/**
 * DataViews groups list screen.
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	Notice,
	Spinner,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { pencil, trash } from '@wordpress/icons';
import { fetchGroups, createGroup, updateGroup, deleteGroup } from './api';

const { flagsUrl } = window.myrkAdminGroups ?? {};

const emptyForm = {
	name: '',
	description: '',
	external_ref: '',
	external_ref_url: '',
};

const defaultView = {
	type: 'table',
	perPage: 20,
	page: 1,
	sort: { field: 'name', direction: 'asc' },
	search: '',
	filters: [],
	hiddenFields: [],
	fields: [ 'description', 'flag_count', 'external_ref' ],
	titleField: 'name',
};

export function GroupsScreen() {
	const [ groups, setGroups ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ notice, setNotice ] = useState( null );
	const [ view, setView ] = useState( defaultView );
	const [ panel, setPanel ] = useState( null ); // null | { id: null|number, ...form }
	const [ saving, setSaving ] = useState( false );
	const [ panelErrors, setPanelErrors ] = useState( {} );

	const load = useCallback( () => {
		setLoading( true );
		fetchGroups()
			.then( ( data ) => {
				setGroups( Array.isArray( data ) ? data : [] );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( {
					type: 'error',
					message:
						err?.message ?? __( 'Failed to load groups.', 'myrk-feature-flags' ),
				} );
				setLoading( false );
			} );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const openCreate = () => {
		setPanel( { id: null, ...emptyForm } );
		setPanelErrors( {} );
	};

	const openEdit = ( group ) => {
		setPanel( {
			id: group.id,
			name: group.name,
			description: group.description ?? '',
			external_ref: group.external_ref ?? '',
			external_ref_url: group.external_ref_url ?? '',
		} );
		setPanelErrors( {} );
	};

	const closePanel = () => {
		setPanel( null );
		setPanelErrors( {} );
	};

	const updatePanel = ( key ) => ( value ) =>
		setPanel( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const validatePanel = () => {
		const errs = {};
		if ( ! panel.name.trim() ) {
			errs.name = __( 'Group name is required.', 'myrk-feature-flags' );
		}
		if (
			panel.external_ref_url &&
			! isValidUrl( panel.external_ref_url )
		) {
			errs.external_ref_url = __( 'Must be a valid URL.', 'myrk-feature-flags' );
		}
		setPanelErrors( errs );
		return Object.keys( errs ).length === 0;
	};

	const handleSave = async () => {
		if ( ! validatePanel() ) {
			return;
		}
		setSaving( true );

		const payload = {
			name: panel.name.trim(),
			description: panel.description,
			external_ref: panel.external_ref || undefined,
			external_ref_url: panel.external_ref_url || undefined,
		};

		try {
			if ( panel.id ) {
				await updateGroup( panel.id, payload );
			} else {
				await createGroup( payload );
			}
			closePanel();
			load();
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err?.message ?? __( 'Save failed.', 'myrk-feature-flags' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	const handleDelete = async ( group ) => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			sprintf(
				/* translators: %s: group name */
				__(
					'Delete group "%s"? Flags in this group will be ungrouped.',
					'myrk-feature-flags'
				),
				group.name
			)
		);
		if ( ! confirmed ) {
			return;
		}

		try {
			await deleteGroup( group.id );
			setNotice( {
				type: 'success',
				message: sprintf(
					/* translators: %s: group name */
					__( '"%s" deleted.', 'myrk-feature-flags' ),
					group.name
				),
			} );
			load();
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message: err?.message ?? __( 'Delete failed.', 'myrk-feature-flags' ),
			} );
		}
	};

	// -------------------------------------------------------------------------
	// Field definitions
	// -------------------------------------------------------------------------

	const fields = useMemo(
		() => [
			{
				id: 'name',
				label: __( 'Name', 'myrk-feature-flags' ),
				enableSorting: true,
				getValue: ( { item } ) => item.name,
			},
			{
				id: 'description',
				label: __( 'Description', 'myrk-feature-flags' ),
				getValue: ( { item } ) => item.description ?? '',
				render: ( { item } ) =>
					item.description ? (
						<span>{ item.description }</span>
					) : (
						<span className="myrk-muted">—</span>
					),
			},
			{
				id: 'flag_count',
				label: __( 'Flags', 'myrk-feature-flags' ),
				enableSorting: true,
				getValue: ( { item } ) => Number( item.flag_count ?? 0 ),
				render: ( { item } ) => {
					const count = Number( item.flag_count ?? 0 );
					if ( ! count ) {
						return <span className="myrk-muted">0</span>;
					}
					if ( flagsUrl ) {
						return (
							<a
								href={ `${ flagsUrl }` }
								className="myrk-group-flags-link"
							>
								{ count }
							</a>
						);
					}
					return <span>{ count }</span>;
				},
			},
			{
				id: 'external_ref',
				label: __( 'External Ref', 'myrk-feature-flags' ),
				getValue: ( { item } ) => item.external_ref ?? '',
				render: ( { item } ) => {
					if ( ! item.external_ref ) {
						return <span className="myrk-muted">—</span>;
					}
					if ( item.external_ref_url ) {
						return (
							<a
								href={ item.external_ref_url }
								target="_blank"
								rel="noopener noreferrer"
								className="myrk-external-ref"
							>
								{ item.external_ref }
							</a>
						);
					}
					return <span>{ item.external_ref }</span>;
				},
			},
		],
		[]
	);

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	const actions = useMemo(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'myrk-feature-flags' ),
				icon: pencil,
				callback: ( items ) => openEdit( items[ 0 ] ),
			},
			{
				id: 'delete',
				label: __( 'Delete', 'myrk-feature-flags' ),
				icon: trash,
				isDestructive: true,
				callback: ( items ) => handleDelete( items[ 0 ] ),
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ load ]
	);

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( groups, view, fields ),
		[ groups, view, fields ]
	);

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	const isCreating = panel !== null && panel.id === null;

	const renderTableContent = () => {
		if ( loading && ! groups.length ) {
			return (
				<div style={ { padding: '48px', textAlign: 'center' } }>
					<Spinner />
				</div>
			);
		}
		if ( groups.length === 0 ) {
			return <EmptyState onAdd={ openCreate } />;
		}
		return (
			<DataViews
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ loading }
				defaultLayouts={ { table: {} } }
			/>
		);
	};

	return (
		<div className="myrk-screen">
			<div className="myrk-brand-mark">
				<span className="myrk-brand-mark__rune">ᛗ</span>
				<span className="myrk-brand-mark__wordmark">myrk</span>
			</div>
			<h1 className="wp-heading-inline">{ __( 'Groups', 'myrk-feature-flags' ) }</h1>{ ' ' }
			{ ! panel && (
				<button
					type="button"
					className="page-title-action"
					onClick={ openCreate }
				>
					{ __( 'Add New Group', 'myrk-feature-flags' ) }
				</button>
			) }
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
			{ /* Create / Edit panel */ }
			{ panel !== null && (
				<Card className="myrk-group-panel">
					<CardHeader>
						<strong>
							{ isCreating
								? __( 'New Group', 'myrk-feature-flags' )
								: sprintf(
										/* translators: %s: group name */
										__( 'Edit: %s', 'myrk-feature-flags' ),
										panel.name
								  ) }
						</strong>
					</CardHeader>
					<CardBody>
						<div className="myrk-field-stack">
							<div className="myrk-field-group">
								<TextControl
									label={ __( 'Name', 'myrk-feature-flags' ) }
									value={ panel.name }
									onChange={ updatePanel( 'name' ) }
									placeholder={ __(
										'e.g. Sprint 42',
										'myrk-feature-flags'
									) }
									help={ __(
										'Used to group flags in the flags list and in code via the group: argument.',
										'myrk-feature-flags'
									) }
									className={
										panelErrors.name
											? 'myrk-field--error'
											: ''
									}
									__nextHasNoMarginBottom
								/>
								{ panelErrors.name && (
									<p className="myrk-field__error">
										{ panelErrors.name }
									</p>
								) }
							</div>

							<TextareaControl
								label={ __( 'Description', 'myrk-feature-flags' ) }
								value={ panel.description }
								onChange={ updatePanel( 'description' ) }
								placeholder={ __(
									'What does this group represent?',
									'myrk-feature-flags'
								) }
								rows={ 2 }
								__nextHasNoMarginBottom
							/>

							<TextControl
								label={ __( 'External reference', 'myrk-feature-flags' ) }
								value={ panel.external_ref }
								onChange={ updatePanel( 'external_ref' ) }
								placeholder={ __(
									'e.g. JIRA-123 or #sprint-42',
									'myrk-feature-flags'
								) }
								help={ __(
									'Short identifier — shown as a link in the table if a URL is provided.',
									'myrk-feature-flags'
								) }
								__nextHasNoMarginBottom
							/>

							<div className="myrk-field-group">
								<TextControl
									label={ __(
										'External reference URL',
										'myrk-feature-flags'
									) }
									value={ panel.external_ref_url }
									onChange={ updatePanel(
										'external_ref_url'
									) }
									placeholder="https://..."
									type="url"
									className={
										panelErrors.external_ref_url
											? 'myrk-field--error'
											: ''
									}
									__nextHasNoMarginBottom
								/>
								{ panelErrors.external_ref_url && (
									<p className="myrk-field__error">
										{ panelErrors.external_ref_url }
									</p>
								) }
							</div>

							<Flex gap={ 2 } justify="flex-start">
								<FlexItem>
									<Button
										variant="primary"
										onClick={ handleSave }
										isBusy={ saving }
										disabled={ saving }
									>
										{ isCreating
											? __( 'Create Group', 'myrk-feature-flags' )
											: __( 'Update Group', 'myrk-feature-flags' ) }
									</Button>
								</FlexItem>
								<FlexItem>
									<Button
										variant="tertiary"
										onClick={ closePanel }
										disabled={ saving }
									>
										{ __( 'Cancel', 'myrk-feature-flags' ) }
									</Button>
								</FlexItem>
							</Flex>
						</div>
					</CardBody>
				</Card>
			) }
			{ /* Groups table */ }
			<div
				className="myrk-table-card"
				style={ panel ? { marginTop: '16px' } : {} }
			>
				{ renderTableContent() }
			</div>
		</div>
	);
}

// -------------------------------------------------------------------------
// Sub-components
// -------------------------------------------------------------------------

function EmptyState( { onAdd } ) {
	return (
		<div className="myrk-empty-state">
			<span className="myrk-empty-state__rune">ᛗ</span>
			<h2 className="myrk-empty-state__heading">
				{ __( 'No groups yet', 'myrk-feature-flags' ) }
			</h2>
			<p className="myrk-empty-state__description">
				{ __(
					'Groups let you organize flags by sprint, release, or initiative — and link them to your project management tool.',
					'myrk-feature-flags'
				) }
			</p>
			<p className="myrk-empty-state__description">
				{ __( 'You can also create a group in code:', 'myrk-feature-flags' ) }
			</p>
			<pre className="myrk-empty-state__snippet">
				{ [
					"\\Myrk\\Myrk::register( 'my_flag', [",
					"    'group' => 'Sprint 42',",
					'] );',
				].join( '\n' ) }
			</pre>
			<button
				type="button"
				className="button button-primary button-large"
				onClick={ onAdd }
			>
				{ __( 'Add your first group', 'myrk-feature-flags' ) }
			</button>
		</div>
	);
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function sprintf( fmt, ...args ) {
	return fmt.replace( /%s/g, () => args.shift() );
}

function isValidUrl( str ) {
	try {
		new URL( str );
		return true;
	} catch {
		return false;
	}
}

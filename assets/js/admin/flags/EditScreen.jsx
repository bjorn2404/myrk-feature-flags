/**
 * DataForm create/edit flag screen.
 */
import { useState, useEffect } from '@wordpress/element';
import {
	Button,
	TextControl,
	TextareaControl,
	ToggleControl,
	SelectControl,
	RangeControl,
	Flex,
	FlexItem,
	Notice,
	Spinner,
	Card,
	CardBody,
	CardHeader,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	fetchFlag,
	createFlag,
	updateFlag,
	updateEnvState,
	fetchGroups,
	createGroup,
} from './api';

const {
	flagKey: initialFlagKey,
	listUrl,
	currentEnv,
} = window.myrkAdminFlags ?? {};
const env = currentEnv ?? 'production';

const REWIND_STRATEGIES = [
	{ label: __( 'Stepwise (safe default)', 'myrk' ), value: 'stepwise' },
	{ label: __( 'Immediate (instant rollback)', 'myrk' ), value: 'immediate' },
];

const ANON_STRATEGIES = [
	{ label: __( 'IP address', 'myrk' ), value: 'ip' },
	{ label: __( 'Session ID', 'myrk' ), value: 'session' },
	{ label: __( 'Device fingerprint', 'myrk' ), value: 'device' },
];

const LIFECYCLE_OPTIONS = [
	{ label: __( 'Temporary — stale-eligible', 'myrk' ), value: 'temporary' },
	{
		label: __( 'Permanent — excluded from stale detection', 'myrk' ),
		value: 'permanent',
	},
];

const defaultForm = {
	flag_key: '',
	label: '',
	description: '',
	default_state: false,
	rewind_strategy: 'stepwise',
	anonymous_strategy: 'ip',
	lifecycle: 'temporary',
	group_id: null,
	tags: '',
	env_enabled: false,
	env_percentage: 0,
};

export function EditScreen() {
	const flagKey = initialFlagKey ?? '';
	const isEditing = Boolean( flagKey );

	const [ form, setForm ] = useState( defaultForm );
	const [ loading, setLoading ] = useState( isEditing );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ groups, setGroups ] = useState( [] );
	const [ showNewGroup, setShowNewGroup ] = useState( false );
	const [ newGroupName, setNewGroupName ] = useState( '' );
	const [ creatingGroup, setCreatingGroup ] = useState( false );

	useEffect( () => {
		fetchGroups()
			.then( setGroups )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		if ( ! isEditing ) {
			return;
		}

		fetchFlag( flagKey )
			.then( ( data ) => {
				const envState = data.environments?.[ env ];
				setForm( {
					flag_key: data.flag_key,
					label: data.label,
					description: data.description ?? '',
					default_state: data.default ?? false,
					rewind_strategy: data.rewind_strategy ?? 'stepwise',
					anonymous_strategy: 'ip',
					lifecycle: data.lifecycle ?? 'temporary',
					group_id: data.group_id ? Number( data.group_id ) : null,
					tags: data.tags ?? '',
					env_enabled: envState?.status === 'enabled',
					env_percentage: envState?.percentage ?? 0,
				} );
				setLoading( false );
			} )
			.catch( ( err ) => {
				setNotice( {
					type: 'error',
					message:
						err?.message ?? __( 'Could not load flag.', 'myrk' ),
				} );
				setLoading( false );
			} );
	}, [ flagKey, isEditing ] );

	const update = ( key ) => ( value ) =>
		setForm( ( prev ) => ( { ...prev, [ key ]: value } ) );

	const validate = () => {
		const errs = {};
		if ( ! form.flag_key.match( /^[a-z][a-z0-9_]*$/ ) ) {
			errs.flag_key = __(
				'Must start with a lowercase letter and contain only lowercase letters, digits, and underscores.',
				'myrk'
			);
		}
		if ( ! form.label.trim() ) {
			errs.label = __( 'Label is required.', 'myrk' );
		}
		setErrors( errs );
		return Object.keys( errs ).length === 0;
	};

	const handleCreateGroup = async () => {
		if ( ! newGroupName.trim() ) {
			return;
		}
		setCreatingGroup( true );
		try {
			const created = await createGroup( { name: newGroupName.trim() } );
			setGroups( ( prev ) => [ ...prev, created ] );
			update( 'group_id' )( created.id );
			setNewGroupName( '' );
			setShowNewGroup( false );
		} catch ( err ) {
			setNotice( {
				type: 'error',
				message:
					err?.message ?? __( 'Failed to create group.', 'myrk' ),
			} );
		} finally {
			setCreatingGroup( false );
		}
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		if ( ! validate() ) {
			return;
		}

		setSaving( true );

		try {
			let resolvedFlagKey = flagKey;

			const definitionFields = {
				label: form.label,
				description: form.description,
				default_state: form.default_state,
				rewind_strategy: form.rewind_strategy,
				lifecycle: form.lifecycle,
				group_id: form.group_id, // null = explicitly remove group
				tags: form.tags || undefined,
			};

			if ( isEditing ) {
				await updateFlag( flagKey, definitionFields );
			} else {
				const created = await createFlag( {
					flag_key: form.flag_key,
					...definitionFields,
				} );
				resolvedFlagKey = created.flag_key ?? form.flag_key;
			}

			await updateEnvState( resolvedFlagKey, env, {
				status: form.env_enabled ? 'enabled' : 'disabled',
				percentage: form.env_percentage,
			} );

			window.location.href = listUrl ?? 'admin.php?page=myrk';
		} catch ( err ) {
			const message = err?.message ?? __( 'Save failed.', 'myrk' );
			setNotice( { type: 'error', message } );
			setSaving( false );
		}
	};

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	if ( loading ) {
		return (
			<div className="myrk-screen">
				<Spinner />
			</div>
		);
	}

	const title = isEditing
		? /* translators: %s: flag key */ sprintf(
				__( 'Edit Flag: %s', 'myrk' ),
				flagKey
		  )
		: __( 'Add New Flag', 'myrk' );

	const groupOptions = [
		{ label: __( '— No group —', 'myrk' ), value: '' },
		...groups.map( ( g ) => ( { label: g.name, value: String( g.id ) } ) ),
	];

	return (
		<div className="myrk-screen myrk-edit-screen">
			{ notice && (
				<Notice
					status={ notice.type }
					onRemove={ () => setNotice( null ) }
					isDismissible
				>
					{ notice.message }
				</Notice>
			) }
			<div className="myrk-brand-mark">
				<span className="myrk-brand-mark__rune">ᛗ</span>
				<span className="myrk-brand-mark__wordmark">myrk</span>
			</div>
			<h1 className="wp-heading-inline">{ title }</h1>{ ' ' }
			<a href={ listUrl } className="page-title-action">
				{ __( '← All flags', 'myrk' ) }
			</a>
			<hr className="wp-header-end" />
			<form onSubmit={ handleSubmit }>
				<div className="myrk-edit-screen__layout">
					<div className="myrk-edit-screen__main">
						{ /* Flag Definition */ }
						<Card>
							<CardHeader>
								<strong>
									{ __( 'Flag Definition', 'myrk' ) }
								</strong>
							</CardHeader>
							<CardBody>
								<div className="myrk-field-stack">
									<div className="myrk-field-group">
										<TextControl
											label={ __( 'Flag Key', 'myrk' ) }
											value={ form.flag_key }
											onChange={ update( 'flag_key' ) }
											readOnly={ isEditing }
											help={
												isEditing
													? __(
															'Flag key cannot be changed after creation.',
															'myrk'
													  )
													: __(
															'Lowercase letters, digits, and underscores. E.g. new_checkout',
															'myrk'
													  )
											}
											className={
												errors.flag_key
													? 'myrk-field--error'
													: ''
											}
											__nextHasNoMarginBottom
										/>
										{ errors.flag_key && (
											<p className="myrk-field__error">
												{ errors.flag_key }
											</p>
										) }
									</div>

									<div className="myrk-field-group">
										<TextControl
											label={ __( 'Label', 'myrk' ) }
											value={ form.label }
											onChange={ update( 'label' ) }
											help={ __(
												'A short, human-readable name shown in this admin screen.',
												'myrk'
											) }
											className={
												errors.label
													? 'myrk-field--error'
													: ''
											}
											__nextHasNoMarginBottom
										/>
										{ errors.label && (
											<p className="myrk-field__error">
												{ errors.label }
											</p>
										) }
									</div>

									<TextareaControl
										label={ __( 'Description', 'myrk' ) }
										value={ form.description }
										onChange={ update( 'description' ) }
										help={ __(
											"Helps your team remember what this flag controls and when it's safe to remove.",
											'myrk'
										) }
										rows={ 3 }
										__nextHasNoMarginBottom
									/>
								</div>
							</CardBody>
						</Card>

						{ /* Organisation */ }
						<Card>
							<CardHeader>
								<strong>
									{ __( 'Organisation', 'myrk' ) }
								</strong>
							</CardHeader>
							<CardBody>
								<div className="myrk-field-stack">
									<div>
										<SelectControl
											label={ __( 'Group', 'myrk' ) }
											value={
												form.group_id
													? String( form.group_id )
													: ''
											}
											options={ groupOptions }
											onChange={ ( val ) =>
												update( 'group_id' )(
													val ? Number( val ) : null
												)
											}
											help={ __(
												'Organise related flags by sprint, release, or initiative.',
												'myrk'
											) }
											__nextHasNoMarginBottom
										/>
										{ ! showNewGroup ? (
											<Button
												variant="link"
												onClick={ () =>
													setShowNewGroup( true )
												}
												style={ {
													marginTop: '6px',
													fontSize: '12px',
												} }
											>
												{ __( '+ New group', 'myrk' ) }
											</Button>
										) : (
											<div className="myrk-inline-create">
												<TextControl
													label={ __(
														'Group name',
														'myrk'
													) }
													value={ newGroupName }
													onChange={ setNewGroupName }
													placeholder={ __(
														'e.g. Sprint 42',
														'myrk'
													) }
													__nextHasNoMarginBottom
												/>
												<Button
													variant="secondary"
													onClick={
														handleCreateGroup
													}
													isBusy={ creatingGroup }
													disabled={
														creatingGroup ||
														! newGroupName.trim()
													}
												>
													{ __( 'Create', 'myrk' ) }
												</Button>
												<Button
													variant="tertiary"
													onClick={ () => {
														setShowNewGroup(
															false
														);
														setNewGroupName( '' );
													} }
													disabled={ creatingGroup }
												>
													{ __( 'Cancel', 'myrk' ) }
												</Button>
											</div>
										) }
									</div>

									<TextControl
										label={ __( 'Tags', 'myrk' ) }
										value={ form.tags }
										onChange={ update( 'tags' ) }
										placeholder={ __(
											'payments, checkout, v2-redesign',
											'myrk'
										) }
										help={ __(
											'Comma-separated. Used for filtering in the flags list.',
											'myrk'
										) }
										__nextHasNoMarginBottom
									/>
								</div>
							</CardBody>
						</Card>

						{ /* Behaviour */ }
						<Card>
							<CardHeader>
								<strong>{ __( 'Behaviour', 'myrk' ) }</strong>
							</CardHeader>
							<CardBody>
								<div className="myrk-field-stack">
									<SelectControl
										label={ __( 'Lifecycle', 'myrk' ) }
										value={ form.lifecycle }
										options={ LIFECYCLE_OPTIONS }
										onChange={ update( 'lifecycle' ) }
										help={ __(
											'Permanent flags are excluded from stale detection.',
											'myrk'
										) }
										__nextHasNoMarginBottom
									/>

									<ToggleControl
										label={ __( 'Default state', 'myrk' ) }
										help={ __(
											'Returned when the flag has no environment state or the circuit breaker is tripped.',
											'myrk'
										) }
										checked={ form.default_state }
										onChange={ update( 'default_state' ) }
										__nextHasNoMarginBottom
									/>

									<SelectControl
										label={ __(
											'Rewind strategy',
											'myrk'
										) }
										value={ form.rewind_strategy }
										options={ REWIND_STRATEGIES }
										onChange={ update( 'rewind_strategy' ) }
										help={ __(
											'How the circuit breaker rolls the flag back.',
											'myrk'
										) }
										__nextHasNoMarginBottom
									/>

									<SelectControl
										label={ __(
											'Anonymous identifier strategy',
											'myrk'
										) }
										value={ form.anonymous_strategy }
										options={ ANON_STRATEGIES }
										onChange={ update(
											'anonymous_strategy'
										) }
										help={ __(
											'Used for percentage rollout when no logged-in user is available.',
											'myrk'
										) }
										__nextHasNoMarginBottom
									/>
								</div>
							</CardBody>
						</Card>
					</div>

					<div className="myrk-edit-screen__sidebar">
						<Card>
							<CardHeader>
								<strong>{ __( 'Rollout', 'myrk' ) }</strong>
							</CardHeader>
							<CardBody>
								<ToggleControl
									label={ __( 'Enabled', 'myrk' ) }
									help={ sprintf(
										/* translators: %s: environment name */
										__(
											'Status in the %s environment.',
											'myrk'
										),
										env
									) }
									checked={ form.env_enabled }
									onChange={ update( 'env_enabled' ) }
									__nextHasNoMarginBottom
								/>
								<RangeControl
									label={ __( 'Rollout percentage', 'myrk' ) }
									value={ form.env_percentage }
									onChange={ update( 'env_percentage' ) }
									min={ 0 }
									max={ 100 }
									step={ 1 }
									help={ __(
										'Percentage of users who see this flag as enabled.',
										'myrk'
									) }
									__nextHasNoMarginBottom
								/>
							</CardBody>
						</Card>

						<CodeCard flagKey={ form.flag_key } />

						<Flex
							className="myrk-edit-screen__actions"
							direction="column"
							gap={ 2 }
						>
							<FlexItem>
								<Button
									variant="primary"
									type="submit"
									isBusy={ saving }
									disabled={ saving }
									style={ {
										width: '100%',
										justifyContent: 'center',
									} }
								>
									{ isEditing
										? __( 'Update Flag', 'myrk' )
										: __( 'Create Flag', 'myrk' ) }
								</Button>
							</FlexItem>
							<FlexItem>
								<Button
									variant="tertiary"
									href={ listUrl }
									disabled={ saving }
								>
									{ __( 'Cancel', 'myrk' ) }
								</Button>
							</FlexItem>
						</Flex>
					</div>
				</div>
			</form>
		</div>
	);
}

// -------------------------------------------------------------------------
// Sub-components
// -------------------------------------------------------------------------

function CodeCard( { flagKey } ) {
	const key = flagKey || 'my_flag';

	const phpSnippet = [
		`if ( myrk_is_enabled( '${ key }' ) ) {`,
		'    // your feature code',
		'}',
	].join( '\n' );

	const jsSnippet = [
		`if ( myrkIsEnabled( '${ key }' ) ) {`,
		'    // your feature code',
		'}',
	].join( '\n' );

	return (
		<Card>
			<CardHeader>
				<strong>{ __( 'Use in code', 'myrk' ) }</strong>
			</CardHeader>
			<CardBody>
				<div className="myrk-field-stack" style={ { gap: '12px' } }>
					<div>
						<p className="myrk-code-label">PHP</p>
						<pre className="myrk-code-snippet">{ phpSnippet }</pre>
					</div>
					<div>
						<p className="myrk-code-label">JS</p>
						<pre className="myrk-code-snippet">{ jsSnippet }</pre>
					</div>
				</div>
			</CardBody>
		</Card>
	);
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function sprintf( fmt, ...args ) {
	return fmt.replace( /%s/g, () => args.shift() );
}

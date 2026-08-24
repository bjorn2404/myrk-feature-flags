<?php
/**
 * WP-CLI commands for managing Myrk feature flags.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\ChangedVia;
use Myrk\Cleanup;
use Myrk\Database\FlagRepository;
use Myrk\Database\FlagWriter;
use Myrk\Database\GroupRepository;
use Myrk\Database\StateWriter;
use Myrk\Registry;
use WP_CLI;
use WP_CLI_Command;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * Manage Myrk feature flags.
 *
 * ## EXAMPLES
 *
 *     wp myrk flags list
 *     wp myrk flags list --env=staging --status=enabled
 *     wp myrk flags enable new_checkout --percentage=25
 *     wp myrk flags disable new_checkout
 *     wp myrk flags refs new_checkout
 */
class FlagsCommand extends WP_CLI_Command {

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	/**
	 * List all flags with their current environment state.
	 *
	 * ## OPTIONS
	 *
	 * [--env=<env>]
	 * : Environment to query. Defaults to wp_get_environment_type().
	 *
	 * [--status=<status>]
	 * : Filter by status. One of: enabled, disabled.
	 *
	 * [--stale]
	 * : Show only stale flags (100% or 0% with no changes for 30+ days).
	 *
	 * [--days=<days>]
	 * : Staleness threshold in days when --stale is used. Default 30.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml, csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags list
	 *     wp myrk flags list --env=staging --status=enabled --format=json
	 *     wp myrk flags list --stale --days=60
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: env, status, stale, days, format.
	 * @when after_wp_load
	 */
	public function list( array $args, array $assoc_args ): void {
		$env    = $assoc_args['env'] ?? wp_get_environment_type();
		$format = $assoc_args['format'] ?? 'table';

		$query_args = [];
		if ( isset( $assoc_args['status'] ) ) {
			$query_args['status']      = $assoc_args['status'];
			$query_args['environment'] = $env;
		}

		$flags       = FlagRepository::get_all_with_env_state( $env, $query_args );
		$all_targets = FlagRepository::get_all_targets_for_env( $env );

		$stale_only      = get_flag_value( $assoc_args, 'stale', false );
		$stale_threshold = (int) ( $assoc_args['days'] ?? Cleanup::DEFAULT_STALE_DAYS );

		$rows = [];
		foreach ( $flags as $flag ) {
			$env_state = null !== $flag->env_id ? $flag : null;

			$is_stale = false;
			if ( null !== $env_state ) {
				$is_stale = Cleanup::is_stale(
					[
						'status'             => $env_state->status,
						'rollout_percentage' => $env_state->rollout_percentage,
						'updated_at'         => $env_state->env_updated_at ?? $env_state->updated_at,
					],
					$stale_threshold
				);
			}

			if ( $stale_only && ! $is_stale ) {
				continue;
			}

			$targets = $all_targets[ (int) $flag->id ] ?? [];

			$rows[] = [
				'flag_key'      => $flag->flag_key,
				'label'         => $flag->label,
				'env'           => $env,
				'status'        => null !== $env_state ? ( 1 === (int) $env_state->status ? 'enabled' : 'disabled' ) : '—',
				'percentage'    => null !== $env_state ? (int) $env_state->rollout_percentage . '%' : '—',
				'targets'       => count( $targets ),
				'is_registered' => (bool) $flag->is_registered ? 'yes' : 'orphaned',
				'is_stale'      => $is_stale ? 'yes' : 'no',
				'last_changed'  => $env_state->env_updated_at ?? $env_state->updated_at ?? '—',
			];
		}

		$fields = [ 'flag_key', 'label', 'status', 'percentage', 'targets', 'is_registered', 'is_stale', 'last_changed' ];
		format_items( $format, $rows, $fields );
	}

	// -------------------------------------------------------------------------
	// get
	// -------------------------------------------------------------------------

	/**
	 * Show details for a single flag across all environments.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key to inspect.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags get new_checkout
	 *     wp myrk flags get new_checkout --format=json
	 *
	 * @param array $args       Positional arguments: flag_key.
	 * @param array $assoc_args Associative arguments: format.
	 * @when after_wp_load
	 */
	public function get( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$result = FlagRepository::get_with_all_environments( $flag_key );
		if ( null === $result ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "Flag '%s' not found.", 'myrk-feature-flags' ), $flag_key ) );
		}

		$flag   = $result['flag'];
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			$output = [
				'flag_key'        => $flag->flag_key,
				'label'           => $flag->label,
				'description'     => $flag->description,
				'default'         => (bool) $flag->default_state,
				'rewind_strategy' => $flag->rewind_strategy,
				'is_registered'   => (bool) $flag->is_registered,
				'created_at'      => $flag->created_at,
				'environments'    => [],
			];
			foreach ( $result['environments'] as $env ) {
				$output['environments'][ $env->environment ] = [
					'status'     => 1 === (int) $env->status ? 'enabled' : 'disabled',
					'percentage' => (int) $env->rollout_percentage,
					'updated_at' => $env->updated_at,
				];
			}
			WP_CLI::line( (string) wp_json_encode( $output, JSON_PRETTY_PRINT ) );
			return;
		}

		/* translators: %s: flag key */
		WP_CLI::line( sprintf( __( 'Flag key:    %s', 'myrk-feature-flags' ), $flag->flag_key ) );
		/* translators: %s: flag label */
		WP_CLI::line( sprintf( __( 'Label:       %s', 'myrk-feature-flags' ), $flag->label ) );
		/* translators: %s: flag description */
		WP_CLI::line( sprintf( __( 'Description: %s', 'myrk-feature-flags' ), $flag->description ) );
		/* translators: %s: registration status (yes or orphaned) */
		WP_CLI::line( sprintf( __( 'Registered:  %s', 'myrk-feature-flags' ), (bool) $flag->is_registered ? 'yes' : 'orphaned' ) );
		WP_CLI::line( '' );

		$env_rows = [];
		foreach ( $result['environments'] as $env ) {
			$env_rows[] = [
				'environment' => $env->environment,
				'status'      => 1 === (int) $env->status ? 'enabled' : 'disabled',
				'percentage'  => (int) $env->rollout_percentage . '%',
				'updated_at'  => $env->updated_at,
			];
		}

		if ( empty( $env_rows ) ) {
			WP_CLI::line( __( '(no environment state — use `wp myrk flags init` or enable/disable commands)', 'myrk-feature-flags' ) );
		} else {
			format_items( 'table', $env_rows, [ 'environment', 'status', 'percentage', 'updated_at' ] );
		}
	}

	// -------------------------------------------------------------------------
	// enable
	// -------------------------------------------------------------------------

	/**
	 * Enable a flag in the current (or specified) environment.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key to enable.
	 *
	 * [--percentage=<percentage>]
	 * : Rollout percentage (0–100). Default 100.
	 *
	 * [--env=<env>]
	 * : Target environment. Defaults to wp_get_environment_type().
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags enable new_checkout
	 *     wp myrk flags enable new_checkout --percentage=25 --env=staging
	 *
	 * @param array $args       Positional arguments: flag_key.
	 * @param array $assoc_args Associative arguments: percentage, env.
	 * @when after_wp_load
	 */
	public function enable( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$env        = $assoc_args['env'] ?? wp_get_environment_type();
		$percentage = min( 100, max( 0, (int) ( $assoc_args['percentage'] ?? 100 ) ) );
		$git_sha    = self::capture_git_sha();

		$ok = StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $env,
			changes:     [
				'status'             => 1,
				'rollout_percentage' => $percentage,
			],
			changed_via: ChangedVia::Cli,
			git_sha:     $git_sha
		);

		if ( ! $ok ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "Failed to enable '%s'. Is the flag registered in code?", 'myrk-feature-flags' ), $flag_key ) );
		}

		/* translators: %s: git commit SHA */
		$sha_note = $git_sha ? sprintf( __( ' [commit %s]', 'myrk-feature-flags' ), $git_sha ) : '';
		WP_CLI::success(
			sprintf(
			/* translators: 1: flag key, 2: rollout percentage, 3: environment name */
				__( 'Enabled %1$s at %2$d%% in %3$s', 'myrk-feature-flags' ),
				$flag_key,
				$percentage,
				$env
			) . $sha_note
		);
	}

	// -------------------------------------------------------------------------
	// disable
	// -------------------------------------------------------------------------

	/**
	 * Disable a flag in the current (or specified) environment.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key to disable.
	 *
	 * [--env=<env>]
	 * : Target environment. Defaults to wp_get_environment_type().
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags disable new_checkout
	 *
	 * @param array $args       Positional arguments: flag_key.
	 * @param array $assoc_args Associative arguments: env.
	 * @when after_wp_load
	 */
	public function disable( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$env     = $assoc_args['env'] ?? wp_get_environment_type();
		$git_sha = self::capture_git_sha();

		$ok = StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $env,
			changes:     [
				'status'             => 0,
				'rollout_percentage' => 0,
			],
			changed_via: ChangedVia::Cli,
			git_sha:     $git_sha
		);

		if ( ! $ok ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "Failed to disable '%s'. Is the flag registered in code?", 'myrk-feature-flags' ), $flag_key ) );
		}

		/* translators: %s: git commit SHA */
		$sha_note = $git_sha ? sprintf( __( ' [commit %s]', 'myrk-feature-flags' ), $git_sha ) : '';
		WP_CLI::success(
			sprintf(
			/* translators: 1: flag key, 2: environment name */
				__( 'Disabled %1$s in %2$s', 'myrk-feature-flags' ),
				$flag_key,
				$env
			) . $sha_note
		);
	}

	// -------------------------------------------------------------------------
	// set-percentage
	// -------------------------------------------------------------------------

	/**
	 * Set the rollout percentage for a flag without changing its enabled/disabled state.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key.
	 *
	 * <percentage>
	 * : Rollout percentage (0–100).
	 *
	 * [--env=<env>]
	 * : Target environment. Defaults to wp_get_environment_type().
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags set-percentage new_checkout 50
	 *
	 * @param array $args       Positional arguments: flag_key, percentage.
	 * @param array $assoc_args Associative arguments: env.
	 * @subcommand set-percentage
	 * @when after_wp_load
	 */
	public function set_percentage( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$percentage = min( 100, max( 0, (int) ( $args[1] ?? 0 ) ) );
		$env        = $assoc_args['env'] ?? wp_get_environment_type();
		$git_sha    = self::capture_git_sha();

		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $env,
			changes:     [ 'rollout_percentage' => $percentage ],
			changed_via: ChangedVia::Cli,
			git_sha:     $git_sha
		);

		/* translators: %s: git commit SHA */
		$sha_note = $git_sha ? sprintf( __( ' [commit %s]', 'myrk-feature-flags' ), $git_sha ) : '';
		WP_CLI::success(
			sprintf(
			/* translators: 1: flag key, 2: rollout percentage, 3: environment name */
				__( 'Set %1$s to %2$d%% in %3$s', 'myrk-feature-flags' ),
				$flag_key,
				$percentage,
				$env
			) . $sha_note
		);
	}

	// -------------------------------------------------------------------------
	// init
	// -------------------------------------------------------------------------

	/**
	 * Initialize DB state for a registered flag at 0% (disabled) in current env.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key to initialize. Must be registered in code.
	 *
	 * [--env=<env>]
	 * : Target environment. Defaults to wp_get_environment_type().
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags init new_checkout
	 *
	 * @param array $args       Positional arguments: flag_key.
	 * @param array $assoc_args Associative arguments: env.
	 * @when after_wp_load
	 */
	public function init( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		if ( ! Registry::has( $flag_key ) ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "'%s' is not registered in code. Register it with Myrk::register() first.", 'myrk-feature-flags' ), $flag_key ) );
		}

		$env = $assoc_args['env'] ?? wp_get_environment_type();

		// get_or_create_flag_id creates the flags row if absent.
		$flag_id = FlagRepository::get_or_create_flag_id( $flag_key );
		if ( null === $flag_id ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "Failed to initialize flag row for '%s'.", 'myrk-feature-flags' ), $flag_key ) );
		}

		$existing = FlagRepository::get_environment_state( $flag_key, $env );
		if ( null !== $existing ) {
			/* translators: 1: flag key, 2: environment name */
			WP_CLI::warning( sprintf( __( "Flag '%1\$s' is already initialized in %2\$s. No changes made.", 'myrk-feature-flags' ), $flag_key, $env ) );
			return;
		}

		StateWriter::upsert_environment_state(
			flag_key:    $flag_key,
			environment: $env,
			changes:     [
				'status'             => 0,
				'rollout_percentage' => 0,
			],
			changed_via: ChangedVia::Cli,
			note:        'Initialized via wp myrk flags init'
		);

		/* translators: 1: flag key, 2: environment name */
		WP_CLI::success( sprintf( __( "Initialized '%1\$s' in %2\$s at 0%% (disabled).", 'myrk-feature-flags' ), $flag_key, $env ) );
	}

	// -------------------------------------------------------------------------
	// prune
	// -------------------------------------------------------------------------

	/**
	 * Remove DB state for flags no longer registered in code.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be pruned without making changes.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags prune --dry-run
	 *     wp myrk flags prune
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: dry-run, format.
	 * @when after_wp_load
	 */
	public function prune( array $args, array $assoc_args ): void {
		$dry_run = get_flag_value( $assoc_args, 'dry-run', false );
		$format  = $assoc_args['format'] ?? 'table';

		global $wpdb;
		$all_db_keys = $wpdb->get_col( "SELECT flag_key FROM {$wpdb->prefix}myrk_flags" );

		$registered_keys = array_keys( Registry::all() );
		$orphaned        = array_diff( $all_db_keys, $registered_keys );

		if ( empty( $orphaned ) ) {
			WP_CLI::success( __( 'No orphaned flags found.', 'myrk-feature-flags' ) );
			return;
		}

		$rows = array_map(
			fn( $k ) => [
				'flag_key' => $k,
				'action'   => $dry_run ? 'would delete' : 'deleted',
			],
			$orphaned
		);
		format_items( $format, $rows, [ 'flag_key', 'action' ] );

		if ( ! $dry_run ) {
			foreach ( $orphaned as $key ) {
				FlagWriter::delete( $key );
			}
			/* translators: %d: number of orphaned flags removed */
			WP_CLI::success( sprintf( __( 'Pruned %d orphaned flag(s).', 'myrk-feature-flags' ), count( $orphaned ) ) );
		} else {
			/* translators: %d: number of orphaned flags that would be removed */
			WP_CLI::line( sprintf( __( '%d orphaned flag(s) would be removed (re-run without --dry-run to apply).', 'myrk-feature-flags' ), count( $orphaned ) ) );
		}
	}

	// -------------------------------------------------------------------------
	// refs
	// -------------------------------------------------------------------------

	/**
	 * Find all Myrk API call sites for a flag key in the codebase.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key to search for.
	 *
	 * [--path=<path>]
	 * : Directory to search. Defaults to wp-content/.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags refs new_checkout
	 *     wp myrk flags refs new_checkout --path=/var/www/html/wp-content/themes/mytheme
	 *
	 * @param array $args       Positional arguments: flag_key.
	 * @param array $assoc_args Associative arguments: path, format.
	 * @when after_wp_load
	 */
	public function refs( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$search_path = $assoc_args['path'] ?? WP_CONTENT_DIR;
		$format      = $assoc_args['format'] ?? 'table';

		if ( ! is_dir( $search_path ) ) {
			/* translators: %s: directory path */
			WP_CLI::error( sprintf( __( "Path '%s' does not exist.", 'myrk-feature-flags' ), $search_path ) );
		}

		$refs = \Myrk\Cleanup::find_refs( $flag_key, $search_path );

		if ( empty( $refs ) ) {
			/* translators: 1: flag key, 2: directory path */
			WP_CLI::warning( sprintf( __( "No references to '%1\$s' found in %2\$s.", 'myrk-feature-flags' ), $flag_key, $search_path ) );
			return;
		}

		$rows = array_map(
			fn( $ref ) => [
				'file'    => $ref['file'],
				'line'    => $ref['line'],
				'context' => trim( $ref['context'] ),
			],
			$refs
		);

		format_items( $format, $rows, [ 'file', 'line', 'context' ] );
		/* translators: 1: number of references found, 2: flag key */
		WP_CLI::line( sprintf( __( 'Found %1$d reference(s) to "%2$s".', 'myrk-feature-flags' ), count( $refs ), $flag_key ) );
	}

	// -------------------------------------------------------------------------
	// set-group
	// -------------------------------------------------------------------------

	/**
	 * Assign or remove a group from a flag.
	 *
	 * ## OPTIONS
	 *
	 * <flag_key>
	 * : The flag key.
	 *
	 * [<group_name>]
	 * : The name of the group to assign. Omit when using --remove.
	 *
	 * [--remove]
	 * : Remove the current group assignment.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk flags set-group new_checkout "Sprint 42"
	 *     wp myrk flags set-group new_checkout --remove
	 *
	 * @param array $args       Positional arguments: flag_key, group_name.
	 * @param array $assoc_args Associative arguments: remove.
	 * @subcommand set-group
	 * @when after_wp_load
	 */
	public function set_group( array $args, array $assoc_args ): void {
		$flag_key = $args[0] ?? '';
		$this->require_flag_key( $flag_key );

		$flag = FlagRepository::get_by_key( $flag_key );
		if ( null === $flag ) {
			/* translators: %s: flag key */
			WP_CLI::error( sprintf( __( "Flag '%s' not found.", 'myrk-feature-flags' ), $flag_key ) );
		}

		$removing = get_flag_value( $assoc_args, 'remove', false );

		if ( $removing ) {
			FlagWriter::update_definition( $flag_key, [ 'group_id' => null ] );
			/* translators: %s: flag key */
			WP_CLI::success( sprintf( __( "Removed group from '%s'.", 'myrk-feature-flags' ), $flag_key ) );
			return;
		}

		$group_name = trim( $args[1] ?? '' );
		if ( '' === $group_name ) {
			WP_CLI::error( __( 'Provide a group_name or use --remove to unassign.', 'myrk-feature-flags' ) );
		}

		$group = GroupRepository::get_by_name( $group_name );
		if ( null === $group ) {
			/* translators: %s: group name */
			WP_CLI::error( sprintf( __( "Group '%s' not found. Create it first with `wp myrk groups create`.", 'myrk-feature-flags' ), $group_name ) );
		}

		FlagWriter::update_definition( $flag_key, [ 'group_id' => (int) $group->id ] );

		/* translators: 1: flag key, 2: group name */
		WP_CLI::success( sprintf( __( "Assigned '%1\$s' to group '%2\$s'.", 'myrk-feature-flags' ), $flag_key, $group_name ) );
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Abort with an error if the flag key is empty.
	 *
	 * @param string $flag_key The flag key to validate.
	 */
	private function require_flag_key( string $flag_key ): void {
		if ( '' === $flag_key ) {
			WP_CLI::error( __( 'flag_key is required.', 'myrk-feature-flags' ) );
		}
	}

	/**
	 * Return the current Git SHA via the myrk_git_sha filter, or null if not provided.
	 *
	 * @return string|null
	 */
	private static function capture_git_sha(): ?string {
		$sha = apply_filters( 'myrk_git_sha', null );
		return ( is_string( $sha ) && '' !== $sha ) ? $sha : null;
	}
}

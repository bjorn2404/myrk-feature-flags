<?php
/**
 * Pro WP-CLI commands for managing Myrk environments.
 *
 * @package Myrk\Pro
 */

declare( strict_types=1 );

namespace Myrk\Pro\Cli;

use Myrk\ChangedVia;
use Myrk\Database\StateWriter;
use WP_CLI;
use WP_CLI_Command;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\get_flag_value;

/**
 * Pro environment commands — requires an active Myrk Pro license.
 *
 * ## EXAMPLES
 *
 *     wp myrk envs copy --from=staging --to=production
 *     wp myrk envs copy --from=staging --to=production --dry-run
 */
class EnvsCommand extends WP_CLI_Command {

	/**
	 * Copy flag states from one environment to another.
	 *
	 * ## OPTIONS
	 *
	 * --from=<env>
	 * : Source environment.
	 *
	 * --to=<env>
	 * : Target environment.
	 *
	 * [--flags=<flags>]
	 * : Comma-separated list of flag keys to copy. Omit to copy all.
	 *
	 * [--dry-run]
	 * : Show what would be copied without making changes.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk envs copy --from=staging --to=production
	 *     wp myrk envs copy --from=staging --to=production --dry-run
	 *     wp myrk envs copy --from=staging --to=production --flags=new_checkout,new_nav
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: from, to, flags, dry-run, format.
	 * @when after_wp_load
	 */
	public function copy( array $args, array $assoc_args ): void {
		$from    = $assoc_args['from'] ?? '';
		$to      = $assoc_args['to'] ?? '';
		$dry_run = get_flag_value( $assoc_args, 'dry-run', false );
		$format  = $assoc_args['format'] ?? 'table';

		if ( '' === $from || '' === $to ) {
			WP_CLI::error( __( '--from and --to are required.', 'myrk-feature-flags' ) );
		}

		if ( $from === $to ) {
			WP_CLI::error( __( '--from and --to must differ.', 'myrk-feature-flags' ) );
		}

		$valid_envs = [ 'production', 'staging', 'development', 'local' ];
		if ( ! in_array( $from, $valid_envs, true ) ) {
			/* translators: %s: environment name */
			WP_CLI::error( sprintf( __( 'Invalid environment: %s', 'myrk-feature-flags' ), $from ) );
		}
		if ( ! in_array( $to, $valid_envs, true ) ) {
			/* translators: %s: environment name */
			WP_CLI::error( sprintf( __( 'Invalid environment: %s', 'myrk-feature-flags' ), $to ) );
		}

		$flag_filter = isset( $assoc_args['flags'] )
			? array_map( 'trim', explode( ',', $assoc_args['flags'] ) )
			: null;

		global $wpdb;
		$source_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.flag_key, fle.status, fle.rollout_percentage, fle.anonymous_strategy
				 FROM {$wpdb->prefix}myrk_flag_environments fle
				 INNER JOIN {$wpdb->prefix}myrk_flags f ON f.id = fle.flag_id
				 WHERE fle.environment = %s",
				$from
			)
		);

		if ( ! is_array( $source_rows ) || empty( $source_rows ) ) {
			/* translators: %s: environment name */
			WP_CLI::warning( sprintf( __( 'No flag states found in %s environment.', 'myrk-feature-flags' ), $from ) );
			return;
		}

		$applied = [];
		$skipped = [];
		$git_sha = $this->capture_git_sha();

		foreach ( $source_rows as $row ) {
			if ( null !== $flag_filter && ! in_array( $row->flag_key, $flag_filter, true ) ) {
				$skipped[] = [
					'flag_key' => $row->flag_key,
					'reason'   => 'not in --flags filter',
				];
				continue;
			}

			if ( ! $dry_run ) {
				StateWriter::upsert_environment_state(
					flag_key:    $row->flag_key,
					environment: $to,
					changes:     [
						'status'             => (int) $row->status,
						'rollout_percentage' => (int) $row->rollout_percentage,
						'anonymous_strategy' => $row->anonymous_strategy,
					],
					changed_via: ChangedVia::Cli,
					note:        "Copied from {$from} via wp myrk envs copy",
					git_sha:     $git_sha
				);
			}

			$applied[] = [
				'flag_key'   => $row->flag_key,
				'status'     => 1 === (int) $row->status ? 'enabled' : 'disabled',
				'percentage' => (int) $row->rollout_percentage . '%',
				'action'     => $dry_run ? 'would copy' : 'copied',
			];
		}

		if ( ! empty( $applied ) ) {
			format_items( $format, $applied, [ 'flag_key', 'status', 'percentage', 'action' ] );
		}

		$count    = count( $applied );
		/* translators: %s: git commit SHA */
		$sha_note = ( $git_sha && ! $dry_run ) ? sprintf( __( ' [commit %s]', 'myrk-feature-flags' ), $git_sha ) : '';

		if ( $dry_run ) {
			WP_CLI::line(
				sprintf(
					/* translators: 1: count of flags, 2: source environment, 3: target environment */
					__( 'Would copy %1$d flag state(s) from %2$s to %3$s (dry run — no changes made).', 'myrk-feature-flags' ),
					$count,
					$from,
					$to
				)
			);
		} else {
			WP_CLI::success(
				sprintf(
					/* translators: 1: count of flags, 2: source environment, 3: target environment */
					__( 'Copied %1$d flag state(s) from %2$s to %3$s', 'myrk-feature-flags' ),
					$count,
					$from,
					$to
				) . $sha_note
			);
		}

		if ( ! empty( $skipped ) ) {
			/* translators: %s: comma-separated list of skipped flag keys */
			WP_CLI::line( sprintf( __( 'Skipped: %s', 'myrk-feature-flags' ), implode( ', ', array_column( $skipped, 'flag_key' ) ) ) );
		}
	}

	/**
	 * Return the current Git SHA via the myrk_git_sha filter, or null if not provided.
	 *
	 * @return string|null
	 */
	private function capture_git_sha(): ?string {
		$sha = apply_filters( 'myrk_git_sha', null );
		return ( is_string( $sha ) && '' !== $sha ) ? $sha : null;
	}
}

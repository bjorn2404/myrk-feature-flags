<?php
/**
 * WP-CLI commands for managing Myrk flag groups.
 *
 * @package Myrk
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

namespace Myrk\Cli;

use Myrk\Database\GroupRepository;
use WP_CLI;
use WP_CLI_Command;
use function WP_CLI\Utils\format_items;

/**
 * Manage Myrk flag groups.
 *
 * ## EXAMPLES
 *
 *     wp myrk groups list
 *     wp myrk groups get "Sprint 42"
 *     wp myrk groups create "Sprint 42" --description="Q2 sprint" --external-ref=PROJ-100
 *     wp myrk groups update "Sprint 42" --name="Sprint 43"
 *     wp myrk groups delete "Sprint 42"
 */
class GroupsCommand extends WP_CLI_Command {

	// -------------------------------------------------------------------------
	// list
	// -------------------------------------------------------------------------

	/**
	 * List all groups with their flag counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml, csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk groups list
	 *     wp myrk groups list --format=json
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments: format.
	 * @when after_wp_load
	 */
	public function list( array $args, array $assoc_args ): void {
		$format = $assoc_args['format'] ?? 'table';
		$groups = GroupRepository::get_all_with_flag_counts();

		if ( empty( $groups ) ) {
			WP_CLI::line( __( 'No groups found.', 'myrk' ) );
			return;
		}

		$rows = array_map(
			fn( $g ) => [
				'id'           => (int) $g->id,
				'name'         => $g->name,
				'description'  => $g->description ?? '',
				'flags'        => (int) $g->flag_count,
				'external_ref' => $g->external_ref ?? '',
				'created_at'   => $g->created_at,
			],
			$groups
		);

		format_items( $format, $rows, [ 'id', 'name', 'description', 'flags', 'external_ref', 'created_at' ] );
	}

	// -------------------------------------------------------------------------
	// get
	// -------------------------------------------------------------------------

	/**
	 * Show details for a single group, including its flags.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The group name.
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk groups get "Sprint 42"
	 *     wp myrk groups get "Sprint 42" --format=json
	 *
	 * @param array $args       Positional arguments: name.
	 * @param array $assoc_args Associative arguments: format.
	 * @when after_wp_load
	 */
	public function get( array $args, array $assoc_args ): void {
		$name  = $args[0] ?? '';
		$group = $this->require_group( $name );
		$id    = (int) $group->id;

		$flags  = GroupRepository::get_flags_for_group( $id );
		$format = $assoc_args['format'] ?? 'table';

		if ( 'json' === $format ) {
			$output = [
				'id'               => $id,
				'name'             => $group->name,
				'description'      => $group->description,
				'external_ref'     => $group->external_ref,
				'external_ref_url' => $group->external_ref_url,
				'created_at'       => $group->created_at,
				'updated_at'       => $group->updated_at,
				'flags'            => array_map(
					fn( $f ) => [
						'flag_key'  => $f->flag_key,
						'label'     => $f->label,
						'lifecycle' => $f->lifecycle,
						'tags'      => $f->tags ?? '',
					],
					$flags
				),
			];
			WP_CLI::line( (string) wp_json_encode( $output, JSON_PRETTY_PRINT ) );
			return;
		}

		/* translators: %s: group name */
		WP_CLI::line( sprintf( __( 'Name:         %s', 'myrk' ), $group->name ) );
		/* translators: %s: group description */
		WP_CLI::line( sprintf( __( 'Description:  %s', 'myrk' ), $group->description ?? '' ) );
		if ( $group->external_ref ) {
			/* translators: %s: external reference identifier */
			WP_CLI::line( sprintf( __( 'External ref: %s', 'myrk' ), $group->external_ref ) );
		}
		if ( $group->external_ref_url ) {
			/* translators: %s: external reference URL */
			WP_CLI::line( sprintf( __( 'Ref URL:      %s', 'myrk' ), $group->external_ref_url ) );
		}
		WP_CLI::line( '' );

		if ( empty( $flags ) ) {
			WP_CLI::line( __( '(no flags in this group)', 'myrk' ) );
		} else {
			$flag_rows = array_map(
				fn( $f ) => [
					'flag_key'  => $f->flag_key,
					'label'     => $f->label,
					'lifecycle' => $f->lifecycle,
					'tags'      => $f->tags ?? '',
				],
				$flags
			);
			format_items( 'table', $flag_rows, [ 'flag_key', 'label', 'lifecycle', 'tags' ] );
		}
	}

	// -------------------------------------------------------------------------
	// create
	// -------------------------------------------------------------------------

	/**
	 * Create a new group.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The group name.
	 *
	 * [--description=<description>]
	 * : Optional description.
	 *
	 * [--external-ref=<ref>]
	 * : Short identifier for the linked PM ticket (e.g. JIRA-100).
	 *
	 * [--external-ref-url=<url>]
	 * : Full URL to the linked PM ticket.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk groups create "Sprint 42"
	 *     wp myrk groups create "Sprint 42" --description="Q2 sprint" --external-ref=PROJ-100 --external-ref-url=https://jira.example.com/browse/PROJ-100
	 *
	 * @param array $args       Positional arguments: name.
	 * @param array $assoc_args Associative arguments: description, external-ref, external-ref-url.
	 * @when after_wp_load
	 */
	public function create( array $args, array $assoc_args ): void {
		$name = trim( $args[0] ?? '' );
		if ( '' === $name ) {
			WP_CLI::error( __( 'name is required.', 'myrk' ) );
		}

		if ( null !== GroupRepository::get_by_name( $name ) ) {
			/* translators: %s: group name */
			WP_CLI::error( sprintf( __( "A group named '%s' already exists.", 'myrk' ), $name ) );
		}

		$id = GroupRepository::create(
			[
				'name'             => $name,
				'description'      => $assoc_args['description'] ?? '',
				'external_ref'     => $assoc_args['external-ref'] ?? null,
				'external_ref_url' => $assoc_args['external-ref-url'] ?? null,
			]
		);

		if ( null === $id ) {
			WP_CLI::error( __( 'Failed to create group.', 'myrk' ) );
		}

		/* translators: 1: group name, 2: new group ID */
		WP_CLI::success( sprintf( __( "Created group '%1\$s' (ID %2\$d).", 'myrk' ), $name, $id ) );
	}

	// -------------------------------------------------------------------------
	// update
	// -------------------------------------------------------------------------

	/**
	 * Update fields on an existing group.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The current name of the group to update.
	 *
	 * [--name=<new_name>]
	 * : New name for the group.
	 *
	 * [--description=<description>]
	 * : New description.
	 *
	 * [--external-ref=<ref>]
	 * : New external reference identifier.
	 *
	 * [--external-ref-url=<url>]
	 * : New external reference URL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk groups update "Sprint 42" --name="Sprint 43"
	 *     wp myrk groups update "Sprint 42" --description="Updated sprint"
	 *
	 * @param array $args       Positional arguments: name.
	 * @param array $assoc_args Associative arguments: name, description, external-ref, external-ref-url.
	 * @when after_wp_load
	 */
	public function update( array $args, array $assoc_args ): void {
		$name  = $args[0] ?? '';
		$group = $this->require_group( $name );

		$changes = [];

		if ( isset( $assoc_args['name'] ) ) {
			$changes['name'] = $assoc_args['name'];
		}
		if ( isset( $assoc_args['description'] ) ) {
			$changes['description'] = $assoc_args['description'];
		}
		if ( isset( $assoc_args['external-ref'] ) ) {
			$changes['external_ref'] = $assoc_args['external-ref'];
		}
		if ( isset( $assoc_args['external-ref-url'] ) ) {
			$changes['external_ref_url'] = $assoc_args['external-ref-url'];
		}

		if ( empty( $changes ) ) {
			WP_CLI::warning( __( 'No changes specified.', 'myrk' ) );
			return;
		}

		GroupRepository::update( (int) $group->id, $changes );

		/* translators: %s: group name */
		WP_CLI::success( sprintf( __( "Updated group '%s'.", 'myrk' ), $group->name ) );
	}

	// -------------------------------------------------------------------------
	// delete
	// -------------------------------------------------------------------------

	/**
	 * Delete a group. Flags in the group will be ungrouped.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : The group name.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp myrk groups delete "Sprint 42"
	 *     wp myrk groups delete "Sprint 42" --yes
	 *
	 * @param array $args       Positional arguments: name.
	 * @param array $assoc_args Associative arguments: yes.
	 * @when after_wp_load
	 */
	public function delete( array $args, array $assoc_args ): void {
		$name  = $args[0] ?? '';
		$group = $this->require_group( $name );

		WP_CLI::confirm(
			/* translators: %s: group name */
			sprintf( __( "Delete group '%s'? Flags in this group will be ungrouped.", 'myrk' ), $group->name ),
			$assoc_args
		);

		if ( ! GroupRepository::delete( (int) $group->id ) ) {
			/* translators: %s: group name */
			WP_CLI::error( sprintf( __( "Failed to delete group '%s'.", 'myrk' ), $group->name ) );
		}

		/* translators: %s: group name */
		WP_CLI::success( sprintf( __( "Deleted group '%s'.", 'myrk' ), $group->name ) );
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Fetch a group by name, aborting with an error when not found.
	 *
	 * @param string $name Group name.
	 * @return object
	 */
	private function require_group( string $name ): object {
		if ( '' === $name ) {
			WP_CLI::error( __( 'name is required.', 'myrk' ) );
		}

		$group = GroupRepository::get_by_name( $name );
		if ( null === $group ) {
			/* translators: %s: group name */
			WP_CLI::error( sprintf( __( "Group '%s' not found.", 'myrk' ), $name ) );
		}

		return $group;
	}
}

<?php
/**
 * Core plugin bootstrap class.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Myrk\Database\GroupRepository;
use Myrk\Database\Schema;
use WP_CLI;

/**
 * Bootstraps the Myrk plugin: hooks, REST routes, admin pages, and WP-CLI commands.
 */
class Plugin {

	/**
	 * Register all WordPress hooks required by the plugin.
	 */
	public function boot(): void {
		register_activation_hook( MYRK_FILE, [ $this, 'activate' ] );

		add_action( 'init', [ $this, 'sync_registered_flags' ], 20 );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu', [ $this, 'register_admin_pages' ] );

		( new FrontendBridge() )->register_hooks();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_cli_commands();
		}
	}

	/**
	 * Run database migrations on plugin activation.
	 */
	public function activate(): void {
		Schema::install();
	}

	/**
	 * Sync in-memory Registry flags to wp_myrk_flags on every request.
	 *
	 * Sets is_registered = 1 for all currently registered flags and updates
	 * their label/description/default in case they changed in code.
	 * `wp myrk flags prune` separately handles clearing is_registered for
	 * flags removed from code.
	 */
	public function sync_registered_flags(): void {
		global $wpdb;

		$flags = Registry::all();
		if ( empty( $flags ) ) {
			return;
		}

		$now = current_time( 'mysql', true );

		foreach ( $flags as $flag_key => $flag ) {
			$group_id    = null !== $flag->group ? GroupRepository::get_or_create_by_name( $flag->group ) : null;
			$tags_string = empty( $flag->tags ) ? null : implode( ',', $flag->tags );

			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}myrk_flags WHERE flag_key = %s",
					$flag_key
				)
			);

			if ( $existing_id ) {
				// Code-owned fields always sync. group_id and tags are only overwritten
				// when the flag explicitly sets them in code — otherwise admin assignments persist.
				$sync = [
					'label'           => $flag->label,
					'description'     => $flag->description,
					'default_state'   => (int) $flag->fallback,
					'rewind_strategy' => $flag->rewind_strategy->value,
					'lifecycle'       => $flag->lifecycle,
					'is_registered'   => 1,
					'updated_at'      => $now,
				];

				if ( null !== $flag->group ) {
					$sync['group_id'] = $group_id;
				}
				if ( ! empty( $flag->tags ) ) {
					$sync['tags'] = $tags_string;
				}

				$wpdb->update(
					$wpdb->prefix . 'myrk_flags',
					$sync,
					[ 'id' => (int) $existing_id ]
				);
			} else {
				$wpdb->insert(
					$wpdb->prefix . 'myrk_flags',
					[
						'flag_key'        => $flag->flag_key,
						'label'           => $flag->label,
						'description'     => $flag->description,
						'default_state'   => (int) $flag->fallback,
						'rewind_strategy' => $flag->rewind_strategy->value,
						'lifecycle'       => $flag->lifecycle,
						'group_id'        => $group_id,
						'tags'            => $tags_string,
						'is_registered'   => 1,
						'created_at'      => $now,
						'updated_at'      => $now,
					]
				);
			}
		}
	}

	/**
	 * Register all REST API routes for the plugin.
	 */
	public function register_routes(): void {
		( new Api\FlagsController() )->register_routes();
		( new Api\GroupsController() )->register_routes();
		( new Api\EnvironmentsController() )->register_routes();
		( new Api\TargetsController() )->register_routes();
		( new Api\EnvsController() )->register_routes();
		( new Api\EvaluateController() )->register_routes();
	}

	/**
	 * Register the Myrk admin menu pages.
	 */
	public function register_admin_pages(): void {
		$flags_screen  = new Admin\FlagsScreen();
		$edit_screen   = new Admin\EditScreen();
		$groups_screen = new Admin\GroupsScreen();

		$flags_screen->register();
		$edit_screen->register();
		$groups_screen->register();
	}

	/**
	 * Register WP-CLI commands for flag and environment management.
	 */
	private function register_cli_commands(): void {
		WP_CLI::add_command( 'myrk flags', Cli\FlagsCommand::class );
		WP_CLI::add_command( 'myrk envs', Cli\EnvsCommand::class );
		WP_CLI::add_command( 'myrk groups', Cli\GroupsCommand::class );
	}
}

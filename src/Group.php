<?php
/**
 * Value object representing a flag group.
 *
 * @package   Myrk
 * @author    Bjorn Holine <bjorn@myrk.build>
 * @license   GPL-2.0-or-later
 * @link      https://myrk.build/
 * @copyright 2026 Bjorn Holine
 */

declare( strict_types=1 );

namespace Myrk;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable value object for a flag group as held in memory.
 * Groups organize related flags by sprint, release, or initiative and carry
 * an optional outbound link to the authoritative PM system (Jira, Linear, etc.).
 */
class Group {

	/**
	 * Construct a group.
	 *
	 * @param int         $id               DB primary key.
	 * @param string      $name             Human-readable group name.
	 * @param string      $description      Optional group description.
	 * @param string|null $external_ref     Optional PM system reference (e.g. PROJ-1234).
	 * @param string|null $external_ref_url Optional clickable URL for external_ref.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $description = '',
		public readonly ?string $external_ref = null,
		public readonly ?string $external_ref_url = null,
	) {}
}

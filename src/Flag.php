<?php
/**
 * Value object representing a registered feature flag definition.
 *
 * @package Myrk
 */

declare( strict_types=1 );

namespace Myrk;

/**
 * Immutable value object holding a flag's configuration as registered in code.
 */
class Flag {

	/**
	 * Construct a flag definition.
	 *
	 * @param string         $flag_key           Unique flag identifier.
	 * @param string         $label              Human-readable label.
	 * @param string         $description        Optional description.
	 * @param bool           $fallback           Fallback return value when no state row exists.
	 * @param RewindStrategy $rewind_strategy    Automatic rewind strategy.
	 * @param string         $anonymous_strategy Anonymous user bucketing strategy.
	 * @param string         $lifecycle          'temporary' (stale-eligible) or 'permanent' (excluded).
	 * @param string|null    $group              Group name as passed in code; resolved to group_id on sync.
	 * @param string[]       $tags               Free-text tags for ad-hoc filtering.
	 */
	public function __construct(
		public readonly string $flag_key,
		public readonly string $label,
		public readonly string $description = '',
		public readonly bool $fallback = false,
		public readonly RewindStrategy $rewind_strategy = RewindStrategy::Stepwise,
		public readonly string $anonymous_strategy = 'ip',
		public readonly string $lifecycle = 'temporary',
		public readonly ?string $group = null,
		public readonly array $tags = [],
	) {}
}

<?php

declare( strict_types=1 );

namespace Myrk\Tests\Unit;

use Myrk\Cleanup;
use PHPUnit\Framework\TestCase;

class CleanupTest extends TestCase {

	private string $fixtures_path;

	protected function setUp(): void {
		parent::setUp();
		$this->fixtures_path = __DIR__ . '/fixtures/refs';
	}

	// -------------------------------------------------------------------------
	// is_stale()
	// -------------------------------------------------------------------------

	public function test_identifies_stale_flag_at_100_percent(): void {
		$row = [
			'status'             => 1,
			'rollout_percentage' => 100,
			'updated_at'         => date( 'Y-m-d H:i:s', strtotime( '-95 days' ) ),
		];
		$this->assertTrue( Cleanup::is_stale( $row ) );
	}

	public function test_identifies_stale_flag_at_0_percent(): void {
		$row = [
			'status'             => 0,
			'rollout_percentage' => 0,
			'updated_at'         => date( 'Y-m-d H:i:s', strtotime( '-91 days' ) ),
		];
		$this->assertTrue( Cleanup::is_stale( $row ) );
	}

	public function test_does_not_flag_recently_changed_as_stale(): void {
		$row = [
			'status'             => 1,
			'rollout_percentage' => 100,
			'updated_at'         => date( 'Y-m-d H:i:s', strtotime( '-5 days' ) ),
		];
		$this->assertFalse( Cleanup::is_stale( $row ) );
	}

	public function test_partial_rollout_is_never_stale(): void {
		$row = [
			'status'             => 1,
			'rollout_percentage' => 50,
			'updated_at'         => date( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
		];
		$this->assertFalse( Cleanup::is_stale( $row ) );
	}

	public function test_respects_custom_threshold(): void {
		$row = [
			'status'             => 1,
			'rollout_percentage' => 100,
			'updated_at'         => date( 'Y-m-d H:i:s', strtotime( '-45 days' ) ),
		];
		$this->assertFalse( Cleanup::is_stale( $row, 60 ) );
		$this->assertTrue( Cleanup::is_stale( $row, 30 ) );
	}

	// -------------------------------------------------------------------------
	// find_refs()
	// -------------------------------------------------------------------------

	public function test_refs_scan_finds_is_enabled_calls(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$contexts = array_column( $refs, 'context' );
		$this->assertContains( "if ( Myrk::is_enabled( 'new_checkout' ) ) {", $contexts );
	}

	public function test_refs_scan_finds_is_enabled_with_user(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$contexts = array_column( $refs, 'context' );
		$this->assertContains( "if ( Myrk::is_enabled( 'new_checkout', \$user ) ) {", $contexts );
	}

	public function test_refs_scan_finds_attempt_calls(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$contexts = array_column( $refs, 'context' );
		$this->assertContains( "return Myrk::attempt( 'new_checkout', function() {", $contexts );
	}

	public function test_refs_scan_finds_procedural_aliases(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$contexts = array_column( $refs, 'context' );
		$this->assertContains( "return myrk_is_enabled( 'new_checkout' );", $contexts );
		$this->assertContains( "return myrk_is_enabled_for( 'new_checkout', \$user );", $contexts );
	}

	public function test_refs_scan_does_not_match_different_flag_key(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$contexts = array_column( $refs, 'context' );
		foreach ( $contexts as $context ) {
			$this->assertStringNotContainsString( 'new_editor_experience', $context );
		}
	}

	public function test_refs_scan_returns_correct_line_numbers(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		$this->assertNotEmpty( $refs );
		foreach ( $refs as $ref ) {
			$this->assertArrayHasKey( 'line', $ref );
			$this->assertIsInt( $ref['line'] );
			$this->assertGreaterThan( 0, $ref['line'] );
		}
	}

	public function test_refs_scan_respects_custom_path_argument(): void {
		$empty_path = sys_get_temp_dir();
		$refs       = Cleanup::find_refs( 'new_checkout', $empty_path );

		$this->assertIsArray( $refs );
		foreach ( $refs as $ref ) {
			$this->assertStringNotContainsString( $this->fixtures_path, $ref['file'] );
		}
	}

	public function test_refs_scan_returns_empty_for_nonexistent_path(): void {
		$refs = Cleanup::find_refs( 'new_checkout', '/nonexistent/path/to/nowhere' );
		$this->assertSame( [], $refs );
	}

	public function test_refs_scan_counts_all_occurrences(): void {
		$refs = Cleanup::find_refs( 'new_checkout', $this->fixtures_path );

		// block_render.php has 3 occurrences (is_enabled x2, attempt x1)
		// procedural_usage.php has 2 occurrences (myrk_is_enabled, myrk_is_enabled_for)
		// unrelated.php has 0
		$this->assertCount( 5, $refs );
	}
}

<?php
/**
 * Idempotency keys for background work are deterministic per event, so a
 * WP-Cron re-run cannot apply the same work twice (docs/ARCHITECTURE.md A4).
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

class IdempotencyTest extends SAMOMIND_TestCase {

	// Contract 5.1: 8 to 128 visible ASCII characters.
	const KEY_FORMAT = '/^[\x21-\x7e]{8,128}$/';

	public function test_event_key_is_deterministic_and_well_formed() {
		$key = SAMOMIND_Client::event_key( 'push', array( 'push:5:2026-11-02 08:30:00' ) );

		$this->assertSame( $key, SAMOMIND_Client::event_key( 'push', array( 'push:5:2026-11-02 08:30:00' ) ) );
		$this->assertMatchesRegularExpression( self::KEY_FORMAT, $key );
		$this->assertSame( 43, strlen( $key ) );
	}

	public function test_event_key_changes_with_scope_and_parts() {
		$base = SAMOMIND_Client::event_key( 'push', array( 'push:5:2026-11-02 08:30:00' ) );

		$this->assertNotSame( $base, SAMOMIND_Client::event_key( 'delete', array( 'push:5:2026-11-02 08:30:00' ) ) );
		$this->assertNotSame( $base, SAMOMIND_Client::event_key( 'push', array( 'push:5:2026-11-02 08:30:01' ) ) );
		$this->assertNotSame( $base, SAMOMIND_Client::event_key( 'push', array( 'push:6:2026-11-02 08:30:00' ) ) );
		// Parts are separated, so ("ab", "c") and ("a", "bc") differ.
		$this->assertNotSame( SAMOMIND_Client::event_key( 's', array( 'ab', 'c' ) ), SAMOMIND_Client::event_key( 's', array( 'a', 'bc' ) ) );
	}

	public function test_post_event_id_is_operation_id_and_modified_time() {
		$this->assertSame( 'push:12:2026-11-02 08:30:00', SAMOMIND_Sync::event_id( 'push', 12, '2026-11-02 08:30:00' ) );
		$this->assertSame( 'delete:12:2026-11-02 08:30:00', SAMOMIND_Sync::event_id( 'delete', '12', '2026-11-02 08:30:00' ) );
	}

	public function test_batch_key_ignores_order_but_not_content() {
		$a = SAMOMIND_Sync::batch_key( 'push', array( '1' => 'push:1:t1', '2' => 'push:2:t1' ) );
		$b = SAMOMIND_Sync::batch_key( 'push', array( '2' => 'push:2:t1', '1' => 'push:1:t1' ) );
		$c = SAMOMIND_Sync::batch_key( 'push', array( '1' => 'push:1:t2', '2' => 'push:2:t1' ) );

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertMatchesRegularExpression( self::KEY_FORMAT, $a );
	}

	public function test_user_actions_get_a_fresh_key() {
		Functions\when( 'wp_generate_uuid4' )->justReturn( '4f8e2a1c-0b3d-4e5f-8a9b-1c2d3e4f5a6b' );

		$key = SAMOMIND_Client::fresh_key( 'connect' );

		$this->assertSame( 'connect-4f8e2a1c-0b3d-4e5f-8a9b-1c2d3e4f5a6b', $key );
		$this->assertMatchesRegularExpression( self::KEY_FORMAT, $key );
	}

	public function test_retry_delays_follow_the_contract() {
		$this->assertSame( 60, SAMOMIND_Sync::retry_delay( 1, 0 ) );
		$this->assertSame( 300, SAMOMIND_Sync::retry_delay( 2, 0 ) );
		$this->assertSame( 900, SAMOMIND_Sync::retry_delay( 3, 0 ) );
		$this->assertSame( 3600, SAMOMIND_Sync::retry_delay( 4, 0 ) );
		$this->assertSame( 21600, SAMOMIND_Sync::retry_delay( 5, 0 ) );
		$this->assertSame( 86400, SAMOMIND_Sync::retry_delay( 6, 0 ) );
		$this->assertSame( 86400, SAMOMIND_Sync::retry_delay( 40, 0 ) );
		$this->assertSame( 17, SAMOMIND_Sync::retry_delay( 3, 17 ) );
	}
}

<?php
/**
 * Unit tests for the atomic primitives behind the order checks: each one
 * must make its decision in one statement and read the answer from the rows
 * that statement changed. The database is faked, so these tests check the
 * statements and what is made of their results; the integration tests run
 * them against a real database, in parallel.
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/**
 * Records every statement and answers from a queue.
 */
class SAMOMIND_State_Wpdb {

	public $prefix  = 'wp_';
	public $queries = array();

	/**
	 * Results of query(), first in first out; 1 when the queue is empty.
	 *
	 * @var array
	 */
	public $rows = array();

	/**
	 * Results of get_row() and get_var(), first in first out.
	 *
	 * @var array
	 */
	public $reads = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );
		return vsprintf( $query, $args );
	}

	public function query( $query ) {
		$this->queries[] = $query;
		return array() === $this->rows ? 1 : array_shift( $this->rows );
	}

	public function get_row( $query, $output = null ) {
		$this->queries[] = $query;
		return array_shift( $this->reads );
	}

	public function get_var( $query ) {
		$this->queries[] = $query;
		return array_shift( $this->reads );
	}
}

class OrdersStateTest extends SAMOMIND_TestCase {

	/**
	 * The fake database.
	 *
	 * @var SAMOMIND_State_Wpdb
	 */
	private $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db        = new SAMOMIND_State_Wpdb();
		$GLOBALS['wpdb'] = $this->db;
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'esc_sql' )->returnArg();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['REQUEST_URI'] );
		parent::tearDown();
	}

	private function last_query() {
		return end( $this->db->queries );
	}

	public function test_claim_removes_an_expired_holder_then_inserts_against_the_unique_key() {
		$this->db->rows = array( 0, 1 );

		$this->assertSame( 1, SAMOMIND_Orders_State::claim( 'nonce', str_repeat( 'a', 64 ), time() + 300 ) );
		$this->assertStringStartsWith( 'DELETE FROM wp_samomind_orders_state', $this->db->queries[0] );
		$this->assertStringContainsString( 'expires <=', $this->db->queries[0] );
		$this->assertStringStartsWith( 'INSERT IGNORE INTO wp_samomind_orders_state', $this->db->queries[1] );
	}

	public function test_claim_of_a_held_key_changes_no_row_and_a_database_error_is_false() {
		$this->db->rows = array( 0, 0 );
		$this->assertSame( 0, SAMOMIND_Orders_State::claim( 'nonce', 'k', time() + 300 ) );

		$this->db->rows = array( 0, false );
		$this->assertFalse( SAMOMIND_Orders_State::claim( 'nonce', 'k', time() + 300 ) );
	}

	public function test_bump_is_one_conditional_increment_below_the_limit() {
		$this->db->rows = array( 0, 1, 1 );

		$this->assertTrue( SAMOMIND_Orders_State::bump( 'omail', 'k', 3, time() + 3600 ) );
		$this->assertStringContainsString( 'SET n = n + 1', $this->last_query() );
		$this->assertStringContainsString( 'n < 3', $this->last_query() );
		$this->assertStringContainsString( 'expires >', $this->last_query() );
	}

	public function test_bump_past_the_limit_changes_no_row_and_is_refused() {
		$this->db->rows = array( 0, 0, 0 );
		$this->assertFalse( SAMOMIND_Orders_State::bump( 'omail', 'k', 3, time() + 3600 ) );
	}

	public function test_bump_does_not_count_when_the_counter_cannot_be_created() {
		$this->db->rows = array( 0, false );

		$this->assertFalse( SAMOMIND_Orders_State::bump( 'omail', 'k', 3, time() + 3600 ) );
		$this->assertCount( 2, $this->db->queries, 'No UPDATE after a failed insert.' );
	}

	public function test_a_try_is_counted_by_a_conditional_update_on_the_row() {
		$this->db->rows = array( 1 );
		$this->assertTrue( SAMOMIND_Orders_State::try_once( 12, 3 ) );
		$this->assertStringContainsString( 'SET n = n + 1 WHERE id = 12 AND n < 3 AND expires >', $this->last_query() );

		$this->db->rows = array( 0 );
		$this->assertFalse( SAMOMIND_Orders_State::try_once( 12, 3 ), 'Locked: no row changed.' );
	}

	public function test_spend_is_won_by_the_one_delete_that_changes_a_row() {
		$this->db->rows = array( 1, 0 );

		$this->assertTrue( SAMOMIND_Orders_State::spend( 5 ) );
		$this->assertFalse( SAMOMIND_Orders_State::spend( 5 ) );
		$this->assertStringContainsString( 'DELETE FROM wp_samomind_orders_state WHERE id = 5', $this->last_query() );
	}

	public function test_take_gives_the_record_only_to_the_delete_that_wins() {
		$row = array(
			'id'      => '9',
			'expires' => (string) ( time() + 60 ),
			'data'    => '{"user_id":4}',
		);

		$this->db->reads = array( $row );
		$this->db->rows  = array( 1 );
		$this->assertSame( array( 'user_id' => 4 ), SAMOMIND_Orders_State::take( 'record', 'k' ) );

		$this->db->reads = array( $row );
		$this->db->rows  = array( 0 );
		$this->assertNull( SAMOMIND_Orders_State::take( 'record', 'k' ), 'The other request read it too but its delete changed nothing.' );
	}

	public function test_take_of_an_expired_record_burns_it_and_gives_nothing() {
		$this->db->reads = array(
			array(
				'id'      => '9',
				'expires' => (string) ( time() - 1 ),
				'data'    => '{"user_id":4}',
			),
		);
		$this->db->rows  = array( 1 );

		$this->assertNull( SAMOMIND_Orders_State::take( 'record', 'k' ) );
		$this->assertStringStartsWith( 'DELETE', $this->last_query() );
	}

	public function test_take_of_a_missing_record_gives_nothing() {
		$this->db->reads = array( null );
		$this->assertNull( SAMOMIND_Orders_State::take( 'record', 'k' ) );
	}

	public function test_count_and_get_read_live_rows_only() {
		$this->db->reads = array( '2' );
		$this->assertSame( 2, SAMOMIND_Orders_State::count( 'hour', 'k' ) );
		$this->assertStringContainsString( 'expires >', $this->last_query() );

		$this->db->reads = array( null );
		$this->assertNull( SAMOMIND_Orders_State::get( 'code', 'k' ) );
		$this->assertStringContainsString( 'expires >', $this->last_query() );
	}

	public function test_the_end_of_a_session_deletes_only_its_own_rows_of_the_kinds_named() {
		SAMOMIND_Orders_State::delete_session( 'sess', array( 'code', 'grant' ) );
		$this->assertStringContainsString( "session_ref = 'sess' AND kind IN ('code', 'grant')", $this->last_query() );
	}

	public function test_a_nonce_is_used_once_and_a_failed_insert_refuses_the_request() {
		$auth = new ReflectionMethod( 'SAMOMIND_Orders_Auth', 'remember_nonce' );
		if ( PHP_VERSION_ID < 80100 ) {
			$auth->setAccessible( true );
		}
		$object = new SAMOMIND_Orders_Auth();

		$this->db->rows = array( 0, 1 );
		$this->assertTrue( $auth->invoke( $object, 'AAAAAAAAAAAAAAAAAAAAAA' ) );

		$this->db->rows = array( 0, 0 );
		$this->assertFalse( $auth->invoke( $object, 'AAAAAAAAAAAAAAAAAAAAAA' ), 'Replay.' );

		$this->db->rows = array( 0, false );
		$this->assertFalse( $auth->invoke( $object, 'AAAAAAAAAAAAAAAAAAAAAA' ), 'Fail closed.' );
	}

	public function test_a_code_is_counted_before_it_is_compared_and_spent_once() {
		$store = new SAMOMIND_Orders_Store( 'secret' );
		$row   = array(
			'id'      => '3',
			'n'       => '0',
			'expires' => (string) ( time() + 60 ),
			'data'    => json_encode( array( 'hash' => 'right' ) ),
		);

		// Wrong: counted, not spent.
		$this->db->reads = array( $row );
		$this->db->rows  = array( 1 );
		$this->assertFalse( $store->check_code( 's', 7, 'wrong', 3 ) );
		$this->assertCount( 2, $this->db->queries, 'A SELECT and the counting UPDATE, no DELETE.' );

		// Right but locked: the guess is refused even though it is correct.
		$this->db->queries = array();
		$this->db->reads   = array( $row );
		$this->db->rows    = array( 0 );
		$this->assertFalse( $store->check_code( 's', 7, 'right', 3 ) );

		// Right, and this request's DELETE wins.
		$this->db->queries = array();
		$this->db->reads   = array( $row );
		$this->db->rows    = array( 1, 1 );
		$this->assertTrue( $store->check_code( 's', 7, 'right', 3 ) );

		// Right, but another request spent it first.
		$this->db->reads = array( $row );
		$this->db->rows  = array( 1, 0 );
		$this->assertFalse( $store->check_code( 's', 7, 'right', 3 ) );
	}

	public function test_the_order_cap_is_keyed_on_the_order_id_and_is_three() {
		$store = new SAMOMIND_Orders_Store( 'secret' );
		$this->assertSame( 3, SAMOMIND_Orders_Store::ORDER_EMAILS_PER_HOUR );

		$this->db->rows = array( 0, 1, 1 );
		$this->assertTrue( $store->allow_order_email( 42 ) );
		$this->assertStringContainsString( 'n < 3', $this->last_query() );
		$this->assertStringContainsString( SAMOMIND_Orders_State::key( array( '42' ) ), $this->db->queries[1] );
		$this->assertStringContainsString( "'omail'", $this->db->queries[1] );

		$this->db->reads = array( '3' );
		$this->assertTrue( $store->order_cap_reached( 42 ) );
		$this->db->reads = array( '2' );
		$this->assertFalse( $store->order_cap_reached( 42 ) );
	}

	public function test_a_request_whose_path_is_the_rest_path_of_its_route_matches() {
		$_SERVER['REQUEST_URI'] = '/wp-json/samomind/v1/orders/start';
		$this->assertTrue( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ) );

		$_SERVER['REQUEST_URI'] = '/blog/wp-json/samomind/v1/orders/start?x=1';
		$this->assertTrue( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ) );

		unset( $_SERVER['REQUEST_URI'] );
		$this->assertTrue( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ), 'No request URI, nothing to compare.' );
	}

	public function test_a_rest_route_parameter_on_a_rest_prefix_path_is_refused() {
		$_SERVER['REQUEST_URI'] = '/wp-json/samomind/v1/orders/ping?rest_route=/samomind/v1/orders/start';
		$this->assertFalse( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ) );
		$this->assertFalse( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/ping' ) );
	}

	public function test_a_route_that_is_not_the_signed_path_is_refused() {
		$_SERVER['REQUEST_URI'] = '/wp-json/samomind/v1/orders/ping';
		$this->assertFalse( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ) );

		$_SERVER['REQUEST_URI'] = '/index.php?rest_route=/samomind/v1/orders/start';
		$this->assertFalse( SAMOMIND_Orders_Auth::path_matches_route( '/samomind/v1/orders/start' ), 'The path is not the REST path.' );
	}
}

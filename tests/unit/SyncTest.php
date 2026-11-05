<?php
/**
 * The worker against a scripted API: full sync, resume, reconcile,
 * retries, replays, the page cap and post events (contract 5.4 to 5.7).
 *
 * @package SAMOMIND
 */

use Brain\Monkey\Functions;

/**
 * Client that records requests and returns scripted responses.
 */
class SAMOMIND_Fake_Client extends SAMOMIND_Client {

	public $calls     = array();
	public $responses = array();

	public function __construct() {
		parent::__construct( 'https://api.samomind.com', 'key-id', 'secret' );
	}

	public function post( $endpoint, array $payload, $idempotency_key ) {
		$this->calls[] = array(
			'endpoint' => $endpoint,
			'payload'  => $payload,
			'key'      => $idempotency_key,
		);
		$response = array_shift( $this->responses );
		return null === $response ? array() : $response;
	}
}

/**
 * Content source with a fixed set of posts: id => eligible.
 */
class SAMOMIND_Fake_Content extends SAMOMIND_Content {

	public $posts = array();

	public function get_candidate_ids( $after_id, $limit ) {
		$ids = array_filter(
			array_keys( $this->posts ),
			function ( $id ) use ( $after_id ) {
				return $id > $after_id;
			}
		);
		sort( $ids );
		return array_slice( $ids, 0, $limit );
	}

	public function count_candidates() {
		return count( $this->posts );
	}

	public function get_document( $post_id, $max_text_chars ) {
		if ( empty( $this->posts[ $post_id ] ) ) {
			return null;
		}
		return array(
			'external_id' => (string) $post_id,
			'url'         => 'https://www.example.com/?p=' . $post_id,
			'kind'        => 'page',
			'title'       => 'Page ' . $post_id,
			'text'        => 'Text ' . $post_id,
			'modified_at' => '2026-11-01T00:00:00+00:00',
		);
	}

	public function get_inventory() {
		$entries = array();
		foreach ( $this->posts as $id => $eligible ) {
			if ( $eligible ) {
				$entries[ $id ] = '2026-11-01T00:00:00+00:00';
			}
		}
		return self::sort_by_external_id( $entries );
	}

	public function is_tracked_type( $post_type ) {
		return true;
	}

	public function is_eligible( $post ) {
		return 'publish' === $post->post_status && '' === $post->post_password;
	}

	public $published_by_type = array();

	public function get_published_ids( $post_type ) {
		return isset( $this->published_by_type[ $post_type ] ) ? $this->published_by_type[ $post_type ] : array();
	}

	public function get_included_ids( $post_type ) {
		return $this->get_published_ids( $post_type );
	}
}

/**
 * Sync with the fake client.
 */
class SAMOMIND_Test_Sync extends SAMOMIND_Sync {

	public $fake_client;

	protected function client() {
		return $this->fake_client;
	}
}

/**
 * Connection with the fake client.
 */
class SAMOMIND_Test_Connection extends SAMOMIND_Connection {

	public $fake_client;

	protected function client() {
		return $this->fake_client;
	}
}

/**
 * Scheduler that only records.
 */
class SAMOMIND_Fake_Scheduler {

	public $scheduled = array();

	public function schedule_worker( $delay = 0 ) {
		$this->scheduled[] = $delay;
	}

	public function unschedule_worker() {
	}
}

class SyncTest extends SAMOMIND_TestCase {

	/**
	 * In-memory options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * @var SAMOMIND_Test_Sync
	 */
	private $sync;

	/**
	 * @var SAMOMIND_Fake_Client
	 */
	private $client;

	/**
	 * @var SAMOMIND_Fake_Content
	 */
	private $content;

	protected function setUp(): void {
		parent::setUp();

		$this->options = array(
			'samomind_connection' => array( 'source_id' => '8c1d2e3f-4a5b-4c6d-8e7f-9a0b1c2d3e4f' ),
		);
		$options       = &$this->options;
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( &$options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value ) use ( &$options ) {
				if ( array_key_exists( $name, $options ) ) {
					return false;
				}
				$options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) use ( &$options ) {
				unset( $options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'run-0001' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );

		$this->client              = new SAMOMIND_Fake_Client();
		$this->content             = new SAMOMIND_Fake_Content();
		$this->sync                = new SAMOMIND_Test_Sync();
		$this->sync->fake_client   = $this->client;
		$connection                = new SAMOMIND_Test_Connection();
		$connection->fake_client   = $this->client;
		$GLOBALS['samomind_test_plugin'] = (object) array(
			'connection' => $connection,
			'content'    => $this->content,
			'scheduler'  => new SAMOMIND_Fake_Scheduler(),
			'sync'       => $this->sync,
		);
	}

	private function endpoints() {
		return array_column( $this->client->calls, 'endpoint' );
	}

	private function queued( array $ids, $status = 'queued' ) {
		$results = array();
		foreach ( $ids as $id ) {
			$results[] = array(
				'external_id' => (string) $id,
				'status'      => $status,
			);
		}
		return array( 'results' => $results );
	}

	private function error( $status, $code ) {
		return new WP_Error(
			$code,
			'message for ' . $code,
			array(
				'status'      => $status,
				'retryable'   => SAMOMIND_Client::is_retryable( $status ),
				'retry_after' => 0,
			)
		);
	}

	public function test_full_sync_then_reconcile() {
		$this->content->posts = array(
			1  => true,
			2  => false,
			3  => true,
			10 => true,
		);
		$this->client->responses = array(
			array( 'state' => 'in_progress', 'cursor' => null ),
			$this->queued( array( 1, 3, 10 ) ),
			array( 'state' => 'complete', 'cursor' => '10' ),
			array( 'missing' => array(), 'stale' => array( '3' ), 'deleted' => array( '99' ) ),
			$this->queued( array( 3 ), 'unchanged' ),
		);

		$this->sync->start_full_sync();
		$this->assertSame( 'idle', $this->sync->run() );

		$this->assertSame( array( '/sync', '/documents', '/sync', '/reconcile', '/documents' ), $this->endpoints() );
		$calls = $this->client->calls;
		$this->assertSame( 'begin', $calls[0]['payload']['action'] );
		$this->assertSame( array( '1', '3', '10' ), array_column( $calls[1]['payload']['documents'], 'external_id' ) );
		$this->assertSame( '10', $calls[1]['payload']['sync_cursor'] );
		$this->assertSame( 'complete', $calls[2]['payload']['action'] );
		$this->assertNull( $calls[3]['payload']['range_start'] );
		$this->assertNull( $calls[3]['payload']['range_end'] );
		$this->assertSame( array( '1', '10', '3' ), array_column( $calls[3]['payload']['entries'], 'external_id' ) );
		$this->assertSame( array( '3' ), array_column( $calls[4]['payload']['documents'], 'external_id' ) );

		$progress = $this->sync->get_progress();
		$this->assertSame( 'complete', $progress['state'] );
		$this->assertSame( 3, $progress['sent'] );
		$this->assertSame( 3, $progress['accepted'] );
		$this->assertSame( 'complete', $progress['reconcile']['state'] );
		$this->assertSame( 1, $progress['reconcile']['stale'] );
		$this->assertSame( 1, $progress['reconcile']['deleted'] );
		$this->assertSame( 0, $progress['pending'] );
	}

	public function test_full_sync_resumes_after_the_server_cursor() {
		$this->content->posts    = array(
			1  => true,
			3  => true,
			10 => true,
		);
		$this->client->responses = array(
			array( 'state' => 'in_progress', 'cursor' => '3' ),
			$this->queued( array( 10 ) ),
		);

		$this->sync->start_full_sync();
		$this->sync->run();

		$this->assertSame( array( '10' ), array_column( $this->client->calls[1]['payload']['documents'], 'external_id' ) );
	}

	public function test_server_error_is_retried_later_with_the_same_key() {
		$this->content->posts    = array( 1 => true );
		$this->client->responses = array(
			array( 'state' => 'in_progress', 'cursor' => null ),
			$this->error( 503, 'http_503' ),
		);

		$this->sync->start_full_sync();
		$this->assertSame( 'retry', $this->sync->run() );

		$queue = $this->sync->get_queue();
		$this->assertSame( 1, $queue['retry']['attempts'] );
		$this->assertEqualsWithDelta( time() + 60, $queue['retry']['next_at'], 2 );

		// Before the retry time nothing is sent.
		$this->assertSame( 'retry', $this->sync->run() );
		$this->assertCount( 2, $this->client->calls );

		// After it, the same request goes out with the same Idempotency-Key.
		$queue['retry']['next_at']             = time() - 1;
		$this->options['samomind_queue']       = $queue;
		$this->client->responses               = array( $this->queued( array( 1 ) ) );
		$this->sync->run();

		$this->assertSame( '/documents', $this->client->calls[2]['endpoint'] );
		$this->assertSame( $this->client->calls[1]['key'], $this->client->calls[2]['key'] );
		$this->assertSame( 0, $this->sync->get_queue()['retry']['attempts'] );
	}

	public function test_replay_counts_as_applied() {
		$this->sync->on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 5 ) ) );
		$this->content->posts    = array( 5 => true );
		$this->client->responses = array( $this->error( 409, 'duplicate_request' ) );

		$this->assertSame( 'idle', $this->sync->run() );

		$this->assertSame( array( '/documents' ), $this->endpoints() );
		$this->assertSame( 0, $this->sync->get_progress()['pending'] );
		$this->assertSame( 0, $this->sync->get_queue()['retry']['attempts'] );
	}

	public function test_page_cap_stops_the_sync_and_shows_the_message() {
		$this->content->posts    = array(
			1 => true,
			2 => true,
		);
		$this->client->responses = array(
			array( 'state' => 'in_progress', 'cursor' => null ),
			array(
				'results' => array(
					array( 'external_id' => '1', 'status' => 'queued' ),
					array( 'external_id' => '2', 'status' => 'refused', 'code' => 'plan_page_cap' ),
				),
			),
		);

		$this->sync->start_full_sync();
		$this->sync->run();

		$progress = $this->sync->get_progress();
		$this->assertSame( 'stopped', $progress['state'] );
		$this->assertStringContainsString( 'plan_page_cap', $progress['message'] );
		$this->assertSame( array( '/sync', '/documents' ), $this->endpoints() );
	}

	public function test_blocking_error_stops_background_work() {
		$this->content->posts    = array( 1 => true );
		$this->client->responses = array( $this->error( 401, 'key_revoked' ) );

		$this->sync->start_full_sync();
		$this->assertSame( 'blocked', $this->sync->run() );
		$this->assertSame( 'blocked', $this->sync->run() );

		$this->assertCount( 1, $this->client->calls );
		$this->assertSame( 'key_revoked', SAMOMIND()->connection->get_block()['code'] );
	}

	public function test_trash_after_publish_sends_a_delete_only() {
		$this->sync->on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 5 ) ) );
		$this->sync->on_transition_post_status(
			'trash',
			'publish',
			new WP_Post(
				array(
					'ID'                => 5,
					'post_status'       => 'trash',
					'post_modified_gmt' => '2026-11-02 09:00:00',
				)
			)
		);
		$this->client->responses = array( array( 'results' => array( array( 'external_id' => '5', 'status' => 'queued' ) ) ) );

		$this->sync->run();

		$this->assertSame( array( '/documents/delete' ), $this->endpoints() );
		$this->assertSame( array( '5' ), $this->client->calls[0]['payload']['external_ids'] );
	}

	public function test_password_protecting_a_published_post_deletes_it() {
		$this->sync->on_transition_post_status( 'publish', 'publish', new WP_Post( array( 'ID' => 7, 'post_password' => 'x' ) ) );

		$this->assertSame( 'delete', $this->sync->get_queue()['items']['7']['op'] );
	}

	public function test_drafts_never_queue_anything() {
		$this->sync->on_transition_post_status( 'draft', 'draft', new WP_Post( array( 'ID' => 8, 'post_status' => 'draft' ) ) );

		$this->assertSame( array(), $this->sync->get_queue()['items'] );
	}

	public function test_events_are_ignored_when_not_connected() {
		unset( $this->options['samomind_connection'] );

		$this->sync->on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 5 ) ) );

		$this->assertSame( array(), $this->sync->get_queue()['items'] );
	}

	public function test_a_rerun_of_the_same_work_builds_the_same_key() {
		$this->sync->on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 5 ) ) );
		$this->content->posts = array( 5 => true );

		$first  = $this->sync->next_request();
		$second = $this->sync->next_request();

		$this->assertSame( $first['key'], $second['key'] );
		$this->assertSame( $first['payload'], $second['payload'] );
	}

	public function test_dashboard_disconnect_leaves_the_worker_idle_and_never_reconnects() {
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		$this->sync->on_transition_post_status( 'publish', 'draft', new WP_Post( array( 'ID' => 5 ) ) );
		$this->content->posts    = array( 5 => true );
		$this->client->responses = array( $this->error( 409, 'source_disconnected' ) );

		$this->assertSame( 'detached', $this->sync->run() );

		// Two more worker ticks and two hourly ticks, with a check-in long overdue.
		$this->options['samomind_connection']['last_checkin_at'] = 0;
		$this->assertSame( 'not_connected', $this->sync->run() );
		$this->sync->on_hourly();
		$this->assertSame( 'not_connected', $this->sync->run() );
		$this->sync->on_hourly();

		$this->assertSame( array( '/documents' ), $this->endpoints() );
		$connection = SAMOMIND()->connection;
		$this->assertFalse( $connection->is_connected() );
		$this->assertSame( 'source_disconnected', $connection->get_block()['code'] );
		$this->assertSame( 'Disconnected from the SamoMind dashboard.', $connection->get_block()['message'] );
		$this->assertSame( array(), $this->sync->get_queue()['items'] );
		$this->assertFalse( $this->sync->has_work() );
	}

	public function test_unknown_source_on_checkin_detaches_without_reconnecting() {
		Functions\when( 'get_bloginfo' )->justReturn( '7.1' );
		$this->client->responses = array( $this->error( 404, 'unknown_source' ) );

		$result = SAMOMIND()->connection->checkin( false );

		$this->assertSame( 'unknown_source', $result->get_error_code() );
		$this->assertSame( array( '/checkin' ), $this->endpoints() );
		$this->assertFalse( SAMOMIND()->connection->is_connected() );
		$this->assertSame( 'unknown_source', SAMOMIND()->connection->get_block()['code'] );
	}

	public function test_unticking_a_type_queues_deletes_in_batches() {
		$this->content->published_by_type = array(
			'book'  => range( 1, 501 ),
			'event' => array( 900 ),
		);
		$this->client->responses          = array( array( 'results' => array() ), array( 'results' => array() ) );

		$this->sync->on_excluded_post_types_updated( array( 'event' ), array( 'event', 'book' ) );

		$items = $this->sync->get_queue()['items'];
		$this->assertCount( 501, $items );
		$this->assertArrayNotHasKey( '900', $items );
		$this->assertSame( 'delete', $items['1']['op'] );

		$this->sync->run();

		$this->assertSame( array( '/documents/delete', '/documents/delete' ), $this->endpoints() );
		$this->assertCount( 500, $this->client->calls[0]['payload']['external_ids'] );
		$this->assertSame( array( '501' ), $this->client->calls[1]['payload']['external_ids'] );
		$this->assertSame( 0, $this->sync->get_progress()['pending'] );
	}

	public function test_untick_then_retick_queues_deletes_then_pushes() {
		$this->content->posts             = array(
			1 => true,
			2 => true,
		);
		$this->content->published_by_type = array( 'book' => array( 1, 2 ) );
		$this->client->responses          = array( $this->queued( array( 1, 2 ) ), $this->queued( array( 1, 2 ) ) );

		// Untick: deletes go out.
		$this->sync->on_excluded_post_types_updated( array(), array( 'book' ) );
		$this->sync->run();
		// Tick again: pushes for the same items go out at once, without a reconcile.
		$this->sync->on_excluded_post_types_updated( array( 'book' ), array() );
		$this->assertSame( 'push', $this->sync->get_queue()['items']['1']['op'] );
		$this->sync->run();

		$this->assertSame( array( '/documents/delete', '/documents' ), $this->endpoints() );
		$this->assertSame( array( '1', '2' ), array_column( $this->client->calls[1]['payload']['documents'], 'external_id' ) );
		$this->assertSame( 0, $this->sync->get_progress()['pending'] );
		$this->assertNotSame( $this->client->calls[0]['key'], $this->client->calls[1]['key'] );
	}

	public function test_a_retick_before_the_deletes_were_sent_replaces_them() {
		$this->content->posts             = array( 1 => true );
		$this->content->published_by_type = array( 'book' => array( 1 ) );
		$this->client->responses          = array( $this->queued( array( 1 ) ) );

		$this->sync->on_excluded_post_types_updated( array(), array( 'book' ) );
		$this->sync->on_excluded_post_types_updated( array( 'book' ), array() );
		$this->sync->run();

		$this->assertSame( array( '/documents' ), $this->endpoints() );
	}

	public function test_a_retick_queues_nothing_while_not_connected() {
		unset( $this->options['samomind_connection'] );
		$this->content->published_by_type = array( 'book' => array( 1, 2 ) );

		$this->sync->on_excluded_post_types_updated( array( 'book' ), array() );

		$this->assertSame( array(), $this->sync->get_queue()['items'] );
	}

	public function test_reconcile_after_a_retick_pushes_the_items_the_server_lacks() {
		// The untick's deletes went out earlier; the server now lists both
		// items as missing because their rows are marked deleted.
		$this->content->posts    = array(
			1 => true,
			2 => true,
		);
		$this->client->responses = array(
			array( 'missing' => array( '1', '2' ), 'stale' => array(), 'deleted' => array() ),
			$this->queued( array( 1, 2 ) ),
		);

		$this->sync->request_reconcile( 'manual-1' );
		$this->sync->run();

		$this->assertSame( array( '/reconcile', '/documents' ), $this->endpoints() );
		$this->assertSame( array( '1', '2' ), array_column( $this->client->calls[1]['payload']['documents'], 'external_id' ) );
		$this->assertSame( 2, $this->sync->get_progress()['reconcile']['missing'] );
	}

	public function test_unticking_while_not_connected_queues_nothing() {
		unset( $this->options['samomind_connection'] );
		$this->content->published_by_type = array( 'book' => array( 1, 2 ) );

		$this->sync->on_excluded_post_types_updated( array(), array( 'book' ) );

		$this->assertSame( array(), $this->sync->get_queue()['items'] );
	}

	public function test_reconcile_pages_over_the_entry_cap() {
		$this->content->posts = array_fill_keys( range( 1, 5003 ), true );
		$ids                  = array_map( 'strval', range( 1, 5003 ) );
		usort( $ids, 'strcmp' );
		$this->client->responses = array(
			array( 'missing' => array( '42' ), 'stale' => array(), 'deleted' => array() ),
			$this->queued( array( 42 ) ),
			array( 'missing' => array(), 'stale' => array( '999' ), 'deleted' => array( '7777' ) ),
			$this->queued( array( 999 ) ),
		);

		$this->sync->request_reconcile( 'test-run' );
		$this->assertSame( 'idle', $this->sync->run() );

		$this->assertSame( array( '/reconcile', '/documents', '/reconcile', '/documents' ), $this->endpoints() );
		$first  = $this->client->calls[0];
		$second = $this->client->calls[2];

		// Page 1: open start, closed end at the 5000th id in byte order.
		$this->assertNull( $first['payload']['range_start'] );
		$this->assertSame( $ids[4999], $first['payload']['range_end'] );
		$this->assertSame( array_slice( $ids, 0, 5000 ), array_column( $first['payload']['entries'], 'external_id' ) );

		// Page 2: starts after page 1's end (exclusive), open end, the last 3 ids.
		$this->assertSame( $ids[4999], $second['payload']['range_start'] );
		$this->assertNull( $second['payload']['range_end'] );
		$this->assertSame( array_slice( $ids, 5000 ), array_column( $second['payload']['entries'], 'external_id' ) );

		// Each page has its own key; missing and stale items were pushed.
		$this->assertNotSame( $first['key'], $second['key'] );
		$this->assertSame( array( '42' ), array_column( $this->client->calls[1]['payload']['documents'], 'external_id' ) );
		$this->assertSame( array( '999' ), array_column( $this->client->calls[3]['payload']['documents'], 'external_id' ) );

		$reconcile = $this->sync->get_progress()['reconcile'];
		$this->assertSame( 'complete', $reconcile['state'] );
		$this->assertSame( 1, $reconcile['missing'] );
		$this->assertSame( 1, $reconcile['stale'] );
		$this->assertSame( 1, $reconcile['deleted'] );
	}
}

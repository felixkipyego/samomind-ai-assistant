<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Sync
 *
 * The queue of post events, the full sync, reconcile and the background
 * worker that sends them, one request at a time (docs/API_CONTRACT.md 5.6
 * and 5.7, docs/ARCHITECTURE.md A1, A4, A8).
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Sync
 * @since       0.1.0
 */
class SAMOMIND_Sync {

	/**
	 * Option holding full sync and reconcile state.
	 *
	 * @var string
	 */
	const STATE_OPTION = 'samomind_sync';

	/**
	 * Option holding pending pushes and deletes, and the retry state.
	 *
	 * @var string
	 */
	const QUEUE_OPTION = 'samomind_queue';

	/**
	 * Option used as the worker lock.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'samomind_lock';

	/**
	 * A lock older than this is taken to be from a crashed run.
	 *
	 * @var int
	 */
	const LOCK_TTL = 300;

	/**
	 * Seconds a queued post event waits after its last enqueue before the
	 * worker may send it, so rapid saves collapse into one push of the
	 * final content. Filter: SAMOMIND/sync/debounce_seconds.
	 *
	 * @var int
	 */
	const EVENT_DELAY = 30;

	/**
	 * Requests per worker run; the API allows 60 per minute per key.
	 *
	 * @var int
	 */
	const MAX_REQUESTS_PER_RUN = 20;

	/**
	 * Seconds a worker run may spend before handing over to the next run.
	 *
	 * @var int
	 */
	const RUN_TIME_BUDGET = 20;

	/**
	 * Retry delays in seconds (contract 5.7), then BACKOFF_MAX.
	 *
	 * @var int[]
	 */
	const BACKOFF = array( 60, 300, 900, 3600, 21600 );

	/**
	 * Retry delay after the BACKOFF list is used up.
	 *
	 * @var int
	 */
	const BACKOFF_MAX = 86400;

	/**
	 * Candidate posts read per query during a full sync.
	 *
	 * @var int
	 */
	const SCAN_CHUNK = 100;

	/**
	 * Bytes kept free under the body limit for the envelope.
	 *
	 * @var int
	 */
	const BODY_MARGIN = 8192;

	/**
	 * ######################
	 * ###
	 * #### STATE
	 * ###
	 * ######################
	 */

	/**
	 * Full sync and reconcile state with defaults.
	 *
	 * @since   0.1.0
	 * @return  array
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		$state['full']        = wp_parse_args(
			isset( $state['full'] ) ? (array) $state['full'] : array(),
			array(
				'state'       => 'idle',
				'run_id'      => '',
				'cursor'      => 0,
				'begun'       => false,
				'total'       => 0,
				'sent'        => 0,
				'accepted'    => 0,
				'unchanged'   => 0,
				'refused'     => 0,
				'started_at'  => 0,
				'finished_at' => 0,
				'message'     => '',
			)
		);
		$state['reconcile']   = wp_parse_args(
			isset( $state['reconcile'] ) ? (array) $state['reconcile'] : array(),
			array(
				'state'       => 'idle',
				'run_id'      => '',
				'after'       => '',
				'missing'     => 0,
				'stale'       => 0,
				'deleted'     => 0,
				'finished_at' => 0,
				'message'     => '',
			)
		);
		$state['batch_limit'] = isset( $state['batch_limit'] ) ? (int) $state['batch_limit'] : 0;
		$state['notice']      = isset( $state['notice'] ) ? (string) $state['notice'] : '';

		return $state;
	}

	/**
	 * Save full sync and reconcile state.
	 *
	 * @since   0.1.0
	 * @param   array $state  Output of get_state(), changed.
	 * @return  void
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Pending items and retry state.
	 *
	 * @since   0.1.0
	 * @return  array  items: external_id => array( op, event ); retry: attempts, next_at.
	 */
	public function get_queue() {
		$queue = get_option( self::QUEUE_OPTION, array() );
		$queue = is_array( $queue ) ? $queue : array();

		$queue['items'] = isset( $queue['items'] ) ? (array) $queue['items'] : array();
		$queue['retry'] = wp_parse_args(
			isset( $queue['retry'] ) ? (array) $queue['retry'] : array(),
			array(
				'attempts' => 0,
				'next_at'  => 0,
			)
		);
		return $queue;
	}

	/**
	 * Save the queue.
	 *
	 * @since   0.1.0
	 * @param   array $queue  Output of get_queue(), changed.
	 * @return  void
	 */
	private function save_queue( array $queue ) {
		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Forget all sync state and pending work.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function reset() {
		delete_option( self::STATE_OPTION );
		delete_option( self::QUEUE_OPTION );
		delete_option( self::LOCK_OPTION );
		SAMOMIND()->scheduler->unschedule_worker();
	}

	/**
	 * Whether anything is waiting to be sent.
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	public function has_work() {
		$state = $this->get_state();
		$queue = $this->get_queue();
		return ! empty( $queue['items'] ) || 'running' === $state['full']['state'] || 'running' === $state['reconcile']['state'];
	}

	/**
	 * Progress for the status page, the AJAX poll and WP-CLI.
	 *
	 * @since   0.1.0
	 * @return  array
	 */
	public function get_progress() {
		$state = $this->get_state();
		$queue = $this->get_queue();
		$full  = $state['full'];

		return array(
			'state'          => $full['state'],
			'total'          => (int) $full['total'],
			'sent'           => (int) $full['sent'],
			'accepted'       => (int) $full['accepted'],
			'unchanged'      => (int) $full['unchanged'],
			'refused'        => (int) $full['refused'],
			'percent'        => $full['total'] > 0 ? min( 100, (int) floor( 100 * $full['sent'] / $full['total'] ) ) : ( 'complete' === $full['state'] ? 100 : 0 ),
			'started_at'     => (int) $full['started_at'],
			'finished_at'    => (int) $full['finished_at'],
			'message'        => (string) $full['message'],
			'reconcile'      => $state['reconcile'],
			'pending'        => count( $queue['items'] ),
			'retry_at'       => (int) $queue['retry']['next_at'],
			'retry_attempts' => (int) $queue['retry']['attempts'],
			'notice'         => $state['notice'],
		);
	}

	/**
	 * ######################
	 * ###
	 * #### STARTING WORK
	 * ###
	 * ######################
	 */

	/**
	 * Start (or restart) a full sync and wake the worker.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function start_full_sync() {
		$state                = $this->get_state();
		$state['full']        = array(
			'state'       => 'running',
			'run_id'      => wp_generate_uuid4(),
			'cursor'      => 0,
			'begun'       => false,
			'total'       => SAMOMIND()->content->count_candidates(),
			'sent'        => 0,
			'accepted'    => 0,
			'unchanged'   => 0,
			'refused'     => 0,
			'started_at'  => time(),
			'finished_at' => 0,
			'message'     => '',
		);
		$state['batch_limit'] = 0;
		$state['notice']      = '';
		$this->save_state( $state );
		$this->clear_retry();
		SAMOMIND()->scheduler->schedule_worker( 0 );
	}

	/**
	 * Start a reconcile and wake the worker.
	 *
	 * @since   0.1.0
	 * @param   string $run_id  Identifies this reconcile in idempotency keys;
	 *                          the daily one uses the date, so a WP-Cron
	 *                          re-run repeats the same keys.
	 * @return  void
	 */
	public function request_reconcile( $run_id ) {
		$state              = $this->get_state();
		$state['reconcile'] = array(
			'state'       => 'running',
			'run_id'      => (string) $run_id,
			'after'       => '',
			'missing'     => 0,
			'stale'       => 0,
			'deleted'     => 0,
			'finished_at' => 0,
			'message'     => '',
		);
		$this->save_state( $state );
		SAMOMIND()->scheduler->schedule_worker( 0 );
	}

	/**
	 * Event id for a post: operation, post id and modified time. The same
	 * saved state always gives the same id (docs/ARCHITECTURE.md A4).
	 *
	 * @since   0.1.0
	 * @param   string $op            push or delete.
	 * @param   int    $post_id       Post id.
	 * @param   string $modified_gmt  Post modified time, GMT.
	 * @return  string
	 */
	public static function event_id( $op, $post_id, $modified_gmt ) {
		return $op . ':' . (int) $post_id . ':' . $modified_gmt;
	}

	/**
	 * Idempotency key for a batch of events, independent of their order.
	 *
	 * @since   0.1.0
	 * @param   string   $scope   push or delete.
	 * @param   string[] $events  Event ids.
	 * @return  string
	 */
	public static function batch_key( $scope, array $events ) {
		$events = array_values( $events );
		sort( $events, SORT_STRING );
		return SAMOMIND_Client::event_key( $scope, $events );
	}

	/**
	 * Hooked to transition_post_status: queue a push or a delete.
	 *
	 * @since   0.1.0
	 * @param   string  $new_status  New status.
	 * @param   string  $old_status  Old status.
	 * @param   WP_Post $post        Post.
	 * @return  void
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$this->queue_post( $post, 'publish' === $old_status || 'publish' === $new_status );
	}

	/**
	 * Hooked to wp_after_insert_post: runs after a save through the block
	 * editor, the Classic editor, Quick Edit, the REST API or wp_update_post(),
	 * once the post's meta and terms are written. Only the id is queued; the
	 * content is read when the worker sends it.
	 *
	 * @since   0.2.0
	 * @param   int          $post_id      Post id.
	 * @param   WP_Post      $post         Post.
	 * @param   bool         $update       Whether this updated an existing post.
	 * @param   WP_Post|null $post_before  Post before the update.
	 * @return  void
	 */
	public function on_after_insert_post( $post_id, $post, $update = false, $post_before = null ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$was_published = $post_before instanceof WP_Post && 'publish' === $post_before->post_status;
		$this->queue_post( $post, $was_published );
	}

	/**
	 * Hooked to added_post_meta, updated_post_meta and deleted_post_meta:
	 * page builders write their layout to post meta, often after the post
	 * itself was saved, so a change to one of their storage keys queues a
	 * push. Cheap for every other key.
	 *
	 * @since   0.2.0
	 * @param   mixed  $meta_id   Meta id (or ids).
	 * @param   int    $post_id   Post id.
	 * @param   string $meta_key  Meta key.
	 * @return  void
	 */
	public function on_post_meta_change( $meta_id, $post_id, $meta_key = '' ) {
		if ( ! SAMOMIND()->builders->is_builder_meta_key( (string) $meta_key ) ) {
			return;
		}
		$this->queue_post_id( (int) $post_id );
	}

	/**
	 * Queue a push for a post id after a builder's own "layout saved"
	 * action. Nothing depends on these actions; the meta and save hooks
	 * above catch the same save.
	 *
	 * @since   0.2.0
	 * @param   int $post_id  Post id.
	 * @return  void
	 */
	public function queue_post_id( $post_id ) {
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post ) {
			// A layout save only ever adds or changes content, never deletes it.
			$this->queue_post( $post, false );
		}
	}

	/**
	 * Queue the right operation for a post: a push when it is eligible, a
	 * delete when it just stopped being eligible.
	 *
	 * @since   0.2.0
	 * @param   WP_Post $post             Post.
	 * @param   bool    $may_have_been_sent  Whether it could be at SamoMind already
	 *                                       (it was or is published).
	 * @return  void
	 */
	private function queue_post( $post, $may_have_been_sent ) {
		if ( ! SAMOMIND()->connection->is_connected() ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$content = SAMOMIND()->content;
		if ( ! $content->is_tracked_type( $post->post_type ) ) {
			return;
		}

		if ( $content->is_eligible( $post ) ) {
			$this->enqueue( $post, 'push' );
		} elseif ( $may_have_been_sent ) {
			// Unpublished, trashed, made private, given a password or excluded.
			$this->enqueue( $post, 'delete' );
		}
	}

	/**
	 * Hooked to before_delete_post: queue a delete for a post deleted while published.
	 * A trashed post was already deleted at the trash event.
	 *
	 * @since   0.1.0
	 * @param   int          $post_id  Post id.
	 * @param   WP_Post|null $post     Post.
	 * @return  void
	 */
	public function on_before_delete_post( $post_id, $post = null ) {
		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! SAMOMIND()->connection->is_connected() ) {
			return;
		}
		if ( ! SAMOMIND()->content->is_tracked_type( $post->post_type ) ) {
			return;
		}
		$this->enqueue( $post, 'delete' );
	}

	/**
	 * Put an operation in the queue. The latest operation per post wins and
	 * restarts the wait, so several saves in a row become one push.
	 *
	 * The event id carries the enqueue time and a counter, so a later save
	 * never reuses an earlier request's Idempotency-Key, even when the
	 * post's modified time did not change (a builder writing only meta).
	 *
	 * @since   0.1.0
	 * @param   WP_Post $post  Post.
	 * @param   string  $op    push or delete.
	 * @return  void
	 */
	private function enqueue( $post, $op ) {
		$queue        = $this->get_queue();
		$queue['seq'] = isset( $queue['seq'] ) ? (int) $queue['seq'] + 1 : 1;
		$delay        = $this->debounce_seconds();

		if ( 'delete' === $op ) {
			// A post that is gone from SamoMind cannot be incomplete there.
			SAMOMIND()->builders->forget( $post->ID );
		}

		$queue['items'][ (string) (int) $post->ID ] = array(
			'op'    => $op,
			'event' => self::event_id( $op, $post->ID, SAMOMIND_Content::modified_gmt( $post ) . '@' . $this->now() . '.' . $queue['seq'] ),
			'due'   => $this->now() + $delay,
		);
		$this->save_queue( $queue );
		SAMOMIND()->scheduler->schedule_worker( $delay );
	}

	/**
	 * Seconds a post event waits before it is sent.
	 *
	 * @since   0.2.0
	 * @return  int
	 */
	public function debounce_seconds() {
		/**
		 * Filter how long a saved post waits before it is pushed. Every new
		 * save of the same post restarts the wait.
		 *
		 * @since 0.2.0
		 * @param int $seconds Seconds, 30 by default; 0 sends at the next worker run.
		 */
		return max( 0, (int) apply_filters( 'SAMOMIND/sync/debounce_seconds', self::EVENT_DELAY ) );
	}

	/**
	 * The current time. Separate so tests can move the clock.
	 *
	 * @since   0.2.0
	 * @return  int
	 */
	protected function now() {
		return time();
	}

	/**
	 * Seconds until the worker has something to send: 0 when a full sync or
	 * reconcile is running or an item is due, otherwise the wait for the
	 * first item to come due.
	 *
	 * @since   0.2.0
	 * @return  int
	 */
	public function seconds_until_due() {
		$state = $this->get_state();
		if ( 'running' === $state['full']['state'] || 'running' === $state['reconcile']['state'] ) {
			return 0;
		}
		$queue = $this->get_queue();
		$wait  = null;
		foreach ( $queue['items'] as $item ) {
			$left = isset( $item['due'] ) ? (int) $item['due'] - $this->now() : 0;
			if ( $left <= 0 ) {
				return 0;
			}
			$wait = null === $wait ? $left : min( $wait, $left );
		}
		return null === $wait ? 0 : $wait;
	}

	/**
	 * Hooked to update_option_samomind_post_types: queue deletes
	 * for every published item of a type that was just unticked, and pushes
	 * for every published item of a type that was just ticked again. A
	 * later change replaces an earlier queued one for the same item, so an
	 * untick followed quickly by a re-tick ends as a push.
	 *
	 * @since   0.1.0
	 * @param   mixed $old_value  Previous selection.
	 * @param   mixed $new_value  New selection.
	 * @return  void
	 */
	public function on_post_types_updated( $old_value, $new_value ) {
		if ( ! SAMOMIND()->connection->is_connected() ) {
			return;
		}
		$unticked = array_diff( (array) $old_value, (array) $new_value );
		$reticked = array_diff( (array) $new_value, (array) $old_value );
		if ( empty( $unticked ) && empty( $reticked ) ) {
			return;
		}

		// The settings change is the event: its time keeps the keys stable
		// across worker re-runs and distinct from an earlier change.
		$changed = 'excluded@' . time();
		$queue   = $this->get_queue();
		foreach ( $unticked as $post_type ) {
			foreach ( SAMOMIND()->content->get_published_ids( (string) $post_type ) as $post_id ) {
				$queue['items'][ (string) $post_id ] = array(
					'op'    => 'delete',
					'event' => self::event_id( 'delete', $post_id, $changed ),
				);
			}
		}
		foreach ( $reticked as $post_type ) {
			foreach ( SAMOMIND()->content->get_included_ids( (string) $post_type ) as $post_id ) {
				$queue['items'][ (string) $post_id ] = array(
					'op'    => 'push',
					'event' => self::event_id( 'push', $post_id, 'included@' . time() ),
				);
			}
		}
		$this->save_queue( $queue );
		SAMOMIND()->scheduler->schedule_worker( 0 );
	}

	/**
	 * Hourly tick: check in when the interval has passed, then reconcile
	 * (contract 9: "check-in then reconcile daily"). Also wakes the worker
	 * if a scheduled run was lost.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	public function on_hourly() {
		$connection = SAMOMIND()->connection;
		if ( ! $connection->is_connected() ) {
			return;
		}

		$block = $connection->get_block();
		// A suspended tenant or disabled source still checks in (5.4), a bad key does not.
		$can_checkin = empty( $block['code'] ) || in_array( $block['code'], array( 'tenant_suspended', 'source_disabled' ), true );

		if ( $can_checkin && $connection->is_checkin_due() ) {
			$result = $connection->checkin( false );
			if ( ! is_wp_error( $result ) && ! $connection->is_blocked() ) {
				$this->request_reconcile( 'daily-' . gmdate( 'Y-m-d' ) );
			}
		}

		if ( $this->has_work() ) {
			SAMOMIND()->scheduler->schedule_worker( 0 );
		}
	}

	/**
	 * ######################
	 * ###
	 * #### WORKER
	 * ###
	 * ######################
	 */

	/**
	 * One worker pass: send requests until the queue is empty, the time
	 * budget is spent, or a request fails.
	 *
	 * @since   0.1.0
	 * @return  string  idle, more, retry, blocked, locked, detached or not_connected.
	 */
	public function run() {
		$connection = SAMOMIND()->connection;
		if ( ! $connection->is_connected() ) {
			return 'not_connected';
		}
		if ( $connection->is_blocked() ) {
			return 'blocked';
		}
		if ( ! $this->lock() ) {
			SAMOMIND()->scheduler->schedule_worker( MINUTE_IN_SECONDS );
			return 'locked';
		}

		$queue = $this->get_queue();
		if ( $queue['retry']['next_at'] > time() ) {
			$this->unlock();
			SAMOMIND()->scheduler->schedule_worker( $queue['retry']['next_at'] - time() );
			return 'retry';
		}

		$client  = $this->client();
		$started = time();
		$sent    = 0;
		$outcome = 'idle';

		while ( $sent < self::MAX_REQUESTS_PER_RUN && time() - $started < self::RUN_TIME_BUDGET ) {
			$request = $this->next_request();
			if ( null === $request ) {
				break;
			}

			$result = $client->post( $request['endpoint'], $request['payload'], $request['key'] );
			++$sent;

			if ( ! is_wp_error( $result ) ) {
				$this->apply( $request, $result );
				$this->clear_retry();
				continue;
			}

			$action = $connection->handle_error( $result );
			if ( 'applied' === $action ) {
				// 5.7: a duplicate_request on a retry means the original was applied.
				$this->apply( $request, null );
				$this->clear_retry();
				continue;
			}
			if ( 'retry' === $action ) {
				$this->schedule_retry( $result );
				$outcome = 'retry';
				break;
			}
			if ( 'split' === $action && $this->shrink_batch( $request ) ) {
				continue;
			}
			if ( 'detached' === $action ) {
				// handle_error() dropped the source and all pending work.
				$this->unlock();
				return 'detached';
			}
			if ( 'block' === $action ) {
				$outcome = 'blocked';
				break;
			}
			$this->drop( $request, $result );
		}

		$this->unlock();

		if ( 'idle' === $outcome && $this->has_work() ) {
			$outcome = 'more';
			// Items still waiting out their debounce: come back when the first is due.
			// Otherwise leave room under the per-key rate limit before the next run.
			$wait = $this->seconds_until_due();
			SAMOMIND()->scheduler->schedule_worker( $wait > 0 ? $wait : ( $sent >= self::MAX_REQUESTS_PER_RUN ? MINUTE_IN_SECONDS : 5 ) );
		}

		return $outcome;
	}

	/**
	 * Build the request client. Separate so it can be replaced in tests.
	 *
	 * @since   0.1.0
	 * @return  SAMOMIND_Client
	 */
	protected function client() {
		return SAMOMIND_Client::from_settings();
	}

	/**
	 * The next request to send, or null when there is nothing to do.
	 * Order: deletes, pushes, full sync, reconcile.
	 *
	 * @since   0.1.0
	 * @return  array|null  Keys type, endpoint, payload, key, and per type data.
	 */
	public function next_request() {
		$connection = SAMOMIND()->connection;
		$source_id  = $connection->get_source_id();
		$queue      = $this->get_queue();

		$deletes = array();
		$pushes  = array();
		$now     = $this->now();
		foreach ( $queue['items'] as $external_id => $item ) {
			if ( isset( $item['due'] ) && (int) $item['due'] > $now ) {
				// Saved moments ago: wait, a builder may still be writing.
				continue;
			}
			if ( 'delete' === $item['op'] ) {
				$deletes[ (string) $external_id ] = $item['event'];
			} else {
				$pushes[ (string) $external_id ] = $item['event'];
			}
		}

		if ( ! empty( $deletes ) ) {
			$deletes = array_slice( $deletes, 0, $connection->get_limit( 'max_delete_ids' ), true );
			return array(
				'type'     => 'delete',
				'endpoint' => '/documents/delete',
				'payload'  => array(
					'source_id'    => $source_id,
					'external_ids' => array_map( 'strval', array_keys( $deletes ) ),
				),
				'key'      => self::batch_key( 'delete', $deletes ),
				'events'   => $deletes,
			);
		}

		if ( ! empty( $pushes ) ) {
			$request = $this->build_push_request( $pushes );
			if ( null !== $request ) {
				return $request;
			}
			// Every pending push turned out ineligible and became a delete.
			return $this->next_request();
		}

		$state = $this->get_state();
		if ( 'running' === $state['full']['state'] ) {
			return $this->build_sync_request( $state );
		}
		if ( 'running' === $state['reconcile']['state'] ) {
			return $this->build_reconcile_request( $state );
		}

		return null;
	}

	/**
	 * Maximum documents in one /documents request.
	 *
	 * @since   0.1.0
	 * @return  int
	 */
	private function batch_size() {
		$state = $this->get_state();
		$limit = SAMOMIND()->connection->get_limit( 'max_documents_per_push' );
		return $state['batch_limit'] > 0 ? min( $limit, $state['batch_limit'] ) : $limit;
	}

	/**
	 * Add documents to a batch while it stays under the count and byte
	 * limits. Always takes at least one.
	 *
	 * @since   0.1.0
	 * @param   array $documents  Batch so far, changed in place.
	 * @param   int   $bytes      Encoded size so far, changed in place.
	 * @param   array $document   Document to add.
	 * @return  bool  False when the batch is full and the document was not added.
	 */
	private function add_to_batch( array &$documents, &$bytes, array $document ) {
		$size  = strlen( SAMOMIND_Client::encode( $document ) ) + 1;
		$limit = SAMOMIND()->connection->get_limit( 'max_body_bytes' ) - self::BODY_MARGIN;
		if ( ! empty( $documents ) && ( count( $documents ) >= $this->batch_size() || $bytes + $size > $limit ) ) {
			return false;
		}
		$documents[] = $document;
		$bytes      += $size;
		return true;
	}

	/**
	 * Build a /documents request from pending pushes. Posts that are no
	 * longer eligible are turned into deletes.
	 *
	 * @since   0.1.0
	 * @param   array $pushes  external_id => event.
	 * @return  array|null
	 */
	private function build_push_request( array $pushes ) {
		$max_text  = SAMOMIND()->connection->get_limit( 'max_text_chars' );
		$documents = array();
		$events    = array();
		$bytes     = 0;
		$demoted   = array();

		foreach ( $pushes as $external_id => $event ) {
			$document = SAMOMIND()->content->get_document( (int) $external_id, $max_text );
			if ( null === $document ) {
				$demoted[ $external_id ] = $event;
				continue;
			}
			if ( ! $this->add_to_batch( $documents, $bytes, $document ) ) {
				break;
			}
			$events[ $external_id ] = $event;
		}

		if ( ! empty( $demoted ) ) {
			$queue = $this->get_queue();
			foreach ( $demoted as $external_id => $event ) {
				$queue['items'][ $external_id ] = array(
					'op'    => 'delete',
					'event' => 'delete' . substr( $event, strpos( $event, ':' ) ),
				);
			}
			$this->save_queue( $queue );
		}

		if ( empty( $documents ) ) {
			return null;
		}

		return array(
			'type'     => 'push',
			'endpoint' => '/documents',
			'payload'  => array(
				'source_id' => SAMOMIND()->connection->get_source_id(),
				'documents' => $documents,
			),
			'key'      => self::batch_key( 'push', $events ),
			'events'   => $events,
		);
	}

	/**
	 * Build the next full sync request: begin, a batch in post id order,
	 * or complete.
	 *
	 * @since   0.1.0
	 * @param   array $state  Sync state.
	 * @return  array
	 */
	private function build_sync_request( array $state ) {
		$full      = $state['full'];
		$source_id = SAMOMIND()->connection->get_source_id();

		if ( ! $full['begun'] ) {
			return array(
				'type'     => 'sync_begin',
				'endpoint' => '/sync',
				'payload'  => array(
					'source_id' => $source_id,
					'action'    => 'begin',
				),
				'key'      => SAMOMIND_Client::event_key( 'sync-begin', array( $full['run_id'] ) ),
			);
		}

		$content   = SAMOMIND()->content;
		$max_text  = SAMOMIND()->connection->get_limit( 'max_text_chars' );
		$documents = array();
		$bytes     = 0;
		$cursor    = (int) $full['cursor'];
		$full_up   = false;

		do {
			$ids = $content->get_candidate_ids( $cursor, self::SCAN_CHUNK );
			foreach ( $ids as $id ) {
				$document = $content->get_document( $id, $max_text );
				if ( null !== $document && ! $this->add_to_batch( $documents, $bytes, $document ) ) {
					$full_up = true;
					break;
				}
				$cursor = $id;
			}
		} while ( ! $full_up && ! empty( $ids ) );

		if ( empty( $documents ) ) {
			return array(
				'type'     => 'sync_complete',
				'endpoint' => '/sync',
				'payload'  => array(
					'source_id' => $source_id,
					'action'    => 'complete',
				),
				'key'      => SAMOMIND_Client::event_key( 'sync-complete', array( $full['run_id'] ) ),
			);
		}

		return array(
			'type'     => 'sync_batch',
			'endpoint' => '/documents',
			'payload'  => array(
				'source_id'   => $source_id,
				'documents'   => $documents,
				'sync_cursor' => (string) $cursor,
			),
			'key'      => SAMOMIND_Client::event_key( 'sync', array( $full['run_id'], (string) $full['cursor'], (string) $cursor ) ),
			'cursor'   => $cursor,
		);
	}

	/**
	 * Build the next reconcile page (contract 5.6): entries after the
	 * previous page's last id, in byte order.
	 *
	 * @since   0.1.0
	 * @param   array $state  Sync state.
	 * @return  array
	 */
	private function build_reconcile_request( array $state ) {
		$reconcile = $state['reconcile'];
		$after     = (string) $reconcile['after'];
		$page_size = SAMOMIND()->connection->get_limit( 'max_reconcile_entries' );

		$entries = array();
		$more    = false;
		foreach ( SAMOMIND()->content->get_inventory() as $pair ) {
			if ( '' !== $after && strcmp( $pair[0], $after ) <= 0 ) {
				continue;
			}
			if ( count( $entries ) >= $page_size ) {
				$more = true;
				break;
			}
			$entries[] = array(
				'external_id' => $pair[0],
				'modified_at' => $pair[1],
			);
		}

		$last = empty( $entries ) ? '' : $entries[ count( $entries ) - 1 ]['external_id'];

		return array(
			'type'      => 'reconcile',
			'endpoint'  => '/reconcile',
			'payload'   => array(
				'source_id'   => SAMOMIND()->connection->get_source_id(),
				'range_start' => '' === $after ? null : $after,
				'range_end'   => $more ? $last : null,
				'entries'     => $entries,
			),
			'key'       => SAMOMIND_Client::event_key( 'reconcile', array( $reconcile['run_id'], $after ) ),
			'range_end' => $more ? $last : '',
		);
	}

	/**
	 * Record a request that the API applied.
	 *
	 * @since   0.1.0
	 * @param   array      $request  Output of next_request().
	 * @param   array|null $result   Response, null when the API said the
	 *                               request had already been applied.
	 * @return  void
	 */
	private function apply( array $request, $result ) {
		if ( 'delete' === $request['type'] || 'push' === $request['type'] ) {
			$this->remove_from_queue( $request['events'] );
			if ( 'push' === $request['type'] && is_array( $result ) ) {
				$this->note_refusals( $result );
			}
			return;
		}

		$state = $this->get_state();

		if ( 'sync_begin' === $request['type'] ) {
			$state['full']['begun'] = true;
			// 5.6: resume after the cursor the server stored for an interrupted sync.
			if ( is_array( $result ) && isset( $result['cursor'] ) && ctype_digit( (string) $result['cursor'] ) ) {
				$state['full']['cursor'] = (int) $result['cursor'];
			}
		} elseif ( 'sync_batch' === $request['type'] ) {
			$state['full']['cursor'] = (int) $request['cursor'];
			$state['full']['sent']  += count( $request['payload']['documents'] );
			if ( is_array( $result ) ) {
				$counts                      = self::count_results( $result );
				$state['full']['accepted']  += $counts['queued'];
				$state['full']['unchanged'] += $counts['unchanged'];
				$state['full']['refused']   += $counts['refused'];
				if ( $counts['plan_page_cap'] > 0 ) {
					// Stop and show the message (the plan's page cap was reached).
					$state['full']['state']       = 'stopped';
					$state['full']['finished_at'] = time();
					$state['full']['message']     = self::page_cap_message();
				}
			}
		} elseif ( 'sync_complete' === $request['type'] ) {
			$state['full']['state']       = 'complete';
			$state['full']['finished_at'] = time();
			$state['batch_limit']         = 0;
			$this->save_state( $state );
			// 5.6: reconcile after a first sync.
			$this->request_reconcile( 'sync-' . $state['full']['run_id'] );
			return;
		} elseif ( 'reconcile' === $request['type'] ) {
			if ( is_array( $result ) ) {
				$missing = isset( $result['missing'] ) ? (array) $result['missing'] : array();
				$stale   = isset( $result['stale'] ) ? (array) $result['stale'] : array();
				$this->queue_reconcile_pushes( array_merge( $missing, $stale ), $state['reconcile']['run_id'] );
				$state['reconcile']['missing'] += count( $missing );
				$state['reconcile']['stale']   += count( $stale );
				$state['reconcile']['deleted'] += isset( $result['deleted'] ) ? count( (array) $result['deleted'] ) : 0;
			}
			if ( '' === $request['range_end'] ) {
				$state['reconcile']['state']       = 'complete';
				$state['reconcile']['finished_at'] = time();
			} else {
				$state['reconcile']['after'] = $request['range_end'];
			}
		}

		$this->save_state( $state );
	}

	/**
	 * Queue pushes the reconcile asked for. A push already queued stays; a
	 * delete queued for the same id is replaced, because the server lists
	 * the id as missing or stale only when this site still has it as
	 * included, and the push turns back into a delete at send time if the
	 * post is no longer eligible.
	 *
	 * @since   0.1.0
	 * @param   string[] $external_ids  Ids to push.
	 * @param   string   $run_id        Reconcile run id.
	 * @return  void
	 */
	private function queue_reconcile_pushes( array $external_ids, $run_id ) {
		if ( empty( $external_ids ) ) {
			return;
		}
		$queue = $this->get_queue();
		foreach ( $external_ids as $external_id ) {
			$external_id = (string) $external_id;
			if ( ! ctype_digit( $external_id ) ) {
				continue;
			}
			if ( isset( $queue['items'][ $external_id ] ) && 'push' === $queue['items'][ $external_id ]['op'] ) {
				continue;
			}
			$queue['items'][ $external_id ] = array(
				'op'    => 'push',
				'event' => 'reconcile:' . $external_id . ':' . $run_id,
			);
		}
		$this->save_queue( $queue );
	}

	/**
	 * Count per-item results of a /documents response.
	 *
	 * @since   0.1.0
	 * @param   array $result  Response.
	 * @return  array  queued, unchanged, refused, plan_page_cap.
	 */
	public static function count_results( array $result ) {
		$counts = array(
			'queued'        => 0,
			'unchanged'     => 0,
			'refused'       => 0,
			'plan_page_cap' => 0,
		);
		foreach ( isset( $result['results'] ) ? (array) $result['results'] : array() as $item ) {
			$status = isset( $item['status'] ) ? $item['status'] : '';
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			if ( 'refused' === $status && isset( $item['code'] ) && 'plan_page_cap' === $item['code'] ) {
				++$counts['plan_page_cap'];
			}
		}
		return $counts;
	}

	/**
	 * Message shown when the plan's page cap refuses documents.
	 *
	 * @since   0.1.0
	 * @return  string
	 */
	public static function page_cap_message() {
		return __( 'SamoMind refused more pages because your plan\'s page limit is reached (plan_page_cap). Sync stopped. Upgrade the plan or remove pages in the SamoMind dashboard, then click "Sync now".', 'samomind-ai-assistant' );
	}

	/**
	 * Keep a notice for refused event pushes.
	 *
	 * @since   0.1.0
	 * @param   array $result  /documents response.
	 * @return  void
	 */
	private function note_refusals( array $result ) {
		$counts = self::count_results( $result );
		if ( $counts['refused'] > 0 ) {
			$state           = $this->get_state();
			$state['notice'] = $counts['plan_page_cap'] > 0
				? self::page_cap_message()
				: __( 'SamoMind refused some updates (off_site_url: the page address is not on the connected site).', 'samomind-ai-assistant' );
			$this->save_state( $state );
		}
	}

	/**
	 * Remove sent items, unless a newer event replaced them meanwhile.
	 *
	 * @since   0.1.0
	 * @param   array $events  external_id => event that was sent.
	 * @return  void
	 */
	private function remove_from_queue( array $events ) {
		$queue = $this->get_queue();
		foreach ( $events as $external_id => $event ) {
			if ( isset( $queue['items'][ $external_id ] ) && $queue['items'][ $external_id ]['event'] === $event ) {
				unset( $queue['items'][ $external_id ] );
			}
		}
		$this->save_queue( $queue );
	}

	/**
	 * Give up on a request the API refused for good (a 4xx that a retry
	 * cannot fix). The error is already in last_error.
	 *
	 * @since   0.1.0
	 * @param   array    $request  Request.
	 * @param   WP_Error $error    Error.
	 * @return  void
	 */
	private function drop( array $request, $error ) {
		if ( 'delete' === $request['type'] || 'push' === $request['type'] ) {
			$this->remove_from_queue( $request['events'] );
			return;
		}
		$state = $this->get_state();
		$part  = 'reconcile' === $request['type'] ? 'reconcile' : 'full';

		$state[ $part ]['state']       = 'stopped';
		$state[ $part ]['finished_at'] = time();
		$state[ $part ]['message']     = SAMOMIND_Connection::describe( $error );
		$this->save_state( $state );
	}

	/**
	 * Halve the batch size after a 413.
	 *
	 * @since   0.1.0
	 * @param   array $request  Request that was too large.
	 * @return  bool  False when the batch was already a single document.
	 */
	private function shrink_batch( array $request ) {
		$count = isset( $request['payload']['documents'] ) ? count( $request['payload']['documents'] ) : 0;
		if ( $count <= 1 ) {
			return false;
		}
		$state                = $this->get_state();
		$state['batch_limit'] = max( 1, (int) floor( $count / 2 ) );
		$this->save_state( $state );
		return true;
	}

	/**
	 * Delay before the next attempt (contract 5.7).
	 *
	 * @since   0.1.0
	 * @param   int $attempts     Failed attempts so far, at least 1.
	 * @param   int $retry_after  Retry-After seconds, 0 when absent.
	 * @return  int
	 */
	public static function retry_delay( $attempts, $retry_after ) {
		if ( $retry_after > 0 ) {
			return (int) $retry_after;
		}
		$index = max( 0, (int) $attempts - 1 );
		return isset( self::BACKOFF[ $index ] ) ? self::BACKOFF[ $index ] : self::BACKOFF_MAX;
	}

	/**
	 * Record a failed attempt and schedule the retry.
	 *
	 * @since   0.1.0
	 * @param   WP_Error $error  Retryable error.
	 * @return  void
	 */
	private function schedule_retry( $error ) {
		$data  = (array) $error->get_error_data();
		$queue = $this->get_queue();

		++$queue['retry']['attempts'];
		$delay                     = self::retry_delay( $queue['retry']['attempts'], isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0 );
		$queue['retry']['next_at'] = time() + $delay;
		$this->save_queue( $queue );

		SAMOMIND()->scheduler->schedule_worker( $delay );
	}

	/**
	 * Reset the retry state after a success.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	private function clear_retry() {
		$queue = $this->get_queue();
		if ( $queue['retry']['attempts'] > 0 || $queue['retry']['next_at'] > 0 ) {
			$queue['retry'] = array(
				'attempts' => 0,
				'next_at'  => 0,
			);
			$this->save_queue( $queue );
		}
		SAMOMIND()->connection->clear_failure();
	}

	/**
	 * Take the worker lock (one request in flight per site, 5.7).
	 *
	 * @since   0.1.0
	 * @return  bool
	 */
	private function lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$taken = (int) get_option( self::LOCK_OPTION, 0 );
		if ( time() - $taken > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Release the worker lock.
	 *
	 * @since   0.1.0
	 * @return  void
	 */
	private function unlock() {
		delete_option( self::LOCK_OPTION );
	}
}

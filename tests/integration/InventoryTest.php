<?php
/**
 * The reconcile list (get_inventory): the same entries as judging every post
 * one by one, memory that does not grow with the shop, and no flush of the
 * object cache.
 *
 * @package SAMOMIND
 */

use PHPUnit\Framework\TestCase;

/**
 * An object cache that counts flushes.
 */
class SAMOMIND_Counting_Cache extends WP_Object_Cache {

	/**
	 * Calls of flush() that did not come from flush_runtime().
	 *
	 * @var int
	 */
	public $flushes = 0;

	/**
	 * Calls of flush_runtime(), which only clears this request's copy.
	 *
	 * @var int
	 */
	public $runtime_flushes = 0;

	private $in_runtime = false;

	public function flush() {
		if ( ! $this->in_runtime ) {
			++$this->flushes;
		}
		return parent::flush();
	}

	public function flush_runtime() {
		++$this->runtime_flushes;
		$this->in_runtime = true;
		$result           = parent::flush_runtime();
		$this->in_runtime = false;
		return $result;
	}
}

class InventoryTest extends TestCase {

	/**
	 * Posts made by a test.
	 *
	 * @var int[]
	 */
	private $ids = array();

	protected function setUp(): void {
		parent::setUp();
		$this->ids = array();
		update_option( 'samomind_post_types', array( 'post', 'page', 'product' ), false );
		remove_all_filters( 'SAMOMIND/content/excluded_ids' );
	}

	protected function tearDown(): void {
		foreach ( $this->ids as $id ) {
			wp_delete_post( $id, true );
		}
		remove_all_filters( 'SAMOMIND/content/excluded_ids' );
		delete_option( 'samomind_post_types' );
		parent::tearDown();
	}

	private function make( $type, $status = 'publish', array $extra = array() ) {
		$id = wp_insert_post(
			array_merge(
				array(
					'post_title'   => 'Inventory ' . $type . ' ' . count( $this->ids ),
					'post_content' => str_repeat( 'Some text for the item. ', 60 ),
					'post_status'  => $status,
					'post_type'    => $type,
				),
				$extra
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->ids[] = $id;
		return $id;
	}

	private function product( $visibility_terms = array(), $status = 'publish' ) {
		$id = $this->make( 'product', $status );
		foreach ( array( '_price', '_regular_price', '_sku', '_stock_status', '_weight' ) as $key ) {
			update_post_meta( $id, $key, str_repeat( 'm', 60 ) );
		}
		if ( ! empty( $visibility_terms ) ) {
			wp_set_object_terms( $id, $visibility_terms, 'product_visibility' );
		}
		return $id;
	}

	/**
	 * The list as it was built before: every post loaded, judged by is_eligible(),
	 * a product's visibility read from the WooCommerce product itself.
	 */
	private function reference_inventory() {
		$content = SAMOMIND()->content;
		$entries = array();
		$after   = 0;
		do {
			$ids   = $content->get_candidate_ids( $after, 500 );
			$found = count( $ids );
			foreach ( $ids as $id ) {
				$post  = get_post( $id );
				$after = $id;
				if ( ! $post instanceof WP_Post ) {
					continue;
				}
				$data = array(
					'id'        => (int) $post->ID,
					'post_type' => $post->post_type,
					'status'    => $post->post_status,
					'password'  => $post->post_password,
				);
				if ( 'product' === $post->post_type ) {
					$data['product'] = array( 'catalog_visibility' => (string) wc_get_product( $id )->get_catalog_visibility() );
				}
				if ( SAMOMIND_Content::is_eligible_data( $data, $content->get_post_types(), $content->get_excluded_ids() ) ) {
					$entries[ (string) $id ] = SAMOMIND_Content::rfc3339( SAMOMIND_Content::modified_gmt( $post ) );
				}
			}
		} while ( 500 === $found );
		return SAMOMIND_Content::sort_by_external_id( $entries );
	}

	public function test_the_list_is_the_same_as_judging_every_post_one_by_one() {
		$included = array(
			$this->make( 'page' ),
			$this->make( 'post' ),
			$this->product(),
			$this->product( array( 'exclude-from-search' ) ),
			$this->product( array( 'exclude-from-catalog' ) ),
		);
		$left_out = array(
			'a draft post'           => $this->make( 'post', 'draft' ),
			'a private page'         => $this->make( 'page', 'private' ),
			'a protected page'       => $this->make( 'page', 'publish', array( 'post_password' => 'secret' ) ),
			'a draft product'        => $this->product( array(), 'draft' ),
			'a hidden product'       => $this->product( array( 'exclude-from-catalog', 'exclude-from-search' ) ),
			'an excluded page'       => $this->make( 'page' ),
			'a post type not synced' => $this->make( 'nav_menu_item', 'publish' ),
		);
		add_filter(
			'SAMOMIND/content/excluded_ids',
			function () use ( $left_out ) {
				return array( $left_out['an excluded page'] );
			}
		);
		// A post whose modified time is empty falls back to its publish time.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '0000-00-00 00:00:00', 'post_date_gmt' => '2026-03-04 05:06:07' ), array( 'ID' => $included[0] ) ); // phpcs:ignore
		clean_post_cache( $included[0] );

		$content = SAMOMIND()->content;
		$entries = $content->get_inventory();
		$listed  = array_column( $entries, 0 );

		$this->assertSame( $this->reference_inventory(), $entries, 'The same entries, the same times, the same order.' );
		foreach ( $included as $id ) {
			$this->assertContains( (string) $id, $listed );
			$this->assertTrue( $content->is_eligible( get_post( $id ) ), 'A push of it is eligible too.' );
		}
		foreach ( $left_out as $name => $id ) {
			$this->assertNotContains( (string) $id, $listed, $name );
			$this->assertFalse( $content->is_eligible( get_post( $id ) ), $name . ': a push of it is refused too.' );
		}
		$by_id = array_column( $entries, 1, 0 );
		$this->assertSame( '2026-03-04T05:06:07+00:00', $by_id[ (string) $included[0] ] );
		$sorted = $listed;
		usort( $sorted, 'strcmp' );
		$this->assertSame( $sorted, $listed, 'Byte order.' );
	}

	public function test_every_visibility_gives_the_same_answer_as_the_woocommerce_product() {
		$content = SAMOMIND()->content;
		$cases   = array(
			'visible' => array(),
			'catalog' => array( 'exclude-from-search' ),
			'search'  => array( 'exclude-from-catalog' ),
			'hidden'  => array( 'exclude-from-catalog', 'exclude-from-search' ),
		);
		foreach ( $cases as $expected => $terms ) {
			$id = $this->product( $terms );
			$this->assertSame( $expected, $content->product_visibilities( array( $id ) )[ $id ] );
			$this->assertSame( $expected, wc_get_product( $id )->get_catalog_visibility() );
		}
	}

	/**
	 * Peak memory of one inventory, from an empty cache.
	 */
	private function inventory_peak() {
		wp_cache_flush();
		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();
		$count  = count( SAMOMIND()->content->get_inventory() );
		return array( memory_get_peak_usage() - $before, $count );
	}

	public function test_memory_does_not_grow_with_the_number_of_products() {
		if ( ! function_exists( 'memory_reset_peak_usage' ) ) {
			$this->markTestSkipped( 'Needs PHP 8.2 for memory_reset_peak_usage().' );
		}
		for ( $i = 0; $i < 300; $i++ ) {
			$this->product();
		}
		list( $small, $count_small ) = $this->inventory_peak();
		for ( $i = 0; $i < 600; $i++ ) {
			$this->product();
		}
		list( $large, $count_large ) = $this->inventory_peak();

		$this->assertGreaterThanOrEqual( $count_small + 600, $count_large );
		$per_product = ( $large - $small ) / ( $count_large - $count_small );
		// Loading every product grew about 6 KB or more per product; plain rows grow only by the list itself (a few hundred bytes).
		$this->assertLessThan( 2048, $per_product, sprintf( 'Memory grew %d bytes per product (%d at %d, %d at %d).', $per_product, $small, $count_small, $large, $count_large ) );
	}

	public function test_the_list_never_flushes_the_object_cache_and_the_runtime_clear_is_the_only_flush_there_is() {
		global $wp_object_cache;
		$this->product();
		$this->product( array( 'exclude-from-catalog' ) );
		$real                  = $wp_object_cache;
		$counting              = new SAMOMIND_Counting_Cache();
		$wp_object_cache       = $counting; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a counting cache for this test.
		try {
			$entries = SAMOMIND()->content->get_inventory();
			$this->assertNotEmpty( $entries );
			$this->assertSame( 0, $counting->flushes, 'Building the list flushes nothing.' );

			// WordPress' own cache has no persistent copy, and its "runtime" clear is its flush: the one call that may reach it.
			SAMOMIND_Content::release_runtime_cache();
			$this->assertSame( wp_cache_supports( 'flush_runtime' ) ? 1 : 0, $counting->flushes );
		} finally {
			$wp_object_cache = $real; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}
}

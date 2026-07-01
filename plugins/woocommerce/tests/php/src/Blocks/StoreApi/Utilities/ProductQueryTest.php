<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Blocks\StoreApi\Utilities;

use Automattic\WooCommerce\StoreApi\Utilities\ProductQuery;
use Automattic\WooCommerce\Tests\Blocks\Helpers\FixtureData;

/**
 * Unit tests for the ProductQuery::get_last_modified() caching behavior.
 */
class ProductQueryTest extends \WC_Unit_Test_Case {

	/**
	 * @var ProductQuery
	 */
	private ProductQuery $product_query;

	/**
	 * Setup test data. Called before every test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->product_query = new ProductQuery();
		wp_cache_delete( 'last_modified', 'wc_products' );
	}

	/**
	 * Get a Store API products request with the defaults needed by ProductQuery.
	 *
	 * @param array $params Request parameter overrides.
	 * @return \WP_REST_Request
	 */
	private function get_products_request( array $params = array() ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/wc/store/v1/products' );
		$params  = wp_parse_args(
			$params,
			array(
				'offset'         => 0,
				'order'          => 'asc',
				'orderby'        => 'date',
				'page'           => 1,
				'include'        => array(),
				'exclude'        => array(),
				'per_page'       => 10,
				'parent'         => array(),
				'parent_exclude' => array(),
				'search'         => '',
				'slug'           => '',
				'attributes'     => array(),
				'featured'       => null,
				'on_sale'        => null,
				'rating'         => null,
				'related'        => null,
			)
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * @testdox prepare_objects_query stores on_sale as an internal query var without materializing sale IDs.
	 */
	public function test_prepare_objects_query_uses_on_sale_query_var_without_sale_ids(): void {
		set_transient( 'wc_products_onsale', array( 101, 202, 303 ), DAY_IN_SECONDS );

		try {
			$args = $this->product_query->prepare_objects_query(
				$this->get_products_request(
					array(
						'on_sale' => true,
					)
				)
			);
		} finally {
			delete_transient( 'wc_products_onsale' );
		}

		$this->assertSame( array(), $args['post__in'] );
		$this->assertSame( array(), $args['post__not_in'] );
		$this->assertTrue( $args['store_api_on_sale'] );
	}

	/**
	 * @testdox prepare_objects_query keeps explicit excludes when on_sale is false.
	 */
	public function test_prepare_objects_query_keeps_excludes_when_on_sale_is_false(): void {
		set_transient( 'wc_products_onsale', array( 101, 202, 303 ), DAY_IN_SECONDS );

		try {
			$args = $this->product_query->prepare_objects_query(
				$this->get_products_request(
					array(
						'exclude' => array( 404 ),
						'on_sale' => false,
					)
				)
			);
		} finally {
			delete_transient( 'wc_products_onsale' );
		}

		$this->assertSame( array( 404 ), $args['post__not_in'] );
		$this->assertFalse( $args['store_api_on_sale'] );
	}

	/**
	 * @testdox prepare_objects_query preserves include ordering while applying on_sale as a SQL clause.
	 */
	public function test_prepare_objects_query_preserves_include_ordering_with_on_sale_filter(): void {
		set_transient( 'wc_products_onsale', array( 101, 202, 303 ), DAY_IN_SECONDS );

		try {
			$args = $this->product_query->prepare_objects_query(
				$this->get_products_request(
					array(
						'include' => array( 606, 505 ),
						'on_sale' => true,
						'orderby' => 'include',
					)
				)
			);
		} finally {
			delete_transient( 'wc_products_onsale' );
		}

		$this->assertSame( array( 606, 505 ), $args['post__in'] );
		$this->assertSame( 'post__in', $args['orderby'] );
		$this->assertTrue( $args['store_api_on_sale'] );
	}

	/**
	 * @testdox add_query_clauses filters on-sale products through the lookup table.
	 */
	public function test_add_query_clauses_filters_on_sale_products_using_lookup_table(): void {
		$wp_query = new \WP_Query();
		$wp_query->set( 'store_api_on_sale', true );

		$args = $this->product_query->add_query_clauses(
			array(
				'join'  => '',
				'where' => '',
			),
			$wp_query
		);

		$this->assertStringContainsString( 'wc_product_meta_lookup', $args['join'] );
		$this->assertStringContainsString( 'wc_product_meta_lookup.onsale = 1', $args['where'] );
		$this->assertStringContainsString( 'on_sale_variations.post_parent', $args['where'] );
		$this->assertStringContainsString( '.ID IN (', $args['where'] );
	}

	/**
	 * @testdox add_query_clauses treats missing lookup rows as not on sale when on_sale is false.
	 */
	public function test_add_query_clauses_filters_not_on_sale_products_using_lookup_table(): void {
		$wp_query = new \WP_Query();
		$wp_query->set( 'store_api_on_sale', false );

		$args = $this->product_query->add_query_clauses(
			array(
				'join'  => '',
				'where' => '',
			),
			$wp_query
		);

		$this->assertStringContainsString( 'wc_product_meta_lookup', $args['join'] );
		$this->assertStringContainsString( 'wc_product_meta_lookup.onsale IS NULL OR wc_product_meta_lookup.onsale = 0', $args['where'] );
		$this->assertStringContainsString( 'on_sale_variations.post_parent', $args['where'] );
		$this->assertStringContainsString( '.ID NOT IN (', $args['where'] );
	}

	/**
	 * @testdox get_last_modified returns null when no products exist.
	 */
	public function test_get_last_modified_returns_null_when_no_products(): void {
		global $wpdb;

		// Temporarily remove all product posts to test the null case.
		$original_posts = $wpdb->get_results(
			"SELECT ID, post_type, post_status FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')"
		);
		$wpdb->query(
			"UPDATE {$wpdb->posts} SET post_type = '_tmp_hidden' WHERE post_type IN ('product', 'product_variation')"
		);

		$result = $this->product_query->get_last_modified();

		// Restore original posts.
		$wpdb->query(
			"UPDATE {$wpdb->posts} SET post_type = REPLACE(post_type, '_tmp_hidden', '') WHERE post_type = '_tmp_hidden'"
		);

		// Restore correct post types from the saved data.
		foreach ( $original_posts as $post ) {
			$wpdb->update( $wpdb->posts, array( 'post_type' => $post->post_type ), array( 'ID' => $post->ID ) );
		}

		$this->assertNull( $result );
	}

	/**
	 * @testdox get_last_modified returns an HTTP-date formatted string.
	 */
	public function test_get_last_modified_returns_http_date_format(): void {
		$fixtures = new FixtureData();
		$fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		$result = $this->product_query->get_last_modified();

		$this->assertNotNull( $result );
		$this->assertStringEndsWith( 'GMT', $result );
		// Verify it parses as a valid date.
		$this->assertNotFalse( strtotime( $result ) );
	}

	/**
	 * @testdox get_last_modified caches the result in the object cache.
	 */
	public function test_get_last_modified_caches_result(): void {
		$fixtures = new FixtureData();
		$fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		// First call seeds the cache.
		$result = $this->product_query->get_last_modified();

		// Verify cache is populated.
		$cached = wp_cache_get( 'last_modified', 'wc_products' );
		$this->assertNotFalse( $cached );
		$this->assertSame( $result, $cached );
	}

	/**
	 * @testdox get_last_modified returns cached value without querying the database.
	 */
	public function test_get_last_modified_uses_cached_value(): void {
		$sentinel = 'Thu, 01 Jan 2099 00:00:00 GMT';
		wp_cache_set( 'last_modified', $sentinel, 'wc_products' );

		$result = $this->product_query->get_last_modified();

		$this->assertSame( $sentinel, $result );
	}

	/**
	 * @testdox Cache is invalidated when a product post cache is cleaned.
	 */
	public function test_cache_invalidated_on_product_change(): void {
		$fixtures = new FixtureData();
		$product  = $fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		// Seed the cache.
		$this->product_query->get_last_modified();
		$this->assertNotFalse( wp_cache_get( 'last_modified', 'wc_products' ) );

		// Simulate product change — clean_post_cache fires WC_Post_Data::invalidate_products_last_modified.
		clean_post_cache( $product->get_id() );

		$this->assertFalse( wp_cache_get( 'last_modified', 'wc_products' ) );
	}

	/**
	 * @testdox Cache is invalidated when a product variation post cache is cleaned.
	 */
	public function test_cache_invalidated_on_variation_change(): void {
		$fixtures = new FixtureData();
		$product  = $fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		// Create a variation post directly to avoid complex variable product setup.
		$variation_id = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_parent' => $product->get_id(),
				'post_status' => 'publish',
			)
		);

		// Seed the cache.
		$this->product_query->get_last_modified();
		$this->assertNotFalse( wp_cache_get( 'last_modified', 'wc_products' ) );

		// Clean variation post cache.
		clean_post_cache( $variation_id );

		$this->assertFalse( wp_cache_get( 'last_modified', 'wc_products' ) );
	}

	/**
	 * @testdox Cache is NOT invalidated when a non-product post cache is cleaned.
	 */
	public function test_cache_not_invalidated_on_non_product_change(): void {
		$fixtures = new FixtureData();
		$fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		// Seed the cache.
		$this->product_query->get_last_modified();
		$cached_value = wp_cache_get( 'last_modified', 'wc_products' );
		$this->assertNotFalse( $cached_value );

		// Create and clean a regular post.
		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Regular Post',
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		clean_post_cache( $post_id );

		$this->assertSame( $cached_value, wp_cache_get( 'last_modified', 'wc_products' ) );
	}

	/**
	 * @testdox get_last_modified re-seeds cache from DB after invalidation.
	 */
	public function test_get_last_modified_reseeds_after_invalidation(): void {
		$fixtures = new FixtureData();
		$product  = $fixtures->get_simple_product(
			array(
				'name'          => 'Test Product',
				'regular_price' => 10,
			)
		);

		// Seed the cache.
		$first_result = $this->product_query->get_last_modified();

		// Invalidate.
		clean_post_cache( $product->get_id() );

		// Next call should re-query DB and re-seed.
		$second_result = $this->product_query->get_last_modified();

		$this->assertNotNull( $second_result );
		$this->assertNotFalse( wp_cache_get( 'last_modified', 'wc_products' ) );
	}
}

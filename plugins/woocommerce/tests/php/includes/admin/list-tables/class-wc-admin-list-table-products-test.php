<?php
/**
 * Tests for Product List Tables in WooCommerce Admin.
 */

declare( strict_types = 1 );

require_once WC_ABSPATH . '/includes/admin/list-tables/class-wc-admin-list-table-products.php';

/**
 * WC Admin List Table Products test.
 */
class WC_Admin_List_Table_Products_Test extends WC_Unit_Test_Case {

	/**
	 * Test that product list table searches pass the default cap to the product data store.
	 */
	public function test_product_search_uses_default_result_limit() {
		$captured_limit = null;
		$pre_search     = function ( $results, $term, $type, $include_variations, $all_statuses, $limit ) use ( &$captured_limit ) {
			$captured_limit = $limit;
			return array( 123 );
		};

		add_filter( 'woocommerce_product_pre_search_products', $pre_search, 10, 6 );

		try {
			$query_vars = $this->filter_product_query_vars(
				array(
					's' => 'admin product search',
				)
			);
		} finally {
			remove_filter( 'woocommerce_product_pre_search_products', $pre_search, 10 );
		}

		$this->assertSame( 1000, $captured_limit );
		$this->assertSame( array( 123, 0 ), $query_vars['post__in'] );
		$this->assertTrue( $query_vars['product_search'] );
		$this->assertArrayNotHasKey( 's', $query_vars );
	}

	/**
	 * Test that product list table search result materialization can be capped with a filter.
	 */
	public function test_product_search_result_limit_is_filterable() {
		$search_term = 'adminsearchcap' . wp_rand();
		$products    = array(
			WC_Helper_Product::create_simple_product( true, array( 'name' => $search_term . ' 01' ) ),
			WC_Helper_Product::create_simple_product( true, array( 'name' => $search_term . ' 02' ) ),
			WC_Helper_Product::create_simple_product( true, array( 'name' => $search_term . ' 03' ) ),
		);
		$set_limit   = function ( $limit ) {
			return 2;
		};

		add_filter( 'woocommerce_admin_product_search_results_limit', $set_limit );

		try {
			$query_vars = $this->filter_product_query_vars(
				array(
					's' => $search_term,
				)
			);
		} finally {
			remove_filter( 'woocommerce_admin_product_search_results_limit', $set_limit );

			foreach ( $products as $product ) {
				WC_Helper_Product::delete_product( $product->get_id() );
			}
		}

		$this->assertCount( 3, $query_vars['post__in'] );
		$this->assertSame( 0, end( $query_vars['post__in'] ) );
		$this->assertNotContains( $products[2]->get_id(), $query_vars['post__in'] );
	}

	/**
	 * Test that returning 0 from the admin product search result limit filter disables the cap.
	 */
	public function test_product_search_result_limit_can_be_disabled() {
		$captured_limit = 'not called';
		$pre_search     = function ( $results, $term, $type, $include_variations, $all_statuses, $limit ) use ( &$captured_limit ) {
			$captured_limit = $limit;
			return array( 123 );
		};

		add_filter( 'woocommerce_admin_product_search_results_limit', '__return_zero' );
		add_filter( 'woocommerce_product_pre_search_products', $pre_search, 10, 6 );

		try {
			$this->filter_product_query_vars(
				array(
					's' => 'admin product search',
				)
			);
		} finally {
			remove_filter( 'woocommerce_admin_product_search_results_limit', '__return_zero' );
			remove_filter( 'woocommerce_product_pre_search_products', $pre_search, 10 );
		}

		$this->assertNull( $captured_limit );
	}

	/**
	 * Test that exact product ID searches are preserved when broad numeric results exceed the cap.
	 */
	public function test_product_search_result_limit_preserves_exact_product_id_searches() {
		$product    = WC_Helper_Product::create_simple_product();
		$set_limit  = function ( $limit ) {
			return 2;
		};
		$pre_search = function () {
			return array( 111111, 222222, 333333 );
		};

		add_filter( 'woocommerce_admin_product_search_results_limit', $set_limit );
		add_filter( 'woocommerce_product_pre_search_products', $pre_search, 10, 0 );

		try {
			$query_vars = $this->filter_product_query_vars(
				array(
					's' => (string) $product->get_id(),
				)
			);
		} finally {
			remove_filter( 'woocommerce_admin_product_search_results_limit', $set_limit );
			remove_filter( 'woocommerce_product_pre_search_products', $pre_search, 10 );
			WC_Helper_Product::delete_product( $product->get_id() );
		}

		$this->assertCount( 3, $query_vars['post__in'] );
		$this->assertContains( $product->get_id(), $query_vars['post__in'] );
		$this->assertSame( 0, end( $query_vars['post__in'] ) );
	}

	/**
	 * Runs the product list table query filters for product queries.
	 *
	 * @param array $query_vars Query vars.
	 * @return array
	 */
	private function filter_product_query_vars( array $query_vars ): array {
		$previous_typenow = $GLOBALS['typenow'] ?? null;
		$GLOBALS['typenow'] = 'product';

		try {
			$list_table = new WC_Admin_List_Table_Products();
			return $list_table->request_query( $query_vars );
		} finally {
			if ( null === $previous_typenow ) {
				unset( $GLOBALS['typenow'] );
			} else {
				$GLOBALS['typenow'] = $previous_typenow;
			}
		}
	}
}

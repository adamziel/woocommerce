<?php
/**
 * Class WC_Order_Item_Product_Data_Store file.
 *
 * @package WooCommerce\DataStores
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC Order Item Product Data Store
 *
 * @version  3.0.0
 */
class WC_Order_Item_Product_Data_Store extends Abstract_WC_Order_Item_Type_Data_Store implements WC_Object_Data_Store_Interface, WC_Order_Item_Product_Data_Store_Interface, WC_Order_Item_Type_Data_Store_Interface {

	/**
	 * Data stored in meta keys.
	 *
	 * @since 3.0.0
	 * @var array
	 */
	protected $internal_meta_keys = array( '_product_id', '_variation_id', '_qty', '_tax_class', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', '_line_tax_data' );

	/**
	 * Read/populate data properties specific to this order item.
	 *
	 * @since 3.0.0
	 * @param WC_Order_Item_Product $item Product order item object.
	 */
	public function read( &$item ) {
		parent::read( $item );
		$id = $item->get_id();
		$item->set_props(
			array(
				'product_id'   => get_metadata( 'order_item', $id, '_product_id', true ),
				'variation_id' => get_metadata( 'order_item', $id, '_variation_id', true ),
				'quantity'     => get_metadata( 'order_item', $id, '_qty', true ),
				'tax_class'    => get_metadata( 'order_item', $id, '_tax_class', true ),
				'subtotal'     => get_metadata( 'order_item', $id, '_line_subtotal', true ),
				'total'        => get_metadata( 'order_item', $id, '_line_total', true ),
				'taxes'        => get_metadata( 'order_item', $id, '_line_tax_data', true ),
			)
		);
		$item->set_object_read( true );
	}

	/**
	 * Saves an item's data to the database / item meta.
	 * Ran after both create and update, so $id will be set.
	 *
	 * @since 3.0.0
	 * @param WC_Order_Item_Product $item Product order item object.
	 */
	public function save_item_data( &$item ) {
		$id                = $item->get_id();
		$changes           = $item->get_changes();
		$meta_key_to_props = array(
			'_product_id'        => 'product_id',
			'_variation_id'      => 'variation_id',
			'_qty'               => 'quantity',
			'_tax_class'         => 'tax_class',
			'_line_subtotal'     => 'subtotal',
			'_line_subtotal_tax' => 'subtotal_tax',
			'_line_total'        => 'total',
			'_line_tax'          => 'total_tax',
			'_line_tax_data'     => 'taxes',
		);
		$props_to_update   = $this->is_creating_order_item() ? $meta_key_to_props : $this->get_props_to_update( $item, $meta_key_to_props, 'order_item' );

		if ( $this->is_creating_order_item() ) {
			$this->insert_item_data_meta( $id, $props_to_update, $item );
			return;
		}

		foreach ( $props_to_update as $meta_key => $prop ) {
			update_metadata( 'order_item', $id, $meta_key, $item->{"get_$prop"}( 'edit' ) );
		}
	}

	/**
	 * Inserts product order item data meta in one query for newly created items.
	 *
	 * @param int                   $item_id Order item ID.
	 * @param array<string,string>  $props_to_update Meta key to prop mapping.
	 * @param WC_Order_Item_Product $item Product order item object.
	 */
	private function insert_item_data_meta( int $item_id, array $props_to_update, WC_Order_Item_Product $item ): void {
		global $wpdb;

		if ( empty( $props_to_update ) ) {
			return;
		}

		$placeholders = array();
		$values       = array();

		foreach ( $props_to_update as $meta_key => $prop ) {
			$placeholders[] = '( %d, %s, %s )';
			$values[]       = $item_id;
			$values[]       = $meta_key;
			$values[]       = maybe_serialize( $item->{"get_$prop"}( 'edit' ) );
		}

		$query = "INSERT INTO {$wpdb->prefix}woocommerce_order_itemmeta ( order_item_id, meta_key, meta_value ) VALUES " . implode( ', ', $placeholders );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholder groups are generated above and values are passed separately.
				$query,
				$values
			)
		);

		wp_cache_delete( $item_id, 'order_item_meta' );
	}

	/**
	 * Get a list of download IDs for a specific item from an order.
	 *
	 * @since 3.0.0
	 * @param WC_Order_Item_Product $item Product order item object.
	 * @param WC_Order              $order Order object.
	 * @return array
	 */
	public function get_download_ids( $item, $order ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT download_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE user_email = %s AND order_key = %s AND product_id = %d ORDER BY permission_id",
				$order->get_billing_email(),
				$order->get_order_key(),
				$item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id()
			)
		);
	}
}

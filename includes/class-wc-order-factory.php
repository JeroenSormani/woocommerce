<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Order Factory Class
 *
 * The WooCommerce order factory creating the right order objects.
 *
 * @class 		WC_Order_Factory
 * @version		2.2.0
 * @package		WooCommerce/Classes
 * @category	Class
 * @author 		WooThemes
 */
class WC_Order_Factory {

	private $bulk_orders = array();

	public function get_order( $the_order = false ) {
		if ( $order = $this->get_order_from_bulk( $the_order ) ) {
			return $order;
		}

		return $this->get_order_object( $the_order );
	}

	/**
	 * Get order.
	 *
	 * @param bool $the_order (default: false)
	 * @return WC_Order|bool
	 */
	public function get_order_object( $the_order = false ) {
		global $post;

		if ( false === $the_order ) {
			$the_order = $post;
		} elseif ( is_numeric( $the_order ) ) {
			$the_order = get_post( $the_order );
		} elseif ( $the_order instanceof WC_Order ) {
			$the_order = get_post( $the_order->id );
		}

		if ( ! $the_order || ! is_object( $the_order ) ) {
			return false;
		}

		$order_id  = absint( $the_order->ID );
		$post_type = $the_order->post_type;

		if ( $order_type = wc_get_order_type( $post_type ) ) {
			$classname = $order_type['class_name'];
		} else {
			$classname = false;
		}

		// Filter classname so that the class can be overridden if extended.
		$classname = apply_filters( 'woocommerce_order_class', $classname, $post_type, $order_id, $the_order );

		if ( ! class_exists( $classname ) ) {
			return false;
		}

		return new $classname( $the_order );
	}

	public function get_order_from_bulk( $order_id ) {

		if ( ! $this->bulk_orders instanceof WP_Collection ) {
			return false;
		}

		if ( ! $order_col = $this->bulk_orders->where_first( 'ID', $order_id ) ) {
			return false;
		}

		// Initialize WC_Order object
		$order = $this->get_order_object( $order_id );

		// Pre load order items (+meta)
		$order_item_col = $order_col->index( 'order_items' );
		$order_itemmeta_col = $this->bulk_orders->get_relation_data( 'order_items' )->get_relation_data( 'meta' );
		$order_item_ids = $order_item_col->pluck( 'order_item_id' )->get();

		$order->items = $order_item_col->fields( 'order_item_id', 'order_item_name', 'order_item_type' );
		$order->order_item_meta = $order_itemmeta_col->indexes( $order_item_ids )->get();


		// Pre load order item meta in WP cache
		// @todo - possibly deprecate WP/WC caching for order item meta..?
		foreach ( $order_item_col->pluck( 'order_item_id' )->get() as $order_item_id ) {
			$cache_key       = WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'item_meta_array_' . $order_item_id;
			$item_meta_array = array();
			$metadata = $order_itemmeta_col->index( $order_item_id, array() )->fields( 'meta_value', 'meta_key', 'meta_id' )->order_by( 'meta_id' )->get();

			foreach ( $metadata as $metadata_row ) {
				$item_meta_array[ $metadata_row->meta_id ] = (object) array( 'key' => $metadata_row->meta_key, 'value' => $metadata_row->meta_value );
			}
			wp_cache_set( $cache_key, $item_meta_array, 'orders' );
		}

		// Pre load order comments
		$order->comments = $order_col->index( 'comments' );

		// Pre load total refunded
		// This is quite a complex one..
		$total_refund_value = $this->bulk_orders->where_first( 'ID', $order_id )->index( 'refunds' )->fields( 'meta' )->pluck( 'meta' )->pluck( 0 )->pluck( 'meta_value' )->sum();
		$order->total_refunded = $total_refund_value;

		return $order;

	}

	public function set_bulk_orders( $orders = array() ) {
		global $wpdb;

		// Posts
		$order_collection = new WP_Collection( $orders );
		$order_ids = $order_collection->pluck( 'ID' )->get();
		$order_id_list    = implode( ',', array_map( 'absint', $order_ids ) );

		// Postmeta
		$postmeta = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$order_id_list})" );
		$meta_col = new WP_Collection( $postmeta );
		$meta_col = $meta_col->group_by( 'post_id' )->get();
		foreach ( $meta_col as $k => $metas ) {
			$meta_col[ $k ] = wp_list_pluck( $metas, 'meta_value', 'meta_key' );
		}

		// Order items
		$order_items = $wpdb->get_results( "SELECT order_id, order_item_id, order_item_name, order_item_type FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ({$order_id_list})" );
		$order_items_col = new WP_Collection( $order_items );
		$order_item_id_list = implode( ',', array_map( 'absint', $order_items_col->pluck( 'order_item_id' )->get() ) );

		// Order item meta
		$order_item_meta = $wpdb->get_results( "SELECT meta_id, order_item_id, meta_key, meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id IN ({$order_item_id_list})" );
		$order_item_meta_col = new WP_Collection( $order_item_meta );
		$order_item_meta_col = $order_item_meta_col->group_by( 'order_item_id' )->get();
		// Because the values are already grouped by order item ID, we need to manually loop them
//		foreach ( $order_item_meta_col as $k => $metas ) {
//			$order_item_meta_col[ $k ] = wp_list_pluck( $metas, 'meta_value', 'meta_key' );
//		}

		// Order comments
		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );
		$comment_query = new WP_Comment_Query( array( 'post__in' => $order_ids, 'approve' => 'approve', 'type'  => '' ) );
		$comments = $comment_query->get_comments();
		$comments_collection = new WP_Collection( $comments );
//		$comments_collection = $comments_collection->group_by( 'comment_post_ID' );
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ) );


		// Refund orders
		$refund_query = new WP_Query( array(
			'post_type'       => 'shop_order_refund',
//			'fields'          => 'ids',
			'post_status'     => 'any',
			'posts_per_page'  => 999,
			'no_found_rows'   => true,
			'post_parent__in' => $order_ids,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
		) );
		$refunds = $refund_query->get_posts();
		$refund_collection = new WP_Collection( $refunds );

		// Refund meta relation
		if ( ! $refund_collection->is_empty() ) {
			$refund_id_list = implode( ',', array_map( 'absint', $refund_collection->pluck( 'ID' )->get() ) );
			$refund_meta = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$refund_id_list}) AND meta_key = '_refund_amount'" );

			// Refund relations
			$refund_collection->add_relation( 'meta', $refund_meta, 'post_id', 'ID' );
		}

		// Order item relations
		$order_items_col->add_relation( 'meta', $order_item_meta_col, true, 'order_item_id', 'many', false );

		// Order relations
		$order_collection->add_relation( 'meta', $meta_col, true, 'ID' );
		$order_collection->add_relation( 'comments', $comments_collection, 'comment_post_ID', 'ID' );
		$order_collection->add_relation( 'order_items', $order_items_col, 'order_id', 'ID' );
		$order_collection->add_relation( 'refunds', $refund_collection, 'post_parent', 'ID' );

		// Set bulk orders
		$this->bulk_orders = $order_collection;
//print_r( $order_collection );
//print_r( $order_collection->get_relation_data( 'order_items' )->get_relation_data( 'meta' ) );
//		die;
		return true;
	}
}

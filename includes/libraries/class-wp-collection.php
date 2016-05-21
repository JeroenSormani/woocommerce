<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


/**
 * Class WP_Collection
 */
class WP_Collection {

	/**
	 * @var array Holding the full collection list.
	 */
	protected $collection;

	/**
	 * @var array|string Fields to return
	 */
	protected $fields = '*';

	/**
	 * @var int Sorting order flag (SORT_ASC or SORT_DESC allowed).
	 */
	private $order = SORT_ASC;

	public function __construct( $collection ) {
		$this->collection = $collection;
	}


	/**************************************************************
	 * Helpers
	 *************************************************************/

	public function count() {
		return count( $this->collection );
	}

	public function is_empty() {
		return empty( $this->collection );
	}


	/**************************************************************
	 * Modifiers
	 *************************************************************/

	// Add
	// Delete
	// Pop
	// Push


	public function pluck( $field, $index = null ) {
		$this->collection = wp_list_pluck( $this->collection, $field, $index );
		return $this;
	}

	/**************************************************************
	 * Filters
	 *************************************************************/

	public function fields( $fields ) {

		if ( ! is_array( $fields ) ) {
			$fields = array( $fields );
		}
		$this->fields = $fields;

		// Allow fields to be set as extra func args
		if ( func_num_args() > 1 ) {
			foreach ( func_get_args() as $arg ) {
				if ( is_string( $arg ) ) {
					$this->fields[] = $arg;
				}
			}
		}

		return $this;
	}

	public function where( $field, $value = null ) {

		if ( is_array( $field ) ) {
			foreach ( $field as $k => $v ) {
//				if ( is_array( $v ) && is_numeric( $k ) ) {
//					return $this->or_where( $field );
//				} else {
					$this->where( $k, $v );
//				}
			}
			return $this;
		}

		foreach ( $this->collection as $key => $item ) {
			if ( ! $this->field_equals( $item, $field, $value ) ) {
				unset( $this->collection[ $key ] );
			}
		}

		return $this;
	}

//$where3 = array(
//	array(
//		'ID' => 263141,
//		'post_status' => 'publish',
//	),
//	array(
//		'ID' => 261566,
//	),
//);
	public function or_where( $rule_lists ) {

		$origin_collection = $this->collection;
		$new_collection = array();
		foreach ( $rule_lists as $rule_list_k => $rule_list ) {
			$this->collection = $origin_collection;
			$this->where( $rule_list );
			$new_collection = array_merge( $new_collection, $this->collection );
		}
		$this->collection = $new_collection;

		return $this;
	}

	public function where_in( $field, $list = null ) {
		if ( is_array( $field) ) {
			foreach ( $field as $k => $v ) {
				$this->where_in( $k, $v );
			}
			return $this;
		}

		foreach ( $this->collection as $key => $item ) {
			if ( ! $this->field_in( $item, $field, $list ) ) {
				unset( $this->collection[ $key ] );
			}
		}

		return $this;
	}

	// is loose
	protected function field_equals( $item, $field, $value  ) {
		return array_key_exists( $field, $item ) &&
		   ( ( is_object( $item ) && $item->$field == $value ) || ( is_array( $item ) && (array) $item[ $field ] == $value ) );
	}

	// Note: is case sensitive
	protected function field_in( $item, $field, $list ) {
		return array_key_exists( $field, $item ) &&
	       ( ( is_object( $item ) && in_array( $item->$field, $list ) ) || ( is_array( $item ) && in_array( $item[ $field ], $list ) ) );
	}

	// when using negative number it takes that amount from the back of the collection
	// limit( 5 ) - takes the first 5
	// limit( -5 ) - takes the last 5
	// limit( 5, 3 ) - takes 3 at the offset 5
	// limit -5, 4 ) - takes 4 at the offset of 5 at the end of the array
	public function limit( $offset_or_limit = 0, $limit = null ) {
		if ( $offset_or_limit < 0 && $limit == null ) {
			$this->slice( $offset_or_limit, abs( $offset_or_limit ) );
			return $this;
		}

		$offset = is_null( $limit ) ? 0 : $offset_or_limit;
		$limit = is_null( $limit ) ? $offset_or_limit : $limit;
		return $this->slice( $offset, $limit );
	}

	// $this->limit() alias.
	public function slice( $offset = 0, $limit = null ) {
		$this->collection = array_slice( $this->collection, $offset, $limit, true );
		return $this;
	}

	public function order( $asc_desc = 'ASC' ) {
		$this->order = $asc_desc == 'DESC' ? SORT_DESC : SORT_ASC;
		return $this;
	}

	public function order_by( $field, $asc_desc = null ) {
		$this->order = in_array( $asc_desc, array( SORT_DESC, SORT_ASC ) ) ? $asc_desc : $this->order;
		$this->collection = $this->array_orderby( $this->collection, $field, $this->order );
		return $this;
	}

	private function array_orderby() {
		$args  = func_get_args();
		$array = array_shift( $args );
		foreach ( $args as $n => $field ) {
			if ( is_string( $field ) ) {
				$tmp = array();
				foreach ( $array as $key => $row ) {
					$tmp[ $key ] = is_object( $row ) ? $row->$field : $row[ $field ];
				}
				$args[ $n ] = $tmp;
			}
		}
		$args[] = &$array;
		call_user_func_array( 'array_multisort', $args );

		return array_pop( $args );
	}

	public function group_by( $field ) {
		$result = array();
		foreach ( $this->collection as $k => $item ) {
			$key = is_object( $item ) ? $item->$field : $item[ $field ];
			$result[ $key ][] = $item;
		}
		$this->collection = $result;

		return $this;
	}

	/**************************************************************
	 * Returning
	 *************************************************************/

	public function to_array() {
		foreach ( $this->collection as $key => $item ) {
			$this->collection[ $key ] = (array) $item;
		}
		return $this->collection;
	}

	public function to_json() {
		return json_encode( $this->collection );
	}

	public function to_object() {
		foreach ( $this->collection as $key => $item ) {
			$this->collection[ $key ] = (object) $item;
		}
		return $this->collection;
	}

	public function get() {
		if ( $this->fields !== '*' ) {
			foreach ( $this->collection as $k => $item ) {
				$this->collection[ $k ] = array_intersect_key( (array) $item, array_combine( $this->fields, $this->fields ) );
			}
		}

		return $this->collection;
	}


	public function first() {
		return reset( $this->get() );
	}

	// Alias for $collection->where([..])->first();
	public function where_first( $field, $value = null ) {
		return $this->where( $field, $value )->first();
	}

	// Alias for $collection->where([..])->last();
	public function where_last( $field, $value = null ) {
		return $this->where( $field, $value )->last();
	}

	public function last() {
		return end( $this->get() );
	}

	public function get_var( $field, $default = null ) {
		if ( $this->count() > 1 ) {
			return $this->pluck( $field )->to_array();
		} else {
			return $this->first();
		}
	}

}
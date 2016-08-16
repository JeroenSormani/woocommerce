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

	private $relations = array();

	public function __construct( $collection = array() ) {
		$this->collection = $collection;
	}


	private function return_static( $data = array() ) {
		$return = new self( $data );
		$return->relations = $this->get_relations();
		return $return;
	}

	/**************************************************************
	 * Relations
	 *************************************************************/

	public function add_relation( $name, $items, $foreign_key, $local_key, $relation_type = 'many', $attach = true ) {
		$this->relations[ $name ] = array(
			'name'       => $name,
			'collection' => $items instanceof static ? $items : new static( $items ),
			'relation'   => array(
				'foreign_key' => $foreign_key,
				'type'        => $relation_type,
				'local_key'   => $local_key,
			),
		);
		if ( $attach ) {
			$this->attach_relation( $name );
		}

		return $this->return_static( $this->collection );
	}

	public function delete_relation( $name ) {
		if ( $this->has_relation( $name ) ) {
			unset( $this->relations[ $name ] );
		}
		return $this->return_static( $this->collection );
	}

	public function has_relation( $name ) {
		return ! empty( $this->relations[ $name ] );
	}

	public function get_relations() {
		return $this->relations;
	}

	public function get_relation( $name, $as_collection = true ) {
		if ( $this->has_relation( $name ) ) {
			return $as_collection ? $this->return_static( $this->relations[ $name ] ) : $this->relations[ $name ];
		}
		return $this->return_static();
	}

	public function get_relation_data( $name, $as_collection = true ) {
		$relation = $this->get_relation( $name, false );
		return $this->get_key( $relation, 'collection', array() );
	}

	public function attach_relations() {
		foreach ( $this->relations as $name => $relation ) {
			$this->attach_relation( $name );
		}
		return $this->return_static();
	}

	public function attach_relation( $name ) {
		if ( ! $relation = $this->get_relation( $name, false ) ) {
			return new static();
		}

		$foreign_key = $relation['relation']['foreign_key'];
		$local_key = $relation['relation']['local_key'];

		if ( $foreign_key === true ) { // true stands for the index (it may already be grouped)
			$data = $relation['collection'];
		} else {
			$data = $relation['collection']->group_by( $foreign_key );
		}

		foreach ( $this->collection as $k => $item ) {
			$local_key_value = $this->get_key( $item, $local_key );
			$return[ $k ] = $item;
			$return[ $k ]->{$relation['name']} = $data->index( $local_key_value, array() )->get();
		}
	}

	// Get key of either object, array, or none if not existent
	private function get_key( $var, $key, $default = null ) {
		if ( is_array( $var ) && isset( $var[ $key ] ) ) {
			return $var[ $key ];
		} elseif ( is_object( $var ) && isset( $var->$key ) ) {
			return $var->$key;
		}
		return $default;
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

	public function sum() {
		return array_sum( $this->collection );
	}

	/**************************************************************
	 * Modifiers
	 *************************************************************/

	// Add
	// Delete
	// Pop
	// Push


	public function pluck( $field, $index = null ) {
		return new static( wp_list_pluck( $this->collection, $field, $index ) );
	}

	/**************************************************************
	 * Filters
	 *************************************************************/

	// @todo - make fields sorted by the way they come in
	public function fields( $fields ) {

		if ( ! is_array( $fields ) ) {
			$fields = array( $fields );
		}

		// Allow fields to be set as extra func args
		if ( func_num_args() > 1 ) {
			foreach ( func_get_args() as $arg ) {
				if ( is_string( $arg ) ) {
					$fields[] = $arg;
				}
			}
		}

		$return = $this->collection;

		if ( $fields !== '*' ) {
			foreach ( $return as $k => $item ) {
				$return[ $k ] = $this->intersect_key( $item, $fields );
			}
		}

		return $this->return_static( $return );
	}

	private function intersect_key( $item, $fields ) {
		$field_keys = array_values( $fields );

		if ( is_object( $item ) ) {
			$intersected_keys = new stdClass;
			foreach ( $item as $k => $v ) {
				if ( in_array( $k, $field_keys ) ) {
					$intersected_keys->$k = $v;
				}
			}
		}

		if ( is_array( $item ) ) {
			$intersected_keys = array();
			foreach ( $item as $k => $v ) {
				if ( in_array( $k, $field_keys ) ) {
					$intersected_keys[ $k ] = $v;
				}
			}
		}

		return $intersected_keys;
	}

	public function where( $field, $value = null ) {

		$return = $this->collection;
		if ( is_array( $field ) ) {
			foreach ( $field as $k => $v ) {
				$return = $this->where( $k, $v );
			}
			return $return;
		}

		foreach ( $return as $key => $item ) {
			if ( ! $this->field_equals( $item, $field, $value ) ) {
				unset( $return[ $key ] );
			}
		}

		return new static( $return );
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
// @todo - refactor this
	public function or_where( $rule_lists ) {

		$origin_collection = $this->collection;
		$new_collection = array();
		foreach ( $rule_lists as $rule_list_k => $rule_list ) {
			$this->collection = $origin_collection;
			$this->where( $rule_list );
			$new_collection = array_merge( $new_collection, $this->collection );
		}
		$this->collection = $new_collection;

		return $this->return_static( $this->collection );
	}

	// @todo - test this
	public function where_in( $field, $list = null ) {
		$return = $this->collection;
//		if ( is_array( $field) ) {
//			foreach ( $field as $k => $v ) {
//				$return = $this->where_in( $k, $v );
//			}
//			return $this->return_static( $return );
//		}

		foreach ( $return as $key => $item ) {
			if ( ! $this->field_in( $item, $field, $list ) ) {
				unset( $return[ $key ] );
			}
		}

		return $this->return_static( $return );
	}

	// is loose
	protected function field_equals( $item, $field, $value  ) {
		return array_key_exists( $field, $item ) &&
		   ( ( is_object( $item ) && $item->{$field} == $value ) || ( is_array( $item ) && (array) $item[ $field ] == $value ) );
	}

	// Note: is case sensitive
	protected function field_in( $item, $field, $list ) {
		return array_key_exists( $field, $item ) &&
	       ( ( is_object( $item ) && in_array( $item->{$field}, $list ) ) || ( is_array( $item ) && in_array( $item[ $field ], $list ) ) );
	}

	// when using negative number it takes that amount from the back of the collection
	// limit( 5 ) - takes the first 5
	// limit( -5 ) - takes the last 5
	// limit( 5, 3 ) - takes 3 at the offset 5
	// limit -5, 4 ) - takes 4 at the offset of 5 at the end of the array
	public function limit( $offset_or_limit = 0, $limit = null ) {
		if ( $offset_or_limit < 0 && $limit == null ) {
			return $this->slice( $offset_or_limit, abs( $offset_or_limit ) );
		}

		$offset = is_null( $limit ) ? 0 : $offset_or_limit;
		$limit = is_null( $limit ) ? $offset_or_limit : $limit;
		return $this->slice( $offset, $limit );
	}

	// $this->limit() alias.
	public function slice( $offset = 0, $limit = null ) {
		$return = array_slice( $this->collection, $offset, $limit, true );
		return new static( $return );
	}

	public function order( $asc_desc = 'ASC' ) {
		$this->order = $asc_desc == 'DESC' ? SORT_DESC : SORT_ASC;
		return $this->return_static( $this->collection );
	}

	public function order_by( $field, $asc_desc = null ) {
		$this->order = in_array( $asc_desc, array( SORT_DESC, SORT_ASC ) ) ? $asc_desc : $this->order;
		$return = $this->array_orderby( $this->collection, $field, $this->order );
		return new static( $return );
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

		return new static( $result );
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
		$all = $this->collection;
		return $this->return_static( reset( $all ) );
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

	public function index( $index, $default = null ) {
		return $this->return_static( $this->get_key( $this->collection, $index, $default ) );
	}

	public function indexes( $indexes, $default = null ) {
		$return = array();
		foreach ( $this->collection as $k => $item ) {
			if ( in_array( $k, $indexes ) ) {
				$return[ $k ] = $item;
			}
		}
		return $this->return_static( $this->intersect_key( $return, $indexes ) );
	}

	// @todo?
	public function get_var( $field, $default = null ) {
		if ( $this->count() > 1 ) {
			return $this->pluck( $field )->to_array();
		} else {
			return $this->first();
		}
	}

}
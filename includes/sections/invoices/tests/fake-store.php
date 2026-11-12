<?php
/**
 * An in-memory Store: every method the section calls on the real one,
 * with no $wpdb. Rows are kept as the database would hold them and go
 * through the SAME pure code as the real Store — Store::invoice_columns()
 * and line_columns() on the way in, hydrate_invoice() and hydrate_line()
 * on the way out — so the shapes cannot differ.
 *
 * The two reads of WooCommerce's order-line tables are answered from the
 * in-memory orders of wc-stubs.php, by the same rule as the SQL: an
 * order contains a product when one of its lines is that product
 * (`_product_id`) or carries `_fh_invoice_product_id` for it; a title is
 * found on a line's name or in its `_fh_invoice_title`.
 *
 * Seams a test may use: $reads / $writes count the calls (to prove a
 * code path asked nothing), $fail_saves makes the next saves fail,
 * $orders_unreadable makes the order-line reads answer null ("unknown").
 *
 * @package FisHotel\Misc\Sections\Invoices
 */

namespace FisHotel\Misc\Sections\Invoices\Tests;

use FisHotel\Misc\Sections\Invoices\Invoices;
use FisHotel\Misc\Sections\Invoices\Rules;
use FisHotel\Misc\Sections\Invoices\Store;

// Helper, not a test: the CI job runs every tests/*.php with php, so exit
// cleanly when executed directly instead of as a require. (The namespace
// statement has to come first, hence the order.)
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Store {

	/**
	 * Raw invoice rows, id → row.
	 *
	 * @var array[]
	 */
	public $invoices = array();

	/**
	 * Raw line rows, id → row.
	 *
	 * @var array[]
	 */
	public $lines = array();

	public $next_invoice = 0;
	public $next_line    = 0;

	public $reads  = 0;
	public $writes = 0;

	/**
	 * How many of the next save_invoice() calls fail ("db").
	 *
	 * @var int
	 */
	public $fail_saves = 0;

	/**
	 * Let this many saves through first, then start failing.
	 *
	 * @var int
	 */
	public $fail_after = 0;

	/**
	 * Whether the order-line tables cannot be read.
	 *
	 * @var bool
	 */
	public $orders_unreadable = false;

	/**
	 * Called after every successful save_invoice() with ( id, fields ) —
	 * "meanwhile, something else happened" (an order was paid between a
	 * check and a write).
	 *
	 * @var callable|null
	 */
	public $after_save = null;

	/**
	 * The "now" the rows are stamped with (UTC).
	 *
	 * @var string
	 */
	public $now = '2026-11-03 15:00:00';

	/* ---------------------------------------------------------- */
	/*  Reads                                                      */
	/* ---------------------------------------------------------- */

	public function invoice( $id ) {
		$this->reads++;
		return isset( $this->invoices[ (int) $id ] ) ? Store::hydrate_invoice( $this->invoices[ (int) $id ] ) : null;
	}

	public function invoices_by_products( array $product_ids ) {
		$this->reads++;
		$ids = Store::ids( $product_ids );
		$out = array();
		foreach ( $this->invoices as $row ) {
			if ( ! empty( $row['product_id'] ) && in_array( (int) $row['product_id'], $ids, true ) ) {
				$out[] = Store::hydrate_invoice( $row );
			}
		}
		return $out;
	}

	public function invoice_by_product( $product_id ) {
		$rows = $this->invoices_by_products( array( (int) $product_id ) );
		return $rows ? $rows[0] : null;
	}

	public function invoices_paid_by_order( $order_id ) {
		$this->reads++;
		$out = array();
		foreach ( $this->invoices as $row ) {
			if ( 'paid' === $row['state'] && (int) $row['order_id'] === (int) $order_id ) {
				$out[] = Store::hydrate_invoice( $row );
			}
		}
		return $out;
	}

	private function filtered( array $filters ) {
		$states = isset( $filters['state'] ) ? array_values( array_intersect( (array) $filters['state'], Rules::STATES ) ) : array();
		$search = isset( $filters['search'] ) ? strtolower( trim( (string) $filters['search'] ) ) : '';
		$ids    = isset( $filters['ids'] ) ? Store::ids( $filters['ids'] ) : null;

		$out = array();
		foreach ( $this->invoices as $row ) {
			if ( $states && ! in_array( $row['state'], $states, true ) ) {
				continue;
			}
			if ( '' !== $search && false === strpos( strtolower( $row['handle'] . ' ' . $row['customer_name'] . ' ' . $row['title'] ), $search ) ) {
				continue;
			}
			if ( null !== $ids && ! in_array( (int) $row['id'], $ids, true ) ) {
				continue;
			}
			$out[] = $row;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return (int) $b['id'] - (int) $a['id'];
			}
		);
		return $out;
	}

	public function invoices( array $filters = array(), $limit = 30, $offset = 0 ) {
		$this->reads++;
		return array_map( array( Store::class, 'hydrate_invoice' ), array_slice( $this->filtered( $filters ), (int) $offset, (int) $limit ) );
	}

	public function count_invoices( array $filters = array() ) {
		$this->reads++;
		return count( $this->filtered( $filters ) );
	}

	public function state_counts() {
		$this->reads++;
		$out = array_fill_keys( Rules::STATES, 0 );
		foreach ( $this->invoices as $row ) {
			$out[ $row['state'] ]++;
		}
		return $out;
	}

	public function invoices_for_customer( array $who, $exclude_id = 0 ) {
		$this->reads++;
		$key   = isset( $who['handle_key'] ) ? (string) $who['handle_key'] : '';
		$cid   = ! empty( $who['customer_id'] ) ? (int) $who['customer_id'] : 0;
		$email = isset( $who['customer_email'] ) ? trim( (string) $who['customer_email'] ) : '';
		if ( '' === $key && 0 === $cid && '' === $email ) {
			return array();
		}

		$out = array();
		foreach ( $this->invoices as $row ) {
			if ( (int) $row['id'] === (int) $exclude_id ) {
				continue;
			}
			$hit = ( '' !== $key && $row['handle_key'] === $key )
				|| ( $cid > 0 && (int) $row['customer_id'] === $cid )
				|| ( '' !== $email && strtolower( (string) $row['customer_email'] ) === strtolower( $email ) );
			if ( $hit ) {
				$out[] = Store::hydrate_invoice( $row );
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['id'] - $a['id'];
			}
		);
		return $out;
	}

	public function invoices_published_between( $start_utc, $end_utc ) {
		$this->reads++;
		$out = array();
		foreach ( $this->invoices as $row ) {
			if ( ! in_array( $row['state'], array( 'published', 'paid' ), true ) || empty( $row['published_at'] ) ) {
				continue;
			}
			if ( $row['published_at'] >= $start_utc && $row['published_at'] < $end_utc ) {
				$out[] = Store::hydrate_invoice( $row );
			}
		}
		return $out;
	}

	public function handles( $term, $limit = 10 ) {
		$this->reads++;
		$key    = Rules::handle_key( $term );
		$latest = array();
		foreach ( $this->invoices as $row ) {
			if ( '' === $row['handle_key'] || ( '' !== $key && false === strpos( $row['handle_key'], $key ) ) ) {
				continue;
			}
			if ( ! isset( $latest[ $row['handle_key'] ] ) || (int) $row['id'] > (int) $latest[ $row['handle_key'] ]['id'] ) {
				$latest[ $row['handle_key'] ] = $row;
			}
		}
		ksort( $latest );

		$out = array();
		foreach ( array_slice( $latest, 0, (int) $limit ) as $row ) {
			$out[] = array(
				'handle'         => (string) $row['handle'],
				'forum_user_id'  => ! empty( $row['forum_user_id'] ) ? (int) $row['forum_user_id'] : null,
				'customer_id'    => ! empty( $row['customer_id'] ) ? (int) $row['customer_id'] : null,
				'customer_name'  => (string) $row['customer_name'],
				'customer_email' => (string) $row['customer_email'],
			);
		}
		return $out;
	}

	public function lines( $invoice_id ) {
		$map = $this->lines_for_invoices( array( (int) $invoice_id ) );
		return isset( $map[ (int) $invoice_id ] ) ? $map[ (int) $invoice_id ] : array();
	}

	public function lines_for_invoices( array $invoice_ids ) {
		$this->reads++;
		$ids = Store::ids( $invoice_ids );
		$out = array_fill_keys( $ids, array() );

		$rows = array_values( $this->lines );
		usort(
			$rows,
			static function ( $a, $b ) {
				return array( (int) $a['invoice_id'], (int) $a['sort'], (int) $a['id'] ) <=> array( (int) $b['invoice_id'], (int) $b['sort'], (int) $b['id'] );
			}
		);
		foreach ( $rows as $row ) {
			if ( isset( $out[ (int) $row['invoice_id'] ] ) ) {
				$out[ (int) $row['invoice_id'] ][] = Store::hydrate_line( $row );
			}
		}
		return $out;
	}

	public function stay_reservations( array $stay_ids, $exclude_invoice_id = 0 ) {
		$this->reads++;
		$ids = Store::ids( $stay_ids );
		$out = array();
		foreach ( $this->lines as $row ) {
			$invoice = isset( $this->invoices[ (int) $row['invoice_id'] ] ) ? $this->invoices[ (int) $row['invoice_id'] ] : null;
			if ( ! $invoice || (int) $invoice['id'] === (int) $exclude_invoice_id || ! in_array( $invoice['state'], array( 'draft', 'published' ), true ) ) {
				continue;
			}
			if ( 'fish' === $row['kind'] && ! empty( $row['stay_id'] ) && in_array( (int) $row['stay_id'], $ids, true ) ) {
				$out[ (int) $row['stay_id'] ] = ( isset( $out[ (int) $row['stay_id'] ] ) ? $out[ (int) $row['stay_id'] ] : 0 ) + (int) $row['qty'];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------- */
	/*  Writes                                                     */
	/* ---------------------------------------------------------- */

	public function save_invoice( $id, array $fields, $lines = null, $expected_version = null, $user_id = 0 ) {
		$this->writes++;
		$id   = (int) $id;
		$fail = static function ( $error ) use ( $id ) {
			return array(
				'ok'      => false,
				'id'      => $id,
				'version' => 0,
				'error'   => $error,
			);
		};

		if ( $this->fail_after > 0 ) {
			$this->fail_after--;
		} elseif ( $this->fail_saves > 0 ) {
			$this->fail_saves--;
			return $fail( 'db' );
		}

		list( $data ) = Store::invoice_columns( $fields );

		if ( $id <= 0 ) {
			$id  = ++$this->next_invoice;
			$row = array_merge(
				array(
					'id'             => $id,
					'product_id'     => null,
					'handle'         => '',
					'handle_key'     => '',
					'forum_user_id'  => null,
					'customer_id'    => null,
					'customer_name'  => '',
					'customer_email' => '',
					'state'          => 'draft',
					'shipping'       => 'premium',
					'shipping_slug'  => '',
					'flat_total'     => null,
					'total'          => 0,
					'title'          => '',
					'notes'          => '',
					'order_id'       => null,
					'version'        => 1,
					'created_by'     => (int) $user_id,
					'created_at'     => $this->now,
					'updated_by'     => (int) $user_id,
					'updated_at'     => $this->now,
					'published_by'   => null,
					'published_at'   => null,
					'paid_at'        => null,
				),
				$data
			);
		} else {
			if ( ! isset( $this->invoices[ $id ] ) ) {
				return $fail( 'missing' );
			}
			if ( null !== $expected_version && (int) $expected_version !== (int) $this->invoices[ $id ]['version'] ) {
				return $fail( 'conflict' );
			}
			$row               = array_merge( $this->invoices[ $id ], $data );
			$row['version']    = (int) $this->invoices[ $id ]['version'] + 1;
			$row['updated_by'] = (int) $user_id;
			$row['updated_at'] = $this->now;
		}

		$this->invoices[ $id ] = $row;

		if ( is_array( $lines ) ) {
			$existing = array();
			foreach ( $this->lines as $line ) {
				if ( (int) $line['invoice_id'] === $id ) {
					$existing[] = (int) $line['id'];
				}
			}
			$kept = array();
			foreach ( array_values( $lines ) as $line ) {
				list( $columns ) = Store::line_columns( $line, $id );
				$line_id         = ! empty( $line['id'] ) ? (int) $line['id'] : 0;
				if ( $line_id <= 0 || ! in_array( $line_id, $existing, true ) || in_array( $line_id, $kept, true ) ) {
					$line_id = ++$this->next_line;
				}
				$columns['id']           = $line_id;
				$this->lines[ $line_id ] = $columns;
				$kept[]                  = $line_id;
			}
			foreach ( array_diff( $existing, $kept ) as $gone ) {
				unset( $this->lines[ $gone ] );
			}
		}

		if ( is_callable( $this->after_save ) ) {
			call_user_func( $this->after_save, $id, $fields );
		}

		return array(
			'ok'      => true,
			'id'      => $id,
			'version' => (int) $row['version'],
			'error'   => '',
		);
	}

	/* ---------------------------------------------------------- */
	/*  WooCommerce's order lines (from wc-stubs.php's orders)     */
	/* ---------------------------------------------------------- */

	public function order_ids_for_products( array $product_ids ) {
		$this->reads++;
		if ( $this->orders_unreadable ) {
			return null;
		}

		$ids = Store::ids( $product_ids );
		$out = array_fill_keys( $ids, array() );

		$order_ids = array_keys( $GLOBALS['fh_wc']['orders'] );
		rsort( $order_ids );
		foreach ( $order_ids as $order_id ) {
			foreach ( $GLOBALS['fh_wc']['orders'][ $order_id ]->items as $item ) {
				$found = array();
				if ( $item instanceof \WC_Order_Item_Product ) {
					$found[] = (int) $item->product_id;
				}
				$found[] = (int) $item->get_meta( Invoices::ITEM_PRODUCT_META );
				foreach ( $found as $product_id ) {
					if ( isset( $out[ $product_id ] ) && ! in_array( (int) $order_id, $out[ $product_id ], true ) ) {
						$out[ $product_id ][] = (int) $order_id;
					}
				}
			}
		}
		return $out;
	}

	public function order_ids_for_invoice_title( $title_start, $limit = 100 ) {
		$this->reads++;
		if ( $this->orders_unreadable ) {
			return null;
		}
		$start = strtolower( trim( (string) $title_start ) );
		if ( '' === $start ) {
			return array();
		}

		$out = array();
		foreach ( $GLOBALS['fh_wc']['orders'] as $order_id => $order ) {
			foreach ( $order->items as $item ) {
				$by_name  = $item instanceof \WC_Order_Item_Product && 0 === strpos( strtolower( $item->name ), $start );
				$by_title = 0 === strpos( strtolower( (string) $item->get_meta( Invoices::ITEM_TITLE_META ) ), $start );
				if ( $by_name || $by_title ) {
					$out[] = (int) $order_id;
					break;
				}
			}
		}
		rsort( $out );
		return array_slice( $out, 0, (int) $limit );
	}
}

/**
 * A stand-in for the Tanks class: tank groups and a species list a test
 * fills in, or "the Clean Room is off".
 */
class Fake_Tanks {

	public $on = true;

	/**
	 * Tank groups, each as Tanks::group() gives them.
	 *
	 * @var array[]
	 */
	public $groups = array();

	/**
	 * Species rows: species_id, name, sci_name, price, product_id.
	 *
	 * @var array[]
	 */
	public $species = array();

	public function available() {
		return $this->on;
	}

	public function reason() {
		return $this->on ? '' : 'The Clean Room section is switched off.';
	}

	public function search( $term, $limit = 12 ) {
		$out = array();
		foreach ( $this->on ? $this->groups : array() as $group ) {
			if ( false !== stripos( $group['species'], (string) $term ) && $group['alive'] > 0 && ! empty( $group['open'] ) ) {
				$out[] = $group;
			}
		}
		return array_slice( $out, 0, (int) $limit );
	}

	public function group_by_id( $stay_id ) {
		foreach ( $this->on ? $this->groups : array() as $group ) {
			if ( (int) $group['stay_id'] === (int) $stay_id ) {
				return $group;
			}
		}
		return null;
	}

	public function species_search( $term, $limit = 12 ) {
		$out = array();
		foreach ( $this->on ? $this->species : array() as $row ) {
			if ( false !== stripos( $row['name'], (string) $term ) ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, (int) $limit );
	}

	public function species_price( $product_id, $name ) {
		foreach ( $this->on ? $this->species : array() as $row ) {
			if ( ( (int) $product_id > 0 && (int) $row['product_id'] === (int) $product_id ) || strtolower( $row['name'] ) === strtolower( (string) $name ) ) {
				return array(
					'price'      => $row['price'],
					'species_id' => (int) $row['species_id'],
				);
			}
		}
		return array(
			'price'      => null,
			'species_id' => 0,
		);
	}
}

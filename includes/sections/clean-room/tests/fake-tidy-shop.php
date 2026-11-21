<?php
/**
 * An in-memory shop for "Tidy up the sizes": the same seam the real site
 * implements (Sizes_Tidy_Shop), with invented terms, fish, prices and
 * rows held in arrays — so the plan, Apply, a run cut off half-way and
 * Undo all run headless. dump() gives everything a run may touch, to
 * compare before and after.
 *
 * Seams a test may use that the real site has no need for: $fuse (throw
 * after that many writes, as a timeout would cut a batch off), $calls
 * (what was called, in order), $loaded (how many products the last
 * step read) and $before_apply (called with a product's id just before
 * it is written — a shop that does not stand still).
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room\Tests;

use FisHotel\Misc\Sections\Clean_Room\Sizes;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy;
use FisHotel\Misc\Sections\Clean_Room\Sizes_Tidy_Shop;

// Helper, not a test: the CI lint job runs every tests/*.php with php, so
// exit cleanly when executed directly instead of as a require.
if ( ! empty( $_SERVER['SCRIPT_FILENAME'] ) && realpath( $_SERVER['SCRIPT_FILENAME'] ) === __FILE__ ) {
	exit( 0 );
}

class Fake_Tidy_Shop implements Sizes_Tidy_Shop {

	public $available = true;
	public $taxonomy  = 'pa_size';
	public $orderby   = 'menu_order';

	/** @var array[] slug → term_id, name, slug, order (int|null), description, meta. */
	public $terms = array();

	/** @var array[] id → product. */
	public $products = array();

	/** @var array<string,int> slug → past order lines. */
	public $orders = array();

	/** @var array<string,int> slug → uses by products in the trash. */
	public $trashed = array();

	/** @var array<string,array[]> store → id → row. */
	public $tables = array(
		'stays'          => array(),
		'order_lines'    => array(),
		'requests'       => array(),
		'lostfish_cases' => array(),
		'invoice_lines'  => array(),
		'box_shares'     => array(),
	);

	public $options = array();
	public $runs    = array();
	public $items   = array();
	public $locked  = false;
	public $flushed = 0;

	public $fuse   = 0;

	/** @var callable|null */
	public $before_apply = null;
	public $writes = 0;
	public $calls  = array();
	public $loaded = 0;

	private $next_id = 1000;

	/* ---- building a shop ----------------------------------------- */

	/**
	 * Add terms, in order: a label, or slug => label.
	 */
	public function add_terms( array $labels ) {
		foreach ( $labels as $slug => $label ) {
			$slug                 = is_string( $slug ) ? $slug : Sizes::slug( $label );
			$this->terms[ $slug ] = array(
				'term_id'     => ++$this->next_id,
				'name'        => htmlspecialchars( $label, ENT_QUOTES ),
				'slug'        => $slug,
				'order'       => count( $this->terms ),
				'description' => '',
				'meta'        => array( 'made' => 'by hand' ),
			);
		}
	}

	/**
	 * Add a variable product: variations as size slug => array( price, stock ) —
	 * stock null = not managed, in stock; 0 = out of stock; -1 = on backorder.
	 * A variation's own keys (status, attrs, sku, stock_status) may be given too.
	 */
	public function add_product( $title, array $variations, array $extra = array() ) {
		$pid     = ++$this->next_id;
		$product = array(
			'id'         => $pid,
			'title'      => $title,
			'status'     => 'publish',
			'fish'       => true,
			'sizes'      => array(),
			'default'    => '',
			'typed'      => null,
			'fill'       => array(),
			'variations' => array(),
		);
		foreach ( $variations as $slug => $row ) {
			$vid   = ++$this->next_id;
			$stock = array_key_exists( 1, $row ) ? $row[1] : null;
			$slug  = isset( $row['size'] ) ? (string) $row['size'] : (string) $slug;
			$product['variations'][ $vid ] = array(
				'id'           => $vid,
				'status'       => isset( $row['status'] ) ? $row['status'] : 'publish',
				'title'        => $title . ' - ' . $slug,
				'summary'      => 'pa_size: ' . $slug, // As an older WooCommerce wrote it.
				'attrs'        => isset( $row['attrs'] ) ? $row['attrs'] : array( $this->taxonomy => $slug ),
				'price'        => (string) ( $row[0] ?? '' ),
				'stock'        => null === $stock || $stock < 0 ? null : (int) $stock,
				'stock_status' => isset( $row['stock_status'] ) ? $row['stock_status'] : ( null === $stock ? 'instock' : ( $stock < 0 ? 'onbackorder' : ( $stock > 0 ? 'instock' : 'outofstock' ) ) ),
				'sku'          => isset( $row['sku'] ) ? $row['sku'] : 'SKU-' . $vid,
			);
			if ( isset( $product['variations'][ $vid ]['attrs'][ $this->taxonomy ] ) && ! in_array( $slug, $product['sizes'], true ) ) {
				$product['sizes'][] = $slug;
			}
		}
		$this->products[ $pid ] = array_merge( $product, $extra );
		return $pid;
	}

	/**
	 * The ids of a product's variations, in order.
	 */
	public function vids( $pid ) {
		return array_keys( $this->products[ $pid ]['variations'] );
	}

	/**
	 * Add a row to a store; returns its id.
	 */
	public function add_row( $store, array $row ) {
		$id                            = isset( $row['id'] ) ? (int) $row['id'] : count( $this->tables[ $store ] ) + 1;
		$this->tables[ $store ][ $id ] = array( 'id' => $id ) + $row;
		return $id;
	}

	/**
	 * Everything a run may touch.
	 */
	public function dump() {
		return array(
			'orderby'  => $this->orderby,
			'terms'    => $this->terms,
			'products' => $this->products,
			'tables'   => $this->tables,
			'options'  => $this->options,
		);
	}

	private function write( $what ) {
		$this->calls[] = $what;
		++$this->writes;
		if ( $this->fuse > 0 && $this->writes >= $this->fuse ) {
			$this->fuse = 0;
			throw new \RuntimeException( 'cut off' );
		}
	}

	/* ---- the seam ------------------------------------------------- */

	public function available() {
		return (bool) $this->available;
	}

	public function read_shop( $taxonomy ) {
		$terms = array();
		foreach ( $this->terms as $term ) {
			$terms[] = array(
				'term_id'    => $term['term_id'],
				'name'       => $term['name'],
				'slug'       => $term['slug'],
				'menu_order' => null === $term['order'] ? 0 : (int) $term['order'],
			);
		}
		$stores = array();
		foreach ( $this->tables as $store => $rows ) {
			foreach ( $rows as $row ) {
				$slug = (string) ( $row['size_slug'] ?? '' );
				if ( '' === $slug || ( 'invoice_lines' === $store && empty( $row['draft'] ) ) ) {
					continue;
				}
				$stores[ $store ][ $slug ] = ( $stores[ $store ][ $slug ] ?? 0 ) + 1;
			}
		}
		$state   = $this->option_get( Sizes::STATE_OPTION );
		$packing = $this->option_get( 'fishotel_packing_settings' );

		return array(
			'taxonomy' => (string) $taxonomy,
			'orderby'  => $this->orderby,
			'terms'    => $terms,
			'orders'   => $this->orders,
			'trashed'  => $this->trashed,
			'stores'   => $stores,
			'packing'  => is_array( $packing ) && isset( $packing['size_map'] ) ? $packing['size_map'] : array(),
			'state'    => is_array( $state ) ? $state : array(),
			'products' => $this->products,
		);
	}

	public function term_get( $taxonomy, $slug ) {
		return isset( $this->terms[ $slug ] ) ? $this->terms[ $slug ] : null;
	}

	public function term_add( $taxonomy, $label, $slug ) {
		$this->write( 'term_add ' . $slug );
		$this->terms[ $slug ] = array(
			'term_id'     => ++$this->next_id,
			'name'        => htmlspecialchars( $label, ENT_QUOTES ),
			'slug'        => $slug,
			'order'       => null,
			'description' => '',
			'meta'        => array(),
		);
		return true;
	}

	public function term_uses( $taxonomy, $slug ) {
		$uses = (int) ( $this->trashed[ $slug ] ?? 0 );
		foreach ( $this->products as $product ) {
			$uses += in_array( $slug, $product['sizes'], true ) ? 1 : 0;
			foreach ( $product['variations'] as $variation ) {
				$uses += (string) ( $variation['attrs'][ $taxonomy ] ?? '' ) === (string) $slug ? 1 : 0;
			}
		}
		return $uses;
	}

	public function term_delete( $taxonomy, $slug ) {
		$this->write( 'term_delete ' . $slug );
		unset( $this->terms[ $slug ] );
		return true;
	}

	public function term_restore( $taxonomy, array $record ) {
		$this->write( 'term_restore ' . $record['slug'] );
		$this->terms[ $record['slug'] ] = $record;
		// As a table would give them: by id.
		uasort(
			$this->terms,
			static function ( $a, $b ) {
				return $a['term_id'] <=> $b['term_id'];
			}
		);
		return true;
	}

	public function order_get( $taxonomy ) {
		$order = array();
		foreach ( $this->terms as $slug => $term ) {
			$order[ $slug ] = $term['order'];
		}
		return array(
			'orderby' => $this->orderby,
			'order'   => $order,
		);
	}

	public function order_set( $taxonomy, array $order, $orderby ) {
		$this->write( 'order_set' );
		$this->orderby = (string) $orderby;
		foreach ( $order as $slug => $at ) {
			if ( isset( $this->terms[ $slug ] ) ) {
				$this->terms[ $slug ]['order'] = null === $at ? null : (int) $at;
			}
		}
		return true;
	}

	public function product_get( $taxonomy, $product_id, array $variation_ids ) {
		++$this->loaded;
		if ( ! isset( $this->products[ $product_id ] ) ) {
			return null;
		}
		// The product with every variation; the ones the run changes are named.
		return $this->products[ $product_id ] + array( 'changing' => array_values( $variation_ids ) );
	}

	public function for_sale_now( $taxonomy, $product_id, array $variation_ids ) {
		if ( is_callable( $this->before_apply ) ) {
			call_user_func( $this->before_apply, (int) $product_id );
		}
		$out = array();
		foreach ( $variation_ids as $vid ) {
			if ( isset( $this->products[ $product_id ]['variations'][ $vid ] ) && Sizes_Tidy::for_sale( $this->products[ $product_id ], $this->products[ $product_id ]['variations'][ $vid ] ) ) {
				$out[] = (int) $vid;
			}
		}
		return $out;
	}

	public function product_apply( $taxonomy, $product_id, array $change ) {
		if ( ! isset( $this->products[ $product_id ] ) ) {
			return false;
		}
		$convert = isset( $change['convert'] ) && is_array( $change['convert'] ) ? $change['convert'] : null;
		if ( null !== $convert ) {
			$this->write( 'convert ' . $product_id );
			$this->products[ $product_id ]['typed'] = null;
		}
		foreach ( (array) $change['vars'] as $vid => $entry ) {
			if ( ! isset( $this->products[ $product_id ]['variations'][ $vid ] ) ) {
				continue;
			}
			$this->write( 'variation ' . $vid );
			$variation = $this->products[ $product_id ]['variations'][ $vid ];
			if ( null !== $convert ) {
				unset( $variation['attrs'][ $convert['key'] ] );
			}
			$variation['attrs'][ $taxonomy ] = (string) $entry['to'];
			if ( ! empty( $entry['off'] ) ) {
				$variation['status'] = 'private';
			}
			$variation['title']                                    = $this->products[ $product_id ]['title'] . ' - ' . $entry['to'];
			$this->products[ $product_id ]['variations'][ $vid ] = $variation;
		}
		$this->write( 'parent ' . $product_id );
		if ( array_values( (array) $change['sizes'] ) !== $this->products[ $product_id ]['sizes'] || null !== $convert ) {
			// As WooCommerce does when a product's attributes change: the summary line of EVERY variation is written again.
			foreach ( $this->products[ $product_id ]['variations'] as $vid => $variation ) {
				$this->products[ $product_id ]['variations'][ $vid ]['summary'] = 'Size: ' . implode( ', ', $variation['attrs'] );
			}
		}
		$this->products[ $product_id ]['sizes'] = array_values( (array) $change['sizes'] );
		if ( null !== $change['default'] ) {
			$this->products[ $product_id ]['default'] = (string) $change['default'];
		}
		if ( null !== $change['fill'] ) {
			$this->products[ $product_id ]['fill'] = $change['fill'];
		}
		return true;
	}

	public function product_restore( $taxonomy, $product_id, array $record, array $change = array() ) {
		$this->write( 'restore ' . $product_id );
		$changing   = (array) ( $record['changing'] ?? array() );
		$variations = $this->products[ $product_id ]['variations'];
		$convert    = isset( $change['convert'] ) && is_array( $change['convert'] ) ? $change['convert'] : null;
		$title      = (string) $this->products[ $product_id ]['title'];

		// A variation the run changed that has another size by now — not the one the run set, not
		// the one it had: somebody gave it that size since. It is left as it is.
		$kept = array();
		foreach ( $record['variations'] as $vid => $variation ) {
			if ( ! isset( $variations[ $vid ], $change['vars'][ $vid ] ) || ! in_array( (int) $vid, $changing, true ) ) {
				continue;
			}
			$now = (string) ( $variations[ $vid ]['attrs'][ $taxonomy ] ?? '' );
			$had = (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
			if ( $now !== (string) $change['vars'][ $vid ]['to'] && $now !== $had ) {
				$kept[ (int) $vid ] = array(
					'variation' => (int) $vid,
					'product'   => (int) $product_id,
					'title'     => $title,
					'size'      => $now,
					'label'     => isset( $this->terms[ $now ] ) ? Sizes::plain( (string) $this->terms[ $now ]['name'] ) : $now,
				);
			}
		}
		if ( $kept && null !== $convert ) {
			return array_values( $kept ); // A typed size made a size of the list: all of it or none.
		}

		foreach ( $record['variations'] as $vid => $variation ) {
			if ( ! isset( $variations[ $vid ] ) || isset( $kept[ (int) $vid ] ) ) {
				continue;
			}
			if ( in_array( (int) $vid, $changing, true ) ) {
				// Its size and whether it is switched on — never its price, stock or SKU.
				foreach ( array( 'attrs', 'status', 'title', 'summary' ) as $field ) {
					$variations[ $vid ][ $field ] = $variation[ $field ];
				}
			} elseif ( $variations[ $vid ]['attrs'] === $variation['attrs'] ) {
				// One the run did not change: only what WooCommerce rewrote in its wake.
				$variations[ $vid ]['summary'] = $variation['summary'];
				$variations[ $vid ]['title']   = $variation['title'];
			}
		}

		// The product's own list, default size and notes — not its title or whether it is published.
		$sizes = array_values( (array) $record['sizes'] );
		$then  = array();
		foreach ( $record['variations'] as $variation ) {
			$then[ (string) ( $variation['attrs'][ $taxonomy ] ?? '' ) ] = true;
		}
		foreach ( $variations as $variation ) {
			$slug = (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
			if ( '' !== $slug && ! isset( $then[ $slug ] ) && ! in_array( $slug, $sizes, true ) ) {
				$sizes[] = $slug; // A size a variation was given since stays listed.
			}
		}
		foreach ( array( 'default', 'typed', 'fill' ) as $field ) {
			$this->products[ $product_id ][ $field ] = $record[ $field ];
		}
		$this->products[ $product_id ]['sizes']      = $sizes;
		$this->products[ $product_id ]['variations'] = $variations;

		return array_values( $kept );
	}

	public function rows_find( $store, array $slugs, $after_id, $limit ) {
		$out = array();
		foreach ( $this->tables[ $store ] ?? array() as $id => $row ) {
			if ( $id > $after_id && in_array( (string) ( $row['size_slug'] ?? '' ), $slugs, true ) && ( 'invoice_lines' !== $store || ! empty( $row['draft'] ) ) ) {
				$out[ $id ] = $row;
			}
		}
		ksort( $out );
		return array_slice( array_values( $out ), 0, $limit );
	}

	public function rows_with( $store, $column, array $values ) {
		$out = array();
		foreach ( $this->tables[ $store ] ?? array() as $row ) {
			if ( in_array( (string) ( $row[ $column ] ?? '' ), $values, true ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function row_get( $store, $id ) {
		return isset( $this->tables[ $store ][ $id ] ) ? $this->tables[ $store ][ $id ] : null;
	}

	public function row_update( $store, $id, array $fields ) {
		$this->write( 'row ' . $store . ' ' . $id );
		if ( ! isset( $this->tables[ $store ][ $id ] ) ) {
			return false;
		}
		if ( 'box_shares' === $store && isset( $fields['share_key'] ) ) {
			foreach ( $this->tables[ $store ] as $other ) {
				if ( (int) $other['id'] !== (int) $id && (string) $other['share_key'] === (string) $fields['share_key'] ) {
					throw new \LogicException( 'two shares on one key' ); // The table's UNIQUE KEY.
				}
			}
		}
		$this->tables[ $store ][ $id ] = array_merge( $this->tables[ $store ][ $id ], $fields );
		return true;
	}

	public function row_insert( $store, array $row ) {
		$this->write( 'insert ' . $store . ' ' . $row['id'] );
		$this->tables[ $store ][ (int) $row['id'] ] = $row;
		ksort( $this->tables[ $store ] );
		return true;
	}

	public function row_delete( $store, $id ) {
		$this->write( 'delete ' . $store . ' ' . $id );
		unset( $this->tables[ $store ][ $id ] );
		return true;
	}

	public function option_get( $name ) {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : null;
	}

	public function option_set( $name, $value ) {
		$this->write( 'option ' . $name );
		if ( null === $value ) {
			unset( $this->options[ $name ] );
		} else {
			$this->options[ $name ] = $value;
		}
		// The pickers read the real option.
		if ( Sizes::STATE_OPTION === $name ) {
			if ( null === $value ) {
				delete_option( $name );
			} else {
				update_option( $name, $value, false );
			}
		}
		return true;
	}

	public function run_create( array $run ) {
		$id                = count( $this->runs ) ? max( array_keys( $this->runs ) ) + 1 : 1;
		$this->runs[ $id ] = array(
			'id'          => $id,
			'status'      => (string) $run['status'],
			'taxonomy'    => (string) $run['taxonomy'],
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'created_by'  => (string) ( $run['created_by'] ?? '' ),
			'finished_at' => null,
			'data'        => (array) ( $run['data'] ?? array() ),
		);
		return $id;
	}

	public function run_get( $id ) {
		return isset( $this->runs[ $id ] ) ? $this->runs[ $id ] : null;
	}

	public function run_update( $id, array $fields ) {
		if ( ! isset( $this->runs[ $id ] ) ) {
			return false;
		}
		$this->runs[ $id ] = array_merge( $this->runs[ $id ], $fields );
		return true;
	}

	public function run_delete( $id ) {
		unset( $this->runs[ $id ] );
		foreach ( $this->items as $item_id => $item ) {
			if ( (int) $item['run_id'] === (int) $id ) {
				unset( $this->items[ $item_id ] );
			}
		}
		return true;
	}

	public function runs() {
		$runs = array_values( $this->runs );
		usort(
			$runs,
			static function ( $a, $b ) {
				return $b['id'] <=> $a['id'];
			}
		);
		return $runs;
	}

	public function items_add( $run_id, array $items ) {
		foreach ( $items as $item ) {
			$id                 = count( $this->items ) ? max( array_keys( $this->items ) ) + 1 : 1;
			// As the real record: through JSON and back.
			$this->items[ $id ] = array(
				'id'         => $id,
				'run_id'     => (int) $run_id,
				'kind'       => (string) $item['kind'],
				'object_id'  => (int) $item['object_id'],
				'object_key' => (string) $item['object_key'],
				'state'      => (int) $item['state'],
				'before'     => null === $item['before'] ? null : json_decode( (string) wp_json_encode( $item['before'] ), true ),
				'after'      => json_decode( (string) wp_json_encode( $item['after'] ), true ),
				'note'       => '',
			);
		}
		return true;
	}

	public function items_next( $run_id, array $states, $limit, $reverse = false ) {
		$out = array();
		foreach ( $this->items as $item ) {
			if ( (int) $item['run_id'] === (int) $run_id && in_array( (int) $item['state'], $states, true ) ) {
				$out[] = $item;
			}
		}
		if ( $reverse ) {
			$out = array_reverse( $out );
		}
		return array_slice( $out, 0, $limit );
	}

	public function item_update( $id, array $fields ) {
		if ( ! isset( $this->items[ $id ] ) ) {
			return false;
		}
		if ( array_key_exists( 'before', $fields ) ) {
			$fields['before'] = json_decode( (string) wp_json_encode( $fields['before'] ), true );
		}
		$this->items[ $id ] = array_merge( $this->items[ $id ], $fields );
		return true;
	}

	public function items_count( $run_id ) {
		$out = array();
		foreach ( $this->items as $item ) {
			if ( (int) $item['run_id'] === (int) $run_id ) {
				$out[ (int) $item['state'] ] = ( $out[ (int) $item['state'] ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	public function lock() {
		if ( $this->locked ) {
			return false;
		}
		$this->locked = true;
		return true;
	}

	public function unlock() {
		$this->locked = false;
	}

	public function flush() {
		++$this->flushed;
	}

	/**
	 * The "Sizes to fill in" list, as the real site gives it.
	 */
	public function fill_list( $taxonomy = 'pa_size' ) {
		$out = array();
		foreach ( $this->products as $pid => $product ) {
			$open = array();
			foreach ( (array) $product['fill'] as $vid => $entry ) {
				if ( isset( $product['variations'][ $vid ] ) && '' === (string) ( $product['variations'][ $vid ]['attrs'][ $this->taxonomy ] ?? '' ) ) {
					$open[ $vid ] = $entry;
				}
			}
			if ( $open ) {
				$out[ $pid ] = array(
					'id'    => $pid,
					'title' => $product['title'],
					'sizes' => $open,
				);
			}
		}
		return $out;
	}

	public function fill_dismiss( $product_id ) {
		if ( isset( $this->products[ $product_id ] ) ) {
			$this->products[ $product_id ]['fill'] = array();
		}
		return true;
	}

	public function retiring_counts( $taxonomy, array $slugs ) {
		$out = array();
		foreach ( $this->products as $product ) {
			$own = array();
			foreach ( $product['variations'] as $variation ) {
				$slug         = (string) ( $variation['attrs'][ $taxonomy ] ?? '' );
				$own[ $slug ] = true;
				if ( in_array( $slug, $slugs, true ) && Sizes_Tidy::for_sale( $product, $variation ) ) {
					$out[ $slug ] = ( $out[ $slug ] ?? 0 ) + 1;
				}
			}
			// Sold as "any size": every size the product lists that no variation of its own carries.
			$sellers = count( array_filter( Sizes_Tidy::any_size( $product, $taxonomy ) ) );
			foreach ( $sellers > 0 ? $product['sizes'] : array() as $slug ) {
				if ( in_array( $slug, $slugs, true ) && ! isset( $own[ $slug ] ) ) {
					$out[ $slug ] = ( $out[ $slug ] ?? 0 ) + $sellers;
				}
			}
		}
		return $out;
	}
}

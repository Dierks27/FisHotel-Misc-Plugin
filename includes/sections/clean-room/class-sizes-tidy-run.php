<?php
/**
 * Tidy up the sizes — the run: Apply, in batches that survive a timeout,
 * and Undo. It works on a plan of Sizes_Tidy::plan() and writes only
 * through the shop it is handed (Sizes_Tidy_Shop — the real site is
 * Sizes_Tidy_Site, the tests hand it an in-memory one), so everything
 * here runs headless.
 *
 * A run is a list of items, saved in the record's own tables before
 * anything is written:
 *   term_add     a size of the short list that has no term yet;
 *   product      one product with its variations (the variations' size,
 *                the ones switched off, the product's own list of sizes
 *                and its default size, a typed size made a term);
 *   rows         a page of rows of another place that stores a size
 *                (STORES) whose size becomes another;
 *   shares       the remembered box shares whose key holds the size;
 *   option       a setting it rewrites (the Packing section's size → bag
 *                map, the tool's own state);
 *   order        the attribute's custom order;
 *   term_delete  an old term nothing uses any more.
 * Every item carries what was there before it (`before`) and what it
 * sets (`after`). The states: PLANNED (no record yet), RECORDED (its
 * before is saved), APPLIED, SKIPPED (an old term something still uses),
 * UNDONE.
 *
 * start() saves the whole list; the first step()s record every product
 * as it is ("recording") and only when the last one is recorded does the
 * first write happen ("applying"). Each item sets absolute values, so an
 * item cut off half-way is simply applied again: clicking Apply twice,
 * or after a timeout, carries on where it stopped. undo() walks the same
 * items backwards and puts every `before` back. The last MAX_RUNS runs
 * are kept — a stack: the latest is undone first.
 *
 * Nothing here runs on its own: no cron, no activation hook, nothing on
 * an upgrade — only the tool's buttons call it.
 *
 * @package FisHotel\Misc\Sections\Clean_Room
 */

namespace FisHotel\Misc\Sections\Clean_Room;

defined( 'ABSPATH' ) || exit;

/**
 * What the run needs of a shop.
 */
interface Sizes_Tidy_Shop {

	/**
	 * Whether the shop is there (WooCommerce is active).
	 *
	 * @return bool
	 */
	public function available();

	/**
	 * Everything the plan is made of: taxonomy, orderby, terms (term_id,
	 * name, slug, menu_order), orders (slug → past order lines), stores
	 * (store → slug → rows), packing (slug → bag), state (the tool's own:
	 * short, retiring, waiting, retired) and products (id → title,
	 * status, fish, sizes, default, typed, fill, variations: id →
	 * status, attrs, price, stock, stock_status, sku).
	 *
	 * @param string $taxonomy The size attribute's taxonomy.
	 * @return array
	 */
	public function read_shop( $taxonomy );

	/**
	 * A term as it is stored, complete (name, slug, order, meta, …), or null.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return array|null
	 */
	public function term_get( $taxonomy, $slug );

	/**
	 * Create a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $label    Name.
	 * @param string $slug     Slug.
	 * @return bool
	 */
	public function term_add( $taxonomy, $label, $slug );

	/**
	 * How many products and variations — of every kind and status — use a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return int
	 */
	public function term_uses( $taxonomy, $slug );

	/**
	 * Delete a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Slug.
	 * @return bool
	 */
	public function term_delete( $taxonomy, $slug );

	/**
	 * Put a deleted term back exactly as term_get() gave it.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $record   term_get().
	 * @return bool
	 */
	public function term_restore( $taxonomy, array $record );

	/**
	 * The attribute's order: orderby and order (slug → the stored order, null = none).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array{orderby: string, order: array}
	 */
	public function order_get( $taxonomy );

	/**
	 * Set the attribute's order.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $order    slug → order (null = none).
	 * @param string $orderby  menu_order|name|name_num|id.
	 * @return bool
	 */
	public function order_set( $taxonomy, array $order, $orderby );

	/**
	 * A product and its variations as they are stored — everything
	 * product_apply() may change.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @return array|null
	 */
	public function product_get( $taxonomy, $product_id );

	/**
	 * Apply one product of a plan (Sizes_Tidy::plan()['products'][id]).
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @param array  $change     vars, sizes, default, convert, fill.
	 * @return bool
	 */
	public function product_apply( $taxonomy, $product_id, array $change );

	/**
	 * Put a product and its variations back exactly as product_get() gave them.
	 *
	 * @param string $taxonomy   Taxonomy.
	 * @param int    $product_id Product id.
	 * @param array  $record     product_get().
	 * @return bool
	 */
	public function product_restore( $taxonomy, $product_id, array $record );

	/**
	 * Rows of a store whose size is one of the slugs, by id, complete.
	 *
	 * @param string   $store    Store name.
	 * @param string[] $slugs    Size slugs.
	 * @param int      $after_id Only rows after this id.
	 * @param int      $limit    At most.
	 * @return array[]
	 */
	public function rows_find( $store, array $slugs, $after_id, $limit );

	/**
	 * Rows of a store where a column is one of the values, complete.
	 *
	 * @param string   $store  Store name.
	 * @param string   $column share_key.
	 * @param string[] $values Values.
	 * @return array[]
	 */
	public function rows_with( $store, $column, array $values );

	/**
	 * One row, complete, or null.
	 *
	 * @param string $store Store name.
	 * @param int    $id    Row id.
	 * @return array|null
	 */
	public function row_get( $store, $id );

	/**
	 * Write fields of a row.
	 *
	 * @param string $store  Store name.
	 * @param int    $id     Row id.
	 * @param array  $fields column → value.
	 * @return bool
	 */
	public function row_update( $store, $id, array $fields );

	/**
	 * Put a deleted row back, with its id.
	 *
	 * @param string $store Store name.
	 * @param array  $row   The complete row.
	 * @return bool
	 */
	public function row_insert( $store, array $row );

	/**
	 * Delete a row.
	 *
	 * @param string $store Store name.
	 * @param int    $id    Row id.
	 * @return bool
	 */
	public function row_delete( $store, $id );

	/**
	 * A setting, or null when it is not there.
	 *
	 * @param string $name Option name.
	 * @return mixed
	 */
	public function option_get( $name );

	/**
	 * Save a setting (null removes it).
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public function option_set( $name, $value );

	/**
	 * The record: runs (id, status, taxonomy, created_at, created_by,
	 * finished_at, data) and their items (id, run_id, kind, object_id,
	 * object_key, state, before, after, note).
	 *
	 * @param array $run status, taxonomy, created_by, data.
	 * @return int Its id (0 = could not be saved).
	 */
	public function run_create( array $run );

	/**
	 * A run, or null.
	 *
	 * @param int $id Run id.
	 * @return array|null
	 */
	public function run_get( $id );

	/**
	 * Change a run.
	 *
	 * @param int   $id     Run id.
	 * @param array $fields status, finished_at, data.
	 * @return bool
	 */
	public function run_update( $id, array $fields );

	/**
	 * Delete a run and its items.
	 *
	 * @param int $id Run id.
	 * @return bool
	 */
	public function run_delete( $id );

	/**
	 * Every run, the latest first.
	 *
	 * @return array[]
	 */
	public function runs();

	/**
	 * Add items to a run, in order.
	 *
	 * @param int     $run_id Run id.
	 * @param array[] $items  kind, object_id, object_key, state, before, after.
	 * @return bool
	 */
	public function items_add( $run_id, array $items );

	/**
	 * The next items of a run in one of the states, in order (or backwards).
	 *
	 * @param int   $run_id  Run id.
	 * @param int[] $states  States.
	 * @param int   $limit   At most.
	 * @param bool  $reverse The last first.
	 * @return array[]
	 */
	public function items_next( $run_id, array $states, $limit, $reverse = false );

	/**
	 * Change an item.
	 *
	 * @param int   $id     Item id.
	 * @param array $fields state, before, note.
	 * @return bool
	 */
	public function item_update( $id, array $fields );

	/**
	 * How many items a run has in each state.
	 *
	 * @param int $run_id Run id.
	 * @return array<int,int> state → count.
	 */
	public function items_count( $run_id );

	/**
	 * Take the lock (one step at a time). False when another step holds it.
	 *
	 * @return bool
	 */
	public function lock();

	/**
	 * Give the lock back.
	 */
	public function unlock();

	/**
	 * Drop what is cached of the sizes (after a run or an undo).
	 */
	public function flush();

	/**
	 * The "Sizes to fill in" list: every product a tidy-up took a size
	 * off (Sizes_Tidy::FILL_META) whose variation still has none.
	 *
	 * @return array[] product id → id, title, sizes (variation id → slug, label).
	 */
	public function fill_list();

	/**
	 * Tick a product off the "Sizes to fill in" list.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public function fill_dismiss( $product_id );

	/**
	 * How many variations that are for sale today carry each size.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param string[] $slugs    Slugs.
	 * @return array<string,int>
	 */
	public function retiring_counts( $taxonomy, array $slugs );
}

/**
 * Class Sizes_Tidy_Run
 */
class Sizes_Tidy_Run {

	/**
	 * How many runs are kept for Undo.
	 */
	const MAX_RUNS = 3;

	/**
	 * Items per step, and the seconds a step may take before it hands
	 * back (the next step carries on).
	 */
	const BATCH   = 20;
	const SECONDS = 8;

	/**
	 * Rows of another store per item.
	 */
	const ROWS_PAGE = 200;

	/**
	 * Item states.
	 */
	const PLANNED  = 0;
	const RECORDED = 1;
	const APPLIED  = 2;
	const SKIPPED  = 3;
	const UNDONE   = 4;

	/**
	 * The other places that store a size as size_slug + size_label, by
	 * the name the shop knows them by: the Clean Room's stays and order
	 * lines, the fish requests, the lost-fish cases and the lines of
	 * draft invoices.
	 */
	const STORES = array( 'stays', 'order_lines', 'requests', 'lostfish_cases', 'invoice_lines' );

	/**
	 * The Packing section's settings (its size_map is keyed by size slug).
	 */
	const PACKING_OPTION = 'fishotel_packing_settings';

	/**
	 * The run that is not finished — recording, applying or undoing — if any.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @return array|null
	 */
	public static function open( Sizes_Tidy_Shop $shop ) {
		foreach ( $shop->runs() as $run ) {
			if ( in_array( (string) $run['status'], array( 'recording', 'applying', 'undoing' ), true ) ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * The run Undo would take back: the unfinished one, else the latest done.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @return array|null
	 */
	public static function top( Sizes_Tidy_Shop $shop ) {
		foreach ( $shop->runs() as $run ) {
			if ( in_array( (string) $run['status'], array( 'recording', 'applying', 'undoing', 'done' ), true ) ) {
				return $run;
			}
		}
		return null;
	}

	/**
	 * Start a run of a plan: save every item — and, for everything but
	 * the products, what is there now. Nothing is written to the shop
	 * here. A run that is not finished is handed back instead (Apply
	 * clicked twice carries on), and a plan with nothing to do starts
	 * nothing.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @param array           $plan Sizes_Tidy::plan().
	 * @param string          $who  The user's login.
	 * @return array{ok: bool, run: int, why: string} why: '' | open | nothing | busy | record.
	 */
	public static function start( Sizes_Tidy_Shop $shop, array $plan, $who = '' ) {
		$open = self::open( $shop );
		if ( $open ) {
			return array(
				'ok'  => 'undoing' !== (string) $open['status'],
				'run' => (int) $open['id'],
				'why' => 'open',
			);
		}
		if ( ! empty( $plan['nothing'] ) ) {
			return array(
				'ok'  => false,
				'run' => 0,
				'why' => 'nothing',
			);
		}
		if ( ! $shop->lock() ) {
			return array(
				'ok'  => false,
				'run' => 0,
				'why' => 'busy',
			);
		}

		try {
			$taxonomy = (string) $plan['taxonomy'];
			$targets  = (array) $plan['targets'];
			$items    = array();

			foreach ( (array) $plan['create'] as $size ) {
				$items[] = self::item( 'term_add', 0, (string) $size['slug'], array( 'existed' => null !== $shop->term_get( $taxonomy, (string) $size['slug'] ) ), $size );
			}
			foreach ( (array) $plan['products'] as $pid => $change ) {
				$items[] = self::item( 'product', (int) $pid, '', null, $change );
			}

			if ( $targets ) {
				$slugs = array_map( 'strval', array_keys( $targets ) );
				foreach ( self::STORES as $store ) {
					$after_id = 0;
					do {
						$rows   = (array) $shop->rows_find( $store, $slugs, $after_id, self::ROWS_PAGE );
						$before = array();
						$after  = array();
						foreach ( $rows as $row ) {
							$after_id = max( $after_id, (int) $row['id'] );
							$fields   = Sizes_Tidy::remap_row( $row, $targets );
							if ( null === $fields ) {
								continue;
							}
							$after[ (int) $row['id'] ] = $fields;
							foreach ( array_keys( $fields ) as $column ) {
								$before[ (int) $row['id'] ][ $column ] = $row[ $column ] ?? null;
							}
						}
						if ( $after ) {
							$items[] = self::item( 'rows', 0, $store, $before, $after );
						}
					} while ( count( $rows ) >= self::ROWS_PAGE );
				}

				$shares = array();
				foreach ( (array) $shop->rows_find( 'box_shares', $slugs, 0, 100000 ) as $row ) {
					$shares[ (int) $row['id'] ] = $row;
				}
				$landing = array();
				foreach ( $shares as $row ) {
					$landing[] = Box_Shares::share_key( (string) ( $row['location_key'] ?? '' ), (string) ( $row['fish_key'] ?? '' ), (string) $targets[ (string) $row['size_slug'] ]['slug'] );
				}
				foreach ( $landing ? (array) $shop->rows_with( 'box_shares', 'share_key', $landing ) : array() as $row ) {
					$shares[ (int) $row['id'] ] = $row;
				}
				$moved = Sizes_Tidy::remap_shares( array_values( $shares ), $targets );
				if ( $moved['update'] || $moved['delete'] ) {
					$lines_before = array();
					$lines_after  = array();
					foreach ( $moved['keys'] ? (array) $shop->rows_with( 'order_lines', 'share_key', array_keys( $moved['keys'] ) ) : array() as $line ) {
						$lines_before[ (int) $line['id'] ] = (string) $line['share_key'];
						$lines_after[ (int) $line['id'] ]  = $moved['keys'][ (string) $line['share_key'] ];
					}
					$touched = array();
					foreach ( array_merge( array_keys( $moved['update'] ), $moved['delete'] ) as $id ) {
						$touched[ (int) $id ] = $shares[ (int) $id ];
					}
					$items[] = self::item(
						'shares',
						0,
						'box_shares',
						array(
							'rows'  => $touched,
							'lines' => $lines_before,
						),
						array(
							'update' => $moved['update'],
							'delete' => $moved['delete'],
							'lines'  => $lines_after,
						)
					);
				}
			}

			if ( ! empty( $plan['packing']['changed'] ) ) {
				$packing = $shop->option_get( self::PACKING_OPTION );
				if ( is_array( $packing ) ) {
					$next             = $packing;
					$next['size_map'] = $plan['packing']['map'];
					$items[]          = self::item( 'option', 0, self::PACKING_OPTION, array( 'value' => $packing ), array( 'value' => $next ) );
				}
			}

			$order   = $shop->order_get( $taxonomy );
			$current = array();
			foreach ( Sizes::order_terms( self::order_rows( $order ), 'menu_order' ) as $term ) {
				$current[] = (string) $term['slug'];
			}
			$items[] = self::item( 'order', 0, $taxonomy, $order, array( 'slugs' => Sizes_Tidy::order_after( $plan, $current ) ) );

			$retired = array();
			foreach ( (array) $plan['delete'] as $slug ) {
				$term = $shop->term_get( $taxonomy, (string) $slug );
				if ( null === $term ) {
					continue;
				}
				$items[]                  = self::item( 'term_delete', 0, (string) $slug, $term, array( 'slug' => (string) $slug ) );
				$retired[ (string) $slug ] = Sizes::plain( (string) $term['name'] );
			}

			$state   = $shop->option_get( Sizes::STATE_OPTION );
			$next    = array(
				'taxonomy' => $taxonomy,
				'short'    => Sizes_Tidy::short_labels( (array) $plan['short'] ),
				'retiring' => (array) $plan['retiring'],
				'waiting'  => (array) $plan['waiting'],
				'retired'  => $retired + ( is_array( $state ) && isset( $state['retired'] ) && is_array( $state['retired'] ) ? $state['retired'] : array() ),
			);
			$items[] = self::item( 'option', 0, Sizes::STATE_OPTION, array( 'value' => $state ), array( 'value' => $next ) );

			// The stack: the oldest finished runs make room.
			$done = array();
			foreach ( $shop->runs() as $run ) {
				if ( 'done' === (string) $run['status'] ) {
					$done[] = (int) $run['id'];
				}
			}
			foreach ( array_slice( $done, self::MAX_RUNS - 1 ) as $old ) {
				$shop->run_delete( $old );
			}

			$run_id = (int) $shop->run_create(
				array(
					'status'     => 'recording',
					'taxonomy'   => $taxonomy,
					'created_by' => (string) $who,
					'data'       => array(
						'totals' => (array) $plan['totals'],
						'short'  => Sizes_Tidy::short_labels( (array) $plan['short'] ),
						'token'  => (string) $plan['token'],
					),
				)
			);
			if ( $run_id <= 0 || ! $shop->items_add( $run_id, $items ) ) {
				if ( $run_id > 0 ) {
					$shop->run_delete( $run_id );
				}
				return array(
					'ok'  => false,
					'run' => 0,
					'why' => 'record',
				);
			}

			return array(
				'ok'  => true,
				'run' => $run_id,
				'why' => '',
			);
		} finally {
			$shop->unlock();
		}
	}

	/**
	 * An item as it is saved.
	 *
	 * @param string     $kind   Kind.
	 * @param int        $id     Object id.
	 * @param string     $key    Object key.
	 * @param array|null $before What is there now (null = recorded later).
	 * @param array      $after  What it sets.
	 * @return array
	 */
	private static function item( $kind, $id, $key, $before, array $after ) {
		return array(
			'kind'       => (string) $kind,
			'object_id'  => (int) $id,
			'object_key' => (string) $key,
			'state'      => null === $before ? self::PLANNED : self::RECORDED,
			'before'     => $before,
			'after'      => $after,
		);
	}

	/**
	 * An order record as terms Sizes::order_terms() sorts.
	 *
	 * @param array $order order_get().
	 * @return array[]
	 */
	private static function order_rows( array $order ) {
		$rows = array();
		$id   = 0;
		foreach ( (array) ( $order['order'] ?? array() ) as $slug => $at ) {
			$rows[] = array(
				'term_id'    => ++$id,
				'name'       => (string) $slug,
				'slug'       => (string) $slug,
				'menu_order' => null === $at ? 0 : (int) $at,
			);
		}
		return $rows;
	}

	/**
	 * Take back the latest run: from the next step on, its items are
	 * walked backwards. A run that never finished can be taken back too.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @return array{ok: bool, run: int, why: string} why: '' | none.
	 */
	public static function undo( Sizes_Tidy_Shop $shop ) {
		$top = self::top( $shop );
		if ( ! $top ) {
			return array(
				'ok'  => false,
				'run' => 0,
				'why' => 'none',
			);
		}
		if ( 'undoing' !== (string) $top['status'] ) {
			$shop->run_update( (int) $top['id'], array( 'status' => 'undoing' ) );
		}
		return array(
			'ok'  => true,
			'run' => (int) $top['id'],
			'why' => '',
		);
	}

	/**
	 * One step of a run: a batch of products recorded, or a batch of
	 * items applied, or a batch undone — whatever the run is at. Call it
	 * until `finished`.
	 *
	 * @param Sizes_Tidy_Shop $shop    Shop.
	 * @param int             $run_id  Run id.
	 * @param int             $batch   Items at most (0 = BATCH).
	 * @param float           $seconds Seconds at most (0 = SECONDS).
	 * @return array{ok: bool, why: string, status: string, done: int, total: int, finished: bool, skipped: array[]}
	 */
	public static function step( Sizes_Tidy_Shop $shop, $run_id, $batch = 0, $seconds = 0 ) {
		$batch   = (int) $batch > 0 ? (int) $batch : self::BATCH;
		$seconds = (float) $seconds > 0 ? (float) $seconds : self::SECONDS;
		$run     = $shop->run_get( (int) $run_id );
		if ( ! $run ) {
			return self::progress( $shop, null, 'gone' );
		}
		if ( in_array( (string) $run['status'], array( 'done', 'undone' ), true ) ) {
			return self::progress( $shop, $run, '' );
		}
		if ( ! $shop->lock() ) {
			return self::progress( $shop, $run, 'busy' );
		}

		$started  = microtime( true );
		$taxonomy = (string) $run['taxonomy'];
		$left     = $batch;

		try {
			while ( $left > 0 && microtime( true ) - $started < $seconds ) {
				$status = (string) $run['status'];

				if ( 'recording' === $status ) {
					$items = $shop->items_next( (int) $run['id'], array( self::PLANNED ), $left );
					if ( ! $items ) {
						$shop->run_update( (int) $run['id'], array( 'status' => 'applying' ) );
						$run['status'] = 'applying';
						break; // The first write waits for the next step: the record is complete and saved.
					}
					foreach ( $items as $item ) {
						$before = 'product' === $item['kind'] ? $shop->product_get( $taxonomy, (int) $item['object_id'] ) : array();
						if ( null === $before ) {
							$shop->item_update(
								(int) $item['id'],
								array(
									'state' => self::SKIPPED,
									'note'  => 'gone',
								)
							);
						} else {
							$shop->item_update(
								(int) $item['id'],
								array(
									'state'  => self::RECORDED,
									'before' => $before,
								)
							);
						}
						--$left;
						if ( microtime( true ) - $started >= $seconds ) {
							break;
						}
					}
					continue;
				}

				if ( 'applying' === $status ) {
					$items = $shop->items_next( (int) $run['id'], array( self::RECORDED ), $left );
					if ( ! $items ) {
						$shop->run_update(
							(int) $run['id'],
							array(
								'status'      => 'done',
								'finished_at' => gmdate( 'Y-m-d H:i:s' ),
							)
						);
						$run['status'] = 'done';
						$shop->flush();
						break;
					}
					foreach ( $items as $item ) {
						$note = self::apply_item( $shop, $taxonomy, $item );
						$shop->item_update(
							(int) $item['id'],
							array(
								'state' => '' === $note ? self::APPLIED : self::SKIPPED,
								'note'  => $note,
							)
						);
						--$left;
						if ( microtime( true ) - $started >= $seconds ) {
							break;
						}
					}
					continue;
				}

				if ( 'undoing' === $status ) {
					$items = $shop->items_next( (int) $run['id'], array( self::RECORDED, self::APPLIED ), $left, true );
					if ( ! $items ) {
						$shop->run_delete( (int) $run['id'] );
						$run['status'] = 'undone';
						$shop->flush();
						break;
					}
					foreach ( $items as $item ) {
						self::restore_item( $shop, $taxonomy, $item );
						$shop->item_update( (int) $item['id'], array( 'state' => self::UNDONE ) );
						--$left;
						if ( microtime( true ) - $started >= $seconds ) {
							break;
						}
					}
					continue;
				}

				break;
			}
		} finally {
			$shop->unlock();
		}

		return self::progress( $shop, $run, '' );
	}

	/**
	 * Where a run is.
	 *
	 * @param Sizes_Tidy_Shop $shop Shop.
	 * @param array|null      $run  The run (null = it is gone).
	 * @param string          $why  '' | gone | busy.
	 * @return array
	 */
	public static function progress( Sizes_Tidy_Shop $shop, $run, $why = '' ) {
		if ( ! $run ) {
			return array(
				'ok'       => false,
				'why'      => (string) $why,
				'status'   => 'gone',
				'done'     => 0,
				'total'    => 0,
				'finished' => true,
				'skipped'  => array(),
			);
		}
		$status = (string) $run['status'];
		if ( 'undone' === $status ) {
			return array(
				'ok'       => true,
				'why'      => '',
				'status'   => 'undone',
				'done'     => 0,
				'total'    => 0,
				'finished' => true,
				'skipped'  => array(),
			);
		}
		$counts = (array) $shop->items_count( (int) $run['id'] );
		$total  = array_sum( $counts );
		$n      = static function ( $state ) use ( $counts ) {
			return (int) ( $counts[ $state ] ?? 0 );
		};
		if ( 'recording' === $status ) {
			// Recording is the first half of the work, applying the second.
			$done   = $total - $n( self::PLANNED );
			$total *= 2;
		} elseif ( 'applying' === $status ) {
			$done   = $total + $n( self::APPLIED ) + $n( self::SKIPPED );
			$total *= 2;
		} elseif ( 'undoing' === $status ) {
			$done = $n( self::UNDONE ) + $n( self::SKIPPED ) + $n( self::PLANNED );
		} else {
			$done = $total;
		}

		$skipped = array();
		foreach ( 'done' === $status ? (array) $shop->items_next( (int) $run['id'], array( self::SKIPPED ), 200 ) : array() as $item ) {
			$skipped[] = array(
				'kind' => (string) $item['kind'],
				'id'   => (int) $item['object_id'],
				'key'  => (string) $item['object_key'],
				'note' => (string) ( $item['note'] ?? '' ),
			);
		}

		return array(
			'ok'       => '' === (string) $why,
			'why'      => (string) $why,
			'status'   => $status,
			'done'     => $done,
			'total'    => $total,
			'finished' => 'done' === $status,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Apply one item. Every write sets an absolute value, so an item
	 * that was cut off half-way is safe to apply again.
	 *
	 * @param Sizes_Tidy_Shop $shop     Shop.
	 * @param string          $taxonomy Taxonomy.
	 * @param array           $item     Item.
	 * @return string '' when applied, else why it was skipped.
	 */
	private static function apply_item( Sizes_Tidy_Shop $shop, $taxonomy, array $item ) {
		$after = (array) $item['after'];
		$key   = (string) $item['object_key'];

		switch ( (string) $item['kind'] ) {
			case 'term_add':
				if ( null === $shop->term_get( $taxonomy, $key ) ) {
					$shop->term_add( $taxonomy, (string) $after['label'], $key );
				}
				return '';

			case 'product':
				$shop->product_apply( $taxonomy, (int) $item['object_id'], $after );
				return '';

			case 'rows':
				foreach ( $after as $id => $fields ) {
					$shop->row_update( $key, (int) $id, (array) $fields );
				}
				return '';

			case 'shares':
				foreach ( (array) $after['delete'] as $id ) {
					$shop->row_delete( 'box_shares', (int) $id );
				}
				foreach ( (array) $after['update'] as $id => $fields ) {
					$shop->row_update( 'box_shares', (int) $id, (array) $fields );
				}
				foreach ( (array) $after['lines'] as $id => $share_key ) {
					$shop->row_update( 'order_lines', (int) $id, array( 'share_key' => (string) $share_key ) );
				}
				return '';

			case 'option':
				$shop->option_set( $key, $after['value'] ?? null );
				return '';

			case 'order':
				$order = array();
				foreach ( array_values( (array) $after['slugs'] ) as $index => $slug ) {
					$order[ (string) $slug ] = $index;
				}
				$shop->order_set( $taxonomy, $order, 'menu_order' );
				return '';

			case 'term_delete':
				if ( null === $shop->term_get( $taxonomy, $key ) ) {
					return ''; // Gone already: this item was cut off after its delete.
				}
				if ( $shop->term_uses( $taxonomy, $key ) > 0 ) {
					return 'used';
				}
				$shop->term_delete( $taxonomy, $key );
				return '';
		}

		return 'unknown';
	}

	/**
	 * Put back what one item changed.
	 *
	 * @param Sizes_Tidy_Shop $shop     Shop.
	 * @param string          $taxonomy Taxonomy.
	 * @param array           $item     Item.
	 */
	private static function restore_item( Sizes_Tidy_Shop $shop, $taxonomy, array $item ) {
		$before = is_array( $item['before'] ) ? $item['before'] : array();
		$key    = (string) $item['object_key'];

		switch ( (string) $item['kind'] ) {
			case 'term_add':
				if ( empty( $before['existed'] ) && null !== $shop->term_get( $taxonomy, $key ) && 0 === $shop->term_uses( $taxonomy, $key ) ) {
					$shop->term_delete( $taxonomy, $key );
				}
				return;

			case 'product':
				if ( $before ) {
					$shop->product_restore( $taxonomy, (int) $item['object_id'], $before );
				}
				return;

			case 'rows':
				foreach ( $before as $id => $fields ) {
					$shop->row_update( $key, (int) $id, (array) $fields );
				}
				return;

			case 'shares':
				// Out of the way first: a share that took a key gives it back before its old holder returns.
				$rows = (array) ( $before['rows'] ?? array() );
				foreach ( $rows as $id => $row ) {
					if ( null !== $shop->row_get( 'box_shares', (int) $id ) ) {
						$shop->row_update( 'box_shares', (int) $id, array( 'share_key' => substr( 'tidy' . md5( 'undo|' . (int) $id ), 0, 32 ) ) );
					}
				}
				foreach ( $rows as $id => $row ) {
					if ( null !== $shop->row_get( 'box_shares', (int) $id ) ) {
						$shop->row_update( 'box_shares', (int) $id, (array) $row );
					} else {
						$shop->row_insert( 'box_shares', (array) $row );
					}
				}
				foreach ( (array) ( $before['lines'] ?? array() ) as $id => $share_key ) {
					$shop->row_update( 'order_lines', (int) $id, array( 'share_key' => (string) $share_key ) );
				}
				return;

			case 'option':
				$shop->option_set( $key, $before['value'] ?? null );
				return;

			case 'order':
				$shop->order_set( $taxonomy, (array) ( $before['order'] ?? array() ), (string) ( $before['orderby'] ?? 'menu_order' ) );
				return;

			case 'term_delete':
				if ( $before && null === $shop->term_get( $taxonomy, $key ) ) {
					$shop->term_restore( $taxonomy, $before );
				}
				return;
		}
	}
}
